<?php
/**
 * Content Analyzer for Keyword Maps.
 *
 * Provides shared content analysis functionality used across Auto-Linker,
 * Preview, and Execution flows.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Keyword_Maps\Utils
 */

namespace RankMathPro\Link_Genius\Features\KeywordMaps\Utils;

use RankMath\Helpers\Str;

defined( 'ABSPATH' ) || exit;

/**
 * Content_Analyzer class.
 *
 * Single responsibility: Analyze content to extract link information.
 */
class Content_Analyzer {
	/**
	 * Analyze content once to extract link information and unsafe ranges.
	 *
	 * @param string $content Post content.
	 * @return array Analysis results with 'link_ranges', 'existing_hrefs', and 'unsafe_ranges'.
	 */
	public static function analyze( $content ) {
		$link_ranges    = [];
		$existing_hrefs = [];
		$href_counts    = [];

		// Single regex scan to get all links with their positions, hrefs, and anchor text.
		if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $index => $match ) {
				$link_ranges[]              = [
					'start'       => $match[1],
					'end'         => $match[1] + strlen( $match[0] ),
					'href'        => self::normalize_url( $matches[1][ $index ][0] ),
					'anchor_text' => strtolower( trim( wp_strip_all_tags( $matches[2][ $index ][0] ) ) ),
				];
				$normalized                 = self::normalize_url( $matches[1][ $index ][0] );
				$existing_hrefs[]           = $normalized;
				$href_counts[ $normalized ] = ( $href_counts[ $normalized ] ?? 0 ) + 1;
			}
		}

		// Extract unsafe ranges (blocks, shortcodes, HTML comments, etc.).
		$unsafe_ranges = self::extract_unsafe_ranges( $content );

		return [
			'link_ranges'    => $link_ranges,
			'existing_hrefs' => array_unique( $existing_hrefs ),
			'href_counts'    => $href_counts,
			'unsafe_ranges'  => $unsafe_ranges,
		];
	}

	/**
	 * Update analysis after a link has been inserted.
	 *
	 * This is much faster than re-analyzing the entire content because it only
	 * shifts positions of ranges that come after the insertion point.
	 *
	 * @param array  $content_analysis Previous analysis result.
	 * @param int    $insert_position  Position where link was inserted.
	 * @param int    $insert_length    Length of the inserted link HTML.
	 * @param string $target_url       URL of the inserted link (for tracking).
	 * @return array Updated analysis with shifted positions.
	 */
	public static function update_after_link_insertion( $content_analysis, $insert_position, $insert_length, $target_url ) {
		// Add new link to link_ranges.
		$content_analysis['link_ranges'][] = [
			'start' => $insert_position,
			'end'   => $insert_position + $insert_length,
		];

		// Add URL to existing_hrefs.
		$normalized_url = self::normalize_url( $target_url );
		if ( ! in_array( $normalized_url, $content_analysis['existing_hrefs'], true ) ) {
			$content_analysis['existing_hrefs'][] = $normalized_url;
		}

		// Shift all ranges that come after the insertion point.
		$shift_amount = $insert_length;

		// Shift link_ranges.
		foreach ( $content_analysis['link_ranges'] as &$range ) {
			if ( $range['start'] > $insert_position ) {
				$range['start'] += $shift_amount;
				$range['end']   += $shift_amount;
			}
		}

		// Shift unsafe_ranges.
		foreach ( $content_analysis['unsafe_ranges'] as &$range ) {
			if ( $range['start'] > $insert_position ) {
				$range['start'] += $shift_amount;
				$range['end']   += $shift_amount;
			}
		}

		return $content_analysis;
	}

	/**
	 * Check if content contains a link to the target URL.
	 *
	 * @param array  $content_analysis Pre-computed analysis.
	 * @param string $target_url       Target URL to check.
	 * @return bool True if link exists.
	 */
	public static function has_target_link( $content_analysis, $target_url ) {
		$normalized = self::normalize_url( $target_url );
		return in_array( $normalized, $content_analysis['existing_hrefs'], true );
	}

	/**
	 * Count existing links that point to the target URL with a matching keyword variation as anchor text.
	 *
	 * @param array  $content_analysis Pre-computed analysis.
	 * @param string $target_url       Target URL to match.
	 * @param array  $variations       Keyword variations to match against anchor text.
	 * @return int Number of existing keyword-matched links to the target URL.
	 */
	public static function count_target_links( $content_analysis, $target_url, $variations ) {
		$normalized_url      = self::normalize_url( $target_url );
		$normalized_variants = array_map( 'strtolower', $variations );
		$count               = 0;

		foreach ( $content_analysis['link_ranges'] as $range ) {
			if ( isset( $range['href'] ) && $range['href'] === $normalized_url
				&& isset( $range['anchor_text'] ) && in_array( $range['anchor_text'], $normalized_variants, true )
			) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Filter matches that are inside existing links or unsafe contexts.
	 *
	 * @param array $matches          Matches from find_matches_in_content().
	 * @param array $content_analysis Pre-computed analysis.
	 * @return array Filtered matches (only those safe to link).
	 */
	public static function filter_linked_matches( $matches, $content_analysis ) {
		$filtered = [];

		foreach ( $matches as $match ) {
			$pos = $match['position'];
			$end = $pos + strlen( $match['text'] );

			// Check if inside existing link.
			$inside_link = false;
			foreach ( $content_analysis['link_ranges'] as $range ) {
				if ( $pos >= $range['start'] && $end <= $range['end'] ) {
					$inside_link = true;
					break;
				}
			}

			if ( $inside_link ) {
				continue;
			}

			// Check if inside unsafe range (blocks, shortcodes, HTML tags, etc.).
			$in_unsafe_range = false;
			if ( ! empty( $content_analysis['unsafe_ranges'] ) ) {
				foreach ( $content_analysis['unsafe_ranges'] as $range ) {
					// Check if the match overlaps with unsafe range.
					// A match is unsafe if any part of it is within an unsafe range.
					if ( $pos < $range['end'] && $end > $range['start'] ) {
						$in_unsafe_range = true;
						break;
					}
				}
			}

			if ( ! $in_unsafe_range ) {
				$filtered[] = $match;
			}
		}

		return $filtered;
	}

	/**
	 * Extract context around a match position.
	 *
	 * Returns surrounding text with the matched text for preview purposes.
	 * Supports both character-based and sentence-based extraction.
	 *
	 * @param string $content  Full content.
	 * @param int    $position Position of the match.
	 * @param string $text     Matched text.
	 * @param array  $options  {
	 *     Optional. Extraction options.
	 *
	 *     @type string $mode      'chars' or 'sentence'. Default 'chars'.
	 *     @type int    $chars     Number of characters before/after (for chars mode). Default 50.
	 *     @type int    $max_words Maximum words to include (for sentence mode). Default 50.
	 * }
	 * @return array Context with 'before', 'match', 'after', and 'preview' keys.
	 */
	public static function extract_context( $content, $position, $text, $options = [] ) {
		$defaults = [
			'mode'      => 'chars',
			'chars'     => 50,
			'max_words' => 50,
		];
		$options  = array_merge( $defaults, $options );

		if ( 'sentence' === $options['mode'] ) {
			return self::extract_sentence_context( $content, $position, $text, $options['max_words'] );
		}

		return self::extract_char_context( $content, $position, $text, $options['chars'] );
	}

	/**
	 * Normalize URL for comparison.
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized URL.
	 */
	public static function normalize_url( $url ) {
		// Remove protocol.
		$url = preg_replace( '#^https?://#i', '', $url );

		// Remove www.
		$url = preg_replace( '#^www\.#i', '', $url );

		// Remove trailing slash.
		$url = rtrim( $url, '/' );

		// Convert to lowercase.
		return strtolower( $url );
	}

	/**
	 * Extract context using character-based extraction.
	 *
	 * @param string $content  Full content.
	 * @param int    $position Position of the match.
	 * @param string $text     Matched text.
	 * @param int    $chars    Number of characters to show before and after.
	 * @return array Context with 'before', 'match', 'after', and 'preview' keys.
	 */
	private static function extract_char_context( $content, $position, $text, $chars ) {
		$content_length = strlen( $content );
		$text_length    = strlen( $text );
		$match_end      = $position + $text_length;

		// Calculate start and end positions for context.
		$context_start = max( 0, $position - $chars );
		$context_end   = min( $content_length, $match_end + $chars );

		// Extract text segments.
		$before = Str::substr( $content, $context_start, $position - $context_start );
		$after  = Str::substr( $content, $match_end, $context_end - $match_end );

		// Clean up context (remove HTML tags, normalize whitespace).
		$before = self::clean_context( $before );
		$after  = self::clean_context( $after );

		// Trim to word boundaries for better readability.
		$before = self::trim_to_word_boundary( $before, false );
		$after  = self::trim_to_word_boundary( $after, true );

		// Ensure spacing around the match text.
		$before = rtrim( $before ) . ' ';
		$after  = ' ' . ltrim( $after );

		// Build preview with ellipsis if needed.
		$prefix  = $context_start > 0 ? '...' : '';
		$suffix  = $context_end < $content_length ? '...' : '';
		$preview = $prefix . $before . $text . $after . $suffix;

		return [
			'before'  => trim( $before ),
			'match'   => $text,
			'after'   => trim( $after ),
			'preview' => $preview,
		];
	}

	/**
	 * Extract unsafe ranges where links should not be added.
	 *
	 * Uses a two-path strategy:
	 * - Block Editor: Uses parse_blocks() to analyze structured block content
	 * - Classic Editor: Scans entire HTML content for unsafe patterns
	 *
	 * This includes:
	 * - WordPress blocks (entire block or just comment markers, depending on block type)
	 * - HTML comments (<!-- ... -->)
	 * - Shortcodes ([shortcode])
	 * - HTML tags and attributes (<tag attr="value">)
	 * - Script and style tags
	 *
	 * @param string $content Post content.
	 * @return array Array of unsafe ranges with 'start' and 'end' positions.
	 */
	private static function extract_unsafe_ranges( $content ) {
		$unsafe_ranges = [];

		/**
		 * Filter the list of block types where links can be added.
		 *
		 * By default, only paragraph and heading blocks allow link insertion.
		 * Use this filter to add more block types where links should be allowed.
		 */
		$safe_block_types = apply_filters(
			'rank_math/link_genius/keyword_maps/safe_block_types',
			[
				'core/paragraph',
				'core/heading',
			]
		);

		// Detect if content uses blocks (Block Editor) or plain HTML (Classic Editor).
		if ( has_blocks( $content ) ) {
			// PATH 1: Block Editor - use parse_blocks() for structured analysis.
			$unsafe_ranges = self::extract_unsafe_ranges_from_blocks( $content, $safe_block_types );
		} else {
			// PATH 2: Classic Editor / Page Builder - scan entire content.
			$unsafe_ranges = self::extract_unsafe_ranges_from_html( $content );
		}

		// Sort by start position for efficient filtering later.
		usort(
			$unsafe_ranges,
			function ( $a, $b ) {
				return $a['start'] - $b['start'];
			}
		);

		return $unsafe_ranges;
	}

	/**
	 * Extract context using sentence-based extraction.
	 *
	 * Finds the complete sentence(s) containing the match.
	 *
	 * @param string $content   Full content.
	 * @param int    $position  Position of the match.
	 * @param string $text      Matched text.
	 * @param int    $max_words Maximum words to include.
	 * @return array Context with 'before', 'match', 'after', and 'preview' keys.
	 */
	private static function extract_sentence_context( $content, $position, $text, $max_words ) {
		$text_length = strlen( $text );
		$match_end   = $position + $text_length;

		// Find sentence boundaries (period, exclamation, question mark followed by space or end).
		// Look backwards from match position.
		$before_text    = Str::substr( $content, 0, $position );
		$sentence_start = max(
			strrpos( $before_text, '. ' ),
			strrpos( $before_text, '! ' ),
			strrpos( $before_text, '? ' ),
			0
		);

		if ( $sentence_start > 0 ) {
			$sentence_start += 2; // Skip the punctuation and space.
		}

		// Look forward from match end.
		$after_text   = Str::substr( $content, $match_end );
		$sentence_end = strlen( $content );
		$pos_period   = strpos( $after_text, '. ' );
		$pos_exclaim  = strpos( $after_text, '! ' );
		$pos_question = strpos( $after_text, '? ' );
		$next_punct   = min(
			false !== $pos_period ? $pos_period : PHP_INT_MAX,
			false !== $pos_exclaim ? $pos_exclaim : PHP_INT_MAX,
			false !== $pos_question ? $pos_question : PHP_INT_MAX
		);

		if ( $next_punct < PHP_INT_MAX ) {
			$sentence_end = $match_end + $next_punct + 1; // Include the punctuation.
		}

		// Extract the full sentence.
		$full_sentence = Str::substr( $content, $sentence_start, $sentence_end - $sentence_start );

		// Clean context.
		$cleaned = self::clean_context( $full_sentence );

		// Split into before/match/after based on the position within the sentence.
		$before_length = $position - $sentence_start;
		$before        = self::clean_context( Str::substr( $full_sentence, 0, $before_length ) );
		$after         = self::clean_context( Str::substr( $full_sentence, $before_length + $text_length ) );

		// Trim to max words if needed.
		$preview = wp_trim_words( $cleaned, $max_words, '...' );

		return [
			'before'  => trim( $before ),
			'match'   => $text,
			'after'   => trim( $after ),
			'preview' => $preview,
		];
	}

	/**
	 * Clean context text by removing HTML tags and normalizing whitespace.
	 *
	 * @param string $text Text to clean.
	 * @return string Cleaned text.
	 */
	private static function clean_context( $text ) {
		// Remove HTML comments first (<!-- ... -->).
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );

		// Strip HTML tags.
		$text = wp_strip_all_tags( $text );

		// Normalize whitespace (convert multiple spaces/newlines to single space).
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( $text );
	}

	/**
	 * Trim text to nearest word boundary.
	 *
	 * @param string $text       Text to trim.
	 * @param bool   $trim_start True to trim from start, false to trim from end.
	 * @return string Trimmed text.
	 */
	private static function trim_to_word_boundary( $text, $trim_start ) {
		if ( empty( $text ) ) {
			return $text;
		}

		if ( $trim_start ) {
			// Find first space and trim everything before it.
			$space_pos = strpos( $text, ' ' );
			if ( false !== $space_pos && $space_pos > 0 ) {
				$text = Str::substr( $text, $space_pos + 1 );
			}
		} else {
			// Find last space and trim everything after it.
			$space_pos = strrpos( $text, ' ' );
			if ( false !== $space_pos && $space_pos < strlen( $text ) - 1 ) {
				$text = Str::substr( $text, 0, $space_pos );
			}
		}

		return trim( $text );
	}

	/**
	 * Extract unsafe ranges for Block Editor content using parse_blocks().
	 *
	 * Uses WordPress native parse_blocks() to get structured block data,
	 * then recursively processes each block to determine safe/unsafe ranges.
	 *
	 * @param string $content          Post content with blocks.
	 * @param array  $safe_block_types Array of safe block type names.
	 * @return array Array of unsafe ranges.
	 */
	private static function extract_unsafe_ranges_from_blocks( $content, $safe_block_types ) {
		$unsafe_ranges = [];
		$blocks        = parse_blocks( $content );
		$search_from   = 0;

		// Recursively process top-level blocks; use block_end to advance the cursor.
		foreach ( $blocks as $block ) {
			$result        = self::process_block_recursively(
				$block,
				$content,
				$safe_block_types,
				false,        // parent_is_unsafe = false for top-level blocks.
				$search_from  // Start searching after the previous block.
			);
			$unsafe_ranges = array_merge( $unsafe_ranges, $result['ranges'] );
			$search_from   = $result['block_end'];
		}

		return $unsafe_ranges;
	}

	/**
	 * Extract unsafe ranges for Classic Editor / page builder content.
	 *
	 * Scans entire content for unsafe patterns when blocks are not present.
	 *
	 * @param string $content Post content (HTML without blocks).
	 * @return array Array of unsafe ranges.
	 */
	private static function extract_unsafe_ranges_from_html( $content ) {
		// Scan entire content for unsafe patterns.
		return self::scan_for_unsafe_patterns( $content, 0 );
	}

	/**
	 * Get the start and end positions of a block in the original content.
	 *
	 * @param array  $block       Block data from parse_blocks().
	 * @param string $content     Full post content.
	 * @param int    $search_from Offset to start searching from (avoids matching earlier sibling blocks of same type).
	 * @return array|false Array with 'start' and 'end' keys, or false if not found.
	 */
	private static function get_block_range_in_content( $block, $content, $search_from = 0 ) {
		$block_name = $block['blockName'];

		// Strip 'core/' prefix for core blocks to match HTML format.
		if ( strpos( $block_name, 'core/' ) === 0 ) {
			$block_name = Str::substr( $block_name, 5 ); // Remove 'core/' (5 characters).
		}

		$opening_pattern = '<!-- wp:' . $block_name;
		$closing_pattern = '<!-- /wp:' . $block_name . ' -->';

		// Find opening comment starting from $search_from to correctly locate repeated blocks of the same type.
		$start = strpos( $content, $opening_pattern, $search_from );
		if ( false === $start ) {
			return false;
		}

		// Check if the block is self-closing (ends with '/-->' and has no closing comment).
		$comment_end = strpos( $content, '-->', $start );
		if ( false !== $comment_end ) {
			$comment_text = Str::substr( $content, $start, $comment_end + 3 - $start );
			if ( Str::substr( rtrim( $comment_text ), -4 ) === '/-->' ) {
				return [
					'start' => $start,
					'end'   => $comment_end + 3,
				];
			}
		}

		// Find the matching closing comment by tracking nested blocks of the same type.
		$depth          = 1;
		$cursor         = $start + strlen( $opening_pattern );
		$content_length = strlen( $content );

		while ( $depth > 0 && $cursor < $content_length ) {
			$next_open  = strpos( $content, $opening_pattern, $cursor );
			$next_close = strpos( $content, $closing_pattern, $cursor );

			if ( false === $next_close ) {
				return false; // Unclosed block — malformed content.
			}

			// If there's another opening before the next closing, it's a nested block.
			if ( false !== $next_open && $next_open < $next_close ) {
				// But only count it as a nested open if it's not self-closing.
				$nested_comment_end  = strpos( $content, '-->', $next_open );
				$nested_comment_text = Str::substr( $content, $next_open, $nested_comment_end + 3 - $next_open );
				if ( Str::substr( rtrim( $nested_comment_text ), -4 ) !== '/-->' ) {
					++$depth;
				}
				$cursor = $next_open + strlen( $opening_pattern );
			} else {
				--$depth;
				if ( 0 === $depth ) {
					return [
						'start' => $start,
						'end'   => $next_close + strlen( $closing_pattern ),
					];
				}
				$cursor = $next_close + strlen( $closing_pattern );
			}
		}

		return false;
	}

	/**
	 * Process a block and its children recursively.
	 *
	 * Each block is evaluated independently based on its own type — safe or unsafe.
	 * Unsafe container blocks protect their own HTML markup but
	 * still allow child blocks to be evaluated individually, so safe blocks like
	 * core/paragraph nested anywhere in the tree can still receive links.
	 *
	 * @param array  $block            Block data from parse_blocks().
	 * @param string $content          Full post content.
	 * @param array  $safe_block_types Array of safe block type names.
	 * @param bool   $parent_is_unsafe Unused — kept for backwards-compatibility with any callers. Always ignored.
	 * @param int    $search_from      Offset to start searching for this block (avoids matching earlier siblings of same type).
	 * @return array{ranges: array, block_end: int} 'ranges' is the unsafe ranges array; 'block_end' is the end
	 *                                               position of this block in content (used by callers to advance
	 *                                               their search cursor without a redundant get_block_range_in_content call).
	 */
	private static function process_block_recursively( $block, $content, $safe_block_types, $parent_is_unsafe = false, $search_from = 0 ) {
		$unsafe_ranges = [];

		// Skip empty blocks (parse_blocks can return empty array items).
		if ( empty( $block['blockName'] ) ) {
			return [
				'ranges'    => $unsafe_ranges,
				'block_end' => $search_from,
			];
		}

		// Find the block position starting from $search_from to handle repeated blocks.
		$block_range = self::get_block_range_in_content( $block, $content, $search_from );
		if ( false === $block_range ) {
			return [
				'ranges'    => $unsafe_ranges,
				'block_end' => $search_from,
			];
		}

		// Always protect the block's opening comment marker.
		$opening_comment_end = strpos( $content, '-->', $block_range['start'] );
		if ( false !== $opening_comment_end ) {
			$unsafe_ranges[] = [
				'start' => $block_range['start'],
				'end'   => $opening_comment_end + 3,
				'type'  => 'block_comment',
			];
		}

		// Check if the block is safe using the parse_blocks() block name.
		$is_safe = in_array( $block['blockName'], $safe_block_types, true );

		if ( $is_safe ) {
			// Protect closing comment (from start of closing comment to block end).
			$closing_comment_start = strrpos( Str::substr( $content, 0, $block_range['end'] ), '<!-- /wp:' );
			if ( false !== $closing_comment_start ) {
				$unsafe_ranges[] = [
					'start' => $closing_comment_start,
					'end'   => $block_range['end'],
					'type'  => 'block_comment',
				];
			}

			// Scan innerHTML for unsafe patterns (shortcodes, scripts, HTML tags, etc.).
			if ( ! empty( $block['innerHTML'] ) ) {
				$inner_html_start  = $opening_comment_end + 3;
				$inner_html_unsafe = self::scan_for_unsafe_patterns(
					$block['innerHTML'],
					$inner_html_start
				);
				$unsafe_ranges     = array_merge( $unsafe_ranges, $inner_html_unsafe );
			}
		} else {
			// Protect closing comment.
			$closing_comment_start = strrpos( Str::substr( $content, 0, $block_range['end'] ), '<!-- /wp:' );
			if ( false !== $closing_comment_start ) {
				$unsafe_ranges[] = [
					'start' => $closing_comment_start,
					'end'   => $block_range['end'],
					'type'  => 'block_comment',
				];
			}

			// Check innerContent for unsafe patterns; fall back to innerHTML for leaf blocks (e.g. core/shortcode).
			if ( empty( $block['innerContent'] ) && ! empty( $block['innerHTML'] ) ) {
				$inner_html_start = $opening_comment_end + 3;
				$text_only        = trim( wp_strip_all_tags( $block['innerHTML'] ) );
				if ( ! empty( $text_only ) ) {
					$unsafe_ranges[] = [
						'start' => $inner_html_start,
						'end'   => $inner_html_start + strlen( $block['innerHTML'] ),
						'type'  => 'block_html',
					];
				}
				$fragment_unsafe = self::scan_for_unsafe_patterns( $block['innerHTML'], $inner_html_start );
				$unsafe_ranges   = array_merge( $unsafe_ranges, $fragment_unsafe );
			}

			if ( ! empty( $block['innerContent'] ) ) {
				$fragment_cursor   = $opening_comment_end + 3;
				$inner_block_index = 0;
				$inner_blocks      = ! empty( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
				$inner_search_pos  = $block_range['start'];

				foreach ( $block['innerContent'] as $fragment ) {
					if ( null === $fragment ) {
						// This slot is an inner block — skip past it in the cursor.
						if ( isset( $inner_blocks[ $inner_block_index ] ) ) {
							$child_range = self::get_block_range_in_content(
								$inner_blocks[ $inner_block_index ],
								$content,
								$inner_search_pos
							);
							if ( false !== $child_range ) {
								$fragment_cursor  = $child_range['end'];
								$inner_search_pos = $child_range['end'];
							}
							++$inner_block_index;
						}
						continue;
					}

					// Scan fragment for unsafe patterns; also mark unsafe if it has plain text outside HTML tags.
					if ( ! empty( trim( $fragment ) ) ) {
						$fragment_unsafe = self::scan_for_unsafe_patterns( $fragment, $fragment_cursor );
						$unsafe_ranges   = array_merge( $unsafe_ranges, $fragment_unsafe );

						// Mark fragment unsafe if it contains plain text outside HTML tags.
						$text_only = trim( wp_strip_all_tags( $fragment ) );
						if ( ! empty( $text_only ) ) {
							$unsafe_ranges[] = [
								'start' => $fragment_cursor,
								'end'   => $fragment_cursor + strlen( $fragment ),
								'type'  => 'block_html',
							];
						}
					}
					$fragment_cursor += strlen( $fragment );
				}
			}
		}

		// Recurse into inner blocks and use returned block_end to advance the cursor.
		if ( ! empty( $block['innerBlocks'] ) ) {
			$inner_search_from = $block_range['start'];
			foreach ( $block['innerBlocks'] as $inner_block ) {
				$inner_result      = self::process_block_recursively(
					$inner_block,
					$content,
					$safe_block_types,
					false,
					$inner_search_from
				);
				$unsafe_ranges     = array_merge( $unsafe_ranges, $inner_result['ranges'] );
				$inner_search_from = $inner_result['block_end'];
			}
		}

		return [
			'ranges'    => $unsafe_ranges,
			'block_end' => $block_range['end'],
		];
	}

	/**
	 * Scan content for unsafe patterns (shortcodes, scripts, styles, HTML tags, comments).
	 *
	 * This method is used by both Block Editor and Classic Editor paths.
	 *
	 * Performance optimized: Uses a single combined regex pattern instead of 5 separate scans.
	 *
	 * @param string $content Content to scan.
	 * @param int    $offset  Offset to add to positions (for nested content).
	 * @return array Array of unsafe ranges.
	 */
	private static function scan_for_unsafe_patterns( $content, $offset = 0 ) {
		$unsafe_ranges = [];

		// Single regex to match scripts, styles, and shortcodes for better performance.
		$combined_pattern = '/'
			. '(?<script><script[^>]*>.*?<\/script>)'
			. '|(?<style><style[^>]*>.*?<\/style>)'
			. '|(?<shortcode>\[[^\]]+\])'
			. '/is';

		if ( preg_match_all( $combined_pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				// Determine type based on which named group matched.
				if ( ! empty( $match['script'][0] ) ) {
					$type       = 'script';
					$full_match = $match['script'];
				} elseif ( ! empty( $match['style'][0] ) ) {
					$type       = 'style';
					$full_match = $match['style'];
				} else {
					$type       = 'shortcode';
					$full_match = $match['shortcode'];
				}

				$unsafe_ranges[] = [
					'start' => $offset + $full_match[1],
					'end'   => $offset + $full_match[1] + strlen( $full_match[0] ),
					'type'  => $type,
				];
			}
		}

		// Match HTML tags (<tag ...>) to protect markup and attributes.
		if ( preg_match_all( '/<[^>]+>/s', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$unsafe_ranges[] = [
					'start' => $offset + $match[1],
					'end'   => $offset + $match[1] + strlen( $match[0] ),
					'type'  => 'html_tag',
				];
			}
		}

		// Match HTML comments (excluding WordPress block comments).
		if ( preg_match_all( '/<!--(?! wp:).*?-->/s', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$unsafe_ranges[] = [
					'start' => $offset + $match[1],
					'end'   => $offset + $match[1] + strlen( $match[0] ),
					'type'  => 'html_comment',
				];
			}
		}

		return $unsafe_ranges;
	}
}
