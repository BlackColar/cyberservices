<?php
get_header();
?>
<main id="noi-dung">
<?php while (have_posts()) : the_post(); ?>
  <?php $article_content = cyber_services_article_content(get_the_content()); ?>
  <article <?php post_class(); ?>><header class="article-header"><div class="container"><div class="meta"><time datetime="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>"><?php echo esc_html(get_the_date('d.m.Y')); ?></time><span><?php echo esc_html(get_the_author()); ?></span></div><h1><?php the_title(); ?></h1><?php if (has_excerpt()) : ?><p class="excerpt"><?php echo esc_html(cyber_services_excerpt()); ?></p><?php endif; ?><?php if (has_post_thumbnail()) : ?><?php the_post_thumbnail('full', ['class' => 'article-image']); ?><?php endif; ?></div></header><div class="container section article-layout"><a class="article-back" href="<?php echo esc_url(get_post_type_archive_link('post') ?: home_url('/blog/')); ?>">← Quay lại Blog</a><?php get_template_part('template-parts/article-content', null, ['content' => $article_content]); ?></div></article>
<?php endwhile; ?>
</main>
<?php get_footer();
