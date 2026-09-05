<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================================================================
// BẢO TRÌ & CẬP NHẬT — Theo dõi plugin/theme cần update, cảnh báo bảo mật
// ==========================================================================

add_action( 'wp_ajax_sv_update_plugin', 'sv_ajax_update_plugin' );
add_action( 'wp_ajax_so_update_plugin', 'sv_ajax_update_plugin' );
function sv_ajax_update_plugin() {
    if ( ! current_user_can( 'update_plugins' ) ) {
        wp_send_json_error( array( 'message' => __( 'Bạn không có quyền cập nhật plugin.', 'sitevorx' ) ), 403 );
    }

    check_ajax_referer( 'sv_plugin_update_nonce', 'nonce' );

    $plugin_file = isset( $_POST['plugin'] ) ? plugin_basename( sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) ) : '';
    if ( empty( $plugin_file ) ) {
        wp_send_json_error( array( 'message' => __( 'Thiếu thông tin plugin cần cập nhật.', 'sitevorx' ) ), 400 );
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/update.php';

    wp_clean_plugins_cache( true );
    wp_update_plugins();

    $plugin_updates = get_plugin_updates();
    if ( empty( $plugin_updates[ $plugin_file ] ) || empty( $plugin_updates[ $plugin_file ]->update->new_version ) ) {
        wp_send_json_error( array( 'message' => __( 'Plugin này hiện không còn bản cập nhật hợp lệ.', 'sitevorx' ) ), 400 );
    }

    $target_version = (string) $plugin_updates[ $plugin_file ]->update->new_version;

    ob_start();
    $upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
    $result   = $upgrader->upgrade( $plugin_file );
    ob_end_clean();

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
    }

    $skin_errors = $upgrader->skin->get_errors();
    if ( $skin_errors instanceof WP_Error && $skin_errors->has_errors() ) {
        wp_send_json_error( array( 'message' => $skin_errors->get_error_message() ), 500 );
    }

    if ( false === $result ) {
        wp_send_json_error( array( 'message' => __( 'WordPress không thể hoàn tất quá trình cập nhật plugin.', 'sitevorx' ) ), 500 );
    }

    wp_clean_plugins_cache( true );
    wp_update_plugins();

    $all_plugins      = get_plugins();
    $updated_version  = isset( $all_plugins[ $plugin_file ]['Version'] ) ? (string) $all_plugins[ $plugin_file ]['Version'] : $target_version;
    $remaining_updates = count( get_plugin_updates() );

    wp_send_json_success( array(
        'plugin'          => $plugin_file,
        'version'         => $updated_version,
        'remaining_count' => $remaining_updates,
        'message'         => sprintf( __( 'Đã cập nhật lên phiên bản %s.', 'sitevorx' ), $updated_version ),
    ) );
}

