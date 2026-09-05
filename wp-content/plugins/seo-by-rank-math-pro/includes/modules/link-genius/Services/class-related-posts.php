<?php
/**
 * Related Posts service for Content AI.
 *
 * @since 1.0.0
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Services;

use RankMath\Traits\Hooker;
use RankMathPro\Link_Genius\Services\Batch_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Related_Posts class.
 */
class Related_Posts {
	use Hooker;

	/**
	 * Get AI-derived results for the given post.
	 *
	 * Modes:
	 * - related_posts (default): returns array of related post IDs and persists results.
	 * - suggest_link_opportunities: returns array of [ 'word' => anchor, 'link' => url ] pairs, no persistence.
	 *
	 * @param int   $post_id Target post ID.
	 * @param int   $limit   Number of items.
	 * @param array $args    Additional args: force_refresh(bool), current(array), mode(string).
	 *
	 * @return array|\WP_Error Array of IDs (related_posts) or pairs (suggest_link_opportunities).
	 */
	public function get_related_posts( $post_id, $limit = 3, $args = [] ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return [];
		}

		$args = wp_parse_args(
			$args,
			[
				'force_refresh' => false,
				'current'       => [],
				'mode'          => 'related_posts',
			]
		);
		$mode = sanitize_key( $args['mode'] );

		if ( ! $args['force_refresh'] && 'related_posts' === $mode ) {
			$related_posts = get_post_meta( $post_id, 'rank_math_related_posts', true );
			return ! empty( $related_posts ) ? array_slice( (array) $related_posts, 0, $limit ) : [];
		}

		$service            = new Content_Similarity();
		$current            = is_array( $args['current'] ) ? $args['current'] : [];
		$current['content'] = $this->get_target_text( $post_id, $service, $current );

		$candidates = $service->find_similar_posts(
			$post_id,
			$current['content'],
			10,
			[
				'mode'    => $mode,
				'current' => $current,
			]
		);

		// Propagate WP_Error from Content_Similarity service.
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		$results = array_slice( $candidates, 0, $limit );

		if ( 'related_posts' === $mode ) {
			// Seed reverse relationships for all returned candidates when missing.
			$this->seed_reverse_relationships( $post_id, $candidates );

			/**
			 * Filter related posts results.
			 *
			 * @param array $results Results array.
			 * @param int   $post_id Post ID.
			 * @param array $args    Args.
			 */
			return $this->do_filter( 'content_ai/related_posts/results', $results, $post_id, $args );
		}

