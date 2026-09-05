<?php
declare(strict_types=1);

get_header();

while (have_posts()) :
    the_post();
    $services = cyber_services_services();
    $social = cyber_services_social_links();
    $contact = cyber_services_contact_details();
    [$status_state, $status_message] = cyber_services_contact_status();
    $summary = has_excerpt()
        ? get_the_excerpt()
        : 'Trao đổi trực tiếp với đội ngũ Cyber Services về nhu cầu tuân thủ, chứng nhận và an toàn thông tin của doanh nghiệp.';
    ?>
    <main id="noi-dung" class="contact-page">
        <section class="hero contact-page-hero" aria-labelledby="contact-page-title">
            <div class="container contact-page-hero-grid">
                <div>
                    <p class="eyebrow">KẾT NỐI VỚI CHUYÊN GIA</p>
                    <h1 id="contact-page-title"><?php the_title(); ?></h1>
                    <p class="lead"><?php echo esc_html($summary); ?></p>
                </div>
                <div class="contact-page-hero-actions" aria-label="Kênh liên hệ nhanh">
                    <a href="<?php echo esc_url($contact['phone_url']); ?>"><span>Gọi trực tiếp</span><strong><?php echo esc_html($contact['phone']); ?></strong></a>
                    <a href="<?php echo esc_url($contact['zalo_url']); ?>"><span>Nhắn qua Zalo</span><strong>Bắt đầu trao đổi ↗</strong></a>
                </div>
            </div>
        </section>

        <section class="contact contact-page-core" id="gui-yeu-cau" aria-labelledby="contact-form-title">
            <div class="contactInner">
                <div class="contactCopy">
                    <p class="eyebrow">THÔNG TIN LIÊN HỆ</p>
                    <h2 id="contact-form-title">Hãy cho chúng tôi biết mục tiêu của bạn</h2>
                    <p>Cyber Services sẽ tiếp nhận thông tin, làm rõ phạm vi và đề xuất hướng triển khai phù hợp với hiện trạng của doanh nghiệp.</p>
                    <dl class="contactList">
                        <div><dt>Điện thoại / Zalo</dt><dd><a href="<?php echo esc_url($contact['phone_url']); ?>"><?php echo esc_html($contact['phone']); ?></a></dd></div>
                        <div><dt>Email</dt><dd><a href="<?php echo esc_url($contact['email_url']); ?>"><?php echo esc_html($contact['email']); ?></a></dd></div>
                        <div><dt>Địa chỉ</dt><dd><?php echo esc_html($contact['address']); ?></dd></div>
                        <div><dt>Giờ làm việc</dt><dd><?php echo esc_html($contact['hours']); ?></dd></div>
                    </dl>
                    <nav class="contactSocial" aria-label="Mạng xã hội"><?php foreach ($social as [$name, $href]) : ?><a href="<?php echo esc_url($href); ?>" rel="noreferrer"><?php echo cyber_services_icon($name); ?><?php echo esc_html($name); ?></a><?php endforeach; ?></nav>
                </div>
                <?php get_template_part('template-parts/contact-form', null, ['services' => $services, 'status_state' => $status_state, 'status_message' => $status_message, 'return_url' => get_permalink() . '#gui-yeu-cau']); ?>
            </div>
        </section>

        <section class="section contact-channels" aria-labelledby="contact-channels-title">
            <div class="container">
                <div class="section-heading"><h2 id="contact-channels-title">Liên hệ theo cách thuận tiện nhất</h2></div>
                <div class="contact-channel-grid">
                    <a href="<?php echo esc_url($contact['phone_url']); ?>"><span>01</span><h3>Hotline</h3><p><?php echo esc_html($contact['phone']); ?></p><strong>Gọi ngay ↗</strong></a>
                    <a href="<?php echo esc_url($contact['zalo_url']); ?>"><span>02</span><h3>Zalo</h3><p>Trao đổi nhanh với đội ngũ tư vấn.</p><strong>Mở Zalo ↗</strong></a>
                    <a href="<?php echo esc_url($contact['email_url']); ?>"><span>03</span><h3>Email</h3><p><?php echo esc_html($contact['email']); ?></p><strong>Soạn email ↗</strong></a>
                </div>
            </div>
        </section>

        <section class="section contact-response" aria-labelledby="contact-response-title">
            <div class="container">
                <div class="section-heading"><h2 id="contact-response-title">Từ nhu cầu đến một lộ trình rõ ràng</h2></div>
                <ol class="contact-response-grid">
                    <li><span>01</span><h3>Tiếp nhận nhu cầu</h3><p>Ghi nhận tiêu chuẩn, phạm vi hệ thống và mục tiêu doanh nghiệp đang hướng tới.</p></li>
                    <li><span>02</span><h3>Phân tích hiện trạng</h3><p>Làm rõ bối cảnh kỹ thuật, yêu cầu tuân thủ và những bên liên quan.</p></li>
                    <li><span>03</span><h3>Đề xuất lộ trình</h3><p>Xác định các hạng mục ưu tiên, phương án triển khai và đầu ra cần thiết.</p></li>
                    <li><span>04</span><h3>Hẹn buổi tư vấn</h3><p>Thống nhất buổi làm việc để trao đổi phạm vi, tiến độ và báo giá.</p></li>
                </ol>
            </div>
        </section>

        <section class="cta contact-page-cta"><div class="container cta-inner"><h2>Bắt đầu bằng một cuộc trao đổi ngắn về nhu cầu của bạn.</h2><a class="button dark" href="#gui-yeu-cau">Gửi yêu cầu tư vấn</a></div></section>
    </main>
    <?php
endwhile;

get_footer();
