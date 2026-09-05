<?php
/**
 * Robots.txt Checker for Link Status Crawler.
 *
 * Checks if URLs are allowed by robots.txt before crawling.
 *
 * @since      1.0.99
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Robots_Checker class.
 *
 * Fetches and parses robots.txt files to determine if URLs are allowed for crawling.
 * Results are cached per domain for 24 hours to reduce HTTP requests.
 */
class Robots_Checker {

	/**
	 * Cache group for robots.txt data.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'rank_math_robots';

	/**
	 * Cache TTL (24 hours).
	 *
	 * @var int
	 */
	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * User agent string for robots.txt matching.
	 *
	 * @var string
	 */
	const USER_AGENT = 'RankMath';

	/**
	 * Main instance.
	 *
	 * Ensure only one instance is loaded or can be loaded.
	 *
	 * @return Robots_Checker
	 */
	public static function get() {
		static $instance;

		if ( is_null( $instance ) && ! ( $instance instanceof Robots_Checker ) ) {
			$instance = new Robots_Checker();
		}

		return $instance;
	}

	/**
	 * Check if URL is allowed by robots.txt.
	 *
	 * @param string $url URL to check.
	 * @return bool True if allowed, false if blocked.
	 */
	public function is_allowed( $url ) {
		$parsed = wp_parse_url( $url );

		if ( ! isset( $parsed['host'] ) ) {
			return true; // Invalid URL, allow by default.
		}

		$domain = $parsed['host'];
		$path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';

		// Get robots.txt for domain.
		$robots_txt = $this->get_robots_txt( $domain );

		if ( null === $robots_txt ) {
			return true; // No robots.txt or error fetching, allow by default.
		}

		// Parse and check rules.
		return $this->check_rules( $robots_txt, $path );
	}

	/**
	 * Get robots.txt content for domain.
	 *
	 * @param string $domain Domain name.
	 * @return string|null Robots.txt content or null if not found/error.
	 */
	private function get_robots_txt( $domain ) {
		// Use transient for persistence across requests.
		$cache_key = 'rm_robots_' . md5( $domain );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			// Return cached value (empty string means "no robots.txt").
			return $cached === '' ? null : $cached;
		}

		// Fetch robots.txt.
		$robots_txt = $this->fetch_robots_txt( $domain );

		// Cache result for 24 hours (use empty string for null to differentiate from "not cached").
		set_transient( $cache_key, $robots_txt ?? '', self::CACHE_TTL );

		return $robots_txt;
	}

	/**
	 * Fetch robots.txt from domain.
	 *
	 * @param string $domain Domain name.
	 * @return string|null Robots.txt content or null if not found/error.
	 */
	private function fetch_robots_txt( $domain ) {
		// Try HTTPS first, then HTTP.
		$urls = [
			'https://' . $domain . '/robots.txt',
			'http://' . $domain . '/robots.txt',
		];

		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				[
					'timeout'    => 5,
					'user-agent' => 'RankMath Link Checker/1.0 (+https://rankmath.com)',
					'sslverify'  => false,
				]
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 === $code ) {
				return wp_remote_retrieve_body( $response );
			}
		}

		return null; // Not found or error.
	}

	/**
	 * Check if path is allowed according to robots.txt rules.
	 *
	 * @param string $robots_txt Robots.txt content.
	 * @param string $path       URL path to check.
	 * @return bool True if allowed, false if blocked.
	 */
	private function check_rules( $robots_txt, $path ) {
		// Parse robots.txt into rules.
		$rules = $this->parse_robots_txt( $robots_txt );

		if ( empty( $rules ) ) {
			return true; // No rules, allow by default.
		}

		// RFC 9309: Longest matching pattern wins. Equal length = Allow wins.
		$best_match_length = -1;
		$best_match_allow  = true;

		foreach ( $rules as $rule ) {
			$pattern = $rule['pattern'];

			// Convert robots.txt pattern to regex.
			// * = zero or more characters
			// $ = end of URL.
			$regex_pattern = preg_quote( $pattern, '/' );
			$regex_pattern = str_replace( '\*', '.*', $regex_pattern );
			$regex_pattern = str_replace( '\$', '$', $regex_pattern );

			if ( preg_match( '/^' . $regex_pattern . '/i', $path ) ) {
				$pattern_length = strlen( $pattern );

				if ( $pattern_length > $best_match_length
					|| ( $pattern_length === $best_match_length && 'allow' === $rule['directive'] ) ) {
					$best_match_length = $pattern_length;
					$best_match_allow  = ( 'allow' === $rule['directive'] );
				}
			}
		}

		return $best_match_allow;
	}

	/**
	 * Parse robots.txt into rules array.
	 *
	 * Parses rules for our user agent (RankMath) or wildcard (*).
	 *
	 * @param string $robots_txt Robots.txt content.
	 * @return array Rules array.
	 */
	private function parse_robots_txt( $robots_txt ) {
		$rules         = [];
		$current_agent = null;
		$applies_to_us = false;

		// Split into lines.
		$lines = explode( "\n", $robots_txt );

		foreach ( $lines as $line ) {
			// Remove comments and whitespace.
			$line = trim( preg_replace( '/#.*$/', '', $line ) );

			if ( empty( $line ) ) {
				continue;
			}

			// Parse directive.
			if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $matches ) ) {
				$agent         = trim( $matches[1] );
				$current_agent = $agent;

				// Check if this rule block applies to us.
				$applies_to_us = ( '*' === $agent || stripos( $agent, self::USER_AGENT ) !== false );
			} elseif ( preg_match( '/^(disallow|allow):\s*(.*)$/i', $line, $matches ) && $applies_to_us ) {
				$directive = strtolower( trim( $matches[1] ) );
				$pattern   = trim( $matches[2] );

				if ( empty( $pattern ) ) {
					continue; // Empty pattern, skip.
				}

				$rules[] = [
					'directive' => $directive,
					'pattern'   => $pattern,
				];
			}
		}

		return $rules;
	}
}
