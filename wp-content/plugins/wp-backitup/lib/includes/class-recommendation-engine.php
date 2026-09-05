<?php if (!defined('ABSPATH')) die('No direct access allowed');

/**
 * WP BackItUp - Recommendation Engine
 *
 * @package WP BackItUp
 * @author  Chris Simmons <chris.simmons@wpbackitup.com>
 * @link    http://www.wpbackitup.com
 */

/**
 * Recommendation Engine
 *
 * Analyzes WordPress events and generates rule-based backup recommendations
 * for the FREE plugin. All recommendations include upgrade CTAs to premium.
 *
 * @since      2.1.0
 * @package    WP_BackItUp
 * @subpackage WP_BackItUp/includes
 * @author     Chris Simmons <chris.simmons@wpbackitup.com>
 */
class WPBackItUp_Recommendation_Engine {

	/**
	 * The log name for this class
	 *
	 * @since    2.1.0
	 * @access   private
	 * @var      string    $log_name    Log file name
	 */
	private static $log_name = 'debug_recommendation_engine';

	/**
	 * Get backup recommendation based on recent events
	 *
	 * Analyzes recent events from the database and returns a recommendation
	 * if any of the 5 rules are triggered. Returns null if no recommendation.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @param    int       $hours    Number of hours to look back (default 24)
	 * @return   array|null          Recommendation array or null if none
	 */
	public static function get_recommendation($hours = 24) {
		try {
			WPBackItUp_Logger::log_info(self::$log_name, __METHOD__, 'Getting recommendation for last ' . $hours . ' hours');

			global $wpdb;
			$table_name = $wpdb->prefix . 'wpbackitup_events';

			// Get recent events
			$sql = $wpdb->prepare(
				"SELECT * FROM $table_name
				 WHERE timestamp >= DATE_SUB(NOW(), INTERVAL %d HOUR)
				 ORDER BY timestamp DESC",
				$hours
			);

			$events = $wpdb->get_results($sql);

			if (empty($events)) {
				WPBackItUp_Logger::log_info(self::$log_name, __METHOD__, 'No recent events found');
				return null;
			}

			WPBackItUp_Logger::log_info(self::$log_name, __METHOD__, 'Found ' . count($events) . ' recent events');

			// Evaluate events against all rules
			$recommendation = self::evaluate_events($events);

			if ($recommendation) {
				WPBackItUp_Logger::log_info(
					self::$log_name,
					__METHOD__,
					'Recommendation generated: level=' . $recommendation['level'] .
					', rule=' . $recommendation['rule']
				);
			} else {
				WPBackItUp_Logger::log_info(self::$log_name, __METHOD__, 'No recommendation generated');
			}

			return $recommendation;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error(self::$log_name, __METHOD__, 'Error: ' . $e->getMessage());
			return null;
		}
	}

