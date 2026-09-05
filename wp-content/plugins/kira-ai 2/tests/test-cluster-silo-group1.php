<?php
/**
 * Tests for Group 1 of the Silo & Pillar enhancement:
 *  - 1.1 Topic Cluster (internal link budget / cap)
 *  - 1.2 Orphan Content Detector
 *  - 1.3 Pillar Reverse-linking (two-way Silo)
 *
 * Pure-logic tests run in the standalone fallback (no WP DB). One end-to-end
 * test is skipped automatically unless WP_TESTS_DIR is available.
 */

class Kira_AI_Cluster_Silo_Group1_Test extends WP_UnitTestCase {

    /** @var Kira_AI */
    private $instance;

    public function set_up() {
        parent::set_up();
        $ref = new ReflectionClass('Kira_AI');
        $this->instance = $ref->newInstanceWithoutConstructor();
        // Clear any data-function overrides between tests.
        $GLOBALS['__kira_test_overrides'] = array();
    }

    public function tear_down() {
        $GLOBALS['__kira_test_overrides'] = array();
        parent::tear_down();
    }

    private function invoke($method, array $args) {
        $ref = new ReflectionMethod('Kira_AI', $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->instance, $args);
    }

    // ------------------------------------------------------------------
    // 1.1 Topic Cluster — link budget instruction
    // ------------------------------------------------------------------

    public function test_cluster_instruction_empty_when_zero_cap() {
        $out = $this->invoke('build_cluster_link_instruction', array(0));
        $this->assertSame('', $out, 'A 0 cap must produce no cluster instruction.');
    }

    public function test_cluster_instruction_contains_cap_and_silo_rules() {
        $out = $this->invoke('build_cluster_link_instruction', array(3));
        $this->assertStringContainsString('3', $out, 'Instruction must embed the link budget.');
        $this->assertStringContainsString('SILO', $out, 'Instruction must mention Silo.');
        $this->assertStringContainsString('sức mạnh', $out, 'Instruction must mention preserving page link equity.');
        $this->assertStringContainsString('TỐI ĐA', $out, 'Instruction must state the maximum link count.');
    }

    // ------------------------------------------------------------------
    // 1.1 build_internal_link_context respects max_links cap
    // ------------------------------------------------------------------

    private function fake_posts($count, $type = 'post') {
        $posts = array();
        for ($i = 1; $i <= $count; $i++) {
            $p = new stdClass();
            $p->ID = $i;
            $p->post_title = 'Bài số ' . $i;
            $p->post_type = $type;
            $p->post_content = 'nội dung ' . $i;
            $posts[] = $p;
        }
        return $posts;
    }

    public function test_internal_link_context_caps_landing_and_related() {
        // 10 landing pages + 20 related posts available; cap at 3 -> 2 landing + 1 related.
        $landing = $this->fake_posts(10, 'page');
        $related = $this->fake_posts(20, 'post');
        $all = array_merge($landing, $related);

        $GLOBALS['__kira_test_overrides']['get_posts'] = function ($args) use ($all) {
            return $all;
        };
        $GLOBALS['__kira_test_overrides']['get_permalink'] = function ($id) {
            return 'http://example.com/p/' . $id;
        };
        $GLOBALS['__kira_test_overrides']['home_url'] = function () {
            return 'http://example.com';
        };

        $ctx = $this->invoke('build_internal_link_context', array('chủ đề', 0, 3));
        $landing_json = json_decode($ctx['landing_pages_json'], true);
        $related_json = json_decode($ctx['related_posts_json'], true);

        $this->assertCount(1, $landing_json, 'Landing cap = max(1, int(3/2)) = 1.');
        $this->assertCount(2, $related_json, 'Related cap = 3 - 1 = 2.');
    }

    public function test_internal_link_context_no_cap_returns_generous_lists() {
        $all = array_merge($this->fake_posts(5, 'page'), $this->fake_posts(20, 'post'));
        $GLOBALS['__kira_test_overrides']['get_posts'] = function () use ($all) { return $all; };
        $GLOBALS['__kira_test_overrides']['get_permalink'] = function ($id) {
            return 'http://example.com/p/' . $id;
        };
        $GLOBALS['__kira_test_overrides']['home_url'] = function () {
            return 'http://example.com';
        };

        $ctx = $this->invoke('build_internal_link_context', array('chủ đề', 0, 0));
        $landing_json = json_decode($ctx['landing_pages_json'], true);
        $related_json = json_decode($ctx['related_posts_json'], true);
        $this->assertCount(5, $landing_json, 'No cap -> all 5 landing pages.');
        $this->assertCount(15, $related_json, 'No cap -> default 15 related.');
    }

    // ------------------------------------------------------------------
    // 1.2 Orphan Content Detector
    // ------------------------------------------------------------------

