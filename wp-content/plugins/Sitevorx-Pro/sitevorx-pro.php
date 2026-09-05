<?php
/**
 * Plugin Name: Sitevorx Pro
 * Description: Phiên bản đầy đủ của Sitevorx — quản trị, tối ưu, bảo mật Website toàn diện kèm Kho Giao Diện Premium và Rank Math SEO Pro dành cho khách hàng VIP.
 * Version: 1.3.0
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * Author: iNET
 * Author URI: https://inet.vn
 * Text Domain: sitevorx
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SV_PLUGIN_FILE', __FILE__ );
define( 'SV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SV_PLUGIN_VERSION', '1.3.0' );

// ==========================================================================
// FREE/PRO MUTUAL EXCLUSION
// Pro is a strict superset of Free. Running both at the same time causes:
//   - duplicate admin menus (Pro & Free both register parent slug `sitevorx`)
//   - duplicate hook callbacks for the same option pages
//   - subtle conflicts where a Free hook fires after a Pro hook for the
//     same feature, producing inconsistent state.
// We deactivate Free both at activation time and on every admin pageload.
// ==========================================================================
function sv_deactivate_free_sibling() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $free_slug = 'sitevorx/sitevorx.php';
    if ( is_plugin_active( $free_slug ) ) {
        deactivate_plugins( $free_slug, true ); // silent = true → don't fire deactivation hooks
        update_option( 'sv_pro_deactivated_free', '1' );
    }
}
register_activation_hook( __FILE__, 'sv_deactivate_free_sibling' );
add_action( 'admin_init', 'sv_deactivate_free_sibling' );

// One-time admin notice after Pro auto-deactivates Free.
add_action( 'admin_notices', function() {
    if ( '1' !== get_option( 'sv_pro_deactivated_free' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    delete_option( 'sv_pro_deactivated_free' );
    echo '<div class="notice notice-info is-dismissible sv-notice"><p><strong>Sitevorx Pro:</strong> '
        . esc_html__( 'Đã tự động vô hiệu hóa plugin Sitevorx (Free) vì Sitevorx Pro đã bao gồm toàn bộ tính năng và còn nhiều hơn nữa.', 'sitevorx' )
        . '</p></div>';
} );

function sv_get_managed_option_keys() {
    return array(
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
        'sv_sec_headers_enabled',
        'sv_sec_headers_hsts', 'sv_sec_headers_hsts_max', 'sv_sec_headers_hsts_sub',
        'sv_sec_honeypot_enabled',
        'sv_sec_block_user_enum',
        'sv_sec_login_notify',
        'sv_sec_last_scan', 'sv_sec_login_notify_last',
        'sv_opt_disable_emojis', 'sv_opt_disable_embeds', 'sv_opt_clean_wp_head',
        'sv_opt_remove_jquery_migrate', 'sv_opt_disable_pingbacks',
        'sv_util_header_script', 'sv_util_footer_script',
        'sv_util_disable_copy', 'sv_util_copy_msg',
        'sv_util_maintenance', 'sv_util_custom_login_logo', 'sv_util_login_logo_url',
        'sv_contact_phone', 'sv_contact_zalo', 'sv_contact_fb',
        'sv_cron_cleanup_enabled', 'sv_cron_cleanup_frequency',
        'sv_toolkit_language',
        'sv_auto_translate_external_consent',
        'sv_migrated_from_sp',
        'sv_email_log',
        'sv_cleanup_log',
        'sv_smtp_db_version',
        'sv_cron_cleanup_logs',
        'sv_auto_translation_cache_en',
        'sv_hosting_fingerprint',
        'sv_clone_revoke_log',
        'sv_non_inet_strikes',
        'sv_clone_grace_started',
        'sv_clone_last_strike_at',
        'sv_clone_last_warning',
        'sv_clone_notice_email_sent',
        'sv_sec_trusted_proxy_ips',
        'sv_audit_log',
        'sv_plugin_version_seen',
        // Cloud backup (S3) — connection + schedule/retention.
        'sv_s3_enabled', 'sv_s3_endpoint', 'sv_s3_region', 'sv_s3_bucket',
        'sv_s3_access_key', 'sv_s3_secret_key', 'sv_s3_prefix', 'sv_s3_path_style',
        'sv_backup_schedule_enabled', 'sv_backup_frequency', 'sv_backup_retention',
        'sv_backup_include', 'sv_backup_logs', 'sv_backup_last',
        // Telemetry — trạng thái nhịp gửi (KHÔNG gồm sv_telemetry_site_id: giữ ổn
        // định qua reset để không đếm trùng site).
        'sv_telemetry_last_sent', 'sv_telemetry_version_sent',
    );
}

function sv_get_managed_transient_keys() {
    return array(
        'sv_wpcontent_size',
        'sv_dashboard_db_size',
        'sv_dashboard_content_size',
        'sv_dashboard_upload_size',
        'sv_hosting_check',
        'sv_premium_themes_list',
        'sv_mts_api_data',
    );
}

function sv_delete_removed_security_center_data() {
    global $wpdb;

    $security_center_options = array(
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

    foreach ( $security_center_options as $option_name ) {
        delete_option( $option_name );
    }

    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sitevorx_waf_%' OR option_name LIKE '_transient_timeout_sitevorx_waf_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('sitevorx_2fa_secret','sitevorx_2fa_backup_codes','sitevorx_2fa_trusted_hash')" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sitevorx_activity_log" );
}

function sv_cleanup_removed_security_center_once() {
    if ( '1' === get_option( 'sv_removed_security_center_cleaned_106' ) ) {
        return;
    }

    sv_delete_removed_security_center_data();
    update_option( 'sv_removed_security_center_cleaned_106', '1', false );
}
add_action( 'admin_init', 'sv_cleanup_removed_security_center_once' );

function sv_get_valid_cleanup_frequency( $frequency = '' ) {
    $frequency = is_string( $frequency ) ? $frequency : '';
    if ( ! in_array( $frequency, array( 'daily', 'twicedaily', 'weekly' ), true ) ) {
        $frequency = 'weekly';
    }

    return $frequency;
}

function sv_sync_cleanup_schedule( $preferred_timestamp = 0 ) {
    $preferred_timestamp = absint( $preferred_timestamp );
    $legacy_timestamp    = wp_next_scheduled( 'sp_scheduled_cleanup_event' );
    $siteops_timestamp   = wp_next_scheduled( 'so_scheduled_cleanup_event' );
    $current_timestamp   = wp_next_scheduled( 'sv_scheduled_cleanup_event' );

    wp_clear_scheduled_hook( 'sp_scheduled_cleanup_event' );
    wp_clear_scheduled_hook( 'so_scheduled_cleanup_event' );
    wp_clear_scheduled_hook( 'sv_scheduled_cleanup_event' );

    if ( get_option( 'sv_cron_cleanup_enabled', '0' ) !== '1' ) {
        return;
    }

    $frequency = sv_get_valid_cleanup_frequency( get_option( 'sv_cron_cleanup_frequency', 'weekly' ) );
    update_option( 'sv_cron_cleanup_frequency', $frequency );

    $timestamp = $preferred_timestamp ? $preferred_timestamp : ( $current_timestamp ? $current_timestamp : ( $siteops_timestamp ? $siteops_timestamp : ( $legacy_timestamp ? $legacy_timestamp : time() ) ) );
    wp_schedule_event( $timestamp, $frequency, 'sv_scheduled_cleanup_event' );
}

/**
 * Decode the obfuscated list of iNET hosting CIDR ranges used by
 * sv_is_inet_hosting() for premium-license host validation.
 *
 * The CIDR list is intentionally XOR-obfuscated (not encrypted) to make casual
 * tampering harder; this is NOT a security boundary. The returned array is
 * only used to compare $_SERVER['SERVER_ADDR'] against known iNET subnets.
 *
 * @internal Regenerate the encoded payload via tools/encode-net-cfg.php when
 *           iNET network ranges change. Do not edit the literal by hand.
 *
 * @return array<int,string> List of CIDR strings, or empty array on failure.
 */
function sv_decode_net_cfg() {
    $d = 'KE1tXldaZlFIU3FGHkFdfUJHRW9QSFJoWANBX3FeSkZtQUpFbkYCXVhqQFRMa01WSG1EE19Nbl5WWmpVSFZpRh9DQG1cR1h9UlZUcUQARUFuX1Nab0xUVX1aE0JfbEBSRnFaUElvWQNBTQI=';
    $k = 'so_net' . '_cfg_v1';
    $r = base64_decode($d);
    $kl = strlen($k);
    $o = '';
    for ($i = 0, $l = strlen($r); $i < $l; $i++) {
        $o .= chr(ord($r[$i]) ^ ord($k[$i % $kl]));
    }
    $result = json_decode($o, true);
    return is_array($result) ? $result : array();
}

function sv_allows_hosting_override() {
    return defined( 'WP_DEBUG' ) && WP_DEBUG
        && defined( 'SV_ALLOW_HOSTING_OVERRIDE' )
        && SV_ALLOW_HOSTING_OVERRIDE;
}

/**
 * Collect candidate server IPs to match against the iNET CIDR allowlist.
 *
 * SERVER_ADDR alone is unreliable on shared/clustered hosting where PHP runs
 * behind an internal reverse proxy and reports a private RFC1918 address.
 * We additionally resolve the site's own hostname and the OS hostname via DNS,
 * which yields the public iNET IP in those topologies.
 */
function sv_collect_server_ips() {
    $ips = array();

    foreach ( array( 'SERVER_ADDR', 'LOCAL_ADDR' ) as $key ) {
        if ( empty( $_SERVER[ $key ] ) ) continue;
        $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            $ips[] = $ip;
        }
    }

    $os_host = gethostname();
    if ( $os_host ) {
        $resolved = @gethostbyname( $os_host );
        if ( $resolved && $resolved !== $os_host && filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
            $ips[] = $resolved;
        }
    }

    $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( $site_host ) {
        if ( function_exists( 'dns_get_record' ) ) {
            $records = @dns_get_record( $site_host, DNS_A );
            if ( is_array( $records ) ) {
                foreach ( $records as $r ) {
                    if ( ! empty( $r['ip'] ) && filter_var( $r['ip'], FILTER_VALIDATE_IP ) ) {
                        $ips[] = $r['ip'];
                    }
                }
            }
        } else {
            $resolved = @gethostbyname( $site_host );
            if ( $resolved && $resolved !== $site_host && filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
                $ips[] = $resolved;
            }
        }
    }

    return array_values( array_unique( array_filter( $ips ) ) );
}

/**
 * Check if current server is iNET hosting (Pro feature).
 * Positive verdict cached 12h; negative verdict cached only 1h so customers
 * recover quickly after DNS/proxy fixes without waiting half a day.
 */
function sv_is_inet_hosting() {
    if ( sv_allows_hosting_override() && defined( 'SV_FORCE_INET_HOSTING' ) ) {
        return (bool) SV_FORCE_INET_HOSTING;
    }

    $cached = get_transient('sv_hosting_check');
    if ($cached !== false) {
        return $cached === 'yes';
    }

    $is_inet     = false;
    $inet_ranges = sv_decode_net_cfg();
    $candidates  = sv_collect_server_ips();

    foreach ( $candidates as $ip ) {
        foreach ( $inet_ranges as $cidr ) {
            if ( sv_ip_in_cidr( $ip, $cidr ) ) {
                $is_inet = true;
                break 2;
            }
        }
    }

    // Fallback: check hostname pattern
    if (!$is_inet) {
        $hostname = gethostname();
        $hn_sig = chr(105).chr(110).chr(101).chr(116); // runtime token
        if ($hostname && stripos($hostname, $hn_sig) !== false) {
            $is_inet = true;
        }
    }

    if ( sv_allows_hosting_override() ) {
        $is_inet = (bool) apply_filters( 'sv_is_inet_hosting_result', $is_inet, $candidates, $inet_ranges );
    }

    $ttl = $is_inet ? ( 12 * HOUR_IN_SECONDS ) : HOUR_IN_SECONDS;
    set_transient('sv_hosting_check', $is_inet ? 'yes' : 'no', $ttl);
    return $is_inet;
}

function sv_ip_in_cidr($ip, $cidr) {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    list($subnet, $mask) = explode('/', $cidr);
    $ip_long     = ip2long($ip);
    $subnet_long = ip2long($subnet);
    $mask_long   = -1 << (32 - intval($mask));
    if ($ip_long === false || $subnet_long === false) return false;
    return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
}

