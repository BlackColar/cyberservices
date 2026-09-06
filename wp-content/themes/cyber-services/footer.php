<?php if (!is_front_page()) :
    $navigation = cyber_services_navigation(true);
    [$footer_services, $footer_products, $footer_company] = cyber_services_footer_navigation($navigation);
    $policy_links = cyber_services_policy_links();
    $social = cyber_services_social_links();
    $contact = cyber_services_contact_details();
?>
<footer class="footer"><div class="footerInner"><div class="footerBrand"><div class="footerLogoWrap"><?php echo cyber_services_footer_logo_markup(); ?></div><p>Đồng hành cùng doanh nghiệp xây dựng năng lực tuân thủ và an ninh mạng có thể duy trì.</p><nav class="footerSocial" aria-label="<?php esc_attr_e('Mạng xã hội', 'cyber-services'); ?>"><?php foreach ($social as [$name, $href]) : ?><a href="<?php echo esc_url($href); ?>" rel="noreferrer" aria-label="<?php echo esc_attr($name); ?>"><?php echo cyber_services_icon($name); ?></a><?php endforeach; ?></nav></div><nav aria-label="Dịch vụ"><h3>Dịch vụ</h3><?php foreach ($footer_services as [$label, $href]) : ?><a href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav><nav aria-label="Sản phẩm"><h3>Sản phẩm</h3><?php foreach ($footer_products as [$label, $href]) : ?><a href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav><nav aria-label="Công ty"><h3>Công ty</h3><?php foreach ($footer_company as [$label, $href]) : ?><a href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav><div class="footerContact"><h3 class="footerContactHeading">Liên hệ</h3><p class="footerCompanyName">Công ty cổ phần Cyber Services Việt Nam</p><p><strong>Hotline:</strong> <a href="<?php echo esc_url($contact['phone_url']); ?>"><?php echo esc_html($contact['phone']); ?></a></p><p><strong>Email:</strong> <a href="<?php echo esc_url($contact['email_url']); ?>"><?php echo esc_html($contact['email']); ?></a></p><p><strong>Địa chỉ:</strong> <?php echo esc_html($contact['address']); ?></p><p><strong>Giờ làm việc:</strong> <?php echo esc_html($contact['hours']); ?></p></div><div class="footerBottom"><span>© <?php echo esc_html(wp_date('Y')); ?> Cyber Services Việt Nam. All rights reserved.</span><nav class="footerPolicyLinks" aria-label="Chính sách website"><?php foreach ($policy_links as $policy_link) : ?><a href="<?php echo esc_url($policy_link['url']); ?>"><?php echo esc_html($policy_link['label']); ?></a><?php endforeach; ?></nav></div></div></footer>
<?php endif; ?>
<?php get_template_part('template-parts/contact-launcher'); ?>
<?php wp_footer(); ?>
</body>
</html>
