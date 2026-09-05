=== Sitevorx Pro ===
Contributors: inetcorp
Tags: optimization, security, smtp, cleanup, maintenance, premium-themes, rankmath
Requires at least: 5.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full-featured WordPress toolkit — optimization, security, SMTP, disk cleanup, maintenance monitoring, Premium Themes and Rank Math SEO Pro for VIP customers.

== Description ==

**Sitevorx Pro** is the full-featured edition of Sitevorx — an all-in-one WordPress plugin for site optimization, security hardening, SMTP configuration, disk cleanup, and maintenance monitoring. The Pro edition adds a **Premium** tab with MyThemeShop licensed themes and one-click Rank Math SEO Pro installation, exclusively for iNET hosting customers.

= Premium — MyThemeShop Themes (109+ Themes) =
* **1-click install** licensed MyThemeShop premium themes.
* Auto-activate license after installation.
* Search, filter (Installed / Not Installed), and paginate themes.
* Quick-activate button for already-installed themes.

= Premium — Rank Math SEO Pro =
* **1-click install & activate** Rank Math Free + Pro from a single button.
* Auto-inject license key from iNET API.
* Auto-enable core Pro SEO modules (sitemap, rich-snippet, WooCommerce, etc.).

= Speed Optimization & Security =
* **Malware Scanner**: Scan your entire codebase and database for suspicious injections.
* **Database Cleanup**: Remove revisions, spam comments, expired transients in one click.
* **System Tweaks**: Lazy load images, limit revisions, disable Heartbeat API, allow safe SVG uploads.
* **Google reCAPTCHA v2**: Protect your login form from bots.
* **Login Attempt Limiter**: Lock out IPs after repeated failed login attempts.
* **Secret Login URL**: Hide the default `wp-login.php` with a custom keyword.
* **Disable XML-RPC**: Block DDoS and brute-force attacks via XML-RPC.
* **Disable File Editor**: Prevent code editing from the WordPress dashboard.

= SMTP Configuration =
* Send emails via **Gmail** (App Password) or a **custom SMTP server** (SSL/TLS).
* Built-in **Test Email** sender.
* Email delivery log with success/failure tracking.
* Force From Name and From Email to prevent address drift.

= Website Utilities =
* Inject tracking codes in **Header/Footer** (Google Analytics, Facebook Pixel, etc.).
* **Content Protection**: Disable right-click, text selection, and drag-and-drop.
* **Maintenance Mode**: Display a professional "under construction" page to visitors.
* **Custom Login Logo**: Replace the WordPress logo on the login screen with your own brand.

= Disk Space Manager =
* Recursively scan your hosting for large files (>50 MB).
* Auto-categorize files (backups, error logs, large media).
* Bulk delete to free up disk space instantly.

= Floating Contact Buttons =
* **Phone Hotline** button with animated icon.
* **Zalo** chat button (auto-opens Zalo app).
* **Messenger** chat button (m.me deep link).
* Fully responsive floating widget in the corner of your site.

= Import / Export Settings =
* **Export** all Sitevorx settings as a JSON file.
* **Import** settings from another site in one click.
* **Reset** all settings to factory defaults.

= Scheduled Cleanup (WP-Cron) =
* Automatic cleanup: daily, twice daily, or weekly.
* Clears temp files, auto-drafts, spam, and optimizes database tables.
* Activity log showing the last 20 cleanup runs.

= Maintenance & Update Monitor =
* Track plugins and themes that need updating.
* Check WordPress core, PHP version, SSL status, and WP_DEBUG.
* Maintenance health score with actionable recommendations.

= Support Center =
* 24/7 Hotline contact.
* Ticket system for advanced technical support.
* Link to helpdesk documentation library.

= Server Info =
* View Web Server, PHP, MySQL, and WordPress versions at a glance.
* PHP limits: memory, execution time, input vars, upload size.
* List all loaded PHP extensions.
* Database size monitoring.

== External Services ==