function sv_get_inet_option_keys() {
    $legacy_keys = array();

    foreach ( sv_get_managed_option_keys() as $key ) {
        if ( 0 === strpos( $key, 'sv_' ) && 'sv_migrated_from_sp' !== $key ) {
            $legacy_keys[] = 'inet_' . substr( $key, 3 );
        }
    }

    return array_values( array_unique( $legacy_keys ) );
}

// ==========================================================================
// MIGRATION: Automatically migrate legacy prefixed data to sv_ prefix
// ==========================================================================
register_activation_hook( __FILE__, 'sv_run_migration' );
// Run on plugins_loaded (priority 1) so legacy options are migrated BEFORE
// any other module reads them — including front-end requests and WP-CRON.
// Previously hooked on admin_init, which never fired on front-end visits.
add_action( 'plugins_loaded', 'sv_run_migration', 1 );

/**
 * Invalidate the cached hosting verdict whenever the plugin version changes.
 *
 * Required because the iNET-detection logic itself can change between
 * versions (e.g. v1.1.0 → v1.1.1 added DNS-based IP discovery). Without this
 * the old negative verdict would persist for up to 12 hours after upgrade,
 * leaving customers locked out of Premium on a fully-correct deployment.
 */
add_action( 'plugins_loaded', 'sv_invalidate_hosting_cache_on_upgrade', 1 );
function sv_invalidate_hosting_cache_on_upgrade() {
    $seen = get_option( 'sv_plugin_version_seen' );
    if ( $seen === SV_PLUGIN_VERSION ) {
        return;
    }
    delete_transient( 'sv_hosting_check' );
    update_option( 'sv_plugin_version_seen', SV_PLUGIN_VERSION, false );
}

function sv_get_legacy_option_keys() {
    $legacy_keys = array();

    foreach ( sv_get_managed_option_keys() as $key ) {
        if ( 0 === strpos( $key, 'sv_' ) && 'sv_migrated_from_sp' !== $key ) {
            $legacy_keys[] = 'sp_' . substr( $key, 3 );
        }
    }

    $legacy_keys[] = 'sp_migrated_from_legacy';

    return array_values( array_unique( $legacy_keys ) );
}

/**
 * SiteOps-era option keys (so_*) — predecessors of sv_*.
 */
function sv_get_siteops_option_keys() {
    $legacy_keys = array();

    foreach ( sv_get_managed_option_keys() as $key ) {
        if ( 0 === strpos( $key, 'sv_' ) && 'sv_migrated_from_sp' !== $key ) {
            $legacy_keys[] = 'so_' . substr( $key, 3 );
        }
    }

    $legacy_keys[] = 'so_migrated_from_sp';
    $legacy_keys[] = 'so_migrated_from_legacy';

    return array_values( array_unique( $legacy_keys ) );
}

function sv_get_legacy_transient_keys() {
    $legacy_keys = array();

    foreach ( sv_get_managed_transient_keys() as $key ) {
        if ( 0 === strpos( $key, 'sv_' ) ) {
            $legacy_keys[] = 'sp_' . substr( $key, 3 );
        }
    }

    return array_values( array_unique( $legacy_keys ) );
}

/**
 * SiteOps-era transient keys (so_*).
 */
function sv_get_siteops_transient_keys() {
    $legacy_keys = array();

    foreach ( sv_get_managed_transient_keys() as $key ) {
        if ( 0 === strpos( $key, 'sv_' ) ) {
            $legacy_keys[] = 'so_' . substr( $key, 3 );
        }
    }

    return array_values( array_unique( $legacy_keys ) );
}

function sv_copy_option_if_missing( $old_name, $new_name ) {
    global $wpdb;

    if ( get_option( $new_name, null ) !== null ) {
        return;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
            $old_name
        )
    );

    if ( ! $row ) {
        return;
    }

    update_option( $new_name, maybe_unserialize( $row->option_value ), $row->autoload );
}

function sv_copy_transient_if_missing( $old_key, $new_key ) {
    global $wpdb;

    $value_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            '_transient_' . $old_key
        )
    );

    if ( ! $value_row ) {
        return;
    }

    $new_value_name = '_transient_' . $new_key;
    $new_timeout_name = '_transient_timeout_' . $new_key;

    $new_value_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
            $new_value_name
        )
    );

    if ( ! $new_value_exists ) {
        update_option( $new_value_name, maybe_unserialize( $value_row->option_value ), false );
    }

    $timeout_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            '_transient_timeout_' . $old_key
        )
    );

    if ( $timeout_row ) {
        $new_timeout_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
                $new_timeout_name
            )
        );

        if ( ! $new_timeout_exists ) {
            update_option( $new_timeout_name, $timeout_row->option_value, false );
        }
    }
}

function sv_migrate_smtp_log_table() {
    global $wpdb;

    $new_table = $wpdb->prefix . 'sv_smtp_logs';
    $new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) );

    // Try each legacy prefix in order (most recent first)
    foreach ( array( 'so_smtp_logs', 'sp_smtp_logs' ) as $old_suffix ) {
        $old_table  = $wpdb->prefix . $old_suffix;
        $old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );

        if ( ! $old_exists ) continue;

        if ( ! $new_exists ) {
            $wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
            $new_exists = $new_table;
            continue;
        }

        // Both exist → merge rows then drop legacy
        $wpdb->query( "INSERT INTO `{$new_table}` (`time`, `to_email`, `subject`, `status`, `error_msg`) SELECT `time`, `to_email`, `subject`, `status`, `error_msg` FROM `{$old_table}`" );
        $wpdb->query( "DROP TABLE IF EXISTS `{$old_table}`" );
    }
}

function sv_migrate_scheduled_cleanup_event() {
    sv_sync_cleanup_schedule();
}

/**
 * Clear legacy Pro-only cron hooks (license heartbeat) from old eras.
 */
function sv_migrate_pro_crons() {
    wp_clear_scheduled_hook( 'sp_license_heartbeat' );
    wp_clear_scheduled_hook( 'so_license_heartbeat' );
    // sv_license_heartbeat will be re-registered by sv-mts-active.php on 'wp' hook
}

/**
 * Run data migration from legacy prefixes (inet_, sp_, so_) to the current sv_ prefix.
 * Uses a version flag so each era only runs once even if the plugin is re-activated.
 *
 * Eras:
 *   - v1: inet_* + sp_*  → sv_*  (pre-1.0 → SitePilot → SiteOps)
 *   - v2: so_*           → sv_*  (SiteOps → Sitevorx, current rename)
 */
function sv_run_migration() {
    global $wpdb;

    $done = get_option( 'sv_migration_version', '0' );

    // Detect legacy v1 flag (existing installs had 'sv_migrated_from_sp' from pre-bulk-rename)
    // as well as the so_* form that existed before the current rename.
    if ( '0' === $done && ( '1' === get_option( 'sv_migrated_from_sp' ) || '1' === get_option( 'so_migrated_from_sp' ) ) ) {
        $done = '1';
    }

    // ──────────────────────────────────────────────────────────────────
    // v1 migration: inet_* + sp_* → sv_*
    // ──────────────────────────────────────────────────────────────────
    if ( version_compare( $done, '1', '<' ) ) {
        foreach ( sv_get_inet_option_keys() as $old_name ) {
            $new_name = preg_replace( '/^inet_/', 'sv_', $old_name );
            if ( $old_name !== $new_name ) {
                sv_copy_option_if_missing( $old_name, $new_name );
            }
        }

        foreach ( sv_get_legacy_option_keys() as $old_name ) {
            if ( 'sp_migrated_from_legacy' === $old_name ) continue;
            $new_name = preg_replace( '/^sp_/', 'sv_', $old_name );
            if ( $old_name !== $new_name ) {
                sv_copy_option_if_missing( $old_name, $new_name );
            }
        }

        foreach ( sv_get_legacy_transient_keys() as $old_key ) {
            $new_key = preg_replace( '/^sp_/', 'sv_', $old_key );
            if ( $old_key !== $new_key ) {
                sv_copy_transient_if_missing( $old_key, $new_key );
            }
        }

        foreach ( sv_get_legacy_option_keys() as $old_name )  { delete_option( $old_name ); }
        foreach ( sv_get_inet_option_keys() as $old_name )    { delete_option( $old_name ); }
        foreach ( sv_get_legacy_transient_keys() as $old_key ){ delete_transient( $old_key ); }

        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sp_login_attempts_%' OR option_name LIKE '_transient_timeout_sp_login_attempts_%'" );

        $done = '1';
    }

    // ──────────────────────────────────────────────────────────────────
    // v2 migration: so_* → sv_*  (CURRENT rename, SiteOps → Sitevorx)
    // ──────────────────────────────────────────────────────────────────
    if ( version_compare( $done, '2', '<' ) ) {
        // 1. Copy so_* options (including critical so_hosting_fingerprint,
        //    so_clone_revoke_log — preserves anti-clone state intact)
        foreach ( sv_get_siteops_option_keys() as $old_name ) {
            if ( 'so_migrated_from_sp' === $old_name || 'so_migrated_from_legacy' === $old_name ) continue;
            $new_name = preg_replace( '/^so_/', 'sv_', $old_name );
            if ( $old_name !== $new_name ) {
                sv_copy_option_if_missing( $old_name, $new_name );
            }
        }

        // 2. Copy so_* transients (so_hosting_check, so_mts_api_data, etc.)
        foreach ( sv_get_siteops_transient_keys() as $old_key ) {
            $new_key = preg_replace( '/^so_/', 'sv_', $old_key );
            if ( $old_key !== $new_key ) {
                sv_copy_transient_if_missing( $old_key, $new_key );
            }
        }

        // 3. Database tables + crons (handles both sp_ and so_ era)
        sv_migrate_smtp_log_table();
        sv_migrate_scheduled_cleanup_event();
        sv_migrate_pro_crons();

        // 4. Delete legacy so_* data after copying
        foreach ( sv_get_siteops_option_keys() as $old_name ) { delete_option( $old_name ); }
        foreach ( sv_get_siteops_transient_keys() as $old_key ){ delete_transient( $old_key ); }

        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_so_login_attempts_%' OR option_name LIKE '_transient_timeout_so_login_attempts_%'" );

        $done = '2';
    }

    update_option( 'sv_migration_version', $done );
    update_option( 'sv_migrated_from_sp', '1' ); // keep legacy flag for any external callers
}

// Override locale for this plugin's text domain based on user preference
add_filter( 'plugin_locale', function( $locale, $domain ) {
    if ( $domain === 'sitevorx' ) {
        $user_lang = get_option( 'sv_toolkit_language', '' );
        if ( $user_lang === 'en_US' ) {
            return 'en_US';
        }
        // Default or 'vi' — return Vietnamese locale
        return 'vi';
    }
    return $locale;
}, 10, 2 );

// Load textdomain on `plugins_loaded` with priority 1 to ensure translations
// are available BEFORE admin_menu (priority 10) renders menu labels.
add_action( 'plugins_loaded', function() {
    $user_lang = get_option( 'sv_toolkit_language', '' );

    if ( $user_lang === 'en_US' ) {
        // WordPress's load_plugin_textdomain() skips en_US locale (it treats
        // en_US as the base language and returns early).  Our source strings
        // are Vietnamese, so we MUST load the English .mo file explicitly.
        unload_textdomain( 'sitevorx' );
        $mo_file = SV_PLUGIN_DIR . 'languages/sitevorx-en_US.mo';
        load_textdomain( 'sitevorx', $mo_file );
    } else {
        // Vietnamese is the source language — directly block JIT loading by setting
        // the $l10n_unloaded flag. unload_textdomain() is a no-op at plugins_loaded
        // priority 1 (domain not loaded yet), so the flag must be set directly.
        global $l10n_unloaded;
        $l10n_unloaded            = (array) $l10n_unloaded;
        $l10n_unloaded['sitevorx'] = true;
        unset( $GLOBALS['l10n']['sitevorx'] );
    }
}, 1 );

// The runtime .po → .mo compiler was removed: regenerating the bundled .mo
// inside the plugin folder is wiped on every plugin update and is generally
// disallowed. The compiled languages/sitevorx-en_US.mo is shipped pre-built
// and WordPress loads it normally.

function sv_is_auto_translation_enabled() {
    return get_option( 'sv_toolkit_language', '' ) === 'en_US'
        && get_option( 'sv_auto_translate_external_consent', '0' ) === '1';
}

function sv_get_auto_translation_cache() {
    $cache = get_option( 'sv_auto_translation_cache_en', array() );
    return is_array( $cache ) ? $cache : array();
}

