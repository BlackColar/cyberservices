<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('admin_init', 'sv_smtp_check_db');
function sv_smtp_check_db() {
    if ( get_option('sv_smtp_db_version') != '1.1' ) {
        global $wpdb; $table_name = sv_smtp_get_log_table_name(); $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name ( id mediumint(9) NOT NULL AUTO_INCREMENT, time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, to_email text NOT NULL, subject text NOT NULL, status varchar(20) NOT NULL, error_msg text, PRIMARY KEY  (id) ) $charset_collate;";
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' ); dbDelta( $sql ); update_option('sv_smtp_db_version', '1.1');
    }
}

function sv_smtp_normalize_status($status) {
    if (in_array($status, ['success', 'Thành công', 'Success'], true)) {
        return 'success';
    }

    return 'failed';
}

function sv_smtp_get_status_label($status) {
    return 'success' === sv_smtp_normalize_status($status)
        ? __('Thành công', 'sitevorx')
        : __('Thất bại', 'sitevorx');
}

function sv_smtp_get_log_table_name() {
    global $wpdb;

    return $wpdb->prefix . 'sv_smtp_logs';
}

function sv_smtp_normalize_email_value( $email ) {
    return strtolower( trim( (string) $email ) );
}

function sv_smtp_split_email_list( $emails ) {
    $parts = array_filter( array_map( 'trim', explode( ',', (string) $emails ) ) );
    return array_values( $parts );
}

function sv_smtp_log_matches_email( $emails, $target_email ) {
    $needle = sv_smtp_normalize_email_value( $target_email );

    foreach ( sv_smtp_split_email_list( $emails ) as $email ) {
        if ( sv_smtp_normalize_email_value( $email ) === $needle ) {
            return true;
        }
    }

    return false;
}

function sv_smtp_query_logs_for_email( $email_address, $page = 1, $limit = 100 ) {
    global $wpdb;

    $table = sv_smtp_get_log_table_name();
    $page  = max( 1, absint( $page ) );
    $limit = max( 1, absint( $limit ) );
    $offset = ( $page - 1 ) * $limit;

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $table_exists !== $table ) {
        return array();
    }

    $candidate_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, time, to_email, subject, status, error_msg FROM {$table} WHERE to_email LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d",
            '%' . $wpdb->esc_like( $email_address ) . '%',
            $limit,
            $offset
        )
    );

    if ( empty( $candidate_rows ) ) {
        return array();
    }

    return array_values(
        array_filter(
            $candidate_rows,
            function( $row ) use ( $email_address ) {
                return sv_smtp_log_matches_email( $row->to_email, $email_address );
            }
        )
    );
}

function sv_smtp_register_privacy_content() {
    if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
        return;
    }

    $policy_text  = '<p>' . esc_html__( 'Sitevorx can optionally log outgoing email delivery activity when SMTP logging is enabled by an administrator. These logs may include recipient email addresses, message subjects, delivery status, and related error details for troubleshooting purposes.', 'sitevorx' ) . '</p>';
    $policy_text .= '<p>' . esc_html__( 'If Google reCAPTCHA protection is enabled for the login form, login verification tokens and the visitor IP address are sent to Google only during login attempts. This happens only when the feature is explicitly enabled and configured by the site administrator.', 'sitevorx' ) . '</p>';

    wp_add_privacy_policy_content( 'Sitevorx', wp_kses_post( $policy_text ) );
}
add_action( 'admin_init', 'sv_smtp_register_privacy_content' );

function sv_smtp_register_personal_data_exporter( $exporters ) {
    $exporters['sitevorx-smtp-logs'] = array(
        'exporter_friendly_name' => __( 'Sitevorx SMTP Logs', 'sitevorx' ),
        'callback'               => 'sv_smtp_personal_data_exporter',
    );

    return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'sv_smtp_register_personal_data_exporter' );

