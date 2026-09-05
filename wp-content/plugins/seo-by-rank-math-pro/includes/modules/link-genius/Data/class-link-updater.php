<?php
/**
 * Link Updater - Shared link update logic.
 *
 * This class provides shared link update functionality for both single-link updates
 * and bulk link update operations. It extracts and consolidates pattern building,
 * link matching, and attribute preservation logic previously embedded in class-rest.php.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Link_Updater class.
 *
 * Handles link pattern matching, content updates, and attribute preservation
 * for both single and bulk link operations.
 */
class Link_Updater {

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Build URL patterns for flexible matching in post content.
	 *
	 * Returns array of URL variations to try matching:
	 * - Full URL as stored
	 * - Relative path (for internal links)
	 * - With/without trailing slash
	 *
	 * @param string $url The stored URL.
	 * @return array Array of escaped URL patterns to try.
	 */
	public function build_url_patterns( $url ) {
		static $home_url = null;
		if ( null === $home_url ) {
			$home_url = untrailingslashit( home_url() );
		}

		$patterns = [];

		// Pattern 1: URL as stored (without trailing slash).
		$patterns[] = preg_quote( untrailingslashit( $url ), '/' );

		// Pattern 2: URL with trailing slash.
		$patterns[] = preg_quote( trailingslashit( $url ), '/' );

		// For internal links, add relative path variations.
		if ( 0 === strpos( $url, $home_url ) ) {
			$relative_path = str_replace( $home_url, '', $url );
			if ( ! empty( $relative_path ) ) {
				$patterns[] = preg_quote( untrailingslashit( $relative_path ), '/' );
				$patterns[] = preg_quote( trailingslashit( $relative_path ), '/' );
			}
		}

		return $patterns;
	}

