<?php
/**
 * Plugin Name: Cyber Services - Security Assessment Hub Enterprise (Modular Edition)
 * Plugin URI: https://cyberservices.vn
 * Description: Hệ thống khảo sát và thẩm định phạm vi ATTT Enterprise (Xác thực Email OTP Session Token, Chống Brute-Force, E-NDA Pháp lý, Tự động hóa Telegram, Magic Bytes Validation & Mã hóa AES-256).
 * Version: 7.7.0
 * Author: Cyber Services Vietnam (Mr Mạnh Hùng – 0979 875 985)
 * Author URI: https://cyberservices.vn
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

// Định nghĩa các hằng số lõi
define('CYBER_HUB_VERSION', '7.7.0');
define('CYBER_HUB_PATH', plugin_dir_path(__FILE__));
define('CYBER_HUB_URL', plugin_dir_url(__FILE__));
// Use WordPress' site secret.  The legacy key is retained only so existing
// AES-CBC records can be read and migrated naturally when they are updated.
$cyber_hub_key_material = defined('AUTH_KEY') ? AUTH_KEY : wp_salt('auth');
define('CYBER_ENCRYPTION_KEY', hash('sha256', $cyber_hub_key_material, true));
define('CYBER_ENCRYPTION_LEGACY_KEY', hash('sha256', $cyber_hub_key_material));
define('CYBER_HUB_NDA_VERSION', '2026-08-22');

// 1. Nạp module bảo mật, CSDL & thông báo
require_once CYBER_HUB_PATH . 'includes/class-security.php';
require_once CYBER_HUB_PATH . 'includes/class-db.php';
require_once CYBER_HUB_PATH . 'includes/class-notifications.php';
require_once CYBER_HUB_PATH . 'includes/class-ajax-otp.php';

// 2. Nạp module Giao diện người dùng (Shortcodes & Xử lý Submit)
require_once CYBER_HUB_PATH . 'public/class-form-render.php';

// 3. Nạp module Admin
if (is_admin()) {
    require_once CYBER_HUB_PATH . 'includes/class-admin.php';
}

// Hook kích hoạt Database
register_activation_hook(__FILE__, ['Cyber_Hub_DB', 'create_tables']);
add_action('plugins_loaded', ['Cyber_Hub_DB', 'maybe_upgrade']);
