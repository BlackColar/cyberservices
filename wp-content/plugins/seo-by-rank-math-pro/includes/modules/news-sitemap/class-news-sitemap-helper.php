<?php
/**
 * The News Sitemap Module
 *
 * @since      1.0.0
 * @package    RankMath
 * @subpackage RankMathPro
 * @author     RankMath <support@rankmath.com>
 */

namespace RankMathPro\Sitemap;

use RankMath\Helper;

/**
 * News_Sitemap_Helper class.
 */
class News_Sitemap_Helper {

	/**
	 * Escape Exclude terms value before displaying them in Settings.
	 *
	 * @param mixed $value      The unescaped value from the database.
	 * @param array $field_args Array of field arguments.
	 *
	 * @return mixed Escaped value to be displayed.
	 */
	public static function escape_exclude_terms_value( $value, $field_args ) {
		$terms_list = json_decode( $field_args['attributes']['data-terms'] );
		$value      = isset( $value[0] ) ? array_filter(
			$terms_list,
			function ( $term ) use ( $value ) {
				return in_array( (string) $term->value, $value, true );
			}
		) : [];

		$value = wp_json_encode( array_values( $value ) );

		return $value;
	}

	/**
	 * Handles sanitization before updating the value in Database.
	 *
	 * @param string $terms The unsanitized value from the form.
	 *
	 * @return string Sanitized value to be stored.
	 */
	public static function sanitize_exclude_terms_value( $terms ) {
		if ( empty( $terms ) ) {
			return [];
		}

		return array_map(
			function ( $term ) {
				return $term['value'];
			},
			json_decode( stripslashes( $terms ), true )
		);
	}
}
