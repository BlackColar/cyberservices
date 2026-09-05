<?php

// 0. HELPERS
// -------------------------------------------------------------
function ms_booking_now_vn() {
    return new DateTime('now', new DateTimeZone(MS_BOOKING_TZ));
}

function ms_booking_today_vn() {
    return ms_booking_now_vn()->format('Y-m-d');
}

/** Trả về ngày hợp lệ theo múi giờ đặt lịch, hoặc false nếu ngày không tồn tại. */
function ms_parse_booking_date($date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $tz = new DateTimeZone(MS_BOOKING_TZ);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $parsed->format('Y-m-d') !== $date) {
        return false;
    }

    return $parsed;
}

/** Kiểm tra các giới hạn ngày đã cấu hình trước khi gọi Microsoft Graph. */
function ms_validate_bookable_date($date) {
    $booking_date = ms_parse_booking_date($date);
    if (!$booking_date) {
        return new WP_Error('invalid_date', 'Ngày được chọn không hợp lệ.');
    }

    $tz = new DateTimeZone(MS_BOOKING_TZ);
    $today = new DateTimeImmutable(ms_booking_today_vn(), $tz);
    $max_days = ms_sanitize_max_days(get_option('ms_max_days', 30));
    $last_bookable_date = $today->modify('+' . $max_days . ' days');

    if ($booking_date < $today || $booking_date > $last_bookable_date) {
        return new WP_Error('date_out_of_range', 'Ngày hẹn phải nằm trong khoảng cho phép.');
    }

    if (ms_option_on('ms_skip_weekends', '1') && (int) $booking_date->format('N') >= 6) {
        return new WP_Error('weekend_unavailable', 'Hệ thống không nhận lịch vào cuối tuần.');
    }

    return $booking_date;
}

function ms_sanitize_client_secret($new) {
    $new = is_string($new) ? trim($new) : '';
    if ($new === '') {
        return get_option('ms_client_secret', '');
    }
    return sanitize_text_field($new);
}

function ms_graph_tz_to_datetimezone($windows_or_iana) {
    $name = trim((string) $windows_or_iana);
    if ($name === '' || strcasecmp($name, 'UTC') === 0 || strcasecmp($name, 'GMT') === 0) {
        return new DateTimeZone('UTC');
    }

    static $map = [
        'SE Asia Standard Time'      => 'Asia/Ho_Chi_Minh',
        'Singapore Standard Time'    => 'Asia/Singapore',
        'China Standard Time'        => 'Asia/Shanghai',
        'Tokyo Standard Time'        => 'Asia/Tokyo',
        'Korea Standard Time'        => 'Asia/Seoul',
        'India Standard Time'        => 'Asia/Kolkata',
        'GMT Standard Time'          => 'Europe/London',
        'W. Europe Standard Time'    => 'Europe/Berlin',
        'Romance Standard Time'      => 'Europe/Paris',
        'Pacific Standard Time'      => 'America/Los_Angeles',
        'Mountain Standard Time'     => 'America/Denver',
        'Central Standard Time'      => 'America/Chicago',
        'Eastern Standard Time'      => 'America/New_York',
        'UTC'                        => 'UTC',
    ];

    if (isset($map[$name])) {
        return new DateTimeZone($map[$name]);
    }

    try {
        return new DateTimeZone($name);
    } catch (Exception $e) {
        return new DateTimeZone('UTC');
    }
}

function ms_graph_datetime_to_timestamp($item_time) {
    if (empty($item_time['dateTime']) || !is_string($item_time['dateTime'])) {
        return false;
    }

    $raw = $item_time['dateTime'];
    $tz_name = $item_time['timeZone'] ?? 'UTC';

    // Graph hay trả 7 chữ số thập phân; PHP chỉ parse tới microsecond (6).
    $normalized = preg_replace('/\.\d+/', '', $raw);

    try {
        if (preg_match('/Z$/i', $raw) || preg_match('/[+-]\d{2}:\d{2}$/', $normalized)) {
            $dt = new DateTime($normalized);
        } else {
            $dt = new DateTime($normalized, ms_graph_tz_to_datetimezone($tz_name));
        }
        return $dt->getTimestamp();
    } catch (Exception $e) {
        return false;
    }
}

