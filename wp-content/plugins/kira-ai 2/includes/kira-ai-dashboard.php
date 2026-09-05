<?php
/**
 * Dashboard (Tổng quan) - Cyber Services Content
 *
 * Cung cấp các hàm helper để render thống kê cho tab "Tổng quan".
 *
 * @package Cyber_Services_Content
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lấy toàn bộ số liệu thống kê cho Dashboard.
 *
 * @return array
 */
function kira_ai_get_dashboard_stats()
{
    $stats = array();

    // 1. Tổng bài đã tạo (có meta _kira_ai_generated)
    $generated_ids = get_posts(array(
        'post_type'      => 'any',
        'post_status'    => 'any',
        'meta_key'       => '_kira_ai_generated',
        'meta_value'     => '1',
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'nopaging'       => true,
    ));
    $stats['total_generated'] = count($generated_ids);

    // 2. Bài chờ duyệt (pending) do AI tạo
    $pending_ids = get_posts(array(
        'post_type'      => 'any',
        'post_status'    => 'pending',
        'meta_key'       => '_kira_ai_generated',
        'meta_value'     => '1',
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'nopaging'       => true,
    ));
    $stats['total_pending'] = count($pending_ids);

    // 3. Bài đã xuất bản (publish) do AI tạo
    $published_ids = get_posts(array(
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'meta_key'       => '_kira_ai_generated',
        'meta_value'     => '1',
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'nopaging'       => true,
    ));
    $stats['total_published'] = count($published_ids);

    // 4. Tổng ảnh đã sinh (attachment có meta _kira_ai_generated)
    $image_ids = get_posts(array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image/webp,image/png,image/jpeg',
        'meta_query'     => array(
            array(
                'key'     => '_kira_ai_generated',
                'compare' => 'EXISTS',
            ),
        ),
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'nopaging'       => true,
    ));
    $stats['total_images'] = count($image_ids);

    // 5. Lượt gọi API 7 ngày gần nhất
    $logs = get_option('kira_ai_api_logs', array());
    if (!is_array($logs)) {
        $logs = array();
    }

    $seven_days_ago = current_time('timestamp') - 7 * DAY_IN_SECONDS;
    $api_calls_7d = 0;
    $api_success_7d = 0;
    $api_error_7d = 0;
    $daily_counts = array();
    $daily_success = array();
    $daily_error = array();

    for ($i = 6; $i >= 0; $i--) {
        $date_key = date('Y-m-d', current_time('timestamp') - $i * DAY_IN_SECONDS);
        $daily_counts[$date_key] = 0;
        $daily_success[$date_key] = 0;
        $daily_error[$date_key] = 0;
    }

    foreach ($logs as $log) {
        if (!isset($log['time'])) {
            continue;
        }
        $log_ts = strtotime($log['time']);
        if ($log_ts >= $seven_days_ago) {
            $api_calls_7d++;
            $log_date = date('Y-m-d', $log_ts);
            if (isset($daily_counts[$log_date])) {
                $daily_counts[$log_date]++;
            }
            if (isset($log['status']) && $log['status'] === 'success') {
                $api_success_7d++;
                if (isset($daily_success[$log_date])) {
                    $daily_success[$log_date]++;
                }
            } else {
                $api_error_7d++;
                if (isset($daily_error[$log_date])) {
                    $daily_error[$log_date]++;
                }
            }
        }
    }
    $stats['api_calls_7d'] = $api_calls_7d;
    $stats['api_success_7d'] = $api_success_7d;
    $stats['api_error_7d'] = $api_error_7d;
    $stats['daily_counts'] = array_values($daily_counts);
    $stats['daily_labels'] = array_keys($daily_counts);
    $stats['daily_success'] = array_values($daily_success);
    $stats['daily_error'] = array_values($daily_error);


    // 7. Top từ khóa gần nhất (từ logs)
    $top_keywords = array();
    foreach ($logs as $log) {
        if (empty($log['keyword'])) {
            continue;
        }
        $kw = $log['keyword'];
        if (!isset($top_keywords[$kw])) {
            $top_keywords[$kw] = array(
                'keyword'     => $kw,
                'count'       => 0,
                'success'     => 0,
                'error'       => 0,
                'last_time'   => '',
                'last_status' => '',
            );
        }
        $top_keywords[$kw]['count']++;
        if (isset($log['status']) && $log['status'] === 'success') {
            $top_keywords[$kw]['success']++;
        } else {
            $top_keywords[$kw]['error']++;
        }
        $top_keywords[$kw]['last_time'] = isset($log['time']) ? $log['time'] : '';
        $top_keywords[$kw]['last_status'] = isset($log['status']) ? $log['status'] : 'error';
    }
    usort($top_keywords, function ($a, $b) {
        return $b['count'] - $a['count'];
    });
    $stats['top_keywords'] = array_slice($top_keywords, 0, 10);

    // 8. Lịch sử hoạt động gần đây (10 item mới nhất từ logs)
    $stats['recent_activity'] = array_slice($logs, 0, 10);

    // 9. Trạng thái kết nối
    $api_key = get_option('kira_ai_api_key', '');
    $stats['api_key_ok'] = !empty($api_key);
    $stats['fb_ok'] = (bool) get_option('kira_ai_fb_enabled', 0)
        && !empty(get_option('kira_ai_fb_page_id', ''))
        && !empty(get_option('kira_ai_fb_access_token', ''));
    $stats['zalo_ok'] = (bool) get_option('kira_ai_zalo_enabled', 0)
        && !empty(get_option('kira_ai_zalo_token', ''))
        && !empty(get_option('kira_ai_zalo_oa_id', ''));
    $stats['telegram_ok'] = (bool) get_option('kira_ai_telegram_enabled', 0)
        && !empty(get_option('kira_ai_telegram_bot_token', ''))
        && !empty(get_option('kira_ai_telegram_chat_id', ''));

    return $stats;
}

