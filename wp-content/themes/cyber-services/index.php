<?php
get_header();
?>
<main id="noi-dung" class="container section">
  <div class="section-heading"><h1><?php echo esc_html(wp_strip_all_tags(get_the_archive_title()) ?: get_bloginfo('name')); ?></h1></div>
  <?php if (have_posts()) : ?><div class="card-grid posts"><?php while (have_posts()) : the_post(); ?><article <?php post_class('card'); ?>><div class="meta"><time datetime="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>"><?php echo esc_html(get_the_date('d.m.Y')); ?></time></div><h2><a href="<?php the_permalink(); ?>"><?php echo esc_html(get_the_title()); ?></a></h2><p><?php echo esc_html(cyber_services_excerpt()); ?></p><a class="read-link" href="<?php the_permalink(); ?>">Xem nội dung →</a></article><?php endwhile; ?></div><?php else : ?><p class="empty">Không có nội dung.</p><?php endif; ?>
  <nav class="pagination" aria-label="Phân trang"><?php echo wp_kses_post(paginate_links() ?: ''); ?></nav>
</main>
<?php get_footer();
