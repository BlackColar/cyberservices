<?php
/**
 * Batch Helper Utility.
 *
 * Provides common batch operation methods following DRY principle.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Services;

use RankMath\Helpers\DB;
use RankMathPro\Link_Genius\Data\Query_Builder;

defined( 'ABSPATH' ) || exit;

/**
 * Batch_Helper class.
 *
 * Centralized utility for common batch operations.
 */
class Batch_Helper {

	/**
	 * Batch fetch posts by IDs using custom SQL query.
	 *
	 * This is faster than get_posts() because:
	 * - No WP_Query overhead (filters, hooks, global setup).
	 * - Fetch only needed columns instead of all 30+ columns.
	 * - No post object instantiation overhead.
	 *
	 * @param array $post_ids  Array of post IDs.
	 * @param array $columns   Columns to fetch (default: ID, post_title, post_content).
	 * @return array           Associative array indexed by post ID.
	 */
	public static function batch_fetch_posts( $post_ids, $columns = [ 'ID', 'post_title', 'post_content' ] ) {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return [];
		}

		// Sanitize column names to prevent SQL injection.
		$allowed_columns = [
			'ID',
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_content',
			'post_title',
			'post_excerpt',
			'post_status',
			'post_name',
			'post_modified',
			'post_modified_gmt',
			'post_type',
		];

		$safe_columns = array_intersect( $columns, $allowed_columns );
		if ( empty( $safe_columns ) ) {
			$safe_columns = [ 'ID', 'post_content' ];
		}

		$columns_sql = implode( ', ', $safe_columns );
		$ids_sql     = implode( ',', array_map( 'intval', $post_ids ) );

		$posts = DB::get_results(
			"SELECT {$columns_sql}
			FROM {$wpdb->posts}
			WHERE ID IN ({$ids_sql})",
			OBJECT
		);

		// Index posts by ID for O(1) lookup.
		$posts_by_id = [];
		foreach ( $posts as $post ) {
			$posts_by_id[ $post->ID ] = $post;
		}

