<?php if (!defined('ABSPATH')) die('No direct access allowed');

/**
 * WP BackItUp - Event Logger
 *
 * @package WP BackItUp
 * @author  Chris Simmons <chris.simmons@wpbackitup.com>
 * @link    http://www.wpbackitup.com
 */

/**
 * Event Logger
 *
 * Captures WordPress events (plugin updates, core updates, etc.) and logs them
 * to the events database table for backup recommendation analysis.
 *
 * @since      2.1.0
 * @package    WP_BackItUp
 * @subpackage WP_BackItUp/includes
 * @author     Chris Simmons <chris.simmons@wpbackitup.com>
 */
class WPBackItUp_Event_Logger {

	/**
	 * Event Type Constants
	 *
	 * Centralized event type definitions to prevent typos and ensure consistency.
	 * Used throughout the event system for logging, querying, and aggregation.
	 *
	 * @since 2.1.0
	 */

	// Raw Event Types (captured directly, may require aggregation)
	const EVENT_CONTENT_CHANGE_RAW = 'content_change_raw';
	const EVENT_SECURITY_EVENT_RAW = 'security_event_raw';

	// Reactive Event Types (logged when actions occur)
	const EVENT_PLUGIN_UPDATE = 'plugin_update';
	const EVENT_THEME_UPDATE = 'theme_update';
	const EVENT_CORE_UPDATE = 'core_update';
	const EVENT_SETTINGS_CHANGE = 'settings_change';

	// Proactive Event Types (logged by cron checks)
	const EVENT_UPDATE_AVAILABLE = 'update_available';

	// Aggregated Event Types (created by cron aggregation)
	const EVENT_CONTENT_CHANGE = 'content_change';
	const EVENT_SECURITY_EVENT = 'security_event';

	/**
	 * Singleton instance
	 *
	 * @since    2.1.0
	 * @access   private
	 * @var      WPBackItUp_Event_Logger    $instance    Singleton instance
	 */
	private static $instance = null;

	/**
	 * The logger instance
	 *
	 * @since    2.1.0
	 * @access   private
	 * @var      WPBackItUp_Logger    $logger    Logger instance
	 */
	private $logger;

	/**
	 * The log file name
	 *
	 * @since    2.1.0
	 * @access   private
	 * @var      string    $log_name    Log file name
	 */
	private $log_name;

	/**
	 * Get singleton instance
	 *
	 * Returns the single instance of the Event Logger, creating it if necessary.
	 * Follows the same singleton pattern as WPBackitup_Admin.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @return   WPBackItUp_Event_Logger    Singleton instance
	 */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the event logger (private constructor for singleton)
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    string    $log_name    Optional log file name
	 */
	private function __construct($log_name = null) {
		try {
			$this->log_name = 'debug_events';
			if (is_string($log_name) && !empty($log_name)) {
				$this->log_name = $log_name;
			}

			$this->logger = new WPBackItUp_Logger(false, null, $this->log_name);

			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Event Logger initialized');

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Constructor Exception: ' . $e->getMessage());
		}

		// Register hooks automatically on construction
		$this->register_hooks();
	}

	/**
	 * Prevent cloning of singleton
	 *
	 * @since    2.1.0
	 * @access   private
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization of singleton
	 *
	 * @since    2.1.0
	 * @access   public
	 */
	public function __wakeup() {
		throw new Exception("Cannot unserialize singleton");
	}

	/**
	 * Register WordPress hooks for event capture
	 *
	 * Registers hooks for all 7 event types:
	 * - Plugin updates (reactive)
	 * - Theme updates (reactive)
	 * - WordPress core updates (reactive)
	 * - Content changes (reactive)
	 * - Settings changes (reactive)
	 * - Failed login attempts (reactive)
	 * - Update checks (proactive - triggered by cron)
	 *
	 * @since    2.1.0
	 * @access   public
	 */
	public function register_hooks() {
		try {
			// Hook for plugin, theme, and core updates (all use same hook)
			add_action('upgrader_process_complete', array($this, 'log_plugin_update'), 10, 2);

			// Hook for content changes (posts and pages)
			add_action('save_post', array($this, 'log_content_change'), 10, 2);

			// Hook for settings changes
			add_action('updated_option', array($this, 'log_settings_change'), 10, 3);

			// Hook for failed login attempts
			add_action('wp_login_failed', array($this, 'track_failed_login'), 10, 1);

			// WP-Cron hooks (registered here, scheduled in WPBackitup_Admin::activate())
			// Timing is approximate - WP-Cron depends on site traffic, not exact scheduling
			add_action('wpbackitup_aggregate_content_changes', array($this, 'aggregate_content_changes'));
			add_action('wpbackitup_check_updates', array($this, 'check_for_updates'));
			add_action('wpbackitup_cleanup_old_events', array('WPBackItUp_Event_Database', 'cleanup_old_events'));
			add_action('wpbackitup_aggregate_security', array($this, 'aggregate_security_events'));

			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Event hooks registered successfully');

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error registering hooks: ' . $e->getMessage());
		}
	}

