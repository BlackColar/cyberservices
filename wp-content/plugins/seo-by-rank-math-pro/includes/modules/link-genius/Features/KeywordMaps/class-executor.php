<?php
/**
 * Keyword Map Executor - Executes keyword maps to add links to content.
 *
 * This class handles the execution of keyword maps, which involves:
 * 1. Finding posts that contain the keyword/variations (as plain text)
 * 2. Adding links to the target URL for matching text
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Keyword_Maps
 */

namespace RankMathPro\Link_Genius\Features\KeywordMaps;

use RankMathPro\Link_Genius\Features\KeywordMaps\Utils\Variation_Expander;
use RankMathPro\Link_Genius\Features\KeywordMaps\Utils\Content_Analyzer;
use RankMathPro\Link_Genius\Features\KeywordMaps\Utils\Link_Applier;

defined( 'ABSPATH' ) || exit;

/**
 * Executor class.
 *
 * Handles keyword map execution - finding text matches and adding links.
 */
class Executor {

	/**
	 * Maximum posts to process per execution.
	 *
	 * @var int
	 */
	const MAX_POSTS_PER_BATCH = 100;

	/**
	 * Storage instance.
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->storage = Storage::get();
	}

	/**
	 * Find posts containing any of the keywords.
	 *
	 * @param array  $variations   Array of keyword variations.
	 * @param object $keyword_map  Keyword map object.
	 * @return array Array of post objects.
	 */
	private function find_posts_with_keywords( $variations, $keyword_map ) {
		global $wpdb;

		// Build LIKE conditions for each variation against post_content.
		$like_conditions = [];
		foreach ( $variations as $variation ) {
			$like_conditions[] = $wpdb->prepare(
				'post_content LIKE %s',
				'%' . $wpdb->esc_like( $variation ) . '%'
			);
		}

		if ( empty( $like_conditions ) ) {
			return [];
		}

		$like_sql = implode( ' OR ', $like_conditions );

		// Build LIKE conditions for _elementor_data postmeta (Elementor stores
		// content as JSON in this meta key, not in post_content).
		$elementor_conditions = [];
		foreach ( $variations as $variation ) {
			$elementor_conditions[] = $wpdb->prepare(
				'pm.meta_value LIKE %s',
				'%' . $wpdb->esc_like( $variation ) . '%'
			);
		}
		$elementor_like_sql = implode( ' OR ', $elementor_conditions );

		// Get global settings once for both post types and exclusions.
		$global_settings = Keyword_Maps::get_settings();

		// Get supported post types (already excludes globally excluded types).
		$post_types = Keyword_Maps::get_supported_post_types( $global_settings );

		if ( empty( $post_types ) ) {
			return []; // No post types left after exclusion.
		}

		$post_types_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// Build query with alias 'p' for exclusion SQL.
		// LEFT JOIN with _elementor_data postmeta so Elementor posts are found even
		// when post_content is empty/placeholder. Both $like_sql and $elementor_like_sql
		// are already prepared via wpdb->prepare() above.
		$base_query = "SELECT DISTINCT p.ID, p.post_title, p.post_content, p.post_type
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_elementor_data'
			WHERE p.post_status = 'publish'
			AND p.post_type IN ($post_types_placeholders)
			AND (($like_sql) OR (pm.post_id IS NOT NULL AND ($elementor_like_sql)))";

		$query = $wpdb->prepare( $base_query, ...$post_types );

		// Add exclusion SQL for post IDs, terms, and meta.
		$exclusion_sql = $this->build_exclusion_sql( $global_settings );
		if ( ! empty( $exclusion_sql ) ) {
			$query .= $exclusion_sql;
		}

		$query .= ' LIMIT ' . self::MAX_POSTS_PER_BATCH;

		$posts = $wpdb->get_results( $query );

		if ( empty( $posts ) ) {
			return [];
		}

		// Filter out posts that match the target URL (prevent self-linking).
		// This is more efficient than url_to_postid() for each post.
		$target_normalized = Content_Analyzer::normalize_url( $keyword_map->target_url );

		$filtered_posts = array_filter(
			$posts,
			function ( $post ) use ( $target_normalized ) {
				return Content_Analyzer::normalize_url( get_permalink( $post->ID ) ) !== $target_normalized;
			}
		);

		return $filtered_posts ? array_values( $filtered_posts ) : [];
	}

