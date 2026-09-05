<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================================================================
// SECURITY CENTER MODULE — Runtime Hooks + Score Calculator + Action Log
// ==========================================================================

/**
 * Format the current time in the site's local timezone, with the timezone
 * label appended so the operator can immediately see whether WordPress is
 * using the right zone.
 *
 * When the site has no timezone_string set AND gmt_offset is 0, WordPress
 * falls back to UTC — that's the situation that produced "04:50:21" emails
 * when the actual Vietnamese time was 11:50. We add a Asia/Ho_Chi_Minh
 * fallback in that case (sensible default for a Vietnamese plugin), and
 * tag the rendered time with "(UTC)" or "(Asia/Ho_Chi_Minh - default)" so
 * the issue is visible.
 */
function sv_format_local_time( $format = 'd/m/Y H:i:s' ) {
    $tz_string  = (string) get_option( 'timezone_string' );
    $gmt_offset = (float) get_option( 'gmt_offset' );

    if ( '' === $tz_string && 0.0 === $gmt_offset ) {
        try {
            $tz = new DateTimeZone( 'Asia/Ho_Chi_Minh' );
            return wp_date( $format, null, $tz ) . ' ' . __( '(Asia/Ho_Chi_Minh — múi giờ mặc định, hãy chỉnh WordPress → Cài đặt → Tổng quan)', 'sitevorx' );
        } catch ( Exception $e ) {
            // Fall through to default rendering below.
        }
    }

    $tz       = wp_timezone();
    $tz_label = $tz_string ?: ( $gmt_offset >= 0 ? 'UTC+' . $gmt_offset : 'UTC' . $gmt_offset );
    return wp_date( $format, null, $tz ) . ' (' . $tz_label . ')';
}

/**
 * Security Headers — apply safe headers on frontend only.
 */
add_action( 'send_headers', 'sv_apply_security_headers', 20 );
function sv_apply_security_headers() {
    if ( is_admin() ) return;
    if ( defined( 'DOING_AJAX' ) || defined( 'DOING_CRON' ) || defined( 'XMLRPC_REQUEST' ) || defined( 'REST_REQUEST' ) ) return;
    if ( headers_sent() ) return;

    if ( get_option( 'sv_sec_headers_enabled' ) === '1' ) {
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
    }

    // HSTS � only on HTTPS, otherwise it would lock users out for max-age.
    if ( get_option( 'sv_sec_headers_hsts' ) === '1' && sv_is_effectively_ssl() ) {
        $max = max( 60, (int) get_option( 'sv_sec_headers_hsts_max', 15768000 ) );
        $sub = get_option( 'sv_sec_headers_hsts_sub' ) === '1' ? '; includeSubDomains' : '';
        header( 'Strict-Transport-Security: max-age=' . $max . $sub );
    }
}

// AJAX: chmod critical files to a safer mode. Called by Kiểm Tra tab.
// Whitelisted to wp-config.php (mode 0640) and .htaccess (mode 0644) only.
add_action( 'wp_ajax_sv_fix_perms', 'sv_ajax_fix_perms' );
function sv_ajax_fix_perms() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );
    check_ajax_referer( 'sv_security_nonce', 'nonce' );

    $target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
    $mode_in = isset( $_POST['mode'] ) ? preg_replace( '/[^0-7]/', '', wp_unslash( $_POST['mode'] ) ) : '';

    $allowed = array(
        'wp-config.php' => array( 'path' => ABSPATH . 'wp-config.php', 'mode' => 0640 ),
        '.htaccess'     => array( 'path' => ABSPATH . '.htaccess',     'mode' => 0644 ),
    );
    if ( ! isset( $allowed[ $target ] ) ) {
        wp_send_json_error( __( 'File không nằm trong danh sách cho phép.', 'sitevorx' ) );
    }
    $path = $allowed[ $target ]['path'];
    $mode = $allowed[ $target ]['mode'];
    if ( $mode_in && '0' === substr( $mode_in, 0, 1 ) ) {
        $req = octdec( $mode_in );
        if ( $req === $mode ) $mode = $req; // accept exact match
    }
    if ( ! file_exists( $path ) ) {
        wp_send_json_error( sprintf( __( 'File %s không tồn tại.', 'sitevorx' ), $target ) );
    }
    $before = fileperms( $path ) & 0777;
    // @chmod returns true even on some failures; verify by re-reading.
    $ok = @chmod( $path, $mode );
    clearstatcache( true, $path );
    $after = fileperms( $path ) & 0777;
    if ( ! $ok || $after !== $mode ) {
        wp_send_json_error( sprintf(
            __( 'PHP không có quyền đổi chmod (%1$s → %2$s). Hosting có thể đang chạy PHP-FPM với user khác file owner. Hãy SSH/FTP đổi tay: chmod %3$s %4$s', 'sitevorx' ),
            sprintf( '%04o', $before ), sprintf( '%04o', $mode ),
            sprintf( '%04o', $mode ), $target
        ) );
    }
    sv_sec_log( 'fix_perms', sprintf( '%s: %04o → %04o', $target, $before, $after ) );
    wp_send_json_success( array(
        'message' => sprintf( __( 'Đã đổi quyền %1$s từ %2$s sang %3$s.', 'sitevorx' ), $target, sprintf( '%04o', $before ), sprintf( '%04o', $after ) ),
    ) );
}