function sv_display_maintenance_check_page() {
    if ( !current_user_can('manage_options') ) {
        wp_die( esc_html__('Bạn không có quyền truy cập trang này.', 'sitevorx') );
    }

    // Load update functions (uses cached transient data, no forced refresh)
    if ( !function_exists('get_plugin_updates') ) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_updates = get_plugin_updates();
    $theme_updates  = get_theme_updates();
    $core_updates   = get_core_updates();

    $wp_version  = get_bloginfo('version');
    $php_version = phpversion();
    $php_ok      = version_compare($php_version, '7.4', '>=');
    $php_rec     = version_compare($php_version, '8.0', '>=');

    $wp_latest      = (!empty($core_updates) && isset($core_updates[0]->current)) ? $core_updates[0]->current : $wp_version;
    $wp_up_to_date  = version_compare($wp_version, $wp_latest, '>=');

    $ssl_active = is_ssl();
    $debug_on   = defined('WP_DEBUG') && WP_DEBUG;
    $editor_off = ( get_option( 'sv_sec_disable_editor', '0' ) === '1' ) || ( defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT );

    // Health score
    $score = 100;
    $issues = array();
    if (!empty($plugin_updates)) {
        $count = count($plugin_updates);
        $score -= min(30, $count * 10);
        $issues[] = sprintf(__('%d plugin cần cập nhật', 'sitevorx'), $count);
    }
    if (!empty($theme_updates)) {
        $count = count($theme_updates);
        $score -= min(20, $count * 10);
        $issues[] = sprintf(__('%d theme cần cập nhật', 'sitevorx'), $count);
    }
    if (!$wp_up_to_date) {
        $score -= 20;
        $issues[] = sprintf(__('WordPress chưa cập nhật (%s → %s)', 'sitevorx'), $wp_version, $wp_latest);
    }
    if (!$php_ok) {
        $score -= 15;
        $issues[] = sprintf(__('PHP %s quá cũ — khuyến nghị nâng lên 8.0+ (không bắt buộc)', 'sitevorx'), $php_version);
    } elseif (!$php_rec) {
        $score -= 5;
        $issues[] = sprintf(__('PHP %s — khuyến nghị 8.0+ để tối ưu hiệu suất (không bắt buộc)', 'sitevorx'), $php_version);
    }
    if (!$ssl_active) {
        $score -= 10;
        $issues[] = __('SSL/HTTPS chưa kích hoạt', 'sitevorx');
    }
    if ($debug_on) {
        $score -= 5;
        $issues[] = __('WP_DEBUG đang bật trên production', 'sitevorx');
    }
    $score = max(0, $score);

    $plugin_count = count($plugin_updates);
    $theme_count  = count($theme_updates);

    ?>
    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar('maintenance-check'); ?>
            <div class="sv-content-area">

                <div class="sv-top-banner">
                    <h2><?php esc_html_e('Bảo Trì & Cập Nhật', 'sitevorx'); ?></h2>
                    <p><?php esc_html_e('Theo dõi trạng thái cập nhật plugin, theme, WordPress Core và các cảnh báo bảo mật quan trọng.', 'sitevorx'); ?></p>
                </div>

                <?php if (!empty($issues)) : ?>
                <!-- Issues Summary -->
                <div class="sv-content-box">
                    <div class="sv-box-header">
                        <span class="dashicons dashicons-warning" style="color:#f59e0b;"></span>
                        <h3><?php echo esc_html(sprintf(__('Phát hiện %d hạng mục cần lưu ý', 'sitevorx'), count($issues))); ?></h3>
                    </div>
                    <?php foreach ($issues as $issue) : ?>
                    <div class="sv-form-group">
                        <div class="sv-form-label" style="display:flex; align-items:center; gap:10px;">
                            <span class="dashicons dashicons-flag" style="color:#f59e0b; font-size:16px; width:16px; height:16px; flex-shrink:0;"></span>
                            <span style="font-size:13px; color:#475569;"><?php echo esc_html($issue); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Environment Checks -->
                <div class="sv-content-box">
                    <div class="sv-box-header">
                        <span class="dashicons dashicons-shield-alt" style="color:#6366f1;"></span>
                        <h3><?php esc_html_e('Kiểm Tra Môi Trường', 'sitevorx'); ?></h3>
                    </div>

                    <!-- WordPress -->
                    <div class="sv-form-group">
                        <div class="sv-form-label">
                            <strong><?php esc_html_e('Phiên bản WordPress', 'sitevorx'); ?></strong>
                            <p><?php echo $wp_up_to_date ? esc_html__('Đã cập nhật mới nhất.', 'sitevorx') : esc_html__('Có phiên bản mới, nên cập nhật sớm.', 'sitevorx'); ?></p>
                        </div>
                        <div class="sv-form-input">
                            <?php if ($wp_up_to_date) : ?>
                                <span style="background:#d4edda; color:#155724; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;"><?php echo esc_html($wp_version); ?></span>
                            <?php else : ?>
                                <span style="background:#fff3cd; color:#856404; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;"><?php echo esc_html($wp_version); ?> → <?php echo esc_html($wp_latest); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- PHP -->
                    <div class="sv-form-group">
                        <div class="sv-form-label">
                            <strong><?php esc_html_e('Phiên bản PHP', 'sitevorx'); ?></strong>
                            <?php if ($php_rec) : ?>
                                <p><?php esc_html_e('Phiên bản được khuyến nghị.', 'sitevorx'); ?></p>
                            <?php elseif ($php_ok) : ?>
                                <p><?php esc_html_e('Khuyến nghị nâng lên PHP 8.0+ để tối ưu hiệu suất. Đây chỉ là gợi ý, không bắt buộc — việc nâng PHP có thể gây xung đột với một số plugin hoặc theme cũ. Hãy kiểm tra tương thích trước khi nâng cấp.', 'sitevorx'); ?></p>
                            <?php else : ?>
                                <p><?php esc_html_e('Phiên bản PHP đã cũ. Khuyến nghị nâng cấp lên 8.0+ nhưng không bắt buộc — hãy kiểm tra tương thích plugin/theme trước khi nâng để tránh xung đột.', 'sitevorx'); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="sv-form-input">
                            <?php if ($php_rec) : ?>
                                <span style="background:#d4edda; color:#155724; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;"><?php echo esc_html($php_version); ?></span>
                            <?php elseif ($php_ok) : ?>
                                <span style="background:#fff3cd; color:#856404; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;"><?php echo esc_html($php_version); ?></span>
                            <?php else : ?>
                                <span style="background:#f8d7da; color:#721c24; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;"><?php echo esc_html($php_version); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- SSL -->
                    <div class="sv-form-group">
                        <div class="sv-form-label">
                            <strong>SSL / HTTPS</strong>
                            <p><?php echo $ssl_active ? esc_html__('Website đã được bảo mật bằng chứng chỉ SSL.', 'sitevorx') : esc_html__('Nên kích hoạt SSL để bảo mật dữ liệu truyền tải.', 'sitevorx'); ?></p>
                        </div>
                        <div class="sv-form-input">
                            <?php if ($ssl_active) : ?>
                                <span style="background:#d4edda; color:#155724; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;"><?php esc_html_e('Đang bật', 'sitevorx'); ?></span>
                            <?php else : ?>
                                <span style="background:#f8d7da; color:#721c24; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;"><?php esc_html_e('Chưa bật', 'sitevorx'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- WP_DEBUG -->
                    <div class="sv-form-group">
                        <div class="sv-form-label">
                            <strong>WP_DEBUG</strong>
                            <p><?php echo $debug_on ? esc_html__('Đang bật chế độ debug. Nên tắt trên môi trường production để tránh lộ thông tin nhạy cảm.', 'sitevorx') : esc_html__('Đã tắt, đúng chuẩn cho môi trường production.', 'sitevorx'); ?></p>
                        </div>
                        <div class="sv-form-input">
                            <?php if (!$debug_on) : ?>
                                <span style="background:#d4edda; color:#155724; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;"><?php esc_html_e('Đã tắt', 'sitevorx'); ?></span>
                            <?php else : ?>
                                <span style="background:#f8d7da; color:#721c24; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;"><?php esc_html_e('Đang bật', 'sitevorx'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- DISALLOW_FILE_EDIT -->
                    <div class="sv-form-group">
                        <div class="sv-form-label">
                            <strong>DISALLOW_FILE_EDIT</strong>
                            <p><?php echo $editor_off ? esc_html__('Trình chỉnh sửa code trong Dashboard đã bị vô hiệu hóa.', 'sitevorx') : esc_html__('Nên thêm define(\'DISALLOW_FILE_EDIT\', true) vào wp-config.php để chặn chỉnh sửa code từ Dashboard.', 'sitevorx'); ?></p>
                        </div>
                        <div class="sv-form-input">
                            <?php if ($editor_off) : ?>
                                <span style="background:#d4edda; color:#155724; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;"><?php esc_html_e('Đang bật', 'sitevorx'); ?></span>
                            <?php else : ?>
                                <span style="background:#fff3cd; color:#856404; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;"><?php esc_html_e('Chưa bật', 'sitevorx'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Plugin Updates -->
                <div class="sv-content-box">
                    <div class="sv-box-header">
                        <span class="dashicons dashicons-admin-plugins" style="color:#8b5cf6;"></span>
                        <h3><?php esc_html_e('Cập Nhật Plugin', 'sitevorx'); ?>
                            <?php if ($plugin_count > 0) : ?>
                            <span id="sv-plugin-update-count" style="background:#fee2e2; color:#dc2626; font-size:11px; padding:2px 8px; border-radius:10px; margin-left:8px; font-weight:600;"><?php echo $plugin_count; ?></span>
                            <?php endif; ?>
                        </h3>
                    </div>
                    <?php if (empty($plugin_updates)) : ?>
                    <div id="sv-plugin-update-empty" class="sv-form-group" style="justify-content:center; text-align:center; padding:30px 20px;">
                        <div>
                            <span class="dashicons dashicons-yes-alt" style="color:#22c55e; font-size:36px; width:36px; height:36px;"></span>
                            <p style="margin:10px 0 0; font-weight:600; color:#22c55e; font-size:14px;"><?php esc_html_e('Tất cả plugin đã cập nhật mới nhất!', 'sitevorx'); ?></p>
                        </div>
                    </div>
                    <?php else : ?>
                        <div id="sv-plugin-update-empty" class="sv-form-group" style="justify-content:center; text-align:center; padding:30px 20px; display:none;">
                            <div>
                                <span class="dashicons dashicons-yes-alt" style="color:#22c55e; font-size:36px; width:36px; height:36px;"></span>
                                <p style="margin:10px 0 0; font-weight:600; color:#22c55e; font-size:14px;"><?php esc_html_e('Tất cả plugin đã cập nhật mới nhất!', 'sitevorx'); ?></p>
                            </div>
                        </div>
                        <?php foreach ($plugin_updates as $file => $plugin) : ?>
                        <div class="sv-form-group sv-plugin-update-item" data-plugin="<?php echo esc_attr( $file ); ?>">
                            <div class="sv-form-label">
                                <strong><?php echo esc_html($plugin->Name); ?></strong>
                                <p class="sv-plugin-update-version"><?php echo esc_html($plugin->Version); ?> → <?php echo esc_html($plugin->update->new_version); ?></p>
                                <p class="sv-plugin-update-status" style="margin:8px 0 0; color:#64748b; font-size:12px;"><?php esc_html_e('Sẵn sàng cập nhật ngay trên trang này.', 'sitevorx'); ?></p>
                            </div>
                            <div class="sv-form-input">
                                <button type="button" class="button button-primary sv-plugin-update-btn" data-plugin="<?php echo esc_attr( $file ); ?>" style="font-size:12px; padding:4px 16px; border-radius:6px;"><?php esc_html_e('Cập nhật', 'sitevorx'); ?></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Theme Updates -->
                <div class="sv-content-box">
                    <div class="sv-box-header">
                        <span class="dashicons dashicons-admin-appearance" style="color:#ec4899;"></span>
                        <h3><?php esc_html_e('Cập Nhật Theme', 'sitevorx'); ?>
                            <?php if ($theme_count > 0) : ?>
                            <span style="background:#fee2e2; color:#dc2626; font-size:11px; padding:2px 8px; border-radius:10px; margin-left:8px; font-weight:600;"><?php echo $theme_count; ?></span>
                            <?php endif; ?>
                        </h3>
                    </div>
                    <?php if (empty($theme_updates)) : ?>
                    <div class="sv-form-group" style="justify-content:center; text-align:center; padding:30px 20px;">
                        <div>
                            <span class="dashicons dashicons-yes-alt" style="color:#22c55e; font-size:36px; width:36px; height:36px;"></span>
                            <p style="margin:10px 0 0; font-weight:600; color:#22c55e; font-size:14px;"><?php esc_html_e('Tất cả theme đã cập nhật mới nhất!', 'sitevorx'); ?></p>
                        </div>
                    </div>
                    <?php else : ?>
                        <?php foreach ($theme_updates as $stylesheet => $theme) :
                            $update_info = $theme->update;
                        ?>
                        <div class="sv-form-group">
                            <div class="sv-form-label">
                                <strong><?php echo esc_html($theme->display('Name')); ?></strong>
                                <p><?php echo esc_html($theme->display('Version')); ?> → <?php echo isset( $update_info['new_version'] ) ? esc_html( $update_info['new_version'] ) : ''; ?></p>
                            </div>
                            <div class="sv-form-input">
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('update.php?action=upgrade-theme&theme=' . urlencode($stylesheet)), 'upgrade-theme_' . $stylesheet)); ?>" class="button button-primary" style="font-size:12px; padding:4px 16px; border-radius:6px;"><?php esc_html_e('Cập nhật', 'sitevorx'); ?></a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
    <?php
}
