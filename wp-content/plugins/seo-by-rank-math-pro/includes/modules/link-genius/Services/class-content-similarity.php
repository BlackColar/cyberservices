<?php
/**
 * Content Similarity service (TF-IDF + AI ranking).
 *
 * Centralizes logic used by Link Suggestions and Related Posts
 * to avoid duplication and improve maintainability.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Services;

use RankMath\Helper;
use RankMath\Admin\Admin_Helper;
use RankMath\Paper\Singular;
use RankMath\Helpers\DB;
use RankMathPro\Link_Genius\Services\Batch_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Content_Similarity service class.
 */
class Content_Similarity {

	/**
	 * Object cache group name.
	 *
	 * @var string
	 */
	private $cache_group = 'rank_math_content_similarity';

	/**
	 * Find similar posts using TF-IDF + Cosine Similarity + AI refinement.
	 *
	 * Process flow:
	 * 1. Build candidate pool (pillar content → related by taxonomy → recently updated)
	 * 2. Build candidate documents with SEO text
	 * 3. Calculate TF-IDF similarity scores
	 * 4. Filter top candidates
	 * 5. Refine with AI
	 *
	 * @param int    $post_id     Post ID to compare against (excluded from results).
	 * @param string $target_text Text to compare (highlighted text or current post content).
	 * @param int    $limit       Number of items to return (after AI refinement).
	 * @param array  $options     Optional args: [ 'mode' => 'suggest_links'|'related_posts'|'suggest_link_opportunities', 'current' => array ].
	 *
	 * @return array<int, array>|int[]|\WP_Error Array of items or IDs depending on mode, or WP_Error on failure.
	 */
	public function find_similar_posts( $post_id, $target_text, $limit = 3, $options = [] ) {
		global $wpdb;

		$exclude_id   = absint( $post_id );
		$post_type    = get_post_type( $post_id );
		$post_type    = $post_type ? sanitize_key( $post_type ) : 'post';
		$target_limit = (int) apply_filters( 'rank_math/ai_link_suggestions/target_limit', 100 );

		$selected  = [];
		$remaining = $target_limit;

		// ===================================================================
		// STEP 1: Build Candidate Pool
		// Priority: Pillar → Related by taxonomy → Recently updated
		// ===================================================================

		// 1a) Pillar content first.
		$pillar_ids = DB::get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = %s AND p.post_status = 'publish' AND p.ID != %d
					AND pm.meta_key = %s AND pm.meta_value != ''
					ORDER BY p.post_modified_gmt DESC
					LIMIT %d",
				$post_type,
				$exclude_id,
				'rank_math_pillar_content',
				$target_limit
			)
		);

		if ( ! empty( $pillar_ids ) ) {
			$selected  = $pillar_ids;
			$remaining = $target_limit - count( $selected );
		}

		// 2) Posts sharing any taxonomy terms with the current post.
		if ( $remaining > 0 ) {
			$related_ids = [];
			$taxonomies  = get_object_taxonomies( $post_type, 'names' );
			if ( ! empty( $taxonomies ) ) {
				// Prefer terms passed from JS (unsaved state) via options.current. Expect terms_ids only.
				$provided_term_ids = [];
				if ( ! empty( $options['current'] ) ) {
					$cur = $options['current'];
					if ( ! empty( $cur['terms_ids'] ) && is_array( $cur['terms_ids'] ) ) {
						foreach ( $cur['terms_ids'] as $tax => $ids ) {
							$provided_term_ids = array_merge( $provided_term_ids, array_map( 'intval', $ids ) );
						}
					}
				}

				if ( ! empty( $provided_term_ids ) ) {
					$term_ids   = array_values( array_unique( array_map( 'intval', $provided_term_ids ) ) );
					$in_terms   = implode( ',', array_map( 'intval', array_slice( $term_ids, 0, 2000 ) ) );
					$not_in     = array_unique( array_map( 'absint', array_merge( [ $exclude_id ], $selected ) ) );
					$not_in_sql = '';
					if ( count( $not_in ) > 1 ) {
						$not_in_sql = ' AND p.ID NOT IN (' . implode( ',', $not_in ) . ')';
					}
					$related_sql = "SELECT DISTINCT tr.object_id
							FROM {$wpdb->term_relationships} tr
							INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
							INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
							WHERE tt.term_id IN ($in_terms)
								AND p.post_status = 'publish'
								AND p.post_type = %s
								AND p.ID != %d" . $not_in_sql .
								' ORDER BY p.post_modified_gmt DESC
							LIMIT %d';
					$related_ids = DB::get_col( $wpdb->prepare( $related_sql, $post_type, $exclude_id, $remaining ) );
				}
			}

			if ( ! empty( $related_ids ) ) {
				$related_ids = array_values( array_unique( array_map( 'absint', $related_ids ) ) );
				// Exclude already selected and the current post.
				$related_ids = array_values( array_diff( $related_ids, $selected, [ $exclude_id ] ) );
				$to_take     = array_slice( $related_ids, 0, $remaining );
				$selected    = array_values( array_unique( array_merge( $selected, $to_take ) ) );
				$remaining   = $target_limit - count( $selected );
			}
		}

		// 3) Recently updated posts to fill remaining.
		if ( $remaining > 0 ) {
			// Exclude already selected and current post at SQL level when possible.
			$not_in = array_unique( array_map( 'absint', array_merge( [ $exclude_id ], $selected ) ) );
			$where  = "WHERE post_type = %s AND post_status = 'publish' AND ID != %d";
			if ( count( $not_in ) > 1 ) {
				$in_str = implode( ',', $not_in );
				$where .= " AND ID NOT IN ($in_str)";
			}

			$sql        = "SELECT ID FROM {$wpdb->posts} {$where} ORDER BY post_modified_gmt DESC LIMIT %d";
			$recent_ids = DB::get_col( $wpdb->prepare( $sql, $post_type, $exclude_id, $remaining ) );

			if ( ! empty( $recent_ids ) ) {
				$recent_ids = array_values( array_unique( array_map( 'absint', $recent_ids ) ) );
				$recent_ids = array_values( array_diff( $recent_ids, $selected, [ $exclude_id ] ) );
				$to_take    = array_slice( $recent_ids, 0, $remaining );
				$selected   = array_values( array_unique( array_merge( $selected, $to_take ) ) );
			}
		}

		if ( empty( $selected ) ) {
			return [];
		}

		$candidate_ids = $selected;

		// ===================================================================
		// STEP 2: Build Candidate Documents
		// Collect SEO text (title + description) for each candidate post
		// ===================================================================

		// Batch fetch all candidate posts to avoid N+1 queries.
		// Include post_type and post_name for permalink generation.
		$posts = Batch_Helper::batch_fetch_posts( $candidate_ids, [ 'ID', 'post_title', 'post_excerpt', 'post_content', 'post_modified_gmt', 'post_type', 'post_name' ] );

		$documents      = [];
		$prefetched_seo = [];
		foreach ( $candidate_ids as $cid ) {
			if ( ! Helper::is_post_indexable( $cid ) ) {
				continue;
			}

			if ( empty( $posts[ $cid ] ) ) {
				continue;
			}

			$seo_text = $this->get_post_seo_text( $posts[ $cid ] );
			if ( '' === $seo_text ) {
				continue;
			}

			$documents[] = [
				'post_id'    => $cid,
				'ai_summary' => $seo_text,
			];

			// Store the SEO text for reuse in ai_refine_generic.
			$prefetched_seo[ $cid ] = $seo_text;
		}

		if ( empty( $documents ) ) {
			return [];
		}

		// ===================================================================
		// STEP 3: Calculate TF-IDF Similarity Scores
		// Compare target text against all candidate documents
		// ===================================================================
		$similarity_scores = $this->calculate_tfidf_similarity( $target_text, $documents, $posts );

		// ===================================================================
		// STEP 4: Filter Top Candidates
		// Keep only candidates with similarity > -1, limit to top N
		// ===================================================================
		$top_candidates = array_filter(
			$similarity_scores,
			function ( $candidate ) {
				return isset( $candidate['similarity'] ) && $candidate['similarity'] > -1;
			}
		);

		$top_limit      = (int) apply_filters( 'rank_math/ai_link_suggestions/top_candidates_limit', 20 );
		$top_candidates = array_slice( $top_candidates, 0, max( 1, $top_limit ), true );
		$mode           = isset( $options['mode'] ) ? $options['mode'] : 'suggest_links';

		// ===================================================================
		// STEP 5: AI Refinement
		// Use AI to select the best matches from top candidates
		// ===================================================================
		if ( $mode !== 'suggest_link_opportunities' && count( $top_candidates ) < $limit ) {
			return $mode === 'suggest_links' ? $top_candidates : array_keys( $top_candidates );
		}

		$current = $this->sanitize_recursive( ( $options['current'] ?? [] ) );
		$result  = $this->ai_refine_generic(
			$post_id,
			$top_candidates,
			$limit,
			[
				'endpoint'         => $mode,
				'text'             => $target_text,
				'current'          => $current,
				'prefetched_posts' => $posts,
				'prefetched_seo'   => $prefetched_seo,
			]
		);

		return $result;
	}

	/**
	 * Build a summary for a post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array{ id:int, seo_text:string, terms:array, excerpt:string, headings:array }
	 */
	public function build_post_summary( $post_id ) {
		$summaries = $this->build_post_summaries_batch( [ $post_id ] );
		return ! empty( $summaries[0] ) ? $summaries[0] : [
			'id'       => 0,
			'seo_text' => '',
			'terms'    => [],
			'excerpt'  => '',
			'headings' => [],
			'keywords' => [],
			'url'      => '',
		];
	}

	/**
	 * Build a comparable text from a summary array.
	 *
	 * @param array $summary Summary from build_post_summary().
	 *
	 * @return string
	 */
	public function build_target_text_from_summary( $summary ) {
		$parts = [];
		if ( ! empty( $summary['seo_text'] ) ) {
			$parts[] = $summary['seo_text'];
		}
		if ( ! empty( $summary['excerpt'] ) ) {
			$parts[] = $summary['excerpt'];
		}
		if ( ! empty( $summary['headings'] ) && is_array( $summary['headings'] ) ) {
			$parts[] = implode( ' ', array_map( 'wp_strip_all_tags', $summary['headings'] ) );
		}
		if ( ! empty( $summary['terms'] ) && is_array( $summary['terms'] ) ) {
			foreach ( $summary['terms'] as $names ) {
				$parts[] = implode( ' ', $names );
			}
		}
		return trim( implode( ' ', $parts ) );
	}

	/**
	 * Build post summaries payload for AI from candidates.
	 *
	 * @param array $candidates       Candidates list.
	 * @param array $prefetched_posts Optional pre-fetched posts indexed by ID.
	 * @param array $prefetched_seo   Optional pre-computed SEO text indexed by post ID.
	 *
	 * @return array
	 */
	private function get_post_summaries( $candidates, $prefetched_posts = [], $prefetched_seo = [] ) {
		$post_ids = array_map(
			function ( $candidate ) {
				return isset( $candidate['id'] ) ? absint( $candidate['id'] ) : 0;
			},
			array_values( $candidates )
		);
		$post_ids = array_filter( $post_ids );

		return $this->build_post_summaries_batch( $post_ids, $prefetched_posts, $prefetched_seo );
	}

	/**
	 * Build post summaries in batch for multiple posts.
	 * This is the core method that both get_post_summaries() and build_post_summary() use.
	 *
	 * @param array $post_ids         Array of post IDs.
	 * @param array $prefetched_posts Optional pre-fetched posts indexed by ID.
	 * @param array $prefetched_seo   Optional pre-computed SEO text indexed by post ID.
	 *
	 * @return array Array of summaries.
	 */
	private function build_post_summaries_batch( $post_ids, $prefetched_posts = [], $prefetched_seo = [] ) {
		$post_ids = array_map( 'absint', $post_ids );

		// Batch fetch posts if not pre-fetched.
		if ( empty( $prefetched_posts ) ) {
			$prefetched_posts = Batch_Helper::batch_fetch_posts(
				$post_ids,
				[ 'ID', 'post_title', 'post_excerpt', 'post_content', 'post_modified_gmt', 'post_type', 'post_name' ]
			);
		}

		// Batch fetch focus keywords for all posts.
		$keywords_meta = Batch_Helper::batch_fetch_postmeta( $post_ids, 'rank_math_focus_keyword' );

		$summaries = [];
		foreach ( $post_ids as $post_id ) {
			if ( empty( $prefetched_posts[ $post_id ] ) ) {
				continue;
			}

			$post = $prefetched_posts[ $post_id ];

			// Check cache first.
			$cache_key = 'summary:' . $post->ID . ':' . $post->post_modified_gmt;
			$cached    = wp_cache_get( $cache_key, $this->cache_group );
			if ( false !== $cached ) {
				$summaries[] = $cached;
				continue;
			}

			// Use pre-computed seo_text if available, otherwise compute it.
			$seo_text = isset( $prefetched_seo[ $post_id ] ) ? $prefetched_seo[ $post_id ] : $this->get_post_seo_text( $post );
			$excerpt  = wp_strip_all_tags( get_the_excerpt( $post ) );
			$headings = $this->extract_headings_from_content( $post->post_content );
			$terms    = $this->get_post_terms_by_taxonomy( $post );
			$keywords = isset( $keywords_meta[ $post_id ] ) ? explode( ',', $keywords_meta[ $post_id ]->meta_value ) : [];

			$summary = [
				'id'       => $post->ID,
				'url'      => get_permalink( $post->ID ),
				'seo_text' => $seo_text,
				'terms'    => $terms,
				'excerpt'  => $excerpt,
				'headings' => $headings,
				'keywords' => $keywords,
			];

			wp_cache_set( $cache_key, $summary, $this->cache_group, HOUR_IN_SECONDS );
			$summaries[] = $summary;
		}

		return $summaries;
	}

	/**
	 * Get SEO text (title + description) for a post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private function get_post_seo_text( $post ) {
		rank_math()->variables->setup();
		$cache_key = 'seo_text:' . $post->ID . ':' . $post->post_modified_gmt;
		$cached    = wp_cache_get( $cache_key, $this->cache_group );
		if ( $cached !== false ) {
			return $cached;
		}

		$paper = new Singular();
		$paper->set_object( $post );
		$title_meta = $paper->title();
		$desc_meta  = $paper->description();

		$seo_text = trim( $title_meta . ' ' . $desc_meta );
		wp_cache_set( $cache_key, $seo_text, $this->cache_group, HOUR_IN_SECONDS );
		return $seo_text;
	}

	/**
	 * Extract heading texts from post content.
	 *
	 * @param string $content HTML content.
	 * @return array
	 */
	private function extract_headings_from_content( $content ) {
		$headings = [];
		if ( empty( $content ) ) {
			return $headings;
		}

		if ( preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>\s*/is', $content, $matches ) ) {
			foreach ( $matches[1] as $raw ) {
				$text = trim( wp_strip_all_tags( $raw ) );
				if ( '' !== $text ) {
					$headings[] = $text;
				}
			}
		}

		return $headings;
	}

	/**
	 * Get post terms grouped by taxonomy.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	private function get_post_terms_by_taxonomy( $post ) {
		$terms_map  = [];
		$taxonomies = get_object_taxonomies( $post, 'names' );
		if ( empty( $taxonomies ) ) {
			return $terms_map;
		}

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$names = [];
			foreach ( $terms as $term ) {
				$names[] = $term->name;
			}
			if ( ! empty( $names ) ) {
				$terms_map[ $taxonomy ] = array_values( array_unique( $names ) );
			}
		}

		return $terms_map;
	}

	/**
	 * Get term names from term IDs grouped by taxonomy.
	 * Fetch all terms in a single custom query instead of using get_terms().
	 * This is 70-80% faster as it skips WP_Term object creation, filters, and metadata loading.
	 *
	 * @param array $terms_ids Array of term IDs grouped by taxonomy. Example: ['category' => [2], 'post_tag' => [26, 27]].
	 * @return array Array of term names grouped by taxonomy. Example: ['category' => ['News'], 'post_tag' => ['PHP', 'WordPress']].
	 */
	private function get_terms_names_by_taxonomy( $terms_ids ) {
		global $wpdb;

		// Collect all term IDs and valid taxonomies.
		$all_term_ids     = [];
		$valid_taxonomies = [];

		foreach ( $terms_ids as $tax => $ids ) {
			if ( empty( $ids ) || ! is_array( $ids ) ) {
				continue;
			}

			$tax = sanitize_key( $tax );
			$ids = array_map( 'intval', $ids );
			$ids = array_filter( $ids );

			if ( empty( $ids ) ) {
				continue;
			}

			$valid_taxonomies[] = $tax;
			$all_term_ids       = array_merge( $all_term_ids, $ids );
		}

		if ( empty( $all_term_ids ) || empty( $valid_taxonomies ) ) {
			return [];
		}

		// Prepare query parameters.
		$all_term_ids       = array_unique( $all_term_ids );
		$term_ids_placehold = Batch_Helper::generate_placeholders( $all_term_ids, '%d' );
		$taxonomy_placehold = Batch_Helper::generate_placeholders( $valid_taxonomies, '%s' );

		// Fetch all terms in a single optimized query.
		// Only select the columns we need: term_id, name, taxonomy.
		$query = $wpdb->prepare(
			"SELECT t.term_id, t.name, tt.taxonomy
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			WHERE t.term_id IN ({$term_ids_placehold})
			AND tt.taxonomy IN ({$taxonomy_placehold})", // phpcs:ignore -- WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are dynamically generated via generate_placeholders()
			...array_merge( array_map( 'intval', $all_term_ids ), $valid_taxonomies )
		);

		$results = DB::get_results( $query, OBJECT );

		if ( empty( $results ) ) {
			return [];
		}

		// Group term names by taxonomy.
		$terms_map = [];
		foreach ( $results as $row ) {
			if ( ! isset( $terms_map[ $row->taxonomy ] ) ) {
				$terms_map[ $row->taxonomy ] = [];
			}
			$terms_map[ $row->taxonomy ][] = $row->name;
		}

		// Deduplicate names per taxonomy.
		foreach ( $terms_map as $tax => $names ) {
			$terms_map[ $tax ] = array_values( array_unique( $names ) );
		}

		return $terms_map;
	}

	/**
	 * Calculate TF-IDF similarity between target and candidates.
	 *
	 * @param string $target_text     Target text.
	 * @param array  $candidates      Candidate documents.
	 * @param array  $prefetched_posts Optional prefetched posts indexed by ID.
	 *
	 * @return array
	 */
	private function calculate_tfidf_similarity( $target_text, $candidates, $prefetched_posts = [] ) {
		$target_words  = $this->tokenize_text( $target_text );
		$idf_scores    = $this->calculate_idf( $target_words, $candidates );
		$target_vector = $this->calculate_tfidf_vector( $target_words, $idf_scores );

		$similarity_scores = [];
		foreach ( $candidates as $candidate ) {
			$candidate_words  = $this->tokenize_text( $candidate['ai_summary'] );
			$candidate_vector = $this->calculate_tfidf_vector( $candidate_words, $idf_scores );
			$similarity       = $this->cosine_similarity( $target_vector, $candidate_vector );

			$pid  = (int) $candidate['post_id'];
			$post = isset( $prefetched_posts[ $pid ] ) ? $prefetched_posts[ $pid ] : null;

			$similarity_scores[ $pid ] = [
				'id'          => $pid,
				'title'       => $post ? $post->post_title : get_the_title( $pid ),
				'url'         => $post ? get_permalink( $post ) : get_permalink( $pid ),
				'type'        => $post ? $post->post_type : get_post_type( $pid ),
				'description' => $candidate['ai_summary'],
				'similarity'  => $similarity,
			];
		}

		uasort(
			$similarity_scores,
			function ( $a, $b ) {
				return $b['similarity'] <=> $a['similarity'];
			}
		);

		return $similarity_scores;
	}

	/**
	 * Tokenize text into normalized words.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	private function tokenize_text( $text ) {
		$text = strtolower( $text );
		$text = preg_replace( '/[^\w\s]/', '', $text );

		$words      = preg_split( '/\s+/', $text );
		$stop_words = [ 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'can', 'this', 'that', 'these', 'those' ];

		return array_filter(
			(array) $words,
			function ( $word ) use ( $stop_words ) {
				return ! in_array( $word, $stop_words, true ) && strlen( $word ) > 2;
			}
		);
	}

	/**
	 * Calculate IDF across documents.
	 *
	 * @param array $target_words Target words.
	 * @param array $candidates   Docs.
	 *
	 * @return array
	 */
	private function calculate_idf( $target_words, $candidates ) {
		$total_docs     = count( $candidates ) + 1; // +1 for target doc.
		$word_doc_count = [];

		foreach ( $target_words as $word ) {
			$word_doc_count[ $word ] = 1; // Target doc contains target words.
		}

		foreach ( $candidates as $candidate ) {
			$candidate_words = $this->tokenize_text( $candidate['ai_summary'] );
			$unique_words    = array_unique( $candidate_words );

			foreach ( $unique_words as $word ) {
				if ( ! isset( $word_doc_count[ $word ] ) ) {
					$word_doc_count[ $word ] = 0;
				}
				++$word_doc_count[ $word ];
			}
		}

		$idf_scores = [];
		foreach ( $word_doc_count as $word => $count ) {
			$idf_scores[ $word ] = ( $count > 0 ) ? log( $total_docs / $count ) : 0;
		}

		return $idf_scores;
	}

	/**
	 * Calculate TF-IDF vector for a document.
	 *
	 * @param array $words      Tokenized words.
	 * @param array $idf_scores IDF scores.
	 *
	 * @return array
	 */
	private function calculate_tfidf_vector( $words, $idf_scores ) {
		$word_count  = array_count_values( $words );
		$total_words = max( 1, (int) count( $words ) );
		$vector      = [];

		foreach ( $word_count as $word => $count ) {
			if ( isset( $idf_scores[ $word ] ) ) {
				$tf              = $count / $total_words;
				$vector[ $word ] = $tf * $idf_scores[ $word ];
			}
		}

		return $vector;
	}

	/**
	 * Cosine similarity between two vectors.
	 *
	 * @param array $vector1 First.
	 * @param array $vector2 Second.
	 *
	 * @return float
	 */
	private function cosine_similarity( $vector1, $vector2 ) {
		$dot_product = 0;
		$magnitude1  = 0;
		$magnitude2  = 0;

		$all_words = array_unique( array_merge( array_keys( (array) $vector1 ), array_keys( (array) $vector2 ) ) );
		foreach ( $all_words as $word ) {
			$val1         = $vector1[ $word ] ?? 0;
			$val2         = $vector2[ $word ] ?? 0;
			$dot_product += $val1 * $val2;
			$magnitude1  += $val1 * $val1;
			$magnitude2  += $val2 * $val2;
		}

		$magnitude1 = sqrt( $magnitude1 );
		$magnitude2 = sqrt( $magnitude2 );

		if ( 0.0 === $magnitude1 || 0.0 === $magnitude2 ) {
			return 0.0;
		}

		return $dot_product / ( $magnitude1 * $magnitude2 );
	}

	/**
	 * Generic AI refinement handler for both suggest_links and related_posts.
	 *
	 * @param int   $post_id          Post ID.
	 * @param array $candidates       Candidate list.
	 * @param int   $limit            Max results.
	 * @param array $args             Options: endpoint (required), text (optional), content_html (optional), current (optional), prefetched_posts (optional), prefetched_seo (optional).
	 *
	 * @return array[]|int[]|\WP_Error Array of items (suggest_links), array of IDs (related_posts/suggest_link_opportunities), or WP_Error on failure.
	 */
	private function ai_refine_generic( $post_id, $candidates, $limit, $args ) {
		$connect_data = Admin_Helper::get_registration_data();

		// Early bail if registration data is missing - AI API won't work without it.
		if ( empty( $connect_data ) || empty( $connect_data['username'] ) || empty( $connect_data['api_key'] ) ) {
			return new \WP_Error(
				'missing_registration',
				__( 'Rank Math registration data is missing. Please connect your site to use AI features.', 'rank-math-pro' ),
				[ 'status' => 401 ]
			);
		}

		$endpoint         = isset( $args['endpoint'] ) ? $args['endpoint'] : 'suggest_links';
		$current          = $args['current'] ?? [];
		$prefetched_posts = $args['prefetched_posts'] ?? [];
		$prefetched_seo   = $args['prefetched_seo'] ?? [];

		if ( ! empty( $current['content'] ) ) {
			$current['headings'] = $this->extract_headings_from_content( $current['content'] );
		}

		if ( ! empty( $current['terms_ids'] ) ) {
			$current['terms'] = $this->get_terms_names_by_taxonomy( $current['terms_ids'] );
			unset( $current['terms_ids'] );
		}

		// Base payload.
		$payload = [
			'username'       => $connect_data['username'],
			'api_key'        => $connect_data['api_key'],
			'site_url'       => $connect_data['site_url'],
			'plugin_version' => rank_math_pro()->version,
			'summaries'      => $this->get_post_summaries( $candidates, $prefetched_posts, $prefetched_seo ),
			'current'        => $current,
		];

		if ( $endpoint === 'suggest_links' && isset( $args['text'] ) ) {
			$payload['text'] = $args['text'];
		}

		$url      = CONTENT_AI_URL . '/ai/' . $endpoint;
		$response = wp_remote_post(
			$url,
			[
				'headers' => [ 'content-type' => 'application/json' ],
				'body'    => wp_json_encode( $payload ),
				'timeout' => 40,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'ai_request_failed',
				$response->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $response_body['usage']['feature'] ) ) {
			$usage     = $response_body['usage'];
			$remaining = isset( $usage['remaining'] ) ? (int) $usage['remaining'] : null;
			Helper::update_feature_usage( sanitize_key( $usage['feature'] ), $usage['used'] ?? 0, $remaining );
		}

		// Check if the response contains an error from the AI service.
		if ( is_array( $response_body ) && isset( $response_body['error'] ) ) {
			$error_message = isset( $response_body['message'] ) ? $response_body['message'] : $response_body['error'];
			$error_code    = isset( $response_body['err_key'] ) ? $response_body['err_key'] : 'ai_service_error';
			$status_code   = isset( $response_body['statusCode'] ) ? (int) $response_body['statusCode'] : 500;

			return new \WP_Error(
				$error_code,
				$error_message,
				[ 'status' => $status_code ]
			);
		}

		if ( $endpoint === 'suggest_link_opportunities' ) {
			$result = ! isset( $response_body['results'][0] ) || ! is_string( $response_body['results'][0] ) ? [] : json_decode( $response_body['results'][0], true );
			// Return WP_Error if result is itself a WP_Error.
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return $result;
		}

		$ids = $this->parse_ai_ids_from_response( $response_body );
		// Return WP_Error if parse returned a WP_Error.
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		if ( empty( $ids ) ) {
			return [];
		}

		return $endpoint === 'suggest_links' ? $this->map_ids_to_items( $ids, $limit, $prefetched_posts ) : $ids;
	}

	/**
	 * Parse AI service response and return array of IDs.
	 *
	 * @param array|null $response_body Decoded response body.
	 * @return int[]|\WP_Error
	 */
	private function parse_ai_ids_from_response( $response_body ) {
		if ( ! is_array( $response_body ) ) {
			return [];
		}

		if ( ! empty( $response_body['results'][0] ) ) {
			$parsed = json_decode( $response_body['results'][0], true );
			return is_array( $parsed ) ? array_map( 'absint', $parsed ) : [];
		}
		// If API returns raw array of IDs.
		return array_map( 'absint', array_values( $response_body ) );
	}

	/**
	 * Map IDs to payload items with title, url and type.
	 * Prefetched posts instead of calling get_the_title, get_permalink, get_post_type in a loop.
	 *
	 * @param int[] $ids              Post IDs.
	 * @param int   $limit            Max results.
	 * @param array $prefetched_posts Optional prefetched posts indexed by ID.
	 * @return array[]
	 */
	private function map_ids_to_items( $ids, $limit, $prefetched_posts = [] ) {
		$ids = array_slice( $ids, 0, $limit );

		// If no prefetched posts, batch fetch them.
		if ( empty( $prefetched_posts ) ) {
			$prefetched_posts = Batch_Helper::batch_fetch_posts(
				$ids,
				[ 'ID', 'post_title', 'post_type', 'post_name' ]
			);
		}

		$data = [];
		foreach ( $ids as $pid ) {
			$pid  = absint( $pid );
			$post = isset( $prefetched_posts[ $pid ] ) ? $prefetched_posts[ $pid ] : null;

			$data[] = [
				'id'    => $pid,
				'title' => $post ? $post->post_title : get_the_title( $pid ),
				'url'   => $post ? get_permalink( $post ) : get_permalink( $pid ),
				'type'  => $post ? $post->post_type : get_post_type( $pid ),
			];
		}
		return $data;
	}

	/**
	 * Recursively sanitize mixed value (arrays/strings/numbers) coming from requests.
	 *
	 * - Arrays: sanitize each value recursively
	 * - Strings: unslash and allow safe HTML via wp_kses_post
	 * - Other scalars: returned as-is
	 *
	 * @param mixed $value Input value.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_recursive( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ $this, 'sanitize_recursive' ], $value );
		}
		return is_string( $value ) ? wp_kses_post( wp_unslash( $value ) ) : $value;
	}
}
