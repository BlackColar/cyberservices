<?php

// 1. MENU QUẢN LÝ VÀ TRANG CÀI ĐẶT (SETTINGS PAGE)
// -------------------------------------------------------------
add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=booking_consult',
        'Cài Đặt Microsoft Graph API',
        'Cài Đặt Cấu Hình',
        'manage_options',
        'ms-booking-settings',
        'render_ms_booking_settings_page'
    );
});

add_action('admin_post_ms_test_graph_connection', function() {
    if (!current_user_can('manage_options') || !check_admin_referer('ms_test_graph_connection')) {
        wp_die('Không đủ quyền.');
    }
    delete_transient('ms_graph_access_token_v3');
    $ok = false;
    $token = ms_get_access_token();
    if ($token) {
        $slots = ms_get_available_slots_for_date(ms_booking_today_vn());
        $ok = !is_wp_error($slots);
        if ($ok) {
            ms_clear_graph_error();
        } else {
            ms_log_graph_error($slots->get_error_message());
        }
    } else {
        ms_log_graph_error('Không lấy được access token. Kiểm tra Tenant ID, Client ID, Client Secret.');
    }
    $url = add_query_arg('ms_graph_test', $ok ? 'ok' : 'fail', admin_url('edit.php?post_type=booking_consult&page=ms-booking-settings'));
    wp_safe_redirect($url);
    exit;
});