function sv_smtp_personal_data_exporter( $email_address, $page = 1 ) {
    $logs = sv_smtp_query_logs_for_email( $email_address, $page, 100 );
    $data = array();

    foreach ( $logs as $log ) {
        $data[] = array(
            'group_id'    => 'sitevorx-smtp-logs',
            'group_label' => __( 'Sitevorx SMTP Logs', 'sitevorx' ),
            'item_id'     => 'sitevorx-smtp-log-' . absint( $log->id ),
            'data'        => array(
                array(
                    'name'  => __( 'Recipient Email(s)', 'sitevorx' ),
                    'value' => esc_html( $log->to_email ),
                ),
                array(
                    'name'  => __( 'Sent At', 'sitevorx' ),
                    'value' => esc_html( $log->time ),
                ),
                array(
                    'name'  => __( 'Subject', 'sitevorx' ),
                    'value' => esc_html( $log->subject ),
                ),
                array(
                    'name'  => __( 'Status', 'sitevorx' ),
                    'value' => esc_html( sv_smtp_get_status_label( $log->status ) ),
                ),
                array(
                    'name'  => __( 'Error Details', 'sitevorx' ),
                    'value' => esc_html( $log->error_msg ),
                ),
            ),
        );
    }

    return array(
        'data' => $data,
        'done' => count( $logs ) < 100,
    );
}

function sv_smtp_register_personal_data_eraser( $erasers ) {
    $erasers['sitevorx-smtp-logs'] = array(
        'eraser_friendly_name' => __( 'Sitevorx SMTP Logs', 'sitevorx' ),
        'callback'             => 'sv_smtp_personal_data_eraser',
    );

    return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'sv_smtp_register_personal_data_eraser' );

function sv_smtp_personal_data_eraser( $email_address, $page = 1 ) {
    global $wpdb;

    $items_removed  = false;
    $items_retained = false;
    $messages       = array();
    $logs           = sv_smtp_query_logs_for_email( $email_address, $page, 100 );
    $table          = sv_smtp_get_log_table_name();

    foreach ( $logs as $log ) {
        $emails   = sv_smtp_split_email_list( $log->to_email );
        $updated  = array();
        $modified = false;

        foreach ( $emails as $email ) {
            if ( sv_smtp_normalize_email_value( $email ) === sv_smtp_normalize_email_value( $email_address ) ) {
                $updated[] = wp_privacy_anonymize_data( 'email', $email );
                $modified  = true;
                continue;
            }

            $updated[] = $email;
        }

        if ( ! $modified ) {
            continue;
        }

        $result = $wpdb->update(
            $table,
            array( 'to_email' => implode( ', ', $updated ) ),
            array( 'id' => absint( $log->id ) ),
            array( '%s' ),
            array( '%d' )
        );

        if ( false === $result ) {
            $items_retained = true;
            $messages[] = sprintf(
                /* translators: %d: SMTP log row ID. */
                __( 'Sitevorx could not erase SMTP log entry #%d automatically.', 'sitevorx' ),
                absint( $log->id )
            );
            continue;
        }

        $items_removed = true;
    }

    return array(
        'items_removed'  => $items_removed,
        'items_retained' => $items_retained,
        'messages'       => $messages,
        'done'           => count( $logs ) < 100,
    );
}

function sv_smtp_log_callback($isSent, $to, $cc, $bcc, $subject, $body, $from, $error = '') {
    $is_test = ($subject === 'Sitevorx SMTP - Test');
    if ( get_option('sv_smtp_enable_log') != '1' && !$is_test ) return;
    global $wpdb; $table_name = sv_smtp_get_log_table_name();
    $to_emails = array(); if (is_array($to)) { foreach($to as $t) { $to_emails[] = is_array($t) ? $t[0] : $t; } } else { $to_emails[] = $to; }
    $to_str = implode(', ', $to_emails);
    // Skip failed emails here — wp_mail_failed hook handles them with proper error details
    if (!$isSent) return;
    $wpdb->insert( $table_name, array('time' => current_time('mysql'), 'to_email' => sanitize_text_field($to_str), 'subject' => sanitize_text_field($subject), 'status' => 'success', 'error_msg' => '' ));
}

