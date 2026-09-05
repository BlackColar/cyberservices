<?php
/**
 * Links REST API controller.
 *
 * Handles link management endpoints: list, details, delete, update, remove-nofollow, undo-delete.
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
use RankMathPro\Link_Genius\Data\Link_Updater;
use RankMathPro\Link_Genius\Services\Batch_Helper;
use RankMathPro\Link_Genius\Background\Bulk_Link_Modifier;

defined( 'ABSPATH' ) || exit;

/**
 * Links_Controller class.
 *
 * Provides REST endpoints for link management operations.
 */
class Links_Controller extends Base_Controller {

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// GET /links - Get paginated links.
		register_rest_route(
			$this->namespace,
			'/links',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_links' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => $this->get_links_args(),
			]
		);

		// GET /links/{id}/details - Get detailed link information.
		register_rest_route(
			$this->namespace,
			'/links/(?P<id>\d+)/details',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_link_details' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'id' => [
						'description' => esc_html__( 'Link ID.', 'rank-math-pro' ),
						'type'        => 'integer',
						'required'    => true,
					],
				],
			]
		);

		// POST /delete - Delete link(s).
		register_rest_route(
			$this->namespace,
			'/delete',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'delete_links' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'link_ids' => [
						'description' => esc_html__( 'Link IDs to delete.', 'rank-math-pro' ),
						'type'        => 'array',
						'required'    => false,
					],
					'filters'  => [
						'description' => esc_html__( 'Filter criteria for selecting links to delete.', 'rank-math-pro' ),
						'type'        => 'object',
						'required'    => false,
					],
				],
			]
		);

		// POST /undo-delete - Undo link deletion.
		register_rest_route(
			$this->namespace,
			'/undo-delete',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'undo_delete_link' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'post_id'          => [
						'description' => esc_html__( 'Post ID to restore.', 'rank-math-pro' ),
						'type'        => 'integer',
						'required'    => true,
					],
					'original_content' => [
						'description' => esc_html__( 'Original post content to restore.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
					],
				],
			]
		);

		// POST /remove-nofollow - Remove nofollow from link.
		register_rest_route(
			$this->namespace,
			'/remove-nofollow',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'remove_nofollow' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'link_id' => [
						'description' => esc_html__( 'Link ID to remove nofollow from.', 'rank-math-pro' ),
						'type'        => 'integer',
						'required'    => true,
					],
				],
			]
		);

		// POST /update - Update link.
		register_rest_route(
			$this->namespace,
			'/update',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'update_link' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'link_id'      => [
						'description' => esc_html__( 'Link ID to update.', 'rank-math-pro' ),
						'type'        => 'integer',
						'required'    => true,
					],
					'new_url'      => [
						'description' => esc_html__( 'New URL for the link.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
					],
					'new_anchor'   => [
						'description' => esc_html__( 'New anchor text for the link.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
					],
					'is_nofollow'  => [
						'description' => esc_html__( 'Whether the link should have nofollow attribute.', 'rank-math-pro' ),
						'type'        => 'integer',
						'required'    => false,
						'default'     => 0,
					],
					'target_blank' => [
						'description' => esc_html__( 'Whether the link should open in a new tab.', 'rank-math-pro' ),
						'type'        => 'integer',
						'required'    => false,
						'default'     => 0,
					],
				],
			]
		);

		// POST /bulk-actions/restore - Restore bulk deleted links.
		register_rest_route(
			$this->namespace,
			'/bulk-actions/restore',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'restore_bulk_delete' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'batch_id' => [
						'description' => esc_html__( 'Batch ID to restore from snapshots.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
					],
				],
			]
		);
	}

	/**
	 * Get paginated links.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Response.
	 */
	public function get_links( $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = min( (int) $request->get_param( 'per_page' ), 100 );
		$offset   = ( $page - 1 ) * $per_page;

		// Get status_code parameter (always an array for multi-select).
		$status_codes = $request->get_param( 'status_code' );
		$status_codes = is_array( $status_codes ) ? array_map( 'absint', $status_codes ) : [];

		// Build query arguments.
		$args = [
			'search'          => $request->get_param( 'search' ),
			'source_id'       => (int) $request->get_param( 'source_id' ),
			'target_post_id'  => (int) $request->get_param( 'target_post_id' ),
			'is_internal'     => $request->get_param( 'is_internal' ),
			'is_nofollow'     => $request->get_param( 'is_nofollow' ),
			'anchor_type'     => $request->get_param( 'anchor_type' ),
			'target_blank'    => $request->get_param( 'target_blank' ),
			'status_codes'    => $status_codes,
			'status_category' => $request->get_param( 'status_category' ),
			'is_broken'       => (bool) $request->get_param( 'is_broken' ),
			'is_success'      => (bool) $request->get_param( 'is_success' ),
			'is_redirect'     => $request->get_param( 'is_redirect' ),
			'robots_blocked'  => $request->get_param( 'robots_blocked' ),
			'unchecked'       => $request->get_param( 'unchecked' ),
			'is_marked_safe'  => $request->get_param( 'is_marked_safe' ),
			'orderby'         => $request->get_param( 'orderby' ),
			'order'           => $request->get_param( 'order' ),
			'offset'          => $offset,
			'per_page'        => $per_page,
		];

		// Get results and total count.
		$results = Query_Builder::get_links( $args );
		$total   = Query_Builder::get_links_count( $args );

		return rest_ensure_response(
			[
				'links' => $results,
				'total' => (int) $total,
				'pages' => ceil( $total / $per_page ),
			]
		);
	}

	/**
	 * Get detailed information about a specific link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error Response.
	 */
	public function get_link_details( $request ) {
		global $wpdb;
		$links_table  = $wpdb->prefix . 'rank_math_internal_links';
		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';

		$link_id = (int) $request->get_param( 'id' );

		if ( $link_id <= 0 ) {
			return $this->error( 'invalid_link_id', __( 'Invalid link ID.', 'rank-math-pro' ), 400 );
		}

		// Get link basic data.
		$link = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT l.*,
				 s.http_status_code,
				 s.status_category,
				 s.robots_blocked,
				 s.is_marked_safe,
				 s.last_checked_at,
				 s.last_error_message
				 FROM {$links_table} l
				 LEFT JOIN {$status_table} s ON l.id = s.link_id
				 WHERE l.id = %d",
				$link_id
			)
		);

		if ( ! $link ) {
			return $this->error( 'link_not_found', __( 'Link not found.', 'rank-math-pro' ), 404 );
		}

		// Get all occurrences (posts where this link appears).
		$occurrences_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT l.post_id, p.post_title
				 FROM {$links_table} l
				 INNER JOIN {$wpdb->posts} p ON l.post_id = p.ID
				 WHERE l.url_hash = %s
				 AND p.post_status = 'publish'
				 LIMIT 100",
				$link->url_hash
			)
		);

		// Batch fetch post content to avoid N+1 queries.
		$post_ids    = array_unique( wp_list_pluck( $occurrences_data, 'post_id' ) );
		$posts_batch = Batch_Helper::batch_fetch_posts( $post_ids, [ 'ID', 'post_content' ] );

		// Process occurrences and extract target_blank from post content.
		$occurrences       = [];
		$target_blank_vals = [];

		foreach ( $occurrences_data as $occurrence ) {
			$post_id      = (int) $occurrence->post_id;
			$post_content = isset( $posts_batch[ $post_id ] ) ? $posts_batch[ $post_id ]->post_content : '';
			$target_blank = false;

			if ( $post_content ) {
				static $updater = null;
				if ( null === $updater ) {
					$updater = new Link_Updater();
				}
				$url_patterns = $updater->build_url_patterns( $link->url );

				foreach ( $url_patterns as $url_pattern ) {
					if ( preg_match( '/<a\s+[^>]*?href=["\']' . $url_pattern . '["\'][^>]*?>/i', $post_content, $matches ) ) {
						$link_tag = $matches[0];
						if ( preg_match( '/target=["\']_blank["\']/i', $link_tag ) ) {
							$target_blank = true;
						}
						break;
					}
				}
			}

			$occurrences[] = [
				'post_id'      => $post_id,
				'post_title'   => $occurrence->post_title,
				'target_blank' => $target_blank,
			];

			$target_blank_vals[] = $target_blank ? 1 : 0;
		}

		// Determine majority target_blank value.
		$target_blank_count    = array_count_values( $target_blank_vals );
		$majority_target_blank = ( isset( $target_blank_count[1] ) && $target_blank_count[1] > count( $target_blank_vals ) / 2 );

		// Build response.
		$response = [
			'id'                 => (int) $link->id,
			'url'                => $link->url,
			'anchor_text'        => $link->anchor_text,
			'type'               => $link->type,
			'is_nofollow'        => ! empty( $link->is_nofollow ),
			'target_blank'       => $majority_target_blank,
			'http_status_code'   => ! empty( $link->http_status_code ) ? (int) $link->http_status_code : null,
			'status_category'    => $link->status_category ?? null,
			'robots_blocked'     => ! empty( $link->robots_blocked ),
			'is_marked_safe'     => ! empty( $link->is_marked_safe ),
			'last_checked_at'    => $link->last_checked_at ?? null,
			'last_error_message' => $link->last_error_message ?? null,
			'occurrences'        => $occurrences,
			'occurrences_count'  => count( $occurrences ),
		];

		return rest_ensure_response( $response );
	}

	/**
	 * Delete link(s) from database and content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error Response.
	 */
	public function delete_links( $request ) {
		$link_ids = $request->get_param( 'link_ids' );
		$filters  = $request->get_param( 'filters' );

		// Normalize to array.
		if ( ! empty( $link_ids ) ) {
			$link_ids = array_map( 'intval', $link_ids );
		} elseif ( empty( $filters ) ) {
			return $this->error( 'invalid_params', __( 'No link ID or filters provided.', 'rank-math-pro' ), 400 );
		}

		// Determine if this is a bulk operation.
		if ( ( ! empty( $link_ids ) && count( $link_ids ) > 1 ) || ! empty( $filters ) ) {
			$processor = Bulk_Link_Modifier::get();

			if ( $processor->is_active() ) {
				return $this->error(
					'delete_in_progress',
					__( 'A bulk delete operation is already in progress.', 'rank-math-pro' ),
					409
				);
			}

			$processor->start(
				'delete',
				[
					'filters'  => $filters,
					'link_ids' => $link_ids,
				]
			);

			$this->track_link_genius_event(
				'Button Clicked',
				[
					'button'      => 'Bulk Delete Links (Background)',
					'has_filters' => ! empty( $filters ),
				]
			);

			return rest_ensure_response(
				[
					'success' => true,
					'queued'  => true,
					'message' => __( 'Bulk delete queued for background processing.', 'rank-math-pro' ),
				]
			);
		}

		// Single link - process synchronously.
		if ( empty( $link_ids ) ) {
			return $this->error( 'invalid_params', __( 'No link ID provided.', 'rank-math-pro' ), 400 );
		}

		return $this->delete_single_link( $link_ids[0] );
	}

	/**
	 * Undo a single link deletion.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|\WP_Error Response object.
	 */
	public function undo_delete_link( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		// Verify post exists using Batch_Helper.
		$posts = Batch_Helper::batch_fetch_posts( [ $post_id ], [ 'ID' ] );
		if ( empty( $posts[ $post_id ] ) ) {
			return $this->error( 'post_not_found', __( 'Post not found.', 'rank-math-pro' ), 404 );
		}

		// Restore post content using Batch_Helper.
		$result = Batch_Helper::update_post_content( [ $post_id => $request->get_param( 'original_content' ) ] );

		if ( ! $result ) {
			return $this->error( 'restore_failed', __( 'Failed to restore post content.', 'rank-math-pro' ), 500 );
		}

		Query_Builder::invalidate_cache();

		$this->track_link_genius_event(
			'Button Clicked',
			[ 'button' => 'Undo Delete Link' ]
		);

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Link restored successfully.', 'rank-math-pro' ),
			]
		);
	}

	/**
	 * Remove nofollow attribute from a link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error Response.
	 */
	public function remove_nofollow( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_internal_links';

		$link_id = (int) $request->get_param( 'link_id' );

		if ( empty( $link_id ) ) {
			return $this->error( 'invalid_params', __( 'No link ID provided.', 'rank-math-pro' ), 400 );
		}

		$link = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, post_id, url, type, anchor_text, is_nofollow, anchor_type FROM {$table} WHERE id = %d",
				$link_id
			)
		);

		if ( ! $link ) {
			return $this->error( 'link_not_found', __( 'Link not found.', 'rank-math-pro' ), 404 );
		}

		if ( empty( $link->is_nofollow ) || 0 === (int) $link->is_nofollow ) {
			return $this->error( 'not_nofollow', __( 'Link is not set to nofollow.', 'rank-math-pro' ), 400 );
		}

		if ( empty( $link->url ) || ( empty( $link->anchor_text ) && 'IMAGE' !== $link->anchor_type ) ) {
			return $this->error( 'invalid_link_data', __( 'Link data is incomplete.', 'rank-math-pro' ), 400 );
		}

		// Fetch post content using Batch_Helper.
		$posts = Batch_Helper::batch_fetch_posts( [ $link->post_id ], [ 'ID', 'post_content' ] );
		if ( empty( $posts[ $link->post_id ] ) ) {
			return $this->error( 'post_not_found', __( 'Post not found.', 'rank-math-pro' ), 404 );
		}

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button'    => 'Remove Nofollow',
				'link_type' => $link->type,
			]
		);

		$updater     = new Link_Updater();
		$new_content = $updater->remove_nofollow_from_content( $posts[ $link->post_id ]->post_content, $link );

		if ( null === $new_content ) {
			return $this->error(
				'link_not_found_in_content',
				__( 'Could not find the link in post content. It may have been modified or deleted.', 'rank-math-pro' ),
				404
			);
		}

		if ( $new_content !== $posts[ $link->post_id ]->post_content ) {
			Batch_Helper::update_post_content( [ $link->post_id => $new_content ] );
		}

		Query_Builder::invalidate_cache();

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Nofollow attribute removed successfully.', 'rank-math-pro' ),
			]
		);
	}

	/**
	 * Update link URL, anchor text, and attributes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error Response.
	 */
	public function update_link( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_internal_links';

		$link_id = (int) $request->get_param( 'link_id' );
		$new_url = trim( $request->get_param( 'new_url' ) );

		if ( empty( $link_id ) ) {
			return $this->error( 'invalid_params', __( 'No link ID provided.', 'rank-math-pro' ), 400 );
		}

		if ( empty( $new_url ) ) {
			return $this->error( 'invalid_params', __( 'URL cannot be empty.', 'rank-math-pro' ), 400 );
		}

		$link = DB::get_row(
			$wpdb->prepare(
				"SELECT id, post_id, url, type, anchor_text, anchor_type FROM {$table} WHERE id = %d",
				$link_id
			)
		);

		if ( ! $link ) {
			return $this->error( 'link_not_found', __( 'Link not found.', 'rank-math-pro' ), 404 );
		}

		$new_anchor = trim( $request->get_param( 'new_anchor' ) );
		if ( empty( $new_anchor ) && 'IMAGE' !== $link->anchor_type ) {
			return $this->error( 'invalid_params', __( 'Anchor text cannot be empty.', 'rank-math-pro' ), 400 );
		}

		if ( empty( $link->url ) || ( empty( $link->anchor_text ) && 'IMAGE' !== $link->anchor_type ) ) {
			return $this->error( 'invalid_link_data', __( 'Link data is incomplete.', 'rank-math-pro' ), 400 );
		}

		// Fetch post content using Batch_Helper.
		$posts = Batch_Helper::batch_fetch_posts( [ $link->post_id ], [ 'ID', 'post_content' ] );
		if ( empty( $posts[ $link->post_id ] ) ) {
			return $this->error( 'post_not_found', __( 'Post not found.', 'rank-math-pro' ), 404 );
		}

		// Check if URL changed (normalize by removing trailing slashes).
		$url_changed = untrailingslashit( $new_url ) !== untrailingslashit( $link->url );

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button'         => 'Update Link',
				'link_type'      => $link->type,
				'url_changed'    => $url_changed ? 1 : 0,
				'anchor_changed' => $new_anchor !== $link->anchor_text ? 1 : 0,
			]
		);

		$updater     = new Link_Updater();
		$new_content = $updater->update_single_link_in_content(
			$posts[ $link->post_id ]->post_content,
			$link,
			$new_url,
			$new_anchor,
			[
				'is_nofollow'  => (int) $request->get_param( 'is_nofollow' ),
				'target_blank' => (int) $request->get_param( 'target_blank' ),
			]
		);

		if ( null === $new_content ) {
			return $this->error(
				'link_not_found_in_content',
				__( 'Could not find the link in post content. It may have been modified or deleted.', 'rank-math-pro' ),
				404
			);
		}

		if ( $new_content !== $posts[ $link->post_id ]->post_content ) {
			Batch_Helper::update_post_content( [ $link->post_id => $new_content ] );
		}

		// Find the new link ID by URL hash and post ID.
		$url_hash     = md5( untrailingslashit( $new_url ) );
		$new_link_row = DB::get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE post_id = %d
				 AND url_hash = %s
				 AND anchor_text = %s
				 ORDER BY id DESC
				 LIMIT 1",
				$link->post_id,
				$url_hash,
				$new_anchor
			)
		);

		$new_link_id = $new_link_row && $new_link_row->id ? $new_link_row->id : null;

		Query_Builder::invalidate_cache();

		return rest_ensure_response(
			[
				'success'     => true,
				'message'     => __( 'Link updated successfully.', 'rank-math-pro' ),
				'new_link_id' => $new_link_id,
				'url_changed' => $url_changed,
			]
		);
	}

	/**
	 * Restore bulk deleted links from snapshots.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|\WP_Error Response object.
	 */
	public function restore_bulk_delete( $request ) {
		$batch_id = $request->get_param( 'batch_id' );

		Bulk_Link_Modifier::clear_completion();

		$processor = Bulk_Link_Modifier::get();
		$result    = $processor->start(
			'restore',
			[ 'batch_id' => $batch_id ]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->track_link_genius_event(
			'Button Clicked',
			[ 'button' => 'Restore Bulk Delete' ]
		);

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Restore started. Processing in background.', 'rank-math-pro' ),
			],
			200
		);
	}

	/**
	 * Delete a single link synchronously.
	 *
	 * @param int $link_id Link ID to delete.
	 * @return WP_REST_Response|\WP_Error Response.
	 */
	private function delete_single_link( $link_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_internal_links';

		// Fetch link data using DB Helper.
		$link = DB::get_row(
			$wpdb->prepare(
				"SELECT id, post_id, url, type, anchor_text, anchor_type, is_nofollow FROM {$table} WHERE id = %d",
				$link_id
			)
		);

		if ( ! $link ) {
			return $this->error( 'link_not_found', __( 'Link not found.', 'rank-math-pro' ), 404 );
		}

		// Validate link data.
		if ( empty( $link->url ) || ( empty( $link->anchor_text ) && 'IMAGE' !== $link->anchor_type ) ) {
			return $this->error( 'invalid_link_data', __( 'Link data is incomplete.', 'rank-math-pro' ), 400 );
		}

		// Fetch post content using Batch_Helper for consistency.
		$posts = Batch_Helper::batch_fetch_posts(
			[ $link->post_id ],
			[ 'ID', 'post_content' ]
		);

		if ( empty( $posts[ $link->post_id ] ) ) {
			return $this->error( 'post_not_found', __( 'Post not found.', 'rank-math-pro' ), 404 );
		}

		$original_content = $posts[ $link->post_id ]->post_content;

		// Build delete pattern and remove link.
		$updater = new Link_Updater();
		$pattern = $updater->build_delete_pattern( $link, $original_content );

		if ( ! $pattern ) {
			return $this->error( 'pattern_failed', __( 'Failed to build delete pattern for the link.', 'rank-math-pro' ), 500 );
		}

		$new_content = preg_replace_callback(
			$pattern,
			function ( $matches ) {
				return $matches[1];
			},
			$original_content,
			1
		);

		if ( $new_content === $original_content ) {
			return $this->error( 'link_not_found_in_content', __( 'Link not found in post content.', 'rank-math-pro' ), 404 );
		}

		// Update post content using Batch_Helper (handles cache clearing and link reprocessing).
		Batch_Helper::update_post_content( [ $link->post_id => $new_content ] );

		// Invalidate query cache.
		Query_Builder::invalidate_cache();

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button'         => 'Delete Link',
				'total_selected' => 1,
				'deleted_count'  => 1,
				'failed_count'   => 0,
			]
		);

		return rest_ensure_response(
			[
				'success'       => true,
				'deleted_count' => 1,
				'failed_count'  => 0,
				'total_count'   => 1,
				'message'       => __( 'Link deleted successfully.', 'rank-math-pro' ),
				'undo_data'     => [
					'post_id'          => $link->post_id,
					'original_content' => $original_content,
				],
			]
		);
	}

	/**
	 * Get arguments for links endpoint.
	 *
	 * @return array
	 */
	private function get_links_args() {
		return [
			'page'            => [
				'description' => esc_html__( 'Page number.', 'rank-math-pro' ),
				'type'        => 'integer',
				'default'     => 1,
			],
			'per_page'        => [
				'description' => esc_html__( 'Items per page.', 'rank-math-pro' ),
				'type'        => 'integer',
				'default'     => 50,
				'maximum'     => 100,
			],
			'source_id'       => [
				'description' => esc_html__( 'Filter by source post ID.', 'rank-math-pro' ),
				'type'        => 'integer',
				'default'     => 0,
			],
			'target_post_id'  => [
				'description' => esc_html__( 'Filter by target post ID.', 'rank-math-pro' ),
				'type'        => 'integer',
				'default'     => 0,
			],
			'is_internal'     => [
				'description' => esc_html__( 'Filter by link type.', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => '',
			],
			'is_nofollow'     => [
				'description' => esc_html__( 'Filter by nofollow status.', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => '',
			],
			'anchor_type'     => [
				'description' => esc_html__( 'Filter by anchor type (HPLNK, CNCL, HLANG, IMAGE).', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => '',
			],
			'target_blank'    => [
				'description' => esc_html__( 'Filter by target blank status.', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => '',
			],
			'search'          => [
				'description' => esc_html__( 'Search in anchor text or URL.', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => '',
			],
			'orderby'         => [
				'description' => esc_html__( 'Order by field.', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => 'created_at',
			],
			'order'           => [
				'description' => esc_html__( 'Order direction.', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => 'DESC',
			],
			'status_code'     => [
				'description' => esc_html__( 'Filter by HTTP status code (e.g., 404). Supports multiple values.', 'rank-math-pro' ),
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'default'     => [],
			],
			'status_category' => [
				'description' => esc_html__( 'Filter by status category (2xx, 3xx, 4xx, 5xx, timeout, error).', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => '',
			],
			'is_broken'       => [
				'description' => esc_html__( 'Filter broken links (4xx, 5xx, timeout, error).', 'rank-math-pro' ),
				'type'        => 'boolean',
				'default'     => false,
			],
			'is_success'      => [
				'description' => esc_html__( 'Filter successful links (2xx).', 'rank-math-pro' ),
				'type'        => 'boolean',
				'default'     => false,
			],
			'is_redirect'     => [
				'description' => esc_html__( 'Filter redirect links (3xx).', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => '',
			],
			'robots_blocked'  => [
				'description' => esc_html__( 'Filter links blocked by robots.txt.', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => '',
			],
			'unchecked'       => [
				'description' => esc_html__( 'Filter unchecked links (no status data).', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => '',
			],
			'is_marked_safe'  => [
				'description' => esc_html__( 'Filter links marked as safe.', 'rank-math-pro' ),
				'type'        => 'string',
				'default'     => '',
			],
		];
	}
}