function sv_get_auto_translation_cache_limits() {
    return array(
        'ttl'     => max( DAY_IN_SECONDS, (int) apply_filters( 'sv_auto_translation_cache_ttl', 30 * DAY_IN_SECONDS ) ),
        'entries' => max( 50, (int) apply_filters( 'sv_auto_translation_cache_max_entries', 500 ) ),
    );
}

function sv_normalize_auto_translation_cache( $cache ) {
    $limits = sv_get_auto_translation_cache_limits();
    $now    = time();
    $clean  = array();

    foreach ( (array) $cache as $source => $entry ) {
        if ( ! is_string( $source ) || '' === $source ) {
            continue;
        }

        if ( is_array( $entry ) ) {
            $translation = isset( $entry['text'] ) ? (string) $entry['text'] : '';
            $created     = isset( $entry['time'] ) ? (int) $entry['time'] : $now;
        } elseif ( is_string( $entry ) ) {
            $translation = $entry;
            $created     = $now;
        } else {
            continue;
        }

        if ( '' === $translation || $created < ( $now - $limits['ttl'] ) ) {
            continue;
        }

        $clean[ $source ] = array(
            'text' => $translation,
            'time' => $created,
        );
    }

    uasort( $clean, function( $a, $b ) {
        return (int) $b['time'] <=> (int) $a['time'];
    } );

    return array_slice( $clean, 0, $limits['entries'], true );
}

function sv_get_auto_translation_cache_text( $source ) {
    $raw_cache = sv_get_auto_translation_cache();
    $cache     = sv_normalize_auto_translation_cache( $raw_cache );
    if ( $cache !== $raw_cache ) {
        update_option( 'sv_auto_translation_cache_en', $cache, false );
    }

    return isset( $cache[ $source ]['text'] ) ? $cache[ $source ]['text'] : '';
}

function sv_set_auto_translation_cache_entry( $source, $translation ) {
    if ( ! is_string( $source ) || '' === $source || ! is_string( $translation ) || '' === $translation ) {
        return;
    }

    $cache = sv_normalize_auto_translation_cache( sv_get_auto_translation_cache() );
    if ( isset( $cache[ $source ]['text'] ) && $cache[ $source ]['text'] === $translation ) {
        return;
    }

    $cache[ $source ] = array(
        'text' => $translation,
        'time' => time(),
    );

    $cache = sv_normalize_auto_translation_cache( $cache );

    if ( get_option( 'sv_auto_translation_cache_en', null ) === null ) {
        add_option( 'sv_auto_translation_cache_en', $cache, '', 'no' );
        return;
    }

    update_option( 'sv_auto_translation_cache_en', $cache, false );
}

function sv_prepare_auto_translation_text( $text, &$tokens ) {
    $tokens   = array();
    $index    = 0;
    $patterns = array(
        '/%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]/',
        '/<[^>]+>/',
        '/&(?:[a-zA-Z0-9#]+);/',
    );

    foreach ( $patterns as $pattern ) {
        $text = preg_replace_callback(
            $pattern,
            function( $matches ) use ( &$tokens, &$index ) {
                $token            = '__SO_TOKEN_' . $index . '__';
                $tokens[ $token ] = $matches[0];
                $index++;
                return $token;
            },
            $text
        );
    }

    return $text;
}

function sv_restore_auto_translation_text( $text, $tokens ) {
    return empty( $tokens ) ? $text : strtr( $text, $tokens );
}

function sv_request_auto_translation( $text ) {
    if ( ! is_string( $text ) || '' === $text || ! preg_match( '/[^\x00-\x7F]/', $text ) ) {
        return '';
    }

    // Per-request budget: at most N Google Translate API calls per pageload.
    // Anything beyond the budget returns '' so WP falls back to the source
    // string. The persistent cache (`sv_auto_translation_cache_en`) fills up
    // gradually over subsequent pageloads instead of stalling one load with
    // 50+ sequential HTTP calls.
    static $calls_this_request = 0;
    $budget = (int) apply_filters( 'sv_auto_translation_per_request_budget', 6 );
    if ( $calls_this_request >= $budget ) {
        return '';
    }
    $calls_this_request++;

    $tokens   = array();
    $prepared = sv_prepare_auto_translation_text( $text, $tokens );
    $url      = add_query_arg(
        array(
            'client' => 'gtx',
            'sl'     => 'vi',
            'tl'     => 'en',
            'dt'     => 't',
            'q'      => $prepared,
        ),
        'https://translate.googleapis.com/translate_a/single'
    );

    // Reduced from 8s → 2s so a slow/unreachable Google endpoint can't stall
    // the whole admin page. With the per-request budget of 6, worst case is
    // 6 × 2s = 12s instead of 6 × 8s = 48s (or more with 50+ strings).
    $response = wp_remote_get(
        $url,
        array(
            'timeout' => 2,
            'headers' => array(
                'User-Agent' => 'Sitevorx/1.0',
            ),
        )
    );

    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return '';
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $body ) || empty( $body[0] ) || ! is_array( $body[0] ) ) {
        return '';
    }

    $translated = '';
    foreach ( $body[0] as $segment ) {
        if ( isset( $segment[0] ) && is_string( $segment[0] ) ) {
            $translated .= $segment[0];
        }
    }

    $translated = trim( sv_restore_auto_translation_text( $translated, $tokens ) );
    return $translated;
}

function sv_get_auto_translated_text( $translation, $text, $domain ) {
    static $request_cache = array();

    if ( 'sitevorx' !== $domain || ! sv_is_auto_translation_enabled() ) {
        return $translation;
    }

    if ( ! is_string( $text ) || '' === $text || $translation !== $text ) {
        return $translation;
    }

    if ( ! preg_match( '/[^\x00-\x7F]/', $text ) ) {
        return $translation;
    }

    if ( isset( $request_cache[ $text ] ) ) {
        return $request_cache[ $text ];
    }

    $cached_translation = sv_get_auto_translation_cache_text( $text );
    if ( '' !== $cached_translation ) {
        $request_cache[ $text ] = $cached_translation;
        return $request_cache[ $text ];
    }

    $auto_translation = sv_request_auto_translation( $text );
    if ( '' !== $auto_translation ) {
        sv_set_auto_translation_cache_entry( $text, $auto_translation );
        $request_cache[ $text ] = $auto_translation;
        return $request_cache[ $text ];
    }

    $request_cache[ $text ] = $translation;
    return $translation;
}

add_filter(
    'gettext',
    function( $translation, $text, $domain ) {
        return sv_get_auto_translated_text( $translation, $text, $domain );
    },
    20,
    3
);

add_filter(
    'gettext_with_context',
    function( $translation, $text, $context, $domain ) {
        return sv_get_auto_translated_text( $translation, $text, $domain );
    },
    20,
    4
);

// AJAX handler for language toggle
add_action( 'wp_ajax_sv_toggle_language', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'sv_lang_toggle_nonce', 'nonce' );
    $lang = sanitize_text_field( wp_unslash( $_POST['language'] ?? '' ) );
    if ( ! in_array( $lang, [ 'vi', 'en_US' ], true ) ) {
        wp_send_json_error( 'Invalid language' );
    }
    if ( 'en_US' === $lang && isset( $_POST['allow_external_translate'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['allow_external_translate'] ) ) ) {
        update_option( 'sv_auto_translate_external_consent', '1' );
    } elseif ( 'vi' === $lang ) {
        update_option( 'sv_auto_translate_external_consent', '0' );
    }
    update_option( 'sv_toolkit_language', $lang );
    wp_send_json_success( [
        'language'                 => $lang,
        'auto_translate_consented' => get_option( 'sv_auto_translate_external_consent', '0' ),
    ] );
});

// ==========================================================================
// GLOBAL HELPER FUNCTIONS
// ==========================================================================
function sv_format_size($bytes) {
    if (!$bytes || $bytes <= 0) return '0 MB';
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

// --- Path + KSES helpers used by the Security Center module ---------------

function sv_kses_basic_tags() {
    return array(
        'strong' => array(),
        'b'      => array(),
        'em'     => array(),
        'i'      => array(),
        'br'     => array(),
        'code'   => array(),
        'span'   => array( 'class' => true, 'style' => true ),
        'a'      => array(
            'href'   => true,
            'target' => true,
            'rel'    => true,
            'title'  => true,
            'style'  => true,
        ),
    );
}

function sv_kses_basic( $html ) {
    return wp_kses( (string) $html, sv_kses_basic_tags() );
}

/**
 * Sanitize a raw header/footer tracking snippet before storing it. Only
 * unslashes + UTF-8 checks the value (gated at save time by the
 * unfiltered_html capability); output is later filtered through
 * sv_kses_tracking_tags().
 */
function sv_sanitize_raw_script( $value ) {
    if ( is_array( $value ) || is_object( $value ) ) {
        return '';
    }
    $value = wp_unslash( (string) $value );
    $value = wp_check_invalid_utf8( $value );
    return trim( $value );
}

/**
 * Allow-list used by wp_kses() for header/footer tracking snippets. Permits
 * third-party analytics, tag manager, pixel and verification markup (Google
 * Analytics, GTM, Facebook Pixel, Search Console / Bing / Pinterest meta
 * verification, etc.) while still running every attribute value through
 * wp_kses_bad_protocol() so javascript:, data: and vbscript: are stripped.
 */
function sv_kses_tracking_tags() {
    return array(
        'script'   => array(
            'src'         => true,
            'async'       => true,
            'defer'       => true,
            'type'        => true,
            'charset'     => true,
            'nonce'       => true,
            'id'          => true,
            'class'       => true,
            'crossorigin' => true,
            'integrity'   => true,
            'referrerpolicy' => true,
            'data-cfasync'    => true,
            'data-domain'     => true,
            'data-website-id' => true,
            'data-host'       => true,
            'data-api'        => true,
            'data-cf-beacon'  => true,
        ),
        'style'    => array(
            'type'  => true,
            'media' => true,
            'nonce' => true,
        ),
        'noscript' => array(),
        'iframe'   => array(
            'src'         => true,
            'width'       => true,
            'height'      => true,
            'style'       => true,
            'frameborder' => true,
            'scrolling'   => true,
            'title'       => true,
            'loading'     => true,
            'referrerpolicy' => true,
        ),
        'meta'     => array(
            'name'       => true,
            'content'    => true,
            'http-equiv' => true,
            'property'   => true,
            'charset'    => true,
        ),
        'link'     => array(
            'rel'         => true,
            'href'        => true,
            'type'        => true,
            'sizes'       => true,
            'crossorigin' => true,
            'as'          => true,
        ),
        'img'      => array(
            'src'    => true,
            'alt'    => true,
            'width'  => true,
            'height' => true,
            'style'  => true,
            'border' => true,
            'id'     => true,
            'class'  => true,
            'loading' => true,
        ),
        'div'      => array( 'id' => true, 'class' => true, 'style' => true ),
        'span'     => array( 'id' => true, 'class' => true, 'style' => true ),
        'p'        => array( 'id' => true, 'class' => true, 'style' => true ),
        'a'        => array( 'href' => true, 'rel' => true, 'target' => true, 'title' => true, 'id' => true, 'class' => true ),
        // Google AdSense ad units (and similar) use <ins> with data-ad-* attrs.
        'ins'      => array( 'class' => true, 'style' => true, 'id' => true, 'data-ad-client' => true, 'data-ad-slot' => true, 'data-ad-format' => true, 'data-ad-region' => true, 'data-ad-layout' => true, 'data-ad-layout-key' => true, 'data-full-width-responsive' => true, 'data-adtest' => true ),
    );
}

function sv_get_wordpress_root_path() {
    if ( ! function_exists( 'get_home_path' ) ) {
        $file_helper = ABSPATH . 'wp-admin/includes/file.php';
        if ( is_readable( $file_helper ) ) {
            require_once $file_helper;
        }
    }
    if ( function_exists( 'get_home_path' ) ) {
        return wp_normalize_path( untrailingslashit( get_home_path() ) );
    }
    return wp_normalize_path( untrailingslashit( ABSPATH ) );
}

function sv_get_content_dir_path() {
    return wp_normalize_path( WP_CONTENT_DIR );
}

function sv_get_filesystem() {
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if ( ! WP_Filesystem() ) {
        return false;
    }
    global $wp_filesystem;
    return $wp_filesystem ? $wp_filesystem : false;
}

function sv_get_relative_display_path( $path ) {
    $path = wp_normalize_path( (string) $path );
    $root = sv_get_wordpress_root_path();
    if ( $root && 0 === strpos( $path, $root ) ) {
        return '/' . ltrim( substr( $path, strlen( $root ) ), '/' );
    }
    return $path;
}

/**
 * Mã hóa chuỗi nhạy cảm (SMTP password) trước khi lưu vào DB.
 * Sử dụng AES-256-CBC với AUTH_KEY làm secret.
 * Tương thích ngược: nếu OpenSSL không khả dụng, trả về plaintext.
 */
function sv_get_encryption_key() {
    if ( defined('AUTH_KEY') && AUTH_KEY !== 'put your unique phrase here' ) {
        return AUTH_KEY;
    }
    $salt = wp_salt('auth');
    if ( !empty($salt) && $salt !== 'put your unique phrase here' ) {
        return $salt;
    }
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'Sitevorx: AUTH_KEY and wp_salt are not properly configured. Encryption is weakened.' );
    }
    return 'sv-fallback-' . md5( ABSPATH . DB_NAME );
}

