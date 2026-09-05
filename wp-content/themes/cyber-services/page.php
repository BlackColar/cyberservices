<?php
get_header();
?>
<main id="noi-dung" class="container section page-content">
<?php while (have_posts()) : the_post(); ?>
  <?php
  $page_children = cyber_services_page_children((int) get_the_ID());
  $page_content = trim((string) get_the_content());
  $article_content = $page_content !== '' ? cyber_services_article_content($page_content) : '';
  ?>
  <article>
    <header class="section-heading"><h1><?php the_title(); ?></h1><?php if (has_excerpt()) : ?><p><?php echo esc_html(cyber_services_excerpt()); ?></p><?php endif; ?></header>
    <?php if ($page_children) : ?>
      <section aria-labelledby="page-children-title">
        <h2 class="screen-reader-text" id="page-children-title"><?php echo esc_html(sprintf(__('Các trang thuộc %s', 'cyber-services'), get_the_title())); ?></h2>
        <div class="card-grid page-child-grid">
          <?php foreach ($page_children as $page_child) : ?>
            <?php $page_child_excerpt = cyber_services_page_excerpt($page_child); ?>
            <article class="card page-child-card">
              <?php if (has_post_thumbnail($page_child)) : ?>
                <a class="card-media" href="<?php echo esc_url(get_permalink($page_child)); ?>" aria-label="<?php echo esc_attr(get_the_title($page_child)); ?>"><?php echo get_the_post_thumbnail($page_child, 'large', ['loading' => 'lazy']); ?></a>
              <?php else : ?>
                <div class="card-media card-media-placeholder" aria-hidden="true"><span>CYBER SERVICES</span></div>
              <?php endif; ?>
              <h2><a href="<?php echo esc_url(get_permalink($page_child)); ?>"><?php echo esc_html(get_the_title($page_child)); ?></a></h2>
              <?php if ($page_child_excerpt !== '') : ?><p><?php echo esc_html($page_child_excerpt); ?></p><?php endif; ?>
              <a class="read-link" href="<?php echo esc_url(get_permalink($page_child)); ?>"><?php esc_html_e('Xem chi tiết →', 'cyber-services'); ?></a>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
    <?php if ($page_content !== '') : ?><?php get_template_part('template-parts/article-content', null, ['content' => $article_content, 'body_class' => 'page-detail' . ($page_children ? ' page-detail-after-children' : '')]); ?><?php endif; ?>
  </article>
<?php endwhile; ?>
</main>
<?php get_footer();
