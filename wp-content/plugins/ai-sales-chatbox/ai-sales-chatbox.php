<?php
/**
 * Plugin Name: AI Sales Assistant Chatbox
 * Description: Chatbot AI chuyên nghiệp giao diện Modern Light Theme, trợ lý tư vấn của Cyber Services (Đã tối ưu CRM & Bảo mật).
 * Version: 5.0.4 (Enterprise CRM Version)
 * Author: Cyber Services
 */

if (!defined('ABSPATH')) exit;

// Phiên bản plugin - dùng làm version tĩnh cho CSS/JS để trình duyệt cache lâu dài
if (!defined('AI_SALES_CHATBOX_VERSION')) {
    define('AI_SALES_CHATBOX_VERSION', '5.0.4');
}

class AISalesChatbox {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        register_activation_hook(__FILE__, [$this, 'setup_database']);
        add_action('admin_init', [$this, 'handle_delete_lead']);

        add_action('wp_footer', [$this, 'render_chatbox_ui']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        // FIX PageSpeed: tải CSS chatbox không đồng bộ (không chặn hiển thị trang)
        add_filter('style_loader_tag', [$this, 'make_chatbox_style_async'], 10, 4);
        
        // Tự động xóa cache kiến thức khi đăng bài mới
        add_action('save_post', [$this, 'clear_post_cache'], 10, 3);

        // Hooks cho xuất file Excel và cập nhật trạng thái CRM
        add_action('admin_post_export_ai_leads', [$this, 'export_leads_csv']);
        add_action('wp_ajax_update_lead_status', [$this, 'handle_update_status']);
        
        // Hook AJAX xóa hàng loạt Leads
        add_action('wp_ajax_bulk_delete_ai_leads', [$this, 'handle_bulk_delete']);

        add_action('wp_ajax_ai_chat_save_lead', [$this, 'handle_save_lead']);
        add_action('wp_ajax_nopriv_ai_chat_save_lead', [$this, 'handle_save_lead']);
        add_action('wp_ajax_ai_chat_send_message', [$this, 'handle_chat_message']);
        add_action('wp_ajax_nopriv_ai_chat_send_message', [$this, 'handle_chat_message']);
        add_action('wp_ajax_ai_chat_load_history', [$this, 'load_chat_history']);
        add_action('wp_ajax_nopriv_ai_chat_load_history', [$this, 'load_chat_history']);
        
        // Hook đăng ký cổng nhận tin nhắn Telegram Webhook
        add_action('rest_api_init', [$this, 'register_telegram_webhook']);
    }

