<?php
/**
 * Plugin Name: Cyber Services Content
 * Plugin URI: https://cyberservices.vn/
 * Description: Plugin tích hợp Kira AI API để sinh nội dung, viết bài hàng loạt, hẹn giờ xuất bản, viết lại tiêu đề, bài viết, tạo ảnh đại diện cho Post Types và Taxonomies.
 * Version: 1.3.0
 * Author: Cyber Services
 * Author URI: https://cyberservices.vn/
 * License: GPLv2 or later
 * Text Domain: cyber-services-content
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kira_AI
{
    private static $instance = null;
    const LOGO_URL = 'https://cyberservices.vn/wp-content/uploads/2026/08/tach-nen-CS.png';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->define_constants();
        $this->include_dependencies();
        $this->init_hooks();
    }

    private function define_constants()
    {
        define('KIRA_AI_VERSION', '1.3.0');
        define('KIRA_AI_DIR', plugin_dir_path(__FILE__));
        define('KIRA_AI_URL', plugin_dir_url(__FILE__));
    }

    private function include_dependencies()
    {
        require_once KIRA_AI_DIR . 'includes/kira-ai-dashboard.php';
    }

    private function init_hooks()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'), 100);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_footer', array($this, 'render_modal_html'));
        
        // Single & Bulk AJAX endpoints
        add_action('wp_ajax_kira_ai_generate_post_text', array($this, 'ajax_generate_post_text'));
        add_action('wp_ajax_kira_ai_generate_post_image', array($this, 'ajax_generate_post_image'));

        // Hooks for row actions on existing posts
        add_filter('post_row_actions', array($this, 'add_post_row_actions'), 10, 2);
        add_filter('page_row_actions', array($this, 'add_post_row_actions'), 10, 2);

        // Hooks for row actions on existing taxonomies
        $enabled_taxonomies = get_option('kira_ai_taxonomies', array());
        if (is_array($enabled_taxonomies)) {
            foreach ($enabled_taxonomies as $taxonomy) {
                add_filter("{$taxonomy}_row_actions", array($this, 'add_term_row_actions'), 10, 2);
            }
        }

        // Auto-post to social networks when post is published (including scheduled)
        add_action('transition_post_status', array($this, 'on_post_published'), 10, 3);

        // AJAX endpoint to manually trigger Facebook re-post for a post
        add_action('wp_ajax_kira_ai_repost_facebook', array($this, 'ajax_repost_facebook'));

        // NOTE: JSON-LD structured data is intentionally NOT output here.
        // Rank Math owns all schema markup (Article/BlogPosting, Person, FAQPage).
        // Emitting a second Article block caused duplicate/conflicting entities
        // with a stale headline that ignored the Rank Math SEO title.

        // Evergreen Refresh cron
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
        add_action('kira_ai_evergreen_refresh', array($this, 'run_evergreen_refresh'));
        if (!wp_next_scheduled('kira_ai_evergreen_refresh')) {
            wp_schedule_event(time(), 'kira_ai_weekly', 'kira_ai_evergreen_refresh');
        }

        // AJAX handlers for processing existing posts and terms
        add_action('wp_ajax_kira_ai_process_existing_post', array($this, 'ajax_process_existing_post'));
        add_action('wp_ajax_kira_ai_test_facebook', array($this, 'ajax_test_facebook'));
        add_action('wp_ajax_kira_ai_process_existing_term', array($this, 'ajax_process_existing_term'));
        add_action('wp_ajax_kira_ai_clean_blacklist', array($this, 'ajax_clean_blacklist'));
        add_action('wp_ajax_kira_ai_generate_standalone_image', array($this, 'ajax_generate_standalone_image'));
        add_action('wp_ajax_kira_ai_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_kira_ai_sync_models', array($this, 'ajax_sync_models'));
    }

    public function add_term_row_actions($actions, $term)
    {
        $enabled_taxonomies = get_option('kira_ai_taxonomies', array());
        if (!in_array($term->taxonomy, $enabled_taxonomies)) {
            return $actions;
        }

        $actions['kira_ai_term_gen_image'] = sprintf(
            '<a href="#" class="kira-ai-term-row-action kira-ai-row-action" data-action="term_gen_image" data-term-id="%d" data-term-name="%s" data-taxonomy="%s">%s</a>',
            $term->term_id,
            esc_attr($term->name),
            esc_attr($term->taxonomy),
            __('AI Tạo ảnh', 'cyber-services-content')
        );
        $actions['kira_ai_term_gen_desc'] = sprintf(
            '<a href="#" class="kira-ai-term-row-action kira-ai-row-action" data-action="term_gen_desc" data-term-id="%d" data-term-name="%s" data-taxonomy="%s">%s</a>',
            $term->term_id,
            esc_attr($term->name),
            esc_attr($term->taxonomy),
            __('AI Tạo mô tả', 'cyber-services-content')
        );

        return $actions;
    }

    public function add_post_row_actions($actions, $post)
    {
        $enabled_post_types = get_option('kira_ai_post_types', array());
        if (!in_array($post->post_type, $enabled_post_types)) {
            return $actions;
        }

        $actions['kira_ai_gen_image'] = sprintf(
            '<a href="#" class="kira-ai-row-action" data-action="gen_image" data-post-id="%d" data-post-title="%s">%s</a>',
            $post->ID,
            esc_attr($post->post_title),
            __('AI Tạo ảnh', 'cyber-services-content')
        );
        $actions['kira_ai_rewrite_title'] = sprintf(
            '<a href="#" class="kira-ai-row-action" data-action="rewrite_title" data-post-id="%d" data-post-title="%s">%s</a>',
            $post->ID,
            esc_attr($post->post_title),
            __('AI Viết lại Title', 'cyber-services-content')
        );
        $actions['kira_ai_rewrite_content'] = sprintf(
            '<a href="#" class="kira-ai-row-action" data-action="rewrite_content" data-post-id="%d" data-post-title="%s">%s</a>',
            $post->ID,
            esc_attr($post->post_title),
            __('AI Viết lại Nội dung', 'cyber-services-content')
        );

        return $actions;
    }

    public function add_admin_menu()
    {
        add_menu_page(
            'Cấu hình Cyber Services Content',
            'Cyber Services Content',
            'manage_options',
            'kira-ai-settings',
            array($this, 'render_settings_page'),
            'dashicons-admin-customizer',
            80
        );
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['kira_ai_save']) && check_admin_referer('kira_ai_nonce_action')) {
            update_option('kira_ai_api_key', isset($_POST['kira_ai_api_key']) ? sanitize_text_field($_POST['kira_ai_api_key']) : '');
            update_option('kira_ai_text_model', isset($_POST['kira_ai_text_model']) ? sanitize_text_field($_POST['kira_ai_text_model']) : 'kira-3.5-flash');
            update_option('kira_ai_image_model', isset($_POST['kira_ai_image_model']) ? sanitize_text_field($_POST['kira_ai_image_model']) : 'kira-2.5-flash-image');

            update_option('kira_ai_fb_enabled', isset($_POST['kira_ai_fb_enabled']) ? 1 : 0);
            update_option('kira_ai_fb_page_id', isset($_POST['kira_ai_fb_page_id']) ? sanitize_text_field($_POST['kira_ai_fb_page_id']) : '');
            update_option('kira_ai_fb_access_token', isset($_POST['kira_ai_fb_access_token']) ? sanitize_text_field($_POST['kira_ai_fb_access_token']) : '');
            update_option('kira_ai_fb_post_message', isset($_POST['kira_ai_fb_post_message']) ? sanitize_textarea_field($_POST['kira_ai_fb_post_message']) : '');

            // Zalo OA settings
            update_option('kira_ai_zalo_enabled', isset($_POST['kira_ai_zalo_enabled']) ? 1 : 0);
            update_option('kira_ai_zalo_token', isset($_POST['kira_ai_zalo_token']) ? sanitize_text_field($_POST['kira_ai_zalo_token']) : '');
            update_option('kira_ai_zalo_oa_id', isset($_POST['kira_ai_zalo_oa_id']) ? sanitize_text_field($_POST['kira_ai_zalo_oa_id']) : '');

            // Telegram settings
            update_option('kira_ai_telegram_enabled', isset($_POST['kira_ai_telegram_enabled']) ? 1 : 0);
            update_option('kira_ai_telegram_bot_token', isset($_POST['kira_ai_telegram_bot_token']) ? sanitize_text_field($_POST['kira_ai_telegram_bot_token']) : '');
            update_option('kira_ai_telegram_chat_id', isset($_POST['kira_ai_telegram_chat_id']) ? sanitize_text_field($_POST['kira_ai_telegram_chat_id']) : '');

            // SEO & Automation settings
            update_option('kira_ai_url_blacklist', isset($_POST['kira_ai_url_blacklist']) ? sanitize_textarea_field($_POST['kira_ai_url_blacklist']) : '');
            // JSON-LD output was removed from this plugin; force the legacy flag off.
            update_option('kira_ai_jsonld_enabled', 0);
            update_option('kira_ai_evergreen_enabled', isset($_POST['kira_ai_evergreen_enabled']) ? 1 : 0);
            update_option('kira_ai_evergreen_age', isset($_POST['kira_ai_evergreen_age']) ? sanitize_text_field($_POST['kira_ai_evergreen_age']) : '6');

            $selected_post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', $_POST['post_types']) : array();
            $selected_taxonomies = isset($_POST['taxonomies']) ? array_map('sanitize_text_field', $_POST['taxonomies']) : array();

            update_option('kira_ai_post_types', $selected_post_types);
            update_option('kira_ai_taxonomies', $selected_taxonomies);

            echo '<div class="updated"><p>Cấu hình Cyber Services Content đã được lưu thành công.</p></div>';
        }

        if (isset($_POST['kira_ai_clear_logs']) && check_admin_referer('kira_ai_clear_logs_action')) {
            update_option('kira_ai_api_logs', array());
            echo '<div class="updated"><p>Lịch sử Log API đã được xóa thành công.</p></div>';
        }

        $api_key = get_option('kira_ai_api_key', '');
        $text_model = get_option('kira_ai_text_model', 'kira-3.5-flash');
        $image_model = get_option('kira_ai_image_model', 'kira-2.5-flash-image');
        $fb_page_id = get_option('kira_ai_fb_page_id', '');
        $fb_access_token = get_option('kira_ai_fb_access_token', '');
        $fb_enabled = get_option('kira_ai_fb_enabled', 0);
        $fb_post_message = get_option('kira_ai_fb_post_message', get_option('kira_ai_fb_post_message', '📌 Bài viết mới: {title}\n{excerpt}\n🔗 Xem chi tiết tại: {url}'));

        $zalo_enabled = get_option('kira_ai_zalo_enabled', 0);
        $zalo_token = get_option('kira_ai_zalo_token', '');
        $zalo_oa_id = get_option('kira_ai_zalo_oa_id', '');

        $telegram_enabled = get_option('kira_ai_telegram_enabled', 0);
        $telegram_bot_token = get_option('kira_ai_telegram_bot_token', '');
        $telegram_chat_id = get_option('kira_ai_telegram_chat_id', '');

        $evergreen_enabled = get_option('kira_ai_evergreen_enabled', 0);
        $evergreen_age = get_option('kira_ai_evergreen_age', '6');
        $models = $this->get_kira_models();
        $enabled_post_types = get_option('kira_ai_post_types', array());
        $enabled_taxonomies = get_option('kira_ai_taxonomies', array());
        $logs = get_option('kira_ai_api_logs', array());
        if (!is_array($logs)) {
            $logs = array();
        }

        $post_types = get_post_types(array('public' => true), 'objects');
        $taxonomies = get_taxonomies(array('public' => true), 'objects');
        ?>
        <div class="wrap kira-ai-admin-wrap">
            <h1>Cyber Services Content - Cấu hình & Tạo bài viết hàng loạt</h1>
            <p>Hệ thống tự động hóa nội dung chuẩn SEO Top 1 Google & Sinh ảnh bản quyền gắn logo CyberServices.</p>

            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="#dashboard" class="nav-tab nav-tab-active" id="kira-tab-dashboard-nav">📊 Tổng quan</a>
                <a href="#settings" class="nav-tab" id="kira-tab-settings-nav">Cấu hình chung</a>
                <a href="#bulk" class="nav-tab" id="kira-tab-bulk-nav">Tạo bài viết hàng loạt (Bulk)</a>
                <a href="#logs" class="nav-tab" id="kira-tab-logs-nav">Log API</a>
            </h2>

            <!-- TAB 0: DASHBOARD (TỔNG QUAN) -->
            <?php kira_ai_render_dashboard_tab(); ?>

            <!-- TAB 1: CẤU HÌNH -->
            <div id="kira-tab-settings" class="kira-tab-content-wrapper" style="display: none;">
                <form method="post" action=""
                    style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); max-width: 850px; margin-top: 10px;">
                    <?php wp_nonce_field('kira_ai_nonce_action'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row" colspan="2" style="padding-bottom: 0;">
                                <h3 style="margin: 0; color: #0f172a;">🔌 Cấu hình Cyber Services Content</h3>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_api_key">Kira AI API Key</label></th>
                            <td>
                                <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 5px;">
                                    <input name="kira_ai_api_key" type="password" id="kira_ai_api_key"
                                        value="<?php echo esc_attr($api_key); ?>" class="regular-text"
                                        placeholder="Nhập API Key của bạn">
                                    <button type="button" id="kira-ai-test-connection-btn" class="button button-secondary">Kiểm tra kết nối</button>
                                </div>
                                <div id="kira-ai-connection-status" style="font-weight: 600; font-size: 13px; margin-bottom: 10px;"></div>
                                <p class="description">API Key được cấp bởi hệ thống <a href="https://kiraai.vn/" target="_blank" style="font-weight: 600; text-decoration: none;">Kira AI</a>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_text_model">Model sinh văn bản</label></th>
                            <td>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <select name="kira_ai_text_model" id="kira_ai_text_model" style="max-width: 350px; width: 100%;">
                                        <?php 
                                        if (!empty($models['text_models'])) {
                                            foreach ($models['text_models'] as $m) {
                                                echo '<option value="' . esc_attr($m['id']) . '" ' . selected($text_model, $m['id'], false) . '>' . esc_html($m['name']) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                    <button type="button" id="kira-ai-sync-models-btn" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 5px;">
                                        <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px; margin: 0;"></span>
                                        Cập nhật model
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_image_model">Model sinh hình ảnh</label></th>
                            <td>
                                <select name="kira_ai_image_model" id="kira_ai_image_model" class="regular-text" style="max-width: 350px; width: 100%;">
                                    <?php 
                                    if (!empty($models['image_models'])) {
                                        foreach ($models['image_models'] as $m) {
                                            echo '<option value="' . esc_attr($m['id']) . '" ' . selected($image_model, $m['id'], false) . '>' . esc_html($m['name']) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Áp dụng cho Post Types:</th>
                            <td>
                                <?php foreach ($post_types as $pt):
                                    $checked = in_array($pt->name, $enabled_post_types) ? 'checked' : '';
                                    ?>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php echo $checked; ?> />
                                        <?php echo esc_html($pt->label); ?> <span style="color: #888; font-size: 12px;">(<?php echo esc_html($pt->name); ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Áp dụng cho Taxonomies:</th>
                            <td>
                                <?php foreach ($taxonomies as $tax):
                                    $checked = in_array($tax->name, $enabled_taxonomies) ? 'checked' : '';
                                    ?>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr($tax->name); ?>" <?php echo $checked; ?> />
                                        <?php echo esc_html($tax->label); ?> <span style="color: #888; font-size: 12px;">(<?php echo esc_html($tax->name); ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" colspan="2" style="padding-bottom: 0; padding-top: 30px;">
                                <h3 style="margin: 0; color: #0f172a;">📘 Đăng bài tự động lên Facebook Fanpage</h3>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_fb_enabled">Kích hoạt</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="kira_ai_fb_enabled" value="1" <?php checked($fb_enabled, 1); ?> />
                                    Tự động đăng bài lên Facebook Fanpage khi bài viết được xuất bản (bao gồm bài hẹn giờ)
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_fb_page_id">Facebook Page ID</label></th>
                            <td>
                                <input name="kira_ai_fb_page_id" type="text" id="kira_ai_fb_page_id"
                                    value="<?php echo esc_attr($fb_page_id); ?>" class="regular-text"
                                    placeholder="VD: 123456789012345">
                                <p class="description">ID của Fanpage (có thể xem trong phần About của Fanpage).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_fb_access_token">Page Access Token</label></th>
                            <td>
                                <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 5px;">
                                    <input name="kira_ai_fb_access_token" type="password" id="kira_ai_fb_access_token"
                                        value="<?php echo esc_attr($fb_access_token); ?>" class="regular-text" style="width: 400px;"
                                        placeholder="Nhập Page Access Token từ Facebook">
                                    <button type="button" id="kira-ai-test-facebook-btn" class="button button-secondary">Kiểm tra kết nối</button>
                                </div>
                                <div id="kira-ai-facebook-status" style="font-weight: 600; font-size: 13px; margin-bottom: 6px;"></div>
                                <p class="description">
                                    Cách lấy Token: Vào <a href="https://developers.facebook.com/tools/explorer/" target="_blank">Graph API Explorer</a> 
                                    → Chọn App → Get Token → Page Access Token → Chọn quyền <code>pages_manage_posts</code>.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_fb_post_message">Nội dung bài đăng</label></th>
                            <td>
                                <textarea name="kira_ai_fb_post_message" id="kira_ai_fb_post_message" rows="4" class="large-text" style="max-width: 500px;"><?php echo esc_textarea($fb_post_message); ?></textarea>
                                <p class="description">
                                    Hỗ trợ các biến: <code>{title}</code> — Tiêu đề bài viết, <code>{excerpt}</code> — Mô tả ngắn, <code>{url}</code> — Link bài viết.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" colspan="2" style="padding-bottom: 0; padding-top: 30px;">
                                <h3 style="margin: 0; color: #0f172a;">💬 Zalo Official Account (OA)</h3>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_zalo_enabled">Kích hoạt</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="kira_ai_zalo_enabled" value="1" <?php checked($zalo_enabled, 1); ?> />
                                    Tự động gửi nội dung bài viết lên Zalo OA khi bài được xuất bản
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_zalo_oa_id">Zalo OA ID</label></th>
                            <td>
                                <input name="kira_ai_zalo_oa_id" type="text" id="kira_ai_zalo_oa_id"
                                    value="<?php echo esc_attr($zalo_oa_id); ?>" class="regular-text"
                                    placeholder="VD: 1234567890123456789">
                                <p class="description">ID của Official Account. Có thể lấy từ Zalo OA dashboard.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_zalo_token">Zalo Access Token</label></th>
                            <td>
                                <input name="kira_ai_zalo_token" type="password" id="kira_ai_zalo_token"
                                    value="<?php echo esc_attr($zalo_token); ?>" class="regular-text" style="width: 400px;"
                                    placeholder="Nhập Zalo OA Access Token">
                                <p class="description">Lấy từ <a href="https://developers.zalo.me" target="_blank">Zalo Developers</a> → App → Access Token với quyền <code>send_message_to_followers</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" colspan="2" style="padding-bottom: 0; padding-top: 30px;">
                                <h3 style="margin: 0; color: #0f172a;">✈️ Telegram Bot</h3>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_telegram_enabled">Kích hoạt</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="kira_ai_telegram_enabled" value="1" <?php checked($telegram_enabled, 1); ?> />
                                    Tự động gửi thông báo bài viết lên Telegram khi bài được xuất bản
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_telegram_bot_token">Bot Token</label></th>
                            <td>
                                <input name="kira_ai_telegram_bot_token" type="password" id="kira_ai_telegram_bot_token"
                                    value="<?php echo esc_attr($telegram_bot_token); ?>" class="regular-text" style="width: 400px;"
                                    placeholder="VD: 123456789:ABCdef...">
                                <p class="description">Lấy từ <a href="https://t.me/BotFather" target="_blank">@BotFather</a> trên Telegram.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_telegram_chat_id">Chat ID</label></th>
                            <td>
                                <input name="kira_ai_telegram_chat_id" type="text" id="kira_ai_telegram_chat_id"
                                    value="<?php echo esc_attr($telegram_chat_id); ?>" class="regular-text"
                                    placeholder="VD: -1001234567890">
                                <p class="description">ID kênh hoặc group. Lấy bằng <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" colspan="2" style="padding-bottom: 0; padding-top: 30px;">
                                <h3 style="margin: 0; color: #0f172a;">🚀 SEO & Automation nâng cao</h3>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row">JSON-LD Schema</th>
                            <td>
                                <p class="description">Đã tắt vĩnh viễn trong plugin. Rank Math là nguồn duy nhất xuất structured data (Article/BlogPosting, Person, FAQPage) để tránh trùng lặp thực thể trên Google.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_evergreen_enabled">Evergreen Refresh</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="kira_ai_evergreen_enabled" value="1" <?php checked($evergreen_enabled, 1); ?> />
                                    Tự động làm mới bài viết cũ (WP Cron hàng tuần)
                                </label>
                                <div style="margin-top: 6px;">
                                    <label style="font-weight: normal; font-size: 13px;">Chỉ refresh bài cũ hơn:</label>
                                    <select name="kira_ai_evergreen_age" id="kira_ai_evergreen_age" style="margin-left: 6px;">
                                        <option value="3" <?php selected($evergreen_age, '3'); ?>>3 tháng</option>
                                        <option value="6" <?php selected($evergreen_age, '6'); ?>>6 tháng</option>
                                        <option value="12" <?php selected($evergreen_age, '12'); ?>>12 tháng</option>
                                        <option value="24" <?php selected($evergreen_age, '24'); ?>>24 tháng</option>
                                    </select>
                                </div>
                                <p class="description">AI sẽ tự động viết lại và bổ sung số liệu mới cho bài cũ để duy trì thứ hạng Google.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kira_ai_url_blacklist">🚫 URL Blacklist</label></th>
                            <td>
                                <textarea name="kira_ai_url_blacklist" id="kira_ai_url_blacklist" rows="8" class="large-text" style="max-width: 500px; font-family: monospace; font-size: 12.5px; line-height: 1.6;"><?php
                                    $saved_blacklist = get_option('kira_ai_url_blacklist', '');
                                    if (!empty($saved_blacklist)) {
                                        echo esc_textarea($saved_blacklist);
                                    } else {
                                        echo esc_textarea(implode("\n", $this->get_url_blacklist()));
                                    }
                                ?></textarea>
                                <p class="description">
                                    Mỗi URL một dòng. Các link chứa URL trong danh sách này sẽ <strong>tự động bị gỡ bỏ</strong> khỏi nội dung bài viết khi AI tạo/viết lại, và được <strong>cấm tuyệt đối</strong> trong prompt AI.<br>
                                    <em>Đây là danh sách mặc định — bạn có thể thêm hoặc xóa URL theo ý muốn. Dòng bắt đầu bằng <code>#</code> sẽ bị bỏ qua.</em>
                                </p>
                                <p>
                                    <button type="button" class="button" id="kira-ai-clean-blacklist-btn">
                                        🧹 Dọn link blacklist trong các bài viết đã có
                                    </button>
                                    <span id="kira-ai-clean-blacklist-result" style="margin-left: 8px; font-weight: 600;"></span>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="kira_ai_save" class="button button-primary" value="Lưu cấu hình" />
                    </p>
                </form>
            </div>

            <!-- TAB 2: TẠO BÀI VIẾT HÀNG LOẠT (BULK GENERATOR & SCHEDULE) -->
            <div id="kira-tab-bulk" class="kira-tab-content-wrapper" style="display: none;">
                <div class="kira-bulk-container" style="display: flex; gap: 24px; flex-wrap: wrap; margin-top: 10px;">
                    
                    <!-- Left: Bulk Form -->
                    <div style="flex: 1; min-width: 380px; max-width: 550px; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                        <h3 style="margin-top: 0; font-size: 17px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">1. Thiết lập bài viết & Lên lịch</h3>
                        
                        <div class="kira-ai-form-group">
                            <label for="kira-bulk-post-type"><strong>Loại bài viết (Post Type):</strong></label>
                            <select id="kira-bulk-post-type" class="regular-text" style="width: 100%;">
                                <?php 
                                $bulk_post_types = array_filter($post_types, function($pt) use ($enabled_post_types) {
                                    return $pt->name !== 'attachment' && in_array($pt->name, $enabled_post_types);
                                });
                                if (empty($bulk_post_types)) {
                                    $bulk_post_types = array_filter($post_types, function($pt) {
                                        return $pt->name !== 'attachment';
                                    });
                                }
                                foreach ($bulk_post_types as $pt): 
                                ?>
                                    <option value="<?php echo esc_attr($pt->name); ?>"><?php echo esc_html($pt->label); ?> (<?php echo esc_html($pt->name); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty(array_filter($post_types, function($pt) use ($enabled_post_types) { return $pt->name !== 'attachment' && in_array($pt->name, $enabled_post_types); }))): ?>
                                <p class="description" style="color: #d97706; margin-top: 6px;">Chưa có Post Type nào được bật trong Cấu hình chung. Đang hiển thị tất cả.</p>
                            <?php endif; ?>
                        </div>

                        <div class="kira-ai-form-group" style="margin-top: 15px;">
                            <label for="kira-bulk-keywords"><strong>Danh sách từ khóa chính (Mỗi dòng 1 từ khóa):</strong></label>
                            <textarea id="kira-bulk-keywords" rows="8" class="large-text" placeholder="Đất nền Tiền Hải&#10;Dịch vụ SEO tổng thể&#10;Thiết kế website chuẩn SEO..." style="font-size: 13.5px; line-height: 1.5;"></textarea>
                            <div style="margin-top: 6px; display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 12px; color: #64748b;" id="kira-bulk-kw-count">0 từ khóa được phát hiện</span>
                                <label class="button button-small" style="cursor: pointer;">
                                    <span>Import file .txt / .csv</span>
                                    <input type="file" id="kira-bulk-file-import" accept=".txt,.csv" style="display: none;">
                                </label>
                            </div>
                        </div>

                        <div class="kira-ai-form-group" style="margin-top: 15px;">
                            <label for="kira-bulk-prompt"><strong>Yêu cầu chung / Prompt bổ sung (Áp dụng cho toàn bộ):</strong></label>
                            <textarea id="kira-bulk-prompt" rows="3" class="large-text" placeholder="Ví dụ: Phân tích chuyên sâu, giọng văn chuyên nghiệp, cung cấp số liệu thực tế..."></textarea>
                        </div>

                        <div class="kira-ai-form-group" style="margin-top: 15px; border-top: 1px dashed #e2e8f0; padding-top: 15px;">
                            <label><strong>Chế độ xuất bản & Hẹn giờ:</strong></label>
                            <div style="margin: 8px 0;">
                                <label style="margin-right: 15px;"><input type="radio" name="kira_bulk_status" value="draft" checked> Lưu bản nháp (Draft)</label>
                                <label style="margin-right: 15px;"><input type="radio" name="kira_bulk_status" value="publish"> Đăng ngay lập tức (Publish)</label>
                                <label><input type="radio" name="kira_bulk_status" value="future"> Hẹn giờ đăng (Schedule)</label>
                            </div>

                            <div id="kira-bulk-schedule-options" style="display: none; background: #f8fafc; padding: 14px; border-radius: 6px; border: 1px solid #e2e8f0; margin-top: 10px;">
                                <div style="margin-bottom: 10px;">
                                    <label style="display: block; font-size: 12px; color: #475569; margin-bottom: 4px;">Thời gian bắt đầu đăng bài đầu tiên:</label>
                                    <input type="datetime-local" id="kira-bulk-start-time" class="regular-text" style="width: 100%;" value="<?php echo current_time('Y-m-d\TH:i', 0); ?>">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; color: #475569; margin-bottom: 4px;">Khoảng cách giữa các bài viết:</label>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <input type="number" id="kira-bulk-interval-val" value="2" min="1" style="width: 80px;">
                                        <select id="kira-bulk-interval-unit" style="flex: 1;">
                                            <option value="minutes">Phút</option>
                                            <option value="hours" selected>Giờ</option>
                                            <option value="days">Ngày</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="kira-ai-form-group" style="margin-top: 15px;">
                            <label style="display: inline-flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" id="kira-bulk-gen-image" value="1" checked style="margin-right: 8px;">
                                <strong>Sinh 3 ảnh WebP thực tế & tự chèn Logo CyberServices</strong>
                            </label>
                        </div>

                        <div style="margin-top: 20px;">
                            <button type="button" id="kira-bulk-start-btn" class="button button-primary button-hero" style="width: 100%; justify-content: center; display: flex; align-items: center; gap: 8px;">
                                <span class="dashicons dashicons-controls-play"></span> Bắt đầu tạo hàng loạt
                            </button>
                            <button type="button" id="kira-bulk-pause-btn" class="button button-secondary button-hero" style="width: 100%; justify-content: center; display: none; margin-top: 8px; color: #d97706;">
                                <span class="dashicons dashicons-controls-pause"></span> Tạm dừng tiến trình
                            </button>
                        </div>
                    </div>

                    <!-- Right: Progress & Realtime Queue Table -->
                    <div style="flex: 1.6; min-width: 480px; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                        <h3 style="margin-top: 0; font-size: 17px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">2. Hàng đợi & Tiến trình xử lý (AJAX Queue)</h3>

                        <div class="kira-bulk-progress-box" style="margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; font-weight: 600; margin-bottom: 6px; font-size: 13.5px;">
                                <span>Tiến độ hoàn thành:</span>
                                <span id="kira-bulk-progress-text">0 / 0 bài</span>
                            </div>
                            <div style="background: #e2e8f0; height: 16px; border-radius: 10px; overflow: hidden;">
                                <div id="kira-bulk-progress-bar" style="background: linear-gradient(135deg, #ea580c 0%, #22c55e 100%); width: 0%; height: 100%; transition: width 0.3s ease;"></div>
                            </div>
                        </div>

                        <div style="max-height: 480px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
                            <table class="wp-list-table widefat fixed striped" id="kira-bulk-table">
                                <thead>
                                    <tr>
                                        <th style="width: 45px;">STT</th>
                                        <th>Từ khóa</th>
                                        <th style="width: 140px;">Lên lịch</th>
                                        <th style="width: 150px;">Trạng thái</th>
                                        <th style="width: 80px;">Tác vụ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: #94a3b8; padding: 30px 0;">Chưa có từ khóa nào trong hàng đợi.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: LOGS -->
            <div id="kira-tab-logs" class="kira-tab-content-wrapper" style="display: none;">
                <div style="background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); max-width: 1000px; margin-top: 10px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px;">
                        <h3 style="margin: 0; font-size: 16px; color: #1e293b;">Lịch sử gọi API gần đây</h3>
                        <?php if (!empty($logs)): ?>
                            <form method="post" action="" class="kira-ai-clear-logs-form" style="display:inline-block;">
                                <?php wp_nonce_field('kira_ai_clear_logs_action'); ?>
                                <button type="button" class="button button-secondary kira-ai-clear-logs-trigger" style="color: #ef4444; border-color: #fca5a5;">Xóa tất cả Log</button>
                                <span class="kira-ai-clear-logs-confirm" style="display:none; margin-left:10px; font-weight:bold; color:#ef4444; font-size:13px;">
                                    Bạn chắc chắn muốn xóa?
                                    <button type="submit" name="kira_ai_clear_logs" class="button button-link" style="color:#ef4444; padding:0 5px; font-weight:bold;">[Đồng ý]</button>
                                    <button type="button" class="button button-link kira-ai-clear-logs-cancel" style="color:#64748b; padding:0 5px;">[Hủy]</button>
                                </span>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($logs)): ?>
                        <p style="color: #64748b; font-style: italic;">Chưa có lịch sử gọi API nào được ghi lại.</p>
                    <?php else: ?>
                        <div class="kira-logs-list">
                            <?php foreach ($logs as $index => $log):
                                $status_text = $log['status'] === 'success' ? 'Thành công' : 'Thất bại';
                                $status_color = $log['status'] === 'success' ? '#22c55e' : '#ef4444';
                                $status_bg = $log['status'] === 'success' ? '#f0fdf4' : '#fef2f2';
                                $status_border = $log['status'] === 'success' ? '#bbf7d0' : '#fca5a5';
                                ?>
                                <details style="border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 14px; overflow: hidden; background: #f8fafc;">
                                    <summary style="padding: 14px 18px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: space-between; background: #ffffff;">
                                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                            <span style="font-size: 12.5px; color: #64748b; font-family: monospace;"><?php echo esc_html($log['time']); ?></span>
                                            <span style="background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; border: 1px solid <?php echo $status_border; ?>; font-size: 11px; padding: 2px 8px; border-radius: 6px; font-weight: 600; text-transform: uppercase;"><?php echo $status_text; ?></span>
                                            <strong style="font-size: 14px; color: #0f172a;"><?php echo esc_html($log['keyword']); ?></strong>
                                        </div>
                                        <div style="font-size: 12px; color: #64748b;">
                                            <span>Model:</span>
                                            <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo esc_html($log['model']); ?></code>
                                        </div>
                                    </summary>
                                    <div style="padding: 20px; border-top: 1px solid #e2e8f0; background: #ffffff;">
                                        <div style="margin-bottom: 18px; border-left: 3px solid #ea580c; padding-left: 12px; background: #f8fafc; padding-top: 8px; padding-bottom: 8px; border-radius: 0 6px 6px 0;">
                                            <h4 style="margin: 0 0 6px 0; font-size: 12.5px; text-transform: uppercase; color: #c2410c;">Từ khóa & Prompt yêu cầu</h4>
                                            <p style="margin: 0 0 6px 0; font-size: 14px; color: #1e293b;"><strong>Từ khóa chính:</strong> <?php echo esc_html($log['keyword']); ?></p>
                                            <p style="margin: 0; font-size: 14px; color: #1e293b;"><strong>Yêu cầu (Prompt):</strong> <?php echo esc_html($log['prompt']); ?></p>
                                        </div>
                                        <div style="margin-bottom: 18px;">
                                            <h4 style="margin: 0 0 6px 0; font-size: 12.5px; text-transform: uppercase; color: #64748b;">Prompt đầu vào đầy đủ</h4>
                                            <pre style="background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 12px; white-space: pre-wrap; margin: 0; max-height: 250px; overflow-y: auto;"><?php echo esc_html($log['input_full']); ?></pre>
                                        </div>
                                        <div>
                                            <h4 style="margin: 0 0 6px 0; font-size: 12.5px; text-transform: uppercase; color: #64748b;">Phản hồi đầu ra</h4>
                                            <?php if ($log['status'] === 'success'): ?>
                                                <pre style="background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 12px; white-space: pre-wrap; margin: 0; max-height: 350px; overflow-y: auto;"><?php echo esc_html($log['output']); ?></pre>
                                            <?php else: ?>
                                                <?php if (!empty($log['error'])): ?>
                                                    <div style="background: #fef2f2; color: #991b1b; border: 1px solid #fee2e2; padding: 14px; border-radius: 8px; font-size: 13.5px; margin-bottom: 12px;">
                                                        <strong>Lỗi API:</strong> <?php echo esc_html($log['error']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const dashboardNav = document.getElementById('kira-tab-dashboard-nav');
                    const settingsNav = document.getElementById('kira-tab-settings-nav');
                    const bulkNav = document.getElementById('kira-tab-bulk-nav');
                    const logsNav = document.getElementById('kira-tab-logs-nav');
                    
                    const dashboardTab = document.getElementById('kira-tab-dashboard');
                    const settingsTab = document.getElementById('kira-tab-settings');
                    const bulkTab = document.getElementById('kira-tab-bulk');
                    const logsTab = document.getElementById('kira-tab-logs');

                    function switchTab(tabId) {
                        dashboardNav.classList.remove('nav-tab-active');
                        settingsNav.classList.remove('nav-tab-active');
                        bulkNav.classList.remove('nav-tab-active');
                        logsNav.classList.remove('nav-tab-active');

                        dashboardTab.style.display = 'none';
                        settingsTab.style.display = 'none';
                        bulkTab.style.display = 'none';
                        logsTab.style.display = 'none';

                        if (tabId === 'bulk') {
                            bulkNav.classList.add('nav-tab-active');
                            bulkTab.style.display = 'block';
                        } else if (tabId === 'logs') {
                            logsNav.classList.add('nav-tab-active');
                            logsTab.style.display = 'block';
                        } else if (tabId === 'settings') {
                            settingsNav.classList.add('nav-tab-active');
                            settingsTab.style.display = 'block';
                        } else {
                            dashboardNav.classList.add('nav-tab-active');
                            dashboardTab.style.display = 'block';
                        }
                    }

                    dashboardNav.addEventListener('click', function (e) {
                        e.preventDefault();
                        switchTab('dashboard');
                        window.location.hash = 'dashboard';
                    });

                    settingsNav.addEventListener('click', function (e) {
                        e.preventDefault();
                        switchTab('settings');
                        window.location.hash = 'settings';
                    });

                    bulkNav.addEventListener('click', function (e) {
                        e.preventDefault();
                        switchTab('bulk');
                        window.location.hash = 'bulk';
                    });

                    logsNav.addEventListener('click', function (e) {
                        e.preventDefault();
                        switchTab('logs');
                        window.location.hash = 'logs';
                    });

                    if (window.location.hash === '#bulk') {
                        switchTab('bulk');
                    } else if (window.location.hash === '#logs') {
                        switchTab('logs');
                    } else if (window.location.hash === '#settings') {
                        switchTab('settings');
                    }

                    const triggerBtn = document.querySelector('.kira-ai-clear-logs-trigger');
                    const confirmSpan = document.querySelector('.kira-ai-clear-logs-confirm');
                    const cancelBtn = document.querySelector('.kira-ai-clear-logs-cancel');

                    if (triggerBtn && confirmSpan && cancelBtn) {
                        triggerBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            triggerBtn.style.display = 'none';
                            confirmSpan.style.display = 'inline-block';
                        });

                        cancelBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            confirmSpan.style.display = 'none';
                            triggerBtn.style.display = 'inline-block';
                        });
                    }

                    // Clean blacklist button
                    const cleanBlacklistBtn = document.getElementById('kira-ai-clean-blacklist-btn');
                    const cleanBlacklistResult = document.getElementById('kira-ai-clean-blacklist-result');
                    if (cleanBlacklistBtn && cleanBlacklistResult) {
                        cleanBlacklistBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            if (!confirm('Quét và gỡ tất cả link blacklist khỏi các bài viết đã có? Hành động này không thể hoàn tác.')) {
                                return;
                            }
                            cleanBlacklistBtn.disabled = true;
                            cleanBlacklistBtn.textContent = '🧹 Đang dọn...';
                            cleanBlacklistResult.textContent = 'Đang xử lý, vui lòng chờ...';

                            const formData = new FormData();
                            formData.append('action', 'kira_ai_clean_blacklist');
                            formData.append('_ajax_nonce', document.querySelector('input[name="_wpnonce"]') ? document.querySelector('input[name="_wpnonce"]').value : '');

                            fetch(ajaxurl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: formData
                            })
                            .then(function (res) { return res.json(); })
                            .then(function (data) {
                                if (data && data.success) {
                                    cleanBlacklistResult.textContent = data.data.message;
                                    cleanBlacklistResult.style.color = '#46b450';
                                } else {
                                    cleanBlacklistResult.textContent = data && data.data ? data.data : 'Đã xảy ra lỗi.';
                                    cleanBlacklistResult.style.color = '#dc3232';
                                }
                            })
                            .catch(function () {
                                cleanBlacklistResult.textContent = 'Đã xảy ra lỗi kết nối.';
                                cleanBlacklistResult.style.color = '#dc3232';
                            })
                            .finally(function () {
                                cleanBlacklistBtn.disabled = false;
                                cleanBlacklistBtn.textContent = '🧹 Dọn link blacklist trong các bài viết đã có';
                            });
                        });
                    }
                });
            </script>
        </div>
        <?php
    }

    public function enqueue_admin_assets($hook)
    {
        $should_enqueue = false;
        $post_type = '';
        $taxonomy = '';

        if ('edit.php' === $hook) {
            $post_type = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : 'post';
            $enabled_post_types = get_option('kira_ai_post_types', array());
            if (in_array($post_type, $enabled_post_types)) {
                $should_enqueue = true;
            }
        } elseif ('edit-tags.php' === $hook) {
            $taxonomy = isset($_GET['taxonomy']) ? sanitize_text_field($_GET['taxonomy']) : 'category';
            $enabled_taxonomies = get_option('kira_ai_taxonomies', array());
            if (in_array($taxonomy, $enabled_taxonomies)) {
                $should_enqueue = true;
            }
        } elseif ('upload.php' === $hook) {
            $enabled_post_types = get_option('kira_ai_post_types', array());
            if (in_array('attachment', $enabled_post_types)) {
                $should_enqueue = true;
            }
        } elseif ('toplevel_page_kira-ai-settings' === $hook) {
            $should_enqueue = true;
        }

        if (!$should_enqueue) {
            return;
        }

        wp_enqueue_style('kira-ai-admin-css', KIRA_AI_URL . 'assets/css/kira-ai-admin.css', array(), KIRA_AI_VERSION);
        wp_enqueue_script('kira-ai-admin-js', KIRA_AI_URL . 'assets/js/kira-ai-admin.js', array('jquery'), KIRA_AI_VERSION, true);

        // Chart.js CDN - chỉ enqueue trên trang cấu hình (nơi có Dashboard)
        if ('toplevel_page_kira-ai-settings' === $hook) {
            wp_enqueue_script('kira-ai-chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true);
        }

        wp_localize_script('kira-ai-admin-js', 'kira_ai_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kira_ai_generate_nonce'),
            'post_type' => $post_type,
            'taxonomy' => $taxonomy,
            'is_media' => ('upload.php' === $hook),
            'admin_url' => admin_url()
        ));
    }

    public function render_modal_html()
    {
        if (isset($_GET['page']) && $_GET['page'] === 'kira-ai-settings') {
            return;
        }
        global $pagenow;

        $should_render = false;

        if ('edit.php' === $pagenow) {
            $post_type = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : 'post';
            $enabled_post_types = get_option('kira_ai_post_types', array());
            if (in_array($post_type, $enabled_post_types)) {
                $should_render = true;
            }
        } elseif ('edit-tags.php' === $pagenow) {
            $taxonomy = isset($_GET['taxonomy']) ? sanitize_text_field($_GET['taxonomy']) : 'category';
            $enabled_taxonomies = get_option('kira_ai_taxonomies', array());
            if (in_array($taxonomy, $enabled_taxonomies)) {
                $should_render = true;
            }
        } elseif ('upload.php' === $pagenow) {
            $enabled_post_types = get_option('kira_ai_post_types', array());
            if (in_array('attachment', $enabled_post_types)) {
                $should_render = true;
            }
        }

        if (!$should_render) {
            return;
        }

        $post_type = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : 'post';
        $post_type_object = get_post_type_object($post_type);
        $post_type_label = $post_type_object ? $post_type_object->labels->singular_name : 'Bài viết';
        ?>
        <div id="kira-ai-modal-backdrop" class="kira-ai-modal-backdrop">
            <div class="kira-ai-modal">
                <div class="kira-ai-modal-header">
                    <h3>
                        <span class="icon-glow"><span class="dashicons dashicons-admin-customizer"></span></span>
                        Tạo nội dung <?php echo esc_html($post_type_label); ?> với AI
                    </h3>
                    <button type="button" class="kira-ai-modal-close">&times;</button>
                </div>

                <div class="kira-ai-modal-body">
                    <div class="kira-ai-error-msg"></div>

                    <form id="kira-ai-form">
                        <div class="kira-ai-form-group">
                            <label for="kira-ai-keyword">Từ khóa chính (Focus Keyword)</label>
                            <input type="text" id="kira-ai-keyword" class="kira-ai-input" placeholder="Ví dụ: đất nền Tiền Hải, dịch vụ SEO..." required>
                        </div>

                        <div class="kira-ai-form-group">
                            <label for="kira-ai-prompt">Yêu cầu viết bài (Prompt)</label>
                            <textarea id="kira-ai-prompt" class="kira-ai-input kira-ai-textarea" placeholder="Nhập yêu cầu chi tiết của bạn để AI viết bài..." required></textarea>
                        </div>
                        <div class="kira-ai-form-group" style="margin-top: 15px; padding: 12px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 700; color: #0369a1;">🔗 Cấu trúc Silo & Pillar Page (Tối ưu Link Juice)</label>
                            <div style="margin-bottom: 10px;">
                                <label for="kira-ai-pillar-url" style="font-size: 12.5px; font-weight: 600;">URL Trang trụ cột (Pillar Page)</label>
                                <input type="url" id="kira-ai-pillar-url" class="kira-ai-input" placeholder="https://your-site.com/trang-tong-quan-chu-de" style="margin-top: 4px;">
                                <p class="description" style="font-size: 11.5px; margin: 4px 0 0; color: #64748b;">AI sẽ lồng ghép link này vào 1/3 đầu bài viết để tập trung sức mạnh về trang trụ cột.</p>
                            </div>
                            <div>
                                <label for="kira-ai-pillar-keyword" style="font-size: 12.5px; font-weight: 600;">Từ khóa Anchor cho Pillar</label>
                                <input type="text" id="kira-ai-pillar-keyword" class="kira-ai-input" placeholder="Ví dụ: dịch vụ tư vấn toàn diện" style="margin-top: 4px;">
                                <p class="description" style="font-size: 11.5px; margin: 4px 0 0; color: #64748b;">Từ khóa dùng làm anchor text tự nhiên trỏ về Pillar Page.</p>
                            </div>
                            <div style="margin-top: 10px;">
                                <label for="kira-ai-max-internal-links" style="font-size: 12.5px; font-weight: 600;">Giới hạn Internal Link phụ (Silo)</label>
                                <input type="number" id="kira-ai-max-internal-links" class="kira-ai-input" min="0" max="10" step="1" value="3" style="margin-top: 4px; max-width: 120px;">
                                <p class="description" style="font-size: 11.5px; margin: 4px 0 0; color: #64748b;">Số link nội bộ phụ trợ tối đa (không tính link Pillar). 0 = không giới hạn. Giúp tránh loãng sức mạnh trang.</p>
                            </div>
                        </div>


                        <div class="kira-ai-form-group" style="margin-top: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Chế độ xuất bản:</label>
                            <select id="kira-ai-post-status" class="kira-ai-input" style="margin-bottom: 10px;">
                                <option value="draft">Lưu bản nháp (Draft)</option>
                                <option value="publish">Xuất bản ngay (Publish)</option>
                            </select>
                        </div>

                        <div class="kira-ai-form-group" style="margin-top: 10px;">
                            <label style="display: inline-flex; align-items: center; font-weight: 500; cursor: pointer;">
                                <input type="checkbox" id="kira-ai-gen-image" value="1" checked style="margin-right: 8px; width: 16px; height: 16px;">
                                Tạo ảnh đại diện & tự chèn 2 ảnh WebP thực tế có Logo CyberServices
                            </label>
                        </div>
                    </form>

                    <div class="kira-ai-loading-container">
                        <div class="kira-ai-spinner"></div>
                        <div class="kira-ai-loading-text">AI đang soạn thảo nội dung...</div>
                        <div class="kira-ai-loading-subtext">Quá trình này có thể mất từ 30 giây đến 1 phút do AI phân tích chuyên sâu và chèn logo tự động.</div>

                        <ul class="kira-ai-progress-steps">
                            <li id="kira-step-text" class="step-pending">
                                <span class="step-icon"></span>
                                <span class="step-label">Soạn thảo văn bản & Tối ưu SEO</span>
                            </li>
                            <li id="kira-step-image" class="step-pending">
                                <span class="step-icon"></span>
                                <span class="step-label">Chụp 3 ảnh thực tế WebP & Chèn Logo CyberServices</span>
                            </li>
                            <li id="kira-step-save" class="step-pending">
                                <span class="step-icon"></span>
                                <span class="step-label">Lưu trữ bài viết & Hoàn tất</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="kira-ai-modal-footer">
                    <button type="button" class="kira-ai-btn kira-ai-btn-secondary">Hủy bỏ</button>
                    <button type="button" id="kira-ai-submit" class="kira-ai-btn kira-ai-btn-primary">
                        <span class="dashicons dashicons-admin-customizer"></span> Bắt đầu tạo bài viết
                    </button>
                </div>
            </div>
        </div>

        <div id="kira-ai-action-modal-backdrop" class="kira-ai-modal-backdrop">
            <div class="kira-ai-modal">
                <div class="kira-ai-modal-header">
                    <h3>
                        <span class="icon-glow"><span class="dashicons dashicons-admin-customizer"></span></span>
                        <span id="kira-ai-action-modal-title">AI Xử lý bài viết</span>
                    </h3>
                    <button type="button" class="kira-ai-action-modal-close kira-ai-modal-close">&times;</button>
                </div>

                <div class="kira-ai-modal-body">
                    <div class="kira-ai-action-error-msg kira-ai-error-msg"></div>

                    <form id="kira-ai-action-form">
                        <input type="hidden" id="kira-ai-action-post-id" value="">
                        <input type="hidden" id="kira-ai-action-type" value="">

                        <div class="kira-ai-form-group">
                            <p id="kira-ai-action-description" style="font-size: 13.5px; color: #475569; background: #f8fafc; padding: 12px; border-radius: 8px; border-left: 3px solid #ea580c; margin-top: 0; line-height: 1.5;"></p>
                        </div>

                        <div class="kira-ai-form-group">
                            <label for="kira-ai-action-custom-prompt">Yêu cầu bổ sung (Custom Prompt - Tùy chọn)</label>
                            <textarea id="kira-ai-action-custom-prompt" class="kira-ai-input kira-ai-textarea" placeholder="Nhập thêm các yêu cầu đặc biệt..." style="min-height: 80px;"></textarea>
                        </div>
                        <div class="kira-ai-form-group" style="margin-top: 12px; padding: 12px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 700; color: #0369a1;">🔗 Cấu trúc Silo & Pillar Page (Tối ưu Link Juice)</label>
                            <div style="margin-bottom: 10px;">
                                <label for="kira-ai-action-pillar-url" style="font-size: 12.5px; font-weight: 600;">URL Trang trụ cột (Pillar Page)</label>
                                <input type="url" id="kira-ai-action-pillar-url" class="kira-ai-input" placeholder="https://your-site.com/trang-tong-quan-chu-de" style="margin-top: 4px;">
                                <p class="description" style="font-size: 11.5px; margin: 4px 0 0; color: #64748b;">AI sẽ lồng ghép link này vào 1/3 đầu bài viết để tập trung sức mạnh về trang trụ cột.</p>
                            </div>
                            <div>
                                <label for="kira-ai-action-pillar-keyword" style="font-size: 12.5px; font-weight: 600;">Từ khóa Anchor cho Pillar</label>
                                <input type="text" id="kira-ai-action-pillar-keyword" class="kira-ai-input" placeholder="Ví dụ: dịch vụ tư vấn toàn diện" style="margin-top: 4px;">
                            </div>
                            <div style="margin-top: 10px;">
                                <label for="kira-ai-action-max-internal-links" style="font-size: 12.5px; font-weight: 600;">Giới hạn Internal Link phụ (Silo)</label>
                                <input type="number" id="kira-ai-action-max-internal-links" class="kira-ai-input" min="0" max="10" step="1" value="3" style="margin-top: 4px; max-width: 120px;">
                                <p class="description" style="font-size: 11.5px; margin: 4px 0 0; color: #64748b;">Số link nội bộ phụ trợ tối đa (không tính link Pillar). 0 = không giới hạn.</p>
                            </div>
                        </div>

                    </form>

                    <div class="kira-ai-action-loading-container kira-ai-loading-container" style="display:none;">
                        <div class="kira-ai-spinner"></div>
                        <div class="kira-ai-action-loading-text kira-ai-loading-text">AI đang xử lý yêu cầu...</div>
                        <div class="kira-ai-action-loading-subtext kira-ai-loading-subtext">Quá trình này có thể mất từ 15 đến 45 giây.</div>
                    </div>
                </div>

                <div class="kira-ai-modal-footer">
                    <button type="button" class="kira-ai-btn kira-ai-action-btn-secondary kira-ai-btn-secondary">Hủy bỏ</button>
                    <button type="button" id="kira-ai-action-submit" class="kira-ai-btn kira-ai-btn-primary">
                        <span class="dashicons dashicons-admin-customizer"></span> Xác nhận thực hiện
                    </button>
                </div>
            </div>
        </div>

        <div id="kira-ai-media-modal-backdrop" class="kira-ai-modal-backdrop">
            <div class="kira-ai-modal">
                <div class="kira-ai-modal-header">
                    <h3>
                        <span class="icon-glow"><span class="dashicons dashicons-admin-customizer"></span></span>
                        Tạo ảnh chụp thực tế với AI
                    </h3>
                    <button type="button" class="kira-ai-media-modal-close kira-ai-modal-close">&times;</button>
                </div>

                <div class="kira-ai-modal-body">
                    <div class="kira-ai-media-error-msg kira-ai-error-msg"></div>

                    <form id="kira-ai-media-form">
                        <div class="kira-ai-form-group">
                            <label for="kira-ai-media-prompt">Yêu cầu vẽ ảnh (Prompt)</label>
                            <textarea id="kira-ai-media-prompt" class="kira-ai-input kira-ai-textarea" placeholder="Mô tả bối cảnh ảnh thực tế bạn muốn tạo..." required></textarea>
                        </div>

                        <div class="kira-ai-form-group">
                            <label for="kira-ai-media-aspect-ratio">Kích thước ảnh (Aspect Ratio)</label>
                            <select id="kira-ai-media-aspect-ratio" class="kira-ai-input">
                                <option value="16:9">Ngang (16:9)</option>
                                <option value="9:16">Dọc (9:16)</option>
                                <option value="4:3">Ngang (4:3)</option>
                                <option value="3:4">Dọc (3:4)</option>
                                <option value="1:1">Vuông (1:1)</option>
                            </select>
                        </div>
                    </form>

                    <div class="kira-ai-media-loading-container kira-ai-loading-container" style="display:none;">
                        <div class="kira-ai-spinner"></div>
                        <div class="kira-ai-media-loading-text kira-ai-loading-text">AI đang chụp ảnh và gắn logo...</div>
                        <div class="kira-ai-media-loading-subtext kira-ai-loading-subtext">Quá trình này mất khoảng 15 đến 20 giây.</div>
                    </div>
                </div>

                <div class="kira-ai-modal-footer">
                    <button type="button" class="kira-ai-btn kira-ai-media-btn-secondary kira-ai-btn-secondary">Hủy bỏ</button>
                    <button type="button" id="kira-ai-media-submit" class="kira-ai-btn kira-ai-btn-primary">
                        <span class="dashicons dashicons-admin-customizer"></span> Bắt đầu tạo ảnh
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private function build_realistic_photo_prompt($description)
    {
        return "A real, high-resolution authentic professional photograph of {$description}. " .
               "Real-life photography style, natural lighting, realistic textures, shot on 35mm DSLR lens, vivid details, 8k resolution, authentic atmosphere. " .
               "--negative prompt / strict avoidance: no 3D render, no CGI, no cartoon, no anime, no drawing, no artificial looking, no deformed bodies or extra limbs, " .
               "no text, no watermark, no logo, no captions, no subtitles, no words, no blurry details, no noise.";
    }

    /**
     * Build internal link context data for AI prompts.
     *
     * @param string $keyword         The focus keyword.
     * @param int    $exclude_post_id Post ID to exclude (for rewrite).
     * @return array{existing_titles_str: string, landing_pages_json: string, related_posts_json: string}
     */
    private function build_internal_link_context($keyword, $exclude_post_id = 0, $max_links = 0)
    {
        $query_args = array(
            'numberposts' => 40,
            'post_status' => 'publish',
            'post_type'   => array('post', 'page'),
        );
        if ($exclude_post_id) {
            $query_args['exclude'] = array($exclude_post_id);
        }

        $all_contents = get_posts($query_args);

        $scored_items          = array();
        $current_keyword_lower = mb_strtolower($keyword, 'UTF-8');
        $keyword_words         = array_filter(explode(' ', $current_keyword_lower), function ($w) {
            return mb_strlen($w) > 2;
        });

        foreach ($all_contents as $item) {
            $item_title_lower = mb_strtolower($item->post_title, 'UTF-8');
            $score            = 0;

            foreach ($keyword_words as $word) {
                if (strpos($item_title_lower, $word) !== false) {
                    $score += 2;
                }
            }

            if (strpos($item_title_lower, $current_keyword_lower) !== false) {
                $score += 6;
            }

            $is_landing_page = ($item->post_type === 'page');
            if ($is_landing_page && $score > 0) {
                $score += 8;
            }

            $scored_items[] = array(
                'item'            => $item,
                'score'           => $score,
                'is_landing_page' => $is_landing_page,
            );
        }

        usort($scored_items, function ($a, $b) {
            return $b['score'] - $a['score'];
        });

        $top_items       = array_slice($scored_items, 0, 30);
        $existing_titles = array();
        $landing_pages   = array();
        $related_posts   = array();

        // Cap the number of suggested links when a limit is requested so the
        // AI does not dilute link equity across too many pages (Silo hygiene).
        $max_landing = ($max_links > 0) ? max(1, (int) ($max_links / 2)) : 5;
        $max_related = ($max_links > 0) ? max(1, $max_links - $max_landing) : 15;

        foreach ($top_items as $entry) {
            $obj               = $entry['item'];
            $existing_titles[] = '"' . $obj->post_title . '"';

            $link_info = array(
                'title' => $obj->post_title,
                'url'   => get_permalink($obj->ID),
            );

            if ($entry['is_landing_page']) {
                if (count($landing_pages) < $max_landing) {
                    $landing_pages[] = $link_info;
                }
            } else {
                if (count($related_posts) < $max_related) {
                    $related_posts[] = $link_info;
                }
            }
        }

        // 1.2 Orphan Content Detector: surface published posts that currently
        // receive NO internal links from anywhere on the site, so the AI can
        // prioritise linking to them and pull them into the cluster.
        $orphan_json = $this->get_orphan_posts_json($keyword, $top_items, ($max_related > 0 ? $max_related : 10));

        return array(
            'existing_titles_str'  => !empty($existing_titles) ? implode(', ', $existing_titles) : 'Chưa có bài viết nào khác.',
            'landing_pages_json'   => !empty($landing_pages) ? wp_json_encode($landing_pages, JSON_UNESCAPED_UNICODE) : '[]',
            'related_posts_json'   => !empty($related_posts) ? wp_json_encode($related_posts, JSON_UNESCAPED_UNICODE) : '[]',
            'orphan_posts_json'    => $orphan_json,
        );
    }

    /**
     * 1.2 Orphan Content Detector.
     *
     * Returns a JSON list of published posts (excluding the current one) that
     * have NO inbound internal link from any other published post/page on the
     * same domain. These "orphan" pages should be prioritised as internal-link
     * targets so they join the topical cluster.
     *
     * @param string $keyword     Focus keyword (used to rank orphans by relevance).
     * @param array  $scored_top  Already-scored candidate items (score desc).
     * @param int    $limit       Max number of orphans to return.
     * @return string JSON array of {title,url} or '[]'.
     */
    private function get_orphan_posts_json($keyword, $scored_top = array(), $limit = 10)
    {
        $home = parse_url(home_url(), PHP_URL_HOST);
        if (empty($home)) {
            return '[]';
        }
        $host = preg_quote($home, '/');

        // Collect every internal href currently used across published content.
        $linked = array();
        $all    = get_posts(array(
            'numberposts' => 200,
            'post_status' => 'publish',
            'post_type'   => array('post', 'page'),
            'fields'      => array('ids', 'post_content'),
        ));
        foreach ($all as $p) {
            if (preg_match_all('/<a[^>]+href=["\']https?:\/\/([^"\']+)["\']/i', $p->post_content, $m)) {
                foreach ($m[1] as $href_host_path) {
                    // Only count links pointing to our own domain.
                    if (preg_match('/^' . $host . '/i', $href_host_path)) {
                        $linked[strtolower($href_host_path)] = true;
                    }
                }
            }
        }

        $candidates = array();
        $kw_lower   = mb_strtolower($keyword, 'UTF-8');

        $pool = !empty($scored_top) ? $scored_top : array();
        foreach ($pool as $entry) {
            $obj = $entry['item'];
            $url = get_permalink($obj->ID);
            $path = preg_replace('/^https?:\/\//i', '', $url);
            if (isset($linked[strtolower($path)])) {
                continue; // already has an inbound link -> not orphan
            }
            $candidates[] = array(
                'title' => $obj->post_title,
                'url'   => $url,
            );
            if (count($candidates) >= $limit) {
                break;
            }
        }

        // Fallback: if the keyword-scored pool was empty, scan recent posts.
        if (empty($candidates)) {
            $recent = get_posts(array(
                'numberposts' => 50,
                'post_status' => 'publish',
                'post_type'   => array('post', 'page'),
                'fields'      => array('ids', 'post_title', 'post_content'),
            ));
            foreach ($recent as $p) {
                $url  = get_permalink($p->ID);
                $path = preg_replace('/^https?:\/\//i', '', $url);
                if (isset($linked[strtolower($path)])) {
                    continue;
                }
                if ($kw_lower && strpos(mb_strtolower($p->post_title, 'UTF-8'), $kw_lower) === false) {
                    continue;
                }
                $candidates[] = array('title' => $p->post_title, 'url' => $url);
                if (count($candidates) >= $limit) {
                    break;
                }
            }
        }

        return !empty($candidates) ? wp_json_encode($candidates, JSON_UNESCAPED_UNICODE) : '[]';
    }

    /**
     * Get the URL blacklist (from option, fallback to default list).
     *
     * @return array List of blacklisted URL fragments (lowercase, trimmed).
     */
    private function get_url_blacklist()
    {
        $saved = get_option('kira_ai_url_blacklist', '');
        $default = array(
            'https://cyberservices.vn/survey-3ds/',
            'https://cyberservices.vn/survey-iso-42001/',
            'https://cyberservices.vn/survey-soc-report/',
            'https://cyberservices.vn/survey-iso/',
            'https://cyberservices.vn/survey-pci-dss/',
            'https://cyberservices.vn/chinh-sach-quyen-rieng-tu/',
            'https://cyberservices.vn/chinh-sach-su-dung-duoc-chap-nhan/',
            'https://cyberservices.vn/tinh-khach-quan/',
        );

        $list = array();
        if (!empty($saved)) {
            $lines = preg_split('/\r\n|\r|\n/', $saved);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && strpos($line, '#') !== 0) {
                    $list[] = $line;
                }
            }
        }

        // Fallback to defaults only when user has not saved any custom list.
        if (empty($list)) {
            return $default;
        }

        return $list;
    }

    /**
     * 1.1 Topic Cluster / link-budget instruction.
     *
     * Enforces a capped number of contextual internal links so the article
     * does not dilute link equity (juice) across too many pages. The Pillar
     * link (handled separately) is excluded from this budget.
     *
     * @param int $max_internal_links Max number of supporting internal links (0 = no cap).
     * @return string Prompt block (empty when no cap requested).
     */
    private function build_cluster_link_instruction($max_internal_links = 0)
    {
        $max = (int) $max_internal_links;
        if ($max <= 0) {
            return '';
        }

        return
            "  *** QUẢN LÝ NGÂN SÁCH INTERNAL LINK (TRÁNH LOÃNG SỨC MẠNH - SILO): ***\n" .
            "  + CHỈ ĐƯỢC chèn TỐI ĐA {$max} liên kết nội bộ phụ trợ (không tính link Pillar trụ cột).\n" .
            "  + Ưu tiên 1: link về BÀI PILLAR/trang trụ cột (đã có riêng ở trên). Ưu tiên 2: link về các bài mồ côi (danh sách ở dưới). Ưu tiên 3: các bài liên quan.\n" .
            "  + Mỗi link phải tự nhiên, đặt trong thân bài, KHÔNG chèn chồng chéo cùng 1 URL. Tuyệt đối không vượt quá {$max} link để giữ nguyên sức mạnh trang.\n\n";
    }

    /**
     * 1.3 Pillar Reverse-linking.
     *
     * After a Child post is created/updated, append (or ensure) a single
     * back-link from the Pillar page to this child so the Silo becomes
     * two-way. Skips if the pillar already links to the child or the pillar
     * cannot be resolved.
     *
     * @param string $pillar_url Absolute Pillar URL.
     * @param int    $child_id   Newly created/updated child post ID.
     * @param string $child_title Child post title (used as anchor).
     * @return bool True when a back-link was added/ensured.
     */
    private function update_pillar_with_backlink($pillar_url, $child_id, $child_title)
    {
        $pillar_url = esc_url_raw($pillar_url);
        $child_id   = (int) $child_id;
        if (empty($pillar_url) || empty($child_id)) {
            return false;
        }

        $pillar_id = url_to_postid($pillar_url);
        if (!$pillar_id) {
            return false;
        }
        // Do not back-link a pillar to itself.
        if ($pillar_id === $child_id) {
            return false;
        }

        $pillar = get_post($pillar_id);
        if (!$pillar || $pillar->post_status !== 'publish') {
            return false;
        }

        $child_url = get_permalink($child_id);
        if (empty($child_url)) {
            return false;
        }

        $content = $pillar->post_content;

        // Already linked -> nothing to do.
        if (stripos($content, $child_url) !== false) {
            return false;
        }

        $anchor = !empty($child_title) ? $child_title : 'xem thêm bài viết liên quan';
        $link   = '<a href="' . esc_url($child_url) . '" target="_blank" rel="dofollow">' . esc_html($anchor) . '</a>';

        // Append a short "Các bài viết trong chuỗi chủ đề" paragraph at the end
        // (or merge into an existing one) to keep the pillar tidy.
        $marker = 'kira-cluster-links';
        if (strpos($content, $marker) !== false) {
            // Append inside the existing cluster block.
            $content = preg_replace(
                '/(<ul[^>]*class=["\'][^"\']*' . $marker . '[^"\']*["\'][^>]*>)/i',
                '$1' . "\n    <li>" . $link . '</li>',
                $content,
                1
            );
            // If the marker class was on a wrapper without a <ul>, just append a list item fallback.
            if (strpos($content, $child_url) === false) {
                $content = preg_replace(
                    '/(<!--\s*' . $marker . '\s*-->)/i',
                    "$1\n<ul class=\"" . $marker . "\">\n    <li>" . $link . "</li>\n</ul>",
                    $content,
                    1
                );
            }
        } else {
            $block =
                "\n\n<!-- " . $marker . " -->\n" .
                "<div class=\"kira-pillar-cluster\" style=\"margin:32px 0;padding:20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;\">\n" .
                "  <strong style=\"display:block;margin-bottom:10px;color:#0f172a;\">📚 Các bài viết trong chuỗi chủ đề này</strong>\n" .
                "  <ul class=\"" . $marker . "\" style=\"margin:0;padding-left:20px;\">\n" .
                "    <li style=\"margin:6px 0;\">" . $link . "</li>\n" .
                "  </ul>\n</div>\n";
            $content .= $block;
        }

        $updated = wp_update_post(array(
            'ID'           => $pillar_id,
            'post_content' => $content,
        ));

        return !is_wp_error($updated) && $updated;
    }

    /**
     * Build the "URL Blacklist" text block injected into AI prompts.
     *
     * @return string
     */
    private function build_url_blacklist_prompt()
    {
        $list = $this->get_url_blacklist();
        if (empty($list)) {
            return '';
        }

        $lines = array();
        foreach ($list as $url) {
            $lines[] = "    * [{$url}]({$url})";
        }

        return "  + BLACKLIST (TUYỆT ĐỐI CẤM SỬ DỤNG CÁC URL NÀY):\n" . implode("\n", $lines) . "\n\n";
    }

    /**
     * Build the Pillar Page link instruction block injected into AI prompts.
     * Enforces Silo structure: the Pillar link MUST appear in the first third
     * of the article to concentrate link juice on the pillar/trunk page.
     *
     * @param string $pillar_url     The absolute Pillar Page URL.
     * @param string $pillar_keyword The target anchor keyword for the Pillar link.
     * @return string Prompt block (empty if no pillar provided).
     */
    private function build_pillar_link_instruction($pillar_url, $pillar_keyword)
    {
        if (empty($pillar_url)) {
            return '';
        }

        $anchor = !empty($pillar_keyword) ? $pillar_keyword : 'trang tổng quan';
        $anchor_safe = esc_html($anchor);

        return
            "  *** LIÊN KẾT TRANG TRỤ CỘT (PILLAR PAGE - SILO STRUCTURE) - BẮT BUỘC ƯU TIÊN CAO NHẤT: ***\n" .
            "  + URL PILLAR: {$pillar_url}\n" .
            "  + TỪ KHÓA ANCHOR PILLAR: {$anchor_safe}\n" .
            "  + QUY TẮC XƯỞNG SỨC MẠNH (LINK JUICE): BẮT BUỘC chèn link Pillar này vào VÙNG 1/3 ĐẦU BÀI VIẾT " .
            "(trong đoạn SAPO hoặc ngay dưới H2 đầu tiên), dùng anchor text tự nhiên chứa từ khóa [{$anchor_safe}].\n" .
            "  + Cú pháp: <a href='{$pillar_url}' target='_blank' rel='dofollow'>Anchor tự nhiên</a>. " .
            "Chỉ chèn ĐÚNG 1 link Pillar, tuyệt đối không trùng lặp để tránh loãng sức mạnh trang trụ cột.\n\n";
    }

    /**
     * Post-process content to guarantee the Pillar link sits in the first third
     * of the article (Silo / link-juice concentration). If the AI already
     * inserted the Pillar URL, leave it as-is. Otherwise inject a natural
     * Pillar link into the first paragraph after the SAPO.
     *
     * @param string $content        Post content HTML.
     * @param string $pillar_url     Absolute Pillar URL.
     * @param string $pillar_keyword Anchor keyword.
     * @return string Modified content.
     */
    private function inject_pillar_link($content, $pillar_url, $pillar_keyword)
    {
        if (empty($content) || empty($pillar_url)) {
            return $content;
        }

        // Already linked to the pillar? Respect AI's placement.
        if (stripos($content, $pillar_url) !== false) {
            return $content;
        }

        $anchor = !empty($pillar_keyword) ? $pillar_keyword : 'tìm hiểu thêm tại trang tổng quan';
        $link   = '<a href="' . esc_url($pillar_url) . '" target="_blank" rel="dofollow">' . esc_html($anchor) . '</a>';

        // Locate the first third of the content by character length.
        $len       = mb_strlen($content, 'UTF-8');
        $third_len = (int) ($len / 3);

        // Try to inject right after the first closing </p> that is within the first third.
        if (preg_match('/<\/p>/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            $pos = (int) $m[0][1] + strlen($m[0][0]);
            if ($pos <= $third_len || $third_len < 50) {
                return mb_substr($content, 0, $pos, 'UTF-8') . "\n<p>Xem chi tiết chuyên sâu " . $link . " để nắm vững toàn bộ bức tranh tổng thể.</p>\n" . mb_substr($content, $pos, null, 'UTF-8');
            }
        }

        // Fallback: prepend a short pillar paragraph at the very top.
        return "<p>Khám phá giải pháp toàn diện qua " . $link . ".</p>\n" . $content;
    }

    /**
     * Remove all links that contain any blacklisted URL fragment.
     * Covers https://, http://, www., protocol-less and trailing-slash variants.
     *
     * @param string $content Post content HTML.
     * @return string Content without blacklisted links.
     */
    private function strip_blacklisted_urls($content)
    {
        if (empty($content)) {
            return $content;
        }

        $list = $this->get_url_blacklist();
        if (empty($list)) {
            return $content;
        }

        // Build regex fragments per URL to catch multiple variants.
        $variants = array();
        foreach ($list as $url) {
            $url_trimmed = trim($url);
            $url_trimmed = rtrim($url_trimmed, '/');
            $url_trimmed = preg_replace('#^https?://#i', '', $url_trimmed);
            $url_trimmed = preg_replace('#^www\.#i', '', $url_trimmed);
            if ($url_trimmed === '') {
                continue;
            }
            $escaped = preg_quote($url_trimmed, '#');
            $variants[] = $escaped;
        }

        if (empty($variants)) {
            return $content;
        }

        $pattern_body = '(?:https?://)?(?:www\.)?(?:' . implode('|', $variants) . ')/?';
        $pattern = '#' . $pattern_body . '#i';

        // 1) Remove complete anchor tags containing a blacklisted URL.
        $content = preg_replace_callback(
            '#<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>.*?</a>#is',
            function ($m) use ($pattern) {
                if (preg_match($pattern, $m[1])) {
                    return '';
                }
                return $m[0];
            },
            $content
        );

        // 2) Remove markdown-style blacklisted links before bare URLs, e.g. [url](url).
        $content = preg_replace_callback(
            '#\[[^\]]*\]\(' . $pattern_body . '\)#i',
            function ($m) {
                return '';
            },
            $content
        );

        // 3) Remove any bare blacklisted URL text left behind.
        $content = preg_replace($pattern, '', $content);

        return $content;
    }

    /**
     * Get the standard CTA box HTML for AI prompts.
     *
     * @param string $keyword The focus keyword.
     * @return string
     */
    private function get_cta_box_prompt($keyword)
    {
        return "  <div style='background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #fff; padding: 30px; border-radius: 12px; text-align: center; margin: 40px 0; box-shadow: 0 10px 25px rgba(0,0,0,0.15);'>\n" .
            "    <strong style='color: #38bdf8; display: block; font-size: 22px; margin-bottom: 10px;'>Bạn Cần Tư Vấn Chuyên Sâu Về " . esc_attr($keyword) . "?</strong>\n" .
            "    <p style='color: #cbd5e1; font-size: 15.5px; line-height: 1.6; margin-bottom: 20px;'>Đừng ngần ngại liên hệ ngay với CyberServices để nhận tư vấn chi tiết, báo giá ưu đãi và giải pháp tối ưu nhất!</p>\n" .
            "    <div style='display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; align-items: center;'>\n" .
            "      <a href='https://cyberservices.vn/lien-he/' target='_blank' rel='noopener noreferrer' style='display: inline-block; background: #0284c7; color: #fff; padding: 12px 22px; border-radius: 8px; font-weight: bold; text-decoration: none;'>Liên Hệ Tư Vấn Ngay</a>\n" .
            "      <a href='https://zalo.me/0979875985' target='_blank' rel='noopener noreferrer' style='display: inline-block; background: #0068ff; color: #fff; padding: 12px 22px; border-radius: 8px; font-weight: bold; text-decoration: none;'>💬 Chat Zalo: 0979.875.985</a>\n" .
            "      <a href='tel:0979875985' style='display: inline-block; background: #16a34a; color: #fff; padding: 12px 22px; border-radius: 8px; font-weight: bold; text-decoration: none;'>📞 Hotline: 0979.875.985</a>\n" .
            "    </div>\n" .
            "  </div>\n";
    }

    /**
     * Insert images into post content after h2 tags.
     *
     * @param string $content     The post content HTML.
     * @param int    $attach_id_1 Attachment ID for image 1.
     * @param int    $attach_id_2 Attachment ID for image 2.
     * @param string $keyword     The focus keyword for alt text.
     * @return string Modified content.
     */
    private function build_image_seo_text($keyword, $title, $role = 'Ảnh')
    {
        $keyword = trim(wp_strip_all_tags((string) $keyword));
        $title   = trim(wp_strip_all_tags((string) $title));
        $role    = trim(wp_strip_all_tags((string) $role));

        if ($keyword === '') {
            $keyword = $title !== '' ? $title : 'hình ảnh SEO';
        }
        if ($title === '') {
            $title = $keyword;
        }
        if ($role === '') {
            $role = 'Ảnh';
        }

        $alt = sprintf('%s - %s %s', $keyword, $role, $title !== $keyword ? 'cho ' . $title : '');
        $alt = trim(preg_replace('/\s+/u', ' ', $alt));

        $seo_title = sprintf('%s | %s', $role, $title);
        $seo_title = trim(preg_replace('/\s+/u', ' ', $seo_title));

        return array(
            'alt'  => $alt,
            'title'=> $seo_title,
        );
    }

    private function insert_images_into_content($content, $attach_id_1, $attach_id_2, $keyword)
    {
        $parts       = explode('</h2>', $content);
        $new_content = '';

        if (count($parts) > 1) {
            foreach ($parts as $index => $part) {
                $new_content .= $part;
                if ($index < count($parts) - 1) {
                    $new_content .= '</h2>';
                }
                if ($index === 0 && $attach_id_1) {
                    $img_url_1  = wp_get_attachment_url($attach_id_1);
                    $seo_img_1  = $this->build_image_seo_text($keyword, $keyword, 'Ảnh 1');
                    $new_content .= "\n\n<figure class='wp-block-image size-large' style='text-align:center; margin: 25px 0;'><img src='" . esc_url($img_url_1) . "' alt='" . esc_attr($seo_img_1['alt']) . "' title='" . esc_attr($seo_img_1['title']) . "' style='border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width:100%; height:auto;' /></figure>\n\n";
                }
                if (($index === 2 || (count($parts) == 3 && $index === 1)) && $attach_id_2) {
                    $img_url_2  = wp_get_attachment_url($attach_id_2);
                    $seo_img_2  = $this->build_image_seo_text($keyword, $keyword, 'Ảnh 2');
                    $new_content .= "\n\n<figure class='wp-block-image size-large' style='text-align:center; margin: 25px 0;'><img src='" . esc_url($img_url_2) . "' alt='" . esc_attr($seo_img_2['alt']) . "' title='" . esc_attr($seo_img_2['title']) . "' style='border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width:100%; height:auto;' /></figure>\n\n";
                }
            }
            return $new_content;
        }

        return $content;
    }

    public function ajax_generate_post_text()
    {
        check_ajax_referer('kira_ai_generate_nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $api_key = get_option('kira_ai_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error('Vui lòng cấu hình API Key trong mục Cyber Services Content trước khi sử dụng.');
        }

        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
        $keyword = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';
        $prompt = isset($_POST['prompt']) ? sanitize_textarea_field($_POST['prompt']) : '';
        $post_status = isset($_POST['post_status']) ? sanitize_text_field($_POST['post_status']) : 'draft';
        $scheduled_time = isset($_POST['scheduled_time']) ? sanitize_text_field($_POST['scheduled_time']) : '';
        // Silo & Pillar Page inputs
        $pillar_url = isset($_POST['pillar_url']) ? esc_url_raw($_POST['pillar_url']) : '';
        $pillar_keyword = isset($_POST['pillar_keyword']) ? sanitize_text_field($_POST['pillar_keyword']) : '';
        // 1.1 Topic Cluster: cap supporting internal links to protect link equity.
        $max_internal_links = isset($_POST['max_internal_links']) ? (int) $_POST['max_internal_links'] : 0;
        $max_internal_links = ($max_internal_links > 0) ? min($max_internal_links, 10) : 0;

        if (empty($keyword)) {
            wp_send_json_error('Vui lòng điền từ khóa chính.');
        }

        if (empty($prompt)) {
            $prompt = 'Viết bài viết chi tiết, chuyên sâu, phân tích toàn diện chuẩn SEO về chủ đề: ' . $keyword;
        }

        $text_model = get_option('kira_ai_text_model', 'kira-3.5-flash');
        $base_url = 'https://kiraai.vn';

        $link_context = $this->build_internal_link_context($keyword, 0, $max_internal_links);
        $existing_titles_str = $link_context['existing_titles_str'];
        $landing_pages_json  = $link_context['landing_pages_json'];
        $related_posts_json  = $link_context['related_posts_json'];
        $orphan_posts_json   = $link_context['orphan_posts_json'];

        $system_msg = 'Bạn là một Chuyên gia SEO Master, một Copywriter thực chiến xuất sắc. Nhiệm vụ của bạn là tạo ra một bài viết chuẩn SEO Top 1 Google, mang lại giá trị thực tế cao nhất cho độc giả. Bạn luôn tuân thủ tuyệt đối các quy tắc định dạng và trả về kết quả JSON chuẩn.';

        $ai_prompt = "1. TIÊU ĐỀ BÀI VIẾT & DUNG LƯỢNG TỐI ƯU:\n" .
            "- Từ khóa chính: {$keyword}\n" .
            "- Yêu cầu chủ đề: {$prompt}\n" .
            "- TIÊU ĐỀ BÀI VIẾT (THẺ H1 - HIỂN THỊ TRÊN WEBSITE): Chứa từ khóa chính tự nhiên, hấp dẫn và bao quát chiều sâu nội dung. Độ dài tiêu chuẩn: 10 - 15 từ.\n" .
            "- DUNG LƯỢNG BÀI VIẾT: ~1.300 từ (dao động 1.200 - 1.400 từ), tránh viết lan man, tập trung phân tích sâu.\n" .
            "- CẤU TRÚC HEADING (LINH HOẠT & LOGIC):\n" .
            "  + Triển khai H2, H3 theo luồng giải quyết vấn đề thực tế, tuyệt đối KHÔNG ép khuôn số lượng thẻ.\n" .
            "  + Ý đơn giản: Giải thích trực diện ngay dưới H2.\n" .
            "  + Ý phức tạp hoặc quy trình nhiều bước: Phân tách thành các H3 để làm rõ.\n\n" .

            "2. CHIẾN LƯỢC LIÊN KẾT NỘI BỘ (INTERNAL LINK) & BLACKLIST:\n" .
            "- Bài viết đã có: [{$existing_titles_str}] (Phát triển góc nhìn mới, không sao chép lại).\n" .
            "- LANDING PAGE MỤC TIÊU: {$landing_pages_json}\n" .
            "- BÀI VIẾT BỔ TRỢ: {$related_posts_json}\n" .
            "- BÀI VIẾT MỒ CÔI (chưa có link nội bộ trỏ tới, ƯU TIÊN link tới để gom vào cụm chủ đề): {$orphan_posts_json}\n" .
            "- QUY TẮC CHÈN LINK:\n" .
            "  + Thứ tự ưu tiên: BẮT BUỘC chèn internal link trỏ về bài PILLAR của chủ đề này TRƯỚC, sau đó mới gắn link sang các bài viết liên quan khác (nếu ngữ cảnh tự nhiên).\n" .
            "  + Phân bổ đều: 1-2 link Landing Page/Pillar + 1-2 link bài viết liên quan rải rác trong thân bài (không dồn link vào cùng 1 đoạn).\n" .
            "  + Anchor Text tự nhiên, chứa ngữ cảnh hoặc từ khóa liên quan (TUYỆT ĐỐI KHÔNG dùng anchor text chung chung như 'tại đây', 'xem thêm', 'link').\n" .
            "  + Cú pháp chuẩn: <a href='URL' target='_blank' title='Title mô tả'>Anchor text</a>.\n" .
            $this->build_url_blacklist_prompt() .
            $this->build_cluster_link_instruction($max_internal_links) .
            $this->build_pillar_link_instruction($pillar_url, $pillar_keyword) .
            "3. TIÊU CHUẨN GIỌNG VĂN CHUYÊN GIA (EEAT) & CẤM DẤU VẾT AI:\n" .
            "- VAI TRÒ: Chuyên gia an toàn thông tin & tư vấn giải pháp doanh nghiệp. Lời văn khách quan, sắc bén, mang tính thực chiến cao.\n" .
            "- CẤM TUYỆT ĐỐI: Không nhắc tên bất kỳ AI hay nền tảng nào ('Tôi là Kira', 'OpenAI', 'Gemini', 'Kira AI', 'kiraai.vn', 'ChatGPT', 'AI language model'...). Không viết câu mở đầu/kết bài dạng chào hỏi, thông báo hay xã giao.\n" .
            "- MỞ ĐẦU (SAPO): Bắt buộc có đoạn dẫn nhập (SAPO) bằng thẻ <p> hoàn chỉnh NGAY ĐẦU BÀI, TRƯỚC khi xuất hiện bất kỳ thẻ Heading (H2) nào. Đoạn SAPO phải đi thẳng vào vấn đề, chứa từ khóa [{$keyword}] trong 150 ký tự đầu tiên và dẫn dắt mạch lạc vào thân bài.\n" .
            "- MỤC TIÊU TỪ KHÓA SAU H2 ĐẦU TIÊN: Ngay sau thẻ H2 đầu tiên của bài viết, câu/đoạn mở đầu bên dưới bắt buộc phải xuất hiện chính xác từ khóa [{$keyword}] một cách tự nhiên.\n" .
            "- TRÌNH BÀY & VĂN PHONG: Ngắt đoạn thoáng, mỗi đoạn chỉ dài từ 2 - 3 câu để tối ưu cho người đọc quét thông tin. Giọng văn thực chiến, tự nhiên, gãy gọn, đi thẳng vào trọng tâm. Loại bỏ hoàn toàn các câu từ sáo rỗng, mở bài lan man hoặc dịch máy gượng gạo.\n\n" .

            "4. CẤU TRÚC HTML & BỐ CỤC UI/UX CHUẨN SEO:\n" .
            "- HEADING: Chỉ dùng <h2> cho ý chính và <h3> cho ý phụ. CẤM dùng <h1>. CẤM nhảy cóc cấp độ (như H4 sau H2).\n" .
            "- BOX NỔI BẬT (Callout/Tip Box): Sử dụng thẻ <div style='...'> hoặc <aside style='...'> kèm inline CSS tinh gọn. Tiêu đề box dùng <strong>. TUYỆT ĐỐI KHÔNG dùng thẻ heading (H2/H3/H4) cho box callout.\n" .
            "- BẢNG BIỂU (TABLE): Bắt buộc render đúng chuẩn HTML kèm Inline CSS dàn đều 100% chiều rộng:\n" .
            "  + Thẻ table: <table style='width: 100%; border-collapse: collapse; margin: 24px 0; table-layout: fixed;'>\n" .
            "  + Thẻ th: <th style='border: 1px solid #e2e8f0; padding: 12px 16px; background: #f8fafc; font-weight: 600; text-align: left;'>\n" .
            "  + Thẻ td: <td style='border: 1px solid #e2e8f0; padding: 12px 16px; vertical-align: top; word-break: break-word;'>\n" .
            "  + Số lượng cột: Tất cả thẻ <th> và <td> trên từng hàng phải đồng nhất số lượng, tự động chia đều tỷ lệ width giữa các cột.\n" .
            "- DANH SÁCH & NHẤN MẠNH: Dùng <ul>, <ol>, <li> để liệt kê; in đậm <strong> các từ khóa quan trọng rải đều trong bài để tăng trải nghiệm đọc lướt.\n" .
            "- MỤC FAQ: Tiêu đề là <h2>Câu hỏi thường gặp về {$keyword}</h2>. Mỗi câu hỏi là 1 thẻ <h3>, câu trả lời là thẻ <p> dài, giải thích cặn kẽ (từ 4 - 6 câu hỏi đáp).\n\n" .

            "5. KHỐI CTA LIÊN HỆ (CUỐI BÀI):\n" .
            "- Đặt ở phần kết thúc bài viết (nằm sau toàn bộ mục FAQ):\n" .
            $this->get_cta_box_prompt($keyword) . "\n\n" .

            "6. ĐỊNH DẠNG ĐẦU RA JSON BẮT BUỘC (JSON HỢP LỆ 100%):\n" .
            "- Trả về DUY NHẤT 1 đối tượng JSON trên MỘT DÒNG DUY NHẤT, không xuống dòng, không bọc markdown (```json ... ```), không kèm văn bản giải thích.\n" .
            "- Bên trong value của \"content\", MỌI ký tự xuống dòng PHẢI chuyển thành \\n, MỌI dấu nháy kép (\" lẫn \") PHẢI escape thành \\\\\", KHÔNG dùng dấu nháy kép trần. Dùng nháy đơn ' cho thuộc tính HTML.\n" .
            "- \"title\" (THẺ H1 - HIỂN THỊ TRÊN WEBSITE): 10 - 15 từ, chứa từ khóa chính tự nhiên, hấp dẫn, bao quát nội dung.\n" .
            "- \"seo_title\" (TIÊU ĐỀ SEO - HIỂN THỊ GOOGLE SERP): 50 - 60 ký tự, từ khóa chính sát đầu câu, kèm yếu tố kích thích CTR (số liệu, lợi ích cụ thể hoặc từ khẳng định giá trị).\n" .
            "- \"seo_description\" (MÔ TẢ SEO - HIỂN THỊ GOOGLE SERP): 140 - 155 ký tự (tính cả khoảng trắng), chứa từ khóa chính + ít nhất 1 từ khóa phụ liên quan, nêu bật giải pháp/USP.\n" .
            "- \"content\" (NỘI DUNG HTML): ~1.300 từ (1.200 - 1.400), mở đầu bằng <p> SAPO hoàn chỉnh TRƯỚC thẻ H2 đầu tiên.\n" .
            "{\"title\": \"Tiêu đề bài viết 10-15 từ chứa {$keyword}\", \"content\": \"<p>SAPO dẫn dắt...</p><h2>...</h2>... DÙNG \\n CHO XUỐNG DÒNG, ESCAPE MỌI NHÁY KÉP BÊN TRONG\", \"seo_title\": \"{$keyword} + yếu tố CTR (50-60 ký tự)\", \"seo_description\": \"Mô tả 140-155 ký tự: từ khóa chính + từ khóa phụ + USP\"}";

        $log_entry = array(
            'time' => current_time('Y-m-d H:i:s'),
            'post_type' => $post_type,
            'keyword' => $keyword,
            'prompt' => $prompt,
            'model' => $text_model,
            'status' => 'pending',
            'input_full' => "System message: " . $system_msg . "\n\nUser prompt:\n" . $ai_prompt,
            'output' => '',
            'error' => ''
        );

        $additional_args = array(
            'response_format' => array('type' => 'json_object')
        );
        $response = $this->call_kira_api($ai_prompt, $system_msg, $api_key, $text_model, $base_url, $additional_args);

        if (is_wp_error($response)) {
            $log_entry['status'] = 'error';
            $log_entry['error'] = $response->get_error_message();
            $this->add_api_log($log_entry);
            wp_send_json_error('Kira AI API Error: ' . $response->get_error_message());
        }

        $data = $this->clean_and_decode_json($response);
        if (!$data || empty($data['title']) || empty($data['content'])) {
            $log_entry['status'] = 'error';
            $log_entry['output'] = $response;
            $log_entry['error'] = 'AI returned invalid JSON format.';
            $this->add_api_log($log_entry);
            wp_send_json_error('Lỗi: AI trả về định dạng không đúng chuẩn JSON yêu cầu.');
        }

        $final_post_status = in_array($post_status, array('publish', 'draft', 'future')) ? $post_status : 'draft';

        $post_data = array(
            'post_title' => sanitize_text_field($data['title']),
            'post_content' => $this->inject_pillar_link(
                $this->strip_blacklisted_urls(wp_kses_post($data['content'])),
                $pillar_url,
                $pillar_keyword
            ),
            'post_status' => $final_post_status,
            'post_type' => $post_type,
            'post_author' => get_current_user_id()
        );

        // Xử lý lên lịch xuất bản trong tương lai (Scheduled Publishing)
        // Chuẩn hóa thời gian theo múi giờ của site (wp_timezone) để tránh lệch giờ
        // giữa PHP server timezone (thường UTC) và WordPress timezone (vd: Asia/Ho_Chi_Minh).
        if ($final_post_status === 'future' && !empty($scheduled_time)) {
            $scheduled_time = str_replace('T', ' ', $scheduled_time);
            $local_dt = DateTime::createFromFormat('Y-m-d H:i:s', $scheduled_time, wp_timezone());

            if ($local_dt instanceof DateTime) {
                $utc_timestamp = $local_dt->getTimestamp();

                if ($utc_timestamp > current_time('timestamp', 1)) {
                    $post_data['post_date']     = $local_dt->format('Y-m-d H:i:s');
                    $post_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', $utc_timestamp);
                    $post_data['edit_date']     = true;
                } else {
                    // Thời gian đã qua -> đăng ngay lập tức
                    $post_data['post_status'] = 'publish';
                }
            } else {
                // Chuỗi thời gian không hợp lệ -> an toàn là đăng ngay
                $post_data['post_status'] = 'publish';
            }
        }

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id) || !$post_id) {
            $log_entry['status'] = 'error';
            $log_entry['output'] = $response;
            $log_entry['error'] = 'Could not create post in database.';
            $this->add_api_log($log_entry);
            wp_send_json_error('Không thể tạo bài viết trong cơ sở dữ liệu.');
        }

        $seo_title = !empty($data['seo_title']) ? sanitize_text_field($data['seo_title']) : sanitize_text_field($data['title']);
        $seo_desc = !empty($data['seo_description']) ? sanitize_text_field($data['seo_description']) : '';

        update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);
        update_post_meta($post_id, 'rank_math_title', $seo_title);
        update_post_meta($post_id, 'rank_math_description', $seo_desc);

        update_post_meta($post_id, '_yoast_wpseo_focuskw', $keyword);
        update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $seo_desc);

        $log_entry['status'] = 'success';
        $log_entry['output'] = $response;
        $this->add_api_log($log_entry);

        // Đánh dấu bài viết do AI tạo để phục vụ thống kê Dashboard
        update_post_meta($post_id, '_kira_ai_generated', 1);

        // 1.3 Pillar Reverse-linking: ensure the Pillar page links back to this
        // newly created child post so the Silo is two-way.
        if (!empty($pillar_url)) {
            $this->update_pillar_with_backlink($pillar_url, $post_id, $data['title']);
        }

        wp_send_json_success(array(
            'post_id' => $post_id,
            'title' => $data['title'],
            'status' => $post_data['post_status'],
            'scheduled_date' => !empty($post_data['post_date']) ? $post_data['post_date'] : '',
            'edit_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
            'view_url' => get_permalink($post_id)
        ));
    }

    public function ajax_generate_post_image()
    {
        check_ajax_referer('kira_ai_generate_nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $api_key = get_option('kira_ai_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error('Vui lòng cấu hình API Key.');
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';

        if (!$post_id || empty($title)) {
            wp_send_json_error('Thiếu thông tin ID bài viết hoặc tiêu đề để vẽ ảnh.');
        }

        $keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true);
        if (empty($keyword)) $keyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        if (empty($keyword)) $keyword = $title;

        $image_model = get_option('kira_ai_image_model', 'kira-2.5-flash-image');
        $base_url = 'https://kiraai.vn';

        // 1. Ảnh đại diện Feature Image
        $feat_prompt = $this->build_realistic_photo_prompt("chủ đề: " . $title . ". Bố cục đẹp mắt, bối cảnh thực tế, ánh sáng ban ngày tự nhiên");
        $feat_response = $this->call_kira_image_api($feat_prompt, $api_key, $image_model, $base_url);
        
        if (!is_wp_error($feat_response)) {
            $feat_attach_id = $this->save_base64_image_as_webp_attachment($feat_response['b64_json'], $post_id, $title, $this->build_image_seo_text($keyword, $title, 'Ảnh đại diện')['alt']);
            if ($feat_attach_id) {
                set_post_thumbnail($post_id, $feat_attach_id);
            }
        }

        // 2. Ảnh thân bài 1 & 2
        $img1_prompt = $this->build_realistic_photo_prompt("góc nhìn toàn cảnh, phong cảnh bối cảnh thực tế sống động cho nội dung: " . $title);
        $img2_prompt = $this->build_realistic_photo_prompt("góc chụp cận cảnh, chi tiết chân thực, ánh sáng studio/đời thực cho chủ đề: " . $title);
        
        $attach_id_1 = 0;
        $attach_id_2 = 0;

        $img1_response = $this->call_kira_image_api($img1_prompt, $api_key, $image_model, $base_url);
        if (!is_wp_error($img1_response)) {
            $attach_id_1 = $this->save_base64_image_as_webp_attachment($img1_response['b64_json'], $post_id, $title . ' phần 1', $this->build_image_seo_text($keyword, $title, 'Chi tiết 1')['alt']);
        }

        $img2_response = $this->call_kira_image_api($img2_prompt, $api_key, $image_model, $base_url);
        if (!is_wp_error($img2_response)) {
            $attach_id_2 = $this->save_base64_image_as_webp_attachment($img2_response['b64_json'], $post_id, $title . ' phần 2', $this->build_image_seo_text($keyword, $title, 'Chi tiết 2')['alt']);
        }

        $content = get_post_field('post_content', $post_id);
        $new_content = $this->insert_images_into_content($content, $attach_id_1, $attach_id_2, $keyword);

        if ($new_content !== $content) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $new_content
            ));
        }

        wp_send_json_success(array(
            'redirect_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
            'post_id' => $post_id
        ));
    }

    public function ajax_process_existing_post()
    {
        check_ajax_referer('kira_ai_generate_nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $api_key = get_option('kira_ai_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error('Vui lòng cấu hình API Key.');
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
        $custom_prompt = isset($_POST['custom_prompt']) ? sanitize_textarea_field($_POST['custom_prompt']) : '';
        // Silo & Pillar Page inputs
        $pillar_url = isset($_POST['pillar_url']) ? esc_url_raw($_POST['pillar_url']) : '';
        $pillar_keyword = isset($_POST['pillar_keyword']) ? sanitize_text_field($_POST['pillar_keyword']) : '';
        // 1.1 Topic Cluster: cap supporting internal links to protect link equity.
        $max_internal_links = isset($_POST['max_internal_links']) ? (int) $_POST['max_internal_links'] : 0;
        $max_internal_links = ($max_internal_links > 0) ? min($max_internal_links, 10) : 0;

        if (!$post_id) {
            wp_send_json_error('Thiếu ID bài viết.');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Bạn không có quyền chỉnh sửa bài viết này.');
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Bài viết không tồn tại.');
        }

        $title = $post->post_title;
        $content = wp_strip_all_tags($post->post_content);

        $text_model = get_option('kira_ai_text_model', 'kira-3.5-flash');
        $image_model = get_option('kira_ai_image_model', 'kira-2.5-flash-image');
        $base_url = 'https://kiraai.vn';

        if ($action_type === 'gen_image') {
            $desc = "bài viết: " . $title . (!empty($custom_prompt) ? " (" . $custom_prompt . ")" : "");
            $image_prompt = $this->build_realistic_photo_prompt($desc);

            $keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true);
            if (empty($keyword)) {
                $keyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
            }
            if (empty($keyword)) {
                $keyword = $title;
            }

            $log_entry = array(
                'time' => current_time('Y-m-d H:i:s'),
                'post_type' => $post->post_type,
                'keyword' => 'Tạo ảnh đời thực có Logo: ' . $title,
                'prompt' => $image_prompt,
                'model' => $image_model,
                'status' => 'pending',
                'input_full' => "Image Generation Prompt: " . $image_prompt,
                'output' => '',
                'error' => ''
            );

            $image_response = $this->call_kira_image_api($image_prompt, $api_key, $image_model, $base_url);

            if (is_wp_error($image_response)) {
                $log_entry['status'] = 'error';
                $log_entry['error'] = $image_response->get_error_message();
                $this->add_api_log($log_entry);
                wp_send_json_error('Lỗi sinh ảnh AI: ' . $image_response->get_error_message());
            }

            $attach_id = $this->save_base64_image_as_webp_attachment(
                $image_response['b64_json'],
                $post_id,
                $title,
                $keyword . ' - Ảnh đại diện'
            );

            if ($attach_id) {
                set_post_thumbnail($post_id, $attach_id);
                $log_entry['status'] = 'success';
                $log_entry['output'] = "Đã tạo và gán ảnh đại diện WebP có Logo thành công. Attachment ID: " . $attach_id;
                $this->add_api_log($log_entry);
                wp_send_json_success('Tạo ảnh đại diện đời thực thành công.');
            } else {
                $log_entry['status'] = 'error';
                $log_entry['error'] = 'Không thể lưu ảnh WebP vào Media Library.';
                $this->add_api_log($log_entry);
                wp_send_json_error('Không thể lưu ảnh WebP vào Media Library.');
            }

        } elseif ($action_type === 'rewrite_title') {
            $system_msg = 'Bạn là một chuyên gia viết tiêu đề và tối ưu hóa SEO. Bạn luôn trả về duy nhất tiêu đề mới dưới dạng văn bản thuần túy.';

            $ai_prompt = "Hãy viết lại tiêu đề bài viết sau đây dựa trên tiêu đề cũ và nội dung bài viết. Tiêu đề mới phải hấp dẫn, cuốn hút người đọc và tối ưu hóa chuẩn SEO.\n\n" .
                "Tiêu đề cũ: {$title}\n\n" .
                "Tóm tắt nội dung bài viết:\n" . wp_html_excerpt($content, 400) . "\n\n";

            if (!empty($custom_prompt)) {
                $ai_prompt .= "Yêu cầu bổ sung của người dùng: {$custom_prompt}\n\n";
            }

            $ai_prompt .= "Lưu ý quan trọng: Chỉ trả về tiêu đề mới dạng văn bản thuần túy (không ngoặc kép, không giải thích).";

            $log_entry = array(
                'time' => current_time('Y-m-d H:i:s'),
                'post_type' => $post->post_type,
                'keyword' => 'Viết lại Title: ' . $title,
                'prompt' => $custom_prompt,
                'model' => $text_model,
                'status' => 'pending',
                'input_full' => "System message: " . $system_msg . "\n\nUser prompt:\n" . $ai_prompt,
                'output' => '',
                'error' => ''
            );

            $response = $this->call_kira_api($ai_prompt, $system_msg, $api_key, $text_model, $base_url);

            if (is_wp_error($response)) {
                $log_entry['status'] = 'error';
                $log_entry['error'] = $response->get_error_message();
                $this->add_api_log($log_entry);
                wp_send_json_error('Kira AI API Error: ' . $response->get_error_message());
            }

            $new_title = trim($response);
            $new_title = trim($new_title, '"\'');

            $updated = wp_update_post(array(
                'ID' => $post_id,
                'post_title' => sanitize_text_field($new_title)
            ));

            if (is_wp_error($updated) || !$updated) {
                $log_entry['status'] = 'error';
                $log_entry['error'] = 'Could not update post title in database.';
                $this->add_api_log($log_entry);
                wp_send_json_error('Không thể cập nhật tiêu đề mới.');
            }

            $log_entry['status'] = 'success';
            $log_entry['output'] = $new_title;
            $this->add_api_log($log_entry);

            wp_send_json_success('Viết lại tiêu đề thành công.');

        } elseif ($action_type === 'rewrite_content') {
            $system_msg = 'Bạn là một Chuyên gia SEO Master, một Copywriter thực chiến xuất sắc. Nhiệm vụ của bạn là tối ưu và viết lại bài viết chuẩn SEO Top 1 Google. Bạn luôn tuân thủ tuyệt đối các quy tắc định dạng và trả về kết quả JSON chuẩn.';

            $keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true);
            if (empty($keyword)) {
                $keyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
            }
            if (empty($keyword)) {
                $keyword = $title;
            }

            $link_context = $this->build_internal_link_context($keyword, $post_id, $max_internal_links);
            $existing_titles_str = $link_context['existing_titles_str'];
            $landing_pages_json  = $link_context['landing_pages_json'];
            $related_posts_json  = $link_context['related_posts_json'];
            $orphan_posts_json   = $link_context['orphan_posts_json'];

            $ai_prompt = "Nhiệm vụ của bạn là VIẾT LẠI và tối ưu toàn diện bài viết bằng tiếng Việt, chuẩn SEO Top 1 Google:\n\n" .
                "Tiêu đề cũ: {$title}\n\n" .
                "Nội dung cũ cần viết lại:\n" . wp_html_excerpt($post->post_content, 15000) . "\n\n" .
                "1. TIÊU ĐỀ BÀI VIẾT & DUNG LƯỢNG TỐI ƯU:\n" .
                "- Từ khóa chính: {$keyword}\n" .
                "- Yêu cầu chỉnh sửa thêm: {$custom_prompt}\n" .
                "- TIÊU ĐỀ BÀI VIẾT (THẺ H1 - HIỂN THỊ TRÊN WEBSITE): 10 - 15 từ, chứa từ khóa chính tự nhiên, hấp dẫn, bao quát chiều sâu nội dung.\n" .
                "- DUNG LƯỢNG BÀI VIẾT: ~1.300 từ (dao động 1.200 - 1.400 từ), tránh lan man, tập trung phân tích sâu.\n" .
                "- CẤU TRÚC HEADING (LINH HOẠT & LOGIC): Triển khai H2, H3 theo luồng giải quyết vấn đề thực tế, tuyệt đối KHÔNG ép khuôn số lượng thẻ. Ý đơn giản: Giải thích trực diện ngay dưới H2. Ý phức tạp hoặc quy trình nhiều bước: Phân tách thành các H3 để làm rõ.\n\n" .
                
                "2. CHIẾN LƯỢC INTERNAL LINK:\n" .
                "- Website ĐÃ CÓ các bài viết sau: [{$existing_titles_str}].\n" .
                "- DANH SÁCH LANDING PAGE MỤC TIÊU: {$landing_pages_json}\n" .
                "- DANH SÁCH BÀI VIẾT BỔ TRỢ: {$related_posts_json}\n" .
                "- BÀI VIẾT MỒ CÔI (chưa có link nội bộ trỏ tới, ƯU TIÊN link tới để gom vào cụm chủ đề): {$orphan_posts_json}\n" .
                "- Thứ tự ưu tiên: BẮT BUỘC chèn internal link trỏ về bài PILLAR của chủ đề này TRƯỚC, sau đó mới gắn link sang các bài viết liên quan khác (nếu ngữ cảnh tự nhiên).\n" .
                "- BẮT BUỘC chèn ít nhất 1-2 liên kết trỏ về LANDING PAGE mục tiêu/Pillar và 1-2 link bài viết liên quan.\n" .
                $this->build_url_blacklist_prompt() .
                $this->build_cluster_link_instruction($max_internal_links) .
                $this->build_pillar_link_instruction($pillar_url, $pillar_keyword) .
                "3. QUY TẮC MỞ ĐẦU (SAPO) & VĂN PHONG:\n" .
                "- Bắt buộc có đoạn <p> SAPO hoàn chỉnh NGAY ĐẦU BÀI, TRƯỚC thẻ H2 đầu tiên, chứa từ khóa [{$keyword}] trong 150 ký tự đầu tiên và dẫn dắt mạch lạc vào thân bài.\n" .
                "- MỤC TIÊU TỪ KHÓA SAU H2 ĐẦU TIÊN: Ngay sau thẻ H2 đầu tiên của bài viết, câu/đoạn mở đầu bên dưới bắt buộc phải xuất hiện chính xác từ khóa [{$keyword}] một cách tự nhiên.\n" .
                "- TRÌNH BÀY & VĂN PHONG: Ngắt đoạn thoáng, mỗi đoạn chỉ dài từ 2 - 3 câu để tối ưu cho người đọc quét thông tin. Giọng văn thực chiến, tự nhiên, gãy gọn, đi thẳng vào trọng tâm. Loại bỏ hoàn toàn các câu từ sáo rỗng, mở bài lan man hoặc dịch máy gượng gạo.\n\n" .
                
                "4. YÊU CẦU BỐ CỤC:\n" .
                "- Phân cấp thẻ <h2>, <h3> logic. KHÔNG dùng <h1> trong thân bài.\n" .
                "- BẢNG BIỂU (TABLE): Bắt buộc render đúng chuẩn HTML kèm Inline CSS dàn đều 100% chiều rộng:\n" .
                "  + Thẻ table: <table style='width: 100%; border-collapse: collapse; margin: 24px 0; table-layout: fixed;'>\n" .
                "  + Thẻ th: <th style='border: 1px solid #e2e8f0; padding: 12px 16px; background: #f8fafc; font-weight: 600; text-align: left;'>\n" .
                "  + Thẻ td: <td style='border: 1px solid #e2e8f0; padding: 12px 16px; vertical-align: top; word-break: break-word;'>\n" .
                "  + Số lượng cột: Tất cả thẻ <th> và <td> trên từng hàng phải đồng nhất số lượng, tự động chia đều tỷ lệ width giữa các cột.\n" .
                "- BẮT BUỘC có ít nhất 1 Bảng biểu (<table>) so sánh/tổng hợp và các Box Callout tóm tắt ý chính.\n" .
                "- Có mục FAQ (4-6 câu hỏi đáp dạng <h3> + <p> dài) ở gần cuối bài.\n\n" .
                
                "5. KHỐI KÊU GỌI HÀNH ĐỘNG & LIÊN HỆ (CTA BOX - BẮT BUỘC Ở CUỐI BÀI):\n" .
                "- BẮT BUỘC chèn chính xác khối CTA sau:\n" .
                $this->get_cta_box_prompt($keyword) . "\n" .
                
                "6. KỶ LUẬT ĐỊNH DẠNG ĐẦU RA JSON:\n" .
                "- Trả về JSON chuẩn với các key: \"title\" (THẺ H1: 10-15 từ), \"content\" (HTML thuần ~1.300 từ, mở đầu bằng <p> SAPO trước H2), \"seo_title\" (50-60 ký tự, từ khóa sát đầu câu + yếu tố CTR), \"seo_description\" (140-155 ký tự, từ khóa chính + từ khóa phụ + USP).";

            $log_entry = array(
                'time'       => current_time('Y-m-d H:i:s'),
                'post_type'  => $post->post_type,
                'keyword'    => 'Viết lại & Tối ưu chuẩn SEO: ' . $title,
                'prompt'     => $custom_prompt,
                'model'      => $text_model,
                'status'     => 'pending',
                'input_full' => "System message: " . $system_msg . "\n\nUser prompt:\n" . $ai_prompt,
                'output'     => '',
                'error'      => ''
            );

            $additional_args = array(
                'response_format' => array('type' => 'json_object')
            );
            $response = $this->call_kira_api($ai_prompt, $system_msg, $api_key, $text_model, $base_url, $additional_args);

            if (is_wp_error($response)) {
                $log_entry['status'] = 'error';
                $log_entry['error'] = $response->get_error_message();
                $this->add_api_log($log_entry);
                wp_send_json_error('Kira AI API Error: ' . $response->get_error_message());
            }

            $data = $this->clean_and_decode_json($response);
            if (!$data || empty($data['content'])) {
                $log_entry['status'] = 'error';
                $log_entry['error'] = 'AI returned empty or invalid JSON format.';
                $this->add_api_log($log_entry);
                wp_send_json_error('Lỗi: AI trả về định dạng không đúng chuẩn JSON.');
            }

            $new_post_title = !empty($data['title']) ? sanitize_text_field($data['title']) : $title;
            $new_post_content = $this->inject_pillar_link(
                $this->strip_blacklisted_urls(wp_kses_post($data['content'])),
                $pillar_url,
                $pillar_keyword
            );

            $image_model = get_option('kira_ai_image_model', 'kira-2.5-flash-image');

            $feat_prompt = $this->build_realistic_photo_prompt("chủ đề: " . $new_post_title . ". Ánh sáng tự nhiên chân thực, bố cục hài hòa");
            $feat_response = $this->call_kira_image_api($feat_prompt, $api_key, $image_model, $base_url);
            if (!is_wp_error($feat_response)) {
                $feat_attach_id = $this->save_base64_image_as_webp_attachment($feat_response['b64_json'], $post_id, $new_post_title, $this->build_image_seo_text($keyword, $new_post_title, 'Ảnh đại diện')['alt']);
                if ($feat_attach_id) {
                    set_post_thumbnail($post_id, $feat_attach_id);
                }
            }

            $img1_prompt = $this->build_realistic_photo_prompt("bối cảnh rộng thực tế về: " . $new_post_title);
            $img2_prompt = $this->build_realistic_photo_prompt("góc chụp chi tiết thực tế về: " . $new_post_title);
            
            $attach_id_1 = 0;
            $attach_id_2 = 0;

            $img1_response = $this->call_kira_image_api($img1_prompt, $api_key, $image_model, $base_url);
            if (!is_wp_error($img1_response)) {
                $attach_id_1 = $this->save_base64_image_as_webp_attachment($img1_response['b64_json'], $post_id, $new_post_title . ' phần 1', $this->build_image_seo_text($keyword, $new_post_title, 'Chi tiết 1')['alt']);
            }

            $img2_response = $this->call_kira_image_api($img2_prompt, $api_key, $image_model, $base_url);
            if (!is_wp_error($img2_response)) {
                $attach_id_2 = $this->save_base64_image_as_webp_attachment($img2_response['b64_json'], $post_id, $new_post_title . ' phần 2', $this->build_image_seo_text($keyword, $new_post_title, 'Chi tiết 2')['alt']);
            }

            $final_content = $this->insert_images_into_content($new_post_content, $attach_id_1, $attach_id_2, $keyword);

            $updated = wp_update_post(array(
                'ID'           => $post_id,
                'post_title'   => $new_post_title,
                'post_content' => $final_content
            ));

            if (is_wp_error($updated) || !$updated) {
                $log_entry['status'] = 'error';
                $log_entry['error'] = 'Could not update post content in database.';
                $this->add_api_log($log_entry);
                wp_send_json_error('Không thể cập nhật nội dung mới.');
            }

            update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);
            if (!empty($data['seo_title'])) {
                update_post_meta($post_id, 'rank_math_title', sanitize_text_field($data['seo_title']));
                update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($data['seo_title']));
            }
            if (!empty($data['seo_description'])) {
                update_post_meta($post_id, 'rank_math_description', sanitize_text_field($data['seo_description']));
                update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($data['seo_description']));
            }

            // 1.3 Pillar Reverse-linking: ensure the Pillar page links back to
            // this rewritten child post so the Silo stays two-way.
            if (!empty($pillar_url)) {
                $this->update_pillar_with_backlink($pillar_url, $post_id, $new_post_title);
            }

            $log_entry['status'] = 'success';
            $log_entry['output'] = "Viết lại thành công và chèn 3 ảnh đời thực có Logo cho bài ID: " . $post_id;
            $this->add_api_log($log_entry);

            wp_send_json_success('Viết lại nội dung, tạo 3 ảnh đời thực gắn Logo CyberServices thành công.');
        } else {
            wp_send_json_error('Hành động không hợp lệ.');
        }
    }

    public function ajax_process_existing_term()
    {
        check_ajax_referer('kira_ai_generate_nonce');

        if (!current_user_can('manage_categories')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $api_key = get_option('kira_ai_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error('Vui lòng cấu hình API Key.');
        }

        $term_id = isset($_POST['term_id']) ? (int) $_POST['term_id'] : 0;
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';
        $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
        $custom_prompt = isset($_POST['custom_prompt']) ? sanitize_textarea_field($_POST['custom_prompt']) : '';

        if (!$term_id || empty($taxonomy)) {
            wp_send_json_error('Thiếu thông tin phân loại.');
        }

        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            wp_send_json_error('Phân loại không tồn tại.');
        }

        $name = $term->name;
        $desc = $term->description;

        $text_model = get_option('kira_ai_text_model', 'kira-3.5-flash');
        $image_model = get_option('kira_ai_image_model', 'kira-2.5-flash-image');
        $base_url = 'https://kiraai.vn';

        if ($action_type === 'term_gen_image') {
            $desc_term = "danh mục hoặc địa điểm: " . $name . (!empty($desc) ? " - " . wp_strip_all_tags($desc) : "") . (!empty($custom_prompt) ? " - " . $custom_prompt : "");
            $image_prompt = $this->build_realistic_photo_prompt($desc_term);

            $log_entry = array(
                'time' => current_time('Y-m-d H:i:s'),
                'post_type' => 'taxonomy_' . $taxonomy,
                'keyword' => 'Tạo ảnh phân loại đời thực: ' . $name,
                'prompt' => $image_prompt,
                'model' => $image_model,
                'status' => 'pending',
                'input_full' => "Image Generation Prompt: " . $image_prompt,
                'output' => '',
                'error' => ''
            );

            $image_response = $this->call_kira_image_api($image_prompt, $api_key, $image_model, $base_url);

            if (is_wp_error($image_response)) {
                $log_entry['status'] = 'error';
                $log_entry['error'] = $image_response->get_error_message();
                $this->add_api_log($log_entry);
                wp_send_json_error('Lỗi sinh ảnh AI: ' . $image_response->get_error_message());
            }

            $attach_id = $this->save_base64_image_as_webp_attachment(
                $image_response['b64_json'],
                0,
                $name,
                'Hình ảnh ' . $name
            );

            if ($attach_id) {
                if (function_exists('update_field')) {
                    update_field('term_image', $attach_id, 'term_' . $term_id);
                } else {
                    update_term_meta($term_id, 'term_image', $attach_id);
                }

                $log_entry['status'] = 'success';
                $log_entry['output'] = "Đã tạo ảnh đại diện phân loại WebP có Logo thành công ID: " . $attach_id;
                $this->add_api_log($log_entry);
                wp_send_json_success('Tạo ảnh đại diện thành công.');
            } else {
                $log_entry['status'] = 'error';
                $log_entry['error'] = 'Không thể giải mã và lưu trữ file ảnh vào Media Library.';
                $this->add_api_log($log_entry);
                wp_send_json_error('Không thể lưu ảnh vào Media Library.');
            }

        } elseif ($action_type === 'term_gen_desc') {
            $system_msg = 'Bạn là một chuyên gia biên tập nội dung chuẩn SEO. Bạn luôn trả về đoạn mô tả mới dưới dạng văn bản ngắn gọn, súc tích và hấp dẫn.';
            $ai_prompt = "Hãy viết một đoạn mô tả chi tiết khoảng 100-150 từ cho phân loại/danh mục có tên là: \"{$name}\". Chuẩn SEO, mạch lạc.\n\n";

            if (!empty($custom_prompt)) {
                $ai_prompt .= "Yêu cầu bổ sung: {$custom_prompt}\n\n";
            }

            $ai_prompt .= "Lưu ý quan trọng: Chỉ trả về đoạn văn bản thuần túy, không có tiêu đề, không bọc dấu ngoặc kép.";

            $log_entry = array(
                'time' => current_time('Y-m-d H:i:s'),
                'post_type' => 'taxonomy_' . $taxonomy,
                'keyword' => 'Tạo mô tả phân loại: ' . $name,
                'prompt' => $custom_prompt,
                'model' => $text_model,
                'status' => 'pending',
                'input_full' => "System message: " . $system_msg . "\n\nUser prompt:\n" . $ai_prompt,
                'output' => '',
                'error' => ''
            );

            $response = $this->call_kira_api($ai_prompt, $system_msg, $api_key, $text_model, $base_url);

            if (is_wp_error($response)) {
                $log_entry['status'] = 'error';
                $log_entry['error'] = $response->get_error_message();
                $this->add_api_log($log_entry);
                wp_send_json_error('Kira AI API Error: ' . $response->get_error_message());
            }

            $new_desc = trim($response);
            $new_desc = trim($new_desc, '"\'');

            $updated = wp_update_term($term_id, $taxonomy, array(
                'description' => sanitize_textarea_field($new_desc)
            ));

            if (is_wp_error($updated)) {
                $log_entry['status'] = 'error';
                $log_entry['error'] = $updated->get_error_message();
                $this->add_api_log($log_entry);
                wp_send_json_error('Không thể cập nhật mô tả mới.');
            }

            $log_entry['status'] = 'success';
            $log_entry['output'] = $new_desc;
            $this->add_api_log($log_entry);

            wp_send_json_success('Tạo mô tả phân loại thành công.');
        } else {
            wp_send_json_error('Hành động không hợp lệ.');
        }
    }

    public function ajax_generate_standalone_image()
    {
        check_ajax_referer('kira_ai_generate_nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Bạn không có quyền tải tệp lên.');
        }

        $api_key = get_option('kira_ai_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error('Vui lòng cấu hình API Key.');
        }

        $prompt = isset($_POST['prompt']) ? sanitize_textarea_field($_POST['prompt']) : '';
        $aspect_ratio = isset($_POST['aspect_ratio']) ? sanitize_text_field($_POST['aspect_ratio']) : '16:9';

        if (empty($prompt)) {
            wp_send_json_error('Vui lòng nhập yêu cầu vẽ ảnh.');
        }

        $image_model = get_option('kira_ai_image_model', 'kira-2.5-flash-image');
        $base_url = 'https://kiraai.vn';

        $image_prompt = $this->build_realistic_photo_prompt($prompt);

        $log_entry = array(
            'time' => current_time('Y-m-d H:i:s'),
            'post_type' => 'attachment',
            'keyword' => 'Sinh ảnh thực tế (' . $aspect_ratio . '): ' . wp_html_excerpt($prompt, 50),
            'prompt' => $image_prompt,
            'model' => $image_model,
            'status' => 'pending',
            'input_full' => "Image Generation Prompt: " . $image_prompt . " (Aspect Ratio: " . $aspect_ratio . ")",
            'output' => '',
            'error' => ''
        );

        $image_response = $this->call_kira_image_api($image_prompt, $api_key, $image_model, $base_url, $aspect_ratio);

        if (is_wp_error($image_response)) {
            $log_entry['status'] = 'error';
            $log_entry['error'] = $image_response->get_error_message();
            $this->add_api_log($log_entry);
            wp_send_json_error('Lỗi sinh ảnh AI: ' . $image_response->get_error_message());
        }

        $attach_id = $this->save_base64_image_as_webp_attachment(
            $image_response['b64_json'],
            0,
            $prompt,
            'Hình ảnh ' . $prompt
        );

        if ($attach_id) {
            $log_entry['status'] = 'success';
            $log_entry['output'] = "Đã sinh ảnh tự do thành công có chèn Logo CyberServices. Attachment ID: " . $attach_id;
            $this->add_api_log($log_entry);
            wp_send_json_success('Tạo ảnh thành công.');
        } else {
            $log_entry['status'] = 'error';
            $log_entry['error'] = 'Không thể giải mã và lưu trữ file ảnh vào Media Library.';
            $this->add_api_log($log_entry);
            wp_send_json_error('Không thể lưu ảnh vào Media Library.');
        }
    }

    private function call_kira_image_api($prompt, $api_key, $model, $base_url, $aspect_ratio = '16:9')
    {
        $endpoint = rtrim($base_url, '/') . '/api/v1/images/generations';

        $payload = array(
            'prompt' => $prompt,
            'model' => $model,
            'aspect_ratio' => $aspect_ratio
        );

        $max_retries = 2;
        $last_error  = null;

        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_post($endpoint, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key
                ),
                'body' => wp_json_encode($payload),
                'timeout' => 90
            ));

            if (is_wp_error($response)) {
                $last_error = $response;
                if ($attempt < $max_retries) {
                    usleep(500000 * ($attempt + 1));
                }
                continue;
            }

            $body         = wp_remote_retrieve_body($response);
            $response_code = wp_remote_retrieve_response_code($response);
            $data         = json_decode($body, true);

            // Retry on server errors (5xx)
            if ($response_code >= 500 && $attempt < $max_retries) {
                usleep(500000 * ($attempt + 1));
                continue;
            }

            if ($response_code !== 200) {
                $err_msg = 'Máy chủ phản hồi mã lỗi HTTP: ' . $response_code;
                if (isset($data['error'])) {
                    $err = $data['error'];
                    $err_msg = is_array($err) ? ($err['message'] ?? $err_msg) : (string) $err;
                }
                return new WP_Error('kira_ai_image_error', $err_msg);
            }

            if (isset($data['error'])) {
                $err     = $data['error'];
                $err_msg = is_array($err) ? ($err['message'] ?? 'Lỗi không xác định khi sinh ảnh.') : (string) $err;
                return new WP_Error('kira_ai_image_error', $err_msg);
            }

            $image_info = $data['data'][0] ?? null;
            if (!$image_info || empty($image_info['b64_json'])) {
                return new WP_Error('kira_ai_image_empty', 'Không nhận được dữ liệu ảnh từ API.');
            }

            return $image_info;
        }

        if ($last_error) {
            return $last_error;
        }

        return new WP_Error('kira_ai_image_error', 'Lỗi không xác định khi gọi API sinh ảnh Kira AI.');
    }

    private function apply_cyberservices_logo(&$main_image)
    {
        if (!$main_image || !function_exists('imagecopyresampled')) {
            return;
        }

        $logo_url = self::LOGO_URL;
        $logo_transient_key = 'kira_ai_cs_logo_cache';
        $logo_data = get_transient($logo_transient_key);

        if (!$logo_data) {
            $response = wp_remote_get($logo_url, array('timeout' => 10));
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $logo_data = wp_remote_retrieve_body($response);
                set_transient($logo_transient_key, $logo_data, 7 * DAY_IN_SECONDS);
            }
        }

        if (empty($logo_data)) {
            return;
        }

        $logo_resource = @imagecreatefromstring($logo_data);
        if (!$logo_resource) {
            return;
        }

        $img_width = imagesx($main_image);
        $img_height = imagesy($main_image);

        $logo_w = imagesx($logo_resource);
        $logo_h = imagesy($logo_resource);

        $target_logo_w = (int) ($img_width * 0.18);
        if ($target_logo_w < 100) $target_logo_w = 100;
        if ($target_logo_w > $logo_w) $target_logo_w = $logo_w;

        $target_logo_h = (int) ($logo_h * ($target_logo_w / $logo_w));

        $margin_right = (int) ($img_width * 0.03);
        $margin_top = (int) ($img_height * 0.03);

        $dest_x = $img_width - $target_logo_w - $margin_right;
        $dest_y = $margin_top;

        imagealphablending($main_image, true);
        imagesavealpha($main_image, true);

        imagecopyresampled(
            $main_image,
            $logo_resource,
            $dest_x,
            $dest_y,
            0,
            0,
            $target_logo_w,
            $target_logo_h,
            $logo_w,
            $logo_h
        );

        imagedestroy($logo_resource);
    }

    private function save_base64_image_as_webp_attachment($b64_data, $post_id, $title, $alt_text = '')
    {
        if (preg_match('/^data:image\/(\w+);base64,/i', $b64_data, $type_matches)) {
            $b64_data = substr($b64_data, strpos($b64_data, ',') + 1);
        }

        $img_data = base64_decode($b64_data);
        if (!$img_data) {
            return false;
        }

        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            error_log('Kira AI: Thư viện PHP GD không được bật trên server.');
            return false;
        }

        $image = @imagecreatefromstring($img_data);
        if (!$image) {
            return false;
        }

        $this->apply_cyberservices_logo($image);

        $safe_title = sanitize_file_name($title);
        $filename_title = wp_html_excerpt($safe_title, 50, '');
        if (empty($filename_title)) {
            $filename_title = 'kira-ai-image';
        }
        
        $filename = $filename_title . '-' . time() . '.webp';
        $upload_dir = wp_upload_dir();

        if (!file_exists($upload_dir['path'])) {
            wp_mkdir_p($upload_dir['path']);
        }

        $filepath = $upload_dir['path'] . '/' . $filename;
        
        if (!imagewebp($image, $filepath, 85)) {
            imagedestroy($image);
            return false;
        }
        imagedestroy($image);

        // SEO-friendly media library metadata: alt, title, caption/excerpt.
        $seo_texts = $this->build_image_seo_text($alt_text, $title, 'Hình ảnh');
        $seo_caption = $alt_text !== '' ? $alt_text : $seo_texts['alt'];

        $attachment = array(
            'post_mime_type' => 'image/webp',
            'post_title'     => sanitize_text_field(wp_html_excerpt($seo_texts['title'], 80, '...')),
            'post_content'   => sanitize_textarea_field($seo_caption), // SEO description / caption
            'post_excerpt'   => sanitize_text_field(wp_html_excerpt($seo_caption, 120, '...')),
            'post_status'    => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $filepath, $post_id);
        if (!$attach_id || is_wp_error($attach_id)) {
            return false;
        }

        $final_alt = !empty($alt_text) ? $alt_text : $seo_texts['alt'];
        update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($final_alt));
        update_post_meta($attach_id, '_kira_ai_generated', 1);

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    private function call_kira_api($prompt, $system_msg, $api_key, $model, $base_url, $additional_args = array())
    {
        $endpoint = rtrim($base_url, '/') . '/api/v1/chat/completions';

        $messages = array();
        if (!empty($system_msg)) {
            $messages[] = array(
                'role' => 'system',
                'content' => $system_msg
            );
        }
        $messages[] = array(
            'role' => 'user',
            'content' => $prompt
        );

        $payload = array(
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'temperature' => 0.4,
            'max_tokens' => 16000
        );

        if (!empty($additional_args)) {
            $payload = array_merge($payload, $additional_args);
        }

        $max_retries = 2;
        $last_error  = null;

        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_post($endpoint, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key
                ),
                'body' => wp_json_encode($payload),
                'timeout' => 120
            ));

            if (is_wp_error($response)) {
                $last_error = $response;
                if ($attempt < $max_retries) {
                    usleep(500000 * ($attempt + 1));
                }
                continue;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body          = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                $data = json_decode($body, true);

                if (isset($data['error'])) {
                    return new WP_Error('kira_ai_error', $data['error']['message'] ?? 'Lỗi không xác định từ Kira AI.');
                }

                return $data['choices'][0]['message']['content'] ?? '';
            }

            // Retry on server errors (5xx)
            if ($response_code >= 500 && $attempt < $max_retries) {
                usleep(500000 * ($attempt + 1));
                continue;
            }

            $data    = json_decode($body, true);
            $err_msg = isset($data['error']['message']) ? $data['error']['message'] : '';
            if (empty($err_msg)) {
                $err_msg = 'Máy chủ phản hồi mã lỗi HTTP: ' . $response_code;
            }
            return new WP_Error('kira_ai_http_error', $err_msg);
        }

        if ($last_error) {
            return $last_error;
        }

        return new WP_Error('kira_ai_unknown_error', 'Lỗi không xác định khi gọi API Kira AI.');
    }

    private function clean_and_decode_json($raw_content)
    {
        $clean_content = trim($raw_content);

        // 1. Strip markdown code fences if present
        if (preg_match('/```json\s*([\s\S]*?)\s*```/i', $clean_content, $matches)) {
            $clean_content = trim($matches[1]);
        } elseif (preg_match('/```\s*([\s\S]*?)\s*```/i', $clean_content, $matches)) {
            $clean_content = trim($matches[1]);
        }

        // 2. Extract only the outermost JSON object
        $first_brace = strpos($clean_content, '{');
        $last_brace  = strrpos($clean_content, '}');
        if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
            $clean_content = substr($clean_content, $first_brace, $last_brace - $first_brace + 1);
        }

        // 3. Attempt direct decode
        $decoded = json_decode($clean_content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // 4. Repair: escape unescaped double quotes inside string values (but keep structural quotes).
        //    This handles AI output where literal double quotes appear inside "content".
        $repaired = $this->repair_json_quotes($clean_content);
        if ($repaired !== null && $repaired !== $clean_content) {
            $decoded = json_decode($repaired, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // 5. Repair: remove literal newlines/tabs inside string values (replace with \n escaped).
        $normalized = preg_replace_callback(
            '/"((?:[^"\\\\]|\\\\.)*)"/',
            function ($matches) {
                return '"' . str_replace(
                    array("\r\n", "\r", "\n", "\t"),
                    array('\n', '\n', '\n', '\t'),
                    $matches[1]
                ) . '"';
            },
            $clean_content
        );
        if ($normalized !== $clean_content) {
            $decoded = json_decode($normalized, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // 6. Fallback: position-aware newline collapse using brace depth tracking
        $chars     = str_split($clean_content);
        $stack     = array();
        $in_string = false;
        $escaped   = false;
        $result    = '';
        $last_key  = null;

        foreach ($chars as $ch) {
            if ($in_string) {
                if ($escaped) {
                    $result .= $ch;
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $result .= $ch;
                    $escaped = true;
                    continue;
                }
                if ($ch === '"') {
                    $result .= $ch;
                    $in_string = false;
                    continue;
                }
                if ($ch === "\n" || $ch === "\r") {
                    $result .= '\n';
                    continue;
                }
                $result .= $ch;
                continue;
            }

            if ($ch === '"') {
                $result .= $ch;
                $in_string = true;
                continue;
            }
            if ($ch === '{' || $ch === '[') {
                $stack[] = $ch;
                $result .= $ch;
                continue;
            }
            if ($ch === '}' || $ch === ']') {
                array_pop($stack);
                $result .= $ch;
                continue;
            }
            if ($ch === ':' && end($stack) === '{') {
                $last_key = trim(substr($result, strrpos($result, '"') + 1));
            }
            if (($ch === "\n" || $ch === "\r") && empty($stack) === false) {
                continue;
            }
            $result .= $ch;
        }

        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        error_log('Cyber Services Content JSON Decode Failed: ' . json_last_error_msg() . ' | Raw Content: ' . $raw_content);

        return false;
    }

    /**
     * Escape unescaped double quotes that appear inside string values.
     *
     * @param string $json Input JSON string.
     * @return string|null Repaired JSON string, or null if unable to repair.
     */
    private function repair_json_quotes($json)
    {
        $len   = strlen($json);
        $out   = '';
        $inStr = false;
        $esc   = false;
        $stack = array();

        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];

            if ($inStr) {
                if ($esc) {
                    $out .= $ch;
                    $esc = false;
                    continue;
                }
                if ($ch === '\\') {
                    $out .= $ch;
                    $esc = true;
                    continue;
                }
                if ($ch === '"') {
                    $out .= $ch;
                    $inStr = false;
                    continue;
                }
                $out .= $ch;
                continue;
            }

            if ($ch === '"') {
                $out .= $ch;
                $inStr = true;
                continue;
            }
            if ($ch === '{' || $ch === '[') {
                $stack[] = $ch;
                $out .= $ch;
                continue;
            }
            if ($ch === '}' || $ch === ']') {
                array_pop($stack);
                $out .= $ch;
                continue;
            }
            $out .= $ch;
        }

        if ($inStr) {
            // Unclosed string — cannot repair reliably.
            return null;
        }

        return $out;
    }

    public function ajax_test_connection()
    {
        check_ajax_referer('kira_ai_generate_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $text_model = isset($_POST['text_model']) ? sanitize_text_field($_POST['text_model']) : 'kira-3.5-flash';

        if (empty($api_key)) {
            wp_send_json_error('Vui lòng nhập API Key.');
        }

        $base_url = 'https://kiraai.vn';
        $test_prompt = 'Hãy trả về duy nhất chữ "OK" để xác nhận kết nối hoạt động.';
        $system_msg = 'Bạn là trợ lý hệ thống kiểm tra kết nối API.';

        $response = $this->call_kira_api($test_prompt, $system_msg, $api_key, $text_model, $base_url);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        if (empty($response)) {
            wp_send_json_error('Kết nối thành công nhưng nhận được phản hồi trống từ API.');
        }

        wp_send_json_success();
    }

    public function ajax_sync_models()
    {
        check_ajax_referer('kira_ai_generate_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $models = $this->get_kira_models(true);
        wp_send_json_success($models);
    }

    public function get_kira_models($force_refresh = false)
    {
        $cache_key = 'kira_ai_models_cache';
        $models = $force_refresh ? false : get_transient($cache_key);

        if (false === $models) {
            $response = wp_remote_get('https://kiraai.vn/api/v1/models', array(
                'timeout' => 15
            ));

            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);

                if (200 === $response_code) {
                    $data = json_decode($body, true);
                    if (isset($data['data']) && is_array($data['data'])) {
                        $text_models = array();
                        $image_models = array();

                        foreach ($data['data'] as $model) {
                            if (isset($model['status']) && 'active' !== $model['status']) {
                                continue;
                            }

                            $model_id = $model['id'] ?? '';
                            $model_name = $model['name'] ?? $model_id;
                            $model_desc = $model['description'] ?? '';
                            $model_type = $model['type'] ?? '';

                            if (empty($model_id)) {
                                continue;
                            }

                            $model_entry = array(
                                'id' => $model_id,
                                'name' => $model_name,
                                'description' => $model_desc
                            );

                            if ('chat' === $model_type) {
                                $text_models[] = $model_entry;
                            } elseif ('image' === $model_type) {
                                $image_models[] = $model_entry;
                            }
                        }

                        if (!empty($text_models) || !empty($image_models)) {
                            $models = array(
                                'text_models' => $text_models,
                                'image_models' => $image_models
                            );
                            set_transient($cache_key, $models, DAY_IN_SECONDS);
                        }
                    }
                }
            }
        }

        if (empty($models)) {
            $models = array(
                'text_models' => array(
                    array('id' => 'kira-3.5-flash', 'name' => 'Kira 3.5 Flash', 'description' => 'Nhanh nhất, đề xuất'),
                    array('id' => 'kira-3-flash-preview', 'name' => 'kira-3-flash-preview', 'description' => 'Thử nghiệm tốc độ'),
                    array('id' => 'kira-2.5-flash', 'name' => 'kira-2.5-flash', 'description' => 'Model nhẹ, cơ bản'),
                    array('id' => 'kira-2.5-pro', 'name' => 'kira-2.5-pro', 'description' => 'Mạnh mẽ, lập luận logic cao'),
                ),
                'image_models' => array(
                    array('id' => 'kira-2.5-flash-image', 'name' => 'kira-2.5-flash-image', 'description' => 'Model sinh ảnh chân thực chất lượng cao'),
                    array('id' => 'kira-3.1-flash-image-preview', 'name' => 'kira-3.1-flash-image-preview', 'description' => 'Mới, chất lượng cao'),
                    array('id' => 'kira-3-pro-image-preview', 'name' => 'kira-3-pro-image-preview', 'description' => 'Chi tiết cao, sắc nét'),
                )
            );
        }

        return $models;
    }

    /**
     * Auto-post to Facebook Fanpage when a post is published (including scheduled).
     *
     * @param string $new_status New post status.
     * @param string $old_status Old post status.
     * @param WP_Post $post      Post object.
     */
    public function on_post_published($new_status, $old_status, $post)
    {
        // Only on publish transition
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }

        // Skip if not a public post type
        $post_type = get_post_type_object($post->post_type);
        if (!$post_type || !$post_type->public) {
            return;
        }

        $title   = $post->post_title;
        $excerpt = wp_html_excerpt(wp_strip_all_tags($post->post_content), 200);
        $url     = get_permalink($post->ID);

        // === Facebook ===
        $fb_enabled = get_option('kira_ai_fb_enabled', 0);
        $fb_page_id = get_option('kira_ai_fb_page_id', '');
        $fb_token   = get_option('kira_ai_fb_access_token', '');

        if ($fb_enabled && !empty($fb_page_id) && !empty($fb_token) && !get_post_meta($post->ID, '_kira_ai_fb_posted', true)) {
            $message_template = get_option('kira_ai_fb_post_message', '📌 Bài viết mới: {title}\n\n{excerpt}\n\n🔗 Xem chi tiết tại: {url}');
            $message = str_replace(
                array('{title}', '{excerpt}', '{url}'),
                array($title, $excerpt, $url),
                $message_template
            );

            $response = $this->post_to_facebook($message, $url, $fb_page_id, $fb_token);

            if (!is_wp_error($response)) {
                update_post_meta($post->ID, '_kira_ai_fb_posted', 1);
                update_post_meta($post->ID, '_kira_ai_fb_post_id', $response);
            } else {
                error_log('Kira AI Facebook Post Error: ' . $response->get_error_message());
            }
        }

        // === Zalo OA ===
        $zalo_enabled = get_option('kira_ai_zalo_enabled', 0);
        $zalo_token   = get_option('kira_ai_zalo_token', '');
        $zalo_oa_id   = get_option('kira_ai_zalo_oa_id', '');

        if ($zalo_enabled && !empty($zalo_token) && !empty($zalo_oa_id) && !get_post_meta($post->ID, '_kira_ai_zalo_posted', true)) {
            $response = $this->post_to_zalo($title, $url, $zalo_token);

            if (!is_wp_error($response)) {
                update_post_meta($post->ID, '_kira_ai_zalo_posted', 1);
            } else {
                error_log('Kira AI Zalo Post Error: ' . $response->get_error_message());
            }
        }

        // === Telegram ===
        $telegram_enabled    = get_option('kira_ai_telegram_enabled', 0);
        $telegram_bot_token  = get_option('kira_ai_telegram_bot_token', '');
        $telegram_chat_id    = get_option('kira_ai_telegram_chat_id', '');

        if ($telegram_enabled && !empty($telegram_bot_token) && !empty($telegram_chat_id) && !get_post_meta($post->ID, '_kira_ai_telegram_posted', true)) {
            $telegram_message = "📌 {$title}\n\n{$excerpt}\n\n🔗 Xem chi tiết tại: {$url}";
            $response = $this->post_to_telegram($telegram_message, $telegram_bot_token, $telegram_chat_id);

            if (!is_wp_error($response)) {
                update_post_meta($post->ID, '_kira_ai_telegram_posted', 1);
            } else {
                error_log('Kira AI Telegram Post Error: ' . $response->get_error_message());
            }
        }
    }

    /**
     * Post a message with link to Facebook Fanpage.
     *
     * @param string $message The message text.
     * @param string $url     The post URL.
     * @param string $page_id Facebook Page ID.
     * @param string $token   Page Access Token.
     * @return string|WP_Error Facebook post ID on success.
     */
    private function post_to_facebook($message, $url, $page_id, $token)
    {
        $endpoint = "https://graph.facebook.com/v21.0/{$page_id}/feed";

        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'message'      => $message,
                'link'          => $url,
                'access_token'  => $token,
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            return new WP_Error('facebook_error', $data['error']['message'] ?? 'Lỗi không xác định từ Facebook.');
        }

        return $data['id'] ?? '';
    }

    /**
     * AJAX handler to test Facebook connection.
     */
    public function ajax_test_facebook()
    {
        check_ajax_referer('kira_ai_generate_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $page_id = isset($_POST['page_id']) ? sanitize_text_field($_POST['page_id']) : '';
        $token   = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

        if (empty($page_id) || empty($token)) {
            wp_send_json_error('Vui lòng nhập Page ID và Access Token.');
        }

        $endpoint = "https://graph.facebook.com/v21.0/{$page_id}?fields=name&access_token={$token}";
        $response = wp_remote_get($endpoint, array('timeout' => 15));

        if (is_wp_error($response)) {
            wp_send_json_error('Không thể kết nối: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            wp_send_json_error('Facebook API Error: ' . $data['error']['message']);
        }

        $page_name = $data['name'] ?? 'Không xác định';
        wp_send_json_success('Kết nối thành công! Fanpage: "' . $page_name . '"');
    }

    /**
     * Add custom cron schedule for weekly evergreen refresh.
     *
     * @param array $schedules Cron schedules.
     * @return array
     */
    public function add_cron_schedule($schedules)
    {
        $schedules['kira_ai_weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => 'Mỗi tuần (Kira AI Evergreen Refresh)',
        );
        return $schedules;
    }

    /**
     * Run Evergreen Refresh: rewrite old posts to keep them fresh.
     */
    public function run_evergreen_refresh()
    {
        $evergreen_enabled = get_option('kira_ai_evergreen_enabled', 0);
        if (!$evergreen_enabled) {
            return;
        }

        $api_key = get_option('kira_ai_api_key', '');
        if (empty($api_key)) {
            return;
        }

        $age_months = (int) get_option('kira_ai_evergreen_age', '6');
        $text_model = get_option('kira_ai_text_model', 'kira-3.5-flash');
        $base_url   = 'https://kiraai.vn';

        $old_posts = get_posts(array(
            'numberposts'      => 5,
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'date_query'       => array(
                'before' => date('Y-m-d', strtotime("-{$age_months} months")),
            ),
            'meta_query'       => array(
                'relation' => 'OR',
                array(
                    'key'     => '_kira_ai_evergreen_refreshed',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => '_kira_ai_evergreen_refreshed',
                    'value'   => date('Y-m-d', strtotime('-3 months')),
                    'compare' => '<=',
                    'type'    => 'DATE',
                ),
            ),
        ));

        foreach ($old_posts as $post) {
            $title = $post->post_title;
            $content = wp_html_excerpt(wp_strip_all_tags($post->post_content), 1000);

            $system_msg = 'Bạn là Chuyên gia SEO Master. Nhiệm vụ của bạn là làm mới bài viết cũ để duy trì thứ hạng Google.';
            $ai_prompt = "Hãy làm mới và tối ưu lại bài viết sau đây bằng tiếng Việt:\n" .
                "Tiêu đề cũ: {$title}\n" .
                "Nội dung cũ (tóm tắt): {$content}\n\n" .
                "Yêu cầu:\n" .
                "1. Bổ sung thông tin mới, số liệu cập nhật, xu hướng mới nhất.\n" .
                "2. Giữ nguyên cấu trúc H2/H3 hiện có, chỉ cải thiện.\n" .
                "3. Đảm bảo bài viết đạt 1500-2000 từ.\n" .
                "4. Trả về JSON duy nhất dạng: {\"content\": \"HTML mới\"}.";

            $additional_args = array(
                'response_format' => array('type' => 'json_object')
            );
            $response = $this->call_kira_api($ai_prompt, $system_msg, $api_key, $text_model, $base_url, $additional_args);

            if (is_wp_error($response)) {
                continue;
            }

            $data = $this->clean_and_decode_json($response);
            if (!$data || empty($data['content'])) {
                continue;
            }

            wp_update_post(array(
                'ID'           => $post->ID,
                'post_content' => $this->strip_blacklisted_urls(wp_kses_post($data['content'])),
            ));

            update_post_meta($post->ID, '_kira_ai_evergreen_refreshed', current_time('Y-m-d'));
        }
    }

    /**
     * Send post to Zalo OA.
     *
     * @param string $title   Post title.
     * @param string $url     Post URL.
     * @param string $token   Zalo access token.
     * @return bool|WP_Error
     */
    private function post_to_zalo($title, $url, $token)
    {
        $endpoint = 'https://openapi.zalo.me/v2.0/oa/message';

        $payload = array(
            'recipient'  => array('user_id' => 'oa'),
            'message'    => array(
                'text' => "📌 {$title}\n\n🔗 Xem chi tiết tại: {$url}",
            ),
        );

        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'access_token'  => $token,
            ),
            'body'    => wp_json_encode($payload),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            return new WP_Error('zalo_error', $data['error']['message'] ?? 'Lỗi không xác định từ Zalo.');
        }

        return true;
    }

    /**
     * Send post to Telegram via Bot API.
     *
     * @param string $message The message text.
     * @param string $bot_token Bot token.
     * @param string $chat_id  Chat/channel ID.
     * @return bool|WP_Error
     */
    private function post_to_telegram($message, $bot_token, $chat_id)
    {
        $endpoint = "https://api.telegram.org/bot{$bot_token}/sendMessage";

        $response = wp_remote_post($endpoint, array(
            'body' => array(
                'chat_id'                  => $chat_id,
                'text'                     => $message,
                'disable_web_page_preview' => 'false',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['ok']) && true !== $data['ok']) {
            return new WP_Error('telegram_error', $data['description'] ?? 'Lỗi không xác định từ Telegram.');
        }

        return true;
    }

    /**
     * AJAX handler to manually re-post a post to Facebook.
     */
    public function ajax_repost_facebook()
    {
        check_ajax_referer('kira_ai_generate_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id) {
            wp_send_json_error('Thiếu ID bài viết.');
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Bài viết không tồn tại.');
        }

        $fb_enabled = get_option('kira_ai_fb_enabled', 0);
        $fb_page_id = get_option('kira_ai_fb_page_id', '');
        $fb_token   = get_option('kira_ai_fb_access_token', '');

        if (!$fb_enabled || empty($fb_page_id) || empty($fb_token)) {
            wp_send_json_error('Facebook auto-post chưa được cấu hình.');
        }

        // Delete old flag so we can repost
        delete_post_meta($post->ID, '_kira_ai_fb_posted');

        // Trigger repost by calling the publish handler logic directly
        $this->on_post_published('publish', 'draft', $post);

        if (get_post_meta($post->ID, '_kira_ai_fb_posted', true)) {
            $fb_post_id = get_post_meta($post->ID, '_kira_ai_fb_post_id', true);
            wp_send_json_success('Đã đăng bài lên Facebook thành công. Facebook Post ID: ' . $fb_post_id);
        }

        wp_send_json_error('Không thể đăng bài lên Facebook. Kiểm tra log lỗi.');
    }

    private function add_api_log($log_entry)
    {
        $logs = get_option('kira_ai_api_logs', array());
        if (!is_array($logs)) {
            $logs = array();
        }

        array_unshift($logs, $log_entry);

        if (count($logs) > 30) {
            $logs = array_slice($logs, 0, 30);
        }

        update_option('kira_ai_api_logs', $logs, false);
    }
    /**
     * AJAX: scan all posts/pages and remove blacklisted URLs from content.
     */
    public function ajax_clean_blacklist()
    {
        check_ajax_referer('kira_ai_nonce_action');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Không có quyền thực hiện.');
        }

        $posts = get_posts(array(
            'numberposts' => -1,
            'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
            'post_type'   => array('post', 'page'),
            'fields'      => 'ids',
        ));

        $updated = 0;
        $already_clean = 0;
        $errors = 0;

        foreach ($posts as $post_id) {
            $content = get_post_field('post_content', $post_id);
            if (empty($content)) {
                continue;
            }

            $cleaned = $this->strip_blacklisted_urls($content);
            if ($cleaned !== $content) {
                $result = wp_update_post(array(
                    'ID'           => $post_id,
                    'post_content' => $cleaned,
                ));
                if (is_wp_error($result)) {
                    $errors++;
                } else {
                    $updated++;
                }
            } else {
                $already_clean++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(
                'Đã dọn xong: %d bài đã được làm sạch, %d bài không có link blacklist, %d lỗi.',
                $updated,
                $already_clean,
                $errors
            ),
            'updated'       => $updated,
            'already_clean' => $already_clean,
            'errors'        => $errors,
        ));
    }


}

function kira_ai_init()
{
    return Kira_AI::get_instance();
}
add_action('init', 'kira_ai_init');