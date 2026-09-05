<?php //phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- This filename format is intentionally used to match the plugin version.
/**
 * The Updates routine for version 3.0.114
 *
 * @since      3.0.114
 * @package    RankMathPro
 * @subpackage RankMathPro\Updates
 * @author     Rank Math <support@rankmath.com>
 */

defined( 'ABSPATH' ) || exit;

use RankMath\Helper;

/**
 * Enable Marketplace module by default for existing installs.
 */
function rank_math_pro_3_0_114_enable_marketplace_module() {
	Helper::update_modules( [ 'marketplace' => 'on' ] );
}

rank_math_pro_3_0_114_enable_marketplace_module();
