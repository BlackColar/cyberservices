<?php
declare(strict_types=1);

$services = cyber_services_services();
$products = cyber_services_products();
$about_page = get_page_by_path('gioi-thieu', OBJECT, 'page');
$about_images = $about_page instanceof WP_Post ? cyber_services_page_images($about_page) : [];
$about_title = 'Truyền thông nói về chúng tôi';
$about_summary = $about_page instanceof WP_Post && has_excerpt($about_page)
    ? get_the_excerpt($about_page)
    : 'Cyber Services Việt Nam đồng hành cùng doanh nghiệp xây dựng năng lực tuân thủ và an toàn thông tin có thể duy trì trong thực tế.';
$metrics = [
    ['15–50%', '15', '50', '15–', '%', 'Tiết kiệm chi phí'],
    ['50–63%', '50', '63', '50–', '%', 'Rút ngắn triển khai'],
    ['100%', '0', '100', '', '%', 'Chứng nhận lần đầu'],
];
$process = cyber_services_process();
$customers = cyber_services_customers();
$social = cyber_services_social_links();
$contact = cyber_services_contact_details();
$home_news_category = get_category_by_slug('tin-tuc');
$home_news_resource_category = get_category_by_slug('resource');
$home_news_query_args = [
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => 9,
    'ignore_sticky_posts' => false,
];
if ($home_news_category instanceof WP_Term) {
    $home_news_query_args['cat'] = (int) $home_news_category->term_id;
}
$home_news_query = new WP_Query($home_news_query_args);
$home_news_posts = $home_news_query->posts;
$home_news_featured = $home_news_posts[0] ?? null;
$home_news_items = array_slice($home_news_posts, 1, 8);
$home_news_archive_url = home_url('/tin-tuc/');
if ($home_news_category instanceof WP_Term) {
    $candidate_url = get_category_link($home_news_category->term_id);
    if (!is_wp_error($candidate_url)) {
        $home_news_archive_url = (string) $candidate_url;
    }
}
$home_news_tabs = [['label' => 'Tin tức chung', 'url' => $home_news_archive_url, 'active' => true]];
if ($home_news_resource_category instanceof WP_Term) {
    $resource_url = get_category_link($home_news_resource_category->term_id);
    if (!is_wp_error($resource_url)) {
        $home_news_tabs[] = ['label' => 'Blog kỹ thuật', 'url' => (string) $resource_url, 'active' => false];
    }
}
$navigation = cyber_services_navigation(true);
[$footer_services, $footer_products, $footer_company] = cyber_services_footer_navigation($navigation);
$policy_links = cyber_services_policy_links();
[$contact_status_state, $contact_status_message] = cyber_services_contact_status();
get_header();
?>
<header class="header" data-header>
    <div class="topBar"><span class="topBarStatus"><span class="statusDot" aria-hidden="true"></span><span>Tuân thủ — An toàn — Vững tin</span></span><span class="topContacts"><a href="<?php echo esc_url($contact['phone_url']); ?>"><?php echo esc_html($contact['phone']); ?></a><a href="<?php echo esc_url($contact['email_url']); ?>"><?php echo esc_html($contact['email']); ?></a></span></div>
    <div class="navBar">
        <a class="brand" href="<?php echo esc_url(home_url('/#top')); ?>" aria-label="Cyber Services Việt Nam — Trang chủ"><?php echo cyber_services_brand_markup(); ?></a>
        <div class="root" data-staggered-menu data-open="false">
            <nav class="desktop" aria-label="Điều hướng chính"><ul><?php cyber_services_navigation_desktop($navigation); ?></ul></nav>
            <button class="trigger" type="button" aria-expanded="false" aria-controls="cyber-menu-panel" aria-label="Mở trình đơn"><span></span><span></span><span class="triggerText">Trình đơn</span></button>
            <button class="overlay" type="button" tabindex="-1" aria-label="Đóng trình đơn"></button>
            <div class="preLayer preLayerOne" aria-hidden="true"></div><div class="preLayer preLayerTwo" aria-hidden="true"></div>
            <aside class="panel" id="cyber-menu-panel" role="dialog" aria-modal="true" aria-label="Trình đơn chính" aria-hidden="true" inert>
                <div class="panelTop"><a class="menuCta" href="#lien-he">Miễn phí tư vấn &amp; Báo giá</a><button class="close" type="button" aria-label="Đóng trình đơn"><span aria-hidden="true"></span></button></div>
                <nav aria-label="Điều hướng di động"><ul class="drawerList"><?php cyber_services_navigation_drawer($navigation); ?></ul></nav>
            </aside>
        </div>
        <a class="headerCta" data-glare href="#lien-he">Miễn phí tư vấn &amp; Báo giá</a>
    </div>
