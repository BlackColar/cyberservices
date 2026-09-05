<?php
/**
 * Author box displayed right after the post content on single posts.
 *
 * Shows the post author avatar, name (linked to the author archive), biography
 * with a graceful fallback, published post count and a Person schema marker.
 *
 * The box is skipped when the author has no archive to link to (for example
 * when author archives are hidden) and can be turned off entirely with the
 * `cyber_services_author_box_enabled` filter.
 *
 * @package Cyber_Services
 */

if (!defined('ABSPATH')) {
    exit;
}

$author_id = (int) get_the_author_meta('ID');

if ($author_id <= 0 || !apply_filters('cyber_services_author_box_enabled', true)) {
    return;
}

$author_url = (string) get_author_posts_url($author_id);

if ($author_url === '') {
    return;
}

$author_name = (string) get_the_author_meta('display_name', $author_id);
$author_bio = (string) get_the_author_meta('description', $author_id);
$author_post_count = (int) count_user_posts($author_id, 'post', true);
$author_avatar = get_avatar($author_id, 80, '', $author_name, ['class' => 'author-box-avatar']);
$author_fallback = __('Tác giả hiện chưa cập nhật tiểu sử. Các bài viết khác của tác giả được liệt kê tại trang hồ sơ.', 'cyber-services');
?>
<section class="author-box" itemscope itemtype="https://schema.org/Person" aria-labelledby="author-box-heading">
  <div class="author-box-avatar">
    <?php echo $author_avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
  </div>
  <div class="author-box-main">
    <p class="author-box-eyebrow" id="author-box-heading"><?php esc_html_e('Tác giả', 'cyber-services'); ?></p>
    <p class="author-box-name"><a href="<?php echo esc_url($author_url); ?>" itemprop="url"><span itemprop="name"><?php echo esc_html($author_name); ?></span></a></p>
    <?php if ($author_bio !== '') : ?>
      <div class="author-box-bio" itemprop="description"><?php echo wp_kses_post(wpautop($author_bio)); ?></div>
    <?php else : ?>
      <p class="author-box-bio author-box-bio--fallback" itemprop="description"><?php echo esc_html($author_fallback); ?></p>
    <?php endif; ?>
    <div class="author-box-actions">
      <?php if ($author_post_count > 0) : ?>
        <a class="author-box-cta" href="<?php echo esc_url($author_url); ?>"><?php echo esc_html(sprintf(_n('Xem %d bài viết của tác giả', 'Xem %d bài viết của tác giả', $author_post_count, 'cyber-services'), $author_post_count)); ?></a>
      <?php else : ?>
        <a class="author-box-cta" href="<?php echo esc_url($author_url); ?>"><?php esc_html_e('Xem hồ sơ tác giả', 'cyber-services'); ?></a>
      <?php endif; ?>
    </div>
  </div>
  <meta itemprop="sameAs" content="<?php echo esc_url($author_url); ?>">
  <script type="application/ld+json"><?php echo wp_json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'Person',
      'name' => $author_name,
      'url' => $author_url,
      'description' => wp_strip_all_tags($author_bio !== '' ? $author_bio : $author_fallback),
      'image' => get_avatar_url($author_id, ['size' => 160]),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
</section>
