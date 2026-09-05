<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================================================================
// TAB 3: QUÉT MÃ ĐỘC (Scanner) — extracted from system-optimizer.php
// ==========================================================================
function sv_render_sec_tab_scanner() {
    global $wpdb;
    $scan_results = array();
    $scan_ran     = false;
    $scanned_count = 0;

    if ( current_user_can( 'manage_options' ) && isset( $_POST['sv_run_scan'] ) && check_admin_referer( 'sv_scanner_nonce' ) ) {
        $scan_ran  = true;
        $scan_dir  = sv_get_wordpress_root_path();
        $content_dir_name   = preg_quote( basename( sv_get_content_dir_path() ), '/' );
        $backup_dir_pattern = '/(?:^|[\/\\\\])' . $content_dir_name . '[\/\\\\](plugins|upgrade)[\/\\\\](backup|updraftplus|wordfence|litespeed-cache|seo-by-rank-math.*|elementor|woocommerce)/i';

        $scanner_term_groups = array(
            'decode'  => array( 'base64' . '_decode', 'gzi' . 'nflate', 'gzun' . 'compress', 'str_' . 'rot13' ),
            'exec'    => array( 'shell' . '_exec', 'pass' . 'thru', 'sys' . 'tem', 'ex' . 'ec', 'proc_' . 'open', 'po' . 'pen' ),
            'dynamic' => array( 'ass' . 'ert', 'create_' . 'function' ),
            'shells'  => array( 'Files' . 'Man', 'r57' . 'shell', 'c99' . 'shell' ),
            'wso'     => 'W' . 'SO',
        );
        $regex_union = function( $terms ) {
            return implode( '|', array_map( function( $term ) { return preg_quote( $term, '/' ); }, $terms ) );
        };
        $decode_regex  = $regex_union( $scanner_term_groups['decode'] );
        $exec_regex    = $regex_union( $scanner_term_groups['exec'] );
        $dynamic_regex = $regex_union( $scanner_term_groups['dynamic'] );
        $shells_regex  = $regex_union( $scanner_term_groups['shells'] );
        $wso_regex     = preg_quote( $scanner_term_groups['wso'], '/' );

        $critical_signatures = array(
            array( 'pattern' => '/ev' . 'al\s*\(\s*(?:' . $decode_regex . ')\s*\(/i', 'desc' => __( 'Thực thi chuỗi mã hóa / giải nén qua eval', 'sitevorx' ) ),
            array( 'pattern' => '/\b(?:' . $exec_regex . ')\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE|SERVER)|' . $decode_regex . '|url' . 'decode)/i', 'desc' => __( 'Gọi lệnh hệ thống từ dữ liệu đầu vào', 'sitevorx' ) ),
            array( 'pattern' => '/preg_replace\s*\(\s*["\'][^"\']*\/e[imsxeuADSUXJu]*[\"\']/' . 'i', 'desc' => __( 'preg_replace với modifier /e', 'sitevorx' ) ),
            array( 'pattern' => '/\b(?:' . $dynamic_regex . ')\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE|SERVER)|' . $decode_regex . ')/i', 'desc' => __( 'Thực thi động từ dữ liệu không tin cậy', 'sitevorx' ) ),
            array( 'pattern' => '/\b(?:' . $shells_regex . ')\b/i', 'desc' => __( 'Chữ ký web shell phổ biến', 'sitevorx' ) ),
            array( 'pattern' => '/(?<![a-z0-9_])' . $wso_regex . '(?:\s|["\'])/i', 'desc' => __( 'Chữ ký web shell phổ biến', 'sitevorx' ) ),
        );
        $heuristic_signatures = array(
            array( 'pattern' => '/\b(?:' . $decode_regex . ')\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE|SERVER)/i', 'desc' => __( 'Giải mã trực tiếp dữ liệu đầu vào', 'sitevorx' ), 'score' => 3 ),
            array( 'pattern' => '/\b(?:' . $decode_regex . ')\s*\(\s*(?:' . $decode_regex . ')\s*\(/i', 'desc' => __( 'Chuỗi giải mã nhiều lớp', 'sitevorx' ), 'score' => 2 ),
            array( 'pattern' => '/\b(?:' . $exec_regex . ')\s*\(/i', 'desc' => __( 'Hàm thực thi lệnh hệ thống', 'sitevorx' ), 'score' => 2 ),
            array( 'pattern' => '/\b(?:' . $decode_regex . ')\s*\(\s*["\'][A-Za-z0-9+\/=]{120,}["\']' . '\s*\)/i', 'desc' => __( 'Chuỗi mã hóa dài được giải mã', 'sitevorx' ), 'score' => 2 ),
        );
        $min_risk_score = 4;
        $prepare_scan_buffer = function( $content ) {
            if ( ! function_exists( 'token_get_all' ) ) return $content;
            $buffer = '';
            $tokens = token_get_all( $content );
            foreach ( $tokens as $token ) {
                if ( is_array( $token ) ) {
                    if ( in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML ), true ) ) { $buffer .= ' '; continue; }
                    $buffer .= $token[1]; continue;
                }
                $buffer .= $token;
            }
            return $buffer;
        };

        // --- Database scan ---
        $post_scan_terms = array( 'ev' . 'al(', 'base64' . '_decode', 'gzi' . 'nflate', 'shell' . '_exec', 'pass' . 'thru', 'sys' . 'tem(', 'ex' . 'ec(', 'ass' . 'ert(' );
        $post_scan_clauses = array_fill( 0, count( $post_scan_terms ), 'post_content LIKE %s' );
        $post_scan_params  = array( 'revision' );
        foreach ( $post_scan_terms as $t ) { $post_scan_params[] = '%' . $wpdb->esc_like( $t ) . '%'; }
        $suspicious_posts = $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} WHERE post_type <> %s AND (" . implode( ' OR ', $post_scan_clauses ) . ') LIMIT 50' ), $post_scan_params ) ) );
        foreach ( $suspicious_posts as $post ) {
            $scanned_count++;
            $matched = array(); $risk = 0; $crit = false;
            foreach ( $critical_signatures as $s ) { if ( preg_match( $s['pattern'], $post->post_content ) ) { $matched[] = $s['desc']; $crit = true; } }
            foreach ( $heuristic_signatures as $s ) { if ( preg_match( $s['pattern'], $post->post_content ) ) { $matched[] = $s['desc']; $risk += $s['score']; } }
            if ( $crit || $risk >= $min_risk_score ) {
                $scan_results[] = array( 'file' => 'Database (wp_posts): ID ' . $post->ID . ' - ' . $post->post_title, 'threat' => implode( ', ', array_unique( $matched ) ), 'size' => 'DB Record' );
            }
        }

        // --- Filesystem scan ---
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $scan_dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD );
        $scan_start = microtime( true );
        $incomplete = false;
        foreach ( $iterator as $file ) {
            if ( ( microtime( true ) - $scan_start ) > 30.0 ) { $incomplete = true; break; }
            if ( $file->isFile() && $file->getExtension() === 'php' ) {
                if ( $file->getSize() > 2 * 1024 * 1024 ) continue;
                $scanned_count++;
                $relative = ltrim( sv_get_relative_display_path( $file->getPathname() ), '/' );
                if ( preg_match( $backup_dir_pattern, $relative ) ) continue;
                if ( preg_match( '/wp-includes[\/\\\\](Requests|SimplePie|ID3|pomo)/i', $relative ) ) continue;
                $content = @file_get_contents( $file->getPathname() );
                if ( false === $content ) continue;
                $buf = $prepare_scan_buffer( $content );
                $matched = array(); $risk = 0; $crit = false;
                foreach ( $critical_signatures as $s ) { if ( preg_match( $s['pattern'], $buf ) ) { $matched[] = $s['desc']; $crit = true; } }
                foreach ( $heuristic_signatures as $s ) { if ( preg_match( $s['pattern'], $buf ) ) { $matched[] = $s['desc']; $risk += $s['score']; } }
                if ( $crit || $risk >= $min_risk_score ) {
                    $scan_results[] = array( 'file' => $relative, 'threat' => implode( ', ', array_unique( $matched ) ), 'size' => sv_format_size( $file->getSize() ) );
                }
            }
        }
        $last_scan = array(
            'ts'         => time(),
            'scanned'    => (int) $scanned_count,
            'threats'    => count( $scan_results ),
            'duration'   => isset( $scan_start ) ? round( microtime( true ) - $scan_start, 1 ) : 0,
            'incomplete' => $incomplete,
        );
        update_option( 'sv_sec_last_scan', $last_scan, false );
        if ( function_exists( 'sv_sec_log' ) ) {
            sv_sec_log( 'malware_scan', sprintf( __( 'Quét %s đối tượng, %d nghi ngờ', 'sitevorx' ), number_format( $scanned_count ), count( $scan_results ) ) );
        }
    }

    // Last scan summary (option-backed) — surfaces previous result without rescanning.
    $last       = get_option( 'sv_sec_last_scan', array() );
    $sig_count  = isset( $critical_signatures ) ? ( count( $critical_signatures ) + count( $heuristic_signatures ) ) : 10;
    $php_root   = function_exists( 'sv_get_wordpress_root_path' ) ? sv_get_wordpress_root_path() : ABSPATH;
    ?>

    <div class="sv-content-box">
        <div class="sv-box-header"><span class="dashicons dashicons-info-outline" style="color:#0ea5e9;"></span><h3><?php esc_html_e( 'Thông số quét', 'sitevorx' ); ?></h3></div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; padding:18px 25px;">
            <div style="padding:14px; background:#f8fafc; border-radius:8px; border-left:3px solid #0ea5e9;">
                <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;"><?php esc_html_e( 'Số dấu hiệu kiểm tra', 'sitevorx' ); ?></div>
                <div style="font-size:22px; font-weight:800; color:#0c4a6e;"><?php echo (int) $sig_count; ?> <span style="font-size:13px; font-weight:600; color:#64748b;"><?php esc_html_e( 'dấu hiệu', 'sitevorx' ); ?></span></div>
                <div style="font-size:11px; color:#64748b; margin-top:4px;"><?php esc_html_e( 'Đối chiếu với các loại mã độc và backdoor phổ biến nhất hiện nay.', 'sitevorx' ); ?></div>
            </div>
            <div style="padding:14px; background:#f8fafc; border-radius:8px; border-left:3px solid #16a34a;">
                <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;"><?php esc_html_e( 'Phạm vi quét', 'sitevorx' ); ?></div>
                <div style="font-size:13px; font-weight:700; color:#14532d; line-height:1.4;"><?php esc_html_e( 'File PHP + Bài viết trong DB', 'sitevorx' ); ?></div>
                <div style="font-size:11px; color:#64748b; margin-top:4px;"><?php esc_html_e( 'Quét toàn bộ source code WordPress và nội dung trong database.', 'sitevorx' ); ?></div>
            </div>
            <div style="padding:14px; background:#f8fafc; border-radius:8px; border-left:3px solid #f59e0b;">
                <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;"><?php esc_html_e( 'Giới hạn mỗi lượt', 'sitevorx' ); ?></div>
                <div style="font-size:13px; font-weight:700; color:#78350f; line-height:1.4;"><?php esc_html_e( 'Tối đa 30 giây', 'sitevorx' ); ?></div>
                <div style="font-size:11px; color:#64748b; margin-top:4px;"><?php esc_html_e( 'Tự dừng sau 30 giây để không làm chậm website. Có thể quét lại nếu cần.', 'sitevorx' ); ?></div>
            </div>
            <div style="padding:14px; background:#f8fafc; border-radius:8px; border-left:3px solid #8b5cf6;">
                <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;"><?php esc_html_e( 'Lần quét trước', 'sitevorx' ); ?></div>
                <?php if ( ! empty( $last['ts'] ) ) : ?>
                    <div style="font-size:13px; font-weight:700; color:#1e1b4b; line-height:1.4;"><?php echo esc_html( wp_date( 'd/m/Y H:i', $last['ts'] ) ); ?></div>
                    <div style="font-size:11px; color:<?php echo esc_attr( ( ! empty( $last['threats'] ) ) ? '#b91c1c' : ( ! empty( $last['incomplete'] ) ? '#b45309' : '#15803d' ) ); ?>; margin-top:4px; font-weight:600;">
                        <?php
                        if ( ! empty( $last['threats'] ) ) {
                            echo esc_html( sprintf( __( '⚠ %1$d nghi ngờ / %2$s đối tượng', 'sitevorx' ), (int) $last['threats'], number_format( (int) $last['scanned'] ) ) );
                        } elseif ( ! empty( $last['incomplete'] ) ) {
                            echo esc_html( sprintf( __( '⏳ Quét dở · %s đối tượng', 'sitevorx' ), number_format( (int) $last['scanned'] ) ) );
                        } else {
                            echo esc_html( sprintf( __( '✓ Sạch · %s đối tượng', 'sitevorx' ), number_format( (int) $last['scanned'] ) ) );
                        }
                        if ( ! empty( $last['duration'] ) ) echo ' · ' . esc_html( $last['duration'] . 's' );
                        ?>
                    </div>
                <?php else : ?>
                    <div style="font-size:13px; color:#94a3b8; font-style:italic;"><?php esc_html_e( 'Chưa quét lần nào', 'sitevorx' ); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="sv-content-box">
        <div class="sv-box-header"><span class="dashicons dashicons-shield-alt" style="color:#e74c3c;"></span><h3><?php esc_html_e( 'Trình quét mã độc chuyên sâu', 'sitevorx' ); ?></h3></div>
        <div style="padding:18px 25px;">
            <p style="margin:0 0 8px 0; color:#475569;"><?php echo sv_kses_basic( __( 'Bấm <strong>Bắt đầu quét</strong> để rà soát toàn bộ website tìm mã độc, backdoor, shell ẩn. Hệ thống tự bỏ qua phần ghi chú trong code để tránh báo nhầm.', 'sitevorx' ) ); ?></p>
            <p style="margin:0 0 14px 0; color:#475569;"><?php esc_html_e( 'Các thư mục sao lưu của plugin lớn (UpdraftPlus, Wordfence, LiteSpeed, WooCommerce, Rank Math) và thư viện gốc của WordPress được bỏ qua để kết quả không bị nhiễu.', 'sitevorx' ); ?></p>
            <form method="POST" style="display:inline;">
                <?php wp_nonce_field( 'sv_scanner_nonce' ); ?>
                <button type="submit" name="sv_run_scan" class="button button-primary" style="background:#e74c3c;border:none;padding:10px 24px;font-size:13px;height:auto;"
                    data-saving-text="<?php echo esc_attr( __( '⏳ Đang quét...', 'sitevorx' ) ); ?>"
                    data-scan-overlay-msg="<?php echo esc_attr( __( 'Đang quét toàn bộ hệ thống...', 'sitevorx' ) ); ?>"
                    data-scan-overlay-sub="<?php echo esc_attr( __( 'Thời gian từ 30–90 giây, vui lòng không tắt trang.', 'sitevorx' ) ); ?>"><?php esc_html_e( 'Bắt đầu quét chuyên sâu', 'sitevorx' ); ?></button>
                <?php if ( ! empty( $last['ts'] ) ) : ?>
                    <span style="margin-left:14px; font-size:12px; color:#64748b;"><?php echo esc_html( sprintf( __( 'Quét lần cuối %s trước', 'sitevorx' ), human_time_diff( (int) $last['ts'], time() ) ) ); ?></span>
                <?php endif; ?>
            </form>
        </div>
        <?php if ( $scan_ran ) : ?>
        <div style="padding:0 25px 25px;">
            <?php if ( empty( $scan_results ) ) : ?>
                <?php if ( ! empty( $incomplete ) ) : ?>
                <div style="padding:20px;background:#fff3cd;border-left:4px solid #f59e0b;border-radius:4px;">
                    <strong style="color:#7c5e10;">⏳ <?php esc_html_e( 'Quét chưa hoàn tất', 'sitevorx' ); ?></strong>
                    <p style="margin:5px 0 0;color:#7c5e10;"><?php echo sv_kses_basic( sprintf( __( 'Đã dừng ở giới hạn 30 giây sau khi quét %s đối tượng — website lớn nên chưa quét hết. Phần đã quét chưa thấy mã độc, nhưng hãy bấm quét lại để kiểm tra phần còn lại trước khi kết luận an toàn.', 'sitevorx' ), '<strong>' . esc_html( number_format( $scanned_count ) ) . '</strong>' ) ); ?></p>
                </div>
                <?php else : ?>
                <div style="padding:20px;background:#d4edda;border-left:4px solid #28a745;border-radius:4px;">
                    <strong style="color:#155724;">✅ <?php esc_html_e( 'Không phát hiện mã độc!', 'sitevorx' ); ?></strong>
                    <p style="margin:5px 0 0;color:#155724;"><?php echo sv_kses_basic( sprintf( __( 'Đã quét %s đối tượng. Hệ thống an toàn.', 'sitevorx' ), '<strong>' . esc_html( number_format( $scanned_count ) ) . '</strong>' ) ); ?></p>
                </div>
                <?php endif; ?>
            <?php else : ?>
                <div style="padding:15px;background:#f8d7da;border-left:4px solid #dc3545;border-radius:4px;margin-bottom:15px;">
                    <strong style="color:#721c24;">⚠️ <?php echo esc_html( sprintf( __( 'Phát hiện %d file nghi ngờ!', 'sitevorx' ), count( $scan_results ) ) ); ?></strong>
                    <p style="margin:5px 0 0;color:#721c24;"><?php echo esc_html( sprintf( __( 'Đã quét %s mục. Kiểm tra kỹ từng file trước khi xóa — đôi khi plugin hợp lệ có code phức tạp cũng bị báo nhầm. Liên hệ kỹ thuật nếu không chắc chắn.', 'sitevorx' ), number_format( $scanned_count ) ) ); ?></p>
                </div>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;">
                        <thead><tr style="background:#f8f9fa;text-align:left;">
                            <th style="padding:10px;border-bottom:2px solid #dee2e6;width:5%;">#</th>
                            <th style="padding:10px;border-bottom:2px solid #dee2e6;width:55%;"><?php esc_html_e( 'Đường dẫn', 'sitevorx' ); ?></th>
                            <th style="padding:10px;border-bottom:2px solid #dee2e6;width:25%;"><?php esc_html_e( 'Loại đe dọa', 'sitevorx' ); ?></th>
                            <th style="padding:10px;border-bottom:2px solid #dee2e6;width:15%;"><?php esc_html_e( 'Kích thước', 'sitevorx' ); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $scan_results as $i => $r ) : ?>
                            <tr style="border-bottom:1px solid #f0f0f1;">
                                <td style="padding:8px 10px;"><?php echo (int) ( $i + 1 ); ?></td>
                                <td style="padding:8px 10px;word-break:break-all;"><code style="font-size:12px;color:#d63638;background:transparent;padding:0;"><?php echo esc_html( $r['file'] ); ?></code></td>
                                <td style="padding:8px 10px;line-height:1.4;"><?php echo esc_html( $r['threat'] ); ?></td>
                                <td style="padding:8px 10px;"><?php echo esc_html( $r['size'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
