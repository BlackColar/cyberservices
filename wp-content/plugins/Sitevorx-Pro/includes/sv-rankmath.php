<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================================================================
// AUTO-INSTALL & ACTIVATE RANK MATH PRO
//
// Flow (single admin-init request, idempotent — safe to re-run):
//   1. Install + activate Rank Math FREE from WordPress.org
//   2. Download + install + activate Rank Math PRO from iNET premium endpoint
//   3. Pull license from iNET get-rm-key.php and persist via the canonical
//      Rank_Math\Admin\Admin_Helper setter (when available) so the Pro
//      plugin's `is_site_connected()` check returns true.
//
// Design notes:
//   - We request a modest `set_time_limit` increase when the host permits it,
//     but keep the flow retryable because shared hosts may still enforce a
//     hard 30s cap.
//   - The license-only sub-step is also exposed as `sv_activate_rm_license`
//     so the user can retry the connection without re-downloading the
//     plugin if the first attempt got cut short.
//   - Every failure is captured AND saved to a transient
//     `sv_rm_last_debug` so the Premium tab can show the raw HTTP code +
//     response body excerpt for support diagnostics.
// ==========================================================================

function sv_rm_add_error( &$errors, $message, $detail = '' ) {
    $message = (string) $message;
    $detail  = (string) $detail;

    $errors[] = $detail ? $message . ' ' . $detail : $message;
}

function sv_rm_activate_plugin( $plugin_file, &$errors, $label ) {
    if ( is_plugin_active( $plugin_file ) ) {
        return true;
    }

    if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
        sv_rm_add_error( $errors, $label . ' is not installed.' );
        return false;
    }

    $activation = activate_plugin( $plugin_file );
    if ( is_wp_error( $activation ) ) {
        sv_rm_add_error( $errors, 'Failed to activate ' . $label . ':', $activation->get_error_message() );
        return false;
    }

    wp_clean_plugins_cache( true );

    if ( ! is_plugin_active( $plugin_file ) ) {
        sv_rm_add_error( $errors, $label . ' was installed but is not active.' );
        return false;
    }

    return true;
}

/**
 * Persist Rank Math license registration data through the canonical setter
 * when Rank Math is loaded, falling back to direct option writes otherwise.
 *
 * The canonical Admin_Helper write triggers Rank Math's internal cache
 * invalidation hooks; bypassing it (as a previous refactor did) leaves the
 * Pro plugin showing "Connect now" even though the option is on disk.
 *
 * @param array  $registration Registration payload to store.
 * @param string $debug_step   Free-form label saved into the debug transient.
 */
function sv_rm_persist_registration( array $registration, $debug_step = '' ) {
    // Wipe any stale legacy values first so old half-written state cannot
    // shadow the new payload.
    delete_option( 'rank_math_connect_data' );
    delete_option( 'rank_math_registration' );

    // Rank Math's storage contract for `rank_math_connect_data` has shifted
    // over time:
    //
    //   Era A (very old)  – plain array via update_option().
    //   Era B (mid-era)   – Admin_Helper::get_registration_data($value) acted
    //                       as a getter/setter when given an argument.
    //   Era C (current)   – Admin_Helper::update_registration_data($value)
    //                       is the only canonical setter, and the value is
    //                       encrypted before being written. Writing plain
    //                       data triggers the "Unable to validate Rank Math
    //                       SEO registration data" / "Unable to Encrypt"
    //                       admin notice we have been chasing.
    //
    // We try the newest canonical setter first, then fall back gracefully.
    $written_via = 'direct';
    $helper      = '\\RankMath\\Admin\\Admin_Helper';

    if ( class_exists( $helper ) && method_exists( $helper, 'update_registration_data' ) ) {
        $helper::update_registration_data( $registration );
        $written_via = 'admin_helper.update_registration_data';
    } elseif ( class_exists( $helper ) && method_exists( $helper, 'get_registration_data' ) ) {
        // Era B fallback — pass the value to the legacy dual-purpose method.
        // We do NOT also call update_option() afterwards because, in Era C,
        // the helper writes an encrypted blob; clobbering it with a plain
        // array would re-introduce the "Unable to Encrypt" error.
        $helper::get_registration_data( $registration );
        $written_via = 'admin_helper.get_registration_data_legacy';
    } else {
        // Pre-Rank-Math (or plugin not loaded) — direct option write.
        update_option( 'rank_math_connect_data', $registration );
    }

    update_option( 'rank_math_pro_license_key', $registration['api_key'] );
    update_option( 'rankmath_api_key_failed', 0 );

    $active_modules = get_option( 'rank_math_modules', array() );
    $active_modules = is_array( $active_modules ) ? $active_modules : array();
    $pro_modules    = array( 'seo-analysis', 'sitemap', 'rich-snippet', 'woocommerce', 'link-counter', 'local-seo' );
    update_option(
        'rank_math_modules',
        array_values( array_unique( array_merge( $active_modules, $pro_modules ) ) )
    );

    // Verify the write actually stuck. If Rank Math encrypted the payload,
    // reading the option back yields a non-array (string) value — that's
    // the success signature for Era C. An array means we hit the fallback
    // path; a missing value means the helper rejected the write.
    $stored      = get_option( 'rank_math_connect_data', null );
    $stored_kind = is_string( $stored ) ? 'encrypted_string' : ( is_array( $stored ) ? 'plain_array' : gettype( $stored ) );

    set_transient(
        'sv_rm_last_debug',
        array(
            'step'        => $debug_step,
            'time'        => current_time( 'mysql' ),
            'written_via' => $written_via,
            'stored_kind' => $stored_kind,
            'plan'        => $registration['plan'] ?? '',
            'username'    => $registration['username'] ?? '',
        ),
        DAY_IN_SECONDS
    );
}

