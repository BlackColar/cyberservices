<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================================================================
// WP-CRON SCHEDULED CLEANUP
// ==========================================================================

// Register/deregister cron on setting change
add_action('admin_init', function() {
    if (!isset($_POST['sv_save_cron_settings'])) return;
    if (!current_user_can('manage_options')) return;
    if (!check_admin_referer('sv_cron_nonce')) return;

    $enabled = isset($_POST['cron_enabled']) ? '1' : '0';
    $frequency = sv_get_valid_cleanup_frequency(sanitize_text_field(wp_unslash($_POST['cron_frequency'] ?? '')));

    update_option('sv_cron_cleanup_enabled', $enabled);
    update_option('sv_cron_cleanup_frequency', $frequency);

    sv_sync_cleanup_schedule();

    if ( function_exists( 'sv_audit_log' ) ) {
        sv_audit_log( 'cleanup_scheduled', array( 'enabled' => $enabled, 'frequency' => $frequency ) );
    }

    add_action('admin_notices', function() {
        echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . esc_html__('Đã lưu thiết lập Dọn dẹp tự động thành công!', 'sitevorx') . '</p></div>';
    });
});

// Register weekly schedule (WP doesn't have it by default)
add_filter('cron_schedules', function($schedules) {
    $schedules['weekly'] = array(
        'interval' => 604800,
        'display' => __('Hàng tuần', 'sitevorx')
    );
    return $schedules;
});

// The actual cleanup action
add_action('sv_scheduled_cleanup_event', 'sv_run_auto_cleanup');
function sv_run_auto_cleanup() {
    global $wpdb;

    $cleaned = [];

    // 1. Xóa revisions dư (giữ lại N bản mới nhất mỗi bài). Việc dọn tự động
    //    KHÔNG được xóa sạch toàn bộ revision — như vậy sẽ mâu thuẫn với tùy chọn
    //    "giữ tối đa N revision" của trình tối ưu và làm mất lịch sử biên tập.
    //    Batch để tránh khóa bảng lâu.
    $total_rev = 0;
    $keep_rev  = (int) apply_filters( 'sv_cleanup_keep_revisions', 5 );
    do {
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' AND ID IN (
                SELECT ID FROM (
                    SELECT r.ID FROM {$wpdb->posts} r
                    WHERE r.post_type = 'revision'
                    AND ( SELECT COUNT(*) FROM {$wpdb->posts} r2
                          WHERE r2.post_parent = r.post_parent AND r2.post_type = 'revision' AND r2.ID > r.ID ) >= %d
                    LIMIT 500
                ) AS batch
            )",
            $keep_rev
        ) );
        if ($deleted > 0) $total_rev += $deleted;
        if ($deleted >= 500) usleep(100000); // 100ms pause between batches
    } while ($deleted >= 500);
    if ($total_rev > 0) $cleaned[] = "$total_rev bản nháp";

    // 2. Xóa spam + trash comments
    $spam = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved IN ('spam', 'trash')");
    if ($spam > 0) $cleaned[] = "$spam bình luận rác";

    // 3. Xóa expired transients (cách an toàn, tương thích MySQL cũ)
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%%' AND option_value < %d", time()));
    $wpdb->query("DELETE a FROM {$wpdb->options} a LEFT JOIN {$wpdb->options} b ON CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12)) = b.option_name WHERE a.option_name LIKE '_transient_%' AND a.option_name NOT LIKE '_transient_timeout_%' AND b.option_name IS NULL");

    // 4. Xóa auto-drafts
    $drafts = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    if ($drafts > 0) $cleaned[] = "$drafts bản nháp tự động";

    // 5. Optimize tables (chỉ optimize tables có prefix của site này)
    $max_optimize_table_bytes = (int) apply_filters( 'sv_cleanup_optimize_max_table_bytes', 500 * 1024 * 1024 );
    $skipped_large_tables = 0;
    $tables = $wpdb->get_results(
        $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($wpdb->prefix) . '%'),
        ARRAY_N
    );
    foreach ($tables as $table) {
        $table_name = $table[0];
        // Chỉ cho phép tên bảng hợp lệ (chặn SQL injection qua tên bảng)
        if (preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
            // Use information_schema with an exact TABLE_NAME match. The old
            // `SHOW TABLE STATUS LIKE %s` treated `_` in the table name as a
            // wildcard (every WP prefix contains one), so it returned an
            // arbitrary matching table and guarded the WRONG table's size.
            $table_size = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT (DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table_name
            ) );
            if ( $max_optimize_table_bytes > 0 && $table_size > $max_optimize_table_bytes ) {
                $skipped_large_tables++;
                continue;
            }
            $wpdb->query("OPTIMIZE TABLE `{$table_name}`");
        }
    }

    if ($skipped_large_tables > 0) $cleaned[] = sprintf(__('%d bảng lớn bỏ qua tối ưu', 'sitevorx'), $skipped_large_tables);

    // Log result
    $log_entry = wp_date('Y-m-d H:i:s') . ' — ' . __('Dọn dẹp tự động:', 'sitevorx') . ' ' . (!empty($cleaned) ? implode(', ', $cleaned) : __('Không có gì cần dọn', 'sitevorx')) . '. ' . __('Database đã tối ưu.', 'sitevorx');
    $logs = get_option('sv_cron_cleanup_logs', []);
    array_unshift($logs, $log_entry);
    $logs = array_slice($logs, 0, 20); // Giữ 20 log gần nhất
    update_option('sv_cron_cleanup_logs', $logs);
}

