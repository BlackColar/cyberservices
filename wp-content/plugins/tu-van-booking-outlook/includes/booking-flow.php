<?php

// 6. XỬ LÝ ĐẶT LỊCH (SUBMIT FORM)
// -------------------------------------------------------------
add_action('admin_post_nopriv_submit_ultimate_booking', 'process_ultimate_booking');
add_action('admin_post_submit_ultimate_booking', 'process_ultimate_booking');

function process_ultimate_booking() {
    if (!isset($_POST['bk_nonce']) || !wp_verify_nonce($_POST['bk_nonce'], 'ultimate_booking_nonce')) {
        wp_die('Lỗi bảo mật! Phiên làm việc đã hết hạn. Vui lòng thử lại.');
    }

    $name  = sanitize_text_field($_POST['client_name'] ?? '');
    $email = sanitize_email($_POST['client_email'] ?? '');
    $phone = sanitize_text_field($_POST['client_phone'] ?? '');
    $date  = sanitize_text_field($_POST['booking_date'] ?? '');
    $time  = sanitize_text_field($_POST['booking_time'] ?? '');

    if (!$name || !is_email($email) || !$phone || !$date || !$time) {
        wp_die('Vui lòng điền đầy đủ và chính xác tất cả thông tin.');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        wp_die('Ngày hoặc khung giờ không hợp lệ.');
    }

    $bookable_date = ms_validate_bookable_date($date);
    if (is_wp_error($bookable_date)) {
        wp_die($bookable_date->get_error_message());
    }

    if (!ms_booking_acquire_lock($date, $time)) {
        wp_die(ms_booking_notice_html(
            'Khung giờ đang được giữ',
            '<p>Có người khác đang đặt cùng khung giờ. Vui lòng chọn giờ khác hoặc thử lại sau vài giây.</p>
             <p><a href="javascript:history.back()" style="color:#0078d4; font-weight:bold;">← Quay lại</a></p>'
        ));
    }

    $event_id = '';

    try {
        $available_slots = ms_get_available_slots_for_date($date);
        if (is_wp_error($available_slots)) {
            wp_die(ms_booking_notice_html(
                'Không kiểm tra được lịch',
                '<p>' . esc_html($available_slots->get_error_message()) . '</p>
                 <p><a href="javascript:history.back()" style="color:#0078d4; font-weight:bold;">← Quay lại</a></p>'
            ));
        }

        if (!in_array($time, $available_slots, true)) {
            wp_die(ms_booking_notice_html(
                'Khung giờ vừa có người đặt',
                '<p>Khung giờ <strong>' . esc_html($time) . '</strong> ngày <strong>' . esc_html($date) . '</strong> vừa được khách hàng khác đặt hoặc không còn khả dụng.</p>
                 <p><a href="javascript:history.back()" style="color:#0078d4; font-weight:bold;">← Quay lại để chọn giờ khác</a></p>'
            ));
        }

        $duration   = (int) get_option('ms_meeting_duration', 45);
        $user_email = get_option('ms_user_email');
        $token      = ms_get_access_token();
        if (!$token || !$user_email) {
            wp_die(ms_booking_notice_html(
                'Không kết nối Microsoft 365',
                '<p>Hệ thống chưa tạo được cuộc họp Teams. Vui lòng thử lại sau.</p>
                 <p><a href="javascript:history.back()" style="color:#0078d4; font-weight:bold;">← Quay lại</a></p>'
            ));
        }

        $cancel_token = wp_generate_password(32, false);
        $tz = new DateTimeZone(MS_BOOKING_TZ);
        $start_dt_obj = new DateTime("{$date} {$time}:00", $tz);
        $end_dt_obj   = (clone $start_dt_obj)->modify("+{$duration} minutes");

        $start_dt_iso   = $start_dt_obj->format('Y-m-d\TH:i:s');
        $end_dt_iso     = $end_dt_obj->format('Y-m-d\TH:i:s');
        $formatted_date = $start_dt_obj->format('d/m/Y');
        $cancel_url     = add_query_arg(['action_booking' => 'cancel', 'token' => $cancel_token], home_url('/'));

        $safe_name  = esc_html($name);
        $safe_phone = esc_html($phone);
        $safe_time  = esc_html($time);

        $meeting_body = "
        <div style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Arial, sans-serif; max-width:600px; margin:auto; background:#fff; border:1px solid #edebe9; border-radius:8px; overflow:hidden;'>
            <div style='background:#0078d4; padding:20px; color:#fff;'>
                <h2 style='margin:0; font-size:20px;'>Xác Nhận Lịch Hẹn Tư Vấn Trực Tuyến</h2>
            </div>
            <div style='padding:20px;'>
                <p>Xin chào <strong>{$safe_name}</strong>,</p>
                <p>Buổi tư vấn của bạn đã được thiết lập thành công trên hệ thống:</p>
                <ul>
                    <li><strong>Thời gian:</strong> {$safe_time} - Ngày {$formatted_date}</li>
                    <li><strong>Hình thức:</strong> Trực tuyến qua Microsoft Teams</li>
                    <li><strong>Số điện thoại liên hệ:</strong> {$safe_phone}</li>
                </ul>
                <div style='margin-top:20px; padding:12px; background:#fff4ce; border-radius:4px; font-size:13px;'>
                    Nếu bạn có việc đột xuất cần đổi lịch hoặc hủy hẹn? <a href='" . esc_url($cancel_url) . "' style='color:#a80000; font-weight:bold;'>Bấm vào đây để hủy lịch hẹn</a>.
                </div>
            </div>
        </div>";

        $created = ms_create_graph_event($user_email, $token, [
            'subject' => "Tư vấn: {$name} ({$phone})",
            'body'    => ['contentType' => 'HTML', 'content' => $meeting_body],
            'start'   => ['dateTime' => $start_dt_iso, 'timeZone' => MS_BOOKING_GRAPH_TZ],
            'end'     => ['dateTime' => $end_dt_iso, 'timeZone' => MS_BOOKING_GRAPH_TZ],
            'attendees' => [
                ['emailAddress' => ['address' => $email, 'name' => $name], 'type' => 'required'],
            ],
            'isOnlineMeeting'       => true,
            'onlineMeetingProvider' => 'teamsForBusiness',
        ]);

        if (is_wp_error($created)) {
            wp_die(ms_booking_notice_html(
                'Không tạo được cuộc họp Teams',
                '<p>' . esc_html($created->get_error_message()) . '</p>
                 <p>Lịch hẹn chưa được ghi nhận. Vui lòng thử lại.</p>
                 <p><a href="javascript:history.back()" style="color:#0078d4; font-weight:bold;">← Quay lại</a></p>'
            ));
        }

        $event_id  = $created['id'];
        $teams_url = $created['teams_url'];

        $post_id = wp_insert_post([
            'post_title'  => sanitize_text_field("{$name} - {$phone}"),
            'post_type'   => 'booking_consult',
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            ms_delete_graph_event($event_id);
            wp_die(ms_booking_notice_html(
                'Không lưu được lịch hẹn',
                '<p>Cuộc họp Teams đã được hoàn tác. Vui lòng thử lại.</p>
                 <p><a href="javascript:history.back()" style="color:#0078d4; font-weight:bold;">← Quay lại</a></p>'
            ));
        }

        update_post_meta($post_id, '_client_name', $name);
        update_post_meta($post_id, '_client_email', $email);
        update_post_meta($post_id, '_client_phone', $phone);
        update_post_meta($post_id, '_booking_date', $date);
        update_post_meta($post_id, '_booking_time', $time);
        update_post_meta($post_id, '_meeting_duration', $duration);
        update_post_meta($post_id, '_teams_url', $teams_url);
        update_post_meta($post_id, '_ms_event_id', $event_id);
        update_post_meta($post_id, '_cancel_token', $cancel_token);
        update_post_meta($post_id, '_booking_status', 'confirmed');

        // App-only Graph thường không gửi lời mời Outlook, nên gửi xác nhận riêng.
        ms_send_booking_email($post_id, 'confirm');

        $reminder_time = $start_dt_obj->getTimestamp() - 3600;
        if ($reminder_time > time()) {
            wp_schedule_single_event($reminder_time, 'ms_booking_send_reminder', [$post_id]);
        }

        $thankyou_url = get_option('ms_thankyou_url');
        if ($thankyou_url) {
            $redirect = add_query_arg([
                'booking_id' => $post_id,
                'name'       => $name,
                'date'       => $date,
                'time'       => $time,
            ], $thankyou_url);
            wp_safe_redirect($redirect);
            exit;
        }

        $teams_btn = $teams_url
            ? "<p style='margin-top:15px;'><a href='" . esc_url($teams_url) . "' target='_blank' rel='noopener noreferrer' style='background:#0078d4; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;'>Link Họp Teams</a></p>"
            : '<p style="color:#666;">Lịch đã lưu trên Outlook. Link Teams sẽ có trong lời mời email nếu hệ thống gửi được.</p>';

        wp_die(ms_booking_notice_html(
            'Đặt Lịch Thành Công',
            '<p>Khách hàng: <strong>' . esc_html($name) . '</strong> | SĐT: <strong>' . esc_html($phone) . '</strong></p>
             <p>Thời gian: <strong>' . esc_html($time) . ' - ' . esc_html($formatted_date) . '</strong></p>' .
            $teams_btn .
            '<p style="margin-top:25px;"><a href="' . esc_url(home_url()) . '" style="color:#0078d4;">Về trang chủ</a></p>',
            true
        ));
    } finally {
        ms_booking_release_lock($date, $time);
    }
}

// -------------------------------------------------------------
// 7. XỬ LÝ GỬI EMAIL NHẮC LỊCH QUA WP-CRON
// -------------------------------------------------------------
add_action('ms_booking_send_reminder', function($post_id) {
    $status = get_post_meta($post_id, '_booking_status', true);
    if ($status === 'cancelled') return;

    $email     = get_post_meta($post_id, '_client_email', true);
    $name      = get_post_meta($post_id, '_client_name', true);
    $time      = get_post_meta($post_id, '_booking_time', true);
    $date      = get_post_meta($post_id, '_booking_date', true);
    $teams_url = get_post_meta($post_id, '_teams_url', true);

    if (!$email) return;

    $subject = "[Nhắc nhở] Buổi tư vấn trực tuyến sẽ bắt đầu sau 1 giờ";
    $safe_name = esc_html($name);
    $safe_time = esc_html($time);

    $formatted_date = $date;
    try {
        $formatted_date = (new DateTime($date, new DateTimeZone(MS_BOOKING_TZ)))->format('d/m/Y');
    } catch (Exception $e) {
        $formatted_date = esc_html($date);
    }
    $safe_date = esc_html($formatted_date);

    $message = "
        <div style='font-family: Arial, sans-serif; padding: 20px; line-height:1.6;'>
            <h3>Chào {$safe_name},</h3>
            <p>Buổi tư vấn trực tuyến của bạn sẽ bắt đầu vào lúc <strong>{$safe_time}</strong> ngày <strong>{$safe_date}</strong>.</p>
            " . ($teams_url ? "<p style='margin-top:20px;'><a href='" . esc_url($teams_url) . "' style='display:inline-block; padding:12px 20px; background:#0078d4; color:#fff; text-decoration:none; border-radius:4px; font-weight:bold;'>Bấm Vào Đây Để Tham Gia Họp Teams</a></p>" : "") . "
            <p style='color:#666; font-size:13px; margin-top:25px;'>Nếu cần hỗ trợ kỹ thuật trước cuộc họp, vui lòng phản hồi lại email này.</p>
        </div>
    ";
    wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
});

// -------------------------------------------------------------

