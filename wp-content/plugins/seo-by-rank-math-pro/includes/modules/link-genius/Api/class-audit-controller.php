<?php
/**
 * Audit REST API controller.
 *
 * Handles audit-related endpoints: stats, start, recheck, mark-safe.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Api
 */

namespace RankMathPro\Link_Genius\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use RankMath\Helpers\DB;
use RankMathPro\Link_Genius\Data\Query_Builder;
use RankMathPro\Link_Genius\Background\Link_Status_Crawler;
use RankMathPro\Link_Genius\Background\Bulk_Link_Modifier;

defined( 'ABSPATH' ) || exit;

/**
 * Audit_Controller class.
 *
 * Provides REST endpoints for link audit operations.
 */
class Audit_Controller extends Base_Controller {

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// GET /audit/stats - Get audit statistics.
		register_rest_route(
			$this->namespace,
			'/audit/stats',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_audit_stats' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		// POST /audit/start - Start link audit crawl.
		register_rest_route(
			$this->namespace,
			'/audit/start',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'start_audit' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'filters' => [
						'description' => esc_html__( 'Filters to apply (is_internal, unchecked_only, etc.).', 'rank-math-pro' ),
						'type'        => 'object',
						'required'    => false,
					],
				],
			]
		);

		// POST /audit/recheck - Recheck specific links.
		register_rest_route(
			$this->namespace,
			'/audit/recheck',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'recheck_links' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'link_ids' => [
						'description' => esc_html__( 'Array of link IDs to recheck.', 'rank-math-pro' ),
						'type'        => 'array',
						'required'    => false,
					],
					'filters'  => [
						'description' => esc_html__( 'Filter criteria for selecting links to recheck.', 'rank-math-pro' ),
						'type'        => 'object',
						'required'    => false,
					],
				],
			]
		);

		// POST /audit/mark-safe - Mark link(s) as safe.
		register_rest_route(
			$this->namespace,
			'/audit/mark-safe',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'mark_link_safe' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'link_ids' => [
						'description' => esc_html__( 'Link IDs to mark as safe.', 'rank-math-pro' ),
						'type'        => 'array',
						'required'    => false,
					],
					'reason'   => [
						'description' => esc_html__( 'Reason for marking as safe.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => false,
					],
					'filters'  => [
						'description' => esc_html__( 'Filter criteria for selecting links to mark as safe.', 'rank-math-pro' ),
						'type'        => 'object',
						'required'    => false,
					],
				],
			]
		);
	}

	/**
	 * Get audit statistics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_audit_stats( $request ) {
		$stats = Query_Builder::get_audit_stats();

		return rest_ensure_response(
			[
				'success'        => true,
				'stats'          => $stats,
				'has_run_before' => Query_Builder::has_audit_run_before(),
			]
		);
	}

	/**
	 * Start audit crawl.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function start_audit( $request ) {
		$filters = $request->get_param( 'filters' ) ?? [];
		$crawler = Link_Status_Crawler::get();

		if ( $crawler->is_active() ) {
			return $this->error(
				'audit_already_running',
				__( 'An audit is already in progress.', 'rank-math-pro' ),
				409
			);
		}

		$crawler->start( $filters );

		$progress = Link_Status_Crawler::get_progress();

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button'       => 'Start Link Audit',
				'total_links'  => $progress['total'] ?? 0,
				'has_filters'  => ! empty( $filters ) ? 1 : 0,
				'filter_count' => count( $filters ),
			]
		);

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Audit started successfully.', 'rank-math-pro' ),
				'total'   => $progress['total'] ?? 0,
				'filters' => $filters,
			],
			200
		);
	}

	/**
	 * Recheck specific links.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function recheck_links( $request ) {
		$link_ids = $request->get_param( 'link_ids' );
		$filters  = $request->get_param( 'filters' );

		if ( empty( $link_ids ) && empty( $filters ) ) {
			return $this->error(
				'invalid_params',
				__( 'Either link_ids or filters must be provided.', 'rank-math-pro' ),
				400
			);
		}

		$links = [];
		if ( ! empty( $filters ) ) {
			$query_args = $filters;
			unset( $query_args['filter'] );
			unset( $query_args['page'] );
			unset( $query_args['per_page'] );
			$query_args['per_page'] = 0;

			$all_links = Query_Builder::get_links( $query_args );

			foreach ( $all_links as $link ) {
				$links[] = [
					'id'       => $link->id,
					'url'      => $link->url,
					'url_hash' => $link->url_hash,
					'type'     => $link->type,
				];
			}
		} elseif ( ! empty( $link_ids ) && is_array( $link_ids ) ) {
			global $wpdb;

			$links_table  = $wpdb->prefix . 'rank_math_internal_links';
			$link_ids     = array_map( 'intval', $link_ids );
			$placeholders = implode( ',', array_fill( 0, count( $link_ids ), '%d' ) );
			$query        = "SELECT id, url, url_hash, type FROM `{$links_table}` WHERE id IN ({$placeholders})";

			$links = DB::get_results( $wpdb->prepare( $query, ...$link_ids ), ARRAY_A );
		} else {
			return $this->error(
				'invalid_link_ids',
				__( 'Invalid link IDs provided.', 'rank-math-pro' ),
				400
			);
		}

		if ( empty( $links ) ) {
			return $this->error(
				'links_not_found',
				__( 'No links found with the provided parameters.', 'rank-math-pro' ),
				404
			);
		}

		$is_bulk = ( count( $links ) > 1 ) || ! empty( $filters );

		if ( $is_bulk ) {
			$crawler = Link_Status_Crawler::get();

			// Save in chunks of 100 — each chunk becomes its own batch row,
			// preventing large wp_options writes that cause binlog growth.
			foreach ( array_chunk( $links, 100 ) as $chunk ) {
				foreach ( $chunk as $link ) {
					$crawler->push_to_queue(
						[
							'link_id'  => $link['id'],
							'url'      => $link['url'],
							'url_hash' => $link['url_hash'],
							'type'     => $link['type'],
						]
					);
				}
				$crawler->save();
			}
			$crawler->update_progress_tracking();

			if ( ! wp_next_scheduled( 'rank_math_link_genius_dispatch_crawler' ) ) {
				wp_schedule_single_event( time() + 2, 'rank_math_link_genius_dispatch_crawler' );
			}

			$this->track_link_genius_event(
				'Button Clicked',
				[
					'button'     => 'Recheck Links',
					'link_count' => count( $links ),
					'is_bulk'    => 1,
					'via_filter' => ! empty( $filters ) ? 1 : 0,
				]
			);

			return new WP_REST_Response(
				[
					'success' => true,
					'message' => sprintf(
						/* translators: 1: Number of links queued. */
						_n(
							'%d link queued for rechecking.',
							'%d links queued for rechecking.',
							count( $links ),
							'rank-math-pro'
						),
						count( $links )
					),
					'count'   => count( $links ),
					'queued'  => true,
				],
				200
			);
		}

		// Single link - process immediately.
		$link            = $links[0];
		$check_succeeded = Link_Status_Crawler::check_link_immediately( $link );

		if ( $check_succeeded ) {
			$this->track_link_genius_event(
				'Button Clicked',
				[
					'button'     => 'Recheck Links',
					'link_count' => 1,
					'is_bulk'    => 0,
					'via_filter' => 0,
				]
			);

			return new WP_REST_Response(
				[
					'success' => true,
					'message' => __( 'Link rechecked successfully.', 'rank-math-pro' ),
				],
				200
			);
		}

		return new WP_REST_Response(
			[
				'success' => false,
				'message' => __( 'Failed to recheck link. The URL may be unreachable.', 'rank-math-pro' ),
			],
			200
		);
	}

	/**
	 * Mark link(s) as safe (not broken).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function mark_link_safe( $request ) {
		$link_ids = $request->get_param( 'link_ids' );
		$link_ids = ! empty( $link_ids ) && is_array( $link_ids ) ? array_map( 'intval', $link_ids ) : [];
		$filters  = $request->get_param( 'filters' );

		// Validate parameters.
		if ( empty( $link_ids ) && empty( $filters ) ) {
			return $this->error(
				'invalid_params',
				__( 'Either link_ids or filters must be provided.', 'rank-math-pro' ),
				400
			);
		}

		// Determine if this is a bulk operation (multiple IDs or filters provided).
		$reason         = $request->get_param( 'reason' ) ?? '';
		$is_marked_safe = $request->get_param( 'is_marked_safe' );
		$is_marked_safe = $is_marked_safe === null ? 1 : (int) $is_marked_safe;
		$is_bulk        = count( $link_ids ) > 1 || ! empty( $filters );

		if ( $is_bulk ) {
			$processor = Bulk_Link_Modifier::get();

			if ( $processor->is_active() ) {
				return $this->error(
					'mark_safe_in_progress',
					__( 'A bulk mark safe operation is already in progress.', 'rank-math-pro' ),
					409
				);
			}

			$processor->start(
				'mark_safe',
				[
					'filters'        => $filters,
					'link_ids'       => $link_ids,
					'reason'         => ! empty( $reason ) ? $reason : __( 'Bulk marked as safe', 'rank-math-pro' ),
					'is_marked_safe' => $is_marked_safe,
				]
			);

			$this->track_link_genius_event(
				'Button Clicked',
				[
					'button'         => 'Mark Link Safe',
					'is_marked_safe' => $is_marked_safe,
					'is_bulk'        => 1,
					'link_count'     => count( $link_ids ),
					'has_filters'    => ! empty( $filters ) ? 1 : 0,
					'has_reason'     => ! empty( $reason ) ? 1 : 0,
				]
			);

			return rest_ensure_response(
				[
					'success' => true,
					'queued'  => true,
					'message' => __( 'Bulk mark safe queued for background processing.', 'rank-math-pro' ),
				]
			);
		}

		// Single link - process synchronously.
		global $wpdb;
		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';

		$updated_count = 0;
		$failed_count  = 0;

		if ( $is_marked_safe ) {
			$update_data = [
				'is_marked_safe'     => 1,
				'last_error_message' => $reason ? 'Marked as safe: ' . $reason : 'Marked as safe by user',
			];
		} else {
			$update_data = [
				'is_marked_safe'     => 0,
				'last_error_message' => null,
			];
		}

		foreach ( $link_ids as $link_id ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM `{$status_table}` WHERE link_id = %d",
					$link_id
				)
			);

			if ( ! $existing ) {
				++$failed_count;
				continue;
			}

			$updated = $wpdb->update(
				$status_table,
				$update_data,
				[ 'link_id' => $link_id ],
				[ '%d', '%s' ],
				[ '%d' ]
			);

			if ( false !== $updated ) {
				++$updated_count;
			} else {
				++$failed_count;
			}
		}

		Query_Builder::invalidate_cache();

		if ( $updated_count > 0 ) {
			$message = $is_marked_safe
				? __( 'Link marked as not broken successfully.', 'rank-math-pro' )
				: __( 'Link status reverted successfully.', 'rank-math-pro' );

			$this->track_link_genius_event(
				'Button Clicked',
				[
					'button'         => 'Mark Link Safe',
					'is_marked_safe' => $is_marked_safe,
					'is_bulk'        => 0,
					'has_reason'     => ! empty( $reason ) ? 1 : 0,
				]
			);
		} else {
			return $this->error(
				'update_failed',
				__( 'No status record found for this link.', 'rank-math-pro' ),
				404
			);
		}

		return new WP_REST_Response(
			[
				'success'       => true,
				'message'       => $message,
				'updated_count' => $updated_count,
				'failed_count'  => $failed_count,
				'total_count'   => count( $link_ids ),
			],
			200
		);
	}
}
