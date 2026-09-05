<?php
/**
 * Link Processor Utility.
 *
 * Provides centralized access to the FREE plugin's ContentProcessor singleton.
 * This ensures consistent usage across all Link Genius features.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Link_Processor class.
 *
 * Centralized utility for link processing operations.
 */
class Link_Processor {

	/**
	 * Get the ContentProcessor singleton instance.
	 *
	 * This method provides a single point of access to the FREE plugin's
	 * ContentProcessor singleton, ensuring:
	 * - No repeated instantiation of Storage/Classifier objects
	 * - Consistent usage across all Link Genius features
	 * - Automatic use of PRO's enhanced extraction via filters
	 *
	 * @return \RankMath\Links\ContentProcessor ContentProcessor singleton instance.
	 */
	public static function get_processor() {
		return \RankMath\Links\ContentProcessor::get(); // @phpstan-ignore-line
	}

	/**
	 * Process post content for link extraction and tracking.
	 *
	 * Convenience wrapper for common use case of processing content.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Post content.
	 */
	public static function process( $post_id, $content ) {
		self::get_processor()->process( $post_id, $content );
	}
}
