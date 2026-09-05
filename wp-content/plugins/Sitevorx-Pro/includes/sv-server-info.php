<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function sv_display_server_info_page() {
    global $wpdb;
    
    $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __('Không xác định', 'sitevorx');
    $php_version = phpversion();
    $mysql_version = $wpdb->db_version();
    
    $memory_limit = ini_get('memory_limit');
    $max_execution_time = ini_get('max_execution_time');
    $max_input_vars = ini_get('max_input_vars');
    $post_max_size = ini_get('post_max_size');
    $upload_max_filesize = ini_get('upload_max_filesize');
    
    $db_size = $wpdb->get_var($wpdb->prepare("SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s", DB_NAME));
    $formatted_db_size = size_format($db_size);

    $extensions = get_loaded_extensions();
    natcasesort($extensions); 
    ?>
    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar('server-info'); ?>
            <div class="sv-content-area">
                
                <div class="sv-top-banner">
                    <h2><?php esc_html_e('Thông Số Máy Chủ & Hệ Thống', 'sitevorx'); ?></h2>
                    <p><?php esc_html_e('Xem nhanh các giới hạn cấu hình môi trường Hosting/VPS để tối ưu hoá chức năng hoặc làm việc với kỹ thuật viên.', 'sitevorx'); ?></p>
                </div>

                <div class="sv-content-box">
                    <div class="sv-box-header"><span class="dashicons dashicons-database" style="color:#0073aa;"></span><h3><?php esc_html_e('Môi trường Máy chủ', 'sitevorx'); ?></h3></div>
                    <div class="sv-form-group"><div class="sv-form-label"><strong>Web Server</strong></div><div class="sv-form-input"><code style="width:auto;"><?php echo esc_html($server_software); ?></code></div></div>
                    <div class="sv-form-group"><div class="sv-form-label"><strong><?php esc_html_e('Phiên bản PHP', 'sitevorx'); ?></strong></div><div class="sv-form-input"><strong style="color:#00a32a;"><?php echo esc_html($php_version); ?></strong></div></div>
                    <div class="sv-form-group"><div class="sv-form-label"><strong><?php esc_html_e('Phiên bản MySQL/MariaDB', 'sitevorx'); ?></strong></div><div class="sv-form-input"><span><?php echo esc_html($mysql_version); ?></span></div></div>
                    <div class="sv-form-group"><div class="sv-form-label"><strong><?php esc_html_e('Dung lượng Database', 'sitevorx'); ?></strong><p><?php esc_html_e('Tổng kích thước thực tế của Cơ sở dữ liệu.', 'sitevorx'); ?></p></div><div class="sv-form-input"><strong style="color: #d63638; font-size: 16px;"><?php echo esc_html($formatted_db_size); ?></strong></div></div>
                </div>

                <div class="sv-content-box">
                    <div class="sv-box-header"><span class="dashicons dashicons-performance" style="color:#f39c12;"></span><h3><?php esc_html_e('Giới hạn cấu hình PHP (PHP Limits)', 'sitevorx'); ?></h3></div>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; padding: 20px;">
                        <div style="padding: 15px; background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 6px; display: flex; justify-content: space-between;"><span style="color: #646970; font-weight: 600;">PHP Memory Limit</span><strong style="color: #1d2327; font-size: 15px;"><?php echo esc_html($memory_limit); ?></strong></div>
                        <div style="padding: 15px; background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 6px; display: flex; justify-content: space-between;"><span style="color: #646970; font-weight: 600;">Max Execution Time</span><strong style="color: #1d2327; font-size: 15px;"><?php echo esc_html($max_execution_time); ?>s</strong></div>
                        <div style="padding: 15px; background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 6px; display: flex; justify-content: space-between;"><span style="color: #646970; font-weight: 600;">Max Input Vars</span><strong style="color: #1d2327; font-size: 15px;"><?php echo esc_html($max_input_vars); ?></strong></div>
                        <div style="padding: 15px; background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 6px; display: flex; justify-content: space-between;"><span style="color: #646970; font-weight: 600;">Upload / Post Max Size</span><strong style="color: #1d2327; font-size: 15px;"><?php echo esc_html($upload_max_filesize); ?> / <?php echo esc_html($post_max_size); ?></strong></div>
                    </div>
                </div>

                <div class="sv-content-box">
                    <div class="sv-box-header"><span class="dashicons dashicons-admin-plugins" style="color:#8e44ad;"></span><h3>PHP Extensions</h3></div>
                    <div style="padding: 20px;">
                        <?php 
                        $important_exts = array('curl', 'mbstring', 'zip', 'gd', 'mysqli', 'imagick', 'exif', 'fileinfo', 'zlib', 'openssl');
                        foreach ($extensions as $ext) {
                            $is_important = in_array(strtolower($ext), $important_exts);
                            echo '<span style="display: inline-block; background: '.($is_important ? '#e8f5e9' : '#f0f6fc').'; color: '.($is_important ? '#2e7d32' : '#0073aa').'; border: 1px solid '.($is_important ? '#c8e6c9' : '#c3c4c7').'; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin: 0 8px 8px 0;">' . esc_html($ext) . ($is_important ? ' <span class="dashicons dashicons-yes" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>' : '') . '</span>';
                        }
                        ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php
}