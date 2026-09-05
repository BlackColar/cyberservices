<?php
/**
 * Plugin Name: Đặt Lịch Tư Vấn Ultimate Pro (Microsoft 365 & Teams Full Automation)
 * Description: Hệ thống đặt lịch Microsoft 365/Teams với kiểm tra Outlook thời gian thực.
 * Version: 3.3.0
 * Author: Custom Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MS_BOOKING_TZ', 'Asia/Ho_Chi_Minh');
define('MS_BOOKING_GRAPH_TZ', 'SE Asia Standard Time');
define('MS_BOOKING_PLUGIN_FILE', __FILE__);

$ms_booking_modules = [
    'includes/helpers.php',
    'includes/admin-settings.php',
    'includes/graph-api.php',
    'includes/booking-flow.php',
    'includes/cancellation.php',
    'includes/shortcode.php',
];

foreach ($ms_booking_modules as $ms_booking_module) {
    require_once plugin_dir_path(__FILE__) . $ms_booking_module;
}
