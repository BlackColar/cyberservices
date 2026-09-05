<?php
/**
 * Sitevorx Pro — Sao lưu Đám mây (S3 Backup/Restore).
 *
 * Đóng gói database + wp-content thành 1 file .zip rồi upload lên kho S3-compatible
 * (multipart, resume được), kèm lịch tự động + retention. Restore: tải về từ S3,
 * giải nén, khôi phục file, import database, search-replace URL (serialize-aware).
 *
 * Cỗ máy chunking (job-state transient, vòng lặp time-budget, ZIP builder bỏ
 * deflate cho media, DB dump cursor-pagination, sealed tmp dir, GC sweep) kế thừa
 * từ module migrate cũ đã được kiểm chứng qua nhiều bản phát hành.
 *
 * Phụ thuộc: includes/sv-s3-client.php (nạp trước trong $modules).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =============================================================================
// Hằng số & helper
// =============================================================================

const SV_BACKUP_BATCH_FILES     = 40;
const SV_BACKUP_DB_CHUNK_ROWS   = 500;
const SV_BACKUP_JOB_TTL         = 6 * HOUR_IN_SECONDS;
const SV_BACKUP_MAGIC           = 'sitevorx-pro-backup-v1';
const SV_BACKUP_TOKEN_LEN       = 32;
const SV_BACKUP_STEP_BUDGET_SEC = 15;
const SV_BACKUP_PART_SIZE       = 16777216; // 16 MB mỗi part multipart (ít round-trip hơn).
const SV_BACKUP_ZIP_BYTES_PER_STEP = 536870912; // ~512 MB dữ liệu mỗi lần mở-đóng zip — giảm số lần close (libzip ghi lại CẢ archive mỗi lần close → O(n²) nếu mở/đóng quá nhiều).
const SV_BACKUP_EXPIRE_DAYS     = 3;       // gói migrate bỏ dở tự xóa sau 3 ngày.

function sv_backup_no_compress_exts() {
	return array(
		'jpg','jpeg','png','gif','webp','avif','heic','heif',
		'mp3','mp4','m4a','m4v','mov','avi','mkv','webm','flv','ogg','opus','wav',
		'pdf','zip','gz','bz2','7z','rar','tar','tgz','xz',
		'woff','woff2',
	);
}

function sv_backup_tmp_root() {
	$u = wp_upload_dir();
	return trailingslashit( $u['basedir'] ) . 'sitevorx-backup';
}

function sv_backup_state_key( $job_id ) {
	return 'sv_backup_job_' . preg_replace( '/[^a-z0-9_]/i', '', $job_id );
}

function sv_backup_get_state( $job_id ) {
	$state = get_transient( sv_backup_state_key( $job_id ) );
	return is_array( $state ) ? $state : null;
}

function sv_backup_set_state( $job_id, array $state ) {
	set_transient( sv_backup_state_key( $job_id ), $state, SV_BACKUP_JOB_TTL );
}

function sv_backup_del_state( $job_id ) {
	delete_transient( sv_backup_state_key( $job_id ) );
}

/**
 * Xóa cây thư mục — chỉ hành động nếu nằm dưới sv_backup_tmp_root().
 */
function sv_backup_rm_rf( $path ) {
	$root = wp_normalize_path( sv_backup_tmp_root() );
	$path = wp_normalize_path( $path );
	if ( 0 !== strpos( $path, $root ) ) return;
	if ( ! file_exists( $path ) ) return;
	if ( is_file( $path ) || is_link( $path ) ) { @unlink( $path ); return; }
	$dh = @opendir( $path );
	if ( ! $dh ) return;
	while ( false !== ( $f = readdir( $dh ) ) ) {
		if ( '.' === $f || '..' === $f ) continue;
		sv_backup_rm_rf( $path . '/' . $f );
	}
	closedir( $dh );
	@rmdir( $path );
}

/**
 * Niêm phong thư mục tạm (chống liệt kê + chặn truy cập HTTP trực tiếp).
 */
function sv_backup_seal_tmp_root() {
	$root = sv_backup_tmp_root();
	if ( ! file_exists( $root ) ) {
		wp_mkdir_p( $root );
	}
	if ( ! file_exists( $root . '/index.php' ) ) {
		@file_put_contents( $root . '/index.php', "<?php // Silence is golden.\n" );
	}
	if ( ! file_exists( $root . '/.htaccess' ) ) {
		@file_put_contents( $root . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" );
	}
	if ( ! file_exists( $root . '/web.config' ) ) {
		@file_put_contents( $root . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" );
	}
}

function sv_backup_ensure_tmp_dir( $job_id ) {
	sv_backup_seal_tmp_root();
	$dir = sv_backup_tmp_root() . '/' . $job_id;
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
	}
	return $dir;
}

function sv_backup_host_slug() {
	return sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) );
}

function sv_backup_sanitize_key_seg( $s ) {
	$s = strtolower( (string) $s );
	$s = preg_replace( '/[^a-z0-9._-]+/', '-', $s );
	return trim( $s, '-' );
}

/**
 * "Account key" = định danh KHÁCH (không phải site) để namespace backup, cho phép
 * clone xuyên tên miền: một bản Pro cài mới cùng tài khoản hosting iNET sẽ thấy
 * mọi backup của khách đó.
 *
 * Thứ tự ưu tiên: hằng số SV_BACKUP_ACCOUNT (iNET set per-account) → user hosting
 * (cPanel/Unix user qua posix hoặc đường dẫn /home/{user}/) → biến môi trường →
 * domain (dự phòng). Lọc qua 'sv_backup_account_key' để override khi test.
 */
function sv_backup_account_key() {
	$key = '';

	if ( defined( 'SV_BACKUP_ACCOUNT' ) && '' !== trim( (string) SV_BACKUP_ACCOUNT ) ) {
		$key = (string) SV_BACKUP_ACCOUNT;
	}

	if ( '' === $key && function_exists( 'posix_getpwuid' ) && function_exists( 'posix_getuid' ) ) {
		$pw = @posix_getpwuid( @posix_getuid() );
		if ( ! empty( $pw['name'] ) ) {
			$key = $pw['name'];
		}
	}

	if ( '' === $key ) {
		// /home/USER/... hoặc /home2/USER/... (cPanel) — USER là tài khoản hosting.
		if ( preg_match( '#/home\d*/([^/]+)/#', wp_normalize_path( ABSPATH ), $m ) ) {
			$key = $m[1];
		}
	}

	if ( '' === $key ) {
		$env = getenv( 'USER' );
		if ( false === $env || '' === $env ) {
			$env = getenv( 'USERNAME' );
		}
		if ( $env ) {
			$key = $env;
		}
	}

	if ( '' === $key ) {
		$key = sv_backup_host_slug(); // dự phòng cuối: quay về theo domain
	}

	$key = sv_backup_sanitize_key_seg( apply_filters( 'sv_backup_account_key', $key ) );
	return '' !== $key ? $key : 'default';
}

/**
 * Prefix S3 của site hiện tại: {account}/{domain}/ — backup của site này nằm dưới đây.
 */
function sv_backup_site_prefix() {
	return sv_backup_account_key() . '/' . sv_backup_host_slug() . '/';
}

/**
 * Chặn truy cập chéo tài khoản: key client gửi lên (restore/delete) BẮT BUỘC phải
 * nằm trong namespace account của chính site này. Nếu không, một request tự chế
 * có thể restore/xóa backup của khách khác.
 */
function sv_backup_key_in_account( array $cfg, $key ) {
	$account_prefix = ltrim( sv_s3_full_key( $cfg, sv_backup_account_key() . '/' ), '/' );
	return '' !== $account_prefix && 0 === strpos( ltrim( (string) $key, '/' ), $account_prefix );
}

// =============================================================================
// Mã hóa phía client (1b) — khóa per-account, kho S3 chỉ chứa ciphertext
// =============================================================================

const SV_BACKUP_ENC_MAGIC      = 'SVBKENC1';
const SV_BACKUP_ENC_CHUNK      = 1048576; // 1MB plaintext mỗi khung.
const SV_BACKUP_CRYPT_BUDGET   = 15;

/**
 * Khóa mã hóa 32 byte (raw), hoặc null nếu KHÔNG bật mã hóa (mặc định).
 *
 * Mặc định trả null → backup đẩy thẳng không mã hóa (S3 là tầng trung chuyển nội
 * bộ). Bật bằng cách định nghĩa SV_BACKUP_ENC_KEY (ép) hoặc SV_BACKUP_KEY_API
 * (ký quỹ iNET). Bộ máy mã hóa/giải mã vẫn sẵn sàng, chỉ kích hoạt khi có khóa.
 */
function sv_backup_encryption_key() {
	static $cached = false;
	if ( false !== $cached ) {
		return $cached;
	}
	$key = '';

	// MẶC ĐỊNH: KHÔNG mã hóa (S3 chỉ là tầng trung chuyển nội bộ của iNET, khách
	// không truy cập trực tiếp). Bật lại bằng cách định nghĩa 1 trong 2 hằng số:
	if ( defined( 'SV_BACKUP_ENC_KEY' ) && '' !== (string) SV_BACKUP_ENC_KEY ) {
		$raw = (string) SV_BACKUP_ENC_KEY;
		$key = preg_match( '/^[0-9a-f]{64}$/i', $raw ) ? hex2bin( $raw ) : hash( 'sha256', $raw, true );
	} elseif ( defined( 'SV_BACKUP_KEY_API' ) && '' !== (string) SV_BACKUP_KEY_API ) {
		$key = sv_backup_fetch_escrow_key();
	}

	$cached = ( is_string( $key ) && 32 === strlen( $key ) ) ? $key : null;
	return $cached;
}

/**
 * Lấy khóa của tài khoản từ endpoint ký quỹ iNET (cache 50 phút). Endpoint tự xác
 * định tài khoản theo ngữ cảnh hosting iNET và trả JSON {"key":"<64-hex>"}.
 */
function sv_backup_fetch_escrow_key() {
	$cache = get_transient( 'sv_backup_enc_key' );
	if ( is_string( $cache ) && 32 === strlen( $cache ) ) {
		return $cache;
	}
	$res = wp_remote_get( SV_BACKUP_KEY_API, array( 'timeout' => 15 ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return '';
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	$hex  = is_array( $data ) && ! empty( $data['key'] ) ? (string) $data['key'] : '';
	if ( ! preg_match( '/^[0-9a-f]{64}$/i', $hex ) ) {
		return '';
	}
	$key = hex2bin( $hex );
	set_transient( 'sv_backup_enc_key', $key, 50 * MINUTE_IN_SECONDS );
	return $key;
}

function sv_backup_is_encrypting() {
	return null !== sv_backup_encryption_key();
}

/**
 * Mã hóa streaming theo khung: đọc $src từ $offset, ghi nối các khung vào $dst tới
 * khi hết budget hoặc EOF. Khung = [4B độ dài ciphertext][16B IV][ciphertext] (AES-256-CBC).
 * Trả true khi xong, false nếu còn dở (cập nhật $offset by-ref), WP_Error nếu lỗi.
 */
function sv_backup_encrypt_step( $src, $dst, &$offset, $key, $deadline ) {
	$in = @fopen( $src, 'rb' );
	if ( ! $in ) return new WP_Error( 'sv_enc_src', __( 'Không mở được file để mã hóa.', 'sitevorx' ) );
	$first = ( 0 === (int) $offset );
	$out   = @fopen( $dst, $first ? 'wb' : 'ab' );
	if ( ! $out ) { fclose( $in ); return new WP_Error( 'sv_enc_dst', __( 'Không ghi được file mã hóa.', 'sitevorx' ) ); }
	if ( $first ) {
		fwrite( $out, SV_BACKUP_ENC_MAGIC );
	} else {
		fseek( $in, (int) $offset );
	}
	$done = false;
	while ( microtime( true ) < $deadline ) {
		$data = fread( $in, SV_BACKUP_ENC_CHUNK );
		if ( false === $data || '' === $data ) { $done = true; break; }
		$iv = openssl_random_pseudo_bytes( 16 );
		$ct = openssl_encrypt( $data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ct ) { fclose( $in ); fclose( $out ); return new WP_Error( 'sv_enc_fail', __( 'Mã hóa thất bại.', 'sitevorx' ) ); }
		fwrite( $out, pack( 'N', strlen( $ct ) ) . $iv . $ct );
		$offset = ftell( $in );
		if ( feof( $in ) ) { $done = true; break; }
	}
	fclose( $in );
	fclose( $out );
	return $done;
}

/**
 * Giải mã streaming theo khung (đảo ngược sv_backup_encrypt_step).
 */
function sv_backup_decrypt_step( $src, $dst, &$offset, $key, $deadline ) {
	$in = @fopen( $src, 'rb' );
	if ( ! $in ) return new WP_Error( 'sv_dec_src', __( 'Không mở được file để giải mã.', 'sitevorx' ) );
	$first = ( 0 === (int) $offset );
	if ( $first ) {
		if ( SV_BACKUP_ENC_MAGIC !== fread( $in, strlen( SV_BACKUP_ENC_MAGIC ) ) ) {
			fclose( $in );
			return new WP_Error( 'sv_dec_magic', __( 'File mã hóa không hợp lệ (sai định dạng).', 'sitevorx' ) );
		}
		$offset = strlen( SV_BACKUP_ENC_MAGIC );
	} else {
		fseek( $in, (int) $offset );
	}
	$out = @fopen( $dst, $first ? 'wb' : 'ab' );
	if ( ! $out ) { fclose( $in ); return new WP_Error( 'sv_dec_dst', __( 'Không ghi được file giải mã.', 'sitevorx' ) ); }
	$done = false;
	while ( microtime( true ) < $deadline ) {
		$lenbin = fread( $in, 4 );
		if ( false === $lenbin || strlen( $lenbin ) < 4 ) { $done = true; break; }
		$arr = unpack( 'N', $lenbin );
		$len = (int) $arr[1];
		$iv  = fread( $in, 16 );
		$ct  = fread( $in, $len );
		$pt  = openssl_decrypt( $ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $pt ) {
			fclose( $in ); fclose( $out );
			return new WP_Error( 'sv_dec_fail', __( 'Giải mã thất bại — khóa không khớp hoặc file hỏng.', 'sitevorx' ) );
		}
		fwrite( $out, $pt );
		$offset = ftell( $in );
	}
	fclose( $in );
	fclose( $out );
	return $done;
}

// =============================================================================
// Thu thập file + bảng, dump DB, dựng ZIP (port từ migrate)
// =============================================================================

function sv_backup_collect_files() {
	$files       = array();
	$content_dir = wp_normalize_path( WP_CONTENT_DIR );
	$tmp_root    = wp_normalize_path( sv_backup_tmp_root() );

	$skip_dirs = array(
		$tmp_root,
		$content_dir . '/cache',
		$content_dir . '/uploads/cache',
		$content_dir . '/uploads/sitevorx-backup',
		$content_dir . '/uploads/sitevorx-migrate',
		$content_dir . '/wflogs',
		$content_dir . '/w3tc-config',
		$content_dir . '/litespeed',
		$content_dir . '/et-cache',
	);
	$skip_basename_dirs = array( 'node_modules', '.git', '.svn', '.hg', '.idea', '.vscode' );
	$skip_files         = array( '.DS_Store', 'Thumbs.db', 'error_log', 'debug.log' );

	$roots = array(
		$content_dir . '/uploads',
		$content_dir . '/themes',
		$content_dir . '/plugins',
		$content_dir . '/mu-plugins',
	);

	foreach ( $roots as $root ) {
		if ( ! is_dir( $root ) ) continue;
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $it as $f ) {
			$abs  = wp_normalize_path( $f->getPathname() );
			$skip = false;
			foreach ( $skip_dirs as $sd ) {
				if ( 0 === strpos( $abs, $sd . '/' ) || $abs === $sd ) { $skip = true; break; }
			}
			if ( $skip ) continue;
			foreach ( $skip_basename_dirs as $bn ) {
				if ( false !== strpos( '/' . $abs . '/', '/' . $bn . '/' ) ) { $skip = true; break; }
			}
			if ( $skip ) continue;
			if ( in_array( basename( $abs ), $skip_files, true ) ) continue;
			if ( ! is_file( $abs ) ) continue;

			$rel     = 'wp-content/' . ltrim( substr( $abs, strlen( $content_dir ) ), '/' );
			$files[] = array( 'abs' => $abs, 'rel' => $rel );
		}
	}
	return $files;
}

/**
 * Đếm số file theo thư mục con đầu tiên của wp-content (uploads/themes/plugins/…).
 * Dùng để chẩn đoán: nếu một nhánh = 0 thì biết gói thiếu ngay từ khâu thu thập.
 */
function sv_backup_files_breakdown( array $files ) {
	$out = array();
	foreach ( $files as $f ) {
		$rel = isset( $f['rel'] ) ? $f['rel'] : '';
		$seg = explode( '/', $rel ); // wp-content/<sub>/...
		$sub = isset( $seg[1] ) && '' !== $seg[1] ? $seg[1] : '(root)';
		$out[ $sub ] = ( isset( $out[ $sub ] ) ? $out[ $sub ] : 0 ) + 1;
	}
	return $out;
}

function sv_backup_breakdown_str( array $breakdown ) {
	$parts = array();
	foreach ( $breakdown as $sub => $n ) {
		$parts[] = $sub . '=' . $n;
	}
	return $parts ? implode( ', ', $parts ) : '(trống)';
}

function sv_backup_collect_tables() {
	global $wpdb;
	$prefix = $wpdb->prefix;
	$rows   = $wpdb->get_col( 'SHOW TABLES' );
	if ( ! is_array( $rows ) ) return array();
	$out = array();
	foreach ( $rows as $t ) {
		if ( 0 === strpos( $t, $prefix ) ) {
			$out[] = $t;
		}
	}
	return array_values( array_unique( $out ) );
}

function sv_backup_table_pk( $table ) {
	global $wpdb;
	$keys = $wpdb->get_results( "SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'", ARRAY_A );
	if ( ! is_array( $keys ) || count( $keys ) !== 1 ) return '';
	$row = $keys[0];
	if ( empty( $row['Column_name'] ) ) return '';
	$col_info = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $row['Column_name'] ), ARRAY_A );
	if ( ! $col_info || empty( $col_info['Type'] ) ) return '';
	if ( ! preg_match( '/^(int|bigint|mediumint|smallint|tinyint)\b/i', $col_info['Type'] ) ) return '';
	return $row['Column_name'];
}