/**
 * Return legacy encryption keys (in order of recency) for backward-compat decrypt.
 * Used by sv_decrypt() to try older keys if the current one fails.
 */
function sv_get_legacy_encryption_keys() {
    return array(
        'so-fallback-' . md5( ABSPATH . DB_NAME ), // SiteOps era
        'sp-fallback-' . md5( ABSPATH . DB_NAME ), // SitePilot era
    );
}

// Backward-compat wrapper (kept for any external callers)
function sv_get_legacy_encryption_key() {
    return 'sp-fallback-' . md5( ABSPATH . DB_NAME );
}

function sv_encrypt($value) {
    if (empty($value)) return $value;
    if (!function_exists('openssl_encrypt')) return $value;
    $key = sv_get_encryption_key();
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
    if ($encrypted === false) return $value;
    return 'enc::' . base64_encode($iv . base64_decode($encrypted));
}

/**
 * Giải mã chuỗi đã được mã hóa bởi sv_encrypt().
 * Tương thích ngược: nếu chuỗi chưa được mã hóa (plaintext cũ), trả về nguyên bản.
 */
function sv_decrypt($value) {
    if (empty($value)) return $value;
    if (strpos($value, 'enc::') !== 0) return $value;
    if (!function_exists('openssl_decrypt')) return substr($value, 5);
    $key = sv_get_encryption_key();
    $raw = base64_decode(substr($value, 5));
    if ($raw === false || strlen($raw) < 17) {
        // Legacy format (static IV) — backward compatible
        $iv = substr(hash('sha256', $key), 0, 16);
        $decrypted = openssl_decrypt(substr($value, 5), 'AES-256-CBC', $key, 0, $iv);
        // On genuine decrypt failure (e.g. AUTH_KEY/salt changed after a host
    // migration) return '' rather than the still-encrypted blob, so callers
    // treat it as "no password" and fall back to native mail() instead of
    // authenticating with the raw ciphertext (which fails on every send).
    return $decrypted !== false ? $decrypted : '';
    }
    $iv = substr($raw, 0, 16);
    $cipher_text = base64_encode(substr($raw, 16));
    $decrypted = openssl_decrypt($cipher_text, 'AES-256-CBC', $key, 0, $iv);
    if ( false === $decrypted ) {
        // Try each legacy key (SiteOps → SitePilot era) for backward compatibility
        foreach ( sv_get_legacy_encryption_keys() as $legacy_key ) {
            $decrypted = openssl_decrypt($cipher_text, 'AES-256-CBC', $legacy_key, 0, $iv);
            if ( false !== $decrypted ) break;
        }
    }
    // On genuine decrypt failure (e.g. AUTH_KEY/salt changed after a host
    // migration) return '' rather than the still-encrypted blob, so callers
    // treat it as "no password" and fall back to native mail() instead of
    // authenticating with the raw ciphertext (which fails on every send).
    return $decrypted !== false ? $decrypted : '';
}

/**
 * Lấy IP thật của client, hỗ trợ Cloudflare, Nginx reverse proxy, Load Balancer.
 * Fallback về REMOTE_ADDR nếu không có header proxy.
 */
function sv_get_trusted_proxy_ips() {
    $raw = get_option( 'sv_sec_trusted_proxy_ips', '' );
    $ips = preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );

    return array_values( array_unique( array_filter( array_map( 'trim', (array) apply_filters( 'sv_trusted_proxy_ips', $ips ) ) ) ) );
}

function sv_ip_matches_trusted_proxy( $ip, $trusted_proxy ) {
    if ( '' === $ip || '' === $trusted_proxy ) {
        return false;
    }

    if ( false !== strpos( $trusted_proxy, '/' ) ) {
        return sv_ip_in_cidr( $ip, $trusted_proxy );
    }

    return $ip === $trusted_proxy;
}

function sv_remote_addr_is_trusted_proxy() {
    $remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    if ( ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
        return false;
    }

    foreach ( sv_get_trusted_proxy_ips() as $trusted_proxy ) {
        if ( sv_ip_matches_trusted_proxy( $remote_addr, $trusted_proxy ) ) {
            return true;
        }
    }

    return false;
}

function sv_is_effectively_ssl() {
    if ( is_ssl() ) {
        return true;
    }

    if ( ! sv_remote_addr_is_trusted_proxy() && empty( $_SERVER['HTTP_CF_RAY'] ) ) {
        return false;
    }

    $forwarded_proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) : '';
    if ( 'https' === $forwarded_proto ) {
        return true;
    }

    $cf_visitor = isset( $_SERVER['HTTP_CF_VISITOR'] ) ? wp_unslash( $_SERVER['HTTP_CF_VISITOR'] ) : '';
    return is_string( $cf_visitor ) && false !== stripos( $cf_visitor, '"scheme":"https"' );
}

function sv_get_client_ip() {
    // Trust CF-Connecting-IP only when CF-Ray header is present (real Cloudflare proxy)
    if ( !empty($_SERVER['HTTP_CF_RAY']) && !empty($_SERVER['HTTP_CF_CONNECTING_IP']) ) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        if ( filter_var($ip, FILTER_VALIDATE_IP) ) {
            return $ip;
        }
    }

    if ( sv_remote_addr_is_trusted_proxy() && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $forwarded = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
        foreach ( $forwarded as $candidate ) {
            $candidate = trim( $candidate );
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                return $candidate;
            }
        }
    }

    // Fallback to REMOTE_ADDR (cannot be spoofed at TCP level)
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
}

function sv_get_premium_api_auth_args( $purpose, $legacy_seed = '' ) {
    $args = array(
        'purpose' => sanitize_key( $purpose ),
        'site'    => home_url(),
        'ts'      => time(),
        'nonce'   => wp_generate_password( 20, false, false ),
    );

    if ( '' !== $legacy_seed ) {
        // Legacy token kept for backward compatibility with older server endpoints.
        $args['token'] = md5( $legacy_seed . wp_date( 'Y-m-d' ) );

        // Stronger forward-compatible HMAC bound to seed + full payload.
        // Server may verify this if upgraded; ignored otherwise.
        $payload      = $args['purpose'] . '|' . $args['site'] . '|' . $args['ts'] . '|' . $args['nonce'];
        $args['hmac'] = hash_hmac( 'sha256', $payload, $legacy_seed . wp_date( 'Y-m-d' ) );
    }

    $secret = defined( 'SV_PREMIUM_API_SECRET' ) ? (string) SV_PREMIUM_API_SECRET : '';
    if ( '' !== $secret ) {
        $payload           = $args['purpose'] . '|' . $args['site'] . '|' . $args['ts'] . '|' . $args['nonce'];
        $args['signature'] = hash_hmac( 'sha256', $payload, $secret );
    }

    return apply_filters( 'sv_premium_api_auth_args', $args, $purpose );
}

function sv_get_premium_api_url( $endpoint, $purpose, $legacy_seed = '', $extra_args = array() ) {
    $endpoint = ltrim( (string) $endpoint, '/' );
    $base_url = 'https://theme.trungtq.io.vn/' . $endpoint;

    return add_query_arg(
        array_merge( sv_get_premium_api_auth_args( $purpose, $legacy_seed ), (array) $extra_args ),
        $base_url
    );
}

function sv_premium_pick_first_value( $source, $keys ) {
    $source  = (array) $source;
    $sources = array( $source );
    foreach ( array( 'connect_data', 'data', 'account', 'license' ) as $nested_key ) {
        if ( isset( $source[ $nested_key ] ) && is_array( $source[ $nested_key ] ) ) {
            $sources[] = $source[ $nested_key ];
        }
    }

    foreach ( $sources as $candidate ) {
        foreach ( $keys as $key ) {
            if ( isset( $candidate[ $key ] ) && '' !== trim( (string) $candidate[ $key ] ) ) {
                return (string) $candidate[ $key ];
            }
        }
    }

    return '';
}

function sv_premium_build_connect_data( $api_key, $source = array() ) {
    $api_key  = sanitize_text_field( $api_key );
    $username = sv_premium_pick_first_value( (array) $source, array(
        'username',
        'user',
        'user_login',
        'login',
        'login_name',
        'account',
        'account_name',
        'display_name',
        'name',
        'mts_username',
        'mythemeshop_username',
    ) );
    $email    = sv_premium_pick_first_value( (array) $source, array(
        'email',
        'mail',
        'user_email',
        'account_email',
        'mts_email',
        'mythemeshop_email',
    ) );

    if ( defined( 'SV_MTS_USERNAME' ) && '' !== trim( (string) SV_MTS_USERNAME ) ) {
        $username = (string) SV_MTS_USERNAME;
    }
    if ( defined( 'SV_MTS_EMAIL' ) && '' !== trim( (string) SV_MTS_EMAIL ) ) {
        $email = (string) SV_MTS_EMAIL;
    }

    $username = sanitize_text_field( $username );
    $email    = sanitize_email( $email );

    if ( '' === $email && is_email( $username ) ) {
        $email = sanitize_email( $username );
    }

    if ( '' === $username && '' !== $email ) {
        $email_parts = explode( '@', $email );
        $username    = sanitize_user( $email_parts[0], true );
    }

    // Fallback to the real MyThemeShop account that iNET bulk license is registered under.
    // Using the correct username ensures MTS server-side re-verification succeeds even after
    // Sitevorx Pro is deactivated — otherwise MTS detects username mismatch and clears license.
    if ( '' === $username ) {
        $username = defined( 'SV_MTS_USERNAME' ) && '' !== trim( (string) SV_MTS_USERNAME )
            ? (string) SV_MTS_USERNAME
            : 'payment4';
    }

    if ( '' === $email ) {
        $email = sanitize_email( get_option( 'admin_email' ) );
    }

    return array(
        'username'  => $username,
        'email'     => $email,
        'api_key'   => $api_key,
        'connected' => true,
    );
}

/**
 * Phát hiện xung đột với các plugin phổ biến.
 * Trả về mảng ['feature' => ['Plugin Name 1', ...]]
 */
function sv_detect_conflicts() {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $checks = array(
        'smtp' => array(
            'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP',
            'post-smtp/postman-smtp.php' => 'Post SMTP',
            'fluent-smtp/fluent-smtp.php' => 'FluentSMTP',
            'easy-wp-smtp/easy-wp-smtp.php' => 'Easy WP SMTP',
        ),
        'login_url' => array(
            'wps-hide-login/wps-hide-login.php' => 'WPS Hide Login',
        ),
        'xmlrpc' => array(
            'jetpack/jetpack.php' => 'Jetpack',
        ),
        'limit_login' => array(
            'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' => 'Limit Login Attempts Reloaded',
        ),
        'recaptcha' => array(
            'google-captcha/google-captcha.php' => 'Google Captcha by BestWebSoft',
        ),
    );
    $conflicts = array();
    foreach ($checks as $feature => $plugins) {
        foreach ($plugins as $file => $name) {
            if (is_plugin_active($file)) {
                $conflicts[$feature][] = $name;
            }
        }
    }
    return $conflicts;
}

