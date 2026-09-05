<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================================================================
// ANTI-CLONE PROTECTION — Fingerprint & License Revoke
// ==========================================================================

/**
 * Generate a unique server fingerprint based on environment.
 * Changes when site is migrated to a different server.
 */
function sv_generate_hosting_fingerprint() {
    $server_ip = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
    $hostname  = function_exists('gethostname') ? gethostname() : '';
    $db_name   = defined('DB_NAME') ? DB_NAME : '';
    $abspath   = ABSPATH;
    return md5( $server_ip . '|' . $hostname . '|' . $db_name . '|' . $abspath );
}

/**
 * Check if a theme is from MyThemeShop.
 */
function sv_is_mts_theme( $theme = null ) {
    if ( $theme === null ) {
        $theme = wp_get_theme();
    } elseif ( is_string( $theme ) ) {
        $theme = wp_get_theme( $theme );
    }
    if ( ! $theme->exists() ) return false;

    $author     = strtolower( (string) $theme->get('Author') );
    $author_uri = strtolower( (string) $theme->get('AuthorURI') );
    $theme_uri  = strtolower( (string) $theme->get('ThemeURI') );
    $slug       = $theme->get_stylesheet();

    return (
        strpos($author, 'mythemeshop') !== false ||
        strpos($author_uri, 'mythemeshop') !== false ||
        strpos($theme_uri, 'mythemeshop') !== false ||
        0 === strpos($slug, 'mts_') ||
        0 === strpos($slug, 'flavor')
    );
}

/**
 * Get the best available default WordPress theme.
 */
function sv_get_fallback_theme_slug() {
    $defaults = array('twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone', 'twentytwenty', 'twentynineteen');
    foreach ($defaults as $slug) {
        $t = wp_get_theme($slug);
        if ($t->exists()) return $slug;
    }
    // Last resort: first non-MTS theme found
    $all = wp_get_themes();
    foreach ($all as $slug => $t) {
        if ( ! sv_is_mts_theme($slug) ) return $slug;
    }
    return 'twentytwentyfour';
}

/**
 * Force switch away from MTS theme to a default WP theme.
 */
function sv_force_switch_from_mts_theme() {
    if ( sv_is_mts_theme() ) {
        $fallback = sv_get_fallback_theme_slug();
        switch_theme( $fallback );
    }
}

/**
 * Revoke all premium licenses (MTS themes + Rank Math Pro).
 * Called when clone is detected on non-iNET hosting.
 */
function sv_revoke_all_premium_licenses( $reason = '' ) {
    // Force switch away from MTS theme FIRST
    sv_force_switch_from_mts_theme();

    // Revoke MTS
    delete_transient('sv_mts_api_data');
    remove_filter( 'option_mts_connect_data', 'sv_mts_filter_connect_data_option' );
    remove_filter( 'site_option_mts_connect_data', 'sv_mts_filter_connect_data_option' );
    remove_filter( 'pre_site_option_mts_connect_data', 'sv_mts_pre_site_connect_data_option' );
    delete_option('mts_connect_data');
    delete_option('mts_theme_connected');
    delete_site_option('mts_connect_data');
    delete_site_option('mts_theme_connected');
    add_filter( 'option_mts_connect_data', 'sv_mts_filter_connect_data_option' );
    add_filter( 'site_option_mts_connect_data', 'sv_mts_filter_connect_data_option' );
    add_filter( 'pre_site_option_mts_connect_data', 'sv_mts_pre_site_connect_data_option' );

    global $wpdb;
    $like_pattern = $wpdb->esc_like('mts_') . '%' . $wpdb->esc_like('_options');
    $mts_options = $wpdb->get_results($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like_pattern));
    foreach ($mts_options as $opt) {
        $val = get_option($opt->option_name);
        if (is_array($val)) {
            unset($val['mts_connected'], $val['mts_license_key']);
            update_option($opt->option_name, $val);
        }
    }

    // Revoke Rank Math
    delete_option('rank_math_connect_data');
    delete_option('rank_math_pro_license_key');
    delete_option('rank_math_registration');

    // Clear transients
    delete_transient('sv_hosting_check');

    // Reset fingerprint
    delete_option('sv_hosting_fingerprint');
    delete_option('sv_non_inet_strikes');
    delete_option('sv_clone_grace_started');
    delete_option('sv_clone_last_strike_at');
    delete_option('sv_clone_last_warning');
    delete_option('sv_clone_notice_email_sent');

    // Log revoke event
    $log = get_option('sv_clone_revoke_log', array());
    $log[] = array(
        'time'   => current_time('mysql'),
        'reason' => sanitize_text_field($reason),
        'ip'     => isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : 'unknown',
    );
    if (count($log) > 20) $log = array_slice($log, -20);
    update_option('sv_clone_revoke_log', $log);
}

