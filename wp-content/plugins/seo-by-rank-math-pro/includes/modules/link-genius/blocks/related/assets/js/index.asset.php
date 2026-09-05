<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- This filename format is required to dynamically load the necessary block dependencies.
/**
 * Block script dependencies.
 *
 * @package    RankMath
 * @subpackage RankMathPro\Link_Genius
 * @author     Rank Math <support@rankmath.com>
 */

return [
	'dependencies' => [
		'lodash',
		'wp-block-editor',
	],
	'version'      => rank_math_pro()->version,
];
