<?php
get_header();
?>
<main id="noi-dung">
  <header class="container section blog-intro"><div class="section-heading"><p class="eyebrow">GÓC CHIA SẺ</p><h1><?php single_post_title(); ?></h1><p>Thông tin thực tiễn về tuân thủ, an toàn thông tin và quản trị rủi ro.</p></div></header>
  <section class="container posts-section" aria-label="Bài viết"><?php if (have_posts()) : ?><div class="card-grid posts"><?php while (have_posts()) : the_post(); ?><article <?php post_class('card'); ?>><?php if (has_post_thumbnail()) : ?><a href="<?php the_permalink(); ?>"><?php the_post_thumbnail('large', ['loading' => 'lazy']); ?></a><?php endif; ?><div class="meta"><time datetime="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>"><?php echo esc_html(get_the_date('d.m.Y')); ?></time></div><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><p><?php echo esc_html(cyber_services_excerpt()); ?></p><a class="read-link" href="<?php the_permalink(); ?>">Đọc bài viết →</a></article><?php endwhile; ?></div><?php else : ?><p class="empty">Chưa có bài viết.</p><?php endif; ?><nav class="pagination" aria-label="Phân trang"><?php echo wp_kses_post(paginate_links() ?: ''); ?></nav></section>
</main>
<?php get_footer();
