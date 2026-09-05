<?php

// 3. MICROSOFT GRAPH API ENGINE
// -------------------------------------------------------------
function ms_get_access_token() {
    $token = get_transient('ms_graph_access_token_v3');
    if ($token) return $token;

    $tenant_id = get_option('ms_tenant_id');
    $client_id = get_option('ms_client_id');
    $secret    = get_option('ms_client_secret');

    if (!$tenant_id || !$client_id || !$secret) return false;

    $url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
    $response = wp_remote_post($url, [
        'body' => [
            'client_id'     => $client_id,
            'client_secret' => $secret,
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
        ],
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) return false;

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($code >= 200 && $code < 300 && !empty($data['access_token'])) {
        $expires = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        set_transient('ms_graph_access_token_v3', $data['access_token'], max(60, $expires - 300));
        return $data['access_token'];
    }

    return false;
}

// -------------------------------------------------------------
// 4. CORE: KIỂM TRA LỊCH TRỐNG (FAIL-CLOSED)
// -------------------------------------------------------------
function ms_get_available_slots_for_date($date) {
    $bookable_date = ms_validate_bookable_date($date);
    if (is_wp_error($bookable_date)) {
        return $bookable_date;
    }

    $user_email = get_option('ms_user_email');
    $duration   = (int) get_option('ms_meeting_duration', 45);
    $buffer     = (int) get_option('ms_buffer_time', 15);
    $lead_hours = (int) get_option('ms_lead_time', 2);

    $work_slots_str = get_option('ms_work_slots', '08:30, 09:30, 10:30, 11:30, 13:30, 14:30, 15:30, 16:30');
    $work_slots = array_filter(array_map('trim', explode(',', $work_slots_str)));

    $token = ms_get_access_token();
    if (!$token || !$user_email) {
        return new WP_Error('ms_auth', 'Không kết nối được Microsoft 365. Vui lòng thử lại sau.');
    }

    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($user_email) . '/calendar/getSchedule';
    $body = [
        'schedules'                => [$user_email],
        'startTime'                => ['dateTime' => "{$date}T00:00:00", 'timeZone' => MS_BOOKING_GRAPH_TZ],
        'endTime'                  => ['dateTime' => "{$date}T23:59:59", 'timeZone' => MS_BOOKING_GRAPH_TZ],
        'availabilityViewInterval' => 15,
    ];

    $res = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Prefer'        => 'outlook.timezone="' . MS_BOOKING_GRAPH_TZ . '"',
        ],
        'body'    => ms_graph_json_encode($body),
        'timeout' => 15,
    ]);

    if (is_wp_error($res)) {
        return new WP_Error('ms_schedule', 'Không kiểm tra được lịch Outlook. Vui lòng thử lại sau.');
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if ($code < 200 || $code >= 300 || !empty($data['error']) || empty($data['value'][0]) || !empty($data['value'][0]['error'])) {
        return new WP_Error('ms_schedule', 'Không kiểm tra được lịch Outlook. Vui lòng thử lại sau.');
    }

    $busy_times = [];
    if (!empty($data['value'][0]['scheduleItems']) && is_array($data['value'][0]['scheduleItems'])) {
        foreach ($data['value'][0]['scheduleItems'] as $item) {
            if (!isset($item['status']) || $item['status'] === 'free') {
                continue;
            }
            $start_ts = ms_graph_datetime_to_timestamp($item['start'] ?? []);
            $end_ts   = ms_graph_datetime_to_timestamp($item['end'] ?? []);
            if ($start_ts === false || $end_ts === false) {
                return new WP_Error('ms_schedule_tz', 'Không đọc được múi giờ lịch Outlook. Vui lòng thử lại sau.');
            }
            $busy_times[] = [
                'start' => $start_ts,
                'end'   => $end_ts,
            ];
        }
    }

    $tz = new DateTimeZone(MS_BOOKING_TZ);
    $busy_times = array_merge($busy_times, ms_cpt_busy_times_for_date($date, $duration, $tz));

    $now_ts = ms_booking_now_vn()->getTimestamp();
    $min_bookable_time = $now_ts + ($lead_hours * 3600);
    $buffer_seconds = $buffer * 60;
    $available_slots = [];

    foreach ($work_slots as $slot) {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $slot)) {
            continue;
        }

        try {
            $slot_start = (new DateTime("{$date} {$slot}:00", $tz))->getTimestamp();
        } catch (Exception $e) {
            continue;
        }
        $slot_end = $slot_start + ($duration * 60);

        if ($slot_start < $min_bookable_time) {
            continue;
        }

        if (ms_slot_overlaps_busy($slot_start, $slot_end, $busy_times, $buffer_seconds)) {
            continue;
        }

        $available_slots[] = $slot;
    }

    return $available_slots;
}

// -------------------------------------------------------------
// 5. AJAX HANDLER: TẢI KHUNG GIỜ CÒN TRỐNG (CÓ NONCE & RATE LIMIT)
// -------------------------------------------------------------
add_action('wp_ajax_get_available_slots', 'handle_get_available_slots');
add_action('wp_ajax_nopriv_get_available_slots', 'handle_get_available_slots');

function handle_get_available_slots() {
    check_ajax_referer('ultimate_booking_nonce', 'security');

    $date = sanitize_text_field($_POST['date'] ?? '');
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        wp_send_json_error('Ngày được chọn không đúng định dạng.');
    }

    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $transient_key = 'ms_slot_limit_' . md5($ip);
    $requests = (int) get_transient($transient_key);
    if ($requests > 30) {
        wp_send_json_error('Bạn đã thao tác quá nhiều lần. Vui lòng chờ 1 phút.');
    }
    set_transient($transient_key, $requests + 1, 60);

    $slots = ms_get_available_slots_for_date($date);
    if (is_wp_error($slots)) {
        wp_send_json_error($slots->get_error_message());
    }

    wp_send_json_success($slots);
}

// -------------------------------------------------------------