	/**
	 * Update a single link in post content.
	 *
	 * Extracted from class-rest.php::update_link() method.
	 * Handles URL, anchor text, and attribute updates for a specific link.
	 *
	 * @param string $content     Post content.
	 * @param object $link        Link object with url, type, anchor_text, anchor_type properties.
	 * @param string $new_url     New URL for the link.
	 * @param string $new_anchor  New anchor text for the link.
	 * @param array  $options     Update options (is_nofollow, target_blank).
	 * @return string|null Updated content, or null if link not found.
	 */
	public function update_single_link_in_content( $content, $link, $new_url, $new_anchor, $options = [] ) {
		// Build pattern to match the link in content.
		$pattern = $this->build_link_pattern_generic( $link, $content, 'standard' );

		// If no pattern matched, return null to indicate link not found.
		if ( ! $pattern ) {
			return null;
		}

		// Extract options with defaults.
		$is_nofollow  = isset( $options['is_nofollow'] ) ? (int) $options['is_nofollow'] : 0;
		$target_blank = isset( $options['target_blank'] ) ? (int) $options['target_blank'] : 0;

		// Update the link in post content.
		$new_content = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $new_url, $new_anchor, $is_nofollow, $target_blank, $link ) {
				$attrs          = $matches[1];
				$original_inner = $matches[2]; // Original content between <a> tags.

				// Update href attribute.
				$new_attrs = $this->update_href_attribute( $attrs, $new_url );

				// Handle target="_blank" attribute.
				if ( $target_blank ) {
					// Add or keep target="_blank".
					if ( ! preg_match( '/target=["\']/', $new_attrs ) ) {
						$new_attrs .= ' target="_blank"';
					}
				} else {
					// Remove target="_blank" if exists.
					$new_attrs = preg_replace( '/\s*target=["\']_blank["\']/', '', $new_attrs );
				}

				// Handle rel="nofollow" attribute.
				$new_attrs = $this->update_rel_attribute( $new_attrs, $is_nofollow );

				// Clean up extra spaces.
				$new_attrs = $this->cleanup_attributes( $new_attrs );

				// Determine new inner content based on anchor_type.
				$new_inner = $this->determine_inner_content( $link, $original_inner, $new_anchor );

				return '<a ' . $new_attrs . '>' . $new_inner . '</a>';
			},
			$content,
			1 // Only replace the first match.
		);

		return $new_content;
	}

	/**
	 * Find and return a regex pattern to match a link for deletion.
	 *
	 * Extracted from class-rest.php::find_link_pattern() method.
	 * This version captures inner content only, suitable for delete operations.
	 *
	 * @param object $link    Link object with url, type, anchor_text, anchor_type properties.
	 * @param string $content Post content to search in.
	 * @return string|null Matching regex pattern or null if no match found.
	 */
	public function build_delete_pattern( $link, $content ) {
		return $this->build_link_pattern_generic( $link, $content, 'delete' );
	}

	/**
	 * Remove nofollow attribute from a link in content.
	 *
	 * Extracted from class-rest.php::remove_nofollow() method.
	 *
	 * @param string $content Post content.
	 * @param object $link    Link object.
	 * @return string|null Updated content, or null if link not found.
	 */
	public function remove_nofollow_from_content( $content, $link ) {
		// Build pattern for nofollow removal.
		$pattern = $this->build_link_pattern_generic( $link, $content, 'nofollow' );

		if ( ! $pattern ) {
			return null;
		}

		// Remove nofollow from the link.
		$new_content = preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$attrs_before = $matches[1];
				$href_attr    = $matches[2];
				$attrs_after  = $matches[3];
				$anchor_text  = $matches[4];
				$all_attrs    = $attrs_before . ' ' . $attrs_after;

				// Remove nofollow from rel attribute using consolidated method.
				$new_attrs = $this->remove_nofollow_from_rel( $all_attrs );

				// Clean up any double spaces and combine all attributes.
				$all_attrs_cleaned = trim( $attrs_before . ' ' . $href_attr . ' ' . $new_attrs );
				$all_attrs_cleaned = preg_replace( '/\s+/', ' ', $all_attrs_cleaned );

				// Reconstruct the anchor tag without nofollow.
				return '<a ' . $all_attrs_cleaned . '>' . $anchor_text . '</a>';
			},
			$content,
			1 // Only replace the first match.
		);

		return $new_content;
	}

	/**
	 * Update multiple links in content in a single pass (for bulk operations).
	 *
	 * Processes multiple link updates efficiently by iterating once through content.
	 *
	 * @param string $content       Post content.
	 * @param array  $links         Array of link objects to update.
	 * @param array  $update_config Update configuration.
	 * @return string Updated content.
	 */
	public function update_multiple_links_in_content( $content, $links, $update_config ) {
		if ( empty( $links ) ) {
			return $content;
		}

		$operation_type  = $update_config['operation_type'];
		$updated_content = $content;

		// Process each link - update both URL and anchor based on operation_type.
		foreach ( $links as $link ) {
			$new_url    = null;
			$new_anchor = null;

			// Determine new URL if updating URLs.
			if ( in_array( $operation_type, [ 'url', 'both' ], true ) ) {
				$new_url = $this->calculate_new_value(
					$link->url,
					$update_config['url_update'],
					true
				);
			}

			// Determine new anchor if updating anchors (skip IMAGE links).
			if ( in_array( $operation_type, [ 'anchor', 'both' ], true ) && 'IMAGE' !== $link->anchor_type ) {
				$new_anchor = $this->calculate_new_value(
					$link->anchor_text,
					$update_config['anchor_update']
				);
			}

			// Skip if no changes needed.
			if ( null === $new_url && null === $new_anchor ) {
				continue;
			}

			// When operation_type is 'both', require BOTH patterns to match (AND logic).
			// Skip links where only one pattern matches.
			if ( 'both' === $operation_type ) {
				// For IMAGE links, only URL is checked (anchor is skipped above).
				if ( 'IMAGE' !== $link->anchor_type && ( null === $new_url || null === $new_anchor ) ) {
					continue;
				}
			}

			// Build pattern for this link.
			$pattern = $this->build_link_pattern_generic( $link, $updated_content, 'bulk', $update_config );

			if ( ! $pattern ) {
				continue; // Link not found in content.
			}

			// Apply update to content.
			$updated_content = preg_replace_callback(
				$pattern,
				function ( $matches ) use ( $link, $new_url, $new_anchor ) {
					return $this->apply_bulk_update_replacement( $matches, $link, $new_url, $new_anchor );
				},
				$updated_content,
				1 // Only replace first match.
			);
		}

		return $updated_content;
	}

	/**
	 * Update rel attribute to add or remove nofollow.
	 *
	 * Extracted from class-rest.php::update_link() and remove_nofollow() methods.
	 *
	 * @param string $attrs       HTML attributes string.
	 * @param int    $is_nofollow Whether to add (1) or remove (0) nofollow.
	 * @return string Updated attributes string.
	 */
	protected function update_rel_attribute( $attrs, $is_nofollow ) {
		// Remove nofollow from rel attribute.
		if ( ! $is_nofollow ) {
			return $this->remove_nofollow_from_rel( $attrs );
		}

		// Add or update rel attribute with nofollow.
		if ( preg_match( '/rel=["\']([^"\']*)["\']/i', $attrs, $rel_matches ) ) {
			$rel_value = $rel_matches[1];
			if ( stripos( $rel_value, 'nofollow' ) === false ) {
				// Add nofollow to existing rel.
				$new_rel = trim( $rel_value . ' nofollow' );
				$attrs   = preg_replace( '/rel=["\'][^"\']*["\']/', 'rel="' . $new_rel . '"', $attrs );
			}
		} else {
			// Add new rel="nofollow".
			$attrs .= ' rel="nofollow"';
		}

		return $attrs;
	}

	/**
	 * Clean up extra spaces in HTML attributes string.
	 *
	 * @param string $attrs HTML attributes string.
	 * @return string Cleaned attributes string.
	 */
	private function cleanup_attributes( $attrs ) {
		return preg_replace( '/\s+/', ' ', trim( $attrs ) );
	}

	/**
	 * Determine inner content for link based on anchor type.
	 *
	 * @param object $link          Link object with anchor_type property.
	 * @param string $original_inner Original inner HTML content.
	 * @param string $new_anchor     New anchor text (optional, null means keep original).
	 * @return string Inner content to use.
	 */
	private function determine_inner_content( $link, $original_inner, $new_anchor = null ) {
		if ( 'IMAGE' === $link->anchor_type ) {
			// For IMAGE links, preserve the original inner HTML (img tag).
			return $original_inner;
		}

		// For text links, keep original if no new anchor provided.
		if ( null === $new_anchor ) {
			return $original_inner;
		}

		// Replace text while preserving any inline HTML wrapping.
		return $this->replace_text_preserving_tags( $original_inner, $new_anchor );
	}

	/**
	 * Replace text content while preserving surrounding inline HTML tags.
	 *
	 * If the original inner HTML has wrapping tags (e.g., <strong>text</strong>),
	 * the new text is placed inside those same wrapper tags.
	 * If no wrapping tags exist, returns the escaped new text directly.
	 *
	 * @param string $original_inner Original inner HTML from the <a> tag.
	 * @param string $new_text       New plain text to insert.
	 * @return string New inner content with preserved wrapping tags.
	 */
	private function replace_text_preserving_tags( $original_inner, $new_text ) {
		$pattern = '/^(\s*(?:<[a-z][a-z0-9]*\b[^>]*>\s*)*)(.+?)(\s*(?:<\/[a-z][a-z0-9]*>\s*)*)$/is';

		if ( preg_match( $pattern, $original_inner, $parts ) ) {
			$leading_tags  = $parts[1];
			$trailing_tags = $parts[3];

			// Only preserve if there are actual HTML tags present.
			if ( '' !== trim( $leading_tags ) || '' !== trim( $trailing_tags ) ) {
				return $leading_tags . esc_html( $new_text ) . $trailing_tags;
			}
		}

		// No wrapping tags - return escaped text as before.
		return esc_html( $new_text );
	}

	/**
	 * Build a generic regex pattern to match a specific link in content.
	 *
	 * This is the core pattern builder used by all link operations (update, delete, nofollow, bulk).
	 * Consolidates previously duplicated pattern-building logic across 4 different methods.
	 *
	 * @param object $link         Link object with url, type, anchor_text, anchor_type properties.
	 * @param string $content      Post content to search in.
	 * @param string $capture_mode Capture mode: 'standard', 'delete', 'nofollow', 'bulk'.
	 * @param array  $options      Optional configuration for specific modes (e.g., operation_type for bulk).
	 * @return string|null Matching regex pattern or null if no match found.
	 */
	private function build_link_pattern_generic( $link, $content, $capture_mode = 'standard', $options = [] ) {
		// Build URL patterns for matching.
		$url_patterns = $this->build_url_patterns( $link->url );

		// Build pattern based on anchor_type and capture_mode - try multiple URL patterns.
		foreach ( $url_patterns as $url_safe ) {
			if ( 'IMAGE' === $link->anchor_type ) {
				// For IMAGE links, match <a href="url"><img...></a>.
				$test_pattern = $this->get_image_pattern( $url_safe, $capture_mode );
			} else {
				// For text links (HPLNK, CNCL, HLANG), match exact anchor text.
				$anchor_safe  = preg_quote( $link->anchor_text, '/' );
				$test_pattern = $this->get_text_pattern( $url_safe, $anchor_safe, $capture_mode, $options );
			}

			// Test if pattern matches in content.
			if ( preg_match( $test_pattern, $content ) ) {
				return $test_pattern;
			}
		}

		return null;
	}

	/**
	 * Get regex pattern for IMAGE anchor links based on capture mode.
	 *
	 * @param string $url_safe     Escaped URL pattern.
	 * @param string $capture_mode Capture mode.
	 * @return string Regex pattern.
	 */
	private function get_image_pattern( $url_safe, $capture_mode ) {
		switch ( $capture_mode ) {
			case 'nofollow':
				// Capture: (1) attrs before href, (2) href attr, (3) attrs after href, (4) inner content.
				return '/<a\s+([^>]*?)(href=["\']' . $url_safe . '["\'])([^>]*?)>(.*?<img[^>]*>.*?)<\/a>/is';

			case 'delete':
				// Capture: (1) inner content only (for deletion).
				return '/<a\s+[^>]*?href=["\']' . $url_safe . '["\'][^>]*?>(.*?<img[^>]*>.*?)<\/a>/is';

			case 'standard':
			case 'bulk':
			default:
				// Capture: (1) all attributes including href, (2) inner content with img tag.
				return '/<a\s+([^>]*?href=["\']' . $url_safe . '["\'][^>]*?)>(.*?<img[^>]*>.*?)<\/a>/is';
		}
	}

	/**
	 * Get regex pattern for TEXT anchor links based on capture mode.
	 *
	 * @param string $url_safe     Escaped URL pattern.
	 * @param string $anchor_safe  Escaped anchor text pattern.
	 * @param string $capture_mode Capture mode.
	 * @param array  $options      Optional configuration.
	 * @return string Regex pattern.
	 */
	private function get_text_pattern( $url_safe, $anchor_safe, $capture_mode, $options = [] ) {
		$anchor_group = $this->wrap_anchor_pattern( $anchor_safe );

		switch ( $capture_mode ) {
			case 'nofollow':
				// Capture: (1) attrs before href, (2) href attr, (3) attrs after href, (4) anchor text with optional inline tags.
				return '/<a\s+([^>]*?)(href=["\']' . $url_safe . '["\'])([^>]*?)>' . $anchor_group . '<\/a>/is';

			case 'delete':
				// Capture: (1) anchor text with optional inline tags (for deletion - replaces <a> with inner content).
				return '/<a\s+[^>]*?href=["\']' . $url_safe . '["\'][^>]*?>' . $anchor_group . '<\/a>/is';

			case 'bulk':
				// For bulk operations, check if we're updating anchors.
				$operation_type = isset( $options['operation_type'] ) ? $options['operation_type'] : 'url';
				if ( in_array( $operation_type, [ 'anchor', 'both' ], true ) ) {
					// Use flexible pattern when updating anchors.
					return '/<a\s+([^>]*?href=["\']' . $url_safe . '["\'][^>]*?)>' . $anchor_group . '<\/a>/is';
				}
				// Fall through to standard pattern for URL-only updates.

			case 'standard':
			default:
				// Capture: (1) all attributes including href, (2) anchor text with optional inline tags.
				return '/<a\s+([^>]*?href=["\']' . $url_safe . '["\'][^>]*?)>' . $anchor_group . '<\/a>/is';
		}
	}

	/**
	 * Wrap anchor text pattern to allow optional inline HTML tags.
	 *
	 * Handles cases where the anchor text in content is wrapped in inline
	 * formatting tags (e.g., <strong>, <em>, <span>) while the database
	 * stores only the plain text (HTML stripped via wp_strip_all_tags).
	 * Also handles void elements (e.g., <br>, <br/>) adjacent to the text.
	 *
	 * @param string $anchor_safe Escaped anchor text pattern.
	 * @return string Capturing group pattern that matches anchor text with optional inline HTML wrapping.
	 */
	private function wrap_anchor_pattern( $anchor_safe ) {
		$open_tags = '(?:<[a-z][a-z0-9]*\b[^>]*>\s*)*';
		// Match either a standard closing tag (</foo>) or a void/self-closing element (<br>, <br/>, <img .../>).
		$close_tags = '(?:(?:<\/[a-z][a-z0-9]*>|<(?:br|hr|img|input|link|meta|area|base|col|embed|param|source|track|wbr)\b[^>]*\/?>)\s*)*';
		return '(\s*' . $open_tags . $anchor_safe . '\s*' . $close_tags . ')';
	}

	/**
	 * Remove nofollow value from rel attribute.
	 *
	 * Consolidated logic used by both update_rel_attribute() and remove_nofollow_from_content().
	 *
	 * @param string $attrs HTML attributes string.
	 * @return string Updated attributes string with nofollow removed.
	 */
	private function remove_nofollow_from_rel( $attrs ) {
		return preg_replace_callback(
			'/rel=["\']([^"\']*)["\']/i',
			function ( $rel_matches ) {
				$rel_value = $rel_matches[1];
				// Remove 'nofollow' from the rel attribute value.
				$rel_parts = array_filter(
					array_map( 'trim', explode( ' ', $rel_value ) ),
					function ( $part ) {
						return 'nofollow' !== strtolower( $part );
					}
				);

				// If no rel values remain, remove the entire rel attribute.
				if ( empty( $rel_parts ) ) {
					return '';
				}

				return 'rel="' . implode( ' ', $rel_parts ) . '"';
			},
			$attrs
		);
	}

	/**
	 * Calculate new value based on search/replace configuration.
	 *
	 * @param string $old_value Original value (URL or anchor text).
	 * @param array  $update    Update configuration with search_type, search_value, replace_value.
	 * @param bool   $is_url    Whether the value being matched is a URL (enables trailing slash normalization).
	 * @return string|null New value or null if no change.
	 */
	private function calculate_new_value( $old_value, $update, $is_url = false ) {
		if ( empty( $update['search_value'] ) ) {
			return null;
		}

		$search         = $update['search_value'];
		$replace        = $update['replace_value'];
		$search_type    = $update['search_type'];
		$case_sensitive = isset( $update['case_sensitive'] ) ? $update['case_sensitive'] : true;

		switch ( $search_type ) {
			case 'exact':
				// For URL matching, normalize trailing slashes and compare case-insensitively.
				if ( $is_url ) {
					return strcasecmp( untrailingslashit( $search ), untrailingslashit( $old_value ) ) === 0 ? $replace : null;
				}
				if ( $case_sensitive ) {
					return $search === $old_value ? $replace : null;
				} else {
					return strcasecmp( $search, $old_value ) === 0 ? $replace : null;
				}

			case 'contains':
			case 'partial':
				$whole_word = isset( $update['whole_word'] ) ? $update['whole_word'] : false;

				// Check if the search pattern exists in the old value.
				$pattern_exists = $case_sensitive
					? ( false !== strpos( $old_value, $search ) )
					: ( false !== stripos( $old_value, $search ) );

				if ( ! $pattern_exists ) {
					return null;
				}

				// If the replacement value is a full URL, replace the entire URL instead of just the matched portion.
				$is_replace_full_url = preg_match( '/^https?:\/\//i', $replace );
				if ( $is_replace_full_url ) {
					return $replace;
				}

				// If the replacement value looks like a path/slug (no dots, no protocol),
				// and we're replacing a domain-like pattern, convert to internal URL.
				$is_replace_path_like  = ! preg_match( '/[.:]/', $replace ) || preg_match( '/^\//', $replace );
				$is_search_domain_like = preg_match( '/^[a-zA-Z0-9]([a-zA-Z0-9-]*\.)+[a-zA-Z]{2,}$/', $search );
				if ( $is_replace_path_like && $is_search_domain_like ) {
					// User is replacing a domain with a path - create internal URL.
					return home_url( '/' . ltrim( $replace, '/' ) );
				}

				if ( $whole_word ) {
					// Use word boundary regex for whole word matching.
					$pattern = '/\b' . preg_quote( $search, '/' ) . '\b/';
					if ( ! $case_sensitive ) {
						$pattern .= 'i';
					}
					$new_value = preg_replace( $pattern, $replace, $old_value );
				} else {
					// Regular replacement (existing logic).
					$new_value = $case_sensitive
						? str_replace( $search, $replace, $old_value )
						: str_ireplace( $search, $replace, $old_value );
				}
				return $new_value !== $old_value ? $new_value : null;

			case 'regex':
				// Use preg_replace for regex pattern.
				$result = preg_replace( $search, $replace, $old_value );
				if ( null !== $result && PREG_NO_ERROR === preg_last_error() && $result !== $old_value ) {
					return $result;
				}
				return null;

			case 'domain':
				// Extract domain from search and replace URLs.
				$old_domain = wp_parse_url( $search, PHP_URL_HOST );
				$new_domain = wp_parse_url( $replace, PHP_URL_HOST );

				if ( $old_domain && $new_domain ) {
					$new_value = str_replace( $old_domain, $new_domain, $old_value );
					return $new_value !== $old_value ? $new_value : null;
				}
				return null;

			default:
				return null;
		}
	}

	/**
	 * Apply bulk update replacement.
	 *
	 * @param array  $matches    Regex matches.
	 * @param object $link       Link object.
	 * @param string $new_url    New URL (or null if not updating).
	 * @param string $new_anchor New anchor text (or null if not updating).
	 * @return string Replacement HTML.
	 */
	private function apply_bulk_update_replacement( $matches, $link, $new_url, $new_anchor ) {
		$attrs          = $matches[1];
		$original_inner = $matches[2]; // Original content between <a> tags.

		// Update href attribute if new URL provided.
		if ( null !== $new_url ) {
			$attrs = $this->update_href_attribute( $attrs, $new_url );
		}

		// Determine inner content.
		$new_inner = $this->determine_inner_content( $link, $original_inner, $new_anchor );

		// Clean up extra spaces in attributes.
		$attrs = $this->cleanup_attributes( $attrs );

		return '<a ' . $attrs . '>' . $new_inner . '</a>';
	}

	/**
	 * Update href attribute in HTML attributes string.
	 *
	 * @param string $attrs   HTML attributes string.
	 * @param string $new_url New URL to set.
	 * @return string Updated attributes string.
	 */
	private function update_href_attribute( $attrs, $new_url ) {
		return preg_replace(
			'/href=["\'][^"\']*["\']/',
			'href="' . esc_url( $new_url ) . '"',
			$attrs
		);
	}
}
