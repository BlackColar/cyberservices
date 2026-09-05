<?php if (!defined('ABSPATH')) die('No direct access allowed');

/**
 * WP BackItUp - Event Database Manager
 *
 * @package WP BackItUp
 * @author  Chris Simmons <chris.simmons@wpbackitup.com>
 * @link    http://www.wpbackitup.com
 */

/**
 * Event Database Manager
 *
 * Manages the creation, maintenance, and removal of the events table
 * used for logging WordPress events for backup recommendations.
 *
 * @since      2.1.0
 * @package    WP_BackItUp
 * @subpackage WP_BackItUp/includes
 * @author     Chris Simmons <chris.simmons@wpbackitup.com>
 */
class WPBackItUp_Event_Database {

	/**
	 * The name of the events table (without prefix)
	 *
	 * @since    2.1.0
	 * @access   private
	 * @var      string    $table_name    Table name without prefix
	 */
	private static $table_name = 'wpbackitup_events';

	/**
	 * Get the full table name with WordPress prefix
	 *
	 * @since    2.1.0
	 * @access   private
	 * @return   string    Full table name with prefix
	 */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::$table_name;
	}

	/**
	 * Create the events table in the database
	 *
	 * Creates a table to store WordPress events for backup recommendation
	 * analysis. Includes proper indexes for efficient querying by time and type.
	 * Uses dbDelta for safe table creation that won't fail if table exists.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @return   bool    True on success, false on failure
	 */
	public static function create_events_table() {
		global $wpdb;

		$log_name = 'debug_events';

		try {
			$table_name = self::get_table_name();
			$charset_collate = $wpdb->get_charset_collate();

			// SQL to create events table
			$sql = "CREATE TABLE IF NOT EXISTS $table_name (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_type VARCHAR(50) NOT NULL,
				event_data TEXT NOT NULL,
				event_hash VARCHAR(32) DEFAULT NULL,
				timestamp DATETIME NOT NULL,
				PRIMARY KEY (id),
				INDEX idx_timestamp (timestamp),
				INDEX idx_type_timestamp (event_type, timestamp)
			) $charset_collate;";

			// Load WordPress upgrade functions
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

			// Use dbDelta for safe table creation
			dbDelta($sql);

			// Verify table was created
			if (!self::table_exists()) {
				throw new Exception('Events table creation failed - table does not exist after dbDelta');
			}

			WPBackItUp_Logger::log_info($log_name, __METHOD__, 'Events table created successfully');
			return true;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($log_name, __METHOD__, 'Error creating events table: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Check if the events table exists
	 *
	 * Queries the database to verify that the events table exists.
	 * Used during activation and for validation checks.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @return   bool    True if table exists, false otherwise
	 */
	public static function table_exists() {
		global $wpdb;

		try {
			$table_name = self::get_table_name();

			$query = $wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$table_name
			);

			$result = $wpdb->get_var($query);

			return ($result === $table_name);

		} catch (Exception $e) {
			error_log($e);
			return false;
		}
	}

	/**
	 * Get information about the events table
	 *
	 * Returns statistics and metadata about the events table including
	 * row count, table size, index information, and existence status.
	 * Useful for debugging and admin dashboard displays.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @return   array    Array containing table information
	 */
	public static function get_table_info() {
		global $wpdb;

		$log_name = 'debug_events';

		try {
			$table_name = self::get_table_name();

			$info = array(
				'exists' => self::table_exists(),
				'row_count' => 0,
				'table_size' => 'N/A',
				'indexes' => array(),
			);

			// If table doesn't exist, return basic info
			if (!$info['exists']) {
				return $info;
			}

			// Get row count
			$count_query = "SELECT COUNT(*) FROM $table_name";
			$info['row_count'] = (int) $wpdb->get_var($count_query);

			// Get table size
			$size_query = $wpdb->prepare(
				"SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
				 FROM information_schema.TABLES
				 WHERE table_schema = %s
				 AND table_name = %s",
				DB_NAME,
				$table_name
			);

			$size = $wpdb->get_var($size_query);
			$info['table_size'] = $size ? $size . ' MB' : 'N/A';

			// Get index information
			$index_query = "SHOW INDEX FROM $table_name";
			$indexes = $wpdb->get_results($index_query);

			if ($indexes) {
				foreach ($indexes as $index) {
					$info['indexes'][] = array(
						'name' => $index->Key_name,
						'column' => $index->Column_name,
						'unique' => ($index->Non_unique == 0),
					);
				}
			}

			WPBackItUp_Logger::log_info(
				$log_name,
				__METHOD__,
				'Table info retrieved: exists=' . ($info['exists'] ? 'yes' : 'no') .
				', rows=' . $info['row_count'] .
				', size=' . $info['table_size'] .
				', indexes=' . count($info['indexes'])
			);

			return $info;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($log_name, __METHOD__, 'Error getting table info: ' . $e->getMessage());

			return array(
				'exists' => false,
				'row_count' => 0,
				'table_size' => 'Error',
				'indexes' => array(),
			);
		}
	}

	/**
	 * Drop the events table from the database
	 *
	 * Removes the events table completely. This should ONLY be called
	 * during plugin uninstall, NOT during deactivation. All event data
	 * will be permanently lost.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @return   bool    True on success, false on failure
	 */
	public static function drop_events_table() {
		global $wpdb;

		$log_name = 'debug_events';

		try {
			$table_name = self::get_table_name();

			// Only drop if table exists
			if (!self::table_exists()) {
				WPBackItUp_Logger::log_warning($log_name, __METHOD__, 'Attempted to drop events table but it does not exist');
				return true; // Not an error - table doesn't exist anyway
			}

			$sql = "DROP TABLE IF EXISTS $table_name";
			$result = $wpdb->query($sql);

			if (false === $result) {
				throw new Exception('DROP TABLE query failed: ' . $wpdb->last_error);
			}

			WPBackItUp_Logger::log_info($log_name, __METHOD__, 'Events table dropped successfully');
			return true;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($log_name, __METHOD__, 'Error dropping events table: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get event statistics for the settings page
	 *
	 * Returns counts of events by time period and table size information.
	 * Used by the Event Logging settings tab to display statistics.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @return   array    Array containing event statistics
	 */
	public static function get_event_stats() {
		global $wpdb;

		$log_name = 'debug_events';

		$stats = array(
			'total_events'     => 0,
			'updates_applied'  => 0,
			'content_changes'  => 0,
			'security_events'  => 0,
			'settings_changes' => 0,
			'pending_updates'  => 0,
		);

		try {
			// Check if table exists
			if ( ! self::table_exists() ) {
				WPBackItUp_Logger::log_info( $log_name, __METHOD__, 'Events table does not exist - returning default stats' );
				return $stats;
			}

			$table_name = self::get_table_name();

			// Get total event count
			$stats['total_events'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

			// Get updates applied (plugin_update + theme_update + core_update)
			$stats['updates_applied'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM $table_name WHERE event_type IN ('plugin_update', 'theme_update', 'core_update')"
			);

			// Get content changes (content_change + content_change_raw)
			$stats['content_changes'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM $table_name WHERE event_type IN ('content_change', 'content_change_raw')"
			);

			// Get security events (security_event + security_event_raw)
			$stats['security_events'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM $table_name WHERE event_type IN ('security_event', 'security_event_raw')"
			);

			// Get settings changes
			$stats['settings_changes'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM $table_name WHERE event_type = 'settings_change'"
			);

			// Get pending updates (update_available)
			$stats['pending_updates'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM $table_name WHERE event_type = 'update_available'"
			);

			WPBackItUp_Logger::log_info(
				$log_name,
				__METHOD__,
				'Event stats retrieved: total=' . $stats['total_events'] .
				', updates=' . $stats['updates_applied'] .
				', content=' . $stats['content_changes'] .
				', security=' . $stats['security_events'] .
				', settings=' . $stats['settings_changes'] .
				', pending=' . $stats['pending_updates']
			);

			return $stats;

		} catch ( Exception $e ) {
			error_log( $e );
			WPBackItUp_Logger::log_error( $log_name, __METHOD__, 'Error getting event stats: ' . $e->getMessage() );
			return $stats;
		}
	}

	/**
	 * Clean up old events from the database
	 *
	 * Deletes all events older than the specified number of days. This method
	 * is called by the daily WP-Cron job to maintain a rolling window of events
	 * and prevent the table from growing indefinitely.
	 *
	 * Default retention is 7 days as specified in the event system design.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @param    int    $days    Number of days to retain (default: 7)
	 * @return   int|false       Number of events deleted, or false on error
	 */
	public static function cleanup_old_events($days = 7) {
		global $wpdb;

		$log_name = 'debug_events';

		try {
			$table_name = self::get_table_name();

			// Validate table exists
			if (!self::table_exists()) {
				WPBackItUp_Logger::log_warning($log_name, __METHOD__, 'Events table does not exist - skipping cleanup');
				return false;
			}

			// Delete events older than specified days
			$sql = $wpdb->prepare(
				"DELETE FROM $table_name WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
				absint($days)
			);

			$deleted_count = $wpdb->query($sql);

			// Check for database errors
			if (false === $deleted_count) {
				throw new Exception('DELETE query failed: ' . $wpdb->last_error);
			}

			if ($deleted_count > 0) {
				WPBackItUp_Logger::log_info(
					$log_name,
					__METHOD__,
					"Cleanup completed: deleted $deleted_count events older than $days days"
				);
			} else {
				WPBackItUp_Logger::log_info($log_name, __METHOD__, "No events older than $days days found");
			}

			return $deleted_count;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($log_name, __METHOD__, 'Error cleaning up old events: ' . $e->getMessage());
			return false;
		}
	}
}
