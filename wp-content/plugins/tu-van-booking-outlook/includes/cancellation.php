<?php

// 8. XỬ LÝ KHÁCH HỦY LỊCH HẸN
// -------------------------------------------------------------
add_action('template_redirect', function() {
    $action = sanitize_key($_REQUEST['action_booking'] ?? '');
    $token = sanitize_text_field($_REQUEST['token'] ?? '');
    if ($action === 'cancel' && $token !== '') {
        $booking = ms_find_booking_by_cancel_token($token);
        if (!$booking) {
            wp_die('Yêu cầu hủy lịch không hợp lệ.');
        }

        $post_id  = $booking->ID;
        $status   = get_post_meta($post_id, '_booking_status', true);

        if ($status === 'cancelled') {
            wp_die(ms_booking_notice_html(
                'Lịch hẹn này đã được hủy trước đó.',
                '<p><a href="' . esc_url(home_url()) . '" style="color:#0078d4;">Về trang chủ</a></p>'
            ));
        }

        // GET chỉ hiển thị bước xác nhận: tránh bot quét email hoặc preview link hủy lịch.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $form = "<p>Bạn có chắc muốn hủy lịch hẹn này không?</p>\n"
                . "<form method='post' action='" . esc_url(home_url('/')) . "'>"
                . "<input type='hidden' name='action_booking' value='cancel'>"
                . "<input type='hidden' name='token' value='" . esc_attr($token) . "'>"
                . "<input type='hidden' name='ms_cancel_nonce' value='" . esc_attr(wp_create_nonce('ms_cancel_booking_' . $post_id)) . "'>"
                . "<p><button type='submit' style='background:#d9534f;color:#fff;padding:10px 18px;border:0;border-radius:4px;font-weight:bold;cursor:pointer;'>Xác nhận hủy lịch</button></p>"
                . "</form>";
            wp_die(ms_booking_notice_html('Xác nhận hủy lịch hẹn', $form));
        }

        if (!isset($_POST['ms_cancel_nonce']) || !wp_verify_nonce($_POST['ms_cancel_nonce'], 'ms_cancel_booking_' . $post_id)) {
            wp_die('Phiên xác nhận đã hết hạn. Vui lòng mở lại liên kết hủy lịch.');
        }

        $result = ms_cancel_booking($post_id, true);
        if (is_wp_error($result)) {
            wp_die(ms_booking_notice_html('Chưa thể hủy lịch hẹn', '<p>' . esc_html($result->get_error_message()) . '</p>'));
        }

        wp_die(ms_booking_notice_html(
            'Đã Hủy Lịch Hẹn Thành Công',
            '<p>Lịch hẹn của bạn đã được giải phóng khỏi hệ thống và Calendar của tư vấn viên.</p>
             <p style="margin-top:20px;"><a href="' . esc_url(home_url()) . '" style="background:#0078d4; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;">Về trang chủ để đặt lịch mới</a></p>'
        ));
    }
});

// -------------------------------------------------------------

