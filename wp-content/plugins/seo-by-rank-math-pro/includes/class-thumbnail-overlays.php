<?php
/**
 * Social thumbnail overlays.
 *
 * @since      1.0
 * @package    RankMathPro
 * @subpackage RankMathPro\Admin
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro;

use RankMath\Helper;
use RankMath\Helpers\Str;
use RankMath\Helpers\Param;
use RankMath\Traits\Hooker;

defined( 'ABSPATH' ) || exit;

/**
 * Admin class.
 *
 * @method get_pt_default()
 * @codeCoverageIgnore
 */
class Thumbnail_Overlays {

	use Hooker;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		$this->filter( 'rank_math/social/overlay_images', 'add_custom_overlays' );
		$this->filter( 'rank_math/settings/sanitize_fields', 'sanitize_fields', 10, 3 );
		$this->filter( 'rank_math/social/overlay_image_position', 'apply_overlay_position', 20, 2 );
		$this->filter( 'rank_math/social/overlay_image_positions', 'get_position_margins', 20, 4 );
		$this->filter( 'default_post_metadata', 'get_postmeta_default', 10, 3 );
		$this->filter( 'default_term_metadata', 'get_termmeta_default', 10, 3 );
		$this->filter( 'default_user_metadata', 'get_usermeta_default', 10, 3 );

