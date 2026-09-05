<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================================================================
// TABS RENDERER - OVERVIEW
// ==========================================================================
function sv_render_sec_tab_overview() {
    $captcha  = get_option( 'sv_sec_enable_recaptcha' ) === '1';
    $login    = get_option( 'sv_sec_limit_login' ) === '1';
    $key      = get_option( 'sv_sec_enable_login_key' ) === '1';
    $xmlrpc   = get_option( 'sv_sec_disable_xmlrpc' ) === '1';
    $editor   = get_option( 'sv_sec_disable_editor' ) === '1' || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );
    $headers  = get_option( 'sv_sec_headers_enabled' ) === '1';
    $honeypot = get_option( 'sv_sec_honeypot_enabled' ) === '1';
    $enum     = get_option( 'sv_sec_block_user_enum' ) === '1';
    $notify   = get_option( 'sv_sec_login_notify' ) === '1';

    $ssl_ok   = sv_is_effectively_ssl();
    $debug_ok = ! ( defined( 'WP_DEBUG' ) && WP_DEBUG );
    $php_ok   = version_compare( phpversion(), '8.0', '>=' );

    $checks = array(
        array( 'label' => __( 'SSL / HTTPS', 'sitevorx' ),             'pass' => $ssl_ok,   'link' => '' ),
        array( 'label' => __( 'WP_DEBUG tắt', 'sitevorx' ),            'pass' => $debug_ok, 'link' => '' ),
        array( 'label' => __( 'PHP ≥ 8.0', 'sitevorx' ),               'pass' => $php_ok,   'link' => '' ),
        array( 'label' => __( 'reCAPTCHA', 'sitevorx' ),               'pass' => $captcha,  'link' => 'sv-security-center&tab=config' ),
        array( 'label' => __( 'Giới hạn đăng nhập', 'sitevorx' ),      'pass' => $login,    'link' => 'sv-security-center&tab=config' ),
        array( 'label' => __( 'URL đăng nhập bí mật', 'sitevorx' ),    'pass' => $key,      'link' => 'sv-security-center&tab=config' ),
        array( 'label' => __( 'Khóa XML-RPC', 'sitevorx' ),            'pass' => $xmlrpc,   'link' => 'sv-security-center&tab=config' ),
        array( 'label' => __( 'Khóa Code Editor', 'sitevorx' ),        'pass' => $editor,   'link' => 'sv-security-center&tab=config' ),
        array( 'label' => __( 'Security Headers', 'sitevorx' ),        'pass' => $headers,  'link' => 'sv-security-center&tab=headers' ),
        array( 'label' => __( 'Honeypot đăng nhập', 'sitevorx' ),      'pass' => $honeypot, 'link' => 'sv-security-center&tab=monitor' ),
        array( 'label' => __( 'Chặn dò username', 'sitevorx' ),        'pass' => $enum,     'link' => 'sv-security-center&tab=monitor' ),
        array( 'label' => __( 'Email báo đăng nhập admin', 'sitevorx' ), 'pass' => $notify, 'link' => 'sv-security-center&tab=monitor' ),
    );
    $total      = count( $checks );
    $pass_count = 0;
    foreach ( $checks as $c ) { if ( $c['pass'] ) $pass_count++; }
    $score      = $total > 0 ? (int) round( ( $pass_count / $total ) * 100 ) : 0;
    $tone       = $score >= 80 ? 'green' : ( $score >= 50 ? 'yellow' : 'red' );
    $status_txt = $score >= 80 ? __( 'Tuyệt vời', 'sitevorx' ) : ( $score >= 50 ? __( 'Cần tối ưu', 'sitevorx' ) : __( 'Cảnh báo', 'sitevorx' ) );

    $lockouts = function_exists( 'sv_login_get_current_lockouts' ) ? sv_login_get_current_lockouts() : array();
    $logs = array();
    if ( function_exists( 'sv_audit_get_entries' ) ) {
        foreach ( sv_audit_get_entries( 50 ) as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['event'] ) ) continue;
            if ( 'login_failed' !== $entry['event'] && 'login_lockout' !== $entry['event'] ) continue;
            $ctx = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
            $logs[] = array(
                'time'     => isset( $entry['ts'] ) ? (int) $entry['ts'] : 0,
                'ip'       => isset( $entry['actor_ip'] ) ? (string) $entry['actor_ip'] : '',
                'username' => isset( $ctx['username'] ) ? (string) $ctx['username'] : ( isset( $ctx['user'] ) ? (string) $ctx['user'] : '' ),
            );
            if ( count( $logs ) >= 5 ) break;
        }
    }
    ?>
    <?php
    $off_items = array_values( array_filter( $checks, function ( $c ) { return ! $c['pass']; } ) );
    $off_count = count( $off_items );
    $last_scan = get_option( 'sv_sec_last_scan', array() );
    ?>

    <div class="sv-content-box">
        <div class="sv-box-header">
            <h3><span class="dashicons dashicons-shield-alt"></span> <?php esc_html_e( 'Trạng thái bảo mật', 'sitevorx' ); ?></h3>
            <strong class="sv-color-<?php echo esc_attr( $tone ); ?>"><?php echo (int) $score; ?>/100 — <?php echo esc_html( $status_txt ); ?></strong>
        </div>
        <div class="sv-card-content">
            <div style="display:flex; align-items:center; gap:18px; flex-wrap:wrap; margin-bottom:14px;">
                <div style="flex:1; min-width:220px;">
                    <div class="sv-ov-progress" style="margin-bottom:6px;"><span class="sv-prog-<?php echo esc_attr( $tone ); ?>" style="width:<?php echo (int) $score; ?>%"></span></div>
                    <p style="margin:0; color:#64748b; font-size:13px;"><?php echo esc_html( sprintf( __( 'Đang bật %1$d/%2$d lớp. %3$d hạng mục chưa kích hoạt.', 'sitevorx' ), $pass_count, $total, $off_count ) ); ?></p>
                </div>
            </div>

            <?php if ( $off_count > 0 ) : ?>
                <div style="padding-top:10px; border-top:1px dashed #e2e8f0;">
                    <p style="margin:0 0 8px 0; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8;"><?php esc_html_e( 'Hạng mục cần xử lý', 'sitevorx' ); ?></p>
                    <div class="sv-checklist">
                        <?php foreach ( $off_items as $c ) :
                            $href = $c['link'] ? '?page=' . $c['link'] : '';
                        ?>
                            <?php if ( $href ) : ?><a href="<?php echo esc_attr( $href ); ?>" class="sv-checklist-chip" style="background:#fef2f2; color:#b91c1c; border-color:#fecaca;"><?php else : ?>
                            <span class="sv-checklist-chip" style="background:#fef2f2; color:#b91c1c; border-color:#fecaca;"><?php endif; ?>
                            <strong>○</strong> <?php echo esc_html( $c['label'] ); ?>
                            <?php echo $href ? '</a>' : '</span>'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else : ?>
                <p style="margin:0; padding-top:10px; border-top:1px dashed #e2e8f0; color:#15803d; font-weight:600;">✓ <?php esc_html_e( 'Tất cả lớp bảo mật đã được kích hoạt.', 'sitevorx' ); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="sv-content-box">
        <div class="sv-box-header"><h3><span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Hành động nhanh', 'sitevorx' ); ?></h3></div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; padding:18px 25px;">
            <a href="?page=sv-security-center&tab=scanner" style="display:flex; gap:12px; padding:14px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; text-decoration:none; transition:transform 0.15s;">
                <span class="dashicons dashicons-shield-alt" style="font-size:28px; width:28px; height:28px; color:#dc2626; flex-shrink:0;"></span>
                <div style="min-width:0;">
                    <strong style="display:block; color:#7f1d1d; font-size:14px;"><?php esc_html_e( 'Quét mã độc', 'sitevorx' ); ?></strong>
                    <span style="font-size:12px; color:#991b1b; line-height:1.4;">
                        <?php if ( ! empty( $last_scan['ts'] ) ) : ?>
                            <?php echo esc_html( sprintf( __( 'Lần cuối: %s trước', 'sitevorx' ), human_time_diff( (int) $last_scan['ts'], time() ) ) ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Chưa quét lần nào', 'sitevorx' ); ?>
                        <?php endif; ?>
                    </span>
                </div>
            </a>
            <a href="?page=sv-security-center&tab=audit" style="display:flex; gap:12px; padding:14px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; text-decoration:none;">
                <span class="dashicons dashicons-yes-alt" style="font-size:28px; width:28px; height:28px; color:#16a34a; flex-shrink:0;"></span>
                <div style="min-width:0;">
                    <strong style="display:block; color:#14532d; font-size:14px;"><?php esc_html_e( 'Kiểm tra lõi WordPress', 'sitevorx' ); ?></strong>
                    <span style="font-size:12px; color:#15803d; line-height:1.4;"><?php esc_html_e( 'Đối chiếu MD5 với wp.org', 'sitevorx' ); ?></span>
                </div>
            </a>
            <a href="?page=sv-security-center&tab=headers" style="display:flex; gap:12px; padding:14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; text-decoration:none;">
                <span class="dashicons dashicons-search" style="font-size:28px; width:28px; height:28px; color:#2563eb; flex-shrink:0;"></span>
                <div style="min-width:0;">
                    <strong style="display:block; color:#1e3a8a; font-size:14px;"><?php esc_html_e( 'Test Security Headers', 'sitevorx' ); ?></strong>
                    <span style="font-size:12px; color:#1d4ed8; line-height:1.4;"><?php esc_html_e( 'Xem header server thực tế gửi', 'sitevorx' ); ?></span>
                </div>
            </a>
            <a href="?page=sv-audit-log" style="display:flex; gap:12px; padding:14px; background:#faf5ff; border:1px solid #e9d5ff; border-radius:8px; text-decoration:none;">
                <span class="dashicons dashicons-list-view" style="font-size:28px; width:28px; height:28px; color:#7c3aed; flex-shrink:0;"></span>
                <div style="min-width:0;">
                    <strong style="display:block; color:#4c1d95; font-size:14px;"><?php esc_html_e( 'Nhật ký kiểm toán', 'sitevorx' ); ?></strong>
                    <span style="font-size:12px; color:#6d28d9; line-height:1.4;"><?php esc_html_e( '200 hành động gần nhất', 'sitevorx' ); ?></span>
                </div>
            </a>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px;">
        <div class="sv-content-box">
            <div class="sv-box-header">
                <h3><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'IP đang bị khóa', 'sitevorx' ); ?></h3>
                <?php if ( ! empty( $lockouts ) ) : ?>
                    <button type="button" class="sv-btn sv-btn-sm sv-btn-outline sv-clear-lockouts"><?php esc_html_e( 'Mở khóa tất cả', 'sitevorx' ); ?></button>
                <?php endif; ?>
            </div>
            <div class="sv-card-content">
                <?php if ( empty( $lockouts ) ) : ?>
                    <p style="color:#64748b; margin:0;"><?php esc_html_e( 'Không có IP nào đang bị khóa.', 'sitevorx' ); ?></p>
                <?php else : ?>
                    <table class="sv-table">
                        <thead><tr><th><?php esc_html_e( 'Mã khóa', 'sitevorx' ); ?></th><th><?php esc_html_e( 'Lần thử', 'sitevorx' ); ?></th><th><?php esc_html_e( 'Hết hạn', 'sitevorx' ); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ( $lockouts as $lk ) :
                                $hash    = isset( $lk['hash'] ) ? (string) $lk['hash'] : '';
                                $attempts = isset( $lk['attempts'] ) ? (int) $lk['attempts'] : 0;
                                $expires  = isset( $lk['expires_at'] ) ? (int) $lk['expires_at'] : 0;
                            ?>
                            <tr>
                                <td><code style="font-size:11px;"><?php echo esc_html( substr( $hash, 0, 12 ) ); ?>&hellip;</code></td>
                                <td><?php echo (int) $attempts; ?></td>
                                <td><?php echo $expires ? esc_html( wp_date( 'H:i d/m/Y', $expires ) ) : '&mdash;'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <div class="sv-content-box">
            <div class="sv-box-header">
                <h3><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Ghi Nhận Gần Đây', 'sitevorx' ); ?></h3>
                <a href="?page=sv-security-center&tab=audit" class="sv-btn sv-btn-sm sv-btn-outline"><?php esc_html_e( 'Xem tất cả', 'sitevorx' ); ?></a>
            </div>
            <div class="sv-card-content">
                <?php if ( empty( $logs ) ) : ?>
                    <p style="color:#64748b; margin:0;"><?php esc_html_e( 'Chưa có hoạt động đáng ngờ nào.', 'sitevorx' ); ?></p>
                <?php else : ?>
                    <table class="sv-table">
                        <tbody>
                            <?php foreach ( array_slice( $logs, 0, 5 ) as $log ) : ?>
                            <tr>
                                <td><span style="font-size:11px; color:#94a3b8;"><?php echo esc_html( wp_date( 'H:i', $log['time'] ) ); ?></span></td>
                                <td><strong style="color:#ef4444;"><?php echo esc_html( $log['ip'] ); ?></strong></td>
                                <td><?php echo esc_html( $log['username'] ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// ==========================================================================
// TABS RENDERER - CONFIG
// ==========================================================================
function sv_render_sec_tab_config() {
    sv_render_sec_config_form();
}

// ==========================================================================
// TABS RENDERER - MONITOR
// ==========================================================================
function sv_render_sec_tab_monitor() {
    sv_render_sec_monitor_form();
}

// ==========================================================================
// TABS RENDERER - AUDIT LOG
// ==========================================================================
function sv_render_sec_tab_audit() {
    $integrity_nonce = wp_create_nonce( 'sv_security_nonce' );

    // Critical files surfaced for permission management.
    $files = array(
        'wp-config.php' => array(
            'path'   => ABSPATH . 'wp-config.php',
            'safe'   => 0640,
            'label'  => __( 'wp-config.php', 'sitevorx' ),
            'why'    => __( 'Chứa DB credentials + secret salts. Lý tưởng 600 hoặc 640 để chỉ owner web đọc được, tránh user khác trên cùng server xem.', 'sitevorx' ),
        ),
        '.htaccess' => array(
            'path'   => ABSPATH . '.htaccess',
            'safe'   => 0644,
            'label'  => '.htaccess',
            'why'    => __( 'File rewrite rules của Apache. Cần readable (644) để Apache đọc nhưng không nên writable bởi web (664/666 nguy hiểm).', 'sitevorx' ),
        ),
    );
    ?>
    <div class="sv-content-box">
        <div class="sv-box-header"><span class="dashicons dashicons-yes-alt" style="color:#16a34a;"></span><h3><?php esc_html_e( 'Kiểm tra toàn vẹn lõi WordPress', 'sitevorx' ); ?></h3></div>

        <div class="sv-form-group">
            <div class="sv-form-label">
                <strong><?php esc_html_e( 'Đối chiếu tệp core với api.wordpress.org', 'sitevorx' ); ?></strong>
                <p><?php esc_html_e( 'Tải MD5 chính thức của phiên bản WordPress đang chạy từ WordPress.org, sau đó so với từng file core trên server để phát hiện file bị sửa đổi hoặc thiếu. Chỉ chạy khi bạn bấm — không tự động.', 'sitevorx' ); ?></p>
            </div>
            <div class="sv-form-input">
                <button type="button" class="button button-primary sv-run-integrity" data-nonce="<?php echo esc_attr( $integrity_nonce ); ?>"><?php esc_html_e( 'Bắt đầu kiểm tra', 'sitevorx' ); ?></button>
            </div>
        </div>

        <div id="sv-integrity-result" style="display:none; padding:18px 25px; border-top:1px solid #f0f0f1; background:#f8fafc;">
            <p style="margin:0; color:#64748b;"><?php esc_html_e( 'Đang kiểm tra... có thể mất tới 30 giây.', 'sitevorx' ); ?></p>
        </div>
    </div>

    <div class="sv-content-box">
        <div class="sv-box-header"><span class="dashicons dashicons-admin-network" style="color:#dc2626;"></span><h3><?php esc_html_e( 'Sửa quyền file quan trọng', 'sitevorx' ); ?></h3></div>
        <div style="padding:14px 25px 4px; color:#475569; font-size:13px; line-height:1.6; border-bottom:1px solid #f1f5f9;">
            <?php esc_html_e( 'Đặt lại chmod chuẩn cho các file nhạy cảm. Hostings shared thường để mặc định 644/664 — quá rộng. Bấm "Sửa về 640" để tự khoá xuống mức an toàn. Nếu Hosting không cho PHP đổi quyền (PHP-FPM khác user với file owner), sẽ hiện cảnh báo, lúc đó cần SSH/FTP đổi tay.', 'sitevorx' ); ?>
        </div>
        <table class="sv-table" style="width:100%; border-collapse:collapse; table-layout:fixed;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="padding:12px; text-align:left; width:34%;"><?php esc_html_e( 'Tên file', 'sitevorx' ); ?></th>
                    <th style="padding:12px; text-align:left; width:14%;"><?php esc_html_e( 'Quyền hiện tại', 'sitevorx' ); ?></th>
                    <th style="padding:12px; text-align:left; width:10%;"><?php esc_html_e( 'Đề xuất', 'sitevorx' ); ?></th>
                    <th style="padding:12px; text-align:left;"><?php esc_html_e( 'Mô tả & Hành động', 'sitevorx' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $files as $rel => $info ) :
                $exists = file_exists( $info['path'] );
                $cur    = $exists ? ( fileperms( $info['path'] ) & 0777 ) : 0;
                $cur_str = $exists ? sprintf( '%04o', $cur ) : '—';
                $safe_str = sprintf( '%04o', $info['safe'] );
                $is_safe = $exists && ( $cur === $info['safe'] || ( '.htaccess' === $rel && $cur <= 0644 && $cur >= 0600 ) || ( 'wp-config.php' === $rel && $cur <= 0640 && ( $cur & 0006 ) === 0 ) );
                $writable = $exists ? is_writable( $info['path'] ) : false;
            ?>
                <tr style="border-top:1px solid #f0f0f1;">
                    <td style="padding:14px 12px; vertical-align:top; word-break:break-all;">
                        <strong style="display:block; margin-bottom:4px;"><?php echo esc_html( $info['label'] ); ?></strong>
                        <code style="font-size:11px; color:#64748b; word-break:break-all; display:block; line-height:1.4;"><?php echo esc_html( $info['path'] ); ?></code>
                    </td>
                    <td style="padding:14px 12px; vertical-align:top;">
                        <?php if ( ! $exists ) : ?>
                            <span style="color:#94a3b8;"><?php esc_html_e( 'Không tồn tại', 'sitevorx' ); ?></span>
                        <?php else : ?>
                            <code style="font-size:14px; font-weight:700; color:<?php echo esc_attr( $is_safe ? '#15803d' : '#b91c1c' ); ?>; display:block;"><?php echo esc_html( $cur_str ); ?></code>
                            <span style="font-size:11px; font-weight:600; color:<?php echo esc_attr( $is_safe ? '#15803d' : '#b91c1c' ); ?>; display:block; margin-top:4px;">
                                <?php echo $is_safe ? '✓ ' . esc_html__( 'An toàn', 'sitevorx' ) : '✕ ' . esc_html__( 'Quá rộng', 'sitevorx' ); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:14px 12px; vertical-align:top;"><code style="font-size:14px; font-weight:700; color:#1f2937;"><?php echo esc_html( $safe_str ); ?></code></td>
                    <td style="padding:14px 12px; vertical-align:top; color:#475569; font-size:13px; line-height:1.5;">
                        <?php echo esc_html( $info['why'] ); ?>
                        <?php if ( $exists && ! $is_safe ) : ?>
                            <div style="margin-top:10px; display:flex; flex-wrap:wrap; align-items:center; gap:8px;">
                                <button type="button" class="button button-primary sv-fix-perms"
                                    data-nonce="<?php echo esc_attr( $integrity_nonce ); ?>"
                                    data-target="<?php echo esc_attr( $rel ); ?>"
                                    data-mode="<?php echo esc_attr( $safe_str ); ?>"
                                    style="background:#dc2626; border-color:#b91c1c;"><?php echo esc_html( sprintf( __( 'Sửa về %s', 'sitevorx' ), $safe_str ) ); ?></button>
                                <?php if ( ! $writable ) : ?>
                                    <span style="color:#a16207; font-size:11px;">⚠ <?php esc_html_e( 'PHP có thể không có quyền ghi', 'sitevorx' ); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div id="sv-perms-result" style="display:none; padding:14px 25px; border-top:1px solid #f0f0f1; background:#f8fafc;"></div>
    </div>

    <script>
    (function($){
        $(document).on('click', '.sv-run-integrity', function(){
            var $btn = $(this), nonce = $btn.data('nonce'), $out = $('#sv-integrity-result');
            $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Đang kiểm tra…', 'sitevorx' ) ); ?>');
            $out.show().html('<p style="margin:0; color:#64748b;"><?php echo esc_js( __( 'Đang tải checksum từ api.wordpress.org…', 'sitevorx' ) ); ?></p>');
            $.post(ajaxurl, { action: 'sv_integrity_check', nonce: nonce })
                .done(function(resp){
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Kiểm tra lại', 'sitevorx' ) ); ?>');
                    if (!resp || !resp.success) {
                        $out.html('<p style="margin:0; color:#dc2626;"><strong><?php echo esc_js( __( 'Lỗi:', 'sitevorx' ) ); ?></strong> ' + (resp && resp.data ? resp.data : '<?php echo esc_js( __( 'Không phản hồi', 'sitevorx' ) ); ?>') + '</p>');
                        return;
                    }
                    var d = resp.data, html = '';
                    if ((!d.modified || !d.modified.length) && (!d.missing || !d.missing.length)) {
                        html = '<p style="margin:0; color:#15803d;"><strong>✓ <?php echo esc_js( __( 'Tất cả tệp lõi khớp với phiên bản chính thức.', 'sitevorx' ) ); ?></strong></p>';
                    } else {
                        if (d.modified && d.modified.length) {
                            html += '<p style="margin:0 0 6px 0; color:#b91c1c;"><strong>✕ <?php echo esc_js( __( 'Tệp bị sửa đổi', 'sitevorx' ) ); ?> (' + d.modified.length + '):</strong></p><ul style="margin:0 0 12px 18px; font-family:monospace; font-size:12px;">';
                            d.modified.forEach(function(f){ html += '<li>' + $('<div>').text(f).html() + '</li>'; });
                            html += '</ul>';
                        }
                        if (d.missing && d.missing.length) {
                            html += '<p style="margin:0 0 6px 0; color:#a16207;"><strong>⚠ <?php echo esc_js( __( 'Tệp bị thiếu', 'sitevorx' ) ); ?> (' + d.missing.length + '):</strong></p><ul style="margin:0 0 0 18px; font-family:monospace; font-size:12px;">';
                            d.missing.forEach(function(f){ html += '<li>' + $('<div>').text(f).html() + '</li>'; });
                            html += '</ul>';
                        }
                    }
                    $out.html(html);
                })
                .fail(function(){
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Thử lại', 'sitevorx' ) ); ?>');
                    $out.html('<p style="margin:0; color:#dc2626;"><strong><?php echo esc_js( __( 'Không kết nối được WordPress.org API.', 'sitevorx' ) ); ?></strong></p>');
                });
        });

        $(document).on('click', '.sv-fix-perms', function(){
            var $btn = $(this), $out = $('#sv-perms-result'),
                target = $btn.data('target'), mode = $btn.data('mode'), nonce = $btn.data('nonce');
            if (!confirm('<?php echo esc_js( __( 'Áp dụng chmod mới cho file này?', 'sitevorx' ) ); ?>')) return;
            $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Đang sửa…', 'sitevorx' ) ); ?>');
            $.post(ajaxurl, { action: 'sv_fix_perms', nonce: nonce, target: target, mode: mode })
                .done(function(resp){
                    if (resp && resp.success) {
                        $out.show().html('<p style="margin:0; color:#15803d;"><strong>✓</strong> ' + $('<div>').text(resp.data.message || '').html() + '</p>');
                        setTimeout(function(){ window.location.reload(); }, 900);
                    } else {
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Thử lại', 'sitevorx' ) ); ?>');
                        $out.show().html('<p style="margin:0; color:#dc2626;"><strong><?php echo esc_js( __( 'Không sửa được:', 'sitevorx' ) ); ?></strong> ' + $('<div>').text((resp && resp.data) || '').html() + '</p>');
                    }
                })
                .fail(function(){
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Thử lại', 'sitevorx' ) ); ?>');
                    $out.show().html('<p style="margin:0; color:#dc2626;"><?php echo esc_js( __( 'Lỗi kết nối server.', 'sitevorx' ) ); ?></p>');
                });
        });
    })(jQuery);
    </script>
    <?php
}