// ==========================================================================
// ASSET ENQUEUE — CSS & JS
// ==========================================================================
add_action('admin_enqueue_scripts', function($hook) {
    // Only load on our plugin pages
    if (strpos($hook, 'sv-') === false && $hook !== 'toplevel_page_sitevorx') return;

    $css_file = SV_PLUGIN_DIR . 'assets/css/sv-admin.css';
    $js_file  = SV_PLUGIN_DIR . 'assets/js/sv-admin.js';

    $css_version = file_exists( $css_file ) ? filemtime( $css_file ) : SV_PLUGIN_VERSION;
    $js_version  = file_exists( $js_file ) ? filemtime( $js_file ) : SV_PLUGIN_VERSION;

    wp_enqueue_style(
        'sv-admin-css',
        SV_PLUGIN_URL . 'assets/css/sv-admin.css',
        array(),
        $css_version
    );

    wp_enqueue_script(
        'sv-admin-js',
        SV_PLUGIN_URL . 'assets/js/sv-admin.js',
        array('jquery'),
        $js_version,
        true
    );

    wp_localize_script( 'sv-admin-js', 'svToolkit', array(
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'langNonce'   => wp_create_nonce( 'sv_lang_toggle_nonce' ),
        'pluginUpdateNonce' => wp_create_nonce( 'sv_plugin_update_nonce' ),
        'currentLang' => get_option( 'sv_toolkit_language', 'vi' ),
        'autoTranslateConsent' => get_option( 'sv_auto_translate_external_consent', '0' ),
        'i18n'        => array(
            'close'          => __( 'Đóng', 'sitevorx' ),
            'saving'         => __( 'Đang lưu...', 'sitevorx' ),
            'copied'         => __( 'Đã sao chép cấu hình vào clipboard!', 'sitevorx' ),
            'downloaded'     => __( 'Đã tải file cấu hình xuống!', 'sitevorx' ),
            'switching'      => __( 'Đang chuyển ngôn ngữ...', 'sitevorx' ),
            'switchSuccess'  => __( 'Đã lưu ngôn ngữ. Đang tải lại...', 'sitevorx' ),
            'switchError'    => __( 'Không thể chuyển ngôn ngữ. Vui lòng thử lại.', 'sitevorx' ),
            'externalTranslateTitle' => __( 'Cho phép dịch tự động?', 'sitevorx' ),
            'externalTranslateConsent' => __( 'Một số chuỗi chưa có bản dịch sẵn có thể được gửi tới Google Translate để dịch tự động. Chỉ bật nếu bạn đồng ý gửi các chuỗi giao diện này ra dịch vụ bên ngoài.', 'sitevorx' ),
            'confirmTitle'   => __( 'Xác nhận hành động', 'sitevorx' ),
            'confirmCancel'  => __( 'Hủy bỏ', 'sitevorx' ),
            'confirmOk'      => __( 'Xác nhận', 'sitevorx' ),
            'confirmDefault' => __( 'Bạn có chắc chắn?', 'sitevorx' ),
            'pluginUpdating' => __( 'Đang cập nhật...', 'sitevorx' ),
            'pluginUpdated'  => __( 'Đã cập nhật', 'sitevorx' ),
            'pluginUpdateFailed' => __( 'Cập nhật thất bại. Vui lòng thử lại.', 'sitevorx' ),
            'pluginVersionUpdated' => __( 'Đã cập nhật lên phiên bản %s.', 'sitevorx' ),
            'pluginCurrentVersion' => __( 'Phiên bản hiện tại: %s', 'sitevorx' ),
            'pluginNoUpdatesLeft' => __( 'Tất cả plugin đã cập nhật mới nhất!', 'sitevorx' ),
        ),
    ) );
});

// ==========================================================================
// SIDEBAR RENDERER
// ==========================================================================
function sv_render_sidebar($current_page) {
    if ($current_page == 'dashboard') return;
    // Status indicators for sidebar
    $statuses = [
        'optimizer'    => (get_option('sv_opt_lazy_load') == '1' || get_option('sv_opt_limit_revisions') == '1' || get_option('sv_sec_enable_login_key') == '1'),
        'smtp'         => !empty(get_option('sv_active_mailer', '')),
        'utilities'    => (get_option('sv_util_disable_copy') == '1' || get_option('sv_util_maintenance') == '1' || get_option('sv_util_custom_login_logo') == '1'),
        'contact'      => (!empty(get_option('sv_contact_phone')) || !empty(get_option('sv_contact_zalo'))),
    ];
    // Điều hướng chia NHÓM cho gọn & có hệ thống. Premium nổi bật trên cùng, Hỗ trợ ở
    // dưới cùng; phần giữa gom theo chức năng. Chấm trạng thái giữ nguyên cho các mục có.
    $nav_top = [
        'premium' => [__('Premium', 'sitevorx'), 'star-filled'],
    ];
    $nav_groups = [
        __('Hiệu năng', 'sitevorx') => [
            'optimizer'    => [__('Tối ưu Tốc Độ', 'sitevorx'), 'performance'],
            'disk-cleaner' => [__('Quản lý Dung lượng', 'sitevorx'), 'trash'],
        ],
        __('Bảo mật', 'sitevorx') => [
            'security-center' => [__('Trung tâm Bảo mật', 'sitevorx'), 'shield-alt'],
            'audit-log'       => [__('Nhật ký Kiểm toán', 'sitevorx'), 'list-view'],
        ],
        __('Cấu hình', 'sitevorx') => [
            'utilities'     => [__('Tiện ích Website', 'sitevorx'), 'admin-tools'],
            'smtp'          => [__('Cấu hình SMTP', 'sitevorx'), 'email-alt'],
            'import-export' => [__('Nhập/Xuất Cấu hình', 'sitevorx'), 'download'],
        ],
        __('Dữ liệu & Hệ thống', 'sitevorx') => [
            'backup'            => [__('Sao lưu / Di chuyển', 'sitevorx'), 'cloud'],
            'maintenance-check' => [__('Bảo trì & Cập nhật', 'sitevorx'), 'update'],
            'server-info'       => [__('Thông số Server', 'sitevorx'), 'desktop'],
        ],
    ];
    $nav_bottom = [
        'support' => [__('Trung Tâm Hỗ Trợ', 'sitevorx'), 'sos'],
    ];
    $sv_render_item = function ($slug, $data) use ($current_page, $statuses) {
        $has = isset($statuses[$slug]);
        $on  = $has && $statuses[$slug];
        $active = ($current_page === $slug) ? ' active' : '';
        echo '<a href="?page=sv-' . esc_attr($slug) . '" class="sv-nav-item' . $active . '">'
            . '<span class="dashicons dashicons-' . esc_attr($data[1]) . '"></span>'
            . '<span class="sv-nav-text">' . esc_html($data[0]) . '</span>'
            . ($has ? '<span class="sv-status-dot ' . ($on ? 'on' : 'off') . '"></span>' : '')
            . '</a>';
    };
    ?>
    <style>
        .sv-nav-group{padding:14px 16px 5px;font-size:10.5px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--sv-muted,#9aa1ad)}
        .sv-sidebar.collapsed .sv-nav-group{height:1px;padding:6px 0;text-indent:-9999px;overflow:hidden}
    </style>
    <div class="sv-sidebar">
        <div class="sv-sidebar-inner">
            <div class="sv-sidebar-logo">
                <span class="sv-sidebar-brand">Sitevorx Pro</span>
                <button class="sv-sidebar-toggle" title="<?php esc_attr_e('Thu gọn', 'sitevorx'); ?>">&#9664;</button>
            </div>
            <div style="padding: 10px 0;">
                <a href="?page=sitevorx" class="sv-nav-item <?php echo ($current_page == 'dashboard') ? 'active' : ''; ?>"><span class="dashicons dashicons-dashboard"></span> <span class="sv-nav-text"><?php esc_html_e('Trang chủ', 'sitevorx'); ?></span></a>
                <?php foreach ($nav_top as $slug => $data) { $sv_render_item($slug, $data); } ?>
                <?php foreach ($nav_groups as $group_label => $group_items) : ?>
                    <div class="sv-nav-group"><?php echo esc_html($group_label); ?></div>
                    <?php foreach ($group_items as $slug => $data) { $sv_render_item($slug, $data); } ?>
                <?php endforeach; ?>
                <?php foreach ($nav_bottom as $slug => $data) { $sv_render_item($slug, $data); } ?>
            </div>
            <div class="sv-lang-toggle" id="soLangToggle">
                <?php $current_lang = get_option( 'sv_toolkit_language', 'vi' ); ?>
                <button type="button" class="sv-lang-btn <?php echo $current_lang !== 'en_US' ? 'active' : ''; ?>" data-lang="vi">VI</button>
                <button type="button" class="sv-lang-btn <?php echo $current_lang === 'en_US' ? 'active' : ''; ?>" data-lang="en_US">EN</button>
            </div>
            <div class="sv-sidebar-version">
                <span class="sv-nav-text">v<?php echo esc_html(SV_PLUGIN_VERSION); ?></span>
            </div>
        </div>
    </div>
    <?php
}


// ==========================================================================
// HEALTH SCORE CALCULATOR
// ==========================================================================
function sv_calculate_health_score() {
    // Deprecated in favor of the System Overview grid
    return ['score' => 0, 'checks' => []];
}

// ==========================================================================
// MODULE LOADER
// ==========================================================================
// Note: sv_run_migration() is already wired to register_activation_hook and
// admin_init above — no need to invoke it directly here.

$modules = ['sv-audit', 'smtp', 'system-optimizer', 'sv-security-center', 'sv-security-center-ui', 'sv-security-center-tabs', 'sv-security-center-scanner', 'sv-utilities', 'sv-server-info', 'sv-disk-cleaner', 'sv-contact', 'sv-import-export', 'sv-scheduled-cleanup', 'sv-malware-scanner', 'sv-maintenance-check', 'sv-premium', 'sv-mts-active', 'sv-rankmath', 'sv-support', 'sv-s3-config', 'sv-s3-client', 'sv-backup', 'sv-telemetry'];
foreach ($modules as $m) {
    if (file_exists(SV_PLUGIN_DIR . "includes/$m.php")) {
        require_once SV_PLUGIN_DIR . "includes/$m.php";
    }
}

add_action( 'admin_init', function() {
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( '' === $page ) {
        return;
    }

    $redirect_page = '';
    if ( 'sitepilot' === $page || 'siteops' === $page ) {
        $redirect_page = 'sitevorx';
    } elseif ( 0 === strpos( $page, 'sp-' ) ) {
        $redirect_page = 'sv-' . substr( $page, 3 );
    } elseif ( 0 === strpos( $page, 'so-' ) ) {
        $redirect_page = 'sv-' . substr( $page, 3 );
    }

    if ( '' === $redirect_page ) {
        return;
    }

    $query = wp_unslash( $_GET );
    $query['page'] = $redirect_page;
    wp_safe_redirect( add_query_arg( array_map( 'sanitize_text_field', $query ), admin_url( 'admin.php' ) ) );
    exit;
}, 1 );

// ==========================================================================
// RESET HANDLER (must run on admin_init, before page output)
// ==========================================================================
add_action('admin_init', function() {
    $action = isset($_POST['sv_action']) ? sanitize_key(wp_unslash($_POST['sv_action'])) : '';
    if ( $action !== 'reset_all' ) {
        return;
    }
    if ( !current_user_can('manage_options') ) {
        return;
    }
    $reset_nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $reset_nonce, 'sv_reset_nonce' ) ) {
        wp_die('Nonce verification failed.');
    }

    if ( function_exists( 'sv_audit_log' ) ) {
        sv_audit_log( 'settings_reset' );
    }

    global $wpdb;
    foreach ( sv_get_managed_option_keys() as $option_name ) {
        if ( 'sv_audit_log' === $option_name ) {
            continue; // preserve audit trail across reset
        }
        delete_option( $option_name );
    }
    foreach ( sv_get_legacy_option_keys() as $option_name ) {
        delete_option( $option_name );
    }
    foreach ( sv_get_siteops_option_keys() as $option_name ) {
        delete_option( $option_name );
    }
    foreach ( sv_get_inet_option_keys() as $option_name ) {
        delete_option( $option_name );
    }
    foreach ( sv_get_managed_transient_keys() as $transient_name ) {
        delete_transient( $transient_name );
    }
    foreach ( sv_get_legacy_transient_keys() as $transient_name ) {
        delete_transient( $transient_name );
    }
    foreach ( sv_get_siteops_transient_keys() as $transient_name ) {
        delete_transient( $transient_name );
    }
    delete_option( 'sv_non_inet_strikes' );
    delete_option( 'sv_migration_version' );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sp_login_attempts_%' OR option_name LIKE '_transient_timeout_sp_login_attempts_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_so_login_attempts_%' OR option_name LIKE '_transient_timeout_so_login_attempts_%'" );
    sv_delete_removed_security_center_data();
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sp_smtp_logs" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}so_smtp_logs" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sv_smtp_logs" );
    wp_clear_scheduled_hook( 'sp_scheduled_cleanup_event' );
    wp_clear_scheduled_hook( 'so_scheduled_cleanup_event' );
    wp_clear_scheduled_hook( 'sv_scheduled_cleanup_event' );
    wp_clear_scheduled_hook( 'sp_license_heartbeat' );
    wp_clear_scheduled_hook( 'so_license_heartbeat' );
    wp_clear_scheduled_hook( 'sv_license_heartbeat' );
    wp_cache_flush();

    wp_safe_redirect( admin_url('admin.php?page=sv-import-export&reset=done') );
    exit;
});