		return $posts_by_id;
	}

	/**
	 * Batch fetch links for multiple posts using Query_Builder.
	 *
	 * @param array $post_ids Array of post IDs.
	 * @param array $filters  Query filters (optional).
	 * @return array          Links grouped by post_id.
	 */
	public static function batch_fetch_links_by_post( $post_ids, $filters = [] ) {
		if ( empty( $post_ids ) ) {
			return [];
		}

		$query_args             = $filters;
		$query_args['post_ids'] = $post_ids;
		$query_args['per_page'] = 0; // Get all links.

		$all_links = Query_Builder::get_links( $query_args );

		// Group links by post_id for efficient processing.
		$links_by_post = [];
		foreach ( $all_links as $link ) {
			$links_by_post[ $link->post_id ][] = $link;
		}

		return $links_by_post;
	}

	/**
	 * Batch fetch postmeta for multiple posts.
	 *
	 * @param array  $post_ids  Array of post IDs.
	 * @param string $meta_key  Meta key to fetch.
	 * @return array            Meta values indexed by post_id.
	 */
	public static function batch_fetch_postmeta( $post_ids, $meta_key ) {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return [];
		}

		$ids_sql = implode( ',', array_map( 'intval', $post_ids ) );

		$results = DB::get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				AND post_id IN ({$ids_sql})",
				$meta_key
			),
			OBJECT_K
		);

		return $results;
	}

	/**
	 * Batch fetch snapshots for multiple posts.
	 *
	 * @param string $batch_id Batch ID.
	 * @param array  $post_ids Array of post IDs.
	 * @return array           Snapshots indexed by post_id.
	 */
	public static function batch_fetch_snapshots( $batch_id, $post_ids ) {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return [];
		}

		$placeholders = self::generate_placeholders( $post_ids, '%d' );
		$query        = "SELECT post_id, original_content, link_changes
			FROM {$wpdb->prefix}rank_math_link_genius_snapshots
			WHERE batch_id = %s AND post_id IN ({$placeholders})";

		$snapshots = DB::get_results(
			$wpdb->prepare( $query, $batch_id, ...$post_ids ),
			OBJECT_K // Key by post_id for O(1) lookup.
		);

		return $snapshots;
	}

	/**
	 * Batch insert snapshots.
	 *
	 * @param array $snapshots Array of snapshot data.
	 * @return int|false       Number of rows inserted or false on error.
	 */
	public static function batch_insert_snapshots( $snapshots ) {
		global $wpdb;

		if ( empty( $snapshots ) ) {
			return 0;
		}

		$columns      = [ 'batch_id', 'post_id', 'original_content', 'link_changes', 'created_at' ];
		$placeholders = [];
		$values       = [];

		foreach ( $snapshots as $snapshot ) {
			// Handle optional created_at field.
			$created_at = $snapshot['created_at'] ?? current_time( 'mysql' );

			$placeholders[] = '(%s, %d, %s, %s, %s)';
			$values         = array_merge(
				$values,
				[
					$snapshot['batch_id'],
					$snapshot['post_id'],
					$snapshot['original_content'],
					$snapshot['link_changes'],
					$created_at,
				]
			);
		}

		$sql = "INSERT INTO {$wpdb->prefix}rank_math_link_genius_snapshots
				(" . implode( ', ', $columns ) . ')
				VALUES ' . implode( ', ', $placeholders );

		return $wpdb->query( $wpdb->prepare( $sql, ...$values ) );
	}

	/**
	 * Cleanup old history records and their snapshots.
	 *
	 * @param string $source_type Source type to filter (e.g., 'bulk_update', 'keyword_map').
	 * @param int    $keep_count  Number of recent records to keep (default: 5).
	 * @return int                Number of records deleted.
	 */
	public static function cleanup_old_history( $source_type, $keep_count = 5 ) {
		global $wpdb;

		// Build WHERE clause based on source type.
		if ( 'bulk_update' === $source_type ) {
			$where_clause = "source_type = 'bulk_update' OR source_type IS NULL OR source_type = ''";
		} else {
			$where_clause = $wpdb->prepare( 'source_type = %s', $source_type );
		}

		// Get total count.
		$total_count = DB::get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_link_genius_history WHERE {$where_clause}"
		);

		if ( $total_count <= $keep_count ) {
			return 0; // Nothing to clean up.
		}

		// Get batch IDs to delete (keep only recent $keep_count records).
		$batch_ids_to_delete = DB::get_col(
			$wpdb->prepare(
				"SELECT batch_id FROM {$wpdb->prefix}rank_math_link_genius_history
				WHERE {$where_clause}
				ORDER BY created_at DESC
				LIMIT %d, 999999",
				$keep_count
			)
		);

		if ( empty( $batch_ids_to_delete ) ) {
			return 0;
		}

		$placeholders = self::generate_placeholders( $batch_ids_to_delete, '%s' );
		$snapshot_sql = "DELETE FROM {$wpdb->prefix}rank_math_link_genius_snapshots WHERE batch_id IN ({$placeholders})";
		$history_sql  = "DELETE FROM {$wpdb->prefix}rank_math_link_genius_history WHERE batch_id IN ({$placeholders})";

		// Delete snapshots.
		DB::query( $wpdb->prepare( $snapshot_sql, ...$batch_ids_to_delete ) );

		// Delete history.
		$deleted = DB::query( $wpdb->prepare( $history_sql, ...$batch_ids_to_delete ) );

		return $deleted;
	}

	/**
	 * Generate placeholders for wpdb->prepare() IN clause.
	 *
	 * @param array  $items Array of items.
	 * @param string $type  Placeholder type ('%d' for int, '%s' for string).
	 * @return string       Comma-separated placeholders.
	 */
	public static function generate_placeholders( $items, $type = '%d' ) {
		return implode( ',', array_fill( 0, count( $items ), $type ) );
	}

	/**
	 * Update post content and trigger link reprocessing.
	 *
	 * Handles both single and batch post content updates with:
	 * - Direct SQL UPDATE for performance
	 * - Automatic post_modified timestamp updates
	 * - Cache clearing
	 * - Link tracking reprocessing via Link_Processor
	 * - Page-builder content sync via extensible hooks (Elementor, etc.)
	 *
	 * @param array $posts_to_update Associative array of post_id => new_content.
	 * @return bool                  True on success, false on failure.
	 */
	public static function update_post_content( $posts_to_update ) {
		global $wpdb;

		if ( empty( $posts_to_update ) || ! is_array( $posts_to_update ) ) {
			return false;
		}

		$page_builder_old_content = [];
		foreach ( array_keys( $posts_to_update ) as $post_id ) {
			/**
			 * Before the SQL UPDATE, allow page-builder integrations to snapshot the
			 * current post_content so a diff can be computed after the update.
			 *
			 * @param false|string $old_content Return the current post_content string to
			 *                                  opt this post into page-builder syncing, or
			 *                                  leave as false to skip.
			 * @param int          $post_id     The post being updated.
			 */
			$old = apply_filters( 'rank_math/link_genius/page_builder_old_content', false, $post_id );
			if ( false !== $old ) {
				$page_builder_old_content[ $post_id ] = (string) $old;
			}
		}

		$now     = current_time( 'mysql' );
		$now_gmt = current_time( 'mysql', 1 );

		// Build CASE WHEN for post_content.
		$content_case_whens = [];
		foreach ( $posts_to_update as $id => $content ) {
			$content_case_whens[] = $wpdb->prepare( 'WHEN %d THEN %s', $id, $content );
		}

		$case_when_sql = implode( ' ', $content_case_whens );
		$post_ids_sql  = implode( ',', array_map( 'intval', array_keys( $posts_to_update ) ) );

		// Execute batch update.
		$result = DB::query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts}
				SET post_content = CASE ID {$case_when_sql} END,
					post_modified = %s,
					post_modified_gmt = %s
				WHERE ID IN ({$post_ids_sql})",
				$now,
				$now_gmt
			)
		);

		// Update link tracking for all updated posts and clear cache.
		if ( false !== $result ) {
			foreach ( $posts_to_update as $id => $new_content ) {
				if ( isset( $page_builder_old_content[ $id ] ) ) {
					$old_content   = $page_builder_old_content[ $id ];
					$added_links   = self::diff_links( $old_content, $new_content );
					$removed_links = self::diff_links( $new_content, $old_content );

					/**
					 * Fire page-builder sync action for posts that opted in via the filter.
					 * Provides the pre-computed link diff so integrations don't need to repeat it.
					 *
					 * @param int    $id            The updated post ID.
					 * @param string $old_content   post_content before the update.
					 * @param string $new_content   post_content after the update.
					 * @param array  $added_links   Links added (each: 'url', 'text', 'link').
					 * @param array  $removed_links Links removed (each: 'url', 'text', 'link').
					 */
					do_action( 'rank_math/link_genius/sync_page_builder_content', $id, $old_content, $new_content, $added_links, $removed_links );
				}

				Link_Processor::process( $id, $new_content );
			}
			return true;
		}

		return false;
	}

	/**
	 * Return links present in $to but absent in $from.
	 *
	 * Each entry contains the full link HTML ('link'), the href 'url', and the
	 * plain-text anchor 'text'. Public so page-builder integrations can reuse it.
	 *
	 * @param string $from Base content.
	 * @param string $to   Updated content.
	 * @return array
	 */
	public static function diff_links( $from, $to ) {
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $from, $from_matches, PREG_SET_ORDER );
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $to, $to_matches, PREG_SET_ORDER );

		$from_links = array_column( $from_matches, 0 );

		$diff = [];
		foreach ( $to_matches as $match ) {
			if ( ! in_array( $match[0], $from_links, true ) ) {
				$diff[] = [
					'url'  => $match[1],
					'text' => wp_strip_all_tags( $match[2] ),
					'link' => $match[0],
				];
			}
		}

		return $diff;
	}
}
