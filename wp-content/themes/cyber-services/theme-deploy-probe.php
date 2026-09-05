<?php
// TEMPORARY deploy diagnostic. Removed in the next commit.
header('Content-Type: text/plain; charset=utf-8');
$target = __DIR__ . '/page-case-study.php';
$src = (string) file_get_contents($target);

echo "PROBE_VERSION=2\n";
echo "PHP=" . PHP_VERSION . " SAPI=" . php_sapi_name() . "\n";
echo "TARGET=$target\n";
echo "TARGET_SIZE=" . strlen($src) . "\n";
echo "TARGET_SHA1=" . sha1($src) . "\n";
echo "TARGET_MTIME=" . date('c', (int) filemtime($target)) . "\n";
echo "HAS_NEW_SET404=" . (strpos($src, '$wp_query->set_404') !== false ? 'yes' : 'no') . "\n";
echo "HAS_OLD_BARE_SET404=" . (preg_match('/^\s*set_404\(\);/m', $src) ? 'yes' : 'no') . "\n";
echo "HAS_HOMEURL=" . (strpos($src, "home_url('/case-study/')") !== false ? 'yes' : 'no') . "\n";
echo "HAS_TOTALPAGES=" . (strpos($src, 'max_num_pages') !== false ? 'yes' : 'no') . "\n";

if (function_exists('opcache_get_status')) {
    $st = @opcache_get_status(false);
    if (is_array($st)) {
        echo "OPCACHE_ENABLED=" . var_export($st['opcache_enabled'] ?? null, true) . "\n";
        echo "OPCACHE_NUM_CACHED=" . var_export($st['opcache_statistics']['num_cached_files'] ?? null, true) . "\n";
        echo "OPCACHE_HITS=" . var_export($st['opcache_statistics']['hits'] ?? null, true) . "\n";
        echo "OPCACHE_MISSES=" . var_export($st['opcache_statistics']['misses'] ?? null, true) . "\n";
    } else {
        echo "OPCACHE_STATUS=unavailable (disabled for this SAPI)\n";
    }
    $cfg = @opcache_get_configuration();
    if (is_array($cfg) && isset($cfg['directives'])) {
        foreach (['opcache.enable', 'opcache.validate_timestamps', 'opcache.revalidate_freq',
                  'opcache.file_cache', 'opcache.file_cache_only'] as $k) {
            echo "INI[$k]=" . var_export($cfg['directives'][$k] ?? null, true) . "\n";
        }
    }
} else {
    echo "OPCACHE=not installed\n";
}

echo "REALPATH_TTL=" . var_export(ini_get('realpath_cache_ttl'), true) . "\n";
echo "APC_ENABLED=" . var_export(function_exists('apc_fetch'), true) . "\n";
echo "DONE\n";