// AJAX: probe site for actual security headers (used by Headers tab "Kiểm tra ngay").
add_action( 'wp_ajax_sv_test_headers', 'sv_ajax_test_headers' );
function sv_ajax_test_headers() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );
    check_ajax_referer( 'sv_security_nonce', 'nonce' );
    $url = home_url( '/' );
    $resp = wp_remote_get( $url, array(
        'timeout'     => 15,
        'redirection' => 3,
        'sslverify'   => false,
        'headers'     => array( 'Cache-Control' => 'no-cache' ),
    ) );
    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( $resp->get_error_message() );
    }
    $raw = wp_remote_retrieve_headers( $resp );
    $headers = array();
    foreach ( $raw->getAll() as $name => $val ) {
        $key = strtolower( $name );
        $headers[ $key ] = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
    }
    wp_send_json_success( array( 'url' => $url, 'headers' => $headers ) );
}

/**
 * Login Honeypot — hidden field that bots fill, humans don't.
 */
add_action( 'login_form', 'sv_honeypot_field' );
function sv_honeypot_field() {
    if ( get_option( 'sv_sec_honeypot_enabled' ) !== '1' ) return;
    echo '<p style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;" aria-hidden="true">';
    echo '<label for="sv_hp_field">Leave empty</label>';
    echo '<input type="text" name="sv_hp_field" id="sv_hp_field" value="" tabindex="-1" autocomplete="off">';
    echo '</p>';
}

add_filter( 'authenticate', 'sv_honeypot_check', 1, 3 );
function sv_honeypot_check( $user, $username, $password ) {
    if ( get_option( 'sv_sec_honeypot_enabled' ) !== '1' ) return $user;
    $hp = isset( $_POST['sv_hp_field'] ) ? sanitize_text_field( wp_unslash( $_POST['sv_hp_field'] ) ) : '';
    if ( '' !== $hp ) {
        sv_sec_log( 'honeypot_triggered', 'Bot blocked by honeypot' );
        return new WP_Error( 'honeypot_triggered', esc_html__( 'Truy cập bị từ chối.', 'sitevorx' ) );
    }
    return $user;
}

/**
 * User Enumeration Protection — block ?author=N and REST API /users for anonymous.
 */
add_action( 'template_redirect', 'sv_block_author_enum' );
function sv_block_author_enum() {
    if ( get_option( 'sv_sec_block_user_enum' ) !== '1' ) return;
    if ( isset( $_GET['author'] ) && ! is_admin() && ! is_user_logged_in() ) {
        wp_safe_redirect( home_url( '/' ), 301 );
        exit;
    }
}

add_filter( 'rest_endpoints', 'sv_block_rest_users' );
function sv_block_rest_users( $endpoints ) {
    if ( get_option( 'sv_sec_block_user_enum' ) !== '1' ) return $endpoints;
    if ( ! is_user_logged_in() ) {
        unset( $endpoints['/wp/v2/users'] );
        unset( $endpoints['/wp/v2/users/(?P<id>[\\d]+)'] );
    }
    return $endpoints;
}