/**
 * Helper: is the given IP a private / reserved / loopback address?
 * We never want to revoke licenses based on an internal IP that can't
 * be reliably matched against the public iNET CIDR list.
 */
function sv_is_private_or_reserved_ip( $ip ) {
    if ( empty( $ip ) ) return true;
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    return false === filter_var( $ip, FILTER_VALIDATE_IP, array( 'flags' => $flags ) );
}

function sv_can_enforce_premium_host_lock() {
    $server_ip = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';

    return ! sv_is_private_or_reserved_ip( $server_ip );
}

function sv_get_premium_host_lock_strike_limit() {
    return max( 2, (int) apply_filters( 'sv_premium_host_lock_strike_limit', 5 ) );
}

function sv_get_premium_host_lock_grace_seconds() {
    return max( HOUR_IN_SECONDS, (int) apply_filters( 'sv_premium_host_lock_grace_seconds', 7 * DAY_IN_SECONDS ) );
}

function sv_get_premium_host_lock_state() {
    $started = (int) get_option( 'sv_clone_grace_started', 0 );

    return array(
        'strikes'      => (int) get_option( 'sv_non_inet_strikes', 0 ),
        'started'      => $started,
        'last_strike'  => (int) get_option( 'sv_clone_last_strike_at', 0 ),
        'grace_until'  => $started ? $started + sv_get_premium_host_lock_grace_seconds() : 0,
        'strike_limit' => sv_get_premium_host_lock_strike_limit(),
    );
}

function sv_reset_premium_host_lock_state() {
    delete_option( 'sv_non_inet_strikes' );
    delete_option( 'sv_clone_grace_started' );
    delete_option( 'sv_clone_last_strike_at' );
    delete_option( 'sv_clone_last_warning' );
    delete_option( 'sv_clone_notice_email_sent' );
}

function sv_premium_host_lock_ready_to_revoke( $state = null ) {
    $state = is_array( $state ) ? $state : sv_get_premium_host_lock_state();

    return ! empty( $state['grace_until'] )
        && (int) $state['strikes'] >= (int) $state['strike_limit']
        && time() >= (int) $state['grace_until'];
}

function sv_record_non_inet_premium_strike( $reason, $force = false ) {
    $now   = time();
    $state = sv_get_premium_host_lock_state();

    if ( ! $force && ! empty( $state['last_strike'] ) && ( $now - (int) $state['last_strike'] ) < 6 * HOUR_IN_SECONDS ) {
        return $state;
    }

    if ( empty( $state['started'] ) ) {
        $state['started'] = $now;
        update_option( 'sv_clone_grace_started', $now );
    }

    $state['strikes']++;
    $state['last_strike'] = $now;
    $state['grace_until'] = (int) $state['started'] + sv_get_premium_host_lock_grace_seconds();

    update_option( 'sv_non_inet_strikes', $state['strikes'] );
    update_option( 'sv_clone_last_strike_at', $now );
    update_option( 'sv_clone_last_warning', array(
        'time'        => current_time( 'mysql' ),
        'reason'      => sanitize_text_field( $reason ),
        'server_ip'   => isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '',
        'strikes'     => $state['strikes'],
        'grace_until' => $state['grace_until'],
    ) );

    if ( '1' !== get_option( 'sv_clone_notice_email_sent', '0' ) ) {
        $admin_email = get_option( 'admin_email' );
        if ( is_email( $admin_email ) ) {
            wp_mail(
                $admin_email,
                sprintf( '[%s] Sitevorx Pro premium verification warning', wp_parse_url( home_url(), PHP_URL_HOST ) ),
                sprintf(
                    "Sitevorx Pro could not verify this site as iNET hosting.\n\nReason: %s\nStrikes: %d/%d\nGrace until: %s\n\nPremium licenses have not been revoked yet. Re-verify from the WordPress admin if this is a legitimate iNET migration.",
                    $reason,
                    $state['strikes'],
                    $state['strike_limit'],
                    wp_date( 'Y-m-d H:i:s', $state['grace_until'] )
                )
            );
        }
        update_option( 'sv_clone_notice_email_sent', '1' );
    }

    return $state;
}

