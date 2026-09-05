<?php
/**
 * Cyber Services SEO Auditor - Dashboard riêng
 *
 * Menu "Cyber Services SEO" → Dashboard: trạng thái kết nối API, thống kê bài audit,
 * danh sách bài đã audit, log AJAX gần nhất & test scrape nhanh.
 *
 * @package Kira_AI_SA
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kira_SA_Dashboard
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_dashboard_assets'));
        add_action('wp_ajax_kira_sa_dashboard_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_kira_sa_dashboard_test_scrape', array($this, 'ajax_test_scrape'));
    }

    public function register_menu()
    {
        add_menu_page(
            'Cyber Services SEO Auditor',
            'Cyber Services SEO',
            'manage_options',
            'kira-sa-dashboard',
            array($this, 'render_dashboard'),
            'dashicons-search',
            81
        );
    }

    public function enqueue_dashboard_assets($hook)
    {
        if ('toplevel_page_kira-sa-dashboard' !== $hook) {
            return;
        }

        // filemtime() cache-busting — browser luôn tải bản mới nhất khi file thay đổi
        $dash_css_v = file_exists(KIRA_AI_SA_DIR . 'assets/css/kira-ai-auditor.css') ? filemtime(KIRA_AI_SA_DIR . 'assets/css/kira-ai-auditor.css') : KIRA_AI_SA_VERSION;
        $dash_js_v  = file_exists(KIRA_AI_SA_DIR . 'assets/js/kira-ai-dashboard.js') ? filemtime(KIRA_AI_SA_DIR . 'assets/js/kira-ai-dashboard.js') : KIRA_AI_SA_VERSION;

        wp_enqueue_style('kira-sa-dashboard-css', KIRA_AI_SA_URL . 'assets/css/kira-ai-auditor.css', array(), $dash_css_v);
        wp_enqueue_script('kira-sa-dashboard-js', KIRA_AI_SA_URL . 'assets/js/kira-ai-dashboard.js', array('jquery'), $dash_js_v, true);

        wp_localize_script('kira-sa-dashboard-js', 'kira_sa_dashboard_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('kira_sa_dashboard_nonce'),
        ));
    }

    public function ajax_test_api()
    {
        check_ajax_referer('kira_sa_dashboard_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $api_key = get_option('kira_ai_api_key', '');
        $model   = get_option('kira_ai_text_model', 'kira-3.5-flash');

        if (empty($api_key)) {
            wp_send_json_error('Chưa có API Key. Vui lòng cấu hình trong plugin Kira AI trước.');
        }

        $client = new Kira_SA_Api_Client();
        $result = $client->test_connection($api_key, $model);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'model'    => $model,
            'api_key'  => '••••' . substr($api_key, -4),
            'message'  => 'Kết nối Kira AI thành công!',
        ));
    }

    public function ajax_test_scrape()
    {
        check_ajax_referer('kira_sa_dashboard_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if (empty($url)) {
            wp_send_json_error('Vui lòng nhập URL cần test scrape.');
        }

        $scraper = new Kira_SA_Scraper();
        $result = $scraper->scrape_url($url);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'title'        => $result['title'],
            'headings'     => $result['headings'],
            'word_count'   => (int) ($result['word_count'] ?? 0),
            'from_cache'   => !empty($result['from_cache']),
        ));
    }

    public function render_dashboard()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $api_key  = get_option('kira_ai_api_key', '');
        $model    = get_option('kira_ai_text_model', 'kira-3.5-flash');
        $has_key  = !empty($api_key);

        // Stats
        $audited_posts = $this->get_audited_posts();

        $total_outlines = 0;
        $total_keywords = 0;
        $total_missing  = 0;

        foreach ($audited_posts as $audit) {
            $total_outlines += (int) $audit['headings'];
            $total_keywords += (int) $audit['keywords'];
        }

        // Logs
        $logs = get_option('kira_sa_auditor_logs', array());
        if (!is_array($logs)) $logs = array();
        ?>
        <div class="wrap kira-sa-wrap">
            <h1 style="margin-bottom: 6px;">🧠 Cyber Services SEO Auditor — Dashboard</h1>
            <p style="color:#64748b; margin-top:0;">Tổng quan hệ thống, trạng thái kết nối & bài viết đã audit.</p>

            <!-- Connection status -->
            <div class="kira-sa-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:18px 22px; margin-bottom:18px;">
                <h3 style="margin:0 0 12px;">🔌 Trạng thái kết nối Kira AI</h3>
                <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:center;">
                    <span id="kira-sa-conn-indicator" style="display:inline-flex; align-items:center; gap:6px; font-weight:700; border-radius:20px; padding:6px 14px; <?php echo $has_key ? 'background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0;' : 'background:#fef2f2; color:#b91c1c; border:1px solid #fecaca;'; ?>">
                        <?php echo $has_key ? '● Có API Key' : '○ Thiếu API Key'; ?>
                    </span>
                    <span style="color:#475569;">Model: <code><?php echo esc_html($model); ?></code></span>
                    <span style="color:#475569;">Key: <code><?php echo $has_key ? '••••' . substr($api_key, -4) : '—'; ?></code></span>
                    <button type="button" id="kira-sa-test-api-btn" class="button button-primary">🔄 Kiểm tra kết nối</button>
                    <span id="kira-sa-test-api-status" style="font-weight:600;"></span>
                </div>
            </div>

            <!-- Stats cards -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:18px;">
                <div class="kira-sa-stat" style="background:#fff; border:1px solid #e2e8f0; border-left:4px solid #ea580c; border-radius:10px; padding:16px 18px;">
                    <div style="font-size:28px; font-weight:800; color:#0f172a;"><?php echo count($audited_posts); ?></div>
                    <div style="color:#64748b; font-size:13px;">Bài đã audit (dàn ý/từ khóa)</div>
                </div>
                <div class="kira-sa-stat" style="background:#fff; border:1px solid #e2e8f0; border-left:4px solid #22c55e; border-radius:10px; padding:16px 18px;">
                    <div style="font-size:28px; font-weight:800; color:#0f172a;"><?php echo $total_outlines; ?></div>
                    <div style="color:#64748b; font-size:13px;">Heading trong dàn ý</div>
                </div>
                <div class="kira-sa-stat" style="background:#fff; border:1px solid #e2e8f0; border-left:4px solid #f59e0b; border-radius:10px; padding:16px 18px;">
                    <div style="font-size:28px; font-weight:800; color:#0f172a;"><?php echo $total_keywords; ?></div>
                    <div style="color:#64748b; font-size:13px;">Từ khóa đang theo dõi</div>
                </div>
                <div class="kira-sa-stat" style="background:#fff; border:1px solid #e2e8f0; border-left:4px solid #3b82f6; border-radius:10px; padding:16px 18px;">
                    <div style="font-size:28px; font-weight:800; color:#0f172a;"><?php echo count($logs); ?></div>
                    <div style="color:#64748b; font-size:13px;">Log hoạt động gần nhất</div>
                </div>
            </div>

            <!-- Quick test scrape -->
            <div class="kira-sa-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:18px 22px; margin-bottom:18px;">
                <h3 style="margin:0 0 12px;">🧪 Test Scrape nhanh (không cần mở bài viết)</h3>
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <input type="url" id="kira-sa-test-url" placeholder="https://doithu.com/bai-viet..." style="flex:1; min-width:260px; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;" />
                    <button type="button" id="kira-sa-test-scrape-btn" class="button button-secondary">Scrape</button>
                </div>
                <div id="kira-sa-test-scrape-result" style="margin-top:12px; font-size:13px; color:#334155;"></div>
            </div>

            <!-- Audited posts table -->
            <div class="kira-sa-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:18px 22px;">
                <h3 style="margin:0 0 12px;">📄 Bài viết đã audit</h3>
                <?php if (empty($audited_posts)): ?>
                    <p style="color:#94a3b8; font-style:italic;">Chưa có bài nào được audit. Mở 1 bài viết → "Cyber Services SEO Auditor" → tạo dàn ý.</p>
                <?php else: ?>
                    <table class="widefat striped" style="border:1px solid #e2e8f0;">
                        <thead>
                            <tr>
                                <th>Tiêu đề</th>
                                <th>Heading</th>
                                <th>Từ khóa</th>
                                <th>Chủ đề thiếu</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audited_posts as $audit): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($audit['title']); ?></strong></td>
                                    <td><?php echo (int) $audit['headings']; ?></td>
                                    <td><?php echo (int) $audit['keywords']; ?></td>
                                    <td><?php echo (int) $audit['missing']; ?></td>
                                    <td><a href="<?php echo esc_url(get_edit_post_link($audit['id'])); ?>" class="button button-small">✏️ Mở bài viết</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Logs -->
            <div class="kira-sa-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:18px 22px; margin-top:18px;">
                <h3 style="margin:0 0 12px;">📜 Log hoạt động gần nhất</h3>
                <?php if (empty($logs)): ?>
                    <p style="color:#94a3b8; font-style:italic;">Chưa có hoạt động nào được ghi lại.</p>
                <?php else: ?>
                    <table class="widefat striped" style="border:1px solid #e2e8f0;">
                        <thead>
                            <tr><th>Thời gian</th><th>Loại</th><th>Trạng thái</th><th>Chi tiết</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($logs, 0, 12) as $log): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo esc_html($log['time'] ?? ''); ?></td>
                                    <td><?php echo esc_html($log['type'] ?? ''); ?></td>
                                    <td>
                                        <?php if (!empty($log['success'])): ?>
                                            <span style="color:#16a34a; font-weight:700;">✓ Thành công</span>
                                        <?php else: ?>
                                            <span style="color:#dc2626; font-weight:700;">✕ Thất bại</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($log['detail'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function get_audited_posts()
    {
        $posts = get_posts(array(
            'numberposts' => 50,
            'post_type'   => 'any',
            'post_status' => 'any',
            'meta_key'    => '_kira_ai_auditor_outline',
        ));

        $result = array();
        foreach ($posts as $post) {
            $outline = get_post_meta($post->ID, '_kira_ai_auditor_outline', true);
            $keywords = get_post_meta($post->ID, '_kira_ai_auditor_keywords', true);
            $missing = get_post_meta($post->ID, '_kira_ai_auditor_missing_topics', true);

            $result[] = array(
                'id'       => $post->ID,
                'title'    => $post->post_title ? $post->post_title : '(không tiêu đề)',
                'headings' => is_array($outline) ? count($outline) : 0,
                'keywords' => is_array($keywords) ? count($keywords) : 0,
                'missing'  => is_array($missing) ? count($missing) : 0,
            );
        }

        return $result;
    }

    /**
     * Ghi log hoạt động auditor (được gọi từ AJAX trong Auditor).
     *
     * @param string $type
     * @param bool   $success
     * @param string $detail
     */
    public static function log($type, $success, $detail)
    {
        $logs = get_option('kira_sa_auditor_logs', array());
        if (!is_array($logs)) $logs = array();
        array_unshift($logs, array(
            'time'    => current_time('Y-m-d H:i:s'),
            'type'    => $type,
            'success' => (bool) $success,
            'detail'  => mb_substr($detail, 0, 200, 'UTF-8'),
        ));
        if (count($logs) > 30) {
            $logs = array_slice($logs, 0, 30);
        }
        update_option('kira_sa_auditor_logs', $logs, false);
    }
}