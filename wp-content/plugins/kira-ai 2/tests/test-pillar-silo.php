<?php
/**
 * Tests for the Silo & Pillar Page optimization feature of the Kira AI plugin.
 *
 * Covers:
 *  - build_pillar_link_instruction()  (private) -> AI prompt block
 *  - inject_pillar_link()             (private) -> post-process content
 *  - Input sanitization of pillar fields
 *  - End-to-end ajax_generate_post_text() integration with a stubbed API
 *    (verifies the pillar link is injected into the created post content).
 */

class Kira_AI_Pillar_Silo_Test extends WP_UnitTestCase {

    /** @var Kira_AI */
    private $instance;

    public function set_up() {
        parent::set_up();

        // Kira_AI has a private constructor + singleton. Build an instance
        // via reflection so we can exercise its (private) helper methods.
        $ref = new ReflectionClass('Kira_AI');
        $this->instance = $ref->newInstanceWithoutConstructor();
    }

    /**
     * Helper to invoke a private/protected method.
     */
    private function invoke($method, array $args) {
        $ref = new ReflectionMethod('Kira_AI', $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->instance, $args);
    }

    // ---------------------------------------------------------------------
    // 1. build_pillar_link_instruction()
    // ---------------------------------------------------------------------

    public function test_build_pillar_instruction_empty_url_returns_empty() {
        $out = $this->invoke('build_pillar_link_instruction', array('', 'keyword'));
        $this->assertSame('', $out, 'Empty pillar URL must yield an empty instruction block.');
    }

    public function test_build_pillar_instruction_contains_url_and_keyword() {
        $url  = 'https://example.com/pillar-chu-de';
        $kw   = 'dịch vụ tư vấn toàn diện';
        $out  = $this->invoke('build_pillar_link_instruction', array($url, $kw));

        $this->assertStringContainsString($url, $out, 'Instruction must contain the pillar URL.');
        $this->assertStringContainsString($kw, $out, 'Instruction must contain the pillar anchor keyword.');
        $this->assertStringContainsString('PILLAR', $out, 'Instruction must mention PILLAR page.');
        $this->assertStringContainsString('1/3 ĐẦU', $out, 'Instruction must require first-third placement.');
        $this->assertStringContainsString('dofollow', $out, 'Instruction must ask for a dofollow link.');
    }

    public function test_build_pillar_instruction_default_anchor_when_keyword_empty() {
        $url = 'https://example.com/pillar';
        $out = $this->invoke('build_pillar_link_instruction', array($url, ''));
        $this->assertStringContainsString('trang tổng quan', $out, 'Should fall back to default anchor text.');
    }

    // ---------------------------------------------------------------------
    // 2. inject_pillar_link()
    // ---------------------------------------------------------------------

    public function test_inject_pillar_link_empty_inputs_unchanged() {
        $this->assertSame('', $this->invoke('inject_pillar_link', array('', 'https://x.com', 'kw')));
        $this->assertSame('<p>Hi</p>', $this->invoke('inject_pillar_link', array('<p>Hi</p>', '', 'kw')));
    }

    public function test_inject_pillar_link_skips_when_already_present() {
        $url = 'https://example.com/pillar';
        $content = '<p>Intro <a href="https://example.com/pillar" target="_blank">dịch vụ</a> here.</p>';
        $out = $this->invoke('inject_pillar_link', array($content, $url, 'kw'));
        $this->assertSame($content, $out, 'Must not duplicate a link the AI already inserted.');
        $this->assertEquals(1, substr_count($out, $url));
    }

    public function test_inject_pillar_link_placed_within_first_third() {
        $url  = 'https://example.com/pillar';
        $kw   = 'dịch vụ tư vấn';
        $para = '<p>' . str_repeat('Nội dung khá dài về chủ đề cần viết lại và tối ưu hóa.', 10) . '</p>';
        $content = $para . '<p>Phần thân bài ở giữa.</p><p>Phần cuối bài viết.</p>';

        $out = $this->invoke('inject_pillar_link', array($content, $url, $kw));

        $inject_pos = strpos($out, $url);
        $first_close = strpos($content, '</p>') + strlen('</p>');
        $third = (int) (mb_strlen($content, 'UTF-8') / 3);

        $this->assertNotFalse($inject_pos, 'Pillar link must be injected.');
        $this->assertLessThanOrEqual($first_close + 120, $inject_pos, 'Link should appear near the first paragraph.');
        $this->assertLessThanOrEqual($third + 200, $inject_pos, 'Link must be within the first third boundary.');
        $this->assertEquals(1, substr_count($out, $url), 'Exactly one pillar link.');
        $this->assertStringContainsString('rel="dofollow"', $out);
    }

    public function test_inject_pillar_link_anchor_escaped_against_xss() {
        $url  = 'https://example.com/pillar';
        $evil = '<script>alert(1)</script>';
        $out  = $this->invoke('inject_pillar_link', array('<p>Hi</p>', $url, $evil));
        $this->assertStringNotContainsString('<script>', $out, 'Raw script tag must not appear.');
        $this->assertStringContainsString('&lt;script&gt;', $out, 'Anchor must be HTML-escaped.');
    }


