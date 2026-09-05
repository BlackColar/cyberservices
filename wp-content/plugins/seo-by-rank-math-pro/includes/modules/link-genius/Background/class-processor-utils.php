<?php
/**
 * Processor utility methods for Link Genius background processors.
 *
 * Provides static utility methods shared across background processors to eliminate duplication.
 * Cannot use abstract base class due to WP_Background_Process async execution issues.
 *
 * @since      1.0.264
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Background
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Background;

use WP_Error;
use RankMathPro\Link_Genius\Services\Batch_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Processor_Utils class.
 *
 * Static utility methods for background processor operations.
 * Avoids inheritance issues with WP_Background_Process by using composition.
 */
class Processor_Utils {

	/**
	 * Fetch rollback snapshots from database.
	 *
	 * Retrieves content snapshots for a given batch ID to enable rollback functionality.
	 *
	 * @param string $rollback_batch_id Batch ID to fetch snapshots for.
	 * @return array|WP_Error Array of snapshot objects or error if none found.
	 */
	public static function fetch_rollback_snapshots( $rollback_batch_id ) {
		global $wpdb;

		$snapshots = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, original_content, link_changes
				FROM {$wpdb->prefix}rank_math_link_genius_snapshots
				WHERE batch_id = %s",
				$rollback_batch_id
			)
		);

		if ( empty( $snapshots ) ) {
			return new WP_Error(
				'no_snapshots_found',
				__( 'No rollback data found for this batch.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		return $snapshots;
	}

	/**
	 * Calculate totals from snapshots.
	 *
	 * Extracts post IDs and counts total posts and links from snapshot data.
	 *
	 * @param array $snapshots Array of snapshot objects from database.
	 * @return array Array with keys: post_ids, total_posts, total_links.
	 */
	public static function calculate_snapshot_totals( $snapshots ) {
		$post_ids    = array_column( $snapshots, 'post_id' );
		$total_posts = count( $post_ids );
		$total_links = 0;

		foreach ( $snapshots as $snapshot ) {
			$link_changes = json_decode( $snapshot->link_changes, true );
			if ( is_array( $link_changes ) ) {
				$total_links += count( $link_changes );
			}
		}

		return [
			'post_ids'    => $post_ids,
			'total_posts' => $total_posts,
			'total_links' => $total_links,
		];
	}

	/**
	 * Standard completion logic for background operations.
	 *
	 * Updates history record status and performs cleanup when operation completes.
	 * Handles both normal completion and rollback completion.
	 *
	 * @param string $batch_id     Batch ID of the completed operation.
	 * @param string $source_type  Source type (e.g., 'bulk_update', 'keyword_map').
	 * @param bool   $is_rollback  Whether this is a rollback operation.
	 */
	public static function complete_operation( $batch_id, $source_type, $is_rollback = false ) {
		global $wpdb;

		if ( $is_rollback ) {
			// Update original batch to rolled_back status.
			$wpdb->update(
				$wpdb->prefix . 'rank_math_link_genius_history',
				[
					'status'       => 'rolled_back',
					'completed_at' => current_time( 'mysql' ),
				],
				[ 'batch_id' => $batch_id ],
				[ '%s', '%s' ],
				[ '%s' ]
			);
		} else {
			// Update to completed status.
			$wpdb->update(
				$wpdb->prefix . 'rank_math_link_genius_history',
				[
					'status'       => 'completed',
					'completed_at' => current_time( 'mysql' ),
				],
				[ 'batch_id' => $batch_id ],
				[ '%s', '%s' ],
				[ '%s' ]
			);
		}

		// Cleanup old history records (keep last 5).
		Batch_Helper::cleanup_old_history( $source_type, 5 );
	}

	/**
	 * Apply chunk size filter.
	 *
	 * Provides a consistent way to get filtered chunk size across processors.
	 *
	 * @param string $filter_name Filter name to apply.
	 * @param int    $chunk_size  Default chunk size if filter not applied.
	 * @return int Filtered chunk size.
	 */
	public static function get_filtered_chunk_size( $filter_name, $chunk_size = 10 ) {
		/**
		 * Filter the chunk size for background processing.
		 *
		 * Allows developers to adjust the number of items processed per chunk
		 * to balance between performance and server resource usage.
		 *
		 * @param int $default Default chunk size.
		 */
		return apply_filters( $filter_name, $chunk_size );
	}

	/**
	 * Filter snapshots by selected items.
	 *
	 * Filters snapshot array to only include posts that contain the selected items.
	 * Used for selective rollback functionality.
	 *
	 * @param array  $snapshots      Array of snapshot objects.
	 * @param array  $selected_items Array of item IDs (link_ids or post_ids) to filter by.
	 * @param string $filter_type    Type of filter: 'link_id' or 'post_id'.
	 * @return array Filtered snapshots array.
	 */
	public static function filter_snapshots_by_selection( $snapshots, $selected_items, $filter_type = 'link_id' ) {
		if ( empty( $selected_items ) || ! is_array( $selected_items ) ) {
			return $snapshots;
		}

		$selected_items = array_map( 'absint', $selected_items );
		$selected_items = array_filter( $selected_items );

		if ( empty( $selected_items ) ) {
			return $snapshots;
		}

		if ( 'post_id' === $filter_type ) {
			// Filter directly by post_id.
			return array_filter(
				$snapshots,
				function ( $snapshot ) use ( $selected_items ) {
					return in_array( (int) $snapshot->post_id, $selected_items, true );
				}
			);
		}

		// Filter by link_id (check link_changes).
		$selected_post_ids = [];
		foreach ( $snapshots as $snapshot ) {
			$link_changes = json_decode( $snapshot->link_changes, true );
			if ( ! empty( $link_changes ) && is_array( $link_changes ) ) {
				foreach ( $link_changes as $change ) {
					$link_id = $change['link_id'] ?? null;
					if ( $link_id && in_array( (int) $link_id, $selected_items, true ) ) {
						$selected_post_ids[ $snapshot->post_id ] = true;
						break;
					}
				}
			}
		}

		return array_filter(
			$snapshots,
			function ( $snapshot ) use ( $selected_post_ids ) {
				return isset( $selected_post_ids[ $snapshot->post_id ] );
			}
		);
	}

	/**
	 * Validate operation not already in progress.
	 *
	 * Checks if an operation with the same identifier is already running.
	 * Prevents concurrent operations that could cause conflicts.
	 *
	 * @param array  $existing_progress Current progress state.
	 * @param string $operation_name    Human-readable operation name for error message.
	 * @return true|WP_Error True if valid, error if operation already in progress.
	 */
	public static function validate_not_in_progress( $existing_progress, $operation_name ) {
		if ( ! empty( $existing_progress ) && 'processing' === ( $existing_progress['status'] ?? '' ) ) {
			return new WP_Error(
				'operation_in_progress',
				sprintf(
					/* translators: %s: operation name */
					__( 'A %s operation is already in progress. Please wait for it to complete first.', 'rank-math-pro' ),
					$operation_name
				),
				[ 'status' => 409 ]
			);
		}

		return true;
	}
}