/**
 * Login Notification — email admin on successful admin login.
 *
 * The mail is sent through wp_mail(), so it goes via whatever Sitevorx
 * SMTP module (or any other SMTP plugin) the site has configured. With
 * no SMTP set up it falls back to PHP mail() which is usually blocked
 * or sent to spam on shared hosting — install Sitevorx → SMTP first.
 */
add_action( 'wp_login', 'sv_login_notify', 10, 2 );
function sv_login_notify( $username, $user ) {
    if ( get_option( 'sv_sec_login_notify' ) !== '1' ) return;
    if ( ! user_can( $user, 'manage_options' ) ) return;
    $ip = sv_get_client_ip();
    $cooldown_key = 'sv_login_notify_' . md5( $ip . $user->ID );
    if ( get_transient( $cooldown_key ) ) return;
    set_transient( $cooldown_key, '1', HOUR_IN_SECONDS );

    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'N/A';
    $sent = sv_login_notify_send( array(
        'username'  => $username,
        'ip'        => $ip,
        'time'      => sv_format_local_time(),
        'ua'        => substr( $ua, 0, 200 ),
        'is_test'   => false,
    ) );

    // Persist the result so the admin can see whether mail actually went
    // out, not just whether the hook fired.
    update_option( 'sv_sec_login_notify_last', array(
        'ts'       => time(),
        'to'       => get_option( 'admin_email' ),
        'username' => $username,
        'ip'       => $ip,
        'success'  => (bool) $sent,
    ), false );

    if ( function_exists( 'sv_audit_log' ) ) {
        sv_audit_log( $sent ? 'admin_login_notify_sent' : 'admin_login_notify_failed', array(
            'username' => $username,
            'ip'       => $ip,
            'to'       => get_option( 'admin_email' ),
        ) );
    }
}

/**
 * Compose and send the admin-login notification email. Returns wp_mail()
 * boolean. Shared between the real `wp_login` hook and the manual test
 * button in the Monitor tab so the layout/sender setup stays in one place.
 */
function sv_login_notify_send( $args ) {
    $defaults = array(
        'username'  => '',
        'ip'        => '',
        'time'      => sv_format_local_time(),
        'ua'        => '',
        'is_test'   => false,
    );
    $args = wp_parse_args( $args, $defaults );

    $to        = get_option( 'admin_email' );
    $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
    $site_url  = home_url( '/' );

    $prefix = $args['is_test']
        ? '[Sitevorx TEST] '
        : '[' . $site_name . '] ';
    $subject = $prefix . __( 'Cảnh báo đăng nhập tài khoản quản trị', 'sitevorx' );

    $intro = $args['is_test']
        ? esc_html__( 'Đây là email thử nghiệm để bạn xác nhận chức năng cảnh báo đăng nhập đang hoạt động.', 'sitevorx' )
        : esc_html__( 'Một tài khoản quản trị vừa đăng nhập vào website của bạn.', 'sitevorx' );

    $rows = array(
        __( 'Website',     'sitevorx' ) => sprintf( '<a href="%1$s">%1$s</a>', esc_url( $site_url ) ),
        __( 'Tài khoản',   'sitevorx' ) => esc_html( $args['username'] ),
        __( 'Địa chỉ IP',  'sitevorx' ) => esc_html( $args['ip'] ),
        __( 'Thời gian',   'sitevorx' ) => esc_html( $args['time'] ),
        __( 'Trình duyệt', 'sitevorx' ) => esc_html( $args['ua'] ),
    );

    $body  = '<div style="font-family:Arial,sans-serif; font-size:14px; color:#1f2937; max-width:560px; margin:0 auto;">';
    $body .= '<h2 style="margin:0 0 12px; color:#dc2626;">🔐 ' . esc_html__( 'Cảnh báo đăng nhập admin', 'sitevorx' ) . '</h2>';
    $body .= '<p style="margin:0 0 16px; color:#475569;">' . $intro . '</p>';
    $body .= '<table cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse;">';
    foreach ( $rows as $label => $value ) {
        $body .= '<tr><td style="padding:8px 12px; background:#f8fafc; border-bottom:1px solid #e5e7eb; width:120px; color:#64748b; font-weight:600;">' . esc_html( $label ) . '</td>';
        $body .= '<td style="padding:8px 12px; background:#ffffff; border-bottom:1px solid #e5e7eb; color:#0f172a; word-break:break-all;">' . $value . '</td></tr>';
    }
    $body .= '</table>';
    $body .= '<p style="margin:18px 0 0; color:#94a3b8; font-size:12px;">' . esc_html__( 'Email này được gửi tự động bởi Sitevorx. Nếu bạn vừa đăng nhập thì có thể bỏ qua. Nếu không phải bạn → đổi mật khẩu ngay và xem nhật ký kiểm toán.', 'sitevorx' ) . '</p>';
    $body .= '</div>';

    // Sender domain — prefer the site's own host so SPF/DKIM line up;
    // SMTP plugins can still override via wp_mail_from filters.
    $parsed     = wp_parse_url( $site_url );
    $host       = isset( $parsed['host'] ) ? $parsed['host'] : '';
    if ( '' !== $host && 0 === strpos( $host, 'www.' ) ) {
        $host = substr( $host, 4 );
    }
    $from_email = '' !== $host ? 'wordpress@' . $host : $to;
    $headers    = array(
        'Content-Type: text/html; charset=UTF-8',
        sprintf( 'From: %s <%s>', '=?UTF-8?B?' . base64_encode( $site_name ) . '?=', $from_email ),
        'Reply-To: ' . $to,
    );

    return (bool) wp_mail( $to, $subject, $body, $headers );
}

