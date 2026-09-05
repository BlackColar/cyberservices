<?php
/**
 * Minimal standalone test runner (no PHPUnit / Composer required).
 *
 * Loads the same bootstrap as the PHPUnit suite (which provides WP stubs when
 * WP_TESTS_DIR is not set), then executes the Kira_AI_Pillar_Silo_Test methods
 * manually. This lets you verify the Silo & Pillar logic on any machine with
 * just `php`.
 *
 * Usage:  php tests/run-standalone.php
 */

require_once __DIR__ . '/bootstrap.php';

// Tiny assertion helpers -------------------------------------------------
function kira_assert($cond, $msg) {
    if (!$cond) {
        throw new Exception('ASSERT FAILED: ' . $msg);
    }
}
function kira_str_contains($hay, $needle) {
    return $needle === '' || strpos($hay, $needle) !== false;
}
function kira_str_not_contains($hay, $needle) {
    return strpos($hay, $needle) === false;
}

// Load the test classes (PHPUnit parent is polyfilled by bootstrap).
require_once __DIR__ . '/test-pillar-silo.php';
require_once __DIR__ . '/test-cluster-silo-group1.php';

// Tests that need a real WordPress DB are skipped in standalone mode.
$skip_names = array(
    'test_ajax_generate_post_text_injects_pillar_link',
    'test_generate_flow_applies_max_internal_links_budget',
);

$test_classes = array('Kira_AI_Pillar_Silo_Test', 'Kira_AI_Cluster_Silo_Group1_Test');

$pass = 0;
$fail = 0;
$skipped = 0;

foreach ($test_classes as $class) {
    $test = new $class();
    $ref  = new ReflectionClass($test);
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $m) {
        $name = $m->getName();
        if (strpos($name, 'test') !== 0) {
            continue;
        }

        if (in_array($name, $skip_names, true)) {
            echo "SKIP  $class::$name (requires WordPress environment)\n";
            $skipped++;
            continue;
        }

        try {
            if (method_exists($test, 'set_up')) {
                $test->set_up();
            }
            $test->$name();
            echo "PASS  $class::$name\n";
            $pass++;
        } catch (Exception $e) {
            echo "FAIL  $class::$name -> " . $e->getMessage() . "\n";
            $fail++;
        } catch (Throwable $e) {
            echo "FAIL  $class::$name -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
            $fail++;
        }
    }
}

echo "\n========================================\n";
echo "RESULT: $pass passed, $fail failed, $skipped skipped\n";
echo "========================================\n";

exit($fail === 0 ? 0 : 1);
