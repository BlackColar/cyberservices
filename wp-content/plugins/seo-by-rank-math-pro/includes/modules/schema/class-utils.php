<?php
/**
 * Schema Utilities
 *
 * @since      3.0.117
 * @package    RankMath
 * @subpackage RankMathPro\Schema
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Utils class.
 */
class Utils {

	/**
	 * Return the primary schema type string from a value that may be an array or a string.
	 *
	 * The schema.org allows multiple @type values (array), but meta keys and post titles use
	 * only the primary (first) type, matching the FREE plugin convention in class-jsonld.php.
	 *
	 * @param string|array $type Raw @type value from schema data.
	 * @return string
	 */
	public static function get_primary_type( $type ) {
		return is_array( $type ) ? $type[0] : $type;
	}
}