function ms_graph_json_encode($data) {
    return wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// -------------------------------------------------------------
// 0b. CÁC HÀM TRỢ GIÚP BỊ THIẾU (MISSING HELPERS)
// -------------------------------------------------------------
function ms_sanitize_flag($value) {
    return $value ? '1' : '0';
}

function ms_sanitize_max_days($value) {
    $v = absint($value);
    if ($v < 1) {
        $v = 1;
    }
    if ($v > 365) {
        $v = 365;
    }
    return $v;
}

function ms_option_on($option, $default = '0') {
    $val = get_option($option, $default);
    if (is_bool($val)) {
        return $val;
    }
    return (string) $val === '1' || (string) $val === 'on' || $val === true;
}

function ms_html_mail_headers() {
    return ['Content-Type: text/html; charset=UTF-8'];
}

function ms_log_graph_error($message) {
    if (is_array($message) || is_object($message)) {
        $message = wp_json_encode($message);
    }
    update_option('ms_graph_last_error', [
        'time'    => current_time('mysql'),
        'message' => is_string($message) ? $message : (string) $message,
    ]);
}

function ms_clear_graph_error() {
    delete_option('ms_graph_last_error');
}

function ms_format_vn_date($date) {
    if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }
    try {
        $dt = new DateTime($date, new DateTimeZone(MS_BOOKING_TZ));
        $days = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
        return $days[(int) $dt->format('w')] . ', ' . $dt->format('d/m/Y');
    } catch (Exception $e) {
        return $date;
    }
}

function ms_booking_lock_name($date, $time) {
    return 'msbk' . md5($date . '|' . $time);
}

function ms_booking_acquire_lock($date, $time) {
    global $wpdb;
    $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', ms_booking_lock_name($date, $time)));
    return (int) $got === 1;
}

function ms_booking_release_lock($date, $time) {
    global $wpdb;
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', ms_booking_lock_name($date, $time)));
}

function ms_store_event_id($event_id) {
    $event_id = is_string($event_id) ? str_replace("\0", '', $event_id) : '';
    return trim($event_id);
}

