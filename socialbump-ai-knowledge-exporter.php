<?php
/**
 * Plugin Name: SocialBUMP SEO for AI
 * Plugin URI:  https://socialbump.com.au
 * Description: Generates AI-friendly llms.txt knowledge exports from WordPress content, custom fields and supported page builders.
 * Version:     1.0.5
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      SocialBUMP
 * Author URI:  https://socialbump.com.au
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: socialbump-ai-knowledge-exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SBAIKE_VERSION', '1.0.5' );
define( 'SBAIKE_FILE', __FILE__ );
define( 'SBAIKE_PATH', plugin_dir_path( __FILE__ ) );
define( 'SBAIKE_URL', plugin_dir_url( __FILE__ ) );
define( 'SBAIKE_SLUG', 'socialbump-ai-knowledge-exporter' );
define( 'SBAIKE_GITHUB_REPO', 'frysteen/socialbump-ai-knowledge-exporter' );
define( 'SBAIKE_HUB_HOST', 'bricks.socialbump.com.au' );

/**
 * Updates come from GitHub releases, the same as the other SocialBUMP plugins.
 */
function sbaike_updater() {
	$loader = SBAIKE_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

	if ( ! is_readable( $loader ) ) {
		return;
	}

	require_once $loader;

	if ( ! class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		return;
	}

	try {
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/' . SBAIKE_GITHUB_REPO . '/',
			SBAIKE_FILE,
			SBAIKE_SLUG
		);

		$checker->getVcsApi()->enableReleaseAssets( '/^socialbump-ai-knowledge-exporter\\.zip$/', 2 );

		$GLOBALS['sbaike_update_checker'] = $checker;

		// Run last, so our icons survive anything else filtering the plugin info.
		remove_filter( 'plugins_api', [ $checker, 'injectInfo' ], 20 );
		add_filter( 'plugins_api', [ $checker, 'injectInfo' ], 999, 3 );

		add_filter(
			'puc_request_info_result-' . SBAIKE_SLUG,
			function ( $info ) {
				if ( is_object( $info ) ) {
					$info->icons = [
						'1x'      => SBAIKE_URL . 'assets/img/icon-128x128.png',
						'2x'      => SBAIKE_URL . 'assets/img/icon-256x256.png',
						'default' => SBAIKE_URL . 'assets/img/icon-256x256.png',
					];
				}

				return $info;
			}
		);
	} catch ( \Throwable $e ) {
		// The updater must never take down the site.
	}
}
sbaike_updater();

/** The site this plugin is developed and released from. */
function sbaike_is_hub() {
	if ( defined( 'SBAIKE_IS_HUB' ) ) {
		return (bool) SBAIKE_IS_HUB;
	}

	return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === SBAIKE_HUB_HOST;
}

/**
 * Note a change for the next release.
 *
 * Anything logged here fills in the notes box on the Publishing page, and the
 * list is emptied once a release goes out.
 */
function sbaike_log_change( $text ) {
	$text = trim( wp_strip_all_tags( (string) $text ) );

	if ( $text === '' ) {
		return;
	}

	$list = (array) get_option( 'sbaike_pending_changes', [] );

	if ( in_array( $text, $list, true ) ) {
		return;
	}

	$list[] = $text;

	update_option( 'sbaike_pending_changes', array_slice( $list, -50 ), false );
}


/**
 * Keep the WP CodeBox copy of this code out of the way.
 *
 * The plugin is the same code as the snippets it came from, so both running at
 * once is a fatal error: the same classes and functions would be declared twice.
 * Guarding the declarations does not help, because PHP declares them while it
 * compiles the file, before any guard could run.
 *
 * So the snippets are switched off instead. This runs while the plugin file is
 * being read, which is before WP CodeBox gets to run anything, and whatever was
 * switched off is reported in the admin so it is never a surprise.
 */
function sbaike_stand_down_snippets() {
	global $wpdb;

	// Looked at once an hour rather than on every request.
	if ( get_transient( 'sbaike_snippets_checked' ) ) {
		return;
	}

	$table = $wpdb->prefix . 'wpcb_snippets';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		set_transient( 'sbaike_snippets_checked', 1, DAY_IN_SECONDS );

		return;
	}

	$clashing = $wpdb->get_results( "SELECT id, title FROM {$table} WHERE enabled = 1 AND code LIKE '%SocialBump_AI_Knowledge_Exporter%'" ); // phpcs:ignore WordPress.DB

	set_transient( 'sbaike_snippets_checked', 1, HOUR_IN_SECONDS );

	if ( ! $clashing ) {
		return;
	}

	$names = [];

	foreach ( $clashing as $snippet ) {
		$wpdb->update( $table, [ 'enabled' => 0 ], [ 'id' => (int) $snippet->id ] ); // phpcs:ignore WordPress.DB

		$names[] = $snippet->title;
	}

	update_option( 'sbaike_disabled_snippets', $names, false );
}
sbaike_stand_down_snippets();

