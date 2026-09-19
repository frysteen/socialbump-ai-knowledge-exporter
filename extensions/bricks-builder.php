<?php
/**
 * SocialBUMP SEO for AI - Bricks Builder Extension
 *
 * Version: 1.0
 * Requires: SocialBUMP SEO for AI core 1.19+
 *
 * Adds Bricks Builder support to the SocialBUMP SEO for AI:
 *   - Detects Bricks-built pages (reads _bricks_page_content_2)
 *   - Does not extract element content: the renderer fetches Bricks pages like
 *     any other. This file only reports detection and template relationships
 *   - Resolves Bricks dynamic tags ({post_title}, {acf_field_name}, etc.)
 *   - Detects which Bricks templates are assigned to which post types and
 *     taxonomies, and surfaces those in the section header lines
 *
 * Install: paste into a snippet manager (WP CodeBox, Fluent Snippets, etc.)
 * alongside the core plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Bail if the core plugin isn't active. The hooks won't exist yet either,
// so registering filters against them would do nothing - explicit return
// is cleaner.
if ( ! class_exists( 'SocialBump_AI_Knowledge_Exporter' ) ) {
    return;
}

// Defer registration to plugins_loaded so we don't depend on snippet
// load order. By the time plugins_loaded fires, core's class has been
// fully evaluated regardless of which snippet ran first.
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'SocialBump_AI_Knowledge_Exporter' ) ) {
        return;
    }
    $core = SocialBump_AI_Knowledge_Exporter::instance();
    if ( ! method_exists( $core, 'register_extension' ) ) {
        return;
    }
    $core->register_extension( 'bricks', [
        'name'    => 'Bricks',
        'category' => 'builder',
        'description' => 'Extracts Bricks element content, resolves dynamic data and records Bricks template relationships in AIKE exports.',
        'version' => '1.0',
        'detects' => function () {
            return defined( 'BRICKS_VERSION' ) || function_exists( 'bricks_is_builder' );
        },
    ] );
}, 20 );


/* =============================================================================
 * Public API: parse, format, cache
 * ===========================================================================*/

/**
 * Returns the parsed Bricks element array for a post, or empty array if the
 * post has no Bricks content.
 *
 * Bricks stores _bricks_page_content_2 either as a JSON string (older versions
 * / some setups) or as serialised PHP (newer versions / most setups).
 * maybe_unserialize() is a no-op on non-serialised data, so we try unserialize
 * first, and only fall back to json_decode when the value comes back unchanged
 * as a string.
 */
function socialbump_bricks_get_elements( int $post_id ): array {
    $raw = get_post_meta( $post_id, '_bricks_page_content_2', true );

    if ( empty( $raw ) ) {
        return [];
    }

    if ( is_array( $raw ) ) {
        return $raw;
    }

    if ( ! is_string( $raw ) ) {
        return [];
    }

    // Try serialised PHP first.
    $maybe = maybe_unserialize( $raw );
    if ( is_array( $maybe ) ) {
        return $maybe;
    }

    // Fall back to JSON.
    $decoded = json_decode( $raw, true );
    if ( is_array( $decoded ) ) {
        return $decoded;
    }

    return [];
}

/**
 * Whether Bricks itself is active on this site. Used to keep every callback
 * dormant when Bricks isn't installed (the add-on may still be pasted in).
 */
function socialbump_bricks_is_active(): bool {
    return defined( 'BRICKS_VERSION' ) || function_exists( 'bricks_is_builder' );
}

/**
 * Detect whether a post is built with Bricks. Single source of truth used by
 * all filter callbacks below.
 */
function socialbump_bricks_post_is_bricks_built( WP_Post $post ): bool {
    if ( ! socialbump_bricks_is_active() ) {
        return false;
    }

    return socialbump_bricks_get_elements( $post->ID ) !== [];
}

/**
 * Whether Bricks is enabled for editing a given post type. Bricks stores the
 * list of builder-enabled post types in the 'postTypes' key of its
 * bricks_global_settings option (slugs). When the key is absent, Bricks
 * defaults to page and post.
 *
 * This lets us tell apart an editor-less post type that Bricks CAN build (so
 * its content surface is just empty) from one that has no content surface at
 * all.
 */
