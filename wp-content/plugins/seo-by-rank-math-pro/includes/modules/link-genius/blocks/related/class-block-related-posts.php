<?php
/**
 * Related Posts Block for Content AI.
 *
 * @since 1.0.0
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Blocks\Related;

use WP_Block_Type_Registry;
use RankMath\Traits\Hooker;
use RankMathPro\Link_Genius\Services\Related_Posts;

defined( 'ABSPATH' ) || exit;

/**
 * Block_Related_Posts class.
 */
class Block_Related_Posts {
	use Hooker;

	/**
	 * Block type name.
	 *
	 * @var string
	 */
	private $block_type = 'rank-math/related-posts';

	/**
	 * The Constructor.
	 */
	public function __construct() {
		$this->action( 'init', 'register' );
	}

	/**
	 * Register block on init.
	 */
	public function register() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( $this->block_type ) ) {
			return;
		}

		register_block_type(
			RANK_MATH_PRO_PATH . 'includes/modules/link-genius/blocks/related/assets/src/block.json',
			[
				'render_callback' => [ $this, 'render' ],
			]
		);
	}

	/**
	 * Render the block output.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block inner content.
	 * @param WP_Block $block      Block instance.
	 *
	 * @return string
	 */
	public function render( $attributes, $content, $block ) {
		$post_id = isset( $block->context['postId'] ) ? $block->context['postId'] : get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$service = new Related_Posts();
		$limit   = isset( $attributes['number'] ) ? max( 1, absint( $attributes['number'] ) ) : 3;
		$items   = array_filter( $service->get_related_posts( $post_id, $limit ) );
		$items   = array_slice( $items, 0, $limit );

		// If there is no stored data yet (fresh post), compute once during block preview in editor.
		// Detect editor SSR via REST request context=edit since is_admin() is false for block renderer.
		$in_editor = ( defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_REQUEST['context'] ) && 'edit' === $_REQUEST['context'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $items ) && $in_editor && ! metadata_exists( 'post', $post_id, 'rank_math_related_posts' ) ) {
			$items = array_filter( $service->get_related_posts( $post_id, $limit, [ 'force_refresh' => true ] ) );
			$items = array_slice( $items, 0, $limit );
		}

		// Also detect if we're in admin/editor for link rendering.
		$is_editor = $in_editor || is_admin();

		if ( empty( $items ) ) {
			return '';
		}

		$show_image   = ! empty( $attributes['showImage'] );
		$show_excerpt = ! empty( $attributes['showExcerpt'] );
		$show_date    = ! empty( $attributes['showDate'] );
		$show_terms   = ! empty( $attributes['showTerms'] );
		$image_size   = ! empty( $attributes['imageSize'] ) ? sanitize_key( $attributes['imageSize'] ) : 'thumbnail';
		$layout       = 'grid-vertical';
		if ( ! empty( $attributes['className'] ) ) {
			if ( false !== strpos( $attributes['className'], 'is-style-grid-horizontal' ) ) {
				$layout = 'grid-horizontal';
			} elseif ( false !== strpos( $attributes['className'], 'is-style-list-vertical' ) ) {
				$layout = 'list-vertical';
			} elseif ( false !== strpos( $attributes['className'], 'is-style-list-horizontal' ) ) {
				$layout = 'list-horizontal';
			}
		}
		$classes = ! empty( $attributes['className'] ) ? sanitize_html_class( $attributes['className'] ) : '';

		// Use get_block_wrapper_attributes() to get WordPress block support classes.
		$wrapper_attributes = get_block_wrapper_attributes(
			[
				'class'       => 'wp-block-rank-math-related-posts rank-math-related-posts rank-math-related-' . esc_attr( $layout ) . ' ' . esc_attr( $classes ),
				'data-layout' => esc_attr( $layout ),
			]
		);

		$out   = [];
		$out[] = '<div ' . $wrapper_attributes . '>';
		if ( ! empty( $attributes['title'] ) ) {
			$out[] = '<h2 class="rank-math-related-heading">' . esc_html( $attributes['title'] ) . '</h2>';
		}

		$out[] = $service->render_items(
			$items,
			[
				'show_image'   => $show_image,
				'image_size'   => $image_size,
				'show_excerpt' => $show_excerpt,
				'show_date'    => $show_date,
				'show_terms'   => $show_terms,
				'layout'       => $layout,
				'is_editor'    => $is_editor,
			]
		);

		if ( ! empty( $attributes['buttonText'] ) && ! empty( $attributes['buttonUrl'] ) ) {
			$out[] = '<div class="rank-math-related-more"><a class="rank-math-related-button" href="' . esc_url( $attributes['buttonUrl'] ) . '">' . esc_html( $attributes['buttonText'] ) . '</a></div>';
		}
		$out[] = '</div>';

		return apply_filters( 'rank_math/content_ai/related_posts/block_output', implode( '', $out ), $items, $attributes );
	}
}
