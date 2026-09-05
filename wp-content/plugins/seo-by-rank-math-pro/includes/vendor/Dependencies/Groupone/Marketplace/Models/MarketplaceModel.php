<?php
namespace RankMathPro\Dependencies\Groupone\Marketplace\Models;

/**
 * Handles all HTTP communication with the external marketplace API.
 */

class MarketplaceModel {
	protected $api_url;
	protected $is_sandbox;

	public function __construct( string $api_url, bool $is_sandbox = false ) {
		$this->api_url    = $api_url;
		$this->is_sandbox = $is_sandbox;
	}

	/**
	 * Generic API request method.
	 *
	 * Sends a GET or POST request to the marketplace API with the given payload.
	 * Returns the parsed JSON array on success, or \WP_Error on a WP-level failure.
	 * HTTP-level errors (4xx/5xx) are returned as parsed arrays so the caller can
	 * inspect the API's own error structure.
	 *
	 * @param array  $payload Key-value pairs sent as the request body (credentials + action + data).
	 * @param string $method  HTTP method: 'GET' or 'POST'. Defaults to 'GET'.
	 * @return array|\WP_Error Parsed response array, or \WP_Error on network/WP failure.
	 */
	public function request( array $payload, string $method = 'GET' ) {
		// Production upstream responds well within 30s; sandbox has been
		// observed to exceed it, so use a more generous timeout there.
		$args = [
			'body'    => $payload,
			'timeout' => $this->is_sandbox ? 60 : 30,
		];

		if ( $method === 'POST' ) {
			$response = wp_remote_post( $this->api_url, $args );
		} elseif ( $method === 'DELETE' ) {
			$args['method'] = 'DELETE';
			$response       = wp_remote_request( $this->api_url, $args );
		} else {
			$response = wp_remote_get( $this->api_url, $args );
		}

		if ( is_wp_error( $response ) ) {
			error_log( '[Marketplace] WP error: ' . $response->get_error_message() );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			error_log( '[Marketplace] Empty response body (HTTP ' . $status_code . ')' );
			return [];
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			error_log( '[Marketplace] Invalid JSON response: ' . $body );
			return [];
		}

		if ( $status_code >= 400 ) {
			error_log( '[Marketplace] HTTP ' . $status_code . ': ' . $body );
		}

		return $data;
	}

	/**
	 * Fetch the plugin catalog. Thin wrapper around request() using GET.
	 * Returns an empty array on any failure so the controller's cache logic
	 * can handle it gracefully.
	 *
	 * @param array $payload Credentials + action payload from config.
	 * @return array Parsed API response, or [] on failure.
	 */
	public function fetch_plugins( array $payload = [] ): array {
		$result = $this->request( $payload, 'GET' );

		if ( is_wp_error( $result ) ) {
			return [];
		}

		return $result;
	}
}