/**
 * Thêm file vào zip trong 1 lần MỞ→ĐÓNG duy nhất, dừng khi đã thêm ~$byte_budget
 * byte (hoặc hết file / quá 8000 file để chặn RAM danh sách entry). Trả offset mới.
 *
 * Vì sao byte-budget thay vì "40 file/lần": mỗi $zip->close() của libzip GHI LẠI
 * toàn bộ archive (copy mọi entry cũ + entry mới). Mở/đóng mỗi 40 file → close rất
 * nhiều lần → tổng ghi ~O(n²). Gom theo byte rồi close 1 lần/lượt giảm mạnh số close.
 */
function sv_backup_append_files_to_zip( $zip_path, array $manifest_files, $offset, $byte_budget ) {
	$zip  = new ZipArchive();
	$flag = file_exists( $zip_path ) ? 0 : ZipArchive::CREATE;
	if ( true !== $zip->open( $zip_path, $flag ) ) {
		return new WP_Error( 'zip_open_failed', __( 'Không mở được file ZIP trung gian.', 'sitevorx' ) );
	}
	$no_compress = array_flip( sv_backup_no_compress_exts() );
	$total       = count( $manifest_files );
	$added_bytes = 0;
	$added_count = 0;
	while ( $offset < $total ) {
		$f = $manifest_files[ $offset ];
		$offset++;
		if ( file_exists( $f['abs'] ) && is_readable( $f['abs'] ) ) {
			if ( $zip->addFile( $f['abs'], $f['rel'] ) ) {
				$ext = strtolower( pathinfo( $f['rel'], PATHINFO_EXTENSION ) );
				if ( $ext !== '' && isset( $no_compress[ $ext ] ) && method_exists( $zip, 'setCompressionIndex' ) ) {
					$zip->setCompressionIndex( $zip->numFiles - 1, ZipArchive::CM_STORE );
				}
				$added_bytes += (int) @filesize( $f['abs'] );
				$added_count++;
			}
		}
		if ( $added_bytes >= $byte_budget || $added_count >= 8000 ) {
			break;
		}
	}
	$zip->close();
	return $offset;
}

function sv_backup_write_insert_row( $fp, $table, array $row ) {
	global $wpdb;
	$cols = array();
	$vals = array();
	foreach ( $row as $col => $val ) {
		$cols[] = '`' . $col . '`';
		$vals[] = ( null === $val ) ? 'NULL' : "'" . $wpdb->_real_escape( (string) $val ) . "'";
	}
	fwrite( $fp, 'INSERT INTO `' . $table . '` (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ");\n" );
}

function sv_backup_dump_table_to_file( $table, $out_path ) {
	global $wpdb;
	$fp = @fopen( $out_path, 'ab' );
	if ( ! $fp ) {
		return new WP_Error( 'open_failed', sprintf( __( 'Không ghi được %s', 'sitevorx' ), $out_path ) );
	}
	$create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
	if ( ! $create || empty( $create[1] ) ) {
		fclose( $fp );
		return new WP_Error( 'no_create', sprintf( __( 'Không lấy được CREATE TABLE cho %s', 'sitevorx' ), $table ) );
	}
	fwrite( $fp, "\n-- ----------------------------\n-- Table structure: $table\n-- ----------------------------\n" );
	fwrite( $fp, "DROP TABLE IF EXISTS `$table`;\n" );
	fwrite( $fp, $create[1] . ";\n\n" );

	$pk = sv_backup_table_pk( $table );
	if ( $pk ) {
		$last_pk = 0;
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `$table` WHERE `$pk` > %d ORDER BY `$pk` ASC LIMIT %d",
				$last_pk, SV_BACKUP_DB_CHUNK_ROWS
			), ARRAY_A );
			if ( empty( $rows ) ) break;
			foreach ( $rows as $row ) {
				if ( isset( $row[ $pk ] ) ) $last_pk = (int) $row[ $pk ];
				sv_backup_write_insert_row( $fp, $table, $row );
			}
		}
	} else {
		$offset = 0;
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `$table` LIMIT %d OFFSET %d",
				SV_BACKUP_DB_CHUNK_ROWS, $offset
			), ARRAY_A );
			if ( empty( $rows ) ) break;
			foreach ( $rows as $row ) {
				sv_backup_write_insert_row( $fp, $table, $row );
			}
			$offset += SV_BACKUP_DB_CHUNK_ROWS;
		}
	}
	fwrite( $fp, "\n" );
	fclose( $fp );
	return true;
}

function sv_backup_build_manifest( array $state ) {
	global $wpdb;
	return array(
		'magic'           => SV_BACKUP_MAGIC,
		'edition'         => 'pro',
		'plugin_version'  => defined( 'SV_PLUGIN_VERSION' ) ? SV_PLUGIN_VERSION : '',
		'created_at'      => gmdate( 'c' ),
		'origin_site_url' => get_option( 'siteurl' ),
		'origin_home_url' => get_option( 'home' ),
		'wp_version'      => get_bloginfo( 'version' ),
		'php_version'     => PHP_VERSION,
		'table_prefix'    => $wpdb->prefix,
		'is_multisite'    => is_multisite() ? 1 : 0,
		'include'         => isset( $state['include'] ) ? $state['include'] : 'both',
		'files_count'     => isset( $state['files_total'] ) ? (int) $state['files_total'] : 0,
		'files_breakdown' => isset( $state['breakdown'] ) && is_array( $state['breakdown'] ) ? $state['breakdown'] : array(),
		'tables_count'    => isset( $state['tables'] ) ? count( $state['tables'] ) : 0,
		'wp_content_dir'  => str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( WP_CONTENT_DIR ) ),
	);
}

/**
 * Đóng gói cuối: thêm database.sql + manifest.json vào ZIP, đổi tên cố định.
 *
 * @return string|WP_Error  Đường dẫn file zip cuối cùng.
 */