	/**
     * Evaluate events against all recommendation rules
     *
     * Checks events in priority order (ERROR > WARNING > INFO).
     * Returns the FIRST matching recommendation to avoid overwhelming
     * free users with multiple notices.
     *
     * Priority order:
     * 1. ERROR: Security events, critical plugin updates
     * 2. WARNING: Multiple updates, critical settings
     * 3. INFO: High content activity
     *
	 * @param    array     $events    Array of event objects from database
	 * @return   array|null           Recommendation array or null
	 *@since    2.1.0
	 * @access   private
	 */
	private static function evaluate_events($events) {
		try {
			// Priority order: error > warning > info
			// Check rules in priority order

			foreach ($events as $event) {
				// Decode event_data JSON
				$event_data = json_decode($event->event_data, true);
				if (!$event_data) {
					continue;
				}

				// Rule 3: High Failed Logins (ERROR - highest priority)
				if ($event->event_type === WPBackItUp_Event_Logger::EVENT_SECURITY_EVENT) {
					$recommendation = self::rule_high_failed_logins($event_data);
					if ($recommendation) {
						return $recommendation;
					}
				}

				// Rule 1: Critical Plugin Update (ERROR)
				if ($event->event_type === WPBackItUp_Event_Logger::EVENT_PLUGIN_UPDATE) {
					$recommendation = self::rule_critical_plugin_update($event_data);
					if ($recommendation) {
						return $recommendation;
					}
				}
			}

			// Rule 2: Multiple Updates Pending (WARNING)
			$recommendation = self::rule_multiple_updates($events);
			if ($recommendation) {
				return $recommendation;
			}

			// Rule 4: Critical Setting Changed (WARNING)
			foreach ($events as $event) {
				$event_data = json_decode($event->event_data, true);
				if (!$event_data) {
					continue;
				}

				if ($event->event_type === WPBackItUp_Event_Logger::EVENT_SETTINGS_CHANGE) {
					$recommendation = self::rule_critical_setting_changed($event_data);
					if ($recommendation) {
						return $recommendation;
					}
				}
			}

			// Rule 5: High Content Activity (INFO - lowest priority)
			foreach ($events as $event) {
				$event_data = json_decode($event->event_data, true);
				if (!$event_data) {
					continue;
				}

				if ($event->event_type === WPBackItUp_Event_Logger::EVENT_CONTENT_CHANGE) {
					$recommendation = self::rule_high_content_activity($event_data);
					if ($recommendation) {
						return $recommendation;
					}
				}
			}

			return null;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error(self::$log_name, __METHOD__, 'Error: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Rule 1: Critical Plugin Update Available
	 *
	 * Triggers when a critical plugin has been updated.
	 * Severity: ERROR (red notice)
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    array     $event_data    Decoded event data
	 * @return   array|null                Recommendation or null
	 */
	private static function rule_critical_plugin_update($event_data) {
		try {
			if (isset($event_data['is_critical']) && $event_data['is_critical'] === true) {
				$plugin_name = isset($event_data['plugin_name']) ? $event_data['plugin_name'] : 'a critical plugin';

				return array(
					'severity' => 'error',
					'title' => 'Critical Plugin Updated - Backup Recommended',
					'message' => sprintf(
						'%s was just updated. Create a backup now in case you need to rollback.',
						esc_html($plugin_name)
					),
					'cta_backup' => true,
					'cta_upgrade' => self::generate_upgrade_cta('automatic backups before updates'),
				);
			}

			return null;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error(self::$log_name, __METHOD__, 'Error: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Rule 2: Multiple Updates Pending
	 *
	 * Triggers when 3 or more updates are available.
	 * Severity: WARNING (orange notice)
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    array     $events    All recent events
	 * @return   array|null           Recommendation or null
	 */
	private static function rule_multiple_updates($events) {
		try {
			// Count update_available events
			$update_count = 0;
			foreach ($events as $event) {
				if ($event->event_type === WPBackItUp_Event_Logger::EVENT_UPDATE_AVAILABLE) {
					$update_count++;
				}
			}

			if ($update_count >= 3) {
				return array(
					'severity' => 'warning',
					'title' => 'Multiple Updates Available',
					'message' => sprintf(
						'You have %d pending updates. Back up before bulk updating.',
						$update_count
					),
					'cta_backup' => true,
					'cta_upgrade' => self::generate_upgrade_cta('AI-powered update analysis'),
				);
			}

			return null;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error(self::$log_name, __METHOD__, 'Error: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Rule 3: High Failed Login Attempts
	 *
	 * Triggers when 50+ failed logins detected.
	 * Severity: ERROR (red notice)
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    array     $event_data    Decoded event data
	 * @return   array|null                Recommendation or null
	 */
	private static function rule_high_failed_logins($event_data) {
		try {
			if (isset($event_data['count']) && $event_data['count'] >= 50) {
				$count = (int) $event_data['count'];

				return array(
					'severity' => 'error',
					'title' => 'Security Alert - Possible Attack Detected',
					'message' => sprintf(
						'%d failed login attempts detected. Backup your site now.',
						$count
					),
					'cta_backup' => true,
					'cta_upgrade' => self::generate_upgrade_cta('security event monitoring'),
				);
			}

			return null;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error(self::$log_name, __METHOD__, 'Error: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Rule 4: Critical Setting Changed
	 *
	 * Triggers when a critical WordPress setting is modified.
	 * Severity: WARNING (orange notice)
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    array     $event_data    Decoded event data
	 * @return   array|null                Recommendation or null
	 */
	private static function rule_critical_setting_changed($event_data) {
		try {
			if (isset($event_data['criticality']) && $event_data['criticality'] === 'high') {
				$option_name = isset($event_data['option_name']) ? $event_data['option_name'] : 'a critical setting';

				return array(
					'severity' => 'warning',
					'title' => 'Critical Setting Changed',
					'message' => sprintf(
						"WordPress setting '%s' was modified. Backup recommended.",
						esc_html($option_name)
					),
					'cta_backup' => true,
					'cta_upgrade' => self::generate_upgrade_cta('automatic setting change detection'),
				);
			}

			return null;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error(self::$log_name, __METHOD__, 'Error: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Rule 5: High Content Activity
	 *
	 * Triggers when 20+ content changes detected in an hour.
	 * Severity: INFO (blue notice)
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    array     $event_data    Decoded event data
	 * @return   array|null                Recommendation or null
	 */
	private static function rule_high_content_activity($event_data) {
		try {
			$post_count = isset($event_data['post_count']) ? (int) $event_data['post_count'] : 0;
			$page_count = isset($event_data['page_count']) ? (int) $event_data['page_count'] : 0;
			$total_changes = $post_count + $page_count;

			if ($total_changes >= 20) {
				return array(
					'severity' => 'info',
					'title' => 'High Content Activity Detected',
					'message' => sprintf(
						'%d content changes in the last hour. Consider backing up.',
						$total_changes
					),
					'cta_backup' => true,
					'cta_upgrade' => self::generate_upgrade_cta('smart content monitoring'),
				);
			}

			return null;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error(self::$log_name, __METHOD__, 'Error: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Generate upgrade CTA text
	 *
	 * Creates the upgrade call-to-action text with feature benefit.
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    string    $feature    Feature description
	 * @return   string                Complete CTA text
	 */
	private static function generate_upgrade_cta($feature = 'advanced features') {
		return sprintf(
			'Upgrade to Premium for %s',
			$feature
		);
	}

	/**
	 * Critical Plugins and Options Management
	 *
	 * These lists are currently hardcoded for performance and simplicity.
	 * Developers can customize via filter hooks (see method documentation below).
	 *
	 * TODO (Future Enhancement): Consider adding UI for customizing critical lists
	 * For now, developers can use filter hooks. May add admin UI if user demand justifies it.
	 */

	/**
	 * Get critical plugins list
	 *
	 * Returns hardcoded list of critical plugins that should trigger high-priority
	 * backup recommendations when updated. Developers can customize this list using
	 * the 'wpbackitup_critical_plugins' filter hook.
	 *
	 * @since    2.1.0
	 * @return   array    Associative array of plugin_file => plugin_name
	 *
	 * @example
	 * // Customize critical plugins list via filter hook
	 * add_filter('wpbackitup_critical_plugins', function($plugins) {
	 *     $plugins['my-plugin/my-plugin.php'] = 'My Critical Plugin';
	 *     return $plugins;
	 * });
	 */
	public static function get_critical_plugins() {
		$default_critical_plugins = array(
			'woocommerce/woocommerce.php' => 'WooCommerce',
			'easy-digital-downloads/easy-digital-downloads.php' => 'Easy Digital Downloads',
			'wordfence/wordfence.php' => 'Wordfence Security',
			'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' => 'Stripe Payment Gateway',
			'jetpack/jetpack.php' => 'Jetpack',
			'gravityforms/gravityforms.php' => 'Gravity Forms',
			'wp-rocket/wp-rocket.php' => 'WP Rocket',
			'akismet/akismet.php' => 'Akismet Anti-Spam',
		);

		/**
		 * Filter the list of critical plugins
		 *
		 * Allows developers to customize which plugins are considered critical
		 * and will trigger high-priority backup recommendations when updated.
		 *
		 * @since 2.1.0
		 * @param array $default_critical_plugins Associative array of plugin_file => plugin_name
		 */
		return apply_filters('wpbackitup_critical_plugins', $default_critical_plugins);
	}

	/**
	 * Get critical options list
	 *
	 * Returns hardcoded list of critical WordPress options that should trigger
	 * backup recommendations when modified. Developers can customize this list
	 * using the 'wpbackitup_critical_options' filter hook.
	 *
	 * @since    2.1.0
	 * @return   array    Array of option names
	 *
	 * @example
	 * // Customize critical options list via filter hook
	 * add_filter('wpbackitup_critical_options', function($options) {
	 *     $options[] = 'my_custom_critical_option';
	 *     return $options;
	 * });
	 */
	public static function get_critical_options() {
		$default_critical_options = array(
			'siteurl',
			'home',
			'permalink_structure',
			'active_plugins',
			'template',
			'stylesheet',
			'blogname',
			'users_can_register',
		);

		/**
		 * Filter the list of critical WordPress options
		 *
		 * Allows developers to customize which WordPress options are considered
		 * critical and will trigger backup recommendations when modified.
		 *
		 * @since 2.1.0
		 * @param array $default_critical_options Array of option names
		 */
		return apply_filters('wpbackitup_critical_options', $default_critical_options);
	}
}