/**
 * Call the iNET license endpoint and persist whatever it returns.
 *
 * @param array $errors  Collected by reference.
 * @return bool          True on success.
 */
function sv_rm_fetch_and_save_license( &$errors ) {
    $api_url  = sv_get_premium_api_url( 'get-rm-key.php', 'rankmath_license', 'inet_premium_rm_', array( 'site' => get_site_url() ) );

    $response = wp_remote_get(
        $api_url,
        array(
            'timeout'    => 30, // was 15 — bumped because slow shared hosting hit edge cases
            'user-agent' => 'iNET-Toolkit-Secure-Agent-V1',
        )
    );

    if ( is_wp_error( $response ) ) {
        set_transient( 'sv_rm_last_debug', array(
            'step'  => 'api_wp_error',
            'time'  => current_time( 'mysql' ),
            'error' => $response->get_error_message(),
        ), DAY_IN_SECONDS );
        sv_rm_add_error( $errors, 'Could not connect to the iNET Rank Math API:', $response->get_error_message() );
        return false;
    }

    $http_code    = (int) wp_remote_retrieve_response_code( $response );
    $raw_body     = (string) wp_remote_retrieve_body( $response );
    $body         = json_decode( $raw_body, true );
    $body_excerpt = mb_substr( wp_strip_all_tags( $raw_body ), 0, 240 );

    if ( 200 !== $http_code || empty( $body['status'] ) || 'success' !== $body['status'] || empty( $body['connect_data'] ) || ! is_array( $body['connect_data'] ) ) {
        set_transient( 'sv_rm_last_debug', array(
            'step'      => 'api_invalid_response',
            'time'      => current_time( 'mysql' ),
            'http_code' => $http_code,
            'body'      => $body_excerpt,
        ), DAY_IN_SECONDS );
        sv_rm_add_error(
            $errors,
            sprintf( 'The iNET Rank Math API returned an invalid response (HTTP %d).', $http_code ),
            '' !== $body_excerpt ? 'Body: ' . $body_excerpt : ''
        );
        return false;
    }

    $data         = $body['connect_data'];
    $registration = array(
        'connected' => true,
        'api_key'   => isset( $data['api_key'] ) ? sanitize_text_field( $data['api_key'] ) : '',
        'plan'      => isset( $data['plan'] ) ? sanitize_text_field( $data['plan'] ) : '',
        'username'  => isset( $data['username'] ) ? sanitize_text_field( $data['username'] ) : '',
        'email'     => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '',
    );

    if ( empty( $registration['api_key'] ) ) {
        set_transient( 'sv_rm_last_debug', array(
            'step' => 'api_missing_api_key',
            'time' => current_time( 'mysql' ),
            'body' => $body_excerpt,
        ), DAY_IN_SECONDS );
        sv_rm_add_error( $errors, 'The iNET Rank Math API did not return a valid license key.' );
        return false;
    }

    sv_rm_persist_registration( $registration, 'fetched_from_api' );
    return true;
}

