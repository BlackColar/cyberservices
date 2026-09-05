<?php
/**
 * Keyword Maps Preview Processor.
 *
 * Handles background processing for keyword map preview generation.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Keyword_Maps
 */

namespace RankMathPro\Link_Genius\Features\KeywordMaps;

defined( 'ABSPATH' ) || exit;

/**
 * Preview_Processor class.
 *
 * Manages preview generation with progress tracking via transients.
 */
class Preview_Processor {

	/**
	 * Transient prefix for preview data.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'rank_math_km_preview_';

	/**
	 * Transient expiration time (30 minutes).
	 *
	 * @var int
	 */
	const TRANSIENT_EXPIRATION = 1800;

	/**
	 * Main instance.
	 *
	 * Ensure only one instance is loaded or can be loaded.
	 *
	 * @return Preview_Processor
	 */
	public static function get() {
		static $instance;

		if ( is_null( $instance ) && ! ( $instance instanceof Preview_Processor ) ) {
			$instance = new Preview_Processor();
		}

		return $instance;
	}

	/**
	 * Start preview generation.
	 *
	 * @param int    $keyword_map_id Keyword map ID.
	 * @param object $keyword_map    Keyword map object.
	 * @return array|\WP_Error Result array or error.
	 */
	public function start( $keyword_map_id, $keyword_map ) {
		// Check if preview is already in progress.
		$existing_progress = $this->get_progress( $keyword_map_id );
		if ( $existing_progress && 'processing' === $existing_progress['status'] ) {
			return new \WP_Error(
				'preview_in_progress',
				__( 'Preview is already being generated. Please wait.', 'rank-math-pro' ),
				[ 'status' => 409 ]
			);
		}

		// Initialize progress.
		$this->update_progress(
			$keyword_map_id,
			[
				'status'     => 'processing',
				'total'      => 0,
				'processed'  => 0,
				'percent'    => 0,
				'active'     => true,
				'started_at' => current_time( 'mysql' ),
			]
		);

		// Execute preview synchronously (keywords maps are typically fast).
		$executor = new Executor();
		$result   = $executor->preview( $keyword_map );

		if ( is_wp_error( $result ) ) {
			$this->update_progress(
				$keyword_map_id,
				[
					'status'        => 'failed',
					'active'        => false,
					'error_message' => $result->get_error_message(),
				]
			);
			return $result;
		}

		// Store preview results.
		$this->store_results( $keyword_map_id, $result );

		// Handle different result formats (no posts found vs posts found).
		$total_links = $result['total_links'] ?? $result['links_added'] ?? 0;
		$total_posts = $result['total_posts'] ?? $result['posts_found'] ?? 0;

		// Update progress to completed.
		$this->update_progress(
			$keyword_map_id,
			[
				'status'       => 'completed',
				'total'        => $total_links,
				'processed'    => $total_links,
				'percent'      => 100,
				'active'       => false,
				'completed_at' => current_time( 'mysql' ),
			]
		);

		return [
			'success'     => true,
			'total_links' => $total_links,
			'total_posts' => $total_posts,
			'message'     => $result['message'] ?? '',
		];
	}

	/**
	 * Get preview progress.
	 *
	 * @param int $keyword_map_id Keyword map ID.
	 * @return array|null Progress data or null if not found.
	 */
	public function get_progress( $keyword_map_id ) {
		return get_transient( self::TRANSIENT_PREFIX . 'progress_' . $keyword_map_id );
	}

	/**
	 * Update preview progress.
	 *
	 * @param int   $keyword_map_id Keyword map ID.
	 * @param array $data           Progress data to update.
	 * @return bool True on success.
	 */
	private function update_progress( $keyword_map_id, $data ) {
		$existing = $this->get_progress( $keyword_map_id );
		$existing = $existing ? $existing : [];
		$updated  = array_merge( $existing, $data );
		return set_transient(
			self::TRANSIENT_PREFIX . 'progress_' . $keyword_map_id,
			$updated,
			self::TRANSIENT_EXPIRATION
		);
	}

	/**
	 * Store preview results.
	 *
	 * @param int   $keyword_map_id Keyword map ID.
	 * @param array $results        Preview results from Executor.
	 * @return bool True on success.
	 */
	private function store_results( $keyword_map_id, $results ) {
		return set_transient(
			self::TRANSIENT_PREFIX . 'results_' . $keyword_map_id,
			$results,
			self::TRANSIENT_EXPIRATION
		);
	}

	/**
	 * Get preview results with pagination.
	 *
	 * @param int $keyword_map_id Keyword map ID.
	 * @param int $page           Page number (1-indexed).
	 * @param int $per_page       Items per page.
	 * @return array|\WP_Error Results or error.
	 */
	public function get_results( $keyword_map_id, $page = 1, $per_page = 20 ) {
		$results = get_transient( self::TRANSIENT_PREFIX . 'results_' . $keyword_map_id );

		if ( ! $results ) {
			return new \WP_Error(
				'no_results_found',
				__( 'No preview results found. Please generate a preview first.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		// Paginate sample_changes.
		$all_changes = $results['sample_changes'] ?? [];
		$total       = count( $all_changes );
		$offset      = ( $page - 1 ) * $per_page;
		$paginated   = array_slice( $all_changes, $offset, $per_page );

		return [
			'success'        => true,
			'preview_id'     => $keyword_map_id,
			'total_links'    => $results['total_links'] ?? 0,
			'total_posts'    => $results['total_posts'] ?? 0,
			'sample_changes' => $paginated,
			'warnings'       => $results['warnings'] ?? [],
			'total_pages'    => ceil( $total / $per_page ),
			'current_page'   => $page,
		];
	}

	/**
	 * Cancel preview generation.
	 *
	 * @param int $keyword_map_id Keyword map ID.
	 * @return bool True on success.
	 */
	public function cancel( $keyword_map_id ) {
		// Delete progress and results transients.
		delete_transient( self::TRANSIENT_PREFIX . 'progress_' . $keyword_map_id );
		delete_transient( self::TRANSIENT_PREFIX . 'results_' . $keyword_map_id );
		return true;
	}
}
