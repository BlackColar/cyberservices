<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================================================================
// SECURITY CENTER UI — Save handlers + Page render (6 tabs)
// ==========================================================================

function sv_display_security_center_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'sitevorx' ) );
    }

    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

    // ── Save: Security Config (tab=config) ──
    if ( isset( $_POST['sv_save_security'] ) && check_admin_referer( 'sv_security_nonce' ) ) {
        $sec_errors = array();
        $login_key_val    = sanitize_key( wp_unslash( $_POST['sec_login_key'] ?? '' ) );
        $enable_login_key = isset( $_POST['sec_enable_login_key'] );
        if ( $enable_login_key && '' === $login_key_val ) {
            $sec_errors[] = __( 'Vui lòng nhập từ khóa bảo mật trước khi bật tính năng.', 'sitevorx' );
        }
        $rc_site   = sanitize_text_field( wp_unslash( $_POST['sec_recaptcha_site_key'] ?? '' ) );
        $rc_secret = sanitize_text_field( wp_unslash( $_POST['sec_recaptcha_secret_key'] ?? '' ) );
        $enable_rc = isset( $_POST['sec_enable_recaptcha'] );
        if ( $enable_rc && ( '' === $rc_site || '' === $rc_secret ) ) {
            $sec_errors[] = __( 'Vui lòng nhập đầy đủ Site Key và Secret Key.', 'sitevorx' );
        }
        if ( empty( $sec_errors ) ) {
            update_option( 'sv_sec_login_key', $login_key_val );
            update_option( 'sv_sec_enable_login_key', ( $enable_login_key && '' !== $login_key_val ) ? '1' : '0' );
            update_option( 'sv_sec_disable_editor', isset( $_POST['sec_disable_editor'] ) ? '1' : '0' );
            update_option( 'sv_sec_disable_xmlrpc', isset( $_POST['sec_disable_xmlrpc'] ) ? '1' : '0' );
            update_option( 'sv_sec_recaptcha_site_key', $rc_site );
            update_option( 'sv_sec_recaptcha_secret_key', $rc_secret );
            $rc_ver = sanitize_key( wp_unslash( $_POST['sec_recaptcha_version'] ?? 'v2' ) );
            update_option( 'sv_sec_recaptcha_version', in_array( $rc_ver, array( 'v2', 'v3' ), true ) ? $rc_ver : 'v2' );
            update_option( 'sv_sec_enable_recaptcha', ( $enable_rc && '' !== $rc_site && '' !== $rc_secret ) ? '1' : '0' );
            update_option( 'sv_sec_limit_login', isset( $_POST['sec_limit_login'] ) ? '1' : '0' );
            sv_sec_log( 'save_config', __( 'Đã lưu cấu hình bảo mật', 'sitevorx' ) );
            echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . esc_html__( 'Đã lưu cấu hình bảo mật.', 'sitevorx' ) . '</p></div>';
        } else {
            foreach ( $sec_errors as $err ) {
                echo '<div class="notice notice-error is-dismissible sv-notice"><p>' . esc_html( $err ) . '</p></div>';
            }
        }
    }

    // ── Save: Headers (tab=headers) ──
    if ( isset( $_POST['sv_save_headers'] ) && check_admin_referer( 'sv_security_nonce' ) ) {
        update_option( 'sv_sec_headers_enabled', isset( $_POST['sec_headers_enabled'] ) ? '1' : '0' );
        // HSTS — only allow saving ON when site is HTTPS, otherwise force OFF.
        $hsts_request = isset( $_POST['sec_headers_hsts'] ) && sv_is_effectively_ssl();
        update_option( 'sv_sec_headers_hsts', $hsts_request ? '1' : '0' );
        $hsts_max_allowed = array( 300, 86400, 2592000, 15768000, 31536000 );
        $hsts_max_input   = isset( $_POST['sec_headers_hsts_max'] ) ? absint( wp_unslash( $_POST['sec_headers_hsts_max'] ) ) : 15768000;
        if ( ! in_array( $hsts_max_input, $hsts_max_allowed, true ) ) {
            $hsts_max_input = 15768000;
        }
        update_option( 'sv_sec_headers_hsts_max', $hsts_max_input );
        update_option( 'sv_sec_headers_hsts_sub', isset( $_POST['sec_headers_hsts_sub'] ) ? '1' : '0' );
        sv_sec_log( 'save_headers', __( 'Đã lưu cấu hình Security Headers', 'sitevorx' ) );
        echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . esc_html__( 'Đã lưu cấu hình Security Headers.', 'sitevorx' ) . '</p></div>';
    }

    // ── Save: Monitor settings (tab=monitor) ──
    if ( isset( $_POST['sv_save_monitor'] ) && check_admin_referer( 'sv_security_nonce' ) ) {
        update_option( 'sv_sec_honeypot_enabled', isset( $_POST['sec_honeypot'] ) ? '1' : '0' );
        update_option( 'sv_sec_block_user_enum', isset( $_POST['sec_block_enum'] ) ? '1' : '0' );
        update_option( 'sv_sec_login_notify', isset( $_POST['sec_login_notify'] ) ? '1' : '0' );
        sv_sec_log( 'save_monitor', __( 'Đã lưu cấu hình giám sát', 'sitevorx' ) );
        echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . esc_html__( 'Đã lưu cấu hình giám sát.', 'sitevorx' ) . '</p></div>';
    }

    $security_nonce = wp_create_nonce( 'sv_security_nonce' );
    ?>
    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar( 'security-center' ); ?>
            <div class="sv-content-area">

                <div class="sv-top-banner">
                    <h2><?php esc_html_e( 'Trung tâm Bảo mật', 'sitevorx' ); ?></h2>
                    <p><?php esc_html_e( 'Trung tâm điều khiển & giám sát bảo mật toàn diện cho website của bạn.', 'sitevorx' ); ?></p>
                </div>

                <div class="sv-tabs-nav">
                    <a href="?page=sv-security-center&tab=overview" class="sv-tab-btn <?php echo esc_attr( 'overview' === $active_tab ? 'active' : '' ); ?>"><?php esc_html_e( 'Tổng Quan', 'sitevorx' ); ?></a>
                    <a href="?page=sv-security-center&tab=config" class="sv-tab-btn <?php echo esc_attr( 'config' === $active_tab ? 'active' : '' ); ?>"><?php esc_html_e( 'Cấu Hình', 'sitevorx' ); ?></a>
                    <a href="?page=sv-security-center&tab=scanner" class="sv-tab-btn <?php echo esc_attr( 'scanner' === $active_tab ? 'active' : '' ); ?>"><?php esc_html_e( 'Quét Mã Độc', 'sitevorx' ); ?></a>
                    <a href="?page=sv-security-center&tab=headers" class="sv-tab-btn <?php echo esc_attr( 'headers' === $active_tab ? 'active' : '' ); ?>"><?php esc_html_e( 'Security Headers', 'sitevorx' ); ?></a>
                    <a href="?page=sv-security-center&tab=monitor" class="sv-tab-btn <?php echo esc_attr( 'monitor' === $active_tab ? 'active' : '' ); ?>"><?php esc_html_e( 'Giám Sát', 'sitevorx' ); ?></a>
                    <a href="?page=sv-security-center&tab=audit" class="sv-tab-btn <?php echo esc_attr( 'audit' === $active_tab ? 'active' : '' ); ?>"><?php esc_html_e( 'Kiểm Tra', 'sitevorx' ); ?></a>
                </div>

                <?php
                switch ( $active_tab ) {
                    case 'config':
                        sv_render_sec_tab_config();
                        break;
                    case 'scanner':
                        sv_render_sec_tab_scanner();
                        break;
                    case 'headers':
                        sv_render_sec_tab_headers();
                        break;
                    case 'monitor':
                        sv_render_sec_tab_monitor( $security_nonce );
                        break;
                    case 'audit':
                        sv_render_sec_tab_audit();
                        break;
                    default:
                        sv_render_sec_tab_overview( $security_nonce );
                        break;
                }
                ?>

            </div>
        </div>
    </div>
    <?php
}