= Google reCAPTCHA =
Sitevorx Pro can optionally integrate with Google reCAPTCHA v2 to protect the WordPress login form. This feature is disabled by default and only works when an administrator explicitly enables it and provides valid API keys.

When enabled, the plugin loads the Google reCAPTCHA JavaScript on the login screen and sends the generated verification token to Google's verification endpoint during login validation.

This service is provided by Google:
* Service URL: https://www.google.com/recaptcha/
* Terms of Service: https://policies.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

= Google Translate =
When an administrator switches the Sitevorx Pro interface to English, the plugin can optionally send untranslated Vietnamese interface strings to Google Translate. This fallback is disabled until the administrator explicitly confirms the external-service prompt.

This service is provided by Google:
* Service URL: https://translate.googleapis.com/
* Terms of Service: https://policies.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

= iNET Premium Services =
Sitevorx Pro connects to iNET servers (theme.trungtq.io.vn) for MyThemeShop theme downloads, MTS license key retrieval, and Rank Math SEO Pro installation. These features are only available on iNET hosting and require server-side IP verification.

== Highlights ==

* **All-in-one**: Replaces 5-7 single-purpose plugins (SMTP, Security, Optimization, Cleanup, Maintenance).
* **Premium Features**: MyThemeShop themes + Rank Math SEO Pro in a unified Premium tab.
* **Modern UI**: Gradient banners, collapsible sidebar, toast notifications, fully responsive.
* **Secure by design**: Nonce verification, input sanitization, CSRF protection, prepared database queries.
* **Lightweight**: Modular architecture — only loads what you use. Zero frontend impact. No Composer or NPM required.
* **Localized**: Full Vietnamese (vi) translation included via .po/.mo files.

== Installation ==

1. Upload the `Sitevorx-Pro` folder to `/wp-content/plugins/`, or install the ZIP file via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to the **Sitevorx Pro** menu item in your admin sidebar.

== Frequently Asked Questions ==

= Does this plugin conflict with WP Mail SMTP? =
Yes, both plugins hook into `phpmailer_init`. We recommend deactivating other SMTP plugins before using Sitevorx Pro's built-in SMTP module.

= Does it detect real IPs behind Cloudflare? =
Yes. Sitevorx Pro reads the `CF-Connecting-IP` header to identify the real visitor IP behind Cloudflare's proxy.

= I forgot my secret login URL. How do I get back in? =
Open phpMyAdmin (or any database tool), find the `wp_options` table, and delete the row where `option_name` is `sv_sec_login_key`. Then access `/wp-login.php` as usual.

= Why are Premium features locked? =
MyThemeShop themes and Rank Math SEO Pro are exclusively available on iNET hosting. The plugin auto-detects the hosting environment via IP range verification.

== Changelog ==

= 1.3.0 =
* **Mới: Di Chuyển Website (qua S3).** Thay cho trang "Sao chép Website" (export ra .zip rồi tự tải/upload tay), nay chuyển host tự động qua một tầng trung chuyển S3 nội bộ của iNET: ở hosting cũ bấm **Tạo gói di chuyển** → đóng gói database + toàn bộ `wp-content` và đẩy lên kho; ở hosting mới bấm **Khôi phục** → tự kéo về, dựng lại site và tự đổi URL (search-replace có xử lý dữ liệu PHP-serialized). Khách không cần nhập thông tin kết nối, không tải file về máy.
* **Tự dọn, không tồn kho:** gói tự xóa khỏi kho ngay sau khi khôi phục thành công; gói tạo nhưng không dùng tự hết hạn sau 3 ngày.
* **Quy mô lớn:** S3 client tự ký AWS Signature V4 (không bundle SDK), hỗ trợ endpoint S3-compatible/RadosGW (path-style), upload multipart resume được; mỗi tài khoản hosting có vùng riêng (namespace theo tài khoản) và chặn truy cập chéo giữa các tài khoản. Khôi phục chạy theo lô, không phụ thuộc phiên đăng nhập nên không gãy khi import database, và không ghi đè chính plugin đang chạy.
* **Removed: trang "Sao chép Website" cũ** (`includes/sv-migrate.php`) và cron dọn thư mục tạm tương ứng.