function socialbump_bricks_is_enabled_for_post_type( string $post_type ): bool {
    if ( ! socialbump_bricks_is_active() ) {
        return false;
    }

    $settings = get_option( 'bricks_global_settings' );

    if ( is_array( $settings ) && ! empty( $settings['postTypes'] ) && is_array( $settings['postTypes'] ) ) {
        return in_array( $post_type, $settings['postTypes'], true );
    }

    return in_array( $post_type, [ 'page', 'post' ], true );
}

/**
 * Build (lazily, once per export run) a map of which Bricks templates apply
 * to which post types and taxonomies. Used in the per-post-type and
 * per-taxonomy section headers in the full file.
 *
 * Template conditions live as the 'templateConditions' array inside the
 * _bricks_template_settings postmeta. Each condition has a 'main' key that
 * names the condition type:
 *
 *   'postType'    - applies to single posts of named post type(s).
 *                   Companion key 'postType' is the array of slugs.
 *
 *   'archiveType' - applies to one or more archives. Companion key
 *                   'archiveType' is an array containing some subset of
 *                   ['postType', 'any', 'term']. When 'postType' is present,
 *                   companion key 'archivePostTypes' lists the post types
 *                   whose archives it covers. When 'term' is present,
 *                   'archiveTerms' lists taxonomy archives in the form
 *                   'taxonomy::all' or 'taxonomy::term_id'.
 *
 *   'ids'         - applies to specific post IDs. Skipped here - only applies
 *                   to one specific page, not a whole post type or taxonomy.
 *
 * In v1 we ignore:
 *   - 'ids' conditions
 *   - 'any' in archiveType (means "all archives globally" - too broad)
 *
 * Specific-term archives ('taxonomy::term_id') are now tracked under
 * archive_by_term so individual term blocks can show whether they have a
 * dedicated template versus inheriting from the taxonomy-wide one.
 *
 * Cached in a static so file generation only walks the bricks_template posts
 * once per export run.
 */