// ==========================================================================
// CONFIG FORM (Tab 2) — reCAPTCHA / Limit Login / Secret URL / XML-RPC / Editor
// Runtime hooks are still owned by includes/system-optimizer.php. This form
// only writes the same option keys.
// ==========================================================================
function sv_render_sec_config_form() {
    $rc_enabled   = get_option( 'sv_sec_enable_recaptcha' ) === '1';
    $rc_version   = get_option( 'sv_sec_recaptcha_version', 'v2' );
    $rc_site      = get_option( 'sv_sec_recaptcha_site_key', '' );
    $rc_secret    = get_option( 'sv_sec_recaptcha_secret_key', '' );
    $limit_login  = get_option( 'sv_sec_limit_login' ) === '1';
    $login_key    = get_option( 'sv_sec_login_key', '' );
    $enable_key   = get_option( 'sv_sec_enable_login_key' ) === '1';
    $dis_xmlrpc   = get_option( 'sv_sec_disable_xmlrpc' ) === '1';
    $dis_editor   = get_option( 'sv_sec_disable_editor' ) === '1' || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );
    ?>
    <form method="POST">
        <?php wp_nonce_field( 'sv_security_nonce' ); ?>

        <div class="sv-content-box">
            <div class="sv-box-header"><span class="dashicons dashicons-update" style="color:#d63638;"></span><h3><?php esc_html_e( 'Chống Spam Đăng Nhập (reCAPTCHA v2 / v3)', 'sitevorx' ); ?></h3></div>
            <div class="sv-form-group">
                <div class="sv-form-label"><strong><?php esc_html_e( 'Bật reCAPTCHA cho trang đăng nhập', 'sitevorx' ); ?></strong><p><?php echo sv_kses_basic( sprintf( __( 'Ngăn bot dò mật khẩu trên wp-login.php. %sLấy Site/Secret Key tại đây%s.', 'sitevorx' ), '<a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener noreferrer">', '</a>' ) ); ?></p></div>
                <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="sec_enable_recaptcha" value="1" <?php checked( $rc_enabled ); ?> data-sv-toggle="sv_rec_box"><span class="sv-slider"></span></label></div>
            </div>
            <div id="sv_rec_box" style="background:#f8f9fa; padding:20px; border-top:1px solid #f0f0f1; display:<?php echo $rc_enabled ? 'block' : 'none'; ?>;">
                <div style="margin-bottom:15px;">
                    <strong><?php esc_html_e( 'Phiên bản', 'sitevorx' ); ?></strong><br>
                    <label style="margin-right:15px;"><input type="radio" name="sec_recaptcha_version" value="v2" <?php checked( $rc_version, 'v2' ); ?>> v2 (checkbox)</label>
                    <label><input type="radio" name="sec_recaptcha_version" value="v3" <?php checked( $rc_version, 'v3' ); ?>> v3 (invisible, score-based)</label>
                </div>
                <div style="margin-bottom:15px;"><strong>Site Key</strong><br><input type="text" name="sec_recaptcha_site_key" value="<?php echo esc_attr( $rc_site ); ?>" style="width:100%; max-width:400px; padding:6px; margin-top:5px; border:1px solid #c3c4c7; border-radius:4px;"></div>
                <div><strong>Secret Key</strong><br><input type="text" name="sec_recaptcha_secret_key" value="<?php echo esc_attr( $rc_secret ); ?>" style="width:100%; max-width:400px; padding:6px; margin-top:5px; border:1px solid #c3c4c7; border-radius:4px;"></div>
            </div>
        </div>

        <div class="sv-content-box">
            <div class="sv-box-header"><span class="dashicons dashicons-hidden" style="color:#8e44ad;"></span><h3><?php esc_html_e( 'Bảo Mật Trang Đăng Nhập', 'sitevorx' ); ?></h3></div>
            <div class="sv-form-group">
                <div class="sv-form-label"><strong><?php esc_html_e( 'Khóa IP truy cập trái phép', 'sitevorx' ); ?></strong><p><?php esc_html_e( 'Tự động khóa IP sau số lần đăng nhập sai. Quản lý IP bị khóa chi tiết tại trang "Tối ưu Tốc Độ" → tab Bảo Mật.', 'sitevorx' ); ?></p></div>
                <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="sec_limit_login" value="1" <?php checked( $limit_login ); ?>><span class="sv-slider"></span></label></div>
            </div>
            <div class="sv-form-group">
                <div class="sv-form-label"><strong><?php esc_html_e( 'Đổi đường dẫn đăng nhập (URL bí mật)', 'sitevorx' ); ?></strong><p><?php esc_html_e( 'Giấu đường link đăng nhập mặc định. Chỉ ai biết từ khóa bí mật mới vào được.', 'sitevorx' ); ?></p></div>
                <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="sec_enable_login_key" value="1" <?php checked( $enable_key ); ?> data-sv-toggle="sv_key_box"><span class="sv-slider"></span></label></div>
            </div>
            <div id="sv_key_box" style="background:#f8f9fa; padding:20px; border-top:1px solid #f0f0f1; display:<?php echo $enable_key ? 'block' : 'none'; ?>;">
                <strong><?php esc_html_e( 'Từ khóa bảo mật', 'sitevorx' ); ?></strong><br>
                <input type="text" name="sec_login_key" value="<?php echo esc_attr( $login_key ); ?>" style="width:100%; max-width:300px; padding:6px; margin-top:5px; border:1px solid #c3c4c7; border-radius:4px;">
                <?php if ( $login_key ) : ?>
                    <p style="margin-top:8px; font-size:12px; color:#646970;">* <?php esc_html_e( 'Đường dẫn truy cập:', 'sitevorx' ); ?> <code><?php echo esc_url( home_url( '/?' . $login_key ) ); ?></code></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="sv-content-box">
            <div class="sv-box-header"><span class="dashicons dashicons-shield" style="color:#f39c12;"></span><h3><?php esc_html_e( 'Củng Cố Lõi Hệ Thống', 'sitevorx' ); ?></h3></div>
            <div class="sv-form-group">
                <div class="sv-form-label"><strong><?php esc_html_e( 'Vô hiệu hóa XML-RPC', 'sitevorx' ); ?></strong><p><?php esc_html_e( 'Đóng cổng kết nối cũ để chặn brute-force và DDoS qua XML-RPC.', 'sitevorx' ); ?></p></div>
                <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="sec_disable_xmlrpc" value="1" <?php checked( $dis_xmlrpc ); ?>><span class="sv-slider"></span></label></div>
            </div>
            <div class="sv-form-group">
                <div class="sv-form-label"><strong><?php esc_html_e( 'Khóa Theme/Plugin File Editor', 'sitevorx' ); ?></strong><p><?php esc_html_e( 'Ngăn việc sửa nhầm mã nguồn Theme/Plugin từ giao diện admin làm sập website.', 'sitevorx' ); ?></p></div>
                <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="sec_disable_editor" value="1" <?php checked( $dis_editor ); ?>><span class="sv-slider"></span></label></div>
            </div>
            <div class="sv-form-footer"><button type="submit" name="sv_save_security" class="button button-primary"><?php esc_html_e( 'Lưu cấu hình', 'sitevorx' ); ?></button></div>
        </div>
    </form>
    <?php
}

