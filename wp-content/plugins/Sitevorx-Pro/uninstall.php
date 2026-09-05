<?php
/**
 * Sitevorx Pro Uninstall Handler
 *
 * Fired when the plugin is deleted (not just deactivated).
 * Removes all plugin options and transients from the database.
 *
 * @package Sitevorx Pro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// All option keys created by this plugin
$sv_options = array(
    'sv_active_mailer', 'sv_gmail_user', 'sv_gmail_pass',
    'sv_smtp_host', 'sv_smtp_port', 'sv_smtp_user', 'sv_smtp_pass',
    'sv_smtp_from_name', 'sv_smtp_force_email', 'sv_smtp_force_name', 'sv_smtp_enable_log',
    'sv_opt_allow_svg', 'sv_opt_limit_revisions', 'sv_opt_disable_heartbeat',
    'sv_opt_disable_auto_update', 'sv_opt_lazy_load',
    'sv_sec_enable_login_key', 'sv_sec_login_key',
    'sv_sec_disable_editor', 'sv_sec_disable_xmlrpc',
    'sv_sec_enable_recaptcha', 'sv_sec_recaptcha_site_key', 'sv_sec_recaptcha_secret_key', 'sv_sec_recaptcha_version',
    'sv_sec_limit_login',
    'sv_sec_limit_login_max',
    'sv_sec_limit_login_minutes',
    'sv_sec_limit_login_allowlist',
    'sv_sec_headers_enabled', 'sv_sec_headers_hsts', 'sv_sec_headers_hsts_max', 'sv_sec_headers_hsts_sub',
    'sv_sec_honeypot_enabled', 'sv_sec_block_user_enum', 'sv_sec_login_notify',
    'sv_sec_action_log', 'sv_sec_failed_logins', 'sv_sec_last_scan', 'sv_sec_login_notify_last',
    'sv_opt_disable_emojis', 'sv_opt_disable_embeds', 'sv_opt_clean_wp_head',
    'sv_opt_remove_jquery_migrate', 'sv_opt_disable_pingbacks',
    'sv_util_header_script', 'sv_util_footer_script',
    'sv_util_disable_copy', 'sv_util_copy_msg',
    'sv_util_maintenance', 'sv_util_custom_login_logo', 'sv_util_login_logo_url',
    'sv_contact_phone', 'sv_contact_zalo', 'sv_contact_fb',
    'sv_cron_cleanup_enabled', 'sv_cron_cleanup_frequency',
    'sv_toolkit_language',
    'sv_migrated_from_sp',
    'sv_email_log',
    'sv_cleanup_log',
    'sv_smtp_db_version',
    'sv_cron_cleanup_logs',
    'sv_hosting_fingerprint',
    'sv_clone_revoke_log',
    'sv_non_inet_strikes',
    'sv_clone_grace_started',
    'sv_clone_last_strike_at',
    'sv_clone_last_warning',
    'sv_clone_notice_email_sent',
    'sv_mts_identity_migrated_v2',
    'sv_migration_version',
    'sv_audit_log',
    'sv_plugin_version_seen',
);

$removed_security_center_options = array(
    'sitevorx_waf_enabled',
    'sitevorx_waf_rate_limit',
    'sitevorx_waf_auto_ban_threshold',
    'sitevorx_waf_auto_ban_duration',
    'sitevorx_waf_rules',
    'sitevorx_waf_ip_allowlist',
    'sitevorx_waf_ip_blocklist',
    'sitevorx_waf_blocked_log',
    'sitevorx_waf_trusted_proxies',
    'sitevorx_headers_enabled',
    'sitevorx_headers_xcto',
    'sitevorx_headers_xfo',
    'sitevorx_headers_referrer',
    'sitevorx_headers_hsts',
    'sitevorx_headers_csp',
    'sitevorx_headers_permissions',
    'sitevorx_2fa_enabled',
    'sitevorx_2fa_required_roles',
    'sitevorx_2fa_grace_period_days',
    'sitevorx_activity_log_enabled',
    'sitevorx_activity_log_retention_days',
    'sitevorx_activity_log_last_cleanup',
    'sv_removed_security_center_cleaned_106',
);

$sp_options = array();
$so_options = array();
$inet_options = array();
foreach ( $sv_options as $option ) {
    if ( 0 === strpos( $option, 'sv_' ) && 'sv_migrated_from_sp' !== $option ) {
        $suffix = substr( $option, 3 );
        $sp_options[]   = 'sp_'   . $suffix;
        $so_options[]   = 'so_'   . $suffix;
        $inet_options[] = 'inet_' . $suffix;
    }
}
$sp_options[] = 'sp_migrated_from_legacy';
$so_options[] = 'so_migrated_from_sp';
$so_options[] = 'so_migrated_from_legacy';

foreach ( array_unique( array_merge( $sv_options, $sp_options, $so_options, $inet_options, $removed_security_center_options ) ) as $option ) {
    delete_option( $option );
}

// Also remove migration version flag
delete_option( 'sv_migration_version' );

// Remove transients
$sv_transients = array(
    'sv_wpcontent_size',
    'sv_dashboard_db_size',
    'sv_dashboard_content_size',
    'sv_dashboard_upload_size',
    'sv_hosting_check',
    'sv_premium_themes_list',
    'sv_mts_api_data',
);

$sp_transients = array();
$so_transients = array();
foreach ( $sv_transients as $transient ) {
    if ( 0 === strpos( $transient, 'sv_' ) ) {
        $suffix = substr( $transient, 3 );
        $sp_transients[] = 'sp_' . $suffix;
        $so_transients[] = 'so_' . $suffix;
    }
}

foreach ( array_unique( array_merge( $sv_transients, $sp_transients, $so_transients ) ) as $transient ) {
    delete_transient( $transient );
}

// Delete dynamic login attempt transients (all eras)
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sp_login_attempts_%' OR option_name LIKE '_transient_timeout_sp_login_attempts_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_so_login_attempts_%' OR option_name LIKE '_transient_timeout_so_login_attempts_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sv_login_attempts_%' OR option_name LIKE '_transient_timeout_sv_login_attempts_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sitevorx_waf_%' OR option_name LIKE '_transient_timeout_sitevorx_waf_%'" );
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('sitevorx_2fa_secret','sitevorx_2fa_backup_codes','sitevorx_2fa_trusted_hash')" );

// Drop SMTP logs table (all eras)
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sp_smtp_logs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}so_smtp_logs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sv_smtp_logs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sitevorx_activity_log" );

// Clear any scheduled cron events (all eras)
wp_clear_scheduled_hook( 'sp_scheduled_cleanup_event' );
wp_clear_scheduled_hook( 'so_scheduled_cleanup_event' );
wp_clear_scheduled_hook( 'sv_scheduled_cleanup_event' );
wp_clear_scheduled_hook( 'sp_license_heartbeat' );
wp_clear_scheduled_hook( 'so_license_heartbeat' );
wp_clear_scheduled_hook( 'sv_license_heartbeat' );

// Legacy migration feature (removed in 1.3.0): clear its GC cron + working dir.
wp_clear_scheduled_hook( 'sv_migrate_gc_event' );
wp_clear_scheduled_hook( 'sitevorx_migrate_gc_event' );

// Cloud backup (Pro): clear scheduled backup cron + local working dir.
wp_clear_scheduled_hook( 'sv_backup_event' );
wp_clear_scheduled_hook( 'sv_backup_gc_event' );

// Telemetry (Pro): clear cron + stable site id (throttle state đã nằm trong $sv_options).
wp_clear_scheduled_hook( 'sv_telemetry_event' );
delete_option( 'sv_telemetry_site_id' );

$sv_uploads = wp_upload_dir();
if ( ! empty( $sv_uploads['basedir'] ) ) {
	foreach ( array( 'sitevorx-migrate', 'sitevorx-backup' ) as $sv_legacy_dir ) {
		$sv_dir = trailingslashit( $sv_uploads['basedir'] ) . $sv_legacy_dir;
		if ( is_dir( $sv_dir ) ) {
			$sv_it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $sv_dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $sv_it as $sv_f ) {
				$sv_f->isDir() ? @rmdir( $sv_f->getPathname() ) : @unlink( $sv_f->getPathname() );
			}
			@rmdir( $sv_dir );
		}
	}
}
