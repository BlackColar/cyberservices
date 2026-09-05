<?php
/**
 * Marketplace module.
 *
 * @since      3.0.114
 * @package    RankMathPro
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Marketplace;

use RankMath\Traits\Hooker;
use RankMath\Admin\Admin_Helper;
use RankMathPro\Dependencies\Groupone\Marketplace\Marketplace as WP_Marketplace;

defined( 'ABSPATH' ) || exit;

/**
 * Marketplace class.
 */
class Marketplace {

	use Hooker;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		$this->action( 'init', 'init_marketplace' );
	}

	/**
	 * Initialize the WP Marketplace integration.
	 */
	public function init_marketplace() {
		if ( ! class_exists( WP_Marketplace::class ) ) {
			return;
		}

		if ( is_admin() && ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		$registered = Admin_Helper::get_registration_data();
		if ( ! $registered || empty( $registered['username'] ) || empty( $registered['api_key'] ) ) {
			return;
		}

		WP_Marketplace::run(
			[
				'parent_menu_slug'        => 'rank-math',
				'page_title'              => __( 'Rank Math Marketplace', 'rank-math-pro' ),
				'menu_title'              => __( 'Marketplace', 'rank-math-pro' ),
				'menu_slug'               => 'rank-math-marketplace',
				'api_url'                 => 'https://www.rankmath.com/wp-json/onemarket/v1/auth',
				'brand'                   => 'rankmath',
				'addons_menu_slug'        => 'marketplace-products',
				'payload'                 => [
					'username' => $registered['username'],
					'api_key'  => $registered['api_key'],
					'site_url' => $registered['site_url'],
					'action'   => 'wp-marketplace-product-list',
					'locale'   => get_user_locale(),
					'php'      => PHP_VERSION,
					'wp'       => get_bloginfo( 'version' ),
				],
				'assets_path'             => RANK_MATH_PRO_PATH . 'includes/vendor/Dependencies/Groupone/Marketplace/',
				'insert_menu_before_slug' => 'rank-math-status',
				'data_consent_status'     => rank_math()->tracking->is_opted_in(),
				'mixp_distinct_id'        => $this->get_mixpanel_distinct_id( $registered ),
				'mixp_props'              => [ 'is_sandbox' => false ],
			]
		);
	}

	/**
	 * Get the Mixpanel distinct ID for the current WP user.
	 *
	 * @param array $registered Registration data from Admin_Helper::get_registration_data().
	 * @return string Current user's email hashed with sha224, or empty string if unavailable.
	 */
	private function get_mixpanel_distinct_id( array $registered ): string {
		if ( ! empty( $registered['email'] ) ) {
			return hash( 'sha224', $registered['email'] );
		}

		$user = wp_get_current_user();
		return ! empty( $user->user_email ) ? hash( 'sha224', $user->user_email ) : '';
	}
}