function sv_backup_finalize_archive( array &$state ) {
	$manifest_path = $state['tmp_dir'] . '/manifest.json';
	@file_put_contents( $manifest_path, wp_json_encode( sv_backup_build_manifest( $state ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

	$zip = new ZipArchive();
	if ( true !== $zip->open( $state['zip_path'], file_exists( $state['zip_path'] ) ? 0 : ZipArchive::CREATE ) ) {
		return new WP_Error( 'zip_finalize', __( 'Không mở được file ZIP để hoàn tất.', 'sitevorx' ) );
	}
	if ( file_exists( $state['sql_path'] ) ) {
		$zip->addFile( $state['sql_path'], 'database.sql' );
	}
	$zip->addFile( $manifest_path, 'manifest.json' );
	$zip->close();

	// Token ngẫu nhiên trong tên file → "mã di chuyển" (key) không đoán được, an
	// toàn để khôi phục xuyên tài khoản chỉ bằng cách dán mã (ai có mã = có quyền).
	$stamp = gmdate( 'Ymd-His' );
	$rand  = strtolower( wp_generate_password( 10, false, false ) );
	$final = $state['tmp_dir'] . '/' . sv_backup_host_slug() . '-' . $stamp . '-' . $rand . '.zip';
	@rename( $state['zip_path'], $final );
	$state['archive_path'] = $final;
	$state['archive_name'] = basename( $final );
	$state['s3_key']       = sv_backup_site_prefix() . basename( $final );
	return $final;
}

// =============================================================================
// Upload S3 (multipart, resume) — dùng chung cho AJAX & cron
// =============================================================================

/**
 * Khởi tạo multipart cho 1 archive: tính số part, tạo UploadId, lưu vào state.
 *
 * @return true|WP_Error
 */
function sv_backup_init_upload( array $cfg, array &$state ) {
	$size = filesize( $state['archive_path'] );
	if ( false === $size ) {
		return new WP_Error( 'sv_backup_no_archive', __( 'Không đọc được file backup.', 'sitevorx' ) );
	}
	$upload_id = sv_s3_create_multipart( $cfg, $state['s3_key'] );
	if ( is_wp_error( $upload_id ) ) {
		return $upload_id;
	}
	$state['upload_id']    = $upload_id;
	$state['archive_size'] = (int) $size;
	$state['parts_total']  = (int) max( 1, ceil( $size / SV_BACKUP_PART_SIZE ) );
	$state['parts']        = array();
	$state['part_offset']  = 0;
	$state['part_number']  = 1;
	return true;
}

/**
 * Upload 1 part kế tiếp từ archive. Trả true (xong part) / 'done' / WP_Error.
 *
 * @return string|true|WP_Error  'done' khi đã upload hết & complete xong.
 */
function sv_backup_upload_next_part( array $cfg, array &$state ) {
	$archive = $state['archive_path'];
	$offset  = (int) $state['part_offset'];
	$size    = (int) $state['archive_size'];

	if ( $offset >= $size ) {
		// Đã upload hết → complete.
		$res = sv_s3_complete_multipart( $cfg, $state['s3_key'], $state['upload_id'], $state['parts'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return 'done';
	}

	$len       = min( SV_BACKUP_PART_SIZE, $size - $offset );
	$part_file = $state['tmp_dir'] . '/part.tmp';

	$in = @fopen( $archive, 'rb' );
	if ( ! $in ) {
		return new WP_Error( 'sv_backup_read', __( 'Không mở được file backup để upload.', 'sitevorx' ) );
	}
	fseek( $in, $offset );
	$out = @fopen( $part_file, 'wb' );
	if ( ! $out ) {
		fclose( $in );
		return new WP_Error( 'sv_backup_part_write', __( 'Không ghi được part tạm.', 'sitevorx' ) );
	}
	$remaining = $len;
	while ( $remaining > 0 && ! feof( $in ) ) {
		$chunk = fread( $in, min( 1048576, $remaining ) );
		if ( false === $chunk || '' === $chunk ) break;
		fwrite( $out, $chunk );
		$remaining -= strlen( $chunk );
	}
	fclose( $in );
	fclose( $out );

	$etag = sv_s3_upload_part( $cfg, $state['s3_key'], $state['upload_id'], $state['part_number'], $part_file );
	@unlink( $part_file );
	if ( is_wp_error( $etag ) ) {
		return $etag;
	}

	$state['parts'][]     = array( 'PartNumber' => $state['part_number'], 'ETag' => $etag );
	$state['part_offset'] = $offset + $len;
	$state['part_number'] = $state['part_number'] + 1;
	return true;
}

/**
 * Upload toàn bộ archive (blocking) — dùng cho cron. Multipart nếu >1 part.
 *
 * @return string|WP_Error  s3_key khi thành công.
 */
function sv_backup_upload_file_blocking( array $cfg, array &$state ) {
	$size = filesize( $state['archive_path'] );
	if ( $size !== false && $size <= SV_BACKUP_PART_SIZE ) {
		$res = sv_s3_put_object( $cfg, $state['s3_key'], $state['archive_path'], 'application/zip' );
		if ( is_wp_error( $res ) ) return $res;
		return $state['s3_key'];
	}
	$init = sv_backup_init_upload( $cfg, $state );
	if ( is_wp_error( $init ) ) return $init;
	while ( true ) {
		$r = sv_backup_upload_next_part( $cfg, $state );
		if ( is_wp_error( $r ) ) {
			sv_s3_abort_multipart( $cfg, $state['s3_key'], $state['upload_id'] );
			return $r;
		}
		if ( 'done' === $r ) break;
	}
	return $state['s3_key'];
}

/**
 * Đây là công cụ DI CHUYỂN (migrate), không phải sao lưu lâu dài: gói chỉ tồn tại
 * tạm để chuyển host. Gói tạo nhưng không restore (bỏ dở) sẽ tự xóa sau N ngày.
 * Quét toàn bộ tài khoản nên một site đang hoạt động cũng dọn được gói cũ của site
 * khác cùng tài khoản (vd site nguồn đã ngừng sau khi chuyển xong).
 */
function sv_backup_expire_old( array $cfg ) {
	$objs = sv_s3_list_objects( $cfg, sv_backup_account_key() . '/' );
	if ( is_wp_error( $objs ) ) {
		return;
	}
	$cutoff = time() - ( SV_BACKUP_EXPIRE_DAYS * DAY_IN_SECONDS );
	foreach ( $objs as $o ) {
		$mtime = ! empty( $o['last_modified'] ) ? strtotime( $o['last_modified'] ) : 0;
		if ( $mtime && $mtime < $cutoff ) {
			sv_s3_delete_object_abs( $cfg, $o['key'] );
		}
	}
}

function sv_backup_log( $message ) {
	$logs = get_option( 'sv_backup_logs', array() );
	if ( ! is_array( $logs ) ) $logs = array();
	array_unshift( $logs, wp_date( 'Y-m-d H:i:s' ) . ' — ' . $message );
	$logs = array_slice( $logs, 0, 20 );
	update_option( 'sv_backup_logs', $logs );
}

/**
 * Log chẩn đoán RESTORE ghi ra FILE (không phải option): bước import DB ghi đè
 * wp_options nên log lưu trong option sẽ bị xóa giữa chừng. File nằm ngoài job dir
 * (sống sót qua dọn dẹp khi xong) trong thư mục đã niêm phong, đọc qua File Manager:
 *   wp-content/uploads/sitevorx-backup/restore-diag.log
 */
function sv_backup_restore_diag( $message ) {
	sv_backup_seal_tmp_root();
	@file_put_contents(
		sv_backup_tmp_root() . '/restore-diag.log',
		gmdate( 'c' ) . '  ' . $message . "\n",
		FILE_APPEND
	);
}

// =============================================================================
// AJAX — backup thủ công (chunked)
// =============================================================================

add_action( 'wp_ajax_sv_backup_start', 'sv_backup_ajax_start' );
function sv_backup_ajax_start() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
	}
	check_ajax_referer( 'sv_backup_nonce', 'nonce' );
	@set_time_limit( 0 );

	if ( ! class_exists( 'ZipArchive' ) ) {
		wp_send_json_error( array( 'message' => __( 'Host này thiếu PHP extension "zip" (ZipArchive) — bắt buộc để tạo/khôi phục gói. Hãy bật extension "zip" cho PHP rồi thử lại.', 'sitevorx' ) ), 500 );
	}

	$cfg = sv_s3_config();
	if ( is_wp_error( $cfg ) ) {
		wp_send_json_error( array( 'message' => $cfg->get_error_message() ), 400 );
	}

	$include = sanitize_key( wp_unslash( $_POST['include'] ?? get_option( 'sv_backup_include', 'both' ) ) );
	if ( ! in_array( $include, array( 'both', 'files', 'db' ), true ) ) $include = 'both';

	$job_id   = 'svbk' . strtolower( wp_generate_password( SV_BACKUP_TOKEN_LEN, false, false ) );
	$tmp_dir  = sv_backup_ensure_tmp_dir( $job_id );
	$zip_path = $tmp_dir . '/site.zip';
	$sql_path = $tmp_dir . '/database.sql';

	$files  = ( 'db' === $include ) ? array() : sv_backup_collect_files();
	$tables = ( 'files' === $include ) ? array() : sv_backup_collect_tables();

	@file_put_contents( $tmp_dir . '/files.json', wp_json_encode( $files ) );
	if ( 'files' !== $include ) {
		@file_put_contents( $sql_path, "-- Sitevorx Pro backup\n-- " . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\n\n" );
	}

	// Chẩn đoán: đếm số file theo từng thư mục con của wp-content (uploads/themes/
	// plugins/…) để dễ phát hiện nếu một nhánh KHÔNG được thu thập vào gói.
	$breakdown = sv_backup_files_breakdown( $files );
	if ( ! empty( $files ) ) {
		sv_backup_log( sprintf(
			__( 'Thu thập %d file: %s', 'sitevorx' ),
			count( $files ),
			sv_backup_breakdown_str( $breakdown )
		) );
	}

	$state = array(
		'id'          => $job_id,
		'tmp_dir'     => $tmp_dir,
		'zip_path'    => $zip_path,
		'sql_path'    => $sql_path,
		'files_path'  => $tmp_dir . '/files.json',
		'files_total' => count( $files ),
		'files_done'  => 0,
		'breakdown'   => $breakdown,
		'tables'      => $tables,
		'tables_done' => 0,
		'include'     => $include,
		'phase'       => ( 'db' === $include ) ? 'db' : 'files',
		'started_at'  => time(),
	);
	sv_backup_set_state( $job_id, $state );

	wp_send_json_success( array(
		'job_id'       => $job_id,
		'files_total'  => $state['files_total'],
		'tables_total' => count( $tables ),
	) );
}

add_action( 'wp_ajax_sv_backup_step', 'sv_backup_ajax_step' );
function sv_backup_ajax_step() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
	}
	check_ajax_referer( 'sv_backup_nonce', 'nonce' );
	@set_time_limit( 0 );

	$job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
	$state  = sv_backup_get_state( $job_id );
	if ( ! $state ) {
		wp_send_json_error( array( 'message' => __( 'Phiên backup đã hết hạn hoặc không tồn tại.', 'sitevorx' ) ), 404 );
	}

	$deadline = microtime( true ) + SV_BACKUP_STEP_BUDGET_SEC;
	$files    = null;

	// Phase MÃ HÓA: nén xong → mã hóa zip → zip.enc (chunked) → rồi mới upload.
	if ( 'encrypt' === $state['phase'] ) {
		$key = sv_backup_encryption_key();
		if ( null === $key ) {
			wp_send_json_error( array( 'message' => __( 'Không lấy được khóa mã hóa của tài khoản.', 'sitevorx' ) ), 500 );
		}
		$off  = (int) $state['enc_offset'];
		$done = sv_backup_encrypt_step( $state['plain_path'], $state['enc_path'], $off, $key, microtime( true ) + SV_BACKUP_CRYPT_BUDGET );
		if ( is_wp_error( $done ) ) {
			wp_send_json_error( array( 'message' => $done->get_error_message() ), 500 );
		}
		$state['enc_offset'] = $off;
		if ( true === $done ) {
			@unlink( $state['plain_path'] );           // xóa zip plaintext, chỉ giữ ciphertext
			$state['archive_path'] = $state['enc_path'];
			$cfg = sv_s3_config();
			if ( is_wp_error( $cfg ) ) {
				wp_send_json_error( array( 'message' => $cfg->get_error_message() ), 400 );
			}
			$init = sv_backup_init_upload( $cfg, $state );
			if ( is_wp_error( $init ) ) {
				wp_send_json_error( array( 'message' => $init->get_error_message() ), 500 );
			}
			$state['phase'] = 'upload';
			sv_backup_set_state( $job_id, $state );
			wp_send_json_success( array( 'phase' => 'upload', 'parts_total' => $state['parts_total'], 'parts_done' => 0, 'size' => size_format( $state['archive_size'] ) ) );
		}
		sv_backup_set_state( $job_id, $state );
		wp_send_json_success( array(
			'phase'     => 'encrypt',
			'enc_done'  => $state['enc_offset'],
			'enc_total' => isset( $state['enc_total'] ) ? $state['enc_total'] : 0,
		) );
	}

	while ( microtime( true ) < $deadline ) {
		if ( 'files' === $state['phase'] ) {
			if ( null === $files ) {
				$json  = ( ! empty( $state['files_path'] ) && file_exists( $state['files_path'] ) ) ? @file_get_contents( $state['files_path'] ) : '';
				$files = $json ? json_decode( $json, true ) : array();
				if ( ! is_array( $files ) ) $files = array();
			}
			$end = sv_backup_append_files_to_zip( $state['zip_path'], $files, $state['files_done'], SV_BACKUP_ZIP_BYTES_PER_STEP );
			if ( is_wp_error( $end ) ) {
				wp_send_json_error( array( 'message' => $end->get_error_message() ), 500 );
			}
			$state['files_done'] = $end;
			if ( $end >= $state['files_total'] ) {
				$state['phase'] = ( 'files' === $state['include'] ) ? 'finalize' : 'db';
			}
			break; // mỗi request chỉ MỞ/ĐÓNG zip 1 lần (close = ghi lại cả archive)
		} elseif ( 'db' === $state['phase'] ) {
			$idx = $state['tables_done'];
			if ( isset( $state['tables'][ $idx ] ) ) {
				$res = sv_backup_dump_table_to_file( $state['tables'][ $idx ], $state['sql_path'] );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ), 500 );
				}
				$state['tables_done'] = $idx + 1;
			}
			if ( $state['tables_done'] >= count( $state['tables'] ) ) {
				$state['phase'] = 'finalize';
				break;
			}
		} else {
			break;
		}
	}

	// Khi sang finalize: đóng gói; nếu bật mã hóa → sang phase encrypt, ngược lại
	// khởi tạo multipart luôn để bước upload bắt đầu.
	if ( 'finalize' === $state['phase'] ) {
		$final = sv_backup_finalize_archive( $state );
		if ( is_wp_error( $final ) ) {
			wp_send_json_error( array( 'message' => $final->get_error_message() ), 500 );
		}
		$cfg = sv_s3_config();
		if ( is_wp_error( $cfg ) ) {
			wp_send_json_error( array( 'message' => $cfg->get_error_message() ), 400 );
		}

		if ( sv_backup_is_encrypting() ) {
			$state['plain_path'] = $state['archive_path'];
			$state['enc_path']   = $state['archive_path'] . '.enc';
			$state['enc_offset'] = 0;
			$state['enc_total']  = (int) filesize( $state['archive_path'] );
			$state['s3_key']     = $state['s3_key'] . '.enc';
			$state['phase']      = 'encrypt';
			sv_backup_set_state( $job_id, $state );
			wp_send_json_success( array( 'phase' => 'encrypt', 'enc_done' => 0, 'enc_total' => $state['enc_total'] ) );
		}

		$init = sv_backup_init_upload( $cfg, $state );
		if ( is_wp_error( $init ) ) {
			wp_send_json_error( array( 'message' => $init->get_error_message() ), 500 );
		}
		$state['phase'] = 'upload';
		sv_backup_set_state( $job_id, $state );
		wp_send_json_success( array(
			'phase'        => 'upload',
			'files_done'   => $state['files_done'],
			'files_total'  => $state['files_total'],
			'tables_done'  => $state['tables_done'],
			'tables_total' => count( $state['tables'] ),
			'parts_total'  => $state['parts_total'],
			'parts_done'   => 0,
			'size'         => size_format( $state['archive_size'] ),
		) );
	}

	sv_backup_set_state( $job_id, $state );
	wp_send_json_success( array(
		'phase'        => $state['phase'],
		'files_done'   => $state['files_done'],
		'files_total'  => $state['files_total'],
		'tables_done'  => $state['tables_done'],
		'tables_total' => count( $state['tables'] ),
	) );
}

add_action( 'wp_ajax_sv_backup_upload', 'sv_backup_ajax_upload' );
function sv_backup_ajax_upload() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
	}
	check_ajax_referer( 'sv_backup_nonce', 'nonce' );
	@set_time_limit( 0 );

	$job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
	$state  = sv_backup_get_state( $job_id );
	if ( ! $state || empty( $state['upload_id'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Phiên upload không tồn tại.', 'sitevorx' ) ), 404 );
	}

	$cfg = sv_s3_config();
	if ( is_wp_error( $cfg ) ) {
		wp_send_json_error( array( 'message' => $cfg->get_error_message() ), 400 );
	}

	// Upload NHIỀU part trong 1 request tới khi gần hết budget — thay vì 1 part/request
	// (3GB/16MB ≈ 192 part = 192 round-trip). Mỗi part vẫn là 1 PUT lên S3.
	$deadline = microtime( true ) + SV_BACKUP_STEP_BUDGET_SEC;
	while ( true ) {
		$r = sv_backup_upload_next_part( $cfg, $state );
		if ( is_wp_error( $r ) ) {
			sv_backup_set_state( $job_id, $state );
			wp_send_json_error( array( 'message' => $r->get_error_message() ), 500 );
		}

		if ( 'done' === $r ) {
			$state['phase'] = 'done';
			sv_backup_set_state( $job_id, $state );
			sv_backup_expire_old( $cfg ); // dọn gói bỏ dở quá hạn
			update_option( 'sv_backup_last', array( 'ts' => time(), 'key' => $state['s3_key'], 'size' => $state['archive_size'] ) );
			sv_backup_log( sprintf( __( 'Tạo gói di chuyển thành công: %s (%s)', 'sitevorx' ), $state['s3_key'], size_format( $state['archive_size'] ) ) );
			if ( function_exists( 'sv_audit_log' ) ) {
				sv_audit_log( 'backup_uploaded', array( 'key' => $state['s3_key'], 'size' => $state['archive_size'] ) );
			}
			// Dọn file local, giữ state để client biết đã xong.
			sv_backup_rm_rf( $state['tmp_dir'] );
			wp_send_json_success( array( 'phase' => 'done', 'key' => $state['s3_key'], 'parts_done' => $state['parts_total'], 'parts_total' => $state['parts_total'], 'size' => size_format( $state['archive_size'] ) ) );
		}

		if ( microtime( true ) >= $deadline ) {
			break;
		}
	}

	sv_backup_set_state( $job_id, $state );
	wp_send_json_success( array(
		'phase'       => 'upload',
		'parts_done'  => count( $state['parts'] ),
		'parts_total' => $state['parts_total'],
		'size'        => size_format( $state['archive_size'] ),
	) );
}

add_action( 'wp_ajax_sv_backup_cancel', 'sv_backup_ajax_cancel' );
function sv_backup_ajax_cancel() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
	}
	check_ajax_referer( 'sv_backup_nonce', 'nonce' );
	$job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
	$state  = sv_backup_get_state( $job_id );
	if ( $state ) {
		if ( ! empty( $state['upload_id'] ) ) {
			$cfg = sv_s3_config();
			if ( ! is_wp_error( $cfg ) ) {
				sv_s3_abort_multipart( $cfg, $state['s3_key'], $state['upload_id'] );
			}
		}
		if ( ! empty( $state['tmp_dir'] ) ) {
			sv_backup_rm_rf( $state['tmp_dir'] );
		}
		sv_backup_del_state( $job_id );
	}
	wp_send_json_success();
}

// =============================================================================
// AJAX — Test kết nối + danh sách backup
// =============================================================================

add_action( 'wp_ajax_sv_backup_test_connection', 'sv_backup_ajax_test_connection' );
function sv_backup_ajax_test_connection() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
	}
	check_ajax_referer( 'sv_backup_nonce', 'nonce' );
	$cfg = sv_s3_config();
	if ( is_wp_error( $cfg ) ) {
		wp_send_json_error( array( 'message' => $cfg->get_error_message() ) );
	}
	$res = sv_s3_head_bucket( $cfg );
	if ( is_wp_error( $res ) ) {
		wp_send_json_error( array( 'message' => $res->get_error_message() ) );
	}
	wp_send_json_success( array( 'message' => __( 'Kết nối S3 thành công!', 'sitevorx' ) ) );
}

add_action( 'wp_ajax_sv_backup_clear_logs', 'sv_backup_ajax_clear_logs' );
function sv_backup_ajax_clear_logs() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
	}
	check_ajax_referer( 'sv_backup_nonce', 'nonce' );
	delete_option( 'sv_backup_logs' ); // option theo từng site → chỉ xóa nhật ký của site này.
	wp_send_json_success();
}

