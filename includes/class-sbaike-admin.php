<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's admin: menu, pages and the shared SocialBUMP chrome.
 *
 * The exporter itself renders its own settings page, so this wraps that page in
 * the same header and stylesheet as the other SocialBUMP plugins rather than
 * rewriting it. Everything else, the module cards, updates and publishing, is
 * built here.
 */
class SBAIKE_Admin {

	const PAGE_SLUG = 'sb-ai-knowledge-exporter';

	private static $instance = null;

	private $core;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot() {
		$this->core = SocialBump_AI_Knowledge_Exporter::instance();

		add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'styles' ] );

		// The shared overview page, when more than one SocialBUMP plugin is about.
		add_filter( 'wp_redirect', [ $this, 'return_after_save' ] );

		// Late, so the core has already put its own node in the bar to hang these off.
		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 120 );
	}

	/** The modules that have a settings page of their own. */
	private function module_pages() {
		$pages = [];

		foreach ( (array) $this->core->get_registered_extensions() as $slug => $module ) {
			if ( empty( $module['is_active'] ) || empty( $module['admin_page'] ) || ! is_callable( $module['admin_page'] ) ) {
				continue;
			}

			$pages[ $slug ] = ! empty( $module['settings_title'] ) ? $module['settings_title'] : $module['name'];
		}

		return $pages;
	}

	public static function module_page_slug( $slug ) {
		return self::PAGE_SLUG . '-module-' . sanitize_key( $slug );
	}

	public function add_menu() {
		add_menu_page(
			__( 'SocialBUMP SEO for AI', 'socialbump-ai-knowledge-exporter' ),
			__( 'SB SEO for AI', 'socialbump-ai-knowledge-exporter' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_content' ],
			$this->menu_icon(),
			$this->menu_position()
		);

		// Content leads, since it decides what ends up in the files.
		add_submenu_page( self::PAGE_SLUG, __( 'Content', 'socialbump-ai-knowledge-exporter' ), __( 'Content', 'socialbump-ai-knowledge-exporter' ), 'manage_options', self::PAGE_SLUG, [ $this, 'render_content' ] );
		add_submenu_page( self::PAGE_SLUG, __( 'Business Details', 'socialbump-ai-knowledge-exporter' ), __( 'Business', 'socialbump-ai-knowledge-exporter' ), 'manage_options', self::PAGE_SLUG . '-business', [ $this, 'render_business' ] );
		add_submenu_page( self::PAGE_SLUG, __( 'Settings', 'socialbump-ai-knowledge-exporter' ), __( 'Settings', 'socialbump-ai-knowledge-exporter' ), 'manage_options', self::PAGE_SLUG . '-settings', [ $this, 'render_main' ] );

		foreach ( $this->module_pages() as $slug => $title ) {
			$module = $this->core->get_registered_extensions()[ $slug ];

			add_submenu_page(
				self::PAGE_SLUG,
				$title,
				$title,
				'manage_options',
				self::module_page_slug( $slug ),
				function () use ( $module ) {
					$this->render_module_page( $module );
				}
			);
		}

		add_submenu_page( self::PAGE_SLUG, __( 'Updates', 'socialbump-ai-knowledge-exporter' ), __( 'Updates', 'socialbump-ai-knowledge-exporter' ), 'manage_options', self::PAGE_SLUG . '-updates', [ $this, 'render_updates' ] );

		if ( function_exists( 'sbaike_is_hub' ) && sbaike_is_hub() ) {
			add_submenu_page( self::PAGE_SLUG, __( 'Publishing', 'socialbump-ai-knowledge-exporter' ), __( 'Publishing', 'socialbump-ai-knowledge-exporter' ), 'manage_options', self::PAGE_SLUG . '-publishing', [ $this, 'render_publishing' ] );
		}
	}
	public function render_settings( $page = 'settings' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$quote = chr( 34 );

		ob_start();
		$this->core->render_admin_page();
		$html = (string) ob_get_clean();

		// Take over the page's own wrapper, so ours is the outer one.
		$wrap = '<div class=' . $quote . 'wrap' . $quote . '>';
		$at   = strpos( $html, $wrap );

		if ( $at !== false ) {
			$html = substr( $html, 0, $at ) . substr( $html, $at + strlen( $wrap ) );
			$last = strrpos( $html, '</div>' );

			if ( $last !== false ) {
				$html = substr( $html, 0, $last ) . substr( $html, $last + 6 );
			}
		}

		// And its heading, which the banner now carries.
		$h1_start = strpos( $html, '<h1' );
		$h1_end   = $h1_start === false ? false : strpos( $html, '</h1>', $h1_start );

		if ( $h1_end !== false && strpos( substr( $html, $h1_start, $h1_end - $h1_start ), 'SEO for AI' ) !== false ) {
			$html = substr( $html, 0, $h1_start ) . substr( $html, $h1_end + 5 );
		}

		$html = $this->panels( $this->unwrap_cards( $html ) );

		// The save bar is drawn at the end of the last section, so it is lifted out
		// and put back at the foot of the form. Every page needs it, whichever
		// sections it happens to show.
		$save = $this->take_save_bar( $html );
		$html = $save[0];

		// The shared save-state script takes over, so the exporter's own reminder
		// goes and its dirty handling is quietened. The script itself stays: it also
		// runs the drag to reorder lists.
		$html = $this->take_element( $html, 'socialbump-unsaved-floating' );
		$html = $this->quieten_dirty_script( $html );

		list( $html, $kept ) = $this->only_sections( $html, $this->page_sections( $page ) );

		$markers = '';

		$markers .= '<input type=' . $quote . 'hidden' . $quote . ' name=' . $quote . 'sbaike_return' . $quote . ' value=' . $quote . esc_attr( $this->page_slug( $page ) ) . $quote . '>';

		foreach ( $kept as $slug ) {
			$markers .= '<input type=' . $quote . 'hidden' . $quote . ' name=' . $quote . 'sbaike_sections[]' . $quote . ' value=' . $quote . esc_attr( $slug ) . $quote . '>';
		}

		$close = strpos( $html, '</form>' );

		if ( $close !== false ) {
			$html = substr( $html, 0, $close ) . $markers . $save[1] . substr( $html, $close );
		}

		// Modules lead the Settings page: what is reading the content, before how.
		if ( $page === 'settings' ) {
			$html = $this->prepend_section( $html, $this->modules_html() );
		}

		// The status belongs with the content it describes, which is the Content page.
		if ( $page === 'content' ) {
			$status = $this->status_html();

			$html = $this->prepend_section( $html, $status );
		}

		$titles = [
			'settings' => [ __( 'Settings', 'socialbump-ai-knowledge-exporter' ), __( 'How the files are built and served.', 'socialbump-ai-knowledge-exporter' ) ],
			'business' => [ __( 'Business', 'socialbump-ai-knowledge-exporter' ), __( 'The business context written into the top of every exported file.', 'socialbump-ai-knowledge-exporter' ) ],
			'content'  => [ __( 'Content', 'socialbump-ai-knowledge-exporter' ), __( 'What goes into the files: post types, taxonomies and the fields that come with them.', 'socialbump-ai-knowledge-exporter' ) ],
		];

		$title = isset( $titles[ $page ] ) ? $titles[ $page ] : $titles['settings'];

		echo '<div class=' . $quote . 'wrap sbaike-wrap' . $quote . '>';
		$this->render_header( $title[0], $title[1] );
		if ( class_exists( 'SBAIKE_Rebuild' ) ) {
			echo SBAIKE_Rebuild::report(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<div class=' . $quote . 'sbaike-core' . $quote . '>';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div>';
	}

	/** Put a panel above whatever the page already has. */
	private function prepend_section( $html, $section ) {
		if ( $section === '' ) {
			return $html;
		}

		$first = strpos( $html, '<section class=' . chr( 34 ) . 'sbaike-section' . chr( 34 ) . '>' );

		if ( $first === false ) {
			return $html . $section;
		}

		return substr( $html, 0, $first ) . $section . substr( $html, $first );
	}

	public function render_main() {
		$this->render_settings( 'settings' );
	}

	public function render_business() {
		$this->render_settings( 'business' );
	}

	public function render_content() {
		$this->render_settings( 'content' );
	}

	/** Remove an element by id, with whatever it contains. */
	private function take_element( $html, $id ) {
		$at = strpos( $html, 'id=' . chr( 34 ) . $id . chr( 34 ) );

		if ( $at === false ) {
			return $html;
		}

		$start = strrpos( substr( $html, 0, $at ), '<' );
		$end   = strpos( $html, '</button>', $at );

		if ( $start === false || $end === false ) {
			return $html;
		}

		return substr( $html, 0, $start ) . substr( $html, $end + 9 );
	}

	/**
	 * Quieten the exporter's own unsaved changes handling.
	 *
	 * The same script also sets up the drag to reorder lists, so it has to stay.
	 * Only the parts that fight with the shared save state are pointed at nothing:
	 * its floating reminder, its save button and its own leave warning.
	 */
	private function quieten_dirty_script( $html ) {
		$swap = [
			"\$('#socialbump-unsaved-floating')" => '$([])',
			"\$('.socialbump-save-button')"      => '$([])',
			"\$(window).on('beforeunload'"        => "\$([]).on('beforeunload'",
		];

		return str_replace( array_keys( $swap ), array_values( $swap ), $html );
	}
	/**
	 * Lift a script out of the page so it survives section filtering.
	 *
	 * The exporter prints its unsaved changes script inside the Post Types
	 * section, so any page without that section would lose it. Returns the page
	 * without the script, and the script to put back.
	 */
	private function take_script( $html, $needle ) {
		$at = 0;

		while ( true ) {
			$start = strpos( $html, '<script', $at );

			if ( $start === false ) {
				return [ $html, '' ];
			}

			$end = strpos( $html, '</script>', $start );

			if ( $end === false ) {
				return [ $html, '' ];
			}

			$end  += 9;
			$block = substr( $html, $start, $end - $start );

			if ( strpos( $block, $needle ) !== false ) {
				return [ substr( $html, 0, $start ) . substr( $html, $end ), $block ];
			}

			$at = $end;
		}
	}
	/**
	 * Lift the save bar out of whichever section it was drawn in.
	 *
	 * Found by the button inside it rather than by its markup, since the Danger
	 * Zone uses a paragraph laid out the same way. Returns the page without the
	 * bar, and the bar itself to put back at the foot of the form.
	 */
	private function take_save_bar( $html ) {
		$quote  = chr( 34 );
		$button = 'name=' . $quote . 'socialbump_mode' . $quote . ' value=' . $quote . 'update' . $quote;
		$at     = strrpos( $html, $button );

		if ( $at === false ) {
			return [ $html, '' ];
		}

		$start = strrpos( substr( $html, 0, $at ), '<p ' );
		$end   = strpos( $html, '</p>', $at );

		if ( $start === false || $end === false ) {
			return [ $html, '' ];
		}

		$end += 4;
		$bar  = substr( $html, $start, $end - $start );

		// Only the save bar, never a paragraph that happens to wrap something else.
		if ( strpos( $bar, 'socialbump-save-button' ) === false ) {
			return [ $html, '' ];
		}

		return [ substr( $html, 0, $start ) . substr( $html, $end ), $bar ];
	}
	/** The admin page slug for one of our pages. */
	private function page_slug( $page ) {
		if ( $page === 'content' ) {
			return self::PAGE_SLUG;
		}

		return self::PAGE_SLUG . '-' . $page;
	}

	/**
	 * Come back to the page you saved from.
	 *
	 * The exporter sends you to its own page after saving, which is the Content
	 * page now the settings are split up. Saving the Business page and landing
	 * somewhere else is disorienting, so the redirect is pointed back.
	 */
	public function return_after_save( $location ) {
		$return = isset( $_REQUEST['sbaike_return'] ) ? sanitize_key( wp_unslash( $_REQUEST['sbaike_return'] ) ) : '';

		if ( $return === '' || strpos( $return, self::PAGE_SLUG ) !== 0 ) {
			return $location;
		}

		// Only our own pages, and only a redirect that was heading to one.
		if ( strpos( $location, 'page=' . self::PAGE_SLUG ) === false ) {
			return $location;
		}

		return add_query_arg( 'page', $return, $location );
	}
	/** Which sections belong on which page, in the order they are drawn. */
	private function page_sections( $page ) {
		$map = [
			'settings' => [ 'rendering', 'acf-visibility', 'serving', 'updates', 'danger' ],
			'business' => [ 'business' ],
			'content'  => [ 'acf-options', 'taxonomies', 'post-types' ],
		];

		return isset( $map[ $page ] ) ? $map[ $page ] : $map['settings'];
	}

	/** The heading each section is known by on the exporter's page. */
	private function section_slugs() {
		return [
			'Business Details'        => 'business',
			'ACF Options Page Fields' => 'acf-options',
			'Taxonomies to Include'   => 'taxonomies',
			'Post Types to Include'   => 'post-types',
			'Content Rendering'       => 'rendering',
			'ACF Field Visibility'    => 'acf-visibility',
			'File Serving'            => 'serving',
			'Automatic Updates'       => 'updates',
			'Danger Zone'             => 'danger',
		];
	}

	/**
	 * Keep only the sections this page is responsible for.
	 *
	 * A hidden field tells the save handler which sections were on the page, so
	 * the ones left out keep whatever they were set to rather than being saved
	 * as empty.
	 */
	private function only_sections( $html, $wanted ) {
		$slugs = $this->section_slugs();
		$open  = '<section class=' . chr( 34 ) . 'sbaike-section' . chr( 34 ) . '>';
		$kept  = [];
		$out   = '';
		$at    = 0;

		while ( true ) {
			$start = strpos( $html, $open, $at );

			if ( $start === false ) {
				$out .= substr( $html, $at );
				break;
			}

			$end = strpos( $html, '</section>', $start );

			if ( $end === false ) {
				$out .= substr( $html, $at );
				break;
			}

			$end  += 10;
			$block = substr( $html, $start, $end - $start );
			$slug  = '';

			foreach ( $slugs as $heading => $known ) {
				if ( strpos( $block, '>' . $heading . '<' ) !== false ) {
					$slug = $known;
					break;
				}
			}

			$out .= substr( $html, $at, $start - $at );

			// A section we do not recognise stays put rather than disappearing.
			if ( $slug === '' || in_array( $slug, $wanted, true ) ) {
				$out .= $block;

				if ( $slug !== '' ) {
					$kept[] = $slug;
				}
			}

			$at = $end;
		}

		return [ $out, $kept ];
	}
	/**
	 * A status panel: what is in the files now, and what is waiting.
	 *
	 * The page reports this per post type further down, but by then it is well
	 * below the fold. This says it once, near the buttons that act on it. Only
	 * the post types being exported are listed.
	 */
	private function status_html() {
		if ( ! method_exists( $this->core, 'count_stale_posts_for_type' ) || ! method_exists( $this->core, 'count_eligible_posts_for_type' ) ) {
			return '';
		}

		$settings = (array) get_option( SBAIKE_Transfer::SETTINGS_OPTION, [] );
		$types    = isset( $settings['post_types'] ) ? (array) $settings['post_types'] : [];

		if ( ! $types ) {
			return '';
		}

		$pills = '';

		foreach ( $types as $type ) {
			$name  = (string) $type;
			$total = (int) $this->core->count_eligible_posts_for_type( $name );

			if ( $total < 1 ) {
				continue;
			}

			$stale  = (int) $this->core->count_stale_posts_for_type( $name );
			$object = get_post_type_object( $name );
			$label  = $object ? ( $total === 1 ? $object->labels->singular_name : $object->labels->name ) : $name;

			if ( $stale > 0 ) {
				/* translators: 1: number waiting, 2: number in the export, 3: post type name */
				$text = sprintf( __( '%1$s of %2$s %3$s need updating', 'socialbump-ai-knowledge-exporter' ), number_format_i18n( $stale ), number_format_i18n( $total ), $label );
			} else {
				/* translators: 1: number in the export, 2: post type name */
				$text = sprintf( _n( '%1$s %2$s is up to date', '%1$s %2$s are up to date', $total, 'socialbump-ai-knowledge-exporter' ), number_format_i18n( $total ), $label );
			}

			if ( $stale > 0 ) {
				// The pill is the button: it updates just this type.
				$action = wp_nonce_url(
					admin_url( 'admin-post.php?action=socialbump_update_post_type&post_type=' . rawurlencode( $name ) ),
					'socialbump_update_post_type_' . $name
				);

				/* translators: %s: post type name */
				$tip = sprintf( __( 'Re-render the changed %s and rebuild the files', 'socialbump-ai-knowledge-exporter' ), $label );

				$pills .= '<a class="sbaike-status__pill is-stale" href="' . esc_url( $action ) . '" title="' . esc_attr( $tip ) . '">' . esc_html( $text ) . '</a>';

				continue;
			}

			$pills .= '<span class="sbaike-status__pill is-good">' . esc_html( $text ) . '</span>';
		}

		if ( $pills === '' ) {
			return '';
		}

		$head = '<section class="sbaike-section"><div class="sbaike-section__head"><h2>' . esc_html__( 'Status', 'socialbump-ai-knowledge-exporter' ) . '</h2>';
		$head .= '<p>' . esc_html__( 'What is in the files now, and what has changed since they were built.', 'socialbump-ai-knowledge-exporter' ) . '</p></div>';

		return $head . '<div class="sbaike-section__body"><div class="sbaike-status">' . $pills . '</div></div></section>';
	}
	/**
	 * Take off the exporter's own boxes.
	 *
	 * The page draws most sections inside an inline styled card, some with extra
	 * styles tacked on, so they are matched on the border they all share. Our
	 * panels do that job now and a box inside a box reads badly, so the wrappers
	 * come off while their contents stay exactly as they were.
	 */
	private function unwrap_cards( $html ) {
		$quote   = chr( 34 );
		$pattern = '/<div[^>]*style=' . $quote . '[^' . $quote . ']*border:\s*1px solid #ccd0d4[^' . $quote . ']*' . $quote . '[^>]*>/i';
		$done    = '';
		$guard   = 0;

		while ( $guard < 60 && preg_match( $pattern, $html, $match, PREG_OFFSET_CAPTURE ) ) {
			$guard++;

			$start    = $match[0][1];
			$open_end = $start + strlen( $match[0][0] );

			// Walk forward to the closing tag that belongs to this one.
			$depth  = 1;
			$cursor = $open_end;
			$close  = false;

			while ( $depth > 0 ) {
				$next_open  = strpos( $html, '<div', $cursor );
				$next_close = strpos( $html, '</div>', $cursor );

				if ( $next_close === false ) {
					break;
				}

				if ( $next_open !== false && $next_open < $next_close ) {
					$depth++;
					$cursor = $next_open + 4;
					continue;
				}

				$depth--;
				$cursor = $next_close + 6;

				if ( $depth === 0 ) {
					$close = $next_close;
				}
			}

			if ( $close === false ) {
				break;
			}

			$inner = substr( $html, $open_end, $close - $open_end );

			/**
			 * A box holding a heading is a section, and our panel replaces it.
			 * Anything else, such as the box drawn around each post type, is the
			 * layout of that list and is left exactly as it is.
			 */
			if ( strpos( $inner, '<h2' ) === false ) {
				$keep  = $close + 6;
				$done .= substr( $html, 0, $keep );
				$html  = substr( $html, $keep );

				continue;
			}

			$html = substr( $html, 0, $start ) . $inner . substr( $html, $close + 6 );
		}

		return $done . $html;
	}
	/**
	 * Turn each heading on the exporter's page into one of our panels.
	 *
	 * A heading and the line under it become the panel head and everything after
	 * it the body. A panel closes at the next heading, at the end of the page, or
	 * at the end of a form it was opened inside, so a panel never straddles a
	 * form boundary. A panel that was opened before a form simply keeps going,
	 * which is how the Danger Zone reads.
	 */
	private function panels( $html ) {
		$open    = false;
		$in_form = false;
		$owned   = false;

		$html = preg_replace_callback(
			'/(<h2[^>]*>.*?<\/h2>\s*(?:<p[^>]*>.*?<\/p>)?)|(<form\b)|(<\/form>)/s',
			function ( $match ) use ( &$open, &$in_form, &$owned ) {
				// A form starting: nothing to close, but remember where we are.
				if ( ! empty( $match[2] ) ) {
					$in_form = true;
					$owned   = false;

					return $match[2];
				}

				// A form ending: only close a panel that began inside it.
				if ( ! empty( $match[3] ) ) {
					$close   = ( $open && $owned ) ? '</div></section>' : '';
					$open    = $open && ! $owned;
					$in_form = false;
					$owned   = false;

					return $close . '</form>';
				}

				$close = $open ? '</div></section>' : '';
				$open  = true;
				$owned = $in_form;

				return $close . '<section class="sbaike-section"><div class="sbaike-section__head">' . $match[1] . '</div><div class="sbaike-section__body">';
			},
			$html
		);

		return $open ? $html . '</div></section>' : $html;
	}
	/**
	 * What the exporter can read content from, as a panel.
	 *
	 * Builders and plugins register themselves with the core, so this lists what
	 * turned up rather than a fixed set. Nothing here is switched on by hand:
	 * each one detects what it needs and steps in when it is there.
	 */
	private function modules_html() {
		$modules = (array) $this->core->get_registered_extensions();

		if ( ! $modules ) {
			return '';
		}

		$pages = $this->module_pages();
		$cards = '';

		foreach ( $modules as $slug => $module ) {
			$active = ! empty( $module['is_active'] );

			$cards .= '<div class="sbaike-card' . ( $active ? ' is-on' : ' is-unavailable' ) . '">';
			$cards .= '<div class="sbaike-card__head"><h3>' . esc_html( $module['name'] ) . '</h3>';
			$cards .= '<span class="sbaike-updates__badge ' . ( $active ? 'is-current' : '' ) . '">' . esc_html( $active ? __( 'Detected', 'socialbump-ai-knowledge-exporter' ) : __( 'Standby', 'socialbump-ai-knowledge-exporter' ) ) . '</span></div>';

			$category = ! empty( $module['category'] ) ? ucfirst( $module['category'] ) : __( 'Integration', 'socialbump-ai-knowledge-exporter' );
			$cards   .= '<p class="sbaike-card__meta">' . esc_html( $category ) . ' &middot; v' . esc_html( $module['version'] ) . '</p>';

			if ( ! empty( $module['description'] ) ) {
				$cards .= '<p class="sbaike-card__desc">' . esc_html( $module['description'] ) . '</p>';
			}

			if ( ! $active ) {
				$cards .= '<p class="sbaike-card__needs">' . esc_html__( 'Waiting for this builder or plugin to be installed.', 'socialbump-ai-knowledge-exporter' ) . '</p>';
			} elseif ( isset( $pages[ $slug ] ) ) {
				$cards .= '<p class="sbaike-card__settings"><a class="sbaike-card__link" href="' . esc_url( admin_url( 'admin.php?page=' . self::module_page_slug( $slug ) ) ) . '">' . esc_html__( 'Settings', 'socialbump-ai-knowledge-exporter' ) . '</a></p>';
			}

			$cards .= '</div>';
		}

		$head = '<section class="sbaike-section"><div class="sbaike-section__head"><h2>' . esc_html__( 'Modules', 'socialbump-ai-knowledge-exporter' ) . '</h2>';
		$head .= '<p>' . esc_html__( 'Support for builders and plugins. Each one switches itself on when what it needs is present.', 'socialbump-ai-knowledge-exporter' ) . '</p></div>';

		return $head . '<div class="sbaike-section__body"><div class="sbaike-grid">' . $cards . '</div></div></section>';
	}
	private function render_module_page( $module ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$intro = ! empty( $module['description'] ) ? $module['description'] : '';

		echo '<div class="wrap sbaike-wrap">';
		$this->render_header( $module['name'], $intro );
		echo '<section class="sbaike-section sbaike-core">';
		call_user_func( $module['admin_page'], $module, $this->core );
		echo '</section></div>';
	}

	public function render_updates() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap sbaike-wrap">';
		$this->render_header( __( 'Updates', 'socialbump-ai-knowledge-exporter' ), __( 'Where this plugin gets its updates, and the settings you can carry across to another site.', 'socialbump-ai-knowledge-exporter' ) );
		SBAIKE_Updates::render();
		SBAIKE_Transfer::render();
		echo '</div>';
	}

	public function render_publishing() {
		if ( ! current_user_can( 'manage_options' ) || ! sbaike_is_hub() ) {
			return;
		}

		echo '<div class="wrap sbaike-wrap">';
		$this->render_header( __( 'Publishing', 'socialbump-ai-knowledge-exporter' ), __( 'Push a new version to GitHub, from here on the hub. Sites pick it up as a normal plugin update.', 'socialbump-ai-knowledge-exporter' ) );
		SBAIKE_Release::instance()->render();

		if ( class_exists( 'SBAIKE_Docs' ) ) {
			SBAIKE_Docs::render();
		}
		echo '</div>';
	}
	/**
	 * The banner across the top of every page.
	 *
	 * The hr after it tells WordPress to put admin notices below the banner
	 * rather than inside it.
	 */
	/**
	 * The plugin pages, along the bottom of the banner.
	 *
	 * The menu lists them already, but on a long admin menu the plugin can be a
	 * scroll away, and its pages only show while you are on one of them. This
	 * keeps them to hand wherever you are.
	 *
	 * Updates says so when a new version is waiting, and Publishing says how many
	 * changes are queued, so neither has to be opened to find out.
	 */
	private function render_nav() {
		$items = $this->bar_items();

		if ( count( $items ) < 2 ) {
			return;
		}

		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$state   = get_site_transient( 'update_plugins' );
		$file    = plugin_basename( SBAIKE_FILE );
		$waiting = ( $state && ! empty( $state->response[ $file ]->new_version ) ) ? $state->response[ $file ]->new_version : '';
		$notes   = count( (array) get_option( 'sbaike_pending_changes', [] ) );

		echo '<nav class="sbaike-header__nav">';

		foreach ( $items as $slug => $title ) {
			$badge = '';

			if ( $slug === self::PAGE_SLUG . '-updates' && $waiting !== '' ) {
				$badge = '<span class="sbaike-header__badge">v' . esc_html( $waiting ) . '</span>';
			}

			if ( $slug === self::PAGE_SLUG . '-publishing' && $notes > 0 ) {
				$badge = '<span class="sbaike-header__badge">' . esc_html( number_format_i18n( $notes ) ) . '</span>';
			}

			echo '<a class="sbaike-header__link' . ( $slug === $page ? ' is-current' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $title ) . $badge . '</a>';
		}

		echo '</nav>';
	}
	private function render_header( $title, $intro = '' ) {
		$state   = get_site_transient( 'update_plugins' );
		$file    = plugin_basename( SBAIKE_FILE );
		$pending = ( $state && ! empty( $state->response[ $file ]->new_version ) ) ? $state->response[ $file ]->new_version : '';

		$home    = esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		$updates = esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-updates' ) );
		$logo    = esc_url( SBAIKE_URL . 'assets/img/socialbump-logo-light.svg' );

		/* translators: %s: version number */
		$tip = $pending ? sprintf( __( 'Version %s is available', 'socialbump-ai-knowledge-exporter' ), $pending ) : __( 'Up to date', 'socialbump-ai-knowledge-exporter' );

		echo '<div class="sbaike-header"><div class="sbaike-header__brand">';
		echo '<a class="sbaike-header__home" href="' . $home . '"><img class="sbaike-header__logo" src="' . $logo . '" alt="SocialBUMP" width="203" height="28"></a>';
		// The plugin name, then the page, so you always know where you are.
		$name = __( 'SEO for AI', 'socialbump-ai-knowledge-exporter' );
		$page = $title !== $name ? ' <span class="sbaike-header__page">' . esc_html( $title ) . '</span>' : '';

		echo '<h1 class="sbaike-header__title">' . esc_html( $name ) . $page . '</h1>';
		echo '<a class="sbaike-header__version' . ( $pending ? ' is-outdated' : '' ) . '" href="' . $updates . '" title="' . esc_attr( $tip ) . '">v' . esc_html( SBAIKE_VERSION ) . ( $pending ? ' &rarr; v' . esc_html( $pending ) : '' ) . '</a>';
		echo '</div>';

		if ( $intro !== '' ) {
			echo '<p class="sbaike-header__intro">' . esc_html( $intro ) . '</p>';
		}

		$this->render_nav();

		echo '</div><hr class="wp-header-end">';
	}

	/**
	 * The same toggle switch as the other SocialBUMP plugins. WordPress recolours
	 * SVG data icons to match the admin menu.
	 */
	private function menu_icon() {
		// The SocialBUMP mark. Its own copy, since nothing is shared any more.
		$q = chr( 34 );

		// Tall and narrow, so it is scaled to the height of the box and centred.
		$path = 'M10.94,30.2c1.24,1.24,1.86,2.75,1.86,4.54s-.62,3.3-1.86,4.54-2.75,1.86-4.54,1.86-3.3-.62-4.54-1.86-1.86-2.75-1.86-4.54.62-3.3,1.86-4.54,2.75-1.86,4.54-1.86,3.3.62,4.54,1.86ZM1.22,24.27L.13,1.4C.09.64.7,0,1.46,0h9.88c.76,0,1.37.64,1.34,1.4l-1.09,22.87c-.03.71-.62,1.27-1.34,1.27H2.56c-.71,0-1.3-.56-1.34-1.27Z';

		$svg  = '<svg xmlns=' . $q . 'http://www.w3.org/2000/svg' . $q . ' viewBox=' . $q . '0 0 20 20' . $q . '>';
		$svg .= '<g transform=' . $q . 'translate(7.2 1) scale(0.4376)' . $q . ' fill=' . $q . '#ffffff' . $q . '>';
		$svg .= '<path d=' . $q . $path . $q . '/></g></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Sits with the other SocialBUMP plugins rather than off on its own.
	 */
	private function menu_position() {
		global $menu;

		$after = [ 'sb-site-kit' => 0.1, 'sb-bricks-tweaks' => 0.2 ];

		foreach ( $after as $slug => $nudge ) {
			foreach ( (array) $menu as $position => $item ) {
				if ( isset( $item[2] ) && $item[2] === $slug ) {
					return (float) $position + $nudge;
				}
			}
		}

		return null;
	}
	public function styles( $hook ) {
		if ( strpos( (string) $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		$css = SBAIKE_PATH . 'assets/css/admin.css';
		$ver = file_exists( $css ) ? SBAIKE_VERSION . '.' . filemtime( $css ) : SBAIKE_VERSION;

		wp_enqueue_style( 'sbaike-admin', SBAIKE_URL . 'assets/css/admin.css', [], $ver );

		// Match the accent to whichever admin colour scheme the user has chosen.
		wp_add_inline_style( 'sbaike-admin', ':root{--sbaike-accent:' . $this->accent_colour() . ';}' );

		// The settings page has a drag to order list.
		wp_enqueue_script( 'jquery-ui-sortable' );

		// Draws the close button on the rebuild report.
		wp_enqueue_script( 'wp-a11y' );
		wp_enqueue_script( 'common' );

		// Rebuilding runs in batches, with a progress bar.
		$rebuild = SBAIKE_PATH . 'assets/js/rebuild.js';

		if ( file_exists( $rebuild ) ) {
			wp_enqueue_script( 'sbaike-rebuild', SBAIKE_URL . 'assets/js/rebuild.js', [], SBAIKE_VERSION . '.' . filemtime( $rebuild ), true );

			wp_add_inline_script(
				'sbaike-rebuild',
				'window.sbaikeRebuild = ' . wp_json_encode(
					[
						'ajax'       => admin_url( 'admin-ajax.php' ),
						'nonce'      => wp_create_nonce( 'sbaike_job' ),
						'workers'    => (int) apply_filters( 'socialbump_aiknowledge_rebuild_workers', 3 ),
						'working'    => __( 'Rebuilding', 'socialbump-ai-knowledge-exporter' ),
						'preparing'  => __( 'Working out what needs doing', 'socialbump-ai-knowledge-exporter' ),
						/* translators: 1: posts done, 2: posts in total */
						'counting'   => __( '%1$s of %2$s done', 'socialbump-ai-knowledge-exporter' ),
						'assembling' => __( 'Writing the files', 'socialbump-ai-knowledge-exporter' ),
						'done'       => __( 'Finished', 'socialbump-ai-knowledge-exporter' ),
						'failed'     => __( 'That did not finish', 'socialbump-ai-knowledge-exporter' ),
						'retry'      => __( 'Nothing was lost. Close this and try again.', 'socialbump-ai-knowledge-exporter' ),
						'unsaved'    => __( 'Save your changes first. A rebuild reloads the page, which would lose them.', 'socialbump-ai-knowledge-exporter' ),
						'close'      => __( 'Close', 'socialbump-ai-knowledge-exporter' ),
						// A save that left rendering to do redirects back with this
						// flag set, and the stale job starts itself on page load.
						'autorun'      => ( isset( $_GET['sbaike_autorun'] ) && sanitize_key( wp_unslash( $_GET['sbaike_autorun'] ) ) === 'stale' ) ? 'stale' : '',
						'autorunTitle' => __( 'Updating', 'socialbump-ai-knowledge-exporter' ),
					]
				) . ';',
				'before'
			);
		}
		// Tells you when there is something to save, and when there is not.
		$save = SBAIKE_PATH . 'assets/js/save-state.js';

		if ( file_exists( $save ) ) {
			wp_enqueue_script( 'sbaike-save-state', SBAIKE_URL . 'assets/js/save-state.js', [], SBAIKE_VERSION . '.' . filemtime( $save ), true );
		}
	}

	/** How strongly coloured a hex value is, from 0 (grey) to 1. */
	private static function saturation( $hex ) {
		$raw = ltrim( (string) $hex, '#' );

		if ( strlen( $raw ) === 3 ) {
			$raw = $raw[0] . $raw[0] . $raw[1] . $raw[1] . $raw[2] . $raw[2];
		}

		if ( strlen( $raw ) !== 6 ) {
			return 0;
		}

		$rgb = [ hexdec( substr( $raw, 0, 2 ) ), hexdec( substr( $raw, 2, 2 ) ), hexdec( substr( $raw, 4, 2 ) ) ];
		$max = max( $rgb );

		return $max > 0 ? ( $max - min( $rgb ) ) / $max : 0;
	}

	/**
	 * The current admin colour scheme's accent.
	 *
	 * The same picking as the other SocialBUMP plugins, so all three land on the
	 * same colour. WordPress does not expose an accent directly: a scheme
	 * registers four swatches and the accent is not always in the same slot.
	 */
	private function accent_colour() {
		global $_wp_admin_css_colors;

		$scheme = get_user_option( 'admin_color' );
		$colors = ( $scheme && isset( $_wp_admin_css_colors[ $scheme ]->colors ) ) ? (array) $_wp_admin_css_colors[ $scheme ]->colors : [];
		$colors = array_values(
			array_filter(
				$colors,
				function ( $hex ) {
					return (bool) sanitize_hex_color( $hex );
				}
			)
		);

		// A scheme with a strongly coloured focus colour is naming its accent directly.
		if ( $scheme && ! empty( $_wp_admin_css_colors[ $scheme ]->icon_colors['focus'] ) ) {
			$focus = sanitize_hex_color( $_wp_admin_css_colors[ $scheme ]->icon_colors['focus'] );

			if ( $focus && self::saturation( $focus ) >= 0.6 ) {
				return $focus;
			}
		}

		if ( count( $colors ) < 2 ) {
			return '#2271b1';
		}

		/**
		 * A scheme registers base, secondary, highlight and notification. The
		 * highlight is usually the accent, but some schemes paint the current menu
		 * item with the notification colour instead, so take that when it is
		 * clearly the more vivid of the two.
		 */
		$pair      = array_slice( $colors, -2 );
		$highlight = $pair[0];
		$notice    = $pair[1];

		return self::saturation( $notice ) > self::saturation( $highlight ) + 0.15 ? $notice : $highlight;
	}
	/** The pages the admin bar shortcut lists, in menu order. */
	private function bar_items() {
		$items = [
			self::PAGE_SLUG               => __( 'Content', 'socialbump-ai-knowledge-exporter' ),
			self::PAGE_SLUG . '-business' => __( 'Business', 'socialbump-ai-knowledge-exporter' ),
			self::PAGE_SLUG . '-settings' => __( 'Settings', 'socialbump-ai-knowledge-exporter' ),
		];

		foreach ( $this->module_pages() as $slug => $title ) {
			$items[ self::module_page_slug( $slug ) ] = $title;
		}

		$items[ self::PAGE_SLUG . '-updates' ] = __( 'Updates', 'socialbump-ai-knowledge-exporter' );

		if ( function_exists( 'sbaike_is_hub' ) && sbaike_is_hub() ) {
			$items[ self::PAGE_SLUG . '-publishing' ] = __( 'Publishing', 'socialbump-ai-knowledge-exporter' );
		}

		return $items;
	}
	/**
	 * Hand our pages, status and actions to the shared SocialBUMP menu.
	 *
	 * The exporter puts its own item on the bar with a stale count and its
	 * rebuild buttons. That is taken down and handed over instead, so there is
	 * one SocialBUMP item rather than one per plugin.
	 */
	public function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'SBAIKE_Admin_Bar' ) ) {
			return;
		}

		$id      = 'socialbump-ai-knowledge';
		$actions = [];

		// Take the exporter's own actions across, then take its item down.
		foreach ( (array) $bar->get_nodes() as $node ) {
			if ( isset( $node->parent ) && $node->parent === $id ) {
				$meta = (array) $node->meta;

				// The update action reads as amber when there is work, grey when there is none.
				if ( substr( $node->id, -7 ) === '-update' ) {
					$idle = isset( $meta['class'] ) && strpos( $meta['class'], 'socialbump-ab-disabled' ) !== false;

					$meta['class'] = trim( ( isset( $meta['class'] ) ? $meta['class'] . ' ' : '' ) . 'sbaike-bar-action' . ( $idle ? ' is-idle' : '' ) );
				}

				$actions[] = [
					'title' => $node->title,
					'href'  => $node->href,
					'meta'  => $meta,
				];

				$bar->remove_node( $node->id );
			}
		}

		$bar->remove_node( $id );

		$items = $this->bar_items();

		// Which of our pages is open, if any. Nothing is current on the front end.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$current = isset( $items[ $page ] ) ? $page : '';

		$pages = [];

		foreach ( $items as $slug => $title ) {
			// Publishing says how many changes are waiting to go out.
			$waiting = $slug === self::PAGE_SLUG . '-publishing' ? count( (array) get_option( 'sbaike_pending_changes', [] ) ) : 0;

			$pages[] = [
				'title'     => $title,
				'href'      => admin_url( 'admin.php?page=' . $slug ),
				'current'   => $slug === $current,
				'attention' => $waiting > 0,
				'count'     => $waiting,
			];
		}

		// Posts waiting to be re-rendered, or a plugin update, both want attention.
		$stale = method_exists( $this->core, 'get_global_stale_count' ) ? (int) $this->core->get_global_stale_count() : 0;

		$state   = get_site_transient( 'update_plugins' );
		$file    = plugin_basename( SBAIKE_FILE );
		$pending = ( $state && ! empty( $state->response[ $file ]->new_version ) ) ? $state->response[ $file ]->new_version : '';

		$why = [];

		if ( $stale > 0 ) {
			/* translators: %s: number of posts */
			$why[] = sprintf( _n( '%s post needs updating', '%s posts need updating', $stale, 'socialbump-ai-knowledge-exporter' ), number_format_i18n( $stale ) );
		}

		if ( $pending !== '' ) {
			/* translators: %s: version number */
			$why[] = sprintf( __( 'Version %s is available', 'socialbump-ai-knowledge-exporter' ), $pending );
		}

		SBAIKE_Admin_Bar::register(
			[
				'id'              => 'seo-for-ai',
				'label'           => __( 'SEO for AI', 'socialbump-ai-knowledge-exporter' ),
				'href'            => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
				'items'           => $pages,
				'actions'         => $actions,
				'attention'       => (bool) $why,
				'attention_title' => implode( '. ', $why ),
				'current'         => $current !== '',
			]
		);
	}
}
