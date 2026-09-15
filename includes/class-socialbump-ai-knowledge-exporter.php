<?php
/**
 * SocialBUMP SEO for AI
 * Version: 1.0.0
 * Compatible with: WordPress and ACF Pro (optional).
 *
 * Note for Fluent Snippets users: Fluent Snippets adds its own <?php
 * wrapper, so paste this file WITHOUT the leading <?php line.
 *
 * Generates llms.txt (slim discovery), llms-full.txt (full knowledge map
 * with rendered content) and llms-details.txt (full plus architecture
 * annotations) for AI crawler discovery. ACF is fully optional - all ACF
 * calls are guarded with acf_active() checks to prevent fatal errors.
 *
 * Serving mode (Settings > File Serving):
 *   - Virtual (default): the three routes are served live from the cache via
 *     a rewrite rule, with no files on disk. Needs pretty permalinks.
 *   - Physical: the three files are written to the WordPress root.
 *
 * Add exclude rules to your caching plugin for: /llms.txt, /llms-full.txt
 * and /llms-details.txt (especially in virtual mode, so a page cache doesn't
 * hold a stale copy of the served text).
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SocialBump_AI_Knowledge_Exporter {

    /**
     * Singleton instance - set when the class is instantiated at the bottom
     * of this file. Extensions can grab the instance to call public helper
     * methods like html_to_markdown().
     *
     * Usage in an extension:
     *   $exporter = SocialBump_AI_Knowledge_Exporter::instance();
     *   $clean   = $exporter->html_to_markdown( $raw, [ 'aggressive' => true ] );
     */
    private static ?self $instance = null;

    public static function instance(): ?self {
        return self::$instance;
    }

    public static function set_instance( self $instance ): void {
        self::$instance = $instance;
    }

    private string $option_name = 'socialbump_ai_knowledge_exporter_settings';
    private string $version = '1.0.0';
    private ?array $settings_cache = null;
    private ?array $touched_cache = null;
    private ?string $settings_fingerprint_cache = null;
    private array $excluded_lookup_cache = [];
    private array $posts_query_cache = [];
    private array $acf_field_object_cache = [];
    private array $acf_options_output_cache = [];

    /**
     * Registry of extensions that have registered with core via
     * register_extension(). Keyed by slug. Each entry:
     *
     *   - name    string      Display name shown in the admin version block.
     *   - version string      Extension's version string.
     *   - detects callable    Optional. Returns true if the integration's
     *                         target (Bricks, Elementor, WooCommerce, etc.)
     *                         is present on the site. Omit for extensions
     *                         that don't depend on another plugin/builder.
     *
     * Populated by extension files calling
     * SocialBump_AI_Knowledge_Exporter::instance()->register_extension( … )
     * on plugins_loaded (or later). Read once in render_admin_page() to
     * draw the version block.
     */
    private array $extensions = [];

    /**
     * Special pseudo-field token representing the post's main body content
     * (post_content for native posts, or whatever a builder extension
     * provides via the socialbump_aiknowledge_post_content_markdown filter).
     *
     * Treated as a draggable item in each post type's content ordering,
     * sitting alongside real ACF field names. Defaults to ticked and at the
     * top of the order, which matches the pre-1.25 hardcoded behaviour.
     *
     * Stored verbatim - curly braces and all - in $settings['acf_fields']
     * and $settings['acf_fields_order'] arrays.
     */
    public const POST_CONTENT_TOKEN = '{post_content}';

    /**
     * WP-Cron hook name for the scheduled auto-update event.
     */
    public const CRON_HOOK = 'socialbump_ai_knowledge_cron_update';

    /**
     * ACF field types that are content-bearing and may appear in the picker.
     *
     * Whitelist approach - if a field type isn't in this list (including
     * third-party / unknown ACF field types), it's hidden from the picker.
     * Excluded by design: UI controls (select, radio, checkbox, button_group,
     * true_false, color_picker), structural containers (tab, group,
     * accordion, message, divider, clone, flexible_content), embeds and maps
     * (oembed, google_map), and security-sensitive (password).
     *
     * The list can be extended at runtime via the
     * `socialbump_aiknowledge_allowed_acf_types` filter - e.g. a custom
     * extension that wants to surface "select" values for a specific use
     * case can add 'select' to the allowed list.
     */
    private array $acf_allowed_types = [
        // Text-bearing
        'text',
        'textarea',
        'wysiwyg',
        'email',
        'url',
        'number',
        'range',

        // Date / time
        'date_picker',
        'date_time_picker',
        'time_picker',

        // Media
        'image',
        'gallery',
        'file',

        // Reference / relationship
        'post_object',
        'relationship',
        'taxonomy',
        'user',
        'page_link',
        'link',

        // Containers that carry nested content
        'repeater',
    ];

    /** ACF field names that are internal control flags, not content. */
    private array $acf_skip_names = [ 'hide_page_title', 'hide_contact_form' ];

    /**
     * Post types that should never appear in the picker even if technically "public".
     * Covers WordPress internals, page builder libraries, font management,
     * SEO/schema engines, ACF's own admin types, and any custom post type
     * created by plugins purely for admin / template use. None of these
     * carry public content meaningful to an AI reader.
     */
    private array $excluded_post_types = [
        // WordPress core internals
        'attachment',
        'nav_menu_item',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_navigation',
        'wp_font_face',
        'wp_font_family',
        'wp_global_styles',
        'custom_css',
        'customize_changeset',
        'revision',
        'oembed_cache',
        'user_request',

        // WooCommerce internals
        'product_variation',
        'shop_order',
        'shop_subscription',
        'shop_coupon',
        'product_visibility',
        'scheduled-action',

        // ACF's own admin types (the fields/groups themselves, not field values)
        'acf-taxonomy',
        'acf-post-type',
        'acf-ui-options-page',
        'acf-field-group',

        // SEO / schema engines
        'yoast_structured_dtypes',
        'rank_math_schema',

        // Font management plugins
        'custom-fonts',
        'font',
        'bricks_fonts',

        // Page builder libraries / templates
        'elementor_library',
        'bricks_template',

        // Workflow / automation plugins
        'ppfuture_workflow',
    ];

    /**
     * If an ACF field name contains any of these substrings, it's hidden from
     * the picker. Belt and braces against accidentally exposing credentials.
     */
    private array $acf_sensitive_patterns = [
        'api_key', 'apikey', 'api-key',
        'secret',
        'password', 'passwd', 'pwd',
        'token', 'auth_',
        'stripe', 'paypal', 'square_',
        'smtp', 'sendgrid', 'mailgun', 'postmark',
        'private_key', 'priv_key',
        'webhook',
        'consumer_secret', 'consumer_key', 'access_token',
        'license_key', 'license-key',
        'aws_', 'gcp_', 'azure_',
        '_internal', '_private', '_hidden',
    ];

    // =========================================================================
    // Boot
    // =========================================================================

    public function __construct() {
        add_action( 'admin_post_socialbump_save_and_generate', [ $this, 'save_and_generate_action' ] );
        add_action( 'admin_post_socialbump_delete_files',      [ $this, 'delete_files_action' ] );
        add_action( 'admin_post_socialbump_rebuild_files',     [ $this, 'rebuild_files_action' ] );
        add_action( 'admin_post_socialbump_update_files',      [ $this, 'update_files_action' ] );
        add_action( 'admin_post_socialbump_rebuild_post_type', [ $this, 'rebuild_post_type_action' ] );
        add_action( 'admin_post_socialbump_update_post_type',  [ $this, 'update_post_type_action' ] );
        add_action( 'admin_post_socialbump_update_single_post', [ $this, 'update_single_post_action' ] );
        add_action( 'admin_post_socialbump_reset_all',         [ $this, 'reset_all_action' ] );
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
        add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_button' ], 100 );
        add_action( 'add_meta_boxes', [ $this, 'register_post_meta_box' ] );

        // Cache invalidation:
        //  - On SAVE we do NOT delete the cache entry. The staleness check
        //    (post_modified > cached_at) already detects the edit, and
        //    keeping the entry preserves the "last cached" timestamp and
        //    the previous render until something re-renders it. We only
        //    flush the cached global stale-count so the admin bar/pills
        //    reflect the change.
        //  - On DELETE we evict the entry entirely (the post is gone).
        add_action( 'save_post', [ $this, 'on_save_post_flush_count' ], 10, 1 );
        add_action( 'before_delete_post', [ $this, 'clear_post_cache' ], 10, 1 );

        // Taxonomy terms aren't cached per-post; their section is rebuilt
        // fresh on every regenerate. So a term edit just needs to trigger
        // a regenerate to land in the files. Priority 20 so ACF (default
        // priority 10) has saved the term's field values first.
        add_action( 'created_term', [ $this, 'on_term_changed' ], 20, 3 );
        add_action( 'edited_term',  [ $this, 'on_term_changed' ], 20, 3 );
        add_action( 'delete_term',  [ $this, 'on_term_changed' ], 20, 3 );

        // Cron auto-update: register custom schedules, keep the scheduled
        // event in sync with the saved interval/toggle, and run the update
        // when the event fires.
        add_filter( 'cron_schedules', [ $this, 'register_cron_schedules' ] );
        add_action( self::CRON_HOOK, [ $this, 'run_scheduled_update' ] );
        add_action( 'init', [ $this, 'sync_cron_event' ] );

        // Virtual file serving (checkpoint 3). Register the rewrite rule
        // and query var so /llms.txt, /llms-full.txt and /llms-details.txt
        // can be served live from the cache, intercept those requests on
        // template_redirect, and flush the rules once per version bump.
        add_action( 'init', [ $this, 'register_rewrite_rule' ], 5 );
        add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 20 );
        add_action( 'admin_init', [ $this, 'maybe_restamp_cache' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'maybe_serve_virtual_file' ], 1 );
    }

    /**
     * Add the two short custom schedules WP doesn't ship with. WP already
     * provides hourly, twicedaily and daily.
     */
    public function register_cron_schedules( array $schedules ): array {
        $schedules['socialbump_15min'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'Every 15 minutes (SocialBUMP)',
        ];
        $schedules['socialbump_30min'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => 'Every 30 minutes (SocialBUMP)',
        ];

        return $schedules;
    }

    /**
     * Keep the scheduled cron event aligned with the current settings:
     *   - auto-update off  → no event scheduled
     *   - auto-update on   → event scheduled on the chosen interval
     * Runs on every load (cheap) and reschedules only when the interval
     * changed or the event is missing, so it self-heals.
     */
    public function sync_cron_event(): void {
        $settings = $this->get_settings();
        $enabled  = ! empty( $settings['cron_enabled'] );
        $interval = $this->get_valid_cron_interval( $settings['cron_interval'] ?? 'hourly' );

        $next     = wp_next_scheduled( self::CRON_HOOK );
        $current  = wp_get_schedule( self::CRON_HOOK ); // false if not scheduled

        if ( ! $enabled ) {
            if ( $next ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
            }
            return;
        }

        // Enabled: schedule if missing, or reschedule if the interval changed.
        if ( ! $next ) {
            wp_schedule_event( time() + 60, $interval, self::CRON_HOOK );
        } elseif ( $current !== $interval ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
            wp_schedule_event( time() + 60, $interval, self::CRON_HOOK );
        }
    }

    /**
     * Validate a stored interval against the allowed set, falling back to
     * hourly for anything unexpected.
     */
    private function get_valid_cron_interval( string $interval ): string {
        $allowed = [ 'socialbump_15min', 'socialbump_30min', 'hourly', 'twicedaily', 'daily' ];

        return in_array( $interval, $allowed, true ) ? $interval : 'hourly';
    }

    /**
     * The scheduled cron callback. Re-renders stale posts and reassembles
     * the files (same work as the manual Update Files button), then records
     * a small last-run note for the settings page.
     *
     * If nothing is stale it still records the run (so you can see cron is
     * alive) but notes "nothing to do" and skips file writes to save work.
     */
    public function run_scheduled_update(): void {
        $settings = $this->get_settings();

        // Respect the toggle even if an orphaned event somehow fires.
        if ( empty( $settings['cron_enabled'] ) ) {
            return;
        }

        $start = microtime( true );

        // Count stale across all selected types before building.
        $stale_total = 0;
        foreach ( (array) ( $settings['post_types'] ?? [] ) as $pt ) {
            $stale_total += $this->count_stale_posts_for_type( $pt );
        }

        if ( $stale_total === 0 ) {
            update_option( 'sbaike_cron_last_run', [
                'time'    => time(),
                'count'   => 0,
                'did_work'=> false,
            ], false );
            return;
        }

        // There's stale content - re-render (cache-miss path) and write.
        $this->regenerate_outputs();

        update_option( 'sbaike_cron_last_run', [
            'time'    => time(),
            'count'   => $stale_total,
            'did_work'=> true,
            'elapsed' => round( microtime( true ) - $start, 1 ),
        ], false );

        // Recompute the global stale count so the admin bar is accurate
        // after the background run.
        $this->get_global_stale_count( true );
    }

    /**
     * save_post handler - the post's content may have changed, so flush the
     * cached global stale-count (admin bar dot / per-type pills recompute on
     * next read). We deliberately DON'T delete the post's cache entry: the
     * staleness check detects the edit via post_modified, and keeping the
     * entry preserves the "last cached" timestamp shown in the meta box plus
     * the previous render until something re-renders it.
     *
     * Skips revisions and autosaves - those aren't the canonical content.
     */
    public function on_save_post_flush_count( int $post_id ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $this->flush_global_stale_count();
    }

    /**
     * Term create / edit / delete handler. Term content is built on the fly
     * rather than cached, so the only thing needed to reflect a term change
     * is to regenerate the output files. Only fires for taxonomies the user
     * has selected for export, and only once per request (a static guard
     * stops bulk term operations triggering a regenerate per term).
     *
     * The admin-bar stale count is intentionally left untouched: it tracks
     * stale POSTS, and term edits create no stale posts, so a green dot here
     * stays correct.
     */
    public function on_term_changed( $term_id, $tt_id = 0, $taxonomy = '' ): void {
        static $done = false;

        if ( $done ) {
            return;
        }

        $settings = $this->get_settings();
        $selected = (array) ( $settings['taxonomies'] ?? [] );

        if ( $taxonomy && ! in_array( $taxonomy, $selected, true ) ) {
            return; // Not an exported taxonomy - nothing to do.
        }

        $done = true;
        $this->regenerate_outputs();
    }

    // =========================================================================
    // ACF availability check
    // =========================================================================

    public function acf_active(): bool {
        return function_exists( 'acf_get_field_groups' ) && function_exists( 'get_field' );
    }

    /**
     * Return the list of allowed ACF field types after filtering.
     *
     * The base list (defined as $acf_allowed_types) is a whitelist of
     * content-bearing field types. Extensions can append additional types
     * via the `socialbump_aiknowledge_allowed_acf_types` filter - useful
     * when an extension genuinely wants to surface a normally-skipped type
     * (e.g. a custom select field used for visible badge labels).
     *
     * @return string[] ACF field type names that are eligible for export.
     */
    public function get_allowed_acf_types(): array {
        /**
         * Filter: socialbump_aiknowledge_allowed_acf_types
         *
         * Modify the list of ACF field types allowed in picker and output.
         * Default is a whitelist of content-bearing types (text, textarea,
         * wysiwyg, dates, media, references, repeaters, etc.).
         *
         * @param string[] $types Default whitelist of allowed ACF field types.
         */
        return (array) apply_filters( 'socialbump_aiknowledge_allowed_acf_types', $this->acf_allowed_types );
    }

    private function get_generator_header(): array {
        return [
            'Generated by SocialBUMP SEO for AI',
            'Version: ' . $this->version,
            'https://socialbump.com.au/',
            'Generated: ' . current_time( 'Y-m-d H:i:s' ),
            '',
        ];
    }

    /**
     * Build the optional "Additional Information" block as an array of lines
     * (or empty array if the body field has no content). The heading uses
     * the user-supplied title if one was set, otherwise falls back to
     * "Additional Information". Both forms are upper-cased in output for
     * consistency with the file's other section headings.
     *
     * The body is intentionally not run through clean_content() - we want
     * line breaks preserved so admins can structure the section however
     * they like.
     */
    private function get_additional_info_block( array $settings ): array {
        $body = trim( (string) ( $settings['compliance_notes'] ?? '' ) );

        if ( $body === '' ) {
            return [];
        }

        $title_raw = trim( (string) ( $settings['compliance_notes_title'] ?? '' ) );
        $heading   = $title_raw !== '' ? strtoupper( $title_raw ) : 'ADDITIONAL INFORMATION';

        return [
            '## ' . $heading,
            $body,
            '',
        ];
    }

    /**
     * Build the "## CONTENTS" block listing taxonomies and post types
     * with their respective term / post counts.
     *
     * Counts respect the exclusion settings - a post that's been ticked
     * out of the picker won't be in the file and shouldn't inflate the
     * count. Term counts use get_terms() with hide_empty=false so the
     * total matches what's rendered in the taxonomy section.
     *
     * Returns an array of lines including a trailing blank line so the
     * caller can splat the result into the output array.
     */
    private function build_contents_toc( array $settings ): array {
        $lines = [];

        $tax_lines = [];
        foreach ( (array) ( $settings['taxonomies'] ?? [] ) as $tax_slug ) {
            $tax_obj = get_taxonomy( $tax_slug );

            if ( ! $tax_obj ) {
                continue;
            }

            $terms = get_terms( [
                'taxonomy'   => $tax_slug,
                'hide_empty' => false,
                'fields'     => 'ids',
            ] );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            $term_count   = count( $terms );
            $term_label   = $term_count === 1 ? 'term' : 'terms';
            $tax_lines[]  = '  - ' . $tax_obj->labels->name . ' - ' . $term_count . ' ' . $term_label;
        }

        $pt_lines = [];
        foreach ( (array) ( $settings['post_types'] ?? [] ) as $post_type ) {
            $pt_obj = get_post_type_object( $post_type );

            if ( ! $pt_obj ) {
                continue;
            }

            // Pull all published posts of this type, then subtract any
            // that are excluded via the per-post picker. We count by
            // iterating IDs only - no body fetch - so this stays cheap
            // even on sites with hundreds of posts per type.
            $ids = $this->get_posts_cached( [
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'suppress_filters' => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ] );

            $excluded_lookup = $this->get_excluded_post_lookup( $post_type, $settings );
            $count = 0;
            foreach ( (array) $ids as $id ) {
                if ( ! isset( $excluded_lookup[ (int) $id ] ) ) {
                    $count++;
                }
            }

            if ( $count === 0 ) {
                continue;
            }

            $entry_label = $count === 1 ? 'entry' : 'entries';
            $pt_lines[]  = '  - ' . $pt_obj->labels->name . ' - ' . $count . ' ' . $entry_label;
        }

        // If there's nothing to show in either category, skip the whole
        // section rather than emitting an empty header.
        if ( ! $tax_lines && ! $pt_lines ) {
            return [];
        }

        $lines[] = '## CONTENTS';
        $lines[] = '';

        if ( $tax_lines ) {
            $tax_count   = count( $tax_lines );
            $tax_label   = $tax_count === 1 ? 'Taxonomy' : 'Taxonomies';
            $lines[]     = $tax_label . ' (' . $tax_count . '):';
            foreach ( $tax_lines as $line ) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        if ( $pt_lines ) {
            $pt_count   = count( $pt_lines );
            $pt_label   = $pt_count === 1 ? 'Post Type' : 'Post Types';
            $lines[]    = $pt_label . ' (' . $pt_count . '):';
            foreach ( $pt_lines as $line ) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        return $lines;
    }

    // =========================================================================
    // Settings
    // =========================================================================

    private function get_settings(): array {
        if ( $this->settings_cache !== null ) {
            return $this->settings_cache;
        }

        $defaults = [
            'post_types'              => [],
            'post_types_order'        => [],
            'taxonomies'              => [],
            'taxonomies_order'        => [],
            'acf_fields'              => [],
            'acf_fields_order'        => [],
            'acf_options_fields'      => [],
            'acf_options_fields_order' => [],
            'acf_term_fields'         => [],
            'acf_term_fields_order'   => [],
            'excluded_posts'          => [],
            'business_name'             => '',
            'business_description'      => '',
            'compliance_notes_title'    => '',
            'compliance_notes'          => '',
            'renderer_strip_selectors'  => '',
            'renderer_selectors_initialised' => false,
            'field_omit_rules'         => [],
            'cron_enabled'             => true,
            'cron_interval'            => 'hourly',
            'serving_mode'             => 'virtual',
        ];

        $this->settings_cache = wp_parse_args( get_option( $this->option_name, [] ), $defaults );

        return $this->settings_cache;
    }

    private function clear_request_caches(): void {
        $this->touched_cache              = null;
        $this->settings_cache             = null;
        $this->settings_fingerprint_cache = null;
        $this->excluded_lookup_cache      = [];
        $this->posts_query_cache          = [];
        $this->acf_field_object_cache     = [];
        $this->acf_options_output_cache   = [];
    }

    private function get_excluded_post_lookup( string $post_type, array $settings ): array {
        if ( isset( $this->excluded_lookup_cache[ $post_type ] ) ) {
            return $this->excluded_lookup_cache[ $post_type ];
        }

        $ids = array_map( 'intval', (array) ( $settings['excluded_posts'][ $post_type ] ?? [] ) );
        $ids = array_filter( $ids );

        $this->excluded_lookup_cache[ $post_type ] = array_fill_keys( $ids, true );

        return $this->excluded_lookup_cache[ $post_type ];
    }

    private function get_posts_cached( array $args ): array {
        $args = wp_parse_args( $args, [
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        ] );

        $key = md5( (string) wp_json_encode( $args ) );

        if ( ! array_key_exists( $key, $this->posts_query_cache ) ) {
            $this->posts_query_cache[ $key ] = get_posts( $args ) ?: [];
        }

        return $this->posts_query_cache[ $key ];
    }

    // =========================================================================
    // Discovery helpers
    // =========================================================================

    public function get_public_post_types(): array {
        $post_types = get_post_types( [ 'public' => true ], 'objects' );

        /**
         * Filter: socialbump_aiknowledge_excluded_post_types
         *
         * Allows extensions to add additional post type slugs to the
         * exclusion list (e.g. a plugin extension hiding its own internal
         * admin post types from the picker).
         *
         * @param string[] $excluded Default list of excluded post type slugs.
         */
        $excluded = apply_filters( 'socialbump_aiknowledge_excluded_post_types', $this->excluded_post_types );

        foreach ( (array) $excluded as $slug ) {
            unset( $post_types[ $slug ] );
        }

        return $post_types;
    }

    /**
     * Return the public post types in the user's chosen display order.
     *
     * Items appear in the order saved in $settings['post_types_order'].
     * Any newly-registered post types (or items present in the live list
     * but not yet in the saved order) get appended to the end in their
     * registration order - so a freshly-installed plugin's CPT is visible
     * in the picker but doesn't disturb the user's existing order.
     *
     * @param array $settings Current settings array (caller passes to avoid
     *                        re-reading the option on every call).
     * @return array<string, WP_Post_Type> Post types keyed by slug, in chosen order.
     */
    public function get_ordered_post_types( array $settings ): array {
        $live  = $this->get_public_post_types();
        $order = (array) ( $settings['post_types_order'] ?? [] );

        $ordered = [];

        // First pass: items the user has already arranged, in their order.
        foreach ( $order as $slug ) {
            if ( isset( $live[ $slug ] ) ) {
                $ordered[ $slug ] = $live[ $slug ];
            }
        }

        // Second pass: any live items not yet in the saved order, appended.
        foreach ( $live as $slug => $object ) {
            if ( ! isset( $ordered[ $slug ] ) ) {
                $ordered[ $slug ] = $object;
            }
        }

        return $ordered;
    }

    /**
     * Return the public taxonomies in the user's chosen display order.
     * Same shape as get_ordered_post_types() - saved order first, then
     * any new/unrecognised taxonomies appended.
     */
    public function get_ordered_taxonomies( array $settings ): array {
        $live  = $this->get_public_taxonomies();
        $order = (array) ( $settings['taxonomies_order'] ?? [] );

        $ordered = [];

        foreach ( $order as $slug ) {
            if ( isset( $live[ $slug ] ) ) {
                $ordered[ $slug ] = $live[ $slug ];
            }
        }

        foreach ( $live as $slug => $object ) {
            if ( ! isset( $ordered[ $slug ] ) ) {
                $ordered[ $slug ] = $object;
            }
        }

        return $ordered;
    }

    /**
     * Return ACF Options Page fields in the user's chosen display order.
     * Same shape as the other ordering helpers.
     */
    public function get_ordered_acf_options_fields( array $settings ): array {
        $live  = $this->get_acf_options_fields();
        $order = (array) ( $settings['acf_options_fields_order'] ?? [] );

        $ordered = [];

        foreach ( $order as $field_name ) {
            if ( isset( $live[ $field_name ] ) ) {
                $ordered[ $field_name ] = $live[ $field_name ];
            }
        }

        foreach ( $live as $field_name => $field ) {
            if ( ! isset( $ordered[ $field_name ] ) ) {
                $ordered[ $field_name ] = $field;
            }
        }

        return $ordered;
    }

    /**
     * Return the ordered content items for a single post type, including
     * the special {post_content} pseudo-field alongside real ACF fields.
     *
     * Order resolution:
     *   1. The user's saved order, if any (preserves drag positions)
     *   2. Any live ACF fields not yet in the saved order - appended at end
     *   3. The {post_content} token - prepended if not already present, so
     *      it never silently goes missing. Existing pre-1.25 saved orders
     *      get it inserted at the top, matching old behaviour.
     *
     * The returned array is keyed by field name (or POST_CONTENT_TOKEN)
     * and each value is the field metadata. The token gets a synthetic
     * metadata block flagged with is_post_content => true so renderers
     * and the picker can identify it.
     */
    public function get_ordered_acf_fields_for_post_type( string $post_type, array $settings ): array {
        $live  = $this->get_acf_fields_for_post_type( $post_type );
        $order = (array) ( $settings['acf_fields_order'][ $post_type ] ?? [] );

        $ordered = [];

        foreach ( $order as $field_name ) {
            if ( $field_name === self::POST_CONTENT_TOKEN ) {
                $ordered[ self::POST_CONTENT_TOKEN ] = $this->get_post_content_pseudo_field();
            } elseif ( isset( $live[ $field_name ] ) ) {
                $ordered[ $field_name ] = $live[ $field_name ];
            }
        }

        // Any live ACF fields not yet in the saved order go at the end.
        foreach ( $live as $field_name => $field ) {
            if ( ! isset( $ordered[ $field_name ] ) ) {
                $ordered[ $field_name ] = $field;
            }
        }

        // Ensure {post_content} is always in the list. If the user hasn't
        // saved any order yet (new post type), OR they have but the token
        // isn't in it (pre-1.25 saved data), prepend it.
        if ( ! isset( $ordered[ self::POST_CONTENT_TOKEN ] ) ) {
            $ordered = array_merge(
                [ self::POST_CONTENT_TOKEN => $this->get_post_content_pseudo_field() ],
                $ordered
            );
        }

        return $ordered;
    }

    /**
     * Build the synthetic metadata block for the {post_content} pseudo-field.
     * Mirrors the shape of an ACF field array so the picker can render it
     * with the same template.
     */
    private function get_post_content_pseudo_field(): array {
        return [
            'name'             => self::POST_CONTENT_TOKEN,
            'label'            => 'Primary Content',
            'type'             => 'post body',
            'is_post_content'  => true,
        ];
    }

    /**
     * Return the effective ticked-and-ordered content items for a post type
     * during rendering. Applies the "default ticked at top for new post
     * types" rule: when no saved state exists for the post type, the
     * {post_content} token is the only effective item.
     *
     * Used by the per-post render loop to know what to emit in what order.
     */
    public function get_effective_ticked_fields_for_post_type( string $post_type, array $settings ): array {
        $saved = $settings['acf_fields'][ $post_type ] ?? null;

        if ( $saved === null ) {
            // No saved state for this post type - default behaviour is
            // "render the post body, nothing else".
            return [ self::POST_CONTENT_TOKEN ];
        }

        return (array) $saved;
    }

    /**
     * Return all posts for a post type for display in the per-post-type
     * picker. Includes drafts and other non-published statuses (exclusions
     * are governed by the picker checkboxes, not post status). Sort: by
     * date desc for 'post', by menu_order then title for everything else.
     */
    public function get_posts_for_picker( string $post_type ): array {
        $is_blog = ( $post_type === 'post' );

        // The meta cache is primed on purpose: the Content page reads each post's
        // cache status per row, and without priming that is one query per post.
        $items = $this->get_posts_cached( [
            'post_type'      => $post_type,
            'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
            'posts_per_page' => -1,
            'orderby'        => $is_blog ? 'date' : 'menu_order title',
            'order'          => $is_blog ? 'DESC' : 'ASC',
        ] );

        return $items ?: [];
    }

    /**
     * Check whether a given post ID is excluded from the export for its
     * post type. A post is excluded if either:
     *
     *   1. The user has unticked this post in the picker, OR
     *   2. The post is not published (draft, pending, private, future, etc).
     *
     * The picker shows non-published posts so they can be ticked for
     * pre-launch testing on the admin side, but they're never written to
     * the export files. Posts not in the exclusion list (e.g. newly created
     * posts) default to included as long as they're published.
     */
    public function is_post_excluded( int $post_id, string $post_type, array $settings ): bool {
        // Non-published posts are always excluded from the export.
        $post = get_post( $post_id );
        return ! $post || $this->is_post_object_excluded( $post, $post_type, $settings );
    }

    private function is_post_object_excluded( WP_Post $post, string $post_type, array $settings ): bool {
        if ( $post->post_status !== 'publish' ) {
            return true;
        }

        $excluded = $this->get_excluded_post_lookup( $post_type, $settings );
        return isset( $excluded[ (int) $post->ID ] );
    }

    /**
     * Sanitise a newline-separated selector string submitted from the
     * pill UI. Trims each line, drops empties and duplicates, and strips
     * anything that isn't plausible CSS-selector syntax (tags, classes,
     * ids, descendant chains). Returns a clean newline-separated string
     * for storage.
     */
    private function sanitise_selector_list( $raw ): string {
        $raw   = (string) $raw;
        $lines = preg_split( "/\r\n|\r|\n/", $raw );
        $clean = [];

        foreach ( (array) $lines as $line ) {
            $line = trim( $line );

            if ( $line === '' ) {
                continue;
            }

            // Allow only characters valid in the selector syntax we
            // support: tag names, .class, #id, descendant chains (spaces),
            // hyphens, underscores, digits. Anything else (attribute
            // selectors, pseudo-classes, combinators like > + ~) isn't
            // handled by our CSS-to-XPath converter, so we reject it
            // rather than store something that silently won't match.
            if ( ! preg_match( '/^[a-zA-Z0-9 ._#\-]+$/', $line ) ) {
                continue;
            }

            if ( ! in_array( $line, $clean, true ) ) {
                $clean[] = $line;
            }
        }

        return implode( "\n", $clean );
    }

    /**
     *
     * Sourced from the Renderer extension when it's loaded, so there's a
     * single source of truth. Falls back to an empty array if the renderer
     * isn't present (in which case the Content Rendering UI won't show
     * anyway, since it's gated on the renderer being active).
     *
     * @return string[]
     */
    public function get_default_strip_selectors(): array {
        if ( class_exists( 'SocialBump_AI_Knowledge_Renderer' )
            && defined( 'SocialBump_AI_Knowledge_Renderer::DEFAULT_STRIP_SELECTORS' )
        ) {
            return SocialBump_AI_Knowledge_Renderer::DEFAULT_STRIP_SELECTORS;
        }

        return [];
    }

    /**
     * Parse the saved "Strip Selectors" setting into a clean array of
     * selector strings.
     *
     * Returns:
     *   - null  when the setting has never been initialised (fresh
     *           install / after Reset All). The renderer treats null as
     *           "use the default seed".
     *   - array (possibly empty) once the user has saved at least once.
     *           An empty array is a deliberate "strip nothing" choice and
     *           is honoured as-is.
     *
     * Selectors are stored newline-separated; each non-empty line becomes
     * one entry, preserving inline whitespace (so `#faq .heading` stays a
     * single descendant chain).
     *
     * @return string[]|null
     */
    public function get_user_strip_selectors(): ?array {
        $settings = $this->get_settings();

        // The init flag distinguishes "never saved" (null → use defaults)
        // from "saved, possibly empty" (array → honour exactly).
        if ( empty( $settings['renderer_selectors_initialised'] ) ) {
            return null;
        }

        $raw = (string) ( $settings['renderer_strip_selectors'] ?? '' );

        $lines = preg_split( "/\r\n|\r|\n/", $raw );
        $clean = [];

        foreach ( (array) $lines as $line ) {
            $line = trim( $line );
            if ( $line !== '' && ! in_array( $line, $clean, true ) ) {
                $clean[] = $line;
            }
        }

        return $clean;
    }

    /**
     * Return the selectors to display in the admin pill UI.
     *
     * Before the user has ever saved (uninitialised), we show the default
     * seed so the UI has a sensible starting point. After that we show
     * exactly what they saved (including an empty list).
     *
     * @return string[]
     */
    public function get_strip_selectors_for_display(): array {
        $saved = $this->get_user_strip_selectors();

        if ( $saved === null ) {
            return $this->get_default_strip_selectors();
        }

        return $saved;
    }

    /**
     * Register an extension snippet with core. Called by each extension's
     * bootstrap (typically on plugins_loaded). Populates the registry that
     * drives the version block in the admin UI.
     *
     * Required keys:
     *   - name    (string) Display name.
     *   - version (string) Extension version.
     *
     * Optional:
     *   - detects (callable) Returns true if the integration's target is
     *             present (e.g. fn() => defined('BRICKS_VERSION') for the
     *             Bricks extension). Omit for extensions that don't depend
     *             on another plugin (like the renderer).
     */
    public function register_extension( string $slug, array $info ): void {
        $slug = sanitize_key( $slug );
        if ( $slug === '' ) {
            return;
        }
        $this->extensions[ $slug ] = wp_parse_args( $info, [
            'name'           => $slug,
            'version'        => 'unknown',
            'detects'        => null,
            'category'       => 'integration',
            'description'    => '',
            'admin_page'     => null,
            'settings_title' => '',
        ] );
    }

    /**
     * Return the registered extensions array with their current detection
     * status resolved. Each entry gains an 'is_active' boolean - true if
     * no detector is set (always active) or if the detector callable
     * returns truthy.
     */
    public function get_registered_extensions(): array {
        $resolved = [];

        foreach ( $this->extensions as $slug => $info ) {
            $is_active = true;
            if ( isset( $info['detects'] ) && is_callable( $info['detects'] ) ) {
                $is_active = (bool) call_user_func( $info['detects'] );
            }
            $info['is_active'] = $is_active;
            $resolved[ $slug ] = $info;
        }

        return $resolved;
    }

    /**
     * Return all public taxonomies that attach to at least one non-excluded
     * post type. A taxonomy that only attaches to excluded types (e.g.
     * template_tag → bricks_template) would have no exportable posts under
     * it, so showing it in the picker is just noise.
     */
    public function get_public_taxonomies(): array {
        $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        $filtered   = [];

        // Use the same hook-aware exclusion list as get_public_post_types()
        // so extensions hiding a post type also hide its dedicated taxonomies.
        $excluded = apply_filters( 'socialbump_aiknowledge_excluded_post_types', $this->excluded_post_types );

        foreach ( $taxonomies as $slug => $tax_obj ) {
            $object_types = (array) $tax_obj->object_type;

            // Drop the taxonomy if every post type it attaches to is excluded.
            $usable = array_diff( $object_types, (array) $excluded );

            if ( ! empty( $usable ) ) {
                $filtered[ $slug ] = $tax_obj;
            }
        }

        return $filtered;
    }

    /**
     * Return taxonomies registered for a specific post type.
     */
    private function get_taxonomies_for_post_type( string $post_type ): array {
        $tax_names = get_object_taxonomies( $post_type );
        $result    = [];

        foreach ( $tax_names as $tax_name ) {
            $tax_obj = get_taxonomy( $tax_name );
            if ( $tax_obj && $tax_obj->public ) {
                $result[ $tax_name ] = $tax_obj;
            }
        }

        return $result;
    }

    /**
     * Case-insensitive substring check against the sensitive-name blacklist.
     *
     * The pattern list is run through the
     * `socialbump_aiknowledge_excluded_meta_keys` filter, so extensions can
     * add their own patterns (e.g. WooCommerce extension adding `_price`,
     * `_sku`, `_stock`, etc.).
     */
    private function is_sensitive_field_name( string $name ): bool {
        $lower = strtolower( $name );

        /**
         * Filter: socialbump_aiknowledge_excluded_meta_keys
         *
         * Modify the list of substring patterns used to identify sensitive
         * or plugin-private meta key names. A field name containing any of
         * these substrings (case-insensitively) is hidden from the picker
         * and from exported output.
         *
         * @param string[] $patterns Default list of sensitive substrings.
         */
        $patterns = apply_filters( 'socialbump_aiknowledge_excluded_meta_keys', $this->acf_sensitive_patterns );

        foreach ( (array) $patterns as $pattern ) {
            if ( strpos( $lower, strtolower( $pattern ) ) !== false ) {
                return true;
            }
        }

        return false;
    }

    private function get_acf_fields_for_post_type( string $post_type ): array {
        if ( ! $this->acf_active() ) {
            return [];
        }

        $groups = acf_get_field_groups( [ 'post_type' => $post_type ] );
        $fields = [];

        $allowed_types = $this->get_allowed_acf_types();

        foreach ( $groups as $group ) {
            $group_fields = acf_get_fields( $group['key'] );

            if ( ! $group_fields ) {
                continue;
            }

            foreach ( $group_fields as $field ) {
                if (
                    ! in_array( $field['type'], $allowed_types, true )
                    || in_array( $field['name'], $this->acf_skip_names, true )
                    || empty( $field['name'] )
                    || $this->is_sensitive_field_name( $field['name'] )
                ) {
                    continue;
                }

                $fields[ $field['name'] ] = [
                    'label' => $field['label'],
                    'name'  => $field['name'],
                    'type'  => $field['type'],
                ];
            }
        }

        return $fields;
    }

    private function get_acf_options_fields(): array {
        if ( ! $this->acf_active() ) {
            return [];
        }

        $groups        = acf_get_field_groups();
        $fields        = [];
        $allowed_types = $this->get_allowed_acf_types();

        foreach ( $groups as $group ) {
            if ( empty( $group['location'] ) ) {
                continue;
            }

            $is_options_group = false;

            foreach ( $group['location'] as $location_group ) {
                foreach ( $location_group as $rule ) {
                    if ( ( $rule['param'] ?? '' ) === 'options_page' ) {
                        $is_options_group = true;
                    }
                }
            }

            if ( ! $is_options_group ) {
                continue;
            }

            $group_fields = acf_get_fields( $group['key'] );

            if ( ! $group_fields ) {
                continue;
            }

            foreach ( $group_fields as $field ) {
                if (
                    ! in_array( $field['type'], $allowed_types, true )
                    || in_array( $field['name'], $this->acf_skip_names, true )
                    || empty( $field['name'] )
                    || $this->is_sensitive_field_name( $field['name'] )
                ) {
                    continue;
                }

                $fields[ $field['name'] ] = [
                    'label' => $field['label'],
                    'name'  => $field['name'],
                    'type'  => $field['type'],
                ];
            }
        }

        return $fields;
    }

    // =========================================================================
    // Admin page
    // =========================================================================

    // The admin menu is registered by SBAIKE_Admin, which wraps this page in the
    // SocialBUMP header and adds the module, updates and publishing pages alongside it.

    public function admin_notices(): void {
        // ---- Rebuild button notice (shown on any admin page) ----
        // Reads the per-user transient set by the Full Rebuild action and
        // deletes it after display, so the notice only shows once.
        if ( current_user_can( 'manage_options' ) ) {
            // ---- Virtual mode: warn if a physical file has reappeared and
            // is shadowing a live route ----
            if ( $this->get_serving_mode() === 'virtual' ) {
                $shadowing = $this->physical_files_present();
                if ( $shadowing ) {
                    $list = implode( ', ', array_map( 'esc_html', $shadowing ) );
                    $many = count( $shadowing ) > 1;
                    echo '<div class="notice notice-warning"><p>'
                        . '<strong>SEO for AI:</strong> '
                        . ( $many ? 'Physical files are' : 'A physical file is' ) . ' present in your site root while virtual serving is on: <code>' . $list . '</code>. '
                        . 'Your web server serves ' . ( $many ? 'these' : 'this' ) . ' instead of the live version, so the content may be out of date. '
                        . 'Delete ' . ( $many ? 'them' : 'it' ) . ' to restore live serving.'
                        . '</p><p>';
                    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
                    echo '<input type="hidden" name="action" value="socialbump_delete_files">';
                    wp_nonce_field( 'socialbump_delete_files', 'socialbump_delete_files_nonce' );
                    echo '<button type="submit" class="button button-primary">Delete physical file' . ( $many ? 's' : '' ) . '</button>';
                    echo '</form></p></div>';
                }
            }

            $user_id      = get_current_user_id();
            $rebuild_flag = get_transient( 'sbaike_rebuild_notice_' . $user_id );

            if ( is_array( $rebuild_flag ) && empty( $rebuild_flag['error'] ) ) {
                delete_transient( 'sbaike_rebuild_notice_' . $user_id );
                $slim    = home_url( '/llms.txt' );
                $full    = home_url( '/llms-full.txt' );
                $details = home_url( '/llms-details.txt' );

                $elapsed = $rebuild_flag['elapsed'] ?? '0';
                $total   = (int) ( $rebuild_flag['total'] ?? 0 );

                $count_note = ' <strong>Cache files rebuilt: ' . esc_html( (string) $total ) . ' of ' . esc_html( (string) $total ) . '.</strong>';
                $time_note  = ' <span style="color:#646970;">Processed in ' . esc_html( (string) $elapsed ) . 's.</span>';

                echo '<div class="notice notice-success is-dismissible"><p><strong>LLMs files rebuilt.</strong>'
                    . $count_note
                    . '<br>'
                    . '<a href="' . esc_url( $slim ) . '" target="_blank">llms.txt</a> &nbsp;|&nbsp; '
                    . '<a href="' . esc_url( $full ) . '" target="_blank">llms-full.txt</a> &nbsp;|&nbsp; '
                    . '<a href="' . esc_url( $details ) . '" target="_blank">llms-details.txt</a>'
                    . $time_note . '</p></div>';
            } elseif ( is_array( $rebuild_flag ) && ! empty( $rebuild_flag['error'] ) ) {
                delete_transient( 'sbaike_rebuild_notice_' . $user_id );
                echo '<div class="notice notice-error is-dismissible"><p><strong>Could not rebuild LLMs files.</strong> Check write permission to the site root.</p></div>';
            }

            // ---- Per-post-type rebuild notice ----
            $pt_notice = get_transient( 'sbaike_pt_rebuild_notice_' . $user_id );

            if ( $pt_notice ) {
                delete_transient( 'sbaike_pt_rebuild_notice_' . $user_id );

                if ( ! empty( $pt_notice['error'] ) ) {
                    echo '<div class="notice notice-error is-dismissible"><p><strong>Could not rebuild that post type.</strong> Check write permission to the site root.</p></div>';
                } else {
                    $label   = (string) ( $pt_notice['label'] ?? 'Post type' );
                    $total   = (int) ( $pt_notice['total'] ?? 0 );
                    $elapsed = $pt_notice['elapsed'] ?? '0';
                    $noun    = $total === 1 ? 'post' : 'posts';

                    echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html( $label ) . ' rebuilt.</strong> '
                        . 'Re-rendered ' . esc_html( (string) $total ) . ' ' . esc_html( $noun ) . '. '
                        . '<span style="color:#646970;">Processed in ' . esc_html( (string) $elapsed ) . 's.</span></p></div>';
                }
            }

            // ---- Per-post-type Update notice ----
            $pt_update_notice = get_transient( 'sbaike_pt_update_notice_' . $user_id );

            if ( $pt_update_notice ) {
                delete_transient( 'sbaike_pt_update_notice_' . $user_id );

                if ( ! empty( $pt_update_notice['error'] ) ) {
                    echo '<div class="notice notice-error is-dismissible"><p><strong>Could not update that post type.</strong> Check write permission to the site root.</p></div>';
                } else {
                    $label   = (string) ( $pt_update_notice['label'] ?? 'Post type' );
                    $count   = (int) ( $pt_update_notice['count'] ?? 0 );
                    $elapsed = $pt_update_notice['elapsed'] ?? '0';
                    $noun    = $count === 1 ? 'post' : 'posts';

                    if ( $count === 0 ) {
                        echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html( $label ) . ' updated.</strong> '
                            . 'Everything was already current. '
                            . '<span style="color:#646970;">Processed in ' . esc_html( (string) $elapsed ) . 's.</span></p></div>';
                    } else {
                        echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html( $label ) . ' updated.</strong> '
                            . 'Re-rendered ' . esc_html( (string) $count ) . ' ' . esc_html( $noun ) . '. '
                            . '<span style="color:#646970;">Processed in ' . esc_html( (string) $elapsed ) . 's.</span></p></div>';
                    }
                }
            }
        }

        // ---- Settings-page-only notices ----
        $screen = get_current_screen();

        if ( ! $screen || $screen->id !== 'tools_page_sb-ai-knowledge-exporter' ) {
            return;
        }

        if ( ! empty( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved.</strong></p></div>';
        }

        if ( ! empty( $_GET['generated'] ) ) {
            $slim    = home_url( '/llms.txt' );
            $full    = home_url( '/llms-full.txt' );
            $details = home_url( '/llms-details.txt' );

            $time_note = '';
            if ( ! empty( $_GET['t'] ) && is_numeric( $_GET['t'] ) ) {
                $time_note = ' <span style="color:#646970;">Processed in ' . esc_html( (string) $_GET['t'] ) . 's.</span>';
            }

            echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved and files generated.</strong> '
                . '<a href="' . esc_url( $slim ) . '" target="_blank">llms.txt</a> &nbsp;|&nbsp; '
                . '<a href="' . esc_url( $full ) . '" target="_blank">llms-full.txt</a> &nbsp;|&nbsp; '
                . '<a href="' . esc_url( $details ) . '" target="_blank">llms-details.txt</a>'
                . $time_note . '</p></div>';
        }

        if ( ! empty( $_GET['error'] ) ) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . esc_html( urldecode( $_GET['error'] ) ) . '</p></div>';
        }

        if ( ! empty( $_GET['deleted'] ) ) {
            $deleted = sanitize_text_field( wp_unslash( $_GET['deleted'] ) );
            echo '<div class="notice notice-success is-dismissible"><p><strong>Files deleted:</strong> ' . esc_html( $deleted ) . '</p></div>';
        }

        if ( ! empty( $_GET['reset'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Everything reset.</strong> All saved settings and generated files have been removed. You are starting fresh.</p></div>';
        }

        // Update action report - shows count + list of re-rendered posts.
        $update_notice = get_transient( 'sbaike_update_notice_' . get_current_user_id() );

        if ( $update_notice ) {
            delete_transient( 'sbaike_update_notice_' . get_current_user_id() );

            if ( ! empty( $update_notice['error'] ) ) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Could not update LLMs files.</strong> Check write permission to the site root.</p></div>';
            } else {
                $count   = (int) ( $update_notice['count'] ?? 0 );
                $elapsed = $update_notice['elapsed'] ?? '0';
                $posts   = is_array( $update_notice['posts'] ?? null ) ? $update_notice['posts'] : [];

                echo '<div class="notice notice-success is-dismissible">';

                if ( $count === 0 ) {
                    echo '<p><strong>LLMs files updated.</strong> Everything was already current - no posts needed re-rendering. <span style="color:#646970;">Processed in ' . esc_html( (string) $elapsed ) . 's.</span></p>';
                } else {
                    $label = $count === 1 ? '1 post' : ( $count . ' posts' );
                    echo '<p><strong>LLMs files updated.</strong> Re-rendered ' . esc_html( $label ) . '. <span style="color:#646970;">Processed in ' . esc_html( (string) $elapsed ) . 's.</span></p>';

                    echo '<ul style="margin:6px 0 4px 18px;list-style:disc;color:#1d2327;">';
                    foreach ( $posts as $row ) {
                        $type  = esc_html( (string) ( $row['type'] ?? '' ) );
                        $title = esc_html( (string) ( $row['title'] ?? '' ) );
                        $id    = (int) ( $row['id'] ?? 0 );

                        $links = '';
                        if ( $id ) {
                            $edit_url = get_edit_post_link( $id );
                            $view_url = get_permalink( $id );

                            $parts = [];
                            if ( $edit_url ) {
                                // Edit - same window.
                                $parts[] = '<a href="' . esc_url( $edit_url ) . '">Edit</a>';
                            }
                            if ( $view_url ) {
                                // View - new window/tab.
                                $parts[] = '<a href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener">View</a>';
                            }
                            if ( $parts ) {
                                $links = ' <span style="color:#646970;font-size:12px;">(' . implode( ' &middot; ', $parts ) . ')</span>';
                            }
                        }

                        echo '<li style="margin:1px 0;"><strong>' . $type . '</strong> - ' . $title . $links . '</li>';
                    }
                    echo '</ul>';
                }

                echo '</div>';
            }
        }

        // ---- Single-post update notice (shown on the post edit screen) ----
        if ( current_user_can( 'manage_options' ) ) {
            $single = get_transient( 'sbaike_single_notice_' . get_current_user_id() );
            if ( is_array( $single ) ) {
                delete_transient( 'sbaike_single_notice_' . get_current_user_id() );
                $title = esc_html( (string) ( $single['title'] ?? 'Post' ) );
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . '<strong>Cache updated.</strong> &ldquo;' . $title . '&rdquo; was re-rendered and the export files were regenerated.'
                    . '</p></div>';
            }
        }
    }

    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings       = $this->get_settings();
        $post_types     = $this->get_ordered_post_types( $settings );
        $all_taxonomies = $this->get_ordered_taxonomies( $settings );
        $options_fields = $this->get_ordered_acf_options_fields( $settings );
        $acf_active     = $this->acf_active();

        $card = 'padding:16px 20px;margin:16px 0;background:#fff;border:1px solid #ccd0d4;border-radius:4px;';

        $slim_path    = ABSPATH . 'llms.txt';
        $full_path    = ABSPATH . 'llms-full.txt';
        $details_path = ABSPATH . 'llms-details.txt';
        $slim_url     = home_url( '/llms.txt' );
        $full_url     = home_url( '/llms-full.txt' );
        $details_url  = home_url( '/llms-details.txt' );
        $slim_exists    = file_exists( $slim_path );
        $full_exists    = file_exists( $full_path );
        $details_exists = file_exists( $details_path );
        $any_exists     = $slim_exists || $full_exists || $details_exists;
        ?>
        <div class="wrap">
            <button type="button"
                class="socialbump-unsaved-indicator socialbump-unsaved-indicator-floating"
                id="socialbump-unsaved-floating"
                style="display:none;position:fixed;top:50px;right:20px;z-index:99999;padding:10px 16px;background:#fcf5e3;border:1px solid #dba617;border-radius:4px;color:#7a5a00;font-size:14px;font-weight:500;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,0.15);transition:background 0.15s, transform 0.1s;line-height:1.2;"
                title="Click to save and regenerate the LLM files now">
                <span style="color:#dba617;font-size:16px;">●</span>
                <span>Unsaved changes - click to save</span>
            </button>

            <h1 style="margin-bottom:4px;">SEO for AI</h1>
            <div style="margin:0 0 12px;font-size:13px;color:#646970;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <span style="display:inline-block;padding:2px 8px;background:#e6f4ea;border:1px solid #34a853;border-radius:3px;color:#1e6332;">
                    <strong>Core:</strong> <?php echo esc_html( $this->version ); ?>
                </span>
                <?php
                $extensions = $this->get_registered_extensions();
                foreach ( $extensions as $slug => $ext ) :
                    if ( $ext['is_active'] ) {
                        $bg     = '#e6f4ea';
                        $border = '#34a853';
                        $colour = '#1e6332';
                        $note   = 'active';
                    } else {
                        $bg     = '#f1f3f4';
                        $border = '#bdc1c6';
                        $colour = '#5f6368';
                        $note   = 'standby';
                    }
                ?>
                    <span style="display:inline-block;padding:2px 8px;background:<?php echo esc_attr( $bg ); ?>;border:1px solid <?php echo esc_attr( $border ); ?>;border-radius:3px;color:<?php echo esc_attr( $colour ); ?>;">
                        <strong><?php echo esc_html( $ext['name'] ); ?>:</strong> <?php echo esc_html( $ext['version'] ); ?>
                        <span style="opacity:0.7;font-size:11px;margin-left:4px;">(<?php echo esc_html( $note ); ?>)</span>
                    </span>
                <?php endforeach; ?>
            </div>

            <?php
            // In virtual mode the routes are always live, so show all three
            // links regardless of whether anything sits on disk. In physical
            // mode only show links for files that actually exist.
            $serving_mode = $this->get_serving_mode();
            $is_virtual   = ( $serving_mode === 'virtual' );
            if ( $is_virtual || $any_exists ) :
                $parts = [];
                if ( $is_virtual || $slim_exists ) {
                    $parts[] = '<a href="' . esc_url( $slim_url ) . '" target="_blank" rel="noopener">llms.txt</a>';
                }
                if ( $is_virtual || $full_exists ) {
                    $parts[] = '<a href="' . esc_url( $full_url ) . '" target="_blank" rel="noopener">llms-full.txt</a>';
                }
                if ( $is_virtual || $details_exists ) {
                    $parts[] = '<a href="' . esc_url( $details_url ) . '" target="_blank" rel="noopener">llms-details.txt</a>';
                }
            ?>
                <p style="margin:0 0 12px;font-size:13px;">
                    <?php echo $is_virtual ? 'Live files (served from cache):' : 'Current files:'; ?>
                    <?php echo implode( ' &nbsp;|&nbsp; ', $parts ); ?>
                </p>
            <?php endif; ?>

            <p style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                <button type="submit" class="button button-primary button-large"
                    form="sb-settings-form" data-sb-always-on data-sb-idle="<?php echo $this->get_global_stale_count() > 0 ? '0' : '1'; ?>" name="socialbump_mode" value="update" data-sbaike-job="stale" data-sbaike-title="Updating changed posts">
                    Update Files
                </button>
                <button type="submit" class="button button-secondary button-large"
                    form="sb-settings-form" data-sb-always-on name="socialbump_mode" value="rebuild" data-sbaike-job="everything" data-sbaike-title="Rebuilding everything">
                    Full Rebuild (all posts)
                </button>
                <button type="button" class="socialbump-save-button button button-large sb-save sb-save--clean" data-sb-save data-sb-label-dirty="Save changes" disabled title="No unsaved changes"><span data-sb-label>No changes to save</span></button>
            </p>

            <hr style="margin:16px 0;">

            <?php if ( ! $acf_active ) : ?>
                <div class="notice notice-warning inline">
                    <p><strong>ACF not detected.</strong> Advanced Custom Fields is not active on this site. ACF field selection is unavailable. Core WordPress data (title, URL, excerpt, content) will still be exported for selected post types.</p>
                </div>
            <?php endif; ?>

            <form id="sb-settings-form" data-sb-dirty method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="socialbump_save_and_generate">
                <?php wp_nonce_field( 'socialbump_save_and_generate', 'socialbump_save_and_generate_nonce' ); ?>

                <!-- ============================================================
                     BUSINESS DETAILS
                     ============================================================ -->
                <h2>Business Details</h2>
                <p>Written into both exported files as the global business context block.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="business_name">Business Name</label></th>
                        <td><input id="business_name" type="text" name="business_name" value="<?php echo esc_attr( $settings['business_name'] ); ?>" class="regular-text">
                            <p class="description">Defaults to WordPress site title if left blank.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="business_description">Business Description</label></th>
                        <td>
                            <textarea id="business_description" name="business_description" rows="2" class="large-text"><?php echo esc_textarea( $settings['business_description'] ); ?></textarea>
                            <p class="description">Plain text overview for AI context. Defaults to WordPress tagline if left blank.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="compliance_notes_title">Additional Information Title</label></th>
                        <td>
                            <input type="text" id="compliance_notes_title" name="compliance_notes_title" value="<?php echo esc_attr( $settings['compliance_notes_title'] ); ?>" class="large-text" placeholder="Additional Information" />
                            <p class="description">Optional heading for the section below. Rendered in all caps. Defaults to "Additional Information" if left blank.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="compliance_notes">Additional Information</label></th>
                        <td>
                            <textarea id="compliance_notes" name="compliance_notes" rows="3" class="large-text"><?php echo esc_textarea( $settings['compliance_notes'] ); ?></textarea>
                            <p class="description">Free-form prose. Line breaks are preserved. The section is only rendered when this field has content.</p>
                        </td>
                    </tr>
                </table>

                <!-- ============================================================
                     ACF OPTIONS FIELDS
                     ============================================================ -->
                <h2>ACF Options Page Fields</h2>
                <p>These fields appear at the top of both exported files as global business information. Drag the <span style="color:#999;font-size:18px;line-height:1;">⋮⋮</span> handle to reorder how they appear in the output.</p>

                <?php if ( ! $acf_active ) : ?>
                    <p style="font-style:italic;">ACF is not active. Options page fields are unavailable.</p>
                <?php elseif ( $options_fields ) : ?>
                    <p style="margin:-8px 0 8px;font-size:13px;">
                        <a href="#" class="socialbump-select-all" data-target="#socialbump-acf-options-sortable">Select all</a>
                        &nbsp;|&nbsp;
                        <a href="#" class="socialbump-select-none" data-target="#socialbump-acf-options-sortable">Select none</a>
                    </p>
                    <div style="<?php echo $card; ?>">
                        <div id="socialbump-acf-options-sortable">
                        <?php foreach ( $options_fields as $field ) : ?>
                            <?php $checked = in_array( $field['name'], $settings['acf_options_fields'], true ); ?>
                            <div class="socialbump-sortable-item" style="display:flex;align-items:center;gap:10px;padding:4px 0;">
                                <span class="socialbump-drag-handle" style="cursor:move;color:#999;font-size:18px;line-height:1;padding:0 2px;user-select:none;flex-shrink:0;" title="Drag to reorder">⋮⋮</span>
                                <input type="hidden" name="acf_options_fields_order[]" value="<?php echo esc_attr( $field['name'] ); ?>">
                                <label style="display:block;margin:0;flex:1;">
                                    <input type="checkbox" name="acf_options_fields[]" value="<?php echo esc_attr( $field['name'] ); ?>" <?php checked( $checked ); ?>>
                                    <?php echo esc_html( $field['label'] ); ?>
                                    <code><?php echo esc_html( $field['name'] ); ?></code>
                                    <span style="color:#888;font-size:12px;">(<?php echo esc_html( $field['type'] ); ?>)</span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                <?php else : ?>
                    <p style="font-style:italic;">No ACF Options page fields detected. Make sure ACF is active and an Options page group is configured.</p>
                <?php endif; ?>

                <!-- ============================================================
                     TAXONOMIES
                     ============================================================ -->
                <h2>Taxonomies to Include</h2>
                <p>Selected taxonomies will be output as their own sections in both files, and used to annotate posts in the slim file. Drag the <span style="color:#999;font-size:18px;line-height:1;">⋮⋮</span> handle to reorder how taxonomy sections appear in the output.</p>
                <p style="margin:-8px 0 8px;font-size:13px;">
                    <a href="#" class="socialbump-select-all" data-target="#socialbump-taxonomies-sortable">Select all</a>
                    &nbsp;|&nbsp;
                    <a href="#" class="socialbump-select-none" data-target="#socialbump-taxonomies-sortable">Select none</a>
                </p>

                <div style="<?php echo $card; ?>">
                    <div id="socialbump-taxonomies-sortable">
                    <?php foreach ( $all_taxonomies as $tax_slug => $tax_obj ) : ?>
                        <?php
                        $checked            = in_array( $tax_slug, $settings['taxonomies'], true );
                        $term_acf_fields    = $this->get_ordered_acf_term_fields_for_taxonomy( $tax_slug, $settings );
                        $saved_term_for_tax = $settings['acf_term_fields'][ $tax_slug ] ?? null;
                        ?>
                        <div class="socialbump-sortable-item" style="display:flex;align-items:flex-start;gap:10px;padding:6px 0;border-bottom:1px solid #f0f0f1;">
                            <span class="socialbump-drag-handle" style="cursor:move;color:#999;font-size:18px;line-height:1;padding:0 2px;user-select:none;flex-shrink:0;" title="Drag to reorder">⋮⋮</span>
                            <input type="hidden" name="taxonomies_order[]" value="<?php echo esc_attr( $tax_slug ); ?>">
                            <div style="flex:1;min-width:0;">
                                <label style="display:block;margin:0;">
                                    <input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr( $tax_slug ); ?>" <?php checked( $checked ); ?>>
                                    <?php echo esc_html( $tax_obj->labels->name ); ?>
                                    <code><?php echo esc_html( $tax_slug ); ?></code>
                                    <span style="color:#888;font-size:12px;">
                                        (<?php echo implode( ', ', (array) $tax_obj->object_type ); ?>)
                                    </span>
                                </label>

                                <?php if ( $acf_active && $term_acf_fields ) : ?>
                                    <details style="margin-top:8px;">
                                        <summary style="cursor:pointer;color:#0073aa;">Term fields (<?php echo count( $term_acf_fields ); ?> detected) - expand to select &amp; reorder</summary>
                                        <div style="margin-top:10px;padding-left:12px;border-left:3px solid #0073aa;">
                                            <p style="margin:4px 0 4px;color:#555;font-size:13px;">Term-level custom fields are printed under each term in the taxonomy section. Drag <span style="color:#999;">⋮⋮</span> to reorder.</p>
                                            <p style="margin:0 0 8px;font-size:13px;">
                                                <a href="#" class="socialbump-select-all" data-target=".socialbump-acf-term-fields-sortable[data-taxonomy='<?php echo esc_attr( $tax_slug ); ?>']">Select all</a>
                                                &nbsp;|&nbsp;
                                                <a href="#" class="socialbump-select-none" data-target=".socialbump-acf-term-fields-sortable[data-taxonomy='<?php echo esc_attr( $tax_slug ); ?>']">Select none</a>
                                            </p>
                                            <div class="socialbump-acf-term-fields-sortable" data-taxonomy="<?php echo esc_attr( $tax_slug ); ?>">
                                            <?php foreach ( $term_acf_fields as $field ) : ?>
                                                <?php $f_checked = ( $saved_term_for_tax === null ) ? false : in_array( $field['name'], (array) $saved_term_for_tax, true ); ?>
                                                <div class="socialbump-sortable-item" style="display:flex;align-items:center;gap:10px;padding:3px 0;">
                                                    <span class="socialbump-drag-handle" style="cursor:move;color:#999;font-size:16px;line-height:1;padding:0 2px;user-select:none;flex-shrink:0;" title="Drag to reorder">⋮⋮</span>
                                                    <input type="hidden" name="acf_term_fields_order[<?php echo esc_attr( $tax_slug ); ?>][]" value="<?php echo esc_attr( $field['name'] ); ?>">
                                                    <label style="display:block;margin:0;flex:1;">
                                                        <input type="checkbox"
                                                            name="acf_term_fields[<?php echo esc_attr( $tax_slug ); ?>][]"
                                                            value="<?php echo esc_attr( $field['name'] ); ?>"
                                                            <?php checked( $f_checked ); ?>>
                                                        <?php echo esc_html( $field['label'] ); ?>
                                                        <code><?php echo esc_html( $field['name'] ); ?></code>
                                                        <span style="color:#888;font-size:12px;">(<?php echo esc_html( $field['type'] ); ?>)</span>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>

                <!-- ============================================================
                     POST TYPES
                     ============================================================ -->
                <h2>Post Types to Include</h2>
                <p>Tick each post type to include. Expand ACF fields to select which appear in <code>llms-full.txt</code>. Drag the <span style="color:#999;font-size:18px;line-height:1;">⋮⋮</span> handle to reorder how post types appear in the output.</p>

                <div id="socialbump-post-types-sortable">
                <?php foreach ( $post_types as $post_type => $object ) : ?>
                    <?php $acf_fields = $this->get_ordered_acf_fields_for_post_type( $post_type, $settings ); ?>

                    <div class="socialbump-sortable-item" style="<?php echo $card; ?>display:flex;align-items:flex-start;gap:12px;">
                        <span class="socialbump-drag-handle" style="cursor:move;color:#999;font-size:22px;line-height:1;padding:4px 2px;user-select:none;flex-shrink:0;" title="Drag to reorder">⋮⋮</span>
                        <input type="hidden" name="post_types_order[]" value="<?php echo esc_attr( $post_type ); ?>">

                        <div style="flex:1;min-width:0;">
                            <h3 style="margin-top:0;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                                <span>
                                    <label>
                                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $post_type ); ?>"
                                            <?php checked( in_array( $post_type, $settings['post_types'], true ) ); ?>>
                                        <?php echo esc_html( $object->labels->name ); ?>
                                    </label>
                                    <code style="font-weight:normal;font-size:12px;"><?php echo esc_html( $post_type ); ?></code>
                                </span>
                                <?php
                                // Per-type Update + Rebuild + cache status -
                                // only meaningful when the type is selected
                                // for export.
                                if ( in_array( $post_type, $settings['post_types'], true ) ) :
                                    $pt_rebuild_url = wp_nonce_url(
                                        admin_url( 'admin-post.php?action=socialbump_rebuild_post_type&post_type=' . rawurlencode( $post_type ) ),
                                        'socialbump_rebuild_post_type_' . $post_type
                                    );
                                    $pt_update_url = wp_nonce_url(
                                        admin_url( 'admin-post.php?action=socialbump_update_post_type&post_type=' . rawurlencode( $post_type ) ),
                                        'socialbump_update_post_type_' . $post_type
                                    );
                                        $stale_count = $this->count_stale_posts_for_type( $post_type );
                                        $has_stale   = $stale_count > 0;
                                        $pt_total    = $this->count_eligible_posts_for_type( $post_type );
                                        $pt_object   = get_post_type_object( $post_type );
                                        $pt_name     = $pt_object ? ( $pt_total === 1 ? $pt_object->labels->singular_name : $pt_object->labels->name ) : $post_type;

                                        // The pill is the button, worded the same as the ones in Status.
                                        $pt_pill = $has_stale
                                            ? sprintf( '%s of %s %s need updating', number_format_i18n( $stale_count ), number_format_i18n( $pt_total ), $pt_name )
                                            : sprintf( _n( '%s %s is up to date', '%s %s are up to date', $pt_total, 'socialbump-ai-knowledge-exporter' ), number_format_i18n( $pt_total ), $pt_name );
                                    ?>
                                        <span style="display:inline-flex;align-items:center;gap:10px;flex-shrink:0;">
                                            <?php if ( $has_stale ) : ?>
                                                <a href="<?php echo esc_url( $pt_update_url ); ?>"
                                                    class="sbaike-status__pill is-stale"
                                            data-sbaike-job="type-stale" data-sbaike-type="<?php echo esc_attr( $post_type ); ?>" data-sbaike-title="<?php echo esc_attr( sprintf( 'Updating %s', $pt_object ? strtolower( $pt_object->labels->name ) : $post_type ) ); ?>"
                                                    title="Re-render the changed posts in this type and rebuild the files">
                                                    <?php echo esc_html( $pt_pill ); ?>
                                                </a>
                                            <?php else : ?>
                                                <span class="sbaike-status__pill is-good"><?php echo esc_html( $pt_pill ); ?></span>
                                            <?php endif; ?>

                                            <a href="<?php echo esc_url( $pt_rebuild_url ); ?>"
                                                class="button button-small sbaike-rebuild-button"
                                                data-sbaike-job="type" data-sbaike-type="<?php echo esc_attr( $post_type ); ?>" data-sbaike-title="<?php echo esc_attr( sprintf( 'Rebuilding all %s', $pt_object ? strtolower( $pt_object->labels->name ) : $post_type ) ); ?>"
                                                title="<?php echo esc_attr( sprintf( 'Clear the cache for %s and re-render every one, then rebuild the files', $pt_object ? strtolower( $pt_object->labels->name ) : $post_type ) ); ?>">
                                                Rebuild All
                                            </a>
                                        </span>
                                <?php endif; ?>
                            </h3>

                            <?php if ( ! $acf_active ) : ?>
                                <p style="color:#666;margin:0;font-style:italic;">ACF not active. Only core WordPress fields (title, URL, excerpt, content) will be exported.</p>
                            <?php elseif ( $acf_fields ) : ?>
                                <details>
                                    <summary style="cursor:pointer;color:#0073aa;">Post Content Ordering (<?php echo count( $acf_fields ); ?> custom fields detected) - expand to select &amp; reorder</summary>
                                    <div style="margin-top:10px;padding-left:12px;border-left:3px solid #0073aa;">
                                        <p style="margin:4px 0 4px;color:#555;font-size:13px;">
                                            Drag <span style="color:#999;">⋮⋮</span> to reorder.
                                        </p>
                                        <p style="margin:0 0 8px;font-size:13px;">
                                            <a href="#" class="socialbump-select-all" data-target=".socialbump-acf-fields-sortable[data-post-type='<?php echo esc_attr( $post_type ); ?>']">Select all</a>
                                            &nbsp;|&nbsp;
                                            <a href="#" class="socialbump-select-none" data-target=".socialbump-acf-fields-sortable[data-post-type='<?php echo esc_attr( $post_type ); ?>']">Select none</a>
                                        </p>
                                        <div class="socialbump-acf-fields-sortable" data-post-type="<?php echo esc_attr( $post_type ); ?>">
                                        <?php
                                        $saved_fields_for_pt = $settings['acf_fields'][ $post_type ] ?? null;
                                        ?>
                                        <?php foreach ( $acf_fields as $field ) : ?>
                                            <?php
                                            $is_post_content = ! empty( $field['is_post_content'] );

                                            // Tick state: with NO saved state for this post type
                                            // (fresh install or new post type), {post_content}
                                            // defaults ticked and everything else defaults
                                            // unticked. Once the user has saved anything for
                                            // this post type, the saved array is authoritative.
                                            if ( $saved_fields_for_pt === null ) {
                                                $checked = $is_post_content;
                                            } else {
                                                $checked = in_array( $field['name'], (array) $saved_fields_for_pt, true );
                                            }

                                            // Visual distinction: {post_content} gets a soft
                                            // blue background to signal it's not an ACF field
                                            // but the post body itself.
                                            $row_style = 'display:flex;align-items:center;gap:10px;padding:3px 0;';
                                            if ( $is_post_content ) {
                                                $row_style .= 'background:#eef6fc;border:1px solid #c6d9eb;border-radius:3px;padding:6px 8px;margin:2px 0;';
                                            }
                                            ?>
<?php
                                            // What the visibility rules say about this field, so the list shows it.
                                            $omit_full    = $this->field_is_omitted( $field['name'], $post_type, false, $settings );
                                            $omit_details = $this->field_is_omitted( $field['name'], $post_type, true, $settings );
                                            $omit_all     = $omit_full && $omit_details;

                                            if ( $omit_all ) {
                                                $row_style .= 'opacity:0.65;';
                                            }
                                            ?>
                                            <div class="socialbump-sortable-item" style="<?php echo esc_attr( $row_style ); ?>">
                                                <span class="socialbump-drag-handle" style="cursor:move;color:#999;font-size:16px;line-height:1;padding:0 2px;user-select:none;flex-shrink:0;" title="Drag to reorder">⋮⋮</span>
                                                <input type="hidden" name="acf_fields_order[<?php echo esc_attr( $post_type ); ?>][]" value="<?php echo esc_attr( $field['name'] ); ?>">
                                                <label style="display:block;margin:0;flex:1;">
                                                    <input type="checkbox"
                                                        name="acf_fields[<?php echo esc_attr( $post_type ); ?>][]"
                                                        value="<?php echo esc_attr( $field['name'] ); ?>"
                                                        <?php checked( $checked ); ?>
                                                        <?php disabled( $omit_all ); ?>
                                                        <?php echo $omit_all ? 'title="Excluded from both files, so it is not exported at all"' : ''; ?>>

                                                    <?php if ( $omit_all && $checked ) : ?>
                                                        <input type="hidden" name="acf_fields[<?php echo esc_attr( $post_type ); ?>][]" value="<?php echo esc_attr( $field['name'] ); ?>">
                                                    <?php endif; ?>
                                                    <?php if ( $is_post_content ) : ?>
                                                        <strong><?php echo esc_html( $field['label'] ); ?></strong>
                                                    <?php else : ?>
                                                        <?php echo esc_html( $field['label'] ); ?>
                                                    <?php endif; ?>
                                                    <code><?php echo esc_html( $field['name'] ); ?></code>
                                                    <span style="color:#888;font-size:12px;">(<?php echo esc_html( $field['type'] ); ?>)</span>
                                                    <?php if ( $omit_all ) : ?>
                                                        <span class="sbaike-omit sbaike-omit--both">Excluded from export</span>
                                                    <?php elseif ( $omit_full ) : ?>
                                                        <span class="sbaike-omit sbaike-omit--full">Excluded from Full</span>
                                                    <?php elseif ( $omit_details ) : ?>
                                                        <span class="sbaike-omit sbaike-omit--details">Excluded from Details</span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                        </div>
                                    </div>
                                </details>
                            <?php else : ?>
                                <p style="color:#666;margin:0;font-style:italic;">Post Content Ordering (No custom fields detected)</p>
                            <?php endif; ?>

                            <?php
                            // ---- Per-post inclusion picker ----
                            $picker_posts    = $this->get_posts_for_picker( $post_type );
                            $excluded_ids    = $settings['excluded_posts'][ $post_type ] ?? [];
                            $picker_target   = '.socialbump-posts-picker[data-post-type="' . esc_attr( $post_type ) . '"]';
                            ?>
                            <input type="hidden" name="excluded_posts_marker[<?php echo esc_attr( $post_type ); ?>]" value="1">

                            <?php if ( $picker_posts ) :
                                // Compute initial selected count from saved
                                // exclusions. Only published+ticked posts
                                // count - drafts are visible in the picker
                                // but never exported, so they don't count
                                // toward the "N of M selected" figure.
                                $excluded_ids_int = array_map( 'intval', (array) $excluded_ids );
                                $initial_selected = 0;
                                foreach ( $picker_posts as $pp ) {
                                    if ( $pp->post_status !== 'publish' ) {
                                        continue;
                                    }
                                    if ( ! in_array( (int) $pp->ID, $excluded_ids_int, true ) ) {
                                        $initial_selected++;
                                    }
                                }
                                ?>
                                <details style="margin-top:8px;">
                                    <summary style="cursor:pointer;color:#0073aa;">Posts to Include (<span class="socialbump-picker-count" data-post-type="<?php echo esc_attr( $post_type ); ?>"><?php echo (int) $initial_selected; ?></span> of <?php echo count( $picker_posts ); ?> selected) - expand to choose which posts appear in the export</summary>
                                    <div style="margin-top:10px;padding-left:12px;border-left:3px solid #0073aa;">
                                        <p style="margin:4px 0 4px;color:#555;font-size:13px;">Ticked posts are included. New posts default to included.</p>
                                        <p style="margin:0 0 8px;font-size:13px;">
                                            <a href="#" class="socialbump-select-all" data-target="<?php echo esc_attr( $picker_target ); ?>">Select all</a>
                                            &nbsp;|&nbsp;
                                            <a href="#" class="socialbump-select-none" data-target="<?php echo esc_attr( $picker_target ); ?>">Select none</a>
                                        </p>
                                        <table class="socialbump-posts-picker" data-post-type="<?php echo esc_attr( $post_type ); ?>" style="width:100%;border-collapse:collapse;font-size:13px;">
                                            <tbody>
                                            <?php foreach ( $picker_posts as $picker_post ) :
                                                $is_included = ! in_array( (int) $picker_post->ID, array_map( 'intval', (array) $excluded_ids ), true );
                                                $is_draft    = ( $picker_post->post_status === 'draft' );
                                                $row_bg      = $is_draft ? '#fff7ed' : 'transparent';
                                                $status_label = $picker_post->post_status === 'publish' ? '' : $picker_post->post_status;
                                            ?>
                                                <tr style="background:<?php echo esc_attr( $row_bg ); ?>;border-bottom:1px solid #eee;">
                                                    <td style="padding:4px 6px;width:24px;">
                                                        <label style="display:block;">
                                                            <input type="checkbox"
                                                                id="socialbump-post-<?php echo (int) $picker_post->ID; ?>"
                                                                class="socialbump-post-include"
                                                                data-post-id="<?php echo (int) $picker_post->ID; ?>"
                                                                data-post-status="<?php echo esc_attr( $picker_post->post_status ); ?>"
                                                                <?php checked( $is_included ); ?>>
                                                            <input type="hidden"
                                                                name="excluded_posts[<?php echo esc_attr( $post_type ); ?>][]"
                                                                value="<?php echo (int) $picker_post->ID; ?>"
                                                                <?php disabled( $is_included ); ?>>
                                                        </label>
                                                    </td>
                                                    <td style="padding:4px 6px;">
                                                        <label for="socialbump-post-<?php echo (int) $picker_post->ID; ?>" style="cursor:pointer;color:#1d2327;">
                                                            <?php echo esc_html( $picker_post->post_title ?: '(no title)' ); ?>
                                                        </label>
                                                        <code style="font-size:11px;color:#888;">ID:<?php echo (int) $picker_post->ID; ?></code>
                                                    </td>
                                                    <td style="padding:4px 6px;width:1%;white-space:nowrap;color:#888;font-size:12px;">
                                                        <?php echo esc_html( $status_label ); ?>
                                                    </td>
                                                    <td style="padding:4px 6px;width:1%;white-space:nowrap;text-align:right;">
                                                    <?php
                                                    // A post that has changed since the files were built can be caught up on its own.
                                                    $picker_stale = $picker_post->post_status === 'publish' && ! $this->post_cache_is_fresh( $picker_post );
                                                    
                                                    if ( $picker_stale ) :
                                                        $picker_update = wp_nonce_url(
                                                            admin_url( 'admin-post.php?action=socialbump_update_single_post&post_id=' . (int) $picker_post->ID ),
                                                            'socialbump_update_single_post_' . $picker_post->ID
                                                        );
                                                    ?>
                                                        <a href="<?php echo esc_url( $picker_update ); ?>"
                                                            class="button button-small sbaike-row-button sbaike-row-button--update" data-sbaike-job="post" data-sbaike-id="<?php echo (int) $picker_post->ID; ?>" data-sbaike-title="Updating this one"
                                                            title="Re-render this one and rebuild the files">Update</a>
                                                    <?php endif; ?>
                                                    <a href="<?php echo esc_url( get_edit_post_link( $picker_post->ID ) ); ?>" target="_blank" rel="noopener" class="button button-small sbaike-row-button">Edit</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            <?php else : ?>
                                <p style="color:#666;margin:8px 0 0;font-style:italic;">Posts to Include (No posts found for this post type)</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

                <script>
                jQuery(function($) {
                    var $form = $('#sb-settings-form');
                    var $floatingIndicator = $('#socialbump-unsaved-floating');
                    var $saveButtons = $('.socialbump-save-button');
                    var isDirty = false;

                    function setSaveButtonsDirty() {
                        $saveButtons
                            .prop('disabled', false)
                            .css({
                                background: '#fcf5e3',
                                borderColor: '#dba617',
                                color: '#7a5a00',
                                cursor: 'pointer',
                                boxShadow: 'none'
                            })
                            .attr('title', 'Click to save your settings and regenerate the files');
                        $saveButtons.find('.socialbump-save-dot').css('color', '#dba617');
                        $saveButtons.find('.socialbump-save-label').text('Save changes');
                    }

                    function setSaveButtonsClean() {
                        $saveButtons
                            .prop('disabled', true)
                            .css({
                                background: '#f0f0f1',
                                borderColor: '#dcdcde',
                                color: '#a7aaad',
                                cursor: 'default',
                                boxShadow: 'none'
                            })
                            .attr('title', 'No unsaved changes');
                        $saveButtons.find('.socialbump-save-dot').css('color', '#a7aaad');
                        $saveButtons.find('.socialbump-save-label').text('No changes to save');
                    }

                    function markDirty() {
                        if (!isDirty) {
                            isDirty = true;
                            $floatingIndicator.show();
                            setSaveButtonsDirty();
                        }
                    }

                    // Click the floating indicator OR a save button → submit
                    // the settings form. Clear the dirty flag first so the
                    // beforeunload prompt doesn't fire on the reload.
                    $floatingIndicator.on('click', function() {
                        isDirty = false;
                        $(this).html('<span style="color:#dba617;font-size:16px;">⟳</span> <span>Saving…</span>').prop('disabled', true);
                        $form.trigger('submit');
                    });

                    $saveButtons.on('click', function() {
                        if ($(this).prop('disabled')) {
                            return;
                        }
                        isDirty = false;
                        $form.trigger('submit');
                    });

                    // Hover effect for the floating button.
                    $floatingIndicator.on('mouseenter', function() {
                        $(this).css({ background: '#f9e9b8', transform: 'translateY(-1px)' });
                    }).on('mouseleave', function() {
                        $(this).css({ background: '#fcf5e3', transform: 'translateY(0)' });
                    });

                    // Beforeunload warning if the user tries to leave with unsaved changes.
                    // Modern browsers ignore the custom message and show their own, but the
                    // confirmation prompt itself still fires when returnValue is set.
                    $(window).on('beforeunload', function() {
                        if (isDirty) {
                            return 'You have unsaved changes to your SEO for AI settings.';
                        }
                    });

                    // Form submit clears the dirty flag so the beforeunload prompt
                    // doesn't fire when the user actually saves.
                    $form.on('submit', function() {
                        isDirty = false;
                    });

                    // Any form input change marks dirty (covers checkboxes, text inputs,
                    // textareas - anything inside the form).
                    $form.on('change input', 'input, textarea, select', function() {
                        markDirty();
                    });

                    // Select all / Select none toggle links.
                    // The links carry a data-target attribute pointing to the
                    // container whose checkboxes should be toggled. We trigger
                    // 'change' on each so the dirty-tracking listener fires
                    // (jQuery's .prop() doesn't fire change events on its own).
                    $form.on('click', '.socialbump-select-all', function(e) {
                        e.preventDefault();
                        var target = $(this).data('target');
                        $(target).find('input[type="checkbox"]').prop('checked', true).trigger('change');
                    });

                    $form.on('click', '.socialbump-select-none', function(e) {
                        e.preventDefault();
                        var target = $(this).data('target');
                        $(target).find('input[type="checkbox"]').prop('checked', false).trigger('change');
                    });

                    // Posts inclusion picker: each row has a checkbox + a
                    // sibling hidden input. The hidden input carries the
                    // post ID and is named excluded_posts[$post_type][].
                    // When the checkbox is CHECKED (= included), disable
                    // the hidden input so it doesn't submit. When UNCHECKED
                    // (= excluded), enable it. Effect: $_POST['excluded_posts']
                    // contains only the post IDs the user has unticked.
                    $form.on('change', '.socialbump-post-include', function() {
                        var $cb = $(this);
                        var $hidden = $cb.siblings('input[type="hidden"]');
                        $hidden.prop('disabled', $cb.is(':checked'));

                        // Update the picker count in the <details> summary
                        // line. Only checked posts with status=publish count
                        // toward the figure - non-published rows are visible
                        // in the picker but never exported, so they don't
                        // count.
                        var $picker = $cb.closest('table.socialbump-posts-picker');
                        if ($picker.length) {
                            var postType = $picker.data('post-type');
                            var checked = $picker
                                .find('.socialbump-post-include:checked[data-post-status="publish"]')
                                .length;
                            $form
                                .find('.socialbump-picker-count[data-post-type="' + postType + '"]')
                                .text(checked);
                        }
                    });

                    // Initialise sortable drag-to-reorder for all four pickers.
                    //
                    // Important: items uses the direct-child selector
                    // (> .socialbump-sortable-item) so a parent sortable
                    // doesn't accidentally grab items belonging to a nested
                    // child sortable. The post-types sortable has nested
                    // ACF-field sortables inside each card; without `>`,
                    // dragging an inner field row would also try to drag
                    // the outer post-type card.
                    //
                    // The first three pickers are single instances with
                    // known IDs. The fourth (per-post-type ACF fields) is
                    // one sortable per post-type card, all sharing the
                    // .socialbump-acf-fields-sortable class.
                    if (typeof $.fn.sortable === 'function') {
                        var sortableConfig = {
                            items: '> .socialbump-sortable-item',
                            handle: '.socialbump-drag-handle',
                            axis: 'y',
                            tolerance: 'pointer',
                            cursor: 'move',
                            opacity: 0.7,
                            placeholder: 'socialbump-sortable-placeholder',
                            forcePlaceholderSize: true,
                            update: function() {
                                markDirty();
                            }
                        };

                        // Top-level sortables (one each).
                        var sortableTargets = [
                            '#socialbump-post-types-sortable',
                            '#socialbump-taxonomies-sortable',
                            '#socialbump-acf-options-sortable'
                        ];
                        $.each(sortableTargets, function(_, selector) {
                            $(selector).sortable(sortableConfig);
                        });

                        // Per-post-type ACF field sortables - one per card,
                        // each scoped independently so dragging within one
                        // card doesn't affect others.
                        $('.socialbump-acf-fields-sortable').each(function() {
                            $(this).sortable(sortableConfig);
                        });

                        // Per-taxonomy term-field sortables.
                        $('.socialbump-acf-term-fields-sortable').each(function() {
                            $(this).sortable(sortableConfig);
                        });
                    }
                });
                </script>
                <style>
                    .socialbump-sortable-placeholder {
                        background: #f0f6fc;
                        border: 2px dashed #2271b1 !important;
                        border-radius: 4px;
                        margin: 16px 0;
                    }
                    .socialbump-sortable-item.ui-sortable-helper {
                        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    }
                    /* Shared colour-key / legend used under the settings panels. */
                    .sb-key { display:flex; align-items:center; flex-wrap:wrap; gap:18px; margin:2px 0 8px; }
                    .sb-key-item { display:inline-flex; align-items:center; gap:7px; }
                    .sb-swatch { display:inline-block; width:16px; height:16px; border-radius:3px; border:1px solid; flex:0 0 auto; }
                    .sb-swatch--default { border-color:#d97706; background:#fff7ed; }
                    .sb-swatch--custom  { border-color:#16a34a; background:#f0fdf4; }
                    .sb-swatch--full    { border-color:#2563eb; background:#eff6ff; }
                    .sb-swatch--details { border-color:#7c3aed; background:#f5f3ff; }
                    .sb-swatch--both    { border-color:#b32d2e; background:#fcf0f0; }
                </style>

                <hr style="margin:24px 0;">

                <?php
                // ---- Content Rendering Selectors ----
                // Only show this section if the Renderer extension is
                // active (it's the consumer of these selectors). Without
                // the renderer, the setting would have no effect.
                $extensions       = $this->get_registered_extensions();
                $renderer_active  = ! empty( $extensions['renderer']['is_active'] );
                ?>
                <?php if ( $renderer_active ) : ?>
                <div style="<?php echo esc_attr( $card ); ?>">
                <h2 style="margin-top:0;">Content Rendering</h2>
                <p>Strip site sections you don't want in the export (page furniture, FAQ blocks, CTAs), removed before the page is converted to markdown.</p>

                <?php
                $display_selectors = $this->get_strip_selectors_for_display();
                $default_selectors = $this->get_default_strip_selectors();
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="socialbump-selector-input">Strip Selectors</label></th>
                        <td>
                            <div id="socialbump-selector-ui">
                                <div class="socialbump-selector-add-row" style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
                                    <input
                                        type="text"
                                        id="socialbump-selector-input"
                                        class="regular-text code"
                                        placeholder="#faq, .call-to-action, #related .heading"
                                        autocomplete="off"
                                        style="margin:0;"
                                    >
                                    <button type="button" class="button" id="socialbump-selector-add">Add Selector</button>
                                    <button type="button" class="button" id="socialbump-selector-restore" title="Add back any default selectors that have been removed, without touching your custom ones.">Restore Defaults</button>
                                </div>

                                <div id="socialbump-selector-pills" class="socialbump-selector-pills" style="display:flex;flex-wrap:wrap;gap:6px;min-height:30px;padding:8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
                                    <!-- pills injected by JS -->
                                </div>

                                <p class="socialbump-selector-empty-note" style="margin:8px 0 0;font-size:12px;color:#646970;display:none;">
                                    No selectors. Nothing will be stripped from the rendered HTML - the full page (including header, footer and nav) will flow into the export.
                                </p>

                                <!-- Hidden field: JS serialises the pill list here on submit -->
                                <textarea id="renderer_strip_selectors" name="renderer_strip_selectors" style="display:none;"><?php echo esc_textarea( implode( "\n", $display_selectors ) ); ?></textarea>
                            </div>

                            <p class="description" style="margin-top:10px;">
                                <span class="sb-key">
                                    <span class="sb-key-item"><span class="sb-swatch sb-swatch--default"></span> Default selectors</span>
                                    <span class="sb-key-item"><span class="sb-swatch sb-swatch--custom"></span> Your custom selectors</span>
                                </span>
                                <em style="display:block;margin-top:6px;">Changes here aren't saved until you click "Update Files" or "Full Rebuild". Refresh the page to discard.</em>
                            </p>

                            <script>
                            (function () {
                                var defaults = <?php echo wp_json_encode( array_values( $default_selectors ) ); ?>;
                                var active   = <?php echo wp_json_encode( array_values( $display_selectors ) ); ?>;

                                var pillsEl  = document.getElementById('socialbump-selector-pills');
                                var inputEl  = document.getElementById('socialbump-selector-input');
                                var addBtn   = document.getElementById('socialbump-selector-add');
                                var restore  = document.getElementById('socialbump-selector-restore');
                                var hidden   = document.getElementById('renderer_strip_selectors');
                                var emptyNote = document.querySelector('.socialbump-selector-empty-note');

                                if (!pillsEl || !hidden) { return; }

                                function isDefault(sel) {
                                    return defaults.indexOf(sel) !== -1;
                                }

                                function syncHidden() {
                                    hidden.value = active.join('\n');
                                    // Nudge the unsaved-changes indicator if the page has one.
                                    if (window.jQuery) {
                                        try { jQuery(hidden).trigger('change'); } catch (e) {}
                                    } else {
                                        hidden.dispatchEvent(new Event('change', { bubbles: true }));
                                    }
                                }

                                function render() {
                                    pillsEl.innerHTML = '';

                                    if (!active.length) {
                                        emptyNote.style.display = 'block';
                                    } else {
                                        emptyNote.style.display = 'none';
                                    }

                                    active.forEach(function (sel, idx) {
                                        var core = isDefault(sel);

                                        var pill = document.createElement('span');
                                        pill.className = 'socialbump-pill';
                                        pill.style.cssText =
                                            'display:inline-flex;align-items:center;gap:6px;' +
                                            'padding:3px 6px 3px 10px;border-radius:14px;font-size:12px;' +
                                            'font-family:Menlo,Consolas,monospace;border:1px solid;' +
                                            (core
                                                ? 'border-color:#d97706;background:#fff7ed;color:#b45309;'
                                                : 'border-color:#16a34a;background:#f0fdf4;color:#15803d;');

                                        var label = document.createElement('span');
                                        label.textContent = sel;
                                        pill.appendChild(label);

                                        var x = document.createElement('button');
                                        x.type = 'button';
                                        x.setAttribute('aria-label', 'Remove ' + sel);
                                        x.textContent = '\u00d7';
                                        x.style.cssText =
                                            'cursor:pointer;border:none;background:transparent;' +
                                            'font-size:15px;line-height:1;padding:0 2px;' +
                                            'color:inherit;opacity:0.6;';
                                        x.addEventListener('mouseenter', function () { x.style.opacity = '1'; });
                                        x.addEventListener('mouseleave', function () { x.style.opacity = '0.6'; });
                                        x.addEventListener('click', function () {
                                            active.splice(idx, 1);
                                            render();
                                            syncHidden();
                                        });
                                        pill.appendChild(x);

                                        pillsEl.appendChild(pill);
                                    });
                                }

                                function addSelector() {
                                    var raw = (inputEl.value || '').trim();
                                    if (!raw) { return; }

                                    // Allow comma- or newline-separated bulk entry.
                                    var parts = raw.split(/[,\n]/);
                                    parts.forEach(function (p) {
                                        p = p.trim();
                                        if (!p) { return; }
                                        // Basic client-side validation mirroring the server.
                                        if (!/^[a-zA-Z0-9 ._#\-]+$/.test(p)) { return; }
                                        if (active.indexOf(p) === -1) {
                                            active.push(p);
                                        }
                                    });

                                    inputEl.value = '';
                                    render();
                                    syncHidden();
                                    inputEl.focus();
                                }

                                addBtn.addEventListener('click', addSelector);

                                inputEl.addEventListener('keydown', function (e) {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        addSelector();
                                    }
                                });

                                restore.addEventListener('click', function () {
                                    // Merge: add back any missing defaults, keep customs, no dupes.
                                    defaults.forEach(function (sel) {
                                        if (active.indexOf(sel) === -1) {
                                            active.push(sel);
                                        }
                                    });
                                    render();
                                    syncHidden();
                                });

                                render();
                                syncHidden();
                            })();
                            </script>
                        </td>
                    </tr>
                </table>
                </div>
                <?php endif; ?>

                <?php
                // ---- ACF Field Visibility (per-file omit rules) ----
                // Independent of the renderer: this governs ACF field output,
                // not page HTML, so it shows whenever ACF has fields.
                $omit_fields_map = $this->acf_active() ? $this->get_acf_fields_by_post_type() : [];
                if ( $omit_fields_map ) :
                    $omit_pt_labels = [];
                    foreach ( array_keys( $omit_fields_map ) as $omit_pt ) {
                        $omit_pt_obj                 = get_post_type_object( $omit_pt );
                        $omit_pt_labels[ $omit_pt ]  = ( $omit_pt_obj && ! empty( $omit_pt_obj->label ) ) ? $omit_pt_obj->label : $omit_pt;
                    }
                    $existing_omit_rules = array_values( (array) ( $settings['field_omit_rules'] ?? [] ) );
                    ?>
                <div style="<?php echo esc_attr( $card ); ?>">
                    <h2 style="margin-top:0;">ACF Field Visibility</h2>
                    <p style="max-width:760px;color:#50575e;">Leave a ticked field out of one file. Off this list, a field shows in both.</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="sb-omit-field">Omit Field</label></th>
                            <td>
                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
                                    <select id="sb-omit-pt" style="margin:0;">
                                        <option value="__all__">All post types</option>
                                        <?php foreach ( $omit_pt_labels as $omit_pt => $omit_lbl ) : ?>
                                            <option value="<?php echo esc_attr( $omit_pt ); ?>"><?php echo esc_html( $omit_lbl ); ?> (<?php echo esc_html( $omit_pt ); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" id="sb-omit-field" list="sb-omit-fields" class="regular-text code" placeholder="start typing a field name" autocomplete="off" style="margin:0;min-width:280px;">
                                    <datalist id="sb-omit-fields"></datalist>
                                    <select id="sb-omit-file" style="margin:0;">
                                        <option value="full">omit from Full</option>
                                        <option value="details">omit from Details</option>
                                        <option value="both">omit from Both</option>
                                    </select>
                                    <button type="button" class="button" id="sb-omit-add">Add</button>
                                </div>

                                <div id="sb-omit-pills" style="display:flex;flex-wrap:wrap;gap:6px;min-height:30px;padding:8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;"></div>

                                <p id="sb-omit-empty" style="margin:8px 0 0;font-size:12px;color:#646970;display:none;">No omit rules. Every ticked field shows in both files.</p>

                                <input type="hidden" id="field_omit_rules" name="field_omit_rules" value="<?php echo esc_attr( (string) wp_json_encode( $existing_omit_rules ) ); ?>">

                                <p class="description" style="margin-top:10px;">
                                    <span class="sb-key">
                                        <span class="sb-key-item"><span class="sb-swatch sb-swatch--full"></span> Excluded from Full</span>
                                        <span class="sb-key-item"><span class="sb-swatch sb-swatch--details"></span> Excluded from Details</span>
                                        <span class="sb-key-item"><span class="sb-swatch sb-swatch--both"></span> Excluded from export</span>
                                    </span>
                                    <em style="display:block;margin-top:6px;">Changes here aren't saved until you click "Update Files" or "Full Rebuild". Refresh the page to discard.</em>
                                </p>

                                <script>
                                (function () {
                                    var fieldsByPt = <?php echo wp_json_encode( $omit_fields_map ); ?>;
                                    var ptLabels   = <?php echo wp_json_encode( $omit_pt_labels ); ?>;
                                    var rules      = <?php echo wp_json_encode( $existing_omit_rules ); ?>;

                                    var ptSel   = document.getElementById('sb-omit-pt');
                                    var fldInp  = document.getElementById('sb-omit-field');
                                    var dl      = document.getElementById('sb-omit-fields');
                                    var fileSel = document.getElementById('sb-omit-file');
                                    var addBtn  = document.getElementById('sb-omit-add');
                                    var pills   = document.getElementById('sb-omit-pills');
                                    var hidden  = document.getElementById('field_omit_rules');
                                    var emptyEl = document.getElementById('sb-omit-empty');

                                    if (!hidden || !ptSel) { return; }

                                    function unionFields() {
                                        var m = {};
                                        Object.keys(fieldsByPt).forEach(function (pt) {
                                            var f = fieldsByPt[pt] || {};
                                            Object.keys(f).forEach(function (n) { if (!(n in m)) { m[n] = f[n]; } });
                                        });
                                        return m;
                                    }
                                    function fieldsFor(pt) { return pt === '__all__' ? unionFields() : (fieldsByPt[pt] || {}); }
                                    function labelFor(pt, field) { var f = fieldsFor(pt); return f[field] || field; }
                                    function ptLabel(pt) { return pt === '__all__' ? 'All post types' : (ptLabels[pt] || pt); }

                                    function rebuildDatalist() {
                                        var f = fieldsFor(ptSel.value);
                                        dl.innerHTML = '';
                                        Object.keys(f).sort().forEach(function (n) {
                                            var o = document.createElement('option');
                                            o.value = n;
                                            o.label = f[n] + ' [' + n + ']';
                                            o.textContent = f[n] + ' [' + n + ']';
                                            dl.appendChild(o);
                                        });
                                    }

                                    function syncHidden() {
                                        hidden.value = JSON.stringify(rules);
                                        if (window.jQuery) { try { jQuery(hidden).trigger('change'); } catch (e) {} }
                                        else { hidden.dispatchEvent(new Event('change', { bubbles: true })); }
                                    }

                                    function render() {
                                        pills.innerHTML = '';
                                        emptyEl.style.display = rules.length ? 'none' : 'block';

                                        rules.forEach(function (r, idx) {
                                            var isDetails = r.file === 'details';
                                            var isBoth    = r.file === 'both';
                                            var pill = document.createElement('span');
                                            pill.style.cssText =
                                                'display:inline-flex;align-items:center;gap:6px;padding:3px 6px 3px 10px;' +
                                                'border-radius:14px;font-size:12px;border:1px solid;' +
                                                (isBoth
                                                    ? 'border-color:#b32d2e;background:#fcf0f0;color:#b32d2e;'
                                                    : isDetails
                                                        ? 'border-color:#7c3aed;background:#f5f3ff;color:#6d28d9;'
                                                        : 'border-color:#2563eb;background:#eff6ff;color:#1d4ed8;');

                                            var label = document.createElement('span');
                                            label.textContent = (r.pt === '__all__' ? 'All' : ptLabel(r.pt)) + ' \u2192 ' + r.field + ' \u2192 ' + (isBoth ? 'Both' : isDetails ? 'Details' : 'Full');
                                            pill.appendChild(label);

                                            var x = document.createElement('button');
                                            x.type = 'button';
                                            x.setAttribute('aria-label', 'Remove rule');
                                            x.textContent = '\u00d7';
                                            x.style.cssText = 'cursor:pointer;border:none;background:transparent;font-size:15px;line-height:1;padding:0 2px;color:inherit;opacity:0.6;';
                                            x.addEventListener('mouseenter', function () { x.style.opacity = '1'; });
                                            x.addEventListener('mouseleave', function () { x.style.opacity = '0.6'; });
                                            x.addEventListener('click', function () { rules.splice(idx, 1); render(); syncHidden(); });
                                            pill.appendChild(x);

                                            pills.appendChild(pill);
                                        });
                                    }

                                    function add() {
                                        var pt    = ptSel.value;
                                        var field = (fldInp.value || '').trim().replace(/\s*\[[^\]]*\]\s*$/, '');
                                        var file  = fileSel.value;
                                        if (!field) { return; }
                                        // Only accept a field that actually exists for the chosen scope.
                                        if (!(field in fieldsFor(pt))) { return; }
                                        var dup = rules.some(function (r) { return r.pt === pt && r.field === field && r.file === file; });
                                        if (!dup) { rules.push({ pt: pt, field: field, file: file }); }
                                        fldInp.value = '';
                                        render();
                                        syncHidden();
                                        fldInp.focus();
                                    }

                                    ptSel.addEventListener('change', rebuildDatalist);
                                    addBtn.addEventListener('click', add);
                                    fldInp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(); } });

                                    rebuildDatalist();
                                    render();
                                    syncHidden();
                                })();
                                </script>
                            </td>
                        </tr>
                    </table>
                </div>
                <?php endif; ?>

                <?php
                // ---- File Serving (virtual vs physical) ----
                $serving_mode = $this->get_serving_mode();
                ?>
                <div style="<?php echo esc_attr( $card ); ?>">
                    <h2 style="margin-top:0;">File Serving</h2>
                    <p style="max-width:760px;color:#50575e;">
                        Choose how the three files are delivered. <strong>Virtual</strong> (recommended) serves them live from the cache at their web addresses, with nothing written to your site's root folder, so they can never fall out of sync. <strong>Physical</strong> writes real files to the root folder, the way earlier versions did.
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Mode</th>
                            <td>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="radio" name="serving_mode" value="virtual" <?php checked( $serving_mode, 'virtual' ); ?>>
                                    Virtual: serve live from the cache (no files on disk)
                                </label>
                                <label style="display:block;">
                                    <input type="radio" name="serving_mode" value="physical" <?php checked( $serving_mode, 'physical' ); ?>>
                                    Physical: write real files to the site root
                                </label>
                                <p class="description" style="margin-top:8px;">
                                    Switching to Virtual removes any physical files from the root folder so they can't shadow the live version. Virtual serving needs pretty permalinks (any Permalinks setting other than Plain).
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php
                // ---- Automatic Updates (WP-Cron) ----
                $cron_enabled  = ! empty( $settings['cron_enabled'] );
                $cron_interval = $this->get_valid_cron_interval( $settings['cron_interval'] ?? 'hourly' );
                $cron_last     = get_option( 'sbaike_cron_last_run', null ) ?: get_option( 'socialbump_cron_last_run', null );

                $interval_options = [
                    'socialbump_15min' => 'Every 15 minutes',
                    'socialbump_30min' => 'Every 30 minutes',
                    'hourly'           => 'Hourly',
                    'twicedaily'       => 'Twice daily',
                    'daily'            => 'Daily',
                ];
                ?>
                <div style="<?php echo esc_attr( $card ); ?>">
                    <h2 style="margin-top:0;">Automatic Updates</h2>
                    <p style="max-width:760px;color:#50575e;">
                        When enabled, a scheduled background task re-renders any changed posts and regenerates the files on its own, so you don't have to remember to click Update. The per-post-type status above is your backup: anything the schedule misses still shows there for a manual update.
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cron_enabled" value="1" <?php checked( $cron_enabled ); ?>>
                                    Automatically update files on a schedule
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cron_interval">How often</label></th>
                            <td>
                                <select name="cron_interval" id="cron_interval">
                                    <?php foreach ( $interval_options as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $cron_interval, $value ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description" style="margin-top:6px;">
                                    WordPress runs scheduled tasks on site visits, so the interval is a minimum, not an exact clock. On a quiet site there may be longer gaps between runs.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Last automatic update</th>
                            <td>
                                <?php
                                if ( ! is_array( $cron_last ) || empty( $cron_last['time'] ) ) {
                                    echo '<span style="color:#646970;">Has not run yet.</span>';
                                } else {
                                    $when = wp_date( 'g:i A, j M Y', (int) $cron_last['time'] );
                                    if ( ! empty( $cron_last['did_work'] ) ) {
                                        $n    = (int) ( $cron_last['count'] ?? 0 );
                                        $noun = $n === 1 ? 'post' : 'posts';
                                        $secs = isset( $cron_last['elapsed'] ) ? ' (' . esc_html( (string) $cron_last['elapsed'] ) . 's)' : '';
                                        echo '<span style="color:#15803d;">Re-rendered ' . esc_html( (string) $n ) . ' ' . esc_html( $noun ) . ' at ' . esc_html( $when ) . $secs . '.</span>';
                                    } else {
                                        echo '<span style="color:#646970;">Ran at ' . esc_html( $when ) . ' - nothing needed updating.</span>';
                                    }
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <p style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                    <button type="submit" class="button button-primary button-large" data-sb-always-on data-sb-idle="<?php echo $this->get_global_stale_count() > 0 ? '0' : '1'; ?>" name="socialbump_mode" value="update" data-sbaike-job="stale" data-sbaike-title="Updating changed posts">
                        Update Files
                    </button>
                    <button type="submit" class="button button-secondary button-large" data-sb-always-on name="socialbump_mode" value="rebuild" data-sbaike-job="everything" data-sbaike-title="Rebuilding everything">
                        Full Rebuild (all posts)
                    </button>
                    <button type="button" class="socialbump-save-button button button-large sb-save sb-save--clean" data-sb-save data-sb-label-dirty="Save changes" disabled title="No unsaved changes"><span data-sb-label>No changes to save</span></button>
                </p>

            </form>

            <?php
            // ---- Danger Zone ----
            //
            // Always shown when there's something to reset - either generated
            // files exist, or saved settings exist in wp_options. Both are
            // independently destructive so each gets its own button.
            $settings_saved = get_option( $this->option_name, null ) !== null;
            $show_danger    = $any_exists || $settings_saved;
            ?>

            <?php if ( $show_danger ) : ?>
                <hr style="margin:40px 0 24px;">

                <h2 style="margin-top:0;color:#b32d2e;">Danger Zone</h2>

                <p style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                    <?php if ( $any_exists ) : ?>
                        <?php
                        $existing_files = [];
                        if ( $slim_exists )    { $existing_files[] = 'llms.txt'; }
                        if ( $full_exists )    { $existing_files[] = 'llms-full.txt'; }
                        if ( $details_exists ) { $existing_files[] = 'llms-details.txt'; }
                        $file_count = count( $existing_files );
                        $files_list = implode( ', ', $existing_files );
                        ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                              style="display:inline;margin:0;"
                              onsubmit="return confirm('Delete <?php echo esc_js( $files_list ); ?>? You can always regenerate them above.');">
                            <input type="hidden" name="action" value="socialbump_delete_files">
                            <?php wp_nonce_field( 'socialbump_delete_files', 'socialbump_delete_files_nonce' ); ?>
                            <button type="submit" class="button button-secondary" style="color:#b32d2e;border-color:#b32d2e;">
                                <?php
                                if ( $file_count === 1 ) {
                                    echo 'Delete ' . esc_html( $existing_files[0] );
                                } elseif ( $file_count === 2 ) {
                                    echo 'Delete both files';
                                } else {
                                    echo 'Delete all ' . esc_html( (string) $file_count ) . ' files';
                                }
                                ?>
                            </button>
                        </form>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          style="display:inline;margin:0;"
                          onsubmit="return confirm('RESET EVERYTHING?\n\nThis will:\n• Delete all saved settings (post types, taxonomies, ACF picks, custom titles, business info, ordering)\n• Delete llms.txt, llms-full.txt and llms-details.txt if present\n\nThis cannot be undone. You will start fresh.\n\nProceed?');">
                        <input type="hidden" name="action" value="socialbump_reset_all">
                        <?php wp_nonce_field( 'socialbump_reset_all', 'socialbump_reset_all_nonce' ); ?>
                        <button type="submit" class="button button-secondary" style="color:#fff;background:#b32d2e;border-color:#b32d2e;">
                            Reset Everything
                        </button>
                    </form>
                </p>

                <p style="margin:8px 0 0;color:#666;font-size:13px;">
                    <strong>Reset Everything</strong> wipes all plugin settings from the database AND deletes all generated files and content saved to the database. Use this only when you want to start completely fresh.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // File generation
    // =========================================================================

    private function process_settings_post(): void {
        // Custom sanitiser for ACF field name arrays: preserves the
        // {post_content} pseudo-field token verbatim (since sanitize_key()
        // would strip the curly braces), while running sanitize_key on
        // every other entry.
        $sanitise_field_list = function ( $list ): array {
            $out = [];
            foreach ( (array) $list as $name ) {
                if ( ! is_string( $name ) ) {
                    continue;
                }
                if ( $name === self::POST_CONTENT_TOKEN ) {
                    $out[] = $name;
                } else {
                    $clean = sanitize_key( $name );
                    if ( $clean !== '' ) {
                        $out[] = $clean;
                    }
                }
            }
            return $out;
        };

        $existing = get_option( $this->option_name, [] );

        $settings = [
            'post_types'               => array_map( 'sanitize_key', (array) ( $_POST['post_types'] ?? [] ) ),
            'post_types_order'         => array_map( 'sanitize_key', (array) ( $_POST['post_types_order'] ?? [] ) ),
            'taxonomies'               => array_map( 'sanitize_key', (array) ( $_POST['taxonomies'] ?? [] ) ),
            'taxonomies_order'         => array_map( 'sanitize_key', (array) ( $_POST['taxonomies_order'] ?? [] ) ),
            'acf_fields'               => [],
            'acf_fields_order'         => [],
            'acf_options_fields'       => array_map( 'sanitize_key', (array) ( $_POST['acf_options_fields'] ?? [] ) ),
            'acf_options_fields_order' => array_map( 'sanitize_key', (array) ( $_POST['acf_options_fields_order'] ?? [] ) ),
            // Term-level ACF picks are populated by the loops below.
            'acf_term_fields'          => [],
            'acf_term_fields_order'    => [],
            // Preserve existing excluded_posts; per-post-type entries are
            // overwritten below when their picker marker was submitted.
            'excluded_posts'           => is_array( $existing['excluded_posts'] ?? null ) ? $existing['excluded_posts'] : [],
            'business_name'          => sanitize_text_field( $_POST['business_name'] ?? '' ),
            'business_description'   => sanitize_textarea_field( $_POST['business_description'] ?? '' ),
            'compliance_notes_title' => sanitize_text_field( $_POST['compliance_notes_title'] ?? '' ),
            'compliance_notes'       => sanitize_textarea_field( $_POST['compliance_notes'] ?? '' ),
            // The pill UI serialises its list into this hidden field as
            // newline-separated selectors on submit. Saving the form marks
            // the list initialised so the renderer honours it exactly
            // (including an empty list) rather than falling back to the
            // default seed.
            'renderer_strip_selectors' => $this->sanitise_selector_list( $_POST['renderer_strip_selectors'] ?? '' ),
            'renderer_selectors_initialised' => true,
            'field_omit_rules' => $this->parse_field_omit_rules( $_POST['field_omit_rules'] ?? '' ),
            'cron_enabled'  => ! empty( $_POST['cron_enabled'] ),
            'cron_interval' => $this->get_valid_cron_interval( sanitize_text_field( $_POST['cron_interval'] ?? 'hourly' ) ),
            'serving_mode'  => ( ( $_POST['serving_mode'] ?? 'virtual' ) === 'physical' ) ? 'physical' : 'virtual',
        ];

        if ( ! empty( $_POST['acf_fields'] ) && is_array( $_POST['acf_fields'] ) ) {
            foreach ( $_POST['acf_fields'] as $post_type => $fields ) {
                $settings['acf_fields'][ sanitize_key( $post_type ) ] = $sanitise_field_list( $fields );
            }
        }

        if ( ! empty( $_POST['acf_fields_order'] ) && is_array( $_POST['acf_fields_order'] ) ) {
            foreach ( $_POST['acf_fields_order'] as $post_type => $fields ) {
                $settings['acf_fields_order'][ sanitize_key( $post_type ) ] = $sanitise_field_list( $fields );
            }
        }

        // Term-level ACF field picks, keyed by taxonomy. Term field names
        // are plain ACF names (no {post_content} token), so sanitize_key is
        // sufficient without the field-list helper used for posts.
        if ( ! empty( $_POST['acf_term_fields'] ) && is_array( $_POST['acf_term_fields'] ) ) {
            foreach ( $_POST['acf_term_fields'] as $taxonomy => $fields ) {
                $settings['acf_term_fields'][ sanitize_key( $taxonomy ) ] = array_map( 'sanitize_key', (array) $fields );
            }
        }

        if ( ! empty( $_POST['acf_term_fields_order'] ) && is_array( $_POST['acf_term_fields_order'] ) ) {
            foreach ( $_POST['acf_term_fields_order'] as $taxonomy => $fields ) {
                $settings['acf_term_fields_order'][ sanitize_key( $taxonomy ) ] = array_map( 'sanitize_key', (array) $fields );
            }
        }

        // Excluded posts: per-post-type list of post IDs the user has
        // unticked. We use a "marker" hidden input per rendered picker so
        // we can tell the difference between "picker not on page" (don't
        // touch saved value) and "picker on page with no exclusions"
        // (overwrite to empty array).
        if ( ! empty( $_POST['excluded_posts_marker'] ) && is_array( $_POST['excluded_posts_marker'] ) ) {
            foreach ( $_POST['excluded_posts_marker'] as $post_type => $marker ) {
                $pt = sanitize_key( $post_type );
                $ids = $_POST['excluded_posts'][ $post_type ] ?? [];
                $clean_ids = array_map( 'absint', (array) $ids );
                $clean_ids = array_filter( $clean_ids );
                $settings['excluded_posts'][ $pt ] = array_values( $clean_ids );
            }
        }


        /**
         * Keep what this page did not show.
         *
         * The settings live on more than one page now, so a saved page only
         * carries its own fields. Each page says which sections it drew, and
         * anything belonging to a section that was not on the page is carried
         * over from what is already saved rather than rebuilt from an empty
         * POST. A page that sends no marker at all, as the single page did,
         * saves everything exactly as before.
         */
        $drawn = isset( $_POST['sbaike_sections'] ) ? array_map( 'sanitize_key', (array) $_POST['sbaike_sections'] ) : [];

        if ( $drawn ) {
            $owned = [
                'business'       => [ 'business_name', 'business_description', 'compliance_notes_title', 'compliance_notes' ],
                'acf-options'    => [ 'acf_options_fields', 'acf_options_fields_order' ],
                'taxonomies'     => [ 'taxonomies', 'taxonomies_order', 'acf_term_fields', 'acf_term_fields_order' ],
                'post-types'     => [ 'post_types', 'post_types_order', 'acf_fields', 'acf_fields_order', 'excluded_posts' ],
                'rendering'      => [ 'renderer_strip_selectors', 'renderer_selectors_initialised' ],
                'acf-visibility' => [ 'field_omit_rules' ],
                'serving'        => [ 'serving_mode' ],
                'updates'        => [ 'cron_enabled', 'cron_interval' ],
            ];

            foreach ( $owned as $section => $keys ) {
                if ( in_array( $section, $drawn, true ) ) {
                    continue;
                }

                foreach ( $keys as $key ) {
                    if ( array_key_exists( $key, $existing ) ) {
                        $settings[ $key ] = $existing[ $key ];
                    }
                }
            }
        }
        update_option( $this->option_name, $settings );
        $this->clear_request_caches();

        // Settings (interval / toggle) may have changed - realign the
        // scheduled event immediately rather than waiting for the next
        // init.
        $this->sync_cron_event();

        // If the serving mode just switched to virtual, remove any
        // physical files from the root so they can't shadow the live
        // routes. The virtual store is repopulated by the regenerate
        // step that runs straight after this save.
        $old_mode = ( ( $existing['serving_mode'] ?? 'virtual' ) === 'physical' ) ? 'physical' : 'virtual';
        if ( $old_mode !== $settings['serving_mode'] && $settings['serving_mode'] === 'virtual' ) {
            $this->delete_physical_files();
        }
    }

    public function save_and_generate_action(): void {
        if (
            ! current_user_can( 'manage_options' ) ||
            empty( $_POST['socialbump_save_and_generate_nonce'] ) ||
            ! wp_verify_nonce( $_POST['socialbump_save_and_generate_nonce'], 'socialbump_save_and_generate' )
        ) {
            wp_die( 'Permission denied.' );
        }

        // Always save settings first, whichever button was clicked. This
        // means you can never regenerate with unsaved settings.
        $this->process_settings_post();

        // Which button: "update" (stale only) or "rebuild" (force all).
        // Default to update if somehow missing.
        $mode = ( ( $_POST['socialbump_mode'] ?? 'update' ) === 'rebuild' ) ? 'rebuild' : 'update';

        $user_id = get_current_user_id();
        $start   = microtime( true );

        if ( $mode === 'rebuild' ) {
            // Full Rebuild: wipe all caches, re-render everything.
            $total = $this->count_eligible_posts();
            $this->clear_all_post_caches();

            $ok = $this->regenerate_outputs();

            $elapsed = round( microtime( true ) - $start, 1 );

            if ( $ok ) {
                set_transient( 'sbaike_rebuild_notice_' . $user_id, [
                    'elapsed' => $elapsed,
                    'total'   => $total,
                ], 30 );
            } else {
                set_transient( 'sbaike_rebuild_notice_' . $user_id, [ 'error' => true ], 30 );
            }
        } else {
            // Update: gather the stale list (for the report) BEFORE
            // building, then re-render stale only via the cache.
            $rebuilt = $this->get_stale_posts_report();

            $ok = $this->regenerate_outputs();

            $elapsed = round( microtime( true ) - $start, 1 );

            if ( $ok ) {
                set_transient( 'sbaike_update_notice_' . $user_id, [
                    'elapsed' => $elapsed,
                    'count'   => count( $rebuilt ),
                    'posts'   => $rebuilt,
                ], 60 );
            } else {
                set_transient( 'sbaike_update_notice_' . $user_id, [ 'error' => true ], 60 );
            }
        }

        // Authoritatively recompute the global stale count so the admin bar
        // dot/label can't disagree with what this action just did.
        $this->get_global_stale_count( true );

        wp_safe_redirect( admin_url( 'admin.php?page=sb-ai-knowledge-exporter' ) );
        exit;
    }

    /**
     * Delete llms.txt, llms-full.txt and llms-details.txt from the
     * WordPress root. Any may not exist - that's fine, only existing
     * files are targeted. Uses WP_Filesystem so we behave the same way
     * write_file() does (consistent ownership/permissions handling).
     */
    public function delete_files_action(): void {
        if (
            ! current_user_can( 'manage_options' ) ||
            empty( $_POST['socialbump_delete_files_nonce'] ) ||
            ! wp_verify_nonce( $_POST['socialbump_delete_files_nonce'], 'socialbump_delete_files' )
        ) {
            wp_die( 'Permission denied.' );
        }

        $result = $this->delete_physical_files();

        if ( $result['failed'] ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sb-ai-knowledge-exporter&error=' . rawurlencode( 'Could not delete: ' . implode( ', ', $result['failed'] ) . '. Check file permissions.' ) ) );
            exit;
        }

        if ( $result['deleted'] ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sb-ai-knowledge-exporter&deleted=' . rawurlencode( implode( ', ', $result['deleted'] ) ) ) );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=sb-ai-knowledge-exporter' ) );
        exit;
    }

    /**
     * Reset Everything - wipes the plugin's saved settings from wp_options
     * AND deletes both generated files. Equivalent to a fresh install. The
     * user is left on the settings page with all defaults restored and no
     * generated files in the WordPress root.
     *
     * No way back from this - the confirmation dialog in the picker UI is
     * the only safety net.
     */
    public function reset_all_action(): void {
        if (
            ! current_user_can( 'manage_options' ) ||
            empty( $_POST['socialbump_reset_all_nonce'] ) ||
            ! wp_verify_nonce( $_POST['socialbump_reset_all_nonce'], 'socialbump_reset_all' )
        ) {
            wp_die( 'Permission denied.' );
        }

        // Wipe settings from wp_options. delete_option() returns true if a
        // row existed and was deleted, false if the option didn't exist.
        // Either is fine for our purposes - we just want it gone.
        delete_option( $this->option_name );
        $this->clear_request_caches();

        // Tear down the scheduled cron event and the last-run note, and
        // wipe every per-post cache entry so a reset truly starts fresh.
        wp_clear_scheduled_hook( self::CRON_HOOK );
        delete_option( 'sbaike_cron_last_run' );
        delete_option( 'socialbump_cron_last_run' );
        delete_option( 'socialbump_ai_virtual_store' );
        $this->clear_all_post_caches();

        // Delete the generated files if they exist. Failures here aren't
        // fatal (the settings wipe still succeeded) but we report them.
        require_once ABSPATH . 'wp-admin/includes/file.php';

        global $wp_filesystem;

        $file_failed = [];

        if ( WP_Filesystem() ) {
            foreach ( [ 'llms.txt', 'llms-full.txt', 'llms-details.txt' ] as $filename ) {
                $path = ABSPATH . $filename;
                if ( $wp_filesystem->exists( $path ) && ! $wp_filesystem->delete( $path ) ) {
                    $file_failed[] = $filename;
                }
            }
        }

        if ( $file_failed ) {
            wp_safe_redirect( admin_url(
                'admin.php?page=sb-ai-knowledge-exporter&error=' .
                rawurlencode( 'Settings were reset, but could not delete: ' . implode( ', ', $file_failed ) . '. Check file permissions.' )
            ) );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=sb-ai-knowledge-exporter&reset=1' ) );
        exit;
    }

    /**
     * Regenerate llms.txt and llms-full.txt using the currently-saved
     * settings (no settings save). Triggered from the admin bar Rebuild
     * button so the user can refresh the files from anywhere in the admin.
     *
     * On success/failure, a transient is stamped per-user so the
     * admin_notices hook shows feedback on whichever page the user lands
     * on next. After the action runs, redirects back to wp_get_referer()
     * so the user stays where they were.
     */
    /**
     * Render one post and put it in the cache, without touching the files.
     *
     * The batch runner works through a list of posts a few at a time, so it
     * needs to do the expensive part on its own. Assembling the files is a
     * separate, cheap step once everything is cached.
     */
    public function cache_post( $post_id ): bool {
        $post = get_post( (int) $post_id );

        if ( ! $post || $post->post_status !== 'publish' ) {
            return false;
        }

        $this->render_and_cache_post_markdown( $post );

        return true;
    }

    /** Reassemble the files from what is already cached. */
    public function build_files(): bool {
        return (bool) $this->regenerate_outputs();
    }

    /** Posts of one type that are in the export, oldest cache first. */
    public function exportable_post_ids( string $post_type, bool $stale_only = false ): array {
        $settings = $this->get_settings();
        $ids      = [];

        foreach ( (array) $this->get_posts_for_picker( $post_type ) as $post ) {
            if ( $post->post_status !== 'publish' ) {
                continue;
            }

            if ( $this->is_post_excluded( $post->ID, $post_type, $settings ) ) {
                continue;
            }

            if ( $stale_only && $this->post_cache_is_fresh( $post ) ) {
                continue;
            }

            $ids[] = (int) $post->ID;
        }

        return $ids;
    }

    /** The post types currently chosen for export. */
    public function exported_post_types(): array {
        $settings = $this->get_settings();

        return array_values( (array) ( $settings['post_types'] ?? [] ) );
    }
    public function rebuild_files_action(): void {
        if (
            ! current_user_can( 'manage_options' ) ||
            empty( $_GET['_wpnonce'] ) ||
            ! wp_verify_nonce( $_GET['_wpnonce'], 'socialbump_rebuild_files' )
        ) {
            wp_die( 'Permission denied.' );
        }

        $start = microtime( true );

        // Full Rebuild: wipe every cache entry first so all posts re-render
        // from scratch. This is the "force everything" path - use it after
        // template/design changes that don't bump post modified dates.
        $total = $this->count_eligible_posts();
        $this->clear_all_post_caches();

        $ok = $this->regenerate_outputs();

        $elapsed = round( microtime( true ) - $start, 1 );

        $user_id = get_current_user_id();

        if ( $ok ) {
            set_transient( 'sbaike_rebuild_notice_' . $user_id, [
                'elapsed' => $elapsed,
                'total'   => $total,
            ], 30 );
        } else {
            set_transient( 'sbaike_rebuild_notice_' . $user_id, [ 'error' => true ], 30 );
        }

        // Always land on the settings page so the result notice is seen,
        // regardless of whether the action was triggered from the admin
        // bar on some other screen.
        wp_safe_redirect( admin_url( 'admin.php?page=sb-ai-knowledge-exporter' ) );
        exit;
    }

    /**
     * Update action: re-render only the posts whose cache is stale or
     * missing, then reassemble the files. Cheap compared to a full
     * rebuild - this is the everyday "pick up my recent edits" button.
     *
     * We gather the stale-post list BEFORE building (the build freshens
     * them as it renders), then stash that list in a transient so the
     * result notice can show what was rebuilt.
     */
    public function update_files_action(): void {
        if (
            ! current_user_can( 'manage_options' ) ||
            empty( $_GET['_wpnonce'] ) ||
            ! wp_verify_nonce( $_GET['_wpnonce'], 'socialbump_update_files' )
        ) {
            wp_die( 'Permission denied.' );
        }

        $start = microtime( true );

        // Snapshot what's about to be re-rendered, for the report.
        $rebuilt = $this->get_stale_posts_report();

        // The build re-renders stale posts on cache miss and reads the
        // rest from cache.
        $ok = $this->regenerate_outputs();

        $elapsed = round( microtime( true ) - $start, 1 );

        $user_id = get_current_user_id();

        if ( $ok ) {
            set_transient( 'sbaike_update_notice_' . $user_id, [
                'elapsed' => $elapsed,
                'count'   => count( $rebuilt ),
                'posts'   => $rebuilt,
            ], 60 );
        } else {
            set_transient( 'sbaike_update_notice_' . $user_id, [ 'error' => true ], 60 );
        }

        // Authoritatively recompute the global stale count so the admin bar
        // dot/label reflects what this action just did.
        $this->get_global_stale_count( true );

        // Return to the page the user triggered this from (e.g. a post edit
        // screen via the admin bar) rather than yanking them to the settings
        // page. The result notice is a per-user transient shown on any admin
        // page, so it'll appear wherever they land. Falls back to the
        // settings page if there's no usable referer.
        $back = wp_get_referer();
        if ( ! $back || strpos( $back, 'wp-login.php' ) !== false ) {
            $back = admin_url( 'admin.php?page=sb-ai-knowledge-exporter' );
        }

        wp_safe_redirect( $back );
        exit;
    }

    /**
     * Rebuild a single post type: clear the cache for just that type's
     * posts, then reassemble all three files. Everything else stays warm,
     * so this is fast even though that one type re-renders fully.
     *
     * Useful after a template change that only affects one post type (e.g.
     * you redesigned the Service single template) - no need to wipe the
     * whole cache.
     */
    public function rebuild_post_type_action(): void {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';

        if (
            ! current_user_can( 'manage_options' ) ||
            $post_type === '' ||
            empty( $_GET['_wpnonce'] ) ||
            ! wp_verify_nonce( $_GET['_wpnonce'], 'socialbump_rebuild_post_type_' . $post_type )
        ) {
            wp_die( 'Permission denied.' );
        }

        $start   = microtime( true );
        $user_id = get_current_user_id();

        $pt_obj      = get_post_type_object( $post_type );
        $type_label  = $pt_obj ? ( $pt_obj->labels->name ?? $post_type ) : $post_type;

        // Count this type's eligible posts (for the report), clear its
        // cache, then rebuild. The build re-renders this type (cache now
        // empty for it) and serves all other types from their warm cache.
        $total = $this->count_eligible_posts_for_type( $post_type );
        $this->clear_post_type_cache( $post_type );

        $ok = $this->regenerate_outputs();

        $elapsed = round( microtime( true ) - $start, 1 );

        if ( $ok ) {
            set_transient( 'sbaike_pt_rebuild_notice_' . $user_id, [
                'label'   => $type_label,
                'total'   => $total,
                'elapsed' => $elapsed,
            ], 30 );
        } else {
            set_transient( 'sbaike_pt_rebuild_notice_' . $user_id, [ 'error' => true ], 30 );
        }

        $this->get_global_stale_count( true );

        wp_safe_redirect( admin_url( 'admin.php?page=sb-ai-knowledge-exporter' ) );
        exit;
    }

    /**
     * Update a single post type: re-render only the stale/missing posts in
     * that type (NOT a forced rebuild), then reassemble the files. The
     * surgical per-type counterpart to the global Update Files button.
     *
     * Unlike rebuild_post_type_action(), this does NOT clear the type's
     * cache first - it relies on the normal cache-miss path so only posts
     * that are actually stale re-render.
     */
    public function update_post_type_action(): void {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';

        if (
            ! current_user_can( 'manage_options' ) ||
            $post_type === '' ||
            empty( $_GET['_wpnonce'] ) ||
            ! wp_verify_nonce( $_GET['_wpnonce'], 'socialbump_update_post_type_' . $post_type )
        ) {
            wp_die( 'Permission denied.' );
        }

        $start   = microtime( true );
        $user_id = get_current_user_id();

        $pt_obj     = get_post_type_object( $post_type );
        $type_label = $pt_obj ? ( $pt_obj->labels->name ?? $post_type ) : $post_type;

        // Count how many will re-render (the stale ones) BEFORE building -
        // the build freshens them as a side effect. No cache clearing, so
        // only stale posts re-render via the normal cache-miss path.
        $stale_count = $this->count_stale_posts_for_type( $post_type );

        $ok = $this->regenerate_outputs();

        $elapsed = round( microtime( true ) - $start, 1 );

        if ( $ok ) {
            set_transient( 'sbaike_pt_update_notice_' . $user_id, [
                'label'   => $type_label,
                'count'   => $stale_count,
                'elapsed' => $elapsed,
            ], 30 );
        } else {
            set_transient( 'sbaike_pt_update_notice_' . $user_id, [ 'error' => true ], 30 );
        }

        $this->get_global_stale_count( true );

        wp_safe_redirect( admin_url( 'admin.php?page=sb-ai-knowledge-exporter' ) );
        exit;
    }

    /**
     * Add the SEO for AI menu to the WordPress admin bar.
     *
     * Only visible to users with manage_options capability so editors and
     * lower roles never see it. The parent link rebuilds files via
     * admin-post.php with a one-shot nonce. The child link points to the
     * settings page under Tools.
     */
    public function add_admin_bar_button( $wp_admin_bar ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings_url = admin_url( 'admin.php?page=' . SBAIKE_Admin::PAGE_SLUG );

        $rebuild_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=socialbump_rebuild_files' ),
            'socialbump_rebuild_files'
        );

        $update_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=socialbump_update_files' ),
            'socialbump_update_files'
        );

        // Global stale count drives the dot colour and the Update label.
        // Backed by a transient so this is a single cheap read.
        $stale = $this->get_global_stale_count();

        // Coloured dot in the menu title: green when everything's current,
        // orange when there's drift. Inline SVG-free - a styled bullet.
        $dot_colour = $stale > 0 ? '#d97706' : '#46b450';
        $dot        = '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' . $dot_colour . ';margin-right:7px;vertical-align:middle;"></span>';

        // Top-level: opens the settings page. Most-frequent destination.
        $wp_admin_bar->add_node( [
            'id'    => 'socialbump-ai-knowledge',
            'title' => $dot . 'SEO for AI',
            'href'  => $settings_url,
            'meta'  => [
                'title' => $stale > 0
                    ? ( $stale === 1 ? '1 post needs updating' : $stale . ' posts need updating' )
                    : 'All content is up to date',
            ],
        ] );

        // Dropdown item 1: Update - re-render only changed posts. The
        // everyday button. Label shows the count; disabled when nothing's
        // stale (WP admin bar dims items with the 'disabled' class and we
        // drop the href so the click does nothing).
        if ( $stale > 0 ) {
            $update_label = $stale === 1 ? 'Update 1 Post' : 'Update ' . $stale . ' Posts';
            $wp_admin_bar->add_node( [
                'parent' => 'socialbump-ai-knowledge',
                'id'     => 'socialbump-ai-knowledge-update',
                'title'  => $update_label,
                'href'   => $update_url,
                'meta'   => [
                    'title' => 'Re-render only posts that have changed since the last build, then regenerate the files',
                ],
            ] );
        } else {
            $wp_admin_bar->add_node( [
                'parent' => 'socialbump-ai-knowledge',
                'id'     => 'socialbump-ai-knowledge-update',
                'title'  => 'Nothing to update',
                'href'   => false,
                'meta'   => [
                    'class' => 'socialbump-ab-disabled',
                    'title' => 'Everything is already up to date',
                    'html'  => '<style>#wp-admin-bar-socialbump-ai-knowledge-update.socialbump-ab-disabled > .ab-item{opacity:0.5;cursor:default;pointer-events:none;}</style>',
                ],
            ] );
        }

        // Dropdown item 2: Full Rebuild - clear all caches and re-render
        // everything. The occasional "force it" button.
        $wp_admin_bar->add_node( [
            'parent' => 'socialbump-ai-knowledge',
            'id'     => 'socialbump-ai-knowledge-rebuild',
            'title'  => 'Full Rebuild (all posts)',
            'href'   => $rebuild_url,
            'meta'   => [
                'title' => 'Clear the cache and re-render every post from scratch, then regenerate the files',
            ],
        ] );
    }

    // =========================================================================
    // Per-post edit-screen meta box
    //
    // Shows the cache status (fresh / stale / not-yet-cached) for the post
    // being edited, plus a one-click "update this post" button. Only
    // registered on post types that are selected for export.
    // =========================================================================

    /**
     * Register the meta box on every selected post type's edit screen.
     */
    public function register_post_meta_box(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings   = $this->get_settings();
        $post_types = (array) ( $settings['post_types'] ?? [] );

        foreach ( $post_types as $post_type ) {
            if ( ! post_type_exists( $post_type ) ) {
                continue;
            }

            add_meta_box(
                'socialbump_ai_knowledge_box',
                'SEO for AI',
                [ $this, 'render_post_meta_box' ],
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the meta box contents for a given post.
     */
    public function render_post_meta_box( WP_Post $post ): void {
        $settings   = $this->get_settings();
        $is_published = ( $post->post_status === 'publish' );
        $is_excluded  = $this->is_post_object_excluded( $post, $post->post_type, $settings );

        // Case 1: not published - nothing to cache, it's not in the export.
        if ( ! $is_published ) {
            echo '<div style="font-size:13px;line-height:1.5;">';
            echo '<p style="margin:6px 0;color:#646970;">'
                . '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#c3c4c7;margin-right:6px;"></span>'
                . 'This ' . esc_html( strtolower( get_post_type_object( $post->post_type )->labels->singular_name ?? 'post' ) )
                . ' isn\'t published, so it isn\'t included in the export yet.</p>';
            echo '</div>';
            return;
        }

        // Case 2: published but excluded via the picker.
        if ( $is_excluded ) {
            echo '<div style="font-size:13px;line-height:1.5;">';
            echo '<p style="margin:6px 0;color:#646970;">'
                . '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#c3c4c7;margin-right:6px;"></span>'
                . 'This post is excluded from the export in the SEO for AI settings.</p>';
            echo '</div>';
            return;
        }

        // Published + included: show freshness.
        $fresh     = $this->post_cache_is_fresh( $post );
        $cache     = get_post_meta( $post->ID, self::CACHE_META_KEY, true );
        $cached_at = ( is_array( $cache ) && isset( $cache['cached_at'] ) ) ? (int) $cache['cached_at'] : 0;

        $update_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=socialbump_update_single_post&post_id=' . $post->ID ),
            'socialbump_update_single_post_' . $post->ID
        );

        $cached_label = $cached_at > 0
            ? ( human_time_diff( $cached_at, time() ) . ' ago' )
            : 'never';

        // Everything renders every time; the JS sets the correct visual
        // state from the initial fresh/stale flag plus live dirty state.
        // data-fresh tells the JS whether the post started up to date.
        ?>
        <div id="socialbump-metabox" data-fresh="<?php echo $fresh ? '1' : '0'; ?>" style="font-size:13px;line-height:1.5;">
            <p style="margin:6px 0;">
                <span id="socialbump-mb-dot" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#46b450;margin-right:6px;"></span>
                <strong id="socialbump-mb-status">Up to date</strong>
            </p>

            <p style="margin:6px 0;color:#646970;">Last cached: <?php echo esc_html( $cached_label ); ?></p>

            <a href="<?php echo esc_url( $update_url ); ?>"
                id="socialbump-update-single"
                class="button button-primary"
                style="display:none;margin-top:6px;width:100%;text-align:center;box-sizing:border-box;">
                Update this post's cache
            </a>

            <p id="socialbump-update-single-savefirst" style="display:none;margin:8px 0 2px;color:#b45309;font-size:12px;">
                Save the post first, then update the cache.
            </p>
        </div>

        <script>
        (function () {
            var box  = document.getElementById('socialbump-metabox');
            if (!box) { return; }

            var dot       = document.getElementById('socialbump-mb-dot');
            var status    = document.getElementById('socialbump-mb-status');
            var btn       = document.getElementById('socialbump-update-single');
            var saveFirst = document.getElementById('socialbump-update-single-savefirst');
            var hint      = btn.getAttribute('href');
            var startedFresh = box.getAttribute('data-fresh') === '1';

            var GREEN  = '#46b450';
            var ORANGE = '#d97706';

            // Three visual states:
            //  fresh-clean   → green, no button
            //  dirty         → orange, greyed button + "save first" (any start)
            //  stale-saved   → orange, active button
            function showFreshClean() {
                dot.style.background = GREEN;
                status.textContent = 'Up to date';
                btn.style.display = 'none';
                saveFirst.style.display = 'none';
            }
            function showDirty() {
                dot.style.background = ORANGE;
                status.textContent = 'Unsaved changes';
                btn.style.display = 'block';
                btn.classList.add('disabled');
                btn.setAttribute('aria-disabled', 'true');
                btn.style.pointerEvents = 'none';
                btn.style.opacity = '0.5';
                btn.removeAttribute('href');
                saveFirst.style.display = 'block';
            }
            function showStaleSaved() {
                dot.style.background = ORANGE;
                status.textContent = 'Needs update';
                btn.style.display = 'block';
                btn.classList.remove('disabled');
                btn.removeAttribute('aria-disabled');
                btn.style.pointerEvents = '';
                btn.style.opacity = '';
                btn.setAttribute('href', hint);
                saveFirst.style.display = 'none';
            }

            // Initial paint from the server-provided state.
            if (startedFresh) {
                showFreshClean();
            } else {
                showStaleSaved();
            }

            // When the editor becomes dirty we always show the "save first"
            // state, regardless of starting point. When it goes clean again
            // we fall back to whatever the post's saved state was.
            function onDirty() { showDirty(); }
            function onClean() { startedFresh ? showFreshClean() : showStaleSaved(); }

            // Gutenberg path.
            if (window.wp && wp.data && wp.data.select && wp.data.select('core/editor')) {
                var editor = wp.data.select('core/editor');
                var wasDirty   = null;
                var wasSaving  = false;
                // Tracks whether the post has been edited+saved in THIS
                // session via Gutenberg's AJAX save (no page reload). Once
                // that happens the cache is stale even though the editor
                // reports "not dirty", so we must show "Needs update".
                var savedThisSession = false;

                setInterval(function () {
                    try {
                        // Detect a save completing: isSavingPost goes
                        // true → false. After a successful AJAX save the
                        // post's published content has changed, so the
                        // cache is now stale until we rebuild it.
                        var saving = editor.isSavingPost() && ! editor.isAutosavingPost();
                        if (wasSaving && ! saving) {
                            // A save just finished.
                            if ( ! editor.didPostSaveRequestFail() ) {
                                savedThisSession = true;
                                showStaleSaved();
                            }
                        }
                        wasSaving = saving;

                        var dirty = editor.isEditedPostDirty();
                        if (dirty !== wasDirty) {
                            wasDirty = dirty;
                            if (dirty) {
                                onDirty();
                            } else {
                                // Editor went clean. If that was because of
                                // a save we did this session, the post is
                                // stale - keep "Needs update". Otherwise
                                // (e.g. undo back to saved) use the start
                                // state.
                                savedThisSession ? showStaleSaved() : onClean();
                            }
                        }
                    } catch (e) {}
                }, 500);
                return;
            }

            // Classic editor path: any change to the form marks dirty. The
            // classic editor reloads on save, so we don't need a clean
            // transition - a fresh page load re-runs the initial paint.
            var form = document.getElementById('post');
            if (form) {
                form.addEventListener('change', onDirty, true);
                form.addEventListener('input', onDirty, true);
                // TinyMCE (classic content area): use the editor's own
                // change/dirty events, NOT keyup - keyup fires on arrow
                // keys and other navigation that doesn't change content.
                // SetContent/ExecCommand/input cover real edits; 'dirty'
                // fires once when the editor first becomes modified.
                if (window.jQuery) {
                    jQuery(document).on('tinymce-editor-init', function (event, editor) {
                        editor.on('Dirty change input SetContent ExecCommand Undo Redo', onDirty);
                    });
                }
            }
        })();
        </script>
        <?php
    }

    /**
     * Update a single post's cache (re-render it) and rebuild the files.
     * Triggered from the edit-screen meta box. Returns to the edit screen.
     */
    public function update_single_post_action(): void {
        $post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;

        if (
            ! current_user_can( 'manage_options' ) ||
            $post_id <= 0 ||
            empty( $_GET['_wpnonce'] ) ||
            ! wp_verify_nonce( $_GET['_wpnonce'], 'socialbump_update_single_post_' . $post_id )
        ) {
            wp_die( 'Permission denied.' );
        }

        $post = get_post( $post_id );

        if ( $post ) {
            // Drop this post's cache so the rebuild re-renders it fresh.
            $this->clear_post_cache( $post_id );

            // Reassemble the files (this re-renders the now-stale post on
            // its cache miss, serves the rest from cache).
            $this->regenerate_outputs();

            set_transient( 'sbaike_single_notice_' . get_current_user_id(), [
                'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
            ], 30 );
        }

        $this->get_global_stale_count( true );

        // Back to the edit screen for this post.
        $back = get_edit_post_link( $post_id, 'url' );
        if ( ! $back ) {
            $back = admin_url();
        }

        wp_safe_redirect( $back );
        exit;
    }


    private function build_llms_slim(): string {
        $settings = $this->get_settings();
        $out      = [];

        $site_name = $settings['business_name'] ?: html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $site_desc = $settings['business_description'] ?: html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        $out[] = '# ' . $site_name . ' AI Discovery File';
        $out[] = str_repeat( '=', 80 );
        $out[] = '';
        $out = array_merge( $out, $this->get_generator_header() );
        $out[] = 'Website: ' . home_url( '/' );
        $out[] = 'Full Knowledge Map: ' . home_url( '/llms-full.txt' );
        $out[] = '';

        // ACF options data in slim file header
        $acf_options_slim = $this->build_acf_options_output( $settings );

        $business_lines = [
            '## BUSINESS INFORMATION',
            'Business Name: ' . $site_name,
            'Business Description: ' . $site_desc,
        ];

        if ( $acf_options_slim ) {
            $business_lines[] = $acf_options_slim;
        }

        $out[] = '';
        $out[] = implode( "\n", $business_lines );

        $out[] = '';

        foreach ( $this->get_additional_info_block( $settings ) as $line ) {
            $out[] = $line;
        }

        // --- Taxonomies as their own sections ---
        foreach ( $settings['taxonomies'] as $tax_slug ) {
            $tax_obj = get_taxonomy( $tax_slug );

            if ( ! $tax_obj ) {
                continue;
            }

            $terms = get_terms( [
                'taxonomy'   => $tax_slug,
                'hide_empty' => false,
            ] );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            $out[] = '## ' . $tax_obj->labels->name;

            foreach ( $terms as $term ) {
                $line = '- ' . $term->name;

                if ( $term->description ) {
                    $line .= ': ' . $this->truncate( $this->clean_content( $term->description ), 170 );
                }

                $out[] = $line;
            }

            $out[] = '';
        }

        // --- Post types ---
        foreach ( $settings['post_types'] as $post_type ) {
            $is_blog = ( $post_type === 'post' );

            $items = $this->get_posts_cached( [
                'post_type'      => $post_type,
                'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
                'posts_per_page' => -1,
                'orderby'        => $is_blog ? 'date' : 'menu_order title',
                'order'          => $is_blog ? 'DESC' : 'ASC',
            ] );

            if ( ! $items ) {
                continue;
            }

            $pto   = get_post_type_object( $post_type );
            $out[] = '## ' . ( $pto ? $pto->labels->name : $post_type );

            foreach ( $items as $item ) {
                if ( $this->is_post_object_excluded( $item, $post_type, $settings ) ) {
                    continue;
                }

                $title   = $this->get_display_title( $item );
                $url     = get_permalink( $item );
                $excerpt = $this->truncate( $this->get_excerpt( $item ), 170 );

                $tax_parts = $this->get_taxonomy_metadata_for_post( $item->ID, $post_type, $settings['taxonomies'] );

                $line = '- [' . $title . '](' . $url . ')';

                if ( $tax_parts ) {
                    $line .= ' | ' . implode( ' | ', $tax_parts );
                }

                if ( $excerpt ) {
                    $line .= ': ' . $excerpt;
                }

                $out[] = $line;
            }

            $out[] = '';
        }

        return implode( "\n", $out );
    }

    // =========================================================================
    // FULL FILE - llms-full.txt / llms-details.txt
    //
    // One builder produces both files. The $details_mode flag controls
    // what's different:
    //
    //   $details_mode = false  →  builds llms-full.txt (public). No
    //     annotations on field labels. Standard output.
    //
    //   $details_mode = true   →  builds llms-details.txt (internal). All
    //     ACF field labels get an `(acf: `field_name`)` annotation so an
    //     LLM consuming the file can see the code identifier behind each
    //     human label. Taxonomy section headings get `(taxonomy: `slug`)`
    //     for the same reason.
    //
    // Both files share the same structure, ordering, and settings. The
    // details file is a SUPERSET of the full file: same Primary Content
    // plus field-name annotations, Post IDs, Content Builder, and Term
    // IDs. It is not a content-free architecture reference.
    // =========================================================================

    // =========================================================================
    // Per-post content cache
    //
    // The expensive part of generating the files is rendering each post's
    // body to markdown - for the Renderer extension that means an HTTP
    // fetch of the permalink plus DOM parsing. Everything else (taxonomy
    // lists, ACF field values, the TOC) is cheap database reads.
    //
    // So we cache ONLY the per-post rendered markdown, keyed in postmeta
    // on the post itself. Postmeta gives us free eviction (deleted post =
    // deleted cache) and no schema to manage.
    //
    // Staleness model ("simple"): a cache entry is valid when BOTH:
    //   1. post_modified <= cached_at        (content hasn't changed)
    //   2. stored fingerprint == current     (settings haven't changed)
    // The fingerprint is a hash of all settings that affect output, so
    // ANY settings change invalidates ALL caches (correct, if blunt).
    // =========================================================================

    private const CACHE_META_KEY = '_socialbump_ai_cache';

    /**
     * A fingerprint of the settings that change a post's cached markdown.
     *
     * The cache holds only the rendered body. ACF picks, options fields, post
     * types and taxonomies are all assembled from the settings when the files
     * are written, so they have no bearing on what is cached and are left out.
     * The strip selectors are the one setting the renderer reads, so a change
     * to them is the one thing that makes every cached body wrong.
     *
     * It used to include the ACF and post type picks as well, so unticking an
     * options field re-rendered every post on the site. See maybe_restamp_cache().
     */
    public function get_settings_fingerprint(): string {
        if ( $this->settings_fingerprint_cache !== null ) {
            return $this->settings_fingerprint_cache;
        }

        $settings = $this->get_settings();

        $relevant = [
            'renderer_strip_selectors'       => $settings['renderer_strip_selectors'] ?? '',
            'renderer_selectors_initialised' => $settings['renderer_selectors_initialised'] ?? false,
        ];

        $this->settings_fingerprint_cache = md5( (string) wp_json_encode( $relevant ) );

        return $this->settings_fingerprint_cache;
    }

    /**
     * Read a post's cached content markdown, or null if there's no valid
     * cache entry. "Valid" means present, fresh against post_modified, and
     * matching the current settings fingerprint.
     */
    private function get_cached_post_markdown( WP_Post $post ): ?string {
        $cache = get_post_meta( $post->ID, self::CACHE_META_KEY, true );

        if ( ! is_array( $cache ) || ! isset( $cache['markdown'], $cache['cached_at'], $cache['fingerprint'] ) ) {
            return null;
        }

        // Settings changed since this was cached → stale.
        if ( $cache['fingerprint'] !== $this->get_settings_fingerprint() ) {
            return null;
        }

        // Post edited since this was cached → stale. Use GMT timestamps on
        // both sides to avoid timezone drift.
        $modified_gmt = get_post_modified_time( 'U', true, $post );
        if ( $modified_gmt !== false && (int) $modified_gmt > (int) $cache['cached_at'] ) {
            return null;
        }

        // Something the post is rendered through changed since, such as its
        // builder template: stale.
        if ( $this->touched_at( $post ) > (int) $cache['cached_at'] ) {
            return null;
        }

        return (string) $cache['markdown'];
    }

    /**
     * Mark every post of a type, or particular posts, as needing a re-render.
     *
     * A post's own modified date says nothing about the template it is drawn
     * through, so a template edit left every post looking current while the
     * export was out of date. A module that knows about templates (Bricks does)
     * calls one of these when a template is saved, and the timestamp is held
     * against the cache stamp from then on. The cache entries are kept, so the
     * files still serve the old render until the next Update, the same as an
     * edited post.
     */
    public function touch_post_type( string $post_type ): void {
        $touched = (array) get_option( 'sbaike_touched', [] );

        $touched['types'][ $post_type ] = time();

        update_option( 'sbaike_touched', $touched, false );
        $this->touched_cache = null;
        $this->flush_global_stale_count();
    }

    public function touch_posts( array $post_ids ): void {
        $touched = (array) get_option( 'sbaike_touched', [] );

        foreach ( $post_ids as $id ) {
            $touched['posts'][ (int) $id ] = time();
        }

        update_option( 'sbaike_touched', $touched, false );
        $this->touched_cache = null;
        $this->flush_global_stale_count();
    }

    /** When something this post renders through was last touched, or 0. */
    private function touched_at( WP_Post $post ): int {
        if ( $this->touched_cache === null ) {
            $this->touched_cache = (array) get_option( 'sbaike_touched', [] );
        }

        $type = (int) ( $this->touched_cache['types'][ $post->post_type ] ?? 0 );
        $one  = (int) ( $this->touched_cache['posts'][ $post->ID ] ?? 0 );

        return max( $type, $one );
    }

    /**
     * Render a post's content markdown fresh (the expensive path) and
     * store it in the cache. Returns the rendered markdown.
     *
     * This is the ONLY place the expensive render happens. Both the cache
     * warmers (Update / Rebuild) and the live assembly fall through to
     * here on a cache miss.
     */
    private function render_and_cache_post_markdown( WP_Post $post ): string {
        /**
         * Filter: socialbump_aiknowledge_post_content_markdown
         *
         * Allows an extension to provide alternative body content
         * extraction (e.g. the Renderer fetching the permalink, a Bricks
         * extension reading _bricks_page_content_2, an Elementor extension
         * reading _elementor_data).
         *
         * Return a non-empty string to override; return the original
         * (empty) string to let core's default extraction run. The default
         * behaviour, with no extension active, is to read post_content via
         * html_to_markdown(). Extensions are called BEFORE that fallback.
         *
         * @param string  $content Pre-extraction empty default.
         * @param WP_Post $post    The post being rendered.
         */
        $extension_content = apply_filters( 'socialbump_aiknowledge_post_content_markdown', '', $post );

        if ( is_string( $extension_content ) && trim( $extension_content ) !== '' ) {
            $markdown = $extension_content;
        } else {
            $markdown = $this->get_post_content_markdown( $post );
        }

        $markdown = (string) $markdown;

        update_post_meta( $post->ID, self::CACHE_META_KEY, [
            'markdown'    => $markdown,
            'cached_at'   => time(),
            'fingerprint' => $this->get_settings_fingerprint(),
        ] );

        // A freshly cached post reduces the stale count; drop the cached
        // total so the admin bar recomputes. delete_transient is cheap, so
        // doing this per render during a build is fine.
        $this->flush_global_stale_count();

        return $markdown;
    }

    /**
     * Get a post's content markdown, using the cache when valid and
     * rendering (+ caching) on a miss. This is what the file assembly
     * calls - it never needs to know whether a render happened.
     */
    private function get_post_markdown_cached( WP_Post $post ): string {
        $cached = $this->get_cached_post_markdown( $post );

        if ( $cached !== null ) {
            return $cached;
        }

        return $this->render_and_cache_post_markdown( $post );
    }

    /**
     * Does this post have a valid (fresh) cache entry? Used by the Update
     * action to decide what needs re-rendering without doing the render.
     */
    public function post_cache_is_fresh( WP_Post $post ): bool {
        return $this->get_cached_post_markdown( $post ) !== null;
    }

    /**
     * Count how many of a post type's eligible posts have a stale or
     * missing cache entry. Used for the per-type "needs rebuild" hint in
     * the admin card header.
     *
     * Deliberately lightweight: it reads each cache entry's metadata
     * (cached_at + fingerprint) but does NOT pull the (potentially large)
     * markdown body, and it computes the settings fingerprint once up
     * front rather than per post. Safe to call while rendering the admin
     * page for every selected post type.
     */
    public function count_stale_posts_for_type( string $post_type ): int {
        if ( ! post_type_exists( $post_type ) ) {
            return 0;
        }

        $settings    = $this->get_settings();
        $fingerprint = $this->get_settings_fingerprint();
        $excluded    = $this->get_excluded_post_lookup( $post_type, $settings );

        // The meta cache is primed on purpose: the cache stamp is read for every
        // post below, and get_post_meta() would otherwise fetch each post's meta
        // one query at a time. Tried without it: same memory, twelve times the queries.
        $posts = $this->get_posts_cached( [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'update_post_term_cache' => false,
        ] );

        $stale = 0;

        foreach ( (array) $posts as $post ) {
            if ( isset( $excluded[ (int) $post->ID ] ) ) {
                continue;
            }

            $cache = get_post_meta( $post->ID, self::CACHE_META_KEY, true );

            // Missing or malformed → stale.
            if ( ! is_array( $cache ) || ! isset( $cache['cached_at'], $cache['fingerprint'] ) ) {
                $stale++;
                continue;
            }

            // Settings changed since cached → stale.
            if ( $cache['fingerprint'] !== $fingerprint ) {
                $stale++;
                continue;
            }

            // Post edited since cached → stale.
            $modified_gmt = get_post_modified_time( 'U', true, $post );
            if ( $modified_gmt !== false && (int) $modified_gmt > (int) $cache['cached_at'] ) {
                $stale++;
                continue;
            }

            // Its template changed since cached: stale.
            if ( $this->touched_at( $post ) > (int) $cache['cached_at'] ) {
                $stale++;
            }
        }

        return $stale;
    }

    /**
     * Total stale posts across all selected post types. Backed by a short
     * transient so the admin bar (which renders on every page load, front
     * and back) doesn't scan every post each time.
     *
     * The transient is refreshed whenever the cache changes (save_post,
     * update/rebuild actions, cron) and expires after 5 minutes as a
     * safety net. Pass $force = true to bypass and recompute.
     */
    public function get_global_stale_count( bool $force = false ): int {
        if ( ! $force ) {
            $cached = get_transient( 'sbaike_global_stale_count' );
            if ( $cached !== false ) {
                return (int) $cached;
            }
        }

        $settings = $this->get_settings();
        $total    = 0;

        foreach ( (array) ( $settings['post_types'] ?? [] ) as $pt ) {
            $total += $this->count_stale_posts_for_type( $pt );
        }

        set_transient( 'sbaike_global_stale_count', $total, 5 * MINUTE_IN_SECONDS );

        return $total;
    }

    /**
     * Invalidate the cached global stale count. Called whenever the cache
     * state changes so the admin bar reflects reality on the next load.
     */
    public function flush_global_stale_count(): void {
        delete_transient( 'sbaike_global_stale_count' );
    }

    /**
     * Clear the cache entry for a single post. Called from save_post so an
     * edited post is picked up by the next Update even if the user never
     * clicks anything.
     */
    public function clear_post_cache( int $post_id ): void {
        delete_post_meta( $post_id, self::CACHE_META_KEY );
        $this->flush_global_stale_count();
    }

    /**
     * Clear cached markdown for every post (all types). Used by Rebuild
     * All. Uses a direct delete so we're not loading the whole post table.
     */
    public function clear_all_post_caches(): void {
        global $wpdb;

        // Through the meta API rather than a raw delete, so the object cache is
        // cleared as well. A raw delete leaves the old values cached on a site with a
        // persistent object cache, and the staleness checks would keep reading them.
        delete_metadata( 'post', null, self::CACHE_META_KEY, '', true );
        $this->flush_global_stale_count();
    }

    /**
     * Clear cached markdown for all posts of one post type. Used by the
     * per-post-type Rebuild action.
     */
    public function clear_post_type_cache( string $post_type ): void {
        $ids = $this->get_posts_cached( [
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ] );

        foreach ( (array) $ids as $id ) {
            delete_post_meta( (int) $id, self::CACHE_META_KEY );
        }

        $this->flush_global_stale_count();
    }

    /**
     * Count the total posts that are eligible for the export across all
     * selected post types - published and not excluded via the picker.
     * This is the "YYY" denominator in the Full Rebuild report ("XX of
     * YYY"). Counts IDs only, no body loading, so it's cheap.
     */
    public function count_eligible_posts(): int {
        $settings = $this->get_settings();
        $total    = 0;

        foreach ( (array) ( $settings['post_types'] ?? [] ) as $post_type ) {
            if ( ! post_type_exists( $post_type ) ) {
                continue;
            }

            $ids = $this->get_posts_cached( [
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ] );

            $excluded_lookup = $this->get_excluded_post_lookup( $post_type, $settings );

            foreach ( (array) $ids as $id ) {
                if ( ! isset( $excluded_lookup[ (int) $id ] ) ) {
                    $total++;
                }
            }
        }

        return $total;
    }

    /**
     * Count eligible (published, not excluded) posts for a single post
     * type. Single-type version of count_eligible_posts(), used by the
     * per-type rebuild report.
     */
    public function count_eligible_posts_for_type( string $post_type ): int {
        if ( ! post_type_exists( $post_type ) ) {
            return 0;
        }

        $settings = $this->get_settings();
        $total    = 0;

        $ids = $this->get_posts_cached( [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ] );

        $excluded_lookup = $this->get_excluded_post_lookup( $post_type, $settings );

        foreach ( (array) $ids as $id ) {
            if ( ! isset( $excluded_lookup[ (int) $id ] ) ) {
                $total++;
            }
        }

        return $total;
    }

    /**
     * Build a list of the posts that will be re-rendered on the next
     * Update - i.e. every included post whose cache is missing or stale.
     *
     * Must be called BEFORE the build runs, because the build re-renders
     * (and so freshens) those posts as a side effect. Returns an array of
     * [ 'type' => 'Service', 'title' => 'Wrinkle Reducing Treatments' ]
     * rows, ordered by post type then title, for the result report.
     *
     * @return array<int, array{type:string,title:string}>
     */
    public function get_stale_posts_report(): array {
        $settings = $this->get_settings();
        $stale    = [];

        foreach ( (array) ( $settings['post_types'] ?? [] ) as $post_type ) {
            $pt_obj = get_post_type_object( $post_type );

            if ( ! $pt_obj ) {
                continue;
            }

            $type_label = $pt_obj->labels->singular_name ?? $post_type;

            $ids = $this->get_posts_cached( [
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'title',
                'order'          => 'ASC',
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ] );

            foreach ( (array) $ids as $id ) {
                $id = (int) $id;

                $post = get_post( $id );

                if ( ! $post ) {
                    continue;
                }

                if ( $this->is_post_object_excluded( $post, $post_type, $settings ) ) {
                    continue;
                }

                if ( ! $this->post_cache_is_fresh( $post ) ) {
                    $stale[] = [
                        'id'    => $id,
                        'type'  => $type_label,
                        'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                    ];
                }
            }
        }

        return $stale;
    }

    /**
     * Build a short orientation block explaining how to read the file. The
     * public (full) and architecture (details) editions differ in the extra
     * detail the latter carries.
     */
    private function get_reading_guide( bool $details_mode ): array {
        $lines = [
            '## HOW TO READ THIS FILE',
            'A structured map of this site\'s content for AI and LLM use.',
            '',
            '- CONTENTS lists every taxonomy and post type, with item counts.',
            '- Each taxonomy section lists its terms and the posts filed under each term.',
            '- Each post type section opens with an Index (a nested list of its posts), then one detailed entry per post.',
            '- Within an entry: "Summary" is the page\'s meta description; "Primary Content" is the page body in markdown; "Related ..." blocks link to associated posts; any other "Label: value" line is one of the page\'s custom fields.',
            '- Links are written as [text](url). Slugs and identifiers appear in [square brackets].',
        ];

        if ( $details_mode ) {
            $lines[] = '- This is the architecture edition: alongside the content, each entry also shows its Post ID, post type sections list the Available Page Builders and any Template assignments, and field values sourced from an options page are tagged like [options:field_name].';
            $lines[] = '- The ACF ARCHITECTURE MAP appendix at the end summarises every field group: where it attaches, and its fields and types.';
        }

        $lines[] = '';

        return $lines;
    }

    private function build_llms_full( bool $details_mode = false ): string {
        $settings = $this->get_settings();
        $out      = [];

        $site_name = $settings['business_name'] ?: html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $site_desc = $settings['business_description'] ?: html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        if ( $details_mode ) {
            $out[] = '# ' . $site_name . ' AI Knowledge Map - Site Architecture & Details';
        } else {
            $out[] = '# ' . $site_name . ' AI Knowledge Map';
        }

        $out[] = str_repeat( '=', 80 );
        $out[] = '';
        $out = array_merge( $out, $this->get_generator_header() );
        $out[] = 'Website: ' . home_url( '/' );
        $out[] = 'Slim Discovery File: ' . home_url( '/llms.txt' );

        // Only the details file gets a cross-reference back to the full
        // content file. The full file's existing format is preserved.
        if ( $details_mode ) {
            $out[] = 'Full Content File: ' . home_url( '/llms-full.txt' );
        }
        $out[] = '';

        $out = array_merge( $out, $this->get_reading_guide( $details_mode ) );

        $business_lines = [
            '## BUSINESS INFORMATION',
            'Business Name: ' . $site_name,
            'Business Description: ' . $site_desc,
        ];

        // ---- ACF Options page global data ----
        $options_output = $this->build_acf_options_output( $settings, $details_mode );

        if ( $options_output ) {
            $business_lines[] = $options_output;
        }

        /**
         * Filter: socialbump_aiknowledge_business_info_lines
         *
         * Modify the lines that appear in the global "## BUSINESS INFORMATION"
         * block at the top of the full file. Useful for extensions that want
         * to add site-wide context (e.g. WooCommerce extension adding store
         * currency, base location, tax setting).
         *
         * @param string[] $business_lines Each line is rendered as-is.
         */
        $business_lines = apply_filters( 'socialbump_aiknowledge_business_info_lines', $business_lines );

        $out[] = implode( "\n", (array) $business_lines );
        $out[] = '';

        // ---- Additional Information block (after business info + ACF options) ----
        foreach ( $this->get_additional_info_block( $settings ) as $line ) {
            $out[] = $line;
        }

        // ---- Table of contents ----
        // Show at-a-glance counts so an LLM (or human) can see the shape
        // of the site without scanning the whole file. Counts respect
        // exclusion settings - only items that will actually appear
        // below are counted.
        foreach ( $this->build_contents_toc( $settings ) as $line ) {
            $out[] = $line;
        }

        // ---- Taxonomy reference sections ----
        foreach ( $settings['taxonomies'] as $tax_slug ) {
            $tax_obj = get_taxonomy( $tax_slug );

            if ( ! $tax_obj ) {
                continue;
            }

            $terms = get_terms( [
                'taxonomy'   => $tax_slug,
                'hide_empty' => false,
            ] );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            $out[] = str_repeat( '-', 80 );

            // In details mode, append the taxonomy slug as a code identifier
            // so an LLM consuming the file can use it in register_taxonomy()
            // or wp_get_post_terms() calls.
            if ( $details_mode ) {
                $heading = '## TAXONOMY: ' . strtoupper( $tax_obj->labels->name ) . ' (taxonomy: `' . $tax_slug . '`)';
            } else {
                $heading = '## TAXONOMY: ' . strtoupper( $tax_obj->labels->name );
            }

            $header_lines = [
                $heading,
                'Slug: ' . $tax_slug,
                'Used by post types: ' . implode( ', ', (array) $tax_obj->object_type ),
            ];

            /**
             * Filter: socialbump_aiknowledge_taxonomy_section_lines
             *
             * Modify the lines that appear in a taxonomy's section header,
             * above the per-term blocks. Default lines include the heading,
             * slug, list of associated post types, and any Bricks taxonomy
             * archive template assignment.
             *
             * @param string[]  $header_lines Default header lines.
             * @param string    $tax_slug     Taxonomy slug being rendered.
             * @param WP_Taxonomy $tax_obj    Taxonomy object.
             */
            $header_lines = apply_filters(
                'socialbump_aiknowledge_taxonomy_section_lines',
                $header_lines,
                $tax_slug,
                $tax_obj,
                $details_mode
            );

            foreach ( (array) $header_lines as $line ) {
                $out[] = (string) $line;
            }

            $out[] = '';

            foreach ( $terms as $term ) {
                $term_url = get_term_link( $term );

                // Build the term block as a line array first so extensions
                // can append / replace lines via filter before output.
                $term_lines = [];

                $term_lines[] = '  Term: ' . $term->name;

                // Term ID is internal architecture detail - only useful for
                // developers / LLMs interacting with the WP API. Hide it
                // from the public file.
                if ( $details_mode ) {
                    $term_lines[] = '  Term ID: ' . $term->term_id;
                }

                $term_lines[] = '  Slug: ' . $term->slug;

                if ( ! is_wp_error( $term_url ) ) {
                    $term_lines[] = '  URL: ' . $term_url;
                }

                if ( $term->description ) {
                    $term_lines[] = '  Description: ' . $this->clean_content( $term->description );
                }

                // Selected term-level ACF fields, rendered the same way as
                // post and options fields and indented to nest in the term
                // block. Empty unless fields are picked for this taxonomy.
                foreach ( $this->build_acf_output_for_term( $term, $settings, $details_mode ) as $acf_line ) {
                    $term_lines[] = $acf_line;
                }

                /**
                 * Filter: socialbump_aiknowledge_term_lines
                 *
                 * Per-term hook fired once for every term rendered in the
                 * taxonomy section. Lets extensions add facts that depend
                 * on the term itself rather than the parent taxonomy -
                 * e.g. Bricks' per-term archive template assignment, a
                 * member count for a custom-membership system, or the
                 * featured image set on the term via ACF.
                 *
                 * Lines are emitted in array order. Use the same 2-space
                 * indent as the existing block so output stays uniform.
                 *
                 * @param string[] $term_lines Lines already built (Term,
                 *                             Slug, URL, etc).
                 * @param WP_Term  $term       The term being rendered.
                 * @param string   $tax_slug   The taxonomy slug.
                 */
                $term_lines = apply_filters(
                    'socialbump_aiknowledge_term_lines',
                    $term_lines,
                    $term,
                    $tax_slug,
                    $details_mode
                );

                foreach ( (array) $term_lines as $line ) {
                    $out[] = (string) $line;
                }

                // Flat list of posts assigned to this term - gives an LLM a
                // direct cross-reference from category/term to its posts
                // without having to scan the entire post-type section.
                $term_posts_lines = $this->get_term_posts_index( $term, $settings );

                if ( $term_posts_lines ) {
                    foreach ( $term_posts_lines as $line ) {
                        $out[] = $line;
                    }
                }

                $out[] = '';
            }
        }

        // ---- Post types ----
        foreach ( $settings['post_types'] as $post_type ) {
            $is_blog = ( $post_type === 'post' );

            $items = $this->get_posts_cached( [
                'post_type'      => $post_type,
                'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
                'posts_per_page' => -1,
                'orderby'        => $is_blog ? 'date' : 'menu_order title',
                'order'          => $is_blog ? 'DESC' : 'ASC',
            ] );

            if ( ! $items ) {
                continue;
            }

            $pto      = get_post_type_object( $post_type );
            $pt_label = $pto ? strtoupper( $pto->labels->name ) : strtoupper( $post_type );

            $out[] = str_repeat( '=', 80 );

            // Count posts after exclusion filtering so the header reflects
            // what's actually in the export. We also count published-vs-other
            // so non-published posts (drafts the user has ticked in for
            // pre-launch testing) are visible in the header.
            $included_count  = 0;
            $published_count = 0;
            foreach ( $items as $count_item ) {
                if ( $count_item->post_status === 'publish' ) {
                    $published_count++;
                }
                if ( ! $this->is_post_object_excluded( $count_item, $post_type, $settings ) ) {
                    $included_count++;
                }
            }

            $count_line = 'Total published: ' . $published_count;
            if ( $included_count !== $published_count ) {
                $count_line .= ' (' . $included_count . ' included in export)';
            }

            $header_lines = [
                '## POST TYPE: ' . $pt_label,
                'Slug: ' . $post_type,
                $count_line,
            ];

            // Available editors / page builders for this post type. This is
            // architecture metadata, so it is only emitted in the details file,
            // matching the Content Builder and Template lines.
            if ( $details_mode ) {
                // Core contributes the native WP editor (Gutenberg or Classic
                // Editor) when the post type supports the editor; each builder
                // addon appends its own via the filter below. The result is one
                // line listing every editing surface the post type offers,
                // separate from which template renders it.
                $page_builders = [];

                if ( post_type_supports( $post_type, 'editor' ) ) {
                    $page_builders[] = $this->post_type_uses_block_editor( $post_type )
                        ? 'Gutenberg'
                        : 'Classic Editor';
                }

                /**
                 * Filter: socialbump_aiknowledge_available_page_builders
                 *
                 * Collect the editors / page builders available for a post
                 * type. Core seeds the native WP editor; builder addons append
                 * their own (e.g. the Bricks addon adds "Bricks Builder" when
                 * Bricks is enabled for the post type, the Elementor addon adds
                 * "Elementor").
                 *
                 * @param string[] $page_builders Builder names collected so far.
                 * @param string   $post_type     Post type slug being rendered.
                 */
                $page_builders = apply_filters(
                    'socialbump_aiknowledge_available_page_builders',
                    $page_builders,
                    $post_type
                );

                $page_builders = array_values( array_unique( array_filter( array_map(
                    static function ( $b ) {
                        return is_string( $b ) ? trim( $b ) : '';
                    },
                    (array) $page_builders
                ) ) ) );

                $builder_label  = ( count( $page_builders ) === 1 )
                    ? 'Available Page Builder: '
                    : 'Available Page Builders: ';
                $header_lines[] = $builder_label . ( $page_builders ? implode( ', ', $page_builders ) : 'None' );
            }

            /**
             * Filter: socialbump_aiknowledge_post_type_section_lines
             *
             * Modify the lines that appear in a post type's section header,
             * which sits above the at-a-glance index and the individual post
             * entries. Default lines include the heading, slug, total count,
             * and any Bricks template assignments (when Bricks is the active
             * content extension).
             *
             * Useful for extensions that want to add post-type-wide context
             * (e.g. WooCommerce extension noting "Catalog visibility default:
             * visible", LearnDash noting "Course count: 12").
             *
             * @param string[] $header_lines Default section header lines.
             * @param string   $post_type    Post type slug being rendered.
             * @param WP_Post[] $items       Published posts in the post type.
             */
            $header_lines = apply_filters(
                'socialbump_aiknowledge_post_type_section_lines',
                $header_lines,
                $post_type,
                $items,
                $details_mode
            );

            foreach ( (array) $header_lines as $line ) {
                $out[] = (string) $line;
            }

            // At-a-glance index of all posts in this post type. Hierarchical
            // post types (page, services, etc.) render as a parent-child tree
            // via post_parent; flat post types render as a simple list.
            $index_lines = $this->get_post_type_index( $post_type, $settings );

            /**
             * Filter: socialbump_aiknowledge_post_index_tree
             *
             * Replace or augment the at-a-glance index for a post type.
             * Useful for plugins with non-post_parent hierarchies, e.g.
             * LearnDash's Course → Lessons → Topics structure (which uses
             * its own relationship metadata rather than post_parent).
             *
             * Return an empty array to suppress the index entirely for a
             * given post type. Return modified lines to customise it.
             *
             * The default lines start with "Index:" and then a list of
             * markdown links in the form "- [Title](url)". Extensions that
             * replace the tree should preserve that format for consistency.
             *
             * @param string[] $index_lines Default index lines.
             * @param string   $post_type   Post type slug being indexed.
             * @param WP_Post[] $items      Published posts in that post type.
             */
            $index_lines = apply_filters(
                'socialbump_aiknowledge_post_index_tree',
                $index_lines,
                $post_type,
                $items
            );

            if ( is_array( $index_lines ) && $index_lines ) {
                $out[] = '';

                foreach ( $index_lines as $line ) {
                    $out[] = (string) $line;
                }
            }

            $out[] = '';

            foreach ( $items as $item ) {
                if ( $this->is_post_object_excluded( $item, $post_type, $settings ) ) {
                    continue;
                }

                $out[] = str_repeat( '-', 80 );

                $wp_title     = html_entity_decode( get_the_title( $item ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $title        = $this->get_display_title( $item );
                $subtitle     = $this->acf_active() ? get_field( 'page_sub_title', $item->ID ) : '';
                $url          = get_permalink( $item );
                $excerpt      = $this->get_excerpt( $item );
                $wp_role      = $this->get_wp_post_role( $item );
                $content_type = $this->get_content_type( $item );

                $heading = $title;
                if ( $title !== $wp_title ) {
                    $heading .= ' (Admin Page Title: ' . $wp_title . ')';
                }

                $out[] = '### ' . $heading;

                $meta_lines = [];

                if ( $subtitle ) {
                    $meta_lines[] = 'Subtitle: ' . $this->clean_content( (string) $subtitle );
                }

                $meta_lines[] = 'Post Type: ' . $post_type;

                // Post ID is internal architecture detail. Only show in
                // the details file, not the public-facing full file.
                if ( $details_mode ) {
                    $meta_lines[] = 'Post ID: ' . $item->ID;
                }

                $meta_lines[] = 'URL: ' . $url;
                $meta_lines[] = 'Last Updated: ' . get_the_modified_date( 'Y-m-d', $item );

                // Only show Status when not published - published is the norm
                // so showing it on every post is noise. Drafts / private /
                // pending posts get an explicit marker so readers (and LLMs)
                // know the post isn't on the live site yet.
                if ( $item->post_status !== 'publish' ) {
                    $meta_lines[] = 'Status: ' . $item->post_status;
                }

                // Content Builder (Bricks, Elementor, Gutenberg) is internal
                // architecture detail - same treatment as Post ID.
                if ( $details_mode ) {
                    $meta_lines[] = 'Content Builder: ' . $content_type;
                }

                if ( $wp_role ) {
                    $meta_lines[] = 'WP Role: ' . $wp_role;
                }

                /**
                 * Filter: socialbump_aiknowledge_post_meta_lines
                 *
                 * Modify the lines that appear in a post's meta block (the
                 * "Post Type / Post ID / URL / ..." section under each
                 * "### Title"). Extensions can add lines like "Product Type:
                 * variable", "Course Price: $99", "SKU: ABC-123".
                 *
                 * Order of registered filters matters - later additions
                 * appear lower in the meta block.
                 *
                 * The $details_mode flag lets extensions emit different
                 * content for the public file vs the architecture-details
                 * file. For example: the Bricks extension only emits
                 * "Content Builder: Bricks Builder" when $details_mode is
                 * true, matching how the core code gates its own
                 * "Content Builder: Empty" line.
                 *
                 * @param string[] $meta_lines   Default lines in current order.
                 * @param WP_Post  $post         The post being rendered.
                 * @param string   $post_type    Post type slug.
                 * @param bool     $details_mode True when building llms-details.txt.
                 */
                $meta_lines = apply_filters( 'socialbump_aiknowledge_post_meta_lines', $meta_lines, $item, $post_type, $details_mode );

                foreach ( (array) $meta_lines as $line ) {
                    $out[] = $line;
                }

                $out[] = '';

                if ( $excerpt ) {
                    $out[] = 'Summary:';
                    $out[] = $excerpt;
                    $out[] = '';
                }

                // ---- Unified ordered content walk ----
                //
                // Replaces the old hardcoded "Primary Content → ACF fields"
                // sequence with a walk through the user's saved order. Each
                // item is either the special {post_content} pseudo-field
                // (the post body) or a real ACF field name. Items emit in
                // the order the user dragged them in the picker.
                //
                // When no saved state exists for this post type (fresh
                // install, new post type), get_effective_ticked_fields_for_post_type()
                // returns just [{post_content}], so the post body still
                // renders - matching pre-1.25 default behaviour.
                $ticked_order = $this->get_effective_ticked_fields_for_post_type( $post_type, $settings );

                foreach ( $ticked_order as $field_name ) {
                    if ( $field_name === self::POST_CONTENT_TOKEN ) {
                        // Per-post body content. Goes through the cache -
                        // renders + stores on a miss, reads on a hit. The
                        // actual extraction (and the
                        // socialbump_aiknowledge_post_content_markdown
                        // filter that lets extensions override it) lives in
                        // render_and_cache_post_markdown(). This is the one
                        // expensive operation in the whole build, so it's
                        // the only thing cached.
                        //
                        // Primary Content appears in BOTH files. The details
                        // file is a superset of the full file (same content
                        // plus annotations, IDs, and architecture lines) -
                        // it is NOT a content-free architecture reference.
                        $content = $this->get_post_markdown_cached( $item );

                        if ( $content ) {
                            $out[] = 'Primary Content:';
                            $out[] = $content;
                            $out[] = '';
                        }
                    } else {
                        // Skip fields the user has chosen to omit from this
                        // file (full vs details) for this post type.
                        if ( $this->field_is_omitted( $field_name, $post_type, $details_mode, $settings ) ) {
                            continue;
                        }

                        // Real ACF field - render via the shared per-field
                        // helper. When in details mode, the helper appends
                        // the (acf: `field_name`) annotation to the label.
                        // Empty/sensitive/missing fields render as empty
                        // string and are skipped.
                        $formatted = $this->render_single_acf_field( $field_name, $item->ID, $details_mode );
                        if ( $formatted !== '' ) {
                            $out[] = $formatted;
                            $out[] = '';
                        }
                    }
                }

                $post_taxonomies = $this->get_taxonomies_for_post_type( $post_type );

                foreach ( $post_taxonomies as $tax_slug => $tax_obj ) {
                    if ( ! in_array( $tax_slug, $settings['taxonomies'], true ) ) {
                        continue;
                    }

                    $terms = wp_get_post_terms( $item->ID, $tax_slug );

                    if ( is_wp_error( $terms ) || empty( $terms ) ) {
                        continue;
                    }

                    if ( $details_mode ) {
                        $out[] = $tax_obj->labels->name . ' (taxonomy: `' . $tax_slug . '`):';
                    } else {
                        $out[] = $tax_obj->labels->name . ':';
                    }

                    foreach ( $terms as $term ) {
                        $out[] = '  - ' . $term->name;
                    }

                    $out[] = '';
                }

                $thumb_id = get_post_thumbnail_id( $item->ID );

                if ( $thumb_id ) {
                    $alt      = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
                    $img_url  = wp_get_attachment_url( $thumb_id );
                    $alt_text = $alt ? $this->clean_content( $alt ) : '';

                    // Only emit when we have something useful to show.
                    // A bare URL with no alt text isn't very informative
                    // for an LLM; an alt text alone with no URL prevents
                    // linking back to the source. Prefer combined when
                    // both exist, fall back gracefully when one's missing.
                    if ( $alt_text && $img_url ) {
                        $out[] = 'Featured Image: ' . $alt_text . ' (' . $img_url . ')';
                        $out[] = '';
                    } elseif ( $alt_text ) {
                        $out[] = 'Featured Image Alt: ' . $alt_text;
                        $out[] = '';
                    } elseif ( $img_url ) {
                        $out[] = 'Featured Image: ' . $img_url;
                        $out[] = '';
                    }
                }

                /**
                 * Filter: socialbump_aiknowledge_post_sections
                 *
                 * Allows extensions to append entire labelled sections after
                 * the per-post Additional Fields block. Each section is an
                 * array of lines (the first usually being the section label
                 * like "Variations:" or "Course Outline:") and they're
                 * emitted in order with blank lines between them.
                 *
                 * Each returned section should look like:
                 *   [
                 *     'Section Heading:',
                 *     '  detail line 1',
                 *     '  detail line 2',
                 *   ]
                 *
                 * @param array[] $sections Array of section blocks. Default: empty.
                 * @param WP_Post $post     The post being rendered.
                 */
                $sections = apply_filters( 'socialbump_aiknowledge_post_sections', [], $item );

                if ( is_array( $sections ) ) {
                    foreach ( $sections as $section ) {
                        if ( ! is_array( $section ) || empty( $section ) ) {
                            continue;
                        }

                        foreach ( $section as $line ) {
                            $out[] = (string) $line;
                        }

                        $out[] = '';
                    }
                }
            }

            $out[] = '';
        }

        // Architecture appendix (details file only): a consolidated, structural
        // map of the site's ACF setup.
        if ( $details_mode ) {
            $acf_map = $this->build_acf_architecture_map();

            if ( $acf_map !== '' ) {
                $out[] = $acf_map;
            }
        }

        return implode( "\n", $out );
    }

    // =========================================================================
    // ACF output builders
    // =========================================================================

    private function build_acf_output_for_post( int $post_id, string $post_type, array $settings ): string {
        if ( ! $this->acf_active() ) {
            return '';
        }

        if ( empty( $settings['acf_fields'][ $post_type ] ) ) {
            return '';
        }

        $lines = [];

        foreach ( $settings['acf_fields'][ $post_type ] as $field_name ) {
            $formatted = $this->render_single_acf_field( $field_name, $post_id );
            if ( $formatted !== '' ) {
                $lines[] = $formatted;
            }
        }

        return implode( "\n\n", $lines );
    }

    /**
     * Render a single ACF field by name for a given post into the markdown
     * line(s) used in the export. Returns an empty string for missing,
     * empty, or sensitive fields. Extracted from build_acf_output_for_post()
     * so the per-post unified walker can render fields one at a time
     * (interleaved with the {post_content} block).
     *
     * When $annotate is true, the field label gets an `(acf: `field_name`)`
     * suffix so an LLM consuming the details file can see the code
     * identifier behind each human-readable label. Public files leave the
     * label alone.
     */
    private function render_single_acf_field( string $field_name, int $post_id, bool $annotate = false ): string {
        if ( ! $this->acf_active() ) {
            return '';
        }

        if ( $this->is_sensitive_field_name( $field_name ) ) {
            return '';
        }

        $value = get_field( $field_name, $post_id );

        if ( $value === null || $value === '' || $value === [] || $value === false ) {
            return '';
        }

        $field_obj = $this->get_acf_field_object_cached( $field_name, $post_id );
        $label     = ! empty( $field_obj['label'] ) ? $field_obj['label'] : null;
        $type      = ! empty( $field_obj['type'] ) ? (string) $field_obj['type'] : '';

        // In details mode, append the code identifier to the label so an
        // LLM can use it directly in get_field() / update_field() calls.
        if ( $annotate ) {
            $display_label = ( $label ?? $field_name ) . ' [' . $field_name . ']';
        } else {
            $display_label = $label;
        }

        $formatted = $this->format_field_value( $field_name, $value, $display_label, $type, false, $annotate );

        return $formatted ?: '';
    }

    private function build_acf_options_output( array $settings, bool $annotate = false ): string {
        if ( ! $this->acf_active() ) {
            return '';
        }

        if ( empty( $settings['acf_options_fields'] ) ) {
            return '';
        }

        $cache_key = ( $annotate ? 'details:' : 'public:' ) . $this->get_settings_fingerprint();

        if ( array_key_exists( $cache_key, $this->acf_options_output_cache ) ) {
            return $this->acf_options_output_cache[ $cache_key ];
        }

        $lines = [];

        foreach ( $settings['acf_options_fields'] as $field_name ) {
            if ( $this->is_sensitive_field_name( $field_name ) ) {
                continue;
            }

            $value = get_field( $field_name, 'option' );

            if ( $value === null || $value === '' || $value === [] || $value === false ) {
                continue;
            }

            $field_obj = $this->get_acf_field_object_cached( $field_name, 'option' );
            $label     = ! empty( $field_obj['label'] ) ? $field_obj['label'] : null;
            $type      = ! empty( $field_obj['type'] ) ? (string) $field_obj['type'] : '';

            if ( $annotate ) {
                // ACF Options fields live on a dedicated Options page rather
                // than a post. The `options:` scope prefix makes that clear
                // and mirrors get_field('foo', 'option') syntax.
                $display_label = ( $label ?? $field_name ) . ' [options:' . $field_name . ']';
            } else {
                $display_label = $label;
            }

            $formatted = $this->format_field_value( $field_name, $value, $display_label, $type, true, $annotate );

            if ( $formatted ) {
                $lines[] = $formatted;
            }
        }

        $this->acf_options_output_cache[ $cache_key ] = $this->join_field_lines( $lines );

        return $this->acf_options_output_cache[ $cache_key ];
    }

    /**
     * Discover the content-bearing ACF fields attached to a taxonomy (i.e.
     * fields shown on the term edit screen). Mirrors get_acf_options_fields()
     * but matches the `taxonomy` location rule and is scoped to one taxonomy.
     * Same whitelist, skip-name and sensitive-name filtering as everywhere
     * else.
     */
    private function get_acf_fields_for_taxonomy( string $taxonomy ): array {
        if ( ! $this->acf_active() ) {
            return [];
        }

        $groups        = acf_get_field_groups( [ 'taxonomy' => $taxonomy ] );
        $fields        = [];
        $allowed_types = $this->get_allowed_acf_types();

        foreach ( $groups as $group ) {
            $group_fields = acf_get_fields( $group['key'] );

            if ( ! $group_fields ) {
                continue;
            }

            foreach ( $group_fields as $field ) {
                if (
                    ! in_array( $field['type'], $allowed_types, true )
                    || in_array( $field['name'], $this->acf_skip_names, true )
                    || empty( $field['name'] )
                    || $this->is_sensitive_field_name( $field['name'] )
                ) {
                    continue;
                }

                $fields[ $field['name'] ] = [
                    'label' => $field['label'],
                    'name'  => $field['name'],
                    'type'  => $field['type'],
                ];
            }
        }

        return $fields;
    }

    /**
     * Return the taxonomy's ACF fields in the user's saved drag order, with
     * any newly-detected fields appended. Mirrors
     * get_ordered_acf_fields_for_post_type() but without the {post_content}
     * pseudo-field, since terms have no body content of their own.
     */
    public function get_ordered_acf_term_fields_for_taxonomy( string $taxonomy, array $settings ): array {
        $live  = $this->get_acf_fields_for_taxonomy( $taxonomy );
        $order = (array) ( $settings['acf_term_fields_order'][ $taxonomy ] ?? [] );

        $ordered = [];

        foreach ( $order as $field_name ) {
            if ( isset( $live[ $field_name ] ) ) {
                $ordered[ $field_name ] = $live[ $field_name ];
            }
        }

        foreach ( $live as $field_name => $field ) {
            if ( ! isset( $ordered[ $field_name ] ) ) {
                $ordered[ $field_name ] = $field;
            }
        }

        return $ordered;
    }

    /**
     * Render the selected term-level ACF fields for a single term. Reads each
     * value with the ACF term target ("{taxonomy}_{term_id}") and runs it
     * through the same value formatter used for posts and options fields, so
     * rich text becomes markdown and repeaters render as nested blocks.
     *
     * Returns an array of lines already indented two spaces so they nest
     * inside the term block in the taxonomy section. Empty array when ACF is
     * inactive or nothing is selected for this taxonomy.
     */
    private function build_acf_output_for_term( WP_Term $term, array $settings, bool $annotate = false ): array {
        if ( ! $this->acf_active() ) {
            return [];
        }

        $selected = $settings['acf_term_fields'][ $term->taxonomy ] ?? [];

        if ( empty( $selected ) ) {
            return [];
        }

        $target = $term->taxonomy . '_' . $term->term_id;

        // Build each field into its own block of lines so we can separate
        // them with a blank line and wrap the whole lot in a labelled,
        // dash-ruled heading. Without this the fields run together and are
        // hard to read.
        $blocks = [];

        foreach ( (array) $selected as $field_name ) {
            if ( $this->is_sensitive_field_name( $field_name ) ) {
                continue;
            }

            $value = get_field( $field_name, $target );

            if ( $value === null || $value === '' || $value === [] || $value === false ) {
                continue;
            }

            $field_obj = $this->get_acf_field_object_cached( $field_name, $target );
            $label     = ! empty( $field_obj['label'] ) ? $field_obj['label'] : null;
            $type      = ! empty( $field_obj['type'] ) ? (string) $field_obj['type'] : '';

            if ( $annotate ) {
                $display_label = ( $label ?? $field_name ) . ' [term:' . $field_name . ']';
            } else {
                $display_label = $label;
            }

            $formatted = $this->format_field_value( $field_name, $value, $display_label, $type, true, $annotate );

            if ( $formatted === '' || $formatted === null ) {
                continue;
            }

            // Indent each line two spaces so the field nests inside the term
            // block alongside Slug / URL / Description.
            $block = [];
            foreach ( explode( "\n", (string) $formatted ) as $line ) {
                $block[] = ( $line === '' ) ? '' : '  ' . $line;
            }

            // Trim blank lines from the top and tail of the block so the
            // spacing between fields is exactly one blank line, no matter how
            // the value formatter padded it.
            while ( $block && $block[0] === '' ) {
                array_shift( $block );
            }
            while ( $block && end( $block ) === '' ) {
                array_pop( $block );
            }

            if ( $block ) {
                $blocks[] = $block;
            }
        }

        if ( ! $blocks ) {
            return [];
        }

        $heading = '  ' . $term->name . ' Custom Fields';
        $rule    = '  ' . str_repeat( '-', 48 );

        $lines   = [];
        $lines[] = '';          // gap below the standard term lines
        $lines[] = $rule;
        $lines[] = $heading;
        $lines[] = $rule;

        foreach ( $blocks as $i => $block ) {
            $lines[] = '';      // one blank line above each field block
            foreach ( $block as $line ) {
                $lines[] = $line;
            }
        }

        $lines[] = '';
        $lines[] = $rule;       // closing rule below the custom-field data

        return $lines;
    }

    private function get_acf_field_object_cached( string $field_name, $post_id ) {
        $cache_key = (string) $post_id . ':' . $field_name;

        if ( ! array_key_exists( $cache_key, $this->acf_field_object_cache ) ) {
            $this->acf_field_object_cache[ $cache_key ] = get_field_object( $field_name, $post_id );
        }

        return $this->acf_field_object_cache[ $cache_key ];
    }

    /**
     * Join an array of formatted field strings with single newlines, but
     * insert a blank line before/after any multi-line entry so block-style
     * fields (label-on-own-line) stay visually separated from their
     * single-line neighbours.
     */
    private function join_field_lines( array $lines ): string {
        if ( ! $lines ) {
            return '';
        }

        $out = '';

        foreach ( $lines as $i => $line ) {
            $is_multiline = strpos( $line, "\n" ) !== false;

            if ( $i === 0 ) {
                $out = $line;
                continue;
            }

            $prev_was_multiline = strpos( $lines[ $i - 1 ], "\n" ) !== false;
            $separator          = ( $is_multiline || $prev_was_multiline ) ? "\n\n" : "\n";
            $out               .= $separator . $line;
        }

        return $out;
    }

    // =========================================================================
    // Field formatters
    // =========================================================================

    /**
     * Format a single ACF field value for inclusion in the exported file.
     *
     * Routing by ACF field type:
     *   - wysiwyg → html_to_markdown() (paragraphs, lists, links survive)
     *   - textarea → newline-preserving cleaner; HTML converted if present
     *   - text / email / url / number / range / oembed / select / radio →
     *     single-line plain text via clean_content()
     *   - any string value that contains HTML tags is routed through the
     *     markdown converter regardless of declared type (safety net for
     *     fields where someone pasted HTML into a plain-text field)
     *
     * Layout:
     *   - When $inline_single_line is true (used for the business info block
     *     at the top of the full file), values that render to a single line
     *     are written as "Label: value". Multi-line values still get the
     *     "Label:\n<value>" block layout so they're readable.
     *   - When $inline_single_line is false (used for per-post ACF blocks,
     *     where most content is WYSIWYG/multi-line), every field uses the
     *     block layout.
     */
    private function format_field_value( string $field_name, $value, ?string $acf_label = null, string $field_type = '', bool $inline_single_line = false, bool $annotate = false ): string {
        $label = $acf_label ?? $this->acf_label_for_key( $field_name );

        if ( is_string( $value ) || is_numeric( $value ) ) {
            $str = (string) $value;

            if ( trim( $str ) === '' ) {
                return '';
            }

            $rendered = $this->render_string_field( $str, $field_type );

            if ( $rendered === '' ) {
                return '';
            }

            return $this->compose_label_value( $label, $rendered, $inline_single_line );
        }

        if ( is_bool( $value ) ) {
            return $this->compose_label_value( $label, $value ? 'Yes' : 'No', $inline_single_line );
        }

        if ( is_array( $value ) ) {
            $formatted = $this->format_array_value( $value, 0, $annotate );

            if ( ! $formatted ) {
                return '';
            }

            return $this->compose_label_value( $label, $formatted, $inline_single_line );
        }

        if ( is_object( $value ) && isset( $value->ID ) ) {
            $object_line = html_entity_decode( get_the_title( $value->ID ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' - ' . get_permalink( $value->ID );
            return $this->compose_label_value( $label, $object_line, $inline_single_line );
        }

        return '';
    }

    /**
     * Compose "Label: value" (single-line) or "Label:\nvalue" (block) based
     * on whether the rendered value contains newlines and whether the caller
     * has opted into inline rendering.
     */
    private function compose_label_value( string $label, string $rendered, bool $inline_single_line ): string {
        if ( $inline_single_line && strpos( $rendered, "\n" ) === false ) {
            return $label . ': ' . $rendered;
        }

        return $label . ":\n" . $rendered;
    }

    /**
     * Render a string ACF value based on its declared ACF field type.
     */
    private function render_string_field( string $str, string $field_type ): string {
        // Quick HTML sniff used as a safety net for plain-text fields that
        // contain pasted HTML. Matches the same tags html_to_markdown handles.
        $contains_html = (bool) preg_match( '/<\/?(p|br|ul|ol|li|h[1-6]|a|strong|em|b|i|blockquote)\b/i', $str );

        switch ( $field_type ) {

            // Rich text editor - always run through markdown converter.
            case 'wysiwyg':
                return $this->html_to_markdown( $str );

            // Multi-line plain text - preserve newlines. If someone has pasted
            // HTML in here, treat it as WYSIWYG to keep structure.
            case 'textarea':
                return $contains_html
                    ? $this->html_to_markdown( $str )
                    : $this->clean_multiline( $str );

            // oEmbed stores a URL that resolves to embed HTML. We want the URL,
            // not the rendered embed, so use clean_content (which strips tags).
            case 'oembed':
            // Single-line plain-text-ish fields.
            case 'text':
            case 'email':
            case 'url':
            case 'number':
            case 'range':
            case 'select':
            case 'radio':
            case 'button_group':
            case 'color_picker':
            case 'date_picker':
            case 'date_time_picker':
            case 'time_picker':
                return $contains_html
                    ? $this->html_to_markdown( $str )
                    : $this->clean_content( $str );

            // Unknown / unspecified type - fall back to the safety-net check.
            default:
                return $contains_html
                    ? $this->html_to_markdown( $str )
                    : $this->clean_content( $str );
        }
    }

    /**
     * Decide whether a repeater's rows should render tight (no blank line
     * between rows) or spaced (a blank line between rows, the existing
     * behaviour). Rows render tight only when EVERY row is a single, plain,
     * single-line scalar sub-field. As soon as a row has more than one
     * sub-field, a nested structure, a multi-line value (e.g. textarea) or
     * HTML (e.g. WYSIWYG), the whole repeater stays spaced so blocks like
     * FAQ keep their readable separation.
     *
     * Operates on values alone (no field schema), so it works the same for
     * post, options and term repeaters.
     */
    private function repeater_rows_are_simple( array $value ): bool {
        $saw_row = false;

        foreach ( $value as $key => $item ) {
            // Any string key means this isn't a plain numeric-indexed
            // repeater (it's a group or associative block) - keep it spaced.
            if ( is_string( $key ) ) {
                return false;
            }

            // A row that is itself a scalar (e.g. a multi-select list)
            // doesn't use the row-separator branch at all, so ignore it.
            if ( ! is_array( $item ) ) {
                continue;
            }

            $saw_row = true;

            // Count the row's non-empty sub-fields.
            $subfields = array_filter( $item, static function ( $v ) {
                return $v !== null && $v !== '' && $v !== [];
            } );

            if ( count( $subfields ) > 1 ) {
                return false; // multiple sub-fields per row → spaced
            }

            foreach ( $subfields as $sv ) {
                if ( is_array( $sv ) || is_object( $sv ) ) {
                    return false; // nested structure → spaced
                }

                $str = (string) $sv;

                if ( strpos( $str, "\n" ) !== false ) {
                    return false; // multi-line (textarea) → spaced
                }

                if ( preg_match( '/<\/?(p|br|ul|ol|li|h[1-6]|a|strong|em|b|i|blockquote)\b/i', $str ) ) {
                    return false; // HTML (WYSIWYG) → spaced
                }
            }
        }

        // Only tighten when we actually saw at least one array row.
        return $saw_row;
    }

    /**
     * Resolve an ACF field/sub-field name to its real ACF label, falling back
     * to a humanised version of the name when the field isn't found. Used when
     * rendering repeater and group sub-fields, where the value array only
     * carries field names (e.g. faq_question), not the labels the author set
     * (e.g. "FAQ Question").
     */
    private function acf_label_for_key( string $key ): string {
        $map = $this->acf_field_label_map();

        if ( isset( $map[ $key ] ) && $map[ $key ] !== '' ) {
            return $map[ $key ];
        }

        return ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
    }

    /**
     * Build (once per request) a map of ACF field name => label across every
     * field group, recursing into repeaters, groups and flexible-content
     * layouts. Names are effectively unique in practice; on the rare collision
     * the first label wins.
     */
    private function acf_field_label_map(): array {
        static $map = null;

        if ( $map !== null ) {
            return $map;
        }

        $map = [];

        if ( $this->acf_active() && function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
            foreach ( acf_get_field_groups() as $group ) {
                $key = (string) ( $group['key'] ?? '' );

                if ( $key === '' ) {
                    continue;
                }

                $this->acf_collect_field_labels( acf_get_fields( $key ), $map );
            }
        }

        return $map;
    }

    /**
     * Recursively collect field name => label pairs from an ACF field list.
     */
    private function acf_collect_field_labels( $fields, array &$map ): void {
        if ( ! is_array( $fields ) ) {
            return;
        }

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            $name  = (string) ( $field['name'] ?? '' );
            $label = trim( (string) ( $field['label'] ?? '' ) );

            if ( $name !== '' && $label !== '' && ! isset( $map[ $name ] ) ) {
                $map[ $name ] = $label;
            }

            if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
                $this->acf_collect_field_labels( $field['sub_fields'], $map );
            }

            if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
                foreach ( $field['layouts'] as $layout ) {
                    if ( is_array( $layout ) && ! empty( $layout['sub_fields'] ) ) {
                        $this->acf_collect_field_labels( $layout['sub_fields'], $map );
                    }
                }
            }
        }
    }

    private function format_array_value( array $value, int $depth = 0, bool $annotate = false ): string {
        $lines  = [];
        $indent = str_repeat( '  ', $depth );

        // Tight rows for simple single-value repeaters; spaced rows when any
        // row is multi-field, multi-line or rich text.
        $rows_tight = $this->repeater_rows_are_simple( $value );

        foreach ( $value as $key => $item ) {
            if ( $item === null || $item === '' || $item === [] ) {
                continue;
            }

            if ( is_object( $item ) && isset( $item->ID ) ) {
                $lines[] = $indent . '- ' . html_entity_decode( get_the_title( $item->ID ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' - ' . get_permalink( $item->ID );
                continue;
            }

            if ( is_array( $item ) ) {
                if ( $this->looks_like_acf_image( $item ) ) {
                    $alt     = trim( $item['alt'] ?? '' );
                    $img_url = $item['url'] ?? '';
                    $lines[] = $indent . '- Image: ' . ( $alt ?: 'no alt text' ) . ' (' . $img_url . ')';
                    continue;
                }

                if ( is_string( $key ) ) {
                    // Named array key (e.g. ACF group field). Emit a
                    // label heading like "Sub Field Name:" then the nested
                    // content underneath.
                    $sub_label = $this->acf_label_for_key( $key );
                    if ( $annotate ) {
                        $sub_label .= ' [' . $key . ']';
                    }
                    $lines[] = $indent . $sub_label . ':';

                    $nested = $this->format_array_value( $item, $depth + 1, $annotate );

                    if ( $nested ) {
                        $lines[] = $nested;
                    }

                    continue;
                }

                // Numeric array key = a repeater row. Render the row's
                // content, then prefix the first line with a "- " marker
                // so each row has a clear, unambiguous start. A blank line
                // before rows 2+ separates them. This avoids both the old
                // lone-"-" line AND the ambiguity of separating rows by
                // blank line alone (which would be indistinguishable from
                // the blank lines between an answer's own paragraphs).
                $nested = $this->format_array_value( $item, $depth + 1, $annotate );

                if ( $nested === '' ) {
                    continue;
                }

                if ( $lines && ! $rows_tight ) {
                    $lines[] = '';
                }

                $nested_lines = explode( "\n", $nested );
                $first        = ltrim( array_shift( $nested_lines ) );
                $lines[]      = $indent . '- ' . $first;

                foreach ( $nested_lines as $nested_line ) {
                    $lines[] = $nested_line;
                }

                continue;
            }

            if ( is_string( $item ) || is_numeric( $item ) ) {
                $str = (string) $item;

                // Detect HTML content (e.g. a WYSIWYG sub-field inside a
                // repeater row). Run it through the markdown converter so
                // paragraphs and lists survive instead of being flattened
                // to a comma-joined single line.
                $is_html = (bool) preg_match( '/<\/?(p|br|ul|ol|li|h[1-6]|a|strong|em|b|i|blockquote)\b/i', $str );

                if ( $is_html ) {
                    $rendered = $this->html_to_markdown( $str );

                    if ( $rendered === '' ) {
                        continue;
                    }

                    // Indent every line of the rendered markdown to match
                    // the surrounding repeater indentation.
                    $body = $this->indent_block( $rendered, $indent );

                    if ( is_string( $key ) ) {
                        $sub_label = $this->acf_label_for_key( $key );
                        if ( $annotate ) {
                            $sub_label .= ' [' . $key . ']';
                        }
                        $lines[] = $indent . $sub_label . ':';
                        $lines[] = $body;
                    } else {
                        $lines[] = $body;
                    }
                    continue;
                }

                if ( is_string( $key ) ) {
                    $sub_label = $this->acf_label_for_key( $key );
                    if ( $annotate ) {
                        $sub_label .= ' [' . $key . ']';
                    }
                    $lines[] = $indent . $sub_label . ': ' . $this->clean_content( $str );
                } else {
                    $lines[] = $indent . '- ' . $this->clean_content( $str );
                }
            }
        }

        // Filter out null entries but KEEP empty strings - they are the
        // intentional blank-line separators between repeater rows. Using
        // a bare array_filter() would drop them (empty string is falsy)
        // and glue adjacent rows together.
        $lines = array_filter( $lines, static function ( $line ) {
            return $line !== null;
        } );

        return implode( "\n", $lines );
    }

    /**
     * Apply a fixed indent to every line of a multi-line string.
     */
    private function indent_block( string $text, string $indent ): string {
        if ( $indent === '' ) {
            return $text;
        }

        $lines = explode( "\n", $text );

        foreach ( $lines as $i => $line ) {
            // Skip blank lines - leading indent on a blank line just creates
            // visible whitespace garbage.
            $lines[ $i ] = ( $line === '' ) ? '' : $indent . $line;
        }

        return implode( "\n", $lines );
    }

    private function looks_like_acf_image( array $item ): bool {
        return isset( $item['url'], $item['alt'], $item['ID'] );
    }

    // =========================================================================
    // Title / excerpt helpers
    // =========================================================================

    private function get_display_title( WP_Post $post ): string {
        if ( $this->acf_active() ) {
            $custom = get_field( 'page_custom_title', $post->ID );

            if ( $custom && is_string( $custom ) && trim( $custom ) !== '' ) {
                return $this->clean_content( $custom );
            }
        }

        return html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    private function get_excerpt( WP_Post $post ): string {
        $excerpt = trim( $post->post_excerpt );

        if ( ! $excerpt ) {
            $excerpt = wp_trim_words( wp_strip_all_tags( do_shortcode( $post->post_content ) ), 55 );
        }

        return $this->clean_content( $excerpt );
    }

    /**
     * Build a compact list of taxonomy assignments for a post, used to
     * annotate post entries in the slim file.
     *
     * Returns an array of strings like:
     *   [ "Category: News, Tips", "Tag: skincare, sun protection" ]
     *
     * Only taxonomies the user has ticked in settings are included, and
     * taxonomies registered against the post type but with no terms
     * assigned to this specific post are skipped.
     */
    private function get_taxonomy_metadata_for_post( int $post_id, string $post_type, array $selected_taxonomies ): array {
        $parts = [];

        $post_taxonomies = $this->get_taxonomies_for_post_type( $post_type );

        foreach ( $post_taxonomies as $tax_slug => $tax_obj ) {
            if ( ! in_array( $tax_slug, $selected_taxonomies, true ) ) {
                continue;
            }

            $terms = wp_get_post_terms( $post_id, $tax_slug );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            $term_names = array_map( fn( $t ) => $t->name, $terms );
            $parts[]    = $tax_obj->labels->singular_name . ': ' . implode( ', ', $term_names );
        }

        return $parts;
    }

    /**
     * Returns a human-readable role string for any "special" role this post
     * plays in WordPress - front page, blog page, privacy policy, WooCommerce
     * shop page, etc. Returns empty string if the post has no special role.
     *
     * Helps AI consumers understand site structure beyond just post type.
     */
    private function get_wp_post_role( WP_Post $post ): string {
        $roles = [];

        if ( (int) get_option( 'page_on_front' ) === $post->ID ) {
            $roles[] = 'Front Page (homepage)';
        }

        if ( (int) get_option( 'page_for_posts' ) === $post->ID ) {
            $roles[] = 'Posts Page (blog listing)';
        }

        if ( (int) get_option( 'wp_page_for_privacy_policy' ) === $post->ID ) {
            $roles[] = 'Privacy Policy Page';
        }

        // WooCommerce shop pages
        if ( function_exists( 'wc_get_page_id' ) ) {
            $wc_pages = [
                'shop'      => 'WooCommerce Shop Page',
                'cart'      => 'WooCommerce Cart Page',
                'checkout'  => 'WooCommerce Checkout Page',
                'myaccount' => 'WooCommerce My Account Page',
                'terms'     => 'WooCommerce Terms & Conditions Page',
            ];

            foreach ( $wc_pages as $wc_slug => $wc_label ) {
                if ( wc_get_page_id( $wc_slug ) === $post->ID ) {
                    $roles[] = $wc_label;
                }
            }
        }

        return implode( ', ', $roles );
    }

    /**
     * Front-end-safe equivalent of use_block_editor_for_post_type(). That core
     * function lives in wp-admin and isn't loaded during cron or front-end
     * (virtual) file generation, so we replicate its logic and apply the same
     * 'use_block_editor_for_post_type' filter. That filter is how plugins
     * (Classic Editor, some builders) force the classic editor while keeping
     * REST enabled, so honouring it keeps our answer accurate.
     */
    private function post_type_uses_block_editor( string $post_type ): bool {
        // Must support the editor and REST to use the block editor at all.
        if ( ! post_type_supports( $post_type, 'editor' ) ) {
            return false;
        }

        $pto = get_post_type_object( $post_type );

        if ( ! $pto || empty( $pto->show_in_rest ) ) {
            return false;
        }

        // 1. Admin and Site Enhancements (ASE) is authoritative when it's
        //    disabling Gutenberg. ASE applies its rule in the admin only, so
        //    reading its stored setting gives a consistent answer in every
        //    generation context (admin, cron, front-end virtual serving).
        $ase = $this->ase_block_editor_decision( $post_type );
        if ( $ase !== null ) {
            return $ase;
        }

        // 2. The Classic Editor plugin, when active, sets the site default.
        $classic = $this->classic_editor_plugin_decision( $post_type );
        if ( $classic !== null ) {
            return $classic;
        }

        // 3. Otherwise defer to WordPress, applying any other plugin's
        //    use_block_editor_for_post_type filter.
        return (bool) apply_filters( 'use_block_editor_for_post_type', true, $post_type );
    }

    /**
     * Block-editor decision from Admin and Site Enhancements (ASE), or null
     * when ASE isn't active or isn't disabling Gutenberg. ASE stores its rule
     * in the admin_site_enhancements option:
     *   disable_gutenberg       - master on/off
     *   disable_gutenberg_type  - 'only-on' | 'except-on' | 'all'
     *   disable_gutenberg_for   - map of post_type => bool (the ticked list)
     */
    private function ase_block_editor_decision( string $post_type ): ?bool {
        $opt = get_option( 'admin_site_enhancements' );

        if ( ! is_array( $opt ) || empty( $opt['disable_gutenberg'] ) ) {
            return null;
        }

        $type   = (string) ( $opt['disable_gutenberg_type'] ?? '' );
        $for    = is_array( $opt['disable_gutenberg_for'] ?? null ) ? $opt['disable_gutenberg_for'] : [];
        $listed = ! empty( $for[ $post_type ] );

        switch ( $type ) {
            case 'only-on':
                // Gutenberg disabled only on the listed types.
                return $listed ? false : true;
            case 'except-on':
                // Gutenberg disabled except on the listed types.
                return $listed ? true : false;
            case 'all':
                return false;
            default:
                return null;
        }
    }

    /**
     * Block-editor decision from the Classic Editor plugin, or null when that
     * plugin isn't active. The plugin stores its site default in the
     * classic-editor-replace option: 'classic' = classic editor, 'block' =
     * block editor. When it allows per-post switching we still report the
     * configured default for this post-type-level summary.
     */
    private function classic_editor_plugin_decision( string $post_type ): ?bool {
        if ( ! class_exists( 'Classic_Editor' ) ) {
            return null;
        }

        $replace = get_option( 'classic-editor-replace' );

        if ( $replace === 'classic' || $replace === 'replace' ) {
            return false;
        }

        if ( $replace === 'block' || $replace === 'no-replace' ) {
            return true;
        }

        return null;
    }

    /**
     * Detect which WP-native editor produced this post's content.
     *
     * Core is builder-agnostic: it only reports the native editors below.
     * Page builders are handled entirely by their own addons (the Bricks
     * addon, an Elementor addon, etc.), each of which hooks
     * socialbump_aiknowledge_post_meta_lines to override the
     * "Content Builder:" line when it detects its own content, and to correct
     * the "Not applicable" line to "Empty" when the builder is enabled for an
     * editor-less post type.
     *
     * Returned strings:
     *   - "Gutenberg (block editor)"
     *   - "Classic WYSIWYG"
     *   - "Not applicable (no content editor)" - the post type has the editor
     *     turned off (e.g. an ACF-only CPT), so there is no body-content area
     *     to report on. Distinct from "Empty".
     *   - "Empty" - the editor is supported but post_content is blank
     */
    private function get_content_type( WP_Post $post ): string {
        // Native WP editors only. Page builders (Bricks, Elementor, etc.) are
        // detected by their own addon files, which override this line via the
        // socialbump_aiknowledge_post_meta_lines filter. Core stays builder-
        // agnostic.
        $content = trim( (string) $post->post_content );

        if ( $content === '' ) {
            // No body content. Distinguish a post type that simply has the
            // editor switched off (an ACF-only CPT like Locations We Serve)
            // from one that supports the editor but was left blank.
            if ( ! post_type_supports( $post->post_type, 'editor' ) ) {
                return 'Not applicable (no content editor)';
            }

            return 'Empty';
        }

        if ( function_exists( 'has_blocks' ) && has_blocks( $post ) ) {
            return 'Gutenberg (block editor)';
        }

        return 'Classic WYSIWYG';
    }

    // =========================================================================
    // Default post content extractor
    //
    // Extensions can override content extraction via the
    // socialbump_aiknowledge_post_content_markdown filter. This default
    // method runs when no extension returns content - it reads post_content
    // and converts to markdown.
    // =========================================================================

    private function get_post_content_markdown( WP_Post $post ): string {
        // Classic WYSIWYG and Gutenberg both store real HTML in post_content,
        // so a single html_to_markdown pass is the right default.
        return $this->html_to_markdown( $post->post_content );
    }

    // =========================================================================
    // At-a-glance index builders (post type indexes + taxonomy term posts)
    // =========================================================================

    /**
     * Build the "Index:" block listed above the per-post entries for a post
     * type. Returns an array of lines, including the "Index:" heading itself.
     *
     * Hierarchical post types (page, services, etc.) render as a parent-child
     * tree via post_parent, indented two spaces per nesting level. Flat post
     * types render as a simple bulleted list.
     *
     * Blog posts are ordered by date (newest first). Everything else is
     * ordered by menu_order then title.
     *
     * Posts flagged with ACF hide_from_ai=1 are excluded, matching the rule
     * applied elsewhere in the export.
     *
     * Entry format: `- [Title](url)` (markdown link).
     *
     * @return string[] Lines for the index block, or empty array if none.
     */
    private function get_post_type_index( string $post_type, array $settings ): array {
        $pto = get_post_type_object( $post_type );

        if ( ! $pto ) {
            return [];
        }

        $is_hierarchical = ! empty( $pto->hierarchical );
        $is_blog         = ( $post_type === 'post' );

        if ( $is_blog ) {
            $orderby = 'date';
            $order   = 'DESC';
        } else {
            $orderby = 'menu_order title';
            $order   = 'ASC';
        }

        $items = $this->get_posts_cached( [
            'post_type'      => $post_type,
            'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
            'posts_per_page' => -1,
            'orderby'        => $orderby,
            'order'          => $order,
        ] );

        if ( ! $items ) {
            return [];
        }

        // Filter out hide_from_ai posts once up front.
        $visible = [];

        foreach ( $items as $item ) {
            if ( $this->is_post_object_excluded( $item, $post_type, $settings ) ) {
                continue;
            }

            $visible[ (int) $item->ID ] = $item;
        }

        if ( ! $visible ) {
            return [];
        }

        $lines = [ 'Index:' ];

        if ( $is_hierarchical ) {
            // Build a children-of-parent map. Root entries live under key 0.
            $children_of = [];

            foreach ( $visible as $item ) {
                $parent_id = (int) $item->post_parent;

                // If the parent isn't visible (hidden or unpublished), promote
                // this item to root so it still appears in the index.
                if ( $parent_id !== 0 && ! isset( $visible[ $parent_id ] ) ) {
                    $parent_id = 0;
                }

                $children_of[ $parent_id ][] = $item;
            }

            $this->append_post_index_tree( $lines, $children_of, 0, 0 );
        } else {
            foreach ( $visible as $item ) {
                $title = html_entity_decode( get_the_title( $item ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $url   = get_permalink( $item );
                $lines[] = '- [' . $title . '](' . $url . ')';
            }
        }

        return $lines;
    }

    /**
     * Depth-first walker for the hierarchical post type index. Appends
     * indented lines into $lines (by reference) so we don't pay the cost of
     * merging arrays at each recursion level.
     */
    private function append_post_index_tree( array &$lines, array $children_of, int $parent_id, int $depth ): void {
        if ( empty( $children_of[ $parent_id ] ) ) {
            return;
        }

        $indent = str_repeat( '  ', $depth );

        foreach ( $children_of[ $parent_id ] as $item ) {
            $title = html_entity_decode( get_the_title( $item ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $url   = get_permalink( $item );
            $lines[] = $indent . '- [' . $title . '](' . $url . ')';

            $this->append_post_index_tree( $lines, $children_of, (int) $item->ID, $depth + 1 );
        }
    }

    /**
     * Build the "Posts in this term:" block listed under each taxonomy term.
     *
     * Returns an array of lines including the "  Posts in this term:" label
     * itself, indented to nest visually inside the term block. Returns empty
     * array if the term has no visible posts.
     *
     * Posts are ordered the same way the post type index orders them: blog
     * categories/tags by date (newest first), everything else by menu_order.
     */
    private function get_term_posts_index( WP_Term $term, array $settings ): array {
        $is_blog = ( $term->taxonomy === 'category' || $term->taxonomy === 'post_tag' );

        if ( $is_blog ) {
            $orderby = 'date';
            $order   = 'DESC';
        } else {
            $orderby = 'menu_order title';
            $order   = 'ASC';
        }

        $items = $this->get_posts_cached( [
            'post_type'      => 'any',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
            'posts_per_page' => -1,
            'orderby'        => $orderby,
            'order'          => $order,
            'tax_query'      => [
                [
                    'taxonomy' => $term->taxonomy,
                    'field'    => 'term_id',
                    'terms'    => $term->term_id,
                ],
            ],
            // Titles and permalinks only, so the meta cache stays unprimed.
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ] );

        if ( ! $items ) {
            return [];
        }

        $lines = [];

        foreach ( $items as $item ) {
            if ( $this->is_post_object_excluded( $item, $item->post_type, $settings ) ) {
                continue;
            }

            $title = html_entity_decode( get_the_title( $item ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $url   = get_permalink( $item );
            $lines[] = '    - [' . $title . '](' . $url . ')';
        }

        if ( ! $lines ) {
            return [];
        }

        array_unshift( $lines, '  Posts in this term:' );

        return $lines;
    }

    // =========================================================================
    // Dynamic shortcode token resolver
    //
    // Content authored via Dynamic.ooo's Dynamic Shortcodes uses a curly-brace
    // syntax that maps 1:1 to a WordPress shortcode invocation. They only get
    // rendered to actual shortcode output at front-end display time, so when
    // we read raw postmeta or builder element data we see the raw tokens.
    //
    // This resolver finds those tokens, translates them to standard
    // shortcode syntax, runs them through do_shortcode() so any registered
    // shortcode handler (whether it ships with WordPress, a plugin, or the
    // site's own custom code) produces real output, then converts the
    // resulting HTML to markdown.
    //
    // Recognised token shape (case-insensitive, whitespace-tolerant):
    //   {wp-shortcode:NAME}
    //   {wp-shortcode:NAME @ key="val"}
    //   {wp-shortcode:NAME @ key="val" key2="val 2"}
    //
    // Params:
    //   - Any number of key="value" pairs in any order.
    //   - Keys are alphanumeric + underscore.
    //   - Values can contain spaces - anything except double-quote.
    //
    // The NAME can be any registered shortcode; we delegate to do_shortcode()
    // so the core has no hardcoded knowledge of specific shortcodes. Tokens
    // referencing unregistered shortcodes pass through unchanged (matching
    // WordPress's own behaviour for unknown shortcodes).
    // =========================================================================

    public function resolve_inline_post_tokens( string $text ): string {
        if ( strpos( $text, '{wp-shortcode' ) === false ) {
            return $text;
        }

        return preg_replace_callback(
            '/\{wp-shortcode:([a-z0-9_\-]+)(?:\s*@\s*([^}]*))?\}/i',
            function ( $m ) {
                $shortcode_name = strtolower( $m[1] );
                $params_blob    = isset( $m[2] ) ? trim( $m[2] ) : '';
                $params         = $this->parse_token_params( $params_blob );

                // Build the equivalent WordPress shortcode invocation.
                $shortcode = '[' . $shortcode_name;

                foreach ( $params as $key => $value ) {
                    // Escape any double quotes in values defensively, though
                    // the regex doesn't allow them through anyway.
                    $value      = str_replace( '"', '&quot;', $value );
                    $shortcode .= ' ' . $key . '="' . $value . '"';
                }

                $shortcode .= ']';

                // Delegate to do_shortcode(). If the shortcode isn't
                // registered, WordPress returns the raw shortcode string
                // unchanged - we detect that and return the original token
                // so unresolvable tokens are visible in the export rather
                // than silently disappearing.
                $rendered = do_shortcode( $shortcode );

                if ( $rendered === $shortcode ) {
                    return $m[0]; // unregistered shortcode - preserve original token
                }

                // Convert the rendered HTML to markdown so anchor tags
                // become [text](url), bold/italic survive, and structural
                // wrapper divs don't bleed into the export.
                return trim( $this->html_to_markdown( $rendered ) );
            },
            $text
        );
    }

    /**
     * Parse a `key="value" key2="value 2"` parameter blob into an associative
     * array. Tolerant of arbitrary param order and unknown keys.
     *
     * Used by resolve_inline_post_tokens() to translate token params into
     * shortcode attributes. Kept as a separate helper in case other
     * curly-brace token grammars need parsing later.
     *
     * @return array<string,string> Lowercased keys → raw values.
     */
    private function parse_token_params( string $blob ): array {
        $params = [];

        if ( $blob === '' ) {
            return $params;
        }

        // Match key="value" pairs. Keys are alphanumeric + underscore;
        // values are everything inside double quotes (escaped quotes are
        // unusual inside Dynamic.ooo tokens, so [^"]* is sufficient).
        if ( preg_match_all( '/([a-z_][a-z0-9_]*)\s*=\s*"([^"]*)"/i', $blob, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $params[ strtolower( $match[1] ) ] = $match[2];
            }
        }

        return $params;
    }

    // =========================================================================
    // HTML → Markdown converter
    //
    // Used for any multi-paragraph HTML: WP-native post_content, Bricks
    // rich-text/editor/text elements, accordion and tab bodies.
    //
    // Preserves: headings (h1-h6), paragraphs, ordered/unordered lists,
    // blockquotes, line breaks, links, bold, italic.
    // Strips: everything else, including scripts, styles, and Gutenberg
    // block comments. Renders shortcodes via do_shortcode().
    // =========================================================================

    /**
     * Convert HTML to markdown.
     *
     * @param string $html    Raw HTML input.
     * @param array  $options {
     *     Optional cleaning behaviour overrides.
     *
     *     @type bool  $aggressive            When true, strips presentational
     *                                        attributes (style, class, id,
     *                                        data-*, role, aria-*) and unwraps
     *                                        structural-only divs/sections
     *                                        before conversion. Designed for
     *                                        page-builder render output where
     *                                        wrapper divs vastly outnumber
     *                                        semantic content. Default false.
     *     @type array $strip_with_content    Tag names to remove ENTIRELY
     *                                        (including their inner content)
     *                                        before any other processing -
     *                                        useful for <svg>, <iframe>,
     *                                        <noscript>, etc. Default [].
     * }
     * @return string Cleaned markdown output.
     */
    public function html_to_markdown( string $html, array $options = [] ): string {
        if ( $html === '' ) {
            return '';
        }

        $aggressive         = ! empty( $options['aggressive'] );
        $strip_with_content = isset( $options['strip_with_content'] ) && is_array( $options['strip_with_content'] )
            ? $options['strip_with_content']
            : [];

        // 0. Resolve inline post-link tokens to markdown links before anything else.
        $html = $this->resolve_inline_post_tokens( $html );

        // 1. Strip Gutenberg block-editor comment markers.
        $html = preg_replace( '/<!--\s*\/?wp:[^>]*-->/i', '', $html );

        // 2. Strip <script> and <style> blocks entirely.
        $html = preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html );

        // 2a. Strip any caller-requested tags entirely (content and all).
        //     Used for things like <svg>, <iframe>, <noscript> in aggressive mode.
        foreach ( $strip_with_content as $tag ) {
            $tag = preg_quote( $tag, '/' );
            // Self-closing variant first (e.g. <img />, <hr />)
            $html = preg_replace( "/<{$tag}\b[^>]*\/>/is", '', $html );
            // Paired tag with content
            $html = preg_replace( "/<{$tag}\b[^>]*>.*?<\/{$tag}>/is", '', $html );
        }

        // 2a-ii. Insert a newline separator between adjacent button-style links.
        //     This has to run before the aggressive pass below strips class
        //     attributes, or the button classes it matches on are already gone.
        //     It sat after that pass once, and never matched renderer output.
        //     Builders (Bricks, Elementor, Gutenberg) often render button
        //     groups as N sibling <a> tags inside a wrapper div, with no
        //     intermediate whitespace. Default behaviour would concatenate
        //     them into one unreadable line of `[btn1](u)[btn2](u)`. We
        //     detect adjacency by looking for <a ...class="...button..."
        //     directly followed by another <a> with similar styling and
        //     insert a newline between them. Loop until no more pairs are
        //     found because preg_replace handles one match per overlapping
        //     region at a time.
        $previous_btn_pass = null;
        $btn_pattern = '/(<a\b[^>]*class=["\'][^"\']*\b(?:button|btn)\b[^"\']*["\'][^>]*>.*?<\/a>)\s*(?=<a\b[^>]*class=["\'][^"\']*\b(?:button|btn)\b)/is';
        while ( $previous_btn_pass !== $html ) {
            $previous_btn_pass = $html;
            $html = preg_replace( $btn_pattern, "$1\n\n", $html );
        }
        // 2b. Aggressive pre-cleaning for page-builder render output.
        if ( $aggressive ) {
            $html = $this->strip_presentational_attributes( $html );
            $html = $this->unwrap_structural_containers( $html );
        }
        // 2c. Any links still touching each other get a space between them, so
        //     two adjacent anchors never run into one word. Builders minify their
        //     markup and leave no whitespace between sibling elements.
        $html = preg_replace( '/<\/a>(?=<a\b)/i', '</a> ', $html );

        // 3. Render shortcodes so dynamic content (e.g. ACF inline tags) survives.
        $html = do_shortcode( $html );

        // 4. Collapse runs of spaces and tabs, preserving newlines.
        $html = preg_replace( "/[ \t]+/", ' ', $html );

        // 5. Inline conversions - run BEFORE block conversions so block-level
        //    callbacks can safely strip remaining tags without losing emphasis.
        $html = preg_replace( '/<(strong|b)\b[^>]*>(.*?)<\/\1>/is', '**$2**', $html );
        $html = preg_replace( '/<(em|i)\b[^>]*>(.*?)<\/\1>/is', '*$2*', $html );


        $html = preg_replace_callback(
            '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            function ( $m ) {
                $url  = trim( $m[1] );
                $text = trim( wp_strip_all_tags( $m[2] ) );
                if ( $text === '' && $url === '' ) {
                    return '';
                }
                if ( $text === '' ) {
                    return $url;
                }
                // Drop links whose target is only an on-page anchor (e.g.
                // href="#consultation"). The fragment is meaningless in a flat
                // text file, so keep the visible text and discard the link.
                if ( $url === '' || strpos( $url, '#' ) === 0 ) {
                    return $text;
                }
                return '[' . $text . '](' . $url . ')';
            },
            $html
        );

        // 6. Block conversions.

        // Tables. Each row becomes a line with the cells separated by a bar,
        // and a header row is followed by a rule so it reads as a table. Without
        // this the cells ran together into one word.
        $html = preg_replace_callback(
            '/<table\b[^>]*>(.*?)<\/table>/is',
            function ( $m ) {
                preg_match_all( '/<tr\b[^>]*>(.*?)<\/tr>/is', $m[1], $rows );
                $lines = [];

                foreach ( $rows[1] as $row ) {
                    preg_match_all( '/<(t[dh])\b[^>]*>(.*?)<\/\1>/is', $row, $cells );
                    $texts = [];

                    foreach ( $cells[2] as $cell ) {
                        $texts[] = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $cell ) ) );
                    }

                    if ( ! array_filter( $texts, 'strlen' ) ) {
                        continue;
                    }

                    $lines[] = '| ' . implode( ' | ', $texts ) . ' |';

                    if ( count( $lines ) === 1 && stripos( $row, '<th' ) !== false ) {
                        $lines[] = '|' . str_repeat( ' --- |', count( $texts ) );
                    }
                }

                return $lines ? "\n\n" . implode( "\n", $lines ) . "\n\n" : '';
            },
            $html
        );

        // Headings h6 → h1 so longer tags can't accidentally match shorter ones.
        for ( $i = 6; $i >= 1; $i-- ) {
            $hashes = str_repeat( '#', $i );
            $html = preg_replace_callback(
                "/<h{$i}\b[^>]*>(.*?)<\/h{$i}>/is",
                function ( $m ) use ( $hashes ) {
                    $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $m[1] ) ) );
                    return $text === '' ? '' : "\n\n{$hashes} {$text}\n\n";
                },
                $html
            );
        }

        // Lists - repeat until no list tags remain so nested lists collapse
        // from inside out. The tempered greedy token only matches lists that
        // don't themselves contain another <ul> or <ol>.
        $previous = null;

        while ( $previous !== $html ) {
            $previous = $html;

            $html = preg_replace_callback(
                '/<ol\b[^>]*>((?:(?!<[ou]l\b).)*?)<\/ol>/is',
                function ( $m ) {
                    preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $m[1], $items );
                    $lines = [];
                    $any_multiline = false;
                    foreach ( $items[1] as $idx => $li ) {
                        $clean = $this->render_list_item_content( $li );
                        if ( $clean !== '' ) {
                            $line = $this->format_list_line( ( $idx + 1 ) . '. ', $clean );
                            if ( strpos( $line, "\n" ) !== false ) {
                                $any_multiline = true;
                            }
                            $lines[] = $line;
                        }
                    }
                    // If any item is multi-line (heading + paragraph),
                    // separate ALL items with a blank line so markdown
                    // renderers don't glue the next marker onto the
                    // previous item's content.
                    $separator = $any_multiline ? "\n\n" : "\n";
                    return $lines ? "\n\n" . implode( $separator, $lines ) . "\n\n" : '';
                },
                $html
            );

            $html = preg_replace_callback(
                '/<ul\b[^>]*>((?:(?!<[ou]l\b).)*?)<\/ul>/is',
                function ( $m ) {
                    preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $m[1], $items );
                    $lines = [];
                    $any_multiline = false;
                    foreach ( $items[1] as $li ) {
                        $clean = $this->render_list_item_content( $li );
                        if ( $clean !== '' ) {
                            $line = $this->format_list_line( '- ', $clean );
                            if ( strpos( $line, "\n" ) !== false ) {
                                $any_multiline = true;
                            }
                            $lines[] = $line;
                        }
                    }
                    $separator = $any_multiline ? "\n\n" : "\n";
                    return $lines ? "\n\n" . implode( $separator, $lines ) . "\n\n" : '';
                },
                $html
            );
        }

        // Blockquotes.
        $html = preg_replace_callback(
            '/<blockquote\b[^>]*>(.*?)<\/blockquote>/is',
            function ( $m ) {
                $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $m[1] ) ) );
                return $text === '' ? '' : "\n\n> {$text}\n\n";
            },
            $html
        );

        // Paragraphs.
        $html = preg_replace( '/<p\b[^>]*>(.*?)<\/p>/is', "\n\n$1\n\n", $html );

        // Line breaks. First, normalise whitespace immediately surrounding
        // each <br> so an editor-inserted "<br>\n" doesn't produce two
        // newlines. Two or more consecutive <br>s (a user-intended blank
        // line) collapse to a double newline.
        $html = preg_replace( '/[ \t]*<br\s*\/?>[ \t]*/i', '<br>', $html );
        $html = preg_replace( '/\n+\s*(<br>)\s*/i', '$1', $html );
        $html = preg_replace( '/\s*(<br>)\s*\n+/i', '$1', $html );
        $html = preg_replace( '/(<br>){2,}/i', "\n\n", $html );
        $html = preg_replace( '/<br>/i', "\n", $html );

        // 7. Strip any remaining tags.
        $html = wp_strip_all_tags( $html );

        // 8. Decode HTML entities.
        $html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );

        // 9. Tidy whitespace: collapse runs of 3+ newlines, strip trailing
        //    spaces on each line. Leading whitespace is preserved for list
        //    continuation lines (those indented under a "- " or "N. " list
        //    item) so multi-line list items render correctly.
        $html = preg_replace( "/\n{3,}/", "\n\n", $html );
        $html = preg_replace( '/[ \t]+$/m', '', $html );

        // Process leading whitespace line-by-line so we can keep
        // list-item indentation. A line is a "list continuation" when:
        //   - it has leading whitespace, AND
        //   - the most recent non-blank line above started with a list
        //     marker (`- ` or `N. `).
        // Continuation lines keep their indent; everything else gets
        // trimmed of leading whitespace.
        $lines       = explode( "\n", $html );
        $cleaned     = [];
        $in_list     = false;
        foreach ( $lines as $line ) {
            $trimmed = ltrim( $line );

            if ( $trimmed === '' ) {
                // Blank line - preserve as-is, but don't flip out of
                // list mode yet (a blank line between list item and its
                // continuation is allowed).
                $cleaned[] = '';
                continue;
            }

            if ( preg_match( '/^(?:-|\d+\.) /', $trimmed ) ) {
                // New list item starts here.
                $cleaned[] = $trimmed;
                $in_list   = true;
                continue;
            }

            if ( $in_list && preg_match( '/^[ \t]+/', $line ) ) {
                // Indented line inside a list - preserve the indent.
                $cleaned[] = $line;
                continue;
            }

            // Anything else: strip leading whitespace, leave list mode.
            $cleaned[] = $trimmed;
            $in_list   = false;
        }
        $html = implode( "\n", $cleaned );

        return trim( $html );
    }

    /**
     * Strip presentational attributes from every tag in the input HTML.
     *
     * Removes: style, class, id, role, aria-*, data-*.
     *
     * Page builders generate HTML where 95% of attribute bytes are presentation
     * (e.g. class="elementor-element elementor-element-7a3b9c8 elementor-widget
     *  elementor-widget-heading"). None of it contributes meaning for an AI
     * reader, and it interferes with downstream regex matching, so we strip
     * it before the main conversion runs.
     *
     * Semantic attributes (href on <a>, src/alt on <img>, etc.) are preserved.
     */
    /**
     * Render the inner content of an <li> for markdown list output.
     *
     * Page builders often put rich content inside list items - headings,
     * paragraphs, multiple anchors. By the time the list converter runs,
     * headings are already markdown (\n\n### Title\n\n) and paragraphs
     * are already \n\n-wrapped. If we just strip tags and squash whitespace
     * we lose all that structure. Instead: strip remaining tags, then
     * keep paragraph breaks visible as line breaks within the item.
     */
    private function render_list_item_content( string $li ): string {
        // Strip any remaining HTML tags (headings/paragraphs are already
        // converted to markdown at this point).
        $text = wp_strip_all_tags( $li );

        // Normalise line endings.
        $text = str_replace( [ "\r\n", "\r" ], "\n", $text );

        // Collapse runs of spaces/tabs on each line but preserve newlines.
        $text = preg_replace( "/[ \t]+/", ' ', $text );

        // Collapse 3+ consecutive newlines to exactly two.
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );

        // Trim leading/trailing whitespace on each line.
        $text = preg_replace( "/^[ \t]+/m", '', $text );
        $text = preg_replace( "/[ \t]+$/m", '', $text );
        $text = trim( $text );

        // A heading inside a list item, which is how a builder card grid comes
        // out (li > h3 > a, then an excerpt), used to render as a markdown heading
        // on the bullet. Make it bold instead, and when a single paragraph
        // follows, put the two on one line: - **[Title](url)**: excerpt.
        $lines = explode( "\n", $text );

        if ( count( $lines ) && preg_match( '/^#{1,6}\s+(.+)$/', $lines[0], $m ) ) {
            $lines[0] = '**' . trim( $m[1] ) . '**';

            $rest = array_values( array_filter( array_slice( $lines, 1 ), 'strlen' ) );

            if ( count( $rest ) === 1 ) {
                return $lines[0] . ': ' . $rest[0];
            }

            $text = implode( "\n", $lines );
        }

        return $text;
    }

    /**
     * Format a single list line given its marker (e.g. "- " or "1. ") and
     * the rendered content. If content is single-line, output is `marker
     * content`. If multi-line, continuation lines are indented to align
     * with the content (2 spaces for "- ", more for longer markers) so the
     * markdown renders correctly as a single list item.
     */
    private function format_list_line( string $marker, string $content ): string {
        $lines = explode( "\n", $content );

        // Filter empty lines while preserving paragraph breaks.
        $cleaned = [];
        $prev_blank = false;
        foreach ( $lines as $line ) {
            $line = rtrim( $line );
            if ( $line === '' ) {
                if ( ! $prev_blank && $cleaned ) {
                    $cleaned[] = '';
                }
                $prev_blank = true;
            } else {
                $cleaned[] = $line;
                $prev_blank = false;
            }
        }

        if ( ! $cleaned ) {
            return '';
        }

        if ( count( $cleaned ) === 1 ) {
            return $marker . $cleaned[0];
        }

        $indent = str_repeat( ' ', strlen( $marker ) );
        $out    = $marker . $cleaned[0];

        for ( $i = 1; $i < count( $cleaned ); $i++ ) {
            $out .= "\n" . ( $cleaned[ $i ] === '' ? '' : $indent . $cleaned[ $i ] );
        }

        return $out;
    }

    private function strip_presentational_attributes( string $html ): string {
        // style="..." and style='...'
        $html = preg_replace( '/\sstyle\s*=\s*"[^"]*"/i', '', $html );
        $html = preg_replace( "/\sstyle\s*=\s*'[^']*'/i", '', $html );

        // class="..." and class='...'
        $html = preg_replace( '/\sclass\s*=\s*"[^"]*"/i', '', $html );
        $html = preg_replace( "/\sclass\s*=\s*'[^']*'/i", '', $html );

        // id="..." and id='...'
        $html = preg_replace( '/\sid\s*=\s*"[^"]*"/i', '', $html );
        $html = preg_replace( "/\sid\s*=\s*'[^']*'/i", '', $html );

        // role="..." and role='...'
        $html = preg_replace( '/\srole\s*=\s*"[^"]*"/i', '', $html );
        $html = preg_replace( "/\srole\s*=\s*'[^']*'/i", '', $html );

        // aria-*="..." attributes (any prefix matching aria-foo)
        $html = preg_replace( '/\saria-[a-z0-9_-]+\s*=\s*"[^"]*"/i', '', $html );
        $html = preg_replace( "/\saria-[a-z0-9_-]+\s*=\s*'[^']*'/i", '', $html );

        // data-*="..." attributes
        $html = preg_replace( '/\sdata-[a-z0-9_-]+\s*=\s*"[^"]*"/i', '', $html );
        $html = preg_replace( "/\sdata-[a-z0-9_-]+\s*=\s*'[^']*'/i", '', $html );

        return $html;
    }

    /**
     * Unwrap structural-only containers (<div> and <section>) that have no
     * remaining attributes after presentational attributes are stripped.
     *
     * Page-builder HTML wraps every piece of content in nested <div> layers
     * for layout. Once their classes are gone, these divs carry zero meaning -
     * they're just noise that bloats the eventual markdown.
     *
     * Replaces "<div>content</div>" with just "content" and adds a paragraph
     * break between blocks so prose stays separated visually.
     *
     * Loop continues until no more matches occur (handles arbitrarily deep
     * nesting) or a safety limit is hit (prevents pathological inputs from
     * looping forever).
     */
    private function unwrap_structural_containers( string $html ): string {
        $max_iterations = 20;
        $iteration      = 0;

        while ( $iteration < $max_iterations ) {
            $previous = $html;

            // Unwrap <div> or <section> with no attributes - replace opening
            // and closing tags with paragraph-break whitespace. The browser
            // wouldn't render anything visual for these tags anyway once
            // attributes are gone.
            $html = preg_replace( '/<(div|section)\s*>/i', "\n", $html );
            $html = preg_replace( '/<\/(div|section)\s*>/i', "\n", $html );

            // Also unwrap <span> with no attributes - those are pure
            // wrappers around inline text.
            $html = preg_replace( '/<span\s*>/i', '', $html );
            $html = preg_replace( '/<\/span\s*>/i', '', $html );

            // Stop if nothing changed this round.
            if ( $html === $previous ) {
                break;
            }

            $iteration++;
        }

        return $html;
    }

    // =========================================================================
    // Content cleaning / truncation
    //
    // clean_content()   - single-line scrubber. Joins multi-line text with
    //                     commas. Used for titles, taxonomy descriptions, ACF
    //                     text fields, image alts, list item labels.
    // clean_multiline() - like clean_content() but preserves newlines. Used
    //                     for ACF textarea fields where line structure matters.
    // html_to_markdown() - full HTML → markdown converter for rich content.
    // =========================================================================

    public function clean_content( string $content ): string {
        $content = $this->resolve_inline_post_tokens( $content );
        $content = str_replace( [ "\r\n", "\r", "\n" ], ', ', $content );
        $content = str_ireplace( [ '<br>', '<br/>', '<br />', '</p>', '</li>' ], ', ', $content );
        $content = do_shortcode( $content );
        $content = wp_strip_all_tags( $content );
        $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
        $content = preg_replace( '/,\s*,/', ',', $content );
        $content = preg_replace( '/\s+/', ' ', $content );
        return trim( $content, ' ,' );
    }

    /**
     * Cleaner that preserves newlines. Used for ACF textarea fields where
     * users have entered multi-line plain text and the line structure carries
     * meaning (e.g. address blocks, bullet-style lists typed without HTML).
     */
    public function clean_multiline( string $content ): string {
        $content = $this->resolve_inline_post_tokens( $content );
        // Normalise line endings to \n.
        $content = str_replace( [ "\r\n", "\r" ], "\n", $content );
        $content = do_shortcode( $content );
        $content = wp_strip_all_tags( $content );
        $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
        // Collapse runs of spaces/tabs but not newlines.
        $content = preg_replace( "/[ \t]+/", ' ', $content );
        // Trim trailing whitespace on each line.
        $content = preg_replace( '/[ \t]+$/m', '', $content );
        // Collapse 3+ blank lines down to 2 (one blank line between blocks).
        $content = preg_replace( "/\n{3,}/", "\n\n", $content );
        return trim( $content );
    }

    private function truncate( string $text, int $limit ): string {
        if ( mb_strlen( $text ) <= $limit ) {
            return $text;
        }

        return rtrim( mb_substr( $text, 0, $limit - 3 ) ) . '...';
    }

    // =========================================================================
    // ACF architecture map (details file appendix)
    // =========================================================================

    /**
     * Build the ACF Architecture Map appendix (details file only): a
     * consolidated, structural overview of the site's ACF setup. Lists every
     * field group, where it attaches, and its fields and types, plus an
     * attachment overview grouped by post type / taxonomy / options page.
     *
     * This is the schema layer only - field VALUES are not repeated here, they
     * appear in the sections above. ACF only; returns '' when ACF is inactive
     * or there are no field groups.
     */
    /**
     * Parse the field-omit rules submitted as a JSON string by the admin UI
     * into a clean, validated array. Each rule is
     *   [ 'pt' => post_type|'__all__', 'field' => field_name, 'file' => 'full'|'details' ]
     * Invalid or incomplete rules are dropped.
     */
    private function parse_field_omit_rules( $raw ): array {
        if ( ! is_string( $raw ) || $raw === '' ) {
            return [];
        }

        $decoded = json_decode( wp_unslash( $raw ), true );

        if ( ! is_array( $decoded ) ) {
            return [];
        }

        $out  = [];
        $seen = [];

        foreach ( $decoded as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }

            $pt    = sanitize_key( (string) ( $rule['pt'] ?? '' ) );
            $field = sanitize_key( (string) ( $rule['field'] ?? '' ) );
            $file  = (string) ( $rule['file'] ?? '' );

            if ( $pt === '' || $field === '' || ! in_array( $file, [ 'full', 'details', 'both' ], true ) ) {
                continue;
            }

            $key = $pt . '|' . $field . '|' . $file;

            if ( isset( $seen[ $key ] ) ) {
                continue;
            }

            $seen[ $key ] = true;
            $out[]        = [ 'pt' => $pt, 'field' => $field, 'file' => $file ];
        }

        return $out;
    }

    /**
     * Whether a given field should be omitted from the current file for the
     * current post type. A rule matches when its file matches, its field name
     * matches, and its post type is either the post type in hand or the
     * "__all__" wildcard. Rules stack: any matching rule omits the field.
     */
    private function field_is_omitted( string $field_name, string $post_type, bool $details_mode, array $settings ): bool {
        $rules = $settings['field_omit_rules'] ?? [];

        if ( ! is_array( $rules ) || ! $rules ) {
            return false;
        }

        $file = $details_mode ? 'details' : 'full';

        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }

            $rule_file = $rule['file'] ?? '';

            // A rule for both files matches whichever one is being written.
            if ( $rule_file !== 'both' && $rule_file !== $file ) {
                continue;
            }

            if ( ( $rule['field'] ?? '' ) !== $field_name ) {
                continue;
            }

            $rule_pt = $rule['pt'] ?? '';

            if ( $rule_pt === '__all__' || $rule_pt === $post_type ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map of post type => [ field_name => label ] for the top-level ACF fields
     * attached to each post type, used to drive the omit-rule autocomplete.
     * Presentational and sensitive fields are excluded. Memoised per request.
     */
    private function get_acf_fields_by_post_type(): array {
        static $cache = null;

        if ( $cache !== null ) {
            return $cache;
        }

        $cache = [];

        if ( ! $this->acf_active() || ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
            return $cache;
        }

        $skip = [ 'tab', 'message', 'accordion' ];

        foreach ( acf_get_field_groups() as $group ) {
            $key = (string) ( $group['key'] ?? '' );

            if ( $key === '' ) {
                continue;
            }

            $fields = acf_get_fields( $key );

            if ( ! is_array( $fields ) ) {
                continue;
            }

            $top = [];

            foreach ( $fields as $field ) {
                if ( ! is_array( $field ) ) {
                    continue;
                }

                $name = (string) ( $field['name'] ?? '' );
                $type = (string) ( $field['type'] ?? '' );

                if ( $name === '' || in_array( $type, $skip, true ) || $this->is_sensitive_field_name( $name ) ) {
                    continue;
                }

                $label        = trim( (string) ( $field['label'] ?? '' ) );
                $top[ $name ] = $label !== '' ? $label : $name;
            }

            if ( ! $top ) {
                continue;
            }

            $targets = $this->acf_parse_group_locations( $group['location'] ?? [] );

            foreach ( $targets['post_type'] as $pt ) {
                foreach ( $top as $name => $label ) {
                    if ( ! isset( $cache[ $pt ][ $name ] ) ) {
                        $cache[ $pt ][ $name ] = $label;
                    }
                }
            }
        }

        return $cache;
    }

    private function build_acf_architecture_map(): string {
        if ( ! $this->acf_active() || ! function_exists( 'acf_get_field_groups' ) ) {
            return '';
        }

        $groups = acf_get_field_groups();

        if ( ! $groups ) {
            return '';
        }

        $parsed        = [];
        $by_post_type  = [];
        $by_taxonomy   = [];
        $by_options    = [];

        foreach ( $groups as $group ) {
            $key   = (string) ( $group['key'] ?? '' );
            $title = html_entity_decode( (string) ( $group['title'] ?? '(untitled)' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            $fields      = function_exists( 'acf_get_fields' ) ? acf_get_fields( $key ) : [];
            $fields      = is_array( $fields ) ? $fields : [];
            $field_count = $this->acf_count_schema_fields( $fields );

            // Skip field groups with no schema-bearing fields. These are
            // usually a third-party plugin registering a settings page as an
            // empty ACF group (e.g. a Bricks add-on's options page) and carry
            // no structural information worth mapping. Dropping them also
            // removes any options page whose only group is empty.
            if ( $field_count === 0 ) {
                continue;
            }

            $targets = $this->acf_parse_group_locations( $group['location'] ?? [] );

            foreach ( $targets['post_type'] as $pt ) {
                $by_post_type[ $pt ][] = $title;
            }
            foreach ( $targets['taxonomy'] as $tx ) {
                $by_taxonomy[ $tx ][] = $title;
            }
            foreach ( $targets['options_page'] as $op ) {
                $by_options[ $op ][] = $title;
            }

            $parsed[] = [
                'title'       => $title,
                'key'         => $key,
                'location'    => $targets['summary'],
                'description' => trim( (string) ( $group['description'] ?? '' ) ),
                'fields'      => $fields,
                'field_count' => $field_count,
            ];
        }

        $out   = [];
        $out[] = str_repeat( '=', 80 );
        $out[] = '## ACF ARCHITECTURE MAP';
        $out[] = '';
        $out[] = 'Structural overview of the Advanced Custom Fields setup: every field';
        $out[] = 'group, where it attaches, and its fields and types. Field values are not';
        $out[] = 'repeated here (see the sections above). Architecture reference only.';
        $out[] = '';
        $out[] = 'Field groups: ' . count( $parsed );
        $out[] = '';

        // ---- Post types ----
        $out[] = '### Post types';
        $out[] = '';

        if ( $by_post_type ) {
            ksort( $by_post_type );
            foreach ( $by_post_type as $pt => $titles ) {
                $pto  = get_post_type_object( $pt );
                $name = ( ( $pto && ! empty( $pto->label ) ) ? $pto->label : $pt ) . ' [' . $pt . ']';

                foreach ( $this->acf_render_attachment_block( $name, $titles ) as $l ) {
                    $out[] = $l;
                }
            }
        } else {
            $out[] = '  - (none)';
        }
        $out[] = '';

        // ---- Taxonomies ----
        // Enumerated from ACF's own taxonomy registrations (not just field
        // group locations) so an ACF taxonomy with no fields attached still
        // appears here. Any taxonomy that does have field groups but isn't
        // ACF-registered is merged in too.
        $out[] = '### Taxonomies';
        $out[] = '';

        $tax_defs = $this->acf_registered_taxonomies();

        foreach ( array_keys( $by_taxonomy ) as $slug ) {
            if ( ! isset( $tax_defs[ $slug ] ) ) {
                $tx_obj            = get_taxonomy( $slug );
                $tax_defs[ $slug ] = [
                    'title'      => $tx_obj ? $tx_obj->label : $slug,
                    'post_types' => ( $tx_obj && is_array( $tx_obj->object_type ) ) ? $tx_obj->object_type : [],
                ];
            }
        }

        if ( $tax_defs ) {
            ksort( $tax_defs );
            foreach ( $tax_defs as $slug => $def ) {
                $title   = $def['title'] ?? $slug;
                $used_by = ! empty( $def['post_types'] ) ? implode( ', ', $def['post_types'] ) : '(none)';
                $groups  = $by_taxonomy[ $slug ] ?? [];

                foreach ( $this->acf_render_attachment_block( $title . ' [' . $slug . ']', $groups, 'Used by: ' . $used_by ) as $l ) {
                    $out[] = $l;
                }
            }
        } else {
            $out[] = '  - (none)';
        }
        $out[] = '';

        // ---- Options pages ----
        $out[] = '### Options pages';
        $out[] = '';

        if ( $by_options ) {
            $op_meta = $this->acf_options_page_meta();
            ksort( $by_options );
            foreach ( $by_options as $op => $titles ) {
                $meta  = $op_meta[ $op ] ?? [];
                $label = ! empty( $meta['title'] ) ? $meta['title'] : $op;
                $name  = $label . ' [' . $op . ']';

                if ( ! empty( $meta['description'] ) ) {
                    $name .= ': ' . $meta['description'];
                }

                foreach ( $this->acf_render_attachment_block( $name, $titles ) as $l ) {
                    $out[] = $l;
                }
            }
        } else {
            $out[] = '  - (none)';
        }
        $out[] = '';

        // ---- Field group definitions ----
        $out[] = '### Field groups';
        $out[] = '';

        foreach ( $parsed as $p ) {
            $out[] = str_repeat( '-', 80 );
            $out[] = '#### ' . $p['title'];
            $out[] = 'Group key: ' . $p['key'];
            $out[] = 'Shows on: ' . ( $p['location'] !== '' ? $p['location'] : '(no location rules)' );

            if ( $p['description'] !== '' ) {
                $out[] = 'Description: ' . $p['description'];
            }

            $field_lines = $this->acf_render_field_schema( $p['fields'], 1 );
            $field_count = $p['field_count'];

            $out[] = 'Fields (' . $field_count . '):';

            if ( $field_lines ) {
                foreach ( $field_lines as $fl ) {
                    $out[] = $fl;
                }
            } else {
                $out[] = '  (none)';
            }

            $out[] = '';
        }

        return implode( "\n", $out );
    }

    /**
     * Parse an ACF field group's location rules into target buckets plus a
     * human-readable summary. ACF stores location as an OR-array of AND-arrays
     * of { param, operator, value } rules.
     */
    private function acf_parse_group_locations( $location ): array {
        $post_type = [];
        $taxonomy  = [];
        $options   = [];
        $summary   = [];

        if ( ! is_array( $location ) ) {
            return [ 'post_type' => [], 'taxonomy' => [], 'options_page' => [], 'summary' => '' ];
        }

        foreach ( $location as $or_group ) {
            if ( ! is_array( $or_group ) ) {
                continue;
            }

            $and_parts = [];

            foreach ( $or_group as $rule ) {
                if ( ! is_array( $rule ) || empty( $rule['param'] ) ) {
                    continue;
                }

                $param = (string) $rule['param'];
                $op    = (string) ( $rule['operator'] ?? '==' );
                $value = (string) ( $rule['value'] ?? '' );
                $neg   = ( $op === '!=' ) ? 'not ' : '';

                switch ( $param ) {
                    case 'post_type':
                        if ( $op === '==' ) {
                            $post_type[] = $value;
                        }
                        $and_parts[] = 'Post type: ' . $neg . $value;
                        break;
                    case 'taxonomy':
                        if ( $op === '==' ) {
                            $taxonomy[] = $value;
                        }
                        $and_parts[] = 'Taxonomy: ' . $neg . $value;
                        break;
                    case 'options_page':
                        if ( $op === '==' ) {
                            $options[] = $value;
                        }
                        $and_parts[] = 'Options page: ' . $neg . $value;
                        break;
                    default:
                        $and_parts[] = $param . ': ' . $neg . $value;
                }
            }

            if ( $and_parts ) {
                $summary[] = implode( ' AND ', $and_parts );
            }
        }

        return [
            'post_type'    => array_values( array_unique( $post_type ) ),
            'taxonomy'     => array_values( array_unique( $taxonomy ) ),
            'options_page' => array_values( array_unique( $options ) ),
            'summary'      => implode( '; ', $summary ),
        ];
    }

    /**
     * Render a field group's fields as an indented schema list, recursing into
     * repeaters, groups and flexible-content layouts. Presentational fields
     * (tab / message / accordion) and sensitive-named fields are skipped.
     * Relational fields show their target post types or taxonomy.
     */
    private function acf_render_field_schema( array $fields, int $depth ): array {
        $skip   = [ 'tab', 'message', 'accordion' ];
        $indent = str_repeat( '  ', $depth );
        $lines  = [];

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            $name = (string) ( $field['name'] ?? '' );
            $type = (string) ( $field['type'] ?? '' );

            if ( $name === '' || in_array( $type, $skip, true ) || $this->is_sensitive_field_name( $name ) ) {
                continue;
            }

            $label   = trim( (string) ( $field['label'] ?? '' ) );
            $display = ( $label !== '' ? $label . ' ' : '' ) . '[' . $name . '] (' . $type . ')';

            $suffix = '';

            if ( in_array( $type, [ 'relationship', 'post_object', 'page_link' ], true ) ) {
                $pts    = ( isset( $field['post_type'] ) && is_array( $field['post_type'] ) ) ? array_filter( $field['post_type'] ) : [];
                $suffix = ' -> ' . ( $pts ? implode( ', ', $pts ) : 'any post type' );
            } elseif ( $type === 'taxonomy' ) {
                $tx     = (string) ( $field['taxonomy'] ?? '' );
                $suffix = ' -> ' . ( $tx !== '' ? $tx : 'any taxonomy' );
            }

            $lines[] = $indent . '- ' . $display . $suffix;

            // Recurse into containers.
            if ( in_array( $type, [ 'repeater', 'group' ], true ) && ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
                $lines = array_merge( $lines, $this->acf_render_field_schema( $field['sub_fields'], $depth + 1 ) );
            } elseif ( $type === 'flexible_content' && ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
                foreach ( $field['layouts'] as $layout ) {
                    if ( ! is_array( $layout ) ) {
                        continue;
                    }

                    $lname   = (string) ( $layout['name'] ?? '(layout)' );
                    $lines[] = str_repeat( '  ', $depth + 1 ) . '[layout: ' . $lname . ']';

                    if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
                        $lines = array_merge( $lines, $this->acf_render_field_schema( $layout['sub_fields'], $depth + 2 ) );
                    }
                }
            }
        }

        return $lines;
    }

    /**
     * Count the top-level (non-presentational, non-sensitive) fields in a group
     * for the "Fields (N):" header. Nested sub-fields are shown indented but not
     * counted here.
     */
    private function acf_count_schema_fields( array $fields ): int {
        $skip  = [ 'tab', 'message', 'accordion' ];
        $count = 0;

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            $name = (string) ( $field['name'] ?? '' );
            $type = (string) ( $field['type'] ?? '' );

            if ( $name === '' || in_array( $type, $skip, true ) || $this->is_sensitive_field_name( $name ) ) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Render one attachment entry in the nested overview style:
     *
     *   - {name}
     *   - - {subline}            (optional, e.g. "Used by: service")
     *   - - Related Fields
     *   - - - {field group title}
     *
     * When there are no related field groups, a single "(none)" child is
     * shown.
     */
    private function acf_render_attachment_block( string $name, array $groups, ?string $subline = null ): array {
        $lines   = [];
        $lines[] = '  - ' . $name;

        if ( $subline !== null && $subline !== '' ) {
            $lines[] = '  - - ' . $subline;
        }

        $lines[] = '  - - Related Fields';

        $groups = array_values( array_unique( $groups ) );

        if ( $groups ) {
            foreach ( $groups as $group_title ) {
                $lines[] = '  - - - ' . $group_title;
            }
        } else {
            $lines[] = '  - - - (none)';
        }

        return $lines;
    }

    /**
     * Enumerate the taxonomies ACF itself has registered (via ACF's UI),
     * keyed by taxonomy slug, each with its label and the post types it
     * attaches to. Returns an empty array on ACF versions without the UI
     * taxonomy registry.
     */
    private function acf_registered_taxonomies(): array {
        $out = [];

        if ( ! function_exists( 'acf_get_internal_post_type_posts' ) ) {
            return $out;
        }

        $defs = acf_get_internal_post_type_posts( 'acf-taxonomy' );

        foreach ( (array) $defs as $def ) {
            $slug = (string) ( $def['taxonomy'] ?? '' );

            if ( $slug === '' ) {
                continue;
            }

            $out[ $slug ] = [
                'title'      => (string) ( $def['title'] ?? $slug ),
                'post_types' => ( isset( $def['object_type'] ) && is_array( $def['object_type'] ) ) ? $def['object_type'] : [],
            ];
        }

        return $out;
    }

    /**
     * Map ACF options pages by menu slug to their title and description, for
     * labelling the Options pages section. Returns an empty array on ACF
     * versions without the options-pages registry.
     */
    private function acf_options_page_meta(): array {
        $out = [];

        if ( ! function_exists( 'acf_get_options_pages' ) ) {
            return $out;
        }

        $pages = acf_get_options_pages();

        foreach ( (array) $pages as $slug => $page ) {
            if ( ! is_array( $page ) ) {
                continue;
            }

            $key = (string) ( $page['menu_slug'] ?? $slug );

            $out[ $key ] = [
                'title'       => (string) ( $page['page_title'] ?? ( $page['menu_title'] ?? $key ) ),
                'description' => trim( (string) ( $page['description'] ?? '' ) ),
            ];
        }

        return $out;
    }

    // =========================================================================
    // Virtual file serving (checkpoint 3)
    //
    // The rewrite rule is always registered. When a physical file exists at
    // the site root the web server serves it directly and WordPress never
    // runs, so the rule lies dormant behind it. The serving mode only decides
    // whether regenerate_outputs() writes files to disk (physical) or saves
    // the assembled text to the virtual store option (virtual). The public
    // route reads from that store, falling back to a live assembly only when
    // the store is empty or a physical file has gone missing.
    // =========================================================================

    /**
     * Current serving mode, normalised to 'virtual' or 'physical'.
     */
    private function get_serving_mode(): string {
        $settings = $this->get_settings();
        return ( ( $settings['serving_mode'] ?? 'virtual' ) === 'physical' ) ? 'physical' : 'virtual';
    }

    /**
     * Build all three outputs and persist them for the current serving mode.
     *
     *   - virtual  : save the assembled text to the virtual store option
     *                (autoload off, since these can be large) so the public
     *                routes can echo it without rebuilding on each hit.
     *   - physical : write the three files to the WordPress root, exactly as
     *                earlier versions did.
     *
     * Returns true on success. This replaces the three write_file() calls
     * that every regenerate path used to make directly.
     */
    private function regenerate_outputs(): bool {
        $slim    = $this->build_llms_slim();
        $full    = $this->build_llms_full( false );
        $details = $this->build_llms_full( true );

        if ( $this->get_serving_mode() === 'virtual' ) {
            update_option( 'socialbump_ai_virtual_store', [
                'slim'    => $slim,
                'full'    => $full,
                'details' => $details,
                'time'    => time(),
            ], false );
            return true;
        }

        $slim_ok    = $this->write_file( 'llms.txt',         $slim );
        $full_ok    = $this->write_file( 'llms-full.txt',    $full );
        $details_ok = $this->write_file( 'llms-details.txt', $details );

        return $slim_ok && $full_ok && $details_ok;
    }

    /**
     * Register the three root-level routes. Always added on init; harmless in
     * physical mode because the disk file shadows the route when present.
     */
    /**
     * Restamp the cache once, after the fingerprint was narrowed.
     *
     * Before 1.0.5 the fingerprint folded in settings that never touch the
     * rendered body, so every entry written then carries a value the new
     * fingerprint can never match. Left alone, each site would face a full
     * re-render after updating for no reason. The body markdown is still
     * right, so the stamp is rewritten instead of the content, a row at a
     * time so a large site does not load its whole cache into memory.
     *
     * Runs once, on the first admin load after the update, and records that
     * it has run. An entry rendered under different strip selectors was stale
     * before this and is not after it; that one-off imprecision is accepted.
     */
    public function maybe_restamp_cache(): void {
        if ( (int) get_option( 'sbaike_fingerprint_scheme', 1 ) >= 2 ) {
            return;
        }

        global $wpdb;

        $fingerprint = $this->get_settings_fingerprint();
        $last        = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT 100",
                    self::CACHE_META_KEY,
                    $last
                )
            );

            foreach ( (array) $rows as $row ) {
                $last  = (int) $row->meta_id;
                $cache = maybe_unserialize( $row->meta_value );

                if ( ! is_array( $cache ) || ! isset( $cache['markdown'] ) || ( $cache['fingerprint'] ?? '' ) === $fingerprint ) {
                    continue;
                }

                $cache['fingerprint'] = $fingerprint;

                $wpdb->update( $wpdb->postmeta, [ 'meta_value' => maybe_serialize( $cache ) ], [ 'meta_id' => $last ] );
                wp_cache_delete( (int) $row->post_id, 'post_meta' );
            }
        } while ( count( (array) $rows ) === 100 );

        update_option( 'sbaike_fingerprint_scheme', 2, false );
        $this->flush_global_stale_count();
    }

    public function register_rewrite_rule(): void {
        add_rewrite_rule( '^llms\.txt$',         'index.php?socialbump_ai_llms=slim',    'top' );
        add_rewrite_rule( '^llms-full\.txt$',    'index.php?socialbump_ai_llms=full',    'top' );
        add_rewrite_rule( '^llms-details\.txt$', 'index.php?socialbump_ai_llms=details', 'top' );
    }

    /**
     * Make our routing query var available to WP_Query so the rewrite targets
     * resolve.
     */
    public function add_query_vars( array $vars ): array {
        $vars[] = 'socialbump_ai_llms';
        return $vars;
    }

    /**
     * Flush rewrite rules once per version bump. Snippet managers don't fire
     * activation hooks reliably, so we self-heal: compare the stored version
     * against the current one and flush only when they differ. Runs after
     * register_rewrite_rule() (init priority 20 vs 5) so the new rules are in
     * place when the flush captures them.
     */
    public function maybe_flush_rewrite_rules(): void {
        if ( get_option( 'socialbump_ai_rewrite_version' ) === $this->version ) {
            return;
        }
        flush_rewrite_rules( false );
        update_option( 'socialbump_ai_rewrite_version', $this->version, false );
    }

    /**
     * Intercept a request for one of the three routes and serve it as plain
     * text. Hooked early on template_redirect (priority 1) so it runs and
     * exits before WordPress's canonical redirect or 404 handling.
     */
    public function maybe_serve_virtual_file(): void {
        $which = get_query_var( 'socialbump_ai_llms' );

        if ( $which !== 'slim' && $which !== 'full' && $which !== 'details' ) {
            return;
        }

        $body = '';

        if ( $this->get_serving_mode() === 'virtual' ) {
            $store = get_option( 'socialbump_ai_virtual_store', [] );
            $body  = is_array( $store ) ? (string) ( $store[ $which ] ?? '' ) : '';

            // Store not populated yet (e.g. never generated since switching) -
            // assemble live this once so the route still returns content.
            if ( $body === '' ) {
                $body = $this->assemble_output( $which );
            }
        } else {
            // Physical mode but we still reached WordPress, which means the
            // disk file is missing. Serve a live copy as a fallback rather
            // than 404.
            $body = $this->assemble_output( $which );
        }

        nocache_headers();
        status_header( 200 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );

        echo $body; // Plain text, cleaned during assembly - must not be escaped.
        exit;
    }

    /**
     * Assemble one of the three outputs on demand. Used for the live fallback
     * paths in maybe_serve_virtual_file().
     */
    private function assemble_output( string $which ): string {
        if ( $which === 'slim' ) {
            return $this->build_llms_slim();
        }
        if ( $which === 'details' ) {
            return $this->build_llms_full( true );
        }
        return $this->build_llms_full( false );
    }

    /**
     * Delete the three generated files from the WordPress root via
     * WP_Filesystem (same handling as write_file). Missing files are skipped,
     * not treated as errors. Returns [ 'deleted' => [...], 'failed' => [...] ].
     */
    private function delete_physical_files(): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        global $wp_filesystem;

        if ( ! WP_Filesystem() ) {
            return [ 'deleted' => [], 'failed' => [ 'llms.txt', 'llms-full.txt', 'llms-details.txt' ] ];
        }

        $targets = [ 'llms.txt', 'llms-full.txt', 'llms-details.txt' ];
        $deleted = [];
        $failed  = [];

        foreach ( $targets as $filename ) {
            $path = ABSPATH . $filename;

            if ( ! $wp_filesystem->exists( $path ) ) {
                continue;
            }

            if ( $wp_filesystem->delete( $path ) ) {
                $deleted[] = $filename;
            } else {
                $failed[] = $filename;
            }
        }

        return [ 'deleted' => $deleted, 'failed' => $failed ];
    }

    /**
     * Return the generated files that currently exist on disk in the root.
     * Used to warn when a physical file is shadowing a virtual route.
     */
    private function physical_files_present(): array {
        $present = [];
        foreach ( [ 'llms.txt', 'llms-full.txt', 'llms-details.txt' ] as $filename ) {
            if ( file_exists( ABSPATH . $filename ) ) {
                $present[] = $filename;
            }
        }
        return $present;
    }

    // =========================================================================
    // File writing
    // =========================================================================

    private function write_file( string $filename, string $content ): bool {
        $path = ABSPATH . $filename;

        require_once ABSPATH . 'wp-admin/includes/file.php';

        global $wp_filesystem;

        if ( ! WP_Filesystem() ) {
            return false;
        }

        return (bool) $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
    }
}

$socialbump_aiknowledge_exporter = new SocialBump_AI_Knowledge_Exporter();
SocialBump_AI_Knowledge_Exporter::set_instance( $socialbump_aiknowledge_exporter );
