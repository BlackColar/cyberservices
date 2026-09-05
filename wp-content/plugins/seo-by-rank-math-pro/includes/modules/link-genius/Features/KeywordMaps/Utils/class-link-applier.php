<?php
/**
 * Link Applier for Keyword Maps.
 *
 * Provides shared link application functionality used across Auto-Linker,
 * Preview, and Execution flows.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Keyword_Maps\Utils
 */

namespace RankMathPro\Link_Genius\Features\KeywordMaps\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Link_Applier class.
 *
 * Single responsibility: Apply links to content and manage link tracking.
 */
class Link_Applier {

	/**
	 * Apply links to content string.
	 *
	 * @param string $content         Content to modify (by reference).
	 * @param array  $matches         Matches to link (filtered, not inside existing links).
	 * @param string $target_url      Target URL.
	 * @param int    $max_links       Maximum links to add.
	 * @param int    $post_id         Post ID (for preview context).
	 * @param string $original_content Original content before any modifications (for context extraction).
	 * @param bool   $include_context Whether to include context in change details (default: false).
	 * @return array Result with 'links_added' and 'changes'.
	 */
	public static function apply_links( &$content, $matches, $target_url, $max_links = 3, $post_id = 0, $original_content = null, $include_context = false ) {
		if ( empty( $matches ) ) {
			return [
				'links_added' => 0,
				'changes'     => [],
			];
		}

		// Sort matches by position (ascending) to process from beginning to end.
		usort(
			$matches,
			function ( $a, $b ) {
				return $a['position'] - $b['position'];
			}
		);

		// Only process up to max_links matches.
		$matches_to_process = array_slice( $matches, 0, $max_links );

		// Reverse to process from end to beginning for substr_replace.
		// This prevents position shifting when modifying the string.
		$matches_to_process = array_reverse( $matches_to_process );

		// Use original content for context extraction if not provided.
		if ( null === $original_content ) {
			$original_content = $content;
		}

		// Replace matches with links.
		$links_added    = 0;
		$change_details = [];

		foreach ( $matches_to_process as $match ) {
			$text        = $match['text'];
			$position    = $match['position'];
			$link        = sprintf( '<a href="%s">%s</a>', esc_url( $target_url ), esc_html( $text ) );
			$link_length = strlen( $link );

			// Replace at position.
			$content = substr_replace( $content, $link, $position, strlen( $text ) );
			++$links_added;

			// Build change details.
			$change_detail = [
				'post_id'     => $post_id,
				'link_id'     => 0, // Will be set by caller if needed.
				'position'    => $position, // Original position before replacement.
				'link_length' => $link_length, // Length of inserted link (for incremental updates).
				'target_url'  => $target_url, // Target URL (for incremental updates).
				'before'      => [
					'text'        => $text,
					'anchor_text' => $text,
					'url'         => null,
				],
				'after'       => [
					'text'        => $link,
					'anchor_text' => $text,
					'url'         => $target_url,
				],
				'original'    => $text,
				'linked'      => $link,
			];

			// Extract context only if needed (for preview generation).
			if ( $include_context ) {
				$context                            = Content_Analyzer::extract_context( $original_content, $position, $text );
				$change_detail['before']['context'] = $context;
				$change_detail['after']['context']  = [
					'before'  => $context['before'],
					'match'   => $link,
					'after'   => $context['after'],
					'preview' => $context['before'] . $link . $context['after'],
				];
			}

			$change_details[] = $change_detail;
		}

		return [
			'links_added' => $links_added,
			'changes'     => $change_details,
		];
	}
}
