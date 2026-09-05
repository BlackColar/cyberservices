<?php
/**
 * Preview Processor for Bulk Update.
 *
 * Handles background processing for preview generation.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius\Bulk_Update
 */

namespace RankMathPro\Link_Genius\Features\BulkUpdate;

use WP_Error;
use RankMathPro\Link_Genius\Data\Query_Builder;
use RankMathPro\Link_Genius\Services\Batch_Helper;
use RankMathPro\Link_Genius\Services\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Preview_Processor class.
 *
 * Extends WP_Background_Process to handle preview generation operations.
 */
class Preview_Processor extends \WP_Background_Process {

	/**
	 * Action.
	 *
	 * @var string
	 */
	protected $action = 'link_genius_preview';

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
	 * Get the chunk size for preview processing.
	 *
	 * @return int Number of links to process per chunk.
	 */
	public static function get_chunk_size() {
		/**
		 * Filter: Allow developers to change the chunk size for preview generation.
		 *
		 * @param int $chunk_size Number of links to process per chunk. Default 100.
		 */
		return apply_filters( 'rank_math/link_genius/preview_chunk_size', 100 );
	}

	/**
	 * Check whether a stuck 'processing' lock is older than the staleness threshold.
	 *
	 * @param array $progress Existing progress data.
	 * @return bool True if the lock should be reclaimed.
	 */
	private function is_progress_stale( $progress ) {
		if ( empty( $progress['started_at'] ) ) {
			return false;
		}

		$started_at = strtotime( $progress['started_at'] );
		if ( false === $started_at ) {
			return false;
		}

		/**
		 * Filter: Age (in seconds) after which a stuck preview lock is considered stale.
		 *
		 * @param int $threshold Staleness threshold in seconds. Default 30 minutes.
		 */
		$threshold = (int) apply_filters( 'rank_math/link_genius/preview_stale_threshold', 30 * MINUTE_IN_SECONDS );

		// Compare against site-local "now" since started_at is site-local too.
		$now = (int) current_time( 'U' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		return ( $now - $started_at ) > $threshold;
	}

	/**
	 * Start preview generation process.
	 *
	 * Site-wide single preview - uses fixed 'current' identifier.
	 *
	 * @param array $filters       Filters to apply when finding links.
	 * @param array $update_config Update configuration.
	 * @return array|WP_Error Process start details or error.
	 */
	public function start( $filters, $update_config ) {
		global $wpdb;

		// Check if another preview is already running.
		$existing_progress = get_option( 'rank_math_preview_current' );
		if ( $existing_progress && 'processing' === $existing_progress['status'] ) {
			if ( $this->is_progress_stale( $existing_progress ) ) {
				// Reclaim a stale lock instead of locking out every future preview.
				self::cleanup();
			} else {
				return new WP_Error(
					'preview_in_progress',
					__( 'A preview generation is already in progress. Please wait for it to complete or cancel it first.', 'rank-math-pro' ),
					[ 'status' => 409 ]
				);
			}
		}

		// Get total count without loading all links into memory.
		$total_links = Query_Builder::get_links_count( $filters );

		if ( empty( $total_links ) ) {
			return new WP_Error(
				'no_links_found',
				__( 'No links found matching the specified filters.', 'rank-math-pro' )
			);
		}

		// Initialize progress metadata.
		$progress_data = [
			'total_links'     => $total_links,
			'processed_links' => 0,
			'started_at'      => current_time( 'mysql' ),
			'update_config'   => $update_config,
			'filters'         => $filters,
			'status'          => 'processing',
			'current_link_id' => null,
		];

		update_option( 'rank_math_preview_current', $progress_data, false );

		// Initialize empty results transient (24-hour expiration).
		set_transient( 'rank_math_preview_results_current', [], DAY_IN_SECONDS );

		// Queue chunks using offset-based batching to avoid loading all links into memory.
		$chunk_size = self::get_chunk_size();
		$num_chunks = ceil( $total_links / $chunk_size );

		for ( $i = 0; $i < $num_chunks; $i++ ) {
			$this->push_to_queue(
				[
					'offset'     => $i * $chunk_size,
					'chunk_size' => $chunk_size,
					'filters'    => $filters,
				]
			);
		}

		$this->save()->dispatch();

		return [
			'success'     => true,
			'preview_id'  => 'current',
			'total_links' => $total_links,
			'message'     => __( 'Preview generation started. Processing in background.', 'rank-math-pro' ),
		];
	}

	/**
	 * Task to perform - processes one chunk of links.
	 *
	 * @param array $chunk_data Chunk data with offset and filters.
	 * @return false
	 */
	protected function task( $chunk_data ) {
		// Get progress metadata.
		$progress = get_option( 'rank_math_preview_current' );
		if ( ! $progress ) {
			return false; // Job not found, remove from queue.
		}

		// Fetch links for this chunk using offset to avoid loading all links into memory.
		$query_args             = $chunk_data['filters'];
		$query_args['per_page'] = $chunk_data['chunk_size'];
		$query_args['offset']   = $chunk_data['offset'];
		// Using 'id' ensures stable ordering and all links are processed exactly once.
		$query_args['orderby'] = 'id';
		$query_args['order']   = 'DESC';

		$links = Query_Builder::get_links( $query_args );

		// If no links found in this chunk, skip processing.
		if ( empty( $links ) ) {
			return false;
		}

		$update_config = $progress['update_config'];
		$service       = new Service();

		// Batch fetch all post content for this chunk to avoid N+1 queries.
		$post_ids = array_unique( array_column( $links, 'post_id' ) );
		$posts    = Batch_Helper::batch_fetch_posts( $post_ids, [ 'ID', 'post_content' ] );

		// Store results for this chunk only (avoid loading all previous results).
		$chunk_results = [];

		// Track occurrence index for each unique combination of post_id + url + anchor_text.
		// This ensures each identical link gets a unique occurrence index.
		$occurrence_tracker = [];

		// Process each link in this chunk.
		foreach ( $links as $link ) {
			// Update progress for every link processed.
			++$progress['processed_links'];
			$progress['current_link_id'] = $link->id;

			$new_url    = null;
			$new_anchor = null;

			// Calculate new URL if updating URLs.
			if ( in_array( $update_config['operation_type'], [ 'url', 'both' ], true ) ) {
				$new_url = Utils::calculate_new_url( $link->url, $update_config['url_update'] );
			}

			// Calculate new anchor if updating anchors.
			if ( in_array( $update_config['operation_type'], [ 'anchor', 'both' ], true ) && 'IMAGE' !== $link->anchor_type ) {
				$new_anchor = Utils::calculate_new_anchor( $link->anchor_text, $update_config['anchor_update'] );
			}

			// Skip if no changes (don't add to results, but progress was already updated).
			if ( null === $new_url && null === $new_anchor ) {
				continue;
			}

			// When operation_type is 'both', require BOTH patterns to match (AND logic).
			// Skip links where only one pattern matches.
			if ( 'both' === $update_config['operation_type'] ) {
				// For IMAGE links, only URL is checked (anchor is skipped above).
				if ( 'IMAGE' !== $link->anchor_type && ( null === $new_url || null === $new_anchor ) ) {
					continue;
				}
			}

			// Extract sentence context.
			$sentence_before = '';
			$sentence_after  = '';

			if ( ! empty( $posts[ $link->post_id ] ) ) {
				// Track which occurrence of this link combination we're processing.
				$tracker_key = $link->post_id . '|' . $link->url . '|' . $link->anchor_text;
				if ( ! isset( $occurrence_tracker[ $tracker_key ] ) ) {
					$occurrence_tracker[ $tracker_key ] = 0;
				} else {
					++$occurrence_tracker[ $tracker_key ];
				}

				$content_context = $service->extract_sentence_context(
					$posts[ $link->post_id ]->post_content,
					$link->anchor_text,
					$link->url,
					$new_anchor,
					$link->id,
					$occurrence_tracker[ $tracker_key ],
					$new_url
				);
				$sentence_before = $content_context['before'];
				$sentence_after  = $content_context['after'];
			}

			// Add preview result for this chunk (only for links with changes).
			$chunk_results[] = [
				'post_id'         => $link->post_id,
				'post_title'      => get_the_title( $link->post_id ),
				'link_id'         => $link->id,
				'source_url'      => get_permalink( $link->post_id ),
				'before_url'      => $link->url,
				'before_anchor'   => $link->anchor_text,
				'after_url'       => $new_url ?? $link->url,
				'after_anchor'    => $new_anchor ?? $link->anchor_text,
				'sentence_before' => $sentence_before,
				'sentence_after'  => $sentence_after,
			];
		}

		// Save this chunk's results to a separate transient to avoid loading all results.
		if ( ! empty( $chunk_results ) ) {
			$chunk_index = $chunk_data['offset'] / $chunk_data['chunk_size'];
			set_transient( 'rank_math_preview_chunk_' . $chunk_index . '_current', $chunk_results, DAY_IN_SECONDS );
		}

		// Save updated progress.
		update_option( 'rank_math_preview_current', $progress, false );

		// Check if this preview is complete (all links processed).
		if ( $progress['processed_links'] >= $progress['total_links'] ) {
			// Merge all chunk results into final transient.
			$this->merge_chunk_results();

			$progress['status']       = 'completed';
			$progress['completed_at'] = current_time( 'mysql' );
			update_option( 'rank_math_preview_current', $progress, false );
		}

		return false; // Successfully processed, remove from queue.
	}

	/**
	 * Merge all chunk results into final transient.
	 *
	 * Called when preview is complete to consolidate chunk results.
	 */
	private function merge_chunk_results() {
		global $wpdb;

		$all_results = [];

		// Find all chunk transients.
		$chunk_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_rank_math_preview_chunk_' ) . '%_current'
			)
		);

