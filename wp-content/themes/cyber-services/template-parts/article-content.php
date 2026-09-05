<?php
if (!defined('ABSPATH')) {
    exit;
}

$article_content = (string) ($args['content'] ?? '');
$article_body_class = trim('article-body ' . (string) ($args['body_class'] ?? ''));
?>
<div class="<?php echo esc_attr($article_body_class); ?>"><?php echo $article_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php wp_link_pages(); ?></div>
