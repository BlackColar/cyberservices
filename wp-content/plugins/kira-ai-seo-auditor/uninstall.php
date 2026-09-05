<?php
/**
 * Cyber Services SEO Auditor - Uninstall Cleanup
 *
 * Tự động dọn dẹp toàn bộ dữ liệu khi plugin bị xóa khỏi WordPress:
 * - Post meta dàn ý / từ khóa / competitor...
 * - Option log hoạt động
 * - Transient scrape cache
 *
 * @package Kira_AI_SA
 */

if (!defined('ABSPATH') || !defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 1. Post meta của mọi bài viết (cả draft, trashed, bất kỳ post type)
global $wpdb;

$meta_keys = array(
    '_kira_ai_auditor_outline',
    '_kira_ai_auditor_keywords',
    '_kira_ai_auditor_competitor',
    '_kira_ai_auditor_title',
    '_kira_ai_auditor_missing_topics',
    '_kira_ai_auditor_focus_keyword',
    '_kira_ai_auditor_word_count',
);

foreach ($meta_keys as $meta_key) {
    $wpdb->delete(
        $wpdb->postmeta,
        array('meta_key' => $meta_key),
        array('%s')
    );
}

// 2. Option log hoạt động
delete_option('kira_sa_auditor_logs');

// 3. Transient scrape cache (xóa mọi transient có tiền tố kira_sa_scrape_)
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_kira_sa_scrape_%' OR option_name LIKE '_transient_timeout_kira_sa_scrape_%'"
);

// 4. Dọn một số option cấu hình nội bộ nếu còn sót (không đụng option API key của plugin Kira AI chính)
delete_option('kira_sa_auditor_version');