		// For suggest_link_opportunities and other modes, return as-is without persistence/filters.
		return $results;
	}

	/**
	 * Render items markup for related posts list.
	 *
	 * @param array $items Array of post IDs.
	 * @param array $args  Options: show_image, image_size, show_excerpt, show_date, show_terms.
	 *
	 * @return string HTML.
	 */
	public function render_items( $items, $args = [] ) {
		$defaults      = [
			'show_image'   => false,
			'image_size'   => 'thumbnail',
			'show_excerpt' => false,
			'show_date'    => false,
			'show_terms'   => false,
			'layout'       => 'grid-vertical',
			'is_editor'    => false,
		];
		$args          = wp_parse_args( $args, $defaults );
		$is_horizontal = in_array( $args['layout'], [ 'grid-horizontal', 'list-horizontal' ], true );

		$out   = [];
		$out[] = '<div class="rank-math-related-wrap">';
		foreach ( (array) $items as $item ) {
			$item = absint( $item );
			if ( $item <= 0 ) {
				continue;
			}

			$out[] = '<article class="rank-math-related-item">';
			if ( $args['show_image'] ) {
				$thumb = get_the_post_thumbnail( $item, sanitize_key( $args['image_size'] ) );
				if ( $thumb ) {
					if ( $args['is_editor'] ) {
						$out[] = '<span class="rank-math-related-thumb">' . $thumb . '</span>';
					} else {
						$out[] = '<a class="rank-math-related-thumb" href="' . esc_url( get_the_permalink( $item ) ) . '">' . $thumb . '</a>';
					}
				}
			}

			// Wrap content in a div for horizontal layouts.
			if ( $is_horizontal ) {
				$out[] = '<div class="rank-math-related-content">';
			}

			if ( $args['is_editor'] ) {
				$out[] = '<h3 class="rank-math-related-title">' . esc_html( get_the_title( $item ) ) . '</h3>';
			} else {
				$out[] = '<h3 class="rank-math-related-title"><a href="' . esc_url( get_the_permalink( $item ) ) . '">' . esc_html( get_the_title( $item ) ) . '</a></h3>';
			}

			if ( $args['show_date'] ) {
				$out[] = '<div class="rank-math-related-date">' . esc_html( get_the_date( '', $item ) ) . '</div>';
			}

			if ( $args['show_terms'] ) {
				$tax_list = get_object_taxonomies( get_post_type( $item ), 'names' );
				$chips    = [];
				foreach ( (array) $tax_list as $taxonomy ) {
					$terms = get_the_terms( $item, $taxonomy );
					if ( is_wp_error( $terms ) || empty( $terms ) ) {
						continue;
					}
					foreach ( $terms as $term ) {
						$chips[] = '<span class="rank-math-related-chip">' . esc_html( $term->name ) . '</span>';
					}
				}
				if ( ! empty( $chips ) ) {
					$out[] = '<div class="rank-math-related-terms">' . implode( ' ', $chips ) . '</div>';
				}
			}

			if ( $args['show_excerpt'] ) {
				$excerpt = get_the_excerpt( $item );
				if ( $excerpt ) {
					$out[] = '<div class="rank-math-related-excerpt">' . esc_html( $excerpt ) . '</div>';
				}
			}

			if ( $is_horizontal ) {
				$out[] = '</div>';
			}

			$out[] = '</article>';
		}
		$out[] = '</div>';

		return implode( '', $out );
	}

	/**
	 * Build target text for similarity comparison.
	 * Combines the post summary and full content, truncated to a max length.
	 *
	 * @param int                $post_id Post ID.
	 * @param Content_Similarity $service Similarity service instance.
	 * @param array              $current Current Post data.
	 * @return string Target text.
	 */
	private function get_target_text( $post_id, $service, $current = [] ) {
		$summary     = ! empty( $current ) ? (array) $current : $service->build_post_summary( $post_id );
		$target_text = $service->build_target_text_from_summary( $summary );
		$content     = ! empty( $current['content'] ) ? $current['content'] : '';
		$max_len     = (int) $this->do_filter( 'content_ai/related_posts/target_text_max_length', 20000, $post_id );
		if ( $max_len > 0 && strlen( $content ) > $max_len ) {
			$content = substr( $content, 0, $max_len );
		}

		return trim( $target_text . ' ' . $content );
	}

	/**
	 * Seed reverse relationships for all candidate posts when they don't have any.
	 *
	 * Batch fetch all postmeta first, then batch update only posts
	 * that need updating. This reduces N get_post_meta() + N update_post_meta() calls
	 * to 1 batch SELECT + M batch INSERTs (where M <= N).
	 *
	 * @param int   $post_id    Source post ID.
	 * @param array $candidates Full candidate list.
	 */
	private function seed_reverse_relationships( $post_id, $candidates ) {
		global $wpdb;

		$candidates = array_map( 'absint', (array) $candidates );
		$candidates = array_filter(
			$candidates,
			function ( $rel_id ) use ( $post_id ) {
				return $rel_id > 0 && $rel_id !== $post_id;
			}
		);

		if ( empty( $candidates ) ) {
			return;
		}

		// Use helper method for batch fetching postmeta.
		$existing_meta = Batch_Helper::batch_fetch_postmeta( $candidates, 'rank_math_related_posts' );

		// Determine which posts need updating (those without existing relationships).
		$posts_to_update = [];
		foreach ( $candidates as $rel_id ) {
			// Skip posts that already have relationships.
			if ( isset( $existing_meta[ $rel_id ] ) && ! empty( $existing_meta[ $rel_id ]->meta_value ) ) {
				continue;
			}

			// Build new relationships for this post.
			$new_candidates = $candidates;
			$pos            = array_search( $rel_id, $new_candidates, true );
			if ( false !== $pos ) {
				$new_candidates[ $pos ] = $post_id;
			} else {
				array_unshift( $new_candidates, $post_id );
			}

			$posts_to_update[ $rel_id ] = array_values( array_unique( $new_candidates ) );
		}

		// Batch update all posts that need new relationships using direct SQL.
		// This avoids N separate UPDATE queries by using a single INSERT ... ON DUPLICATE KEY UPDATE.
		if ( ! empty( $posts_to_update ) ) {
			$values   = [];
			$meta_key = 'rank_math_related_posts';

			foreach ( $posts_to_update as $rel_id => $new_relationships ) {
				$meta_value = maybe_serialize( $new_relationships );
				$values[]   = $wpdb->prepare( '(%d, %s, %s)', $rel_id, $meta_key, $meta_value );
			}

			$values_sql = implode( ', ', $values );

			// Use INSERT ... ON DUPLICATE KEY UPDATE for upsert behavior.
			// This handles both new meta and updates to existing meta in one query.
			$wpdb->query(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				VALUES {$values_sql}
				ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
			);
		}
	}
}
