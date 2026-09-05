<?php
/**
 * Rollback service for Link Genius operations.
 *
 * Provides unified rollback functionality across all operation types (Bulk Update, Keyword Maps, etc.).
 * Handles batch verification, processor delegation, and rollback preview generation.
 *
 * @since      1.0.264
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Services
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Rollback_Service class.
 *
 * Centralized service for handling rollback operations across different operation types.
 */
class Rollback_Service {

	/**
	 * Execute rollback operation.
	 *
	 * Verifies the batch can be rolled back and delegates to the appropriate processor.
	 *
	 * @param string     $batch_id        Batch ID to rollback.
	 * @param array|null $selected_items  Optional: specific item IDs to rollback.
	 * @return array|WP_Error Operation result or error.
	 */
	public function execute( $batch_id, $selected_items = null ) {
		global $wpdb;

		// Verify batch exists and can be rolled back.
		$batch = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, source_type, status, affected_posts_count, affected_links_count
				FROM {$wpdb->prefix}rank_math_link_genius_history
				WHERE batch_id = %s",
				$batch_id
			)
		);

		if ( ! $batch ) {
			return new WP_Error(
				'batch_not_found',
				__( 'Batch not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'completed' !== $batch->status ) {
			return new WP_Error(
				'invalid_batch_status',
				sprintf(
					/* translators: %s: current batch status */
					__( 'Only completed batches can be rolled back. This batch is currently in status: %s', 'rank-math-pro' ),
					$batch->status
				),
				[ 'status' => 400 ]
			);
		}

		// Get processor for this source type.
		$processor = $this->get_processor_for_source_type( $batch->source_type );

		if ( is_wp_error( $processor ) ) {
			return $processor;
		}

		// Delegate to processor's rollback logic.
		// The processor's start() method handles rollback when appropriate parameters are passed.
		return $processor->start_rollback( $batch_id, $selected_items );
	}

	/**
	 * Get processor instance for a given source type.
	 *
	 * Maps source types to their corresponding processor classes.
	 *
	 * @param string $source_type Source type from history record (e.g., 'bulk_update', 'keyword_map').
	 * @return object|WP_Error Processor instance or error if unknown source type.
	 */
	private function get_processor_for_source_type( $source_type ) {
		switch ( $source_type ) {
			case 'bulk_update':
				return \RankMathPro\Link_Genius\Features\BulkUpdate\Processor::get();

			case 'keyword_map':
				return \RankMathPro\Link_Genius\Features\KeywordMaps\Keyword_Map_Processor::get();

			default:
				return new WP_Error(
					'unknown_source_type',
					sprintf(
						/* translators: %s: source type */
						__( 'Unknown source type: %s', 'rank-math-pro' ),
						$source_type
					),
					[ 'status' => 400 ]
				);
		}
	}

	/**
	 * Get rollback preview.
	 *
	 * Generates a preview of changes that will be rolled back.
	 * Shows "before" (current state) and "after" (rolled back state) with reversed order.
	 *
	 * @param string $batch_id Batch ID to preview rollback for.
	 * @param int    $page     Page number for pagination.
	 * @param int    $per_page Items per page.
	 * @return array|WP_Error Preview data or error.
	 */
	public function get_preview( $batch_id, $page = 1, $per_page = 20 ) {
		global $wpdb;

		$snapshots = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, link_changes
				FROM {$wpdb->prefix}rank_math_link_genius_snapshots
				WHERE batch_id = %s",
				$batch_id
			)
		);

		if ( empty( $snapshots ) ) {
			return new WP_Error(
				'no_snapshots_found',
				__( 'No rollback preview available for this batch.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		// Extract and format changes with reversed before/after.
		$all_changes = [];
		foreach ( $snapshots as $snapshot ) {
			$changes = json_decode( $snapshot->link_changes, true );
			if ( is_array( $changes ) ) {
				foreach ( $changes as $change ) {
					// REVERSED: Current state becomes "before", original state becomes "after".
					$all_changes[] = [
						'post_id'       => (int) $snapshot->post_id,
						'post_title'    => get_the_title( $snapshot->post_id ),
						'link_id'       => $change['link_id'] ?? null,
						'before_url'    => $change['new_url'] ?? '',
						'after_url'     => $change['old_url'] ?? '',
						'before_anchor' => $change['new_anchor'] ?? '',
						'after_anchor'  => $change['old_anchor'] ?? '',
					];
				}
			}
		}

		// Paginate results.
		$total  = count( $all_changes );
		$offset = ( $page - 1 ) * $per_page;
		$paged  = array_slice( $all_changes, $offset, $per_page );

		return [
			'success'        => true,
			'total_links'    => $total,
			'total_posts'    => count( $snapshots ),
			'sample_changes' => $paged,
			'current_page'   => $page,
			'total_pages'    => ceil( $total / $per_page ),
			'per_page'       => $per_page,
		];
	}

	/**
	 * Get batch changes for display.
	 *
	 * Retrieves all changes made by a batch without pagination.
	 * Useful for displaying complete change history.
	 *
	 * @param string $batch_id Batch ID to get changes for.
	 * @return array|WP_Error Changes data or error.
	 */
	public function get_batch_changes( $batch_id ) {
		global $wpdb;

		$snapshots = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, link_changes
				FROM {$wpdb->prefix}rank_math_link_genius_snapshots
				WHERE batch_id = %s",
				$batch_id
			)
		);

		if ( empty( $snapshots ) ) {
			return new WP_Error(
				'no_changes_found',
				__( 'No changes found for this batch.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		$all_changes = [];
		foreach ( $snapshots as $snapshot ) {
			$changes = json_decode( $snapshot->link_changes, true );
			if ( is_array( $changes ) ) {
				foreach ( $changes as $change ) {
					$all_changes[] = [
						'post_id'    => (int) $snapshot->post_id,
						'post_title' => get_the_title( $snapshot->post_id ),
						'link_id'    => $change['link_id'] ?? null,
						'old_url'    => $change['old_url'] ?? '',
						'new_url'    => $change['new_url'] ?? '',
						'old_anchor' => $change['old_anchor'] ?? '',
						'new_anchor' => $change['new_anchor'] ?? '',
					];
				}
			}
		}

		return [
			'success'     => true,
			'batch_id'    => $batch_id,
			'total_links' => count( $all_changes ),
			'total_posts' => count( $snapshots ),
			'changes'     => $all_changes,
		];
	}
}