/** Say what was switched off, once, so it is never a surprise. */
function sbaike_snippets_notice() {
	$names = get_option( 'sbaike_disabled_snippets' );

	if ( ! $names || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	delete_option( 'sbaike_disabled_snippets' );

	echo '<div class="notice notice-warning is-dismissible"><p><strong>';
	esc_html_e( 'SEO for AI is now running as a plugin.', 'socialbump-ai-knowledge-exporter' );
	echo '</strong> ';
	esc_html_e( 'These WP CodeBox snippets were switched off, because the same code cannot run twice:', 'socialbump-ai-knowledge-exporter' );
	echo ' ' . esc_html( implode( ', ', (array) $names ) ) . '. ';
	esc_html_e( 'Your settings and cached content carry over untouched.', 'socialbump-ai-knowledge-exporter' );
	echo '</p></div>';
}
add_action( 'admin_notices', 'sbaike_snippets_notice' );

require_once SBAIKE_PATH . 'includes/class-socialbump-ai-knowledge-exporter.php';

/**
 * Load every extension in the extensions folder.
 *
 * Support for a builder or plugin is a file of its own that registers itself
 * with the core, so adding one is a matter of dropping the file in. The
 * renderer goes first because the builder extensions build on what it sets up,
 * and a fatal inside one extension is caught so it cannot take the site down.
 */
function sbaike_load_extensions() {
	$files = glob( SBAIKE_PATH . 'extensions/*.php' );

	if ( ! $files ) {
		return;
	}

	sort( $files );

	usort(
		$files,
		function ( $a, $b ) {
			$first = 'rendered-content-renderer.php';

			return ( basename( $a ) === $first ? 0 : 1 ) <=> ( basename( $b ) === $first ? 0 : 1 );
		}
	);

	foreach ( $files as $file ) {
		try {
			require_once $file;
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SBAIKE: extension ' . basename( $file ) . ' failed to load: ' . $e->getMessage() );
			}
		}
	}
}
sbaike_load_extensions();

require_once SBAIKE_PATH . 'includes/class-socialbump-admin-bar.php';
require_once SBAIKE_PATH . 'includes/class-socialbump-overview.php';
require_once SBAIKE_PATH . 'includes/class-sbaike-admin.php';
require_once SBAIKE_PATH . 'includes/class-sbaike-updates.php';
require_once SBAIKE_PATH . 'includes/class-sbaike-transfer.php';
require_once SBAIKE_PATH . 'includes/class-sbaike-rebuild.php';

function sbaike_boot() {
	SBAIKE_Admin::instance()->boot();
	SBAIKE_Updates::boot();
	SBAIKE_Transfer::boot();
	SBAIKE_Rebuild::boot();

	if ( sbaike_is_hub() ) {
		require_once SBAIKE_PATH . 'includes/class-sbaike-release.php';
		SBAIKE_Release::instance()->boot();

		require_once SBAIKE_PATH . 'includes/class-sbaike-docs.php';
		SBAIKE_Docs::boot();
	}
}
add_action( 'plugins_loaded', 'sbaike_boot', 10 );

/** A Settings link on the plugins screen, like the other SocialBUMP plugins. */
function sbaike_action_links( $links ) {
	$url = admin_url( 'admin.php?page=' . SBAIKE_Admin::PAGE_SLUG );

	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'socialbump-ai-knowledge-exporter' ) . '</a>' );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sbaike_action_links' );

/**
 * Make sure the new files are the ones that run.
 *
 * Updating a plugin swaps its files out mid request. If you were on one of its
 * own pages at the time, the page you land on afterwards can still be running
 * the old code, or code caught halfway through being replaced, so its menus
 * never register and the plugin appears to vanish until you go somewhere else.
 *
 * Clearing the compiled copies as soon as the update finishes means the next
 * request reads what is actually on disk.
 */
function sbaike_forget_compiled( $upgrader, $extra ) {
	if ( ! function_exists( 'opcache_invalidate' ) ) {
		return;
	}

	$ours = plugin_basename( SBAIKE_FILE );
	$mine = isset( $extra['plugins'] ) && in_array( $ours, (array) $extra['plugins'], true );

	// A single update reports the plugin on its own rather than in a list.
	if ( ! $mine && isset( $extra['plugin'] ) && $extra['plugin'] === $ours ) {
		$mine = true;
	}

	if ( ! $mine ) {
		return;
	}

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SBAIKE_PATH, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $files as $file ) {
		if ( $file->getExtension() === 'php' ) {
			@opcache_invalidate( $file->getPathname(), true );
		}
	}
}
add_action( 'upgrader_process_complete', 'sbaike_forget_compiled', 10, 2 );

/**
 * Nothing about publishing belongs on a site that is not the hub.
 *
 * This site is the blueprint new sites are built from, so whatever sits in its
 * database travels with every copy. A GitHub token has no business on a client
 * site, and the release notes waiting to be published are only noise there.
 */
function sbaike_tidy_away_hub_data() {
	if ( sbaike_is_hub() ) {
		return;
	}

	foreach ( [ 'sbaike_github_token', 'sbaike_pending_changes', 'sbaike_latest_release' ] as $option ) {
		if ( get_option( $option ) !== false ) {
			delete_option( $option );
		}
	}
}
add_action( 'admin_init', 'sbaike_tidy_away_hub_data' );