// ==========================================================================
// ADMIN MENU REGISTRATION
// ==========================================================================
add_action('admin_menu', 'sv_plugin_menu');
function sv_plugin_menu() {
    add_menu_page('Sitevorx Pro', 'Sitevorx Pro', 'manage_options', 'sitevorx', 'sv_display_dashboard_page', 'dashicons-admin-site-alt3', 2);
    add_submenu_page('sitevorx', __('Dashboard', 'sitevorx'), __('Dashboard', 'sitevorx'), 'manage_options', 'sitevorx', 'sv_display_dashboard_page');

    $subpages = [
        'premium'          => __('Premium', 'sitevorx'),
        'optimizer'        => __('Tối ưu Tốc Độ', 'sitevorx'),
        'security-center'  => __('Trung tâm Bảo mật', 'sitevorx'),
        'smtp'             => __('Cấu hình SMTP', 'sitevorx'),
        'utilities'        => __('Tiện ích Website', 'sitevorx'),
        'disk-cleaner'     => __('Quản lý Dung lượng', 'sitevorx'),
        'import-export'    => __('Nhập/Xuất Cấu hình', 'sitevorx'),
        'backup'           => __('Sao lưu / Di chuyển', 'sitevorx'),
        'maintenance-check' => __('Bảo trì & Cập nhật', 'sitevorx'),
        'support'          => __('Trung Tâm Hỗ Trợ', 'sitevorx'),
        'server-info'      => __('Thông số Server', 'sitevorx'),
        'audit-log'        => __('Nhật ký Kiểm toán', 'sitevorx'),
    ];
    foreach($subpages as $slug => $title) {
        $func = 'sv_display_' . str_replace('-', '_', $slug) . '_page';
        if (function_exists($func)) {
            add_submenu_page('sitevorx', $title, $title, 'manage_options', "sv-$slug", $func);
        }
    }
}

/**
 * Gọn menu trái WP: ẩn các mục phụ KHỎI HIỂN THỊ bằng CSS (không dùng
 * remove_submenu_page — cái đó làm WordPress chặn truy cập trang: parent bị resolve
 * về 'admin.php' nên hookname không khớp $_registered_pages → "không được phép").
 * Trang vẫn đăng ký bình thường → vào được qua URL + sidebar trong trang; chỉ ẩn
 * khỏi submenu cho gọn.
 */
add_action( 'admin_head', function() {
    $hidden = array( 'sv-smtp', 'sv-utilities', 'sv-disk-cleaner', 'sv-import-export', 'sv-maintenance-check', 'sv-server-info', 'sv-audit-log' );
    $sel = array();
    foreach ( $hidden as $s ) {
        $sel[] = '#adminmenu .wp-submenu a[href$="page=' . $s . '"]';        // ẩn link (mọi trình duyệt)
        $sel[] = '#adminmenu .wp-submenu li:has(> a[href$="page=' . $s . '"])'; // ẩn cả mục (trình duyệt mới)
    }
    echo '<style>' . implode( ',', $sel ) . '{display:none !important;}</style>';
} );