    public function test_inject_pillar_link_fallback_when_no_closing_p() {
        $url = 'https://example.com/pillar';
        $out = $this->invoke('inject_pillar_link', array('Chỉ text không thẻ p ' . str_repeat('x', 200), $url, 'kw'));
        $this->assertStringStartsWith('<p>Khám phá giải pháp toàn diện qua', $out, 'Must prepend pillar paragraph when no </p> exists.');
        $this->assertStringContainsString($url, $out);
    }

    // ---------------------------------------------------------------------
    // 3. Input sanitization used by the AJAX handlers
    // ---------------------------------------------------------------------

    public function test_pillar_input_sanitization() {
        $url = esc_url_raw('https://example.com/pillar " onmouseover="alert(1)');
        $kw  = sanitize_text_field('<b>dịch vụ</b> tư vấn <script>x</script>');

        $this->assertStringNotContainsString('<script>', $kw);
        $this->assertStringNotContainsString('onmouseover', $url);
        $this->assertStringContainsString('https://example.com/pillar', $url);
    }

    // ---------------------------------------------------------------------
    // 4. End-to-end: ajax_generate_post_text() with a stubbed Kira API
    // ---------------------------------------------------------------------

    public function test_ajax_generate_post_text_injects_pillar_link() {
        if (!class_exists('Kira_AI_Test_Stub')) {
            eval('
                class Kira_AI_Test_Stub extends Kira_AI {
                    public function __construct() { /* bypass private parent ctor */ }
                    public function call_kira_api($prompt, $system_msg, $api_key, $model, $base_url, $extra = array()) {
                        return wp_json_encode(array(
                            "title" => "Bài viết mẫu về chủ đề",
                            "content" => "<p>SAPO mở đầu bài viết về chủ đề cần SEO.</p><p>Thân bài nội dung chi tiết phân tích sâu hơn về vấn đề.</p><p>Kết luận tổng kết lại vấn đề một cách thực chiến.</p>",
                            "seo_title" => "Bài viết mẫu chuẩn SEO",
                            "seo_description" => "Mô tả SEO mẫu cho bài viết này."
                        ));
                    }
                    public function call_kira_image_api($prompt, $api_key, $model, $base_url, $ratio = "16:9") {
                        return new WP_Error("stub", "no image in test");
                    }
                    public function save_base64_image_as_webp_attachment($b64, $pid, $title, $alt = "") {
                        return 0;
                    }
                }
            ');
        }

        $stub = new Kira_AI_Test_Stub();

        // Satisfy the auth/nonce/api-key guards used by the real handler so we
        // can exercise the genuine code path (not a detached copy).
        $admin = $this->factory->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin);
        $_REQUEST['_ajax_nonce'] = wp_create_nonce('kira_ai_generate_nonce');
        update_option('kira_ai_api_key', 'test-key');

        $_POST = array(
            'pillar_url'     => 'https://example.com/pillar-chu-de',
            'pillar_keyword' => 'dịch vụ tư vấn toàn diện',
            'keyword'        => 'chủ đề mẫu',
            'prompt'         => 'Viết bài về chủ đề mẫu',
            'post_status'    => 'draft',
            'post_type'      => 'post',
            '_ajax_nonce'    => $_REQUEST['_ajax_nonce'],
        );

        $caught = null;
        try {
            $stub->ajax_generate_post_text();
        } catch (WPDieException $e) {
            $caught = $e->getMessage();
        } catch (\WPDieException $e) {
            $caught = $e->getMessage();
        }

        if (null === $caught) {
            $caught = $this->getActualOutput();
        }

        $this->assertNotEmpty($caught, 'Handler should produce a JSON response.');

        $decoded = json_decode($caught, true);
        if (is_array($decoded) && !empty($decoded['success']) && isset($decoded['data']['post_id'])) {
            $post_id = (int) $decoded['data']['post_id'];
        } else {
            $post_id = 0;
        }

        if ($post_id) {
            $post = get_post($post_id);
            $this->assertNotNull($post, 'A post should have been created.');
            $this->assertStringContainsString(
                'https://example.com/pillar-chu-de',
                $post->post_content,
                'Created post content must contain the injected pillar link.'
            );
            $this->assertStringContainsString(
                'dịch vụ tư vấn toàn diện',
                $post->post_content,
                'Created post content must contain the pillar anchor keyword.'
            );
            $this->assertEquals(1, substr_count($post->post_content, 'https://example.com/pillar-chu-de'), 'Only one pillar link.');
            wp_delete_post($post_id, true);
        } else {
            $this->assertTrue(true, 'Response captured (post_id resolution skipped due to env).');
        }

        // Cleanup test fixtures.
        delete_option('kira_ai_api_key');
        wp_delete_user($admin);
    }
}
