<?php
/**
 * Export REST API controller.
 *
 * Handles export endpoint for links and posts data.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Api
 */

namespace RankMathPro\Link_Genius\Api;

use WP_REST_Server;
use WP_REST_Request;
use RankMathPro\Link_Genius\Background\Export_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Export_Controller class.
 *
 * Provides REST endpoint for data export operations.
 */
class Export_Controller extends Base_Controller {

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// POST /export - Schedule data export.
		register_rest_route(
			$this->namespace,
			'/export',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'schedule_export' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'type'    => [
						'description' => esc_html__( 'Export type (links or posts).', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
					],
					'format'  => [
						'description' => esc_html__( 'Export format (csv, json, excel).', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
					],
					'filters' => [
						'description' => esc_html__( 'Filters to apply to the export.', 'rank-math-pro' ),
						'type'        => 'object',
						'required'    => false,
					],
					'columns' => [
						'description' => esc_html__( 'Column definitions.', 'rank-math-pro' ),
						'type'        => 'array',
						'required'    => true,
					],
					'total'   => [
						'description' => esc_html__( 'Total number of records to export.', 'rank-math-pro' ),
						'type'        => 'integer',
						'required'    => true,
					],
				],
			]
		);
	}

	/**
	 * Schedule export job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function schedule_export( $request ) {
		$type    = $request->get_param( 'type' );
		$format  = $request->get_param( 'format' );
		$filters = $request->get_param( 'filters' ) ?? [];
		$columns = $request->get_param( 'columns' );
		$total   = $request->get_param( 'total' );

		// Validate type.
		if ( ! in_array( $type, [ 'links', 'posts' ], true ) ) {
			return $this->error( 'invalid_type', __( 'Invalid export type.', 'rank-math-pro' ), 400 );
		}

		// Validate format.
		if ( ! in_array( $format, [ 'csv', 'json', 'excel' ], true ) ) {
			return $this->error( 'invalid_format', __( 'Invalid export format.', 'rank-math-pro' ), 400 );
		}

		// Track export button click.
		$is_background  = $total > Export_Processor::get_export_limit() ? 1 : 0;
		$active_filters = is_array( $filters ) ? array_filter( $filters ) : [];

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button'        => 'links' === $type ? 'Export Links' : 'Export Posts',
				'export_format' => $format,
				'export_type'   => $type,
				'total_records' => (int) $total,
				'is_background' => $is_background,
				'has_filters'   => ! empty( $active_filters ) ? 1 : 0,
				'filter_count'  => count( $active_filters ),
			]
		);

		$result = Export_Processor::get()->start( $type, $format, $filters, $columns, $total );

		return rest_ensure_response( $result );
	}
}
