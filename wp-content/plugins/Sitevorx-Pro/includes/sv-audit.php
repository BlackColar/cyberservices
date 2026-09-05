<?php
/**
 * Sitevorx Pro Audit Log
 *
 * Lightweight option-backed ring buffer (no custom DB table) recording
 * sensitive admin actions. Public API:
 *
 *   sv_audit_log( $event, $context = array() )
 *   sv_audit_get_entries( $limit = 50 )
 *   sv_audit_clear()
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'SV_AUDIT_OPTION' ) ) {
    define( 'SV_AUDIT_OPTION', 'sv_audit_log' );
}
if ( ! defined( 'SV_AUDIT_MAX_ENTRIES' ) ) {
    define( 'SV_AUDIT_MAX_ENTRIES', 200 );
}

function sv_audit_get_actor_ip() {
    if ( function_exists( 'sv_get_client_ip' ) ) {
        $ip = sv_get_client_ip();
        return ( '0.0.0.0' === $ip ) ? '' : $ip;
    }
    if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }
    return '';
}

function sv_audit_sanitize_context( $context ) {
    if ( ! is_array( $context ) ) {
        return array();
    }

    $clean = array();
    foreach ( $context as $key => $value ) {
        $key = sanitize_key( (string) $key );
        if ( '' === $key ) {
            continue;
        }
        if ( is_scalar( $value ) ) {
            $clean[ $key ] = sanitize_text_field( (string) $value );
        } elseif ( is_array( $value ) ) {
            $flat = array();
            foreach ( $value as $sub ) {
                if ( is_scalar( $sub ) ) {
                    $flat[] = sanitize_text_field( (string) $sub );
                }
            }
            $clean[ $key ] = implode( ',', $flat );
        }
    }

    return $clean;
}

function sv_audit_log( $event, $context = array() ) {
    $event = sanitize_key( (string) $event );
    if ( '' === $event ) {
        return;
    }

    $user      = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
    $actor_id  = ( $user && $user->exists() ) ? (int) $user->ID : 0;
    $actor_log = ( $user && $user->exists() ) ? sanitize_user( $user->user_login, true ) : '';

    $entry = array(
        'ts'          => time(),
        'event'       => $event,
        'actor_id'    => $actor_id,
        'actor_login' => $actor_log,
        'actor_ip'    => sv_audit_get_actor_ip(),
        'context'     => sv_audit_sanitize_context( $context ),
    );

    $log = get_option( SV_AUDIT_OPTION, array() );
    if ( ! is_array( $log ) ) {
        $log = array();
    }
    $log[] = $entry;

    $overflow = count( $log ) - (int) SV_AUDIT_MAX_ENTRIES;
    if ( $overflow > 0 ) {
        $log = array_slice( $log, $overflow );
    }

    update_option( SV_AUDIT_OPTION, $log, false );
}

function sv_audit_get_entries( $limit = 50 ) {
    $log = get_option( SV_AUDIT_OPTION, array() );
    if ( ! is_array( $log ) ) {
        return array();
    }
    $log   = array_reverse( $log );
    $limit = max( 1, (int) $limit );

    return array_slice( $log, 0, $limit );
}

function sv_audit_clear() {
    update_option( SV_AUDIT_OPTION, array(), false );
}

function sv_audit_summarize_diff( $before, $after, $spec ) {
    $changes = array();
    foreach ( $spec as $key => $meta ) {
        $old  = isset( $before[ $key ] ) ? (string) $before[ $key ] : '';
        $new  = isset( $after[ $key ] ) ? (string) $after[ $key ] : '';
        $type = isset( $meta['type'] ) ? $meta['type'] : 'bool';
        if ( 'bool' === $type ) {
            // Normalize: '' / '0' / false / null are all "off"; only literal
            // '1' is "on". Prevents first-save logging every default-off
            // toggle as "Tắt X".
            $old_on = ( '1' === $old );
            $new_on = ( '1' === $new );
            if ( $old_on === $new_on ) {
                continue;
            }
            $label = isset( $meta['label'] ) ? (string) $meta['label'] : $key;
            $changes[] = ( $new_on ? __( 'Bật', 'sitevorx' ) : __( 'Tắt', 'sitevorx' ) ) . ' ' . $label;
        } else {
            if ( $old === $new ) {
                continue;
            }
            $label = isset( $meta['label'] ) ? (string) $meta['label'] : $key;
            $changes[] = sprintf( __( 'Đổi %s', 'sitevorx' ), $label );
        }
    }
    return implode( ', ', $changes );
}

function sv_audit_event_label( $event ) {
    $labels = array(
        'settings_reset'      => __( 'Đặt lại toàn bộ cấu hình', 'sitevorx' ),
        'settings_import'     => __( 'Nhập cấu hình từ JSON', 'sitevorx' ),
        'settings_export'     => __( 'Xuất cấu hình', 'sitevorx' ),
        'smtp_log_cleared'    => __( 'Xóa log SMTP', 'sitevorx' ),
        'smtp_test_sent'      => __( 'Gửi email test SMTP', 'sitevorx' ),
        'smtp_settings_saved' => __( 'Lưu cấu hình SMTP', 'sitevorx' ),
        'optimizer_saved'     => __( 'Lưu cấu hình Tăng tốc Website', 'sitevorx' ),
        'security_saved'      => __( 'Lưu cấu hình Bảo mật & Tường lửa', 'sitevorx' ),
        'utilities_saved'     => __( 'Lưu cấu hình Tiện ích Website', 'sitevorx' ),
        'cleanup_run'         => __( 'Chạy dọn dẹp thủ công', 'sitevorx' ),
        'cleanup_scheduled'   => __( 'Cập nhật lịch dọn dẹp tự động', 'sitevorx' ),
        'malware_scan'        => __( 'Chạy quét mã độc', 'sitevorx' ),
        'disk_files_deleted'  => __( 'Xóa file từ Disk Cleaner', 'sitevorx' ),
        'audit_log_cleared'   => __( 'Xóa nhật ký kiểm toán', 'sitevorx' ),
        'login_lockout'       => __( 'Khóa đăng nhập IP', 'sitevorx' ),
        'login_unlock'        => __( 'Mở khóa đăng nhập thủ công', 'sitevorx' ),
    );

    if ( isset( $labels[ $event ] ) ) {
        return $labels[ $event ];
    }

    return $event;
}

add_action( 'admin_init', 'sv_audit_handle_clear' );
function sv_audit_handle_clear() {
    if ( empty( $_POST['sv_audit_clear'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sv_audit_clear' ) ) {
        wp_die( esc_html__( 'Nonce verification failed.', 'sitevorx' ) );
    }

    sv_audit_clear();
    sv_audit_log( 'audit_log_cleared' );

    wp_safe_redirect( add_query_arg( array( 'page' => 'sv-audit-log', 'cleared' => '1' ), admin_url( 'admin.php' ) ) );
    exit;
}

function sv_display_audit_log_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $entries = sv_audit_get_entries( 200 );
    $cleared = ! empty( $_GET['cleared'] );
    ?>
    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar( 'audit-log' ); ?>
            <div class="sv-content-area">

                <div class="sv-top-banner">
                    <h2><?php esc_html_e( 'Nhật Ký Kiểm Toán', 'sitevorx' ); ?></h2>
                    <p><?php esc_html_e( 'Ghi lại các thao tác quản trị nhạy cảm trong Sitevorx Pro (lưu, đặt lại, nhập/xuất cấu hình, quét mã độc, dọn dẹp, xóa log). Bộ đệm giữ tối đa 200 mục mới nhất; mục cũ được thay thế tự động.', 'sitevorx' ); ?></p>
                </div>

                <?php if ( $cleared ) : ?>
                    <div class="notice notice-success is-dismissible sv-notice"><p><?php esc_html_e( 'Đã xóa nhật ký kiểm toán.', 'sitevorx' ); ?></p></div>
                <?php endif; ?>

                <div class="sv-content-box">
                    <div class="sv-box-header" style="justify-content:space-between;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span class="dashicons dashicons-list-view" style="color:#8e44ad;"></span>
                            <h3 style="margin:0;"><?php echo esc_html( sprintf( __( 'Lịch sử thao tác (%d mục)', 'sitevorx' ), count( $entries ) ) ); ?></h3>
                        </div>
                        <form method="post" style="margin:0;">
                            <?php wp_nonce_field( 'sv_audit_clear' ); ?>
                            <button type="submit" name="sv_audit_clear" value="1" class="button" style="color:#d63638; border-color:#d63638;" data-confirm="<?php echo esc_attr__( 'Xóa toàn bộ nhật ký kiểm toán?', 'sitevorx' ); ?>" onclick="return confirm(this.dataset.confirm);">
                                <?php esc_html_e( 'Xóa Nhật Ký', 'sitevorx' ); ?>
                            </button>
                        </form>
                    </div>
                    <div style="padding: 20px;">

        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th style="width:160px;"><?php esc_html_e( 'Thời gian', 'sitevorx' ); ?></th>
                    <th style="width:180px;"><?php esc_html_e( 'Người dùng', 'sitevorx' ); ?></th>
                    <th style="width:140px;"><?php esc_html_e( 'IP', 'sitevorx' ); ?></th>
                    <th><?php esc_html_e( 'Hành động', 'sitevorx' ); ?></th>
                    <th><?php esc_html_e( 'Ngữ cảnh', 'sitevorx' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $entries ) ) : ?>
                    <tr><td colspan="5" style="text-align:center; color:#646970;"><?php esc_html_e( 'Chưa có mục nào.', 'sitevorx' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $entries as $entry ) :
                        $ts          = isset( $entry['ts'] ) ? (int) $entry['ts'] : 0;
                        $actor_id    = isset( $entry['actor_id'] ) ? (int) $entry['actor_id'] : 0;
                        $actor_login = isset( $entry['actor_login'] ) ? (string) $entry['actor_login'] : '';
                        $actor_ip    = isset( $entry['actor_ip'] ) ? (string) $entry['actor_ip'] : '';
                        $event       = isset( $entry['event'] ) ? (string) $entry['event'] : '';
                        $context     = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
                        $context_str = '';
                        if ( ! empty( $context['summary'] ) ) {
                            $context_str = (string) $context['summary'];
                        } elseif ( ! empty( $context ) ) {
                            $parts = array();
                            foreach ( $context as $k => $v ) {
                                if ( 'summary' === $k ) continue;
                                $parts[] = $k . '=' . $v;
                            }
                            $context_str = implode( ' | ', $parts );
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html( $ts ? wp_date( 'Y-m-d H:i:s', $ts ) : '-' ); ?></td>
                            <td>
                                <?php if ( $actor_login ) : ?>
                                    <strong><?php echo esc_html( $actor_login ); ?></strong>
                                    <br><span style="color:#9ca3af; font-size:12px;">ID: <?php echo (int) $actor_id; ?></span>
                                <?php else : ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $actor_ip ? esc_html( $actor_ip ) : '<span style="color:#9ca3af;">—</span>'; ?></td>
                            <td><?php echo esc_html( sv_audit_event_label( $event ) ); ?></td>
                            <td style="word-break:break-all; max-width:480px;"><?php echo $context_str ? esc_html( $context_str ) : '<span style="color:#9ca3af;">—</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php
}