add_action('admin_init', function() {
    register_setting('ms_booking_settings_group', 'ms_tenant_id', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('ms_booking_settings_group', 'ms_client_id', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('ms_booking_settings_group', 'ms_client_secret', ['sanitize_callback' => 'ms_sanitize_client_secret']);
    register_setting('ms_booking_settings_group', 'ms_user_email', ['sanitize_callback' => 'sanitize_email']);
    register_setting('ms_booking_settings_group', 'ms_meeting_duration', ['default' => 45, 'sanitize_callback' => 'absint']);
    register_setting('ms_booking_settings_group', 'ms_buffer_time', ['default' => 15, 'sanitize_callback' => 'absint']);
    register_setting('ms_booking_settings_group', 'ms_lead_time', ['default' => 2, 'sanitize_callback' => 'absint']);
    register_setting('ms_booking_settings_group', 'ms_work_slots', ['default' => '08:30, 09:30, 10:30, 11:30, 13:30, 14:30, 15:30, 16:30', 'sanitize_callback' => 'sanitize_text_field']);
    register_setting('ms_booking_settings_group', 'ms_thankyou_url', ['sanitize_callback' => 'esc_url_raw']);
    register_setting('ms_booking_settings_group', 'ms_skip_weekends', ['default' => '1', 'sanitize_callback' => 'ms_sanitize_flag']);
    register_setting('ms_booking_settings_group', 'ms_max_days', ['default' => 30, 'sanitize_callback' => 'ms_sanitize_max_days']);
    register_setting('ms_booking_settings_group', 'ms_send_confirm_email', ['default' => '1', 'sanitize_callback' => 'ms_sanitize_flag']);
    register_setting('ms_booking_settings_group', 'ms_notify_admin', ['default' => '1', 'sanitize_callback' => 'ms_sanitize_flag']);
    register_setting('ms_booking_settings_group', 'ms_notify_email', ['sanitize_callback' => 'sanitize_email']);
});

function render_ms_booking_settings_page() {
    $has_secret = (string) get_option('ms_client_secret', '') !== '';
    $last_error = get_option('ms_graph_last_error');
    $test = sanitize_text_field($_GET['ms_graph_test'] ?? '');
    ?>
    <div class="wrap">
        <h2>Cấu Hình Đặt Lịch Microsoft 365 & Teams</h2>
        <?php if ($test === 'ok') : ?>
            <div class="notice notice-success is-dismissible"><p>Kết nối Microsoft Graph thành công. Đã đọc được lịch Outlook.</p></div>
        <?php elseif ($test === 'fail') : ?>
            <div class="notice notice-error is-dismissible"><p>Không kết nối được Graph. Kiểm tra Tenant/Client/Secret, email hộp thư và quyền Calendars.ReadWrite + OnlineMeetings.ReadWrite (ứng dụng).</p></div>
        <?php endif; ?>
        <?php if (is_array($last_error) && !empty($last_error['message'])) : ?>
            <div class="notice notice-warning"><p><strong>Lỗi Graph gần nhất (<?php echo esc_html($last_error['time'] ?? ''); ?>):</strong> <?php echo esc_html($last_error['message']); ?></p></div>
        <?php endif; ?>

        <p>
            <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ms_test_graph_connection'), 'ms_test_graph_connection')); ?>">Kiểm tra kết nối Graph</a>
            <span class="description"> Dùng để xác nhận Azure App đã cấp quyền và Application Access Policy (nếu tạo Teams bằng app-only).</span>
        </p>

        <form method="post" action="options.php">
            <?php settings_fields('ms_booking_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th>Directory (tenant) ID</th>
                    <td><input type="text" name="ms_tenant_id" value="<?php echo esc_attr(get_option('ms_tenant_id')); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th>Application (client) ID</th>
                    <td><input type="text" name="ms_client_id" value="<?php echo esc_attr(get_option('ms_client_id')); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th>Client Secret Value</th>
                    <td>
                        <input type="password" name="ms_client_secret" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_secret ? 'Đã lưu — để trống nếu không đổi' : ''; ?>" <?php echo $has_secret ? '' : 'required'; ?>>
                        <p class="description">Secret từ Azure App Registration (Application Permissions: Calendars.ReadWrite, OnlineMeetings.ReadWrite). Không hiển thị lại secret đã lưu.</p>
                    </td>
                </tr>
                <tr>
                    <th>Email Hộp Thư Outlook (User Principal Name)</th>
                    <td><input type="email" name="ms_user_email" value="<?php echo esc_attr(get_option('ms_user_email')); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th>Các khung giờ làm việc cố định</th>
                    <td>
                        <input type="text" name="ms_work_slots" value="<?php echo esc_attr(get_option('ms_work_slots', '08:30, 09:30, 10:30, 11:30, 13:30, 14:30, 15:30, 16:30')); ?>" class="large-text">
                        <p class="description">Phân cách các khung giờ bắt đầu bằng dấu phẩy (Ví dụ: 08:30, 09:30, 10:30, 14:00, 15:00)</p>
                    </td>
                </tr>
                <tr>
                    <th>Thời lượng 1 buổi họp (Phút)</th>
                    <td><input type="number" name="ms_meeting_duration" value="<?php echo esc_attr(get_option('ms_meeting_duration', 45)); ?>" class="small-text" min="15" step="5"></td>
                </tr>
                <tr>
                    <th>Thời gian đệm nghỉ giữa các ca - Buffer (Phút)</th>
                    <td><input type="number" name="ms_buffer_time" value="<?php echo esc_attr(get_option('ms_buffer_time', 15)); ?>" class="small-text" min="0" step="5"></td>
                </tr>
                <tr>
                    <th>Chặn đặt trước sát giờ - Lead Time (Tiếng)</th>
                    <td><input type="number" name="ms_lead_time" value="<?php echo esc_attr(get_option('ms_lead_time', 2)); ?>" class="small-text" min="0"></td>
                </tr>
                <tr>
                    <th>Đặt trước tối đa (Ngày)</th>
                    <td><input type="number" name="ms_max_days" value="<?php echo esc_attr(get_option('ms_max_days', 30)); ?>" class="small-text" min="1" max="365"></td>
                </tr>
                <tr>
                    <th>Nghỉ cuối tuần</th>
                    <td>
                        <input type="hidden" name="ms_skip_weekends" value="0">
                        <label><input type="checkbox" name="ms_skip_weekends" value="1" <?php checked(ms_option_on('ms_skip_weekends', '1')); ?>> Không nhận lịch Thứ 7 và Chủ nhật</label>
                    </td>
                </tr>
                <tr>
                    <th>Email xác nhận khách</th>
                    <td>
                        <input type="hidden" name="ms_send_confirm_email" value="0">
                        <label><input type="checkbox" name="ms_send_confirm_email" value="1" <?php checked(ms_option_on('ms_send_confirm_email', '1')); ?>> Gửi email xác nhận ngay sau khi đặt (khuyến nghị: app-only thường không gửi lời mời Outlook)</label>
                    </td>
                </tr>
                <tr>
                    <th>Báo admin</th>
                    <td>
                        <input type="hidden" name="ms_notify_admin" value="0">
                        <label><input type="checkbox" name="ms_notify_admin" value="1" <?php checked(ms_option_on('ms_notify_admin', '1')); ?>> Gửi email cho admin khi có lịch mới hoặc khách hủy</label>
                    </td>
                </tr>
                <tr>
                    <th>Email nhận thông báo</th>
                    <td><input type="email" name="ms_notify_email" value="<?php echo esc_attr(get_option('ms_notify_email', get_option('admin_email'))); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>URL Trang Cảm Ơn</th>
                    <td><input type="url" name="ms_thankyou_url" value="<?php echo esc_attr(get_option('ms_thankyou_url')); ?>" class="regular-text" placeholder="https://domain.com/dat-lich-thanh-cong/"></td>
                </tr>
            </table>
            <?php submit_button('Lưu Cấu Hình'); ?>
        </form>
    </div>
<?php }

// -------------------------------------------------------------
// 2. KHỞI TẠO POST TYPE VÀ CÁC CỘT DỮ LIỆU
// -------------------------------------------------------------
add_action('init', function() {
    register_post_type('booking_consult', [
        'labels' => [
            'name'          => 'Lịch Hẹn Tư Vấn',
            'singular_name' => 'Lịch Hẹn',
            'menu_name'     => 'Lịch Hẹn Teams/Outlook',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => true,
        'menu_icon'    => 'dashicons-calendar-alt',
        'supports'     => ['title'],
    ]);
});

add_filter('manage_booking_consult_posts_columns', function($cols) {
    return [
        'cb'           => $cols['cb'],
        'title'        => 'Khách Hàng',
        'client_phone' => 'SĐT',
        'client_email' => 'Email',
        'booking_time' => 'Thời Gian',
        'status'       => 'Trạng Thái',
        'teams_url'    => 'Link Microsoft Teams',
    ];
});

add_action('manage_booking_consult_posts_custom_column', function($col, $post_id) {
    if ($col === 'client_phone') echo esc_html(get_post_meta($post_id, '_client_phone', true));
    if ($col === 'client_email') echo esc_html(get_post_meta($post_id, '_client_email', true));
    if ($col === 'booking_time') {
        $date = get_post_meta($post_id, '_booking_date', true);
        $time = get_post_meta($post_id, '_booking_time', true);
        echo esc_html("{$time} - {$date}");
    }
    if ($col === 'status') {
        $status = get_post_meta($post_id, '_booking_status', true) ?: 'confirmed';
        if ($status === 'cancelled') {
            echo '<span style="color:#d9534f; font-weight:bold;">Đã Hủy</span>';
        } else {
            echo '<span style="color:#28a745; font-weight:bold;">Đã Xác Nhận</span>';
        }
    }
    if ($col === 'teams_url') {
        $link = get_post_meta($post_id, '_teams_url', true);
        if ($link) {
            echo '<a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer" style="color: #0078d4; font-weight: bold;">Vào họp Teams</a>';
        } else {
            echo '<span style="color: #999;">—</span>';
        }
    }
}, 10, 2);

add_filter('manage_edit-booking_consult_sortable_columns', function($cols) {
    $cols['booking_time'] = 'booking_date';
    $cols['status'] = 'booking_status';
    return $cols;
});

add_action('pre_get_posts', function($query) {
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'booking_consult') {
        return;
    }
    $orderby = $query->get('orderby');
    if ($orderby === 'booking_date') {
        $query->set('meta_key', '_booking_date');
        $query->set('orderby', 'meta_value');
    }
    $status = sanitize_text_field($_GET['ms_status'] ?? '');
    if ($status === 'confirmed' || $status === 'cancelled') {
        $query->set('meta_query', [
            [
                'key'   => '_booking_status',
                'value' => $status,
            ],
        ]);
    }
});

add_action('restrict_manage_posts', function($post_type) {
    if ($post_type !== 'booking_consult') {
        return;
    }
    $current = sanitize_text_field($_GET['ms_status'] ?? '');
    echo '<select name="ms_status">';
    echo '<option value="">Tất cả trạng thái</option>';
    echo '<option value="confirmed" ' . selected($current, 'confirmed', false) . '>Đã xác nhận</option>';
    echo '<option value="cancelled" ' . selected($current, 'cancelled', false) . '>Đã hủy</option>';
    echo '</select>';
});

add_filter('post_row_actions', function($actions, $post) {
    if ($post->post_type !== 'booking_consult') {
        return $actions;
    }
    $status = get_post_meta($post->ID, '_booking_status', true) ?: 'confirmed';
    if ($status !== 'cancelled') {
        $url = wp_nonce_url(admin_url('admin-post.php?action=ms_admin_cancel_booking&post_id=' . $post->ID), 'ms_admin_cancel_' . $post->ID);
        $actions['ms_cancel'] = '<a href="' . esc_url($url) . '" style="color:#d9534f;" onclick="return confirm(\'Hủy lịch hẹn này trên WordPress và Outlook?\');">Hủy lịch</a>';
    }
    return $actions;
}, 10, 2);

add_action('add_meta_boxes', function() {
    add_meta_box('ms_booking_details', 'Chi tiết lịch hẹn', function($post) {
        $fields = [
            'Khách'      => get_post_meta($post->ID, '_client_name', true),
            'Email'      => get_post_meta($post->ID, '_client_email', true),
            'SĐT'        => get_post_meta($post->ID, '_client_phone', true),
            'Ngày'       => get_post_meta($post->ID, '_booking_date', true),
            'Giờ'        => get_post_meta($post->ID, '_booking_time', true),
            'Thời lượng' => ((int) get_post_meta($post->ID, '_meeting_duration', true) ?: (int) get_option('ms_meeting_duration', 45)) . ' phút',
            'Trạng thái' => (get_post_meta($post->ID, '_booking_status', true) ?: 'confirmed') === 'cancelled' ? 'Đã hủy' : 'Đã xác nhận',
            'Teams'      => get_post_meta($post->ID, '_teams_url', true),
        ];
        echo '<table class="widefat striped"><tbody>';
        foreach ($fields as $label => $value) {
            echo '<tr><th style="width:140px;">' . esc_html($label) . '</th><td>';
            if ($label === 'Teams' && $value) {
                echo '<a href="' . esc_url($value) . '" target="_blank" rel="noopener noreferrer">' . esc_html($value) . '</a>';
            } else {
                echo esc_html($value ?: '—');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }, 'booking_consult', 'normal', 'high');
});

add_action('admin_post_ms_admin_cancel_booking', function() {
    $post_id = absint($_GET['post_id'] ?? 0);
    if (!current_user_can('manage_options') || !$post_id || !check_admin_referer('ms_admin_cancel_' . $post_id)) {
        wp_die('Không đủ quyền.');
    }
    if (get_post_type($post_id) !== 'booking_consult') {
        wp_die('Lịch hẹn không hợp lệ.');
    }
    $result = ms_cancel_booking($post_id, true);
    if (is_wp_error($result)) {
        wp_die(esc_html($result->get_error_message()));
    }
    wp_safe_redirect(admin_url('edit.php?post_type=booking_consult&ms_cancelled=1'));
    exit;
});

// -------------------------------------------------------------

