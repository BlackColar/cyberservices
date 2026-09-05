<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function sv_read_file_tail($file_path, $max_lines = 50, $chunk_size = 8192) {
    if (!is_readable($file_path)) {
        return false;
    }

    $handle = fopen($file_path, 'rb');
    if (!$handle) {
        return false;
    }

    $buffer = '';
    $line_count = 0;
    fseek($handle, 0, SEEK_END);
    $position = ftell($handle);

    while ($position > 0 && $line_count <= $max_lines) {
        $read_size = min($chunk_size, $position);
        $position -= $read_size;
        fseek($handle, $position);
        $buffer = fread($handle, $read_size) . $buffer;
        $line_count = preg_match_all("/\r\n|\n|\r/", $buffer);

        if (strlen($buffer) > 262144) {
            break;
        }
    }

    fclose($handle);

    $lines = preg_split("/\r\n|\n|\r/", $buffer);
    if (!is_array($lines)) {
        return false;
    }

    return implode(PHP_EOL, array_slice($lines, -$max_lines));
}

function sv_get_debug_log_file_path() {
    if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
        return WP_DEBUG_LOG;
    }

    return WP_CONTENT_DIR . '/debug.log';
}

// --- FRONTEND LOGIC CỦA TIỆN ÍCH ---

// 1. Chèn Script Header
add_action('wp_head', function() {
    $header_script = get_option('sv_util_header_script');
    if (!empty($header_script)) echo "\n" . wp_kses( stripslashes($header_script), sv_kses_tracking_tags() ) . "\n";
});

// 2. Chèn Script Footer
add_action('wp_footer', function() {
    $footer_script = get_option('sv_util_footer_script');
    if (!empty($footer_script)) echo "\n" . wp_kses( stripslashes($footer_script), sv_kses_tracking_tags() ) . "\n";
});

// 3. Chống Copy & Bảo vệ nội dung
add_action('wp_enqueue_scripts', function() {
    if (get_option('sv_util_disable_copy') != '1') return;
    $alert_msg = get_option('sv_util_copy_msg', 'Nội dung được bảo vệ bởi Sitevorx!');

    wp_register_script('sitevorx-copy-protect', '', array(), SV_PLUGIN_VERSION, true);
    wp_enqueue_script('sitevorx-copy-protect');
    $inline_js = 'document.addEventListener("contextmenu",function(e){e.preventDefault();alert(' . wp_json_encode($alert_msg) . ');});'
               . 'document.addEventListener("selectstart",function(e){e.preventDefault();});'
               . 'document.addEventListener("dragstart",function(e){e.preventDefault();});';
    wp_add_inline_script('sitevorx-copy-protect', $inline_js);

    wp_register_style('sitevorx-copy-protect', false, array(), SV_PLUGIN_VERSION);
    wp_enqueue_style('sitevorx-copy-protect');
    wp_add_inline_style('sitevorx-copy-protect', 'body{-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;-webkit-touch-callout:none;}');
});

// 4. Chế độ Bảo trì (phải dùng hook để is_user_logged_in() hoạt động chính xác)
if (get_option('sv_util_maintenance') == '1') {
    add_action('template_redirect', function() {
        if ( current_user_can('manage_options') ) return; // Admin vẫn truy cập được
        wp_die('<div style="text-align:center; padding: 50px; font-family: sans-serif;"><h1>' . esc_html__('Website đang được bảo trì', 'sitevorx') . '</h1><p>' . esc_html__('Chúng tôi đang nâng cấp hệ thống. Vui lòng quay lại sau ít phút.', 'sitevorx') . '</p></div>', __('Bảo trì hệ thống', 'sitevorx'), array('response' => 503));
    });
}

// 5. Logo Đăng nhập Custom — dùng URL logo do user nhập trực tiếp
if (get_option('sv_util_custom_login_logo') == '1') {
    add_action('login_enqueue_scripts', function() {
        $logo_url = get_option('sv_util_login_logo_url', '');
        if (empty($logo_url)) return;

        wp_register_style('sitevorx-login-logo', false, array(), SV_PLUGIN_VERSION);
        wp_enqueue_style('sitevorx-login-logo');
        wp_add_inline_style(
            'sitevorx-login-logo',
            '#login h1 a, .login h1 a{background-image:url(' . esc_url_raw($logo_url) . ');width:100%;background-size:contain;background-position:center;height:80px;}'
        );
    });
}