// ==========================================================================
// MONITOR FORM (Tab 5) — Honeypot / User Enum / Login Notify
// ==========================================================================
function sv_render_sec_monitor_form() {
    $honeypot = get_option( 'sv_sec_honeypot_enabled' ) === '1';
    $block_enum = get_option( 'sv_sec_block_user_enum' ) === '1';
    $login_notify = get_option( 'sv_sec_login_notify' ) === '1';
    $admin_email  = get_option( 'admin_email' );
    ?>
    <form method="POST">
        <?php wp_nonce_field( 'sv_security_nonce' ); ?>
        <div class="sv-content-box">
            <div class="sv-box-header"><span class="dashicons dashicons-visibility" style="color:#3b82f6;"></span><h3><?php esc_html_e( 'Giám sát đăng nhập', 'sitevorx' ); ?></h3></div>

            <div class="sv-form-group">
                <div class="sv-form-label"><strong><?php esc_html_e( 'Bẫy bot đăng nhập (Honeypot)', 'sitevorx' ); ?></strong><p><?php esc_html_e( 'Chèn ô nhập ẩn vào form đăng nhập. Bot điền vào sẽ bị từ chối, người dùng thật không thấy ô này nên không ảnh hưởng.', 'sitevorx' ); ?></p></div>
                <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="sec_honeypot" value="1" <?php checked( $honeypot ); ?>><span class="sv-slider"></span></label></div>
            </div>

            <div class="sv-form-group">
                <div class="sv-form-label"><strong><?php esc_html_e( 'Chặn dò username', 'sitevorx' ); ?></strong><p><?php esc_html_e( 'Chặn truy vấn ?author=N và REST API /wp/v2/users từ khách truy cập chưa đăng nhập để hacker không thu thập được danh sách tài khoản.', 'sitevorx' ); ?></p></div>
                <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="sec_block_enum" value="1" <?php checked( $block_enum ); ?>><span class="sv-slider"></span></label></div>
            </div>

            <div class="sv-form-group">
                <div class="sv-form-label">
                    <strong><?php esc_html_e( 'Email báo khi admin đăng nhập', 'sitevorx' ); ?></strong>
                    <p><?php echo esc_html( sprintf( __( 'Gửi mail tới %s mỗi khi có tài khoản quyền manage_options đăng nhập thành công.', 'sitevorx' ), $admin_email ) ); ?></p>
                    <p style="margin-top:4px;"><?php esc_html_e( 'Giới hạn 1 email/giờ cho mỗi IP để tránh spam. Đổi địa chỉ nhận tại Cài đặt → Tổng quan → Địa chỉ Email của trang.', 'sitevorx' ); ?></p>
                    <p style="margin-top:4px; color:#b45309;"><?php echo sv_kses_basic( __( '⚠ Email gửi qua <code>wp_mail()</code>. Nếu Hosting chưa cấu hình SMTP thì email có thể không tới hoặc vào Spam. Bật <a href="?page=sv-smtp" style="color:#2563eb; font-weight:600;">Cấu hình SMTP</a> để chắc chắn giao mail.', 'sitevorx' ) ); ?></p>
                </div>
                <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="sec_login_notify" value="1" <?php checked( $login_notify ); ?>><span class="sv-slider"></span></label></div>
            </div>

            <?php $last_notify = get_option( 'sv_sec_login_notify_last', array() ); ?>
            <div style="background:#f8fafc; padding:18px 25px; border-top:1px solid #f0f0f1;">
                <p style="margin:0 0 10px 0; font-weight:600; color:#1f2937;"><?php esc_html_e( 'Kiểm thử ngay', 'sitevorx' ); ?></p>
                <p style="margin:0 0 12px 0; color:#475569; font-size:13px;"><?php echo esc_html( sprintf( __( 'Bấm nút bên dưới để gửi 1 email thử tới %s. Email sẽ có tiền tố [Sitevorx TEST] để dễ phân biệt với email thật.', 'sitevorx' ), $admin_email ) ); ?></p>
                <button type="button" class="button button-primary sv-test-login-notify" data-nonce="<?php echo esc_attr( wp_create_nonce( 'sv_security_nonce' ) ); ?>"><?php esc_html_e( 'Gửi email thử', 'sitevorx' ); ?></button>
                <span id="sv-notify-result" style="margin-left:12px; font-size:13px;"></span>
                <?php if ( ! empty( $last_notify['ts'] ) ) : ?>
                    <p style="margin:12px 0 0; font-size:12px; color:#64748b;">
                        <?php echo esc_html( sprintf(
                            __( 'Lần gửi gần nhất: %1$s — tài khoản %2$s từ IP %3$s — %4$s', 'sitevorx' ),
                            wp_date( 'd/m/Y H:i:s', (int) $last_notify['ts'] ),
                            isset( $last_notify['username'] ) ? (string) $last_notify['username'] : '?',
                            isset( $last_notify['ip'] ) ? (string) $last_notify['ip'] : '?',
                            ! empty( $last_notify['success'] ) ? __( '✓ wp_mail trả về thành công', 'sitevorx' ) : __( '✕ wp_mail thất bại', 'sitevorx' )
                        ) ); ?>
                    </p>
                <?php endif; ?>
            </div>

            <script>
            (function($){
                $(document).on('click', '.sv-test-login-notify', function(){
                    var $btn = $(this), $out = $('#sv-notify-result');
                    $btn.prop('disabled', true);
                    $out.text('<?php echo esc_js( __( 'Đang gửi…', 'sitevorx' ) ); ?>').css('color', '#64748b');
                    $.post(ajaxurl, { action: 'sv_test_login_notify', nonce: $btn.data('nonce') })
                        .done(function(resp){
                            $btn.prop('disabled', false);
                            if (resp && resp.success) {
                                $out.text('✓ ' + (resp.data.message || '<?php echo esc_js( __( 'Đã gửi', 'sitevorx' ) ); ?>')).css('color', '#15803d');
                            } else {
                                $out.text('✕ ' + ((resp && resp.data) || '<?php echo esc_js( __( 'Gửi thất bại', 'sitevorx' ) ); ?>')).css('color', '#b91c1c');
                            }
                        })
                        .fail(function(){
                            $btn.prop('disabled', false);
                            $out.text('✕ <?php echo esc_js( __( 'Lỗi kết nối server.', 'sitevorx' ) ); ?>').css('color', '#b91c1c');
                        });
                });
            })(jQuery);
            </script>

            <div class="sv-form-footer"><button type="submit" name="sv_save_monitor" class="button button-primary"><?php esc_html_e( 'Lưu cấu hình', 'sitevorx' ); ?></button></div>
        </div>
    </form>
    <?php
}

