<?php
get_header();
?>
<main id="noi-dung">
<?php while (have_posts()) : the_post(); ?>
  <?php
  $current_page = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));
  $case_studies = new WP_Query([
      'post_type' => 'post',
      'post_status' => 'publish',
      'category_name' => 'case-study',
      'posts_per_page' => max(1, (int) get_option('posts_per_page')),
      'paged' => $current_page,
      'ignore_sticky_posts' => true,
  ]);
  $page_content = trim((string) get_the_content());
  $article_content = $page_content !== '' ? cyber_services_article_content($page_content) : '';
  ?>
  <header class="container section blog-intro">
    <div class="section-heading">
      <p class="eyebrow">RESOURCE</p>
      <h1><?php the_title(); ?></h1>
      <?php if (has_excerpt()) : ?><p><?php echo esc_html(cyber_services_excerpt()); ?></p><?php endif; ?>
    </div>
  </header>
  <?php if ($page_content !== '') : ?>
    <section class="container page-content" aria-label="<?php esc_attr_e('Giới thiệu Case Study', 'cyber-services'); ?>">
      <article><?php get_template_part('template-parts/article-content', null, ['content' => $article_content, 'body_class' => 'page-detail']); ?></article>
    </section>
  <?php endif; ?>
  <section class="container posts-section" aria-labelledby="case-study-list-title">
    <h2 class="screen-reader-text" id="case-study-list-title"><?php esc_html_e('Danh sách Case Study', 'cyber-services'); ?></h2>
    <?php if ($case_studies->have_posts()) : ?>
      <div class="card-grid posts">
        <?php while ($case_studies->have_posts()) : $case_studies->the_post(); ?>
          <article <?php post_class('card'); ?>>
            <?php if (has_post_thumbnail()) : ?>
              <a class="card-media" href="<?php the_permalink(); ?>" aria-label="<?php echo esc_attr(get_the_title()); ?>"><?php the_post_thumbnail('large', ['loading' => 'lazy']); ?></a>
            <?php else : ?>
              <div class="card-media card-media-placeholder" aria-hidden="true"><span>CYBER SERVICES</span></div>
            <?php endif; ?>
            <div class="meta"><time datetime="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>"><?php echo esc_html(get_the_date('d.m.Y')); ?></time></div>
            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
            <p><?php echo esc_html(cyber_services_excerpt()); ?></p>
            <a class="read-link" href="<?php the_permalink(); ?>"><?php esc_html_e('Đọc Case Study →', 'cyber-services'); ?></a>
          </article>
        <?php endwhile; ?>
      </div>
    <?php else : ?>
      <p class="empty"><?php esc_html_e('Chưa có Case Study.', 'cyber-services'); ?></p>
    <?php endif; ?>
    <?php
    $pagination = paginate_links([
        'total' => (int) $case_studies->max_num_pages,
        'current' => $current_page,
        'prev_text' => __('Trước', 'cyber-services'),
        'next_text' => __('Sau', 'cyber-services'),
    ]);
    ?>
    <?php if ($pagination) : ?><nav class="pagination" aria-label="<?php esc_attr_e('Phân trang Case Study', 'cyber-services'); ?>"><?php echo wp_kses_post($pagination); ?></nav><?php endif; ?>
  </section>
  <?php wp_reset_postdata(); ?>
<?php endwhile; ?>
</main>
<?php get_footer();