$sv_active_mailer = get_option('sv_active_mailer', '');
if (!empty($sv_active_mailer)) {
    add_action( 'phpmailer_init', 'sv_setup_smtp_mailer' );
}
function sv_setup_smtp_mailer( $phpmailer ) {
    $active_mailer = get_option('sv_active_mailer', ''); $from_name = get_option('sv_smtp_from_name', get_bloginfo('name')); $force_email = get_option('sv_smtp_force_email'); $force_name = get_option('sv_smtp_force_name');
    if ( $active_mailer == 'gmail' ) {
        $gmail_user = get_option('sv_gmail_user'); $gmail_pass = sv_decrypt(get_option('sv_gmail_pass'));
        if ( !empty($gmail_user) && !empty($gmail_pass) ) { 
            $phpmailer->isSMTP(); 
            $phpmailer->SMTPAuth = true;
            $phpmailer->Timeout = 10;
            $phpmailer->SMTPDebug = 0;
            $phpmailer->Host = 'smtp.gmail.com'; 
            $phpmailer->Port = 465; 
            $phpmailer->SMTPSecure = 'ssl'; 
            $phpmailer->Username = $gmail_user; 
            $phpmailer->Password = $gmail_pass; 
            if ( $force_email == '1' ) { 
                $phpmailer->From = $gmail_user; 
            }
        }
    } elseif ( $active_mailer == 'other' ) {
        $smtp_host = get_option('sv_smtp_host'); $smtp_user = get_option('sv_smtp_user'); $smtp_pass = sv_decrypt(get_option('sv_smtp_pass')); $smtp_port = get_option('sv_smtp_port', 465);
        if ( !empty($smtp_host) && !empty($smtp_user) && !empty($smtp_pass) ) { 
            $phpmailer->isSMTP(); 
            $phpmailer->SMTPAuth = true;
            $phpmailer->Timeout = 10;
            $phpmailer->SMTPDebug = 0;
            $phpmailer->Host = $smtp_host; 
            $phpmailer->Port = $smtp_port; 
            $phpmailer->Username = $smtp_user; 
            $phpmailer->Password = $smtp_pass; 
            if ($smtp_port == 465) { 
                $phpmailer->SMTPSecure = 'ssl'; 
            } elseif ($smtp_port == 587) { 
                $phpmailer->SMTPSecure = 'tls'; 
            } else { 
                $phpmailer->SMTPSecure = ''; 
                $phpmailer->SMTPAutoTLS = false; 
            }
            if ( $force_email == '1' ) { 
                $phpmailer->From = $smtp_user; 
            }
        }
    }
    if ( $force_name == '1' ) { 
        $phpmailer->FromName = $from_name; 
    }
    $phpmailer->action_function = 'sv_smtp_log_callback';
}

// Warn the admin when a stored SMTP password can no longer be decrypted
// (typically AUTH_KEY / salt changed on a host migration). In that case
// sv_decrypt() returns '' and the mailer silently falls back to PHP mail(),
// so without this notice the outage would be invisible.
add_action( 'admin_notices', 'sv_smtp_decrypt_failure_notice' );
function sv_smtp_decrypt_failure_notice() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $mailer = get_option( 'sv_active_mailer', '' );
    if ( 'gmail' !== $mailer && 'other' !== $mailer ) return;
    $opt    = 'gmail' === $mailer ? 'sv_gmail_pass' : 'sv_smtp_pass';
    $stored = (string) get_option( $opt, '' );
    if ( '' !== $stored && 0 === strpos( $stored, 'enc::' ) && '' === sv_decrypt( $stored ) ) {
        echo '<div class="notice notice-error sv-notice"><p><strong>Sitevorx SMTP:</strong> '
            . esc_html__( 'Không giải mã được mật khẩu SMTP đã lưu (thường do AUTH_KEY/khóa bảo mật thay đổi sau khi chuyển máy chủ). Email đang tạm gửi bằng hàm mail() mặc định — vui lòng vào tab SMTP và nhập lại mật khẩu.', 'sitevorx' )
            . '</p></div>';
    }
}

function sv_smtp_normalize_domain( $domain ) {
    $domain = strtolower( trim( (string) $domain ) );
    return preg_replace( '/^www\./', '', $domain );
}

function sv_smtp_get_force_email_warning( $active_tab ) {
    if ( get_option( 'sv_smtp_force_email', '0' ) !== '1' ) {
        return '';
    }

    $sender = ( 'gmail' === $active_tab ) ? get_option( 'sv_gmail_user', '' ) : get_option( 'sv_smtp_user', '' );
    if ( ! is_email( $sender ) ) {
        return '';
    }

    $sender_domain = sv_smtp_normalize_domain( substr( strrchr( $sender, '@' ), 1 ) );
    $site_domain   = sv_smtp_normalize_domain( wp_parse_url( home_url(), PHP_URL_HOST ) );
    if ( '' === $sender_domain || '' === $site_domain || $sender_domain === $site_domain ) {
        return '';
    }

    return sprintf(
        __( 'Cảnh báo SPF/DKIM: Force From Email đang dùng domain %1$s khác domain website %2$s. Nếu DNS chưa cấu hình đúng, email có thể vào spam.', 'sitevorx' ),
        $sender_domain,
        $site_domain
    );
}

