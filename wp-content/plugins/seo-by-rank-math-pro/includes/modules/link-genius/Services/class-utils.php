<?php
/**
 * Link Genius utilities.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Utils class for shared helpers.
 */
class Utils {

	/**
	 * Check if current save is from manual post edit screen.
	 *
	 * Returns true ONLY when user is manually saving a post from:
	 * - Classic Editor (post.php with proper nonce and editpost action)
	 * - Block Editor / Gutenberg (REST API from admin edit screen)
	 * - Page builders that hook into rank_math/link_genius/is_manual_ajax_save (e.g. Elementor)
	 *
	 * Returns false for:
	 * - Quick Edit (inline edit from post list)
	 * - Bulk Edit (bulk actions from post list)
	 * - Bulk imports (WP All Import, CSV imports, etc.)
	 * - Programmatic saves (wp_insert_post, wp_update_post calls)
	 * - REST API calls not from post edit screen
	 * - WP-CLI operations
	 * - Cron jobs
	 * - Admin AJAX requests
	 *
	 * @return bool True if manual post save, false otherwise.
	 */
	public static function is_manual_post_save() {
		// Exclude WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		// Exclude cron jobs.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return false;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			/**
			 * Allow page-builder integrations to declare that the current AJAX request
			 * is a manual user save (e.g. Elementor save_builder). Third-party plugins
			 * can hook here instead of modifying this class.
			 *
			 * @param bool $is_manual Whether this AJAX request is a manual save. Default false.
			 */
			if ( apply_filters( 'rank_math/link_genius/is_manual_ajax_save', false ) ) {
				return true;
			}

			return false;
		}

