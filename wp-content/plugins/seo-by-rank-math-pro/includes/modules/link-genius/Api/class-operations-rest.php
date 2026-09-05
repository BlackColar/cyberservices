<?php
/**
 * Unified Operations REST API controller.
 *
 * Consolidates progress, cancel, and clear endpoints for all Link Genius background operations.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Api;

use WP_Error;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use RankMathPro\Link_Genius\Background\Regenerate_Links;
use RankMathPro\Link_Genius\Background\Link_Status_Crawler;
use RankMathPro\Link_Genius\Background\Bulk_Link_Modifier;
use RankMathPro\Link_Genius\Background\Export_Processor;
use RankMathPro\Link_Genius\Features\BulkUpdate\Processor as Bulk_Update_Processor;
use RankMathPro\Link_Genius\Features\BulkUpdate\Preview_Processor;
use RankMathPro\Link_Genius\Features\KeywordMaps\Keyword_Map_Processor;
use RankMathPro\Link_Genius\Features\KeywordMaps\Preview_Processor as KM_Preview_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Operations_Rest class.
 *
 * Provides unified endpoints for managing background operations:
 * - Progress tracking
 * - Cancellation
 * - Completion data clearing
 */
class Operations_Rest extends Base_Controller {

	/**
	 * Operation type mappings to processor classes.
	 *
	 * @var array<string, string>
	 */
	const OPERATIONS = [
		'regenerate'          => Regenerate_Links::class,
		'audit'               => Link_Status_Crawler::class,
		'bulk_delete'         => Bulk_Link_Modifier::class,
		'bulk_mark_safe'      => Bulk_Link_Modifier::class,
		'bulk_restore'        => Bulk_Link_Modifier::class,
		'bulk_update_preview' => Preview_Processor::class,
		'bulk_update_apply'   => Bulk_Update_Processor::class,
		'keyword_map_preview' => KM_Preview_Processor::class,
		'keyword_map_apply'   => Keyword_Map_Processor::class,
		'export'              => Export_Processor::class,
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->register_routes();
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// GET /operations/progress - Get progress for any operation.
		register_rest_route(
			$this->namespace,
			'/operations/progress',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_progress' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'operation' => [
						'description' => esc_html__( 'Operation type.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
						'enum'        => array_keys( self::OPERATIONS ),
					],
					'id'        => [
						'description' => esc_html__( 'Optional operation ID (for keyword maps).', 'rank-math-pro' ),
						'type'        => 'integer',
					],
				],
			]
		);

		// POST /operations/cancel - Cancel any operation.
		register_rest_route(
			$this->namespace,
			'/operations/cancel',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'cancel_operation' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'operation' => [
						'description' => esc_html__( 'Operation type to cancel.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
						'enum'        => array_keys( self::OPERATIONS ),
					],
					'id'        => [
						'description' => esc_html__( 'Optional operation ID (for keyword maps).', 'rank-math-pro' ),
						'type'        => 'integer',
					],
				],
			]
		);

		// POST /operations/clear - Clear completion data for any operation.
		register_rest_route(
			$this->namespace,
			'/operations/clear',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'clear_operation' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'operation' => [
						'description' => esc_html__( 'Operation type to clear.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
						'enum'        => array_keys( self::OPERATIONS ),
					],
					'id'        => [
						'description' => esc_html__( 'Optional operation ID (for keyword maps).', 'rank-math-pro' ),
						'type'        => 'integer',
					],
				],
			]
		);
	}

	/**
	 * Get progress for any operation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_progress( $request ) {
		$operation = $request->get_param( 'operation' );
		$id        = $request->get_param( 'id' );

		// Get processor class and raw progress data.
		$processor_class = self::OPERATIONS[ $operation ];
		$raw_progress    = $this->get_raw_progress( $operation, $processor_class, $id );

		// If no progress data, return appropriate response.
		if ( null === $raw_progress || false === $raw_progress ) {
			return new WP_REST_Response(
				[
					'success' => true,
					'data'    => null,
					'message' => sprintf(
						// Translators: %s is the operation type.
						__( 'No %s operation in progress.', 'rank-math-pro' ),
						$this->get_operation_label( $operation )
					),
				],
				200
			);
		}

		// Normalize and return progress data.
		$normalized = $this->normalize_progress( $raw_progress, $operation );

		return new WP_REST_Response(
			[
				'success' => true,
				'data'    => $normalized,
			],
			200
		);
	}

	/**
	 * Cancel any operation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function cancel_operation( $request ) {
		$operation = $request->get_param( 'operation' );
		$id        = $request->get_param( 'id' );

		$error = $this->perform_cancel( $operation, $id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button'    => 'Cancel Operation',
				'operation' => $operation,
			]
		);

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => $this->get_cancel_message( $operation ),
			],
			200
		);
	}

	/**
	 * Clear completion data for any operation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function clear_operation( $request ) {
		$operation = $request->get_param( 'operation' );
		$id        = $request->get_param( 'id' );

		$error = $this->perform_clear( $operation, $id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button'    => 'Clear Operation',
				'operation' => $operation,
			]
		);

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => $this->get_clear_message( $operation ),
			],
			200
		);
	}

	/**
	 * Perform the cancel action for the given operation.
	 *
	 * @param string   $operation Operation type.
	 * @param int|null $id        Optional operation ID.
	 * @return WP_Error|null WP_Error on validation failure, null on success.
	 */
	private function perform_cancel( $operation, $id ) {
		if ( in_array( $operation, [ 'bulk_delete', 'bulk_mark_safe', 'bulk_restore' ], true ) ) {
			Bulk_Link_Modifier::get()->cancel();
			return null;
		}

		if ( 'keyword_map_preview' === $operation ) {
			if ( ! $id ) {
				return new WP_Error(
					'missing_id',
					__( 'Keyword map ID is required for preview cancellation.', 'rank-math-pro' ),
					[ 'status' => 400 ]
				);
			}
			KM_Preview_Processor::get()->cancel( $id );
			return null;
		}

		if ( 'keyword_map_apply' === $operation ) {
			Keyword_Map_Processor::get()->cancel();
			return null;
		}

		if ( 'bulk_update_preview' === $operation ) {
			Preview_Processor::cleanup();
			return null;
		}

		if ( 'bulk_update_apply' === $operation ) {
			Bulk_Update_Processor::get()->cancel();
			return null;
		}

		// Standard operations: regenerate, audit, export.
		$processor_class = self::OPERATIONS[ $operation ];
		if ( ! method_exists( $processor_class, 'get' ) ) {
			return new WP_Error(
				'invalid_processor',
				__( 'Invalid processor for this operation.', 'rank-math-pro' ),
				[ 'status' => 500 ]
			);
		}

		$processor_class::get()->cancel();
		return null;
	}

	/**
	 * Perform the clear action for the given operation.
	 *
	 * @param string   $operation Operation type.
	 * @param int|null $id        Optional operation ID.
	 * @return WP_Error|null WP_Error on validation failure, null on success.
	 */
	private function perform_clear( $operation, $id ) {
		if ( in_array( $operation, [ 'bulk_delete', 'bulk_mark_safe', 'bulk_restore' ], true ) ) {
			Bulk_Link_Modifier::clear_completion();
			return null;
		}

		if ( 'keyword_map_preview' === $operation ) {
			if ( ! $id ) {
				return new WP_Error(
					'missing_id',
					__( 'Keyword map ID is required.', 'rank-math-pro' ),
					[ 'status' => 400 ]
				);
			}
			KM_Preview_Processor::get()->cancel( $id );
			return null;
		}

		if ( 'bulk_update_preview' === $operation ) {
			Preview_Processor::cleanup();
			return null;
		}

		// Standard operations with clear_completion static method.
		$processor_class = self::OPERATIONS[ $operation ];
		if ( ! method_exists( $processor_class, 'clear_completion' ) ) {
			return new WP_Error(
				'not_supported',
				__( 'Clear operation not supported for this operation type.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		$processor_class::clear_completion();
		return null;
	}

	/**
	 * Get raw progress data from the processor.
	 *
	 * @param string $operation       Operation type.
	 * @param string $processor_class Processor class name.
	 * @param int    $id              Optional operation ID.
	 * @return array|null|false Raw progress data.
	 */
	private function get_raw_progress( $operation, $processor_class, $id ) {
		// Handle bulk operations (they use the same processor with operation filtering).
		if ( in_array( $operation, [ 'bulk_delete', 'bulk_mark_safe', 'bulk_restore' ], true ) ) {
			// Map operation to bulk modifier action type.
			$action = str_replace( 'bulk_', '', $operation );
			return Bulk_Link_Modifier::get_progress( $action );
		}

		// Handle keyword map preview (requires ID).
		if ( 'keyword_map_preview' === $operation ) {
			if ( ! $id ) {
				return null;
			}

			$processor = KM_Preview_Processor::get();
			return $processor->get_progress( $id );
		}

		// Handle standard operations with get_progress static method.
		if ( method_exists( $processor_class, 'get_progress' ) ) {
			return $processor_class::get_progress();
		}

		return null;
	}

	/**
	 * Normalize progress data to a consistent format.
	 *
	 * @param array  $raw       Raw progress data from processor.
	 * @param string $operation Operation type.
	 * @return array Normalized progress data.
	 */
	private function normalize_progress( $raw, $operation ) {
		// Base structure.
		$normalized = [
			'operation' => $operation,
			'active'    => $raw['active'] ?? true,
			'status'    => $raw['status'] ?? ( ( $raw['active'] ?? true ) ? 'running' : 'completed' ),
		];

		// All processors should use standardized keys: total, processed, percent.
		$total     = $raw['total'] ?? 0;
		$processed = $raw['processed'] ?? 0;

		$normalized['total']     = $total;
		$normalized['processed'] = $processed;
		$normalized['percent']   = min( 100, $raw['percent'] ?? ( $total > 0 ? round( ( $processed / $total ) * 100 ) : 0 ) );

		// Add optional fields if present.
		if ( isset( $raw['started_at'] ) ) {
			$normalized['started_at'] = $raw['started_at'];
		}

		if ( isset( $raw['completed_at'] ) ) {
			$normalized['completed_at'] = $raw['completed_at'];
		}

		if ( isset( $raw['batch_id'] ) ) {
			$normalized['batch_id'] = $raw['batch_id'];
		}

		if ( isset( $raw['preview_id'] ) ) {
			$normalized['preview_id'] = $raw['preview_id'];
		}

		if ( isset( $raw['failed'] ) ) {
			$normalized['failed'] = $raw['failed'];
		}

		if ( isset( $raw['errors'] ) ) {
			$normalized['errors'] = $raw['errors'];
		}

		if ( isset( $raw['total_instances'] ) ) {
			$normalized['total_instances'] = $raw['total_instances'];
		}

		// Include full details for advanced use.
		$normalized['details'] = $raw;

		return $normalized;
	}

	/**
	 * Get human-readable operation label.
	 *
	 * @param string $operation Operation type.
	 * @return string Operation label.
	 */
	private function get_operation_label( $operation ) {
		$labels = [
			'regenerate'          => __( 'link regeneration', 'rank-math-pro' ),
			'audit'               => __( 'link audit', 'rank-math-pro' ),
			'bulk_delete'         => __( 'bulk delete', 'rank-math-pro' ),
			'bulk_mark_safe'      => __( 'bulk mark safe', 'rank-math-pro' ),
			'bulk_restore'        => __( 'bulk restore', 'rank-math-pro' ),
			'bulk_update_preview' => __( 'bulk update preview', 'rank-math-pro' ),
			'bulk_update_apply'   => __( 'bulk update', 'rank-math-pro' ),
			'keyword_map_preview' => __( 'keyword map preview', 'rank-math-pro' ),
			'keyword_map_apply'   => __( 'keyword map execution', 'rank-math-pro' ),
			'export'              => __( 'export', 'rank-math-pro' ),
		];

		return $labels[ $operation ] ?? $operation;
	}

	/**
	 * Get cancellation success message.
	 *
	 * @param string $operation Operation type.
	 * @return string Success message.
	 */
	private function get_cancel_message( $operation ) {
		$messages = [
			'regenerate'          => __( 'Link regeneration cancelled successfully.', 'rank-math-pro' ),
			'audit'               => __( 'Link audit cancelled successfully.', 'rank-math-pro' ),
			'bulk_delete'         => __( 'Bulk delete operation cancelled successfully.', 'rank-math-pro' ),
			'bulk_mark_safe'      => __( 'Bulk mark safe operation cancelled successfully.', 'rank-math-pro' ),
			'bulk_restore'        => __( 'Bulk restore operation cancelled successfully.', 'rank-math-pro' ),
			'bulk_update_preview' => __( 'Bulk update preview cancelled successfully.', 'rank-math-pro' ),
			'bulk_update_apply'   => __( 'Bulk update cancelled successfully.', 'rank-math-pro' ),
			'keyword_map_preview' => __( 'Keyword map preview cancelled successfully.', 'rank-math-pro' ),
			'keyword_map_apply'   => __( 'Keyword map execution cancelled successfully.', 'rank-math-pro' ),
			'export'              => __( 'Export cancelled successfully.', 'rank-math-pro' ),
		];

		return $messages[ $operation ] ?? __( 'Operation cancelled successfully.', 'rank-math-pro' );
	}

	/**
	 * Get clear completion data success message.
	 *
	 * @param string $operation Operation type.
	 * @return string Success message.
	 */
	private function get_clear_message( $operation ) {
		$messages = [
			'regenerate'          => __( 'Link regeneration completion data cleared.', 'rank-math-pro' ),
			'audit'               => __( 'Link audit completion data cleared.', 'rank-math-pro' ),
			'bulk_delete'         => __( 'Bulk delete completion data cleared.', 'rank-math-pro' ),
			'bulk_mark_safe'      => __( 'Bulk mark safe completion data cleared.', 'rank-math-pro' ),
			'bulk_restore'        => __( 'Bulk restore completion data cleared.', 'rank-math-pro' ),
			'bulk_update_preview' => __( 'Bulk update preview data cleared.', 'rank-math-pro' ),
			'bulk_update_apply'   => __( 'Bulk update completion data cleared.', 'rank-math-pro' ),
			'keyword_map_preview' => __( 'Keyword map preview data cleared.', 'rank-math-pro' ),
			'keyword_map_apply'   => __( 'Keyword map execution completion data cleared.', 'rank-math-pro' ),
			'export'              => __( 'Export completion data cleared.', 'rank-math-pro' ),
		];

		return $messages[ $operation ] ?? __( 'Operation completion data cleared.', 'rank-math-pro' );
	}
}
