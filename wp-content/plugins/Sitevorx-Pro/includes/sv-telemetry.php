<?php
/**
 * Sitevorx Pro — Đo lường cài đặt (telemetry tối giản, Pro-only).
 *
 * MỤC ĐÍCH: nắm SỐ LƯỢNG site đang dùng Pro + phân bố phiên bản (plugin/WP/PHP).
 * KHÔNG thu thập nội dung site, tài khoản người dùng, hay dữ liệu nhạy cảm.
 *
 * CƠ CHẾ: mỗi site có 1 site_id ổn định (UUID lưu option). Một cron hằng ngày gửi
 * 1 "ping" tới endpoint của iNET, ký HMAC-SHA256 bằng secret chung. Server upsert
 * theo site_id → "site đang hoạt động" = số site có last_seen trong N ngày gần nhất
 * (giống cách WordPress.org tính active installs). Gửi NON-BLOCKING nên không bao
 * giờ làm chậm trang.
 *
 * BẢO MẬT / RIÊNG TƯ: chỉ là plugin thương mại cho khách hosting iNET (không qua
 * WordPress.org). Mặc định BẬT, nhưng:
 *  - chưa cấu hình endpoint + secret  → KHÔNG gửi (mặc định an toàn ở repo/free build);
 *  - tắt hẳn: định nghĩa hằng `SV_TELEMETRY_DISABLED = true`, hoặc
 *    filter `add_filter( 'sv_telemetry_enabled', '__return_false' )`.
 *
 * Cấu hình (đặt ở includes/sv-s3-config.php — file managed của iNET, skip-worktree):
 *  - SV_TELEMETRY_ENDPOINT : URL nhận ping.
 *  - SV_TELEMETRY_SECRET   : secret HMAC, PHẢI khớp 'secret' của backend.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'SV_TELEMETRY_ENDPOINT' ) ) define( 'SV_TELEMETRY_ENDPOINT', 'https://thongke.trungtq.io.vn/ingest.php' );
if ( ! defined( 'SV_TELEMETRY_SECRET' ) )   define( 'SV_TELEMETRY_SECRET', '' );

const SV_TELEMETRY_INTERVAL = DAY_IN_SECONDS; // tối đa 1 ping/ngày dù có nhiều trigger.

function sv_telemetry_enabled() {
	if ( defined( 'SV_TELEMETRY_DISABLED' ) && SV_TELEMETRY_DISABLED ) {
		return false;
	}
	// Chưa cấu hình endpoint/secret → không gửi (an toàn mặc định).
	if ( '' === (string) SV_TELEMETRY_ENDPOINT || '' === (string) SV_TELEMETRY_SECRET ) {
		return false;
	}
	return (bool) apply_filters( 'sv_telemetry_enabled', true );
}

/**
 * site_id ổn định cho site này (UUID v4, lưu 1 lần). Giữ nguyên qua reset/cập nhật
 * để không bị đếm trùng — chỉ đổi khi DB bị xóa sạch / cài lại WP.
 */
function sv_telemetry_site_id() {
	$id = get_option( 'sv_telemetry_site_id', '' );
	if ( ! is_string( $id ) || strlen( $id ) < 32 ) {
		$id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( home_url() . wp_rand() . microtime() );
		update_option( 'sv_telemetry_site_id', $id, false );
	}
	return $id;
}

/**
 * Payload tối giản: chỉ định danh site + các phiên bản (không có dữ liệu nội dung).
 */
function sv_telemetry_payload() {
	global $wp_version, $wpdb;
	$domain = wp_parse_url( home_url(), PHP_URL_HOST );
	return array(
		'site_id'        => sv_telemetry_site_id(),
		'domain'         => is_string( $domain ) ? $domain : '',
		'account'        => function_exists( 'sv_backup_account_key' ) ? sv_backup_account_key() : '',
		'edition'        => 'pro',
		'plugin_version' => defined( 'SV_PLUGIN_VERSION' ) ? SV_PLUGIN_VERSION : '',
		'wp_version'     => (string) $wp_version,
		'php_version'    => PHP_VERSION,
		'mysql_version'  => ( $wpdb && method_exists( $wpdb, 'db_version' ) ) ? (string) $wpdb->db_version() : '',
		'is_inet'        => ( function_exists( 'sv_is_inet_hosting' ) && sv_is_inet_hosting() ) ? 1 : 0,
		'ts'             => time(),
	);
}

/**
 * Gửi 1 ping (non-blocking). Có throttle theo ngày trừ khi $force.
 */
function sv_telemetry_send( $force = false ) {
	if ( ! sv_telemetry_enabled() ) {
		return;
	}
	$last = (int) get_option( 'sv_telemetry_last_sent', 0 );
	if ( ! $force && $last && ( time() - $last ) < SV_TELEMETRY_INTERVAL ) {
		return;
	}

	$payload = sv_telemetry_payload();
	$body    = wp_json_encode( $payload );
	$sig     = hash_hmac( 'sha256', $body, (string) SV_TELEMETRY_SECRET );

	// Non-blocking: phát đi rồi quên, không chờ phản hồi → không làm chậm pageload.
	wp_remote_post( SV_TELEMETRY_ENDPOINT, array(
		'timeout'     => 5,
		'blocking'    => false,
		'redirection' => 0,
		'headers'     => array(
			'Content-Type'   => 'application/json',
			'X-SV-Signature' => $sig,
		),
		'body'        => $body,
		'sslverify'   => true,
	) );

	// Vì non-blocking nên không biết kết quả — đánh dấu đã gửi ngay để khỏi spam.
	update_option( 'sv_telemetry_last_sent', time(), false );
	update_option( 'sv_telemetry_version_sent', $payload['plugin_version'], false );
}

// Cron hằng ngày.
add_action( 'sv_telemetry_event', 'sv_telemetry_cron' );
function sv_telemetry_cron() {
	sv_telemetry_send( true ); // cron đã đúng nhịp ngày.
}

add_action( 'admin_init', function() {
	if ( ! sv_telemetry_enabled() ) {
		return;
	}
	if ( ! wp_next_scheduled( 'sv_telemetry_event' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sv_telemetry_event' );
	}
	// Gửi sớm khi lần đầu (chưa từng gửi) hoặc vừa NÂNG CẤP phiên bản (để nắm nhanh
	// tốc độ cập nhật của khách). Throttle theo ngày vẫn áp dụng cho trường hợp đầu.
	$ver_sent = (string) get_option( 'sv_telemetry_version_sent', '' );
	$cur_ver  = defined( 'SV_PLUGIN_VERSION' ) ? SV_PLUGIN_VERSION : '';
	$never    = '' === (string) get_option( 'sv_telemetry_last_sent', '' );
	if ( $never || $ver_sent !== $cur_ver ) {
		sv_telemetry_send( $ver_sent !== $cur_ver ); // đổi version → gửi ngay (bỏ throttle).
	}
} );

register_deactivation_hook(
	defined( 'SV_PLUGIN_FILE' ) ? SV_PLUGIN_FILE : __FILE__,
	function() { wp_clear_scheduled_hook( 'sv_telemetry_event' ); }
);