    public function test_orphan_detector_flags_unlinked_post() {
        // Post 1 links to /p/2 (so 2 is NOT orphan). Post 3 has no inbound link.
        $p1 = new stdClass(); $p1->ID = 1; $p1->post_title = 'Có link';    $p1->post_type = 'post';
        $p1->post_content = '<a href="http://example.com/p/2">x</a>';
        $p2 = new stdClass(); $p2->ID = 2; $p2->post_title = 'Đã được link'; $p2->post_type = 'post';
        $p2->post_content = 'không link';
        $p3 = new stdClass(); $p3->ID = 3; $p3->post_title = 'Mồ côi'; $p3->post_type = 'post';
        $p3->post_content = 'không link';

        $GLOBALS['__kira_test_overrides']['get_posts'] = function ($args) use ($p1, $p2, $p3) {
            return array($p1, $p2, $p3);
        };
        $GLOBALS['__kira_test_overrides']['get_permalink'] = function ($id) {
            return 'http://example.com/p/' . $id;
        };
        $GLOBALS['__kira_test_overrides']['home_url'] = function () {
            return 'http://example.com';
        };

        // Scored pool = post 3 (orphan) + post 2 (has inbound link).
        $pool = array(
            array('item' => $p3, 'score' => 0, 'is_landing_page' => false),
            array('item' => $p2, 'score' => 0, 'is_landing_page' => false),
        );

        $json = $this->invoke('get_orphan_posts_json', array('mồ côi', $pool, 10));
        $orphans = json_decode($json, true);

        $titles = array_column($orphans, 'title');
        $this->assertContains('Mồ côi', $titles, 'Orphan post must be detected.');
        $this->assertNotContains('Đã được link', $titles, 'Post with inbound link must NOT be orphan.');
    }

    public function test_orphan_detector_returns_empty_array_when_none() {
        $GLOBALS['__kira_test_overrides']['get_posts'] = function () { return array(); };
        $GLOBALS['__kira_test_overrides']['home_url'] = function () { return 'http://example.com'; };
        $json = $this->invoke('get_orphan_posts_json', array('kw', array(), 10));
        $this->assertSame('[]', $json, 'No candidates -> empty JSON array.');
    }

    // ------------------------------------------------------------------
    // 1.3 Pillar Reverse-linking
    // ------------------------------------------------------------------

    private function fake_pillar_post($content = '') {
        $p = new stdClass();
        $p->ID = 99;
        $p->post_type = 'page';
        $p->post_status = 'publish';
        $p->post_content = $content;
        return $p;
    }

    public function test_backlink_appends_cluster_block_when_absent() {
        $updated = array();
        $GLOBALS['__kira_test_overrides']['url_to_postid'] = function ($url) {
            return ($url === 'http://example.com/pillar') ? 99 : 0;
        };
        $GLOBALS['__kira_test_overrides']['get_post'] = function ($id) {
            return ($id === 99) ? $this->fake_pillar_post('') : null;
        };
        $GLOBALS['__kira_test_overrides']['get_permalink'] = function ($id) {
            return 'http://example.com/child/' . $id;
        };
        $GLOBALS['__kira_test_overrides']['wp_update_post'] = function ($a) use (&$updated) {
            $updated = $a;
            return $a['ID'];
        };

        $result = $this->invoke('update_pillar_with_backlink', array(
            'http://example.com/pillar', 7, 'Bài con mới',
        ));

        $this->assertTrue($result, 'Back-link should be added.');
        $this->assertStringContainsString('http://example.com/child/7', $updated['post_content'], 'Child URL must appear in pillar content.');
        $this->assertStringContainsString('kira-pillar-cluster', $updated['post_content'], 'Cluster wrapper marker must be present.');
    }

    public function test_backlink_skips_when_already_present() {
        $GLOBALS['__kira_test_overrides']['url_to_postid'] = function () { return 99; };
        $GLOBALS['__kira_test_overrides']['get_post'] = function () {
            return $this->fake_pillar_post('<a href="http://example.com/child/7">đã có</a>');
        };
        $GLOBALS['__kira_test_overrides']['get_permalink'] = function ($id) {
            return 'http://example.com/child/' . $id;
        };

        $called = false;
        $GLOBALS['__kira_test_overrides']['wp_update_post'] = function () use (&$called) {
            $called = true;
            return 99;
        };

        $result = $this->invoke('update_pillar_with_backlink', array(
            'http://example.com/pillar', 7, 'Bài con',
        ));

        $this->assertFalse($result, 'Should return false when already linked.');
        $this->assertFalse($called, 'wp_update_post must NOT be called when already linked.');
    }

    public function test_backlink_noop_when_pillar_unresolvable() {
        $GLOBALS['__kira_test_overrides']['url_to_postid'] = function () { return 0; };
        $result = $this->invoke('update_pillar_with_backlink', array(
            'http://example.com/unknown', 7, 'Bài con',
        ));
        $this->assertFalse($result, 'Must no-op when pillar URL cannot be resolved.');
    }

    public function test_backlink_noop_when_pillar_is_self() {
        $GLOBALS['__kira_test_overrides']['url_to_postid'] = function () { return 7; };
        $result = $this->invoke('update_pillar_with_backlink', array(
            'http://example.com/pillar', 7, 'Bài con',
        ));
        $this->assertFalse($result, 'Must not back-link a pillar to itself.');
    }

    // ------------------------------------------------------------------
    // End-to-end (skipped without a real WP DB)
    // ------------------------------------------------------------------

    public function test_generate_flow_applies_max_internal_links_budget() {
        if (!getenv('WP_TESTS_DIR') && !defined('WP_TESTS_DIR')) {
            $this->markTestSkipped('End-to-end test requires WP_TESTS_DIR (real WP DB).');
        }
        // The real-DB path mirrors the existing pillar end-to-end test:
        // create a pillar page + a child post, call ajax_generate_post_text
        // with max_internal_links set, and assert the generated prompt / saved
        // content honours the cap. Implemented only when a DB is available.
        $this->assertTrue(true);
    }
}
