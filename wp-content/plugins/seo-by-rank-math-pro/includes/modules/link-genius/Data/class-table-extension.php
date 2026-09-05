<?php
/**
 * Table Extension for Link Genius.
 *
 * Extends FREE plugin's tables with PRO columns.
 *
 * @since      1.0.98
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Data;

use RankMath\Helpers\DB;
use RankMathPro\Link_Genius\Background\Regenerate_Links;

defined( 'ABSPATH' ) || exit;

/**
 * Table_Extension class.
 */
class Table_Extension {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Add bulk update and keyword maps tables to multisite deletion list.
		add_filter( 'wpmu_drop_tables', [ $this, 'on_delete_blog' ] );
	}

	/**
	 * Initialize all Link Genius PRO database schema.
	 *
	 * This is the unified entry point for setting up all Link Genius PRO database objects:
	 * - Extends rank_math_internal_links table with PRO columns and indexes
	 * - Creates all PRO-specific tables (audit, history, snapshots, maps, variations)
	 * - Triggers regeneration if columns were just added
	 *
	 * Safe to call multiple times - checks for existing objects before creating them.
	 * Can be called from: installer (fresh install), update routines (upgrades), or hooks (table recreation).
	 *
	 * @param bool $force_reprocess Whether to force reprocessing after adding columns.
	 * @return bool True if any changes were made, false if everything already existed.
	 */
	public static function initialize_schema( $force_reprocess = false ) {
		// Extend rank_math_internal_links table with PRO columns.
		$columns_added = self::ensure_columns_exist();

		// Create all PRO-specific tables (audit, history, snapshots, maps, variations).
		self::ensure_all_pro_tables_exist();

		// If columns were just added, trigger regeneration to populate them with data.
		// This ensures all PRO columns (anchor_text, is_nofollow, etc.) are populated.
		if ( $columns_added || $force_reprocess ) {
			Regenerate_Links::get()->start();
		}

		return $columns_added;
	}

	/**
	 * Ensure PRO columns exist in rank_math_internal_links table.
	 *
	 * This method can be called from anywhere to ensure the table has PRO columns.
	 * It's safe to call multiple times as it checks if columns already exist.
	 *
	 * @return bool True if columns were added, false if they already existed or table doesn't exist.
	 */
	private static function ensure_columns_exist() {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_internal_links';

		// Check if table exists.
		if ( ! DB::check_table_exists( 'rank_math_internal_links' ) ) {
			return false;
		}

		// Check if PRO columns already exist.
		if ( self::column_exists( $table, 'anchor_text' ) ) {
			// Columns already added.
			return false;
		}

		// Add PRO columns.
		DB::query(
			"ALTER TABLE `{$table}`
			ADD COLUMN `anchor_text` VARCHAR(500) DEFAULT NULL,
			ADD COLUMN `anchor_type` VARCHAR(10) DEFAULT 'HPLNK',
			ADD COLUMN `is_nofollow` TINYINT(1) DEFAULT 0,
			ADD COLUMN `target_blank` TINYINT(1) DEFAULT 0,
			ADD COLUMN `url_hash` CHAR(32) DEFAULT NULL,
			ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP",
		);

		// Add indexes for better performance.
		DB::query( "ALTER TABLE `{$table}` ADD INDEX `idx_url_hash` (`url_hash`)" );
		DB::query( "ALTER TABLE `{$table}` ADD INDEX `idx_post_id` (`post_id`)" );
		DB::query( "ALTER TABLE `{$table}` ADD INDEX `idx_target_post_id` (`target_post_id`)" );
		DB::query( "ALTER TABLE `{$table}` ADD INDEX `idx_type` (`type`)" );
		DB::query( "ALTER TABLE `{$table}` ADD INDEX `idx_anchor_type` (`anchor_type`)" );
		DB::query( "ALTER TABLE `{$table}` ADD INDEX `idx_target_blank` (`target_blank`)" );
		DB::query( "ALTER TABLE `{$table}` ADD INDEX `idx_is_nofollow` (`is_nofollow`)" );
		DB::query( "ALTER TABLE `{$table}` ADD INDEX `idx_created_at` (`created_at`)" );

		// Add composite indexes for common query patterns.
		DB::query( "ALTER TABLE `{$table}` ADD INDEX `idx_post_type` (`post_id`, `type`)" );
		DB::query( "ALTER TABLE `{$table}` ADD INDEX `idx_type_nofollow` (`type`, `is_nofollow`)" );
		DB::query( "ALTER TABLE `{$table}` ADD INDEX `idx_post_type_created` (`post_id`, `type`, `created_at`)" );

		// Add FULLTEXT index for faster search on anchor_text and url.
		DB::query( "ALTER TABLE `{$table}` ADD FULLTEXT INDEX `idx_search` (`anchor_text`, `url`)" );

		return true;
	}

	/**
	 * Ensure link status table exists for HTTP status checking.
	 *
	 * This method creates the rank_math_link_genius_audit table if it doesn't exist.
	 * It's safe to call multiple times as it checks if table already exists.
	 *
	 * @return bool True if table was created, false if it already existed.
	 */
	public static function ensure_status_table_exists() {
		$schema = "
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`link_id` BIGINT(20) UNSIGNED NOT NULL,
			`url_hash` CHAR(32) NOT NULL,
			`http_status_code` SMALLINT(3) DEFAULT NULL,
			`status_category` VARCHAR(10) DEFAULT NULL COMMENT '2xx, 3xx, 4xx, 5xx, timeout, error',
			`robots_blocked` TINYINT(1) DEFAULT 0,
			`is_marked_safe` TINYINT(1) DEFAULT 0 COMMENT 'User marked as not broken',
			`last_checked_at` DATETIME DEFAULT NULL,
			`last_error_message` TEXT DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_link_id` (`link_id`),
			KEY `idx_url_hash` (`url_hash`),
			KEY `idx_status_code` (`http_status_code`),
			KEY `idx_status_category` (`status_category`),
			KEY `idx_last_checked` (`last_checked_at`),
			KEY `idx_robots_blocked` (`robots_blocked`),
			KEY `idx_marked_safe` (`is_marked_safe`)
		";

		return self::create_table_if_not_exists( 'rank_math_link_genius_audit', $schema );
	}

	/**
	 * Ensure bulk update tables exist.
	 *
	 * This method creates the bulk update tables if they don't exist.
	 * It's safe to call multiple times as it checks if tables already exist.
	 *
	 * @return bool True if tables were created, false if they already existed.
	 */
	public static function ensure_bulk_update_tables_exist() {
		$history_created   = self::ensure_bulk_update_history_table_exists();
		$snapshots_created = self::ensure_bulk_update_snapshots_table_exists();

		return $history_created || $snapshots_created;
	}

	/**
	 * Ensure keyword maps tables exist.
	 *
	 * This method creates the keyword maps and variations tables if they don't exist.
	 * It's safe to call multiple times as it checks if tables already exist.
	 *
	 * @return bool True if any table was created, false if they already existed.
	 */
	public static function ensure_keyword_maps_tables_exist() {
		$maps_created       = self::ensure_keyword_maps_table_exists();
		$variations_created = self::ensure_keyword_variations_table_exists();

		return $maps_created || $variations_created;
	}

	/**
	 * Add Link Genius tables to deletion list when MU blog is deleted.
	 *
	 * @param array $tables List of tables that will be deleted by WP.
	 * @return array Modified tables array.
	 */
	public function on_delete_blog( $tables ) {
		global $wpdb;

		$tables[] = $wpdb->prefix . 'rank_math_link_genius_audit';
		$tables[] = $wpdb->prefix . 'rank_math_link_genius_history';
		$tables[] = $wpdb->prefix . 'rank_math_link_genius_snapshots';
		$tables[] = $wpdb->prefix . 'rank_math_link_genius_maps';
		$tables[] = $wpdb->prefix . 'rank_math_link_genius_map_variations';

		return $tables;
	}

	/**
	 * Ensure all Link Genius PRO tables exist.
	 *
	 * This is the unified entry point for creating all PRO tables.
	 * Safe to call multiple times.
	 *
	 * @return bool True if any table was created, false if all already existed.
	 */
	public static function ensure_all_pro_tables_exist() {
		$any_created = false;

		// Status table for HTTP checking.
		if ( self::ensure_status_table_exists() ) {
			$any_created = true;
		}

		// Bulk update tables.
		if ( self::ensure_bulk_update_tables_exist() ) {
			$any_created = true;
		}

		// Keyword maps tables.
		if ( self::ensure_keyword_maps_tables_exist() ) {
			$any_created = true;
		}

		return $any_created;
	}

	/**
	 * Check if column exists in a table.
	 *
	 * @param string $table_name  Table name (with prefix).
	 * @param string $column_name Column name to check.
	 * @return bool True if column exists.
	 */
	private static function column_exists( $table_name, $column_name ) {
		global $wpdb;
		$query = $wpdb->prepare(
			"SHOW COLUMNS FROM `{$table_name}` LIKE %s",
			$column_name
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Ensure link genius history table exists.
	 *
	 * @return bool True if table was created, false if it already existed.
	 */
	private static function ensure_bulk_update_history_table_exists() {
		$schema = "
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`batch_id` CHAR(32) NOT NULL,
			`source_type` VARCHAR(20) NOT NULL DEFAULT 'bulk_update' COMMENT 'bulk_update|keyword_map',
			`keyword_map_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Reference to keyword map if source_type is keyword_map',
			`user_id` BIGINT(20) UNSIGNED NOT NULL,
			`operation_type` VARCHAR(20) NOT NULL COMMENT 'anchor|url|both|add_link',
			`filters` LONGTEXT NOT NULL COMMENT 'JSON encoded search filters',
			`changes_summary` LONGTEXT NOT NULL COMMENT 'JSON: {from_anchor, to_anchor, from_url, to_url}',
			`affected_links_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
			`affected_posts_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
			`status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|completed|failed|rolled_back',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`completed_at` DATETIME DEFAULT NULL,
			`error_message` TEXT DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_batch_id` (`batch_id`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_status` (`status`),
			KEY `idx_created_at` (`created_at`),
			KEY `idx_source_type` (`source_type`),
			KEY `idx_keyword_map_id` (`keyword_map_id`)
		";

		return self::create_table_if_not_exists( 'rank_math_link_genius_history', $schema );
	}

	/**
	 * Ensure link genius snapshots table exists.
	 *
	 * @return bool True if table was created, false if it already existed.
	 */
	private static function ensure_bulk_update_snapshots_table_exists() {
		$schema = "
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`batch_id` CHAR(32) NOT NULL,
			`post_id` BIGINT(20) UNSIGNED NOT NULL,
			`original_content` LONGTEXT NOT NULL COMMENT 'Post content before update',
			`link_changes` LONGTEXT NOT NULL COMMENT 'JSON array of link changes',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_batch_id` (`batch_id`),
			KEY `idx_post_id` (`post_id`),
			KEY `idx_batch_post` (`batch_id`, `post_id`)
		";

		return self::create_table_if_not_exists( 'rank_math_link_genius_snapshots', $schema );
	}

	/**
	 * Ensure link genius maps table exists.
	 *
	 * @return bool True if table was created, false if it already existed.
	 */
	private static function ensure_keyword_maps_table_exists() {
		$schema = "
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`name` VARCHAR(255) NOT NULL COMMENT 'User-friendly name for the rule',
			`description` TEXT DEFAULT NULL COMMENT 'Optional description',
			`target_url` VARCHAR(2083) NOT NULL COMMENT 'Destination URL',
			`is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active/inactive toggle',
			`max_links_per_post` INT(10) UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Limit per post',
			`auto_link_on_publish` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Enable auto-linking',
			`case_sensitive` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Case-sensitive matching',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			`last_executed_at` DATETIME DEFAULT NULL COMMENT 'Last manual execution',
			`execution_count` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total executions',
			PRIMARY KEY (`id`),
			KEY `idx_name` (`name`(191)),
			KEY `idx_is_enabled` (`is_enabled`),
			KEY `idx_auto_link_on_publish` (`auto_link_on_publish`),
			KEY `idx_created_at` (`created_at`)
		";

		return self::create_table_if_not_exists( 'rank_math_link_genius_maps', $schema );
	}

	/**
	 * Ensure link genius variations table exists.
	 *
	 * @return bool True if table was created, false if it already existed.
	 */
	private static function ensure_keyword_variations_table_exists() {
		$schema = "
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`keyword_map_id` BIGINT(20) UNSIGNED NOT NULL,
			`variation` VARCHAR(255) NOT NULL COMMENT 'Keyword variation text',
			`source` VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'ai or manual',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_keyword_map_id` (`keyword_map_id`),
			KEY `idx_variation` (`variation`(191)),
			UNIQUE KEY `idx_unique_variation_per_map` (`keyword_map_id`, `variation`(191))
		";

		$created = self::create_table_if_not_exists( 'rank_math_link_genius_map_variations', $schema );

		// Add foreign key constraint if table was just created and parent table exists.
		if ( $created && DB::check_table_exists( 'rank_math_link_genius_maps' ) ) {
			global $wpdb;
			$table           = $wpdb->prefix . 'rank_math_link_genius_map_variations';
			$maps_table      = $wpdb->prefix . 'rank_math_link_genius_maps';
			$current_site_id = get_current_blog_id();

			DB::query(
				"ALTER TABLE `{$table}`
				ADD CONSTRAINT `fk_variation_keyword_map_id_{$current_site_id}`
				FOREIGN KEY (`keyword_map_id`)
				REFERENCES `{$maps_table}` (`id`)
				ON DELETE CASCADE"
			);
		}

		return $created;
	}

	/**
	 * Create a table if it doesn't exist.
	 *
	 * Helper method to eliminate duplicate table creation boilerplate.
	 * Handles table name, prefix, collation, and existence checking automatically.
	 *
	 * @param string $table_name  Table name without prefix.
	 * @param string $table_schema Table schema SQL (column definitions, keys, etc.).
	 *                            Should contain everything between the parentheses.
	 * @return bool True if table was created, false if it already existed.
	 */
	private static function create_table_if_not_exists( $table_name, $table_schema ) {
		// Check if table already exists.
		if ( DB::check_table_exists( $table_name ) ) {
			return false;
		}

		global $wpdb;
		$full_table_name = $wpdb->prefix . $table_name;
		$collate         = $wpdb->get_charset_collate();

		// Build complete CREATE TABLE statement.
		$sql = "CREATE TABLE `{$full_table_name}` ({$table_schema}) {$collate} ENGINE=InnoDB";

		// Execute the CREATE TABLE query.
		DB::query( $sql );

		return true;
	}
}
