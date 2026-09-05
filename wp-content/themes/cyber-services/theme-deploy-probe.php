<?php
// TEMPORARY deploy diagnostic. Removed in a follow-up commit.
header('Content-Type: text/plain; charset=utf-8');

// wp-load.php lives at the document root: theme dir is wp-content/themes/<slug>/,
// so three levels up. Search a few levels to stay robust.
$loader = false;
for ($up = 2; $up <= 5 && $loader === false; $up++) {
    $cand = __DIR__ . '/' . str_repeat('/..', $up) . '/wp-load.php';
    if (file_exists($cand)) { $loader = realpath($cand); }
}
echo "LOADER=" . var_export($loader, true) . "\n";
if ($loader === false) { echo "ABORT: wp-load.php not found\n"; exit; }

define('WP_USE_THEMES', false);
require $loader;

echo "PROBE_VERSION=4\n";
echo "SITE_URL=" . home_url() . "\n";
echo "WP_CONTENT_DIR=" . WP_CONTENT_DIR . "\n";
echo "TEMPLATE_DIR=" . get_template_directory() . "\n";
echo "STYLESHEET_DIR=" . get_stylesheet_directory() . "\n";
echo "IS_CHILD_THEME=" . var_export(is_child_theme(), true) . "\n";
echo "TEMPLATE=" . get_template() . " | STYLESHEET=" . get_stylesheet() . "\n";
echo "ACTIVE_THEME=" . wp_get_theme()->get('Name') . "\n";

echo "\n-- file WP would load for the case-study template --\n";
$edited = get_stylesheet_directory() . '/page-case-study.php';
echo "PATH=$edited\n";
echo "EXISTS=" . var_export(file_exists($edited), true) . "\n";
if (file_exists($edited)) {
    $c = (string) file_get_contents($edited);
    echo "SIZE=" . strlen($c) . " SHA1=" . sha1($c) . "\n";
    echo "HAS_SET404=" . (strpos($c, 'set_404') !== false ? 'yes' : 'no') . "\n";
}

echo "\n-- all page-case-study.php under wp-content/themes --\n";
foreach (glob(WP_CONTENT_DIR . '/themes/*/page-case-study.php') ?: [] as $f) {
    echo basename(dirname($f)) . "  size=" . filesize($f) . "  sha1=" . sha1((string) file_get_contents($f)) . "\n";
}

echo "\n-- themes installed --\n";
foreach (glob(WP_CONTENT_DIR . '/themes/*', GLOB_ONLYDIR) ?: [] as $d) echo basename($d) . "\n";

echo "\n-- template resolution for /case-study/ --\n";
$page_id = url_to_postid(home_url('/case-study/'));
echo "PAGE_ID=$page_id\n";
if ($page_id) {
    $tpl = (string) get_page_template_slug($page_id);
    echo "ASSIGNED_TEMPLATE='" . $tpl . "'\n";
    echo "LOCATE(page-case-study.php)=" . locate_template(['page-case-study.php']) . "\n";
    // Reproduce core's page_template_hierarchy for this page.
    $hierarchy = [];
    $slug = get_page_uri($page_id);
    if ($slug) { $hierarchy[] = 'page-' . $slug . '.php'; }
    $hierarchy[] = 'page-' . $page_id . '.php';
    $hierarchy[] = 'page.php';
    echo "HIERARCHY=" . implode(' > ', $hierarchy) . "\n";
    foreach ($hierarchy as $h) {
        $p = get_stylesheet_directory() . '/' . $h;
        echo "  $h  exists=" . var_export(file_exists($p), true) . "\n";
    }
    echo "FILTERED_TEMPLATE=" . apply_filters('template_include', get_stylesheet_directory() . '/page-case-study.php') . "\n";
}

echo "\nDONE\n";
