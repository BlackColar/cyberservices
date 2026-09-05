<?php
/**
 * The Content Processor for Link Genius.
 *
 * Enhanced link extraction with anchor text, rel attributes, and more.
 *
 * @since      1.0.98
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Services;

use RankMath\Links\Link;
use RankMath\Links\ContentProcessor as Free_ContentProcessor;
use RankMathPro\Link_Genius\Background\Link_Status_Crawler;
use RankMathPro\Link_Genius\Data\Query_Builder;
use RankMathPro\Link_Genius\Services\Utils;
use RankMathPro\Link_Genius\Services\Batch_Helper;
use RankMath\Sitemap\Classifier;
use RankMath\Traits\Hooker;
use RankMath\Helper;
use RankMath\Helpers\Str;
use RankMath\Helpers\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Content_Processor class.
 */
class Content_Processor {

	use Hooker;

	/**
	 * Free plugin's ContentProcessor for shared utility methods.
	 *
	 * @var Free_ContentProcessor
	 */
	protected $free_processor;

	/**
	 * Used to track and cache WordPress site settings.
	 *
	 * @var array
	 */
	private $site_settings = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->free_processor = new Free_ContentProcessor();

		// Hook into FREE plugin's extraction filter.
		$this->filter( 'rank_math/links/extract', 'extract_enhanced', 10, 3 );

