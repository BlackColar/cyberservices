<?php
/**
 * Bulk Update Service - Business logic for bulk link updates.
 *
 * Handles preview generation, validation, batch management, and rollback operations.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius\Bulk_Update
 */

namespace RankMathPro\Link_Genius\Features\BulkUpdate;

use WP_Error;
use RankMath\Helpers\DB;
use RankMathPro\Link_Genius\Data\Query_Builder;
use RankMathPro\Link_Genius\Features\KeywordMaps\Utils\Content_Analyzer;
use RankMathPro\Link_Genius\Services\Batch_Helper;
use RankMathPro\Link_Genius\Services\Link_Processor;
use RankMathPro\Link_Genius\Services\History_Service;
use RankMathPro\Link_Genius\Services\Rollback_Service;
use RankMathPro\Link_Genius\Services\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Service class.
 *
 * Orchestrates bulk update operations, preview generation, and rollback functionality.
 */
class Service {

	/**
	 * Maximum number of links allowed in a single bulk operation.
	 *
	 * @var int
	 */
	const MAX_LINKS_PER_BATCH = 10000;

	/**
	 * Number of changes per page in preview.
	 *
	 * @var int
	 */
	const PREVIEW_PER_PAGE = 20;

	/**
	 * Start bulk update operation.
	 *
	 * Always uses background processing for consistent behavior and better UX.
	 *
	 * @param array  $filters            Search filters for finding links.
	 * @param array  $update_config      Update configuration.
	 * @param array  $selected_link_ids  Optional array of specific link IDs to update.
	 * @param bool   $is_rollback        Whether this is a rollback operation.
	 * @param string $rollback_batch_id  Batch ID being rolled back (if rollback).
	 * @return array|WP_Error Result data or error.
	 */
	public function start_bulk_update( $filters, $update_config, $selected_link_ids = null, $is_rollback = false, $rollback_batch_id = null ) {
		// Skip validation for rollback operations (we're restoring from snapshots).
		if ( ! $is_rollback ) {
			// Validate inputs.
			$validation = $this->validate_update_config( $update_config );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			// Get affected links.
			$affected_links = $this->find_affected_links( $filters, $selected_link_ids );
			if ( is_wp_error( $affected_links ) ) {
				return $affected_links;
			}

			if ( empty( $affected_links ) ) {
				return new WP_Error(
					'no_links_found',
					__( 'No links found matching the specified filters.', 'rank-math-pro' ),
					[ 'status' => 404 ]
				);
			}

			$total_links = count( $affected_links );
		} else {
			// For rollback, we'll get link count from the processor.
			$total_links = 0;
		}

		// Check maximum links limit (skip for rollback).
		if ( ! $is_rollback && $total_links > self::MAX_LINKS_PER_BATCH ) {
			return new WP_Error(
				'too_many_links',
				sprintf(
					/* translators: %d: maximum number of links */
					__( 'Cannot update more than %d links at once. Please refine your filters.', 'rank-math-pro' ),
					self::MAX_LINKS_PER_BATCH
				),
				[ 'status' => 400 ]
			);
		}

		// Always use background processing for consistent behavior.
		$processor = Processor::get();
		$result    = $processor->start( $filters, $update_config, $selected_link_ids, $is_rollback, $rollback_batch_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'success'       => true,
			'batch_id'      => 'current',
			'is_background' => true,
			'total_links'   => $result['total_links'],
			'total_posts'   => $result['total_posts'],
			'message'       => $is_rollback
				? __( 'Rollback started. Processing in background.', 'rank-math-pro' )
				: __( 'Bulk update started. Processing in background.', 'rank-math-pro' ),
		];
	}

	/**
	 * Rollback a completed batch.
	 *
	 * @param string $batch_id          Batch ID to rollback.
	 * @param array  $selected_post_ids Optional array of specific post IDs to rollback.
	 * @return array|WP_Error Result data or error.
	 */
	public function rollback( $batch_id, $selected_post_ids = null ) {
		// Use shared Rollback_Service for unified rollback logic.
		$rollback_service = new Rollback_Service();
		return $rollback_service->execute( $batch_id, $selected_post_ids );
	}

