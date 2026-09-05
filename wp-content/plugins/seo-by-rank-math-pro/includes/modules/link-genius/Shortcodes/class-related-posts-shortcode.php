<?php
/**
 * Related Posts shortcode for Content AI.
 *
 * @since 1.0.0
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Shortcodes;

use RankMath\Traits\Hooker;
use RankMathPro\Link_Genius\Services\Related_Posts;

defined( 'ABSPATH' ) || exit;

/**
 * Related_Posts_Shortcode class.
 */
class Related_Posts_Shortcode {
	use Hooker;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->action( 'init', 'register_shortcode' );
	}

	/**
	 * Register the shortcode.
	 */
	public function register_shortcode() {
		add_shortcode( 'rank_math_related_posts', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the shortcode output.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$atts = shortcode_atts(
			[
				'number'         => 3,
				'layout'         => 'grid-vertical',
				'image'          => 'true',
				'image_size'     => 'thumbnail',
				'excerpt'        => 'true',
				'date'           => 'false',
				'terms'          => 'false',
				'button_text'    => '',
				'button_url'     => '',
				'classes'        => '',
				'force_refresh'  => 'false',
				'min_similarity' => 0,
			],
			$atts,
			'rank_math_related_posts'
		);

		$number  = max( 1, absint( $atts['number'] ) );
		$service = new Related_Posts();
		$items   = $service->get_related_posts(
			$post_id,
			$number
		);

		if ( empty( $items ) ) {
			return '';
		}

		$show_image   = filter_var( $atts['image'], FILTER_VALIDATE_BOOLEAN );
		$show_excerpt = filter_var( $atts['excerpt'], FILTER_VALIDATE_BOOLEAN );
		$show_date    = filter_var( $atts['date'], FILTER_VALIDATE_BOOLEAN );
		$show_terms   = filter_var( $atts['terms'], FILTER_VALIDATE_BOOLEAN );
		$image_size   = sanitize_key( $atts['image_size'] );

		// Support all 4 layout styles.
		$valid_layouts = [ 'grid-vertical', 'grid-horizontal', 'list-vertical', 'list-horizontal' ];
		$layout        = $atts['layout'];

		// Validate layout.
		if ( ! in_array( $layout, $valid_layouts, true ) ) {
			$layout = 'grid-vertical';
		}

		$classes = sanitize_html_class( $atts['classes'] );

		$out   = [];
		$out[] = '<div class="rank-math-related-posts rank-math-related-' . esc_attr( $layout ) . ' ' . esc_attr( $classes ) . '">';
		$out[] = $service->render_items(
			$items,
			[
				'show_image'   => $show_image,
				'image_size'   => $image_size,
				'show_excerpt' => $show_excerpt,
				'show_date'    => $show_date,
				'show_terms'   => $show_terms,
				'layout'       => $layout,
			]
		);
		if ( ! empty( $atts['button_text'] ) && ! empty( $atts['button_url'] ) ) {
			$out[] = '<div class="rank-math-related-more"><a class="rank-math-related-button" href="' . esc_url( $atts['button_url'] ) . '">' . esc_html( $atts['button_text'] ) . '</a></div>';
		}
		$out[] = '</div>';

		return apply_filters( 'rank_math/content_ai/related_posts/shortcode_output', implode( '', $out ), $items, $atts );
	}
}