/**
 * AJAX: send a one-off test of the admin-login notification.
 * Used by the "Gửi thử ngay" button in Trung tâm Bảo mật → Giám Sát.
 */
add_action( 'wp_ajax_sv_test_login_notify', 'sv_ajax_test_login_notify' );
function sv_ajax_test_login_notify() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( __( 'Không có quyền.', 'sitevorx' ) );
    check_ajax_referer( 'sv_security_nonce', 'nonce' );
    $current = wp_get_current_user();
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'N/A';
    $sent = sv_login_notify_send( array(
        'username' => $current && $current->exists() ? $current->user_login : 'admin',
        'ip'       => sv_get_client_ip(),
        'time'     => sv_format_local_time(),
        'ua'       => substr( $ua, 0, 200 ),
        'is_test'  => true,
    ) );
    if ( ! $sent ) {
        wp_send_json_error( __( 'wp_mail() trả về false. Có thể server chưa cấu hình SMTP, hoặc PHP mail() bị hosting chặn. Hãy cài cấu hình SMTP trước (Sitevorx → Cấu hình SMTP).', 'sitevorx' ) );
    }
    wp_send_json_success( array(
        'message' => sprintf(
            __( 'Đã gửi email test tới %s. Kiểm tra hộp thư (cả thư mục Spam/Promotions).', 'sitevorx' ),
            get_option( 'admin_email' )
        ),
    ) );
}

/**
 * Failed Login Logger — record failed attempts into the audit log.
 * The remote lockout (system-optimizer.php) already records `login_lockout`
 * events when the threshold is hit. Here we record every individual failure
 * with `login_failed` so the Monitor tab can show the recent stream.
 */
add_action( 'wp_login_failed', 'sv_log_failed_login' );
function sv_log_failed_login( $username ) {
    if ( ! function_exists( 'sv_audit_log' ) ) return;
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
    sv_audit_log( 'login_failed', array(
        'username' => sanitize_user( $username ),
        'ua'       => substr( $ua, 0, 120 ),
    ) );
}

// ==========================================================================
// SECURITY ACTION LOG — thin wrapper delegating to remote audit API.
// Storage lives in `sv_audit_log` option managed by includes/sitevorx-audit.php.
// ==========================================================================
function sv_sec_log( $action, $detail = '' ) {
    if ( ! function_exists( 'sv_audit_log' ) ) {
        return;
    }
    $context = array();
    if ( '' !== (string) $detail ) {
        $context['detail'] = (string) $detail;
    }
    sv_audit_log( sanitize_key( $action ), $context );
}

