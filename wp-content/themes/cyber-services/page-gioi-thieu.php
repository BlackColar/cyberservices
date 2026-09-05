<?php
declare(strict_types=1);

get_header();

while (have_posts()) :
    the_post();
    $page = get_post();
    $images = $page instanceof WP_Post ? cyber_services_page_images($page) : [];
    $summary = has_excerpt()
        ? get_the_excerpt()
        : 'Cyber Services Việt Nam đồng hành cùng doanh nghiệp xây dựng năng lực tuân thủ và an toàn thông tin có thể duy trì trong thực tế.';
    $overview = [
        'Trong môi trường kinh doanh toàn cầu hóa, việc đạt được các chứng nhận quốc tế và tuân thủ các quy định khắt khe về bảo mật không chỉ là nghĩa vụ pháp lý, mà còn là “tấm thẻ bài” để doanh nghiệp khẳng định uy tín và chinh phục các thị trường khó tính nhất. Cyber Services Việt Nam ra đời với tư cách là đối tác chiến lược chuyên biệt trong lĩnh vực Tư vấn, Đánh giá và Hỗ trợ Tuân thủ (Governance, Risk, and Compliance — GRC).',
        'Chúng tôi tập hợp đội ngũ chuyên gia tư vấn, kiểm toán viên hệ thống thông tin giàu kinh nghiệm trong việc triển khai các khuôn khổ tiêu chuẩn khắt khe như PCI, SOC, ISO, HIPAA, CMMI và các quy định về bảo vệ dữ liệu cá nhân như GDPR, CCPA, Nghị định 356.',
        'Cyber Services Việt Nam mang đến lộ trình chuẩn hóa rõ ràng, tinh gọn và bám sát thực tiễn hoạt động của từng tổ chức; giúp doanh nghiệp rút ngắn thời gian đánh giá, tối ưu nguồn lực và vượt qua các kỳ kiểm toán hiệu quả.',
    ];
    $pillars = [
        ['Tầm nhìn chiến lược', 'Trở thành đơn vị Tư vấn Tuân thủ và Quản trị Rủi ro hàng đầu tại Việt Nam; xây dựng hệ sinh thái doanh nghiệp Việt đạt chuẩn quốc tế, tự tin hội nhập và hợp tác bình đẳng với các tập đoàn công nghệ, tài chính và y tế toàn cầu thông qua sự minh bạch và an toàn dữ liệu.'],
        ['Sứ mệnh cốt lõi', 'Biến các tiêu chuẩn phức tạp trở nên dễ tiếp cận và khả thi đối với mọi doanh nghiệp. Chúng tôi đồng hành cùng khách hàng thiết lập quy trình vận hành chuẩn mực, bảo vệ quyền riêng tư dữ liệu và kiến tạo niềm tin với đối tác, người dùng cuối.'],
    ];
    $values = [
        ['01', 'Chính xác', 'Mọi tư vấn và đánh giá đều bám sát các khuôn khổ, bộ tiêu chuẩn và khung pháp lý cập nhật.'],
        ['02', 'Bảo mật', 'Cam kết bảo mật thông tin kinh doanh, dữ liệu nội bộ và hạ tầng hệ thống của khách hàng.'],
        ['03', 'Thực tiễn', 'Giải pháp được điều chỉnh phù hợp với văn hóa, quy mô và đặc thù của từng doanh nghiệp.'],
        ['04', 'Đồng hành', 'Hỗ trợ duy trì hệ thống và tuân thủ liên tục trong suốt vòng đời doanh nghiệp.'],
    ];
    $services = [
        ['01', 'Tuân thủ Thanh toán Thẻ (PCI)', 'Tư vấn và đánh giá cấp chứng nhận PCI DSS, PCI PIN, PCI 3DS, PCI Card Production và các tiêu chuẩn bảo mật thanh toán liên quan.'],
        ['02', 'Báo cáo Dịch vụ (SOC)', 'Hỗ trợ đánh giá và phát hành báo cáo SOC 1, SOC 2, SOC 3, đáp ứng các tiêu chí về bảo mật, tính khả dụng và toàn vẹn dữ liệu.'],
        ['03', 'Hệ thống Quản lý ISO', 'Xây dựng quy trình để đạt các chứng nhận ISO 27001, ISO 9001 và ISO 27701.'],
        ['04', 'Bảo vệ Dữ liệu', 'Thiết lập khung quản trị dữ liệu tuân thủ Nghị định 356, GDPR, CCPA và các khuôn khổ quyền riêng tư toàn cầu.'],
        ['05', 'Tiêu chuẩn Y tế HIPAA', 'Tư vấn tuân thủ HIPAA cho tổ chức y tế và đối tác liên quan, bảo vệ nghiêm ngặt thông tin sức khỏe cá nhân.'],
        ['06', 'Mô hình Trưởng thành CMMI', 'Đánh giá và tư vấn nâng cao năng lực quy trình phát triển phần mềm theo mô hình CMMI từ Level 3 đến Level 5.'],
    ];
    $commitment = 'Lựa chọn Cyber Services Việt Nam là lựa chọn một tấm khiên pháp lý vững chắc và bảo chứng chất lượng cho thương hiệu. Chúng tôi cam kết đồng hành cùng doanh nghiệp vượt qua các kỳ kiểm toán, biến chi phí tuân thủ thành lợi thế cạnh tranh dài hạn.';
    if ($page instanceof WP_Post) {
        $about_data = cyber_services_about_page_data($page);
        $overview = $about_data['overview'] ?: $overview;
        $pillars = $about_data['pillars'] ?: $pillars;
        $values = $about_data['values'] ?: $values;
        $services = $about_data['services'] ?: $services;
        $commitment = $about_data['commitment'] ?: $commitment;
    }
    ?>
    <main id="noi-dung" class="about-page">
        <section class="hero about-hero" aria-labelledby="about-title">
            <div class="container about-hero-grid">
                <div class="about-hero-copy">
                    <p class="eyebrow">VỀ CYBER SERVICES VIỆT NAM</p>
                    <h1 id="about-title"><?php the_title(); ?></h1>
                    <p class="lead"><?php echo esc_html($summary); ?></p>
                    <a class="button" href="<?php echo esc_url(home_url('/lien-he/')); ?>">Trao đổi với chuyên gia</a>
                </div>
                <div class="about-media" data-count="<?php echo esc_attr((string) count($images)); ?>"<?php if (count($images) > 1) : ?> data-card-swap role="button" tabindex="0" aria-label="Xem hình ảnh tiếp theo"<?php else : ?> aria-label="Hình ảnh Cyber Services Việt Nam"<?php endif; ?>>
                    <?php if ($images) : ?>
                        <?php foreach ($images as $index => $image) : ?><figure class="about-media-<?php echo esc_attr((string) ($index + 1)); ?>" data-swap-card data-position="<?php echo esc_attr((string) $index); ?>" aria-hidden="<?php echo $index === 0 ? 'false' : 'true'; ?>"><img src="<?php echo esc_url($image['src']); ?>" alt="<?php echo esc_attr($image['alt']); ?>" loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>"></figure><?php endforeach; ?>
                        <?php if (count($images) > 1) : ?><span class="about-media-hint" aria-hidden="true">Chạm để xem tiếp →</span><?php endif; ?>
                    <?php else : ?>
                        <div class="about-media-placeholder"><img src="<?php echo cyber_services_asset('images/logo.png'); ?>" alt="Biểu trưng Cyber Services Việt Nam"><span>Tuân thủ · An toàn · Vững tin</span></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="section about-focus" aria-labelledby="about-focus-title">
            <div class="container split">
                <div><p class="eyebrow">LĨNH VỰC HOẠT ĐỘNG</p><h2 id="about-focus-title">Từ yêu cầu tuân thủ đến năng lực vận hành bền vững</h2></div>
                <div class="about-focus-copy"><?php foreach ($overview as $paragraph) : ?><p><?php echo esc_html($paragraph); ?></p><?php endforeach; ?></div>
            </div>
        </section>

        <section class="section about-pillars" aria-labelledby="about-pillars-title">
            <div class="container">
                <div class="section-heading"><h2 id="about-pillars-title">Tầm nhìn và sứ mệnh</h2><p>Chuẩn hóa năng lực tuân thủ để doanh nghiệp Việt tự tin phát triển trong thị trường toàn cầu.</p></div>
                <div class="about-pillars-grid"><?php foreach ($pillars as $index => [$title, $description]) : ?><article><span>0<?php echo esc_html((string) ($index + 1)); ?></span><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($description); ?></p></article><?php endforeach; ?></div>
            </div>
        </section>

        <section class="about-values" aria-labelledby="about-values-title">
            <div class="container">
                <div class="section-heading"><h2 id="about-values-title">Giá trị cốt lõi</h2><p>Những nguyên tắc xuyên suốt trong mỗi chương trình tư vấn và đánh giá.</p></div>
                <div class="about-values-grid"><?php foreach ($values as [$number, $title, $description]) : ?><article><strong><?php echo esc_html($number); ?></strong><div><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($description); ?></p></div></article><?php endforeach; ?></div>
            </div>
        </section>

        <section class="section about-workflow" aria-labelledby="about-services-title">
            <div class="container">
                <div class="section-heading"><h2 id="about-services-title">Các dịch vụ tuân thủ mũi nhọn</h2><p>Năng lực chuyên môn bao phủ các tiêu chuẩn bảo mật, quản trị và bảo vệ dữ liệu quan trọng.</p></div>
                <ol class="about-workflow-grid"><?php foreach ($services as [$number, $title, $description]) : ?><li><span><?php echo esc_html($number); ?></span><div><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($description); ?></p></div></li><?php endforeach; ?></ol>
            </div>
        </section>

        <section class="section about-commitment" aria-labelledby="about-commitment-title">
            <div class="container split">
                <div><p class="eyebrow">CAM KẾT</p><h2 id="about-commitment-title">Tuân thủ là hành trình xây dựng sự tín nhiệm</h2></div>
                <blockquote><p><?php echo esc_html($commitment); ?></p></blockquote>
            </div>
        </section>

        <section class="cta about-cta"><div class="container cta-inner"><h2>Nhận tư vấn miễn phí từ chuyên gia bảo mật.</h2><a class="button dark" href="<?php echo esc_url(home_url('/lien-he/')); ?>">Liên hệ Cyber Services</a></div></section>
    </main>
    <?php
endwhile;

get_footer();
