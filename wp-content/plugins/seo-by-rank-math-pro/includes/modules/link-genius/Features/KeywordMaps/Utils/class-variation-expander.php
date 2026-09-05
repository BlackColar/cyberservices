<?php
/**
 * Variation Expander - Expands keyword variations into search filters.
 *
 * Utility class that expands keyword map variations into bulk update search filters.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Keyword_Maps\Utils
 */

namespace RankMathPro\Link_Genius\Features\KeywordMaps\Utils;

use RankMathPro\Link_Genius\Features\KeywordMaps\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Variation_Expander class.
 *
 * Expands keyword variations into search filters for bulk update execution.
 */
class Variation_Expander {

	/**
	 * Expand keyword map variations into search filters.
	 *
	 * Takes a keyword map object and returns an array of all variations
	 * including the base keyword for OR-based matching.
	 *
	 * @param object $keyword_map Keyword map object from database.
	 * @return array Array with 'anchor_text_variations' containing all variations.
	 */
	public static function expand_variations( $keyword_map ) {
		$storage    = Storage::get();
		$variations = $storage->get_variations( $keyword_map->id );

		// Build combined variation list.
		$variation_texts = [];
		foreach ( $variations as $variation ) {
			$variation_texts[] = $variation->variation;
		}

		// Add base keyword (name field).
		if ( ! empty( $keyword_map->name ) ) {
			$variation_texts[] = $keyword_map->name;
		}

		// Remove duplicates.
		$variation_texts = array_unique( $variation_texts );

		// Return array with anchor_text_variations for OR-based matching.
		return [
			'anchor_text_variations' => array_values( $variation_texts ),
		];
	}

	/**
	 * Build regex pattern for variation matching.
	 *
	 * Creates a regex pattern that matches any of the variations.
	 * Useful for content scanning.
	 *
	 * @param array $variations Array of variation strings.
	 * @param bool  $case_sensitive Whether matching should be case-sensitive.
	 * @param bool  $whole_word Whether to match whole words only.
	 * @return string Regex pattern.
	 */
	public static function build_variation_pattern( $variations, $case_sensitive = false, $whole_word = true ) {
		if ( empty( $variations ) ) {
			return '';
		}

		// Escape special regex characters.
		$escaped_variations = array_map( 'preg_quote', $variations );

		// Build pattern.
		$pattern = implode( '|', $escaped_variations );

		// Add word boundaries if whole word matching.
		if ( $whole_word ) {
			$pattern = '\b(' . $pattern . ')\b';
		} else {
			$pattern = '(' . $pattern . ')';
		}

		// Add delimiters and case-insensitive flag if needed.
		$flags   = $case_sensitive ? '' : 'i';
		$pattern = '/' . $pattern . '/' . $flags . 'u';

		return $pattern;
	}

	/**
	 * Find all variation matches in content.
	 *
	 * Scans content for all keyword variations and returns matches
	 * with their positions and matched text.
	 *
	 * @param string $content       Content to scan.
	 * @param array  $variations    Array of variation strings.
	 * @param bool   $case_sensitive Whether matching should be case-sensitive.
	 * @param bool   $whole_word    Whether to match whole words only.
	 * @return array Array of matches with position and text.
	 */
	public static function find_matches_in_content( $content, $variations, $case_sensitive = false, $whole_word = true ) {
		if ( empty( $content ) || empty( $variations ) ) {
			return [];
		}

		$pattern = self::build_variation_pattern( $variations, $case_sensitive, $whole_word );
		if ( empty( $pattern ) ) {
			return [];
		}

		$matches = [];
		if ( preg_match_all( $pattern, $content, $found, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $found[0] as $match ) {
				$matches[] = [
					'text'     => $match[0],
					'position' => $match[1],
				];
			}
		}

		return $matches;
	}

	/**
	 * Check if content contains any variations.
	 *
	 * Quick check to see if content contains any keyword variations.
	 *
	 * @param string $content       Content to scan.
	 * @param array  $variations    Array of variation strings.
	 * @param bool   $case_sensitive Whether matching should be case-sensitive.
	 * @return bool True if content contains any variation.
	 */
	public static function content_contains_variation( $content, $variations, $case_sensitive = false ) {
		if ( empty( $content ) || empty( $variations ) ) {
			return false;
		}

		foreach ( $variations as $variation ) {
			if ( $case_sensitive ) {
				if ( strpos( $content, $variation ) !== false ) {
					return true;
				}
			} elseif ( stripos( $content, $variation ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get all variations for a keyword map including the base keyword.
	 *
	 * @param object $keyword_map Keyword map object.
	 * @return array Array of all variation strings.
	 */
	public static function get_all_variations( $keyword_map ) {
		$storage    = Storage::get();
		$variations = $storage->get_variations( $keyword_map->id );

		$all_variations = [];
		foreach ( $variations as $variation ) {
			$all_variations[] = $variation->variation;
		}

		// Add base keyword (name field).
		if ( ! empty( $keyword_map->name ) && ! in_array( $keyword_map->name, $all_variations, true ) ) {
			$all_variations[] = $keyword_map->name;
		}

		return array_unique( $all_variations );
	}
}