	/**
	 * Build SQL exclusion clauses for auto-linking.
	 *
	 * @param array $global_settings Global settings array.
	 * @return string SQL exclusion clauses.
	 */
	private function build_exclusion_sql( $global_settings ) {
		global $wpdb;

		$exclusion_clauses = [];

		// 1. Exclude posts with rank_math_auto_linking_disabled meta.
		$exclusion_clauses[] = "NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} pm
			WHERE pm.post_id = p.ID
			AND pm.meta_key = 'rank_math_auto_linking_disabled'
			AND pm.meta_value = '1'
		)";

		// 2. Exclude specific post IDs from global settings.
		$excluded_post_ids = ! empty( $global_settings['excluded_post_ids'] ) ? $global_settings['excluded_post_ids'] : [];
		$excluded_post_ids = array_filter( array_map( 'absint', $excluded_post_ids ) );
		if ( ! empty( $excluded_post_ids ) ) {
			$placeholders        = implode( ', ', array_fill( 0, count( $excluded_post_ids ), '%d' ) );
			$exclusion_sql       = "p.ID NOT IN ($placeholders)";
			$exclusion_clauses[] = $wpdb->prepare( $exclusion_sql, ...$excluded_post_ids );
		}

		// 3. Exclude posts by term_taxonomy_id (categories/tags).
		$excluded_term_ids = ! empty( $global_settings['excluded_term_ids'] ) ? $global_settings['excluded_term_ids'] : [];
		$excluded_term_ids = array_filter( array_map( 'absint', $excluded_term_ids ) );
		if ( ! empty( $excluded_term_ids ) ) {
			$placeholders        = implode( ', ', array_fill( 0, count( $excluded_term_ids ), '%d' ) );
			$exclusion_sql       = "NOT EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} tr
				WHERE tr.object_id = p.ID
				AND tr.term_taxonomy_id IN ($placeholders)
			)";
			$exclusion_clauses[] = $wpdb->prepare( $exclusion_sql, ...$excluded_term_ids );
		}

		return empty( $exclusion_clauses ) ? '' : ' AND ' . implode( ' AND ', $exclusion_clauses );
	}

	/**
	 * Preview keyword map execution.
	 *
	 * Returns what changes would be made without actually making them.
	 *
	 * @param object $keyword_map Keyword map object.
	 * @return array|\WP_Error Preview result or error.
	 */
	public function preview( $keyword_map ) {
		// Get all variations including the base keyword.
		$variations = Variation_Expander::get_all_variations( $keyword_map );

		if ( empty( $variations ) ) {
			return new \WP_Error(
				'no_keywords',
				__( 'No keywords or variations defined for this keyword map.', 'rank-math-pro' )
			);
		}

		// Find posts with keywords.
		// Note: find_posts_with_keywords() already applies all exclusion logic via SQL:
		// - Supported post types (via filter)
		// - Excluded post types
		// - Excluded post IDs
		// - Excluded terms
		// - Post-level opt-out meta.
		$posts = $this->find_posts_with_keywords( $variations, $keyword_map );

		if ( empty( $posts ) ) {
			return [
				'success'        => true,
				'total_links'    => 0,
				'total_posts'    => 0,
				'sample_changes' => [],
				'warnings'       => [],
				'message'        => __( 'No posts found containing the specified keywords.', 'rank-math-pro' ),
			];
		}

		// Process all posts using optimized method.
		$all_changes = [];
		$total_links = 0;
		$link_id     = 1; // Sequential ID for preview changes.

		foreach ( $posts as $post ) {
			// Analyze content once per post.
			// Allow page-builder integrations to provide the effective content
			// (e.g. Elementor stores post content in _elementor_data, not post_content).
			$content = apply_filters( 'rank_math/link_genius/keyword_map_post_content', $post->post_content, $post->ID );

			$content_analysis = Content_Analyzer::analyze( $content );

			// Apply links (content is modified by reference, but we don't save it).
			$result = $this->apply_links_to_content(
				$content,
				$keyword_map,
				$variations,
				$post->ID,
				$content_analysis
			);

			if ( ! empty( $result['links_added'] ) ) {
				// Format changes for preview UI.
				foreach ( $result['changes'] as $change ) {
					$all_changes[] = [
						'link_id'    => $link_id++,
						'post_id'    => $post->ID,
						'post_title' => $post->post_title,
						'post_type'  => $post->post_type,
						'position'   => $change['position'],
						'before'     => $change['before'],
						'after'      => $change['after'],
						'original'   => $change['original'],
						'linked'     => $change['linked'],
					];
				}

				$total_links += $result['links_added'];
			}
		}

		// Count unique posts.
		$unique_posts = [];
		foreach ( $all_changes as $change ) {
			$unique_posts[ $change['post_id'] ] = true;
		}

		// If no link opportunities were found (e.g., all matches were already inside existing links, fell in unsafe ranges, or max_links_per_post was exhausted).
		if ( 0 === $total_links ) {
			return [
				'success'        => true,
				'total_links'    => 0,
				'total_posts'    => 0,
				'sample_changes' => [],
				'warnings'       => [],
				'message'        => __( 'No new link opportunities found. All keyword matches are already linked or in content that cannot be modified.', 'rank-math-pro' ),
			];
		}

		return [
			'success'        => true,
			'total_links'    => $total_links,
			'total_posts'    => count( $unique_posts ),
			'sample_changes' => $all_changes,
			'warnings'       => [],
			'message'        => sprintf(
				/* translators: 1: number of links, 2: number of posts */
				_n(
					'Found %1$d link opportunity in %2$d post.',
					'Found %1$d link opportunities in %2$d posts.',
					$total_links,
					'rank-math-pro'
				),
				$total_links,
				count( $unique_posts )
			),
		];
	}

	/**
	 * Apply links to content without database operations.
	 *
	 * This method modifies the content string directly and returns metadata.
	 * Used by Auto_Linker for batch processing where content is updated once.
	 *
	 * @param string $content         Post content (passed by reference, will be modified).
	 * @param object $keyword_map     Keyword map object.
	 * @param array  $variations      Pre-fetched variations.
	 * @param int    $post_id         Post ID.
	 * @param array  $content_analysis Pre-computed content analysis.
	 * @return array Result with 'links_added' and 'changes'.
	 */
	public function apply_links_to_content( &$content, $keyword_map, $variations, $post_id, $content_analysis ) {
		if ( empty( $variations ) ) {
			return [
				'links_added' => 0,
				'changes'     => [],
			];
		}

		// Store original content for context extraction.
		$original_content = $content;

		$target_url = $keyword_map->target_url;
		$max_links  = ! empty( $keyword_map->max_links_per_post ) ? (int) $keyword_map->max_links_per_post : 3;

		// Subtract links that already point to the target URL so the cap applies
		// to the total number of target-URL links in the post, not just new ones.
		$existing_target_links = Content_Analyzer::count_target_links( $content_analysis, $target_url, $variations );
		$max_links             = max( 0, $max_links - $existing_target_links );

		if ( 0 === $max_links ) {
			return [
				'links_added' => 0,
				'changes'     => [],
			];
		}

		// Find all matches in content.
		$case_sensitive = ! empty( $keyword_map->case_sensitive );
		$matches        = Variation_Expander::find_matches_in_content( $content, $variations, $case_sensitive, true );

		if ( empty( $matches ) ) {
			return [
				'links_added' => 0,
				'changes'     => [],
			];
		}

		// Filter out matches that are already inside links.
		$filtered = Content_Analyzer::filter_linked_matches( $matches, $content_analysis );

		if ( empty( $filtered ) ) {
			return [
				'links_added' => 0,
				'changes'     => [],
			];
		}

		// Apply links with context extraction enabled for preview generation.
		return Link_Applier::apply_links( $content, $filtered, $target_url, $max_links, $post_id, $original_content, true );
	}
}
