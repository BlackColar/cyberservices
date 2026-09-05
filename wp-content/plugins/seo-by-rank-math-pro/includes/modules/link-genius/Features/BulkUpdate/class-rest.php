<?php
/**
 * Bulk Update REST API endpoints.
 *
 * Provides REST API endpoints for bulk link update operations.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius\Bulk_Update
 */

namespace RankMathPro\Link_Genius\Features\BulkUpdate;

use WP_Error;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use RankMath\Rest\Rest_Helper;
use RankMathPro\Link_Genius\Api\Base_Operation_Controller;
use RankMathPro\Link_Genius\Services\History_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Rest class for Bulk Update operations.
 */
class Rest extends Base_Operation_Controller {

	/**
	 * Service instance for business logic.
	 *
	 * @var Service
	 */
	protected $service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = Rest_Helper::BASE . '/link-genius';
		$this->service   = new Service();
		$this->register_routes();
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Register preview and history endpoints using base class methods.
		$this->register_preview_endpoints( '/bulk-update' );
		$this->register_history_endpoints( '/bulk-update' );

		// POST /bulk-update/apply - Apply bulk updates.
		register_rest_route(
			$this->namespace,
			'/bulk-update/apply',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'apply' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'filters'           => [
						'description' => esc_html__( 'Search filters for finding links.', 'rank-math-pro' ),
						'type'        => 'object',
						'required'    => true,
					],
					'update_config'     => [
						'description' => esc_html__( 'Update configuration.', 'rank-math-pro' ),
						'type'        => 'object',
						'required'    => true,
					],
					'selected_link_ids' => [
						'description' => esc_html__( 'Optional array of specific link IDs to update.', 'rank-math-pro' ),
						'type'        => 'array',
						'required'    => false,
						'items'       => [
							'type' => 'integer',
						],
					],
					'is_rollback'       => [
						'description' => esc_html__( 'Whether this is a rollback operation (prevents history creation).', 'rank-math-pro' ),
						'type'        => 'boolean',
						'required'    => false,
						'default'     => false,
					],
					'rollback_batch_id' => [
						'description' => esc_html__( 'Batch ID being rolled back (for status update).', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => false,
					],
				],
			]
		);

		// POST /bulk-update/rollback - Rollback a completed batch.
		register_rest_route(
			$this->namespace,
			'/bulk-update/rollback',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rollback' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'batch_id'          => [
						'description' => esc_html__( 'Batch ID to rollback.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
					],
					'selected_post_ids' => [
						'description' => esc_html__( 'Optional array of specific post IDs to rollback.', 'rank-math-pro' ),
						'type'        => 'array',
						'required'    => false,
						'items'       => [
							'type' => 'integer',
						],
					],
				],
			]
		);

		// GET /bulk-update/changes/{batch_id} - Get list of changes for a specific batch.
		register_rest_route(
			$this->namespace,
			'/bulk-update/changes/(?P<batch_id>[a-zA-Z0-9_]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_batch_changes' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'batch_id' => [
						'description' => esc_html__( 'Batch ID.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
					],
				],
			]
		);
	}

	/**
	 * Generate preview of bulk update changes.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function preview( $request ) {
		$filters       = $request->get_param( 'filters' );
		$update_config = $request->get_param( 'update_config' );

		// Sanitize filters.
		$sanitized_filters = $this->sanitize_filters( $filters );

		// Sanitize update config.
		$sanitized_config = $this->sanitize_update_config( $update_config );

		// Start background preview generation (uses fixed site-wide preview ID).
		$processor = Preview_Processor::get();
		$result    = $processor->start( $sanitized_filters, $sanitized_config );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature'        => 'Bulk Update Preview Started',
				'total_links'    => $result['total_links'],
				'operation_type' => $sanitized_config['operation_type'] ?? 'both',
				'has_filters'    => ! empty( array_filter( $sanitized_filters ) ) ? 1 : 0,
			]
		);

		return new WP_REST_Response(
			[
				'success'       => true,
				'is_background' => true,
				'preview_id'    => $result['preview_id'],
				'total_links'   => $result['total_links'],
				'message'       => $result['message'],
			],
			200
		);
	}

	/**
	 * Apply bulk update.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function apply( $request ) {
		$filters           = $request->get_param( 'filters' );
		$update_config     = $request->get_param( 'update_config' );
		$selected_link_ids = $request->get_param( 'selected_link_ids' );
		$is_rollback       = (bool) $request->get_param( 'is_rollback' );
		$rollback_batch_id = $request->get_param( 'rollback_batch_id' );

		// Sanitize inputs.
		$sanitized_filters = $this->sanitize_filters( $filters );
		$sanitized_config  = $this->sanitize_update_config( $update_config );

		// Sanitize selected link IDs if provided.
		if ( ! empty( $selected_link_ids ) ) {
			$selected_link_ids = array_map( 'absint', $selected_link_ids );
		}

		// Sanitize rollback batch ID if provided.
		if ( ! empty( $rollback_batch_id ) ) {
			$rollback_batch_id = sanitize_text_field( $rollback_batch_id );
		}

		// Clean up any existing preview data before applying.
		Preview_Processor::cleanup();

		// Start bulk update (or rollback if is_rollback is true).
		$result = $this->service->start_bulk_update(
			$sanitized_filters,
			$sanitized_config,
			$selected_link_ids,
			$is_rollback,
			$rollback_batch_id
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button'               => 'Apply Bulk Update',
				'operation_type'       => $sanitized_config['operation_type'] ?? 'both',
				'total_links'          => $result['total_links'] ?? 0,
				'total_posts'          => $result['total_posts'] ?? 0,
				'is_rollback'          => $is_rollback ? 1 : 0,
				'has_selected_links'   => ! empty( $selected_link_ids ) ? 1 : 0,
				'selected_links_count' => ! empty( $selected_link_ids ) ? count( $selected_link_ids ) : 0,
			]
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Rollback a completed batch.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function rollback( $request ) {
		$batch_id          = sanitize_text_field( $request->get_param( 'batch_id' ) );
		$selected_post_ids = $request->get_param( 'selected_post_ids' );

		// Sanitize selected post IDs if provided.
		$selected_post_ids = ! empty( $selected_post_ids ) && is_array( $selected_post_ids ) ? array_map( 'absint', $selected_post_ids ) : null;

		// Perform rollback.
		$result = $this->service->rollback( $batch_id, $selected_post_ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button'                => 'Rollback Bulk Update',
				'has_selected_post_ids' => ! empty( $selected_post_ids ) ? 1 : 0,
			]
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get bulk update history.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_history( $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );

		// Get history from service.
		$history = $this->service->get_history( $page, $per_page );

		return new WP_REST_Response( $history, 200 );
	}

	/**
	 * Delete a history record.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function delete_history( $request ) {
		$batch_id = sanitize_text_field( $request->get_param( 'batch_id' ) );

		// Use shared History_Service for unified deletion.
		$history_service = new History_Service();
		$result          = $history_service->delete_history( $batch_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->track_link_genius_event(
			'Button Clicked',
			[ 'button' => 'Delete Bulk Update History' ]
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get list of changes for a specific batch.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_batch_changes( $request ) {
		$batch_id = sanitize_text_field( $request->get_param( 'batch_id' ) );

		// Get batch changes from service.
		$result = $this->service->get_batch_changes( $batch_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get preview results with pagination.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_preview_results( $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );

		// Check if preview is complete (site-wide current preview).
		$progress = Preview_Processor::get_progress();
		if ( ! $progress ) {
			return new WP_Error(
				'preview_not_found',
				__( 'No preview found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'completed' !== $progress['status'] ) {
			return new WP_Error(
				'preview_not_complete',
				__( 'Preview generation is still in progress.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Get paginated results.
		$results = Preview_Processor::get_results( $page, $per_page );

		// Group by post for counting.
		$posts = [];
		foreach ( $results['sample_changes'] as $change ) {
			$posts[ $change['post_id'] ] = true;
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'preview' => [
					'preview_id'     => $progress['preview_id'],
					'total_links'    => $results['total_items'],
					'total_posts'    => count( $posts ),
					'sample_changes' => $results['sample_changes'],
					'current_page'   => $results['current_page'],
					'total_pages'    => $results['total_pages'],
					'per_page'       => $results['per_page'],
					'warnings'       => [],
				],
			],
			200
		);
	}

	/**
	 * Get preview endpoint arguments (required by base class).
	 *
	 * @return array Preview arguments schema.
	 */
	protected function get_preview_args() {
		return [
			'filters'       => [
				'description' => esc_html__( 'Search filters for finding links.', 'rank-math-pro' ),
				'type'        => 'object',
				'required'    => true,
			],
			'update_config' => [
				'description' => esc_html__( 'Update configuration.', 'rank-math-pro' ),
				'type'        => 'object',
				'required'    => true,
			],
		];
	}

	/**
	 * Sanitize filters array.
	 *
	 * @param array $filters Raw filters from request.
	 * @return array Sanitized filters.
	 */
	private function sanitize_filters( $filters ) {
		if ( ! is_array( $filters ) ) {
			return [];
		}

		$allowed_keys = [
			'search',
			'source_id',
			'target_post_id',
			'is_internal',
			'is_nofollow',
			'anchor_type',
			'target_blank',
			'post_type',
			'date_from',
			'date_to',
			'domain',
			'include_subdomain',
		];

		$sanitized = [];

		foreach ( $filters as $key => $value ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				continue;
			}

			switch ( $key ) {
				case 'search':
				case 'anchor_type':
				case 'domain':
					$sanitized[ $key ] = sanitize_text_field( $value );
					break;

				case 'post_type':
					// Handle both string and array (new multi-select).
					if ( is_array( $value ) ) {
						$sanitized[ $key ] = array_map( 'sanitize_text_field', $value );
						// Remove empty values.
						$sanitized[ $key ] = array_filter( $sanitized[ $key ] );
					} elseif ( ! empty( $value ) ) {
						// Single post type as string.
						$sanitized[ $key ] = [ sanitize_text_field( $value ) ];
					}
					break;

				case 'source_id':
				case 'target_post_id':
					$sanitized[ $key ] = absint( $value );
					break;

				case 'is_internal':
				case 'is_nofollow':
				case 'target_blank':
				case 'include_subdomain':
					$sanitized[ $key ] = in_array( $value, [ '', '0', '1' ], true ) ? $value : '';
					break;

				case 'date_from':
				case 'date_to':
					// Validate Y-m-d format.
					if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
						$sanitized[ $key ] = $value;
					}
					break;
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize update config array.
	 *
	 * @param array $config Raw update config from request.
	 * @return array Sanitized update config.
	 */
	private function sanitize_update_config( $config ) {
		if ( ! is_array( $config ) ) {
			return [];
		}

		$sanitized = [];

		// Sanitize operation_type.
		if ( isset( $config['operation_type'] ) ) {
			$sanitized['operation_type'] = in_array( $config['operation_type'], [ 'anchor', 'url', 'both' ], true )
				? $config['operation_type']
				: 'both';
		}

		// Sanitize anchor_update.
		if ( isset( $config['anchor_update'] ) && is_array( $config['anchor_update'] ) ) {
			$sanitized['anchor_update'] = [
				'search_type'    => in_array( $config['anchor_update']['search_type'] ?? '', [ 'exact', 'contains', 'regex' ], true )
					? $config['anchor_update']['search_type']
					: 'exact',
				'search_value'   => sanitize_text_field( $config['anchor_update']['search_value'] ?? '' ),
				'replace_value'  => sanitize_text_field( $config['anchor_update']['replace_value'] ?? '' ),
				'case_sensitive' => isset( $config['anchor_update']['case_sensitive'] ) ? (bool) $config['anchor_update']['case_sensitive'] : true,
				'whole_word'     => isset( $config['anchor_update']['whole_word'] ) ? (bool) $config['anchor_update']['whole_word'] : false,
			];
		}

		// Sanitize url_update.
		if ( isset( $config['url_update'] ) && is_array( $config['url_update'] ) ) {
			$search_type = in_array( $config['url_update']['search_type'] ?? '', [ 'exact', 'partial', 'domain' ], true )
				? $config['url_update']['search_type']
				: 'exact';

			// For partial search, use sanitize_text_field to preserve partial URL strings.
			// esc_url_raw expects complete URLs and would mangle partial values like "old-domain.com".
			if ( 'partial' === $search_type ) {
				$search_value  = sanitize_text_field( $config['url_update']['search_value'] ?? '' );
				$replace_value = sanitize_text_field( $config['url_update']['replace_value'] ?? '' );
			} else {
				$search_value  = esc_url_raw( $config['url_update']['search_value'] ?? '' );
				$replace_value = esc_url_raw( $config['url_update']['replace_value'] ?? '' );
			}

			$sanitized['url_update'] = [
				'search_type'   => $search_type,
				'search_value'  => $search_value,
				'replace_value' => $replace_value,
			];
		}

		return $sanitized;
	}
}