		$this->action( 'admin_init', 'enqueue', 20 );
	}

	/**
	 * Apply position for custom overlays.
	 *
	 * @param string $position     Original position.
	 * @param string $type         Overlay type.
	 *
	 * @return string New position.
	 */
	public function apply_overlay_position( $position, $type ) {
		$custom_overlays = $this->get_custom_overlays();
		if ( empty( $custom_overlays ) ) {
			return $position;
		}

		foreach ( $custom_overlays as $overlay ) {
			$id = sanitize_title( $overlay['name'], md5( $overlay['name'] ) );
			if ( $id === $type ) {
				return $overlay['position'];
			}
		}

		return $position;
	}

	/**
	 * Calculate margins for new position values.
	 *
	 * @param array    $margins Original margins array.
	 * @param resource $image   GD image resource identifier.
	 * @param resource $stamp   GD image resource identifier.
	 * @param string   $module  PHP module used for the image manipulation ('gd' or 'imagick').
	 *
	 * @return array
	 */
	public function get_position_margins( $margins, $image, $stamp, $module ) {
		$method = 'get_positions_' . $module;
		if ( ! method_exists( $this, $method ) ) {
			return $margins;
		}

		$positions = $this->$method( $margins, $image, $stamp );
		if ( empty( $positions ) ) {
			return $margins;
		}

		$new_margins = [
			'top_left'      => [],
			'top_center'    => [],
			'top_right'     => [],

			'middle_left'   => [],
			'middle_right'  => [],

			'bottom_left'   => [],
			'bottom_center' => [],
			'bottom_right'  => [],
		];

		$new_margins['top_left']['top']  = $positions['top'];
		$new_margins['top_left']['left'] = $positions['left'];

		$new_margins['top_center']['top']  = $positions['top'];
		$new_margins['top_center']['left'] = $positions['center'];

		$new_margins['top_right']['top']  = $positions['top'];
		$new_margins['top_right']['left'] = $positions['right'];

		$new_margins['middle_left']['top']  = $positions['middle'];
		$new_margins['middle_left']['left'] = $positions['left'];

		$new_margins['middle_right']['top']  = $positions['middle'];
		$new_margins['middle_right']['left'] = $positions['right'];

		$new_margins['bottom_left']['top']  = $positions['bottom'];
		$new_margins['bottom_left']['left'] = $positions['left'];

		$new_margins['bottom_center']['top']  = $positions['bottom'];
		$new_margins['bottom_center']['left'] = $positions['center'];

		$new_margins['bottom_right']['top']  = $positions['bottom'];
		$new_margins['bottom_right']['left'] = $positions['right'];

		return $margins + $new_margins;
	}

	/**
	 * Sanitize the Image SEO options.
	 *
	 * @param string $sanitized_value The sanitized value.
	 * @param string $value           Original field value.
	 * @param string $field_id        Field ID.
	 *
	 * @return string
	 */
	public function sanitize_fields( $sanitized_value, $value, $field_id ) {
		if ( $field_id !== 'custom_image_overlays' || ! is_array( $value ) ) {
			return $sanitized_value;
		}

		foreach ( $value as $key => $overlay ) {
			if ( empty( $overlay['image'] ) ) {
				unset( $value[ $key ] );
			} elseif ( empty( $overlay['name'] ) ) {
				Helper::add_notification( esc_html__( 'A Custom Watermark item could not be saved because the name field is empty.', 'rank-math-pro' ), [ 'type' => 'error' ] );
				unset( $value[ $key ] );
			} elseif ( empty( $overlay['position'] ) ) {
				$value[ $key ]['position'] = 'bottom_right';
			}
		}

		return $value;
	}

	/**
	 * Get margins for GD image manipulation.
	 *
	 * @param array    $margins Original margins array.
	 * @param resource $image   GD image resource identifier.
	 * @param resource $stamp   GD image resource identifier.
	 *
	 * @return array
	 */
	private function get_positions_gd( $margins, $image, $stamp ) {
		$left   = 0;
		$top    = 0;
		$right  = abs( imagesx( $image ) - imagesx( $stamp ) );
		$bottom = abs( imagesy( $image ) - imagesy( $stamp ) );
		$center = round( $right / 2 );
		$middle = round( $bottom / 2 );

		return compact( 'left', 'top', 'right', 'bottom', 'center', 'middle' );
	}

	/**
	 * Get margins for Imagick image manipulation.
	 *
	 * @param array  $margins Original margins array.
	 * @param object $image   Imagick image object.
	 * @param object $stamp   Imagick image object.
	 *
	 * @return array
	 */
	private function get_positions_imagick( $margins, $image, $stamp ) {
		$left   = 0;
		$top    = 0;
		$right  = abs( $image->getImageWidth() - $stamp->getImageWidth() );
		$bottom = abs( $image->getImageHeight() - $stamp->getImageHeight() );
		$center = round( $right / 2 );
		$middle = round( $bottom / 2 );

		return compact( 'left', 'top', 'right', 'bottom', 'center', 'middle' );
	}

	/**
	 * Set default value for overlay meta options.
	 *
	 * @param mixed  $value     The value to return, either a single metadata value or an array
	 *                          of values depending on the value of `$single`.
	 * @param int    $object_id ID of the object metadata is for.
	 * @param string $meta_key  Metadata key.
	 * @return mixed
	 */
	public function get_postmeta_default( $value, $object_id, $meta_key ) {
		if ( ! $this->is_overlay_field( $meta_key ) ) {
			return $value;
		}

		return $this->get_meta_default( $meta_key, get_post_type( $object_id ), $value );
	}

	/**
	 * Set default value for overlay meta options.
	 *
	 * @param mixed  $value     The value to return, either a single metadata value or an array
	 *                          of values depending on the value of `$single`.
	 * @param int    $object_id ID of the object metadata is for.
	 * @param string $meta_key  Metadata key.
	 * @return mixed
	 */
	public function get_termmeta_default( $value, $object_id, $meta_key ) {
		if ( ! $this->is_overlay_field( $meta_key ) ) {
			return $value;
		}

		return $this->get_meta_default( $meta_key, '', $value );
	}

	/**
	 * Set default value for overlay meta options.
	 *
	 * @param mixed  $value     The value to return, either a single metadata value or an array
	 *                          of values depending on the value of `$single`.
	 * @param int    $object_id ID of the object metadata is for.
	 * @param string $meta_key  Metadata key.
	 * @return mixed
	 */
	public function get_usermeta_default( $value, $object_id, $meta_key ) {
		if ( ! $this->is_overlay_field( $meta_key ) ) {
			return $value;
		}

		return $this->get_meta_default( $meta_key, '', $value );
	}

	/**
	 * Check if a field ID is for an overlay related field.
	 *
	 * @param string $field Field ID.
	 * @return boolean
	 */
	public function is_overlay_field( $field ) {
		$overlay_fields = [ 'rank_math_facebook_enable_image_overlay', 'rank_math_twitter_enable_image_overlay', 'rank_math_facebook_image_overlay', 'rank_math_twitter_image_overlay' ];

		return in_array( $field, $overlay_fields, true );
	}

	/**
	 * Get default overlay as set in the plugin settings, or return $default.
	 *
	 * @param string $key       Field ID (custom field name).
	 * @param string $post_type Post type.
	 * @param string $defaults   Default value.
	 * @return mixed
	 */
	public function get_meta_default( $key, $post_type, $defaults = false ) {
		if ( $post_type ) {
			$pt_default = Helper::get_settings( 'titles.pt_' . $post_type . '_image_overlay' );
			if ( $pt_default ) {
				if ( strpos( $key, '_enable_image_overlay' ) !== false ) {
					return 'on';
				}

				return $pt_default;
			}
		}

		$global_default = Helper::get_settings( 'titles.default_image_overlay' );
		if ( $global_default ) {
			if ( strpos( $key, '_enable_image_overlay' ) !== false ) {
				return 'on';
			}

			return $global_default;
		}

		return $defaults;
	}

	/**
	 * Add custom overlays to the list.
	 *
	 * @param array $overlays Original overlays.
	 *
	 * @return array New overlays.
	 */
	public function add_custom_overlays( $overlays ) {
		$custom_overlays = $this->get_custom_overlays();
		if ( empty( $custom_overlays ) ) {
			return $overlays;
		}

		foreach ( $custom_overlays as $custom_overlay ) {
			$new_id     = sanitize_title( $custom_overlay['name'], md5( $custom_overlay['name'] ) );
			$upload_dir = wp_upload_dir();
			$image_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $custom_overlay['image'] );

			$overlays[ $new_id ] = [
				'name' => $custom_overlay['name'],
				'url'  => $custom_overlay['image'],
				'path' => $image_path,
			];
		}

		return $overlays;
	}

	/**
	 * Enqueue assets.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( Param::get( 'page' ) !== 'rank-math-options-titles' ) {
			return;
		}

		wp_enqueue_style(
			'rank-math-pro-title-options',
			RANK_MATH_PRO_URL . 'assets/admin/css/title-options.css',
			null,
			rank_math_pro()->version
		);

		wp_enqueue_script( 'rank-math-pro-redirections', RANK_MATH_PRO_URL . 'assets/admin/js/title-options.js', [], RANK_MATH_PRO_VERSION, true );
	}

	/**
	 * Get custom overlays.
	 *
	 * @return array
	 */
	private function get_custom_overlays() {
		return array_filter(
			array_map(
				function ( $overlay ) {
					return empty( $overlay['name'] ) || empty( $overlay['image'] ) ? false : $overlay;
				},
				(array) Helper::get_settings( 'titles.custom_image_overlays' )
			)
		);
	}

	/**
	 * Get field position by post type.
	 *
	 * @param string $post_type Post type.
	 * @param array  $field_ids All field ids.
	 *
	 * @return bool
	 */
	private function get_field_position( $post_type, $field_ids ) {
		$field = "pt_{$post_type}_analyze_fields";
		if ( 'attachment' === $post_type ) {
			$field = 'pt_attachment_bulk_editing';
		}

		if ( 'web-story' === $post_type ) {
			$field = 'pt_web-story_slack_enhanced_sharing';
		}

		return array_search( $field, array_keys( $field_ids ), true ) + 1;
	}

	/**
	 * Is taxonomy allowed
	 *
	 * @param string $taxonomy Taxonomy to check.
	 *
	 * @return bool
	 */
	private function is_taxonomy_allowed( $taxonomy ) {
		$exclude_taxonomies = [ 'post_format', 'product_shipping_class' ];
		if ( Str::starts_with( 'pa_', $taxonomy ) || in_array( $taxonomy, $exclude_taxonomies, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Do not save if name or image is empty.
	 *
	 * @param array $value Field value to save.
	 * @return array
	 */
	private function sanitize_overlays( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}

		foreach ( $value as $key => $overlay ) {
			if ( empty( $overlay['image'] ) ) {
				unset( $value[ $key ] );
			} elseif ( empty( $overlay['name'] ) ) {
				Helper::add_notification( esc_html__( 'A Custom Watermark item could not be saved because the name field is empty.', 'rank-math-pro' ), [ 'type' => 'error' ] );
				unset( $value[ $key ] );
			}
		}

		return $value;
	}

	/**
	 * Get position options.
	 *
	 * @return array
	 */
	private function get_position_choices() {
		return [
			'top_left'      => __( 'Top Left', 'rank-math-pro' ),
			'top_center'    => __( 'Top Center', 'rank-math-pro' ),
			'top_right'     => __( 'Top Right', 'rank-math-pro' ),

			'middle_left'   => __( 'Middle Left', 'rank-math-pro' ),
			'middle_center' => __( 'Middle Center', 'rank-math-pro' ),
			'middle_right'  => __( 'Middle Right', 'rank-math-pro' ),

			'bottom_left'   => __( 'Bottom Left', 'rank-math-pro' ),
			'bottom_center' => __( 'Bottom Center', 'rank-math-pro' ),
			'bottom_right'  => __( 'Bottom Right', 'rank-math-pro' ),
		];
	}
}