= 1.2.2 =
* **Fixed: "Liên kết bạn theo dõi đã hết hạn"** khi click tải file `.zip` ngay sau khi export xong. Nguyên nhân: `wp_generate_password()` ở init trả về chuỗi hỗn hợp hoa-thường, nhưng `sanitize_key()` trong download handler tự lowercase → action string của nonce ở 2 đầu khác case → `wp_verify_nonce` (so sánh PHP string case-sensitive) fail. Job_id giờ được `strtolower()` ngay khi tạo nên hai đầu luôn khớp.
* **Performance: time-budget loop trong AJAX step** — 1.2.0/1.2.1 mỗi request chỉ xử lý 40 file rồi return → site 5000 file = 125+ round trip HTTP (×200-500ms RTT shared hosting = vài chục giây overhead bootstrap thuần). 1.2.2 lặp nội tuyến trong cùng 1 PHP process tới khi sắp chạm `SV_MIGRATE_STEP_BUDGET_SEC = 15s` rồi mới trả progress cho JS. Giảm số round trip 5-10×.
* **Performance: skip deflate cho media đã nén** — JPEG/PNG/MP4/PDF/ZIP/WebP… giờ append vào archive với `ZipArchive::CM_STORE` (không gzip). Codec gốc đã ép bớt redundancy rồi, gzip thêm chỉ tốn CPU mà giảm ~0% size. Trên site WordPress media-heavy (đa số), bước nén file nhanh hơn 3-5×.

= 1.2.1 =
* **Security (Critical)** — file backup `.zip` ở 1.2.0 đặt thẳng vào `wp-content/uploads/sitevorx-migrate/{job_id}/...` với job_id chỉ 8 ký tự, không có auth gate → khả năng đoán URL và tải full DB hash. 1.2.1: download chuyển sang endpoint `admin-post.php?action=sv_migrate_download` có `manage_options` cap + nonce + per-job binding, stream qua PHP. Job_id tăng lên 32 ký tự + thêm `.htaccess` (Apache) / `web.config` (IIS) deny ở root `sitevorx-migrate/` làm defense-in-depth.
* **Stability** — file inventory chuyển từ transient sang JSON trên disk (`{tmp_dir}/files.json`). 1.2.0 nhồi cả mảng path vào transient → site 50k file (5GB media) làm row `wp_options` vượt `max_allowed_packet` → `set_transient()` fail silent, job chết ngay sau init. Giờ chỉ lưu metadata ngắn trong transient.
* **Performance** — DB dump dùng cursor pagination (`WHERE pk > $last_pk ORDER BY pk LIMIT N`) khi bảng có primary key integer; fallback OFFSET khi không có PK. 1.2.0 dùng OFFSET cố định → O(n²) trên InnoDB nên bảng `wp_postmeta` triệu row mất hàng phút mỗi chunk thứ 1000+. Giờ là O(n).
* **Correctness** — multisite: drop logic match `base_prefix` (1.2.0 dùng OR base_prefix → sub-site export nuốt nhầm bảng của các sub-site khác trong network). Giờ chỉ match `$wpdb->prefix` đúng sub-site hiện tại; có notice trong response init nếu phát hiện multisite.
* Loại trừ thêm: `node_modules`, `.git`, `.svn`, `.hg`, `.idea`, `.vscode` ở mọi vị trí trong cây thư mục (1.2.0 bỏ sót, kéo theo dev artifacts vào archive).