// ==========================================================================
// SECURITY SCORE CALCULATOR — 0-100, 12 criteria, NO SMTP
// ==========================================================================
function sv_calculate_security_score() {
    $checks = array();

    // 1. SSL
    $ssl = sv_is_effectively_ssl();
    $checks[] = array( 'label' => 'SSL / HTTPS', 'pass' => $ssl, 'points' => 10, 'link' => '' );

    // 2. WP_DEBUG
    $debug_off = ! ( defined( 'WP_DEBUG' ) && WP_DEBUG );
    $checks[] = array( 'label' => __( 'WP_DEBUG tắt', 'sitevorx' ), 'pass' => $debug_off, 'points' => 8, 'link' => '' );

    // 3. reCAPTCHA
    $recaptcha = get_option( 'sv_sec_enable_recaptcha' ) === '1';
    $checks[] = array( 'label' => 'reCAPTCHA', 'pass' => $recaptcha, 'points' => 10, 'link' => 'sv-security-center&tab=config' );

    // 4. Limit Login
    $limit = get_option( 'sv_sec_limit_login' ) === '1';
    $checks[] = array( 'label' => __( 'Giới hạn đăng nhập', 'sitevorx' ), 'pass' => $limit, 'points' => 8, 'link' => 'sv-security-center&tab=config' );

    // 5. Secret URL
    $secret = get_option( 'sv_sec_enable_login_key' ) === '1';
    $checks[] = array( 'label' => __( 'URL đăng nhập bí mật', 'sitevorx' ), 'pass' => $secret, 'points' => 8, 'link' => 'sv-security-center&tab=config' );

    // 6. XML-RPC
    $xmlrpc = get_option( 'sv_sec_disable_xmlrpc' ) === '1';
    $checks[] = array( 'label' => __( 'Khóa XML-RPC', 'sitevorx' ), 'pass' => $xmlrpc, 'points' => 8, 'link' => 'sv-security-center&tab=config' );

    // 7. Code Editor
    $editor = get_option( 'sv_sec_disable_editor' ) === '1' || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );
    $checks[] = array( 'label' => __( 'Khóa Code Editor', 'sitevorx' ), 'pass' => $editor, 'points' => 8, 'link' => 'sv-security-center&tab=config' );

    // 8. Security Headers
    $headers = get_option( 'sv_sec_headers_enabled' ) === '1';
    $checks[] = array( 'label' => 'Security Headers', 'pass' => $headers, 'points' => 8, 'link' => 'sv-security-center&tab=headers' );

    // 9. WordPress up to date
    $core_updates = function_exists( 'get_core_updates' ) ? get_core_updates() : array();
    $wp_latest    = ( ! empty( $core_updates ) && isset( $core_updates[0]->current ) ) ? $core_updates[0]->current : get_bloginfo( 'version' );
    $wp_ok        = version_compare( get_bloginfo( 'version' ), $wp_latest, '>=' );
    $checks[] = array( 'label' => __( 'WordPress cập nhật', 'sitevorx' ), 'pass' => $wp_ok, 'points' => 10, 'link' => 'sv-maintenance-check' );

    // 10. PHP >= 8.0
    $php_ok = version_compare( phpversion(), '8.0', '>=' );
    $checks[] = array( 'label' => 'PHP ≥ 8.0', 'pass' => $php_ok, 'points' => 7, 'link' => '' );

    // 11. User Enum Protection
    $enum = get_option( 'sv_sec_block_user_enum' ) === '1';
    $checks[] = array( 'label' => __( 'Chặn dò username', 'sitevorx' ), 'pass' => $enum, 'points' => 8, 'link' => 'sv-security-center&tab=monitor' );

    // 12. Honeypot
    $honeypot = get_option( 'sv_sec_honeypot_enabled' ) === '1';
    $checks[] = array( 'label' => __( 'Bẫy bot đăng nhập', 'sitevorx' ), 'pass' => $honeypot, 'points' => 7, 'link' => 'sv-security-center&tab=monitor' );

    $score = 0;
    $max   = 0;
    foreach ( $checks as $c ) {
        $max += $c['points'];
        if ( $c['pass'] ) $score += $c['points'];
    }
    $score_pct = $max > 0 ? (int) round( ( $score / $max ) * 100 ) : 0;

    return array( 'score' => $score_pct, 'checks' => $checks, 'raw' => $score, 'max' => $max );
}

