<?php

// 9. SHORTCODE POPUP VÀ FORM ĐẶT LỊCH REAL-TIME
// -------------------------------------------------------------
add_shortcode('dat_lich_popup', function($atts) {
    static $enqueued = false;
    if (!$enqueued) {
        wp_enqueue_style('ms-booking-style', plugins_url('assets/booking-styles.css', MS_BOOKING_PLUGIN_FILE), [], '3.3.0');
        wp_enqueue_script('ms-booking-script', plugins_url('assets/booking-script.js', MS_BOOKING_PLUGIN_FILE), [], '3.3.0', true);
        wp_localize_script('ms-booking-script', 'msBooking', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ultimate_booking_nonce'),
        ]);
        $enqueued = true;
    }

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCACHEOBJECT')) {
        define('DONOTCACHEOBJECT', true);
    }

    $args = shortcode_atts(['nut_bam' => 'Đặt lịch tư vấn trực tuyến'], $atts);
    $uid = 'bk_' . wp_generate_uuid4();
    $today = ms_booking_today_vn();
    $max_date = ms_booking_now_vn()->setTime(0, 0, 0)->modify('+' . ms_sanitize_max_days(get_option('ms_max_days', 30)) . ' days')->format('Y-m-d');
    ob_start(); ?>

    <button type="button" class="ms-booking-open-btn" data-bk-uid="<?php echo esc_attr($uid); ?>" data-bk-open="1">
        <span class="ms-booking-open-icon" aria-hidden="true">📅</span>
        <span><?php echo esc_html($args['nut_bam']); ?></span>
    </button>

    <div id="bk-overlay-<?php echo esc_attr($uid); ?>" class="bk-modal-overlay" data-bk-uid="<?php echo esc_attr($uid); ?>" aria-hidden="true">
        <div class="bk-modal-box" role="dialog" aria-modal="true" aria-labelledby="bk-title-<?php echo esc_attr($uid); ?>">
            <button type="button" class="bk-close-btn" data-bk-uid="<?php echo esc_attr($uid); ?>" data-bk-close="1" aria-label="Đóng">&times;</button>
            <div class="bk-modal-header">
                <div class="bk-modal-icon" aria-hidden="true">📅</div>
                <h3 class="bk-title" id="bk-title-<?php echo esc_attr($uid); ?>">Đặt Lịch Tư Vấn Trực Tuyến</h3>
                <p class="bk-desc">Chọn ngày để kiểm tra các khung giờ trống theo thời gian thực.</p>
            </div>

            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ultimate-booking-form">
                <input type="hidden" name="action" value="submit_ultimate_booking">
                <input type="hidden" name="bk_nonce" value="<?php echo esc_attr(wp_create_nonce('ultimate_booking_nonce')); ?>">

                <div class="bk-group">
                    <label for="bk-name-<?php echo esc_attr($uid); ?>">Họ và tên *</label>
                    <input type="text" id="bk-name-<?php echo esc_attr($uid); ?>" name="client_name" placeholder="Ví dụ: Nguyễn Văn A" required>
                </div>
                <div class="bk-group">
                    <label for="bk-email-<?php echo esc_attr($uid); ?>">Email *</label>
                    <input type="email" id="bk-email-<?php echo esc_attr($uid); ?>" name="client_email" placeholder="example@domain.com" required>
                </div>
                <div class="bk-group">
                    <label for="bk-phone-<?php echo esc_attr($uid); ?>">Số điện thoại *</label>
                    <input type="tel" id="bk-phone-<?php echo esc_attr($uid); ?>" name="client_phone" placeholder="0988xxxxxx" required>
                </div>
                <div class="bk-row">
                    <div class="bk-group">
                        <label for="bk-date-<?php echo esc_attr($uid); ?>">Ngày hẹn *</label>
                        <input type="date" id="bk-date-<?php echo esc_attr($uid); ?>" name="booking_date" class="bk-date-input" min="<?php echo esc_attr($today); ?>" max="<?php echo esc_attr($max_date); ?>" required>
                    </div>
                    <div class="bk-group">
                        <label for="bk-time-<?php echo esc_attr($uid); ?>">Khung giờ *</label>
                        <select id="bk-time-<?php echo esc_attr($uid); ?>" name="booking_time" class="bk-time-select" required disabled>
                            <option value="">-- Chọn ngày trước --</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="bk-submit-btn">
                    <span class="bk-btn-label">Xác Nhận & Thiết Lập Lịch Teams</span>
                    <span class="bk-btn-spinner" aria-hidden="true"></span>
                </button>
                <p class="bk-form-note">🔒 Thông tin của bạn được bảo mật và chỉ dùng để xác nhận lịch hẹn.</p>
            </form>
        </div>
    </div>

    <?php
    return ob_get_clean();
});