function sv_handle_non_inet_premium_host( $reason, $force_strike = false ) {
    $state = sv_record_non_inet_premium_strike( $reason, $force_strike );

    if ( sv_premium_host_lock_ready_to_revoke( $state ) ) {
        sv_reset_premium_host_lock_state();
        sv_revoke_all_premium_licenses( $reason . ' (grace expired + strike limit reached)' );
        return 'revoked';
    }

    return 'warning';
}

/**
 * Anti-clone check on admin_init.
 * Detects server environment change and revokes licenses if not on iNET.
 *
 * Safety guards (to prevent false-positive mass revocation):
 *   1. When fingerprint changes, FORCE refresh the hosting cache — never
 *      rely on a 12-hour-old cached 'no' verdict.
 *   2. If SERVER_ADDR is private/reserved/empty, skip revocation entirely
 *      (we cannot reliably classify).
 *   3. Require multiple non-iNET verdicts plus a grace period before
 *      revoking; a transient blip should not break a paying customer.
 */
add_action('admin_init', function() {
    if (!is_admin()) return;

    $stored_fp  = get_option('sv_hosting_fingerprint', '');
    $current_fp = sv_generate_hosting_fingerprint();

    if ( !empty($stored_fp) && $stored_fp !== $current_fp ) {
        // Fingerprint changed → server environment differs. Force-refresh
        // the hosting-check cache so we evaluate CURRENT state, not stale.
        delete_transient('sv_hosting_check');

        $server_ip = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';

        // Guard 1: Private/reserved IP → can't classify → refresh FP, don't revoke.
        if ( sv_is_private_or_reserved_ip( $server_ip ) ) {
            update_option('sv_hosting_fingerprint', $current_fp);
            return;
        }

        if ( sv_is_inet_hosting() ) {
            // Still on iNET (legitimate migration between iNET servers)
            update_option('sv_hosting_fingerprint', $current_fp);
            sv_reset_premium_host_lock_state();
        } else {
            update_option('sv_hosting_fingerprint', $current_fp);
            sv_handle_non_inet_premium_host('Clone suspected: fingerprint mismatch + non-iNET hosting', true);
            return;
        }
    }

    // No fingerprint yet + on iNET → set initial fingerprint
    if ( empty($stored_fp) && sv_is_inet_hosting() ) {
        update_option('sv_hosting_fingerprint', $current_fp);
        sv_reset_premium_host_lock_state();
    }
}, 1); // Priority 1 — run before license injection

/**
 * ONGOING ENFORCEMENT: On every admin_init, if NOT on iNET and current
 * theme is MTS → force switch to default. This catches cases where
 * the clone already happened but fingerprint was not yet set.
 */
add_action('admin_init', function() {
    if ( !is_admin() ) return;
    if ( ! sv_can_enforce_premium_host_lock() ) return;
    if ( sv_is_inet_hosting() ) {
        sv_reset_premium_host_lock_state();
        update_option('sv_hosting_fingerprint', sv_generate_hosting_fingerprint());
        return;
    }
    if ( sv_is_mts_theme() ) {
        sv_handle_non_inet_premium_host('MTS theme active on non-iNET hosting', false);
    }
}, 2);

/**
 * Block switching TO an MTS theme on non-iNET hosting.
 * Fires right after switch_theme — if switched to MTS, revert immediately.
 */
