<?php
/**
 * Elementor – Link Genius integration.
 *
 * Hooks into the generic Link Genius page-builder sync filters/actions so that
 * keyword-map link insertions (and rollbacks) are reflected in Elementor's
 * _elementor_data postmeta, not just in post_content.
 *
 * @since      3.0.0
 * @package    RankMathPro
 * @subpackage RankMathPro\Elementor
 */

namespace RankMathPro\Elementor;

use RankMath\Helpers\Param;
use RankMath\Traits\Hooker;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor_Link_Genius class.
 */
class Elementor_Link_Genius {

	use Hooker;

	/**
	 * Constructor — register hooks.
	 */
	public function __construct() {
		$this->filter( 'rank_math/link_genius/is_manual_ajax_save', 'is_manual_ajax_save' );
		$this->filter( 'rank_math/link_genius/page_builder_old_content', 'get_old_content', 10, 2 );
		$this->action( 'rank_math/link_genius/sync_page_builder_content', 'sync_content', 10, 5 );
		$this->filter( 'rank_math/link_genius/keyword_map_post_content', 'get_keyword_map_content', 10, 2 );
		$this->filter( 'rank_math/link_genius/keyword_map_process_post', 'process_keyword_map_post', 10, 6 );
		$this->filter( 'rank_math/link_genius/keyword_map_restore_post', 'restore_keyword_map_post', 10, 3 );
	}

	/**
	 * Detect an Elementor builder save from an admin-ajax.php request.
	 *
	 * Hooked to: rank_math/link_genius/is_manual_ajax_save
	 *
	 * Elementor posts to admin-ajax.php with action=elementor_ajax and an
	 * `actions` JSON payload that includes a save_builder entry when the user
	 * clicks Publish/Update inside the builder.
	 *
	 * @param bool $is_manual Whether the current AJAX request is a manual save.
	 * @return bool
	 */
	public function is_manual_ajax_save( $is_manual ) {
		if ( $is_manual ) {
			return true;
		}

		return 'elementor_ajax' === Param::post( 'action', '' )
			&& false !== strpos( wp_unslash( Param::post( 'actions', '' ) ), 'save_builder' );
	}

	/**
	 * Return the current post_content for Elementor posts to opt them into syncing.
	 *
	 * Hooked to: rank_math/link_genius/page_builder_old_content
	 *
	 * @param false|string $old_content  Current filter value (false = not a page-builder post).
	 * @param int          $post_id      Post being evaluated.
	 * @return false|string              Current post_content, or false to skip.
	 */
	public function get_old_content( $old_content, $post_id ) {
		$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return $old_content;
		}