add_action( 'wp_ajax_sv_backup_list', 'sv_backup_ajax_list' );
function sv_backup_ajax_list() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
	}
	check_ajax_referer( 'sv_backup_nonce', 'nonce' );
	$cfg = sv_s3_config();
	if ( is_wp_error( $cfg ) ) {
		wp_send_json_error( array( 'message' => $cfg->get_error_message() ) );
	}
	// Liệt kê theo TÀI KHOẢN (mọi site của khách) để hỗ trợ clone xuyên tên miền.
	$objs = sv_s3_list_objects( $cfg, sv_backup_account_key() . '/' );
	if ( is_wp_error( $objs ) ) {
		wp_send_json_error( array( 'message' => $objs->get_error_message() ) );
	}
	usort( $objs, function( $a, $b ) { return strcmp( $b['key'], $a['key'] ); } );
	$current_site = sv_backup_host_slug();
	$out = array();
	foreach ( $objs as $o ) {
		// Cấu trúc key: [prefix/]{account}/{domain}/{file}.zip
		$site = basename( dirname( $o['key'] ) );
		$out[] = array(
			'key'           => $o['key'],
			'name'          => basename( $o['key'] ),
			'site'          => $site,
			'is_current'    => ( $site === $current_site ),
			'size'          => size_format( $o['size'] ),
			'last_modified' => $o['last_modified'],
		);
	}
	wp_send_json_success( array( 'backups' => $out ) );
}

// =============================================================================
// Lưu cấu hình (kết nối + lịch)
// =============================================================================

add_action( 'admin_init', function() {
	if ( ! isset( $_POST['sv_save_s3_connection'] ) ) return;
	if ( ! current_user_can( 'manage_options' ) ) return;
	if ( ! check_admin_referer( 'sv_backup_settings_nonce' ) ) return;

	update_option( 'sv_s3_enabled', isset( $_POST['s3_enabled'] ) ? '1' : '0' );
	update_option( 'sv_s3_endpoint', esc_url_raw( wp_unslash( $_POST['s3_endpoint'] ?? '' ) ) );
	update_option( 'sv_s3_region', sanitize_text_field( wp_unslash( $_POST['s3_region'] ?? '' ) ) );
	update_option( 'sv_s3_bucket', sanitize_text_field( wp_unslash( $_POST['s3_bucket'] ?? '' ) ) );
	update_option( 'sv_s3_prefix', sanitize_text_field( wp_unslash( $_POST['s3_prefix'] ?? '' ) ) );
	update_option( 'sv_s3_path_style', isset( $_POST['s3_path_style'] ) ? '1' : '0' );

	// Key/secret: chỉ ghi đè khi người dùng nhập giá trị mới (tránh xóa khi để trống).
	$access = trim( (string) wp_unslash( $_POST['s3_access_key'] ?? '' ) );
	$secret = trim( (string) wp_unslash( $_POST['s3_secret_key'] ?? '' ) );
	if ( '' !== $access ) {
		update_option( 'sv_s3_access_key', sv_encrypt( $access ) );
	}
	if ( '' !== $secret ) {
		update_option( 'sv_s3_secret_key', sv_encrypt( $secret ) );
	}

	if ( function_exists( 'sv_audit_log' ) ) {
		sv_audit_log( 'backup_s3_settings_saved', array( 'bucket' => get_option( 'sv_s3_bucket' ) ) );
	}
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . esc_html__( 'Đã lưu cấu hình kết nối S3.', 'sitevorx' ) . '</p></div>';
	} );
} );

// =============================================================================
// Công cụ DI CHUYỂN (migrate), KHÔNG có lịch tự động/retention. Gói tạo thủ công,
// xóa sau khi restore xong, và tự hết hạn sau SV_BACKUP_EXPIRE_DAYS ngày.
// (Lịch tự động đã được gỡ; legacy cron sv_backup_event được dọn ở deactivation/uninstall.)
// =============================================================================

