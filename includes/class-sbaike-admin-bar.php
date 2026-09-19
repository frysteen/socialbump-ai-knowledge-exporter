<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO for AI's own item in the admin bar.
 *
 * This plugin stands on its own and shares no code with the other SocialBUMP
 * plugins, so it draws its own item rather than joining a combined one. The
 * file began as a copy of the shared SocialBUMP bar class, cut down to the one
 * plugin it now serves, and it may go on borrowing from that plugin as it
 * changes. A copy that drifts is a cosmetic problem. A shared file that drifts
 * has taken client sites down.
 *
 * The dot carries the status: green when there is nothing to do, amber when
 * something wants attention, which here means posts waiting to be rebuilt or a
 * plugin update ready to install.
 */
class SBAIKE_Admin_Bar {

	const NODE = 'sbaike-bar';

	/** What was registered this request. */
	private static $plugin = null;

	private static $booted = false;

	/**
	 * Register what the bar should show.
	 *
	 * Expects: label, href, items. Optionally attention (bool),
	 * attention_title, current (bool) and actions, which are drawn above the
	 * pages for things like a rebuild button.
	 */
	public static function register( array $plugin ) {
		if ( empty( $plugin['label'] ) ) {
			return;
		}

		self::$plugin = wp_parse_args(
			$plugin,
			[
				'href'            => '',
				'items'           => [],
				'actions'         => [],
				'attention'       => false,
				'attention_title' => '',
				'current'         => false,
			]
		);

		self::boot();
	}

	/** Draw once, after the plugin has had its say. */
	private static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'admin_bar_menu', [ __CLASS__, 'render' ], 200 );

		/**
		 * Printed in the footer as well as the head.
		 *
		 * Registration happens while the bar is being built, which in the admin is
		 * after the head has already gone out, so a head only style would never
		 * appear. Printing in both places is guaranteed either way.
		 */
		add_action( 'admin_head', [ __CLASS__, 'styles' ] );
		add_action( 'wp_head', [ __CLASS__, 'styles' ] );
		add_action( 'admin_footer', [ __CLASS__, 'styles' ] );
		add_action( 'wp_footer', [ __CLASS__, 'styles' ] );
	}

	/** A coloured dot for the menu title. */
	private static function dot( $attention ) {
		$colour = $attention ? '#d97706' : '#46b450';

		return '<span class="sbaike-bar-dot" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' . $colour . ';margin-right:7px;vertical-align:middle;"></span>';
	}

	/** Draw the item, its actions and its pages. */
	public static function render( $bar ) {
		// WordPress only fires this hook when the bar is being drawn.
		if ( ! self::$plugin ) {
			return;
		}

		$plugin = self::$plugin;
		$id     = self::NODE;
		$tip    = ! empty( $plugin['attention'] ) && $plugin['attention_title'] !== '' ? $plugin['attention_title'] : '';

		$node = [
			'id'    => $id,
			'title' => self::dot( ! empty( $plugin['attention'] ) ) . $plugin['label'],
			'href'  => $plugin['href'],
			'meta'  => [ 'class' => self::class_for( ! empty( $plugin['current'] ) ) ],
		];

		if ( $tip !== '' ) {
			$node['meta']['title'] = $tip;
		}

		$bar->add_node( $node );

		// Actions first, since they are the things you came to press.
		foreach ( (array) $plugin['actions'] as $i => $action ) {
			$bar->add_node(
				[
					'id'     => $id . '-action-' . $i,
					'parent' => $id,
					'title'  => $action['title'],
					'href'   => isset( $action['href'] ) ? $action['href'] : false,
					'meta'   => isset( $action['meta'] ) ? (array) $action['meta'] : [],
				]
			);
		}

		foreach ( (array) $plugin['items'] as $i => $item ) {
			$bar->add_node(
				[
					'id'     => $id . '-page-' . $i,
					'parent' => $id,
					'title'  => esc_html( $item['title'] ) . self::count( $item ),
					'href'   => $item['href'],
					'meta'   => [ 'class' => trim( self::class_for( ! empty( $item['current'] ) ) . ( ! empty( $item['attention'] ) ? ' sbaike-bar-pending' : '' ) ) ],
				]
			);
		}
	}

	/** A small count beside a page that is waiting on you. */
	private static function count( $item ) {
		$number = isset( $item['count'] ) ? (int) $item['count'] : 0;

		if ( $number < 1 ) {
			return '';
		}

		return '<span class="sbaike-bar-count">' . esc_html( number_format_i18n( $number ) ) . '</span>';
	}

	private static function class_for( $current ) {
		return $current ? 'sbaike-bar-current' : '';
	}

	/**
	 * The highlight for the page you are on, and the action wording.
	 *
	 * Printed rather than enqueued, because the bar shows on the front end too.
	 */
	public static function styles() {
		static $printed = false;

		if ( $printed || ! is_admin_bar_showing() ) {
			return;
		}

		$printed = true;

		// Bold rather than coloured: an admin colour scheme accent can read badly
		// against the dark bar, and this has to look right in all of them.
		$css = '#wpadminbar .sbaike-bar-current > .ab-item{color:#fff !important;font-weight:600;}';

		// An action with nothing to do reads as such; one with work waiting stands out.
		// No link means WordPress draws an empty item, and it colours both on hover.
		$css .= '#wpadminbar .sbaike-bar-action.is-idle > .ab-item,#wpadminbar .sbaike-bar-action.is-idle > .ab-empty-item,#wpadminbar .sbaike-bar-action.is-idle:hover > .ab-item,#wpadminbar .sbaike-bar-action.is-idle:hover > .ab-empty-item,#wpadminbar .sbaike-bar-action.is-idle > .ab-item:focus{color:#787c82 !important;opacity:0.65;cursor:default;pointer-events:none;}';
		$css .= '#wpadminbar .sbaike-bar-action:not(.is-idle) > .ab-item,#wpadminbar .sbaike-bar-action:not(.is-idle):hover > .ab-item,#wpadminbar .sbaike-bar-action:not(.is-idle) > .ab-item:focus{color:#f0b849 !important;font-weight:600;}';

		// A page with something waiting on it, such as changes to publish.
		$css .= '#wpadminbar .sbaike-bar-pending > .ab-item,#wpadminbar .sbaike-bar-pending:hover > .ab-item,#wpadminbar .sbaike-bar-pending > .ab-item:focus{color:#f0b849 !important;font-weight:600;}';
		$css .= '#wpadminbar .sbaike-bar-count{display:inline-block;min-width:17px;height:17px;margin-left:7px;padding:0 4px;border-radius:9px;background:#f0b849;color:#1d2327;font-size:11px;font-weight:700;line-height:17px;text-align:center;vertical-align:1px;}';

		echo '<style>' . $css . '</style>';
	}
}