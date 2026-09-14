<?php
/**
 * SocialBUMP SEO for AI - Rendered Content Renderer
 * Version: 1.0
 *
 * Hooks the socialbump_aiknowledge_post_content_markdown filter to provide
 * builder-independent body content for posts. Fetches each post's permalink
 * via HTTP, strips chrome (header/footer/nav/etc.), and converts the
 * remaining HTML to markdown.
 *
 * Active by default once loaded. Other extensions (Bricks, Elementor, etc.)
 * can still override by hooking the same filter with a higher priority
 * (lower number) than 10.
 *
 * Note for Fluent Snippets users: Fluent Snippets adds its own <?php
 * wrapper, so paste this file WITHOUT the leading <?php line.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// This extension depends on core. Without core, our filter target
// (socialbump_aiknowledge_post_content_markdown) never fires anyway, so
// registering it would be harmless but pointless. Explicit return keeps
// the snippet inert when core isn't loaded.
if ( ! class_exists( 'SocialBump_AI_Knowledge_Exporter' ) ) {
    return;
}

// Defer registration to plugins_loaded so we don't depend on snippet
// load order.
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'SocialBump_AI_Knowledge_Exporter' ) ) {
        return;
    }
    $core = SocialBump_AI_Knowledge_Exporter::instance();
    if ( ! method_exists( $core, 'register_extension' ) ) {
        return;
    }
    $core->register_extension( 'renderer', [
        'name'    => 'Renderer',
        'category' => 'core',
        'description' => 'Turns rendered WordPress output into clean AI-readable Markdown and provides the shared renderer used by builder integrations.',
        'version' => '1.0',
    ] );
}, 20 );

/**
 * Lets the exporter fetch a page that visitors cannot see.
 *
 * The renderer reads each page over HTTP as an anonymous visitor, so a site in
 * coming soon or maintenance mode hands it the holding page instead of the real
 * content. The fetch carries a short lived token, and a request holding a valid
 * token is let straight through.
 *
 * The token is random, lives for five minutes and only ever allows a page to be
 * rendered. It grants nothing else.
 */
class SocialBump_AI_Knowledge_Render_Request {

	const ARG    = 'sbaike_render';
	const PREFIX = 'sbaike_render_';

	private static $token = null;

	/** One token per request, reused for every page in the same build. */
	public static function token() {
		if ( self::$token === null ) {
			self::$token = wp_generate_password( 32, false, false );
			set_transient( self::PREFIX . self::$token, 1, 5 * MINUTE_IN_SECONDS );
		}

		return self::$token;
	}

	/** Add the token to a URL the exporter is about to fetch. */
	public static function sign( $url ) {
		return add_query_arg( self::ARG, self::token(), $url );
	}

	/** Is this request the exporter reading a page? */
	public static function is_render_request() {
		static $answer = null;

		if ( $answer !== null ) {
			return $answer;
		}

		$answer = false;

		if ( ! empty( $_GET[ self::ARG ] ) ) {
			$given = sanitize_text_field( wp_unslash( $_GET[ self::ARG ] ) );

			if ( $given !== '' && get_transient( self::PREFIX . $given ) ) {
				$answer = true;
			}
		}

		return $answer;
	}

	/**
	 * Stand the holding page down for this one request.
	 *
	 * Bricks offers a filter for it. Anything else can hook the action, so a
	 * site using a different coming soon plugin can be handled without touching
	 * this file.
	 */
	public static function allow() {
		if ( ! self::is_render_request() ) {
			return;
		}

		add_filter( 'bricks/maintenance/should_apply', '__return_false', 99 );

		do_action( 'socialbump_aiknowledge_render_request' );
	}
}

add_action( 'plugins_loaded', [ 'SocialBump_AI_Knowledge_Render_Request', 'allow' ], 1 );
class SocialBump_AI_Knowledge_Renderer {

    private static ?self $instance = null;

