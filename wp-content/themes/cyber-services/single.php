<?php
get_header();
?>
<main id="noi-dung">
<?php while (have_posts()) : the_post(); ?>
  <?php
  $article_content = cyber_services_article_content(get_the_content());
  $author_id = (int) get_the_author_meta('ID');
  $author_link = $author_id > 0 ? (string) get_author_posts_url($author_id) : '';
  ?>
  <article <?php post_class(); ?> itemscope itemtype="https://schema.org/BlogPosting">
    <meta itemprop="headline" content="<?php echo esc_attr(get_the_title()); ?>">
    <meta itemprop="mainEntityOfPage" content="<?php echo esc_url(get_permalink()); ?>">
    <header class="article-header">
      <div class="container">
        <div class="meta article-meta">
          <?php if ($author_link !== '') : ?>
            <span class="meta-author"><span class="meta-label"><?php esc_html_e('Tác giả:', 'cyber-services'); ?></span> <a href="<?php echo esc_url($author_link); ?>" rel="author" itemprop="author" itemscope itemtype="https://schema.org/Person"><span itemprop="name"><?php the_author_meta('display_name', $author_id); ?></span></a></span>
          <?php else : ?>
            <span class="meta-author"><span class="meta-label"><?php esc_html_e('Tác giả:', 'cyber-services'); ?></span> <span itemprop="author" itemscope itemtype="https://schema.org/Person"><?php the_author_meta('display_name', $author_id); ?></span></span>
          <?php endif; ?>
          <time class="meta-date" datetime="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>" itemprop="datePublished"><?php echo esc_html(get_the_date('d.m.Y')); ?></time>
          <?php if (get_the_modified_date('U') !== get_the_date('U')) : ?>
            <time class="meta-updated" datetime="<?php echo esc_attr(get_the_modified_date(DATE_W3C)); ?>" itemprop="dateModified"><?php echo esc_html(sprintf(__('Cập nhật %s', 'cyber-services'), get_the_modified_date('d.m.Y'))); ?></time>
          <?php endif; ?>
        </div>
        <h1 itemprop="name"><?php the_title(); ?></h1>
        <?php if (has_excerpt()) : ?><p class="excerpt" itemprop="abstract"><?php echo esc_html(cyber_services_excerpt()); ?></p><?php endif; ?>
        <?php if (has_post_thumbnail()) : ?>
          <?php the_post_thumbnail('full', ['class' => 'article-image', 'itemprop' => 'image']); ?>
        <?php endif; ?>
      </div>
    </header>
    <div class="container section article-layout">
      <a class="article-back" href="<?php echo esc_url(get_post_type_archive_link('post') ?: home_url('/blog/')); ?>">← Quay lại Blog</a>
      <?php get_template_part('template-parts/article-content', null, ['content' => $article_content]); ?>
      <?php get_template_part('template-parts/author-box'); ?>
    </div>
  </article>
<?php endwhile; ?>
</main>
<?php get_footer();
