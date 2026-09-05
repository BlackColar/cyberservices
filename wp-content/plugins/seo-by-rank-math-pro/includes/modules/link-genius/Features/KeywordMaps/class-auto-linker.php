<?php
/**
 * Auto Linker - Automatic link application on post publish.
 *
 * Hooks into save_post to automatically apply enabled keyword maps
 * to newly published or updated posts.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Keyword_Maps
 */

namespace RankMathPro\Link_Genius\Features\KeywordMaps;

use RankMath\Helper;
use RankMath\Traits\Hooker;
use RankMathPro\Link_Genius\Services\Utils;
use RankMathPro\Link_Genius\Services\Batch_Helper;
use RankMathPro\Link_Genius\Features\KeywordMaps\Utils\Content_Analyzer;

defined( 'ABSPATH' ) || exit;

/**
 * Auto_Linker class.
 *
 * Handles automatic application of keyword maps on post publish.
 */
class Auto_Linker {
	use Hooker;

	/**
	 * Singleton instance.
	 *
	 * @var Auto_Linker
	 */
	private static $instance = null;

	/**
	 * Storage instance.
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Global settings cache.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Get singleton instance.
	 *
	 * @return Auto_Linker
	 */
	public static function get() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->storage  = Storage::get();
		$this->settings = Keyword_Maps::get_settings();

		$this->action( 'save_post', 'handle_save_post', 999, 2 );