// Giữ định nghĩa cũ ở dạng không dùng để tương thích; KHÔNG đăng ký vào cron nữa.
function sv_backup_run_scheduled() {
	return; // no-op: lịch tự động đã gỡ; phần thân bên dưới không còn chạy.
	@set_time_limit( 0 );

	$cfg = sv_s3_config();
	if ( is_wp_error( $cfg ) ) {
		sv_backup_log( __( 'Backup tự động BỎ QUA: ', 'sitevorx' ) . $cfg->get_error_message() );
		return;
	}

	$include  = get_option( 'sv_backup_include', 'both' );
	$job_id   = 'svcron' . strtolower( wp_generate_password( SV_BACKUP_TOKEN_LEN, false, false ) );
	$tmp_dir  = sv_backup_ensure_tmp_dir( $job_id );
	$state    = array(
		'id'          => $job_id,
		'tmp_dir'     => $tmp_dir,
		'zip_path'    => $tmp_dir . '/site.zip',
		'sql_path'    => $tmp_dir . '/database.sql',
		'files_total' => 0,
		'tables'      => array(),
		'include'     => $include,
	);

	// Build files.
	if ( 'db' !== $include ) {
		$files               = sv_backup_collect_files();
		$state['files_total'] = count( $files );
		$offset              = 0;
		while ( $offset < count( $files ) ) {
			$end = sv_backup_append_files_to_zip( $state['zip_path'], $files, $offset, SV_BACKUP_ZIP_BYTES_PER_STEP );
			if ( is_wp_error( $end ) ) {
				sv_backup_rm_rf( $tmp_dir );
				sv_backup_log( __( 'Backup tự động THẤT BẠI khi nén file: ', 'sitevorx' ) . $end->get_error_message() );
				return;
			}
			$offset = $end;
		}
	}
	// Build DB.
	if ( 'files' !== $include ) {
		@file_put_contents( $state['sql_path'], "-- Sitevorx Pro backup\n-- " . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\n\n" );
		$state['tables'] = sv_backup_collect_tables();
		foreach ( $state['tables'] as $t ) {
			$res = sv_backup_dump_table_to_file( $t, $state['sql_path'] );
			if ( is_wp_error( $res ) ) {
				sv_backup_rm_rf( $tmp_dir );
				sv_backup_log( __( 'Backup tự động THẤT BẠI khi dump DB: ', 'sitevorx' ) . $res->get_error_message() );
				return;
			}
		}
	}

	$final = sv_backup_finalize_archive( $state );
	if ( is_wp_error( $final ) ) {
		sv_backup_rm_rf( $tmp_dir );
		sv_backup_log( __( 'Backup tự động THẤT BẠI khi đóng gói: ', 'sitevorx' ) . $final->get_error_message() );
		return;
	}

	// Mã hóa (nếu bật) trước khi upload — chạy blocking trong cron.
	$enc_key = sv_backup_encryption_key();
	if ( null !== $enc_key ) {
		$enc_path = $state['archive_path'] . '.enc';
		$off      = 0;
		$r        = sv_backup_encrypt_step( $state['archive_path'], $enc_path, $off, $enc_key, microtime( true ) + 3600 );
		if ( is_wp_error( $r ) ) {
			sv_backup_rm_rf( $tmp_dir );
			sv_backup_log( __( 'Backup tự động THẤT BẠI khi mã hóa: ', 'sitevorx' ) . $r->get_error_message() );
			return;
		}
		@unlink( $state['archive_path'] );
		$state['archive_path'] = $enc_path;
		$state['s3_key']       = $state['s3_key'] . '.enc';
	}

	$key = sv_backup_upload_file_blocking( $cfg, $state );
	sv_backup_rm_rf( $tmp_dir );
	if ( is_wp_error( $key ) ) {
		sv_backup_log( __( 'Backup tự động THẤT BẠI khi upload S3: ', 'sitevorx' ) . $key->get_error_message() );
		if ( function_exists( 'sv_audit_log' ) ) {
			sv_audit_log( 'backup_failed', array( 'error' => $key->get_error_message() ) );
		}
		return;
	}

	sv_backup_expire_old( $cfg );
	update_option( 'sv_backup_last', array( 'ts' => time(), 'key' => $key, 'size' => $state['archive_size'] ?? 0 ) );
	sv_backup_log( sprintf( __( 'Backup tự động thành công: %s', 'sitevorx' ), $key ) );
	if ( function_exists( 'sv_audit_log' ) ) {
		sv_audit_log( 'backup_run', array( 'key' => $key ) );
	}
}

register_deactivation_hook(
	defined( 'SV_PLUGIN_FILE' ) ? SV_PLUGIN_FILE : SV_PLUGIN_DIR . 'sitevorx-pro.php',
	function() {
		wp_clear_scheduled_hook( 'sv_backup_event' );
	}
);

// =============================================================================
// GC sweep — dọn working dir bỏ rơi (như migrate cũ)
// =============================================================================

add_action( 'sv_backup_gc_event', 'sv_backup_gc_sweep' );
function sv_backup_gc_sweep() {
	// 1) Dọn gói migrate quá hạn trên S3 (bỏ dở, không restore).
	$cfg = sv_s3_config();
	if ( ! is_wp_error( $cfg ) ) {
		sv_backup_expire_old( $cfg );
	}

	// 2) Dọn thư mục tạm cục bộ bị bỏ rơi.
	$root = wp_normalize_path( sv_backup_tmp_root() );
	if ( ! is_dir( $root ) ) return;
	$cutoff = time() - SV_BACKUP_JOB_TTL;
	foreach ( glob( $root . '/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
		$state = sv_backup_get_state( basename( $dir ) );
		if ( $state && isset( $state['phase'] ) && 'done' !== $state['phase'] ) {
			continue;
		}
		$newest = (int) @filemtime( $dir );
		foreach ( glob( $dir . '/*' ) ?: array() as $f ) {
			$m = (int) @filemtime( $f );
			if ( $m > $newest ) $newest = $m;
		}
		if ( $newest && $newest < $cutoff ) {
			sv_backup_rm_rf( $dir );
		}
	}
}

add_action( 'admin_init', function() {
	if ( ! wp_next_scheduled( 'sv_backup_gc_event' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sv_backup_gc_event' );
	}
} );

// =============================================================================
// Trang quản trị
// =============================================================================

function sv_display_backup_page() {
	$nonce       = wp_create_nonce( 'sv_backup_nonce' );
	$enabled     = get_option( 'sv_s3_enabled', '0' );
	$endpoint    = get_option( 'sv_s3_endpoint', '' );
	$region      = get_option( 'sv_s3_region', '' );
	$bucket      = get_option( 'sv_s3_bucket', '' );
	$prefix      = get_option( 'sv_s3_prefix', '' );
	$path_style  = get_option( 'sv_s3_path_style', '1' );
	$has_access  = '' !== (string) get_option( 'sv_s3_access_key', '' );
	$has_secret  = '' !== (string) get_option( 'sv_s3_secret_key', '' );

	$sch_enabled = get_option( 'sv_backup_schedule_enabled', '0' );
	$frequency   = get_option( 'sv_backup_frequency', 'weekly' );
	$retention   = (int) get_option( 'sv_backup_retention', 7 );
	$include     = get_option( 'sv_backup_include', 'both' );
	$next_run    = wp_next_scheduled( 'sv_backup_event' );
	$last        = get_option( 'sv_backup_last', array() );
	$logs        = get_option( 'sv_backup_logs', array() );

	$freq_labels = array(
		'daily'      => __( 'Hàng ngày', 'sitevorx' ),
		'twicedaily' => __( '2 lần/ngày', 'sitevorx' ),
		'weekly'     => __( 'Hàng tuần', 'sitevorx' ),
	);
	$inc_labels = array(
		'both'  => __( 'Database + File/Media', 'sitevorx' ),
		'files' => __( 'Chỉ File/Media', 'sitevorx' ),
		'db'    => __( 'Chỉ Database', 'sitevorx' ),
	);
	?>
	<style>
		.sv-mig-steps{display:flex;align-items:stretch;gap:10px;flex-wrap:wrap;margin-top:16px}
		.sv-mig-step{flex:1;min-width:210px;display:flex;gap:12px;align-items:flex-start;padding:13px 15px;background:var(--sv-bg);border:1px solid var(--sv-border);border-radius:var(--sv-radius-sm)}
		.sv-mig-step .n{flex:none;width:26px;height:26px;border-radius:50%;background:var(--sv-primary);color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center}
		.sv-mig-step b{display:block;font-size:13.5px;color:var(--sv-text);margin-bottom:2px}
		.sv-mig-step small{font-size:12px;color:var(--sv-text-secondary);line-height:1.45}
		.sv-mig-arrow{display:flex;align-items:center;color:var(--sv-text-secondary)}
		@media(max-width:782px){.sv-mig-arrow{transform:rotate(90deg)}}
		.sv-step-badge{margin-left:auto;display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;letter-spacing:.02em;color:var(--sv-primary);background:var(--sv-bg);border:1px solid var(--sv-border);padding:3px 11px;border-radius:999px}
		.sv-progress{background:var(--sv-bg);border:1px solid var(--sv-border);border-radius:999px;overflow:hidden;height:14px}
		.sv-progress > div{height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,var(--sv-primary),var(--sv-green));transition:width .3s ease}
		.sv-note{margin:10px 0 0;padding:12px 14px;background:var(--sv-bg);border:1px solid var(--sv-border);border-left:3px solid var(--sv-cyan);color:var(--sv-text-secondary);border-radius:var(--sv-radius-xs);font-size:13px;line-height:1.55}
		.sv-alert{display:none;margin-top:16px;padding:13px 15px;border-radius:var(--sv-radius-sm);font-size:13px;line-height:1.55}
		.sv-alert-ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
		.sv-alert-err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
		.sv-restore-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
		.sv-restore-row input{flex:1;min-width:300px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
	</style>
	<div class="sv-app-wrapper">
		<div class="sv-app-container">
			<?php sv_render_sidebar( 'backup' ); ?>
			<div class="sv-content-area">

				<div class="sv-top-banner">
					<h2><?php esc_html_e( 'Di Chuyển Website', 'sitevorx' ); ?></h2>
					<p><?php esc_html_e( 'Chuyển toàn bộ website sang hosting mới mà KHÔNG cần tải dữ liệu về máy — dữ liệu tự đi thẳng từ hosting cũ sang hosting mới, chỉ 2 bước. Bản chuyển chỉ là tạm thời: tự xóa sau khi khôi phục xong, hoặc tự hết hạn sau 3 ngày nếu không dùng.', 'sitevorx' ); ?></p>
					<div class="sv-mig-steps">
						<div class="sv-mig-step"><span class="n">1</span><div><b><?php esc_html_e( 'Ở hosting CŨ — Tạo gói', 'sitevorx' ); ?></b><small><?php esc_html_e( 'Đóng gói site hiện tại và đưa lên kho, nhận được "Mã di chuyển".', 'sitevorx' ); ?></small></div></div>
						<div class="sv-mig-arrow"><span class="dashicons dashicons-arrow-right-alt"></span></div>
						<div class="sv-mig-step"><span class="n">2</span><div><b><?php esc_html_e( 'Ở hosting MỚI — Khôi phục', 'sitevorx' ); ?></b><small><?php esc_html_e( 'Dán mã hoặc chọn trong danh sách để phục hồi sang hosting mới.', 'sitevorx' ); ?></small></div></div>
					</div>
				</div>

				<!-- Kết nối S3 -->
				<?php if ( sv_s3_is_managed() ) : ?>
				<!-- iNET-managed storage: pre-configured, hidden from customer -->
				<?php else : ?>
				<form method="POST">
					<?php wp_nonce_field( 'sv_backup_settings_nonce' ); ?>
					<div class="sv-content-box">
						<div class="sv-box-header">
							<span class="dashicons dashicons-cloud" style="color:var(--sv-primary);"></span>
							<h3><?php esc_html_e( 'Kết nối kho lưu trữ S3', 'sitevorx' ); ?></h3>
						</div>
						<div style="padding:20px;">
							<div class="sv-form-group">
								<div class="sv-form-label"><strong><?php esc_html_e( 'Kích hoạt backup S3', 'sitevorx' ); ?></strong></div>
								<div class="sv-form-input">
									<label class="sv-switch"><input type="checkbox" name="s3_enabled" value="1" <?php checked( $enabled, '1' ); ?>><span class="sv-slider"></span></label>
								</div>
							</div>
							<table class="form-table">
								<tr><th><?php esc_html_e( 'Endpoint', 'sitevorx' ); ?></th><td><input type="text" name="s3_endpoint" value="<?php echo esc_attr( $endpoint ); ?>" class="regular-text" placeholder="http://103.216.116.22:8000"><p class="description"><?php esc_html_e( 'Nhập đầy đủ scheme — http:// hoặc https:// (gateway nội bộ có thể chạy HTTP và cổng riêng, ví dụ :8000).', 'sitevorx' ); ?></p></td></tr>
								<tr><th><?php esc_html_e( 'Region', 'sitevorx' ); ?></th><td><input type="text" name="s3_region" value="<?php echo esc_attr( $region ); ?>" class="regular-text" placeholder="us-east-1"><p class="description"><?php esc_html_e( 'Để trống sẽ dùng us-east-1. RadosGW/Ceph thường chấp nhận us-east-1.', 'sitevorx' ); ?></p></td></tr>
								<tr><th><?php esc_html_e( 'Bucket', 'sitevorx' ); ?></th><td><input type="text" name="s3_bucket" value="<?php echo esc_attr( $bucket ); ?>" class="regular-text"></td></tr>
								<tr><th><?php esc_html_e( 'Prefix (thư mục)', 'sitevorx' ); ?></th><td><input type="text" name="s3_prefix" value="<?php echo esc_attr( $prefix ); ?>" class="regular-text" placeholder="sitevorx-backups"></td></tr>
								<tr><th><?php esc_html_e( 'Access Key', 'sitevorx' ); ?></th><td><input type="text" name="s3_access_key" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $has_access ? esc_attr__( '•••••• (giữ nguyên nếu để trống)', 'sitevorx' ) : ''; ?>"></td></tr>
								<tr><th><?php esc_html_e( 'Secret Key', 'sitevorx' ); ?></th><td><input type="password" name="s3_secret_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_secret ? esc_attr__( '•••••• (giữ nguyên nếu để trống)', 'sitevorx' ) : ''; ?>"></td></tr>
								<tr><th><?php esc_html_e( 'Path-style', 'sitevorx' ); ?></th><td><label><input type="checkbox" name="s3_path_style" value="1" <?php checked( $path_style, '1' ); ?>> <?php esc_html_e( 'Bật (khuyến nghị cho S3 nội bộ/MinIO)', 'sitevorx' ); ?></label></td></tr>
							</table>
							<div class="sv-form-footer" style="display:flex; gap:10px; align-items:center;">
								<button type="submit" name="sv_save_s3_connection" class="button button-primary"><?php esc_html_e( 'Lưu kết nối', 'sitevorx' ); ?></button>
								<button type="button" id="sv-backup-test" class="button"><?php esc_html_e( 'Test kết nối', 'sitevorx' ); ?></button>
								<span id="sv-backup-test-result" style="font-weight:600;"></span>
							</div>
						</div>
					</div>
				</form>
				<?php endif; ?>

				<!-- Bước 1: Tạo gói (máy cũ) -->
				<div class="sv-content-box">
					<div class="sv-box-header">
						<span class="dashicons dashicons-database-export" style="color:var(--sv-green);"></span>
						<h3><?php esc_html_e( 'Tạo gói di chuyển', 'sitevorx' ); ?></h3>
						<span class="sv-step-badge"><?php esc_html_e( 'Bước 1 · Hosting cũ', 'sitevorx' ); ?></span>
					</div>
					<div style="padding:20px;">
						<p style="color:var(--sv-text-secondary); margin-top:0;"><?php esc_html_e( 'Chạy ở website nguồn (hosting cũ): đóng gói toàn bộ site và đưa lên kho trung chuyển của iNET. Quá trình chạy theo lô nên không bị timeout, upload nhiều phần có thể chạy lại nếu gián đoạn.', 'sitevorx' ); ?></p>
						<button id="sv-backup-start" class="button button-primary button-large"><?php esc_html_e( 'Tạo gói di chuyển', 'sitevorx' ); ?></button>
						<div id="sv-backup-progress" style="display:none; margin-top:20px;">
							<div class="sv-progress"><div id="sv-backup-bar"></div></div>
							<p id="sv-backup-text" style="margin-top:8px; color:var(--sv-text-secondary);"></p>
						</div>
						<div id="sv-backup-done" class="sv-alert sv-alert-ok"></div>
						<div id="sv-backup-err" class="sv-alert sv-alert-err"></div>
					</div>
				</div>

				<!-- Bước 2: Khôi phục (máy mới) -->
				<div class="sv-content-box">
					<div class="sv-box-header">
						<span class="dashicons dashicons-migrate" style="color:var(--sv-red);"></span>
						<h3><?php esc_html_e( 'Khôi phục từ mã di chuyển', 'sitevorx' ); ?></h3>
						<span class="sv-step-badge"><?php esc_html_e( 'Bước 2 · Hosting mới', 'sitevorx' ); ?></span>
					</div>
					<div style="padding:20px;">
						<p style="color:var(--sv-text-secondary); margin-top:0;"><?php esc_html_e( 'Chuyển từ hosting khác (khác tài khoản)? Ở hosting cũ, copy "Mã di chuyển" của gói (nút Copy mã trong danh sách bên dưới, hoặc ngay sau khi tạo gói), rồi dán vào đây để khôi phục — không cần cùng tài khoản hosting.', 'sitevorx' ); ?></p>
						<div class="sv-restore-row">
							<input type="text" id="sv-restore-code" class="regular-text" placeholder="<?php esc_attr_e( 'Dán mã di chuyển ở đây…', 'sitevorx' ); ?>">
							<button type="button" id="sv-restore-by-code" class="button button-primary"><?php esc_html_e( 'Khôi phục từ mã', 'sitevorx' ); ?></button>
						</div>
					</div>
				</div>

				<!-- Danh sách backup trên S3 -->
				<div class="sv-content-box">
					<div class="sv-box-header">
						<span class="dashicons dashicons-backup" style="color:var(--sv-purple);"></span>
						<h3><?php esc_html_e( 'Gói di chuyển trên kho', 'sitevorx' ); ?></h3>
						<button type="button" id="sv-backup-refresh" class="button" style="margin-left:auto;"><?php esc_html_e( 'Tải danh sách', 'sitevorx' ); ?></button>
					</div>
					<div style="padding:0 20px 10px;">
						<p class="sv-note">
							<span class="dashicons dashicons-info-outline" style="color:var(--sv-cyan);"></span>
							<?php esc_html_e( 'Chạy ở website đích (hosting mới): "Khôi phục" sẽ tải gói về và THAY THẾ toàn bộ site hiện tại bằng dữ liệu trong gói, tự đổi tên miền nếu khác. Khôi phục xong, gói sẽ tự xóa khỏi kho. Gói không dùng tự hết hạn sau 3 ngày.', 'sitevorx' ); ?>
						</p>
						<table class="widefat striped" id="sv-backup-list" style="margin-top:10px;">
							<thead><tr><th><?php esc_html_e( 'Site nguồn', 'sitevorx' ); ?></th><th><?php esc_html_e( 'Tên file', 'sitevorx' ); ?></th><th><?php esc_html_e( 'Dung lượng', 'sitevorx' ); ?></th><th><?php esc_html_e( 'Thời gian', 'sitevorx' ); ?></th><th><?php esc_html_e( 'Thao tác', 'sitevorx' ); ?></th></tr></thead>
							<tbody><tr><td colspan="5" style="color:#888;"><?php esc_html_e( 'Bấm "Tải danh sách" để xem các bản backup.', 'sitevorx' ); ?></td></tr></tbody>
						</table>
					</div>
				</div>

				<?php if ( ! empty( $logs ) ) : ?>
				<div class="sv-content-box">
					<div class="sv-box-header"><span class="dashicons dashicons-media-text" style="color:var(--sv-purple);"></span><h3><?php esc_html_e( 'Nhật ký sao lưu', 'sitevorx' ); ?></h3>
						<button type="button" id="sv-backup-clear-logs" class="button" style="margin-left:auto;"><?php esc_html_e( 'Xóa nhật ký', 'sitevorx' ); ?></button>
					</div>
					<p style="margin:10px 20px 0; color:var(--sv-text-secondary); font-size:12px;"><?php esc_html_e( 'Nhật ký chỉ của riêng website này.', 'sitevorx' ); ?></p>
					<div style="padding:4px 20px 14px; max-height:172px; overflow-y:auto;" id="sv-backup-logs-list">
						<?php foreach ( $logs as $log ) : ?><div style="padding:3px 0; border-bottom:1px solid var(--sv-line2,#f1f2f5); font-size:12px; line-height:1.45; color:var(--sv-text-secondary); font-variant-numeric:tabular-nums;"><?php echo esc_html( $log ); ?></div><?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>

			</div>
		</div>
	</div>

	<script>
	(function($){
		var nonce = '<?php echo esc_js( $nonce ); ?>';
		var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
		var jobId = null, filesTotal = 0, tablesTotal = 0, partsTotal = 0, pkgSize = '';

		function call(action, data){ data = data || {}; data.action = action; data.nonce = nonce; return $.post(ajaxurl, data); }
		function err(m){ $('#sv-backup-progress').hide(); $('#sv-backup-err').text(m).show(); $('#sv-backup-start').prop('disabled', false); }
		function setBar(pct, label){ $('#sv-backup-bar').css('width', Math.min(100, pct) + '%'); $('#sv-backup-text').text(label + ' — ' + Math.round(pct) + '%'); }

		// Test kết nối
		$('#sv-backup-test').on('click', function(){
			var $r = $('#sv-backup-test-result').css('color', '#555').text('<?php echo esc_js( __( 'Đang kiểm tra...', 'sitevorx' ) ); ?>');
			call('sv_backup_test_connection').done(function(resp){
				if (resp && resp.success){ $r.css('color', '#1b5e20').text('✔ ' + resp.data.message); }
				else { $r.css('color', '#b71c1c').text('✖ ' + (resp && resp.data ? resp.data.message : 'Lỗi')); }
			}).fail(function(){ $r.css('color', '#b71c1c').text('✖ <?php echo esc_js( __( 'Không gọi được server.', 'sitevorx' ) ); ?>'); });
		});

		function buildLoop(){
			call('sv_backup_step', { job_id: jobId }).done(function(resp){
				if (!resp || !resp.success){ err(resp && resp.data ? resp.data.message : 'Lỗi đóng gói.'); return; }
				var d = resp.data;
				if (d.phase === 'upload'){ partsTotal = d.parts_total; pkgSize = d.size || pkgSize; setBar(50, '<?php echo esc_js( __( 'Đã đóng gói', 'sitevorx' ) ); ?>' + (pkgSize ? ' · ' + pkgSize : '') + ' — <?php echo esc_js( __( 'bắt đầu tải lên', 'sitevorx' ) ); ?>'); uploadLoop(); return; }
				if (d.phase === 'encrypt'){ var er = d.enc_total ? (d.enc_done / d.enc_total) : 0; setBar(40 + 10 * er, '<?php echo esc_js( __( 'Đang mã hóa', 'sitevorx' ) ); ?>'); setTimeout(buildLoop, 50); return; }
				var doneUnits = d.files_done + d.tables_done;
				var totalUnits = (filesTotal + tablesTotal) || 1;
				setBar(doneUnits * 40 / totalUnits, d.phase === 'files' ? '<?php echo esc_js( __( 'Đang nén file', 'sitevorx' ) ); ?>' : '<?php echo esc_js( __( 'Đang xuất database', 'sitevorx' ) ); ?>');
				setTimeout(buildLoop, 50);
			}).fail(function(){ err('<?php echo esc_js( __( 'Mất kết nối khi đóng gói.', 'sitevorx' ) ); ?>'); });
		}
		function uploadLoop(){
			call('sv_backup_upload', { job_id: jobId }).done(function(resp){
				if (!resp || !resp.success){ err(resp && resp.data ? resp.data.message : 'Lỗi upload.'); return; }
				var d = resp.data;
				pkgSize = d.size || pkgSize;
				var pct = 50 + (partsTotal ? (d.parts_done * 50 / partsTotal) : 50);
				setBar(pct, '<?php echo esc_js( __( 'Đang tải lên', 'sitevorx' ) ); ?>' + (pkgSize ? ' · ' + pkgSize : '') + ' (' + d.parts_done + '/' + d.parts_total + ')');
				if (d.phase === 'done'){
					$('#sv-backup-progress').hide();
					$('#sv-backup-done').html('<strong><?php echo esc_js( __( 'Đã tạo gói di chuyển!', 'sitevorx' ) ); ?></strong>' + (pkgSize ? ' <span style="color:var(--sv-text-secondary)">(<?php echo esc_js( __( 'dung lượng', 'sitevorx' ) ); ?>: ' + esc(pkgSize) + ')</span>' : '') + '<br><?php echo esc_js( __( 'Mã di chuyển (copy để khôi phục ở máy mới):', 'sitevorx' ) ); ?><br><code style="word-break:break-all;">' + esc(d.key) + '</code> <button type="button" class="button button-small sv-copy-code" data-code="' + encodeURIComponent(d.key) + '"><?php echo esc_js( __( 'Copy mã', 'sitevorx' ) ); ?></button>').show();
					$('#sv-backup-start').prop('disabled', false);
					$('#sv-backup-refresh').trigger('click');
				} else { setTimeout(uploadLoop, 50); }
			}).fail(function(){ err('<?php echo esc_js( __( 'Mất kết nối khi upload.', 'sitevorx' ) ); ?>'); });
		}

		$('#sv-backup-start').on('click', function(){
			$('#sv-backup-err,#sv-backup-done').hide();
			$(this).prop('disabled', true);
			$('#sv-backup-progress').show(); setBar(0, '<?php echo esc_js( __( 'Đang khởi tạo', 'sitevorx' ) ); ?>');
			call('sv_backup_start').done(function(resp){
				if (!resp || !resp.success){ err(resp && resp.data ? resp.data.message : 'Không khởi tạo được.'); return; }
				jobId = resp.data.job_id; filesTotal = resp.data.files_total; tablesTotal = resp.data.tables_total;
				buildLoop();
			}).fail(function(){ err('<?php echo esc_js( __( 'Không gọi được endpoint khởi tạo.', 'sitevorx' ) ); ?>'); });
		});

		// Danh sách backup
		function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
		$('#sv-backup-refresh').on('click', function(){
			var $tb = $('#sv-backup-list tbody').html('<tr><td colspan="5"><?php echo esc_js( __( 'Đang tải...', 'sitevorx' ) ); ?></td></tr>');
			call('sv_backup_list').done(function(resp){
				if (!resp || !resp.success){ $tb.html('<tr><td colspan="5" style="color:#b71c1c;">' + esc(resp && resp.data ? resp.data.message : 'Lỗi') + '</td></tr>'); return; }
				var rows = resp.data.backups || [];
				if (!rows.length){ $tb.html('<tr><td colspan="5" style="color:#888;"><?php echo esc_js( __( 'Chưa có bản backup nào.', 'sitevorx' ) ); ?></td></tr>'); return; }
				var curBadge = ' <span style="background:#e8f5e9;color:#1b5e20;border-radius:3px;padding:1px 6px;font-size:11px;"><?php echo esc_js( __( 'site này', 'sitevorx' ) ); ?></span>';
				var html = '';
				rows.forEach(function(b){
					html += '<tr><td>' + esc(b.site) + (b.is_current ? curBadge : '') + '</td><td>' + esc(b.name) + '</td><td>' + esc(b.size) + '</td><td>' + esc(b.last_modified) + '</td>'
						+ '<td><button type="button" class="button button-small sv-bk-restore" data-key="' + encodeURIComponent(b.key) + '"><?php echo esc_js( __( 'Khôi phục', 'sitevorx' ) ); ?></button> '
						+ '<button type="button" class="button button-small sv-copy-code" data-code="' + encodeURIComponent(b.key) + '"><?php echo esc_js( __( 'Copy mã', 'sitevorx' ) ); ?></button> '
						+ '<button type="button" class="button button-small sv-bk-delete" data-key="' + encodeURIComponent(b.key) + '"><?php echo esc_js( __( 'Xóa', 'sitevorx' ) ); ?></button></td></tr>';
				});
				$tb.html(html);
			}).fail(function(){ $tb.html('<tr><td colspan="5" style="color:#b71c1c;"><?php echo esc_js( __( 'Không gọi được server.', 'sitevorx' ) ); ?></td></tr>'); });
		});
		// Xóa backup
		$(document).on('click', '.sv-bk-delete', function(){
			var key = decodeURIComponent($(this).data('key'));
			if (!window.confirm('<?php echo esc_js( __( 'Xóa vĩnh viễn bản backup này khỏi S3?', 'sitevorx' ) ); ?>\n' + key)) return;
			var $btn = $(this).prop('disabled', true);
			call('sv_backup_delete', { key: key }).done(function(resp){
				if (resp && resp.success){ $btn.closest('tr').fadeOut(200, function(){ $(this).remove(); }); }
				else { window.alert(resp && resp.data ? resp.data.message : 'Lỗi'); $btn.prop('disabled', false); }
			}).fail(function(){ window.alert('<?php echo esc_js( __( 'Không gọi được server.', 'sitevorx' ) ); ?>'); $btn.prop('disabled', false); });
		});

		// Khôi phục (restore)
		var rJob = null, rSecret = null;
		var rLoginUrl = '<?php echo esc_js( wp_login_url() ); ?>';
		function rErr(m){ $('#sv-restore-progress').hide(); $('#sv-restore-err').text(m).show(); }
		function rBar(pct, label){ $('#sv-restore-bar').css('width', Math.min(100, pct) + '%'); $('#sv-restore-text').text(label); }
		var R_PHASES = { download: [0,20,'<?php echo esc_js( __( 'Đang tải từ S3', 'sitevorx' ) ); ?>'], decrypt: [20,30,'<?php echo esc_js( __( 'Đang giải mã', 'sitevorx' ) ); ?>'], extract: [30,42,'<?php echo esc_js( __( 'Đang giải nén', 'sitevorx' ) ); ?>'], files: [42,65,'<?php echo esc_js( __( 'Đang khôi phục file', 'sitevorx' ) ); ?>'], db: [65,85,'<?php echo esc_js( __( 'Đang nhập database', 'sitevorx' ) ); ?>'], 'search-replace': [85,99,'<?php echo esc_js( __( 'Đang thay thế URL', 'sitevorx' ) ); ?>'] };
		function rLoop(){
			call('sv_backup_restore_step', { job_id: rJob, secret: rSecret }).done(function(resp){
				if (!resp || !resp.success){ rErr(resp && resp.data ? resp.data.message : 'Lỗi khôi phục.'); return; }
				var d = resp.data;
				if (d.phase === 'done'){
					rBar(100, '<?php echo esc_js( __( 'Hoàn tất! Khôi phục thành công.', 'sitevorx' ) ); ?>');
					$('#sv-restore-progress').hide();
					$('#sv-restore-success').show();
					$('html,body').animate({ scrollTop: $('#sv-restore-box').offset().top - 40 }, 300);
					return; // KHÔNG tự chuyển trang — để khách đọc thông báo rồi tự bấm đăng nhập lại
				}
				var seg = R_PHASES[d.phase] || [0,100,d.phase];
				var inner = d.total ? (d.done / d.total) : 0;
				rBar(seg[0] + (seg[1]-seg[0]) * inner, seg[2] + (d.total ? (' (' + d.done + '/' + d.total + ')') : ''));
				setTimeout(rLoop, 50);
			}).fail(function(){ rErr('<?php echo esc_js( __( 'Mất kết nối khi khôi phục.', 'sitevorx' ) ); ?>'); });
		}
		function startRestore(key){
			key = $.trim(key || '');
			if (!key){ window.alert('<?php echo esc_js( __( 'Hãy dán mã di chuyển.', 'sitevorx' ) ); ?>'); return; }
			var warn = '<?php echo esc_js( __( 'Bạn sắp KHÔI PHỤC website từ bản sao lưu:', 'sitevorx' ) ); ?>\n' + key + '\n\n'
				+ '<?php echo esc_js( __( '• Toàn bộ bài viết, hình ảnh, giao diện và cấu hình HIỆN TẠI sẽ bị THAY THẾ bằng dữ liệu trong bản sao lưu này.', 'sitevorx' ) ); ?>\n'
				+ '<?php echo esc_js( __( '• Việc này KHÔNG THỂ hoàn tác.', 'sitevorx' ) ); ?>\n'
				+ '<?php echo esc_js( __( '• Sau khi xong, bạn sẽ phải ĐĂNG NHẬP LẠI bằng tài khoản của bản sao lưu (tài khoản/mật khẩu cũ).', 'sitevorx' ) ); ?>\n\n'
				+ '<?php echo esc_js( __( 'Bạn có chắc chắn muốn tiếp tục?', 'sitevorx' ) ); ?>';
			if (!window.confirm(warn)) return;
			$('#sv-restore-box').show();
			$('#sv-restore-err,#sv-restore-success').hide(); $('#sv-restore-progress').show(); rBar(0, '<?php echo esc_js( __( 'Đang khởi tạo khôi phục', 'sitevorx' ) ); ?>');
			$('html,body').animate({ scrollTop: $('#sv-restore-box').offset().top - 40 }, 300);
			call('sv_backup_restore_start', { key: key }).done(function(resp){
				if (!resp || !resp.success){ rErr(resp && resp.data ? resp.data.message : 'Không khởi tạo được.'); return; }
				rJob = resp.data.job_id; rSecret = resp.data.secret; rLoop();
			}).fail(function(){ rErr('<?php echo esc_js( __( 'Không gọi được endpoint khôi phục.', 'sitevorx' ) ); ?>'); });
		}
		$(document).on('click', '.sv-bk-restore', function(){ startRestore(decodeURIComponent($(this).data('key'))); });
		$('#sv-restore-by-code').on('click', function(){ startRestore($('#sv-restore-code').val()); });

		// Xóa nhật ký (chỉ của site này)
		$('#sv-backup-clear-logs').on('click', function(){
			if (!window.confirm('<?php echo esc_js( __( 'Xóa toàn bộ nhật ký của website này?', 'sitevorx' ) ); ?>')) return;
			var $b = $(this).prop('disabled', true);
			call('sv_backup_clear_logs').done(function(resp){
				if (resp && resp.success){ $('#sv-backup-logs-list').html('<div style="color:#888; padding:8px 0;"><?php echo esc_js( __( 'Đã xóa nhật ký.', 'sitevorx' ) ); ?></div>'); }
				else { window.alert(resp && resp.data ? resp.data.message : 'Lỗi'); $b.prop('disabled', false); }
			}).fail(function(){ window.alert('<?php echo esc_js( __( 'Không gọi được server.', 'sitevorx' ) ); ?>'); $b.prop('disabled', false); });
		});

		// Copy mã di chuyển
		$(document).on('click', '.sv-copy-code', function(){
			var code = decodeURIComponent($(this).data('code')); var $b = $(this);
			var done = function(){ var t = $b.text(); $b.text('<?php echo esc_js( __( 'Đã copy ✓', 'sitevorx' ) ); ?>'); setTimeout(function(){ $b.text(t); }, 1500); };
			if (navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(code).then(done, function(){ window.prompt('<?php echo esc_js( __( 'Copy mã:', 'sitevorx' ) ); ?>', code); }); }
			else { window.prompt('<?php echo esc_js( __( 'Copy mã:', 'sitevorx' ) ); ?>', code); }
		});
	})(jQuery);
	</script>

	<div class="sv-content-box" id="sv-restore-box" style="display:none;">
		<div class="sv-box-header"><span class="dashicons dashicons-update" style="color:var(--sv-red);"></span><h3><?php esc_html_e( 'Tiến trình khôi phục', 'sitevorx' ); ?></h3></div>
		<div style="padding:20px;">
			<div id="sv-restore-progress" style="display:none;">
				<div class="sv-progress"><div id="sv-restore-bar"></div></div>
				<p id="sv-restore-text" style="margin-top:8px; color:var(--sv-text-secondary);"></p>
			</div>
			<div id="sv-restore-err" class="sv-alert sv-alert-err" style="margin-top:12px;"></div>
			<div id="sv-restore-success" class="sv-alert sv-alert-ok" style="margin-top:12px; padding:16px;">
				<p style="margin:0 0 6px; font-size:15px;"><strong>✅ <?php esc_html_e( 'Khôi phục thành công!', 'sitevorx' ); ?></strong></p>
				<p style="margin:0 0 6px;"><?php esc_html_e( 'Website đã được phục hồi từ bản sao lưu bạn chọn.', 'sitevorx' ); ?></p>
				<p style="margin:0 0 12px; padding:10px; background:#fff8e1; border:1px solid #ffe082; border-radius:6px; color:#7a5b00;">
					⚠️ <strong><?php esc_html_e( 'Cần làm tiếp:', 'sitevorx' ); ?></strong>
					<?php esc_html_e( 'Toàn bộ dữ liệu — kể cả tài khoản quản trị — đã được thay bằng dữ liệu của website cũ. Vì vậy bạn cần ĐĂNG NHẬP LẠI bằng đúng tài khoản quản trị của website cũ đó (KHÔNG phải tài khoản vừa tạo trên hosting mới). Đây là việc bình thường khi di chuyển website.', 'sitevorx' ); ?>
				</p>
				<a href="<?php echo esc_url( wp_login_url() ); ?>" class="button button-primary button-large"><?php esc_html_e( 'Tôi đã hiểu — Đăng nhập lại', 'sitevorx' ); ?></a>
			</div>
		</div>
	</div>
	<script>
	(function($){ // hiện box tiến trình khôi phục khi bấm restore
		$(document).on('click', '.sv-bk-restore', function(){ $('#sv-restore-box').show(); $('html,body').animate({ scrollTop: $('#sv-restore-box').offset().top - 40 }, 300); });
	})(jQuery);
	</script>
	<?php
}

// =============================================================================
// RESTORE — tải từ S3, giải nén, khôi phục file, import DB, search-replace
// =============================================================================

const SV_BACKUP_DL_CHUNK            = 16777216; // 16 MB / lần tải (ít range-request hơn).
const SV_BACKUP_EXTRACT_BATCH       = 80;
const SV_BACKUP_RESTORE_FILE_BATCH  = 60;
const SV_BACKUP_SR_ROWS             = 50;
const SV_BACKUP_RESTORE_BUDGET_SEC  = 15;

/**
 * State của RESTORE lưu trên ĐĨA (không phải transient): quá trình import DB
 * sẽ ghi đè bảng wp_options nên transient sẽ bị xóa giữa chừng. File state nằm
 * trong working dir của job nên sống sót qua bước import.
 */
function sv_backup_restore_state_path( $job_id ) {
	return sv_backup_tmp_root() . '/' . preg_replace( '/[^a-z0-9_]/i', '', $job_id ) . '/state.json';
}

function sv_backup_restore_get_state( $job_id ) {
	$p = sv_backup_restore_state_path( $job_id );
	if ( ! file_exists( $p ) ) return null;
	$d = json_decode( (string) @file_get_contents( $p ), true );
	return is_array( $d ) ? $d : null;
}

function sv_backup_restore_set_state( $job_id, array $state ) {
	@file_put_contents( sv_backup_restore_state_path( $job_id ), wp_json_encode( $state ) );
}

add_action( 'wp_ajax_sv_backup_delete', 'sv_backup_ajax_delete' );
function sv_backup_ajax_delete() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
	}
	check_ajax_referer( 'sv_backup_nonce', 'nonce' );
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	if ( '' === $key ) {
		wp_send_json_error( array( 'message' => __( 'Thiếu key.', 'sitevorx' ) ), 400 );
	}
	$cfg = sv_s3_config();
	if ( is_wp_error( $cfg ) ) {
		wp_send_json_error( array( 'message' => $cfg->get_error_message() ) );
	}
	if ( ! sv_backup_key_in_account( $cfg, $key ) ) {
		wp_send_json_error( array( 'message' => __( 'Bản backup không thuộc tài khoản của bạn.', 'sitevorx' ) ), 403 );
	}
	$res = sv_s3_delete_object_abs( $cfg, $key );
	if ( is_wp_error( $res ) ) {
		wp_send_json_error( array( 'message' => $res->get_error_message() ) );
	}
	if ( function_exists( 'sv_audit_log' ) ) {
		sv_audit_log( 'backup_deleted', array( 'key' => $key ) );
	}
	wp_send_json_success();
}

/**
 * Thay thế chuỗi an toàn với dữ liệu PHP-serialized (đệ quy mảng/đối tượng).
 *
 * @param array  $search  Danh sách chuỗi cần tìm.
 * @param array  $replace Danh sách chuỗi thay thế (tương ứng index).
 * @param mixed  $data    Giá trị cần xử lý.
 */
function sv_backup_sr_replace( array $search, array $replace, $data, $depth = 0 ) {
	if ( $depth > 50 ) {
		return $data; // chống đệ quy bệnh lý (dữ liệu serialized lồng quá sâu).
	}
	if ( is_string( $data ) && '' !== $data ) {
		if ( is_serialized( $data ) ) {
			$un = @unserialize( $data );
			if ( false !== $un || 'b:0;' === $data ) {
				return serialize( sv_backup_sr_replace( $search, $replace, $un, $depth + 1 ) );
			}
		}
		return str_replace( $search, $replace, $data );
	}
	if ( is_array( $data ) ) {
		$out = array();
		foreach ( $data as $k => $v ) {
			$out[ $k ] = sv_backup_sr_replace( $search, $replace, $v, $depth + 1 );
		}
		return $out;
	}
	if ( is_object( $data ) ) {
		if ( $data instanceof __PHP_Incomplete_Class ) {
			return $data;
		}
		$out = clone $data;
		foreach ( get_object_vars( $out ) as $k => $v ) {
			$out->{$k} = sv_backup_sr_replace( $search, $replace, $v, $depth + 1 );
		}
		return $out;
	}
	return $data;
}

add_action( 'wp_ajax_sv_backup_restore_start', 'sv_backup_ajax_restore_start' );
function sv_backup_ajax_restore_start() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
	}
	check_ajax_referer( 'sv_backup_nonce', 'nonce' );
	@set_time_limit( 0 );

	if ( ! class_exists( 'ZipArchive' ) ) {
		wp_send_json_error( array( 'message' => __( 'Host này thiếu PHP extension "zip" (ZipArchive) — bắt buộc để khôi phục gói. Hãy bật extension "zip" cho PHP rồi thử lại.', 'sitevorx' ) ), 500 );
	}

	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	if ( '' === $key ) {
		wp_send_json_error( array( 'message' => __( 'Thiếu key backup.', 'sitevorx' ) ), 400 );
	}
	$cfg = sv_s3_config();
	if ( is_wp_error( $cfg ) ) {
		wp_send_json_error( array( 'message' => $cfg->get_error_message() ), 400 );
	}
	// Cho phép khôi phục gói của tài khoản khác KHI người dùng cung cấp đúng "mã di
	// chuyển" (key có token ngẫu nhiên, không đoán được) — phục vụ migration xuyên
	// hosting. Sở hữu mã = có quyền. Danh sách vẫn lọc theo tài khoản nên không lộ.
	// Chặn ký tự bất thường ở key (an toàn cơ bản).
	if ( ! preg_match( '#^[A-Za-z0-9._\-/]+\.zip(\.enc)?$#', ltrim( $key, '/' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Mã di chuyển không hợp lệ.', 'sitevorx' ) ), 400 );
	}

	$head = sv_s3_request( $cfg, 'HEAD', ltrim( $key, '/' ), array( 'timeout' => 20 ) );
	if ( is_wp_error( $head ) ) {
		wp_send_json_error( array( 'message' => $head->get_error_message() ), 500 );
	}
	$size = isset( $head['headers']['content-length'] ) ? (int) $head['headers']['content-length'] : 0;

	$job_id  = 'svrs' . strtolower( wp_generate_password( SV_BACKUP_TOKEN_LEN, false, false ) );
	$tmp_dir = sv_backup_ensure_tmp_dir( $job_id );
	$staging = $tmp_dir . '/staging';
	wp_mkdir_p( $staging );

	// Token uỷ quyền cho các bước restore tiếp theo. Bước import DB sẽ ghi đè
	// wp_users/wp_usermeta → cookie đăng nhập + nonce mất hiệu lực giữa chừng,
	// nên các bước sau xác thực bằng token này (lưu trong file state trên đĩa,
	// sống sót qua import) thay vì dựa vào phiên WP. Bước start vẫn yêu cầu admin.
	$secret = wp_generate_password( 40, false, false );

	// Bản mã hóa có hậu tố .enc → tải về file riêng rồi giải mã ra restore.zip.
	$encrypted = ( '.enc' === strtolower( substr( $key, -4 ) ) );

	$state = array(
		'id'           => $job_id,
		'tmp_dir'      => $tmp_dir,
		'staging'      => $staging,
		'encrypted'    => $encrypted,
		'dl_path'      => $tmp_dir . '/' . ( $encrypted ? 'restore.bin' : 'restore.zip' ),
		'zip_path'     => $tmp_dir . '/restore.zip',
		'dec_offset'   => 0,
		's3_key'       => $key,
		'archive_size' => $size,
		'dl_offset'    => 0,
		'phase'        => 'download',
		'secret'       => $secret,
		'started_at'   => time(),
	);
	sv_backup_restore_set_state( $job_id, $state );
	if ( function_exists( 'sv_audit_log' ) ) {
		sv_audit_log( 'backup_restore_started', array( 'key' => $key ) );
	}
	wp_send_json_success( array( 'job_id' => $job_id, 'secret' => $secret ) );
}

add_action( 'wp_ajax_sv_backup_restore_step', 'sv_backup_ajax_restore_step' );
// Đăng ký cả nopriv: sau khi import DB ghi đè wp_users/usermeta, cookie đăng nhập
// mất hiệu lực → WP định tuyến request sang nhánh nopriv. Handler vẫn chạy nhưng
// chỉ cho qua khi token job khớp (xác thực không phụ thuộc phiên WP).
add_action( 'wp_ajax_nopriv_sv_backup_restore_step', 'sv_backup_ajax_restore_step' );
function sv_backup_ajax_restore_step() {
	@set_time_limit( 0 );

	// Nâng RAM cho thao tác nặng (import DB / search-replace dữ liệu lớn). Chỉ nâng
	// lên, không hạ. Host không cho ini_set thì no-op.
	$cur_mem = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : 0;
	if ( $cur_mem >= 0 && $cur_mem < 536870912 ) {
		@ini_set( 'memory_limit', '512M' );
	}

	$job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
	$secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
	$state  = sv_backup_restore_get_state( $job_id );
	if ( ! $state ) {
		wp_send_json_error( array( 'message' => __( 'Phiên khôi phục đã hết hạn.', 'sitevorx' ) ), 404 );
	}

	// Bắt FATAL (kể cả OOM) ghi ra file trong job dir để đọc được dù display/
	// error_log bị tắt trong ngữ cảnh AJAX. Đọc: {tmp_dir}/crash.log
	$sv_crash_dir = isset( $state['tmp_dir'] ) ? $state['tmp_dir'] : '';
	register_shutdown_function( function() use ( $sv_crash_dir ) {
		$e = error_get_last();
		if ( $sv_crash_dir && $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ), true ) ) {
			@file_put_contents( $sv_crash_dir . '/crash.log', gmdate( 'c' ) . '  ' . $e['message'] . '  @ ' . $e['file'] . ':' . $e['line'] . "\n", FILE_APPEND );
		}
	} );

	// Uỷ quyền: admin còn phiên (cap + nonce) HOẶC token job khớp (sau khi import
	// DB làm mất phiên WP). Token chỉ admin khởi tạo restore mới biết, nằm trong
	// working dir đã niêm phong.
	$nonce        = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	$is_admin     = current_user_can( 'manage_options' ) && wp_verify_nonce( $nonce, 'sv_backup_nonce' );
	$token_ok     = ! empty( $state['secret'] ) && '' !== $secret && hash_equals( (string) $state['secret'], $secret );
	if ( ! $is_admin && ! $token_ok ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
	}
	$cfg = sv_s3_config();
	if ( is_wp_error( $cfg ) ) {
		wp_send_json_error( array( 'message' => $cfg->get_error_message() ), 400 );
	}

	$resp = sv_backup_restore_dispatch( $cfg, $state );
	if ( is_wp_error( $resp ) ) {
		wp_send_json_error( array( 'message' => $resp->get_error_message() ), 500 );
	}

	if ( 'done' === $state['phase'] ) {
		sv_backup_rm_rf( $state['tmp_dir'] );
		// Công cụ migrate: restore xong → xóa gói khỏi S3 (đã hoàn thành di chuyển).
		sv_s3_delete_object_abs( $cfg, $state['s3_key'] );
		sv_backup_log( sprintf( __( 'Khôi phục thành công từ: %s (đã xóa gói khỏi S3)', 'sitevorx' ), $state['s3_key'] ) );
		if ( function_exists( 'sv_audit_log' ) ) {
			sv_audit_log( 'backup_restored', array( 'key' => $state['s3_key'] ) );
		}
	} else {
		// Lưu tiến trình ra file (sống sót qua bước import DB ghi đè wp_options).
		sv_backup_restore_set_state( $job_id, $state );
	}
	wp_send_json_success( $resp );
}

