<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$contact = cyber_services_contact_details();
$social = array_column(cyber_services_social_links(), 1, 0);
?>
<div class="contactLauncher" data-contact-launcher data-open="false">
    <div class="contactLauncherPanel" id="contact-launcher-panel" aria-hidden="true" inert>
        <div class="contactLauncherHeading"><span>Liên hệ ngay</span><strong>Chọn kênh thuận tiện cho bạn</strong></div>
        <div class="contactLauncherPromo" id="contact-launcher-promo" role="note">
            <p><span aria-hidden="true">🎁</span> <strong>ƯU ĐÃI ĐẶC QUYỀN:</strong> Giảm ngay <b>30% - 50%</b> chi phí dịch vụ khi đăng ký tư vấn hôm nay</p>
            <a href="<?php echo esc_url(home_url('/#lien-he')); ?>">Nhận ưu đãi &amp; Tư vấn ngay <span aria-hidden="true">→</span></a>
        </div>
        <nav aria-label="Kênh liên hệ nhanh">
            <a href="<?php echo esc_url($contact['zalo_url']); ?>"><span><?php echo cyber_services_icon('Zalo'); ?></span><div><strong>Zalo</strong><small>Trò chuyện với chuyên gia</small></div><b aria-hidden="true">↗</b></a>
            <a href="<?php echo esc_url($contact['phone_url']); ?>"><span><?php echo cyber_services_icon('Phone'); ?></span><div><strong>Điện thoại</strong><small><?php echo esc_html($contact['phone']); ?></small></div><b aria-hidden="true">↗</b></a>
            <a href="<?php echo esc_url($social['Telegram'] ?? ''); ?>"><span><?php echo cyber_services_icon('Telegram'); ?></span><div><strong>Telegram</strong><small>Nhắn tin trực tiếp</small></div><b aria-hidden="true">↗</b></a>
            <a href="<?php echo esc_url($social['Facebook'] ?? ''); ?>"><span><?php echo cyber_services_icon('Facebook'); ?></span><div><strong>Facebook</strong><small>Theo dõi và trò chuyện</small></div><b aria-hidden="true">↗</b></a>
        </nav>
    </div>
    <button class="contactLauncherButton" type="button" aria-expanded="false" aria-controls="contact-launcher-panel" aria-label="Mở các kênh liên hệ"><span data-contact-open><?php echo cyber_services_icon('Phone'); ?></span><span data-contact-close aria-hidden="true">×</span></button>
</div>