= 1.2.0 =
* New: trang **Sao chép Website** (`includes/sv-migrate.php`) — exporter chunked AJAX đóng gói database + `wp-content/{uploads,themes,plugins,mu-plugins}` thành 1 file `.zip` duy nhất, kèm `manifest.json` chứa marker `sitevorx-pro-migrate-v1` + fingerprint anti-clone gốc, để chuyển sang hosting iNET khác hoặc lưu sao lưu offline.
* Exporter chạy theo lô qua 3 endpoint AJAX (init / step / finalize) — mỗi step xử lý 40 file hoặc 1 bảng DB rồi trả tiến độ, nên không bị timeout trên hosting có max_execution_time thấp. Có thanh progress, link tải về và nút dọn dẹp file tạm.
* DB dump streaming-friendly: chunk 500 row/lần qua `SELECT … LIMIT/OFFSET`, ghi thẳng INSERT vào `database.sql` không nạp full table vào memory.
* Tự loại trừ thư mục cache nặng (cache, wflogs, litespeed, et-cache, w3tc-config) và file rác (.DS_Store, Thumbs.db, error_log, debug.log) để bản sao gọn nhất có thể.
* Phần **Import** (giải nén + restore DB + serialized search-replace URL + auto-reset fingerprint anti-clone) sẽ ra ở bản 1.2.1 — phiên bản hiện tại chỉ Export.

= 1.1.3 =
* Fixed: mỗi lần chuyển tab trong trang Sitevorx Pro, notice của các plugin bên thứ 3 (Rank Math, CartFlows Cart Abandonment, SureForms…) bị "cướp" và hiện lại dưới dạng toast Sitevorx ở góc phải, không thể dismiss vĩnh viễn vì nút X của toast chỉ remove DOM mà không gọi AJAX dismiss của plugin gốc.
* JS selector trong `assets/js/sv-admin.js` thu hẹp từ `.notice` thành `.notice.sv-notice` — chỉ notice do Sitevorx Pro phát ra (gắn class marker `sv-notice`) mới được convert thành toast. Notice của plugin khác giữ nguyên dạng banner WP chuẩn để dismiss flow của họ hoạt động bình thường.
* PHP: thêm class `sv-notice` vào toàn bộ 40 notice dismissible của Sitevorx Pro trên 11 file để duy trì hành vi toast cũ cho các thông điệp save settings / install theme / cleanup / re-verify hosting.

= 1.1.2 =
* Fixed: sau khi cập nhật từ 1.1.0 → 1.1.1, transient `sv_hosting_check` cũ (verdict `'no'` cache 12h) vẫn được tin dùng nên Premium tab tiếp tục bị khóa dù logic phát hiện iNET đã đúng. Plugin nay tự xoá transient này mỗi khi `SV_PLUGIN_VERSION` thay đổi giữa các request (tracked qua option `sv_plugin_version_seen`), nên mọi bản nâng cấp tương lai sẽ tự reset verdict mà không cần admin can thiệp.

= 1.1.1 =
* Fixed: Premium tab bị khóa trên một số máy chủ iNET dù IP server thuộc dải iNET (vd. `103.57.220.0/22`). Nguyên nhân: trên shared/clustered hosting có reverse-proxy nội bộ, `$_SERVER['SERVER_ADDR']` trả về IP private RFC1918 (10.x / 127.0.0.1) thay vì IP public, nên không match CIDR allowlist.
* `sv_is_inet_hosting()` giờ thu thập ứng viên IP từ 4 nguồn — `SERVER_ADDR`, `LOCAL_ADDR`, `gethostbyname(gethostname())`, và A-record của domain site (`dns_get_record` hoặc `gethostbyname` fallback) — và pass nếu **bất kỳ** ứng viên nào nằm trong dải iNET.
* Negative cache `sv_hosting_check` giảm từ 12h → 1h để khách hồi phục nhanh sau khi sửa cấu hình DNS/proxy; positive verdict vẫn cache 12h như cũ.
* Filter `sv_is_inet_hosting_result` (debug mode) nay nhận mảng `$candidates` thay vì `$server_ip` đơn lẻ.

