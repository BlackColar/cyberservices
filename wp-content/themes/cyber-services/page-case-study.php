<?php
$current_page = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));

// Resolve the category's true size with an unpaginated query. WP_Query sets
// max_num_pages to 0 (not 1) when `paged` is past the end, so the paginated
// query below cannot tell "no posts at all" apart from "page out of range" —
// both come back as 0 pages / 0 posts.
$case_study_count = (int) (new WP_Query([
    'post_type' => 'post',
    'post_status' => 'publish',
    'category_name' => 'case-study',
    'posts_per_page' => 1,
    'fields' => 'ids',
    'ignore_sticky_posts' => true,
    'no_found_rows' => false,
]))->found_posts;

$per_page = max(1, (int) get_option('posts_per_page'));
$total_pages = (int) ceil($case_study_count / $per_page);

$case_studies = new WP_Query([
    'post_type' => 'post',
    'post_status' => 'publish',
    'category_name' => 'case-study',
    'posts_per_page' => $per_page,
    'paged' => $current_page,
    'ignore_sticky_posts' => true,
]);

// A custom WP_Query cannot 404 out-of-range pages on its own, so /case-study/page/99/
// would otherwise render an empty 200 page. Mirror core's main-query behaviour.
// When the category is genuinely empty, page 1 still renders so the "Chưa có Case
// Study." state shows; only pages beyond the real page count are rejected.
if ($current_page > 1 && $current_page > $total_pages) {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
    get_header();
    ?>
    <main id="noi-dung" class="container section page-content">
      <header class="section-heading">
        <h1><?php esc_html_e('Không tìm thấy trang', 'cyber-services'); ?></h1>
        <p><?php esc_html_e('Trang bạn yêu cầu không tồn tại hoặc đã vượt quá số trang hiện có.', 'cyber-services'); ?></p>
      </header>
      <p><a class="read-link" href="<?php echo esc_url(home_url('/case-study/')); ?>"><?php esc_html_e('Về danh sách Case Study →', 'cyber-services'); ?></a></p>
    </main>
    <?php
    get_footer();
    return;
}

get_header();
?>
<main id="noi-dung">
<?php while (have_posts()) : the_post(); ?>
  <?php
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
    $pagination = $total_pages > 1 ? paginate_links([
        'base' => user_trailingslashit(trailingslashit(get_permalink()) . 'page/%#%'),
        'format' => '',
        'total' => $total_pages,
        'current' => $current_page,
        'prev_text' => __('Trước', 'cyber-services'),
        'next_text' => __('Sau', 'cyber-services'),
    ]) : '';
    ?>
    <?php if ($pagination) : ?><nav class="pagination" aria-label="<?php esc_attr_e('Phân trang Case Study', 'cyber-services'); ?>"><?php echo wp_kses_post($pagination); ?></nav><?php endif; ?>
  </section>
<?php endwhile; ?>
<?php wp_reset_postdata(); ?>
</main>
<?php get_footer(); ?>

