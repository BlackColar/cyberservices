<?php
/**
 * Query Builder for Link Genius.
 *
 * Provides common query building methods for links and posts data.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Data;

use RankMathPro\Link_Genius\Services\Batch_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Query_Builder class.
 */
class Query_Builder {

	/**
	 * Cache group name for object caching.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'rank_math_link_genius';

	/**
	 * Invalidate all Link Genius caches.
	 *
	 * Call this when links or posts data changes.
	 */
	public static function invalidate_cache() {
		wp_cache_flush_group( self::CACHE_GROUP );
	}

	/**
	 * Build and execute links query.
	 *
	 * @param array $args Query arguments.
	 * @return array Query results.
	 */
	public static function get_links( $args = [] ) {
		// Check cache first.
		$cached = self::get_cached( 'links', $args );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$defaults = [
			'search'          => '',
			'source_id'       => 0,
			'target_post_id'  => 0,
			'is_internal'     => '',
			'is_nofollow'     => '',
			'anchor_type'     => '',
			'target_blank'    => '',
			'post_type'       => '',
			'link_ids'        => [],
			'date_from'       => '',
			'date_to'         => '',
			'orderby'         => 'created_at',
			'order'           => 'DESC',
			'offset'          => 0,
			'per_page'        => 0, // 0 means no limit.
			'cursor_after_id' => 0,
		];

		$args  = wp_parse_args( $args, $defaults );
		$table = $wpdb->prefix . 'rank_math_internal_links';

		// Build WHERE clause using shared method.
		$where_sql = self::build_links_where( $args );

		// Build ORDER BY clause.
		$orderby_map = [
			'id'           => 'l.id',
			'source_title' => 'sp.post_title',
			'url'          => 'l.url',
			'anchor_text'  => 'l.anchor_text',
			'anchor_type'  => 'l.anchor_type',
			'type'         => 'l.type',
			'target_blank' => 'l.target_blank',
			'created_at'   => 'l.created_at',
			'status'       => 'ls.status_category',
		];

		$order       = self::validate_order( $args['order'], 'DESC' );
		$orderby_sql = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'l.created_at';
		$limit_sql   = self::build_limit_clause( $args['per_page'], $args['offset'] );

		// Execute query with status table JOIN.
		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';
		$results      = $wpdb->get_results(
			"SELECT l.*,
					sp.post_title as source_title,
					tp.post_title as target_title,
					ls.http_status_code,
					ls.status_category,
					ls.robots_blocked,
					ls.is_marked_safe,
					ls.last_checked_at,
					ls.last_error_message
			 FROM {$table} l
			 LEFT JOIN {$wpdb->posts} sp ON l.post_id = sp.ID
			 LEFT JOIN {$wpdb->posts} tp ON l.target_post_id = tp.ID
			 LEFT JOIN {$status_table} ls ON l.id = ls.link_id
			 WHERE {$where_sql}
			 ORDER BY {$orderby_sql} {$order}
			 {$limit_sql}"
		);

		// Process results to add source and target URLs.
		foreach ( $results as $row ) {
			// Add source post URL (relative path without home_url).
			$row->source_url = self::get_relative_permalink( $row->post_id );

			$row->last_checked_at_display = $row->last_checked_at ? date( 'M d, Y • h:i A', strtotime( $row->last_checked_at ) ) : null;

			// Add source post edit URL.
			$row->source_edit_url = $row->post_id ? get_edit_post_link( $row->post_id, '&' ) : '';

			// Add target post URL (relative path without home_url) for internal links.
			$row->target_url = ( 'internal' === $row->type ) ? self::get_relative_permalink( $row->target_post_id ) : '';
		}

		// Cache the results.
		self::set_cached( 'links', $args, $results );

		return $results;
	}