add_action('after_switch_theme', function() {
    if ( ! sv_can_enforce_premium_host_lock() ) return;
    if ( sv_is_inet_hosting() ) return;
    if ( sv_is_mts_theme() ) {
        $status = sv_handle_non_inet_premium_host('MTS theme switch attempted on non-iNET hosting', true);
        if ( 'revoked' !== $status ) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p><strong>Sitevorx Pro:</strong> ';
                esc_html_e('Sitevorx Pro chưa xác minh được môi trường iNET. Giao diện MyThemeShop chưa bị vô hiệu hóa; vui lòng bấm Re-verify trong cảnh báo Sitevorx Pro nếu đây là migration hợp lệ.', 'sitevorx');
                echo '</p></div>';
            });
            return;
        }
        $fallback = sv_get_fallback_theme_slug();
        switch_theme( $fallback );
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Sitevorx Pro:</strong> ';
            esc_html_e('Giao diện MyThemeShop chỉ khả dụng trên hệ sinh thái Hosting iNET. Đã chuyển về giao diện mặc định.', 'sitevorx');
            echo '</p></div>';
        });
    }
});

// ==========================================================================
// HEARTBEAT CRON — Periodic hosting validation
// ==========================================================================

add_action('wp', function() {
    if ( !wp_next_scheduled('sv_license_heartbeat') ) {
        wp_schedule_event(time(), 'sv_six_hours', 'sv_license_heartbeat');
    }
});

add_filter('cron_schedules', function($schedules) {
    $schedules['sv_six_hours'] = array(
        'interval' => 6 * HOUR_IN_SECONDS,
        'display'  => __('Every 6 Hours', 'sitevorx'),
    );
    return $schedules;
});

add_action('sv_license_heartbeat', function() {
    // Force fresh hosting check (bypass transient cache)
    delete_transient('sv_hosting_check');

    // Same safety guards as admin_init anti-clone check
    $server_ip = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
    if ( sv_is_private_or_reserved_ip( $server_ip ) ) {
        // Private/reserved IP → skip; can't reliably classify
        return;
    }

    if ( sv_is_inet_hosting() ) {
        // Still on iNET — reset strikes, update fingerprint
        sv_reset_premium_host_lock_state();
        update_option('sv_hosting_fingerprint', sv_generate_hosting_fingerprint());
        return;
    }

    sv_handle_non_inet_premium_host('Heartbeat: hosting no longer iNET', true);
    return;
});

add_action( 'admin_post_sv_reverify_premium_hosting', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'sitevorx' ) );
    }

    check_admin_referer( 'sv_reverify_premium_hosting' );
    delete_transient( 'sv_hosting_check' );
    delete_transient( 'sv_mts_api_data' );

    if ( sv_is_inet_hosting() ) {
        sv_reset_premium_host_lock_state();
        update_option( 'sv_hosting_fingerprint', sv_generate_hosting_fingerprint() );
        sv_mts_ensure_license_available( true );
        $status = 'ok';
    } else {
        sv_handle_non_inet_premium_host( 'Manual re-verify: hosting still not iNET', true );
        $status = 'failed';
    }

    wp_safe_redirect( add_query_arg( 'sv_hosting_reverify', $status, wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=sitevorx' ) ) );
    exit;
} );

add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_GET['sv_hosting_reverify'] ) ) {
        $status = sanitize_key( wp_unslash( $_GET['sv_hosting_reverify'] ) );
        if ( 'ok' === $status ) {
            echo '<div class="notice notice-success is-dismissible sv-notice"><p><strong>Sitevorx Pro:</strong> ' . esc_html__( 'Đã xác minh lại môi trường iNET và giữ nguyên Premium license.', 'sitevorx' ) . '</p></div>';
        } elseif ( 'failed' === $status ) {
            echo '<div class="notice notice-warning is-dismissible sv-notice"><p><strong>Sitevorx Pro:</strong> ' . esc_html__( 'Chưa xác minh được môi trường iNET. Premium license vẫn đang trong thời gian grace, chưa bị revoke.', 'sitevorx' ) . '</p></div>';
        }
    }

    $state = sv_get_premium_host_lock_state();
    if ( empty( $state['strikes'] ) || sv_is_inet_hosting() ) {
        return;
    }

    $reverify_url = wp_nonce_url( admin_url( 'admin-post.php?action=sv_reverify_premium_hosting' ), 'sv_reverify_premium_hosting' );
    $days_left    = max( 0, (int) ceil( ( (int) $state['grace_until'] - time() ) / DAY_IN_SECONDS ) );

    echo '<div class="notice notice-warning"><p><strong>Sitevorx Pro:</strong> ';
    echo esc_html( sprintf( __( 'Không xác minh được iNET hosting (%1$d/%2$d strikes). Premium license chưa bị revoke; còn khoảng %3$d ngày grace để re-verify.', 'sitevorx' ), (int) $state['strikes'], (int) $state['strike_limit'], $days_left ) );
    echo ' <a class="button button-small" href="' . esc_url( $reverify_url ) . '">' . esc_html__( 'Re-verify now', 'sitevorx' ) . '</a>';
    echo '</p></div>';
} );