// Cleanup on plugin deactivation
register_deactivation_hook(defined('SV_PLUGIN_FILE') ? SV_PLUGIN_FILE : SV_PLUGIN_DIR . 'sitevorx.php', function() {
    wp_clear_scheduled_hook('sp_scheduled_cleanup_event');
    wp_clear_scheduled_hook('so_scheduled_cleanup_event');
    wp_clear_scheduled_hook('sv_scheduled_cleanup_event');
});

// ==========================================================================
// ADMIN UI — Integrated into Optimizer page? No, separate sub-section.
// We'll add a settings section accessible from the Optimizer page or standalone.
// For now, let's hook into the optimizer page by adding a tab.
// Actually, let's just add cron controls to a dedicated section in system-optimizer.
// ==========================================================================

// Add cron tab to optimizer
add_action('admin_init', function() {
    if (!isset($_POST['sv_run_manual_cleanup'])) return;
    if (!current_user_can('manage_options')) return;
    if (!check_admin_referer('sv_cron_nonce')) return;
    sv_run_auto_cleanup();
});

// Render cron settings (called from system-optimizer or standalone)
function sv_render_cron_settings() {
    $enabled = get_option('sv_cron_cleanup_enabled', '0');
    $frequency = get_option('sv_cron_cleanup_frequency', 'weekly');
    $next_run = wp_next_scheduled('sv_scheduled_cleanup_event');
    $logs = get_option('sv_cron_cleanup_logs', []);

    $freq_labels = ['daily' => __('Hàng ngày', 'sitevorx'), 'twicedaily' => __('2 lần/ngày', 'sitevorx'), 'weekly' => __('Hàng tuần', 'sitevorx')];
    ?>
    <form method="POST">
        <?php wp_nonce_field('sv_cron_nonce'); ?>
        <div class="sv-content-box">
            <div class="sv-box-header">
                <span class="dashicons dashicons-clock" style="color:#10b981;"></span>
                <h3><?php esc_html_e('Bảo Trì & Dọn Rác Tự Động Định Kỳ', 'sitevorx'); ?></h3>
                <?php if ($enabled === '1') : ?>
                    <span class="sv-cron-status sv-cron-active">● <?php esc_html_e('ĐANG CHẠY', 'sitevorx'); ?></span>
                <?php else : ?>
                    <span class="sv-cron-status sv-cron-inactive">● <?php esc_html_e('Chưa bật', 'sitevorx'); ?></span>
                <?php endif; ?>
            </div>
            <div class="sv-form-group">
                <div class="sv-form-label">
                    <strong><?php esc_html_e('Kích hoạt dọn dẹp rác máy chủ', 'sitevorx'); ?></strong>
                    <p><?php esc_html_e('Hệ thống tự động dò tìm và xóa bỏ bản nháp cũ, bình luận rác và tối ưu lại cơ sở dữ liệu để web luôn phản hồi nhanh nhất.', 'sitevorx'); ?></p>
                </div>
                <div class="sv-form-input">
                    <label class="sv-switch">
                        <input type="checkbox" name="cron_enabled" value="1" <?php checked($enabled, '1'); ?>>
                        <span class="sv-slider"></span>
                    </label>
                </div>
            </div>
            <div class="sv-form-group">
                <div class="sv-form-label">
                    <strong><?php esc_html_e('Tần suất', 'sitevorx'); ?></strong>
                    <p><?php esc_html_e('Chọn khoảng thời gian giữa các lần dọn dẹp tự động.', 'sitevorx'); ?>
                    <?php if ($next_run) : ?>
                        <br><small style="color:#16a085;"><?php esc_html_e('Lần chạy tiếp theo:', 'sitevorx'); ?> <strong><?php echo wp_date('d/m/Y H:i:s', $next_run); ?></strong></small>
                    <?php endif; ?>
                    </p>
                </div>
                <div class="sv-form-input">
                    <select name="cron_frequency" style="padding:8px 12px; border-radius:6px; border:1px solid #c3c4c7; min-width:140px;">
                        <?php foreach ($freq_labels as $val => $label) : ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($frequency, $val); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="sv-form-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <button type="submit" name="sv_run_manual_cleanup" class="button" style="color:#d63638; border-color:#d63638;">▶ <?php esc_html_e('Chạy dọn dẹp ngay', 'sitevorx'); ?></button>
                <button type="submit" name="sv_save_cron_settings" class="button button-primary"><?php esc_html_e('Lưu thiết lập', 'sitevorx'); ?></button>
            </div>
        </div>
    </form>

    <?php if (!empty($logs)) : ?>
    <div class="sv-content-box">
        <div class="sv-box-header">
            <span class="dashicons dashicons-media-text" style="color:#8e44ad;"></span>
            <h3><?php esc_html_e('Nhật ký Dọn dẹp Tự động', 'sitevorx'); ?></h3>
        </div>
        <div style="padding: 15px 20px; max-height: 250px; overflow-y: auto;">
            <?php foreach ($logs as $log) : ?>
                <div style="padding: 8px 0; border-bottom: 1px solid #f0f0f1; font-size: 13px; color: #3c434a;">
                    <span class="dashicons dashicons-yes-alt" style="color:#00a32a; font-size:14px; vertical-align:middle;"></span>
                    <?php echo esc_html($log); ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif;
}