		// Check for Quick Edit (inline edit from post list).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['_inline_edit'] ) ) {
			return false;
		}

		// Check for Bulk Edit.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['bulk_edit'] ) ) {
			return false;
		}

		// Check for REST API request (Block Editor / Gutenberg).
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::is_gutenberg_post_save();
		}

		// Check for Classic Editor save.
		// Classic Editor posts to post.php with specific nonces.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['post_ID'] ) && isset( $_POST['_wpnonce'] ) ) {
			// Verify we're on the post edit screen action.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
			if ( 'editpost' === $action ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map post IDs to array items with id, title, link. Used for Related Posts.
	 *
	 * @param array $ids Post IDs.
	 * @return array[]
	 */
	public static function map_post_ids_to_items( $ids ) {
		if ( empty( $ids ) ) {
			return [];
		}

		$items = [];
		foreach ( (array) $ids as $rid ) {
			$title = get_the_title( $rid );
			$link  = get_the_permalink( $rid );
			if ( empty( $title ) || empty( $link ) ) {
				continue;
			}
			$items[] = [
				'id'    => (int) $rid,
				'title' => wp_strip_all_tags( $title ),
				'link'  => esc_url_raw( $link ),
			];
		}

		return $items;
	}

	/**
	 * Calculate new URL based on update configuration.
	 *
	 * This method handles all URL transformation logic including:
	 * - Exact matching
	 * - Partial replacement with full URL detection
	 * - Path-like replacement detection
	 * - Domain replacement
	 *
	 * @param string $old_url    Original URL.
	 * @param array  $url_update URL update configuration with keys: search_value, replace_value, search_type, case_sensitive (optional).
	 * @return string|null New URL or null if no change.
	 */
	public static function calculate_new_url( $old_url, $url_update ) {
		if ( empty( $url_update['search_value'] ) ) {
			return null;
		}

		$search         = $url_update['search_value'];
		$replace        = $url_update['replace_value'];
		$search_type    = $url_update['search_type'];
		$case_sensitive = isset( $url_update['case_sensitive'] ) ? $url_update['case_sensitive'] : true;

		// For exact URL matching, normalize trailing slashes before comparison.
		if ( 'exact' === $search_type ) {
			return strcasecmp( untrailingslashit( $search ), untrailingslashit( $old_url ) ) === 0 ? $replace : null;
		}

		// Handle domain replacement separately (URL-specific).
		if ( 'domain' === $search_type ) {
			$old_domain = wp_parse_url( $search, PHP_URL_HOST );
			$new_domain = wp_parse_url( $replace, PHP_URL_HOST );

			if ( $old_domain && $new_domain ) {
				$new_value = str_replace( $old_domain, $new_domain, $old_url );
				return $new_value !== $old_url ? $new_value : null;
			}
			return null;
		}

		// For partial/contains, check URL-specific replacements before standard logic.
		if ( in_array( $search_type, [ 'contains', 'partial' ], true ) ) {
			$pattern_exists = $case_sensitive
				? ( false !== strpos( $old_url, $search ) )
				: ( false !== stripos( $old_url, $search ) );

			if ( ! $pattern_exists ) {
				return null;
			}

			// If the replacement value is a full URL, replace the entire URL instead of just the matched portion.
			// This handles cases like: search "sample-page", replace "https://google.com/test"
			// Expected result: "https://google.com/test" (not "http://example.com/https://google.com/test").
			if ( preg_match( '/^https?:\/\//i', $replace ) ) {
				return $replace;
			}

			// If the replacement value looks like a path/slug (no dots, no protocol),
			// and we're replacing a domain-like pattern, convert to internal URL.
			// This handles cases like: search "google.com", replace "hello-world" or "/hello-world"
			// Expected result: internal link to "/hello-world" (not "https://hello-world/test").
			$is_replace_path_like  = ! preg_match( '/[.:]/', $replace ) || preg_match( '/^\//', $replace );
			$is_search_domain_like = preg_match( '/^[a-zA-Z0-9]([a-zA-Z0-9-]*\.)+[a-zA-Z]{2,}$/', $search );
			if ( $is_replace_path_like && $is_search_domain_like ) {
				return home_url( '/' . ltrim( $replace, '/' ) );
			}
		}

		// Use unified replacement logic for common cases (exact, partial, regex).
		return self::apply_text_replacement( $old_url, $search, $replace, $search_type, $case_sensitive );
	}

	/**
	 * Calculate new anchor text based on update configuration.
	 *
	 * This method handles all anchor text transformation logic including:
	 * - Exact matching (case-sensitive and case-insensitive)
	 * - Contains/partial replacement
	 * - Regex pattern replacement
	 *
	 * @param string $old_anchor    Original anchor text.
	 * @param array  $anchor_update Anchor update configuration with keys: search_value, replace_value, search_type, case_sensitive (optional).
	 * @return string|null New anchor text or null if no change.
	 */
	public static function calculate_new_anchor( $old_anchor, $anchor_update ) {
		if ( empty( $anchor_update['search_value'] ) ) {
			return null;
		}

		$search         = $anchor_update['search_value'];
		$replace        = $anchor_update['replace_value'];
		$search_type    = $anchor_update['search_type'];
		$case_sensitive = isset( $anchor_update['case_sensitive'] ) ? $anchor_update['case_sensitive'] : true;

		return self::apply_text_replacement( $old_anchor, $search, $replace, $search_type, $case_sensitive );
	}

	/**
	 * Prepare link changes for snapshot storage.
	 *
	 * This method calculates the new URL and anchor text for each link based on the update configuration.
	 * Used by both bulk update and preview operations.
	 *
	 * @param array $links         Array of link objects with properties: id, url, anchor_text, anchor_type.
	 * @param array $update_config Update configuration with keys: operation_type, url_update, anchor_update.
	 * @return array Array of link change records with keys: link_id, old_url, old_anchor, new_url, new_anchor.
	 */
	public static function prepare_link_changes( $links, $update_config ) {
		$changes = [];

		foreach ( $links as $link ) {
			$change = [
				'link_id'    => $link->id,
				'old_url'    => $link->url,
				'old_anchor' => $link->anchor_text,
				'new_url'    => null,
				'new_anchor' => null,
			];

			// Calculate new values using utility methods.
			if ( in_array( $update_config['operation_type'], [ 'url', 'both' ], true ) ) {
				$change['new_url'] = self::calculate_new_url( $link->url, $update_config['url_update'] );
			}

			if ( in_array( $update_config['operation_type'], [ 'anchor', 'both' ], true ) && 'IMAGE' !== $link->anchor_type ) {
				$change['new_anchor'] = self::calculate_new_anchor( $link->anchor_text, $update_config['anchor_update'] );
			}

			$changes[] = $change;
		}

		return $changes;
	}

	/**
	 * Check if current REST request is from Gutenberg post edit screen.
	 *
	 * Gutenberg saves posts via REST API (POST /wp/v2/posts/{id}).
	 * We verify the request comes from the admin edit screen by checking:
	 * 1. The REST route matches post update pattern
	 * 2. The referer is from the admin edit screen
	 *
	 * @return bool True if Gutenberg post save, false otherwise.
	 */
	private static function is_gutenberg_post_save() {
		// Get the REST route being requested.
		$rest_route = '';
		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$rest_route = $GLOBALS['wp']->query_vars['rest_route'];
		}

		// Check if this is a POST/PUT request to a post endpoint.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';
		if ( ! in_array( $method, [ 'POST', 'PUT' ], true ) ) {
			return false;
		}

		// Verify the route matches a post update pattern: /wp/v2/{post_type}/{id}.
		if ( ! preg_match( '#^/wp/v2/[^/]+/\d+$#', $rest_route ) ) {
			return false;
		}

		// Check that the referer is from the admin post edit screen.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';
		if ( empty( $referer ) ) {
			return false;
		}

		// Verify referer is from admin area.
		$admin_url = admin_url();
		if ( strpos( $referer, $admin_url ) !== 0 ) {
			return false;
		}

		// Check for post.php?post={id}&action=edit (Gutenberg edit screen).
		if ( preg_match( '/post\.php\?.*action=edit/', $referer ) ) {
			return true;
		}

		// Check for post-new.php (new post in Gutenberg).
		if ( strpos( $referer, 'post-new.php' ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * Apply text replacement based on search type.
	 *
	 * Unified method for handling exact, partial, and regex replacements.
	 * Used by both calculate_new_url() and calculate_new_anchor() to avoid code duplication.
	 *
	 * @param string $old_value      Original value to transform.
	 * @param string $search         Search pattern.
	 * @param string $replace        Replacement value.
	 * @param string $search_type    Type of search: 'exact', 'partial', 'contains', or 'regex'.
	 * @param bool   $case_sensitive Whether to use case-sensitive matching.
	 * @return string|null New value or null if no change.
	 */
	private static function apply_text_replacement( $old_value, $search, $replace, $search_type, $case_sensitive ) {
		switch ( $search_type ) {
			case 'exact':
				if ( $case_sensitive ) {
					return $search === $old_value ? $replace : null;
				}
				return strcasecmp( $search, $old_value ) === 0 ? $replace : null;

			case 'contains':
			case 'partial':
				$pattern_exists = $case_sensitive
					? ( false !== strpos( $old_value, $search ) )
					: ( false !== stripos( $old_value, $search ) );

				if ( ! $pattern_exists ) {
					return null;
				}

				if ( $case_sensitive ) {
					$new_value = str_replace( $search, $replace, $old_value );
				} else {
					$new_value = str_ireplace( $search, $replace, $old_value );
				}
				return $new_value !== $old_value ? $new_value : null;

			case 'regex':
				$result = preg_replace( $search, $replace, $old_value );
				if ( null !== $result && PREG_NO_ERROR === preg_last_error() && $result !== $old_value ) {
					return $result;
				}
				return null;

			default:
				return null;
		}
	}
}