function ms_delete_graph_event($event_id) {
    $event_id = ms_store_event_id($event_id);
    $token = ms_get_access_token();
    $user_email = get_option('ms_user_email');
    if (!$token || !$user_email || $event_id === '') {
        return false;
    }

    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($user_email) . '/events/' . rawurlencode($event_id);
    $res = wp_remote_request($url, [
        'method'  => 'DELETE',
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 15,
    ]);

    if (is_wp_error($res)) {
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    return $code === 204 || $code === 200 || $code === 404;
}

function ms_booking_notice_html($title, $body, $ok = false) {
    $color = $ok ? '#28a745' : '#d9534f';
    $soft  = $ok ? 'rgba(40,167,69,.12)' : 'rgba(217,83,79,.12)';
    $icon  = $ok ? '✓' : '!';
    return "
    <style>
        html, body { margin:0 !important; padding:0 !important; background:#f3f4f6 !important; }
        body { display:flex !important; min-height:100vh; align-items:center; justify-content:center; }
        .ms-notice-card { max-width:480px; width:100%; background:#fff; border-radius:16px; box-shadow:0 10px 40px rgba(0,0,0,.12); overflow:hidden; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; margin:20px; }
        .ms-notice-card__bar { height:6px; background:{$color}; }
        .ms-notice-card__body { padding:40px 32px; text-align:center; color:#1f1f1f; }
        .ms-notice-icon { width:64px; height:64px; border-radius:50%; background:{$soft}; color:{$color}; display:inline-flex; align-items:center; justify-content:center; font-size:32px; font-weight:700; margin-bottom:18px; }
        .ms-notice-card h2 { margin:0 0 14px; color:{$color}; font-size:22px; }
        .ms-notice-card p { margin:8px 0; line-height:1.6; }
        .ms-notice-card a { color:#0078d4; font-weight:600; text-decoration:none; }
        .ms-notice-card a:hover { text-decoration:underline; }
    </style>
    <div class='ms-notice-card'>
        <div class='ms-notice-card__bar'></div>
        <div class='ms-notice-card__body'>
            <div class='ms-notice-icon'>{$icon}</div>
            <h2>" . esc_html($title) . "</h2>
            {$body}
        </div>
    </div>";
}

function ms_cpt_busy_times_for_date($date, $duration, $tz) {
    $busy = [];
    $posts = get_posts([
        'post_type'      => 'booking_consult',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'   => '_booking_date',
                'value' => $date,
            ],
            [
                'relation' => 'OR',
                [
                    'key'   => '_booking_status',
                    'value' => 'confirmed',
                ],
                [
                    'key'     => '_booking_status',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ],
        'no_found_rows'  => true,
    ]);

    foreach ($posts as $post_id) {
        $time = get_post_meta($post_id, '_booking_time', true);
        if (!is_string($time) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            continue;
        }
        $item_duration = (int) get_post_meta($post_id, '_meeting_duration', true);
        if ($item_duration < 15) {
            $item_duration = $duration;
        }
        try {
            $start = (new DateTime("{$date} {$time}:00", $tz))->getTimestamp();
        } catch (Exception $e) {
            continue;
        }
        $busy[] = [
            'start' => $start,
            'end'   => $start + ($item_duration * 60),
        ];
    }

    return $busy;
}

function ms_slot_overlaps_busy($slot_start, $slot_end, $busy_times, $buffer_seconds) {
    foreach ($busy_times as $busy) {
        $busy_start = $busy['start'] - $buffer_seconds;
        $busy_end   = $busy['end'] + $buffer_seconds;
        if ($slot_start < $busy_end && $slot_end > $busy_start) {
            return true;
        }
    }
    return false;
}

function ms_create_graph_event($user_email, $token, $payload) {
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($user_email) . '/events';
    $res = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => ms_graph_json_encode($payload),
        'timeout' => 20,
    ]);

    if (is_wp_error($res)) {
        return new WP_Error('graph_http', $res->get_error_message());
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if ($code < 200 || $code >= 300 || empty($data['id'])) {
        $msg = $data['error']['message'] ?? 'Microsoft Graph không tạo được cuộc họp.';
        $msg = is_string($msg) ? $msg : 'Microsoft Graph không tạo được cuộc họp.';
        ms_log_graph_error($msg);
        return new WP_Error('graph_create', $msg);
    }

    return [
        'id'        => ms_store_event_id($data['id']),
        'teams_url' => esc_url_raw($data['onlineMeeting']['joinUrl'] ?? ''),
    ];
}

function ms_cancel_booking($post_id, $notify_client = true) {
    $post_id = (int) $post_id;
    $status = get_post_meta($post_id, '_booking_status', true) ?: 'confirmed';
    if ($status === 'cancelled') {
        return true;
    }

    $event_id = ms_store_event_id((string) get_post_meta($post_id, '_ms_event_id', true));
    if ($event_id !== '' && !ms_delete_graph_event($event_id)) {
        $message = 'Không thể hủy cuộc họp trên Microsoft 365. Lịch hẹn chưa được thay đổi.';
        ms_log_graph_error($message);
        return new WP_Error('graph_delete', $message);
    }

    update_post_meta($post_id, '_booking_status', 'cancelled');

    $next_cron = wp_next_scheduled('ms_booking_send_reminder', [$post_id]);
    if ($next_cron) {
        wp_unschedule_event($next_cron, 'ms_booking_send_reminder', [$post_id]);
    }

    if ($notify_client) {
        ms_send_booking_email($post_id, 'cancelled');
    }

    return true;
}

function ms_find_booking_by_cancel_token($token) {
    $posts = get_posts([
        'post_type'   => 'booking_consult',
        'meta_key'    => '_cancel_token',
        'meta_value'  => $token,
        'post_status' => 'publish',
        'numberposts' => 1,
    ]);
    return $posts[0] ?? null;
}

function ms_google_calendar_url($name, $date, $time, $duration, $teams_url) {
    try {
        $start = new DateTime("{$date} {$time}:00", new DateTimeZone(MS_BOOKING_TZ));
        $end = (clone $start)->modify("+{$duration} minutes");
        $dates = $start->format('Ymd\THis') . '/' . $end->format('Ymd\THis');
    } catch (Exception $e) {
        return '';
    }

    return add_query_arg([
        'action'   => 'TEMPLATE',
        'text'     => 'Tư vấn trực tuyến: ' . $name,
        'dates'    => $dates,
        'ctz'      => MS_BOOKING_TZ,
        'details'  => $teams_url ? ('Link Teams: ' . $teams_url) : 'Tư vấn trực tuyến',
        'location' => 'Microsoft Teams',
    ], 'https://calendar.google.com/calendar/render');
}

function ms_send_booking_email($post_id, $type = 'confirm') {
    $email = get_post_meta($post_id, '_client_email', true);
    $name  = get_post_meta($post_id, '_client_name', true);
    $time  = get_post_meta($post_id, '_booking_time', true);
    $date  = get_post_meta($post_id, '_booking_date', true);
    $phone = get_post_meta($post_id, '_client_phone', true);
    $teams_url = get_post_meta($post_id, '_teams_url', true);
    $duration = (int) get_post_meta($post_id, '_meeting_duration', true) ?: (int) get_option('ms_meeting_duration', 45);
    $cancel_token = get_post_meta($post_id, '_cancel_token', true);
    $cancel_url = $cancel_token ? add_query_arg(['action_booking' => 'cancel', 'token' => $cancel_token], home_url('/')) : '';
    $safe_name = esc_html($name);
    $safe_time = esc_html($time);
    $safe_date = esc_html(ms_format_vn_date($date));
    $gcal = ($teams_url || $date) ? ms_google_calendar_url($name, $date, $time, $duration, $teams_url) : '';

    if ($type === 'confirm' && $email && ms_option_on('ms_send_confirm_email', '1')) {
        $teams_btn = $teams_url
            ? "<p><a href='" . esc_url($teams_url) . "' style='display:inline-block;padding:12px 20px;background:#0078d4;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold;'>Tham gia Microsoft Teams</a></p>"
            : '';
        $gcal_btn = $gcal
            ? "<p><a href='" . esc_url($gcal) . "'>Thêm vào Google Calendar</a></p>"
            : '';
        $cancel_btn = $cancel_url
            ? "<p style='font-size:13px;color:#666;'>Cần hủy? <a href='" . esc_url($cancel_url) . "' style='color:#a80000;'>Bấm vào đây</a>.</p>"
            : '';
        $message = "<div style='font-family:Arial,sans-serif;padding:20px;line-height:1.6;'>
            <h3>Xin chào {$safe_name},</h3>
            <p>Lịch tư vấn trực tuyến của bạn đã được xác nhận.</p>
            <ul>
                <li><strong>Thời gian:</strong> {$safe_time} — {$safe_date}</li>
                <li><strong>Thời lượng:</strong> {$duration} phút</li>
                <li><strong>SĐT:</strong> " . esc_html($phone) . "</li>
            </ul>
            {$teams_btn}{$gcal_btn}{$cancel_btn}
        </div>";
        wp_mail($email, '[Xác nhận] Lịch tư vấn trực tuyến ' . $safe_time . ' ' . $safe_date, $message, ms_html_mail_headers());
    }

    if ($type === 'reminder' && $email) {
        $teams_btn = $teams_url
            ? "<p style='margin-top:20px;'><a href='" . esc_url($teams_url) . "' style='display:inline-block;padding:12px 20px;background:#0078d4;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold;'>Tham gia họp Teams</a></p>"
            : '';
        $message = "<div style='font-family:Arial,sans-serif;padding:20px;line-height:1.6;'>
            <h3>Chào {$safe_name},</h3>
            <p>Buổi tư vấn trực tuyến của bạn sẽ bắt đầu vào lúc <strong>{$safe_time}</strong> ngày <strong>{$safe_date}</strong>.</p>
            {$teams_btn}
            <p style='color:#666;font-size:13px;margin-top:25px;'>Nếu cần hỗ trợ kỹ thuật trước cuộc họp, vui lòng phản hồi lại email này.</p>
        </div>";
        wp_mail($email, '[Nhắc nhở] Buổi tư vấn trực tuyến sẽ bắt đầu sau 1 giờ', $message, ms_html_mail_headers());
    }

    if ($type === 'cancelled' && $email) {
        $message = "<div style='font-family:Arial,sans-serif;padding:20px;line-height:1.6;'>
            <h3>Chào {$safe_name},</h3>
            <p>Lịch tư vấn lúc <strong>{$safe_time}</strong> ngày <strong>{$safe_date}</strong> đã được hủy.</p>
            <p>Bạn có thể đặt lại khung giờ khác trên website.</p>
        </div>";
        wp_mail($email, '[Đã hủy] Lịch tư vấn ' . $safe_time . ' ' . $safe_date, $message, ms_html_mail_headers());
    }

    if (in_array($type, ['confirm', 'cancelled'], true) && ms_option_on('ms_notify_admin', '1')) {
        $admin_to = sanitize_email(get_option('ms_notify_email', get_option('admin_email')));
        if ($admin_to) {
            $label = $type === 'confirm' ? 'Lịch mới' : 'Lịch đã hủy';
            $message = "<div style='font-family:Arial,sans-serif;padding:20px;line-height:1.6;'>
                <p><strong>{$label}</strong></p>
                <ul>
                    <li>Khách: " . esc_html($name) . "</li>
                    <li>Email: " . esc_html($email) . "</li>
                    <li>SĐT: " . esc_html($phone) . "</li>
                    <li>Thời gian: {$safe_time} — {$safe_date}</li>
                </ul>
            </div>";
            wp_mail($admin_to, "[{$label}] {$name} — {$safe_time} {$safe_date}", $message, ms_html_mail_headers());
        }
    }
}

// -------------------------------------------------------------

