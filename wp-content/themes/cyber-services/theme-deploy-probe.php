<?php
// TEMPORARY deploy diagnostic. Removed in a follow-up commit.
// Simulates the exact request /case-study/page/2/ and prints the guard inputs.
header('Content-Type: text/plain; charset=utf-8');

$loader = false;
for ($up = 2; $up <= 5 && $loader === false; $up++) {
    $cand = __DIR__ . '/' . str_repeat('/..', $up) . '/wp-load.php';
    if (file_exists($cand)) $loader = realpath($cand);
}
if ($loader === false) { echo "ABORT: no wp-load.php\n"; exit; }

define('WP_USE_THEMES', false);
require $loader;

echo "PROBE_VERSION=5\n";
echo "posts_per_page option = " . var_export(get_option('posts_per_page'), true) . "\n";

$page_id = url_to_postid(home_url('/case-study/'));
echo "case-study page id = $page_id\n";

// Build the same query the template builds, for several paged values.
foreach ([1, 2, 3, 99] as $p) {
    $q = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'publish',
        'category_name' => 'case-study',
        'posts_per_page' => max(1, (int) get_option('posts_per_page')),
        'paged' => $p,
        'ignore_sticky_posts' => true,
    ]);
    printf(
        "paged=%-3d found_posts=%-3d max_num_pages=%-3d returned=%d  -> %s\n",
        $p,
        (int) $q->found_posts,
        (int) $q->max_num_pages,
        count($q->posts),
        ((int) $q->max_num_pages > 0 && $p > (int) $q->max_num_pages) ? 'GUARD FIRES (404)' : 'render'
    );
}

// Now inspect what the query vars actually are for a real page-2 request.
echo "\n-- force WP to parse /case-study/page/2/ --\n";
$_SERVER['REQUEST_URI'] = '/case-study/page/2/';
$_SERVER['QUERY_STRING'] = '';
global $wp;
$wp->parse_request();
$wp->query_posts();
global $wp_query;
echo "get_query_var('paged') = " . var_export(get_query_var('paged'), true) . "\n";
echo "get_query_var('page')  = " . var_export(get_query_var('page'), true) . "\n";
echo "get_query_var('pagenow') = " . var_export(get_query_var('pagenow'), true) . "\n";
echo "main is_page = " . var_export($wp_query->is_page(), true) . "\n";
echo "main is_singular = " . var_export($wp_query->is_singular(), true) . "\n";
echo "main is_404 = " . var_export($wp_query->is_404(), true) . "\n";
echo "main max_num_pages = " . var_export($wp_query->max_num_pages, true) . "\n";
echo "main found_posts = " . var_export($wp_query->found_posts, true) . "\n";
echo "computed current_page = " . var_export(max(1, (int) get_query_var('paged'), (int) get_query_var('page')), true) . "\n";

echo "\nDONE\n";