/**
 * Render tab Dashboard (Tổng quan).
 */
function kira_ai_render_dashboard_tab()
{
    $stats = kira_ai_get_dashboard_stats();
    ?>
    <div id="kira-tab-dashboard" class="kira-tab-content-wrapper">
        <div style="max-width: 1100px; margin-top: 10px;">

            <!-- Dữ liệu JSON cho Chart.js -->
            <script type="application/json" id="kira-dashboard-data">
                <?php echo wp_json_encode(array(
                    'labels'  => $stats['daily_labels'],
                    'counts'  => $stats['daily_counts'],
                    'success' => $stats['api_success_7d'],
                    'error'   => $stats['api_error_7d'],
                )); ?>
            </script>

            <!-- 6 Stat Cards -->
            <div class="kira-dashboard-cards" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                    <div style="font-size: 13px; opacity: 0.85; margin-bottom: 8px;">📝 Tổng bài đã tạo</div>
                    <div style="font-size: 32px; font-weight: 700;"><?php echo esc_html(number_format_i18n($stats['total_generated'])); ?></div>
                </div>
                <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: 12px; padding: 20px; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                    <div style="font-size: 13px; opacity: 0.85; margin-bottom: 8px;">⏳ Bài chờ duyệt</div>
                    <div style="font-size: 32px; font-weight: 700;"><?php echo esc_html(number_format_i18n($stats['total_pending'])); ?></div>
                </div>
                <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 12px; padding: 20px; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                    <div style="font-size: 13px; opacity: 0.85; margin-bottom: 8px;">✅ Bài đã xuất bản</div>
                    <div style="font-size: 32px; font-weight: 700;"><?php echo esc_html(number_format_i18n($stats['total_published'])); ?></div>
                </div>
                <div style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border-radius: 12px; padding: 20px; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                    <div style="font-size: 13px; opacity: 0.85; margin-bottom: 8px;">🖼️ Ảnh đã sinh</div>
                    <div style="font-size: 32px; font-weight: 700;"><?php echo esc_html(number_format_i18n($stats['total_images'])); ?></div>
                </div>
                <div style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); border-radius: 12px; padding: 20px; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                    <div style="font-size: 13px; opacity: 0.85; margin-bottom: 8px;">📊 Lượt gọi API (7 ngày)</div>
                    <div style="font-size: 32px; font-weight: 700;"><?php echo esc_html(number_format_i18n($stats['api_calls_7d'])); ?></div>
                </div>
                <div style="background: linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%); border-radius: 12px; padding: 20px; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                    <div style="font-size: 13px; opacity: 0.85; margin-bottom: 8px;">🤖 Model đang dùng</div>
                    <div style="font-size: 13px; font-weight: 600; word-break: break-all;">
                        <div>Text: <code style="background: rgba(255,255,255,0.25); padding: 2px 6px; border-radius: 4px; color: #fff;"><?php echo esc_html($stats['text_model']); ?></code></div>
                        <div style="margin-top: 4px;">Image: <code style="background: rgba(255,255,255,0.25); padding: 2px 6px; border-radius: 4px; color: #fff;"><?php echo esc_html($stats['image_model']); ?></code></div>
                    </div>
                </div>
            </div>

            <!-- Biểu đồ -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                <div style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                    <h3 style="margin: 0 0 16px 0; font-size: 15px; color: #1e293b;">📈 Bài tạo theo ngày (7 ngày)</h3>
                    <canvas id="kira-chart-daily" style="width: 100%; height: 200px;"></canvas>
                </div>
                <div style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                    <h3 style="margin: 0 0 16px 0; font-size: 15px; color: #1e293b;">🎯 Tỷ lệ API thành công</h3>
                    <canvas id="kira-chart-pie" style="width: 100%; height: 200px;"></canvas>
                </div>
            </div>

            <!-- Script vẽ biểu đồ Chart.js -->
            <script>
                (function () {
                    if (typeof Chart === 'undefined') {
                        return;
                    }
                    var dataEl = document.getElementById('kira-dashboard-data');
                    if (!dataEl) {
                        return;
                    }
                    var data;
                    try {
                        data = JSON.parse(dataEl.textContent);
                    } catch (e) {
                        return;
                    }
                    var labels = data.labels || [];
                    var counts = data.counts || [];
                    var success = parseInt(data.success, 10) || 0;
                    var error = parseInt(data.error, 10) || 0;

                    var dailyCtx = document.getElementById('kira-chart-daily');
                    if (dailyCtx) {
                        new Chart(dailyCtx.getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels: labels.map(function (l) {
                                    return l.split('-').slice(1).join('/');
                                }),
                                datasets: [{
                                    label: 'Bài tạo',
                                    data: counts,
                                    backgroundColor: 'rgba(234, 88, 12, 0.75)',
                                    borderColor: '#ea580c',
                                    borderWidth: 1,
                                    borderRadius: 4
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: false }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: { precision: 0 }
                                    }
                                }
                            }
                        });
                    }

                    var pieCtx = document.getElementById('kira-chart-pie');
                    if (pieCtx) {
                        new Chart(pieCtx.getContext('2d'), {
                            type: 'doughnut',
                            data: {
                                labels: ['Thành công', 'Lỗi'],
                                datasets: [{
                                    data: [success, error],
                                    backgroundColor: ['#22c55e', '#ef4444'],
                                    borderWidth: 0
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                        labels: { font: { size: 12 } }
                                    }
                                }
                            }
                        });
                    }
                })();
            </script>

            <!-- Top Keywords + Recent Activity -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                <!-- Top Keywords -->
                <div style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                    <h3 style="margin: 0 0 14px 0; font-size: 15px; color: #1e293b;">🔑 Top từ khóa gần nhất</h3>
                    <?php if (empty($stats['top_keywords'])): ?>
                        <p style="color: #94a3b8; font-size: 13px;">Chưa có dữ liệu.</p>
                    <?php else: ?>
                        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                            <thead>
                                <tr style="border-bottom: 2px solid #f1f5f9;">
                                    <th style="text-align: left; padding: 6px 8px; color: #64748b;">Từ khóa</th>
                                    <th style="text-align: center; padding: 6px 8px; color: #64748b;">Lượt</th>
                                    <th style="text-align: center; padding: 6px 8px; color: #64748b;">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['top_keywords'] as $kw): ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 8px; font-weight: 500; color: #1e293b;">
                                            <?php echo esc_html(mb_substr($kw['keyword'], 0, 40)) . (mb_strlen($kw['keyword']) > 40 ? '...' : ''); ?>
                                        </td>
                                        <td style="text-align: center; padding: 8px; color: #475569;"><?php echo esc_html($kw['count']); ?></td>
                                        <td style="text-align: center; padding: 8px;">
                                            <?php if ($kw['last_status'] === 'success'): ?>
                                                <span style="background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 600;">✅ Thành công</span>
                                            <?php else: ?>
                                                <span style="background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 600;">❌ Lỗi</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>


                <!-- Recent Activity -->
                <div style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                    <h3 style="margin: 0 0 14px 0; font-size: 15px; color: #1e293b;">🕐 Lịch sử hoạt động gần đây</h3>
                    <?php if (empty($stats['recent_activity'])): ?>
                        <p style="color: #94a3b8; font-size: 13px;">Chưa có hoạt động nào.</p>
                    <?php else: ?>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($stats['recent_activity'] as $act): ?>
                                <div style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                                    <div style="flex-shrink: 0; margin-top: 2px;">
                                        <?php if (isset($act['status']) && $act['status'] === 'success'): ?>
                                            <span style="color: #22c55e;">✅</span>
                                        <?php else: ?>
                                            <span style="color: #ef4444;">❌</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-weight: 500; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            <?php echo esc_html(isset($act['keyword']) ? mb_substr($act['keyword'], 0, 50) : '(không có từ khóa)'); ?>
                                        </div>
                                        <div style="color: #94a3b8; font-size: 11px; margin-top: 2px;">
                                            <?php echo esc_html(isset($act['time']) ? $act['time'] : ''); ?>
                                            <?php if (!empty($act['model'])): ?>
                                                · <code style="background: #f1f5f9; padding: 1px 4px; border-radius: 3px; font-size: 10px;"><?php echo esc_html($act['model']); ?></code>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>


            <!-- Connection Status -->
            <div style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 24px;">
                <h3 style="margin: 0 0 14px 0; font-size: 15px; color: #1e293b;">🔌 Trạng thái kết nối</h3>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 10px; padding: 12px; border-radius: 8px; <?php echo $stats['api_key_ok'] ? 'background: #f0fdf4; border: 1px solid #bbf7d0;' : 'background: #fef2f2; border: 1px solid #fca5a5;'; ?>">
                        <span style="font-size: 24px;">🔑</span>
                        <div>
                            <div style="font-weight: 600; font-size: 13px; color: #1e293b;">API Key</div>
                            <div style="font-size: 12px; <?php echo $stats['api_key_ok'] ? 'color: #16a34a;' : 'color: #dc2626;'; ?>">
                                <?php echo $stats['api_key_ok'] ? '✅ Đã cấu hình' : '❌ Chưa cấu hình'; ?>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; padding: 12px; border-radius: 8px; <?php echo $stats['fb_ok'] ? 'background: #f0fdf4; border: 1px solid #bbf7d0;' : 'background: #fef2f2; border: 1px solid #fca5a5;'; ?>">
                        <span style="font-size: 24px;">📘</span>
                        <div>
                            <div style="font-weight: 600; font-size: 13px; color: #1e293b;">Facebook</div>
                            <div style="font-size: 12px; <?php echo $stats['fb_ok'] ? 'color: #16a34a;' : 'color: #dc2626;'; ?>">
                                <?php echo $stats['fb_ok'] ? '✅ Đã kết nối' : '❌ Chưa kết nối'; ?>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; padding: 12px; border-radius: 8px; <?php echo $stats['zalo_ok'] ? 'background: #f0fdf4; border: 1px solid #bbf7d0;' : 'background: #fef2f2; border: 1px solid #fca5a5;'; ?>">
                        <span style="font-size: 24px;">💬</span>
                        <div>
                            <div style="font-weight: 600; font-size: 13px; color: #1e293b;">Zalo OA</div>
                            <div style="font-size: 12px; <?php echo $stats['zalo_ok'] ? 'color: #16a34a;' : 'color: #dc2626;'; ?>">
                                <?php echo $stats['zalo_ok'] ? '✅ Đã kết nối' : '❌ Chưa kết nối'; ?>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; padding: 12px; border-radius: 8px; <?php echo $stats['telegram_ok'] ? 'background: #f0fdf4; border: 1px solid #bbf7d0;' : 'background: #fef2f2; border: 1px solid #fca5a5;'; ?>">
                        <span style="font-size: 24px;">✈️</span>
                        <div>
                            <div style="font-weight: 600; font-size: 13px; color: #1e293b;">Telegram</div>
                            <div style="font-size: 12px; <?php echo $stats['telegram_ok'] ? 'color: #16a34a;' : 'color: #dc2626;'; ?>">
                                <?php echo $stats['telegram_ok'] ? '✅ Đã kết nối' : '❌ Chưa kết nối'; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <?php
}
