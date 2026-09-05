<?php
namespace RankMathPro\Dependencies\Groupone\Marketplace\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brand-agnostic plugin operations shared by the AJAX handlers (web UI) and the
 * MCP abilities. Pure operations: no nonce, no capability checks, no JSON output.
 * Callers own auth (nonce for AJAX, permission_callback for abilities) and own
 * response formatting; this class only performs the action and returns a
 * normalized result array.
 *
 * Result shape:
 *   [
 *     'success'   => bool,    // did the operation succeed
 *     'code'      => string,  // machine-readable outcome (see constants below)
 *     'message'   => string,  // human-readable, translated
 *     'installed' => bool,    // post-state on disk
 *     'activated' => bool,    // post-state active
 *   ]
 */
class PluginService {

	/**
	 * Install a plugin from a download URL.
	 *
	 * The brand-specific work (resolving a slug to a download URL via the brand
	 * catalog) happens before this call; install itself is brand-agnostic.
	 *
	 * @param string $slug         Plugin slug (used for idempotency + locking).
	 * @param string $download_url Package URL resolved from the catalog.
	 * @return array Normalized result.
	 */
	public static function install( string $slug, string $download_url ): array {
		if ( empty( $slug ) || empty( $download_url ) ) {
			return self::result( false, 'invalid_data', __( 'Invalid plugin data.', 'onecom-wp' ), false, false );
		}

		// Idempotency: if the plugin is already on disk (sibling tab finished, another
		// consumer plugin installed it, a prior attempt succeeded), be honest rather
		// than report a fresh success.
		if ( self::is_installed( $slug ) ) {
			// Match the legacy handler exactly: report installed without probing
			// activation state (the frontend keys off 'installed' here).
			return self::result( false, 'plugin_already_installed', __( 'Plugin is already installed.', 'onecom-wp' ), true, false );
		}

		// Serialize concurrent installs of the same plugin. Without this, two parallel
		// install requests can both pass the pre-check, both download the same package,
		// and race on cleanup. The lock has a TTL so a crashed request can't permanently
		// block future installs; we delete it explicitly on every exit path.
		$lock_key = "marketplace_install_lock_{$slug}";
		if ( get_transient( $lock_key ) ) {
			return self::result( false, 'install_in_progress', __( 'An install is already in progress for this plugin. Please wait a moment and try again.', 'onecom-wp' ), false, false );
		}
		set_transient( $lock_key, time(), 120 );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $download_url );

		if ( is_wp_error( $result ) ) {
			delete_transient( $lock_key );
			return self::result( false, 'install_failed', $result->get_error_message(), self::is_installed( $slug ), false );
		}

		// No WP_Error: if the plugin is now on disk, this request's upgrader put it
		// there. Plugin_Upgrader::install() can legitimately return false/null on
		// benign hiccups, so is_installed() is the source of truth.
		if ( self::is_installed( $slug ) ) {
			delete_transient( $lock_key );
			return self::result( true, 'installed', __( 'Plugin installed successfully', 'onecom-wp' ), true, false );
		}

		// Plugin is not on disk — only now treat null/false as a real failure.
		if ( $result === null || $result === false ) {
			delete_transient( $lock_key );
			$skin_messages = method_exists( $upgrader->skin, 'get_upgrade_messages' ) ? $upgrader->skin->get_upgrade_messages() : [];
			$skin_errors   = isset( $upgrader->skin->errors ) ? $upgrader->skin->errors : null;
			error_log( '[Marketplace] install failed for ' . $slug . ' (upgrader returned ' . var_export( $result, true ) . '); URL: ' . $download_url . '; skin messages: ' . wp_json_encode( $skin_messages ) . '; skin errors: ' . wp_json_encode( $skin_errors ) );
			return self::result( false, 'install_failed_download', __( 'Plugin installation failed. Unable to download or extract the plugin. The download URL may be invalid or inaccessible.', 'onecom-wp' ), false, false );
		}

