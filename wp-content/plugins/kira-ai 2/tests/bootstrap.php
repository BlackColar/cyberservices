<?php
/**
 * Bootstrap for Kira AI plugin tests.
 *
 * Supports two modes:
 *  1. WordPress official test environment (WP_TESTS_DIR / wp-phpunit).
 *     Set WP_TESTS_DIR and run via:  phpunit
 *  2. Standalone fallback (no WP core) — only the pure-logic tests that
 *     stub the few WP functions used by the helper methods will run.
 */

if (getenv('WP_TESTS_DIR') || defined('WP_TESTS_DIR')) {
    $wp_tests_dir = getenv('WP_TESTS_DIR') ?: WP_TESTS_DIR;

    // Load the WP test bootstrap (defines WP_UnitTestCase, factories, etc.).
    if (file_exists($wp_tests_dir . '/includes/functions.php')) {
        require_once $wp_tests_dir . '/includes/functions.php';
    }
    if (file_exists($wp_tests_dir . '/includes/bootstrap.php')) {
        require_once $wp_tests_dir . '/includes/bootstrap.php';
    }
} else {
    // --- Standalone fallback: define minimal WP stubs so the plugin and the
    //     pure-logic test cases can load without a full WordPress install. ---
    if (!function_exists('esc_url')) {
        function esc_url($url) {
            $url = preg_replace('/^(http|https):\/\//i', '$1://', $url);
            return filter_var($url, FILTER_SANITIZE_URL) ?: '';
        }
    }
    if (!function_exists('esc_html')) {
        function esc_html($text) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }
    if (!function_exists('esc_attr')) {
        function esc_attr($text) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($str) {
            return trim(strip_tags($str));
        }
    }
    if (!function_exists('sanitize_textarea_field')) {
        function sanitize_textarea_field($str) {
            return trim(strip_tags($str));
        }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw($url) {
            // Mimic WP: strip anything after a quote or whitespace (blocks
            // attribute-breakout payloads like url" onmouseover=...).
            $url = (string) $url;
            $url = preg_replace('/[\s"\'<>]+.*$/u', '', $url);
            return esc_url($url);
        }
    }
    if (!function_exists('wp_kses_post')) {
        function wp_kses_post($str) {
            return strip_tags($str, '<p><a><h2><h3><strong><em><ul><ol><li><table><th><td><tr><img><div><aside><figure><br><span>');
        }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data, $opts = 0) {
            return json_encode($data, $opts);
        }
    }
    if (!class_exists('WP_UnitTestCase')) {
        // Minimal base so the test class can load without PHPUnit installed.
        // The standalone runner (run-standalone.php) drives the tests manually.
        if (class_exists('PHPUnit\\Framework\\TestCase')) {
            class WP_UnitTestCase extends PHPUnit\Framework\TestCase {}
        } else {
            // Lightweight assertion base used only by the standalone runner.
            class WP_UnitTestCase {
                public function set_up() {}
                public function tear_down() {}

                protected function fail($msg = '') { throw new Exception($msg); }

                protected function assertTrue($cond, $msg = '') {
                    if ($cond !== true) { throw new Exception($msg ?: 'Expected true, got ' . var_export($cond, true)); }
                }
                protected function assertFalse($cond, $msg = '') {
                    if ($cond !== false) { throw new Exception($msg ?: 'Expected false'); }
                }
                protected function assertSame($exp, $act, $msg = '') {
                    if ($exp !== $act) { throw new Exception($msg ?: 'Expected ' . var_export($exp, true) . ' === ' . var_export($act, true)); }
                }
                protected function assertEquals($exp, $act, $msg = '') {
                    if ($exp != $act) { throw new Exception($msg ?: 'Expected ' . var_export($exp, true) . ' == ' . var_export($act, true)); }
                }
                protected function assertNotSame($exp, $act, $msg = '') {
                    if ($exp === $act) { throw new Exception($msg ?: 'Expected NOT identical'); }
                }
                protected function assertNotNull($v, $msg = '') {
                    if ($v === null) { throw new Exception($msg ?: 'Expected non-null'); }
                }
                protected function assertNull($v, $msg = '') {
                    if ($v !== null) { throw new Exception($msg ?: 'Expected null'); }
                }
                protected function assertEmpty($v, $msg = '') {
                    if (!empty($v)) { throw new Exception($msg ?: 'Expected empty'); }
                }
                protected function assertNotEmpty($v, $msg = '') {
                    if (empty($v)) { throw new Exception($msg ?: 'Expected not empty'); }
                }
                protected function assertStringContainsString($needle, $hay, $msg = '') {
                    if (strpos($hay, $needle) === false) { throw new Exception($msg ?: "String does not contain: $needle"); }
                }
                protected function assertStringNotContainsString($needle, $hay, $msg = '') {
                    if (strpos($hay, $needle) !== false) { throw new Exception($msg ?: "String unexpectedly contains: $needle"); }
                }
                protected function assertStringStartsWith($prefix, $str, $msg = '') {
                    if (strpos($str, $prefix) !== 0) { throw new Exception($msg ?: "String does not start with: $prefix"); }
                }
                protected function assertStringEndsWith($suffix, $str, $msg = '') {
                    if (substr($str, -strlen($suffix)) !== $suffix) { throw new Exception($msg ?: "String does not end with: $suffix"); }
                }
                protected function assertNotFalse($v, $msg = '') {
                    if ($v === false) { throw new Exception($msg ?: 'Expected not false'); }
                }
                protected function assertLessThanOrEqual($exp, $act, $msg = '') {
                    if ($act > $exp) { throw new Exception($msg ?: "$act is not <= $exp"); }
                }
                protected function assertGreaterThanOrEqual($exp, $act, $msg = '') {
                    if ($act < $exp) { throw new Exception($msg ?: "$act is not >= $exp"); }
                }
                protected function assertCount($exp, $arr, $msg = '') {
                    $cnt = is_countable($arr) ? count($arr) : 0;
                    if ($cnt !== $exp) { throw new Exception($msg ?: "Expected count $exp, got $cnt"); }
                }
                protected function assertContains($needle, $hay, $msg = '') {
                    if (!is_array($hay) || !in_array($needle, $hay, true)) {
                        throw new Exception($msg ?: 'Array does not contain expected value');
                    }
                }
                protected function assertNotContains($needle, $hay, $msg = '') {
                    if (is_array($hay) && in_array($needle, $hay, true)) {
                        throw new Exception($msg ?: 'Array unexpectedly contains value');
                    }
                }
            }
        }
    }
    if (!class_exists('WP_Error')) {
        class WP_Error {
            public $errors = array();
            public function __construct($code = '', $message = '') {
                $this->errors[$code] = array($message);
            }
            public function get_error_message() {
                return reset(reset($this->errors));
            }
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) {
            return $thing instanceof WP_Error;
        }
    }
    // Polyfill mb_ (usually built-in, but safe to guard).
    if (!function_exists('mb_strlen')) {
        function mb_strlen($s, $e = 'UTF-8') { return strlen($s); }
    }
    if (!function_exists('mb_substr')) {
        function mb_substr($s, $start, $len = null, $e = 'UTF-8') {
            return $len === null ? substr($s, $start) : substr($s, $start, $len);
        }
    }

    // --- WP hook / option / misc stubs used at plugin load time or by the
    //     handlers we exercise. No-ops sufficient for logic-only testing. ---
    $wp_stubs = array(
        'add_action'             => function ($h, $cb) { return true; },
        'add_filter'             => function ($h, $cb) { return true; },
        'add_menu_page'          => function () { return 'hook'; },
        'add_submenu_page'       => function () { return 'hook'; },
        'wp_enqueue_script'      => function () {},
        'wp_enqueue_style'       => function () {},
        'wp_localize_script'     => function () {},
        'get_option'             => function ($k, $d = false) { return $d; },
        'update_option'          => function () { return true; },
        'delete_option'          => function () { return true; },
        'wp_schedule_event'      => function () { return true; },
        'wp_next_scheduled'      => function () { return false; },
        'wp_unschedule_event'    => function () { return true; },
        'register_activation_hook' => function () {},
        'register_deactivation_hook' => function () {},
        'plugin_dir_url'         => function () { return 'http://example.com/'; },
        'plugin_dir_path'        => function () { return '/tmp/'; },
        'admin_url'              => function ($p = '') { return 'http://example.com/' . $p; },
        'wp_create_nonce'        => function () { return 'test-nonce'; },
        'check_ajax_referer'     => function () { return true; },
        'current_user_can'       => function () { return true; },
        'wp_send_json_success'   => function ($d = null) { echo wp_json_encode(array('success' => true, 'data' => $d)); wp_die(); },
        'wp_send_json_error'     => function ($d = null) { echo wp_json_encode(array('success' => false, 'data' => $d)); wp_die(); },
        'wp_die'                 => function ($m = '') { throw new Exception('wp_die: ' . (is_string($m) ? $m : json_encode($m))); },
        'wp_insert_post'         => function ($a) {
            $o = $GLOBALS['__kira_test_overrides']['wp_insert_post'] ?? null;
            return is_callable($o) ? call_user_func($o, $a) : 1;
        },
        'wp_update_post'         => function ($a) {
            $o = $GLOBALS['__kira_test_overrides']['wp_update_post'] ?? null;
            return is_callable($o) ? call_user_func($o, $a) : 1;
        },
        'wp_delete_post'         => function () { return true; },
        'get_post'               => function () { return null; },
        'get_current_user_id'    => function () { return 1; },
        // --- Overridable data functions for logic tests (Group 1: Cluster/Silo). ---
        // Tests may set $GLOBALS['__kira_test_overrides'][fn] = callable to
        // inject fake posts/permalinks without a real database.
        'get_posts'              => function ($args = array()) {
            $o = $GLOBALS['__kira_test_overrides']['get_posts'] ?? null;
            return is_callable($o) ? call_user_func($o, $args) : array();
        },
        'get_permalink'          => function ($id = 0) {
            $o = $GLOBALS['__kira_test_overrides']['get_permalink'] ?? null;
            return is_callable($o) ? call_user_func($o, $id) : '';
        },
        'url_to_postid'          => function ($url = '') {
            $o = $GLOBALS['__kira_test_overrides']['url_to_postid'] ?? null;
            return is_callable($o) ? call_user_func($o, $url) : 0;
        },
        'home_url'               => function ($path = '') {
            $o = $GLOBALS['__kira_test_overrides']['home_url'] ?? null;
            return is_callable($o) ? call_user_func($o, $path) : 'http://example.com';
        },
        'get_post'               => function ($id = null) {
            $o = $GLOBALS['__kira_test_overrides']['get_post'] ?? null;
            return is_callable($o) ? call_user_func($o, $id) : null;
        },
    );
    $GLOBALS['__kira_wp_stubs'] = $wp_stubs;
    $GLOBALS['__kira_test_overrides'] = $GLOBALS['__kira_test_overrides'] ?? array();
    foreach (array_keys($wp_stubs) as $fn) {
        if (!function_exists($fn)) {
            eval('function ' . $fn . '() {
                $args = func_get_args();
                $cb = $GLOBALS["__kira_wp_stubs"]["' . $fn . '"];
                return call_user_func_array($cb, $args);
            }');
        }
    }
}

// Load the plugin under test. The plugin guards itself with
// `if (!defined('ABSPATH')) exit;` — define it so we can load in the test env.
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/kira-ai.php';