	/**
	 * Get total count of links matching the filters.
	 *
	 * @param array $args Query arguments.
	 * @return int Total count.
	 */
	public static function get_links_count( $args = [] ) {
		// Check cache first.
		$cached = self::get_cached( 'links_count', $args );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$defaults = [
			'search'         => '',
			'source_id'      => 0,
			'target_post_id' => 0,
			'is_internal'    => '',
			'is_nofollow'    => '',
		];

		$args = wp_parse_args( $args, $defaults );

		$table        = $wpdb->prefix . 'rank_math_internal_links';
		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';

		// Build WHERE clause using shared method.
		$where_sql = self::build_links_where( $args );

		// Only JOIN wp_posts when filters reference the sp table (post_type, date).
		$needs_posts_join = ! empty( $args['post_type'] ) || ! empty( $args['date_from'] ) || ! empty( $args['date_to'] );
		$posts_join       = $needs_posts_join ? "LEFT JOIN {$wpdb->posts} sp ON l.post_id = sp.ID" : '';

		// Execute query with conditional JOINs.
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$table} l
			 {$posts_join}
			 LEFT JOIN {$status_table} ls ON l.id = ls.link_id
			 WHERE {$where_sql}"
		);

		// Cache the count.
		self::set_cached( 'links_count', $args, $count );

		return $count;
	}

	/**
	 * Build and execute posts query.
	 *
	 * @param array $args Query arguments.
	 * @return array Query results.
	 */
	public static function get_posts( $args = [] ) {
		// Check cache first.
		$cached = self::get_cached( 'posts', $args );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$defaults = [
			'search'          => '',
			'post_type'       => '',
			'is_orphan'       => '',
			'seo_score_range' => '',
			'orderby'         => 'post_title',
			'order'           => 'ASC',
			'offset'          => 0,
			'per_page'        => 0, // 0 means no limit.
		];

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clause using shared method.
		$where_sql = self::build_posts_where( $args );

		// Build ORDER BY clause.
		$orderby_map = [
			'post_title'          => 'p.post_title',
			'internal_link_count' => 'm.internal_link_count',
			'external_link_count' => 'm.external_link_count',
			'incoming_link_count' => 'm.incoming_link_count',
			'seo_score'           => 'pm_score.meta_value',
			'post_modified'       => 'p.post_modified',
		];

		$order       = self::validate_order( $args['order'], 'ASC' );
		$orderby_sql = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'p.post_title';
		$limit_sql   = self::build_limit_clause( $args['per_page'], $args['offset'] );

		// Execute query.
		$links_table = $wpdb->prefix . 'rank_math_internal_links';

		$results = $wpdb->get_results(
			"SELECT p.ID as post_id,
					p.post_title,
					p.post_type,
					p.post_modified,
					m.internal_link_count,
					m.external_link_count,
					m.incoming_link_count,
					pm_score.meta_value as seo_score,
					pm_keyword.meta_value as focus_keyword,
					pm_robots.meta_value as robots,
					COALESCE(nf.nofollow_count, 0) as internal_nofollow_count
			 FROM {$wpdb->prefix}rank_math_internal_meta m
			 INNER JOIN {$wpdb->posts} p ON m.object_id = p.ID
			 LEFT JOIN {$wpdb->postmeta} pm_score ON p.ID = pm_score.post_id AND pm_score.meta_key = 'rank_math_seo_score'
			 LEFT JOIN {$wpdb->postmeta} pm_keyword ON p.ID = pm_keyword.post_id AND pm_keyword.meta_key = 'rank_math_focus_keyword'
			 LEFT JOIN {$wpdb->postmeta} pm_robots ON p.ID = pm_robots.post_id AND pm_robots.meta_key = 'rank_math_robots'
			 LEFT JOIN (
				SELECT post_id, COUNT(*) as nofollow_count
				FROM {$links_table}
				WHERE type = 'internal'
				AND is_nofollow = 1
				GROUP BY post_id
			 ) nf ON p.ID = nf.post_id
			 WHERE {$where_sql}
			 ORDER BY {$orderby_sql} {$order}
			 {$limit_sql}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// Process results to add SEO score class and orphan status.
		foreach ( $results as $row ) {
			$row->internal_link_count     = (int) ( $row->internal_link_count ?? 0 );
			$row->external_link_count     = (int) ( $row->external_link_count ?? 0 );
			$row->incoming_link_count     = (int) ( $row->incoming_link_count ?? 0 );
			$row->seo_score               = $row->seo_score ? (int) $row->seo_score : 0;
			$row->is_orphan               = $row->incoming_link_count === 0;
			$row->edit_url                = get_edit_post_link( $row->post_id, '&' );
			$robots                       = maybe_unserialize( $row->robots );
			$row->is_noindex              = is_array( $robots ) && in_array( 'noindex', $robots, true );
			$row->has_internal_nofollow   = ( (int) $row->internal_nofollow_count > 0 );
			$row->internal_nofollow_count = (int) $row->internal_nofollow_count;
			$row->post_type               = ucfirst( $row->post_type );

			// Add post URL (relative path without home_url).
			$row->post_url = self::get_relative_permalink( $row->post_id );

			unset( $row->robots );
		}

		// Cache the results.
		self::set_cached( 'posts', $args, $results );

		return $results;
	}

	/**
	 * Get total count of posts matching the filters.
	 *
	 * @param array $args Query arguments.
	 * @return int Total count.
	 */
	public static function get_posts_count( $args = [] ) {
		// Check cache first.
		$cached = self::get_cached( 'posts_count', $args );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$defaults = [
			'search'          => '',
			'post_type'       => '',
			'is_orphan'       => '',
			'seo_score_range' => '',
		];

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clause using shared method.
		$where_sql = self::build_posts_where( $args );

		// Build JOIN clause - start from rank_math_internal_meta and join posts.
		$joins = "INNER JOIN {$wpdb->posts} p ON m.object_id = p.ID";

		// Only join postmeta tables if needed by SEO score filters.
		if ( ! empty( $args['seo_score_range'] ) ) {
			$joins .= " LEFT JOIN {$wpdb->postmeta} pm_score ON p.ID = pm_score.post_id AND pm_score.meta_key = 'rank_math_seo_score'";
			$joins .= " LEFT JOIN {$wpdb->postmeta} pm_keyword ON p.ID = pm_keyword.post_id AND pm_keyword.meta_key = 'rank_math_focus_keyword'";
		}

		// Execute query.
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT m.object_id)
			 FROM {$wpdb->prefix}rank_math_internal_meta m
			 {$joins}
			 WHERE {$where_sql}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// Cache the count.
		self::set_cached( 'posts_count', $args, $count );

		return $count;
	}

	/**
	 * Get link audit statistics.
	 *
	 * Returns counts grouped by status category for dashboard display.
	 *
	 * @return array Statistics array.
	 */
	public static function get_audit_stats() {
		// Check cache first.
		$cached = self::get_cached( 'audit_stats', [] );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$links_table  = $wpdb->prefix . 'rank_math_internal_links';
		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';

		// Get counts grouped by status category.
		$category_counts = $wpdb->get_results(
			"SELECT
				ls.status_category,
				COUNT(*) as count
			FROM {$links_table} l
			INNER JOIN {$status_table} ls ON l.id = ls.link_id
			WHERE ls.status_category IS NOT NULL
			GROUP BY ls.status_category",
			ARRAY_A
		);

		// Get counts for special categories.
		// Count all unchecked link records (not DISTINCT url_hash) to match get_links() behavior.
		$unchecked_count = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$links_table} l
			LEFT JOIN {$status_table} ls ON l.id = ls.link_id
			WHERE ls.id IS NULL"
		);

		$robots_blocked_count = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$links_table} l
			INNER JOIN {$status_table} ls ON l.id = ls.link_id
			WHERE ls.robots_blocked = 1"
		);

		$marked_safe_count = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$links_table} l
			INNER JOIN {$status_table} ls ON l.id = ls.link_id
			WHERE ls.is_marked_safe = 1"
		);

		// Get last crawl time.
		$last_crawl = $wpdb->get_var(
			"SELECT MAX(last_checked_at)
			FROM {$status_table}"
		);

		// Build stats array.
		$stats = [
			'total_checked'  => 0,
			'status_2xx'     => 0,
			'status_3xx'     => 0,
			'status_4xx'     => 0,
			'status_5xx'     => 0,
			'timeout'        => 0,
			'error'          => 0,
			'blocked'        => 0,
			'unchecked'      => $unchecked_count,
			'robots_blocked' => $robots_blocked_count,
			'marked_safe'    => $marked_safe_count,
			'last_crawl_at'  => $last_crawl,
		];

		// Map category counts to stats.
		foreach ( $category_counts as $row ) {
			$category = $row['status_category'];
			$count    = (int) $row['count'];

			switch ( $category ) {
				case '2xx':
					$stats['status_2xx'] = $count;
					break;
				case '3xx':
					$stats['status_3xx'] = $count;
					break;
				case '4xx':
					$stats['status_4xx'] = $count;
					break;
				case '5xx':
					$stats['status_5xx'] = $count;
					break;
				case 'timeout':
					$stats['timeout'] = $count;
					break;
				case 'error':
					$stats['error'] = $count;
					break;
				case 'blocked':
					$stats['blocked'] = $count;
					break;
			}

			$stats['total_checked'] += $count;
		}

		// Cache the stats.
		self::set_cached( 'audit_stats', [], $stats );

		return $stats;
	}

	/**
	 * Check if link audit has run before.
	 *
	 * Returns true if the rank_math_link_genius_audit table has any entries.
	 *
	 * @return bool True if audit has run before, false otherwise.
	 */
	public static function has_audit_run_before() {
		global $wpdb;
		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';

		// Check if table exists first.
		if ( ! \RankMath\Helpers\DB::check_table_exists( 'rank_math_link_genius_audit' ) ) {
			return false;
		}

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$status_table} LIMIT 1" );
		return $count > 0;
	}

	/**
	 * Validate and normalize ORDER direction.
	 *
	 * @param string $order          Order direction from arguments.
	 * @param string $default_order  Default order if invalid. Default 'DESC'.
	 * @return string Validated ORDER direction (ASC or DESC).
	 */
	private static function validate_order( $order, $default_order = 'DESC' ) {
		$order = strtoupper( $order );
		return in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : $default_order;
	}

	/**
	 * Build LIMIT clause for pagination.
	 *
	 * @param int $per_page Items per page (0 means no limit).
	 * @param int $offset   Offset for pagination.
	 * @return string LIMIT clause SQL or empty string if no limit.
	 */
	private static function build_limit_clause( $per_page, $offset ) {
		global $wpdb;

		if ( $per_page > 0 ) {
			return $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );
		}

		return '';
	}

	/**
	 * Get relative permalink without home_url.
	 *
	 * @param int $post_id Post ID.
	 * @return string Relative URL or empty string if permalink not found.
	 */
	private static function get_relative_permalink( $post_id ) {
		if ( empty( $post_id ) ) {
			return '';
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return '';
		}

		return str_replace( untrailingslashit( home_url() ), '', $permalink );
	}

	/**
	 * Build WHERE clause for links queries.
	 *
	 * Shared by both get_links() and get_links_count() to ensure consistency.
	 *
	 * @param array $args Query arguments.
	 * @return string WHERE clause SQL.
	 */
	private static function build_links_where( $args ) {
		global $wpdb;

		$where = [ '1=1' ];

		if ( ! empty( $args['source_id'] ) ) {
			$where[] = $wpdb->prepare( 'l.post_id = %d', $args['source_id'] );
		}

		if ( ! empty( $args['target_post_id'] ) ) {
			$where[] = $wpdb->prepare( 'l.target_post_id = %d', $args['target_post_id'] );
		}

		if ( isset( $args['is_internal'] ) && '' !== $args['is_internal'] ) {
			$is_internal = '1' === $args['is_internal'];
			$where[]     = "l.type = '" . ( $is_internal ? 'internal' : 'external' ) . "'";
		}

		if ( isset( $args['is_nofollow'] ) && '' !== $args['is_nofollow'] ) {
			$is_nofollow = '1' === $args['is_nofollow'];
			$where[]     = $wpdb->prepare( 'l.is_nofollow = %d', $is_nofollow ? 1 : 0 );
		}

		if ( ! empty( $args['anchor_type'] ) ) {
			$where[] = $wpdb->prepare( 'l.anchor_type = %s', $args['anchor_type'] );
		}

		if ( isset( $args['target_blank'] ) && '' !== $args['target_blank'] ) {
			$target_blank = '1' === $args['target_blank'];
			$where[]      = $wpdb->prepare( 'l.target_blank = %d', $target_blank ? 1 : 0 );
		}

		if ( ! empty( $args['post_type'] ) && is_array( $args['post_type'] ) ) {
			$post_types   = array_map( 'sanitize_text_field', $args['post_type'] );
			$post_types   = array_filter( $post_types );
			$placeholders = Batch_Helper::generate_placeholders( $post_types, '%s' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$where[] = $wpdb->prepare( "sp.post_type IN ($placeholders)", ...$post_types );
		}

		if ( ! empty( $args['link_ids'] ) && is_array( $args['link_ids'] ) ) {
			$link_ids_safe = array_map( 'absint', $args['link_ids'] );
			if ( ! empty( $link_ids_safe ) ) {
				$placeholders = Batch_Helper::generate_placeholders( $link_ids_safe, '%d' );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$where[] = $wpdb->prepare( "l.id IN ($placeholders)", ...$link_ids_safe );
			}
		}

		if ( ! empty( $args['search'] ) ) {
			$search_term = $args['search'];

			// Use LIKE search for anchor text and URL.
			$where[] = $wpdb->prepare(
				'(l.anchor_text LIKE %s OR l.url LIKE %s)',
				'%' . $wpdb->esc_like( $search_term ) . '%',
				'%' . $wpdb->esc_like( $search_term ) . '%'
			);
		}

		// Handle status_codes (always array for multi-select).
		if ( ! empty( $args['status_codes'] ) && is_array( $args['status_codes'] ) ) {
			$status_codes_safe = array_map( 'absint', $args['status_codes'] );
			if ( ! empty( $status_codes_safe ) ) {
				$placeholders = Batch_Helper::generate_placeholders( $status_codes_safe, '%d' );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$where[] = $wpdb->prepare( "ls.http_status_code IN ($placeholders)", ...$status_codes_safe );
			}
		}

		$status_category = ! empty( $args['status_category'] ) ? $args['status_category'] : '';
		if ( 'unchecked' === $status_category ) {
			$args['unchecked']       = '1';
			$args['status_category'] = '';
		} elseif ( 'broken' === $status_category ) {
			$args['is_broken']       = true;
			$args['status_category'] = '';
		} elseif ( 'marked_safe' === $status_category ) {
			$args['is_marked_safe']  = '1';
			$args['status_category'] = '';
		}

		if ( ! empty( $args['status_category'] ) ) {
			$where[] = $wpdb->prepare( 'ls.status_category = %s', $args['status_category'] );
		}

		if ( ! empty( $args['is_broken'] ) ) {
			$where[] = "ls.status_category IN ('4xx', '5xx', 'timeout', 'error')";
			$where[] = 'ls.is_marked_safe = 0';
		}

		if ( ! empty( $args['is_success'] ) ) {
			$where[] = "ls.status_category IN ('2xx')";
		}

		if ( ! empty( $args['is_redirect'] ) ) {
			$where[] = "ls.status_category = '3xx'";
		}

		if ( ! empty( $args['robots_blocked'] ) ) {
			$where[] = 'ls.robots_blocked = 1';
		}

		if ( ! empty( $args['unchecked'] ) ) {
			$where[] = 'ls.id IS NULL';
		}

		if ( ! empty( $args['is_marked_safe'] ) ) {
			$where[] = 'ls.is_marked_safe = 1';
		}

		// Date range filter based on post publication date.
		if ( ! empty( $args['date_from'] ) ) {
			$where[] = $wpdb->prepare( 'sp.post_date >= %s', $args['date_from'] . ' 00:00:00' );
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[] = $wpdb->prepare( 'sp.post_date <= %s', $args['date_to'] . ' 23:59:59' );
		}

		// Cursor-based pagination for performance (avoid OFFSET on large datasets).
		if ( ! empty( $args['cursor_after_id'] ) ) {
			$where[] = $wpdb->prepare( 'l.id > %d', $args['cursor_after_id'] );
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Build WHERE clause for posts queries.
	 *
	 * Shared by both get_posts() and get_posts_count() to ensure consistency.
	 *
	 * @param array $args Query arguments.
	 * @return string WHERE clause SQL.
	 */
	private static function build_posts_where( $args ) {
		global $wpdb;

		$where = [ "p.post_status = 'publish'" ];

		// Filter by post type (always array for multi-select).
		if ( ! empty( $args['post_type'] ) && is_array( $args['post_type'] ) ) {
			$post_types = array_map( 'sanitize_text_field', $args['post_type'] );
			$post_types = array_filter( $post_types );
			if ( ! empty( $post_types ) ) {
				$placeholders = Batch_Helper::generate_placeholders( $post_types, '%s' );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$where[] = $wpdb->prepare( "p.post_type IN ($placeholders)", ...$post_types );
			}
		} else {
			$where[] = "p.post_type IN ('post', 'page')";
		}

		// Filter by orphan status.
		if ( isset( $args['is_orphan'] ) && '' !== $args['is_orphan'] ) {
			if ( 'orphan' === $args['is_orphan'] ) {
				$where[] = '(m.incoming_link_count IS NULL OR m.incoming_link_count = 0)';
			} elseif ( 'linked' === $args['is_orphan'] ) {
				$where[] = 'm.incoming_link_count > 0';
			}
		}

		// Filter by SEO score range.
		if ( ! empty( $args['seo_score_range'] ) ) {
			switch ( $args['seo_score_range'] ) {
				case 'great':
					$where[] = 'CAST(pm_score.meta_value AS UNSIGNED) > 80';
					break;
				case 'good':
					$where[] = 'CAST(pm_score.meta_value AS UNSIGNED) BETWEEN 51 AND 80';
					break;
				case 'bad':
					$where[] = 'CAST(pm_score.meta_value AS UNSIGNED) <= 50';
					break;
				case 'no-score':
					$where[] = "(pm_score.meta_value IS NULL OR pm_score.meta_value = '')";
					break;
			}
		}

		// Filter by search term.
		if ( ! empty( $args['search'] ) ) {
			$where[] = $wpdb->prepare(
				'p.post_title LIKE %s',
				'%' . $wpdb->esc_like( $args['search'] ) . '%'
			);
		}

		// Filter by minimum incoming links count.
		if ( ! empty( $args['min_incoming_links'] ) ) {
			$where[] = $wpdb->prepare( 'm.incoming_link_count >= %d', $args['min_incoming_links'] );
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Get cache TTL (time to live) in seconds.
	 *
	 * @return int Cache TTL in seconds.
	 */
	private static function get_cache_ttl() {
		/**
		 * Filter: Allow developers to change cache TTL.
		 *
		 * @param int $ttl Cache time-to-live in seconds. Default 1800 (30 minutes).
		 */
		return (int) apply_filters( 'rank_math/link_genius/cache_ttl', 30 * MINUTE_IN_SECONDS );
	}

	/**
	 * Generate cache key from query arguments.
	 *
	 * @param string $prefix Prefix for the cache key.
	 * @param array  $args   Query arguments.
	 * @return string Cache key.
	 */
	private static function get_cache_key( $prefix, $args ) {
		return $prefix . '_' . md5( wp_json_encode( $args ) );
	}

	/**
	 * Get cached data if available.
	 *
	 * @param string $prefix Prefix for the cache key.
	 * @param array  $args   Query arguments.
	 * @return mixed|false Cached data or false if not found.
	 */
	private static function get_cached( $prefix, $args ) {
		$cache_key = self::get_cache_key( $prefix, $args );
		return wp_cache_get( $cache_key, self::CACHE_GROUP );
	}

	/**
	 * Set data in cache.
	 *
	 * @param string $prefix Prefix for the cache key.
	 * @param array  $args   Query arguments.
	 * @param mixed  $data   Data to cache.
	 */
	private static function set_cached( $prefix, $args, $data ) {
		$cache_key = self::get_cache_key( $prefix, $args );
		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::get_cache_ttl() );
	}
}