// Hook wp_mail_failed to capture error and write to log DB (action_function doesn't receive error on connection crash)
add_action('wp_mail_failed', 'sv_smtp_log_failed_email');
function sv_smtp_log_failed_email( $wp_error ) {
    $is_log_on = (get_option('sv_smtp_enable_log') == '1');
    // Always log if it's a test email or if logging is enabled
    $error_msg = $wp_error->get_error_message();
    $error_data = $wp_error->get_error_data('wp_mail_failed');
    $to_str = '';
    $subject_str = '';
    if (is_array($error_data)) {
        if (isset($error_data['to'])) {
            $to_str = is_array($error_data['to']) ? implode(', ', $error_data['to']) : $error_data['to'];
        }
        if (isset($error_data['subject'])) {
            $subject_str = $error_data['subject'];
        }
        if (isset($error_data['phpmailer_exception_code'])) {
            $error_msg .= ' (Code: ' . $error_data['phpmailer_exception_code'] . ')';
        }
    }
    $is_test = ($subject_str === 'Sitevorx SMTP - Test');
    if (!$is_log_on && !$is_test) return;
    global $wpdb;
    $table_name = sv_smtp_get_log_table_name();
    $wpdb->insert($table_name, array(
        'time' => current_time('mysql'),
        'to_email' => sanitize_text_field($to_str),
        'subject' => sanitize_text_field($subject_str),
        'status' => 'failed',
        'error_msg' => sanitize_textarea_field($error_msg),
    ));
}