// ==========================================================================
// AUTO-INJECT MTS LICENSE (only on iNET hosting)
//
// Design: The inject runs on admin_init priority 3 (before default 10 where
// MTS themes typically check connection state). It fetches the key from the
// iNET API once and caches it for 12 hours. Crucially, on every subsequent
// page load it RE-VERIFIES that the `mts_connect_data` option actually
// contains valid data — if MTS cleared/overwrote it (e.g. after a theme
// update), we re-inject from cache without hitting the API again.
//
// Additionally, filters on both option and site-option reads guarantee that
// MyThemeShop Connect sees connected state before it initializes on
// plugins_loaded.
// ==========================================================================

function sv_mts_normalize_connect_data( $connect_data ) {
    if ( ! is_array( $connect_data ) || empty( $connect_data['api_key'] ) ) {
        return array();
    }

    return sv_premium_build_connect_data( $connect_data['api_key'], $connect_data );
}

function sv_mts_connect_data_is_complete( $connect_data ) {
    return is_array( $connect_data )
        && ! empty( $connect_data['connected'] )
        && ! empty( $connect_data['api_key'] )
        && ! empty( $connect_data['username'] )
        && ! empty( $connect_data['email'] );
}

function sv_mts_connect_data_needs_update( $current, $expected ) {
    if ( ! sv_mts_connect_data_is_complete( $current ) ) {
        return true;
    }

    foreach ( array( 'api_key', 'username', 'email' ) as $key ) {
        if ( (string) $current[ $key ] !== (string) $expected[ $key ] ) {
            return true;
        }
    }

    return false;
}

function sv_mts_connect_data_uses_fallback_identity( $connect_data ) {
    if ( ! is_array( $connect_data ) ) {
        return true;
    }

    $username = isset( $connect_data['username'] ) ? strtolower( trim( (string) $connect_data['username'] ) ) : '';
    $email    = isset( $connect_data['email'] ) ? strtolower( trim( (string) $connect_data['email'] ) ) : '';

    // Legacy placeholder values that predate the correct iNET bulk account mapping.
    // If we see any of these, force a refresh to obtain the real identity from the API.
    $legacy_placeholders = array( 'inet-premium', 'inet premium' );

    return '' === $username
        || in_array( $username, $legacy_placeholders, true )
        || '' === $email;
}

/**
 * Helper: write MTS connect data to all relevant options.
 */
function sv_mts_inject_license_to_options( $connect_data ) {
    $connect_data = sv_mts_normalize_connect_data( $connect_data );
    if ( ! sv_mts_connect_data_is_complete( $connect_data ) ) {
        return;
    }

    // MyThemeShop Connect 3.x reads get_site_option(), not only get_option().
    remove_filter( 'option_mts_connect_data', 'sv_mts_filter_connect_data_option' );
    remove_filter( 'site_option_mts_connect_data', 'sv_mts_filter_connect_data_option' );
    remove_filter( 'pre_site_option_mts_connect_data', 'sv_mts_pre_site_connect_data_option' );
    update_site_option( 'mts_connect_data', $connect_data );
    update_site_option( 'mts_theme_connected', 1 );
    update_option( 'mts_connect_data', $connect_data );
    update_option( 'mts_theme_connected', 1 );
    add_filter( 'option_mts_connect_data', 'sv_mts_filter_connect_data_option' );
    add_filter( 'site_option_mts_connect_data', 'sv_mts_filter_connect_data_option' );
    add_filter( 'pre_site_option_mts_connect_data', 'sv_mts_pre_site_connect_data_option' );

    global $wpdb;
    $like_pattern = $wpdb->esc_like( 'mts_' ) . '%' . $wpdb->esc_like( '_options' );
    $mts_options  = $wpdb->get_results( $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like_pattern
    ) );
    foreach ( $mts_options as $opt ) {
        $val = get_option( $opt->option_name );
        if ( is_array( $val ) ) {
            $val['mts_connected']   = true;
            $val['mts_license_key'] = $connect_data['api_key'];
            update_option( $opt->option_name, $val );
        }
    }
}