// --- GIAO DIỆN QUẢN TRỊ ADMIN ---
function sv_display_utilities_page() {
    $log_file = sv_get_debug_log_file_path();
    $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'utilities';

    // Xử lý Xóa file Log
    if (current_user_can('manage_options') && isset($_POST['sv_clear_debug_log']) && check_admin_referer('sv_utils_nonce')) {
        if (file_exists($log_file)) {
            file_put_contents($log_file, "");
            echo '<div class="notice notice-success is-dismissible sv-notice"><p>' . esc_html__('Đã làm sạch nội dung file error_log!', 'sitevorx') . '</p></div>';
        }
    }

    // Xử lý Lưu Cấu Hình Tiện ích
    if (current_user_can('manage_options') && isset($_POST['sv_save_utilities']) && check_admin_referer('sv_utils_nonce')) {
        $util_spec = array(
            'sv_util_header_script'     => array( 'label' => __( 'script trong <head>', 'sitevorx' ),       'type' => 'value' ),
            'sv_util_footer_script'     => array( 'label' => __( 'script trước </body>', 'sitevorx' ),      'type' => 'value' ),
            'sv_util_disable_copy'      => array( 'label' => __( 'Chống sao chép nội dung', 'sitevorx' ),    'type' => 'bool' ),
            'sv_util_copy_msg'          => array( 'label' => __( 'thông báo khi chặn sao chép', 'sitevorx' ), 'type' => 'value' ),
            'sv_util_maintenance'       => array( 'label' => __( 'Chế độ Bảo trì', 'sitevorx' ),            'type' => 'bool' ),
            'sv_util_custom_login_logo' => array( 'label' => __( 'Logo đăng nhập tùy chỉnh', 'sitevorx' ),  'type' => 'bool' ),
            'sv_util_login_logo_url'    => array( 'label' => __( 'URL logo đăng nhập', 'sitevorx' ),         'type' => 'value' ),
        );
        $before = array();
        foreach ( $util_spec as $opt_key => $_ ) {
            $before[ $opt_key ] = get_option( $opt_key, '' );
        }

        if (current_user_can('unfiltered_html')) {
            update_option('sv_util_header_script', sv_sanitize_raw_script($_POST['header_script'] ?? ''));
            update_option('sv_util_footer_script', sv_sanitize_raw_script($_POST['footer_script'] ?? ''));
        }
        update_option('sv_util_disable_copy', isset($_POST['disable_copy']) ? '1' : '0');
        update_option('sv_util_copy_msg', sanitize_text_field(wp_unslash($_POST['copy_msg'] ?? '')));
        update_option('sv_util_maintenance', isset($_POST['maintenance_mode']) ? '1' : '0');
        update_option('sv_util_custom_login_logo', isset($_POST['custom_login_logo']) ? '1' : '0');
        update_option('sv_util_login_logo_url', esc_url_raw(wp_unslash($_POST['login_logo_url'] ?? '')));
        if ( function_exists( 'sv_audit_log' ) ) {
            $after = array();
            foreach ( $util_spec as $opt_key => $_ ) {
                $after[ $opt_key ] = get_option( $opt_key, '' );
            }
            $summary = sv_audit_summarize_diff( $before, $after, $util_spec );
            sv_audit_log( 'utilities_saved', array(
                'summary' => $summary !== '' ? $summary : __( 'Lưu lại không có thay đổi', 'sitevorx' ),
            ) );
        }
        echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . esc_html__('Đã lưu cấu hình tiện ích thành công!', 'sitevorx') . '</p></div>';
    }

    // Xử lý Lưu Cấu Hình Liên Hệ
    if (current_user_can('manage_options') && isset($_POST['sv_save_contact']) && check_admin_referer('sv_contact_nonce')) {
        update_option('sv_contact_phone', sanitize_text_field(wp_unslash($_POST['phone'] ?? '')));
        update_option('sv_contact_zalo', sanitize_text_field(wp_unslash($_POST['zalo'] ?? '')));
        update_option('sv_contact_fb', esc_url_raw(wp_unslash($_POST['fb'] ?? '')));
        echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . esc_html__('Đã lưu cấu hình nút liên hệ thành công!', 'sitevorx') . '</p></div>';
    }

    // Đọc file Log
    $log_content = __('Không tìm thấy file error_log hoặc hệ thống chưa ghi nhận lỗi nào.', 'sitevorx');
    if (file_exists($log_file)) {
        $tail = sv_read_file_tail($log_file, 50);
        if ($tail !== false && $tail !== '') {
            $log_content = esc_html($tail);
        } else {
            $log_content = __('File log hiện đang trống. Tuyệt vời!', 'sitevorx');
        }
    }

    ?>
    <!-- Terminal CSS đã chuyển vào sv-admin.css -->

    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar('utilities'); ?>
            <div class="sv-content-area">
                
                <div class="sv-top-banner">
                    <h2><?php esc_html_e('Công Cụ & Tiện Ích Website', 'sitevorx'); ?></h2>
                    <p><?php esc_html_e('Các công cụ mở rộng: chèn mã tracking, bảo vệ nội dung, nút liên hệ nổi và gỡ lỗi hệ thống.', 'sitevorx'); ?></p>
                </div>

                <div class="sv-tabs-nav">
                    <a href="?page=sv-utilities&tab=utilities" class="sv-tab-btn <?php echo $active_tab == 'utilities' ? 'active' : ''; ?>"><?php esc_html_e('Tiện Ích & Script', 'sitevorx'); ?></a>
                    <a href="?page=sv-utilities&tab=contact" class="sv-tab-btn <?php echo $active_tab == 'contact' ? 'active' : ''; ?>"><?php esc_html_e('Nút Liên Hệ Nổi', 'sitevorx'); ?></a>
                </div>

                <?php if ($active_tab == 'utilities') : ?>
                
                <form method="POST">
                    <?php wp_nonce_field('sv_utils_nonce'); ?>
                    
                    <div class="sv-content-box">
                        <div class="sv-box-header"><span class="dashicons dashicons-editor-code" style="color:#0073aa;"></span><h3><?php esc_html_e('Mã Kịch Bản (Script) & Thống Kê', 'sitevorx'); ?></h3></div>
                        <div class="notice notice-warning" style="display:block; margin:0 20px 15px;"><p><?php esc_html_e('Khu vực này render script/raw HTML trực tiếp ở frontend. Chỉ dán mã từ nguồn tin cậy và chỉ cấp quyền này cho quản trị viên đáng tin.', 'sitevorx'); ?></p></div>
                        <div style="padding: 20px; border-bottom: 1px solid #f0f0f1;">
                            <strong style="display:block; margin-bottom:5px; color:#111827;"><?php esc_html_e('Chèn mã vào Header (Phần Đầu Trang)', 'sitevorx'); ?></strong>
                            <p style="font-size:13px; color:#4b5563; margin-bottom:10px;"><?php esc_html_e('Dán mã theo dõi Google Analytics, Facebook Pixel, hoặc mã xác minh chủ sở hữu (Meta Tags) vào đây.', 'sitevorx'); ?></p>
                            <textarea name="header_script" rows="5" style="width:100%; border-radius:8px; border:1px solid #e5e7eb; padding:12px; font-family: monospace; font-size: 13px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);"><?php echo esc_textarea(get_option('sv_util_header_script')); ?></textarea>
                        </div>
                        <div style="padding: 20px;">
                            <strong style="display:block; margin-bottom:5px; color:#111827;"><?php esc_html_e('Chèn mã vào Footer (Phần Chân Trang)', 'sitevorx'); ?></strong>
                            <p style="font-size:13px; color:#4b5563; margin-bottom:10px;"><?php esc_html_e('Dán mã Livechat (Zalo, Tawk.to) vào khu vực này để biểu tượng chat tải sau cùng, không làm chậm tốc độ web.', 'sitevorx'); ?></p>
                            <textarea name="footer_script" rows="5" style="width:100%; border-radius:8px; border:1px solid #e5e7eb; padding:12px; font-family: monospace; font-size: 13px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);"><?php echo esc_textarea(get_option('sv_util_footer_script')); ?></textarea>
                        </div>
                    </div>

                    <div class="sv-content-box">
                        <div class="sv-box-header"><span class="dashicons dashicons-admin-generic" style="color:#27ae60;"></span><h3><?php esc_html_e('Tính Năng Đặc Biệt', 'sitevorx'); ?></h3></div>
                        
                        <div class="sv-form-group">
                            <div class="sv-form-label">
                                <strong><?php esc_html_e('Chống Copy & Ăn Cắp Bài Viết', 'sitevorx'); ?></strong>
                                <p><?php esc_html_e('Tự động chặn bôi đen văn bản, vô hiệu hóa chuột phải và đóng băng hình ảnh để tránh bị người khác copy.', 'sitevorx'); ?></p>
                                <div id="sv_copy_msg_box" style="margin-top: 10px; display: <?php echo get_option('sv_util_disable_copy') == '1' ? 'block' : 'none'; ?>;">
                                    <input type="text" name="copy_msg" value="<?php echo esc_attr(get_option('sv_util_copy_msg', 'Nội dung này đã được đăng ký bản quyền!')); ?>" placeholder="<?php esc_attr_e('Nhập thông báo khi click chuột phải...', 'sitevorx'); ?>" style="width: 100%; max-width: 320px; padding: 8px 12px; font-size: 13px; border-radius: 6px; border: 1px dashed #0073aa;">
                                </div>
                            </div>
                            <div class="sv-form-input">
                                <label class="sv-switch">
                                    <input type="checkbox" name="disable_copy" value="1" <?php checked(get_option('sv_util_disable_copy'), '1'); ?> data-sv-toggle="sv_copy_msg_box">
                                    <span class="sv-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Bật Cửa Cuốn (Chế độ Bảo trì)', 'sitevorx'); ?></strong><p><?php esc_html_e('Tạm thời chặn khách truy cập (hiện thông báo đang nâng cấp) trong lúc bạn đang cấu hình lại web. Quản trị viên vẫn xem được bình thường.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="maintenance_mode" value="1" <?php checked(get_option('sv_util_maintenance'), '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-group" style="border:none;">
                            <div class="sv-form-label">
                                <strong><?php esc_html_e('Đổi Logo Trang Đăng Nhập', 'sitevorx'); ?></strong>
                                <p><?php esc_html_e('Thay thế logo WordPress (chữ W) mặc định nhàm chán bằng hình ảnh logo thương hiệu riêng của bạn.', 'sitevorx'); ?></p>
                                <div id="sv_logo_url_box" style="margin-top: 10px; display: <?php echo get_option('sv_util_custom_login_logo') == '1' ? 'block' : 'none'; ?>;">
                                    <input type="url" name="login_logo_url" value="<?php echo esc_attr(get_option('sv_util_login_logo_url', '')); ?>" placeholder="https://example.com/logo.png" style="width: 100%; max-width: 400px; padding: 6px 12px; font-size: 13px; border-radius: 4px; border: 1px solid #c3c4c7;">
                                    <p style="font-size: 12px; color: #646970; margin-top: 5px;"><?php printf(__('Dán link ảnh logo (PNG/SVG). Lấy từ %sMedia → Thư viện%s → click ảnh → copy URL.', 'sitevorx'), '<strong>', '</strong>'); ?></p>
                                    <?php $preview_url = get_option('sv_util_login_logo_url', ''); if (!empty($preview_url)): ?>
                                        <div style="margin-top: 10px; padding: 15px; background: #f8f9fa; border-radius: 6px; border: 1px dashed #c3c4c7; text-align: center;">
                                            <img src="<?php echo esc_url($preview_url); ?>" alt="Logo Preview" style="max-height: 60px; max-width: 200px;">
                                            <p style="font-size: 11px; color: #00a32a; margin: 5px 0 0; font-weight: 600;"><?php esc_html_e('Logo đã được cài đặt', 'sitevorx'); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="custom_login_logo" value="1" <?php checked(get_option('sv_util_custom_login_logo'), '1'); ?> data-sv-toggle="sv_logo_url_box"><span class="sv-slider"></span></label></div>
                        </div>
                        
                        <div class="sv-form-footer"><button type="submit" name="sv_save_utilities" class="button button-primary"><?php esc_html_e('Lưu cấu hình', 'sitevorx'); ?></button></div>
                    </div>
                </form>

                <form method="POST">
                    <?php wp_nonce_field('sv_utils_nonce'); ?>
                    <div class="sv-content-box" style="margin-bottom:0;">
                        <div class="sv-box-header" style="justify-content: space-between;">
                            <div style="display:flex; align-items:center; gap:10px;"><span class="dashicons dashicons-media-text" style="color:#ef4444;"></span><h3 style="margin:0;"><?php esc_html_e('Nhật Ký Lỗi Code (Error Log)', 'sitevorx'); ?></h3></div>
                            <label class="sv-switch">
                                <input type="checkbox" id="sv_toggle_terminal">
                                <span class="sv-slider"></span>
                            </label>
                        </div>
                        
                        <div id="sv_terminal_wrapper" style="padding: 20px; display: none;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                <p style="font-size:13px; color:#4b5563; margin:0;"><?php printf(__('Màn hình xem trực tiếp %s50 lỗi lập trình gần nhất%s để gửi cho coder hoặc bộ phận kỹ thuật.', 'sitevorx'), '<strong>', '</strong>'); ?></p>
                                <button type="submit" name="sv_clear_debug_log" class="button button-secondary" style="color:#ef4444; border-color:#fca5a5; background: #fef2f2;" data-confirm="<?php echo esc_attr( __( 'Bạn có chắc chắn muốn xóa trống toàn bộ dữ liệu lỗi?', 'sitevorx' ) ); ?>"><?php esc_html_e('Xóa Sạch Lịch Sử Lỗi', 'sitevorx'); ?></button>
                            </div>

                            <div class="sv-terminal">
                                <div class="sv-terminal-header">
                                    <div class="sv-terminal-btn sv-term-red"></div>
                                    <div class="sv-terminal-btn sv-term-yellow"></div>
                                    <div class="sv-terminal-btn sv-term-green"></div>
                                </div>
                                <div class="sv-terminal-content">
                                    <textarea readonly><?php echo $log_content; ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <?php elseif ($active_tab == 'contact') : ?>
                <?php
                $phone = get_option('sv_contact_phone');
                $zalo = get_option('sv_contact_zalo');
                $fb = get_option('sv_contact_fb');
                ?>
                <div class="sv-content-box">
                    <div class="sv-box-header"><span class="dashicons dashicons-phone" style="color:#e67e22;"></span><h3><?php esc_html_e('Nút Gọi Điện & Nhắn Tin Nổi', 'sitevorx'); ?></h3></div>
                    <p style="padding: 0 20px; color:#4b5563;"><?php esc_html_e('Cài đặt các nút liên hệ nổi (Zalo, Messenger, Hotline) góc màn hình giúp khách hàng dễ dàng tiếp cận bạn.', 'sitevorx'); ?></p>
                    <form method="POST">
                        <?php wp_nonce_field('sv_contact_nonce'); ?>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Số điện thoại Gọi ngay', 'sitevorx'); ?></strong><p><?php esc_html_e('Nút sẽ tự động rung rinh thu hút khách.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><input type="text" name="phone" value="<?php echo esc_attr($phone); ?>" placeholder="09xxxxxxx"></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Số Zalo', 'sitevorx'); ?></strong><p><?php esc_html_e('Tự động mở app Zalo khi khách click.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><input type="text" name="zalo" value="<?php echo esc_attr($zalo); ?>" placeholder="09xxxxxxx"></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Link Messenger', 'sitevorx'); ?></strong><p><?php esc_html_e('Đường dẫn m.me Fanpage của bạn.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><input type="text" name="fb" value="<?php echo esc_attr($fb); ?>" placeholder="https://m.me/..."></div>
                        </div>
                        <div class="sv-form-footer"><button type="submit" name="sv_save_contact" class="button button-primary"><?php esc_html_e('Lưu cấu hình', 'sitevorx'); ?></button></div>
                    </form>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
    <?php
}