= 1.1.0 =
* Đồng bộ toàn bộ thay đổi từ Sitevorx Free 1.1.0 sang Pro: trang **Trung tâm Bảo mật** mới (6 tab Tổng Quan / Cấu Hình / Quét Mã Độc / Security Headers / Giám Sát / Kiểm Tra), tách khỏi trang Tối ưu Tốc Độ.
* New: HTTP Security Headers + HSTS (max-age / includeSubDomains) — chỉ áp dụng frontend, có nút "Kiểm tra ngay" gọi `home_url()` để xem header server thực tế trả về.
* New: Login Honeypot, User Enumeration Protection, Email báo admin login (HTML body, có nút "Gửi thử"), Core Integrity Checker đối chiếu MD5 với api.wordpress.org.
* New: tab Kiểm Tra → mục "Sửa quyền file quan trọng" — đổi chmod `wp-config.php` / `.htaccess` trực tiếp qua AJAX, có fallback gợi ý lệnh SSH khi PHP-FPM không có quyền.
* New: 5 toggle "Gỡ thành phần không cần thiết" trong Tối ưu Tốc Độ — Tắt Emoji, Tắt nhúng link tự động, Bỏ thư viện JavaScript cũ, Ẩn thông tin phiên bản WordPress, Tắt thông báo liên kết tự động.
* UI: trang "Tối ưu & Bảo mật" tách thành "Tối ưu Tốc Độ" (chỉ tăng tốc) + "Trung tâm Bảo mật" (mọi thứ bảo mật). Sidebar và dashboard cập nhật theo.
* i18n: bổ sung gần 400 chuỗi tiếng Anh vào `sitevorx-en_US.mo` để site chạy English không còn rơi về tiếng Việt.

= 1.0.8 =
* Ported the Sitevorx Free 1.0.8 → 1.0.11 hardening + UX work into Pro.
* New: Audit Log submenu "Nhật ký Kiểm toán" (Sitevorx Pro → Nhật ký Kiểm toán) recording sensitive admin actions — settings save (Tăng tốc / Bảo mật / Tiện ích / SMTP / Cron schedule / Import / Reset), SMTP test, SMTP log clear, manual cleanup, malware scan, disk-cleaner file deletion, login lockout, manual unlock. Option-backed ring buffer of 200 entries in `sv_audit_log`, no new database table. Factory reset preserves the audit trail; uninstall drops it.
* New: configurable login lockout — admin-tunable max attempts (3–50, default 5) and lockout duration (5 min to 7 days, default 24h), plus newline-separated IPv4/IPv6 allowlist so a trusted admin IP is never counted.
* New: "IP đang bị khóa" diagnostics panel under Tối ưu & Bảo mật → Bảo Mật & Tường Lửa lists each currently-locked entry (hash + attempt count + expiry) with a per-row Unlock button. Unlock is gated by `manage_options` + the existing `sv_opt_nonce` and writes `login_unlock` to the audit log.
* Fixed: the old login limiter had two `wp_login_failed` handlers (one writing the transient for 24h, one re-writing it for 1h) — they raced. The new lockout writer is a single source of truth honoring the configured duration.
* Dashboard: health summary now reflects runtime state and surfaces actionable links — option-vs-runtime mismatches (cron enabled but no scheduled tick, SMTP chosen but missing credential, reCAPTCHA on but no Site/Secret key) get a red badge; new detections include `DISALLOW_WP_CRON` in wp-config, SMTP failures in the last 24h, currently-locked IP count, active Maintenance Mode and lingering WP_DEBUG. Each issue carries a "→" deep-link to the matching settings page.
* Dashboard: SMTP and Cron status cards switch to a red "Thiếu credential" / "Lỗi lịch" badge when option/runtime mismatch is detected; the health score no longer counts a broken cron or a credentials-less mailer as a passing check.
* Audit log: the "Ngữ cảnh" column shows what changed (e.g. "Bật Khóa XML-RPC, Tắt reCAPTCHA đăng nhập, Đổi thời gian khóa (phút)") instead of dumping the full post-save flag state. New helper `sv_audit_summarize_diff()` for future modules.
* Audit log: split "Lưu cấu hình Tối ưu & Bảo mật" into two events — "Lưu cấu hình Tăng tốc Website" (Tăng Tốc tab) and "Lưu cấu hình Bảo mật & Tường lửa" (Bảo Mật tab).
* Hardening: SMTP "Xóa Log" handler now uses a parameterized `DELETE FROM` with table-name identifier check instead of the previous raw `TRUNCATE TABLE` concat.

