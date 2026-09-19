<?php
/**
 * SocialBUMP SEO for AI - Elementor Extension
 *
 * Version: 1.0
 * Requires: SocialBUMP SEO for AI core 1.66+
 *
 * Adds Elementor support to the SocialBUMP SEO for AI:
 *   - Detects Elementor-built pages (reads _elementor_edit_mode / _elementor_data)
 *     and labels them "Content Builder: Elementor" in the details file.
 *   - Corrects the "Not applicable" line to "Empty" for editor-less post types
 *     that Elementor is enabled to edit but which have not been built yet.
 *
 * This mirrors the Bricks add-on. Core itself is builder-agnostic and carries
 * no Elementor logic; all of it lives here.
 *
 * Not yet implemented (planned for when an Elementor site is connected for
 * testing): mapping Elementor Theme Builder templates (elementor_library
 * posts with display conditions) to the post types and taxonomies they
 * render, the way the Bricks add-on surfaces "Template:" / "Archive Template:"
 * lines in the section headers.
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
    $core->register_extension( 'elementor', [
        'name'    => 'Elementor',
        'category' => 'builder',
        'description' => 'Adds Elementor-rendered content support when Elementor is installed and active.',
        'version' => '1.0',
        'detects' => function () {
            return defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' );
        },
    ] );
}, 20 );


/* =============================================================================
 * Detection helpers
 * ===========================================================================*/

/**
 * Whether Elementor itself is active on this site. Used to keep every callback
 * dormant when Elementor isn't installed (the add-on may still be pasted in).
 */
function socialbump_elementor_is_active(): bool {
    return defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' );
}

/**
 * Detect whether a post is built with Elementor. Elementor sets
 * _elementor_edit_mode to "builder" when the page is opened in the editor, and
 * stores the layout tree in _elementor_data. Either is a reliable signal.
 */
function socialbump_elementor_post_is_elementor_built( WP_Post $post ): bool {
    if ( ! socialbump_elementor_is_active() ) {
        return false;
    }

    if ( get_post_meta( $post->ID, '_elementor_edit_mode', true ) === 'builder' ) {
        return true;
    }

    $data = get_post_meta( $post->ID, '_elementor_data', true );

    // _elementor_data is stored as a JSON string; an unbuilt page has either
    // no value or an empty array literal.
    return ! empty( $data ) && $data !== '[]';
}

/**
 * Whether Elementor is enabled for editing a given post type. Elementor stores
 * the builder-enabled post types in the 'elementor_cpt_support' option
 * (an array of slugs). When the option is absent, Elementor defaults to page
 * and post.
 *
 * Returns false outright when Elementor isn't active, so the add-on never
 * claims "Elementor" as an available builder on a site that doesn't run it.
 */
function socialbump_elementor_is_enabled_for_post_type( string $post_type ): bool {
    if ( ! socialbump_elementor_is_active() ) {
        return false;
    }

    $supported = get_option( 'elementor_cpt_support' );

    if ( is_array( $supported ) && $supported ) {
        return in_array( $post_type, $supported, true );
    }

    return in_array( $post_type, [ 'page', 'post' ], true );
}


/* =============================================================================
 * Hook 1: Per-post meta line - declare "Content Builder: Elementor"
 *
 * Mirrors the Bricks add-on. Content Builder is architecture metadata, so it is
 * only emitted in the details file; the public file strips the line entirely.
 * ===========================================================================*/

add_filter(
    'socialbump_aiknowledge_post_meta_lines',
    function ( array $lines, WP_Post $post, string $post_type, bool $details_mode = false ): array {
        if ( ! $details_mode ) {
            // Public file: strip any "Content Builder:" line so it never
            // surfaces there.
            foreach ( $lines as $i => $line ) {
                if ( strpos( $line, 'Content Builder:' ) === 0 ) {
                    unset( $lines[ $i ] );
                }
            }
            return array_values( $lines );
        }

        // Locate any existing "Content Builder:" line.
        $idx     = null;
        $current = '';
        foreach ( $lines as $i => $line ) {
            if ( strpos( $line, 'Content Builder:' ) === 0 ) {
                $idx     = $i;
                $current = $line;
                break;
            }
        }

        // The post actually has Elementor content - always wins.
        if ( socialbump_elementor_post_is_elementor_built( $post ) ) {
            if ( $idx !== null ) {
                $lines[ $idx ] = 'Content Builder: Elementor';
            } else {
                $lines[] = 'Content Builder: Elementor';
            }

            return $lines;
        }

        // No Elementor content. If core marked this "Not applicable (no content
        // editor)" but Elementor IS enabled for the post type, the post has an
        // (Elementor) editing surface that simply hasn't been used yet - so
        // it's "Empty", not "Not applicable". Leave Gutenberg / Classic /
        // genuine Empty lines untouched.
        if (
            $idx !== null
            && strpos( $current, 'Not applicable' ) !== false
            && socialbump_elementor_is_enabled_for_post_type( $post->post_type )
        ) {
            $lines[ $idx ] = 'Content Builder: Empty';
        }

        return $lines;
    },
    10,
    4
);


/* =============================================================================
 * Hook 2: Available page builders - declare Elementor as an editing surface
 * ===========================================================================*/

add_filter(
    'socialbump_aiknowledge_available_page_builders',
    function ( array $builders, string $post_type ): array {
        if ( socialbump_elementor_is_enabled_for_post_type( $post_type ) ) {
            $builders[] = 'Elementor';
        }

        return $builders;
    },
    10,
    2
);