function socialbump_bricks_get_template_map(): array {
    static $cache = null;

    if ( $cache !== null ) {
        return $cache;
    }

    $map = [
        'singular_by_post_type' => [],
        'archive_by_post_type'  => [],
        'archive_by_taxonomy'   => [],
        // Map of "taxonomy::term_id" => entry for templates that target a
        // specific term rather than the whole taxonomy archive. Bricks
        // supports this and authors often forget which terms have a
        // custom template versus inheriting the taxonomy-wide one.
        'archive_by_term'       => [],
    ];

    if ( ! post_type_exists( 'bricks_template' ) ) {
        $cache = $map;
        return $map;
    }

    $templates = get_posts( [
        'post_type'      => 'bricks_template',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    if ( ! $templates ) {
        $cache = $map;
        return $map;
    }

    foreach ( $templates as $template ) {
        // Only renderable template types can be assigned to post types or
        // taxonomies. Bricks calls a "Single" template `content` internally,
        // not `single`. Sections / popups / headers / footers never appear
        // in templateConditions in a meaningful way.
        $template_type = (string) get_post_meta( $template->ID, '_bricks_template_type', true );

        if ( ! in_array( $template_type, [ 'content', 'archive', 'search', 'error' ], true ) ) {
            continue;
        }

        $settings   = get_post_meta( $template->ID, '_bricks_template_settings', true );
        $settings   = is_string( $settings ) ? maybe_unserialize( $settings ) : $settings;
        $conditions = is_array( $settings ) && isset( $settings['templateConditions'] ) && is_array( $settings['templateConditions'] )
            ? $settings['templateConditions']
            : [];

        if ( ! $conditions ) {
            continue;
        }

        $entry = [
            'id'   => (int) $template->ID,
            'name' => html_entity_decode( get_the_title( $template ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
        ];

        foreach ( $conditions as $condition ) {
            if ( ! is_array( $condition ) || empty( $condition['main'] ) ) {
                continue;
            }

            $main = $condition['main'];

            // Singular content templates.
            if ( $main === 'postType' ) {
                $post_types = isset( $condition['postType'] ) && is_array( $condition['postType'] )
                    ? $condition['postType']
                    : [];

                foreach ( $post_types as $post_type ) {
                    if ( ! isset( $map['singular_by_post_type'][ $post_type ] ) ) {
                        $map['singular_by_post_type'][ $post_type ] = $entry;
                    }
                }
                continue;
            }

            // Archive templates - post type archives and/or taxonomy archives.
            if ( $main === 'archiveType' ) {
                $archive_types = isset( $condition['archiveType'] ) && is_array( $condition['archiveType'] )
                    ? $condition['archiveType']
                    : [];

                if ( in_array( 'postType', $archive_types, true ) ) {
                    $archive_post_types = isset( $condition['archivePostTypes'] ) && is_array( $condition['archivePostTypes'] )
                        ? $condition['archivePostTypes']
                        : [];

                    foreach ( $archive_post_types as $post_type ) {
                        if ( ! isset( $map['archive_by_post_type'][ $post_type ] ) ) {
                            $map['archive_by_post_type'][ $post_type ] = $entry;
                        }
                    }
                }

                if ( in_array( 'term', $archive_types, true ) ) {
                    $archive_terms = isset( $condition['archiveTerms'] ) && is_array( $condition['archiveTerms'] )
                        ? $condition['archiveTerms']
                        : [];

                    foreach ( $archive_terms as $term_entry ) {
                        if ( ! is_string( $term_entry ) ) {
                            continue;
                        }

                        // 'taxonomy::all' = template covers every term in
                        // this taxonomy. Store under archive_by_taxonomy.
                        if ( substr( $term_entry, -5 ) === '::all' ) {
                            $taxonomy = substr( $term_entry, 0, -5 );

                            if ( $taxonomy === '' ) {
                                continue;
                            }

                            if ( ! isset( $map['archive_by_taxonomy'][ $taxonomy ] ) ) {
                                $map['archive_by_taxonomy'][ $taxonomy ] = $entry;
                            }
                            continue;
                        }

                        // 'taxonomy::123' = template targets one specific
                        // term. Store under archive_by_term keyed by the
                        // full "taxonomy::term_id" string so lookup is
                        // O(1) when rendering each term.
                        if ( strpos( $term_entry, '::' ) !== false && ! isset( $map['archive_by_term'][ $term_entry ] ) ) {
                            $map['archive_by_term'][ $term_entry ] = $entry;
                        }
                    }
                }
            }
        }
    }

    $cache = $map;
    return $map;
}

/**
 * Format a template-map entry as a single output line. Returns empty string
 * when no template was passed.
 */
function socialbump_bricks_format_template_line( ?array $entry ): string {
    if ( ! $entry || empty( $entry['id'] ) ) {
        return '';
    }

    $name = ! empty( $entry['name'] ) ? $entry['name'] : '(untitled)';
    return 'Bricks - ' . $name . ' [bricks_template id="' . (int) $entry['id'] . '"]';
}



/* =============================================================================
 * Hook 0: A saved template marks the posts drawn through it as stale
 *
 * A post's cached render comes from its template as much as from its own
 * content, but only its own modified date was being watched, so editing
 * Single - Service left every treatment looking current while the export was
 * out of date. Bricks calls wp_update_post when a template is saved in the
 * builder, and writes the template's content and conditions to two meta keys,
 * so all three are watched and the first one to fire in a request wins.
 *
 * Only conditions that name post types or particular posts are mapped. A
 * header, footer, popup or section template cannot be tied to specific posts
 * from its conditions, and headers and footers are stripped anyway, so those
 * are left alone: after editing one of those, use Full Rebuild.
 * ===========================================================================*/

function socialbump_bricks_template_touched( $template_id ): void {
    static $done = [];

    $template_id = (int) $template_id;

    if ( ! $template_id || isset( $done[ $template_id ] ) || get_post_type( $template_id ) !== 'bricks_template' ) {
        return;
    }

    if ( wp_is_post_revision( $template_id ) || wp_is_post_autosave( $template_id ) ) {
        return;
    }

    $done[ $template_id ] = true;

    if ( ! class_exists( 'SocialBump_AI_Knowledge_Exporter' ) ) {
        return;
    }

    $core = SocialBump_AI_Knowledge_Exporter::instance();

    if ( ! method_exists( $core, 'touch_post_type' ) ) {
        return;
    }

    $settings   = get_post_meta( $template_id, '_bricks_template_settings', true );
    $settings   = is_string( $settings ) ? maybe_unserialize( $settings ) : $settings;
    $conditions = is_array( $settings ) && isset( $settings['templateConditions'] ) && is_array( $settings['templateConditions'] )
        ? $settings['templateConditions']
        : [];

    foreach ( $conditions as $condition ) {
        if ( ! is_array( $condition ) || empty( $condition['main'] ) ) {
            continue;
        }

        if ( $condition['main'] === 'postType' && ! empty( $condition['postType'] ) && is_array( $condition['postType'] ) ) {
            foreach ( $condition['postType'] as $post_type ) {
                $core->touch_post_type( (string) $post_type );
            }
        }

        if ( $condition['main'] === 'ids' && ! empty( $condition['ids'] ) && is_array( $condition['ids'] ) ) {
            $core->touch_posts( array_map( 'intval', $condition['ids'] ) );
        }
    }
}

add_action( 'save_post_bricks_template', 'socialbump_bricks_template_touched', 20 );

add_action(
    'updated_post_meta',
    function ( $meta_id, $object_id, $meta_key ) {
        if ( $meta_key === '_bricks_page_content_2' || $meta_key === '_bricks_template_settings' ) {
            socialbump_bricks_template_touched( $object_id );
        }
    },
    10,
    3
);

add_action(
    'added_post_meta',
    function ( $meta_id, $object_id, $meta_key ) {
        if ( $meta_key === '_bricks_page_content_2' || $meta_key === '_bricks_template_settings' ) {
            socialbump_bricks_template_touched( $object_id );
        }
    },
    10,
    3
);


/* =============================================================================
 * Hook 1: Per-post meta line - declare "Content Builder: Bricks Builder"
 * ===========================================================================*/

/**
 * Override or insert the "Content Builder:" line in the per-post meta block.
 *
 * Architecture metadata like Content Builder is only useful for developers
 * or LLMs analysing the site's build, so we only emit it when the core is
 * generating the architecture-details file. In the public-facing full
 * file the line is suppressed entirely (matching how core's default
 * "Content Builder: Empty" line is also details-only).
 */
add_filter(
    'socialbump_aiknowledge_post_meta_lines',
    function ( array $lines, WP_Post $post, string $post_type, bool $details_mode = false ): array {
        if ( ! $details_mode ) {
            // Public file: also strip any "Content Builder:" line core
            // might have left behind so we don't double up on cleanup.
            foreach ( $lines as $i => $line ) {
                if ( strpos( $line, 'Content Builder:' ) === 0 ) {
                    unset( $lines[ $i ] );
                }
            }
            return array_values( $lines );
        }

        // Locate any existing "Content Builder:" line core left behind.
        $idx     = null;
        $current = '';
        foreach ( $lines as $i => $line ) {
            if ( strpos( $line, 'Content Builder:' ) === 0 ) {
                $idx     = $i;
                $current = $line;
                break;
            }
        }

        // The post actually has Bricks content - always wins.
        if ( socialbump_bricks_post_is_bricks_built( $post ) ) {
            if ( $idx !== null ) {
                $lines[ $idx ] = 'Content Builder: Bricks Builder';
            } else {
                $lines[] = 'Content Builder: Bricks Builder';
            }

            return $lines;
        }

        // No Bricks content. If core marked this "Not applicable (no content
        // editor)" but Bricks IS enabled for the post type, the post does have
        // a (Bricks) editing surface that simply hasn't been used yet - so it's
        // "Empty", not "Not applicable". Leave Gutenberg / Classic / genuine
        // Empty lines untouched.
        if (
            $idx !== null
            && strpos( $current, 'Not applicable' ) !== false
            && socialbump_bricks_is_enabled_for_post_type( $post->post_type )
        ) {
            $lines[ $idx ] = 'Content Builder: Empty';
        }

        return $lines;
    },
    10,
    4
);


/* =============================================================================
 * Hook 2: Available page builders - declare Bricks as an editing surface
 * ===========================================================================*/

add_filter(
    'socialbump_aiknowledge_available_page_builders',
    function ( array $builders, string $post_type ): array {
        if ( socialbump_bricks_is_enabled_for_post_type( $post_type ) ) {
            $builders[] = 'Bricks Builder';
        }

        return $builders;
    },
    10,
    2
);


/* =============================================================================
 * Hook 3: Post type section header - add Template / Archive Template lines
 * ===========================================================================*/

add_filter(
    'socialbump_aiknowledge_post_type_section_lines',
    function ( array $header_lines, string $post_type, array $items, bool $details_mode = false ): array {
        // Template assignments are architecture metadata - details file only.
        if ( ! $details_mode ) {
            return $header_lines;
        }

        $map = socialbump_bricks_get_template_map();

        // Singular content template.
        $singular_entry = $map['singular_by_post_type'][ $post_type ] ?? null;
        $singular_line  = socialbump_bricks_format_template_line( $singular_entry );

        if ( $singular_line !== '' ) {
            $header_lines[] = 'Template: ' . $singular_line;
        }

        // Archive template - only if the post type has an archive URL.
        $pto = get_post_type_object( $post_type );

        if ( $pto && ! empty( $pto->has_archive ) ) {
            $archive_entry = $map['archive_by_post_type'][ $post_type ] ?? null;
            $archive_line  = socialbump_bricks_format_template_line( $archive_entry );

            if ( $archive_line !== '' ) {
                $header_lines[] = 'Archive Template: ' . $archive_line;
            }
        }

        return $header_lines;
    },
    10,
    4
);


/* =============================================================================
 * Hook 4: Taxonomy section header - add Archive Template line
 * ===========================================================================*/

add_filter(
    'socialbump_aiknowledge_taxonomy_section_lines',
    function ( array $header_lines, string $tax_slug, $tax_obj, bool $details_mode = false ): array {
        // Archive template assignment is architecture metadata - details only.
        if ( ! $details_mode ) {
            return $header_lines;
        }

        $map           = socialbump_bricks_get_template_map();
        $archive_entry = $map['archive_by_taxonomy'][ $tax_slug ] ?? null;
        $archive_line  = socialbump_bricks_format_template_line( $archive_entry );

        // Always emit the line - knowing a taxonomy has no custom archive
        // template is just as useful as knowing it does, because it tells
        // an LLM (or a developer auditing the site) that the taxonomy is
        // using the theme's default category template rather than a
        // designed Bricks layout.
        $header_lines[] = 'Archive Template: ' . ( $archive_line !== '' ? $archive_line : 'None (theme default)' );

        return $header_lines;
    },
    10,
    4
);


/* =============================================================================
 * Hook 5: Per-term archive template - annotate each individual term with
 * whether Bricks has a dedicated template targeting just that term.
 *
 * Bricks lets you create one archive template that covers an entire
 * taxonomy AND override it for specific terms. The override is silent in
 * the WP admin - you have to dig into the template settings to see it,
 * which makes it a common source of "why does this one category look
 * different" confusion. Reporting it explicitly per term surfaces the
 * override.
 * ===========================================================================*/

add_filter(
    'socialbump_aiknowledge_term_lines',
    function ( array $term_lines, $term, string $tax_slug, bool $details_mode = false ): array {
        // Per-term template override is architecture metadata - details only.
        if ( ! $details_mode ) {
            return $term_lines;
        }

        if ( ! is_object( $term ) || empty( $term->term_id ) ) {
            return $term_lines;
        }

        $map = socialbump_bricks_get_template_map();
        $key = $tax_slug . '::' . $term->term_id;

        // Only emit the line when there's a term-specific override. The
        // taxonomy header already states the default - repeating "uses
        // the taxonomy default" on every term would just be noise.
        if ( ! isset( $map['archive_by_term'][ $key ] ) ) {
            return $term_lines;
        }

        $line = socialbump_bricks_format_template_line( $map['archive_by_term'][ $key ] );

        if ( $line !== '' ) {
            $term_lines[] = '  Term Archive Template: ' . $line;
        }

        return $term_lines;
    },
    10,
    4
);