// ==========================================================================
// AJAX: Clear all lockouts.
// Reads current lockouts via the remote API (includes/system-optimizer.php)
// and unlocks each by hash, so the SQL goes through `delete_transient()` and
// never touches a raw LIKE query (Plugin Check friendly).
// ==========================================================================
add_action( 'wp_ajax_sv_clear_lockouts', 'sv_ajax_clear_lockouts' );
function sv_ajax_clear_lockouts() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );
    check_ajax_referer( 'sv_security_nonce', 'nonce' );
    $cleared = 0;
    if ( function_exists( 'sv_login_get_current_lockouts' ) && function_exists( 'sv_login_unlock_by_hash' ) ) {
        foreach ( sv_login_get_current_lockouts() as $lk ) {
            if ( ! empty( $lk['hash'] ) && sv_login_unlock_by_hash( $lk['hash'] ) ) {
                $cleared++;
            }
        }
    }
    sv_sec_log( 'clear_lockouts', __( 'Đã xóa tất cả IP bị khóa', 'sitevorx' ) );
    wp_send_json_success( array( 'message' => __( 'Đã xóa tất cả khóa IP.', 'sitevorx' ) ) );
}

// ==========================================================================
// AJAX: Clear failed login log
// ==========================================================================
add_action( 'wp_ajax_sv_clear_failed_logins', 'sv_ajax_clear_failed_logins' );
function sv_ajax_clear_failed_logins() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );
    check_ajax_referer( 'sv_security_nonce', 'nonce' );
    // Failed logins now live in the central audit log; clearing the legacy
    // option is a no-op and the audit clear endpoint handles the rest.
    delete_option( 'sv_sec_failed_logins' );
    sv_sec_log( 'clear_failed_log', __( 'Đã xóa nhật ký đăng nhập thất bại', 'sitevorx' ) );
    wp_send_json_success();
}

// ==========================================================================
// AJAX: Clear action log
// ==========================================================================
add_action( 'wp_ajax_sv_clear_action_log', 'sv_ajax_clear_action_log' );
function sv_ajax_clear_action_log() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );
    check_ajax_referer( 'sv_security_nonce', 'nonce' );
    if ( function_exists( 'sv_audit_clear' ) ) {
        sv_audit_clear();
    }
    delete_option( 'sv_sec_action_log' );
    wp_send_json_success();
}

// ==========================================================================
// AJAX: Core Integrity Check
// ==========================================================================
add_action( 'wp_ajax_sv_integrity_check', 'sv_ajax_integrity_check' );
function sv_ajax_integrity_check() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );
    check_ajax_referer( 'sv_security_nonce', 'nonce' );

    $wp_version = get_bloginfo( 'version' );
    $response   = wp_remote_get( 'https://api.wordpress.org/core/checksums/1.0/?version=' . $wp_version . '&locale=en_US', array( 'timeout' => 30 ) );
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( __( 'Không thể kết nối WordPress.org API.', 'sitevorx' ) );
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $body['checksums'] ) ) {
        wp_send_json_error( __( 'Không nhận được dữ liệu checksums.', 'sitevorx' ) );
    }

    $modified = array();
    $missing  = array();
    foreach ( $body['checksums'] as $file => $expected_hash ) {
        if ( preg_match( '/^wp-content\//i', $file ) ) continue;
        $local_path = ABSPATH . $file;
        if ( ! file_exists( $local_path ) ) {
            $missing[] = $file;
            continue;
        }
        if ( md5_file( $local_path ) !== $expected_hash ) {
            $modified[] = $file;
        }
    }

    sv_sec_log( 'integrity_check', sprintf( __( '%d sửa đổi, %d thiếu', 'sitevorx' ), count( $modified ), count( $missing ) ) );
    wp_send_json_success( array( 'modified' => array_slice( $modified, 0, 50 ), 'missing' => array_slice( $missing, 0, 50 ) ) );
}
