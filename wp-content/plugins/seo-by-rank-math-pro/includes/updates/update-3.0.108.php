<?php //phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- This filename format is intentionally used to match the plugin version.
/**
 * The Updates routine for version 3.0.108
 *
 * @since      3.0.108
 * @package    RankMathPro
 * @subpackage RankMathPro\Updates
 * @author     Rank Math <support@rankmath.com>
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add Link Genius columns to rank_math_internal_links table and create PRO tables.
 *
 * This update uses the centralized Table_Extension::initialize_schema() method.
 * It handles:
 * - Checking if table exists
 * - Adding PRO columns and indexes
 * - Creating PRO-specific tables (audit, history, snapshots, maps, variations)
 * - Triggering regeneration to populate PRO columns with data
 */
RankMathPro\Link_Genius\Data\Table_Extension::initialize_schema( true );
