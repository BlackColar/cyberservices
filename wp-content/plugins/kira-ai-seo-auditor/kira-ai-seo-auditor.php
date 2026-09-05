<?php
/**
 * Plugin Name: Cyber Services SEO Auditor
 * Plugin URI: https://kiraai.vn/
 * Description: Công cụ SEO Auditor chuyên nghiệp: phân tích đối thủ, tạo dàn ý AI, heading checklist, keyword tracker, SEO score, dashboard & tự động bổ sung bài viết.
 * Version: 1.0.0
 * Author: Kira AI
 * Author URI: https://kiraai.vn/
 * License: GPLv2 or later
 * Text Domain: kira-ai-seo-auditor
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KIRA_AI_SA_VERSION', '1.0.0');
define('KIRA_AI_SA_DIR', plugin_dir_path(__FILE__));
define('KIRA_AI_SA_URL', plugin_dir_url(__FILE__));

// Autoload
require_once KIRA_AI_SA_DIR . 'includes/class-kira-helper.php';
require_once KIRA_AI_SA_DIR . 'includes/class-kira-api-client.php';
require_once KIRA_AI_SA_DIR . 'includes/class-kira-scraper.php';
require_once KIRA_AI_SA_DIR . 'includes/class-kira-keyword-extractor.php';
require_once KIRA_AI_SA_DIR . 'includes/class-kira-outline-generator.php';
require_once KIRA_AI_SA_DIR . 'includes/class-kira-auditor.php';
require_once KIRA_AI_SA_DIR . 'includes/class-kira-dashboard.php';

function kira_sa_auditor_init()
{
    Kira_SA_Dashboard::get_instance();
    return Kira_SA_Auditor::get_instance();
}
add_action('init', 'kira_sa_auditor_init');