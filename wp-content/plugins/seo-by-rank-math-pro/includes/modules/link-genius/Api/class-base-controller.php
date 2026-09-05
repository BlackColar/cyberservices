<?php
/**
 * Base REST API controller for Link Genius module.
 *
 * Provides shared functionality for all Link Genius REST controllers.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Api
 */

namespace RankMathPro\Link_Genius\Api;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Controller;
use RankMath\Helper;
use RankMath\Rest\Rest_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Base_Controller class.
 *
 * Abstract base class providing shared methods for all Link Genius REST controllers.
 */
abstract class Base_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = Rest_Helper::BASE . '/link-genius';
	}

	/**
	 * Permission check: user must have required capability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_admin_permission( $request ) {
		if ( ! Helper::has_cap( 'link_builder' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to access this resource.', 'rank-math-pro' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Permission check: user can edit the given post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_post_edit_permission( $request ) {
		if ( ! Helper::has_cap( 'link_builder' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to access this resource.', 'rank-math-pro' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Invalid post ID.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to edit this post.', 'rank-math-pro' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Create a success response.
	 *
	 * @param mixed  $data    Response data.
	 * @param string $message Optional success message.
	 * @return WP_REST_Response
	 */
	protected function success( $data, $message = '' ) {
		$response = [
			'success' => true,
			'data'    => $data,
		];

		if ( ! empty( $message ) ) {
			$response['message'] = $message;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Create an error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @return WP_Error
	 */
	protected function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}

	/**
	 * Track Link Genius events with common properties.
	 *
	 * @param string $event_name Event name.
	 * @param array  $properties Event properties.
	 * @return void
	 */
	protected function track_link_genius_event( $event_name, $properties = [] ) {
		// Check if tracking is enabled.
		if ( ! rank_math()->tracking->is_opted_in() ) {
			return;
		}

		// Add common properties.
		$properties['plugin'] = rank_math()->tracking->get_plugin_label();
		$properties['path']   = rank_math()->tracking->get_current_path_with_query();

		rank_math()->tracking->track_event( $event_name, $properties );
	}

	/**
	 * Check if Content AI module is active and requirements are met.
	 *
	 * @return true|WP_Error True if all requirements met, WP_Error otherwise.
	 */
	protected function check_content_ai_requirements() {
		if ( \RankMath\Helper::is_module_active( 'content-ai' ) ) {
			return true;
		}

		return new WP_Error(
			'content_ai_module_disabled',
			__( 'Content AI module is not enabled. Please enable it to use this feature.', 'rank-math-pro' ),
			[
				'status'      => 403,
				'module_name' => 'content-ai',
			]
		);
	}
}
