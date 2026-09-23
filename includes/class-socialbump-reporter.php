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
 * Since 1.1.0 it also receives updates pushed from the hub's Installs page,
 * signed by the hub and checked here before anything is installed. Since 1.2.0
 * the hub can also install SocialBUMP Tweaks, and only that, to move a site's
 * standalone plugins into its modules. Since 1.2.1 a report waits for the hub
 * to confirm it, and the answer to a push carries the site's report, so the hub
 * never relies on a separate report arriving while it waits on the site.
 *
 * Loaded from each plugin's main file at the top level rather than on
 * plugins_loaded, so it is already listening when a plugin is activated.
 */
if ( ! class_exists( 'SocialBUMP_Reporter' ) ) {

	class SocialBUMP_Reporter {

		const VERSION  = '1.2.1';
		const ENDPOINT = 'https://plugins.socialbump.com.au/wp-json/sb-tweaks/v1/checkin';
		const KEY      = 'sbump-installs-2026-4c8e1f7a93d2';
		const HUB_HOST = 'plugins.socialbump.com.au';
		const LAST     = 'socialbump_reporter_last';

		/** Set for a while after a report did not get through, so it is not retried on every page. */
		const RETRY    = 'socialbump_reporter_retry';
		const CRON     = 'socialbump_reporter_daily';
		const OWNER    = 'frysteen';

		/** The hub's public signing key: checks updates pushed from the Installs page. */
		const PUSH_KEY = 'mUnBC/yPLSvJ0EWcrBz5ECjGD8OxJy7GFGHOXPfcaTQ=';

		/** The only plugin the hub may install on a site: the one that replaces the rest. */
		const INSTALLABLE = [ 'socialbump-tweaks' ];

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
			add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
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
				'reporter' => self::running_version(),
				'plugins'  => self::plugins(),
			];
		}

		/** Send when anything changed, or once a day. Admin page loads only. */
		public static function maybe_send() {
			if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
				return;
			}

			// A report that did not get through is tried again, but not on every
			// page load while the hub is unreachable.
			if ( get_transient( self::RETRY ) ) {
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


			// The hub records its own report without a trip over HTTP.
			if ( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === self::HUB_HOST && function_exists( 'sb_tweaks_installs_record' ) ) {
				sb_tweaks_installs_record( $payload );
				self::sent( $payload );

				return true;
			}

			/**
			 * Waits for the hub's answer. Sent without waiting, reports went
			 * missing: on some hosts the connection is dropped before an HTTPS
			 * request is fully out, and the site never knew. Only a report the
			 * hub has confirmed counts as sent, so one that did not arrive goes
			 * again on a later admin page load (after RETRY expires).
			 */
			$response = wp_remote_post(
				self::ENDPOINT,
				[
					'timeout' => 8,
					'headers' => [
						'Content-Type' => 'application/json',
						'X-SB-Key'     => self::KEY,
					],
					'body'    => wp_json_encode( $payload ),
				]
			);

			$answer = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( (int) wp_remote_retrieve_response_code( $response ) === 200 && is_array( $answer ) && ! empty( $answer['ok'] ) ) {
				self::sent( $payload );

				return true;
			}

			set_transient( self::RETRY, 1, 15 * MINUTE_IN_SECONDS );

			return false;
		}

		/** Note a report as delivered, so nothing is sent again until something changes. */
		private static function sent( array $payload ) {
			update_option( self::LAST, [ 'time' => time(), 'hash' => md5( wp_json_encode( $payload['plugins'] ) ) ], false );
			delete_transient( self::RETRY );
		}

		/**
		 * The reporter version this site runs from the next request on.
		 *
		 * WordPress loads the active plugins in order and the first copy of
		 * this file to load is the one that runs, so that copy's version is
		 * read from disk. Straight after an update the copy in memory can be
		 * older than the one now on disk, and the hub needs the new one.
		 */
		private static function running_version() {
			foreach ( (array) get_option( 'active_plugins', [] ) as $basename ) {
				$file = WP_PLUGIN_DIR . '/' . dirname( (string) $basename ) . '/includes/class-socialbump-reporter.php';

				if ( in_array( dirname( (string) $basename ), self::PLUGINS, true ) && is_readable( $file ) && preg_match( "/const VERSION\s*=\s*'([0-9.]+)'/", (string) file_get_contents( $file ), $m ) ) {
					return $m[1];
				}
			}

			return self::VERSION;
		}

		/**
		 * This site's report, handed back in the answer to a push.
		 *
		 * During a push the hub is waiting on this site, so a separate report
		 * sent now would queue behind that wait and could be lost. It goes back
		 * with the answer instead and the hub records it from there.
		 */
		private static function report() {
			if ( function_exists( 'wp_clean_plugins_cache' ) ) {
				wp_clean_plugins_cache( true );
			}

			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'active_plugins', 'options' );

			$payload = self::payload();
			self::sent( $payload );

			return $payload;
		}

		/** A push result, with the site's report added when it worked. */
		private static function with_report( array $result ) {
			if ( ! empty( $result['ok'] ) ) {
				$result['report'] = self::report();
			}

			return $result;
		}

		/**
		 * Updates pushed from the hub's Installs page.
		 *
		 * The hub signs each instruction with its private key; only the public
		 * half is here, so nobody else can make one that passes. An instruction
		 * can do exactly one thing: update one of the plugins above, from that
		 * plugin's own release file on GitHub, to a newer version. It must be
		 * addressed to this site, be under ten minutes old, and never have been
		 * used before. Anything else is refused before a byte is downloaded.
		 */
		public static function routes() {
			register_rest_route(
				'socialbump/v1',
				'/update',
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'receive' ],
					'permission_callback' => '__return_true',
				]
			);
		}

		private static function refuse( $message, $code = 400 ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => $message ], $code );
		}

		public static function receive( $request ) {
			$body = json_decode( (string) $request->get_body(), true );
			$raw  = is_array( $body ) ? base64_decode( (string) ( $body['payload'] ?? '' ), true ) : false;
			$sig  = is_array( $body ) ? base64_decode( (string) ( $body['sig'] ?? '' ), true ) : false;

			if ( $raw === false || $sig === false || strlen( $sig ) !== 64 || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				return self::refuse( 'Not a valid update instruction.' );
			}

			try {
				$signed = sodium_crypto_sign_verify_detached( $sig, $raw, base64_decode( self::PUSH_KEY ) );
			} catch ( \Throwable $e ) {
				$signed = false;
			}

			if ( ! $signed ) {
				return self::refuse( 'The signature did not match the SocialBUMP hub.', 403 );
			}

			$job  = json_decode( $raw, true );
			$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

			if ( ! is_array( $job ) || ( $job['host'] ?? '' ) !== $host ) {
				return self::refuse( 'This instruction was meant for another site.', 403 );
			}

			$expires = (int) ( $job['expires'] ?? 0 );

			if ( $expires < time() || $expires > time() + 600 ) {
				return self::refuse( 'This instruction has expired.', 403 );
			}

			$nonce = (string) ( $job['nonce'] ?? '' );

			if ( ! preg_match( '/^[a-f0-9]{32}$/', $nonce ) || get_transient( 'socialbump_push_' . $nonce ) ) {
				return self::refuse( 'This instruction has already been used.', 403 );
			}

			set_transient( 'socialbump_push_' . $nonce, 1, 15 * MINUTE_IN_SECONDS );

			$slug    = (string) ( $job['plugin'] ?? '' );
			$version = (string) ( $job['version'] ?? '' );

			if ( ! in_array( $slug, self::PLUGINS, true ) || ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
				return self::refuse( 'Only SocialBUMP plugins can be updated this way.', 403 );
			}

			$url = 'https://github.com/' . self::OWNER . '/' . $slug . '/releases/download/v' . $version . '/' . $slug . '.zip';

			if ( ( $job['url'] ?? '' ) !== $url ) {
				return self::refuse( 'The download was not the plugin\'s own GitHub release.', 403 );
			}

			$file   = $slug . '/' . $slug . '.php';
			$action = (string) ( $job['action'] ?? 'update' );

			// Since 1.2.0: install SocialBUMP Tweaks, which then takes over from the
			// standalone plugins it has modules for. Nothing else can be installed.
			if ( $action === 'install' ) {
				if ( ! in_array( $slug, self::INSTALLABLE, true ) ) {
					return self::refuse( 'Only SocialBUMP Tweaks can be installed this way.', 403 );
				}

				return new WP_REST_Response( self::with_report( self::install_new( $slug, $file, $url ) ), 200 );
			}

			if ( $action !== 'update' ) {
				return self::refuse( 'Not an instruction this site understands.', 403 );
			}

			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				return self::refuse( 'That plugin is not installed here.', 404 );
			}

			$current = (string) get_file_data( WP_PLUGIN_DIR . '/' . $file, [ 'v' => 'Version' ] )['v'];

			if ( version_compare( $current, $version, '>=' ) ) {
				return new WP_REST_Response( [ 'ok' => true, 'version' => $current, 'message' => 'Already up to date.', 'report' => self::report() ], 200 );
			}

			return new WP_REST_Response( self::with_report( self::install( $slug, $file, $url ) ), 200 );
		}

		/**
		 * Install SocialBUMP Tweaks from its GitHub release and switch it on.
		 *
		 * Then one request with it running, to admin-ajax.php so no page cache
		 * can answer instead, lets it switch off the standalone plugins it has
		 * modules for, as it does on any page load. Its modules pick up the
		 * settings those plugins saved, so nothing else needs doing. Already
		 * installed but switched off: it is simply switched on.
		 */
		private static function install_new( $slug, $file, $url ) {
			@set_time_limit( 300 );

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				if ( get_filesystem_method() !== 'direct' || ! WP_Filesystem() ) {
					return [ 'ok' => false, 'message' => 'This site asks for FTP details to install plugins, so it cannot be done from the hub.' ];
				}

				$skin     = new Automatic_Upgrader_Skin();
				$upgrader = new Plugin_Upgrader( $skin );
				$result   = $upgrader->install( $url );

				wp_clean_plugins_cache( true );

				if ( is_wp_error( $result ) || ! $result || ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
					$message = is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) );

					return [ 'ok' => false, 'message' => $message !== '' ? $message : 'The install did not complete.' ];
				}
			}

			if ( ! is_plugin_active( $file ) ) {
				$activated = activate_plugin( $file );

				if ( is_wp_error( $activated ) ) {
					return [ 'ok' => false, 'message' => 'Installed, but it could not be switched on: ' . $activated->get_error_message() ];
				}
			}

			wp_remote_post( admin_url( 'admin-ajax.php' ), [ 'timeout' => 30, 'sslverify' => false, 'body' => [ 'action' => 'sb_tweaks_handover' ] ] );

			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'active_plugins', 'options' );

			$active  = (array) get_option( 'active_plugins', [] );
			$retired = [];

			foreach ( self::PLUGINS as $other ) {
				if ( $other !== $slug && file_exists( WP_PLUGIN_DIR . '/' . $other . '/' . $other . '.php' ) && ! in_array( $other . '/' . $other . '.php', $active, true ) ) {
					$retired[] = $other;
				}
			}

			return [ 'ok' => true, 'version' => (string) get_file_data( WP_PLUGIN_DIR . '/' . $file, [ 'v' => 'Version' ] )['v'], 'retired' => $retired ];
		}

		/** WordPress's own plugin updater, run quietly, with its automatic rollback. */
		private static function install( $slug, $file, $url ) {
			@set_time_limit( 300 );

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			if ( get_filesystem_method() !== 'direct' || ! WP_Filesystem() ) {
				return [ 'ok' => false, 'message' => 'This site asks for FTP details to update plugins, so it cannot be updated from the hub.' ];
			}

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );

			$upgrader->init();
			$upgrader->upgrade_strings();

			$result = $upgrader->run(
				[
					'package'           => $url,
					'destination'       => WP_PLUGIN_DIR . '/' . $slug,
					'clear_destination' => true,
					'clear_working'     => true,
					'hook_extra'        => [
						'plugin'      => $file,
						'type'        => 'plugin',
						'action'      => 'update',
						'temp_backup' => [
							'slug' => $slug,
							'src'  => WP_PLUGIN_DIR,
							'dir'  => 'plugins',
						],
					],
				]
			);

			wp_clean_plugins_cache( true );

			if ( is_wp_error( $result ) || ! $result ) {
				$message = is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) );

				return [ 'ok' => false, 'message' => $message !== '' ? $message : 'The update did not complete.' ];
			}

			if ( function_exists( 'opcache_invalidate' ) ) {
				foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_PLUGIN_DIR . '/' . $slug, FilesystemIterator::SKIP_DOTS ) ) as $php ) {
					if ( $php->getExtension() === 'php' ) {
						@opcache_invalidate( $php->getPathname(), true );
					}
				}
			}

			return [ 'ok' => true, 'version' => (string) get_file_data( WP_PLUGIN_DIR . '/' . $file, [ 'v' => 'Version' ] )['v'] ];
		}
	}

	SocialBUMP_Reporter::boot();
}
