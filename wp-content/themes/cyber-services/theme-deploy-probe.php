<?php
// TEMPORARY deploy diagnostic. Removed in a follow-up commit.
header('Content-Type: text/plain; charset=utf-8');
define('WP_USE_THEMES', false);
require __DIR__ . '/../../../../wp-load.php';

echo "PROBE_VERSION=3\n";
echo "SITE_URL=" . home_url() . "\n";
echo "WP_CONTENT_DIR=" . WP_CONTENT_DIR . "\n";
echo "ABSPATH=" . ABSPATH . "\n";
echo "TEMPLATE_DIR=" . get_template_directory() . "\n";
echo "STYLESHEET_DIR=" . get_stylesheet_directory() . "\n";
echo "IS_CHILD_THEME=" . var_export(is_child_theme(), true) . "\n";
echo "TEMPLATE=" . get_template() . " | STYLESHEET=" . get_stylesheet() . "\n";
echo "ACTIVE_THEME=" . wp_get_theme()->get('Name') . "\n";

echo "\n-- does the resolved template match the file we edited? --\n";
$edited = get_stylesheet_directory() . '/page-case-study.php';
echo "EDITED_PATH=$edited\n";
echo "EDITED_EXISTS=" . var_export(file_exists($edited), true) . "\n";
if (file_exists($edited)) {
    $c = (string) file_get_contents($edited);
    echo "EDITED_SIZE=" . strlen($c) . "\n";
    echo "EDITED_SHA1=" . sha1($c) . "\n";
    echo "EDITED_HAS_SET404=" . (strpos($c, 'set_404') !== false ? 'yes' : 'no') . "\n";
}

echo "\n-- every page-case-study.php visible under wp-content/themes --\n";
$found = glob(WP_CONTENT_DIR . '/themes/*/page-case-study.php') ?: [];
if (!$found) echo "(none)\n";
foreach ($found as $f) {
    echo "$f  size=" . filesize($f) . "  sha1=" . sha1((string) file_get_contents($f)) . "\n";
}

echo "\n-- themes installed --\n";
foreach (glob(WP_CONTENT_DIR . '/themes/*', GLOB_ONLYDIR) ?: [] as $d) echo basename($d) . "\n";

echo "\n-- how does WP resolve the case-study page template? --\n";
$page_id = url_to_postid(home_url('/case-study/'));
echo "CASE_STUDY_PAGE_ID=$page_id\n";
if ($page_id) {
    $tpl = get_page_template_slug($page_id);
    echo "ASSIGNED_PAGE_TEMPLATE='" . $tpl . "' (empty = auto hierarchy)\n";
    echo "HIERARCHY_RESOLVED=" . var_export((string) locate_template(['page-case-study.php']), true) . "\n";
    $theme_root = get_stylesheet_directory();
    if ($tpl !== '') {
        echo "ASSIGNED_FILE=$theme_root/$tpl exists=" . var_export(file_exists("$theme_root/$tpl"), true) . "\n";
        if (file_exists("$theme_root/$tpl")) {
            echo "ASSIGNED_HAS_SET404=" . (strpos((string) file_get_contents("$theme_root/$tpl"), 'set_404') !== false ? 'yes' : 'no') . "\n";
        }
    }
}

echo "\nDONE\n";
