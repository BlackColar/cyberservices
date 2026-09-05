<?php
/**
 * Sitevorx Pro — Cấu hình kho S3 do iNET quản lý ("managed mode").
 *
 * Đây là kho lưu trữ sao lưu CHUNG của iNET. KHÁCH KHÔNG nhập thông tin này —
 * khi các hằng số dưới đây được định nghĩa, plugin tự động dùng chúng và ẩn
 * form kết nối trong trang quản trị; khách chỉ việc bấm Sao lưu / Khôi phục.
 *
 * ⚠️ TRƯỚC KHI PHÁT HÀNH PRODUCTION: thay các giá trị dưới đây bằng credentials
 * production. Mỗi site được tách riêng theo prefix domain (xem sv_backup_host_slug()).
 *
 * Muốn cho phép khách tự cấu hình S3 riêng (self-managed) thì comment cả khối
 * này lại — plugin sẽ quay về đọc cấu hình từ options + hiện form nhập.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'SV_S3_ENDPOINT' ) )   define( 'SV_S3_ENDPOINT',   'http://103.216.116.22:8000' );
if ( ! defined( 'SV_S3_REGION' ) )     define( 'SV_S3_REGION',     'us-east-1' );
if ( ! defined( 'SV_S3_BUCKET' ) )     define( 'SV_S3_BUCKET',     'sitevorx-backups' );
// ⚠️ ĐIỀN KEY THẬT Ở BẢN DEPLOY — KHÔNG commit secret vào repo. Để trống ở đây.
// (Bản local đang giữ key staging để test; file này được --skip-worktree nên git bỏ qua.)
if ( ! defined( 'SV_S3_ACCESS_KEY' ) ) define( 'SV_S3_ACCESS_KEY', 'J4JFZLBJDD7RJ13F41ZC' );
if ( ! defined( 'SV_S3_SECRET_KEY' ) ) define( 'SV_S3_SECRET_KEY', 'BKxDpSKhe2yVKYWbHiiPrFoVNdkxcrE6XaptlFOj' );
if ( ! defined( 'SV_S3_PREFIX' ) )     define( 'SV_S3_PREFIX',     '' );
if ( ! defined( 'SV_S3_PATH_STYLE' ) ) define( 'SV_S3_PATH_STYLE', true );

/**
 * Mã hóa phía client — MẶC ĐỊNH TẮT.
 *
 * S3 ở đây chỉ là TẦNG TRUNG CHUYỂN NỘI BỘ của iNET (khách không truy cập trực
 * tiếp), nên gói được đẩy thẳng không mã hóa cho nhanh/gọn. Cô lập giữa các khách
 * dựa vào: namespace theo tài khoản + chặn truy cập chéo trong plugin.
 *
 * Muốn BẬT mã hóa lại (gói trên S3 thành ciphertext) — chỉ cần định nghĩa 1 trong 2,
 * không phải sửa code:
 *  - `SV_BACKUP_ENC_KEY`  = 64-hex (32 byte) hoặc passphrase (→ sha256). Khóa cố định.
 *  - `SV_BACKUP_KEY_API`  = endpoint iNET trả khóa riêng mỗi tài khoản (ký quỹ).
 * ⚠️ Nếu bật, phải giữ khóa BỀN — mất/đổi khóa = không giải mã lại được gói cũ.
 */
if ( ! defined( 'SV_BACKUP_ENC_KEY' ) ) define( 'SV_BACKUP_ENC_KEY', '7b1e66776182e67059bbcc47a17d0580524db39ff6fc4bf9f49af0e667c45352' );
if ( ! defined( 'SV_BACKUP_KEY_API' ) ) define( 'SV_BACKUP_KEY_API', '' );

/**
 * Đo lường cài đặt (telemetry) — xem includes/sv-telemetry.php.
 *
 * Để TRỐNG SECRET = telemetry TẮT (không gửi gì). Bật bằng cách điền:
 *  - SV_TELEMETRY_ENDPOINT : URL backend nhận ping (vd telemetry-server/ingest.php).
 *  - SV_TELEMETRY_SECRET   : chuỗi ngẫu nhiên dài, PHẢI khớp 'secret' trong config.php
 *                            của backend (xem telemetry-server/README.md).
 * ⚠️ Đây là secret → điền ở bản deploy, KHÔNG commit (file này đã --skip-worktree).
 */
if ( ! defined( 'SV_TELEMETRY_ENDPOINT' ) ) define( 'SV_TELEMETRY_ENDPOINT', 'https://thongke.trungtq.io.vn/ingest.php' );
if ( ! defined( 'SV_TELEMETRY_SECRET' ) )   define( 'SV_TELEMETRY_SECRET', '' );