		return get_post_field( 'post_content', $post_id );
	}

	/**
	 * Apply link additions/removals to the Elementor element JSON.
	 *
	 * Hooked to: rank_math/link_genius/sync_page_builder_content
	 *
	 * Uses Document::update_meta() — the public write API that works in all
	 * contexts (including cron/background) without Document::save()'s
	 * permission check.
	 *
	 * @param int    $post_id       Updated post ID.
	 * @param string $old_content   post_content before the update (unused here; diff already computed).
	 * @param string $new_content   post_content after the update (unused here; diff already computed).
	 * @param array  $added_links   Links added — each entry: 'url', 'text', 'link'.
	 * @param array  $removed_links Links removed — each entry: 'url', 'text', 'link'.
	 */
	public function sync_content( $post_id, $old_content, $new_content, $added_links, $removed_links ) {
		if ( empty( $added_links ) && empty( $removed_links ) ) {
			return;
		}

		$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		if ( ! $document ) {
			return;
		}

		$data = $document->get_elements_data();
		if ( empty( $data ) || ! is_array( $data ) ) {
			return;
		}

		$updated = wp_json_encode( $this->apply_link_changes( $data, $added_links, $removed_links ) );
		if ( false === $updated ) {
			return; // JSON encoding failed — abort to avoid data loss.
		}

		$document->update_meta(
			\Elementor\Core\Base\Document::ELEMENTOR_DATA_META_KEY,
			wp_slash( $updated )
		);
	}

	/**
	 * Recursively apply link additions and removals to Elementor element settings.
	 *
	 * Targets the text-bearing settings fields where body copy lives:
	 * editor, title, description, text, html.
	 *
	 * Removals are applied first (via remove_links()), then additions (via
	 * add_links()). Callers can therefore pass the same list as both
	 * $added_links and $removed_links to achieve an idempotent "strip then
	 * re-insert" operation — e.g. for keyword-map execution where the keyword
	 * may already be wrapped in an <a> tag from a prior auto-linking pass.
	 *
	 * @param array $elements      Elementor elements array (decoded JSON).
	 * @param array $added_links   Links to insert  (each: 'url', 'text', 'link').
	 * @param array $removed_links Links to remove  (each: 'url', 'text', 'link').
	 * @return array Updated elements.
	 */
	private function apply_link_changes( array $elements, array $added_links, array $removed_links ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = $this->apply_link_changes_to_element_settings( $element['settings'], $added_links, $removed_links );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = $this->apply_link_changes( $element['elements'], $added_links, $removed_links );
			}
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Apply link additions and removals to the text fields of a single element's settings.
	 *
	 * @param array $settings       Element settings array.
	 * @param array $added_links    Links to insert  (each: 'url', 'text', 'link').
	 * @param array $removed_links  Links to remove  (each: 'url', 'text', 'link').
	 * @return array Updated settings.
	 */
	private function apply_link_changes_to_element_settings( array $settings, array $added_links, array $removed_links ) {
		$text_fields = [ 'editor', 'title', 'description', 'text', 'html' ];

		foreach ( $text_fields as $field ) {
			if ( empty( $settings[ $field ] ) || ! is_string( $settings[ $field ] ) ) {
				continue;
			}

			$value              = $this->remove_links( $settings[ $field ], $removed_links );
			$settings[ $field ] = $this->add_links( $value, $added_links );
		}

		return $settings;
	}

	/**
	 * Remove links from a field value by replacing each full <a>…</a> with its plain text.
	 *
	 * @param string $value          Field content.
	 * @param array  $removed_links  Links to remove (each: 'url', 'text', 'link').
	 * @return string
	 */
	private function remove_links( $value, array $removed_links ) {
		foreach ( $removed_links as $link ) {
			$value = str_replace( $link['link'], $link['text'], $value );
		}

		return $value;
	}

	// -------------------------------------------------------------------------
	// Keyword Mapping integration
	// -------------------------------------------------------------------------

	/**
	 * Return the flat text extracted from _elementor_data for keyword-mapping
	 * content analysis and preview generation.
	 *
	 * Hooked to: rank_math/link_genius/keyword_map_post_content
	 *
	 * Elementor stores the actual post content as JSON in _elementor_data, while
	 * post_content is often empty or contains only a placeholder. This filter
	 * supplies the real text so keyword matches are found.
	 *
	 * @param string $content  Current filter value (post_content).
	 * @param int    $post_id  Post being evaluated.
	 * @return string          Flat text from _elementor_data, or original value.
	 */
	public function get_keyword_map_content( $content, $post_id ) {
		$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return $content;
		}

		$elementor_text = $this->get_elementor_text_content( $post_id );
		return '' !== $elementor_text ? $elementor_text : $content;
	}

	/**
	 * Apply keyword-map link additions directly to _elementor_data.
	 *
	 * Hooked to: rank_math/link_genius/keyword_map_process_post
	 *
	 * Bypasses the normal position-based post_content rewrite path — Elementor
	 * posts use text-based replacement inside widget settings fields instead.
	 * Also creates the rollback snapshot, storing the original _elementor_data
	 * so the restore handler can revert it directly.
	 *
	 * @param false|array $handled      Already-handled result, or false.
	 * @param int         $post_id      Post being processed.
	 * @param array       $post_changes Changes from the preview (each has 'before', 'after', 'position').
	 * @param string      $batch_id     Current batch identifier.
	 * @param string      $target_url   Keyword map target URL.
	 * @param int         $max_per_post Maximum links to add per post.
	 * @return false|array              false = not handled; array with 'links_added' when handled.
	 *
	 * Execution is idempotent: any existing <a> tag wrapping the same anchor
	 * text / target URL (e.g. from a prior auto-linking pass) is stripped
	 * before the link is re-inserted, so the keyword is never left unlinked
	 * just because it was previously linked.
	 */
	public function process_keyword_map_post( $handled, $post_id, $post_changes, $batch_id, $target_url, $max_per_post ) {
		global $wpdb;

		if ( false !== $handled ) {
			return $handled;
		}

		$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return false;
		}

		// Snapshot original _elementor_data before any modification.
		$original_elementor_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $original_elementor_data ) || ! is_string( $original_elementor_data ) ) {
			return false;
		}

		// Sort changes by position descending and limit to max_per_post.
		usort(
			$post_changes,
			function ( $a, $b ) {
				return ( $b['position'] ?? 0 ) - ( $a['position'] ?? 0 );
			}
		);
		$changes_to_apply = array_slice( $post_changes, 0, $max_per_post );

		// Build added_links list for the text-based Elementor sync, deduplicating
		// by anchor text to avoid linking the same word twice per execution.
		$added_links    = [];
		$change_details = [];
		$used_texts     = [];

		foreach ( $changes_to_apply as $change ) {
			$anchor_text = $change['before']['anchor_text'] ?? '';
			$link_url    = $change['after']['url'] ?? $target_url;

			if ( empty( $anchor_text ) ) {
				continue;
			}

			$lower_text = strtolower( $anchor_text );
			if ( isset( $used_texts[ $lower_text ] ) ) {
				continue; // Skip duplicate anchor texts.
			}
			$used_texts[ $lower_text ] = true;

			$link          = sprintf( '<a href="%s">%s</a>', esc_url( $link_url ), esc_html( $anchor_text ) );
			$added_links[] = [
				'url'  => $link_url,
				'text' => $anchor_text,
				'link' => $link,
			];

			$change_details[] = [
				'link_id'    => $change['link_id'] ?? 0,
				'old_url'    => null,
				'new_url'    => $link_url,
				'old_anchor' => null,
				'new_anchor' => $anchor_text,
			];
		}

		if ( empty( $added_links ) ) {
			return [ 'links_added' => 0 ];
		}

		$data = $document->get_elements_data();
		if ( empty( $data ) || ! is_array( $data ) ) {
			return [ 'links_added' => 0 ];
		}

		// Strip any existing links for the same anchor/URL pairs before re-inserting.
		// This makes execution idempotent: if a keyword was previously wrapped by
		// auto-linking (via sync_content), it will still be correctly linked here
		// rather than silently skipped by add_links()'s URL-presence check.
		$updated_json = wp_json_encode( $this->apply_link_changes( $data, $added_links, $added_links ) );
		if ( false === $updated_json ) {
			return [ 'links_added' => 0 ];
		}

		$document->update_meta(
			\Elementor\Core\Base\Document::ELEMENTOR_DATA_META_KEY,
			wp_slash( $updated_json )
		);

		// Create snapshot — stores original _elementor_data JSON so the restore
		// handler can write it back directly on rollback.
		$wpdb->insert(
			$wpdb->prefix . 'rank_math_link_genius_snapshots',
			[
				'batch_id'         => $batch_id,
				'post_id'          => $post_id,
				'original_content' => $original_elementor_data,
				'link_changes'     => wp_json_encode( $change_details ),
			],
			[ '%s', '%d', '%s', '%s' ]
		);

		return [ 'links_added' => count( $added_links ) ];
	}

	/**
	 * Restore _elementor_data from a snapshot on rollback.
	 *
	 * Hooked to: rank_math/link_genius/keyword_map_restore_post
	 *
	 * The snapshot's original_content was stored as the raw _elementor_data JSON
	 * by process_keyword_map_post(). Detect this by checking that the post is
	 * built with Elementor and that the content is valid JSON.
	 *
	 * @param bool   $handled          Already handled.
	 * @param int    $post_id          Post being restored.
	 * @param string $original_content Content stored in the rollback snapshot.
	 * @return bool                    True when handled, false to fall back to normal restore.
	 */
	public function restore_keyword_map_post( $handled, $post_id, $original_content ) {
		if ( $handled ) {
			return true;
		}

		$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return false;
		}

		// Confirm the snapshot content is Elementor JSON (array, not an object or scalar).
		$decoded = json_decode( $original_content, true );
		if ( ! is_array( $decoded ) ) {
			return false; // Not Elementor JSON — let normal restore handle it.
		}

		$document->update_meta(
			\Elementor\Core\Base\Document::ELEMENTOR_DATA_META_KEY,
			wp_slash( $original_content )
		);

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract all human-readable text from a post's _elementor_data.
	 *
	 * Returns a string that concatenates the content of every text-bearing
	 * widget setting field (editor, title, description, text, html), separated
	 * by double newlines so keyword positions are stable within each field.
	 *
	 * @param int $post_id Post ID.
	 * @return string      Flat text, or empty string when not available.
	 */
	private function get_elementor_text_content( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			return '';
		}

		$data = json_decode( $raw, true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return '';
		}

		return $this->extract_text_from_elements( $data );
	}

	/**
	 * Recursively collect text from Elementor element settings.
	 *
	 * Targets the same fields that apply_link_changes_to_element_settings()
	 * modifies, ensuring keyword-search and link-insertion see identical content.
	 *
	 * Existing <a> tags are stripped (their text content is preserved) so that
	 * Content_Analyzer does not count already-linked keywords as "existing links"
	 * and incorrectly block new keyword-map opportunities.
	 *
	 * On execution, process_keyword_map_post() removes existing target links
	 * before re-inserting them (strip-then-add), so presenting stripped text
	 * here does not cause phantom preview candidates: any keyword shown in
	 * preview will be correctly applied during execution regardless of whether
	 * it was previously auto-linked.
	 *
	 * @param array $elements Elementor elements array (decoded JSON).
	 * @return string         Concatenated text from all text-bearing fields.
	 */
	private function extract_text_from_elements( array $elements ) {
		$text_fields = [ 'editor', 'title', 'description', 'text', 'html' ];
		$parts       = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				foreach ( $text_fields as $field ) {
					if ( ! empty( $element['settings'][ $field ] ) && is_string( $element['settings'][ $field ] ) ) {
						// Strip <a> tags while preserving anchor text so that existing
						// links do not interfere with keyword-position detection.
						$field_text = preg_replace( '/<a\b[^>]*>(.*?)<\/a>/is', '$1', $element['settings'][ $field ] );
						$parts[]    = $field_text;
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$sub = $this->extract_text_from_elements( $element['elements'] );
				if ( '' !== $sub ) {
					$parts[] = $sub;
				}
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Add links to a field value.
	 *
	 * Skips entries where the anchor text is empty or the URL is already present.
	 * Uses (*SKIP)(*FAIL) to avoid wrapping text already inside an <a> tag.
	 *
	 * @param string $value        Field content.
	 * @param array  $added_links  Links to insert (each: 'url', 'text', 'link').
	 * @return string
	 */
	private function add_links( $value, array $added_links ) {
		foreach ( $added_links as $link ) {
			if ( empty( $link['text'] ) ) {
				continue;
			}
			if ( false !== strpos( $value, 'href="' . $link['url'] . '"' ) ) {
				continue;
			}
			$pattern  = '/(?:<a\b[^>]*>.*?<\/a>)(*SKIP)(*FAIL)|' . preg_quote( $link['text'], '/' ) . '/is';
			$replaced = preg_replace( $pattern, $link['link'], $value, 1 );
			if ( null !== $replaced ) {
				$value = $replaced;
			}
		}

		return $value;
	}
}