    /* TẠO CỔNG WEBHOOK NHẬN TIN NHẮN TỪ TELEGRAM */
    public function register_telegram_webhook() {
        register_rest_route('ai-chatbox/v1', '/telegram-webhook', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_telegram_webhook'],
            'permission_callback' => [$this, 'verify_telegram_webhook_permission']
        ]);
    }

    public function verify_telegram_webhook_permission(WP_REST_Request $request) {
        $token = get_option('ai_chat_telegram_token');
        if (empty($token)) return false;
        
        $secret_header = $request->get_header('x-telegram-bot-api-secret-token');
        $expected_secret = substr(hash('sha256', $token), 0, 32);
        
        // Loại bỏ fallback rủi ro - Yêu cầu chuẩn bảo mật Secret Header
        return ($secret_header && hash_equals($expected_secret, $secret_header));
    }

    public function handle_telegram_webhook(WP_REST_Request $request) {
        global $wpdb;
        $body = $request->get_json_params();
        
        if (isset($body['message']['reply_to_message']['text'], $body['message']['text'])) {
            $reply_to_text = (string)$body['message']['reply_to_message']['text'];
            $admin_reply   = sanitize_textarea_field($body['message']['text']);

            if (preg_match('/ID:\s*([a-zA-Z0-9_]+)/', $reply_to_text, $matches)) {
                $session_id  = sanitize_text_field($matches[1]);
                $table_chats = $wpdb->prefix . 'ai_chat_history';
                
                $wpdb->insert($table_chats, [
                    'session_id' => $session_id,
                    'sender'     => 'bot',
                    'message'    => "🤵 **(CSKH)**: " . $admin_reply
                ], ['%s', '%s', '%s']);
            }
        }
        return new WP_REST_Response(['status' => 'success'], 200);
    }

    /* XÓA CACHE BÀI VIẾT KHI ĐĂNG BÀI MỚI */
    public function clear_post_cache($post_id, $post, $update) {
        if ($post->post_status === 'publish') {
            delete_transient('ai_chat_posts_cache');
        }
    }

    public function enqueue_frontend_scripts() {
        // Không tải Chatbox ở trang giỏ hàng, thanh toán (nếu dùng WooCommerce)
        if (function_exists('is_cart') && (is_cart() || is_checkout())) {
            return;
        }

        $base_url = plugin_dir_url(__FILE__);

        // FIX PageSpeed: dùng version tĩnh thay vì time().
        // time() khiến URL file CSS/JS đổi liên tục -> trình duyệt không cache được
        // -> bị báo lỗi "Serve static assets with an efficient cache policy".
        wp_enqueue_style('ai-chatbox-style', $base_url . 'assets/css/chatbox.css', [], AI_SALES_CHATBOX_VERSION);

        // FIX PageSpeed: defer script để không chặn việc phân tích HTML
        $js_args = version_compare(get_bloginfo('version'), '6.3', '>=')
            ? ['in_footer' => true, 'strategy' => 'defer']
            : true; // WP cũ hơn 6.3: giữ nguyên vị trí footer như trước
        wp_enqueue_script('ai-chatbox-script', $base_url . 'assets/js/chatbox.js', [], AI_SALES_CHATBOX_VERSION, $js_args);

        wp_localize_script('ai-chatbox-script', 'aiChatboxData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ai_chat_nonce_action')
        ]);
    }

    /* FIX PageSpeed: mẫu "async CSS" do Lighthouse khuyến nghị
       (preload + media=print để tải song song, không chặn render, sau đó bật lại media=all) */
    public function make_chatbox_style_async($tag, $handle, $href, $media) {
        if ('ai-chatbox-style' !== $handle) {
            return $tag;
        }

        $preload = "<link rel='preload' as='style' href='{$href}' />";
        $async   = "<link rel='stylesheet' id='ai-chatbox-style-css' href='{$href}' media='print' onload=\"this.media='all';\" />";

        return $preload . "\n" . $async . "\n";
    }

    /* 1. DATABASE SETUP (Thêm INDEX để tối ưu tốc độ) */
    public function setup_database() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_leads = $wpdb->prefix . 'ai_chat_leads';
        $table_chats = $wpdb->prefix . 'ai_chat_history';

        $sql_leads = "CREATE TABLE IF NOT EXISTS $table_leads (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(100) NOT NULL,
            user_name varchar(100) NOT NULL,
            user_phone varchar(50) NOT NULL,
            user_email varchar(100) DEFAULT '',
            page_url text,
            status varchar(50) DEFAULT 'new' NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY session_id (session_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql_chats = "CREATE TABLE IF NOT EXISTS $table_chats (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(100) NOT NULL,
            sender varchar(10) NOT NULL,
            message text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY session_id (session_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_leads);
        dbDelta($sql_chats);
    }

    /* 2. XỬ LÝ XÓA LEAD ĐƠN LẺ */
    public function handle_delete_lead() {
        // FIX bảo mật: chỉ xử lý khi đang ở đúng trang quản lý Leads (tránh can thiệp request admin khác)
        if (!isset($_GET['page']) || $_GET['page'] !== 'ai-chatbox-leads') {
            return;
        }
        if (isset($_GET['action'], $_GET['lead_id']) && $_GET['action'] === 'delete_lead') {
            $lead_id = (int)$_GET['lead_id'];
            check_admin_referer('delete_lead_' . $lead_id);
            
            if (!current_user_can('manage_options')) {
                wp_die('Bạn không có quyền thực hiện hành động này.');
            }

            global $wpdb;
            $table_leads = $wpdb->prefix . 'ai_chat_leads';
            $table_chats = $wpdb->prefix . 'ai_chat_history';

            $session_id = $wpdb->get_var($wpdb->prepare("SELECT session_id FROM $table_leads WHERE id = %d", $lead_id));
            if ($session_id) {
                $wpdb->delete($table_chats, ['session_id' => $session_id], ['%s']);
                $wpdb->delete($table_leads, ['id' => $lead_id], ['%d']);
            }

            wp_safe_redirect(admin_url('admin.php?page=ai-chatbox-leads&deleted=1'));
            exit;
        }
    }

    /* 2.1 XỬ LÝ XÓA HÀNG LOẠT LEADS */
    public function handle_bulk_delete() {
        check_ajax_referer('ai_admin_leads_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);
        
        global $wpdb;
        $ids = isset($_POST['lead_ids']) ? array_map('intval', (array)$_POST['lead_ids']) : [];
        if (empty($ids)) wp_send_json_error(['message' => 'No IDs provided']);

        $table_leads = $wpdb->prefix . 'ai_chat_leads';
        $table_chats = $wpdb->prefix . 'ai_chat_history';

        foreach ($ids as $lead_id) {
            $session_id = $wpdb->get_var($wpdb->prepare("SELECT session_id FROM $table_leads WHERE id = %d", $lead_id));
            if ($session_id) {
                $wpdb->delete($table_chats, ['session_id' => $session_id], ['%s']);
                $wpdb->delete($table_leads, ['id' => $lead_id], ['%d']);
            }
        }
        wp_send_json_success();
    }

    /* 2.2 XUẤT EXCEL (CSV) */
    public function export_leads_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('Bạn không có quyền.');
        }
        check_admin_referer('export_ai_leads_action', 'export_nonce');

        global $wpdb;
        $table_leads = $wpdb->prefix . 'ai_chat_leads';
        $leads = $wpdb->get_results("SELECT created_at, user_name, user_phone, user_email, page_url, status FROM $table_leads ORDER BY id DESC", ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Danh_Sach_Khach_Hang_' . date('Ymd_Hi') . '.csv');
        $output = fopen('php://output', 'w');
        
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 
        fputcsv($output, ['Thời gian', 'Họ Tên', 'Số điện thoại', 'Email', 'Trang đăng ký', 'Trạng thái']);
        
        $status_map = [
            'new'        => 'Mới tiếp nhận',
            'contacting' => 'Đang liên hệ',
            'closed'     => 'Đã chốt hợp đồng',
            'cancelled'  => 'Hủy/Không nghe máy'
        ];

        foreach ($leads as $lead) {
            $lead['status'] = $status_map[$lead['status']] ?? 'Mới tiếp nhận';
            fputcsv($output, $lead);
        }
        fclose($output);
        exit;
    }

    /* 2.3 AJAX CẬP NHẬT TRẠNG THÁI CRM */
    public function handle_update_status() {
        check_ajax_referer('ai_admin_leads_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error();
        
        global $wpdb;
        $lead_id = intval($_POST['lead_id'] ?? 0);
        $status  = sanitize_key($_POST['status'] ?? 'new');
        
        $allowed_statuses = ['new', 'contacting', 'closed', 'cancelled'];
        if (!in_array($status, $allowed_statuses, true)) {
            wp_send_json_error(['message' => 'Invalid status']);
        }

        $wpdb->update($wpdb->prefix . 'ai_chat_leads', ['status' => $status], ['id' => $lead_id], ['%s'], ['%d']);
        wp_send_json_success();
    }

    /* 3. ADMIN SETTINGS & CRM */
    public function add_admin_menu() {
        add_menu_page('Cyber AI Chatbox', 'Cyber AI Chatbox', 'manage_options', 'ai-chatbox', [$this, 'admin_settings_page'], 'dashicons-format-chat');
        add_submenu_page('ai-chatbox', 'Danh Sách Khách Hàng', 'Khách Hàng (Leads)', 'manage_options', 'ai-chatbox-leads', [$this, 'admin_leads_page']);
    }

    public function register_settings() {
        register_setting('ai_chat_settings_group', 'ai_chat_provider', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ai_chat_settings_group', 'ai_chat_api_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ai_chat_settings_group', 'ai_chat_kira_base_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('ai_chat_settings_group', 'ai_chat_model', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ai_chat_settings_group', 'ai_chat_system_prompt', ['sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('ai_chat_settings_group', 'ai_chat_custom_knowledge', ['sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('ai_chat_settings_group', 'ai_chat_telegram_token', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ai_chat_settings_group', 'ai_chat_telegram_chat_id', ['sanitize_callback' => 'sanitize_text_field']);
    }

    public function admin_settings_page() {
        $provider = get_option('ai_chat_provider', 'kira');
        $model = get_option('ai_chat_model', 'kira-3.5-flash');
        $default_prompt = "Bạn LÀ Mai Hoa, nữ trợ lý tư vấn trực tuyến của Cyber Services.
QUY TẮC BẮT BUỘC:
1. Tuyệt đối KHÔNG bao giờ nhắc đến tên Kira, OpenAI, ChatGPT, 3.5 Flash hay phiên bản AI của bạn.
2. Tuyệt đối KHÔNG cung cấp bất kỳ đường link website nào khác ngoại trừ các link thuộc tên miền cyberservices.vn.
3. Khi tự giới thiệu, luôn nói: 'Dạ, em là Mai Hoa trợ lý tư vấn trực tuyến của Cyber Services.'
4. Trả lời CỰC KỲ NGẮN GỌN (từ 2 đến 3 câu), đúng trọng tâm câu hỏi của khách hàng.
5. Giữ thái độ chuyên nghiệp, thân thiện và lịch sự.";
        ?>
        <div class="wrap">
            <h2>Cấu hình Cyber Services AI Chatbox</h2>
            <form method="post" action="options.php">
                <?php settings_fields('ai_chat_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th>Nhà cung cấp AI</th>
                        <td>
                            <select name="ai_chat_provider" id="ai_chat_provider">
                                <option value="kira" <?php selected($provider, 'kira'); ?>>Kira AI (kiraai.vn)</option>
                                <option value="openai" <?php selected($provider, 'openai'); ?>>OpenAI</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="kira_base_url_row">
                        <th>Kira Base URL</th>
                        <td><input type="text" name="ai_chat_kira_base_url" value="<?php echo esc_attr(get_option('ai_chat_kira_base_url', 'https://kiraai.vn')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th>API Key</th>
                        <td><input type="password" name="ai_chat_api_key" value="<?php echo esc_attr(get_option('ai_chat_api_key')); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th>Model AI</th>
                        <td>
                            <select name="ai_chat_model">
                                <option value="kira-3.5-flash" <?php selected($model, 'kira-3.5-flash'); ?>>kira-3.5-flash (Khuyên dùng, phản hồi cực nhanh)</option>
                                <option value="kira-mini-1.0" <?php selected($model, 'kira-mini-1.0'); ?>>kira-mini-1.0 (Miễn phí)</option>
                                <option value="kira-3.5-pro" <?php selected($model, 'kira-3.5-pro'); ?>>kira-3.5-pro (Nâng cao)</option>
                                <option value="gpt-4o-mini" <?php selected($model, 'gpt-4o-mini'); ?>>gpt-4o-mini (OpenAI)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Định hướng AI (System Prompt)</th>
                        <td><textarea name="ai_chat_system_prompt" rows="7" class="large-text"><?php echo esc_textarea(get_option('ai_chat_system_prompt', $default_prompt)); ?></textarea></td>
                    </tr>
                    <tr>
                        <th>Dữ liệu bổ sung / Dịch vụ</th>
                        <td><textarea name="ai_chat_custom_knowledge" rows="5" class="large-text"><?php echo esc_textarea(get_option('ai_chat_custom_knowledge')); ?></textarea></td>
                    </tr>
                    <tr>
                        <th>Telegram Bot Token</th>
                        <td><input type="text" name="ai_chat_telegram_token" value="<?php echo esc_attr(get_option('ai_chat_telegram_token')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th>Telegram Chat ID</th>
                        <td><input type="text" name="ai_chat_telegram_chat_id" value="<?php echo esc_attr(get_option('ai_chat_telegram_chat_id')); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const providerSelect = document.getElementById('ai_chat_provider');
                const baseUrlRow = document.getElementById('kira_base_url_row');
                function toggleRows() {
                    baseUrlRow.style.display = (providerSelect.value === 'kira') ? '' : 'none';
                }
                providerSelect.addEventListener('change', toggleRows);
                toggleRows();
            });
        </script>
        <?php
    }

    /* GIAO DIỆN CRM ADMIN - THÊM PHÂN TRANG VÀ TÌM KIẾM */
    public function admin_leads_page() {
        global $wpdb;
        $table_leads = $wpdb->prefix . 'ai_chat_leads';
        $table_chats = $wpdb->prefix . 'ai_chat_history';

        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Đã xóa khách hàng và lịch sử chat thành công!</p></div>';
        }

        // CHẾ ĐỘ XEM CHI TIẾT 1 KHÁCH HÀNG
        if (isset($_GET['view_session'])) {
            $session_id = sanitize_text_field($_GET['view_session']);
            $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_leads WHERE session_id = %s", $session_id));
            $messages = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_chats WHERE session_id = %s ORDER BY id ASC", $session_id));
            ?>
            <div class="wrap">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ai-chatbox-leads')); ?>" class="button" style="margin-bottom: 15px;">⬅ Quay lại danh sách khách hàng</a>
                
                <div style="display:flex; gap:20px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:300px; max-width:360px; background:#ffffff; padding:20px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.05); border:1px solid #e2e8f0; height:fit-content;">
                        <h3 style="margin-top:0; border-bottom:1px solid #f1f5f9; padding-bottom:12px; color:#0f172a;">👤 Thông Tin Khách Hàng</h3>
                        <p style="margin:10px 0;"><strong>Họ và tên:</strong> <?php echo esc_html($lead->user_name ?? 'Khách vãng lai'); ?></p>
                        <p style="margin:10px 0;"><strong>Số điện thoại:</strong> <a href="tel:<?php echo esc_attr($lead->user_phone ?? ''); ?>" style="color:#f95700; font-weight:bold;"><?php echo esc_html($lead->user_phone ?? '-'); ?></a></p>
                        <p style="margin:10px 0;"><strong>Email:</strong> <?php echo esc_html($lead->user_email ?: 'Chưa cung cấp'); ?></p>
                        <p style="margin:10px 0;"><strong>Thời gian:</strong> <?php echo esc_html($lead->created_at ?? '-'); ?></p>
                        <p style="margin:10px 0;"><strong>Trang đăng ký:</strong> <br/><a href="<?php echo esc_url($lead->page_url ?? ''); ?>" target="_blank" rel="noopener noreferrer" style="color:#2563eb; font-size:12px; word-break:break-all;"><?php echo esc_html($lead->page_url ?? '-'); ?></a></p>
                    </div>

                    <div style="flex:2; min-width:360px; background:#181b22; padding:20px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.05); border:1px solid #282f3d; max-height:75vh; overflow-y:auto;">
                        <h3 style="margin-top:0; border-bottom:1px solid #282f3d; padding-bottom:12px; color:#ffffff;">💬 Chi Tiết Hội Thoại</h3>
                        <?php if ($messages): foreach ($messages as $msg): ?>
                            <div style="display:flex; justify-content:<?php echo $msg->sender === 'user' ? 'flex-end' : 'flex-start'; ?>; margin-bottom:14px;">
                                <div style="max-width:80%; padding:12px 16px; border-radius:14px; font-size:13.5px; line-height:1.5; background:<?php echo $msg->sender === 'user' ? '#f95700' : '#222733'; ?>; color:#ffffff; border:<?php echo $msg->sender === 'user' ? 'none' : '1px solid #2e3547'; ?>;">
                                    <div style="font-size:10.5px; margin-bottom:4px; opacity:0.8; font-weight:600;">
                                        <?php echo $msg->sender === 'user' ? 'Khách hàng (' . esc_html($lead->user_name ?? 'Khách') . ')' : 'Mai Hoa / CSKH'; ?> • <?php echo esc_html($msg->created_at); ?>
                                    </div>
                                    <div><?php echo nl2br(esc_html($msg->message)); ?></div>
                                </div>
                            </div>
                        <?php endforeach; else: ?>
                            <p style="color:#8b949e; text-align:center; padding:30px 0;">Chưa có thêm tin nhắn nào.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        // LỌC VÀ TÌM KIẾM
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
        
        $where = "1=1";
        $args = [];

        if (!empty($search)) {
            $where .= " AND (user_name LIKE %s OR user_phone LIKE %s)";
            $args[] = '%' . $wpdb->esc_like($search) . '%';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        if (!empty($filter_status)) {
            $where .= " AND status = %s";
            $args[] = $filter_status;
        }

        // PHÂN TRANG
        $per_page = 20;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $per_page;

        $count_args = $args; // Lưu biến tạm cho câu truy vấn Count
        
        $query = "SELECT * FROM $table_leads WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d";
        $args[] = $per_page;
        $args[] = $offset;

        $leads = empty($args) ? $wpdb->get_results($query) : $wpdb->get_results($wpdb->prepare($query, $args));

        $total_query = "SELECT COUNT(id) FROM $table_leads WHERE $where";
        $total_items = empty($count_args) ? $wpdb->get_var($total_query) : $wpdb->get_var($wpdb->prepare($total_query, $count_args));
        $total_pages = ceil($total_items / $per_page);

        $export_url = wp_nonce_url(admin_url('admin-post.php?action=export_ai_leads'), 'export_ai_leads_action', 'export_nonce');
        $admin_nonce = wp_create_nonce('ai_admin_leads_nonce');
        ?>
        <div class="wrap">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 10px;">
                <h2>Danh Sách Khách Hàng Tiềm Năng (Leads)</h2>
                <div>
                    <button type="button" id="bulk-delete-btn" class="button" style="background:#ef4444; color:#fff; border-color:#dc2626; margin-right:10px; display:none;">🗑️ Xóa các mục đã chọn</button>
                    <a href="<?php echo esc_url($export_url); ?>" class="button button-primary" style="background:#10b981; border-color:#10b981;">📥 Xuất file Excel (CSV)</a>
                </div>
            </div>

            <!-- Khung tìm kiếm và bộ lọc -->
            <form method="get" style="margin-bottom: 15px; display:flex; gap: 10px; align-items:center;">
                <input type="hidden" name="page" value="ai-chatbox-leads">
                <select name="filter_status">
                    <option value="">-- Tất cả trạng thái --</option>
                    <option value="new" <?php selected($filter_status, 'new'); ?>>🟡 Mới tiếp nhận</option>
                    <option value="contacting" <?php selected($filter_status, 'contacting'); ?>>🔵 Đang liên hệ</option>
                    <option value="closed" <?php selected($filter_status, 'closed'); ?>>🟢 Đã chốt hợp đồng</option>
                    <option value="cancelled" <?php selected($filter_status, 'cancelled'); ?>>🔴 Hủy/Không nghe máy</option>
                </select>
                <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Tìm tên hoặc SĐT..." style="width: 250px;">
                <button type="submit" class="button">Lọc & Tìm kiếm</button>
                <?php if(!empty($search) || !empty($filter_status)): ?>
                    <a href="?page=ai-chatbox-leads" class="button">Xóa lọc</a>
                <?php endif; ?>
            </form>
            
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th width="4%"><input type="checkbox" id="select-all-leads"></th>
                        <th width="12%">Thời gian</th>
                        <th width="14%">Họ và tên</th>
                        <th width="12%">Số điện thoại</th>
                        <th width="18%">Trang đăng ký</th>
                        <th width="16%">Trạng thái (CRM)</th>
                        <th width="24%">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($leads): foreach ($leads as $lead): 
                        $msg_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_chats WHERE session_id = %s", $lead->session_id));
                        $delete_url = wp_nonce_url(admin_url('admin.php?page=ai-chatbox-leads&action=delete_lead&lead_id=' . $lead->id), 'delete_lead_' . $lead->id);
                        $status = isset($lead->status) ? $lead->status : 'new';
                    ?>
                        <tr>
                            <td><input type="checkbox" class="lead-checkbox" value="<?php echo esc_attr($lead->id); ?>"></td>
                            <td><?php echo esc_html($lead->created_at); ?></td>
                            <td><strong style="color:#0f172a; font-size:14px;"><?php echo esc_html($lead->user_name); ?></strong></td>
                            <td>
                                <a href="tel:<?php echo esc_attr($lead->user_phone); ?>" style="font-weight:700; color:#f95700;">
                                    <?php echo esc_html($lead->user_phone); ?>
                                </a>
                            </td>
                            <td><a href="<?php echo esc_url($lead->page_url); ?>" target="_blank" rel="noopener noreferrer" style="color:#64748b; font-size:12px;"><?php echo esc_html($lead->page_url); ?></a></td>
                            <td>
                                <select class="ai-crm-status" data-id="<?php echo esc_attr($lead->id); ?>" style="width:100%; font-size:13px; font-weight:600; border-radius:6px; <?php echo $status=='new'?'color:#d97706;':($status=='contacting'?'color:#2563eb;':($status=='closed'?'color:#16a34a;':'color:#dc2626;')); ?>">
                                    <option value="new" <?php selected($status, 'new'); ?>>🟡 Mới tiếp nhận</option>
                                    <option value="contacting" <?php selected($status, 'contacting'); ?>>🔵 Đang liên hệ</option>
                                    <option value="closed" <?php selected($status, 'closed'); ?>>🟢 Đã chốt hợp đồng</option>
                                    <option value="cancelled" <?php selected($status, 'cancelled'); ?>>🔴 Hủy / KNM</option>
                                </select>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=ai-chatbox-leads&view_session=' . urlencode($lead->session_id))); ?>" class="button" style="color:#f95700; border-color:#f95700; margin-right:4px;">
                                    💬 Xem chat (<?php echo (int)$msg_count; ?>)
                                </a>
                                <a href="<?php echo esc_url($delete_url); ?>" class="button button-link-delete" onclick="return confirm('Bạn có chắc chắn muốn xóa?');" style="color:#ef4444; vertical-align:middle;">
                                    🗑️ Xóa
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" style="text-align:center; padding:20px;">Không tìm thấy khách hàng nào.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Hiển thị phân trang -->
            <?php
            $page_links = paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo; Trước'),
                'next_text' => __('Sau &raquo;'),
                'total' => $total_pages,
                'current' => $paged
            ]);
            if ($page_links) {
                echo '<div class="tablenav"><div class="tablenav-pages" style="margin: 1em 0;">' . $page_links . '</div></div>';
            }
            ?>

            <script>
            jQuery(document).ready(function($) {
                const adminNonce = '<?php echo esc_js($admin_nonce); ?>';

                $('.ai-crm-status').on('change', function() {
                    let select = $(this);
                    let val = select.val();
                    select.css('color', val==='new'?'#d97706':(val==='contacting'?'#2563eb':(val==='closed'?'#16a34a':'#dc2626')));
                    $.post(ajaxurl, {
                        action: 'update_lead_status',
                        security: adminNonce,
                        lead_id: select.data('id'),
                        status: val
                    });
                });

                $('#select-all-leads').on('change', function() {
                    $('.lead-checkbox').prop('checked', this.checked);
                    toggleBulkDeleteBtn();
                });

                $('.lead-checkbox').on('change', function() {
                    toggleBulkDeleteBtn();
                });

                function toggleBulkDeleteBtn() {
                    if ($('.lead-checkbox:checked').length > 0) {
                        $('#bulk-delete-btn').show();
                    } else {
                        $('#bulk-delete-btn').hide();
                    }
                }

                $('#bulk-delete-btn').on('click', function() {
                    let selectedIds = [];
                    $('.lead-checkbox:checked').each(function() {
                        selectedIds.push($(this).val());
                    });

                    if (selectedIds.length === 0) return;

                    if (confirm('Bạn có chắc chắn muốn xóa ' + selectedIds.length + ' khách hàng đã chọn cùng toàn bộ lịch sử chat?')) {
                        $.post(ajaxurl, {
                            action: 'bulk_delete_ai_leads',
                            security: adminNonce,
                            lead_ids: selectedIds
                        }, function(res) {
                            if (res.success) {
                                location.reload();
                            } else {
                                alert('Có lỗi xảy ra, vui lòng thử lại.');
                            }
                        });
                    }
                });
            });
            </script>
        </div>
        <?php
    }

    /* 4. FRONTEND UI MODERN LIGHT THEME */
    public function render_chatbox_ui() {
        if (function_exists('is_cart') && (is_cart() || is_checkout())) return;
        ?>
        <div id="ai-chat-root">
            <button id="ai-chat-btn" type="button" aria-label="Open Chat">
                <span class="ai-ping-ring" aria-hidden="true"></span>
                <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
            </button>

            <div id="ai-chat-box">
                <div class="ai-chat-header">
                    <div class="ai-chat-header-left">
                        <div class="ai-header-avatar">CS</div>
                        <div class="ai-chat-header-info">
                            <div class="ai-chat-title">Hỗ trợ trực tuyến</div>
                            <div class="ai-chat-status">
                                <span class="ai-dot-online"></span> Trực tuyến
                            </div>
                        </div>
                    </div>
                    <button id="ai-chat-close" type="button">✕</button>
                </div>

                <div class="ai-chat-messages" id="ai-chat-body"></div>

                <div class="ai-chat-footer-wrap">
                    <div class="ai-chat-input-area">
                        <div class="ai-input-wrap">
                            <input type="text" id="ai-chat-input" placeholder="Nhập tin nhắn..." autocomplete="off" />
                        </div>
                        <button type="button" id="ai-chat-send-btn" class="ai-chat-submit" aria-label="Gửi">
                            <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                        </button>
                    </div>
                    <div class="ai-chat-copyright">Powered by Cyber Services Việt Nam</div>
                </div>
            </div>
        </div>
        <?php
    }

    /* 5. LƯU LEAD */
    public function handle_save_lead() {
        check_ajax_referer('ai_chat_nonce_action', 'security');

        global $wpdb;
        $session_id  = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $name        = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $phone       = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $email       = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $current_url = isset($_POST['current_url']) ? esc_url_raw($_POST['current_url']) : '';

        if (empty($name) || empty($phone)) {
            wp_send_json_error(['error' => 'Vui lòng nhập đầy đủ tên và số điện thoại.']);
        }

        $table_leads = $wpdb->prefix . 'ai_chat_leads';
        $wpdb->insert($table_leads, [
            'session_id' => $session_id,
            'user_name'  => $name,
            'user_phone' => $phone,
            'user_email' => $email,
            'page_url'   => $current_url
        ], ['%s', '%s', '%s', '%s', '%s']);

        set_transient('ai_lead_' . $session_id, [
            'name'  => $name,
            'phone' => $phone,
            'email' => $email
        ], DAY_IN_SECONDS * 7);

        // Chuyển sang định dạng HTML cho Telegram
        $telegram_msg = "🎯 <b>CÓ KHÁCH HÀNG TIỀM NĂNG MỚI!</b>\n"
                      . "👤 <b>Họ tên:</b> " . esc_html($name) . "\n"
                      . "📞 <b>Số điện thoại:</b> <code>" . esc_html($phone) . "</code>\n"
                      . "📧 <b>Email:</b> " . ($email ? esc_html($email) : 'Chưa cung cấp') . "\n"
                      . "🔗 <b>Trang đang xem:</b> " . esc_url($current_url);
        
        $this->send_telegram_notification($telegram_msg);
        wp_send_json_success();
    }

    /* 6. GỌI API */
    private function call_chat_completion($messages, $api_key, $model, $endpoint) {
        $body_json = wp_json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'stream'      => false,
            'temperature' => 0.2
        ]);

        $response = wp_remote_post($endpoint, [
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . trim($api_key)
            ],
            'body'        => $body_json,
            'timeout'     => 45,
            'sslverify'   => true
        ]);

        if (is_wp_error($response)) {
            return ['error' => 'Lỗi kết nối API WordPress: ' . $response->get_error_message()];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($response_code !== 200) {
            $raw_msg = $data['error']['message'] ?? $data['message'] ?? '';
            if ($response_code == 429 || strpos($raw_msg, 'Resource exhausted') !== false) {
                return ['error' => 'Hệ thống đang quá tải lượt hỏi cùng lúc, anh/chị vui lòng thử lại sau giây lát hoặc liên hệ Hotline để được hỗ trợ trực tiếp ạ!'];
            }
            return ['error' => $raw_msg ?: ('API Error: Mã HTTP ' . $response_code)];
        }

        if (isset($data['choices'][0]['message']['content'])) {
            return ['reply' => trim($data['choices'][0]['message']['content'])];
        }

        return ['error' => 'Phản hồi từ AI không đúng định dạng.'];
    }

    /* 7. XỬ LÝ MESSAGE */
    public function handle_chat_message() {
        check_ajax_referer('ai_chat_nonce_action', 'security');

        global $wpdb;
        $session_id  = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $message     = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';

        if (empty($session_id) || empty($message)) {
            wp_send_json_error(['error' => 'Dữ liệu tin nhắn rỗng.']);
        }

        // Rate limit
        $client_ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $rate_limit_key = 'ai_rate_limit_' . md5($session_id . '_' . $client_ip);
        $msg_count = (int) get_transient($rate_limit_key);
        if ($msg_count >= 8) {
            wp_send_json_error(['error' => 'Bạn đang nhắn tin quá nhanh, vui lòng chờ khoảng 1 phút rồi thử lại nhé!']);
        }
        set_transient($rate_limit_key, $msg_count + 1, MINUTE_IN_SECONDS);

        $user_name = '';
        $lead_info = get_transient('ai_lead_' . $session_id);
        if (!empty($lead_info['name'])) {
            $user_name = $lead_info['name'];
        } else {
            $db_name = $wpdb->get_var($wpdb->prepare("SELECT user_name FROM {$wpdb->prefix}ai_chat_leads WHERE session_id = %s", $session_id));
            if ($db_name) {
                $user_name = $db_name;
            }
        }

        $table_chats = $wpdb->prefix . 'ai_chat_history';
        $raw_history = $wpdb->get_results($wpdb->prepare(
            "SELECT sender, message FROM $table_chats WHERE session_id = %s ORDER BY id DESC LIMIT 4", 
            $session_id
        ));

        $wpdb->insert($table_chats, [
            'session_id' => $session_id,
            'sender'     => 'user',
            'message'    => $message
        ], ['%s', '%s', '%s']);
        $user_msg_id = (int) $wpdb->insert_id; // Lưu ID để trả về cho client đồng bộ polling

        // Gửi thông báo Telegram (Đã fix lỗi parse HTML an toàn)
        $telegram_msg = "💬 <b>Khách hàng " . ($user_name ? esc_html($user_name) : 'Khách') . " nhắn:</b>\n"
                      . esc_html($message) . "\n\n"
                      . "<i>(Để trả lời, hãy bấm Reply/Trả lời trực tiếp vào tin nhắn này)</i>\n"
                      . "🔑 ID: <code>" . esc_html($session_id) . "</code>";
        $this->send_telegram_notification($telegram_msg);

        // Nạp Cache bài viết
        $cached_posts = get_transient('ai_chat_posts_cache');
        if ($cached_posts === false) {
            $recent_posts = get_posts([
                'post_type'   => ['post', 'product'],
                'numberposts' => 4,
                'post_status' => 'publish'
            ]);
            $cached_posts = '';
            if (!empty($recent_posts)) {
                foreach ($recent_posts as $post) {
                    $excerpt = wp_strip_all_tags($post->post_excerpt ?: $post->post_content);
                    $cached_posts .= "- {$post->post_title}: " . mb_substr($excerpt, 0, 120) . "\n";
                }
            }
            set_transient('ai_chat_posts_cache', $cached_posts, HOUR_IN_SECONDS);
        }

        $context_data = get_option('ai_chat_custom_knowledge', '') . "\n" . $cached_posts;
        $system_prompt = get_option('ai_chat_system_prompt') . "\n(Tên khách hàng: " . ($user_name ?: 'Quý khách') . ")\n" . $context_data;

        $messages = [
            ['role' => 'system', 'content' => $system_prompt]
        ];

        // Giới hạn độ dài ngữ cảnh để tiết kiệm API Token (Cắt chuỗi 1000 ký tự)
        if (!empty($raw_history)) {
            $history = array_reverse($raw_history);
            foreach ($history as $h) {
                $messages[] = [
                    'role'    => ($h->sender === 'user') ? 'user' : 'assistant',
                    'content' => mb_substr($h->message, 0, 1000) 
                ];
            }
        }

        $messages[] = [
            'role'    => 'user',
            'content' => mb_substr($message, 0, 1000)
        ];

        $provider = get_option('ai_chat_provider', 'kira');
        $api_key  = get_option('ai_chat_api_key');
        $model    = get_option('ai_chat_model', 'kira-3.5-flash');

        if (empty($api_key)) {
            wp_send_json_error(['error' => 'Chưa cấu hình API Key trong trang quản trị!', 'user_msg_id' => $user_msg_id]);
        }

        if ($provider === 'kira') {
            $base_url = rtrim(get_option('ai_chat_kira_base_url', 'https://kiraai.vn'), '/');
            $endpoint = $base_url . '/api/v1/chat/completions';
        } else {
            $endpoint = 'https://api.openai.com/v1/chat/completions';
        }

        $call_result = $this->call_chat_completion($messages, $api_key, $model, $endpoint);

        if (isset($call_result['error'])) {
            wp_send_json_error(['error' => $call_result['error'], 'user_msg_id' => $user_msg_id]);
        }

        $bot_reply = $call_result['reply'];

        $wpdb->insert($table_chats, [
            'session_id' => $session_id,
            'sender'     => 'bot',
            'message'    => $bot_reply
        ], ['%s', '%s', '%s']);
        $bot_msg_id = (int) $wpdb->insert_id;

        // Trả kèm ID của 2 tin nhắn (user + bot) để client đồng bộ chính xác với DB khi polling
        wp_send_json_success([
            'reply'       => $bot_reply,
            'user_msg_id' => $user_msg_id,
            'bot_msg_id'  => $bot_msg_id
        ]);
    }

    public function load_chat_history() {
        check_ajax_referer('ai_chat_nonce_action', 'security');
        
        global $wpdb;
        $session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
        $table_chats = $wpdb->prefix . 'ai_chat_history';

        if (empty($session_id)) {
            wp_send_json([]);
        }

        // Lấy kèm ID để JS đồng bộ chính xác tin nhắn mới (tránh trùng/mất khi polling)
        $chats = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sender, message FROM $table_chats WHERE session_id = %s ORDER BY id ASC",
            $session_id
        ));

        wp_send_json($chats ?: []);
    }

    /* 8. GỬI TELEGRAM (Đổi sang Parse Mode HTML an toàn hơn) */
    private function send_telegram_notification($text) {
        $token   = get_option('ai_chat_telegram_token');
        $chat_id = get_option('ai_chat_telegram_chat_id');

        if (!$token || !$chat_id) return;

        wp_remote_post("https://api.telegram.org/bot{$token}/sendMessage", [
            'body' => [
                'chat_id'    => $chat_id,
                'text'       => $text,
                'parse_mode' => 'HTML'
            ],
            'sslverify' => true
        ]);
    }
}

new AISalesChatbox();