	/**
	 * Get bulk update history.
	 *
	 * Only returns bulk_update history (not keyword_map entries).
	 *
	 * @param int $page     Page number (1-indexed).
	 * @param int $per_page Number of items per page.
	 * @return array History data.
	 */
	public function get_history( $page = 1, $per_page = 20 ) {
		// Use shared History_Service for unified history retrieval.
		$history_service = new History_Service();
		return $history_service->get_history( 'bulk_update', $page, $per_page );
	}

	/**
	 * Get list of changes for a specific batch.
	 *
	 * @param string $batch_id Batch ID.
	 * @return array|WP_Error Array of changes or error.
	 */
	public function get_batch_changes( $batch_id ) {
		// Use shared Rollback_Service for unified batch changes retrieval.
		$rollback_service = new Rollback_Service();
		return $rollback_service->get_batch_changes( $batch_id );
	}

	/**
	 * Find affected links based on filters and optional selected IDs.
	 *
	 * @param array $filters           Search filters.
	 * @param array $selected_link_ids Optional array of specific link IDs.
	 * @return array|WP_Error Array of link objects or error.
	 */
	private function find_affected_links( $filters, $selected_link_ids = null ) {
		// The user has already selected specific links from the preview, so other filters
		// (search, post_type, etc.) should not be applied again.
		if ( ! empty( $selected_link_ids ) && is_array( $selected_link_ids ) ) {
			$query_args = [
				'link_ids' => $selected_link_ids,
				'per_page' => 0,
			];
		} else {
			// No specific link IDs - use all filters.
			$query_args             = $filters;
			$query_args['per_page'] = 0; // Get all links.
		}

		$links = Query_Builder::get_links( $query_args );

		return $links;
	}

	/**
	 * Extract sentence context containing the anchor text or link.
	 *
	 * @param string $content          Post content.
	 * @param string $anchor_text      Anchor text to find.
	 * @param string $url              Link URL.
	 * @param string $new_anchor       Optional. New anchor text for preview. Default null.
	 * @param int    $link_id          Optional. Link ID to find specific instance. Default null.
	 * @param int    $occurrence_index Optional. Which occurrence to use (0-based). Default 0.
	 * @param string $new_url          Optional. New URL for preview. Default null.
	 * @return array Array with 'before' and 'after' sentence context.
	 */
	public function extract_sentence_context( $content, $anchor_text, $url, $new_anchor = null, $link_id = null, $occurrence_index = 0, $new_url = null ) {
		// Remove WordPress block comments before processing to avoid showing raw HTML in context.
		// These comments like <!-- wp:paragraph --> can appear in the extracted context.
		$cleaned_content = preg_replace( '/<!--\s*\/?wp:[^>]*-->/i', '', $content );

		// Find all links with this URL and anchor text in the content.
		// Use trailing slash optional pattern to handle URL normalization differences
		// (e.g., stored URL "example.com/about" should match content "example.com/about/").
		$escaped_url = preg_quote( $url, '/' );
		$pattern     = '/<a\s+[^>]*?href=["\']' . $escaped_url . '\/?["\'][^>]*?>.*?<\/a>/is';

		if ( ! preg_match_all( $pattern, $cleaned_content, $all_matches, PREG_OFFSET_CAPTURE ) ) {
			return [
				'before' => '',
				'after'  => '',
			];
		}

		// Filter matches to only those with matching anchor text.
		$matching_links = [];
		foreach ( $all_matches[0] as $match ) {
			// Extract anchor from the full <a> tag.
			if ( preg_match( '/>(.+?)<\/a>$/is', $match[0], $anchor_match ) ) {
				$match_anchor = wp_strip_all_tags( $anchor_match[1] );
				// Use case-insensitive comparison to handle variations.
				if ( strcasecmp( trim( $match_anchor ), trim( $anchor_text ) ) === 0 ) {
					$matching_links[] = $match;
				}
			}
		}

		// If no matching links found, return empty.
		if ( empty( $matching_links ) ) {
			return [
				'before' => '',
				'after'  => '',
			];
		}

		// Use the specified occurrence index, or default to first occurrence.
		$target_match_index = min( $occurrence_index, count( $matching_links ) - 1 );
		$target_match_index = max( 0, $target_match_index ); // Ensure non-negative.

		$link_position = $matching_links[ $target_match_index ][1];
		$link_html     = $matching_links[ $target_match_index ][0];

		// Use Content_Analyzer with character-based extraction for focused context.
		// This prevents showing the same sentence for multiple instances of the same word.
		$context = Content_Analyzer::extract_context(
			$cleaned_content,
			$link_position,
			$link_html,
			[
				'mode'  => 'chars',
				'chars' => 80, // Show more chars than keyword maps (80 vs 50) for better context.
			]
		);

		// Build context with link preserved, including ellipsis.
		$before_part = $context['before'] ? $context['before'] . ' ' : '';
		$after_part  = $context['after'] ? ' ' . $context['after'] : '';

		// Clean up partial HTML tags that might appear at the start/end of context.
		// Only remove if we find a closing > without a matching opening <.
		// This handles cases like "...href='url'>text" where context starts mid-tag.
		if ( strpos( $before_part, '<' ) === false && strpos( $before_part, '>' ) !== false ) {
			// No < but has >, so we have an incomplete tag - remove everything up to and including first >.
			$before_part = preg_replace( '/^[^>]*>/', '', $before_part );
		}
		// Remove any incomplete closing tag at the end (e.g., "</a..." or "<a...").
		$after_part = preg_replace( '/<[^>]*$/', '', $after_part );

		// Add ellipsis if context was trimmed.
		$prefix = $link_position > 80 ? '...' : '';
		$suffix = ( $link_position + strlen( $link_html ) + 80 ) < strlen( $cleaned_content ) ? '...' : '';

		$sentence_before = $prefix . $before_part . $link_html . $after_part . $suffix;

		// Build the "after" sentence by applying URL and/or anchor changes.
		$sentence_after = $sentence_before;

		if ( $new_url ) {
			$sentence_after = str_replace(
				'href="' . $url,
				'href="' . $new_url,
				str_replace(
					"href='" . $url,
					"href='" . $new_url,
					$sentence_after
				)
			);
		}

		if ( $new_anchor ) {
			$sentence_after = str_ireplace(
				'>' . $anchor_text . '<',
				'>' . $new_anchor . '<',
				$sentence_after
			);
		}

		return [
			'before' => trim( $sentence_before ),
			'after'  => trim( $sentence_after ),
		];
	}

