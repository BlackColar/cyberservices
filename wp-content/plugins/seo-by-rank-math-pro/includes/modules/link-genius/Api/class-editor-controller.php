<?php
/**
 * Editor REST API controller.
 *
 * Handles editor-related endpoints: link-suggestions, link-opportunities, related-posts.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Api
 */

namespace RankMathPro\Link_Genius\Api;

use WP_REST_Server;
use WP_REST_Request;
use RankMath\Helper;
use RankMathPro\Link_Genius\Services\Related_Posts;
use RankMathPro\Link_Genius\Services\Utils;
use RankMathPro\Link_Genius\Services\Content_Similarity;

defined( 'ABSPATH' ) || exit;

/**
 * Editor_Controller class.
 *
 * Provides REST endpoints for editor integration (link suggestions, opportunities, related posts).
 */
class Editor_Controller extends Base_Controller {

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// POST /link-suggestions - Get link suggestions for selected text.
		register_rest_route(
			$this->namespace,
			'/link-suggestions',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'link_suggestions' ],
				'permission_callback' => [ $this, 'check_post_edit_permission' ],
				'args'                => [
					'post_id' => [
						'description' => esc_html__( 'Post ID.', 'rank-math-pro' ),
						'type'        => 'integer',
						'required'    => true,
					],
					'text'    => [
						'description' => esc_html__( 'Selected text used for suggestions.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => false,
					],
					'current' => [
						'description' => esc_html__( 'Current editor state summary.', 'rank-math-pro' ),
						'type'        => 'object',
						'required'    => false,
					],
				],
			]
		);

		// POST /link-opportunities - Generate AI link opportunities.
		register_rest_route(
			$this->namespace,
			'/link-opportunities',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'link_opportunities' ],
				'permission_callback' => [ $this, 'check_post_edit_permission' ],
				'args'                => [
					'post_id' => [
						'description' => esc_html__( 'Post ID.', 'rank-math-pro' ),
						'type'        => 'integer',
						'required'    => true,
					],
					'current' => [
						'description' => esc_html__( 'Current editor state summary.', 'rank-math-pro' ),
						'type'        => 'object',
						'required'    => false,
					],
				],
			]
		);

		// POST /related-posts - Compute and persist related posts list.
		register_rest_route(
			$this->namespace,
			'/related-posts',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'related_posts' ],
				'permission_callback' => [ $this, 'check_post_edit_permission' ],
				'args'                => [
					'post_id' => [
						'description' => esc_html__( 'Post ID.', 'rank-math-pro' ),
						'type'        => 'integer',
						'required'    => true,
					],
					'current' => [
						'description' => esc_html__( 'Current editor state summary.', 'rank-math-pro' ),
						'type'        => 'object',
						'required'    => false,
					],
				],
			]
		);
	}

	/**
	 * Get link suggestions for selected text.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function link_suggestions( WP_REST_Request $request ) {
		$error = $this->check_content_ai_requirements();
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$post_id = (int) $request->get_param( 'post_id' );
		$text    = (string) ( $request->get_param( 'text' ) ?? '' );
		$current = $request->get_param( 'current' ) ?? [];
		$similar = new Content_Similarity();
		$results = $similar->find_similar_posts( $post_id, $text, 3, [ 'current' => $current ] );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature'           => 'Link Suggestions',
				'post_id'           => $post_id,
				'post_type'         => get_post_type( $post_id ),
				'has_selected_text' => ! empty( $text ) ? 1 : 0,
				'text_length'       => ! empty( $text ) ? strlen( $text ) : 0,
				'results_count'     => is_array( $results ) ? count( $results ) : 0,
			]
		);

		return rest_ensure_response(
			[
				'data'         => $results,
				'usageDetails' => Helper::get_usage_details(),
			]
		);
	}

	/**
	 * Generate AI link opportunities and persist normalized format.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function link_opportunities( WP_REST_Request $request ) {
		$error = $this->check_content_ai_requirements();
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$post_id = (int) $request->get_param( 'post_id' );
		$current = $request->get_param( 'current' );
		$service = new Related_Posts();

		$suggestions = $service->get_related_posts(
			$post_id,
			10,
			[
				'force_refresh' => true,
				'current'       => is_array( $current ) ? $current : [],
				'mode'          => 'suggest_link_opportunities',
			]
		);

		if ( is_wp_error( $suggestions ) ) {
			return $suggestions;
		}

		// Normalize to array of objects [ { word, link } ].
		$items = [];
		if ( is_array( $suggestions ) ) {
			$assoc = array_keys( $suggestions ) !== range( 0, count( (array) $suggestions ) - 1 );
			if ( $assoc ) {
				foreach ( $suggestions as $word => $link ) {
					$items[] = [
						'word' => (string) $word,
						'link' => is_string( $link ) ? $link : '',
					];
				}
			} else {
				$items = $suggestions;
			}
		}

		update_post_meta( $post_id, 'rank_math_ai_link_suggestions', $items );

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature'             => 'Link Opportunities',
				'post_id'             => $post_id,
				'post_type'           => get_post_type( $post_id ),
				'opportunities_count' => count( $items ),
				'force_refresh'       => 1,
			]
		);

		return rest_ensure_response(
			[
				'items'        => $items,
				'usageDetails' => Helper::get_usage_details(),
			]
		);
	}

	/**
	 * Compute and persist related posts list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function related_posts( WP_REST_Request $request ) {
		$error = $this->check_content_ai_requirements();
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$post_id = (int) $request->get_param( 'post_id' );
		$current = $request->get_param( 'current' );
		$service = new Related_Posts();

		$ids = $service->get_related_posts(
			$post_id,
			10,
			[
				'force_refresh' => true,
				'current'       => is_array( $current ) ? $current : [],
				'mode'          => 'related_posts',
			]
		);

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$items = Utils::map_post_ids_to_items( $ids );

		update_post_meta( $post_id, 'rank_math_related_posts', array_map( 'absint', (array) $ids ) );

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature'       => 'Related Posts',
				'post_id'       => $post_id,
				'post_type'     => get_post_type( $post_id ),
				'related_count' => is_array( $ids ) ? count( $ids ) : 0,
				'force_refresh' => 1,
			]
		);

		return rest_ensure_response(
			[
				'items'        => $items,
				'usageDetails' => Helper::get_usage_details(),
			]
		);
	}
}
