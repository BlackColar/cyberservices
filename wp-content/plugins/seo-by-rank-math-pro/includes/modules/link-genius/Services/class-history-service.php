<?php
/**
 * History service for Link Genius operations.
 *
 * Provides unified history retrieval and management across all operation types.
 * Handles querying, formatting, and deletion of operation history records.
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
 * History_Service class.
 *
 * Centralized service for managing operation history across all features.
 */
class History_Service {

	/**
	 * Get operation history with pagination.
	 *
	 * Retrieves history records for a specific source type or all types.
	 *
	 * @param string|null $source_type Optional: Filter by source type ('bulk_update', 'keyword_map', etc.).
	 * @param int         $page        Page number for pagination.
	 * @param int         $per_page    Items per page.
	 * @param string      $orderby     Column to order by.
	 * @param string      $order       Order direction (ASC or DESC).
	 * @return array History data with pagination info.
	 */
	public function get_history( $source_type = null, $page = 1, $per_page = 20, $orderby = 'created_at', $order = 'DESC' ) {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		// Build WHERE clause and JOIN.
		$where = '';
		$join  = '';
		if ( $source_type ) {
			$where = $wpdb->prepare( 'WHERE h.source_type = %s', $source_type );
			// Only join with maps table when source_type is keyword_map.
			if ( 'keyword_map' === $source_type ) {
				$join = "LEFT JOIN {$wpdb->prefix}rank_math_link_genius_maps km ON h.keyword_map_id = km.id";
			}
		}

		// Validate and sanitize orderby and order.
		$allowed_orderby = [ 'id', 'created_at', 'completed_at', 'status', 'affected_links_count', 'affected_posts_count' ];
		$orderby         = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'created_at';
		$order           = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		// Build SELECT clause - only include keyword_map_name if joining.
		$select = 'h.*';
		if ( ! empty( $join ) ) {
			$select .= ', km.name as keyword_map_name';
		}

		// Get history records.
		$history = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$select}
				FROM {$wpdb->prefix}rank_math_link_genius_history h
				{$join}
				{$where}
				ORDER BY h.{$orderby} {$order}
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		// Format history items.
		$formatted_history = array_map( [ $this, 'format_history_item' ], $history );

