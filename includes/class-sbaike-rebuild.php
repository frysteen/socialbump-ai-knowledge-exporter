<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuilding in batches, with something to watch while it happens.
 *
 * Rendering a post means fetching the page over HTTP, so a site with a few
 * hundred of them cannot be rebuilt inside one request: the browser gives up,
 * or PHP does, and either way there is nothing on screen in the meantime.
 *
 * A job is a list of post IDs held in a transient. The browser asks for a few
 * at a time, each request doing a small amount of work and coming straight
 * back, then one last call assembles the files. Nothing runs long enough to be
 * cut off, and the progress is real rather than a spinner.
 *
 * The scheduled update is untouched: it still runs server side on its own.
 */
class SBAIKE_Rebuild {

	const PREFIX = 'sbaike_job_';

	/** How many posts one request renders. */
	const BATCH = 3;

	/** How long a job is kept while it is being worked through. */
	const LIFETIME = 3600;

	public static function boot() {
		add_action( 'wp_ajax_sbaike_job_start', [ __CLASS__, 'start' ] );
		add_action( 'wp_ajax_sbaike_job_step', [ __CLASS__, 'step' ] );
		add_action( 'wp_ajax_sbaike_job_finish', [ __CLASS__, 'finish' ] );
	}

	private static function core() {
		return SocialBump_AI_Knowledge_Exporter::instance();
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'sbaike_job', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'socialbump-ai-knowledge-exporter' ) ], 403 );
		}
	}

	/**
	 * Work out what a job covers.
	 *
	 * scope is one of: everything, stale, type, type-stale or post. A rebuild
	 * clears the cache for what it covers first, so every post in the list is
	 * rendered again rather than served from what is already there.
	 */
	public static function start() {
		self::guard();

		$core   = self::core();
		$scope  = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'everything';
		$type   = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$id     = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$ids    = [];
		$counts = [];

		switch ( $scope ) {
			case 'post':
				if ( $id > 0 ) {
					$core->clear_post_cache( $id );

					$ids                            = [ $id ];
					$counts[ get_post_type( $id ) ] = 1;
				}
				break;

			case 'type':
				if ( $type !== '' ) {
					$core->clear_post_type_cache( $type );

					$ids             = $core->exportable_post_ids( $type );
					$counts[ $type ] = count( $ids );
				}
				break;

			case 'type-stale':
				if ( $type !== '' ) {
					$ids             = $core->exportable_post_ids( $type, true );
					$counts[ $type ] = count( $ids );
				}
				break;

			case 'stale':
				foreach ( $core->exported_post_types() as $exported ) {
					$found = $core->exportable_post_ids( $exported, true );

					if ( ! $found ) {
						continue;
					}

					$ids                = array_merge( $ids, $found );
					$counts[ $exported ] = count( $found );
				}
				break;

			default:
				$core->clear_all_post_caches();

				foreach ( $core->exported_post_types() as $exported ) {
					$found = $core->exportable_post_ids( $exported );

					if ( ! $found ) {
						continue;
					}

					$ids                 = array_merge( $ids, $found );
					$counts[ $exported ] = count( $found );
				}
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$job = wp_generate_password( 12, false, false );

		set_transient( self::PREFIX . $job, $ids, self::LIFETIME );

		set_transient(
			self::PREFIX . 'meta_' . $job,
			[
				'scope'   => $scope,
				'counts'  => $counts,
				'total'   => count( $ids ),
				'started' => microtime( true ),
			],
			self::LIFETIME
		);

		wp_send_json_success(
			[
				'job'   => $job,
				'total' => count( $ids ),
				'batch' => self::BATCH,
			]
		);
	}
	/**
	 * Render the next few posts in a job.
	 *
	 * The browser says where it has got to, so a request that dies takes nothing
	 * with it: asking again for the same offset simply renders those posts again.
	 */
	public static function step() {
		self::guard();

		$job    = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$ids    = $job !== '' ? get_transient( self::PREFIX . $job ) : false;

		if ( ! is_array( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'That rebuild has expired. Start it again.', 'socialbump-ai-knowledge-exporter' ) ], 410 );
		}

		// However long the host allows, ask for as much of it as we can.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$core  = self::core();
		$slice = array_slice( $ids, $offset, self::BATCH );
		$done  = [];

		// The post type leads the name, so the progress says where it has got to.
		$labels = [];

		foreach ( $slice as $id ) {
			$core->cache_post( $id );

			$type = get_post_type( $id );

			if ( ! isset( $labels[ $type ] ) ) {
				$object           = get_post_type_object( $type );
				$labels[ $type ] = $object ? $object->labels->singular_name : $type;
			}

			$title  = get_the_title( $id );
			$title  = $title !== '' ? html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : ( '#' . $id );
			$done[] = $labels[ $type ] . ': ' . $title;
		}

		$position = $offset + count( $slice );


		wp_send_json_success(
			[
				'offset' => $position,
				'total'  => count( $ids ),
				'done'   => $done,
				'more'   => $position < count( $ids ),
			]
		);
	}

	/**
	 * Assemble the files once everything in the job is cached.
	 *
	 * Cheap next to the rendering, since every post is now waiting in the cache.
	 */
	public static function finish() {
		self::guard();

		$job  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$meta = $job !== '' ? get_transient( self::PREFIX . 'meta_' . $job ) : false;

		if ( $job !== '' ) {
			delete_transient( self::PREFIX . $job );
			delete_transient( self::PREFIX . 'meta_' . $job );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$core  = self::core();
		$built = $core->build_files();

		// The counts on the page are read from a transient, so clear it.
		if ( method_exists( $core, 'flush_global_stale_count' ) ) {
			$core->flush_global_stale_count();
		}

		if ( ! $built ) {
			wp_send_json_error( [ 'message' => __( 'The files could not be written. Check the File Serving setting.', 'socialbump-ai-knowledge-exporter' ) ] );
		}

		// The report goes back in the response and is shown in the progress box,
		// with a Close button that reloads the page. It used to be left in a
		// transient for the page to pick up after the reload.
		$html = '';

		if ( is_array( $meta ) ) {
			$html = self::report_body(
				[
					'scope'   => $meta['scope'],
					'total'   => (int) $meta['total'],
					'counts'  => (array) $meta['counts'],
					'seconds' => round( microtime( true ) - (float) $meta['started'], 1 ),
				]
			);
		}

		wp_send_json_success(
			[
				'message' => __( 'Done', 'socialbump-ai-knowledge-exporter' ),
				'report'  => $html,
			]
		);
	}

	/**
	 * What the last rebuild did, shown once on the next page load.
	 *
	 * A line per post type, then the things that are written into every file
	 * regardless: the taxonomies, the ACF options fields and the business
	 * details. Those are assembled from the settings each time the files are
	 * written, so they are always brought up to date along with the posts.
	 */
	public static function report() {
		$key    = self::PREFIX . 'report_' . get_current_user_id();
		$report = get_transient( $key );

		if ( ! is_array( $report ) ) {
			return '';
		}

		delete_transient( $key );

		$quote = chr( 34 );

		return '<div class=' . $quote . 'notice notice-success is-dismissible sbaike-report' . $quote . '>' . self::report_body( $report ) . '</div>';
	}

	/**
	 * The report itself: a title line, then a line per post type and one for
	 * each of the things written fresh every time. Shown in the progress box
	 * when a job finishes, and once as a notice for the older transient path.
	 */
	public static function report_body( array $report ) {
		$scope = $report['scope'];
		$lines = [];

		foreach ( (array) $report['counts'] as $type => $number ) {
			$object = get_post_type_object( $type );
			$label  = $object ? ( (int) $number === 1 ? $object->labels->singular_name : $object->labels->name ) : $type;

			/* translators: 1: number of posts, 2: post type name */
			$lines[] = sprintf( __( '<strong>%1$s %2$s</strong> updated', 'socialbump-ai-knowledge-exporter' ), number_format_i18n( $number ), esc_html( $label ) );
		}

		// Everything else in the files is rewritten whenever they are written.
		if ( in_array( $scope, [ 'everything', 'stale' ], true ) ) {
			$settings   = (array) get_option( SBAIKE_Transfer::SETTINGS_OPTION, [] );
			$taxonomies = count( (array) ( $settings['taxonomies'] ?? [] ) );
			$fields     = count( (array) ( $settings['acf_options_fields'] ?? [] ) );

			if ( $taxonomies > 0 ) {
				/* translators: %s: number of taxonomies */
				$lines[] = sprintf( _n( '<strong>%s Taxonomy</strong> updated', '<strong>%s Taxonomies</strong> updated', $taxonomies, 'socialbump-ai-knowledge-exporter' ), number_format_i18n( $taxonomies ) );
			}

			if ( $fields > 0 ) {
				/* translators: %s: number of fields */
				$lines[] = sprintf( _n( '<strong>%s ACF Options Field</strong> updated', '<strong>%s ACF Options Fields</strong> updated', $fields, 'socialbump-ai-knowledge-exporter' ), number_format_i18n( $fields ) );
			}

			$business = trim( (string) ( $settings['business_name'] ?? '' ) . (string) ( $settings['business_description'] ?? '' ) . (string) ( $settings['compliance_notes'] ?? '' ) );

			if ( $business !== '' ) {
				$lines[] = __( '<strong>Business Details</strong> updated', 'socialbump-ai-knowledge-exporter' );
			}
		}

		switch ( $scope ) {
			case 'post':
				$title = __( 'Post updated', 'socialbump-ai-knowledge-exporter' );
				break;

			case 'type':
				$title = __( 'Rebuild complete', 'socialbump-ai-knowledge-exporter' );
				break;

			case 'type-stale':
			case 'stale':
				$title = __( 'Update complete', 'socialbump-ai-knowledge-exporter' );
				break;

			default:
				$title = __( 'Full rebuild complete', 'socialbump-ai-knowledge-exporter' );
		}

		if ( ! $lines ) {
			$lines[] = __( 'Nothing needed rebuilding, so the files were written as they were', 'socialbump-ai-knowledge-exporter' );
		}

		$quote = chr( 34 );
		$html  = '<p class=' . $quote . 'sbaike-report__title' . $quote . '><strong>' . esc_html( $title ) . '</strong> ';

		/* translators: %s: seconds taken */
		$html .= '<span class=' . $quote . 'sbaike-report__time' . $quote . '>' . esc_html( sprintf( __( 'in %s seconds', 'socialbump-ai-knowledge-exporter' ), number_format_i18n( $report['seconds'], 1 ) ) ) . '</span></p>';

		$html .= '<ul class=' . $quote . 'sbaike-report__list' . $quote . '>';

		foreach ( $lines as $line ) {
			$html .= '<li>' . wp_kses( $line, [ 'strong' => [] ] ) . '</li>';
		}

		return $html . '</ul>';
	}
}
