<?php
/**
 * WooCommerce module.
 *
 * @since      1.0
 * @package    RankMathPro
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro;

use RankMath\Helper;
use RankMath\Helpers\Param;
use RankMath\Traits\Hooker;

defined( 'ABSPATH' ) || exit;

/**
 * Admin class.
 *
 * @codeCoverageIgnore
 */
class Admin {

	use Hooker;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->filter( 'rank_math/metabox/post/values', 'updated_localized_data', 99, 2 );
		$this->filter( 'rank_math/admin/robots', 'update_rank_math_product_robots', 99, 2 );

		if ( ! WooCommerce::add_gtin_field() ) {
			return;
		}
		$this->action( 'woocommerce_product_options_sku', 'add_gtin_field' );
		$this->action( 'woocommerce_product_after_variable_attributes', 'add_variation_gtin_field', 10, 3 );
		$this->action( 'woocommerce_admin_process_product_object', 'save_gtin_data' );
		$this->action( 'woocommerce_save_product_variation', 'save_variation_gtin_data', 10, 2 );
	}

	/**
	 * Change robots for Hidden Products according to settings.
	 *
	 * @param array  $values Metabox values.
	 * @param Screen $screen The current screen.
	 *
	 * @return mixed
	 */
	public function updated_localized_data( $values, $screen ) {
		$object_id = $screen->get_object_id();

		// Early bail if current post type is not Product or the Hidden product option is disabled.
		if ( 'product' !== get_post_type( $object_id ) || ! Helper::get_settings( 'general.noindex_hidden_products' ) ) {
			return $values;
		}

		$product = wc_get_product( $object_id );

		// Early bail.
		if ( 'hidden' !== $product->get_catalog_visibility() ) {
			return $values;
		}

		if ( isset( $values['assessor']['serpData']['robots']['index'] ) ) {
			unset( $values['assessor']['serpData']['robots']['index'] );
		}
		$values['assessor']['serpData']['robots']['noindex'] = 1;

		return $values;
	}

	/**
	 * Change robots for Hidden Products according to settings for post columns.
	 *
	 * @param mixed $robots  The rank_math_robots from the FORM post.
	 * @param int   $post_id The post id.
	 * @return mixed|string[]
	 */
	public function update_rank_math_product_robots( $robots, $post_id ) {
		if ( 'product' !== get_post_type( $post_id ) || ! Helper::get_settings( 'general.noindex_hidden_products' ) ) {
			return $robots;
		}

		$product = \wc_get_product( $post_id );
		if ( $product && 'hidden' !== $product->get_catalog_visibility() ) {
			return $robots;
		}

		return [ 'noindex' ];
	}

	/**
	 * Add GTIN field in Product Metabox.
	 */
	public function add_gtin_field() {
		$gtin  = Helper::get_settings( 'general.gtin', 'gtin8' );
		$label = 'isbn' === $gtin ? esc_html__( 'ISBN', 'rank-math-pro' ) : ( 'mpn' === $gtin ? esc_html__( 'MPN', 'rank-math-pro' ) : esc_html__( 'GTIN', 'rank-math-pro' ) );
		?>
		<div class="options_group">
			<?php
			woocommerce_wp_text_input(
				[
					'id'          => '_rank_math_gtin_code',
					'label'       => $label,
					// Translators: Global Identifier name.
					'description' => sprintf( esc_html__( '%s added by Rank Math SEO). This field overrides WooCommerce GTIN data used in the Product Schema.', 'rank-math-pro' ), $label ),
				]
			);
			?>
		</div>
		<?php
	}

	/**
	 * Add GTIN field in Variable tab.
	 *
	 * @param int    $loop           Current variation index.
	 * @param array  $variation_data Current variation data.
	 * @param object $variation      Current variation object.
	 */
	public function add_variation_gtin_field( $loop, $variation_data, $variation ) {
		$gtin             = Helper::get_settings( 'general.gtin', 'gtin8' );
		$label            = 'isbn' === $gtin ? esc_html__( 'ISBN', 'rank-math-pro' ) : ( 'mpn' === $gtin ? esc_html__( 'MPN', 'rank-math-pro' ) : esc_html__( 'GTIN', 'rank-math-pro' ) );
		$variation_object = wc_get_product( $variation->ID );
		$value            = $variation_object->get_meta( '_rank_math_gtin_code' );

		woocommerce_wp_text_input(
			[
				'id'            => "_rank_math_gtin_code_variable{$loop}",
				'name'          => "_rank_math_gtin_code_variable[{$loop}]",
				'value'         => $value,
				'label'         => $label,
				// Translators: Global Identifier name.
				'description'   => sprintf( esc_html__( '%s added by Rank Math SEO). This field overrides WooCommerce GTIN data used in the Product Schema.', 'rank-math-pro' ), $label ),
				'wrapper_class' => 'form-row widefat',
			]
		);
	}

	/**
	 * Save GTIN code.
	 *
	 * @param WC_PRODUCT $product Product Object.
	 */
	public function save_gtin_data( $product ) {
		if ( ! isset( $_POST['_rank_math_gtin_code'] ) ) {
			return;
		}

		$gtin_code = Param::post( '_rank_math_gtin_code' );
		$product->update_meta_data( '_rank_math_gtin_code', wc_clean( wp_unslash( $gtin_code ) ) );
	}

	/**
	 * Save GTIN code for curent variation.
	 *
	 * @param int $variation_id Current variation ID.
	 * @param int $id           Index of current variation.
	 */
	public function save_variation_gtin_data( $variation_id, $id ) {
		if ( ! isset( $_POST['_rank_math_gtin_code_variable'] ) ) {
			return;
		}

		$gtin_code = isset( $_POST['_rank_math_gtin_code_variable'][ $id ] ) ? sanitize_text_field( $_POST['_rank_math_gtin_code_variable'][ $id ] ) : '';
		if ( empty( $gtin_code ) ) {
			return;
		}

		$variation = wc_get_product( $variation_id );
		$variation->update_meta_data( '_rank_math_gtin_code', wc_clean( wp_unslash( $gtin_code ) ) );
		$variation->save_meta_data();
	}
}