		delete_transient( $lock_key );
		return self::result( false, 'install_failed_not_found', __( 'Plugin installation failed. The plugin was not found after installation.', 'onecom-wp' ), false, false );
	}

	/**
	 * Activate an installed plugin by slug.
	 *
	 * @param string $slug
	 * @return array Normalized result.
	 */
	public static function activate( string $slug ): array {
		if ( empty( $slug ) ) {
			return self::result( false, 'invalid_slug', __( 'Missing plugin slug.', 'onecom-wp' ), false, false );
		}

		if ( ! self::is_installed( $slug ) ) {
			return self::result( false, 'not_installed', __( 'Plugin not installed.', 'onecom-wp' ), false, false );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin_file = self::resolve_plugin_file_by_slug( $slug );

		if ( empty( $plugin_file ) ) {
			return self::result( false, 'file_not_found', __( 'Plugin file not found.', 'onecom-wp' ), true, false );
		}

		// Rank Math Pro depends on Rank Math Free — activating Pro auto-activates Free.
		if ( $plugin_file === 'seo-by-rank-math-pro/rank-math-pro.php' ) {
			$free_plugin_file = 'seo-by-rank-math/rank-math.php';
			if ( self::is_installed( 'seo-by-rank-math' ) && ! is_plugin_active( $free_plugin_file ) ) {
				activate_plugin( $free_plugin_file );
			}
		}

		$result = activate_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return self::result( false, 'activate_failed', $result->get_error_message(), true, false );
		}

		return self::result( true, 'activated', __( 'Plugin activated successfully.', 'onecom-wp' ), true, true );
	}

	/**
	 * Deactivate an installed plugin by slug.
	 *
	 * @param string $slug
	 * @return array Normalized result.
	 */
	public static function deactivate( string $slug ): array {
		if ( empty( $slug ) ) {
			return self::result( false, 'invalid_slug', __( 'Invalid plugin slug', 'onecom-wp' ), false, false );
		}

		if ( ! self::is_installed( $slug ) ) {
			return self::result( false, 'not_installed', __( 'Plugin not installed', 'onecom-wp' ), false, false );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin_file = self::resolve_plugin_file_by_slug( $slug );

		// Rank Math Free is a dependency of Pro — deactivating Free auto-deactivates Pro.
		if ( $plugin_file === 'seo-by-rank-math/rank-math.php' ) {
			if ( is_plugin_active( 'seo-by-rank-math-pro/rank-math-pro.php' ) ) {
				deactivate_plugins( 'seo-by-rank-math-pro/rank-math-pro.php' );
			}
		}

		if ( empty( $plugin_file ) ) {
			return self::result( false, 'file_not_found', __( 'Plugin file not found', 'onecom-wp' ), true, false );
		}

		// Ensure the plugin is loaded so its deactivation hooks are registered.
		if ( is_plugin_active( $plugin_file ) ) {
			include_once WP_PLUGIN_DIR . '/' . $plugin_file;
		}

		deactivate_plugins( $plugin_file, false, null );

		if ( is_plugin_active( $plugin_file ) ) {
			return self::result( false, 'deactivate_failed', __( 'Failed to deactivate plugin', 'onecom-wp' ), true, true );
		}

		return self::result( true, 'deactivated', __( 'Plugin deactivated successfully', 'onecom-wp' ), true, false );
	}

	/**
	 * Delete an installed (and inactive) plugin by slug.
	 *
	 * @param string $slug
	 * @return array Normalized result.
	 */
	public static function delete( string $slug ): array {
		if ( empty( $slug ) ) {
			return self::result( false, 'invalid_slug', __( 'Invalid plugin slug', 'onecom-wp' ), false, false );
		}

		if ( ! self::is_installed( $slug ) ) {
			return self::result( false, 'not_installed', __( 'Plugin not installed', 'onecom-wp' ), false, false );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin_file = self::resolve_plugin_file_by_slug( $slug );

		if ( empty( $plugin_file ) ) {
			return self::result( false, 'file_not_found', __( 'Plugin file not found', 'onecom-wp' ), true, false );
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return self::result( false, 'cannot_delete_active', __( 'Cannot delete an active plugin. Please deactivate it first.', 'onecom-wp' ), true, true );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$result = delete_plugins( [ $plugin_file ] );

		if ( is_wp_error( $result ) ) {
			return self::result( false, 'delete_failed', $result->get_error_message(), true, false );
		}

		if ( $result === false ) {
			return self::result( false, 'delete_failed', __( 'Failed to delete plugin', 'onecom-wp' ), true, false );
		}

		return self::result( true, 'deleted', __( 'Plugin deleted successfully', 'onecom-wp' ), false, false );
	}

	/**
	 * Whether a plugin is installed on disk. Accepts a slug or a full plugin file.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function is_installed( string $slug = '' ): bool {
		if ( empty( $slug ) ) {
			return false;
		}

		// If slug contains a slash, it's likely a full plugin file path like 'dirname/filename.php'
		if ( strpos( $slug, '/' ) !== false ) {
			$plugin_file_path = WP_PLUGIN_DIR . '/' . $slug;
			if ( file_exists( $plugin_file_path ) ) {
				return true;
			}

			$plugin_dir = dirname( $plugin_file_path );
			if ( file_exists( $plugin_dir ) && is_dir( $plugin_dir ) ) {
				return true;
			}

			return false;
		}

		// For simple slugs, check if directory exists
		$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
		if ( file_exists( $plugin_dir ) && is_dir( $plugin_dir ) ) {
			return true;
		}

		// Also check if it's a single-file plugin (slug.php)
		$plugin_file = WP_PLUGIN_DIR . '/' . $slug . '.php';
		if ( file_exists( $plugin_file ) ) {
			return true;
		}

		// Fallback: scan installed plugins for partial matches, e.g. slug "rank-math-pro"
		// matching "seo-by-rank-math-pro/rank-math-pro.php" (file name).
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();

		foreach ( $plugins as $file => $data ) {
			$parts = explode( '/', $file );
			if ( count( $parts ) === 2 ) {
				$directory = $parts[0];
				$main_file = $parts[1];

				if ( $directory === $slug ) {
					return true;
				}

				$file_slug = str_replace( '.php', '', $main_file );
				if ( $file_slug === $slug ) {
					return true;
				}
			} elseif ( count( $parts ) === 1 ) {
				$file_slug = str_replace( '.php', '', $parts[0] );
				if ( $file_slug === $slug ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Resolve a plugin's main file by slug by scanning installed plugins.
	 * Handles cases like 'seo-by-rank-math/rank-math.php' where the main file
	 * does not match slug/slug.php.
	 *
	 * @param string $slug
	 * @return string Plugin file path relative to plugins dir, or '' if not found.
	 */
	public static function resolve_plugin_file_by_slug( string $slug ): string {
		if ( empty( $slug ) ) {
			return '';
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();

		// If incoming "slug" already looks like a plugin file, try an exact match first.
		if ( strpos( $slug, '/' ) !== false || substr( $slug, -4 ) === '.php' ) {
			if ( isset( $plugins[ $slug ] ) ) {
				return $slug;
			}
			$trimmed = ltrim( $slug, '/' );
			if ( isset( $plugins[ $trimmed ] ) ) {
				return $trimmed;
			}
		}

		// Otherwise, treat input as directory slug and try common patterns.
		foreach ( $plugins as $file => $data ) {
			if ( strpos( $file, $slug . '/' ) === 0 || $file === $slug . '.php' ) {
				return $file;
			}
		}

		// Fallback: scan installed plugins for partial matches by directory or main file name.
		foreach ( $plugins as $file => $data ) {
			$parts = explode( '/', $file );
			if ( count( $parts ) === 2 ) {
				$directory = $parts[0];
				$main_file = $parts[1];

				if ( $directory === $slug ) {
					return $file;
				}

				$file_slug = str_replace( '.php', '', $main_file );
				if ( $file_slug === $slug ) {
					return $file;
				}
			} elseif ( count( $parts ) === 1 ) {
				$file_slug = str_replace( '.php', '', $parts[0] );
				if ( $file_slug === $slug ) {
					return $file;
				}
			}
		}

		return '';
	}

	/**
	 * Whether the plugin resolved from $slug is currently active.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function is_active( string $slug ): bool {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin_file = self::resolve_plugin_file_by_slug( $slug );
		return ( ! empty( $plugin_file ) && function_exists( 'is_plugin_active' ) ) ? is_plugin_active( $plugin_file ) : false;
	}

	/**
	 * Build a normalized result array.
	 */
	private static function result( bool $success, string $code, string $message, bool $installed, bool $activated ): array {
		return [
			'success'   => $success,
			'code'      => $code,
			'message'   => $message,
			'installed' => $installed,
			'activated' => $activated,
		];
	}
}