</header>

<main id="noi-dung">
    <section class="hero" id="top" aria-labelledby="hero-title">
        <div class="cursorGrid" data-cursor-grid aria-hidden="true"><canvas></canvas></div>
        <div class="heroInner">
            <div class="heroCopy" data-hero-copy><p class="standards">PCI DSS · SOC 2 · ISO 27001 · CMMI</p><h1 class="heroTitle" id="hero-title" data-split aria-label="Tuân thủ để tăng trưởng.">Tuân thủ để tăng trưởng.</h1><p class="heroSummary">Đánh giá đúng. Khắc phục nhanh. Chứng nhận ngay lần đầu.</p><div class="actions"><a class="primaryButton" data-glare href="#lien-he">Đăng ký tư vấn miễn phí</a><a class="secondaryButton" href="#dich-vu">Xem dịch vụ</a></div></div>
            <div class="heroVisual" data-hero-visual><div class="heroMetrics" data-card-swap role="button" tabindex="0" aria-label="Xem chỉ số tiếp theo">
                <?php foreach ($metrics as $index => [$value, $start, $end, $prefix, $suffix, $text]) : ?><div data-swap-card data-position="<?php echo esc_attr((string) $index); ?>" aria-hidden="<?php echo $index === 0 ? 'false' : 'true'; ?>"><article class="heroMetric"><span><?php echo esc_html($text); ?></span><strong data-counter data-start="<?php echo esc_attr($start); ?>" data-end="<?php echo esc_attr($end); ?>" data-prefix="<?php echo esc_attr($prefix); ?>" data-suffix="<?php echo esc_attr($suffix); ?>"><?php echo esc_html($value); ?></strong></article></div><?php endforeach; ?>
                <span data-swap-hint aria-hidden="true">Chạm để xem tiếp →</span>
            </div></div>
        </div>
    </section>

    <section class="homeAbout" id="gioi-thieu" aria-labelledby="home-about-title">
        <div class="homeAboutInner">
            <div class="homeAboutMedia" data-count="<?php echo esc_attr((string) count($about_images)); ?>"<?php if (count($about_images) > 1) : ?> data-card-swap data-card-swap-interval="1000" role="button" tabindex="0" aria-label="Xem thành tựu tiếp theo"<?php else : ?> aria-label="Thành tựu của Cyber Services Việt Nam"<?php endif; ?>>
                <?php if ($about_images) : ?>
                    <?php foreach ($about_images as $index => $image) : ?><figure data-swap-card data-position="<?php echo esc_attr((string) $index); ?>" aria-hidden="<?php echo $index === 0 ? 'false' : 'true'; ?>"><img src="<?php echo esc_url($image['src']); ?>" alt="<?php echo esc_attr($image['alt']); ?>" loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>"></figure><?php endforeach; ?>
                    <?php if (count($about_images) > 1) : ?><span class="homeAboutHint" aria-hidden="true">Chạm để xem tiếp →</span><?php endif; ?>
                <?php else : ?>
                    <div class="homeAboutPlaceholder"><img src="<?php echo cyber_services_asset('images/logo.png'); ?>" alt="Biểu trưng Cyber Services Việt Nam"><span>Tuân thủ · An toàn · Vững tin</span></div>
                <?php endif; ?>
            </div>
            <div class="homeAboutCopy" data-animate><p class="eyebrow">CÁC THÀNH TỰU ĐẠT ĐƯỢC</p><h2 id="home-about-title"><?php echo esc_html($about_title); ?></h2><p><?php echo esc_html($about_summary); ?></p><a class="secondaryButton" href="<?php echo esc_url(home_url('/gioi-thieu/')); ?>">Trao đổi với chuyên gia</a></div>
        </div>
    </section>

    <section class="servicesRedesign" id="dich-vu" aria-labelledby="services-title">
        <header class="servicesHeader" data-animate><p class="eyebrow">DỊCH VỤ</p><h2 id="services-title">Dịch vụ tư vấn &amp; chứng nhận</h2><p>Danh mục tiêu chuẩn quốc tế mà Cyber Services tư vấn, đánh giá và cấp chứng nhận.</p></header>
        <div class="serviceGrid"><?php foreach ($services as [$badge, $title, $description, $url, $timeline, $investment]) : ?><article class="serviceCard" data-animate><span class="serviceBadge"><?php echo esc_html($badge); ?></span><h3><?php echo esc_html($title); ?></h3><p class="serviceDescription"><?php echo esc_html($description); ?></p><?php if ($timeline !== '' || $investment !== '') : ?><dl class="serviceMeta"><?php if ($timeline !== '') : ?><div><dt>Timeline</dt><dd><?php echo esc_html($timeline); ?></dd></div><?php endif; ?><?php if ($investment !== '') : ?><div><dt>Investment</dt><dd><?php echo esc_html($investment); ?></dd></div><?php endif; ?></dl><?php endif; ?><a class="serviceMore" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Chi tiết dịch vụ →', 'cyber-services'); ?></a></article><?php endforeach; ?></div>
        <footer class="servicesFooter" data-animate><p>All services include: Gap assessment, remediation support, policy creation, and audit coordination</p><a class="primaryButton" href="#lien-he">Xem toàn bộ dịch vụ &amp; Báo giá</a></footer>
    </section>

    <?php $certifications = cyber_services_certifications(); ?>
    <?php if ($certifications) : ?>
    <section class="customers" id="chung-nhan" aria-labelledby="certification-banner-title">
        <div class="sectionHeading"><p class="eyebrow">CHỨNG NHẬN</p><h2 id="certification-banner-title">TỔ CHỨC CÔNG NHẬN</h2><p>Chúng tôi đồng hành với khách hàng đạt được các chứng nhận quốc tế uy tín thông qua các tổ chức đánh giá được công nhận toàn cầu.</p></div>
        <div class="logoLoop" data-logo-loop role="region" aria-label="Tổ chức công nhận">
            <div data-logo-track>
                <div data-logo-group><ul><?php foreach ($certifications as $certification) : ?><li data-certification-logo><?php if (($certification['src'] ?? '') !== '') : ?><img src="<?php echo esc_url($certification['src']); ?>" alt="<?php echo esc_attr($certification['alt'] ?? $certification['label']); ?>" width="<?php echo esc_attr((string) ($certification['width'] ?? 220)); ?>" height="<?php echo esc_attr((string) ($certification['height'] ?? 80)); ?>" loading="lazy" decoding="async"><?php else : ?><span><?php echo esc_html($certification['label']); ?></span><?php endif; ?></li><?php endforeach; ?></ul></div>
                <div data-logo-group aria-hidden="true" inert><ul><?php foreach ($certifications as $certification) : ?><li data-certification-logo><?php if (($certification['src'] ?? '') !== '') : ?><img src="<?php echo esc_url($certification['src']); ?>" alt="" width="<?php echo esc_attr((string) ($certification['width'] ?? 220)); ?>" height="<?php echo esc_attr((string) ($certification['height'] ?? 80)); ?>" loading="lazy" decoding="async"><?php else : ?><span><?php echo esc_html($certification['label']); ?></span><?php endif; ?></li><?php endforeach; ?></ul></div>
            </div>
        </div>
        <div class="certificationCta" data-animate>
            <a class="primaryButton" href="#tu-van">Liên Hệ Tư Vấn Ngay</a>
            <a class="secondaryButton zaloButton" href="https://zalo.me/84979875985" target="_blank" rel="noopener">Chat Zalo: +84 979 875 985</a>
            <a class="secondaryButton hotlineButton" href="tel:+84979875985">Hotline: +84 979 875 985</a>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($products) : ?>
        <section class="homeProducts" id="san-pham" aria-labelledby="home-products-title">
            <div class="homeProductsInner">
                <header class="homeProductsHeader" data-animate>
                    <p class="eyebrow">SẢN PHẨM &amp; GIẢI PHÁP</p>
                    <h2 id="home-products-title">Sản phẩm &amp; giải pháp</h2>
                    <p>Các công cụ và nền tảng giúp doanh nghiệp chủ động quản trị rủi ro, tuân thủ và nâng cao nhận thức bảo mật.</p>
                </header>
                <div class="homeProductsGrid">
                    <?php foreach ($products as [$badge, $title, $description, $url, $manufacturer, $support]) : ?>
                        <article class="homeProductCard" data-animate>
                            <span class="homeProductBadge"><?php echo esc_html($badge); ?></span>
                            <h3><?php echo esc_html($title); ?></h3>
                            <p class="homeProductDescription"><?php echo esc_html($description); ?></p>
                            <?php if ($manufacturer !== '' || $support !== '') : ?>
                                <dl class="homeProductMeta">
                                    <?php if ($manufacturer !== '') : ?><div><dt>Manufacturer</dt><dd><?php echo esc_html($manufacturer); ?></dd></div><?php endif; ?>
                                    <?php if ($support !== '') : ?><div><dt>Support</dt><dd><?php echo esc_html($support); ?></dd></div><?php endif; ?>
                                </dl>
                            <?php endif; ?>
                            <a class="homeProductMore" href="<?php echo esc_url($url); ?>">Xem chi tiết <span aria-hidden="true">→</span></a>
                        </article>
                    <?php endforeach; ?>
                </div>
                <a class="homeProductsMore" href="<?php echo esc_url(home_url('/san-pham/')); ?>">Xem toàn bộ sản phẩm &amp; giải pháp <span aria-hidden="true">→</span></a>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($home_news_featured instanceof WP_Post) : ?>
        <?php
        $home_news_featured_url = get_permalink($home_news_featured);
        $home_news_featured_excerpt = wp_trim_words(wp_strip_all_tags(get_the_excerpt($home_news_featured)), 26, '…');
        ?>
        <section class="homeNews" id="tin-tuc" aria-labelledby="home-news-title">
            <div class="homeNewsInner">
                <header class="homeNewsHeader" data-animate>
                    <div>
                        <p class="eyebrow">TIN TỨC</p>
                        <h2 id="home-news-title">Tin tức &amp; góc nhìn</h2>
                    </div>
                    <a class="homeNewsArchiveLink" href="<?php echo esc_url($home_news_archive_url); ?>">Xem tất cả <span aria-hidden="true">→</span></a>
                </header>
                <div class="homeNewsLayout">
                    <article class="homeNewsFeatured" data-animate>
                        <a class="homeNewsFeaturedMedia" href="<?php echo esc_url($home_news_featured_url); ?>" aria-label="<?php echo esc_attr('Đọc ' . get_the_title($home_news_featured)); ?>">
                            <?php if (has_post_thumbnail($home_news_featured)) : ?>
                                <?php echo get_the_post_thumbnail($home_news_featured, 'large', ['loading' => 'lazy']); ?>
                            <?php else : ?>
                                <span class="homeNewsMediaPlaceholder" aria-hidden="true">CS / TIN TỨC</span>
                            <?php endif; ?>
                        </a>
                        <div class="homeNewsFeaturedBody">
                            <div class="homeNewsMeta"><time datetime="<?php echo esc_attr(get_the_date(DATE_W3C, $home_news_featured)); ?>"><?php echo esc_html(get_the_date('d.m.Y', $home_news_featured)); ?></time><span>Góc nhìn Cyber Services</span></div>
                            <h3><a href="<?php echo esc_url($home_news_featured_url); ?>"><?php echo esc_html(get_the_title($home_news_featured)); ?></a></h3>
                            <?php if ($home_news_featured_excerpt !== '') : ?><p><?php echo esc_html($home_news_featured_excerpt); ?></p><?php endif; ?>
                            <a class="homeNewsReadLink" href="<?php echo esc_url($home_news_featured_url); ?>">Đọc bài viết <span aria-hidden="true">→</span></a>
                        </div>
                    </article>

                    <div class="homeNewsFeed">
                        <nav class="homeNewsTabs" aria-label="Danh mục tin tức">
                            <?php foreach ($home_news_tabs as $tab) : ?><a class="homeNewsTab<?php echo $tab['active'] ? ' is-active' : ''; ?>" href="<?php echo esc_url($tab['url']); ?>"<?php echo $tab['active'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html($tab['label']); ?></a><?php endforeach; ?>
                        </nav>
                        <?php if ($home_news_items) : ?>
                            <div class="homeNewsList">
                                <?php foreach ($home_news_items as $home_news_item) : ?>
                                    <?php $home_news_item_url = get_permalink($home_news_item); ?>
                                    <article class="homeNewsItem" data-animate>
                                        <a class="homeNewsItemMedia" href="<?php echo esc_url($home_news_item_url); ?>" aria-label="<?php echo esc_attr('Đọc ' . get_the_title($home_news_item)); ?>">
                                            <?php if (has_post_thumbnail($home_news_item)) : ?>
                                                <?php echo get_the_post_thumbnail($home_news_item, 'medium', ['loading' => 'lazy']); ?>
                                            <?php else : ?>
                                                <span class="homeNewsMediaPlaceholder" aria-hidden="true">CS</span>
                                            <?php endif; ?>
                                        </a>
                                        <div class="homeNewsItemBody">
                                            <time datetime="<?php echo esc_attr(get_the_date(DATE_W3C, $home_news_item)); ?>"><?php echo esc_html(get_the_date('d.m.Y', $home_news_item)); ?></time>
                                            <h3><a href="<?php echo esc_url($home_news_item_url); ?>"><?php echo esc_html(get_the_title($home_news_item)); ?></a></h3>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="proof" id="vi-sao" aria-labelledby="proof-title"><div class="proofInner"><div class="proofCopy" data-animate><p class="eyebrow">VÌ SAO CHỌN CHÚNG TÔI</p><h2 id="proof-title">Đi trước một bước so với rủi ro</h2><p>Đội ngũ chuyên gia giàu kinh nghiệm về an ninh mạng và tuân thủ, phục vụ từ doanh nghiệp vừa và nhỏ đến ngân hàng và tổ chức lớn với hệ thống phức tạp, nhiều môi trường và yêu cầu giám sát nghiêm ngặt.</p></div><div class="proofMetrics" data-proof-metrics aria-label="Kết quả nổi bật"><?php foreach ($metrics as [$value, $start, $end, $prefix, $suffix, $text]) : ?><article class="proofMetric" data-animate><span><?php echo esc_html($text); ?></span><strong data-counter data-start="<?php echo esc_attr($start); ?>" data-end="<?php echo esc_attr($end); ?>" data-prefix="<?php echo esc_attr($prefix); ?>" data-suffix="<?php echo esc_attr($suffix); ?>"><?php echo esc_html($value); ?></strong></article><?php endforeach; ?></div></div></section>

    <section class="process" id="quy-trinh" aria-labelledby="process-title"><div class="sectionHeading" data-animate><p class="eyebrow">QUY TRÌNH</p><h2 id="process-title">Quy trình 4 bước</h2></div><ol class="processGrid"><?php foreach ($process as [$number, $title, $description]) : ?><li data-animate data-process-card><span class="stepNumber"><?php echo esc_html($number); ?></span><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($description); ?></p></li><?php endforeach; ?></ol></section>

    <?php if ($customers) : ?><section class="customers" id="khach-hang" aria-labelledby="customers-title"><div class="sectionHeading"><p class="eyebrow">KHÁCH HÀNG</p><h2 id="customers-title">Khách hàng tiêu biểu</h2></div><div class="logoLoop" data-logo-loop role="region" aria-label="Khách hàng tiêu biểu" tabindex="0"><div data-logo-track><div data-logo-group><ul><?php foreach ($customers as $customer) : ?><li data-customer-logo><?php if ($customer['src'] !== '') : ?><img src="<?php echo esc_url($customer['src']); ?>" alt="<?php echo esc_attr($customer['alt']); ?>" width="<?php echo esc_attr((string) $customer['width']); ?>" height="<?php echo esc_attr((string) $customer['height']); ?>"><?php else : ?><span><?php echo esc_html($customer['name']); ?></span><?php endif; ?></li><?php endforeach; ?></ul></div><div data-logo-group aria-hidden="true"><ul><?php foreach ($customers as $customer) : ?><li data-customer-logo><?php if ($customer['src'] !== '') : ?><img src="<?php echo esc_url($customer['src']); ?>" alt="" width="<?php echo esc_attr((string) $customer['width']); ?>" height="<?php echo esc_attr((string) $customer['height']); ?>"><?php else : ?><span><?php echo esc_html($customer['name']); ?></span><?php endif; ?></li><?php endforeach; ?></ul></div></div></div></section><?php endif; ?>

    <section class="contact" id="lien-he" aria-labelledby="contact-title"><div class="contactInner"><div class="contactCopy" data-animate><p class="eyebrow">LIÊN HỆ</p><h2 id="contact-title">Hãy trao đổi về nhu cầu của bạn</h2><p>Bạn đang chuẩn bị xin giấy phép, làm việc với ngân hàng, đối tác quốc tế — hay đơn giản là muốn hệ thống an toàn hơn? Hãy để Cyber Services phân tích hiện trạng và đề xuất giải pháp phù hợp nhất với ngân sách của bạn.</p><dl class="contactList"><div><dt>Điện thoại / Zalo</dt><dd><a href="<?php echo esc_url($contact['phone_url']); ?>"><?php echo esc_html($contact['phone']); ?></a></dd></div><div><dt>Email</dt><dd><a href="<?php echo esc_url($contact['email_url']); ?>"><?php echo esc_html($contact['email']); ?></a></dd></div><div><dt>Địa chỉ</dt><dd><?php echo esc_html($contact['address']); ?></dd></div><div><dt>Giờ làm việc</dt><dd><?php echo esc_html($contact['hours']); ?></dd></div></dl><nav class="contactSocial" aria-label="Mạng xã hội"><?php foreach ($social as [$name, $href]) : ?><a href="<?php echo esc_url($href); ?>" rel="noreferrer"><?php echo cyber_services_icon($name); ?><?php echo esc_html($name); ?></a><?php endforeach; ?></nav></div>
        <?php get_template_part('template-parts/contact-form', null, ['services' => $services, 'status_state' => $contact_status_state, 'status_message' => $contact_status_message, 'return_url' => home_url('/#lien-he')]); ?>
    </div></section>
</main>

<footer class="footer"><div class="footerInner"><div class="footerBrand"><div class="footerLogoWrap"><?php echo cyber_services_footer_logo_markup(); ?></div><p>Tư vấn, đánh giá và chứng nhận tuân thủ an ninh mạng cho doanh nghiệp tại Việt Nam.</p><nav class="footerSocial" aria-label="Mạng xã hội"><?php foreach ($social as [$name, $href]) : ?><a href="<?php echo esc_url($href); ?>" rel="noreferrer" aria-label="<?php echo esc_attr($name); ?>"><?php echo cyber_services_icon($name); ?></a><?php endforeach; ?></nav></div><nav aria-label="Dịch vụ"><h3>Dịch vụ</h3><?php foreach ($footer_services as [$label, $href]) : ?><a href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav><nav aria-label="Sản phẩm"><h3>Sản phẩm</h3><?php foreach ($footer_products as [$label, $href]) : ?><a href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav><nav aria-label="Công ty"><h3>Công ty</h3><?php foreach ($footer_company as [$label, $href]) : ?><a href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav><div class="footerContact"><h3 class="footerContactHeading">Liên hệ</h3><p class="footerCompanyName">Công ty cổ phần Cyber Services Việt Nam</p><p><strong>Hotline:</strong> <a href="<?php echo esc_url($contact['phone_url']); ?>"><?php echo esc_html($contact['phone']); ?></a></p><p><strong>Email:</strong> <a href="<?php echo esc_url($contact['email_url']); ?>"><?php echo esc_html($contact['email']); ?></a></p><p><strong>Địa chỉ:</strong> <?php echo esc_html($contact['address']); ?></p><p><strong>Giờ làm việc:</strong> <?php echo esc_html($contact['hours']); ?></p></div><div class="footerBottom"><span>© <?php echo esc_html(wp_date('Y')); ?> Cyber Services Việt Nam. All rights reserved.</span><nav class="footerPolicyLinks" aria-label="Chính sách website"><?php foreach ($policy_links as $policy_link) : ?><a href="<?php echo esc_url($policy_link['url']); ?>"><?php echo esc_html($policy_link['label']); ?></a><?php endforeach; ?></nav></div></div></footer>

<?php get_footer();
