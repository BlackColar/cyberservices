<?php
/**
 * AI Client - Rank Math API integration for keyword variations.
 *
 * Handles communication with Rank Math Content AI API for generating
 * keyword variations and synonyms.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Keyword_Maps
 */

namespace RankMathPro\Link_Genius\Features\KeywordMaps;

use WP_Error;
use RankMath\Admin\Admin_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * AI_Client class.
 *
 * Integrates with Rank Math Content AI API for keyword variation generation.
 */
class AI_Client {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private $api_base = CONTENT_AI_URL . '/ai/keyword_variations';

	/**
	 * Registered site data.
	 *
	 * @var array
	 */
	private $registered_data;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->registered_data = Admin_Helper::get_registration_data();
	}

	/**
	 * Generate keyword variations via Rank Math API.
	 *
	 * @param string $keyword The base keyword to generate variations for.
	 * @param string $context Optional context to help generate better variations (e.g., description).
	 * @return array|WP_Error Array of variation strings on success, WP_Error on failure.
	 */
	public function generate_keyword_variations( $keyword, $context = '' ) {
		// Validate we have registration data.
		if ( empty( $this->registered_data['username'] ) || empty( $this->registered_data['api_key'] ) ) {
			return new WP_Error(
				'not_registered',
				__( 'Please connect your Rank Math account to use AI features.', 'rank-math-pro' )
			);
		}

		// Validate keyword.
		$keyword = trim( $keyword );
		if ( empty( $keyword ) ) {
			return new WP_Error(
				'empty_keyword',
				__( 'Keyword cannot be empty.', 'rank-math-pro' )
			);
		}

		// Build request.
		$endpoint = $this->api_base;

		$body = [
			'keyword'        => $keyword,
			'username'       => $this->registered_data['username'],
			'api_key'        => $this->registered_data['api_key'],
			'site_url'       => $this->get_site_url(),
			'plugin_version' => rank_math_pro()->version,
		];

		// Add context if provided.
		if ( ! empty( $context ) ) {
			$body['context'] = trim( $context );
		}

		// Make request.
		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 30,
				'body'    => $body,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
				],
			]
		);

		// Handle WP errors.
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'api_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'API request failed: %s', 'rank-math-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		// Handle rate limiting.
		if ( 429 === $status_code ) {
			return new WP_Error(
				'rate_limited',
				__( 'Rate limit exceeded. Please try again later.', 'rank-math-pro' )
			);
		}

		// Handle authentication errors.
		if ( 401 === $status_code || 403 === $status_code ) {
			return new WP_Error(
				'auth_failed',
				__( 'Authentication failed. Please check your Rank Math account connection.', 'rank-math-pro' )
			);
		}

		// Handle insufficient credits.
		if ( 402 === $status_code ) {
			return new WP_Error(
				'insufficient_credits',
				__( 'Insufficient Content AI credits. Please upgrade your plan.', 'rank-math-pro' )
			);
		}

		// Handle other errors.
		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_message = isset( $data['message'] ) ? $data['message'] : __( 'Unknown API error.', 'rank-math-pro' );
			return new WP_Error(
				'api_error',
				$error_message
			);
		}

		$data = json_decode( current( $data['results'] ), true );

		// Validate response structure - expect categorized format.
		$categories         = [ 'synonyms', 'related_phrases', 'long_tail_variations', 'common_misspellings' ];
		$has_valid_category = false;
		foreach ( $categories as $category ) {
			if ( isset( $data[ $category ] ) && is_array( $data[ $category ] ) ) {
				$has_valid_category = true;
				break;
			}
		}

		if ( ! $has_valid_category ) {
			return new WP_Error(
				'invalid_response',
				__( 'Invalid API response format.', 'rank-math-pro' )
			);
		}

		// Sanitize and return categorized variations.
		$categorized = [];
		foreach ( $categories as $category ) {
			if ( isset( $data[ $category ] ) && is_array( $data[ $category ] ) ) {
				$categorized[ $category ] = array_map( 'sanitize_text_field', $data[ $category ] );
			} else {
				$categorized[ $category ] = [];
			}
		}

		return $categorized;
	}

	/**
	 * Get site URL for API requests.
	 *
	 * @return string Site URL.
	 */
	private function get_site_url() {
		if ( ! empty( $this->registered_data['site_url'] ) ) {
			return $this->registered_data['site_url'];
		}

		return home_url();
	}

	/**
	 * Check if AI features are available.
	 *
	 * @return bool True if AI features are available.
	 */
	public function is_available() {
		return ! empty( $this->registered_data['username'] ) && ! empty( $this->registered_data['api_key'] );
	}

	/**
	 * Get remaining credits.
	 *
	 * @return int|false Remaining credits or false on error.
	 */
	public function get_remaining_credits() {
		if ( ! $this->is_available() ) {
			return false;
		}

		// Check if we have cached credits.
		$cached_credits = get_transient( 'rank_math_content_ai_credits' );
		if ( false !== $cached_credits ) {
			return (int) $cached_credits;
		}

		// Fetch from API.
		$endpoint = $this->api_base . 'getCredits';

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 10,
				'body'    => [
					'username'       => $this->registered_data['username'],
					'api_key'        => $this->registered_data['api_key'],
					'site_url'       => $this->get_site_url(),
					'plugin_version' => rank_math_pro()->version,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['credits'] ) ) {
			return false;
		}

		$credits = (int) $data['credits'];

		// Cache for 5 minutes.
		set_transient( 'rank_math_content_ai_credits', $credits, 5 * MINUTE_IN_SECONDS );

		return $credits;
	}
}