function sv_display_smtp_page() {
    global $wpdb;
    $active_tab = isset( $_GET[ 'tab' ] ) ? sanitize_text_field(wp_unslash($_GET[ 'tab' ])) : 'gmail';

    if ( current_user_can('manage_options') && isset($_POST['sv_save_gmail']) && check_admin_referer('sv_smtp_nonce') ) {
        $gmail_user = sanitize_email(wp_unslash($_POST['gmail_user'] ?? ''));
        $gmail_pass_input = sanitize_text_field(wp_unslash($_POST['gmail_pass'] ?? ''));
        $from_name = sanitize_text_field(wp_unslash($_POST['smtp_from_name'] ?? ''));
        update_option('sv_active_mailer', 'gmail');
        update_option('sv_gmail_user', $gmail_user);
        if (!empty($gmail_pass_input) && $gmail_pass_input !== '••••••••') {
            update_option('sv_gmail_pass', sv_encrypt($gmail_pass_input));
        }
        update_option('sv_smtp_from_name', $from_name);
        update_option('sv_smtp_force_email', isset($_POST['force_email']) ? '1' : '0');
        update_option('sv_smtp_force_name', isset($_POST['force_name']) ? '1' : '0');
        update_option('sv_smtp_enable_log', isset($_POST['enable_log']) ? '1' : '0');
        if ( function_exists( 'sv_audit_log' ) ) {
            sv_audit_log( 'smtp_settings_saved', array( 'mailer' => 'gmail', 'user' => $gmail_user ) );
        }
        echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . esc_html__('Đã lưu cấu hình Gmail!', 'sitevorx') . '</p></div>';
    }

    if ( current_user_can('manage_options') && isset($_POST['sv_save_other']) && check_admin_referer('sv_smtp_nonce') ) {
        $smtp_port = absint(wp_unslash($_POST['smtp_port'] ?? 465));
        if (!in_array($smtp_port, [25, 465, 587], true)) {
            $smtp_port = 465;
        }
        update_option('sv_active_mailer', 'other');
        update_option('sv_smtp_host', sanitize_text_field(wp_unslash($_POST['smtp_host'] ?? '')));
        update_option('sv_smtp_port', $smtp_port);
        update_option('sv_smtp_user', sanitize_email(wp_unslash($_POST['smtp_user'] ?? '')));
        $smtp_pass_input = sanitize_text_field(wp_unslash($_POST['smtp_pass'] ?? ''));
        if (!empty($smtp_pass_input) && $smtp_pass_input !== '••••••••') {
            update_option('sv_smtp_pass', sv_encrypt($smtp_pass_input));
        }
        update_option('sv_smtp_from_name', sanitize_text_field(wp_unslash($_POST['smtp_from_name'] ?? '')));
        update_option('sv_smtp_force_email', isset($_POST['force_email']) ? '1' : '0');
        update_option('sv_smtp_force_name', isset($_POST['force_name']) ? '1' : '0');
        update_option('sv_smtp_enable_log', isset($_POST['enable_log']) ? '1' : '0');
        if ( function_exists( 'sv_audit_log' ) ) {
            sv_audit_log( 'smtp_settings_saved', array( 'mailer' => 'other', 'host' => get_option( 'sv_smtp_host', '' ), 'port' => $smtp_port ) );
        }
        echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . esc_html__('Đã lưu cấu hình SMTP tùy chỉnh!', 'sitevorx') . '</p></div>';
        $active_tab = 'other';
    }

    if (current_user_can('manage_options') && isset($_POST['sv_clear_logs']) && check_admin_referer('sv_smtp_nonce')) {
        $log_table = sv_smtp_get_log_table_name();
        if ( preg_match( '/^[A-Za-z0-9_]+$/', $log_table ) ) {
            $wpdb->query( "DELETE FROM `{$log_table}`" );
            if ( function_exists( 'sv_audit_log' ) ) {
                sv_audit_log( 'smtp_log_cleared' );
            }
            echo '<div class="notice notice-success is-dismissible sv-notice"><p>' . esc_html__('Đã xóa sạch lịch sử gửi mail.', 'sitevorx') . '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible sv-notice"><p>' . esc_html__('Không thể làm sạch bảng log do tên bảng không hợp lệ.', 'sitevorx') . '</p></div>';
        }
    }

    $test_result_html = '';
    if ( current_user_can('manage_options') && isset($_POST['sv_test_smtp']) && check_admin_referer('sv_smtp_test_nonce') ) {
        $test_email = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        global $sv_debug_error;
        $sv_debug_error = '';
        add_action( 'wp_mail_failed', function( $wp_error ) {
            global $sv_debug_error;
            $sv_debug_error = $wp_error->get_error_message();
            $error_data = $wp_error->get_error_data('wp_mail_failed');
            if (is_array($error_data) && isset($error_data['phpmailer_exception_code'])) {
                $sv_debug_error .= ' (Code: ' . $error_data['phpmailer_exception_code'] . ')';
            }
        }, 5); // priority 5 = run before the logging hook
        $result = wp_mail($test_email, 'Sitevorx SMTP - Test', __('Hệ thống SMTP hoạt động hoàn hảo. Thời gian:', 'sitevorx') . ' ' . current_time('mysql'));
        if ( function_exists( 'sv_audit_log' ) ) {
            sv_audit_log( 'smtp_test_sent', array( 'to' => $test_email, 'result' => $result ? 'success' : 'failed' ) );
        }
        if ($result) {
            $test_result_html = '<div class="notice notice-success is-dismissible sv-notice" style="display:none; margin-top:15px;"><p>' . sv_kses_basic( sprintf(__('Gửi thử thành công tới: %s', 'sitevorx'), '<strong>' . esc_html($test_email) . '</strong>') ) . '</p></div>';
        } else {
            if (empty($sv_debug_error)) $sv_debug_error = __('Không thể kết nối tới máy chủ SMTP. Kiểm tra lại Host, Port, Tài khoản và Mật khẩu.', 'sitevorx');
            $test_result_html = '<div class="notice notice-error is-dismissible sv-notice" style="margin-top:15px;"><p><strong>❌ ' . esc_html__('Gửi mail thất bại', 'sitevorx') . '</strong></p><p style="font-size:13px; background:#fef2f2; padding:12px; border-radius:6px; border:1px solid #fecaca; margin-top:8px; font-family:monospace; word-break:break-all;">' . esc_html($sv_debug_error) . '</p></div>';
        }
        $active_tab = 'test';
    }

    $current_mailer = get_option('sv_active_mailer', '');
    $fn = get_option('sv_smtp_from_name', get_bloginfo('name'));
    $fe = get_option('sv_smtp_force_email', '0');
    $f_n = get_option('sv_smtp_force_name', '0');
    $el = get_option('sv_smtp_enable_log', '0');
    $gmail_status_text = __('ĐANG GỬI QUA GMAIL', 'sitevorx');
    $custom_status_text = __('ĐANG GỬI QUA SMTP RIÊNG', 'sitevorx');
    ?>
    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar('smtp'); ?>
            <div class="sv-content-area">
                
                <div class="sv-top-banner">
                    <h2><?php esc_html_e('Cấu hình Gửi Email Tự Động (SMTP)', 'sitevorx'); ?></h2>
                    <p>
                        <?php esc_html_e('Kết nối website với luồng gửi thư chuyên nghiệp, giúp email hóa đơn, quên mật khẩu không bị rơi vào hòm thư Rác (Spam).', 'sitevorx'); ?>
                        <br><?php esc_html_e('Trạng thái hiện tại:', 'sitevorx'); ?> <strong style="background: #eef2ff; color: #4338ca; padding: 4px 12px; border-radius: 20px; font-size: 12px; margin-top: 5px; display: inline-block;">
                            <?php echo ($current_mailer == 'gmail') ? '✓ ' . esc_html($gmail_status_text) : (($current_mailer == 'other') ? '✓ ' . esc_html($custom_status_text) : '✗ ' . esc_html__('CHƯA CẤU HÌNH', 'sitevorx')); ?>
                        </strong>
                    </p>
                </div>
                
                <div class="sv-tabs-nav">
                    <a href="?page=sv-smtp&tab=gmail" class="sv-tab-btn <?php echo $active_tab == 'gmail' ? 'active' : ''; ?>"><?php esc_html_e('Cấu hình Gmail', 'sitevorx'); ?></a>
                    <a href="?page=sv-smtp&tab=other" class="sv-tab-btn <?php echo $active_tab == 'other' ? 'active' : ''; ?>"><?php esc_html_e('SMTP Tùy chỉnh', 'sitevorx'); ?></a>
                    <a href="?page=sv-smtp&tab=test" class="sv-tab-btn <?php echo $active_tab == 'test' ? 'active' : ''; ?>"><?php esc_html_e('Gửi Test Email', 'sitevorx'); ?></a>
                    <a href="?page=sv-smtp&tab=logs" class="sv-tab-btn <?php echo $active_tab == 'logs' ? 'active' : ''; ?>"><?php esc_html_e('Lịch sử Log', 'sitevorx'); ?></a>
                </div>

                <?php if ( $active_tab == 'gmail' || $active_tab == 'other' ) : ?>
                <form method="POST">
                    <?php wp_nonce_field('sv_smtp_nonce'); ?>
                    
                    <div class="sv-content-box">
                        <?php if ($active_tab == 'gmail'): ?>
                            <div class="sv-box-header"><span class="dashicons dashicons-google" style="color:#ea4335;"></span><h3><?php esc_html_e('Tài khoản Google Workspace / Gmail', 'sitevorx'); ?></h3></div>
                            <div class="sv-form-group">
                                <div class="sv-form-label"><strong><?php esc_html_e('Tài khoản Gmail', 'sitevorx'); ?></strong><p><?php esc_html_e('Địa chỉ Gmail dùng để gửi thư hệ thống.', 'sitevorx'); ?></p></div>
                                <div class="sv-form-input"><input type="email" name="gmail_user" value="<?php echo esc_attr(get_option('sv_gmail_user')); ?>" required style="width:100%; max-width:320px; padding:8px 12px; border-radius:4px; border:1px solid #c3c4c7;"></div>
                            </div>
                            <div class="sv-form-group" style="border:none;">
                                <div class="sv-form-label"><strong><?php esc_html_e('Mật khẩu Ứng dụng', 'sitevorx'); ?></strong><p><?php esc_html_e('Dùng mật khẩu ứng dụng 16 ký tự do Google cấp.', 'sitevorx'); ?></p></div>
                                <div class="sv-form-input"><input type="password" name="gmail_pass" value="<?php echo !empty(get_option('sv_gmail_pass')) ? '••••••••' : ''; ?>" required style="width:100%; max-width:320px; padding:8px 12px; border-radius:4px; border:1px solid #c3c4c7;"></div>
                            </div>
                        <?php else: ?>
                            <div class="sv-box-header"><span class="dashicons dashicons-email" style="color:#0073aa;"></span><h3><?php esc_html_e('Máy chủ SMTP', 'sitevorx'); ?></h3></div>
                            <div class="sv-form-group">
                                <div class="sv-form-label"><strong><?php esc_html_e('Máy chủ & Cổng', 'sitevorx'); ?></strong><p><?php esc_html_e('Thông tin máy chủ SMTP (Host) và cổng kết nối (Port).', 'sitevorx'); ?></p></div>
                                <div class="sv-form-input" style="flex-direction:row; gap:10px;">
                                    <input type="text" name="smtp_host" value="<?php echo esc_attr(get_option('sv_smtp_host')); ?>" required style="flex:1;">
                                    <select name="smtp_port" style="width:120px; padding:8px 12px; border-radius:4px; border:1px solid #c3c4c7; box-shadow: inset 0 1px 2px rgba(0,0,0,0.07);">
                                        <option value="465" <?php selected(get_option('sv_smtp_port', 465), 465); ?>>465 (SSL)</option>
                                        <option value="587" <?php selected(get_option('sv_smtp_port'), 587); ?>>587 (TLS)</option>
                                        <option value="25" <?php selected(get_option('sv_smtp_port'), 25); ?>>25 (None)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="sv-form-group">
                                <div class="sv-form-label"><strong><?php esc_html_e('Tài khoản Email', 'sitevorx'); ?></strong><p><?php esc_html_e('Địa chỉ email dùng để đăng nhập vào máy chủ SMTP.', 'sitevorx'); ?></p></div>
                                <div class="sv-form-input"><input type="email" name="smtp_user" value="<?php echo esc_attr(get_option('sv_smtp_user')); ?>" required style="width:100%; max-width:320px; padding:8px 12px; border-radius:4px; border:1px solid #c3c4c7;"></div>
                            </div>
                            <div class="sv-form-group" style="border:none;">
                                <div class="sv-form-label"><strong><?php esc_html_e('Mật khẩu Email', 'sitevorx'); ?></strong><p><?php esc_html_e('Mật khẩu của tài khoản email tương ứng ở trên.', 'sitevorx'); ?></p></div>
                                <div class="sv-form-input"><input type="password" name="smtp_pass" value="<?php echo !empty(get_option('sv_smtp_pass')) ? '••••••••' : ''; ?>" required style="width:100%; max-width:320px; padding:8px 12px; border-radius:4px; border:1px solid #c3c4c7;"></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="sv-content-box">
                        <div class="sv-box-header"><span class="dashicons dashicons-admin-generic" style="color:#27ae60;"></span><h3><?php esc_html_e('Tùy chọn Nâng cao', 'sitevorx'); ?></h3></div>
                        <?php $force_email_warning = sv_smtp_get_force_email_warning( $active_tab ); ?>
                        <?php if ( '' !== $force_email_warning ) : ?>
                            <div class="notice notice-warning" style="display:block; margin:0 20px 15px;"><p><?php echo esc_html( $force_email_warning ); ?></p></div>
                        <?php endif; ?>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Tên người gửi (From Name)', 'sitevorx'); ?></strong><p><?php esc_html_e('Tên sẽ hiển thị trong hòm thư của khách hàng (Ví dụ: My Shop, My Brand).', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><input type="text" name="smtp_from_name" value="<?php echo esc_attr($fn); ?>"></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Găm chặt Địa chỉ Email', 'sitevorx'); ?></strong><p><?php esc_html_e('Ghi đè mọi thiết lập mặc định của nền tảng, đảm bảo 100% email luôn được gửi đi từ địa chỉ này.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="force_email" value="1" <?php checked($fe, '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Găm chặt Tên Người Gửi', 'sitevorx'); ?></strong><p><?php esc_html_e('Bắt buộc hệ thống luôn dùng tên hiển thị đã thiết lập ở mục trên cùng, không được tự ý đổi tên.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="force_name" value="1" <?php checked($f_n, '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-group" style="border:none;">
                            <div class="sv-form-label"><strong><?php esc_html_e('Lưu Lịch sử (Logs)', 'sitevorx'); ?></strong><p><?php esc_html_e('Ghi lại toàn bộ lịch sử gửi thư thành công/thất bại để dễ dàng kiểm tra hệ thống.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="enable_log" value="1" <?php checked($el, '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-footer"><button type="submit" name="<?php echo $active_tab == 'gmail' ? 'sv_save_gmail' : 'sv_save_other'; ?>" class="button button-primary"><?php esc_html_e('Lưu Cấu Hình', 'sitevorx'); ?></button></div>
                    </div>
                </form>

                <?php elseif ( $active_tab == 'test' ) : ?>
                <form method="POST">
                    <?php wp_nonce_field('sv_smtp_test_nonce'); ?>
                    <div class="sv-content-box">
                        <div class="sv-box-header"><span class="dashicons dashicons-admin-site-alt3" style="color:#ef4444;"></span><h3><?php esc_html_e('Chạy Thử Nghiệm Gửi Mail', 'sitevorx'); ?></h3></div>
                        <div class="sv-form-group" style="border:none;">
                            <div class="sv-form-label"><strong><?php esc_html_e('Nhập Email của bạn', 'sitevorx'); ?></strong><p><?php esc_html_e('Gõ email cá nhân của bạn vào đây để hệ thống gửi thử một bức thư xem cấu hình đã chuẩn chưa.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input" style="flex-direction:row; gap:10px; align-items:center;">
                                <input type="email" name="test_email" required style="width:100%; max-width:250px; border-radius:4px; border:1px solid #c3c4c7; padding:6px 12px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.07);">
                                <button type="submit" name="sv_test_smtp" class="button button-secondary" style="white-space: nowrap;"><?php esc_html_e('Gửi Thử', 'sitevorx'); ?></button>
                            </div>
                        </div>
                    </div>
                    <?php echo wp_kses_post( $test_result_html ); ?>
                </form>

                <?php elseif ( $active_tab == 'logs' ) : ?>
                <div class="sv-content-box">
                    <div class="sv-box-header" style="justify-content:space-between;">
                        <div style="display:flex; align-items:center; gap:10px;"><span class="dashicons dashicons-media-document" style="color:#8e44ad;"></span><h3 style="margin:0;"><?php esc_html_e('Lịch sử gửi Mail', 'sitevorx'); ?></h3></div>
                        <form method="POST" style="margin:0;">
                            <?php wp_nonce_field('sv_smtp_nonce'); ?>
                            <button type="submit" name="sv_clear_logs" class="button" style="color:#d63638; border-color:#d63638;" data-confirm="<?php echo esc_attr(__('Bạn có chắc chắn muốn xóa sạch log?', 'sitevorx')); ?>"><?php esc_html_e('Xóa Log', 'sitevorx'); ?></button>
                        </form>
                    </div>
                    <div style="padding: 20px;">
                        <?php
                        $log_table = sv_smtp_get_log_table_name();
                        $logs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $log_table . '` ORDER BY id DESC LIMIT %d', 100 ) );
                        if (empty($logs)) { echo '<p style="color:#646970;">' . esc_html__('Chưa có dữ liệu log nào.', 'sitevorx') . '</p>'; } else {
                            echo '<table class="wp-list-table widefat striped" style="border: 1px solid #e2e4e7; border-radius: 6px; overflow: hidden;"><thead><tr><th style="padding:10px; font-weight:600;">' . esc_html__('Thời gian', 'sitevorx') . '</th><th style="padding:10px; font-weight:600;">' . esc_html__('Gửi tới', 'sitevorx') . '</th><th style="padding:10px; font-weight:600;">' . esc_html__('Tiêu đề', 'sitevorx') . '</th><th style="padding:10px; font-weight:600;">' . esc_html__('Trạng thái', 'sitevorx') . '</th><th style="padding:10px; font-weight:600;">' . esc_html__('Nguyên nhân lỗi', 'sitevorx') . '</th></tr></thead><tbody>';
                            foreach ($logs as $log) {
                                $status = sv_smtp_normalize_status($log->status);
                                $status_label = sv_smtp_get_status_label($log->status);
                                $color = ('success' === $status) ? '#00a32a' : '#d63638';
                                $error_cell = '';
                                if (!empty($log->error_msg)) {
                                    $error_cell = '<span style="font-size:12px; color:#92400e; background:#fef3c7; padding:4px 8px; border-radius:4px; display:inline-block; max-width:300px; word-break:break-all;">' . esc_html($log->error_msg) . '</span>';
                                } elseif ('success' !== $status) {
                                    $error_cell = '<span style="color:#9ca3af; font-size:12px;">' . esc_html__('Không rõ', 'sitevorx') . '</span>';
                                } else {
                                    $error_cell = '<span style="color:#9ca3af;">—</span>';
                                }
                                echo '<tr><td style="padding:10px;">' . esc_html($log->time) . '</td><td style="padding:10px;">' . esc_html($log->to_email) . '</td><td style="padding:10px; color:#1d2327;"><strong>' . esc_html($log->subject) . '</strong></td><td style="padding:10px; font-weight:600; color:' . $color . ';">' . esc_html($status_label) . '</td><td style="padding:10px;">' . $error_cell . '</td></tr>';
                            }
                            echo '</tbody></table>';
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
    <?php
}