add_action('admin_init', function() {
    if ( ! is_admin() || ! current_user_can( 'install_plugins' ) ) return;

    $action = '';
    if ( isset( $_GET['sv_install_rm'] ) )           $action = 'install';
    elseif ( isset( $_GET['sv_activate_rm_license'] ) ) $action = 'license_only';
    if ( '' === $action ) return;

    if ( ! isset( $_GET['_wpnonce'] ) ) return;
    $nonce_action = 'install' === $action ? 'sv_install_rm_nonce' : 'sv_activate_rm_license_nonce';
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $nonce_action ) ) return;

    if ( ! sv_is_inet_hosting() ) {
        wp_safe_redirect( add_query_arg( 'rm_error', rawurlencode( __( 'This feature is only available in the iNET ecosystem.', 'sitevorx' ) ), admin_url( 'admin.php?page=sv-premium&tab=rankmath' ) ) );
        exit;
    }

    $warnings = array();
    $time_limit = max( 30, (int) apply_filters( 'sv_rankmath_install_time_limit', 120 ) );
    if ( function_exists( 'set_time_limit' ) ) {
        // set_time_limit() emits a warning when PHP is in safe_mode or under
        // disable_functions; we catch that via the return value, not @ suppression.
        $time_limit_ok = set_time_limit( $time_limit );
        if ( ! $time_limit_ok ) {
            $warnings[] = __( 'PHP did not allow Sitevorx Pro to extend max_execution_time. If installation stops midway, retry the license-only activation button.', 'sitevorx' );
        }
    } else {
        $warnings[] = __( 'set_time_limit() is disabled on this hosting. If installation stops midway, retry the license-only activation button.', 'sitevorx' );
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    if ( ! class_exists( 'SV_Quiet_Skin' ) ) {
        class SV_Quiet_Skin extends WP_Upgrader_Skin {
            public function feedback( $string, ...$args ) {}
            public function header() {}
            public function footer() {}
        }
    }

    $redirect_url = admin_url( 'admin.php?page=sv-premium&tab=rankmath' );
    $errors       = array();

    if ( 'license_only' === $action ) {
        // The full install flow is not needed — just (re-)pull the license
        // and persist it. Useful when the previous install request was
        // killed by PHP timeout before the license step completed.
        $rm_free_slug = 'seo-by-rank-math/rank-math.php';
        $rm_pro_slug  = 'seo-by-rank-math-pro/rank-math-pro.php';
        if ( ! is_plugin_active( $rm_free_slug ) || ! is_plugin_active( $rm_pro_slug ) ) {
            sv_rm_add_error( $errors, 'Rank Math Free and Pro must both be installed and active before retrying license activation.' );
        } else {
            sv_rm_fetch_and_save_license( $errors );
        }
    } else {
        // Full install + activate + license flow.
        $rm_free_slug = 'seo-by-rank-math/rank-math.php';
        if ( ! file_exists( WP_PLUGIN_DIR . '/' . $rm_free_slug ) ) {
            $api = plugins_api( 'plugin_information', array( 'slug' => 'seo-by-rank-math', 'fields' => array( 'sections' => false ) ) );
            if ( is_wp_error( $api ) ) {
                sv_rm_add_error( $errors, 'Could not fetch Rank Math Free package:', $api->get_error_message() );
            } elseif ( empty( $api->download_link ) ) {
                sv_rm_add_error( $errors, 'Could not find the Rank Math Free download link.' );
            } else {
                $upgrader = new Plugin_Upgrader( new SV_Quiet_Skin() );
                $result   = $upgrader->install( $api->download_link );
                if ( is_wp_error( $result ) ) {
                    sv_rm_add_error( $errors, 'Failed to install Rank Math Free:', $result->get_error_message() );
                } elseif ( false === $result ) {
                    sv_rm_add_error( $errors, 'WordPress could not install Rank Math Free.' );
                }
            }
        }

        wp_clean_plugins_cache( true );
        if ( empty( $errors ) ) {
            sv_rm_activate_plugin( $rm_free_slug, $errors, 'Rank Math Free' );
        }

        $rm_pro_slug = 'seo-by-rank-math-pro/rank-math-pro.php';
        if ( empty( $errors ) && ! file_exists( WP_PLUGIN_DIR . '/' . $rm_pro_slug ) ) {
            $pro_zip_url = 'https://theme.trungtq.io.vn/seo-by-rank-math-pro.zip';
            $inject_auth = function( $args, $url ) {
                if ( strpos( $url, '.zip' ) !== false ) {
                    $args['user-agent'] = 'iNET-Premium-Downloader-V1';
                }
                return $args;
            };

            add_filter( 'http_request_args', $inject_auth, 10, 2 );
            $tmp_file = download_url( $pro_zip_url, 120 ); // was 60 — Rank Math Pro zip is heavier than Free
            remove_filter( 'http_request_args', $inject_auth, 10 );

            if ( is_wp_error( $tmp_file ) ) {
                sv_rm_add_error( $errors, 'Could not download Rank Math Pro ZIP from iNET:', $tmp_file->get_error_message() );
            } else {
                $upgrader = new Plugin_Upgrader( new SV_Quiet_Skin() );
                $result   = $upgrader->install( $tmp_file );
                if ( is_string( $tmp_file ) && file_exists( $tmp_file ) ) {
                    wp_delete_file( $tmp_file );
                }

                if ( is_wp_error( $result ) ) {
                    sv_rm_add_error( $errors, 'Failed to install Rank Math Pro:', $result->get_error_message() );
                } elseif ( false === $result ) {
                    sv_rm_add_error( $errors, 'WordPress could not install Rank Math Pro.' );
                }
            }
        }

        wp_clean_plugins_cache( true );
        if ( empty( $errors ) ) {
            sv_rm_activate_plugin( $rm_pro_slug, $errors, 'Rank Math Pro' );
        }

        if ( empty( $errors ) ) {
            sv_rm_fetch_and_save_license( $errors );
        }
    }

    if ( ! empty( $errors ) ) {
        $redirect_url = add_query_arg( 'rm_error', rawurlencode( implode( ' | ', $errors ) ), $redirect_url );
    } else {
        $redirect_url = add_query_arg( 'rm_installed', 'success', $redirect_url );
        if ( ! empty( $warnings ) ) {
            $redirect_url = add_query_arg( 'rm_warning', rawurlencode( implode( ' | ', $warnings ) ), $redirect_url );
        }
    }

    wp_safe_redirect( $redirect_url );
    exit;
});