= 1.0.7 =
* i18n: restored Vietnamese diacritics in 13 mojibake-encoded fallback strings inside `assets/js/sv-admin.js` (modal confirm, plugin-update toast, "Đang cập nhật…" / "Đã cập nhật" / "Phiên bản hiện tại" labels). Previously displayed as `XÃ¡c nháº­n` etc. when `svToolkit.i18n` wasn't loaded yet.
* Hardening: SMTP log listing now uses `$wpdb->prepare()` for the LIMIT clause (parity with Free 1.0.8 fix) so SQL-injection scanners stop flagging the function-call table-name concat.
* Hardening: removed PHP `@` error suppression on the malware scanner's `file_get_contents()`; the scanner now checks `is_readable()` first and still gracefully skips unreadable files.
* Hardening: removed `@unlink()` after `download_url()` in Premium themes and Rank Math Pro installers — both now use `wp_delete_file()` guarded by `file_exists()` so failures surface in the WP error log instead of being silently swallowed.
* Hardening: removed `@set_time_limit()` suppression in the Rank Math installer — the return value is already checked, so the suppression was dead.
* Removed: runtime `.po → .mo` translation compiler that wrote `languages/sitevorx-en_US.mo` into the plugin folder. The bundled `.mo` is the only source of English strings now (the compiled file is wiped on every plugin update, so the runtime-write pattern was unreliable anyway).

= 1.0.6 =
* Removed the Security Center module from the admin UI and runtime loader to avoid overlap with the existing Optimizer & Security hardening controls.
* Disabled the unfinished WAF, 2FA, Security Headers, and Activity Log hooks by no longer loading the Security Center module.

= 1.0.5 =
* Fixed MyThemeShop Connect handoff by writing `mts_connect_data` to the site-option storage used by MyThemeShop Connect 3.x.
* Preload the MTS license before MyThemeShop Connect initializes so the Connect page no longer shows a false disconnected state.
* Accept multiple username/email field names from the iNET license API, refresh legacy fallback identity data on Premium/MTS pages, and allow explicit `SV_MTS_USERNAME` / `SV_MTS_EMAIL` overrides.

= 1.0.4 =
* Persist MyThemeShop license data before Pro deactivation so switching to the Free edition does not immediately drop the license.
* Fixed raw MTS license detection so the transient-backed runtime filter no longer masks a missing database option.

= 1.0.3 =
* Hardened anti-clone handling with grace period, admin re-verify, and delayed revoke.
* Changed Heartbeat optimization from full disable to safe throttling.
* Disabled both Theme and Plugin file editors when source-code editing lock is enabled.
* Added explicit opt-in and bounded cache for Google Translate fallback.
* Avoid rendering Premium theme inventory before iNET hosting verification.
* Added trusted proxy support for login rate limiting and safer scheduled database cleanup.

= 1.0.1 =
* Fixed Premium theme search/filter/pagination interactions.
* Hardened Rank Math install flow so failed installs/activations are reported correctly.
* Reduced false-positive MTS theme lockouts on private/NAT server IPs.

= 1.0.0 =
* Initial release of Sitevorx Pro.
* Synchronized full codebase from Sitevorx (free edition) with sv_ prefix convention.
* Premium tab: unified MyThemeShop themes + Rank Math SEO Pro under a single interface.
* Support Center page with Hotline, Ticket system, and Helpdesk links.
* iNET hosting auto-detection via IP range and hostname checks.
* Full security audit: nonce verification, capability checks, input sanitization on all forms.
* Malware scanner for files and database.
* System optimizer with scheduled WP-Cron cleanup.
* Maintenance & Update monitor module.
* Modern Flex/Grid responsive dashboard UI with health score, storage bars, and feature cards.
* Complete Vietnamese localization with auto-compile .po to .mo.
