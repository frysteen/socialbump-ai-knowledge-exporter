<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBAIKE_Transfer {
    const NOTICE = 'sbaike_transfer_notice_';
    const SETTINGS_OPTION = 'socialbump_ai_knowledge_exporter_settings';

    public static function boot() {
        add_action( 'admin_post_sbaike_export_settings', [ __CLASS__, 'export' ] );
        add_action( 'admin_post_sbaike_import_settings', [ __CLASS__, 'import' ] );
    }

    private static function guard( $action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'socialbump-ai-knowledge-exporter' ) );
        }
        check_admin_referer( $action );
    }

    private static function back( $type, $message ) {
        set_transient( self::NOTICE . get_current_user_id(), [ 'type' => $type, 'message' => $message ], 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . SBAIKE_Admin::PAGE_SLUG . '-updates' ) );
        exit;
    }

    public static function export() {
        self::guard( 'sbaike_export_settings' );
        $payload = [
            'plugin'   => SBAIKE_SLUG,
            'version'  => SBAIKE_VERSION,
            'site'     => home_url(),
            'date'     => gmdate( 'c' ),
            'settings' => get_option( self::SETTINGS_OPTION, [] ),
        ];
        $host = str_replace( '.', '-', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        $name = 'aike-settings-' . $host . '-' . gmdate( 'Y-m-d' ) . '.json';
        $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
        header( 'Content-Length: ' . strlen( $json ) );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function import() {
        self::guard( 'sbaike_import_settings' );
        if ( empty( $_FILES['sbaike_settings_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['sbaike_settings_file']['tmp_name'] ) ) {
            self::back( 'error', __( 'Choose a settings file first.', 'socialbump-ai-knowledge-exporter' ) );
        }
        $data = json_decode( (string) file_get_contents( $_FILES['sbaike_settings_file']['tmp_name'] ), true );
        if ( ! is_array( $data ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
            self::back( 'error', __( 'That file is not an AIKE settings export.', 'socialbump-ai-knowledge-exporter' ) );
        }
        if ( ! empty( $data['plugin'] ) && $data['plugin'] !== SBAIKE_SLUG ) {
            self::back( 'error', __( 'That file belongs to a different plugin.', 'socialbump-ai-knowledge-exporter' ) );
        }
        update_option( self::SETTINGS_OPTION, $data['settings'] );
        self::back( 'success', __( 'AIKE settings imported.', 'socialbump-ai-knowledge-exporter' ) );
    }

	/** The export and import panel, shown on the Updates page. */
	public static function render() {
		$notice = get_transient( self::NOTICE . get_current_user_id() );

		if ( $notice ) {
			delete_transient( self::NOTICE . get_current_user_id() );
		}

		$post = esc_url( admin_url( 'admin-post.php' ) );

		echo '<section class="sbaike-section">';
		echo '<div class="sbaike-section__head"><h2>' . esc_html__( 'Settings', 'socialbump-ai-knowledge-exporter' ) . '</h2>';
		echo '<p>' . esc_html__( 'Take this site setup to another site. The generated files, the caches and the GitHub token are not included.', 'socialbump-ai-knowledge-exporter' ) . '</p></div>';

		if ( is_array( $notice ) ) {
			echo '<div class="notice notice-' . ( $notice['type'] === 'success' ? 'success' : 'error' ) . ' inline"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		echo '<div class="sbaike-grid">';

		echo '<div class="sbaike-card"><div class="sbaike-card__head"><h3>' . esc_html__( 'Export', 'socialbump-ai-knowledge-exporter' ) . '</h3></div>';
		echo '<p class="sbaike-card__desc">' . esc_html__( 'Download what is exported, how it is built and every module setting as a JSON file.', 'socialbump-ai-knowledge-exporter' ) . '</p>';
		echo '<form method="post" action="' . $post . '">';
		echo '<input type="hidden" name="action" value="sbaike_export_settings">';
		wp_nonce_field( 'sbaike_export_settings' );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Download settings', 'socialbump-ai-knowledge-exporter' ) . '</button></p>';
		echo '</form></div>';

		echo '<div class="sbaike-card"><div class="sbaike-card__head"><h3>' . esc_html__( 'Import', 'socialbump-ai-knowledge-exporter' ) . '</h3></div>';
		echo '<p class="sbaike-card__desc">' . esc_html__( 'Replaces the settings on this site with the ones in the file. There is no undo, and the files are rebuilt from the new settings.', 'socialbump-ai-knowledge-exporter' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . $post . '">';
		echo '<input type="hidden" name="action" value="sbaike_import_settings">';
		wp_nonce_field( 'sbaike_import_settings' );
		echo '<p><input type="file" name="sbaike_settings_file" accept="application/json,.json" required></p>';
		echo '<p><button type="submit" class="button" onclick="return confirm(&#39;Replace the exporter settings on this site?&#39;);">' . esc_html__( 'Import settings', 'socialbump-ai-knowledge-exporter' ) . '</button></p>';
		echo '</form></div>';

		echo '</div></section>';
	}
}
