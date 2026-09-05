# Kira AI — Tests (Silo & Pillar Page)

Bộ test kiểm tra tính năng **Silo & Pillar Page** của plugin Kira AI:

- `build_pillar_link_instruction()` — tạo block prompt AI (bắt buộc chèn link Pillar vào 1/3 đầu).
- `inject_pillar_link()` — hậu-xử lý PHP đảm bảo link Pillar nằm 1/3 đầu, không trùng lặp, escape XSS.
- Sanitize input `pillar_url` / `pillar_keyword`.
- **End-to-end**: gọi thật `ajax_generate_post_text()` (đã stub API mạng) và assert bài viết được tạo có chứa link Pillar.

## Cách 1 — Chạy trong môi trường WordPress thực tế (khuyên dùng)

Cần [WP-CLI scaffold](https://make.wordpress.org/cli/handbook/plugin-unit-tests/) hoặc `wp-phpunit`.

```bash
# 1. Cài test scaffold (1 lần)
cd /path/to/wp
wp scaffold plugin-tests kira-ai
# hoặc thiết lập WP_TESTS_DIR thủ công, rồi:
bin/install-wp-tests.sh wordpress_test root '' localhost latest

# 2. Cài thư viện PHPUnit
cd /path/to/wp/wp-content/plugins/kira-ai
composer install

# 3. Chạy test
WP_TESTS_DIR=/path/to/wp-tests/includes phpunit
# hoặc nếu dùng wp-phpunit:
vendor/bin/phpunit
```

Khi `WP_TESTS_DIR` được thiết lập, `tests/bootstrap.php` sẽ load WordPress test core
(`WP_UnitTestCase`, factory, DB tạm) và chạy cả test logic lẫn test end-to-end thật.

## Cách 2 — Chạy nhanh không cần WordPress (standalone fallback)

`tests/bootstrap.php` tự định nghĩa các hàm WP tối thiểu (`esc_url`, `esc_html`,
`sanitize_text_field`, `wp_kses_post`, `WP_Error`, `WP_UnitTestCase` polyfill...).
Chỉ các test **logic thuần** (helper, sanitize) mới chạy; test end-to-end sẽ bỏ qua
phần tạo post và chỉ assert response JSON được bắt.

```bash
cd /path/to/kira-ai
composer install          # cài phpunit + polyfills
phpunit                   # không cần WP_TESTS_DIR
```

## Kết quả kỳ vọng

```
Kira_AI_Pillar_Silo_Test
 ✔ test_build_pillar_instruction_empty_url_returns_empty
 ✔ test_build_pillar_instruction_contains_url_and_keyword
 ✔ test_build_pillar_instruction_default_anchor_when_keyword_empty
 ✔ test_inject_pillar_link_empty_inputs_unchanged
 ✔ test_inject_pillar_link_skips_when_already_present
 ✔ test_inject_pillar_link_placed_within_first_third
 ✔ test_inject_pillar_link_anchor_escaped_against_xss
 ✔ test_inject_pillar_link_fallback_when_no_closing_p
 ✔ test_pillar_input_sanitization
 ✔ test_ajax_generate_post_text_injects_pillar_link   (end-to-end, cần WP env)
```

## Ghi chú

- Test end-to-end override `call_kira_api` / `call_kira_image_api` qua class
  `Kira_AI_Test_Stub` (dùng `eval`) để không gọi mạng thật, đồng thời vẫn đi qua
  toàn bộ logic thật của handler (`check_ajax_referer`, `current_user_can`,
  `inject_pillar_link`, `wp_insert_post`).
- Mỗi test tự dọn (xóa post / option / user) nên không để lại rác trong DB test.