    /**
     * The default set of CSS selectors used to strip site chrome and
     * non-content elements before markdown conversion.
     *
     * This is now only a SEED. The active list lives in the plugin
     * setting `renderer_strip_selectors` and is fully user-editable -
     * including removing any of these defaults. Core seeds this list on
     * first run and offers a "Restore Defaults" action that merges it
     * back in. The renderer reads the saved setting at strip time, not
     * this constant directly (see strip_chrome / instance fallback).
     *
     * Exposed publicly so core can read it for seeding and the
     * Restore-Defaults UI without duplicating the list.
     */
    public const DEFAULT_STRIP_SELECTORS = [
        'header',
        'footer',
        'nav',
        'aside',
        'script',
        'style',
        'noscript',
        '.skip-link',
        '.screen-reader-text',
        '#wpadminbar',
        // Pagination on archive/blog index pages. Bricks and other
        // builders sometimes emit these outside <nav>, so cover by class
        // too. Without this they leak as "- 1\n- [2](...)\n- [3](...)\n..."
        // lines and look like content.
        '.pagination',
        '.page-numbers',
        '.nav-links',
        // "Read more..." labels emitted by card/loop templates. They're
        // navigation, not content, and tend to get glued onto the end of
        // the previous paragraph during markdown conversion.
        '.read-more',
    ];

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Priority 99 = run last, after any content-providing extensions
        // (Bricks, Elementor, etc.). The render_post() callback short-
        // circuits if $content is already non-empty, so this is a safe
        // fallback that won't waste an HTTP fetch when an upstream
        // extension has already provided content.
        add_filter( 'socialbump_aiknowledge_post_content_markdown', [ $this, 'render_post' ], 99, 2 );
    }

    /**
     * Filter callback. Returns markdown for the given post, or the
     * pre-existing $content if rendering fails.
     */
    public function render_post( string $content, WP_Post $post ): string {
        // Allow higher-priority filters (lower number) to override first.
        // Only do work if no upstream filter has produced content yet.
        if ( $content !== '' ) {
            return $content;
        }

        $html = $this->fetch_rendered_html( $post );

        if ( $html === '' ) {
            return $content;
        }

        $stripped = $this->strip_chrome( $html );

        if ( $stripped === '' ) {
            return $content;
        }

        $markdown = $this->convert_to_markdown( $stripped );

        // Post-process: bare image-URL lines. Builders like Bricks emit
        // background images via inline <img> wrappers that, after chrome
        // stripping, end up as standalone URL lines in the markdown. An
        // LLM can't infer anything from a bare URL ending in .jpg/.png
        // so they're just noise - drop them entirely. Keep image markdown
        // (![alt](url)) which carries semantic alt text.
        $markdown = $this->strip_bare_image_urls( $markdown );

        return $markdown;
    }

    /**
     * Remove standalone lines that consist of nothing but an image URL.
     *
     * Catches lines like:
     *   https://example.com/wp-content/uploads/photo.jpg
     *
     * Preserves:
     *   ![alt text](https://...photo.jpg)   - markdown image with alt
     *   See https://...photo.jpg for more   - URL inside prose
     *   [Caption](https://...photo.jpg)     - image as link target
     */
    private function strip_bare_image_urls( string $markdown ): string {
        $lines  = explode( "\n", $markdown );
        $output = [];

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );

            // Match a line that is just a URL ending in a common image
            // extension (with an optional query string). Whole-line match
            // only, so URLs embedded in prose are untouched.
            if ( preg_match( '#^https?://\S+\.(?:jpe?g|png|gif|webp|svg|avif)(?:\?\S*)?$#i', $trimmed ) ) {
                continue;
            }

            $output[] = $line;
        }

        // Collapse any 3+ runs of blank lines created by removals.
        $result = implode( "\n", $output );
        $result = preg_replace( "/\n{3,}/", "\n\n", $result );

        return $result;
    }

    /**
     * Fetch the post's permalink via wp_remote_get. Returns the HTML body
     * on success, or empty string on failure / non-200 response.
     */
    private function fetch_rendered_html( WP_Post $post ): string {
        $url = get_permalink( $post );

        if ( ! $url ) {
            return '';
        }

        // Signed, so a site in coming soon mode serves the real page.
        $response = wp_remote_get( SocialBump_AI_Knowledge_Render_Request::sign( $url ), [
            'timeout'     => 30,
            'redirection' => 3,
            'sslverify'   => apply_filters( 'socialbump_aiknowledge_renderer_sslverify', true ),
            'headers'     => [
                'User-Agent' => 'SocialBumpAIKnowledgeExporter/1.0',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return '';
        }

        $body = wp_remote_retrieve_body( $response );

        return is_string( $body ) ? $body : '';
    }

    /**
     * Strip chrome selectors from a rendered HTML document and return the
     * <body> contents. Falls back to the full document if no <body> is
     * found.
     */
    private function strip_chrome( string $html ): string {
        $previous_use = libxml_use_internal_errors( true );

        $doc = new DOMDocument();
        // Force UTF-8 interpretation by prepending a meta tag.
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_use );

        $xpath = new DOMXPath( $doc );

        // The active strip list is now fully user-editable and lives in
        // the plugin setting. We read it via core's getter. If core has
        // never initialised the setting (fresh install before first save)
        // we fall back to the default seed so a brand-new site still gets
        // sensible chrome stripping out of the box.
        $selectors = self::DEFAULT_STRIP_SELECTORS;

        if ( class_exists( 'SocialBump_AI_Knowledge_Exporter' ) ) {
            $core = SocialBump_AI_Knowledge_Exporter::instance();
            if ( method_exists( $core, 'get_user_strip_selectors' ) ) {
                $user_selectors = $core->get_user_strip_selectors();

                // get_user_strip_selectors() returns null when the setting
                // has never been initialised, and an array (possibly empty)
                // once the user has saved at least once. An empty array is
                // a deliberate "strip nothing" choice and must be honoured.
                if ( is_array( $user_selectors ) ) {
                    $selectors = $user_selectors;
                }
            }
        }

        // Remove each strip selector. We convert CSS selectors to XPath
        // queries for the common cases: tag, .class, #id, and descendant
        // chains of those.
        foreach ( $selectors as $selector ) {
            $xpath_query = $this->css_to_xpath( $selector );

            if ( $xpath_query === '' ) {
                continue;
            }

            $nodes = $xpath->query( $xpath_query );

            if ( ! $nodes ) {
                continue;
            }

            // Iterate in reverse so removals don't invalidate later indexes.
            for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
                $node = $nodes->item( $i );
                if ( $node && $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }

        // Extract <body> contents if present.
        $body_nodes = $doc->getElementsByTagName( 'body' );

        if ( $body_nodes->length > 0 ) {
            $body = $body_nodes->item( 0 );
            $inner = '';
            foreach ( $body->childNodes as $child ) {
                $inner .= $doc->saveHTML( $child );
            }
            return trim( $inner );
        }

        // No body found - return everything we have.
        return trim( $doc->saveHTML() ?: '' );
    }

    /**
     * Convert a CSS selector to an XPath query. Single selectors and
     * descendant chains are supported.
     *
     * Single-segment forms:
     *   tag        →  //tag
     *   .class     →  //*[contains(concat(' ', normalize-space(@class), ' '), ' class ')]
     *   #id        →  //*[@id='id']
     *
     * Descendant chains use whitespace as a separator:
     *   #faq .heading  →  //*[@id='faq']//*[contains-class('heading')]
     *
     * Comma-separated selector lists, child combinators (>), attribute
     * selectors, and pseudo-classes are not supported. Each entry in the
     * "Strip Selectors" textarea should be a single descendant chain.
     */
    private function css_to_xpath( string $selector ): string {
        $selector = trim( $selector );

        if ( $selector === '' ) {
            return '';
        }

        // Split on any whitespace to build a descendant chain.
        $segments = preg_split( '/\s+/', $selector );

        if ( ! $segments ) {
            return '';
        }

        $xpath = '';

        foreach ( $segments as $i => $segment ) {
            $part = $this->segment_to_xpath( $segment );

            if ( $part === '' ) {
                // If any segment is invalid, abandon the whole selector.
                return '';
            }

            // Each segment is a descendant of the previous. For the very
            // first segment we use //$part (find anywhere in document).
            // Subsequent segments switch from $part's leading "//" to
            // an explicit descendant axis appended after the prior chain.
            // Stripping the leading // and prepending // is equivalent.
            $xpath .= $part;
        }

        return $xpath;
    }

    /**
     * Convert a single CSS selector segment (no whitespace) to its XPath
     * fragment, including the leading `//` descendant axis. Returns empty
     * string for unsupported / invalid input.
     *
     * Supports:
     *   tag             → //tag
     *   .class          → //*[contains-class(class)]
     *   #id             → //*[@id='id'] (case-insensitive)
     *   tag.class       → //tag[contains-class(class)]
     *   tag#id          → //tag[@id='id']
     *   tag.class.other → //tag[contains-class(class) and contains-class(other)]
     *
     * Tokens are matched in order: an optional leading tag name, then any
     * number of .class or #id qualifiers. Returns '' if no valid tokens.
     */
    private function segment_to_xpath( string $segment ): string {
        $segment = trim( $segment );

        if ( $segment === '' ) {
            return '';
        }

        // Split the segment into ordered tokens: an optional leading tag
        // (no prefix), followed by .class or #id qualifiers.
        $tokens = [];
        if ( preg_match_all( '/^([a-z][a-z0-9_-]*)|([.#])([a-zA-Z0-9_-]+)/', $segment, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                if ( ! empty( $m[1] ) ) {
                    $tokens[] = [ 'type' => 'tag', 'value' => strtolower( $m[1] ) ];
                } elseif ( ! empty( $m[2] ) ) {
                    $tokens[] = [
                        'type'  => $m[2] === '.' ? 'class' : 'id',
                        'value' => $m[3],
                    ];
                }
            }
        }

        if ( ! $tokens ) {
            return '';
        }

        // Build the XPath: choose tag (or "*") for the node test, then
        // attach predicate(s) for each class / id qualifier.
        $tag        = '*';
        $predicates = [];

        foreach ( $tokens as $tok ) {
            if ( $tok['type'] === 'tag' ) {
                // Only the first tag token wins; later ones (shouldn't
                // happen with valid CSS) are ignored.
                if ( $tag === '*' ) {
                    $tag = $tok['value'];
                }
            } elseif ( $tok['type'] === 'class' ) {
                $cls = strtolower( $tok['value'] );
                $predicates[] = "contains(concat(' ', normalize-space(translate(@class, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')), ' '), ' {$cls} ')";
            } elseif ( $tok['type'] === 'id' ) {
                $id_lower = strtolower( $tok['value'] );
                $predicates[] = "translate(@id, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='{$id_lower}'";
            }
        }

        $predicate_str = $predicates ? '[' . implode( ' and ', $predicates ) . ']' : '';

        return "//{$tag}{$predicate_str}";
    }

    /**
     * Convert HTML to markdown using core's existing html_to_markdown
     * helper. Defers to core for consistency with the rest of the export.
     */
    private function convert_to_markdown( string $html ): string {
        if ( ! class_exists( 'SocialBump_AI_Knowledge_Exporter' ) ) {
            return '';
        }

        $core = SocialBump_AI_Knowledge_Exporter::instance();

        if ( ! method_exists( $core, 'html_to_markdown' ) ) {
            return '';
        }

        return trim( $core->html_to_markdown( $html, [
            'aggressive'         => true,
            'strip_with_content' => [ 'svg', 'iframe', 'noscript', 'form' ],
        ] ) );
    }
}

SocialBump_AI_Knowledge_Renderer::instance();
