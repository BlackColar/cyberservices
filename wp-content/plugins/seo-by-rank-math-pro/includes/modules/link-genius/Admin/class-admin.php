<?php
/**
 * The Link Genius module editor assets.
 *
 * @since      1.0.71
 * @package    RankMath
 * @subpackage RankMath
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Admin;

use RankMath\Helper;
use RankMath\Helpers\Arr;
use RankMath\Helpers\Param;
use RankMath\Admin\Admin_Helper;
use RankMathPro\Admin\Admin_Helper as PRO_Admin_Helper;
use RankMath\Traits\Hooker;
use RankMathPro\Link_Genius\Services\Utils;
use RankMathPro\Link_Genius\Background\Export_Processor;
use RankMathPro\Link_Genius\Background\Regenerate_Links;
use RankMathPro\Link_Genius\Features\BulkUpdate\Preview_Processor;
use RankMathPro\Link_Genius\Features\BulkUpdate\Processor;
use RankMathPro\Link_Genius\Features\KeywordMaps\Keyword_Map_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Admin class.
 */
class Admin {

	use Hooker;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		$this->filter( 'rank_math/admin_pages', 'add_links_page' );
		$this->filter( 'rank_math/settings/general', 'add_settings' );
		$this->action( 'rank_math/admin/editor_scripts', 'enqueue' );

		$this->action( 'rank_math/links/admin_page_registered', 'override_links_page_assets' );
	}

	/**
	 * Add Link Genius page to Rank Math admin menu.
	 *
	 * @param array $pages Existing pages.
	 *
	 * @return array
	 */
	public function add_links_page( $pages ) {
		$pages[] = 'rank-math_page_rank-math-links-page';
		return $pages;
	}

	/**
	 * Override the Free plugin's Links page assets with PRO's full React app.
	 */
	public function override_links_page_assets() {
		if ( Param::get( 'page' ) !== 'rank-math-links-page' ) {
			return;
		}

		// Dequeue the Free plugin's basic React bundle.
		wp_dequeue_script( 'rank-math-links-page' );

		wp_enqueue_script(
			'rank-math-links-page',
			RANK_MATH_PRO_URL . 'includes/modules/link-genius/assets/js/links-page.js',
			[ 'lodash', 'wp-components', 'wp-element', 'rank-math-components' ],
			rank_math_pro()->version,
			true
		);

		Helper::add_json(
			'linkGenius',
			[
				'exportLimit' => Export_Processor::get_export_limit(),
			]
		);
	}

	/**
	 * Add module settings in the General Settings panel.
	 *
	 * @param  array $tabs Array of option panel tabs.
	 * @return array
	 */
	public function add_settings( $tabs ) {
		Arr::insert(
			$tabs,
			[
				'link-genius' => [
					'icon'  => 'rm-icon rm-icon-podcast',
					'title' => esc_html__( 'Link Genius', 'rank-math-pro' ),
					'json'  => [
						'linkGeniusExcludeTerms' => $this->get_link_genius_exclude_terms(),
					],
				],
			],
			12
		);

		return $tabs;
	}

	/**
	 * Enqueue scripts for the editor screens.
	 */
	public function enqueue() {
		if ( ! Admin_Helper::is_post_edit() ) {
			return;
		}

		wp_enqueue_script(
			'rank-math-link-genius-editor',
			RANK_MATH_PRO_URL . 'includes/modules/link-genius/assets/js/editor.js',
			[
				'rank-math-pro-editor',
				'jquery',
				'wp-hooks',
				'wp-data',
				'wp-components',
				'wp-element',
				'wp-i18n',
				'wp-plugins',
				'wp-api-fetch',
			],
			rank_math_pro()->version,
			true
		);

		// Provide initial data from postmeta so the panel can render on load (no HTML here).
		$post_id = get_the_ID();
		if ( $post_id ) {
			Helper::add_json(
				'linkGenius',
				[
					'relatedItems'        => Utils::map_post_ids_to_items( get_post_meta( $post_id, 'rank_math_related_posts', true ) ),
					'aiItems'             => get_post_meta( $post_id, 'rank_math_ai_link_suggestions', true ),
					'autoLinkingDisabled' => '1' === get_post_meta( $post_id, 'rank_math_auto_linking_disabled', true ),
				]
			);
		}
	}

	/**
	 * Get exclude terms for Link Genius settings.
	 *
	 * @return array
	 */
	private function get_link_genius_exclude_terms() {
		// Get excluded post types from settings.
		$excluded_post_types = Helper::get_settings( 'general.keyword_maps_excluded_post_types', [] );

		// Pass excluded post types to helper, which will show taxonomy terms for NON-excluded post types.
		return PRO_Admin_Helper::get_exclude_terms_for_settings( 'keyword_maps', $excluded_post_types, 'general' );
	}
}