	/**
	 * Validate update configuration.
	 *
	 * @param array $config Update configuration.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_update_config( $config ) {
		// Validate operation type.
		if ( empty( $config['operation_type'] ) || ! in_array( $config['operation_type'], [ 'anchor', 'url', 'both' ], true ) ) {
			return new WP_Error(
				'invalid_operation_type',
				__( 'Invalid operation type. Must be "anchor", "url", or "both".', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Validate anchor update config.
		if ( in_array( $config['operation_type'], [ 'anchor', 'both' ], true ) ) {
			if ( empty( $config['anchor_update']['search_value'] ) ) {
				return new WP_Error(
					'missing_anchor_search',
					__( 'Anchor search value is required.', 'rank-math-pro' ),
					[ 'status' => 400 ]
				);
			}

			// Validate regex if search_type is regex.
			if ( isset( $config['anchor_update']['search_type'] ) && 'regex' === $config['anchor_update']['search_type'] ) {
				$pattern = $config['anchor_update']['search_value'];
				if ( preg_match( $pattern, '' ) === false && PREG_NO_ERROR !== preg_last_error() ) {
					return new WP_Error(
						'invalid_regex',
						__( 'Invalid regex pattern for anchor search.', 'rank-math-pro' ),
						[ 'status' => 400 ]
					);
				}
			}
		}

		// Validate URL update config.
		if ( in_array( $config['operation_type'], [ 'url', 'both' ], true ) ) {
			if ( empty( $config['url_update']['search_value'] ) ) {
				return new WP_Error(
					'missing_url_search',
					__( 'URL search value is required.', 'rank-math-pro' ),
					[ 'status' => 400 ]
				);
			}

			// Validate URL format for exact search.
			if ( isset( $config['url_update']['search_type'] ) && 'exact' === $config['url_update']['search_type'] ) {
				$url = $config['url_update']['search_value'];
				if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
					return new WP_Error(
						'invalid_url_format',
						__( 'Invalid URL format for exact URL search.', 'rank-math-pro' ),
						[ 'status' => 400 ]
					);
				}
			}
		}

		return true;
	}
}