function sv_mts_get_raw_local_option_value( $option_name ) {
    global $wpdb;

    $raw = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option_name
        )
    );

    if ( null === $raw ) {
        return null;
    }

    return maybe_unserialize( $raw );
}

function sv_mts_get_raw_site_option_value( $option_name ) {
    if ( ! is_multisite() ) {
        return sv_mts_get_raw_local_option_value( $option_name );
    }

    global $wpdb;

    $network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;
    $raw        = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1",
            $network_id,
            $option_name
        )
    );

    if ( null === $raw ) {
        return null;
    }

    return maybe_unserialize( $raw );
}

function sv_mts_get_raw_connect_data_option() {
    $site_value = sv_mts_get_raw_site_option_value( 'mts_connect_data' );
    if ( sv_mts_connect_data_is_complete( $site_value ) || ( is_array( $site_value ) && ! empty( $site_value['api_key'] ) ) ) {
        return $site_value;
    }

    if ( is_multisite() ) {
        $local_value = sv_mts_get_raw_local_option_value( 'mts_connect_data' );
        if ( sv_mts_connect_data_is_complete( $local_value ) || ( is_array( $local_value ) && ! empty( $local_value['api_key'] ) ) ) {
            return $local_value;
        }
    }

    return $site_value;
}

function sv_mts_get_best_connect_data_for_handoff() {
    $cached = get_transient( 'sv_mts_api_data' );
    if ( is_array( $cached ) && ! empty( $cached['api_key'] ) ) {
        return sv_mts_normalize_connect_data( $cached );
    }

    $raw = sv_mts_get_raw_connect_data_option();
    if ( is_array( $raw ) && ! empty( $raw['api_key'] ) ) {
        return sv_mts_normalize_connect_data( $raw );
    }

    $filtered = get_site_option( 'mts_connect_data' );
    if ( is_array( $filtered ) && ! empty( $filtered['api_key'] ) ) {
        return sv_mts_normalize_connect_data( $filtered );
    }

    return array();
}

function sv_mts_preserve_license_on_deactivation() {
    $connect_data = sv_mts_get_best_connect_data_for_handoff();
    if ( is_array( $connect_data ) && ! empty( $connect_data['api_key'] ) ) {
        sv_mts_inject_license_to_options( $connect_data );
    }

    wp_clear_scheduled_hook( 'sv_license_heartbeat' );
}

register_deactivation_hook( defined( 'SV_PLUGIN_FILE' ) ? SV_PLUGIN_FILE : __FILE__, 'sv_mts_preserve_license_on_deactivation' );

function sv_mts_fetch_license_from_api() {
    $api_url  = sv_get_premium_api_url( 'get-mts-key.php', 'mts_license', 'inet_premium_' );
    $response = wp_remote_get( $api_url, array(
        'timeout'    => 10,
        'user-agent' => 'iNET-Toolkit-Secure-Agent-V1',
    ) );

    if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['status'] ) && 'success' === $body['status'] && ! empty( $body['api_key'] ) ) {
            return sv_premium_build_connect_data( $body['api_key'], $body );
        }
    }

    return array();
}

function sv_mts_should_force_license_refresh() {
    return is_admin()
        && isset( $_GET['page'] )
        && in_array( sanitize_key( wp_unslash( $_GET['page'] ) ), array( 'mts-connect', 'sv-premium' ), true );
}

