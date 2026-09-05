<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="preload" href="<?php echo esc_url(get_theme_file_uri('assets/fonts/be-vietnam-pro.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <?php if (!has_site_icon()) : ?><link rel="icon" type="image/png" href="<?php echo esc_url(get_theme_file_uri('assets/images/logo.png')); ?>"><?php endif; ?>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php if (!is_front_page()) : ?>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="<?php echo esc_url(home_url('/#top')); ?>" aria-label="<?php esc_attr_e('Cyber Services Việt Nam — Trang chủ', 'cyber-services'); ?>">
            <?php echo cyber_services_brand_markup(); ?>
        </a>
        <?php $navigation = cyber_services_navigation(false); ?>
        <div class="root" data-staggered-menu data-open="false">
            <nav class="desktop" aria-label="<?php esc_attr_e('Điều hướng chính', 'cyber-services'); ?>"><ul><?php cyber_services_navigation_desktop($navigation); ?></ul></nav>
            <button class="trigger" type="button" aria-expanded="false" aria-controls="cyber-inner-menu" aria-label="Mở trình đơn"><span></span><span></span><span class="triggerText">Trình đơn</span></button>
            <button class="overlay" type="button" tabindex="-1" aria-label="Đóng trình đơn"></button>
            <div class="preLayer preLayerOne" aria-hidden="true"></div><div class="preLayer preLayerTwo" aria-hidden="true"></div>
            <aside class="panel" id="cyber-inner-menu" role="dialog" aria-modal="true" aria-label="Trình đơn chính" aria-hidden="true" inert><div class="panelTop"><a class="menuCta" href="<?php echo esc_url(home_url('/#lien-he')); ?>">Miễn phí tư vấn &amp; Báo giá</a><button class="close" type="button" aria-label="Đóng trình đơn"><span aria-hidden="true"></span></button></div><nav aria-label="Điều hướng di động"><ul class="drawerList"><?php cyber_services_navigation_drawer($navigation); ?></ul></nav></aside>
        </div>
        <a class="header-cta" href="<?php echo esc_url(home_url('/#lien-he')); ?>">Nhận tư vấn</a>
    </div>
</header>
<?php endif; ?>