	/**
	 * Log plugin or core update event
	 *
	 * Captures plugin and core updates triggered by the upgrader_process_complete hook.
	 * Determines event type from $options['type'] and routes to appropriate handler.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @param    object    $upgrader    WP_Upgrader instance
	 * @param    array     $options     Update information
	 */
	public function log_plugin_update($upgrader, $options) {
		try {
			// Determine what type of update this is
			if (isset($options['type'])) {
				if ($options['type'] === 'plugin') {
					$this->handle_plugin_update($upgrader, $options);
				} elseif ($options['type'] === 'core') {
					$this->handle_core_update($upgrader, $options);
				} elseif ($options['type'] === 'theme') {
					$this->handle_theme_update($upgrader, $options);
				}
			}

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error logging update: ' . $e->getMessage());
		}
	}

	/**
	 * Handle plugin update event
	 *
	 * Extracts plugin update information and logs the event with deduplication.
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    object    $upgrader    WP_Upgrader instance
	 * @param    array     $options     Update information
	 */
	private function handle_plugin_update($upgrader, $options) {
		try {
			// Get plugin information from options
			if (!isset($options['plugins']) || empty($options['plugins'])) {
				return;
			}

			$plugins = $options['plugins'];
			if (!is_array($plugins)) {
				$plugins = array($plugins);
			}

			// Log each updated plugin
			foreach ($plugins as $plugin_file) {
				$plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);

				if (empty($plugin_data['Name'])) {
					continue;
				}

				// Build event data
				$event_data = array(
					'plugin_name' => $plugin_data['Name'],
					'plugin_file' => $plugin_file,
					'new_version' => $plugin_data['Version'],
					'is_critical' => $this->is_critical_plugin($plugin_file),
					'updated_at' => current_time('mysql'),
				);

				// Generate hash for deduplication (plugin_file + new_version)
				$hash_data = array(
					'plugin_file' => $plugin_file,
					'new_version' => $plugin_data['Version'],
				);

				// Log the event with 5-minute deduplication
				$this->log_event(self::EVENT_PLUGIN_UPDATE, $event_data, $hash_data, 300);

				WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Plugin update logged: ' . $plugin_data['Name']);
			}

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error handling plugin update: ' . $e->getMessage());
		}
	}

	/**
	 * Handle core update event
	 *
	 * Extracts WordPress core update information and logs the event.
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    object    $upgrader    WP_Upgrader instance
	 * @param    array     $options     Update information
	 */
	private function handle_core_update($upgrader, $options) {
		try {
			global $wp_version;

			// Build event data
			$event_data = array(
				'new_version' => $wp_version,
				'locale' => get_locale(),
				'updated_at' => current_time('mysql'),
			);

			// Generate hash for deduplication (new_version only)
			$hash_data = array(
				'new_version' => $wp_version,
			);

			// Log the event with 5-minute deduplication
			$this->log_event(self::EVENT_CORE_UPDATE, $event_data, $hash_data, 300);

			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Core update logged: WordPress ' . $wp_version);

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error handling core update: ' . $e->getMessage());
		}
	}

	/**
	 * Handle theme update event
	 *
	 * Extracts theme update information and logs the event.
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    object    $upgrader    WP_Upgrader instance
	 * @param    array     $options     Update information
	 */
	private function handle_theme_update($upgrader, $options) {
		try {
			// Get theme information from options
			if (!isset($options['themes']) || empty($options['themes'])) {
				return;
			}

			$themes = $options['themes'];
			if (!is_array($themes)) {
				$themes = array($themes);
			}

			// Log each updated theme
			foreach ($themes as $theme_slug) {
				$theme = wp_get_theme($theme_slug);

				if (!$theme->exists()) {
					continue;
				}

				// Build event data
				$event_data = array(
					'theme_name' => $theme->get('Name'),
					'theme_slug' => $theme_slug,
					'new_version' => $theme->get('Version'),
					'updated_at' => current_time('mysql'),
				);

				// Generate hash for deduplication (theme_slug + new_version)
				$hash_data = array(
					'theme_slug' => $theme_slug,
					'new_version' => $theme->get('Version'),
				);

				// Log the event with 5-minute deduplication
				$this->log_event(self::EVENT_THEME_UPDATE, $event_data, $hash_data, 300);

				WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Theme update logged: ' . $theme->get('Name'));
			}

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error handling theme update: ' . $e->getMessage());
		}
	}

	/**
	 * Log an event to the database
	 *
	 * Generic method to log any event type with deduplication support.
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    string    $event_type       Event type identifier
	 * @param    array     $event_data       Event data to store (will be JSON encoded)
	 * @param    array     $hash_data        Data to use for hash generation (for deduplication)
	 * @param    int       $window_seconds   Deduplication window in seconds (default: 300 = 5 minutes)
	 * @return   bool      True on success, false on failure
	 */
	private function log_event($event_type, $event_data, $hash_data, $window_seconds = 300) {
		global $wpdb;

		try {
			// Log method entry with parameters
			WPBackItUp_Logger::log_info(
				$this->log_name,
				__METHOD__,
				'log_event() called: type=' . $event_type . ', window=' . $window_seconds . 's'
			);

			$table_name = $wpdb->prefix . 'wpbackitup_events';
			$current_time = current_time('mysql');

			// Generate event hash for deduplication
			$event_hash = $this->generate_event_hash($event_type, $hash_data);

			// Calculate deduplication time window
			$window_start = date('Y-m-d H:i:s', strtotime('-' . $window_seconds . ' seconds', strtotime($current_time)));

			// Log deduplication window and hash
			WPBackItUp_Logger::log_info(
				$this->log_name,
				__METHOD__,
				'Deduplication check: hash=' . $event_hash . ', window=' . $window_start . ' to ' . $current_time . ' (' . $window_seconds . 's)'
			);

			// Check for duplicate - inline for detailed logging
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name
				 WHERE event_hash = %s
				 AND timestamp > %s",
				$event_hash,
				$window_start
			);

			// Log the full SQL query
			WPBackItUp_Logger::log_info(
				$this->log_name,
				__METHOD__,
				'Deduplication SQL: ' . $sql
			);

			// Execute deduplication query
			$duplicate_count = $wpdb->get_var($sql);

			// Log query result
			WPBackItUp_Logger::log_info(
				$this->log_name,
				__METHOD__,
				'Deduplication result: found ' . (int) $duplicate_count . ' duplicate(s)'
			);

			// If duplicate found, skip insert
			if ($duplicate_count > 0) {
				WPBackItUp_Logger::log_warning(
					$this->log_name,
					__METHOD__,
					'Duplicate event detected, skipping insert: type=' . $event_type . ', hash=' . $event_hash . ', count=' . (int) $duplicate_count
				);
				return false;
			}

			// Log INSERT intent
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'No duplicate found, inserting event into database');

			// Insert event into database
			$result = $wpdb->insert(
				$table_name,
				array(
					'event_type' => $event_type,
					'event_data' => wp_json_encode($event_data),
					'event_hash' => $event_hash,
					'timestamp' => $current_time,
				),
				array('%s', '%s', '%s', '%s')
			);

			if (false === $result) {
				throw new Exception('Database insert failed: ' . $wpdb->last_error);
			}

			// Log INSERT success with insert ID
			WPBackItUp_Logger::log_info(
				$this->log_name,
				__METHOD__,
				'Event logged successfully: type=' . $event_type . ', insert_id=' . $wpdb->insert_id . ', hash=' . $event_hash
			);
			return true;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error logging event: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Check if event is a duplicate within the time window
	 *
	 * Queries the database for events with the same hash within the specified
	 * time window to prevent duplicate event logging.
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    string    $event_hash       MD5 hash of event
	 * @param    int       $window_seconds   Time window in seconds
	 * @return   bool      True if duplicate found, false otherwise
	 */
	private function is_duplicate_event($event_hash, $window_seconds) {
		global $wpdb;

		try {
			$table_name = $wpdb->prefix . 'wpbackitup_events';

			$sql = $wpdb->prepare(
				"SELECT id FROM $table_name
				 WHERE event_hash = %s
				 AND timestamp > DATE_SUB(NOW(), INTERVAL %d SECOND)
				 LIMIT 1",
				$event_hash,
				$window_seconds
			);

			$exists = $wpdb->get_var($sql);

			return !empty($exists);

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error checking duplicate: ' . $e->getMessage());
			return false; // If error checking, allow the event to be logged
		}
	}

	/**
	 * Generate MD5 hash for event deduplication
	 *
	 * Creates a unique hash based on event type and key data fields.
	 * Used to prevent duplicate events from being logged within the time window.
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    string    $event_type    Event type identifier
	 * @param    array     $key_data      Key data fields for hash generation
	 * @return   string    MD5 hash (32 characters)
	 */
	private function generate_event_hash($event_type, $key_data) {
		try {
			// Combine event type and key data into string for hashing
			$hash_string = $event_type . '|' . serialize($key_data);

			// Generate MD5 hash
			$hash = md5($hash_string);

			return $hash;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error generating hash: ' . $e->getMessage());
			// Return a fallback hash to allow event logging
			return md5($event_type . time());
		}
	}

	/**
	 * Check if plugin is in the critical plugins list
	 *
	 * Checks if the given plugin file is in the list of critical plugins
	 * that require special attention for backup recommendations.
	 * Uses WordPress option storage to allow user customization.
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    string    $plugin_file    Plugin file path (e.g., 'woocommerce/woocommerce.php')
	 * @return   bool      True if critical, false otherwise
	 */
	private function is_critical_plugin($plugin_file) {
		try {
			$critical_plugins = $this->get_critical_plugins();
			return array_key_exists($plugin_file, $critical_plugins);

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error checking critical plugin: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get critical plugins list
	 *
	 * Returns the list of critical plugins that should trigger
	 * backup recommendations when updated. Uses WordPress option storage
	 * to allow user customization.
	 *
	 * @since    2.1.0
	 * @access   private
	 * @return   array    Associative array of plugin file => plugin name
	 */
	private function get_critical_plugins() {
		try {
			// Default critical plugins
			$default_plugins = array(
				'woocommerce/woocommerce.php' => 'WooCommerce',
				'easy-digital-downloads/easy-digital-downloads.php' => 'Easy Digital Downloads',
				'wordfence/wordfence.php' => 'Wordfence Security',
				'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' => 'Stripe Payment Gateway',
				'jetpack/jetpack.php' => 'Jetpack',
				'gravityforms/gravityforms.php' => 'Gravity Forms',
				'wp-rocket/wp-rocket.php' => 'WP Rocket',
				'akismet/akismet.php' => 'Akismet Anti-Spam',
			);

			// Get from WordPress options (seeded on activation in Phase 4)
			$critical_plugins = get_option(WPBACKITUP__NAMESPACE . '_critical_plugins', $default_plugins);

			// If empty or not array, use defaults
			if (empty($critical_plugins) || !is_array($critical_plugins)) {
				$critical_plugins = $default_plugins;
			}

			// Allow filtering by developers
			$critical_plugins = apply_filters('wpbackitup_critical_plugins', $critical_plugins);

			return $critical_plugins;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error getting critical plugins: ' . $e->getMessage());
			// Return default plugins on error
			return array(
				'woocommerce/woocommerce.php' => 'WooCommerce',
				'wordfence/wordfence.php' => 'Wordfence Security',
			);
		}
	}

	/**
	 * Log content change event (raw)
	 *
	 * Captures individual post/page save events as raw data for later hourly aggregation.
	 * Only logs published posts and pages, ignoring revisions, auto-drafts, and autosaves.
	 *
	 * @since    2.1.0
	 * @access   public
	 * @param    int       $post_id    Post ID being saved
	 * @param    WP_Post   $post       Post object
	 */
	public function log_content_change($post_id, $post) {
		try {
			// Skip if this is an autosave or revision
			if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
				return;
			}

			// Only log published posts and pages
			if ($post->post_status !== 'publish') {
				return;
			}

			// Only log posts and pages
			if (!in_array($post->post_type, array('post', 'page'))) {
				return;
			}

			// Build event data (minimal - just post_type and timestamp)
			$event_data = array(
				'post_type' => $post->post_type,
				'changed_at' => current_time('mysql'),
			);

			// Generate hash for deduplication (post_id + changed_at to minute precision)
			// This prevents duplicate logging from multiple saves within same minute
			$hash_data = array(
				'post_id' => $post_id,
				'changed_at' => date('Y-m-d H:i', strtotime(current_time('mysql'))),
			);

			// Log the raw event with 5-minute deduplication
			$this->log_event(self::EVENT_CONTENT_CHANGE_RAW, $event_data, $hash_data, 300);

			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Content change logged: ' . $post->post_type . ' (ID: ' . $post_id . ')');

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error logging content change: ' . $e->getMessage());
		}
	}

	/**
	 * Check for available updates (plugins, themes, core)
	 *
	 * Proactively checks for available WordPress updates and logs each as a separate event.
	 * Called by daily cron job. Uses 7-day deduplication to prevent daily spam.
	 *
	 * @since    2.1.0
	 * @access   public
	 */
	public function check_for_updates() {
		try {
			// DEBUG: Log method start
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Starting update check - forcing WordPress to refresh update data');

			// Force WordPress to check for updates
			wp_update_plugins();
			wp_update_themes();
			wp_version_check();

			// DEBUG: Log API calls complete
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'WordPress update checks complete - fetching transient data');

			$update_count = 0;

			// Check for plugin updates
			$plugin_updates = get_site_transient('update_plugins');

			// DEBUG: Log plugin update count
			$plugin_update_count = !empty($plugin_updates->response) ? count($plugin_updates->response) : 0;
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Plugin updates found: ' . $plugin_update_count);
			if (!empty($plugin_updates->response)) {
				foreach ($plugin_updates->response as $plugin_file => $plugin_data) {
					// Get plugin info
					$plugin_info = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);

					// Determine criticality
					$criticality = $this->is_critical_plugin($plugin_file) ? 'high' : 'medium';

					// Build event data
					$event_data = array(
						'item_type' => 'plugin',
						'item_slug' => $plugin_file,
						'item_name' => $plugin_info['Name'],
						'current_version' => $plugin_info['Version'],
						'new_version' => $plugin_data->new_version,
						'criticality' => $criticality,
					);

					// Generate hash for 7-day deduplication
					$hash_data = array(
						'item_type' => 'plugin',
						'item_slug' => $plugin_file,
						'new_version' => $plugin_data->new_version,
					);

					// DEBUG: Log plugin update attempt
					WPBackItUp_Logger::log_info($this->log_name, __METHOD__,
						'Attempting to log plugin update: ' . $plugin_info['Name'] . ' (slug: ' . $plugin_file . ')');

					// Log event with 7-day deduplication
					if ($this->log_event(self::EVENT_UPDATE_AVAILABLE, $event_data, $hash_data, 604800)) {
						$update_count++;
					}
				}
			}

			// Check for theme updates
			$theme_updates = get_site_transient('update_themes');

			// DEBUG: Log theme update count
			$theme_update_count = !empty($theme_updates->response) ? count($theme_updates->response) : 0;
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Theme updates found: ' . $theme_update_count);

			if (!empty($theme_updates->response)) {
				foreach ($theme_updates->response as $theme_slug => $theme_data) {
					// Get theme info
					$theme = wp_get_theme($theme_slug);

					// Build event data
					$event_data = array(
						'item_type' => 'theme',
						'item_slug' => $theme_slug,
						'item_name' => $theme->get('Name'),
						'current_version' => $theme->get('Version'),
						'new_version' => $theme_data['new_version'],
						'criticality' => 'medium',
					);

					// Generate hash for 7-day deduplication
					$hash_data = array(
						'item_type' => 'theme',
						'item_slug' => $theme_slug,
						'new_version' => $theme_data['new_version'],
					);

					// DEBUG: Log theme update attempt
					WPBackItUp_Logger::log_info($this->log_name, __METHOD__,
						'Attempting to log theme update: ' . $theme_slug);

					// Log event with 7-day deduplication
					if ($this->log_event(self::EVENT_UPDATE_AVAILABLE, $event_data, $hash_data, 604800)) {
						$update_count++;
					}
				}
			}

			// Check for core updates
			$core_updates = get_site_transient('update_core');

			// Count only actual upgrades (not 'latest' or 'development')
			$upgrade_count = 0;
			$total_items = !empty($core_updates->updates) ? count($core_updates->updates) : 0;
			if (!empty($core_updates->updates)) {
				foreach ($core_updates->updates as $update) {
					if (isset($update->response) && $update->response === 'upgrade') {
						$upgrade_count++;
					}
				}
			}

			// Log accurate count
			$count_message = 'Core updates found: ' . $upgrade_count . ' actual upgrade' . ($upgrade_count !== 1 ? 's' : '');
			if ($upgrade_count !== $total_items && $total_items > 0) {
				$count_message .= ' (get_core_updates returned ' . $total_items . ' total items)';
			}
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, $count_message);

			if (!empty($core_updates->updates)) {
				global $wp_version;
				WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Examining core update items (current WP version: ' . $wp_version . ')');

				foreach ($core_updates->updates as $core_update) {
					// DEBUG: Log each update item details
					WPBackItUp_Logger::log_info($this->log_name, __METHOD__,
						'Core update item - Version: ' . $core_update->version .
						', Response: ' . $core_update->response .
						', Current: ' . $wp_version);

					// Only log actual updates, not 'latest' status
					if ($core_update->response !== 'upgrade') {
						WPBackItUp_Logger::log_info($this->log_name, __METHOD__,
							'Skipping non-upgrade item: response=' . $core_update->response . ' (not "upgrade")');
						continue;
					}

					// Build event data
					$event_data = array(
						'item_type' => 'core',
						'item_slug' => 'wordpress',
						'item_name' => 'WordPress',
						'current_version' => $wp_version,
						'new_version' => $core_update->version,
						'criticality' => 'high',
					);

					// Generate hash for 7-day deduplication
					$hash_data = array(
						'item_type' => 'core',
						'item_slug' => 'wordpress',
						'new_version' => $core_update->version,
					);

					// DEBUG: Log core update attempt
					WPBackItUp_Logger::log_info($this->log_name, __METHOD__,
						'Attempting to log core update: WordPress ' . $core_update->version);

					// Log event with 7-day deduplication
					if ($this->log_event(self::EVENT_UPDATE_AVAILABLE, $event_data, $hash_data, 604800)) {
						$update_count++;
					}

					// Only log the first core update (highest priority)
					break;
				}
			}

			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Update check complete. Found ' . $update_count . ' new updates.');

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error checking for updates: ' . $e->getMessage());
		}
	}

	/**
	 * Track failed login attempt (raw)
	 *
	 * Captures individual failed login attempts as raw data for later daily aggregation.
	 * NO usernames or IP addresses are stored (privacy requirement).
	 *
	 * @since    2.1.0
	 * @access   public
	 * @param    string    $username    Username that failed login (not stored)
	 */
	public function track_failed_login($username) {
		try {
			// Build event data (NO username, NO IP - just timestamp)
			$event_data = array(
				'failed_at' => current_time('mysql'),
			);

			// Generate hash for deduplication (timestamp to minute precision)
			// This prevents logging duplicate attempts within same minute
			$hash_data = array(
				'failed_at' => date('Y-m-d H:i', strtotime(current_time('mysql'))),
				'random' => wp_rand(1, 10000), // Add randomness to allow multiple per minute
			);

			// Log the raw event with 5-minute deduplication
			$this->log_event(self::EVENT_SECURITY_EVENT_RAW, $event_data, $hash_data, 300);

			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Failed login tracked (no sensitive data stored)');

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error tracking failed login: ' . $e->getMessage());
		}
	}

	/**
	 * Log critical settings change event
	 *
	 * Captures WordPress option changes for critical settings.
	 * NO old_value or new_value stored (privacy/security requirement).
	 *
	 * @since    2.1.0
	 * @access   public
	 * @param    string    $option      Option name being changed
	 * @param    mixed     $old_value   Old value (not stored)
	 * @param    mixed     $new_value   New value (not stored)
	 */
	public function log_settings_change($option, $old_value, $new_value) {
		try {
			// Only log critical options
			if (!$this->is_critical_option($option)) {
				return;
			}

			// Skip if values are the same (no actual change)
			if ($old_value === $new_value) {
				return;
			}

			// Generate human-readable change description
			$change_descriptions = array(
				'siteurl' => 'Site URL changed',
				'home' => 'Home URL changed',
				'permalink_structure' => 'Permalink structure changed',
				'active_plugins' => 'Active plugins list changed',
				'template' => 'Active theme template changed',
				'stylesheet' => 'Active theme stylesheet changed',
				'blogname' => 'Site title changed',
				'users_can_register' => 'User registration setting changed',
			);

			$change_description = isset($change_descriptions[$option])
				? $change_descriptions[$option]
				: ucwords(str_replace('_', ' ', $option)) . ' changed';

			// Build event data (NO old_value, NO new_value)
			$event_data = array(
				'option_name' => $option,
				'change_description' => $change_description,
				'criticality' => 'high',
				'changed_at' => current_time('mysql'),
			);

			// Generate hash for deduplication (option_name + timestamp to minute precision)
			$hash_data = array(
				'option_name' => $option,
				'changed_at' => date('Y-m-d H:i', strtotime(current_time('mysql'))),
			);

			// Log the event with 5-minute deduplication
			$this->log_event(self::EVENT_SETTINGS_CHANGE, $event_data, $hash_data, 300);

			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Settings change logged: ' . $option . ' (no values stored)');

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error logging settings change: ' . $e->getMessage());
		}
	}

	/**
	 * Get critical WordPress options list
	 *
	 * Returns the list of critical WordPress options that should trigger
	 * backup recommendations when changed. Uses WordPress option storage
	 * to allow user customization.
	 *
	 * @since    2.1.0
	 * @access   private
	 * @return   array    Array of critical option names
	 */
	private function get_critical_options() {
		try {
			// Default critical options
			$default_options = array(
				'siteurl',
				'home',
				'permalink_structure',
				'active_plugins',
				'template',
				'stylesheet',
				'blogname',
				'users_can_register',
			);

			// Get from WordPress options (seeded on activation in Phase 4)
			$critical_options = get_option(WPBACKITUP__NAMESPACE . '_critical_options', $default_options);

			// If empty or not array, use defaults
			if (empty($critical_options) || !is_array($critical_options)) {
				$critical_options = $default_options;
			}

			// Allow filtering by developers
			$critical_options = apply_filters('wpbackitup_critical_options', $critical_options);

			return $critical_options;

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error getting critical options: ' . $e->getMessage());
			// Return default options on error
			return array('siteurl', 'home', 'permalink_structure', 'active_plugins', 'template', 'stylesheet');
		}
	}

	/**
	 * Check if option is in the critical options list
	 *
	 * Checks if the given option name is in the list of critical options
	 * that require special attention for backup recommendations.
	 *
	 * @since    2.1.0
	 * @access   private
	 * @param    string    $option_name    WordPress option name
	 * @return   bool      True if critical, false otherwise
	 */
	private function is_critical_option($option_name) {
		try {
			$critical_options = $this->get_critical_options();
			return in_array($option_name, $critical_options);

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error checking critical option: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Aggregate content changes (hourly WP-Cron job)
	 *
	 * Aggregates all raw content_change events from the last hour into a single
	 * summary event. Counts posts and pages separately, then deletes the raw events.
	 * This reduces database bloat while preserving meaningful metrics.
	 *
	 * Called by: wpbackitup_aggregate_content_changes WP-Cron hook (hourly)
	 * Timing: Approximate - WP-Cron depends on site traffic, not exact scheduling
	 *
	 * @since    2.1.0
	 * @access   public
	 */
	public function aggregate_content_changes() {
		global $wpdb;

		try {
			$table_name = $wpdb->prefix . 'wpbackitup_events';

			// Get period bounds (last complete hour)
			// Use current_time('mysql') to match WordPress timezone used in event insertion
			$period_end = date('Y-m-d H:00:00', strtotime(current_time('mysql'))); // Top of current hour
			$period_start = date('Y-m-d H:00:00', strtotime('-1 hour', strtotime(current_time('mysql')))); // Top of previous hour

			// DEBUG: Log the time window being checked
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Checking time window: ' . $period_start . ' to ' . $period_end);

			// DEBUG: Log current time reference
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Current time for calculation: ' . current_time('mysql'));

			// Get all raw content_change events from last hour
			$sql = $wpdb->prepare(
				"SELECT id, event_data FROM $table_name
				 WHERE event_type = %s
				 AND timestamp >= %s
				 AND timestamp < %s",
				self::EVENT_CONTENT_CHANGE_RAW,
				$period_start,
				$period_end
			);

			// DEBUG: Log the SQL query
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'SQL Query: ' . $sql);

			$raw_events = $wpdb->get_results($sql);

			// DEBUG: Log result count
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Raw events found: ' . count($raw_events));

			// If no events, nothing to aggregate
			if (empty($raw_events)) {
				WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'No content changes to aggregate in last hour');
				return;
			}

			// DEBUG: Log first event details to verify timezone alignment
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'First event ID: ' . $raw_events[0]->id);
			$first_event_data = json_decode($raw_events[0]->event_data, true);
			if (isset($first_event_data['timestamp'])) {
				WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'First event data timestamp: ' . $first_event_data['timestamp']);
			}

			// Query to get actual timestamp from database
			$first_event_timestamp = $wpdb->get_var($wpdb->prepare(
				"SELECT timestamp FROM $table_name WHERE id = %d",
				$raw_events[0]->id
			));
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'First event DB timestamp: ' . $first_event_timestamp);

			// Count posts and pages
			$post_count = 0;
			$page_count = 0;
			$event_ids = array();

			foreach ($raw_events as $event) {
				$event_data = json_decode($event->event_data, true);
				if ($event_data) {
					if (isset($event_data['post_type'])) {
						if ($event_data['post_type'] === 'post') {
							$post_count++;
						} elseif ($event_data['post_type'] === 'page') {
							$page_count++;
						}
					}
					$event_ids[] = $event->id;
				}
			}

			// Only create aggregated event if we have changes
			if ($post_count > 0 || $page_count > 0) {
				// Build aggregated event data
				$aggregated_data = array(
					'post_count' => $post_count,
					'page_count' => $page_count,
					'period_start' => $period_start,
					'period_end' => $period_end,
				);

				// Build hash data for deduplication (prevents duplicate aggregations for same period)
				$hash_data = array(
					'period_start' => $period_start,
					'period_end' => $period_end
				);

				// Log aggregated event with 5-minute deduplication
				$this->log_event(self::EVENT_CONTENT_CHANGE, $aggregated_data, $hash_data, 300);

				// Delete raw events
				if (!empty($event_ids)) {
					$ids_string = implode(',', array_map('absint', $event_ids));
					$delete_sql = "DELETE FROM $table_name WHERE id IN ($ids_string)";
					$deleted = $wpdb->query($delete_sql);

					WPBackItUp_Logger::log_info(
						$this->log_name,
						__METHOD__,
						"Aggregated $post_count posts and $page_count pages, deleted $deleted raw events"
					);
				}
			}

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error aggregating content changes: ' . $e->getMessage());
		}
	}

	/**
	 * Aggregate security events (daily WP-Cron job)
	 *
	 * Aggregates all raw failed login events from the last 24 hours into a single
	 * summary event with total count and severity. Deletes raw events after aggregation.
	 * NO IP addresses or usernames are stored (privacy requirement).
	 *
	 * Severity calculation: 'high' if count >= 50, 'medium' otherwise
	 *
	 * Called by: wpbackitup_aggregate_security WP-Cron hook (daily)
	 * Timing: Approximate (typically runs at ~4am) - WP-Cron depends on site traffic
	 *
	 * @since    2.1.0
	 * @access   public
	 */
	public function aggregate_security_events() {
		global $wpdb;

		try {
			$table_name = $wpdb->prefix . 'wpbackitup_events';

			// Get period bounds (last complete 24 hours)
			// Use current_time('mysql') to match WordPress timezone used in event insertion
			$period_end = date('Y-m-d 00:00:00', strtotime(current_time('mysql'))); // Start of today
			$period_start = date('Y-m-d 00:00:00', strtotime('-1 day', strtotime(current_time('mysql')))); // Start of yesterday

			// DEBUG: Log the time window being checked
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Checking time window: ' . $period_start . ' to ' . $period_end);

			// DEBUG: Log current time reference
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Current time for calculation: ' . current_time('mysql'));

			// Get all raw failed_login events from yesterday
			$sql = $wpdb->prepare(
				"SELECT id FROM $table_name
				 WHERE event_type = %s
				 AND timestamp >= %s
				 AND timestamp < %s",
				self::EVENT_SECURITY_EVENT_RAW,
				$period_start,
				$period_end
			);

			// DEBUG: Log the SQL query
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'SQL Query: ' . $sql);

			$raw_events = $wpdb->get_results($sql);

			// DEBUG: Log result count
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Raw security events found: ' . count($raw_events));

			// If no events, nothing to aggregate
			if (empty($raw_events)) {
				WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'No security events to aggregate from yesterday');
				return;
			}

			// DEBUG: Log first event details to verify timezone alignment
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'First event ID: ' . $raw_events[0]->id);

			$failed_login_count = count($raw_events);

			// Determine severity (high if >= 50 attempts, medium otherwise)
			$severity = ($failed_login_count >= 50) ? 'high' : 'medium';

			// DEBUG: Log severity calculation
			WPBackItUp_Logger::log_info($this->log_name, __METHOD__, 'Severity calculated: ' . $severity . ' (count: ' . $failed_login_count . ')');

			// Build aggregated event data
			$aggregated_data = array(
				'event_subtype' => 'failed_logins',
				'count' => $failed_login_count,
				'severity' => $severity,
				'period_start' => $period_start,
				'period_end' => $period_end,
			);

			// Build hash data for deduplication (prevents duplicate aggregations for same period)
			$hash_data = array(
				'period_start' => $period_start,
				'period_end' => $period_end
			);

			// Log aggregated event with 5-minute deduplication
			$this->log_event(self::EVENT_SECURITY_EVENT, $aggregated_data, $hash_data, 300);

			// Delete raw events
			$event_ids = array_map(function($event) { return $event->id; }, $raw_events);
			if (!empty($event_ids)) {
				$ids_string = implode(',', array_map('absint', $event_ids));
				$delete_sql = "DELETE FROM $table_name WHERE id IN ($ids_string)";
				$deleted = $wpdb->query($delete_sql);

				WPBackItUp_Logger::log_info(
					$this->log_name,
					__METHOD__,
					"Aggregated $failed_login_count failed logins (severity: $severity), deleted $deleted raw events"
				);
			}

		} catch (Exception $e) {
			error_log($e);
			WPBackItUp_Logger::log_error($this->log_name, __METHOD__, 'Error aggregating security events: ' . $e->getMessage());
		}
	}
}
