<?php if (!defined('ABSPATH')) die('No direct access allowed');

/**
 * WP BackItUp - Event Batcher
 *
 * @package WP BackItUp
 * @author  Chris Simmons <chris.simmons@wpbackitup.com>
 * @link    http://www.wpbackitup.com
 */

/**
 * Event Batcher
 *
 * Retrieves and formats WordPress events for AI analysis in the premium plugin.
 * This class serves as a bridge between the free plugin's event logging and
 * the premium plugin's AWS Bedrock AI-powered recommendation system.
 *
 * IMPORTANT: This class provides an API for the PREMIUM plugin to retrieve events.
 * The FREE plugin captures events but doesn't call these methods - it uses the
 * Recommendation Engine for its own rule-based analysis.
 *
 * Privacy: All events returned by this class are already privacy-compliant
 * (no IPs, usernames, or sensitive values) as enforced during event capture.
 *
 * @since      2.1.0
 * @package    WP_BackItUp
 * @subpackage WP_BackItUp/includes
 * @author     Chris Simmons <chris.simmons@wpbackitup.com>
 */
class WPBackItUp_Event_Batcher {

	/**
	 * Log name for debugging
	 *
	 * @since    2.1.0
	 * @access   private
	 * @var      string    $log_name    Name of the debug log file
	 */
	private static $log_name = 'debug_events';

