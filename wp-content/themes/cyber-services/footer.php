<?php if (!is_front_page()) : ?>
<footer class="site-footer">
    <div class="footer-inner">
        <div class="footerBrand"><div class="footerLogoWrap"><?php echo cyber_services_footer_logo_markup(); ?></div><p>Đồng hành cùng doanh nghiệp xây dựng năng lực tuân thủ và an ninh mạng có thể duy trì.</p><nav class="footerSocial" aria-label="<?php esc_attr_e('Mạng xã hội', 'cyber-services'); ?>"><?php foreach (cyber_services_social_links() as [$name, $href]) : ?><a href="<?php echo esc_url($href); ?>" rel="noreferrer" aria-label="<?php echo esc_attr($name); ?>"><?php echo cyber_services_icon($name); ?></a><?php endforeach; ?></nav></div>
        <nav aria-label="<?php esc_attr_e('Điều hướng chân trang', 'cyber-services'); ?>"><?php foreach (cyber_services_navigation(false) as [$label, $href, $children]) : ?><a href="<?php echo esc_url($href); ?>"<?php echo cyber_services_navigation_current($href); ?>><?php echo esc_html($label); ?></a><?php if (sanitize_title($label) === 'resource') : ?><?php foreach ($children as [$child_label, $child_href]) : ?><a href="<?php echo esc_url($child_href); ?>"<?php echo cyber_services_navigation_current($child_href); ?>><?php echo esc_html($child_label); ?></a><?php endforeach; ?><?php endif; ?><?php endforeach; ?></nav>
        <p>© <?php echo esc_html(wp_date('Y')); ?> Cyber Services Việt Nam. All rights reserved.</p>
    </div>
</footer>
<?php endif; ?>
<?php get_template_part('template-parts/contact-launcher'); ?>
<?php wp_footer(); ?>
</body>
</html>