		return [
			'success' => true,
			'history' => $formatted_history,
		];
	}

	/**
	 * Delete a history record.
	 *
	 * Deletes both the history record and associated snapshots.
	 *
	 * @param string $batch_id Batch ID to delete.
	 * @return array|WP_Error Success response or error.
	 */
	public function delete_history( $batch_id ) {
		global $wpdb;

		// Verify record exists.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}rank_math_link_genius_history WHERE batch_id = %s",
				$batch_id
			)
		);

		if ( ! $exists ) {
			return new WP_Error(
				'history_not_found',
				__( 'History record not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		// Delete snapshots first.
		$wpdb->delete(
			$wpdb->prefix . 'rank_math_link_genius_snapshots',
			[ 'batch_id' => $batch_id ],
			[ '%s' ]
		);

		// Delete history record.
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'rank_math_link_genius_history',
			[ 'batch_id' => $batch_id ],
			[ '%s' ]
		);

		if ( false === $deleted ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete history record.', 'rank-math-pro' ),
				[ 'status' => 500 ]
			);
		}

		return [
			'success' => true,
			'message' => __( 'History record deleted successfully.', 'rank-math-pro' ),
		];
	}

	/**
	 * Bulk delete history records.
	 *
	 * Deletes multiple history records and their associated snapshots.
	 *
	 * @param array $batch_ids Array of batch IDs to delete.
	 * @return array Result with count of deleted records.
	 */
	public function bulk_delete( $batch_ids ) {
		if ( empty( $batch_ids ) || ! is_array( $batch_ids ) ) {
			return [
				'success' => false,
				'deleted' => 0,
				'message' => __( 'No batch IDs provided.', 'rank-math-pro' ),
			];
		}

		$deleted_count = 0;
		foreach ( $batch_ids as $batch_id ) {
			$result = $this->delete_history( sanitize_text_field( $batch_id ) );
			if ( ! is_wp_error( $result ) ) {
				++$deleted_count;
			}
		}

		return [
			'success' => true,
			'deleted' => $deleted_count,
			'message' => sprintf(
				/* translators: %d: number of deleted records */
				__( '%d history records deleted.', 'rank-math-pro' ),
				$deleted_count
			),
		];
	}

	/**
	 * Format a history item for output.
	 *
	 * Converts database record to API-friendly format with additional computed fields.
	 *
	 * @param object $item History record from database.
	 * @return array Formatted history item.
	 */
	private function format_history_item( $item ) {
		$changes_summary = ! empty( $item->changes_summary ) ? json_decode( $item->changes_summary, true ) : [];
		$filters         = ! empty( $item->filters ) ? json_decode( $item->filters, true ) : [];

		// Determine if rollback is available.
		$can_rollback = 'completed' === $item->status;

		// Build human-readable summary.
		$summary = $this->build_summary( $item, $changes_summary );

		return [
			'id'                   => (int) $item->id,
			'batch_id'             => $item->batch_id,
			'source_type'          => $item->source_type,
			'keyword_map_id'       => isset( $item->keyword_map_id ) ? (int) $item->keyword_map_id : null,
			'keyword_map_name'     => isset( $item->keyword_map_name ) ? $item->keyword_map_name : null,
			'user_id'              => (int) $item->user_id,
			'operation_type'       => $item->operation_type,
			'affected_links_count' => (int) $item->affected_links_count,
			'affected_posts_count' => (int) $item->affected_posts_count,
			'status'               => $item->status,
			'created_at'           => $item->created_at,
			'completed_at'         => $item->completed_at,
			'can_rollback'         => $can_rollback,
			'summary'              => $summary,
			'changes_summary'      => $changes_summary,
			'filters'              => $filters,
		];
	}

	/**
	 * Build human-readable summary from history item.
	 *
	 * @param object $item             History record.
	 * @param array  $changes_summary  Decoded changes summary.
	 * @return string Human-readable summary.
	 */
	private function build_summary( $item, $changes_summary ) {
		switch ( $item->source_type ) {
			case 'bulk_update':
				$from = '';
				$to   = '';

				if ( 'anchor' === $item->operation_type || 'both' === $item->operation_type ) {
					$from = $changes_summary['from_anchor'] ?? '';
					$to   = $changes_summary['to_anchor'] ?? '';
				} elseif ( 'url' === $item->operation_type ) {
					$from = $changes_summary['from_url'] ?? '';
					$to   = $changes_summary['to_url'] ?? '';
				}

				if ( $from && $to ) {
					return sprintf(
						/* translators: 1: from value, 2: to value, 3: number of links */
						__( 'Changed <span class="rank-math-history-from">"%1$s"</span> to <span class="rank-math-history-to">"%2$s"</span> in <span class="rank-math-history-count">%3$d</span> links', 'rank-math-pro' ),
						$from,
						$to,
						(int) $item->affected_links_count
					);
				}

				return sprintf(
					/* translators: %d: number of links */
					__( 'Updated %d links', 'rank-math-pro' ),
					(int) $item->affected_links_count
				);

			case 'keyword_map':
				$keyword = $changes_summary['keyword'] ?? '';
				if ( $keyword ) {
					return sprintf(
						/* translators: 1: keyword, 2: number of links */
						__( 'Added %2$d links for keyword <span class="rank-math-history-to">"%1$s"</span>', 'rank-math-pro' ),
						$keyword,
						(int) $item->affected_links_count
					);
				}

				return sprintf(
					/* translators: %d: number of links */
					__( 'Added %d links', 'rank-math-pro' ),
					(int) $item->affected_links_count
				);

			default:
				return sprintf(
					/* translators: %d: number of links */
					__( 'Affected %d links', 'rank-math-pro' ),
					(int) $item->affected_links_count
				);
		}
	}
}