	/**
	 * Retrieve recent events for AI analysis
	 *
	 * Fetches events from the database within the specified time window,
	 * ordered by most recent first. Returns both reactive events (plugin updates)
	 * and aggregated events (content changes, security events).
	 *
	 * @since    2.1.0
	 * @access   public
	 * @param    int    $hours    Number of hours to look back (default: 24)
	 * @param    int    $limit    Maximum number of events to retrieve (default: 100)
	 * @return   array|false      Array of events with decoded JSON data, or false on error
	 */
	public static function get_events_batch($hours = 24, $limit = 100) {
		global $wpdb;

		try {
			// Get table name
			$table_name = $wpdb->prefix . 'wpbackitup_events';

			// Build query with prepared statement for security
			$sql = $wpdb->prepare(
				"SELECT id, event_type, event_data, event_hash, timestamp
				 FROM $table_name
				 WHERE timestamp > DATE_SUB(NOW(), INTERVAL %d HOUR)
				 ORDER BY timestamp DESC
				 LIMIT %d",
				absint($hours),
				absint($limit)
			);

			// Execute query
			$results = $wpdb->get_results($sql, ARRAY_A);

			// Check for database errors
			if ($wpdb->last_error) {
				throw new Exception('Database query failed: ' . $wpdb->last_error);
			}

			// If no results, return empty array (not an error)
			if (empty($results)) {
				WPBackItUp_Logger::log_info(self::$log_name, __METHOD__, 'No events found in last ' . $hours . ' hours');
				return array();
			}

			// Decode JSON event_data for each event
			$events = array();
			foreach ($results as $row) {
				$event_data = json_decode($row['event_data'], true);

				// Skip if JSON decode failed
				if (json_last_error() !== JSON_ERROR_NONE) {
					WPBackItUp_Logger::log_warning(
						self::$log_name,
						__METHOD__,
						'Failed to decode JSON for event ID ' . $row['id'] . ': ' . json_last_error_msg()
					);
					continue;
				}

				// Build event array with decoded data
				$events[] = array(
					'id'         => (int) $row['id'],
					'event_type' => $row['event_type'],
					'event_data' => $event_data,
					'event_hash' => $row['event_hash'],
					'timestamp'  => $row['timestamp'],
				);
			}

			WPBackItUp_Logger::log_info(
				self::$log_name,
				__METHOD__,
				'Retrieved ' . count($events) . ' events from last ' . $hours . ' hours'
			);

			return $events;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error(self::$log_name, __METHOD__, 'Error retrieving events: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get site context metadata
	 *
	 * Collects WordPress installation metadata for AI context. Provides
	 * environmental information to help the AI understand the site's
	 * technical characteristics and risk profile.
	 *
	 * Privacy: Only includes non-sensitive metadata. No user data, no full URLs.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @return   array    Site context metadata
	 */
	public static function get_site_context() {
		global $wpdb;

		// Get active plugins count (not names - privacy)
		$active_plugins = get_option('active_plugins', array());
		$active_plugins_count = is_array($active_plugins) ? count($active_plugins) : 0;

		// Add network-activated plugins (multisite only)
		if (is_multisite()) {
			$network_plugins = get_site_option('active_sitewide_plugins', array());
			$active_plugins_count += is_array($network_plugins) ? count($network_plugins) : 0;
		}

		// Get active theme (name only, no URL)
		$theme = wp_get_theme();
		$active_theme = $theme->get('Name');

		// Get site domain (for context, not full URL)
		$site_url = get_site_url();
		$parsed_url = parse_url($site_url);
		$site_domain = isset($parsed_url['host']) ? $parsed_url['host'] : 'unknown';

		// Get MySQL version
		$mysql_version = $wpdb->get_var("SELECT VERSION()");

		// Get memory limit
		$memory_limit = ini_get('memory_limit');

		// Get max execution time
		$max_execution_time = ini_get('max_execution_time');

		// Build context array
		$context = array(
			'wordpress_version'   => get_bloginfo('version'),
			'php_version'         => PHP_VERSION,
			'mysql_version'       => $mysql_version,
			'active_plugins_count' => $active_plugins_count,
			'active_theme'        => $active_theme,
			'site_domain'         => $site_domain,
			'is_multisite'        => is_multisite(),
			'memory_limit'        => $memory_limit,
			'max_execution_time'  => $max_execution_time,
			'plugin_version'      => WPBACKITUP__VERSION,
			'timestamp'           => current_time('mysql'),
		);

		return $context;
	}

	/**
	 * Format events for AWS Bedrock AI analysis
	 *
	 * Structures event data in a format optimized for Claude AI via AWS Bedrock.
	 * Separates reactive events (immediate) from aggregated events (summarized),
	 * includes site context, and provides event summary statistics.
	 *
	 * Output structure is JSON-serializable and designed to be included in
	 * AI prompts for backup recommendation analysis.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @param    array    $events    Array of events from get_events_batch()
	 * @return   array               Formatted data structure for AI analysis
	 */
	public static function format_for_ai($events) {
		// Get site context
		$site_context = self::get_site_context();

		// Separate reactive and aggregated events
		$reactive_events = array();
		$aggregated_events = array();

		// Event types that are aggregated (vs reactive)
		$aggregated_types = array(WPBackItUp_Event_Logger::EVENT_CONTENT_CHANGE, WPBackItUp_Event_Logger::EVENT_SECURITY_EVENT);

		// Event summary counters
		$event_counts = array();

		foreach ($events as $event) {
			$event_type = $event['event_type'];

			// Count by type
			if (!isset($event_counts[$event_type])) {
				$event_counts[$event_type] = 0;
			}
			$event_counts[$event_type]++;

			// Categorize as reactive or aggregated
			if (in_array($event_type, $aggregated_types)) {
				$aggregated_events[] = $event;
			} else {
				$reactive_events[] = $event;
			}
		}

		// Build final structure for AI
		$ai_data = array(
			'site_context' => $site_context,
			'events' => array(
				'reactive'   => $reactive_events,
				'aggregated' => $aggregated_events,
			),
			'event_summary' => array(
				'total_count' => count($events),
				'by_type'     => $event_counts,
			),
			'generated_at' => current_time('mysql'),
		);

		WPBackItUp_Logger::log_info(
			self::$log_name,
			__METHOD__,
			'Formatted ' . count($events) . ' events for AI analysis: reactive=' . count($reactive_events) . ', aggregated=' . count($aggregated_events)
		);

		return $ai_data;
	}
}