		// Merge all chunks.
		foreach ( $chunk_keys as $key ) {
			$transient_name = str_replace( '_transient_', '', $key );
			$chunk_results  = get_transient( $transient_name );

			if ( is_array( $chunk_results ) ) {
				$all_results = array_merge( $all_results, $chunk_results );
			}

			// Delete chunk transient after merging.
			delete_transient( $transient_name );
		}

		// Save merged results to final transient.
		set_transient( 'rank_math_preview_results_current', $all_results, DAY_IN_SECONDS );
	}

	/**
	 * Complete preview generation.
	 *
	 * Called when all chunks have been processed.
	 */
	protected function complete() {
		// Mark any remaining preview jobs as completed.
		global $wpdb;

		$jobs = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			WHERE option_name LIKE 'rank_math_preview_%'"
		);

		foreach ( $jobs as $option_name ) {
			$progress = get_option( $option_name );

			if ( ! $progress || ! is_array( $progress ) ) {
				delete_option( $option_name );
				continue;
			}

			// Only mark as completed if not already completed.
			if ( 'completed' !== $progress['status'] ) {
				// Merge chunk results before marking as complete.
				$this->merge_chunk_results();

				$progress['status']       = 'completed';
				$progress['completed_at'] = current_time( 'mysql' );
				update_option( $option_name, $progress, false );
			}
		}

		parent::complete();
	}

	/**
	 * Get progress for the current preview.
	 *
	 * @return array|false Progress data or false if not found.
	 */
	public static function get_progress() {
		$progress = get_option( 'rank_math_preview_current' );

		if ( ! $progress ) {
			return false;
		}

		$total     = $progress['total_links'] ?? 0;
		$processed = $progress['processed_links'] ?? 0;

		return [
			'preview_id'      => 'current',
			'total'           => $total,
			'processed'       => $processed,
			'current_link_id' => $progress['current_link_id'] ?? null,
			'percent'         => $total > 0 ? round( ( $processed / $total ) * 100 ) : 0,
			'started_at'      => $progress['started_at'] ?? null,
			'status'          => $progress['status'] ?? null,
			'filters'         => $progress['filters'] ?? null,
			'update_config'   => $progress['update_config'] ?? null,
		];
	}

	/**
	 * Get preview results with pagination.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Results per page.
	 * @return array Preview results.
	 */
	public static function get_results( $page = 1, $per_page = 20 ) {
		// Get results from transient.
		$results = get_transient( 'rank_math_preview_results_current' );
		if ( false === $results ) {
			$results = [];
		}

		// Calculate pagination.
		$total  = count( $results );
		$offset = ( $page - 1 ) * $per_page;

		// Get paginated slice.
		$paginated_results = array_slice( $results, $offset, $per_page );

		// Format results for frontend.
		$formatted_results = [];
		foreach ( $paginated_results as $result ) {
			$formatted_results[] = [
				'post_id'         => (int) $result['post_id'],
				'post_title'      => $result['post_title'],
				'link_id'         => (int) $result['link_id'],
				'source_url'      => $result['source_url'],
				'before'          => [
					'url'         => $result['before_url'],
					'anchor_text' => $result['before_anchor'],
				],
				'after'           => [
					'url'         => $result['after_url'],
					'anchor_text' => $result['after_anchor'],
				],
				'sentence_before' => $result['sentence_before'],
				'sentence_after'  => $result['sentence_after'],
			];
		}

		return [
			'total_items'    => $total,
			'total_pages'    => $total > 0 ? ceil( $total / $per_page ) : 1,
			'current_page'   => $page,
			'per_page'       => $per_page,
			'sample_changes' => $formatted_results,
		];
	}

	/**
	 * Clean up current preview results and cancel background process.
	 *
	 * @return bool True on success.
	 */
	public static function cleanup() {
		global $wpdb;

		// Cancel synchronously (avoids the deprecated cancel_process() alias, which relies on an async loopback request).
		$processor = self::get();
		$processor->clear_scheduled_event();
		$processor->delete_batches_from_db();
		$processor->unlock_process();

		// Delete preview results transient.
		delete_transient( 'rank_math_preview_results_current' );

		// Delete all chunk transients.
		$chunk_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_rank_math_preview_chunk_' ) . '%_current'
			)
		);

		foreach ( $chunk_keys as $key ) {
			$transient_name = str_replace( '_transient_', '', $key );
			delete_transient( $transient_name );
		}

		// Delete progress option.
		delete_option( 'rank_math_preview_current' );

		return true;
	}

	/**
	 * Delete all queued batches directly from the database (mirrors Progress_Helper::delete_batches_from_db()).
	 */
	private function delete_batches_from_db() {
		global $wpdb;

		\RankMath\Helpers\DB::query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $this->identifier . '_batch_' ) . '%'
			)
		);
	}
}