function sv_mts_ensure_license_available( $force_refresh = false ) {
    if ( ! is_admin() ) return array();
    if ( ! sv_is_inet_hosting() ) return array();

    $cached  = get_transient( 'sv_mts_api_data' );
    $current = sv_mts_get_raw_connect_data_option();

    if ( ! $force_refresh && sv_mts_should_force_license_refresh() ) {
        $identity_source = is_array( $cached ) ? $cached : $current;
        if ( sv_mts_connect_data_uses_fallback_identity( $identity_source ) ) {
            $force_refresh = true;
        }
    }

    if ( ! $force_refresh && is_array( $current ) && ! empty( $current['api_key'] ) && ( false === $cached || 'error' === $cached ) ) {
        $normalized = sv_mts_normalize_connect_data( $current );
        if ( sv_mts_connect_data_is_complete( $normalized ) ) {
            set_transient( 'sv_mts_api_data', $normalized, 12 * HOUR_IN_SECONDS );
            if ( sv_mts_connect_data_needs_update( $current, $normalized ) ) {
                sv_mts_inject_license_to_options( $normalized );
            }
            return $normalized;
        }
    }

    if ( ! $force_refresh && 'error' === $cached && ! sv_mts_should_force_license_refresh() ) {
        return array();
    }

    if ( false === $cached || 'error' === $cached || $force_refresh ) {
        $fetched = sv_mts_fetch_license_from_api();
        if ( sv_mts_connect_data_is_complete( $fetched ) ) {
            set_transient( 'sv_mts_api_data', $fetched, 12 * HOUR_IN_SECONDS );
            sv_mts_inject_license_to_options( $fetched );
            return $fetched;
        }

        set_transient( 'sv_mts_api_data', 'error', 15 * MINUTE_IN_SECONDS );
        return array();
    }

    if ( is_array( $cached ) && ! empty( $cached['api_key'] ) ) {
        $cached = sv_mts_normalize_connect_data( $cached );

        if ( sv_mts_connect_data_needs_update( $current, $cached ) ) {
            sv_mts_inject_license_to_options( $cached );
        }

        return $cached;
    }

    return array();
}

/**
 * One-time migration: sites that cached data under the old 'inet-premium'
 * placeholder username need to flush and re-inject with the correct identity
 * so that MTS server-side re-verification matches post-deactivation.
 */
add_action( 'plugins_loaded', function() {
    if ( '1' === get_option( 'sv_mts_identity_migrated_v2', '0' ) ) {
        return;
    }

    $cached = get_transient( 'sv_mts_api_data' );
    $current = sv_mts_get_raw_connect_data_option();

    $needs_flush = false;
    foreach ( array( $cached, $current ) as $data ) {
        if ( is_array( $data ) && ! empty( $data['username'] )
             && in_array( strtolower( trim( $data['username'] ) ), array( 'inet-premium', 'inet premium' ), true ) ) {
            $needs_flush = true;
            break;
        }
    }

    if ( $needs_flush ) {
        delete_transient( 'sv_mts_api_data' );
    }

    update_option( 'sv_mts_identity_migrated_v2', '1' );
}, 0 );

add_action( 'plugins_loaded', function() {
    sv_mts_ensure_license_available();
}, 1 ); // Before MyThemeShop Connect initializes its Core object at priority 10.

add_action( 'admin_init', function() {
    sv_mts_ensure_license_available();
}, 3 ); // Priority 3 - after anti-clone (1) and force-switch (2), before MTS's own checks

/**
 * Filter: guarantee MTS option reads return connected data on iNET hosting.
 */
function sv_mts_filter_connect_data_option( $value ) {
    if ( ! sv_is_inet_hosting() ) return $value;
    if ( ! empty( $value ) && is_array( $value ) && ! empty( $value['connected'] ) && ! empty( $value['api_key'] ) ) return sv_mts_normalize_connect_data( $value );

    $cached = get_transient( 'sv_mts_api_data' );
    if ( is_array( $cached ) && ! empty( $cached['api_key'] ) ) {
        return sv_mts_normalize_connect_data( $cached );
    }

    return $value;
}

