<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates panel on the settings page: the version running, whether a newer one
 * is out, and a button to check GitHub now instead of waiting for WordPress's
 * twice daily check.
 */
class SBAIKE_Updates {

	const NOTICE = 'sbaike_update_notice_';

	public static function boot() {
		add_action( 'admin_post_sbaike_check_updates', [ __CLASS__, 'check' ] );
	}

	private static function checker() {
		return isset( $GLOBALS['sbaike_update_checker'] ) ? $GLOBALS['sbaike_update_checker'] : null;
	}

	public static function check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'socialbump-ai-knowledge-exporter' ) );
		}

		check_admin_referer( 'sbaike_check_updates' );

		$checker = self::checker();
		$type    = 'error';
		$message = __( 'The update checker is not running on this site.', 'socialbump-ai-knowledge-exporter' );

		if ( $checker ) {
			delete_transient( 'sbaike_latest_release' );

			try {
				$update = $checker->checkForUpdates();
				$type   = 'success';

				if ( $update && ! empty( $update->version ) && version_compare( $update->version, SBAIKE_VERSION, '>' ) ) {
					/* translators: %s: version number */
					$message = sprintf( __( 'Version %s is available.', 'socialbump-ai-knowledge-exporter' ), $update->version );
				} else {
					$message = __( 'You are running the latest version.', 'socialbump-ai-knowledge-exporter' );
				}
			} catch ( \Throwable $e ) {
				$message = __( 'Could not reach GitHub. Try again shortly.', 'socialbump-ai-knowledge-exporter' );
			}
		}

		set_transient(
			self::NOTICE . get_current_user_id(),
			[
				'type'    => $type,
				'message' => $message,
			],
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . SBAIKE_Admin::PAGE_SLUG . '-updates' ) );
		exit;
	}

	public static function render() {
		$file    = plugin_basename( SBAIKE_FILE );
		$state   = get_site_transient( 'update_plugins' );
		$pending = ( $state && ! empty( $state->response[ $file ]->new_version ) ) ? $state->response[ $file ]->new_version : '';
		$checked = ( $state && ! empty( $state->last_checked ) ) ? (int) $state->last_checked : 0;
		$notice  = get_transient( self::NOTICE . get_current_user_id() );
		$repo    = 'https://github.com/' . SBAIKE_GITHUB_REPO . '/releases';

		if ( $notice ) {
			delete_transient( self::NOTICE . get_current_user_id() );
		}
		?>
		<section class="sbaike-section" id="sbaike-section-updates">
			<div class="sbaike-section__head">
				<h2><?php esc_html_e( 'Updates', 'socialbump-ai-knowledge-exporter' ); ?></h2>
				<p><?php esc_html_e( 'Delivered from the hub site through GitHub releases. WordPress checks twice a day on its own.', 'socialbump-ai-knowledge-exporter' ); ?></p>
			</div>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo $notice['type'] === 'success' ? 'success' : 'error'; ?> inline">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="sbaike-updates">
				<p class="sbaike-updates__status">
					<?php if ( $pending ) : ?>
						<span class="sbaike-updates__badge is-available"><?php echo esc_html( 'v' . $pending . ' ' . __( 'available', 'socialbump-ai-knowledge-exporter' ) ); ?></span>
					<?php else : ?>
						<span class="sbaike-updates__badge is-current"><?php esc_html_e( 'Up to date', 'socialbump-ai-knowledge-exporter' ); ?></span>
					<?php endif; ?>

					<span class="sbaike-updates__meta">
						<?php
						/* translators: %s: version number */
						printf( esc_html__( 'Running v%s.', 'socialbump-ai-knowledge-exporter' ), esc_html( SBAIKE_VERSION ) );

						if ( $checked ) {
							echo ' ';
							/* translators: %s: time since the last check, e.g. 3 hours */
							printf( esc_html__( 'Checked %s ago.', 'socialbump-ai-knowledge-exporter' ), esc_html( human_time_diff( $checked ) ) );
						}
						?>
					</span>
				</p>

				<div class="sbaike-updates__actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="sbaike_check_updates">
						<?php wp_nonce_field( 'sbaike_check_updates' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Check for updates', 'socialbump-ai-knowledge-exporter' ); ?></button>
					</form>

					<?php
					// The bulk path the dashboard uses, which swaps the files under maintenance
					// mode and never deactivates the plugin. The single plugin path deactivates
					// first and reactivates silently, and when that silent step fails the plugin
					// is simply left off with nothing logged. It happened.
					if ( $pending && current_user_can( 'update_plugins' ) ) :
						?>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update-core.php?action=do-plugin-upgrade&plugins=' . rawurlencode( $file ) ), 'upgrade-core' ) ); ?>"><?php esc_html_e( 'Update now', 'socialbump-ai-knowledge-exporter' ); ?></a>
					<?php endif; ?>

					<a class="sbaike-updates__link" href="<?php echo esc_url( $repo ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'All releases', 'socialbump-ai-knowledge-exporter' ); ?></a>
				</div>
			</div>
		</section>
		<?php
	}
}