/**
 * Sinh các cặp [tìm, thay] đưa URL gốc → URL đích, phủ biến thể scheme: https://,
 * http:// và protocol-relative //. Cả 3 đều quy về URL đích (http cũ nâng lên scheme
 * của đích) để media/link đổi đúng dù lưu kiểu nào. Có gắn scheme/'//' ở đầu nên
 * KHÔNG khớp nhầm host lồng nhau (vd old.com vs notold.com). No-op nếu cùng host.
 *
 * @return array<int,array{0:string,1:string}>
 */
function sv_backup_url_variants( $origin, $current ) {
	$origin  = rtrim( trim( (string) $origin ), '/' );
	$current = rtrim( trim( (string) $current ), '/' );
	if ( '' === $origin || $origin === $current ) {
		return array();
	}
	$oh = preg_replace( '#^https?://#i', '', $origin );  // host[+path] gốc, bỏ scheme
	$ch = preg_replace( '#^https?://#i', '', $current );
	if ( '' === $oh || $oh === $ch ) {
		return array();
	}
	// Thứ tự: có scheme trước, protocol-relative sau (để '//' không cắt nhầm scheme URL).
	return array(
		array( 'https://' . $oh, $current ),
		array( 'http://' . $oh,  $current ),
		array( '//' . $oh,       '//' . $ch ),
	);
}