// ==========================================================================
// DASHBOARD PAGE
// ==========================================================================
function sv_display_dashboard_page() {
    global $wpdb;

    if ( ! function_exists( 'recurse_dirsize' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $db_size = get_transient( 'sv_dashboard_db_size' );
    if ( $db_size === false ) {
        $db_size = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s", DB_NAME ) );
        set_transient( 'sv_dashboard_db_size', $db_size, 24 * HOUR_IN_SECONDS );
    }

    $content_size = get_transient( 'sv_dashboard_content_size' );
    if ( $content_size === false ) {
        $content_size = recurse_dirsize( WP_CONTENT_DIR );
        set_transient( 'sv_dashboard_content_size', $content_size, 24 * HOUR_IN_SECONDS );
    }

    $upload_dir  = wp_upload_dir();
    $upload_size = get_transient( 'sv_dashboard_upload_size' );
    if ( $upload_size === false ) {
        $upload_size = recurse_dirsize( $upload_dir['basedir'] );
        set_transient( 'sv_dashboard_upload_size', $upload_size, 24 * HOUR_IN_SECONDS );
    }

    $site_footprint = intval( $content_size ) + intval( $db_size );

    // 9 security layers tracked, mirrors Sitevorx Free 1.1.0.
    $sec_layers = array(
        array( 'on' => get_option( 'sv_sec_enable_recaptcha' ) == '1', 'label' => __( 'reCAPTCHA', 'sitevorx' ),               'tab' => 'sv-security-center&tab=config' ),
        array( 'on' => get_option( 'sv_sec_limit_login' ) == '1',       'label' => __( 'Giới hạn đăng nhập', 'sitevorx' ),     'tab' => 'sv-security-center&tab=config' ),
        array( 'on' => get_option( 'sv_sec_enable_login_key' ) == '1',  'label' => __( 'URL đăng nhập bí mật', 'sitevorx' ),   'tab' => 'sv-security-center&tab=config' ),
        array( 'on' => get_option( 'sv_sec_disable_xmlrpc' ) == '1',    'label' => __( 'Khóa XML-RPC', 'sitevorx' ),           'tab' => 'sv-security-center&tab=config' ),
        array( 'on' => get_option( 'sv_sec_disable_editor' ) == '1' || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ), 'label' => __( 'Khóa Code Editor', 'sitevorx' ), 'tab' => 'sv-security-center&tab=config' ),
        array( 'on' => get_option( 'sv_sec_headers_enabled' ) == '1',   'label' => __( 'Security Headers', 'sitevorx' ),       'tab' => 'sv-security-center&tab=headers' ),
        array( 'on' => get_option( 'sv_sec_honeypot_enabled' ) == '1',  'label' => __( 'Honeypot đăng nhập', 'sitevorx' ),     'tab' => 'sv-security-center&tab=monitor' ),
        array( 'on' => get_option( 'sv_sec_block_user_enum' ) == '1',   'label' => __( 'Chặn dò username', 'sitevorx' ),       'tab' => 'sv-security-center&tab=monitor' ),
        array( 'on' => get_option( 'sv_sec_login_notify' ) == '1',      'label' => __( 'Báo email admin login', 'sitevorx' ),  'tab' => 'sv-security-center&tab=monitor' ),
    );
    $sec_total    = count( $sec_layers );
    $sec_active   = 0;
    $sec_off_list = array();
    foreach ( $sec_layers as $lyr ) {
        if ( $lyr['on'] ) { $sec_active++; } else { $sec_off_list[] = $lyr; }
    }
    $sec_checks     = array_map( function ( $l ) { return $l['on']; }, $sec_layers );
    $sec_dot_labels = array_map( function ( $l ) { return $l['label']; }, $sec_layers );
    $sec_ratio      = $sec_active / max( 1, $sec_total );
    $sec_class      = $sec_ratio >= 0.75 ? 'green' : ( $sec_ratio >= 0.4 ? 'yellow' : 'red' );
    $security_badge = $sec_ratio >= 0.75 ? __( 'Mạnh', 'sitevorx' ) : ( $sec_ratio >= 0.4 ? __( 'Trung bình', 'sitevorx' ) : __( 'Yếu', 'sitevorx' ) );

    $mailer     = get_option( 'sv_active_mailer', '' );
    $smtp_label = $mailer == 'gmail' ? 'Gmail SMTP' : ( $mailer == 'other' ? 'SMTP Custom' : __( 'CHƯA CẤU HÌNH', 'sitevorx' ) );

    $cron_enabled       = get_option( 'sv_cron_cleanup_enabled', '0' ) === '1';
    $next_run           = wp_next_scheduled( 'sv_scheduled_cleanup_event' );
    $cron_misconfigured = $cron_enabled && empty( $next_run );
    $cron_class         = $cron_misconfigured ? 'red' : ( $cron_enabled ? 'purple' : 'gray' );
    $cron_badge         = $cron_misconfigured
        ? __( 'Lỗi lịch', 'sitevorx' )
        : ( $cron_enabled ? __( 'Đang chạy', 'sitevorx' ) : __( 'Đang tắt', 'sitevorx' ) );

    $smtp_ready = false;
    if ( 'gmail' === $mailer ) {
        $smtp_ready = get_option( 'sv_gmail_user', '' ) !== '' && get_option( 'sv_gmail_pass', '' ) !== '';
    } elseif ( 'other' === $mailer ) {
        $smtp_ready = get_option( 'sv_smtp_host', '' ) !== ''
            && get_option( 'sv_smtp_user', '' ) !== ''
            && get_option( 'sv_smtp_pass', '' ) !== '';
    }
    $smtp_misconfigured = ( $mailer !== '' ) && ! $smtp_ready;

    $recaptcha_on   = get_option( 'sv_sec_enable_recaptcha' ) === '1';
    $recaptcha_keys = get_option( 'sv_sec_recaptcha_site_key', '' ) !== ''
        && get_option( 'sv_sec_recaptcha_secret_key', '' ) !== '';
    $recaptcha_misconfigured = $recaptcha_on && ! $recaptcha_keys;

    $maintenance_on   = get_option( 'sv_util_maintenance' ) === '1';
    $debug_on         = defined( 'WP_DEBUG' ) && WP_DEBUG;
    $wp_cron_disabled = defined( 'DISALLOW_WP_CRON' ) && DISALLOW_WP_CRON;

    $smtp_class = $smtp_misconfigured ? 'red' : ( $mailer ? 'blue' : 'gray' );
    $smtp_badge = $smtp_misconfigured
        ? __( 'Thiếu credential', 'sitevorx' )
        : ( $mailer ? __( 'Sẵn sàng', 'sitevorx' ) : __( 'Tùy chọn', 'sitevorx' ) );

    $smtp_recent_failures = 0;
    if ( $mailer !== '' && get_option( 'sv_smtp_enable_log', '0' ) === '1' && function_exists( 'sv_smtp_get_log_table_name' ) ) {
        $log_table = sv_smtp_get_log_table_name();
        if ( preg_match( '/^[A-Za-z0-9_]+$/', $log_table ) ) {
            $smtp_recent_failures = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$log_table}` WHERE status != %s AND time > %s",
                    'success',
                    gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
                )
            );
        }
    }

    $active_lockouts = 0;
    if ( get_option( 'sv_sec_limit_login' ) === '1' && function_exists( 'sv_login_get_current_lockouts' ) ) {
        $active_lockouts = count( sv_login_get_current_lockouts() );
    }

    $optimizer_url = admin_url( 'admin.php?page=sv-optimizer&tab=security' );
    $smtp_url      = admin_url( 'admin.php?page=sv-smtp' );
    $utilities_url = admin_url( 'admin.php?page=sv-utilities' );
    $cleanup_url   = admin_url( 'admin.php?page=sv-maintenance-check' );

    $issues = array();
    if ( $sec_active < 4 ) {
        $issues[] = array( 'text' => __( 'Bảo mật chưa đạt mức khuyến nghị (bật thêm reCAPTCHA, Khóa IP, Đổi URL đăng nhập…).', 'sitevorx' ), 'url' => $optimizer_url, 'cta' => __( 'Mở Bảo Mật', 'sitevorx' ) );
    }
    if ( ! $cron_enabled ) {
        $issues[] = array( 'text' => __( 'Dọn dẹp tự động đang tắt.', 'sitevorx' ), 'url' => $cleanup_url, 'cta' => __( 'Bật cron', 'sitevorx' ) );
    }
    if ( $cron_misconfigured ) {
        $issues[] = array( 'text' => __( 'Cron dọn dẹp đã bật nhưng chưa có lịch chạy — vào trang Bảo trì lưu lại cấu hình để đặt lại lịch.', 'sitevorx' ), 'url' => $cleanup_url, 'cta' => __( 'Đặt lại lịch', 'sitevorx' ) );
    }
    if ( $wp_cron_disabled ) {
        $issues[] = array( 'text' => __( 'wp-config.php có DISALLOW_WP_CRON=true — WP-Cron nội bộ bị tắt. Hãy chắc chắn rằng server cron / hosting panel đang gọi wp-cron.php định kỳ.', 'sitevorx' ), 'url' => '', 'cta' => '' );
    }
    if ( $smtp_misconfigured ) {
        $issues[] = array( 'text' => __( 'SMTP đã chọn nhưng thiếu thông tin đăng nhập.', 'sitevorx' ), 'url' => $smtp_url, 'cta' => __( 'Hoàn tất SMTP', 'sitevorx' ) );
    }
    if ( $smtp_recent_failures > 0 ) {
        $issues[] = array( 'text' => sprintf( _n( '%d email SMTP thất bại trong 24h qua.', '%d email SMTP thất bại trong 24h qua.', $smtp_recent_failures, 'sitevorx' ), $smtp_recent_failures ), 'url' => admin_url( 'admin.php?page=sv-smtp&tab=logs' ), 'cta' => __( 'Xem log', 'sitevorx' ) );
    }
    if ( $recaptcha_misconfigured ) {
        $issues[] = array( 'text' => __( 'reCAPTCHA đang bật nhưng thiếu Site Key hoặc Secret Key — đăng nhập có thể fail.', 'sitevorx' ), 'url' => $optimizer_url, 'cta' => __( 'Bổ sung key', 'sitevorx' ) );
    }
    if ( $active_lockouts > 0 ) {
        $issues[] = array( 'text' => sprintf( _n( 'Có %d IP đang bị khóa đăng nhập.', 'Có %d IP đang bị khóa đăng nhập.', $active_lockouts, 'sitevorx' ), $active_lockouts ), 'url' => $optimizer_url, 'cta' => __( 'Quản lý', 'sitevorx' ) );
    }
    if ( $maintenance_on ) {
        $issues[] = array( 'text' => __( 'Chế độ Bảo trì đang BẬT — khách truy cập không xem được site.', 'sitevorx' ), 'url' => $utilities_url, 'cta' => __( 'Tắt bảo trì', 'sitevorx' ) );
    }
    if ( $debug_on ) {
        $issues[] = array( 'text' => __( 'WP_DEBUG đang bật trên môi trường production — cân nhắc tắt khi go-live.', 'sitevorx' ), 'url' => '', 'cta' => '' );
    }

    $issue_count     = count( $issues );
    $cron_healthy    = $cron_enabled && ! $cron_misconfigured;
    $mailer_healthy  = $mailer !== '' && ! $smtp_misconfigured;
    $health_score    = min( 100, max( 32, intval( round( ( ( $sec_active / 5 ) * 70 ) + ( $cron_healthy ? 30 : 10 ) + ( $mailer_healthy ? 5 : 0 ) ) ) ) );
    $status_class    = $issue_count === 0 ? 'green' : ( $issue_count === 1 ? 'yellow' : 'red' );
    $status_label    = $issue_count === 0 ? __( 'Ổn định cao', 'sitevorx' ) : ( $issue_count === 1 ? __( 'Cần tối ưu nhẹ', 'sitevorx' ) : __( 'Cần chú ý', 'sitevorx' ) );
    $size_badge      = $site_footprint > ( 2 * 1024 * 1024 * 1024 ) ? __( 'Theo dõi', 'sitevorx' ) : __( 'Ước tính', 'sitevorx' );
    $health_messages = ! empty( $issues ) ? $issues : [ __( 'Không có hạng mục cần xử lý ngay.', 'sitevorx' ) ];

    // Server metrics
    $php_version  = phpversion();
    $memory_limit = ini_get( 'memory_limit' );
    $upload_max   = ini_get( 'upload_max_filesize' );
    $wp_version   = get_bloginfo( 'version' );
    $ssl_active   = is_ssl();

    // Feature active states for badges
    $feat_optimizer = get_option( 'sv_opt_lazy_load' ) == '1' || get_option( 'sv_opt_limit_revisions' ) == '1' || $sec_active > 0;
    $feat_utilities = get_option( 'sv_util_disable_copy' ) == '1' || get_option( 'sv_util_maintenance' ) == '1' || get_option( 'sv_util_custom_login_logo' ) == '1';

    // Storage bar percentages
    $total       = max( $site_footprint, 1 );
    $pct_content = $content_size > 0 ? min( 100, (int) round( ( $content_size / $total ) * 100 ) ) : 0;
    $pct_media   = $upload_size  > 0 ? min( 100, (int) round( ( $upload_size  / $total ) * 100 ) ) : 0;
    $pct_db      = $db_size      > 0 ? min( 100, (int) round( ( $db_size      / $total ) * 100 ) ) : 0;

    $current_lang = get_option( 'sv_toolkit_language', 'vi' );
    ?>
    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <div class="sv-content-area">

                <!-- ═══ HERO BANNER ═══ -->
                <div class="sv-dash-hero">
                    <div class="sv-dash-hero-dots"></div>
                    <div class="sv-lang-toggle sv-lang-toggle-dashboard" id="soLangToggle">
                        <button type="button" class="sv-lang-btn <?php echo $current_lang !== 'en_US' ? 'active' : ''; ?>" data-lang="vi">VI</button>
                        <button type="button" class="sv-lang-btn <?php echo $current_lang === 'en_US' ? 'active' : ''; ?>" data-lang="en_US">EN</button>
                    </div>
                    <div class="sv-dash-hero-inner">
                        <div class="sv-dash-hero-logo">
                            <span class="dashicons dashicons-admin-site-alt3"></span>
                        </div>
                        <h2><?php esc_html_e( 'Hệ Sinh Thái Sitevorx Pro', 'sitevorx' ); ?></h2>
                        <p><?php esc_html_e( 'Nền tảng quản trị, tối ưu và bảo mật Website toàn diện kèm Kho Giao Diện Premium và Rank Math SEO Pro.', 'sitevorx' ); ?></p>
                        <div class="sv-dash-hero-version">
                            <span class="sv-version-badge">v<?php echo esc_html( SV_PLUGIN_VERSION ); ?></span>
                        </div>
                    </div>
                </div>

                <!-- ═══ SYSTEM OVERVIEW ═══ -->
                <div class="sv-overview-section">
                    <div class="sv-section-heading">
                        <h2 class="sv-section-title"><?php esc_html_e( 'Tổng Quan Hệ Thống', 'sitevorx' ); ?></h2>
                    </div>

                    <div class="sv-overview-health sv-overview-health-<?php echo $status_class; ?>">
                        <div class="sv-overview-health-score-wrap">
                            <span class="sv-overview-health-label"><?php esc_html_e( 'Điểm sức khỏe website', 'sitevorx' ); ?></span>
                            <div class="sv-overview-health-score score-<?php echo $status_class; ?>" data-score="<?php echo $health_score; ?>"><?php echo $health_score; ?><span>/100</span></div>
                        </div>
                        <div class="sv-overview-health-divider"></div>
                        <div class="sv-overview-health-summary">
                            <span class="sv-overview-badge sv-overview-badge-<?php echo $status_class; ?>"><?php echo esc_html( $status_label ); ?></span>
                            <div class="sv-overview-health-items">
                                <?php foreach ( $health_messages as $message ) :
                                    if ( is_array( $message ) ) {
                                        $msg_text = isset( $message['text'] ) ? (string) $message['text'] : '';
                                        $msg_url  = isset( $message['url'] ) ? (string) $message['url'] : '';
                                        $msg_cta  = isset( $message['cta'] ) ? (string) $message['cta'] : '';
                                    } else {
                                        $msg_text = (string) $message;
                                        $msg_url  = '';
                                        $msg_cta  = '';
                                    }
                                ?>
                                <span>
                                    <?php echo esc_html( $msg_text ); ?>
                                    <?php if ( $msg_url && $msg_cta ) : ?>
                                        <a href="<?php echo esc_url( $msg_url ); ?>" style="margin-left:6px; font-weight:600; color:#2563eb; white-space:nowrap;"><?php echo esc_html( $msg_cta ); ?> →</a>
                                    <?php endif; ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="sv-overview-health-divider"></div>
                        <div class="sv-overview-health-bar-wrap">
                            <div class="sv-health-progress-bar">
                                <div class="sv-health-progress-fill sv-health-fill-<?php echo esc_attr( $status_class ); ?>" data-width="<?php echo (int) $health_score; ?>%" style="width:0%"></div>
                            </div>
                            <div style="font-size:12px; color:#64748b; margin-top:6px;">
                                <?php echo esc_html( sprintf( __( '%1$d/%2$d lớp bảo mật đang bật. Xem chi tiết bên dưới.', 'sitevorx' ), $sec_active, $sec_total ) ); ?>
                            </div>
                        </div>
                    </div>

                    <div class="sv-overview-grid">
                        <!-- Resources -->
                        <div class="sv-overview-card sv-overview-card-wide">
                            <div class="sv-ov-top">
                                <div class="sv-ov-icon sv-icon-green"><span class="dashicons dashicons-portfolio"></span></div>
                                <div class="sv-ov-data">
                                    <div class="sv-ov-headline-row">
                                        <h3 class="sv-ov-label"><?php esc_html_e( 'Tài nguyên website', 'sitevorx' ); ?></h3>
                                        <span class="sv-overview-badge sv-overview-badge-gray"><?php echo esc_html( $size_badge ); ?></span>
                                    </div>
                                    <strong class="sv-ov-value sv-ov-value-xl"><?php echo esc_html( sv_format_size( $site_footprint ) ); ?></strong>
                                    <div class="sv-ov-storage-bars">
                                        <div class="sv-storage-bar-row">
                                            <span class="sv-storage-bar-label">WP Content</span>
                                            <div class="sv-storage-bar"><div class="sv-storage-fill sv-fill-green" style="width:<?php echo $pct_content; ?>%"></div></div>
                                            <span class="sv-storage-bar-val"><?php echo esc_html( sv_format_size( $content_size ) ); ?></span>
                                        </div>
                                        <div class="sv-storage-bar-row">
                                            <span class="sv-storage-bar-label">Media</span>
                                            <div class="sv-storage-bar"><div class="sv-storage-fill sv-fill-blue" style="width:<?php echo $pct_media; ?>%"></div></div>
                                            <span class="sv-storage-bar-val"><?php echo esc_html( sv_format_size( $upload_size ) ); ?></span>
                                        </div>
                                        <div class="sv-storage-bar-row">
                                            <span class="sv-storage-bar-label">Database</span>
                                            <div class="sv-storage-bar"><div class="sv-storage-fill sv-fill-purple" style="width:<?php echo $pct_db; ?>%"></div></div>
                                            <span class="sv-storage-bar-val"><?php echo esc_html( sv_format_size( $db_size ) ); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <a href="?page=sv-disk-cleaner&tab=storage" class="sv-ov-action"><?php esc_html_e( 'Xem dung lượng chi tiết', 'sitevorx' ); ?> <span class="dashicons dashicons-arrow-right-alt2"></span></a>
                        </div>

                        <!-- Security -->
                        <div class="sv-overview-card">
                            <div class="sv-ov-top">
                                <div class="sv-ov-icon sv-icon-<?php echo $sec_class; ?>"><span class="dashicons dashicons-shield"></span></div>
                                <div class="sv-ov-data">
                                    <div class="sv-ov-headline-row">
                                        <h3 class="sv-ov-label"><?php esc_html_e( 'Lớp bảo mật', 'sitevorx' ); ?></h3>
                                        <span class="sv-overview-badge sv-overview-badge-<?php echo $sec_class; ?>"><?php echo esc_html( $security_badge ); ?></span>
                                    </div>
                                    <strong class="sv-ov-value sv-color-<?php echo $sec_class; ?>"><?php echo $sec_active; ?>/5 <span class="sv-ov-sub"><?php esc_html_e( 'lớp đang bật', 'sitevorx' ); ?></span></strong>
                                    <div class="sv-ov-progress"><span class="sv-prog-<?php echo $sec_class; ?>" data-width="<?php echo esc_attr( ( $sec_active / 5 ) * 100 ); ?>%" style="width:0%"></span></div>
                                    <p class="sv-ov-description"><?php echo $sec_active >= 4 ? esc_html__( 'Website đã bật gần như đầy đủ các lớp bảo mật quan trọng.', 'sitevorx' ) : sprintf( __( 'Còn %d lớp nên kích hoạt thêm.', 'sitevorx' ), 5 - $sec_active ); ?></p>
                                </div>
                            </div>
                            <div class="sv-ov-footnote"><?php printf( __( 'Đang bật %1$s mục, còn %2$s mục nên bổ sung.', 'sitevorx' ), '<strong>' . $sec_active . '</strong>', '<strong>' . ( 5 - $sec_active ) . '</strong>' ); ?></div>
                            <a href="?page=sv-optimizer&tab=security" class="sv-ov-action"><?php esc_html_e( 'Mở cấu hình bảo mật', 'sitevorx' ); ?> <span class="dashicons dashicons-arrow-right-alt2"></span></a>
                        </div>

                        <!-- Email -->
                        <div class="sv-overview-card">
                            <div class="sv-ov-top">
                                <div class="sv-ov-icon sv-icon-<?php echo $smtp_class; ?>"><span class="dashicons dashicons-email-alt"></span></div>
                                <div class="sv-ov-data">
                                    <div class="sv-ov-headline-row">
                                        <h3 class="sv-ov-label"><?php esc_html_e( 'Hệ thống email', 'sitevorx' ); ?></h3>
                                        <span class="sv-overview-badge sv-overview-badge-<?php echo $smtp_class; ?>"><?php echo esc_html( $smtp_badge ); ?></span>
                                    </div>
                                    <strong class="sv-ov-value sv-color-<?php echo $smtp_class; ?>"><?php echo esc_html( $smtp_label ); ?></strong>
                                    <p class="sv-ov-description"><?php echo $mailer ? esc_html__( 'Kênh gửi mail đã sẵn sàng cho các email thông báo quan trọng.', 'sitevorx' ) : esc_html__( 'Nên cấu hình SMTP để tránh lỗi gửi mail hoặc vào spam.', 'sitevorx' ); ?></p>
                                </div>
                            </div>
                            <div class="sv-ov-footnote"><?php echo $mailer ? sprintf( __( 'Trạng thái hiện tại: %sSẵn sàng gửi%s.', 'sitevorx' ), '<strong>', '</strong>' ) : sprintf( __( 'Khuyến nghị: %sThiết lập ngay%s để tránh lỗi gửi mail.', 'sitevorx' ), '<strong>', '</strong>' ); ?></div>
                            <a href="?page=sv-smtp" class="sv-ov-action"><?php esc_html_e( 'Mở cấu hình SMTP', 'sitevorx' ); ?> <span class="dashicons dashicons-arrow-right-alt2"></span></a>
                        </div>

                        <!-- Auto-opt -->
                        <div class="sv-overview-card">
                            <div class="sv-ov-top">
                                <div class="sv-ov-icon sv-icon-<?php echo $cron_class; ?>"><span class="dashicons dashicons-performance"></span></div>
                                <div class="sv-ov-data">
                                    <div class="sv-ov-headline-row">
                                        <h3 class="sv-ov-label"><?php esc_html_e( 'Tối ưu tự động', 'sitevorx' ); ?></h3>
                                        <span class="sv-overview-badge sv-overview-badge-<?php echo $cron_class; ?>"><?php echo esc_html( $cron_badge ); ?></span>
                                    </div>
                                    <strong class="sv-ov-value sv-color-<?php echo $cron_class; ?>"><?php echo $cron_enabled ? esc_html__( 'Đã bật', 'sitevorx' ) : esc_html__( 'Đang tắt', 'sitevorx' ); ?></strong>
                                    <p class="sv-ov-description"><?php echo $next_run ? sprintf( __( 'Lần chạy tiếp theo: %s.', 'sitevorx' ), wp_date( 'd/m/Y H:i', $next_run ) ) : esc_html__( 'Chưa có lịch chạy tự động.', 'sitevorx' ); ?></p>
                                </div>
                            </div>
                            <div class="sv-ov-footnote"><?php printf( __( 'Dọn dẹp tự động hiện đang %s.', 'sitevorx' ), '<strong>' . ( $cron_enabled ? esc_html__( 'hoạt động', 'sitevorx' ) : esc_html__( 'tắt', 'sitevorx' ) ) . '</strong>' ); ?></div>
                            <a href="?page=sv-optimizer&tab=optimizer" class="sv-ov-action"><?php esc_html_e( 'Mở tối ưu hệ thống', 'sitevorx' ); ?> <span class="dashicons dashicons-arrow-right-alt2"></span></a>
                        </div>
                    </div>
                </div>

                <!-- ═══ FEATURE MODULES ═══ -->
                <h2 class="sv-section-title"><?php esc_html_e( 'Phân Hệ Chức Năng', 'sitevorx' ); ?></h2>
                <div class="sv-card-grid">
                    <?php
                    $cards = [
                        [ 'page' => 'sv-premium',           'color' => 'purple', 'icon' => 'star-filled', 'title' => __( 'Premium', 'sitevorx' ),                    'desc' => __( 'Kho Giao Diện MyThemeShop bản quyền và Rank Math SEO Pro dành riêng cho khách hàng VIP.', 'sitevorx' ),               'active' => sv_is_inet_hosting() ],
                        [ 'page' => 'sv-optimizer',         'color' => 'blue',   'icon' => 'performance', 'title' => __( 'Tối Ưu Tốc Độ', 'sitevorx' ),             'desc' => __( 'Dọn rác Database, Lazy Load, giảm tải Heartbeat, gỡ Emoji / oEmbed / jQuery Migrate, dọn wp_head và lịch dọn tự động.', 'sitevorx' ),         'active' => $feat_optimizer ],
                        [ 'page' => 'sv-security-center',   'color' => 'orange', 'icon' => 'shield-alt',  'title' => __( 'Trung tâm Bảo mật', 'sitevorx' ),        'desc' => __( 'reCAPTCHA, khóa IP, URL đăng nhập bí mật, quét mã độc, Security Headers + HSTS, honeypot, kiểm tra core và sửa quyền file.', 'sitevorx' ), 'active' => $sec_active > 0 ],
                        [ 'page' => 'sv-smtp',              'color' => 'yellow', 'icon' => 'email-alt',   'title' => __( 'Cấu hình Gửi Mail SMTP', 'sitevorx' ),    'desc' => __( 'Đảm bảo email thông báo từ website luôn vào Inbox với tỉ lệ 99%.', 'sitevorx' ),                                        'active' => ! empty( $mailer ) ],
                        [ 'page' => 'sv-utilities',         'color' => 'green',  'icon' => 'admin-tools', 'title' => __( 'Tiện ích Website', 'sitevorx' ),           'desc' => __( 'Chèn mã Script, nút liên hệ nổi, bảo vệ nội dung, chế độ bảo trì và tuỳ biến trang đăng nhập.', 'sitevorx' ),          'active' => $feat_utilities ],
                        [ 'page' => 'sv-disk-cleaner',      'color' => 'red',    'icon' => 'trash',       'title' => __( 'Quản lý Dung lượng', 'sitevorx' ),         'desc' => __( 'Quét và xóa rác, file log, file backup rác để giải phóng bộ nhớ Hosting.', 'sitevorx' ),                              'active' => false ],
                        [ 'page' => 'sv-import-export',     'color' => 'cyan',   'icon' => 'download',    'title' => __( 'Nhập/Xuất Cấu hình', 'sitevorx' ),         'desc' => __( 'Sao lưu và khôi phục toàn bộ cấu hình Sitevorx giữa các website.', 'sitevorx' ),                                      'active' => false ],
                        [ 'page' => 'sv-backup',            'color' => 'blue',   'icon' => 'migrate',     'title' => __( 'Sao Lưu / Di Chuyển', 'sitevorx' ),        'desc' => __( 'Chuyển toàn bộ website sang hosting mới mà không cần tải dữ liệu về máy — đóng gói rồi khôi phục, chỉ 2 bước.', 'sitevorx' ),            'active' => ( function_exists( 'sv_s3_is_managed' ) && sv_s3_is_managed() ) || get_option( 'sv_s3_enabled' ) === '1' ],
                        [ 'page' => 'sv-maintenance-check', 'color' => 'purple', 'icon' => 'update',      'title' => __( 'Bảo Trì & Cập Nhật', 'sitevorx' ),        'desc' => __( 'Theo dõi plugin, theme cần update, kiểm tra PHP, SSL và cảnh báo bảo mật.', 'sitevorx' ),                             'active' => false ],
                        [ 'page' => 'sv-support',           'color' => 'cyan',   'icon' => 'sos',         'title' => __( 'Trung Tâm Hỗ Trợ', 'sitevorx' ),          'desc' => __( 'Kênh hỗ trợ kỹ thuật và chăm sóc khách hàng ưu tiên dành riêng cho khách hàng iNET.', 'sitevorx' ),                  'active' => false ],
                        [ 'page' => 'sv-server-info',       'color' => 'blue',   'icon' => 'cloud',       'title' => __( 'Thông Số Server', 'sitevorx' ),             'desc' => __( 'Theo dõi phiên bản PHP, MySQL, WordPress, giới hạn bộ nhớ, extensions và dung lượng Database.', 'sitevorx' ),         'active' => false ],
                    ];
                    foreach ( $cards as $card ) :
                    ?>
                    <a href="?page=<?php echo esc_attr( $card['page'] ); ?>" class="sv-card sv-card-<?php echo esc_attr( $card['color'] ); ?>">
                        <?php if ( $card['active'] ) : ?>
                        <span class="sv-card-status-badge sv-card-status-on"><?php esc_html_e( 'Đã bật', 'sitevorx' ); ?></span>
                        <?php endif; ?>
                        <div class="sv-icon-wrapper"><span class="dashicons dashicons-<?php echo esc_attr( $card['icon'] ); ?>"></span></div>
                        <div class="sv-card-content">
                            <h3><?php echo esc_html( $card['title'] ); ?></h3>
                            <p><?php echo esc_html( $card['desc'] ); ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- ═══ SERVER INFO EXPANDED ═══ -->
                <div class="sv-hosting-header">
                    <h2 class="sv-section-title"><?php esc_html_e( 'Thông Số Hosting Cơ Bản', 'sitevorx' ); ?></h2>
                    <a href="?page=sv-server-info" class="button sv-btn-detail"><span class="dashicons dashicons-external"></span> <?php esc_html_e( 'Xem chi tiết', 'sitevorx' ); ?></a>
                </div>
                <div class="sv-server-grid">
                    <div class="sv-stat-card">
                        <div class="sv-stat-icon sv-icon-blue"><span class="dashicons dashicons-sos"></span></div>
                        <div><span class="sv-stat-label"><?php esc_html_e( 'PHIÊN BẢN PHP', 'sitevorx' ); ?></span><strong class="sv-stat-value"><?php echo esc_html( $php_version ); ?></strong></div>
                    </div>
                    <div class="sv-stat-card">
                        <div class="sv-stat-icon sv-icon-green"><span class="dashicons dashicons-performance"></span></div>
                        <div><span class="sv-stat-label">MEMORY LIMIT</span><strong class="sv-stat-value"><?php echo esc_html( $memory_limit ); ?></strong></div>
                    </div>
                    <div class="sv-stat-card">
                        <div class="sv-stat-icon sv-icon-yellow"><span class="dashicons dashicons-upload"></span></div>
                        <div><span class="sv-stat-label">UPLOAD MAX</span><strong class="sv-stat-value"><?php echo esc_html( $upload_max ); ?></strong></div>
                    </div>
                    <div class="sv-stat-card">
                        <div class="sv-stat-icon sv-icon-purple"><span class="dashicons dashicons-wordpress-alt"></span></div>
                        <div><span class="sv-stat-label">WORDPRESS</span><strong class="sv-stat-value">v<?php echo esc_html( $wp_version ); ?></strong></div>
                    </div>
                    <div class="sv-stat-card">
                        <div class="sv-stat-icon sv-icon-<?php echo $ssl_active ? 'green' : 'red'; ?>"><span class="dashicons dashicons-lock"></span></div>
                        <div><span class="sv-stat-label">SSL / HTTPS</span><strong class="sv-stat-value" style="color:<?php echo $ssl_active ? '#10b981' : '#ef4444'; ?>"><?php echo $ssl_active ? 'Active' : 'Inactive'; ?></strong></div>
                    </div>
                    <div class="sv-stat-card">
                        <div class="sv-stat-icon sv-icon-<?php echo ! $debug_on ? 'green' : 'red'; ?>"><span class="dashicons dashicons-visibility"></span></div>
                        <div><span class="sv-stat-label">WP_DEBUG</span><strong class="sv-stat-value" style="color:<?php echo ! $debug_on ? '#10b981' : '#ef4444'; ?>"><?php echo $debug_on ? 'ON' : 'OFF'; ?></strong></div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php
}