		// Handle async auto-linking.
		$this->action( 'rank_math_keyword_maps_auto_link', 'process_auto_link' );
	}

	/**
	 * Handle save_post action.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function handle_save_post( $post_id, $post ) {
		if ( ! $this->should_process_post( $post_id, $post ) ) {
			return;
		}

		// Set a transient to prevent duplicate processing.
		set_transient( 'rank_math_auto_linking_' . $post_id, 1, 60 );

		// Schedule async processing (non-blocking).
		// Pass only post_id to avoid serialization issues with WP_Post object.
		wp_schedule_single_event( time() + 10, 'rank_math_keyword_maps_auto_link', [ $post_id ] );
	}

	/**
	 * Process auto-linking for a post.
	 *
	 * - Fetches all variations in a single query
	 * - Performs a single content scan to check for matches
	 * - Applies all matched maps in one pass
	 * - Updates the post content only once
	 *
	 * @param int $post_id Post ID.
	 */
	public function process_auto_link( $post_id ) {
		// Clear the transient.
		delete_transient( 'rank_math_auto_linking_' . $post_id );

		// Get fresh post object (important for cron context).
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		// Get all enabled keyword maps with auto-linking enabled.
		$keyword_maps = $this->storage->get_keyword_maps(
			[
				'enabled'           => true,
				'auto_link_enabled' => true,
				'per_page'          => 0, // No limit.
			]
		);

		if ( empty( $keyword_maps ) ) {
			return;
		}

		// Filter out maps that target the current post (prevent self-linking).
		$current_permalink = get_permalink( $post_id );
		$keyword_maps      = array_filter(
			$keyword_maps,
			function ( $map ) use ( $current_permalink ) {
				return Content_Analyzer::normalize_url( $map->target_url ) !== Content_Analyzer::normalize_url( $current_permalink );
			}
		);

		if ( empty( $keyword_maps ) ) {
			return;
		}

		// Extract map IDs for batch fetching.
		$map_ids = array_map(
			function ( $map ) {
				return $map->id;
			},
			$keyword_maps
		);

		// Batch fetch all variations in a single query.
		$variations_batch = $this->storage->get_variations_batch( $map_ids );

		// Smart single-pass: Build variations and match content in one loop.
		// This combines 3 operations (build mapping, scan content, filter maps) into 1 pass.
		// Early exit per map: If no variations match, discard the map immediately.
		$matched_maps   = [];
		$map_variations = [];
		$content        = $post->post_content;

		foreach ( $keyword_maps as $map ) {
			// Get variations for this map.
			$variations = array_column(
				$variations_batch[ $map->id ] ?? [],
				'variation'
			);

			// Add base keyword (name field).
			if ( ! empty( $map->name ) && ! in_array( $map->name, $variations, true ) ) {
				$variations[] = $map->name;
			}

			$variations = array_unique( $variations );
			if ( empty( $variations ) ) {
				continue;
			}

			// Check if ANY variation in this map matches the content.
			$case_sensitive = ! empty( $map->case_sensitive );
			$has_match      = false;
			foreach ( $variations as $variation ) {
				$found = $case_sensitive ? strpos( $content, $variation ) : stripos( $content, $variation );
				if ( false !== $found ) {
					$has_match = true;
					break; // Found a match, no need to check other variations.
				}
			}

			// Only keep maps that matched (early exit per map).
			if ( $has_match ) {
				$matched_maps[]             = $map;
				$map_variations[ $map->id ] = $variations;
			}
		}

		if ( empty( $matched_maps ) ) {
			return;
		}

		// Execute all matched maps in a single pass.
		$this->execute_maps_for_post( $post, $matched_maps, $map_variations );
	}

	/**
	 * Execute keyword maps for a specific post.
	 *
	 * Applies all maps in a single pass:
	 * - Pre-computes content analysis once
	 * - Applies all keyword map changes to content string
	 * - Updates post content only once using direct SQL (no hooks)
	 * - Manually updates link tracking database
	 * - Batch updates execution stats
	 *
	 * @param \WP_Post $post           Post object.
	 * @param array    $keyword_maps   Array of keyword map objects.
	 * @param array    $map_variations Pre-fetched variations indexed by map ID.
	 */
	private function execute_maps_for_post( $post, $keyword_maps, $map_variations ) {
		$executor         = new Executor();
		$content          = $post->post_content;
		$original_content = $content;
		$total_links      = 0;
		$executed_map_ids = [];

		// Analyze content once for all maps.
		$content_analysis = Content_Analyzer::analyze( $content );

		// Apply each keyword map to the content.
		foreach ( $keyword_maps as $map ) {
			$variations = isset( $map_variations[ $map->id ] ) ? $map_variations[ $map->id ] : [];

			if ( empty( $variations ) ) {
				continue;
			}

			// Apply links to content (modifies $content by reference).
			$result = $executor->apply_links_to_content(
				$content,
				$map,
				$variations,
				$post->ID,
				$content_analysis
			);

			if ( ! empty( $result['links_added'] ) ) {
				$total_links       += $result['links_added'];
				$executed_map_ids[] = $map->id;

				// Update content analysis incrementally instead of full re-analysis.
				if ( ! empty( $result['changes'] ) ) {
					foreach ( $result['changes'] as $change ) {
						$content_analysis = Content_Analyzer::update_after_link_insertion(
							$content_analysis,
							$change['position'],
							$change['link_length'],
							$change['target_url']
						);
					}
				}
			}
		}

		// Single database write: Update post content only if changed.
		if ( $content !== $original_content ) {
			// Use Batch_Helper for direct SQL update.
			Batch_Helper::update_post_content( [ $post->ID => $content ] );
		}

		// Batch update execution stats for all executed maps.
		if ( ! empty( $executed_map_ids ) ) {
			$this->storage->update_execution_stats_batch( $executed_map_ids );
		}

		// Show notification for auto-links added.
		if ( $total_links > 0 ) {
			$post_title = $post->post_title ? $post->post_title : __( '(no title)', 'rank-math-pro' );

			$edit_link = admin_url( sprintf( 'post.php?post=%d&action=edit', $post->ID ) );

			$message = sprintf(
				/* translators: 1: number of links, 2: post title with edit link */
				_n(
					'%1$d auto-link added by Link Genius to %2$s.',
					'%1$d auto-links added by Link Genius to %2$s.',
					$total_links,
					'rank-math-pro'
				),
				$total_links,
				'<a href="' . esc_url( $edit_link ) . '">' . esc_html( $post_title ) . '</a>'
			);

			Helper::add_notification(
				$message,
				[
					'type'         => 'success',
					'id'           => 'rank_math_auto_link_added_' . $post->ID,
					'capabilities' => 'edit_posts',
				]
			);
		}
	}

	/**
	 * Check if a post should be processed for keyword map application.
	 *
	 * This centralized method performs all common exclusion checks:
	 * - Post type supported (via filter)
	 * - Post type not globally excluded
	 * - Post ID not globally excluded
	 * - Post not in excluded terms
	 * - Post-level opt-out meta (rank_math_auto_linking_disabled)
	 *
	 * Used by Auto_Linker, Executor, and Preview methods to ensure
	 * consistent exclusion logic across all contexts.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 *
	 * @return bool True if post should be processed, false otherwise.
	 */
	private function should_process_post( $post_id, $post ) {
		if (
			wp_is_post_revision( $post_id ) ||
			wp_is_post_autosave( $post_id ) ||
			'publish' !== $post->post_status
		) {
			return;
		}

		// Only run when user manually saves from post edit screen.
		// This prevents performance issues during bulk operations (imports, Quick Edit, Bulk Edit, etc.).
		if ( ! Utils::is_manual_post_save() ) {
			return;
		}

		// Check if we're already processing this post (prevent infinite loops).
		if ( get_transient( 'rank_math_auto_linking_' . $post_id ) ) {
			return;
		}

		// Bail early if no keyword maps exist with auto-linking enabled.
		if ( ! $this->storage->count_keyword_maps(
			[
				'enabled'           => true,
				'auto_link_enabled' => true,
			]
		) ) {
			return false;
		}

		// Check if post type is supported (with exclusions applied).
		$supported_post_types = Keyword_Maps::get_supported_post_types( $this->settings );
		if ( ! in_array( $post->post_type, $supported_post_types, true ) ) {
			return false;
		}

		// Check if post ID is globally excluded.
		if ( in_array( $post_id, array_map( 'absint', $this->settings['excluded_post_ids'] ), true ) ) {
			return false;
		}

		// Check post-level opt-out meta.
		if ( get_post_meta( $post_id, 'rank_math_auto_linking_disabled', true ) ) {
			return false;
		}

		// Check if post belongs to excluded terms.
		if ( $this->post_in_excluded_terms( $post_id, $post->post_type, $this->settings['excluded_term_ids'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a post belongs to any excluded terms.
	 *
	 * @param int    $post_id           Post ID.
	 * @param string $post_type         Post type.
	 * @param array  $excluded_term_ids Array of term taxonomy IDs.
	 * @return bool True if post is in any excluded term.
	 */
	private function post_in_excluded_terms( $post_id, $post_type, $excluded_term_ids ) {
		if ( empty( $excluded_term_ids ) ) {
			return false;
		}

		$excluded_term_ids = array_filter( array_map( 'absint', $excluded_term_ids ) );
		if ( empty( $excluded_term_ids ) ) {
			return false;
		}

		// Get all term taxonomy IDs for this post across all taxonomies.
		$post_terms = wp_get_object_terms(
			$post_id,
			get_object_taxonomies( $post_type ),
			[ 'fields' => 'tt_ids' ] // Get term_taxonomy_id for efficiency.
		);

		if ( is_wp_error( $post_terms ) || empty( $post_terms ) ) {
			return false;
		}

		// Check for intersection.
		return ! empty( array_intersect( $post_terms, $excluded_term_ids ) );
	}
}
