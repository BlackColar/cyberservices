<?php
/**
 * Cyber Services SEO Auditor - Module chính
 *
 * Meta box sidebar + FAB + modal + 9 AJAX endpoints.
 * GIỮ NGUYÊN meta keys của module cũ để dữ liệu dàn ý không bị mất.
 *
 * @package Kira_AI_SA
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kira_SA_Auditor
{
    const NONCE_ACTION = 'kira_sa_auditor_nonce';
    // Meta keys giữ nguyên (tương thích dữ liệu module cũ):
    const META_OUTLINE = '_kira_ai_auditor_outline';
    const META_KEYWORDS = '_kira_ai_auditor_keywords';
    const META_COMPETITOR = '_kira_ai_auditor_competitor';
    const META_TITLE = '_kira_ai_auditor_title';
    const META_MISSING_TOPICS = '_kira_ai_auditor_missing_topics';
    const META_FOCUS_KEYWORD = '_kira_ai_auditor_focus_keyword';
    const META_WORD_COUNT = '_kira_ai_auditor_word_count';

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
        add_action('add_meta_boxes', array($this, 'register_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_editor_assets'));
        add_action('admin_footer', array($this, 'render_modal_html'));

        // Tự động dọn meta dàn ý khi bài viết bị xóa (tránh rác postmeta trong DB)
        add_action('deleted_post', array($this, 'cleanup_post_meta'));

        add_action('wp_ajax_kira_sa_auditor_scrape', array($this, 'ajax_scrape_url'));
        add_action('wp_ajax_kira_sa_auditor_generate_outline', array($this, 'ajax_generate_outline'));
        add_action('wp_ajax_kira_sa_auditor_get_data', array($this, 'ajax_get_data'));
        add_action('wp_ajax_kira_sa_auditor_insert_heading', array($this, 'ajax_insert_heading'));
        add_action('wp_ajax_kira_sa_auditor_refresh_status', array($this, 'ajax_refresh_status'));
        add_action('wp_ajax_kira_sa_auditor_save_outline', array($this, 'ajax_save_outline'));
        add_action('wp_ajax_kira_sa_auditor_scrape_urls', array($this, 'ajax_scrape_urls'));
        add_action('wp_ajax_kira_sa_auditor_generate_section', array($this, 'ajax_generate_section'));
        add_action('wp_ajax_kira_sa_auditor_generate_keyword_sentence', array($this, 'ajax_generate_keyword_sentence'));
        add_action('wp_ajax_kira_sa_auditor_auto_fix', array($this, 'ajax_auto_fix'));
    }

    /**
     * Dọn meta SEO Auditor khi bài viết bị xóa vĩnh viễn.
     *
     * @param int $post_id
     */
    public function cleanup_post_meta($post_id)
    {
        if (!$post_id) {
            return;
        }
        $meta_keys = array(
            self::META_OUTLINE,
            self::META_KEYWORDS,
            self::META_COMPETITOR,
            self::META_TITLE,
            self::META_MISSING_TOPICS,
            self::META_FOCUS_KEYWORD,
            self::META_WORD_COUNT,
        );
        foreach ($meta_keys as $meta_key) {
            delete_post_meta($post_id, $meta_key);
        }
    }

    public function register_meta_box()
    {
        $enabled_post_types = get_option('kira_ai_post_types', array());
        if (empty($enabled_post_types)) {
            $enabled_post_types = array('post', 'page');
        }

        foreach ($enabled_post_types as $post_type) {
            if (!post_type_supports($post_type, 'editor')) {
                continue;
            }
            add_meta_box(
                'kira_sa_auditor_box',
                '<span class="dashicons dashicons-search" style="font-size:16px; width:16px; height:16px; line-height:1.6; margin-right:4px;"></span> Cyber Services SEO Auditor',
                array($this, 'render_meta_box'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box($post)
    {
        if (!$post || !current_user_can('edit_post', $post->ID)) {
            return;
        }

        $has_api_key = !empty(get_option('kira_ai_api_key', ''));
        ?>
        <div id="kira-sa-auditor-launcher" data-post-id="<?php echo (int) $post->ID; ?>"
             data-has-api-key="<?php echo $has_api_key ? '1' : '0'; ?>"
             data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
             data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>">
            <details class="kira-auditor-details">
                <summary class="kira-auditor-summary" role="button" tabindex="0">
                    🧠 Mở Cyber Services SEO Auditor
                </summary>
                <div class="kira-auditor-details-body" id="kira-ai-auditor-root">
                    <p class="kira-auditor-details-note">
                        Phân tích đối thủ, tạo dàn ý, chèn heading & theo dõi SEO Score.
                    </p>
                    <button type="button" class="kira-auditor-btn kira-auditor-btn-ghost kira-auditor-auto-fix-btn-static" style="width:100%; margin:8px 0 2px; border-color:#22c55e; color:#15803d; background:#f0fdf4;">
                        🚀 AI Tự động bổ sung bài viết
                    </button>
                    <div class="kira-auditor-details-loading">Đang tải...</div>
                </div>
            </details>
        </div>
        <?php
    }

    public function render_modal_html()
    {
        global $pagenow;
        if (!in_array($pagenow, array('post.php', 'post-new.php'), true)) {
            return;
        }

        $enabled_post_types = get_option('kira_ai_post_types', array());
        if (empty($enabled_post_types)) {
            $enabled_post_types = array('post', 'page');
        }
        $post_type = '';
        if (isset($_GET['post_type'])) {
            $post_type = sanitize_text_field(wp_unslash($_GET['post_type']));
        } elseif (isset($_POST['post_type'])) {
            $post_type = sanitize_text_field(wp_unslash($_POST['post_type']));
        }
        if (empty($post_type) && !empty($_GET['post'])) {
            $post_obj = get_post((int) $_GET['post']);
            if ($post_obj) {
                $post_type = $post_obj->post_type;
            }
        }
        if (empty($post_type)) {
            $post_type = 'post';
        }
        if (!in_array($post_type, $enabled_post_types, true)) {
            return;
        }
        ?>
        <button type="button" id="kira-auditor-fab" title="Mở Cyber Services SEO Auditor">
            🧠 Cyber Services SEO Auditor
        </button>

        <div id="kira-auditor-modal" class="kira-auditor-modal-hidden">
            <div class="kira-auditor-modal-overlay"></div>
            <div class="kira-auditor-modal-box">
                <div class="kira-auditor-modal-header">
                    <h3>🧠 Cyber Services SEO Auditor</h3>
                    <button type="button" class="kira-auditor-modal-close" aria-label="Đóng">✕</button>
                </div>
                <div class="kira-auditor-modal-body" id="kira-auditor-modal-body">
                    <?php $this->render_static_panel(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render static UI panel (đầy đủ form + tabs) bằng PHP.
     * Dùng cho modal body — modal luôn hiển thị nội dung ngay cả khi JS chưa render.
     */
    private function render_static_panel()
    {
        $i18n = array(
            'tab_headings' => 'Heading Checklist',
            'tab_keywords' => 'Keyword Tracker',
        );
        ?>
        <div class="kira-auditor-guide">
            <div class="kira-auditor-guide-title">📖 Cách dùng</div>
            <ol class="kira-auditor-guide-list">
                <li>Dán <strong>URL đối thủ</strong> xuống dưới → bấm <strong>Phân tích</strong></li>
                <li>Bấm <strong>✨ Tạo dàn ý chuẩn SEO</strong></li>
                <li><strong>Viết bài tại khung soạn thảo lớn</strong> của trang</li>
                <li>Quay lại đây: bấm <strong>+ Chèn nhanh</strong> để thêm heading vào bài</li>
                <li>Badge Xanh/Vàng/Đỏ tự cập nhật khi viết</li>
            </ol>
        </div>

        <div class="kira-auditor-setup">
            <div class="kira-auditor-setup-title">🔍 Phân tích đối thủ & Tạo dàn ý</div>
            <div class="kira-auditor-mode"></div>
            <label class="kira-auditor-label">URL bài viết đối thủ <em style="color:#94a3b8;font-weight:400;">(mỗi dòng 1 URL, tối đa 5)</em></label>
            <div class="kira-auditor-url-row">
                <textarea class="kira-auditor-url" rows="2" placeholder="https://doithu1.com/bai-viet...&#10;https://doithu2.com/bai-viet..."></textarea>
                <button type="button" class="kira-auditor-btn kira-auditor-btn-ghost kira-auditor-scrape-btn">Phân tích</button>
            </div>
            <label class="kira-auditor-label" style="margin-top:8px;">Từ khóa mục tiêu (Focus Keyword)</label>
            <input type="text" class="kira-auditor-focus-kw" placeholder="Ví dụ: dịch vụ SEO tổng thể"/>
            <label class="kira-auditor-label" style="margin-top:8px;">Yêu cầu bổ sung (Prompt) <em style="color:#94a3b8;font-weight:400;">tùy chọn</em></label>
            <textarea class="kira-auditor-extra-prompt" rows="2" placeholder="VD: nhấn mạnh giá rẻ, thêm bảng so sánh..."></textarea>
            <button type="button" class="kira-auditor-btn kira-auditor-btn-primary kira-auditor-generate-btn" style="width:100%; margin-top:10px;">✨ Tạo dàn ý chuẩn SEO từ đối thủ</button>
            <button type="button" class="kira-auditor-btn kira-auditor-btn-ghost kira-auditor-auto-fix-btn" style="width:100%; margin-top:6px; border-color:#22c55e; color:#15803d;">🚀 AI Tự động bổ sung bài viết</button>
            <div class="kira-auditor-scrape-preview" style="display:none;">
                <div class="kira-auditor-preview-title"></div>
                <div class="kira-auditor-preview-meta"></div>
                <div class="kira-auditor-preview-headings"></div>
                <div class="kira-auditor-preview-keywords"></div>
            </div>
            <div class="kira-auditor-status"></div>
        </div>

        <div class="kira-auditor-tabs">
            <button type="button" class="kira-auditor-tab kira-auditor-tab-active" data-tab="headings">📋 <?php echo esc_html($i18n['tab_headings']); ?></button>
            <button type="button" class="kira-auditor-tab" data-tab="keywords">🎯 <?php echo esc_html($i18n['tab_keywords']); ?></button>
            <button type="button" class="kira-auditor-tab" data-tab="score">🏆 SEO Score</button>
        </div>

        <div class="kira-auditor-tab-panel kira-auditor-tab-panel-active" data-panel="headings">
            <div class="kira-auditor-headings-list"></div>
        </div>
        <div class="kira-auditor-tab-panel" data-panel="keywords">
            <div class="kira-auditor-keywords-list"></div>
        </div>
        <div class="kira-auditor-tab-panel" data-panel="score">
            <div class="kira-auditor-score-list"></div>
        </div>
        <?php
    }

    public function enqueue_editor_assets($hook)
    {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $enabled_post_types = get_option('kira_ai_post_types', array());
        if (empty($enabled_post_types)) {
            $enabled_post_types = array('post', 'page');
        }
        if (!in_array($screen->post_type, $enabled_post_types, true)) {
            return;
        }

        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : (isset($_POST['post_ID']) ? (int) $_POST['post_ID'] : 0);

        // filemtime() = cache-busting tự động: browser luôn tải bản mới nhất khi file thay đổi
        $css_v = file_exists(KIRA_AI_SA_DIR . 'assets/css/kira-ai-auditor.css') ? filemtime(KIRA_AI_SA_DIR . 'assets/css/kira-ai-auditor.css') : KIRA_AI_SA_VERSION;
        $js_v  = file_exists(KIRA_AI_SA_DIR . 'assets/js/kira-ai-auditor.js') ? filemtime(KIRA_AI_SA_DIR . 'assets/js/kira-ai-auditor.js') : KIRA_AI_SA_VERSION;

        wp_enqueue_style('kira-ai-auditor-css', KIRA_AI_SA_URL . 'assets/css/kira-ai-auditor.css', array(), $css_v);
        wp_enqueue_script('kira-ai-auditor-js', KIRA_AI_SA_URL . 'assets/js/kira-ai-auditor.js', array('jquery'), $js_v, true);

        wp_localize_script('kira-ai-auditor-js', 'kira_ai_auditor_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
            'post_id'  => $post_id,
            'has_api_key' => !empty(get_option('kira_ai_api_key', '')),
            'i18n' => array(
                'scraping'      => 'Đang phân tích URL đối thủ...',
                'generating'    => 'AI đang tạo dàn ý chuẩn SEO...',
                'error_api_key' => 'Vui lòng cấu hình API Key trong plugin Kira AI hoặc trang cấu hình.',
                'no_url'        => 'Vui lòng dán URL bài viết đối thủ.',
                'no_kw'         => 'Vui lòng nhập từ khóa mục tiêu.',
                'inserted'      => 'Đã chèn heading vào bài viết.',
                'tab_headings'  => 'Heading Checklist',
                'tab_keywords'  => 'Keyword Tracker',
            ),
        ));
    }

    public function ajax_scrape_url()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if (empty($url)) {
            wp_send_json_error('Vui lòng dán URL bài viết đối thủ.');
        }

        $scraper = new Kira_SA_Scraper();
        $result = $scraper->scrape_url($url);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $extractor = new Kira_SA_Keyword_Extractor();
        $keywords = $extractor->extract_keywords($result['main_text'], 18);

        wp_send_json_success(array(
            'url'               => $url,
            'title'             => $result['title'],
            'meta_description'  => $result['meta_description'],
            'headings'          => $result['headings'],
            'keywords'          => $keywords,
            'word_count'        => (int) ($result['word_count'] ?? 0),
        ));
    }

    public function ajax_scrape_urls()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $urls = isset($_POST['urls']) ? $_POST['urls'] : array();
        $urls = array_map('esc_url_raw', array_map('wp_unslash', (array) $urls));

        if (empty($urls)) {
            wp_send_json_error('Vui lòng nhập ít nhất 1 URL đối thủ.');
        }

        $scraper = new Kira_SA_Scraper();
        $result = $scraper->scrape_urls($urls);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Trích xuất từ khóa từ văn bản đối thủ đã merge (Bug fix: trước đây luôn trả rỗng)
        $extractor = new Kira_SA_Keyword_Extractor();
        $keywords = array();
        if (!empty($result['main_text'])) {
            $keywords = $extractor->extract_keywords($result['main_text'], 18);
        }

        wp_send_json_success(array(
            'urls'             => $result['urls'],
            'titles'           => $result['titles'],
            'title'            => $result['title'],
            'meta_description' => $result['meta_description'],
            'headings'         => $this->sanitize_competitor_headings($result['headings']),
            'keywords'         => $keywords,
            'sources_count'    => (int) $result['sources_count'],
            'word_count'       => (int) ($result['word_count'] ?? 0),
        ));
    }

    public function ajax_generate_outline()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $focus_keyword = isset($_POST['focus_keyword']) ? sanitize_text_field(wp_unslash($_POST['focus_keyword'])) : '';
        $extra_prompt  = isset($_POST['extra_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['extra_prompt'])) : '';
        $post_id       = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $competitor    = isset($_POST['competitor']) ? $this->sanitize_competitor_data($_POST['competitor']) : array();
        $keywords      = isset($_POST['competitor_keywords']) ? $this->sanitize_competitor_keywords($_POST['competitor_keywords']) : array();

        if (empty($focus_keyword)) {
            wp_send_json_error('Vui lòng nhập từ khóa mục tiêu.');
        }
        if (empty($competitor['title']) && empty($competitor['headings'])) {
            wp_send_json_error('Chưa có dữ liệu đối thủ. Vui lòng phân tích URL đối thủ trước.');
        }

        $generator = new Kira_SA_Outline_Generator();
        $outline_data = $generator->generate_outline($focus_keyword, $competitor, $keywords, $extra_prompt);

        if (is_wp_error($outline_data)) {
            wp_send_json_error($outline_data->get_error_message());
        }

        if ($post_id) {
            $this->save_outline_to_post($post_id, $outline_data, $competitor, $keywords);
            update_post_meta($post_id, self::META_FOCUS_KEYWORD, $focus_keyword);
            if (!empty($competitor['word_count'])) {
                $this->save_recommended_word_count($post_id, (int) $competitor['word_count']);
            }
        }

        wp_send_json_success($outline_data);
    }

    public function ajax_get_data()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
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

        $data = $this->get_post_audit_data($post_id, $post->post_content);

        // Bổ sung danh sách heading hiện tại của bài viết (kèm cấp độ) cho layout 2 cột
        if (!empty($post->post_content)) {
            $generator = new Kira_SA_Outline_Generator();
            $data['existing_heading_nodes'] = $generator->extract_heading_nodes($post->post_content);
        } else {
            $data['existing_heading_nodes'] = array();
        }

        wp_send_json_success($data);
    }

    public function ajax_refresh_status()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';

        if (!$post_id) {
            wp_send_json_error('Thiếu ID bài viết.');
        }
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Bạn không có quyền chỉnh sửa bài viết này.');
        }

        $outline = get_post_meta($post_id, self::META_OUTLINE, true);
        $target_keywords = get_post_meta($post_id, self::META_KEYWORDS, true);

        if (!is_array($outline)) $outline = array();
        if (!is_array($target_keywords)) $target_keywords = array();

        $generator = new Kira_SA_Outline_Generator();
        $audited_headings = $generator->audit_headings($outline, $content);
        $tracked_keywords = $generator->track_keywords($target_keywords, $content);

        $recommended_word_count = (int) get_post_meta($post_id, self::META_WORD_COUNT, true);
        $focus_keyword = get_post_meta($post_id, self::META_FOCUS_KEYWORD, true);
        $post = get_post($post_id);
        $post_title = $post ? $post->post_title : '';

        $score_data = $generator->audit_seo_score($content, $target_keywords, $recommended_word_count, $post_title);

        wp_send_json_success(array(
            'outline_status'   => $audited_headings,
            'keyword_status'   => $tracked_keywords,
            'score'            => $score_data,
        ));
    }

    public function ajax_insert_heading()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $heading = isset($_POST['heading']) ? sanitize_text_field(wp_unslash($_POST['heading'])) : '';
        $level   = isset($_POST['level']) ? sanitize_text_field(wp_unslash($_POST['level'])) : 'h2';
        $anchor  = isset($_POST['anchor']) ? sanitize_text_field(wp_unslash($_POST['anchor'])) : '';
        $position = isset($_POST['position']) ? sanitize_text_field(wp_unslash($_POST['position'])) : 'append';
        $focus_keyword = isset($_POST['focus_keyword']) ? sanitize_text_field(wp_unslash($_POST['focus_keyword'])) : '';

        if (!$post_id) {
            wp_send_json_error('Thiếu ID bài viết.');
        }
        if (empty($heading)) {
            wp_send_json_error('Thiếu nội dung heading.');
        }
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Bạn không có quyền chỉnh sửa bài viết này.');
        }

        $level = in_array($level, array('h1', 'h2', 'h3', 'h4'), true) ? $level : 'h2';
        $position = in_array($position, array('before', 'after', 'append'), true) ? $position : 'append';
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Bài viết không tồn tại.');
        }

        // TẠO TRỌN CỤM: Heading + Đoạn văn nội dung hoàn chỉnh (không chèn heading rỗng)
        $generator = new Kira_SA_Outline_Generator();

        $content_html = '';
        if (!empty($focus_keyword)) {
            $intro = $generator->generate_section_intro($focus_keyword, $heading, array());
            if (!is_wp_error($intro)) {
                $content_html = $intro;
            }
        }
        // Fallback nếu AI lỗi / thiếu focus keyword → vẫn chèn text mô tả tối thiểu thay vì heading rỗng
        if (empty($content_html)) {
            $content_html = '<p>' . esc_html($heading) . ' — nội dung mục này đang được bổ sung.</p>';
        }

        $heading_html = '<' . $level . '>' . esc_html($heading) . '</' . $level . '>';
        $insert_block = "\n\n" . $heading_html . "\n\n" . $content_html . "\n\n";

        $content = $post->post_content;

        // Nếu có anchor → chèn TRƯỚC/Sau anchor (vị trí chỉ định), giữ NGUYÊN mọi nội dung xung quanh
        if (!empty($anchor) && $position !== 'append' && mb_strpos($content, $anchor) !== false) {
            $anchor_pos = mb_strpos($content, $anchor);
            $anchor_end = $anchor_pos + mb_strlen($anchor);

            if ($position === 'before') {
                // Chèn TRƯỚC heading anchor: bắt đầu từ vị trí bắt đầu thẻ heading chứa anchor
                $tag_start = $this->find_heading_tag_start($content, $anchor_pos);
                $insert_at = ($tag_start !== false) ? $tag_start : $anchor_pos;
                $new_content = mb_substr($content, 0, $insert_at) . $insert_block . mb_substr($content, $insert_at);
            } else {
                // Chèn SAU heading anchor: sau thẻ đóng </h2>... </h4>
                // Dùng level của anchor nếu tìm thấy thẻ đóng tương ứng, nếu không thử từng h1..h4
                $insert_at = $anchor_end;
                $found = false;
                foreach (array('h1', 'h2', 'h3', 'h4') as $try_level) {
                    $heading_end = mb_strpos($content, '</' . $try_level . '>', $anchor_end);
                    if ($heading_end !== false) {
                        $insert_at = $heading_end + mb_strlen('</' . $try_level . '>');
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $insert_at = $anchor_end;
                }
                $new_content = mb_substr($content, 0, $insert_at) . $insert_block . mb_substr($content, $insert_at);
            }
        } else {
            // Không có anchor / vị trí append → chèn cuối bài, giữ nguyên toàn bộ nội dung
            $new_content = $content . $insert_block;
        }

        $updated = wp_update_post(array(
            'ID'           => $post_id,
            'post_content' => $new_content,
        ));

        if (is_wp_error($updated) || !$updated) {
            wp_send_json_error('Không thể cập nhật bài viết.');
        }

        wp_send_json_success(array(
            'heading_html' => $heading_html,
            'content_html' => $content_html,
            'anchor'       => $anchor,
            'position'     => $position,
            'updated'      => true,
        ));
    }

    public function ajax_save_outline()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $outline = isset($_POST['outline']) ? $this->sanitize_outline_data($_POST['outline']) : array();
        $keywords = isset($_POST['keywords']) ? $this->sanitize_competitor_keywords($_POST['keywords']) : array();

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Bạn không có quyền chỉnh sửa bài viết này.');
        }

        update_post_meta($post_id, self::META_OUTLINE, $outline);
        update_post_meta($post_id, self::META_KEYWORDS, $keywords);

        wp_send_json_success('Đã lưu dàn ý và từ khóa thành công.');
    }

    public function ajax_generate_section()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $focus_keyword = isset($_POST['focus_keyword']) ? sanitize_text_field(wp_unslash($_POST['focus_keyword'])) : '';
        $heading       = isset($_POST['heading']) ? sanitize_text_field(wp_unslash($_POST['heading'])) : '';
        $keywords      = isset($_POST['keywords']) && is_array($_POST['keywords'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['keywords']))
            : array();

        if (empty($heading)) {
            wp_send_json_error('Thiếu tiêu đề mục cần viết.');
        }
        if (empty($focus_keyword)) {
            wp_send_json_error('Thiếu từ khóa mục tiêu.');
        }

        $generator = new Kira_SA_Outline_Generator();
        $result = $generator->generate_section_intro($focus_keyword, $heading, $keywords);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'html' => wp_kses_post($result),
        ));
    }

    public function ajax_generate_keyword_sentence()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $keyword       = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
        $focus_keyword = isset($_POST['focus_keyword']) ? sanitize_text_field(wp_unslash($_POST['focus_keyword'])) : '';

        if (empty($keyword)) {
            wp_send_json_error('Thiếu từ khóa cần chèn.');
        }

        $generator = new Kira_SA_Outline_Generator();
        $result = $generator->generate_keyword_sentence($keyword, $focus_keyword);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'html' => wp_kses_post($result),
        ));
    }

    public function ajax_auto_fix()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Bạn không có quyền thực hiện tác vụ này.');
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Bạn không có quyền chỉnh sửa bài viết này.');
        }

        $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';

        $outline = get_post_meta($post_id, self::META_OUTLINE, true);
        if (!is_array($outline)) {
            wp_send_json_error('Chưa có dàn ý. Hãy phân tích đối thủ và tạo dàn ý trước.');
        }
        if (empty($outline)) {
            wp_send_json_error('Dàn ý đang trống. Hãy tạo dàn ý trước.');
        }

        $focus_keyword = get_post_meta($post_id, self::META_FOCUS_KEYWORD, true);
        if (empty($focus_keyword)) {
            wp_send_json_error('Thiếu từ khóa mục tiêu. Vui lòng tạo lại dàn ý.');
        }

        $generator = new Kira_SA_Outline_Generator();
        $audited = $generator->audit_headings($outline, $content);

        $missing = array();
        foreach ($audited as $item) {
            if ($item['status'] === 'missing' || $item['status'] === 'partial') {
                $missing[] = $item;
                if (count($missing) >= 8) break;
            }
        }

        if (empty($missing)) {
            wp_send_json_error('Tuyệt vời! Bài viết đã đầy đủ theo dàn ý — không cần bổ sung thêm.');
        }

        $html_append = '';
        $ai_sections = 0;

        foreach ($missing as $item) {
            $level = in_array($item['level'] ?? '', array('h1', 'h2', 'h3', 'h4'), true) ? $item['level'] : 'h2';
            $level_num = (int) substr($level, 1);
            $heading_text = sanitize_text_field($item['text'] ?? '');
            if (empty($heading_text)) continue;

            $html_append .= "\n<!-- wp:heading {\"level\":" . $level_num . "} -->\n" .
                '<' . $level . '>' . esc_html($heading_text) . '</' . $level . '>' . "\n" .
                "<!-- /wp:heading -->\n";

            if ($level === 'h2' && $ai_sections < 4) {
                $intro = $generator->generate_section_intro($focus_keyword, $heading_text, $item['keywords'] ?? array());
                if (!is_wp_error($intro)) {
                    $html_append .= "<!-- wp:paragraph -->\n" . wp_kses_post($intro) . "\n<!-- /wp:paragraph -->\n";
                    $ai_sections++;
                }
            }
        }

        if (empty($html_append)) {
            wp_send_json_error('Không tạo được nội dung bổ sung. Vui lòng thử lại.');
        }

        wp_send_json_success(array(
            'html_append'    => $html_append,
            'added_headings' => count($missing),
            'ai_sections'    => $ai_sections,
        ));
    }

    private function save_outline_to_post($post_id, $outline_data, $competitor, $raw_keywords)
    {
        update_post_meta($post_id, self::META_TITLE, sanitize_text_field($outline_data['title'] ?? ''));
        update_post_meta($post_id, self::META_OUTLINE, $this->sanitize_outline_data($outline_data['outline'] ?? array()));
        update_post_meta($post_id, self::META_MISSING_TOPICS, array_map('sanitize_text_field', $outline_data['missing_topics'] ?? array()));
        update_post_meta($post_id, self::META_KEYWORDS, $this->sanitize_competitor_keywords($outline_data['target_keywords'] ?? array()));
        $sanitized_competitor = array(
            'url'              => esc_url_raw($competitor['url'] ?? ''),
            'title'            => sanitize_text_field($competitor['title'] ?? ''),
            'meta_description' => sanitize_text_field($competitor['meta_description'] ?? ''),
            'headings'         => $this->sanitize_competitor_headings($competitor['headings'] ?? array()),
        );
        if (!empty($competitor['word_count'])) {
            $sanitized_competitor['word_count'] = absint($competitor['word_count']);
        }
        update_post_meta($post_id, self::META_COMPETITOR, $sanitized_competitor);
    }

    public function save_recommended_word_count($post_id, $word_count)
    {
        if ($post_id && $word_count > 0) {
            update_post_meta($post_id, self::META_WORD_COUNT, absint($word_count));
        }
    }

    private function get_post_audit_data($post_id, $draft_content)
    {
        $outline_data = get_post_meta($post_id, self::META_OUTLINE, true);
        $target_keywords = get_post_meta($post_id, self::META_KEYWORDS, true);
        $competitor = get_post_meta($post_id, self::META_COMPETITOR, true);
        $title = get_post_meta($post_id, self::META_TITLE, true);
        $missing_topics = get_post_meta($post_id, self::META_MISSING_TOPICS, true);

        if (!is_array($outline_data)) $outline_data = array();
        if (!is_array($target_keywords)) $target_keywords = array();
        if (!is_array($competitor)) $competitor = array();
        if (!is_array($missing_topics)) $missing_topics = array();

        $generator = new Kira_SA_Outline_Generator();
        $audited_headings = $generator->audit_headings($outline_data, $draft_content);
        $tracked_keywords = $generator->track_keywords($target_keywords, $draft_content);

        return array(
            'has_data'        => !empty($outline_data) || !empty($target_keywords),
            'title'           => $title,
            'outline'         => $outline_data,
            'outline_status'  => $audited_headings,
            'keywords'        => $target_keywords,
            'keyword_status'  => $tracked_keywords,
            'missing_topics'  => $missing_topics,
            'competitor'      => $competitor,
            'focus_keyword'   => get_post_meta($post_id, self::META_FOCUS_KEYWORD, true),
            'recommended_word_count' => (int) get_post_meta($post_id, self::META_WORD_COUNT, true),
        );
    }

    private function sanitize_competitor_data($data)
    {
        if (!is_array($data)) return array();
        return array(
            'url'              => isset($data['url']) ? esc_url_raw($data['url']) : '',
            'title'            => isset($data['title']) ? sanitize_text_field($data['title']) : '',
            'meta_description' => isset($data['meta_description']) ? sanitize_text_field($data['meta_description']) : '',
            'headings'         => $this->sanitize_competitor_headings($data['headings'] ?? array()),
            'word_count'       => isset($data['word_count']) ? absint($data['word_count']) : 0,
        );
    }

    private function sanitize_competitor_headings($headings)
    {
        if (!is_array($headings)) return array();
        $clean = array();
        foreach ($headings as $h) {
            if (!is_array($h)) continue;
            $text = sanitize_text_field($h['text'] ?? '');
            if (empty($text)) continue;
            $entry = array(
                'level' => in_array($h['level'] ?? '', array('h1', 'h2', 'h3', 'h4'), true) ? $h['level'] : 'h2',
                'text'  => $text,
            );
            if (isset($h['sources'])) $entry['sources'] = max(1, (int) $h['sources']);
            $clean[] = $entry;
        }
        return $clean;
    }

    private function sanitize_competitor_keywords($data)
    {
        if (!is_array($data)) return array();
        $clean = array();
        foreach ($data as $kw) {
            if (!is_array($kw)) continue;
            $keyword = sanitize_text_field($kw['keyword'] ?? '');
            if (empty($keyword)) continue;
            $suggested_places = isset($kw['suggested_places']) && is_array($kw['suggested_places'])
                ? array_map('sanitize_text_field', $kw['suggested_places'])
                : array();
            $clean[] = array(
                'keyword'          => $keyword,
                'recommended_freq' => isset($kw['recommended_freq']) ? max(1, (int) $kw['recommended_freq']) : 1,
                'suggested_places' => $suggested_places,
                'status'           => in_array($kw['status'] ?? '', array('met', 'partial', 'missing'), true) ? $kw['status'] : 'missing',
                'count'            => isset($kw['count']) ? max(0, (int) $kw['count']) : 0,
                'tracked'          => !empty($kw['tracked']),
            );
        }
        return $clean;
    }

    /**
     * Tìm vị trí bắt đầu của thẻ heading chứa anchor text trong nội dung bài viết.
     * Dùng để chèn TRƯỚC một heading cụ thể.
     *
     * @param string $content   Nội dung bài viết.
     * @param int    $pos       Vị trí của anchor text trong content.
     * @return int|false        Vị trí bắt đầu thẻ heading, hoặc false nếu không tìm thấy.
     */
    private function find_heading_tag_start($content, $pos)
    {
        if ($pos <= 0) {
            return false;
        }
        // Tìm ngược từ vị trí anchor lên đầu content để tìm <h1, <h2, <h3, <h4
        $search_start = max(0, $pos - 200);
        $segment = mb_substr($content, $search_start, $pos - $search_start);
        foreach (array('h1>', 'h2>', 'h3>', 'h4>') as $tag) {
            $tag_start = mb_strrpos($segment, '<' . $tag);
            if ($tag_start !== false) {
                return $search_start + $tag_start;
            }
        }
        return false;
    }

    private function sanitize_outline_data($data)
    {
        if (!is_array($data)) return array();
        $clean = array();
        foreach ($data as $item) {
            if (!is_array($item)) continue;
            $text = sanitize_text_field($item['text'] ?? '');
            if (empty($text)) continue;
            $keywords = isset($item['keywords']) && is_array($item['keywords'])
                ? array_map('sanitize_text_field', $item['keywords'])
                : array();
            $clean[] = array(
                'level'    => in_array($item['level'] ?? '', array('h1', 'h2', 'h3', 'h4'), true) ? $item['level'] : 'h2',
                'text'     => $text,
                'keywords' => $keywords,
                'notes'    => sanitize_textarea_field($item['notes'] ?? ''),
            );
        }
        return $clean;
    }
}