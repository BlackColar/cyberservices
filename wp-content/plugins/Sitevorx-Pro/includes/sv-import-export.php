<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function sv_display_import_export_page() {
    // Check reset success
    if (isset($_GET['reset']) && sanitize_key(wp_unslash($_GET['reset'])) === 'done') {
        echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>✅ ' . esc_html__('Đã khôi phục tất cả thiết lập về mặc định thành công!', 'sitevorx') . '</p></div>';
    }

    // === EXPORT ===
    $export_keys = [
        'sv_active_mailer', 'sv_gmail_user', 'sv_gmail_pass',
        'sv_smtp_host', 'sv_smtp_port', 'sv_smtp_user', 'sv_smtp_pass',
        'sv_smtp_from_name', 'sv_smtp_force_email', 'sv_smtp_force_name', 'sv_smtp_enable_log',
        'sv_opt_allow_svg', 'sv_opt_limit_revisions', 'sv_opt_disable_heartbeat',
        'sv_opt_lazy_load',
        'sv_sec_enable_login_key', 'sv_sec_login_key',
        'sv_sec_disable_editor', 'sv_sec_disable_xmlrpc',
        'sv_sec_enable_recaptcha', 'sv_sec_recaptcha_site_key', 'sv_sec_recaptcha_secret_key',
        'sv_sec_limit_login',
        'sv_util_header_script', 'sv_util_footer_script',
        'sv_util_disable_copy', 'sv_util_copy_msg',
        'sv_util_maintenance', 'sv_util_custom_login_logo', 'sv_util_login_logo_url',
        'sv_contact_phone', 'sv_contact_zalo', 'sv_contact_fb',
        'sv_cron_cleanup_enabled', 'sv_cron_cleanup_frequency',
        'sv_toolkit_language',
    ];

    // Sensitive keys to exclude from export
    $sensitive_keys = ['sv_gmail_pass', 'sv_smtp_pass', 'sv_sec_login_key', 'sv_sec_recaptcha_secret_key'];

    $export_data = [];
    foreach ($export_keys as $key) {
        if (in_array($key, $sensitive_keys, true)) continue;
        $val = get_option($key, null);
        if ($val !== null) {
            $export_data[$key] = $val;
        }
    }
    $export_json = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // === IMPORT ===
    if (isset($_POST['sv_import_settings']) && check_admin_referer('sv_ie_nonce') && current_user_can('manage_options')) {
        $import_json_raw = isset($_POST['import_data']) ? wp_unslash($_POST['import_data']) : '';
        $import_json = is_string($import_json_raw) ? wp_check_invalid_utf8($import_json_raw) : '';
        $import_data = json_decode($import_json, true);
        if (is_array($import_data) && !empty($import_data)) {
            $legacy_key_map = array();
            foreach ($export_keys as $current_key) {
                if (0 !== strpos($current_key, 'sv_')) {
                    continue;
                }

                $suffix = substr($current_key, 3);
                $legacy_key_map['sp_' . $suffix] = $current_key;
                $legacy_key_map['so_' . $suffix] = $current_key;
                $legacy_key_map['inet_' . $suffix] = $current_key;
            }

            $count = 0;
            foreach ($import_data as $key => $value) {
                if (isset($legacy_key_map[$key])) {
                    $key = $legacy_key_map[$key];
                }

                if (in_array($key, $export_keys, true)) {
                    // Reject non-scalar values (arrays, objects, etc.)
                    if (!is_scalar($value) && !is_bool($value)) {
                        continue;
                    }

                    if (strpos($key, '_script') !== false) {
                        // Script fields require unfiltered_html capability
                        if (!current_user_can('unfiltered_html')) {
                            continue;
                        }
                        $value = is_string($value) ? $value : '';
                    } elseif (in_array($key, ['sv_gmail_user', 'sv_smtp_user'], true)) {
                        $value = sanitize_email((string) $value);
                    } elseif (in_array($key, ['sv_contact_fb', 'sv_util_login_logo_url'], true)) {
                        $value = esc_url_raw((string) $value);
                    } elseif ($key === 'sv_smtp_port') {
                        $value = absint($value);
                        if (!in_array($value, [25, 465, 587], true)) {
                            $value = 465;
                        }
                    } elseif ($key === 'sv_active_mailer') {
                        $value = in_array($value, ['gmail', 'other'], true) ? $value : '';
                    } elseif ($key === 'sv_toolkit_language') {
                        $value = in_array($value, ['vi', 'en_US'], true) ? $value : 'vi';
                    } elseif ($key === 'sv_sec_login_key') {
                        $value = sanitize_key((string) $value);
                    } elseif (in_array($key, [
                        'sv_smtp_force_email', 'sv_smtp_force_name', 'sv_smtp_enable_log',
                        'sv_opt_allow_svg', 'sv_opt_limit_revisions', 'sv_opt_disable_heartbeat',
                        'sv_opt_lazy_load',
                        'sv_sec_enable_login_key', 'sv_sec_disable_editor', 'sv_sec_disable_xmlrpc',
                        'sv_sec_enable_recaptcha', 'sv_sec_limit_login',
                        'sv_util_disable_copy', 'sv_util_maintenance', 'sv_util_custom_login_logo',
                        'sv_cron_cleanup_enabled',
                    ], true)) {
                        $value = !empty($value) ? '1' : '0';
                    } else {
                        $value = sanitize_text_field((string) $value);
                    }
                    update_option($key, $value);
                    $count++;
                }
            }
            sv_sync_cleanup_schedule();
            if ( function_exists( 'sv_audit_log' ) ) {
                sv_audit_log( 'settings_import', array( 'count' => $count ) );
            }
            echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . sprintf(__('Nhập thành công %s thiết lập!', 'sitevorx'), '<strong>' . $count . '</strong>') . '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible sv-notice" style="display:none;"><p>' . esc_html__('Dữ liệu JSON không hợp lệ.', 'sitevorx') . '</p></div>';
        }
    }
    ?>
    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar('import-export'); ?>
            <div class="sv-content-area">
                
                <div class="sv-top-banner">
                    <h2><?php esc_html_e('Xuất / Nhập Cấu Hình', 'sitevorx'); ?></h2>
                    <p><?php esc_html_e('Sao lưu toàn bộ thiết lập của Sitevorx và di chuyển sang website khác một cách nhanh chóng.', 'sitevorx'); ?></p>
                </div>

                <div class="sv-import-export-grid">
                    <!-- EXPORT -->
                    <div class="sv-ie-card">
                        <span class="dashicons dashicons-upload" style="color: #16a085;"></span>
                        <h3 style="font-size: 18px; font-weight: 700; margin: 0 0 8px 0; color: #1d2327;"><?php esc_html_e('Xuất Cấu Hình', 'sitevorx'); ?></h3>
                        <p style="font-size: 13px; color: #646970; margin: 0 0 20px 0;"><?php esc_html_e('Sao chép hoặc tải xuống toàn bộ thiết lập hiện tại dưới dạng JSON.', 'sitevorx'); ?></p>
                        <textarea id="sv_export_data" readonly style="width:100%; height:150px; font-family:monospace; font-size:12px; border:1px solid #c3c4c7; border-radius:6px; padding:10px; background:#f8f9fa; resize:vertical; margin-bottom:15px;"><?php echo esc_textarea($export_json); ?></textarea>
                        <div style="display:flex; gap:10px; justify-content:center;">
                            <button type="button" class="button button-primary" data-sv-copy-export="sv_export_data">📋 <?php esc_html_e('Sao chép', 'sitevorx'); ?></button>
                            <button type="button" class="button" data-sv-download-export="sv_export_data">💾 <?php esc_html_e('Tải xuống', 'sitevorx'); ?></button>
                        </div>
                    </div>

                    <!-- IMPORT -->
                    <div class="sv-ie-card">
                        <span class="dashicons dashicons-download" style="color: #e67e22;"></span>
                        <h3 style="font-size: 18px; font-weight: 700; margin: 0 0 8px 0; color: #1d2327;"><?php esc_html_e('Nhập Cấu Hình', 'sitevorx'); ?></h3>
                        <p style="font-size: 13px; color: #646970; margin: 0 0 20px 0;"><?php esc_html_e('Dán nội dung JSON đã xuất từ website khác vào đây để nhập nhanh.', 'sitevorx'); ?></p>
                        <form method="POST">
                            <?php wp_nonce_field('sv_ie_nonce'); ?>
                            <textarea name="import_data" placeholder='<?php esc_attr_e('Dán nội dung JSON vào đây...', 'sitevorx'); ?>' style="width:100%; height:150px; font-family:monospace; font-size:12px; border:1px solid #c3c4c7; border-radius:6px; padding:10px; resize:vertical; margin-bottom:15px;"></textarea>
                            <button type="submit" name="sv_import_settings" class="button button-primary" style="width:100%;">📥 <?php esc_html_e('Nhập Cấu Hình', 'sitevorx'); ?></button>
                        </form>
                    </div>
                </div>

                <!-- RESET -->
                <div class="sv-content-box" style="margin-top: 25px;">
                    <div class="sv-box-header">
                        <span class="dashicons dashicons-warning" style="color:#d63638;"></span>
                        <h3><?php esc_html_e('Khôi Phục Mặc Định', 'sitevorx'); ?></h3>
                    </div>
                    <div class="sv-form-group" style="border: none;">
                        <div class="sv-form-label">
                            <strong><?php esc_html_e('Xóa toàn bộ thiết lập', 'sitevorx'); ?></strong>
                            <p><?php esc_html_e('Hành động này sẽ xóa tất cả cấu hình của Sitevorx và đưa về trạng thái ban đầu. Không thể hoàn tác!', 'sitevorx'); ?></p>
                        </div>
                        <div class="sv-form-input">
                            <form method="POST">
                                <?php wp_nonce_field('sv_reset_nonce'); ?>
                                <input type="hidden" name="sv_action" value="reset_all">
                                <button type="submit" class="button" style="color:#d63638; border-color:#d63638;" data-confirm="<?php echo esc_attr( __( 'Bạn có chắc chắn muốn xóa TẤT CẢ thiết lập? Hành động này không thể hoàn tác!', 'sitevorx' ) ); ?>"><?php esc_html_e('Khôi phục mặc định', 'sitevorx'); ?></button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php
}