add_filter( 'option_mts_connect_data', 'sv_mts_filter_connect_data_option' );
add_filter( 'site_option_mts_connect_data', 'sv_mts_filter_connect_data_option' );
function sv_mts_pre_site_connect_data_option( $pre ) {
    if ( ! sv_is_inet_hosting() ) return $pre;

    $cached = get_transient( 'sv_mts_api_data' );
    if ( is_array( $cached ) && ! empty( $cached['api_key'] ) ) {
        return sv_mts_normalize_connect_data( $cached );
    }

    return $pre;
}
add_filter( 'pre_site_option_mts_connect_data', 'sv_mts_pre_site_connect_data_option' );

// ==========================================================================
// CLEANUP: Remove MTS junk menus and connect popups
// ==========================================================================
add_action('admin_menu', function() { remove_menu_page('mts-connect'); }, 999);

/**
 * MTS Connect plugin hooks a redirect from WP core admin pages (plugins.php,
 * themes.php, etc.) to its own admin.php?page=mts-connect page. Block that
 * redirect so users can access the native WP Plugins screen normally.
 */
add_filter( 'wp_redirect', function( $location ) {
    if ( ! is_admin() || empty( $location ) ) {
        return $location;
    }

    // Only block redirects TARGETED at MTS connect pages
    if ( false === strpos( $location, 'mts-connect' )
         && false === strpos( $location, 'mts_connect' )
         && false === strpos( $location, 'page=mts-' )
         && false === strpos( $location, 'page=mts_' ) ) {
        return $location;
    }

    // Allow redirect only if user is CURRENTLY on a mts-* page (legitimate MTS flow).
    // Block if coming FROM a WP core admin page (the offending behaviour).
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ( preg_match( '~/wp-admin/(plugins|themes|update-core|update|index|edit|upload|users|tools|options|profile|admin)\.php~', $uri ) ) {
        return false; // Cancel redirect → user stays on plugins.php etc.
    }

    return $location;
}, 1 );
add_action('admin_head', function() {
    echo '<style>
        #mts-connect-notice,
        .mts-activation-notice,
        .mts-developer-notice,
        div[id^="mts-connect"],
        div[class*="mts-connect"],
        .mts-welcome-panel,
        .not-connected-tab,
        .mts-update-nag,
        .theme-update-message,
        .notice[data-slug="mts-developer-connect"],
        .notice a[href*="mts-connect"],
        .notice a[href*="mythemeshop.com/connect"]
        { display: none !important; }
    </style>';
});

// Suppress MTS connect notice by text content (JS fallback — safer than
// output buffering which can conflict with other plugins).
add_action('admin_head', function() {
    echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".notice,.notice-error,.notice-warning").forEach(function(el){if(el.textContent.indexOf("connect with your MyThemeShop account")!==-1||el.textContent.indexOf("mts-connect")!==-1){el.style.display="none";}});});</script>';
});

// Block MTS theme update checks — suppress update popup
add_filter('pre_set_site_transient_update_themes', function($transient) {
    if ( !is_object($transient) || empty($transient->response) ) return $transient;

    foreach ($transient->response as $slug => $data) {
        $theme = wp_get_theme($slug);
        if ( !$theme->exists() ) continue;

        $author = strtolower( (string) $theme->get('Author') );
        $author_uri = strtolower( (string) $theme->get('AuthorURI') );
        $theme_uri = strtolower( (string) $theme->get('ThemeURI') );

        if (
            strpos($author, 'mythemeshop') !== false ||
            strpos($author_uri, 'mythemeshop') !== false ||
            strpos($theme_uri, 'mythemeshop') !== false ||
            0 === strpos($slug, 'mts_') ||
            0 === strpos($slug, 'flavor')
        ) {
            unset($transient->response[$slug]);
        }
    }

    return $transient;
});

// Block Rank Math disconnected notice
add_action('admin_head', function() {
    echo '<style>
        .notice-rank-math-not-connected,
        .rank-math-account-details,
        .rank-math-box.rank-math-connected-box,
        .rank-math-tooltip-account
        { display: none !important; }
    </style>';
});
