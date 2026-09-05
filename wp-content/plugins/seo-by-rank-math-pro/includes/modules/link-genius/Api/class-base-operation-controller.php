<?php
/**
 * Base REST Controller for Link Genius operations.
 *
 * Provides common functionality for operations that support preview, apply, history, and rollback.
 * Eliminates code duplication across Bulk Update and Keyword Maps REST controllers.
 *
 * @since      1.0.264
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Api
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base_Operation_Controller class.
 *
 * Abstract base class for REST controllers that manage operations with
 * preview, apply, history, and rollback capabilities.
 *
 * Extends Base_Controller to inherit common REST functionality (permissions,
 * response formatting, tracking) and adds operation-specific methods.
 */
abstract class Base_Operation_Controller extends Base_Controller {

	/**
	 * Get standard pagination arguments for REST endpoints.
	 *
	 * Used by preview results, history, and other paginated endpoints.
	 *
	 * @return array Pagination arguments schema.
	 */
	protected function get_pagination_args() {
		return [
			'page'     => [
				'description' => esc_html__( 'Page number.', 'rank-math-pro' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			],
			'per_page' => [
				'description' => esc_html__( 'Number of items per page.', 'rank-math-pro' ),
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
			],
		];
	}

	/**
	 * Register preview endpoints (common pattern).
	 *
	 * Registers both the preview initiation endpoint and the results retrieval endpoint.
	 * Supports both simple routes ('/preview') and parameterized routes ('/{id}/preview').
	 *
	 * @param string $base_route Base route for the operation (e.g., '/bulk-update', '/keyword-maps').
	 * @param array  $options    Optional configuration for preview endpoints.
	 *                           - 'preview_route': Custom preview route pattern (e.g., '(?P<id>\d+)/preview').
	 *                           - 'preview_callback': Custom callback name for preview (default: 'preview').
	 *                           - 'results_callback': Custom callback name for results (default: 'get_preview_results').
	 *                           - 'results_args': Additional args for results endpoint.
	 */
	protected function register_preview_endpoints( $base_route, $options = [] ) {
		$defaults = [
			'preview_route'    => '/preview',
			'preview_callback' => 'preview',
			'results_callback' => 'get_preview_results',
			'results_args'     => [],
		];

		$options = array_merge( $defaults, $options );

		// POST endpoint to initiate preview generation.
		register_rest_route(
			$this->namespace,
			$base_route . $options['preview_route'],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, $options['preview_callback'] ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => $this->get_preview_args(),
			]
		);

		// GET endpoint to retrieve paginated preview results.
		$results_args = array_merge( $this->get_pagination_args(), $options['results_args'] );

		register_rest_route(
			$this->namespace,
			$base_route . '/preview/results',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, $options['results_callback'] ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => $results_args,
			]
		);
	}

	/**
	 * Register history endpoints (common pattern).
	 *
	 * Registers endpoints for retrieving operation history and deleting history records.
	 *
	 * @param string $base_route Base route for the operation (e.g., '/bulk-update', '/keyword-maps').
	 * @param array  $options    Optional configuration for history endpoints.
	 *                           - 'get_callback': Custom callback name for getting history (default: 'get_history').
	 *                           - 'delete_callback': Custom callback name for deleting history (default: 'delete_history').
	 *                           - 'register_delete': Whether to register delete endpoint (default: true).
	 */
	protected function register_history_endpoints( $base_route, $options = [] ) {
		$defaults = [
			'get_callback'    => 'get_history',
			'delete_callback' => 'delete_history',
			'register_delete' => true,
		];

		$options = array_merge( $defaults, $options );

		// GET endpoint to retrieve paginated history.
		register_rest_route(
			$this->namespace,
			$base_route . '/history',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, $options['get_callback'] ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => $this->get_pagination_args(),
			]
		);

		// DELETE endpoint to remove a history record (optional).
		if ( $options['register_delete'] ) {
			register_rest_route(
				$this->namespace,
				$base_route . '/history/(?P<batch_id>[a-zA-Z0-9_]+)',
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, $options['delete_callback'] ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [
						'batch_id' => [
							'description' => esc_html__( 'Batch ID to delete.', 'rank-math-pro' ),
							'type'        => 'string',
							'required'    => true,
						],
					],
				]
			);
		}
	}

	/**
	 * Get preview endpoint arguments.
	 *
	 * Child classes must implement this to define operation-specific preview arguments.
	 *
	 * @return array Preview arguments schema.
	 */
	abstract protected function get_preview_args();

	/**
	 * Handle preview request.
	 *
	 * Child classes must implement this to handle preview generation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	abstract public function preview( $request );

	/**
	 * Get preview results.
	 *
	 * Child classes must implement this to retrieve and paginate preview results.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	abstract public function get_preview_results( $request );

	/**
	 * Get operation history.
	 *
	 * Child classes must implement this to retrieve and format operation history.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	abstract public function get_history( $request );

	/**
	 * Delete a history record.
	 *
	 * Child classes must implement this to handle history record deletion.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	abstract public function delete_history( $request );
}