/**
 * Xử lý 1 bước khôi phục theo phase hiện tại. Cập nhật $state by-ref.
 * Mở zip (đã giải mã) đọc manifest + danh sách entry, dựng sr_from/to, chuyển phase extract.
 *
 * @return true|WP_Error
 */
function sv_backup_restore_prepare_extract( array &$state ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $state['zip_path'] ) ) {
		return new WP_Error( 'sv_restore_zip', __( 'Không mở được file backup đã tải.', 'sitevorx' ) );
	}
	$manifest_raw = $zip->getFromName( 'manifest.json' );
	$manifest     = $manifest_raw ? json_decode( $manifest_raw, true ) : array();
	if ( empty( $manifest['magic'] ) || SV_BACKUP_MAGIC !== $manifest['magic'] ) {
		$zip->close();
		return new WP_Error( 'sv_restore_magic', __( 'File không phải bản backup Sitevorx hợp lệ (manifest sai).', 'sitevorx' ) );
	}
	$names = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$names[] = $zip->getNameIndex( $i );
	}
	$zip->close();
	@file_put_contents( $state['tmp_dir'] . '/entries.json', wp_json_encode( $names ) );

	// Dựng cặp tìm→thay phủ MỌI biến thể scheme (https / http / protocol-relative //)
	// để URL media/link dù lưu kiểu nào cũng đổi sang domain mới — tránh "mất ảnh sau
	// khi đổi tên miền". Gắn scheme/'//' ở đầu nên không khớp nhầm host lồng nhau.
	$pairs = array_merge(
		sv_backup_url_variants( $manifest['origin_home_url'] ?? '', home_url() ),
		sv_backup_url_variants( $manifest['origin_site_url'] ?? '', site_url() )
	);
	$from = array(); $to = array(); $seen = array();
	foreach ( $pairs as $p ) {
		if ( isset( $seen[ $p[0] ] ) ) continue;
		$seen[ $p[0] ] = 1;
		$from[] = $p[0]; $to[] = $p[1];
	}
	$state['sr_from']       = $from;
	$state['sr_to']         = $to;
	$state['include']       = isset( $manifest['include'] ) ? $manifest['include'] : 'both';
	// Prefix bảng của site NGUỒN — để rewrite định danh bảng khi import nếu site
	// đích dùng prefix khác (nếu không, bảng import nằm dưới tên prefix nguồn còn
	// WP đọc bảng prefix đích → ra nội dung mặc định "Hello world").
	$state['src_prefix']    = isset( $manifest['table_prefix'] ) ? (string) $manifest['table_prefix'] : '';
	// Breakdown gói tuyên bố (theo manifest) — đối chiếu với số thực giải nén được.
	$state['manifest_breakdown'] = isset( $manifest['files_breakdown'] ) && is_array( $manifest['files_breakdown'] ) ? $manifest['files_breakdown'] : array();
	$state['entries_total'] = count( $names );
	$state['extract_idx']   = 0;
	$state['phase']         = 'extract';
	return true;
}

/**
 * Xử lý 1 bước khôi phục theo phase hiện tại. Cập nhật $state by-ref.
 *
 * @return array|WP_Error  ['phase'=>, 'done'=>, 'total'=>]
 */