// ==========================================================================
// HEADERS RENDER (Tab 4)
// ==========================================================================
function sv_render_sec_tab_headers() {
    $enabled  = get_option( 'sv_sec_headers_enabled' ) === '1';
    $hsts_on  = get_option( 'sv_sec_headers_hsts' ) === '1';
    $hsts_max = (int) get_option( 'sv_sec_headers_hsts_max', 15768000 ); // 6 months
    $hsts_sub = get_option( 'sv_sec_headers_hsts_sub' ) === '1';
    $is_https = sv_is_effectively_ssl();
    $test_nonce = wp_create_nonce( 'sv_security_nonce' );
    ?>
    <form method="POST">
        <?php wp_nonce_field( 'sv_security_nonce' ); ?>

        <div class="sv-content-box">
            <div class="sv-box-header"><span class="dashicons dashicons-shield-alt" style="color:#16a34a;"></span><h3><?php esc_html_e( 'Bộ header bảo mật cơ bản', 'sitevorx' ); ?></h3></div>

            <div class="sv-form-group">
                <div class="sv-form-label">
                    <strong><?php esc_html_e( 'Bật 4 header an toàn cho frontend', 'sitevorx' ); ?></strong>
                    <p><?php esc_html_e( 'Áp dụng 4 header phòng vệ trên trang public. Không động vào admin / AJAX / REST / cron để tránh ảnh hưởng vận hành. Tất cả đều an toàn — không yêu cầu chỉnh hosting.', 'sitevorx' ); ?></p>
                </div>
                <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="sec_headers_enabled" value="1" <?php checked( $enabled ); ?>><span class="sv-slider"></span></label></div>
            </div>

            <div style="background:#f8f9fa; padding:18px 25px; border-top:1px solid #f0f0f1;">
                <p style="margin:0 0 10px 0; font-weight:600; color:#1f2937;"><?php esc_html_e( 'Bốn header được gửi khi bật:', 'sitevorx' ); ?></p>
                <ul style="margin:0; padding-left:20px; line-height:1.9; color:#374151;">
                    <li><code>X-Content-Type-Options: nosniff</code> &mdash; <?php esc_html_e( 'chặn browser đoán MIME type sai (XSS qua upload).', 'sitevorx' ); ?></li>
                    <li><code>X-Frame-Options: SAMEORIGIN</code> &mdash; <?php esc_html_e( 'chỉ cho domain chính nhúng site qua iframe, chặn clickjacking.', 'sitevorx' ); ?></li>
                    <li><code>Referrer-Policy: strict-origin-when-cross-origin</code> &mdash; <?php esc_html_e( 'chỉ gửi đầy đủ referrer trong cùng domain, ngoài domain chỉ gửi origin.', 'sitevorx' ); ?></li>
                    <li><code>Permissions-Policy: camera=(), microphone=(), geolocation=()</code> &mdash; <?php esc_html_e( 'tắt 3 API nhạy cảm cho mọi iframe nhúng vào site.', 'sitevorx' ); ?></li>
                </ul>
            </div>
        </div>

        <div class="sv-content-box">
            <div class="sv-box-header"><span class="dashicons dashicons-lock" style="color:#dc2626;"></span><h3><?php esc_html_e( 'HSTS — Ép trình duyệt luôn dùng HTTPS', 'sitevorx' ); ?></h3></div>

            <?php if ( ! $is_https ) : ?>
                <div style="margin:16px 25px; padding:12px 16px; background:#fee2e2; border-left:4px solid #dc2626; border-radius:4px;">
                    <strong style="color:#991b1b;"><?php esc_html_e( '⚠ Không thể bật HSTS', 'sitevorx' ); ?></strong>
                    <p style="margin:4px 0 0; color:#7f1d1d;"><?php esc_html_e( 'Site đang truy cập qua HTTP, chưa có SSL ổn định. Cài SSL trước (Let\'s Encrypt miễn phí) rồi mới bật HSTS, nếu không khách sẽ không vào được site.', 'sitevorx' ); ?></p>
                </div>
            <?php endif; ?>

            <div class="sv-form-group">
                <div class="sv-form-label">
                    <strong><?php esc_html_e( 'Bật HTTP Strict-Transport-Security', 'sitevorx' ); ?></strong>
                    <p><?php esc_html_e( 'Báo trình duyệt: "Domain này CHỈ phục vụ qua HTTPS — đừng thử HTTP nữa". Bảo vệ trước tấn công SSL stripping và man-in-the-middle.', 'sitevorx' ); ?></p>
                    <p style="margin-top:6px; color:#b91c1c; font-weight:600;"><?php esc_html_e( '⚠ Lưu ý: Một khi browser đã nhận HSTS, suốt thời gian max-age sẽ KHÔNG cho phép truy cập qua HTTP. Nếu SSL hết hạn / hỏng → khách không vào được site cho tới khi sửa SSL.', 'sitevorx' ); ?></p>
                </div>
                <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="sec_headers_hsts" value="1" <?php checked( $hsts_on ); ?> <?php disabled( ! $is_https ); ?> data-sv-toggle="sv_hsts_box"><span class="sv-slider"></span></label></div>
            </div>

            <div id="sv_hsts_box" style="background:#f8f9fa; padding:20px; border-top:1px solid #f0f0f1; display:<?php echo $hsts_on ? 'block' : 'none'; ?>;">
                <div style="margin-bottom:14px;">
                    <strong><?php esc_html_e( 'Thời gian áp dụng (max-age)', 'sitevorx' ); ?></strong><br>
                    <select name="sec_headers_hsts_max" style="margin-top:6px; padding:6px 10px; border:1px solid #c3c4c7; border-radius:4px;">
                        <option value="300" <?php selected( $hsts_max, 300 ); ?>>5 phút (test)</option>
                        <option value="86400" <?php selected( $hsts_max, 86400 ); ?>>1 ngày</option>
                        <option value="2592000" <?php selected( $hsts_max, 2592000 ); ?>>30 ngày</option>
                        <option value="15768000" <?php selected( $hsts_max, 15768000 ); ?>>6 tháng (khuyên dùng)</option>
                        <option value="31536000" <?php selected( $hsts_max, 31536000 ); ?>>1 năm (yêu cầu cho HSTS Preload)</option>
                    </select>
                    <p style="margin:6px 0 0; font-size:12px; color:#64748b;"><?php esc_html_e( 'Bắt đầu bằng 5 phút để test, ổn định mới tăng lên. Tăng dần — không giảm.', 'sitevorx' ); ?></p>
                </div>
                <div>
                    <label><input type="checkbox" name="sec_headers_hsts_sub" value="1" <?php checked( $hsts_sub ); ?>> <strong><?php esc_html_e( 'includeSubDomains', 'sitevorx' ); ?></strong></label>
                    <p style="margin:4px 0 0 24px; font-size:12px; color:#64748b;"><?php esc_html_e( 'Áp dụng HSTS cho cả mọi subdomain. CHỈ bật khi MỌI subdomain đều có SSL — nếu không, các subdomain không SSL sẽ bị chặn.', 'sitevorx' ); ?></p>
                </div>
            </div>
        </div>

        <div class="sv-content-box">
            <div class="sv-box-header"><span class="dashicons dashicons-search" style="color:#2563eb;"></span><h3><?php esc_html_e( 'Kiểm tra header thực tế', 'sitevorx' ); ?></h3></div>
            <div class="sv-form-group">
                <div class="sv-form-label">
                    <strong><?php esc_html_e( 'Đọc HTTP response của trang chủ', 'sitevorx' ); ?></strong>
                    <p><?php esc_html_e( 'Gọi GET tới home_url() rồi liệt kê các security header thực tế server trả về. Dùng để xác nhận: header đã active, hoặc CDN/Cloudflare có đè header không.', 'sitevorx' ); ?></p>
                </div>
                <div class="sv-form-input">
                    <button type="button" class="button button-primary sv-test-headers" data-nonce="<?php echo esc_attr( $test_nonce ); ?>"><?php esc_html_e( 'Kiểm tra ngay', 'sitevorx' ); ?></button>
                </div>
            </div>
            <div id="sv-headers-result" style="display:none; padding:18px 25px; border-top:1px solid #f0f0f1; background:#f8fafc;"></div>

            <div class="sv-form-footer"><button type="submit" name="sv_save_headers" class="button button-primary"><?php esc_html_e( 'Lưu cấu hình', 'sitevorx' ); ?></button></div>
        </div>
    </form>

    <script>
    (function($){
        $(document).on('click', '.sv-test-headers', function(){
            var $btn = $(this), $out = $('#sv-headers-result');
            $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Đang gọi…', 'sitevorx' ) ); ?>');
            $out.show().html('<p style="margin:0; color:#64748b;"><?php echo esc_js( __( 'Đang fetch home_url()…', 'sitevorx' ) ); ?></p>');
            $.post(ajaxurl, { action: 'sv_test_headers', nonce: $btn.data('nonce') })
                .done(function(resp){
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Kiểm tra lại', 'sitevorx' ) ); ?>');
                    if (!resp || !resp.success) {
                        $out.html('<p style="margin:0; color:#dc2626;"><strong><?php echo esc_js( __( 'Lỗi:', 'sitevorx' ) ); ?></strong> ' + (resp && resp.data ? resp.data : '') + '</p>');
                        return;
                    }
                    var rows = resp.data.headers || {}, html = '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
                    var checkList = ['x-content-type-options','x-frame-options','referrer-policy','permissions-policy','strict-transport-security'];
                    checkList.forEach(function(h){
                        var v = rows[h] || '', ok = !!v;
                        html += '<tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 4px; font-family:monospace; font-size:12px; width:35%; vertical-align:top;">' + h + '</td>' +
                                '<td style="padding:8px 4px; color:' + (ok ? '#15803d' : '#94a3b8') + ';">' + (ok ? '✓ ' + $('<div>').text(v).html() : '<em><?php echo esc_js( __( 'không có', 'sitevorx' ) ); ?></em>') + '</td></tr>';
                    });
                    html += '</table>';
                    if (resp.data.url) html += '<p style="margin:10px 0 0; font-size:11px; color:#94a3b8;"><?php echo esc_js( __( 'URL kiểm tra:', 'sitevorx' ) ); ?> <code>' + $('<div>').text(resp.data.url).html() + '</code></p>';
                    $out.html(html);
                })
                .fail(function(){
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Thử lại', 'sitevorx' ) ); ?>');
                    $out.html('<p style="margin:0; color:#dc2626;"><?php echo esc_js( __( 'Không kết nối được tới home URL.', 'sitevorx' ) ); ?></p>');
                });
        });
    })(jQuery);
    </script>
    <?php
}