		// Hook into FREE plugin's save filter to save enhanced data.
		$this->filter( 'rank_math/links/save_links', 'save_enhanced_links', 10, 3 );
	}

	/**
	 * Enhanced extraction - single pass to get all link data.
	 *
	 * @param null   $data    Default null value.
	 * @param string $content The post content.
	 * @param int    $post_id The post ID.
	 * @return array Array with 'links' and 'counts' keys.
	 */
	public function extract_enhanced( $data, $content, $post_id ) {
		// Quick check if content has any links.
		if ( false === Str::contains( 'href', $content ) ) {
			return [
				'links'  => [],
				'counts' => [
					'internal' => 0,
					'external' => 0,
				],
			];
		}

		// Extract all <a> tags with full attributes in single pass.
		preg_match_all(
			'/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>(.*?)<\/a>/is',
			$content,
			$matches,
			PREG_SET_ORDER
		);

		$new_links      = [];
		$counts         = [
			'internal' => 0,
			'external' => 0,
		];
		$current_time   = current_time( 'mysql' );
		$post_permalink = untrailingslashit( get_permalink( $post_id ) );

		// Collect all internal URLs first, then batch resolve post IDs.
		$internal_urls     = [];
		$pending_link_data = [];

		foreach ( $matches as $match ) {
			$attrs_before = $match[1];
			$href         = $match[2];
			$attrs_after  = $match[3];
			$anchor_html  = $match[4];

			// Filter out invalid URLs early (non-HTTP(S) schemes, WP admin/system URLs).
			if ( ! $this->is_valid_content_url( $href ) ) {
				continue;
			}

			// Get full URL without normalization.
			$full_url = $this->get_full_url( $href );

			// Skip self-links.
			if ( untrailingslashit( $post_permalink ) === untrailingslashit( $full_url ) ) {
				continue;
			}

			// Validate the link type.
			$type = $this->free_processor->is_valid_link_type( $full_url );
			if ( empty( $type ) ) {
				continue;
			}

			// Combine all attributes for easier parsing.
			$all_attrs = $attrs_before . ' ' . $attrs_after;

			// Store link data for batch processing.
			$link_data = [
				'url'          => $full_url,
				'type'         => $type,
				'anchor_html'  => $anchor_html,
				'is_nofollow'  => $this->has_nofollow( $all_attrs ),
				'target_blank' => $this->has_target_blank( $all_attrs ),
				'anchor_type'  => $this->determine_anchor_type( $all_attrs, $anchor_html ),
				'anchor_text'  => $this->clean_anchor_text( $anchor_html ),
			];

			// Collect internal URLs for batch resolution.
			if ( Classifier::TYPE_INTERNAL === $type ) {
				$internal_urls[ $full_url ] = 0; // Will be populated by batch query.
			}

			$pending_link_data[] = $link_data;
			++$counts[ $type ];
		}

		// BATCH RESOLVE: Get all post IDs for internal URLs in single query.
		if ( ! empty( $internal_urls ) ) {
			$internal_urls = $this->batch_url_to_postid( array_keys( $internal_urls ) );
		}

		// Now create Link objects with resolved post IDs.
		foreach ( $pending_link_data as $link_data ) {
			$target_post_id = 0;
			if ( Classifier::TYPE_INTERNAL === $link_data['type'] ) {
				$target_post_id = $internal_urls[ $link_data['url'] ] ?? 0;
			}

			// Calculate URL hash for duplicate detection and link audit.
			$url_hash = md5( $link_data['url'] );

			// Create enhanced Link object.
			$link               = new Link( $link_data['url'], $target_post_id, $link_data['type'] );
			$link->anchor_text  = $link_data['anchor_text'];
			$link->is_nofollow  = $link_data['is_nofollow'];
			$link->url_hash     = $url_hash;
			$link->created_at   = $current_time;
			$link->anchor_type  = $link_data['anchor_type'];
			$link->target_blank = $link_data['target_blank'];

			$new_links[] = $link;
		}

		return [
			'links'  => $new_links,
			'counts' => $counts,
		];
	}

	/**
	 * Save enhanced links with PRO columns.
	 *
	 * @param null  $override Whether to override default save.
	 * @param int   $post_id  Post ID.
	 * @param array $links    Array of Link objects.
	 * @return bool True to bypass default save.
	 */
	public function save_enhanced_links( $override, $post_id, $links ) {
		if ( empty( $links ) ) {
			return true;
		}

		// Batch INSERT instead of individual INSERTs.
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_internal_links';

		// Build VALUES for batch INSERT.
		$values              = [];
		$link_data_for_queue = [];
		$current_time        = current_time( 'mysql' );

		foreach ( $links as $link ) {
			$values[] = $wpdb->prepare(
				'(%s, %d, %d, %s, %s, %d, %s, %s, %s, %d)',
				$link->get_url(),
				$post_id,
				$link->get_target_post_id(),
				$link->get_type(),
				isset( $link->anchor_text ) ? $link->anchor_text : null,
				isset( $link->is_nofollow ) ? (int) $link->is_nofollow : 0,
				isset( $link->url_hash ) ? $link->url_hash : null,
				isset( $link->created_at ) ? $link->created_at : $current_time,
				isset( $link->anchor_type ) ? $link->anchor_type : 'HPLNK',
				isset( $link->target_blank ) ? (int) $link->target_blank : 0
			);

			// Collect link data for status checking (we'll get last_insert_id after batch).
			if ( isset( $link->url_hash ) ) {
				$link_data_for_queue[] = [
					'url'      => $link->get_url(),
					'url_hash' => $link->url_hash,
					'type'     => $link->get_type(),
				];
			}
		}

		// Execute single INSERT with multiple VALUES.
		$wpdb->query(
			"INSERT INTO {$table}
			(url, post_id, target_post_id, type, anchor_text, is_nofollow, url_hash, created_at, anchor_type, target_blank)
			VALUES " . implode( ', ', $values )
		);

		// Invalidate query cache since links have been modified.
		Query_Builder::invalidate_cache();

		/**
		 * Filter whether to queue links for Link Audit status checking on post save.
		 *
		 * This filter runs before checking if it's a manual post save to avoid unnecessary
		 * overhead when queueing is disabled via filter.
		 *
		 * @param bool  $should_queue Whether to queue links. Default true.
		 * @param int   $post_id      Post ID being saved.
		 * @param array $link_data    Array of link data to be queued.
		 */
		$should_queue = apply_filters( 'rank_math/link_genius/queue_links_on_save', true, $post_id, $link_data_for_queue );

		// Link data is stored in an option and processed asynchronously via WP Cron event to avoid blocking.
		if ( $should_queue && Utils::is_manual_post_save() && ! empty( $link_data_for_queue ) ) {
			// Store link data in option for async processing via WP Cron.
			$option_key = 'rm_lg_pending_queue_' . $post_id;
			update_option( $option_key, $link_data_for_queue, false );

			// Schedule async processing if not already scheduled.
			if ( ! wp_next_scheduled( 'rank_math_link_genius_queue_pending_links' ) ) {
				wp_schedule_single_event( time() + 60, 'rank_math_link_genius_queue_pending_links' );
			}
		}

		// Return true to bypass FREE plugin's save.
		return true;
	}

	/**
	 * Get full URL from href attribute.
	 *
	 * Converts relative URLs to absolute URLs, removes fragments.
	 * Uses static caching for performance optimization.
	 *
	 * @param string $href The href attribute value.
	 * @return string Full URL.
	 */
	private function get_full_url( $href ) {
		static $home_url = null;
		if ( null === $home_url ) {
			$home_url = home_url();
		}

		// Remove fragment (hash).
		$href = explode( '#', $href )[0];

		// If already a full URL, return normalized.
		if ( filter_var( $href, FILTER_VALIDATE_URL ) ) {
			return untrailingslashit( $href );
		}

		// Convert relative URL to absolute - optimize by checking first character.
		if ( '/' === $href[0] ) {
			// Absolute path.
			return untrailingslashit( $home_url . $href );
		}

		// Relative path.
		return untrailingslashit( $home_url . '/' . $href );
	}

	/**
	 * Check if link has nofollow attribute.
	 *
	 * @param string $attributes HTML attributes string.
	 * @return bool True if link has nofollow, false otherwise.
	 */
	private function has_nofollow( $attributes ) {
		if ( preg_match( '/rel=["\']([^"\']*)["\']/is', $attributes, $matches ) ) {
			$rel_value = trim( $matches[1] );
			// Check if rel contains 'nofollow'.
			return stripos( $rel_value, 'nofollow' ) !== false;
		}
		return false;
	}

	/**
	 * Check if link has target="_blank" attribute.
	 *
	 * @param string $attributes HTML attributes string.
	 * @return bool True if link has target="_blank", false otherwise.
	 */
	private function has_target_blank( $attributes ) {
		return (bool) preg_match( '/target=["\']_blank["\']/is', $attributes );
	}

	/**
	 * Determine anchor type based on link attributes and content.
	 *
	 * @param string $attributes HTML attributes string.
	 * @param string $anchor_html Anchor HTML content.
	 * @return string Anchor type (HPLNK, CNCL, HLANG, IMAGE).
	 */
	private function determine_anchor_type( $attributes, $anchor_html ) {
		// Check for canonical link (rel="canonical").
		if ( preg_match( '/rel=["\']([^"\']*)["\']/is', $attributes, $matches ) ) {
			$rel_value = trim( $matches[1] );
			if ( stripos( $rel_value, 'canonical' ) !== false ) {
				return 'CNCL';
			}
			// Check for alternate/hreflang.
			if ( stripos( $rel_value, 'alternate' ) !== false && preg_match( '/hreflang=/is', $attributes ) ) {
				return 'HLANG';
			}
		}

		// Check if anchor contains an image tag.
		if ( preg_match( '/<img[^>]*>/is', $anchor_html ) ) {
			return 'IMAGE';
		}

		// Default to standard hyperlink.
		return 'HPLNK';
	}

	/**
	 * Clean anchor text - strip HTML and decode entities.
	 *
	 * @param string $html Anchor HTML content.
	 * @return string|null Cleaned anchor text.
	 */
	private function clean_anchor_text( $html ) {
		// Strip all HTML tags.
		$text = wp_strip_all_tags( $html );

		// Decode HTML entities.
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		// Trim whitespace.
		$text = trim( $text );

		// Limit to 500 characters.
		if ( strlen( $text ) > 500 ) {
			$text = substr( $text, 0, 500 );
		}

		return empty( $text ) ? null : $text;
	}

	/**
	 * Validate if URL is a valid content link.
	 *
	 * Filters out:
	 * - Non-HTTP(S) schemes (mailto, tel, sms, fax, javascript, data, etc.)
	 * - WordPress admin and system URLs (wp-admin, admin-ajax, REST API, cron, feeds)
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if valid content URL, false otherwise.
	 */
	private function is_valid_content_url( $url ) {
		// Skip empty URLs and same-page anchor links (#section, /#section).
		if ( empty( $url ) || '#' === $url[0] || Str::starts_with( '/#', $url ) ) {
			return false;
		}

		// Check if URL has a scheme (absolute URL).
		$parsed = wp_parse_url( $url );
		if ( false === $parsed ) {
			return false;
		}

		// For absolute URLs, validate HTTP/HTTPS scheme using Helper.
		if ( isset( $parsed['scheme'] ) && ! Url::is_url( $url ) ) {
			return false;
		}

		// Check for WordPress admin and system paths.
		$path = isset( $parsed['path'] ) ? $parsed['path'] : '';
		if ( ! empty( $path ) ) {
			// Normalize path for checking.
			$path = strtolower( $path );

			// Define WordPress system path patterns to exclude.
			$excluded_patterns = [
				'/wp-admin',           // Admin area.
				'/wp-login.php',       // Login page.
				'/wp-cron.php',        // Cron endpoint.
				'/xmlrpc.php',         // XML-RPC endpoint.
				'admin-ajax.php',      // AJAX handler.
				'/wp-json/',           // REST API.
				'/feed/',              // RSS/Atom feeds.
				'/trackback/',         // Trackback endpoint.
			];

			foreach ( $excluded_patterns as $pattern ) {
				if ( false !== strpos( $path, $pattern ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Batch resolve URLs to post IDs.
	 *
	 * Replaces N individual url_to_postid() calls
	 * with 1 batch query. Reduces post save time from 30-40s to 2-6s.
	 *
	 * Multiple post types can share a slug, so compare each candidate's permalink
	 * with the requested URL to find the correct post.
	 *
	 * @param array $urls Array of URLs to resolve.
	 * @return array Associative array of url => post_id.
	 */
	private function batch_url_to_postid( $urls ) {
		if ( empty( $urls ) ) {
			return [];
		}

		global $wpdb;
		$home_url = untrailingslashit( home_url() );

		// Extract post slugs from URLs for batch query.
		$slugs_to_urls = [];
		foreach ( $urls as $url ) {
			$path     = str_replace( $home_url, '', untrailingslashit( $url ) );
			$segments = array_filter( explode( '/', trim( $path, '/' ) ) );
			if ( ! empty( $segments ) ) {
				$slug                     = end( $segments );
				$slugs_to_urls[ $slug ][] = $url;
			}
		}

		$results = [];

		if ( ! empty( $slugs_to_urls ) ) {
			$slugs        = array_keys( $slugs_to_urls );
			$placeholders = Batch_Helper::generate_placeholders( $slugs, '%s' );

			// Single batch query for all post slugs.
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_name, post_type
					FROM {$wpdb->posts}
					WHERE post_name IN ({$placeholders})
					AND post_status IN ('publish', 'future')
					LIMIT 200", // phpcs:ignore
					...$slugs
				)
			);

			// Group candidate posts by slug - multiple post types can share a post_name.
			$slug_to_posts = [];
			foreach ( $posts as $post ) {
				$slug_to_posts[ $post->post_name ][] = $post;
			}

			// Build final URL => post_id mapping.
			foreach ( $slugs_to_urls as $slug => $url_list ) {
				$candidates = $slug_to_posts[ $slug ] ?? [];
				foreach ( $url_list as $url ) {
					$results[ untrailingslashit( $url ) ] = $this->resolve_candidate_post_id( $candidates, $url );
				}
			}
		}

		// For any unresolved URLs (post_id = 0), leave as 0.
		// Don't call url_to_postid() individually - it's too slow.
		foreach ( $urls as $url ) {
			$normalized_url = untrailingslashit( $url );
			if ( ! isset( $results[ $normalized_url ] ) ) {
				$atts                       = $this->get_site_settings();
				$results[ $normalized_url ] = $normalized_url === $atts['home_url'] && $atts['homepage_static'] ? (int) get_option( 'page_on_front' ) : 0;
			}
		}

		return $results;
	}

	/**
	 * Pick the right post ID out of same-slug candidates by comparing permalinks.
	 *
	 * @param array  $candidates Posts sharing the URL's slug (possibly across post types).
	 * @param string $url        The original URL being resolved.
	 * @return int Resolved post ID, or 0 if no candidate's permalink matches.
	 */
	private function resolve_candidate_post_id( $candidates, $url ) {
		if ( empty( $candidates ) ) {
			return 0;
		}

		// Common case: slug is unique, no need to verify the permalink.
		if ( 1 === count( $candidates ) ) {
			return (int) $candidates[0]->ID;
		}

		// Multiple post types share this slug - match against the real permalink.
		$normalized_url = untrailingslashit( $url );
		foreach ( $candidates as $candidate ) {
			$permalink = get_permalink( $candidate->ID );
			if ( $permalink && untrailingslashit( $permalink ) === $normalized_url ) {
				return (int) $candidate->ID;
			}
		}

		return 0;
	}

	/**
	 * Get WordPress site options needed.
	 *
	 * @return array The atts.
	 */
	private function get_site_settings() {
		if ( empty( $this->site_settings ) ) {
			$this->site_settings = [
				'home_url'        => untrailingslashit( home_url() ),
				'homepage_static' => get_option( 'show_on_front' ) === 'page',
			];
		}

		return $this->site_settings;
	}
}
