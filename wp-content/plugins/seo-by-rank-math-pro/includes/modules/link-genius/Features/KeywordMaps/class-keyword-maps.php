<?php
/**
 * Keyword Maps Module - Main orchestrator class.
 *
 * Initializes and coordinates all keyword maps components.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Keyword_Maps
 */

namespace RankMathPro\Link_Genius\Features\KeywordMaps;

use RankMath\Traits\Hooker;
use RankMath\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Keyword_Maps class.
 *
 * Module entry point that initializes REST API and auto-linker.
 */
class Keyword_Maps {
	use Hooker;

	/**
	 * Singleton instance.
	 *
	 * @var Keyword_Maps
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Keyword_Maps
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
		// Initialize auto-linker if enabled.
		if ( self::is_auto_linking_enabled() ) {
			Auto_Linker::get();
		}
	}

	/**
	 * Initialize REST API routes.
	 *
	 * Called from Link_Genius::init_rest_api().
	 */
	public static function init_rest_api() {
		new Rest();
	}

	/**
	 * Check if auto-linking is globally enabled.
	 *
	 * @return bool
	 */
	public static function is_auto_linking_enabled() {
		return apply_filters( 'rank_math/link_genius/auto_link_enabled', true );
	}

	/**
	 * Get keyword maps settings.
	 *
	 * @return array Settings array.
	 */
	public static function get_settings() {
		return [
			'auto_linking_enabled' => self::is_auto_linking_enabled(),
			'case_sensitive'       => (bool) Helper::get_settings( 'general.keyword_maps_case_sensitive', false ),
			'whole_word_only'      => (bool) Helper::get_settings( 'general.keyword_maps_whole_word_only', true ),
			'exclude_headings'     => (bool) Helper::get_settings( 'general.keyword_maps_exclude_headings', true ),
			'max_links_per_post'   => (int) Helper::get_settings( 'general.keyword_maps_max_links_per_post', 10 ),
			'excluded_post_types'  => (array) Helper::get_settings( 'general.keyword_maps_excluded_post_types', [] ),
			'excluded_post_ids'    => self::parse_excluded_post_ids( Helper::get_settings( 'general.keyword_maps_excluded_post_ids', '' ) ),
			'excluded_term_ids'    => self::get_excluded_term_ids(),
		];
	}

	/**
	 * Parse excluded post IDs from textarea input.
	 *
	 * @param string|array $input Post IDs as string or array.
	 * @return array Array of post IDs.
	 */
	private static function parse_excluded_post_ids( $input ) {
		if ( empty( $input ) ) {
			return [];
		}

		// Handle array input.
		if ( is_array( $input ) ) {
			return array_filter( array_map( 'absint', $input ) );
		}

		// Handle string input (comma-separated).
		$post_ids = array_map( 'trim', explode( ',', $input ) );
		return array_filter( array_map( 'absint', $post_ids ) );
	}

	/**
	 * Get excluded term IDs from all post type/taxonomy combinations.
	 *
	 * @return array Array of term taxonomy IDs.
	 */
	private static function get_excluded_term_ids() {
		$excluded_term_ids = [];

		// Get all public post types.
		$post_types = get_post_types( [ 'public' => true ], 'names' );

		foreach ( $post_types as $post_type ) {
			// Get excluded terms for this post type.
			$post_type_terms = Helper::get_settings( "general.keyword_maps_exclude_{$post_type}_terms", [] );

			if ( empty( $post_type_terms ) ) {
				continue;
			}

			// Handle nested array structure (terms may be in $post_type_terms[0]).
			if ( isset( $post_type_terms[0] ) && is_array( $post_type_terms[0] ) ) {
				$post_type_terms = $post_type_terms[0];
			}

			// Extract term IDs from all taxonomies for this post type.
			foreach ( $post_type_terms as $taxonomy => $term_ids ) {
				if ( is_array( $term_ids ) ) {
					$excluded_term_ids = array_merge( $excluded_term_ids, array_map( 'absint', $term_ids ) );
				}
			}
		}

		return array_filter( array_unique( $excluded_term_ids ) );
	}

	/**
	 * Get supported post types for keyword maps.
	 *
	 * This method:
	 * 1. Gets all accessible post types (public, viewable)
	 * 2. Applies 'rank_math/keyword_maps/supported_post_types' filter for customization
	 * 3. Removes globally excluded post types from settings
	 *
	 * This is the single source of truth for which post types support keyword maps.
	 * Used by Auto_Linker, Executor, and settings.
	 *
	 * @param array $global_settings Optional. Global settings array. If not provided, will be fetched.
	 * @return array Array of supported post type names.
	 */
	public static function get_supported_post_types( $global_settings ) {
		// Get all accessible post types (public and viewable).
		$post_types = Helper::get_accessible_post_types();

		/**
		 * Filter the post types that support keyword maps.
		 *
		 * By default, all accessible post types support keyword maps.
		 * Use this filter to restrict or expand the list.
		 *
		 * @param array $post_types Array of post type names.
		 */
		$post_types = apply_filters( 'rank_math/keyword_maps/supported_post_types', $post_types );
		if ( isset( $post_types['attachment'] ) ) {
			unset( $post_types['attachment'] );
		}

		// Subtract globally excluded post types.
		$excluded_post_types = ! empty( $global_settings['excluded_post_types'] ) ? $global_settings['excluded_post_types'] : [];
		$post_types          = array_diff( $post_types, $excluded_post_types );

		return array_values( $post_types );
	}
}
