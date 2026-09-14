<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One place to see, and publish, all the SocialBUMP plugins.
 *
 * Every plugin ships an identical copy of this file and whichever loads first
 * defines the class, the same arrangement as the shared admin bar. Each one then
 * registers itself, and the page is drawn once.
 *
 * It only appears when more than one plugin is active. With a single plugin there
 * is nothing here worth a page of its own, and that plugin own menu says it all.
 */
if ( ! class_exists( 'SocialBUMP_Overview' ) ) {

	class SocialBUMP_Overview {

		const SLUG = 'socialbump';

		/** Everything registered this request, in registration order. */
		private static $plugins = [];

		private static $booted = false;

		/**
		 * Add a plugin to the overview.
		 *
		 * Expects: id, name, version, file, pages. Optionally release, a callback
		 * that draws that plugin publishing panel, and notes, the number of changes
		 * waiting to go out.
		 */
		public static function register( array $plugin ) {
			if ( empty( $plugin['id'] ) || empty( $plugin['name'] ) ) {
				return;
			}

			self::$plugins[ $plugin['id'] ] = wp_parse_args(
				$plugin,
				[
					'version' => '',
					'file'    => '',
					'pages'   => [],
					'release' => null,
					'notes'   => 0,
					'css'      => '',
					'css_time' => 0,
					'logo'       => '',
					'accent_var' => '',
					'hub'     => false,
				]
			);

			self::boot();
		}

		private static function boot() {
			if ( self::$booted ) {
				return;
			}

			self::$booted = true;

			// Late, so every plugin has had its say before we decide whether to draw.
			add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 99 );
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'styles' ] );
			add_action( 'admin_head', [ __CLASS__, 'icon_styles' ] );
		}

		/**
		 * Worth a page only on the hub, and only with more than one plugin.
		 *
		 * The hub is the blueprint every new site is built from, so this page goes
		 * of its own accord on the copies: there is nothing to publish there, and
		 * each plugin own menu already says what it does.
		 */
		public static function available() {
			if ( count( self::$plugins ) < 2 ) {
				return false;
			}

			foreach ( self::$plugins as $plugin ) {
				if ( ! empty( $plugin['hub'] ) ) {
					return true;
				}
			}

			return false;
		}

		public static function add_menu() {
			if ( ! self::available() ) {
				return;
			}

			add_menu_page(
				__( 'SocialBUMP Hub', 'socialbump' ),
				__( 'SocialBUMP Hub', 'socialbump' ),
				'manage_options',
				self::SLUG,
				[ __CLASS__, 'render' ],
				self::brand_icon(),
				2
			);
		}

		/**
		 * The SocialBUMP mark, sized for the admin menu.
		 *
		 * WordPress only recolours its own Dashicons, which are a font. An SVG given
		 * as a menu icon becomes a background image and keeps whatever colour is
		 * baked into it, so this is white and the dimming is done in CSS, which is
		 * what makes it behave like the icons around it.
		 */
		public static function brand_icon() {
			$q = chr( 34 );

			// The mark is tall and narrow, so it is scaled to the height of the box
			// and centred across it.
			$path = 'M10.94,30.2c1.24,1.24,1.86,2.75,1.86,4.54s-.62,3.3-1.86,4.54-2.75,1.86-4.54,1.86-3.3-.62-4.54-1.86-1.86-2.75-1.86-4.54.62-3.3,1.86-4.54,2.75-1.86,4.54-1.86,3.3.62,4.54,1.86ZM1.22,24.27L.13,1.4C.09.64.7,0,1.46,0h9.88c.76,0,1.37.64,1.34,1.4l-1.09,22.87c-.03.71-.62,1.27-1.34,1.27H2.56c-.71,0-1.3-.56-1.34-1.27Z';

			$svg  = '<svg xmlns=' . $q . 'http://www.w3.org/2000/svg' . $q . ' viewBox=' . $q . '0 0 20 20' . $q . '>';
			$svg .= '<g transform=' . $q . 'translate(7.2 1) scale(0.4376)' . $q . ' fill=' . $q . '#ffffff' . $q . '>';
			$svg .= '<path d=' . $q . $path . $q . '/></g></svg>';

			return 'data:image/svg+xml;base64,' . base64_encode( $svg );
		}

		/**
		 * Make the mark behave like the icons around it.
		 *
		 * A background image cannot inherit the menu colours, so it is dimmed while
		 * the item is idle and brought up to full when it is hovered or current, the
		 * same as every Dashicon.
		 */
		public static function icon_styles() {
			$items = [ 'toplevel_page_' . self::SLUG ];

			foreach ( self::$plugins as $plugin ) {
				$slugs = array_keys( (array) $plugin['pages'] );

				if ( $slugs ) {
					$items[] = 'toplevel_page_' . $slugs[0];
				}
			}

			$idle = [];
			$live = [];

			foreach ( $items as $item ) {
				$idle[] = '#adminmenu #' . $item . ' div.wp-menu-image.svg';
				$live[] = '#adminmenu #' . $item . ':hover div.wp-menu-image.svg';
				$live[] = '#adminmenu #' . $item . '.wp-has-current-submenu div.wp-menu-image.svg';
				$live[] = '#adminmenu #' . $item . '.current div.wp-menu-image.svg';
			}

			echo '<style>' . implode( ',', $idle ) . '{opacity:0.6;transition:opacity .1s ease-in-out;}';
			echo implode( ',', $live ) . '{opacity:1;}</style>';
		}
		/**
		 * Each plugin styles its own publishing panel, so load the lot here.
		 *
		 * The page belongs to none of them in particular, so nothing would style it
		 * otherwise. The layout of the page itself is small enough to print inline.
		 */
		public static function styles( $hook ) {
			if ( strpos( (string) $hook, self::SLUG ) === false ) {
				return;
			}

			foreach ( self::$plugins as $plugin ) {
				if ( empty( $plugin['css'] ) ) {
					continue;
				}

				$version = ! empty( $plugin['css_time'] ) ? $plugin['version'] . '.' . $plugin['css_time'] : $plugin['version'];

				wp_enqueue_style( 'sb-overview-' . sanitize_key( $plugin['id'] ), $plugin['css'], [], $version );
			}

			// Each plugin styles itself from its own variable, and nothing sets those
			// on a page that belongs to none of them, so they are set here.
			$accent = self::accent();
			$roots  = [];

			foreach ( self::$plugins as $plugin ) {
				if ( ! empty( $plugin['accent_var'] ) ) {
					$roots[] = $plugin['accent_var'] . ':' . $accent;
				}
			}

			$css  = $roots ? ':root{' . implode( ';', $roots ) . ';}' : '';
			$css .= '.sb-overview{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin:20px 0 8px;}';
			$css .= '.sb-overview__card{padding:16px 18px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #85df07;border-radius:8px;}';
			$css .= '.sb-overview__card h2{margin:0 0 4px;font-size:15px;}';
			$css .= '.sb-overview__meta{margin:0 0 10px;color:#646970;font-size:12px;}';
			$css .= '.sb-overview__flag{display:inline-block;margin-left:6px;padding:1px 8px;border-radius:999px;background:#fdf8e8;border:1px solid #dba617;color:#7a5a00;font-weight:600;}';
			$css .= '.sb-overview__flag.is-notes{background:#fdf8e8;border-color:#dba617;color:#7a5a00;text-decoration:none;}';
			$css .= '.sb-overview__flag.is-notes:hover{background:#fcf0d4;color:#7a5a00;}';
			$css .= '.sb-overview__heading{scroll-margin-top:60px;}';
			$css .= '.sb-overview__pages{margin:0;font-size:13px;}';
			$css .= '.sb-overview__heading{margin:0 0 16px;padding:0 0 14px;border-bottom:1px solid #f0f0f1;font-size:16px;}';
			$css .= '.sb-overview__header{margin:16px 0 0;padding:22px 28px;background:#1d2327;border-radius:8px;}';
			$css .= '.sb-overview__brand{display:flex;align-items:center;gap:16px;}';
			$css .= '.sb-overview__logo{display:block;width:203px;height:auto;}';
			$css .= '.wrap .sb-overview__title{margin:0;padding:0 0 0 16px;border-left:1px solid rgba(255,255,255,0.2);color:#fff;font-size:20px;font-weight:600;line-height:28px;}';
			$css .= '.sb-overview__intro{max-width:70ch;margin:10px 0 0;color:#c3c4c7;font-size:13px;}';
			$css .= '.sb-overview-wrap .notice{margin-left:0;margin-right:0;}';

			wp_add_inline_style( 'common', $css );
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

		/** The accent from the admin colour scheme, as the plugin pages use. */
		private static function accent() {
			global $_wp_admin_css_colors;

			$scheme = get_user_option( 'admin_color' );
			$colors = ( $scheme && isset( $_wp_admin_css_colors[ $scheme ]->colors ) ) ? (array) $_wp_admin_css_colors[ $scheme ]->colors : [];
			$colors = array_values( array_filter( $colors, 'sanitize_hex_color' ) );

			if ( $scheme && ! empty( $_wp_admin_css_colors[ $scheme ]->icon_colors['focus'] ) ) {
				$focus = sanitize_hex_color( $_wp_admin_css_colors[ $scheme ]->icon_colors['focus'] );

				if ( $focus && self::saturation( $focus ) >= 0.6 ) {
					return $focus;
				}
			}

			if ( count( $colors ) < 2 ) {
				return '#2271b1';
			}

			$pair = array_slice( $colors, -2 );

			return self::saturation( $pair[1] ) > self::saturation( $pair[0] ) + 0.15 ? $pair[1] : $pair[0];
		}
		/** Where the notes count jumps to. */
		private static function anchor( $id ) {
			return 'sb-plugin-' . sanitize_key( $id );
		}

		/** What the update system says about a plugin. */
		private static function pending( $file ) {
			$state = get_site_transient( 'update_plugins' );

			return ( $state && ! empty( $state->response[ $file ]->new_version ) ) ? $state->response[ $file ]->new_version : '';
		}

		public static function render() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$plugins = self::$plugins;

			$logo = '';

			foreach ( $plugins as $plugin ) {
				if ( ! empty( $plugin['logo'] ) ) {
					$logo = $plugin['logo'];

					break;
				}
			}

			echo '<div class="wrap sb-overview-wrap">';
			echo '<div class="sb-overview__header"><div class="sb-overview__brand">';

			if ( $logo !== '' ) {
				echo '<img class="sb-overview__logo" src="' . esc_url( $logo ) . '" alt="SocialBUMP" width="203" height="28">';
			}

			echo '<h1 class="sb-overview__title">' . esc_html__( 'Hub', 'socialbump' ) . '</h1></div>';
			echo '<p class="sb-overview__intro">' . esc_html__( 'Every SocialBUMP plugin on this site, and one place to publish them from.', 'socialbump' ) . '</p>';
			echo '</div><hr class="wp-header-end">';

			echo '<div class="sb-overview">';

			foreach ( $plugins as $plugin ) {
				$waiting = self::pending( $plugin['file'] );

				echo '<div class="sb-overview__card">';
				echo '<h2>' . esc_html( $plugin['name'] ) . '</h2>';

				echo '<p class="sb-overview__meta">v' . esc_html( $plugin['version'] );

				if ( $waiting !== '' ) {
					/* translators: %s: version number */
					echo ' <span class="sb-overview__flag">' . esc_html( sprintf( __( 'v%s available', 'socialbump' ), $waiting ) ) . '</span>';
				}

				if ( (int) $plugin['notes'] > 0 ) {
					/* translators: %s: number of notes */
					$label = esc_html( sprintf( _n( '%s change to publish', '%s changes to publish', (int) $plugin['notes'], 'socialbump' ), number_format_i18n( $plugin['notes'] ) ) );

					echo ' <a class="sb-overview__flag is-notes" href="#' . esc_attr( self::anchor( $plugin['id'] ) ) . '">' . $label . '</a>';
				}

				echo '</p>';

				if ( $plugin['pages'] ) {
					echo '<p class="sb-overview__pages">';

					$links = [];

					foreach ( $plugin['pages'] as $slug => $title ) {
						$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $title ) . '</a>';
					}

					echo implode( ' &middot; ', $links ) . '</p>';
				}

				echo '</div>';
			}

			echo '</div>';

			// Publishing, one panel per plugin, so all three go out from here.
			foreach ( $plugins as $plugin ) {
				if ( ! $plugin['release'] || ! is_callable( $plugin['release'] ) ) {
					continue;
				}

				ob_start();
				call_user_func( $plugin['release'] );
				$panel = (string) ob_get_clean();

				// Slide the name in just inside the panel, so it reads as its heading.
				$at = strpos( $panel, '>' );
				$name = '<h2 class="sb-overview__heading" id="' . esc_attr( self::anchor( $plugin['id'] ) ) . '">' . esc_html( $plugin['name'] ) . '</h2>';

				if ( $at !== false ) {
					$panel = substr( $panel, 0, $at + 1 ) . $name . substr( $panel, $at + 1 );
				} else {
					$panel = $name . $panel;
				}

				echo $panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '</div>';
		}
	}
}
