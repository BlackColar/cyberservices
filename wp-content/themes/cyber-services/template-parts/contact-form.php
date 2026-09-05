<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$services = isset($args['services']) && is_array($args['services']) ? $args['services'] : cyber_services_services();
$status_state = isset($args['status_state']) ? sanitize_key((string) $args['status_state']) : 'idle';
$status_message = isset($args['status_message']) ? (string) $args['status_message'] : '';
$return_url = isset($args['return_url']) ? (string) $args['return_url'] : home_url('/#lien-he');
?>
<form class="contactForm" data-contact-form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
    <div><p>YÊU CẦU TƯ VẤN</p><h3>Gửi thông tin cho chúng tôi</h3></div>
    <input type="hidden" name="action" value="cyber_contact">
    <input type="hidden" name="cyber_contact_return" value="<?php echo esc_url($return_url); ?>">
    <?php wp_nonce_field('cyber_contact_form', 'cyber_contact_nonce'); ?>
    <label data-honeypot aria-hidden="true">Website công ty<input name="company_website" tabindex="-1" autocomplete="off"></label>
    <div data-form-grid>
        <label>Họ và tên<input name="name" autocomplete="name" placeholder="Nguyễn Văn A" required></label>
        <label>Email<input name="email" type="email" autocomplete="email" placeholder="email@congty.vn" required></label>
        <label>Số điện thoại<input name="phone" type="tel" inputmode="numeric" autocomplete="tel" pattern="[0-9]{10}" maxlength="10" placeholder="0912345678" title="Vui lòng nhập đúng 10 chữ số." required></label>
        <label>Dịch vụ quan tâm<select name="service" required><option value="" selected disabled>Chọn dịch vụ</option><?php foreach ($services as $service) : ?><option value="<?php echo esc_attr((string) $service[1]); ?>"><?php echo esc_html((string) $service[1]); ?></option><?php endforeach; ?></select></label>
    </div>
    <label>Nội dung cần tư vấn<textarea name="message" rows="4" placeholder="Mô tả ngắn nhu cầu, tiêu chuẩn hoặc thời gian dự kiến..." required></textarea></label>
    <button type="submit">Gửi yêu cầu tư vấn<span aria-hidden="true">↗</span></button>
    <small><?php esc_html_e('Thông tin của bạn chỉ được sử dụng để phản hồi yêu cầu tư vấn.', 'cyber-services'); ?></small>
    <p data-form-status data-state="<?php echo esc_attr($status_state); ?>" role="status" aria-live="polite"><?php echo esc_html($status_message); ?></p>
</form>
