<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells the hub which SocialBUMP plugins this site has.
 *
 * Shared by every SocialBUMP plugin, the same file in each, so whichever loads
 * first defines the class and the rest skip it. It reports all of them at once,
 * active or not, since a plugin that is switched off cannot speak for itself:
 * site address and name, each plugin's version and whether it is active, and
 * the WordPress and PHP versions. Nothing else leaves the site.
 *
 * It sends when something changed since the last report (a plugin activated,
 * deactivated or updated), or once a day otherwise: on an admin page load, and
 * from a daily cron event for sites nobody logs into. The request does not
 * wait for an answer, so it never slows a page down, and a failed one is
 * simply tried again next time. On the hub itself the report is recorded
 * directly rather than sent over HTTP.
 *
 * Loaded from each plugin's main file at the top level rather than on
 * plugins_loaded, so it is already listening when a plugin is activated.
 */
if ( ! class_exists( 'SocialBUMP_Reporter' ) ) {

	class SocialBUMP_Reporter {

		const VERSION  = '1.0.1';
		const ENDPOINT = 'https://bricks.socialbump.com.au/wp-json/sb-tweaks/v1/checkin';
		const KEY      = 'sbump-installs-2026-4c8e1f7a93d2';
		const HUB_HOST = 'bricks.socialbump.com.au';
		const LAST     = 'socialbump_reporter_last';
		const CRON     = 'socialbump_reporter_daily';

		/** Every SocialBUMP plugin the hub keeps track of, by folder. */
		const PLUGINS = [
			'socialbump-site-kit',
			'socialbump-bricks-tweaks',
			'socialbump-ai-knowledge-exporter',
			'socialbump-tweaks',
		];

		private static $booted = false;

		public static function boot() {
			if ( self::$booted ) {
				return;
			}

			self::$booted = true;

			add_action( 'init', [ __CLASS__, 'schedule' ] );
			add_action( self::CRON, [ __CLASS__, 'send' ] );
			add_action( 'admin_init', [ __CLASS__, 'maybe_send' ] );
			add_action( 'activated_plugin', [ __CLASS__, 'changed' ] );
			add_action( 'deactivated_plugin', [ __CLASS__, 'deactivated' ] );
			add_action( 'upgrader_process_complete', [ __CLASS__, 'forget' ], 30 );
		}

		public static function schedule() {
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
			}
		}

		/** Each tracked plugin that is installed: version and whether active. */
		public static function plugins() {
			$active  = (array) get_option( 'active_plugins', [] );
			$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : [];
			$found   = [];

			foreach ( self::PLUGINS as $slug ) {
				$file = $slug . '/' . $slug . '.php';
				$path = WP_PLUGIN_DIR . '/' . $file;

				if ( ! file_exists( $path ) ) {
					continue;
				}

				$data = get_file_data( $path, [ 'version' => 'Version' ] );

				$found[ $slug ] = [
					'version' => (string) $data['version'],
					'active'  => in_array( $file, $active, true ) || in_array( $file, $network, true ),
				];
			}

			return $found;
		}

		public static function payload() {
			global $wp_version;

			return [
				'url'      => home_url( '/' ),
				'name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'wp'       => (string) $wp_version,
				'php'      => PHP_VERSION,
				'reporter' => self::VERSION,
				'plugins'  => self::plugins(),
			];
		}

		/** Send when anything changed, or once a day. Admin page loads only. */
		public static function maybe_send() {
			if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
				return;
			}

			$last = (array) get_option( self::LAST, [] );
			$hash = md5( wp_json_encode( self::plugins() ) );

			if ( ( $last['hash'] ?? '' ) === $hash && time() - (int) ( $last['time'] ?? 0 ) < DAY_IN_SECONDS ) {
				return;
			}

			self::send();
		}

		/** One of ours was switched on or off: report straight away. */
		public static function changed( $plugin ) {
			if ( in_array( dirname( (string) $plugin ), self::PLUGINS, true ) ) {
				self::send();
			}
		}

		/**
		 * One of ours was switched off: report it as off.
		 *
		 * WordPress fires deactivated_plugin before it saves the new list of
		 * active plugins, so reading that list here still shows the plugin as
		 * active. The plugin being switched off is marked inactive by hand.
		 */
		public static function deactivated( $plugin ) {
			$slug = dirname( (string) $plugin );

			if ( in_array( $slug, self::PLUGINS, true ) ) {
				self::send( [ $slug => false ] );
			}
		}

		/** After an update, the next admin page load reports the new version. */
		public static function forget() {
			delete_option( self::LAST );
		}

		/**
		 * Report now. $active overrides a plugin's active state, for the moment
		 * WordPress has switched one off but not yet saved it.
		 */
		public static function send( $active = [] ) {
			$payload = self::payload();

			foreach ( (array) $active as $slug => $state ) {
				if ( isset( $payload['plugins'][ $slug ] ) ) {
					$payload['plugins'][ $slug ]['active'] = (bool) $state;
				}
			}


			update_option( self::LAST, [ 'time' => time(), 'hash' => md5( wp_json_encode( $payload['plugins'] ) ) ], false );

			// The hub records its own report without a trip over HTTP.
			if ( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === self::HUB_HOST && function_exists( 'sb_tweaks_installs_record' ) ) {
				sb_tweaks_installs_record( $payload );

				return;
			}

			wp_remote_post(
				self::ENDPOINT,
				[
					'timeout'  => 3,
					'blocking' => false,
					'headers'  => [
						'Content-Type' => 'application/json',
						'X-SB-Key'     => self::KEY,
					],
					'body'     => wp_json_encode( $payload ),
				]
			);
		}
	}

	SocialBUMP_Reporter::boot();
}