function sv_backup_restore_dispatch( array $cfg, array &$state ) {
	global $wpdb;
	$deadline = microtime( true ) + SV_BACKUP_RESTORE_BUDGET_SEC;

	switch ( $state['phase'] ) {

		case 'download':
			// Vòng lặp time-budget: tải nhiều chunk 8MB trong 1 request tới khi gần
			// hết SV_BACKUP_RESTORE_BUDGET_SEC, rồi trả về cho JS gọi tiếp. Tránh
			// kiểu 1-chunk-mỗi-request (25+ request) dễ vượt request_terminate_timeout
			// của PHP-FPM → 502.
			while ( $state['dl_offset'] < $state['archive_size'] && microtime( true ) < $deadline ) {
				$end   = min( $state['archive_size'], $state['dl_offset'] + SV_BACKUP_DL_CHUNK );
				$range = 'bytes=' . $state['dl_offset'] . '-' . ( $end - 1 );
				$res   = sv_s3_request( $cfg, 'GET', ltrim( $state['s3_key'], '/' ), array(
					'headers' => array( 'Range' => $range ),
					'outfile' => $state['dl_path'],
					'timeout' => 60,
				) );
				if ( is_wp_error( $res ) ) return $res;
				$state['dl_offset'] = $end;
			}
			if ( $state['dl_offset'] >= $state['archive_size'] || 0 === $state['archive_size'] ) {
				if ( ! empty( $state['encrypted'] ) ) {
					$state['phase']      = 'decrypt';
					$state['dec_offset'] = 0;
				} else {
					// Không mã hóa: dl_path chính là zip_path → đọc manifest luôn.
					$prep = sv_backup_restore_prepare_extract( $state );
					if ( is_wp_error( $prep ) ) return $prep;
				}
			}
			return array( 'phase' => $state['phase'], 'done' => (int) $state['dl_offset'], 'total' => (int) $state['archive_size'] );

		case 'decrypt':
			$key = sv_backup_encryption_key();
			if ( null === $key ) {
				return new WP_Error( 'sv_restore_nokey', __( 'Không có khóa giải mã cho tài khoản này.', 'sitevorx' ) );
			}
			$doff = (int) $state['dec_offset'];
			$ddone = sv_backup_decrypt_step( $state['dl_path'], $state['zip_path'], $doff, $key, $deadline );
			if ( is_wp_error( $ddone ) ) return $ddone;
			$state['dec_offset'] = $doff;
			if ( true === $ddone ) {
				@unlink( $state['dl_path'] );
				$prep = sv_backup_restore_prepare_extract( $state );
				if ( is_wp_error( $prep ) ) return $prep;
			}
			return array( 'phase' => $state['phase'], 'done' => (int) $state['dec_offset'], 'total' => (int) $state['archive_size'] );

		case 'extract':
			$names = json_decode( (string) @file_get_contents( $state['tmp_dir'] . '/entries.json' ), true );
			if ( ! is_array( $names ) ) $names = array();
			$zip = new ZipArchive();
			if ( true !== $zip->open( $state['zip_path'] ) ) {
				return new WP_Error( 'sv_restore_zip2', __( 'Không mở được file backup khi giải nén.', 'sitevorx' ) );
			}
			$total_n = count( $names );
			// Giữ zip MỞ và giải nén NHIỀU lô trong 1 request tới khi gần hết budget —
			// thay vì 80 entry/request (185+ round-trip cho site nhiều file). Nhanh hơn
			// nhiều lần vì bỏ phần lớn round-trip mạng + mở lại file zip lớn mỗi lần.
			while ( $state['extract_idx'] < $total_n && microtime( true ) < $deadline ) {
				$end   = min( $total_n, $state['extract_idx'] + SV_BACKUP_EXTRACT_BATCH );
				$batch = array_slice( $names, $state['extract_idx'], $end - $state['extract_idx'] );
				if ( ! empty( $batch ) ) {
					$zip->extractTo( $state['staging'], $batch );
				}
				$state['extract_idx'] = $end;
			}
			$zip->close();
			if ( $state['extract_idx'] >= $total_n ) {
				// Dựng danh sách file để khôi phục từ staging/wp-content.
				$src_root = $state['staging'] . '/wp-content';
				$files    = array();
				if ( is_dir( $src_root ) ) {
					$it = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator( $src_root, FilesystemIterator::SKIP_DOTS ),
						RecursiveIteratorIterator::LEAVES_ONLY
					);
					foreach ( $it as $f ) {
						if ( ! $f->isFile() ) continue;
						$abs = wp_normalize_path( $f->getPathname() );
						$rel = ltrim( substr( $abs, strlen( wp_normalize_path( $src_root ) ) ), '/' );
						$files[] = $rel;
					}
				}
				@file_put_contents( $state['tmp_dir'] . '/restore-files.json', wp_json_encode( $files ) );
				$state['files_total'] = count( $files );
				$state['files_idx']   = 0;
				// Giải nén xong → gói zip không còn cần: xóa ngay để hạ ĐỈNH đĩa từ ~3×
				// (zip+staging+bản chép) xuống ~2× (staging+bản chép). Đã có file list +
				// staging; sql nằm trong staging. Quan trọng với site nặng (đĩa đích hẹp).
				if ( ! empty( $state['zip_path'] ) && file_exists( $state['zip_path'] ) ) {
					@unlink( $state['zip_path'] );
				}
				// Chẩn đoán bug "thiếu uploads/themes": ghi log breakdown gói TUYÊN BỐ
				// (manifest) vs THỰC TẾ giải nén được. Lệch → mất ở extract; cả hai cùng
				// thiếu uploads/themes → mất từ khâu đóng gói ở site nguồn.
				$extracted_bd = sv_backup_files_breakdown( array_map( function( $r ) { return array( 'rel' => 'wp-content/' . $r ); }, $files ) );
				sv_backup_restore_diag( sprintf(
					'EXTRACT - goi khai bao: %s | thuc giai nen duoc: %s',
					sv_backup_breakdown_str( isset( $state['manifest_breakdown'] ) ? $state['manifest_breakdown'] : array() ),
					sv_backup_breakdown_str( $extracted_bd )
				) );
				$state['phase']       = ( 'db' === $state['include'] ) ? 'db' : 'files';
				if ( 'db' === $state['phase'] ) {
					$state['db_offset'] = 0;
				}
			}
			return array( 'phase' => 'extract', 'done' => (int) $state['extract_idx'], 'total' => (int) $state['entries_total'] );

		case 'files':
			$files = json_decode( (string) @file_get_contents( $state['tmp_dir'] . '/restore-files.json' ), true );
			if ( ! is_array( $files ) ) $files = array();
			$src_root  = wp_normalize_path( $state['staging'] . '/wp-content' );
			$dest_root = wp_normalize_path( WP_CONTENT_DIR );
			// KHÔNG ghi đè chính plugin đang chạy: file của nó bị thay giữa các bước
			// AJAX sẽ gây fatal ở request kế tiếp. Bản gốc trên S3 cũng là Sitevorx
			// Pro nên bỏ qua không mất gì.
			$self_plugin_rel = 'plugins/' . basename( untrailingslashit( SV_PLUGIN_DIR ) ) . '/';
			$processed = 0;
			while ( $state['files_idx'] < count( $files ) && microtime( true ) < $deadline ) {
				$rel = $files[ $state['files_idx'] ];
				$state['files_idx']++;
				$processed++;
				if ( 0 !== strpos( $rel, $self_plugin_rel ) ) {
					$src  = $src_root . '/' . $rel;
					$dest = $dest_root . '/' . $rel;
					$ddir = dirname( $dest );
					if ( ! is_dir( $ddir ) ) wp_mkdir_p( $ddir );
					// DI CHUYỂN (rename) thay vì copy: cùng ổ đĩa → gần như tức thì, KHÔNG
					// ghi lại bytes lần 2 (nhanh hơn nhiều + đỡ đỉnh đĩa). Khác ổ đĩa thì
					// rename fail → fallback copy.
					if ( @rename( $src, $dest ) || @copy( $src, $dest ) ) {
						$state['files_copied'] = ( isset( $state['files_copied'] ) ? (int) $state['files_copied'] : 0 ) + 1;
					} else {
						$state['files_failed'] = ( isset( $state['files_failed'] ) ? (int) $state['files_failed'] : 0 ) + 1;
					}
				}
				if ( 0 === $processed % SV_BACKUP_RESTORE_FILE_BATCH && microtime( true ) >= $deadline ) break;
			}
			if ( $state['files_idx'] >= count( $files ) ) {
				// Chẩn đoán: ghi log số file chép được vs thất bại (thiếu quyền ghi /
				// sai web root ở host đích sẽ lộ ra ở con số failed > 0).
				sv_backup_log( sprintf(
					__( 'Khôi phục file: chép %d, lỗi %d (đích: %s)', 'sitevorx' ),
					isset( $state['files_copied'] ) ? (int) $state['files_copied'] : 0,
					isset( $state['files_failed'] ) ? (int) $state['files_failed'] : 0,
					$dest_root
				) );
				if ( 'files' === $state['include'] ) {
					$state['phase'] = ! empty( $state['sr_from'] ) ? 'search-replace' : 'done';
				} else {
					$state['phase']     = 'db';
					$state['db_offset'] = 0;
				}
				sv_backup_restore_prepare_sr( $state );
			}
			return array( 'phase' => 'files', 'done' => (int) $state['files_idx'], 'total' => (int) $state['files_total'] );

		case 'db':
			$sql_file = $state['staging'] . '/database.sql';
			if ( ! file_exists( $sql_file ) ) {
				$state['phase'] = ! empty( $state['sr_from'] ) ? 'search-replace' : 'done';
				sv_backup_restore_prepare_sr( $state );
				return array( 'phase' => $state['phase'], 'done' => 0, 'total' => 0 );
			}
			$fp = @fopen( $sql_file, 'rb' );
			if ( ! $fp ) {
				return new WP_Error( 'sv_restore_sql_open', __( 'Không mở được database.sql.', 'sitevorx' ) );
			}
			$total = filesize( $sql_file );
			fseek( $fp, (int) $state['db_offset'] );
			// Rewrite prefix khi site đích khác prefix nguồn: tên bảng trong dump dùng
			// prefix nguồn → đổi sang $wpdb->prefix để bảng import đúng chỗ WP đọc.
			$src_prefix     = isset( $state['src_prefix'] ) ? (string) $state['src_prefix'] : '';
			$rewrite_prefix = ( '' !== $src_prefix && $src_prefix !== $wpdb->prefix );
			$buffer    = '';
			$safe_off  = (int) $state['db_offset'];
			while ( microtime( true ) < $deadline && ! feof( $fp ) ) {
				$line = fgets( $fp );
				if ( false === $line ) break;
				$trim = ltrim( $line );
				if ( '' === trim( $trim ) || 0 === strpos( $trim, '--' ) ) {
					$safe_off = ftell( $fp );
					continue;
				}
				$buffer .= $line;
				if ( ';' === substr( rtrim( $line ), -1 ) ) {
					$stmt = trim( $buffer );
					$buffer = '';
					if ( '' !== $stmt ) {
						if ( $rewrite_prefix ) {
							$stmt = sv_backup_rewrite_stmt_prefix( $stmt, $src_prefix, $wpdb->prefix );
						}
						$wpdb->query( $stmt );
					}
					$safe_off = ftell( $fp );
				}
			}
			$eof = feof( $fp );
			fclose( $fp );
			$state['db_offset'] = $safe_off;
			if ( $eof && $safe_off >= $total ) {
				// Sau khi import xong: nếu đổi prefix, sửa các KHÓA phụ thuộc prefix
				// (option user_roles, meta_key capabilities/user_level…) kẻo mất quyền admin.
				if ( $rewrite_prefix ) {
					sv_backup_restore_fix_prefix_keys( $src_prefix );
				}
				$state['phase'] = ! empty( $state['sr_from'] ) ? 'search-replace' : 'done';
				sv_backup_restore_prepare_sr( $state );
			}
			return array( 'phase' => 'db', 'done' => (int) $state['db_offset'], 'total' => (int) $total );

		case 'search-replace':
			return sv_backup_restore_sr_step( $state, $deadline );

		default:
			$state['phase'] = 'done';
			return array( 'phase' => 'done', 'done' => 1, 'total' => 1 );
	}
}

/**
 * Đổi prefix tên bảng trong 1 statement của dump (DROP/CREATE/INSERT) từ prefix
 * nguồn sang prefix đích. Chỉ chạm định danh bảng ngay sau từ khóa ở ĐẦU statement
 * (vị trí dump luôn đặt tên bảng) nên không đụng dữ liệu chứa chuỗi giống prefix.
 */
function sv_backup_rewrite_stmt_prefix( $stmt, $src, $dest ) {
	if ( '' === $src || $src === $dest ) {
		return $stmt;
	}
	return preg_replace(
		'/^(DROP TABLE IF EXISTS |CREATE TABLE (?:IF NOT EXISTS )?|INSERT INTO )`' . preg_quote( $src, '/' ) . '/i',
		'${1}`' . $dest,
		$stmt,
		1
	);
}

/**
 * Sau khi import với prefix đổi: sửa các KHÓA phụ thuộc prefix để giữ quyền/đăng nhập.
 * - option `{prefix}user_roles` (định nghĩa vai trò) → prefix đích.
 * - meta_key người dùng bắt đầu bằng `{prefix}` (capabilities, user_level,
 *   user-settings…) → prefix đích, nếu không user sẽ MẤT quyền (kể cả admin).
 * Bảng options/usermeta tham chiếu qua $wpdb (đã là prefix đích sau khi rewrite import).
 */
function sv_backup_restore_fix_prefix_keys( $src_prefix ) {
	global $wpdb;
	$dest = $wpdb->prefix;
	if ( '' === $src_prefix || $src_prefix === $dest ) {
		return;
	}

	// option_name: chỉ user_roles mang prefix trong bảng options.
	$wpdb->query( $wpdb->prepare(
		"UPDATE `{$wpdb->options}` SET option_name = %s WHERE option_name = %s",
		$dest . 'user_roles',
		$src_prefix . 'user_roles'
	) );

	// usermeta: mọi meta_key bắt đầu bằng prefix nguồn → thay bằng prefix đích.
	$wpdb->query( $wpdb->prepare(
		"UPDATE `{$wpdb->usermeta}` SET meta_key = CONCAT( %s, SUBSTRING( meta_key, %d ) ) WHERE meta_key LIKE %s",
		$dest,
		strlen( $src_prefix ) + 1,
		$wpdb->esc_like( $src_prefix ) . '%'
	) );
}

/**
 * Chuẩn bị danh sách bảng cho search-replace (gọi khi chuyển sang phase đó).
 */
function sv_backup_restore_prepare_sr( array &$state ) {
	if ( 'search-replace' !== $state['phase'] ) return;
	if ( isset( $state['sr_tables'] ) ) return;
	$state['sr_tables']   = sv_backup_collect_tables();
	$state['sr_table_idx'] = 0;
	$state['sr_last_pk']  = 0;
	$state['sr_total']    = count( $state['sr_tables'] );
}

/**
 * 1 bước search-replace: duyệt bảng/row theo cursor, thay thế serialize-aware.
 */
function sv_backup_restore_sr_step( array &$state, $deadline ) {
	global $wpdb;
	$tables  = isset( $state['sr_tables'] ) ? $state['sr_tables'] : array();
	$search  = $state['sr_from'];
	$replace = $state['sr_to'];

	while ( $state['sr_table_idx'] < count( $tables ) && microtime( true ) < $deadline ) {
		$table = $tables[ $state['sr_table_idx'] ];
		$pk    = sv_backup_table_pk( $table );
		if ( '' === $pk ) {
			// Không có PK số nguyên đơn → bỏ qua an toàn.
			$state['sr_table_idx']++;
			$state['sr_last_pk'] = 0;
			continue;
		}
		$cols = $wpdb->get_results( "SHOW COLUMNS FROM `$table`", ARRAY_A );
		$text_cols = array();
		foreach ( (array) $cols as $c ) {
			if ( preg_match( '/(char|text|enum|set|blob)/i', $c['Type'] ) ) {
				$text_cols[] = $c['Field'];
			}
		}
		if ( empty( $text_cols ) ) {
			$state['sr_table_idx']++;
			$state['sr_last_pk'] = 0;
			continue;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `$table` WHERE `$pk` > %d ORDER BY `$pk` ASC LIMIT %d",
			(int) $state['sr_last_pk'], SV_BACKUP_SR_ROWS
		), ARRAY_A );

		if ( empty( $rows ) ) {
			$state['sr_table_idx']++;
			$state['sr_last_pk'] = 0;
			continue;
		}

		foreach ( $rows as $row ) {
			$state['sr_last_pk'] = (int) $row[ $pk ];
			$update = array();
			foreach ( $text_cols as $col ) {
				if ( ! isset( $row[ $col ] ) || null === $row[ $col ] || '' === $row[ $col ] ) continue;
				$val = $row[ $col ];
				// Pre-filter: chỉ chạy unserialize đệ quy (tốn RAM) khi giá trị THỰC SỰ
				// chứa URL cũ. Đa số hàng (kể cả dữ liệu page-builder khổng lồ) không
				// chứa → bỏ qua → tránh OOM, nhanh hơn nhiều lần.
				$hit = false;
				foreach ( $search as $s ) {
					if ( '' !== $s && false !== strpos( $val, $s ) ) { $hit = true; break; }
				}
				if ( ! $hit ) continue;
				$new = sv_backup_sr_replace( $search, $replace, $val );
				if ( $new !== $val ) {
					$update[ $col ] = $new;
				}
			}
			if ( ! empty( $update ) ) {
				$wpdb->update( $table, $update, array( $pk => $row[ $pk ] ) );
			}
		}
		unset( $rows );
	}

	if ( $state['sr_table_idx'] >= count( $tables ) ) {
		$state['phase'] = 'done';
		// Đảm bảo URL gốc của site đã trỏ về domain hiện tại.
		update_option( 'siteurl', site_url() );
		update_option( 'home', home_url() );
		wp_cache_flush();
	}
	return array( 'phase' => $state['phase'], 'done' => (int) $state['sr_table_idx'], 'total' => (int) $state['sr_total'] );
}
