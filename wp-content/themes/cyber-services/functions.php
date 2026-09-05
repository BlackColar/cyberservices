<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function cyber_services_setup(): void
{
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_post_type_support('page', 'excerpt');
    add_theme_support('responsive-embeds');
    add_theme_support('align-wide');
    add_theme_support('editor-styles');
    add_editor_style('editor-style.css');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('custom-logo', ['height' => 86, 'width' => 70, 'flex-height' => true, 'flex-width' => true]);
    register_nav_menus([
        'primary' => __('Điều hướng chính', 'cyber-services'),
        'footer' => __('Điều hướng chân trang', 'cyber-services'),
    ]);
}
add_action('after_setup_theme', 'cyber_services_setup');

function cyber_services_customize_register($wp_customize): void
{
    if (!is_object($wp_customize) || !method_exists($wp_customize, 'add_setting') || !class_exists('WP_Customize_Image_Control')) {
        return;
    }

    $wp_customize->add_setting('cyber_services_footer_logo', [
        'type' => 'theme_mod',
        'default' => '',
        'sanitize_callback' => 'esc_url_raw',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'cyber_services_footer_logo', [
        'label' => __('Logo chân trang', 'cyber-services'),
        'description' => __('Logo này độc lập với logo ở đầu trang.', 'cyber-services'),
        'section' => 'title_tagline',
        'settings' => 'cyber_services_footer_logo',
    ]));
}
add_action('customize_register', 'cyber_services_customize_register');

function cyber_services_customer_defaults(): array
{
    return [
        ['name' => 'FPT', 'slug' => 'fpt', 'file' => 'fpt.svg'],
        ['name' => 'MobiFone', 'slug' => 'mobifone', 'file' => 'mobifone.svg'],
        ['name' => 'Viettel', 'slug' => 'viettel', 'file' => 'viettel.svg'],
        ['name' => 'VNG', 'slug' => 'vng', 'file' => 'vng.svg'],
        ['name' => 'VinaPhone', 'slug' => 'vinaphone', 'file' => 'vinaphone.svg'],
        ['name' => 'Vingroup', 'slug' => 'vingroup', 'file' => 'vingroup.svg'],
        ['name' => 'Vietnam Airlines', 'slug' => 'vietnam-airlines', 'file' => 'vietnam-airlines.svg'],
    ];
}

function cyber_services_register_customer_post_type(): void
{
    register_post_type('cyber_customer', [
        'labels' => [
            'name' => __('Khách hàng', 'cyber-services'),
            'singular_name' => __('Khách hàng', 'cyber-services'),
            'add_new_item' => __('Thêm khách hàng', 'cyber-services'),
            'edit_item' => __('Sửa khách hàng', 'cyber-services'),
            'new_item' => __('Khách hàng mới', 'cyber-services'),
            'search_items' => __('Tìm khách hàng', 'cyber-services'),
            'not_found' => __('Chưa có khách hàng.', 'cyber-services'),
            'featured_image' => __('Logo khách hàng', 'cyber-services'),
            'set_featured_image' => __('Chọn hoặc thay logo', 'cyber-services'),
            'remove_featured_image' => __('Xóa logo đã chọn', 'cyber-services'),
            'use_featured_image' => __('Dùng làm logo khách hàng', 'cyber-services'),
        ],
        'public' => false,
        'publicly_queryable' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_nav_menus' => false,
        'show_in_rest' => true,
        'exclude_from_search' => true,
        'has_archive' => false,
        'rewrite' => false,
        'query_var' => false,
        'menu_icon' => 'dashicons-building',
        'menu_position' => 21,
        'supports' => ['title', 'thumbnail', 'page-attributes'],
    ]);
}
add_action('init', 'cyber_services_register_customer_post_type');

/**
 * Preserve the currently shipped logos as editable customer records.
 * The version flag prevents deleted customers from being recreated later.
 */
function cyber_services_seed_customers(): void
{
    if (wp_installing() || get_option('cyber_services_customer_seed_version') === '1') {
        return;
    }

    foreach (cyber_services_customer_defaults() as $order => $customer) {
        $existing = get_page_by_path($customer['slug'], OBJECT, 'cyber_customer');
        if ($existing instanceof WP_Post) {
            continue;
        }

        $customer_id = wp_insert_post([
            'post_type' => 'cyber_customer',
            'post_status' => 'publish',
            'post_title' => $customer['name'],
            'post_name' => $customer['slug'],
            'menu_order' => $order,
        ], true);
        if (is_wp_error($customer_id)) {
            return;
        }
        update_post_meta((int) $customer_id, '_cyber_services_bundled_logo', $customer['file']);
    }

    update_option('cyber_services_customer_seed_version', '1', false);
}
add_action('init', 'cyber_services_seed_customers', 20);

function cyber_services_customer_data(WP_Post $customer): array
{
    $name = get_the_title($customer);
    $thumbnail_id = get_post_thumbnail_id($customer);
    if ($thumbnail_id > 0) {
        $source = wp_get_attachment_image_src($thumbnail_id, 'medium');
        if (is_array($source)) {
            $alt = trim((string) get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true));
            return [
                'name' => $name,
                'src' => $source[0],
                'width' => $source[1],
                'height' => $source[2],
                'alt' => $alt !== '' ? $alt : sprintf(__('Logo %s', 'cyber-services'), $name),
            ];
        }
    }

    $bundled_logo = sanitize_file_name((string) get_post_meta($customer->ID, '_cyber_services_bundled_logo', true));
    return [
        'name' => $name,
        'src' => $bundled_logo !== '' ? cyber_services_asset('images/customer-logos/' . $bundled_logo) : '',
        'width' => 220,
        'height' => 80,
        'alt' => sprintf(__('Logo %s', 'cyber-services'), $name),
    ];
}

function cyber_services_customers(): array
{
    $customers = get_posts([
        'post_type' => 'cyber_customer',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);

    if (!$customers && get_option('cyber_services_customer_seed_version') !== '1') {
        return array_map(static function (array $customer): array {
            return [
                'name' => $customer['name'],
                'src' => cyber_services_asset('images/customer-logos/' . $customer['file']),
                'width' => 220,
                'height' => 80,
                'alt' => sprintf(__('Logo %s', 'cyber-services'), $customer['name']),
            ];
        }, cyber_services_customer_defaults());
    }

    return array_map('cyber_services_customer_data', $customers);
}

function cyber_services_customer_columns(array $columns): array
{
    return [
        'cb' => $columns['cb'] ?? '<input type="checkbox">',
        'cyber_customer_logo' => __('Logo', 'cyber-services'),
        'title' => $columns['title'] ?? __('Tên khách hàng', 'cyber-services'),
        'cyber_customer_order' => __('Thứ tự', 'cyber-services'),
        'date' => $columns['date'] ?? __('Ngày', 'cyber-services'),
    ];
}
add_filter('manage_cyber_customer_posts_columns', 'cyber_services_customer_columns');

function cyber_services_customer_column(string $column, int $post_id): void
{
    if ($column === 'cyber_customer_order') {
        echo esc_html((string) get_post_field('menu_order', $post_id));
        return;
    }
    if ($column !== 'cyber_customer_logo') {
        return;
    }

    $customer = get_post($post_id);
    if (!($customer instanceof WP_Post)) {
        return;
    }
    $logo = cyber_services_customer_data($customer);
    if ($logo['src'] !== '') {
        echo '<img src="' . esc_url($logo['src']) . '" alt="" style="width:88px;height:44px;object-fit:contain">';
    } else {
        echo '<span aria-hidden="true">—</span>';
    }
}
add_action('manage_cyber_customer_posts_custom_column', 'cyber_services_customer_column', 10, 2);

function cyber_services_customer_sortable_columns(array $columns): array
{
    $columns['cyber_customer_order'] = 'menu_order';
    return $columns;
}
add_filter('manage_edit-cyber_customer_sortable_columns', 'cyber_services_customer_sortable_columns');

function cyber_services_customer_default_order(WP_Query $query): void
{
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'cyber_customer' || $query->get('orderby')) {
        return;
    }
    $query->set('orderby', ['menu_order' => 'ASC', 'title' => 'ASC']);
}
add_action('pre_get_posts', 'cyber_services_customer_default_order');

function cyber_services_customer_logo_meta_box(): void
{
    remove_meta_box('postimagediv', 'cyber_customer', 'side');
    add_meta_box('cyber-customer-logo', __('Logo khách hàng', 'cyber-services'), 'cyber_services_customer_logo_field', 'cyber_customer', 'side', 'high');
}
add_action('add_meta_boxes_cyber_customer', 'cyber_services_customer_logo_meta_box');

function cyber_services_customer_logo_field(WP_Post $customer): void
{
    $logo = cyber_services_customer_data($customer);
    wp_nonce_field('cyber_services_customer_logo', 'cyber_services_customer_logo_nonce');
    ?>
    <div data-cyber-customer-logo>
        <div data-logo-preview style="min-height:72px;display:grid;place-items:center;margin-bottom:12px;border:1px solid #dcdcde;background:#fff"><?php if ($logo['src'] !== '') : ?><img src="<?php echo esc_url($logo['src']); ?>" alt="" style="max-width:100%;max-height:68px"><?php endif; ?></div>
        <input type="hidden" name="cyber_customer_logo_id" value="<?php echo esc_attr((string) get_post_thumbnail_id($customer)); ?>" data-logo-id>
        <input type="hidden" name="cyber_customer_logo_remove" value="0" data-logo-remove>
        <p><button type="button" class="button button-primary" data-logo-select><?php esc_html_e('Chọn / thay logo', 'cyber-services'); ?></button></p>
        <p><button type="button" class="button-link-delete" data-logo-delete><?php esc_html_e('Xóa logo', 'cyber-services'); ?></button></p>
        <p class="description"><?php esc_html_e('Chọn ảnh từ Media Library. PNG, JPG hoặc WebP được khuyến nghị.', 'cyber-services'); ?></p>
    </div>
    <?php
}

function cyber_services_save_customer_logo(int $post_id): void
{
    if (!isset($_POST['cyber_services_customer_logo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cyber_services_customer_logo_nonce'])), 'cyber_services_customer_logo') || !current_user_can('edit_post', $post_id) || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    $logo_id = isset($_POST['cyber_customer_logo_id']) ? absint($_POST['cyber_customer_logo_id']) : 0;
    $remove_logo = isset($_POST['cyber_customer_logo_remove']) && $_POST['cyber_customer_logo_remove'] === '1';
    if ($remove_logo) {
        delete_post_thumbnail($post_id);
        delete_post_meta($post_id, '_cyber_services_bundled_logo');
    } elseif ($logo_id > 0) {
        set_post_thumbnail($post_id, $logo_id);
        delete_post_meta($post_id, '_cyber_services_bundled_logo');
    }
}
add_action('save_post_cyber_customer', 'cyber_services_save_customer_logo');

function cyber_services_customer_admin_assets(string $hook): void
{
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'cyber_customer') {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script('cyber-services-customer-admin', get_theme_file_uri('assets/js/customer-admin.js'), ['jquery'], cyber_services_asset_version('assets/js/customer-admin.js'), true);
    wp_localize_script('cyber-services-customer-admin', 'cyberCustomerAdmin', [
        'title' => __('Chọn logo khách hàng', 'cyber-services'),
        'button' => __('Dùng ảnh này', 'cyber-services'),
    ]);
}
add_action('admin_enqueue_scripts', 'cyber_services_customer_admin_assets');

function cyber_services_certification_defaults(): array
{
    return [
        [
            'label' => 'ISO CERTIFICATIONS',
            'slug' => 'iso-certifications',
            'standards' => 'ISO 27001 · 27701 · 22301',
            'description' => 'Các tiêu chuẩn quản lý an toàn thông tin và bảo vệ dữ liệu quốc tế.',
            'image' => 'https://cyberservices.vn/wp-content/uploads/2026/07/iso-27001.png',
            'alt' => 'Bộ tiêu chuẩn chứng nhận ISO 27001, ISO 27701 và ISO 22301',
        ],
        [
            'label' => 'ACCREDITED BY UKAS & ANAB',
            'slug' => 'accredited-by-ukas-anab',
            'standards' => 'UKAS · ANAB · BOA · UAF',
            'description' => 'Kết nối với các tổ chức đánh giá được công nhận toàn cầu.',
            'image' => 'https://cyberservices.vn/wp-content/uploads/2026/07/iso-27017.png',
            'alt' => 'Các tổ chức công nhận quốc tế UKAS, ANAB, BOA và UAF',
        ],
        [
            'label' => 'FEDERAL ASSESSMENTS',
            'slug' => 'federal-assessments',
            'standards' => 'FedRAMP · GovRAMP · CMMC',
            'description' => 'Chuẩn bị hồ sơ và năng lực đáp ứng yêu cầu của cơ quan chính phủ Hoa Kỳ.',
            'image' => 'https://cyberservices.vn/wp-content/uploads/2026/08/Dich-Vu-Tu-Van-Chung-Nhan-CMMC-Uy-Tin-Chuyen-Nghiep-Anh-minh-hoa-2-1786410028.jpg',
            'alt' => 'Chương trình đánh giá liên bang Hoa Kỳ: FedRAMP, GovRAMP và CMMC',
        ],
        [
            'label' => 'CYBERSECURITY & DATA',
            'slug' => 'cybersecurity-data',
            'standards' => 'AICPA · HITRUST · CCPA/CPRA',
            'description' => 'Đánh giá bảo mật, quyền riêng tư và quản trị dữ liệu cho doanh nghiệp.',
            'image' => 'https://cyberservices.vn/wp-content/uploads/2026/08/Chung-Nhan-HITRUST-1786418348.webp',
            'alt' => 'Chứng nhận bảo mật và quản trị dữ liệu: AICPA, HITRUST, CCPA/CPRA',
        ],
        [
            'label' => 'HEALTHCARE ASSESSMENTS',
            'slug' => 'healthcare-assessments',
            'standards' => 'HITRUST · HIPAA',
            'description' => 'Bảo vệ dữ liệu y tế và duy trì khả năng tuân thủ trong vận hành.',
            'image' => 'https://cyberservices.vn/wp-content/uploads/2026/08/Healthcare-Assessments-1786425008.webp',
            'alt' => 'Đánh giá tuân thủ an toàn dữ liệu y tế theo HITRUST và HIPAA',
        ],
    ];
}

function cyber_services_register_certification_post_type(): void
{
    if (post_type_exists('cyber_certification')) {
        return;
    }

    register_post_type('cyber_certification', [
        'labels' => [
            'name' => __('Chứng nhận', 'cyber-services'),
            'singular_name' => __('Chứng nhận', 'cyber-services'),
            'add_new_item' => __('Thêm chứng nhận', 'cyber-services'),
            'edit_item' => __('Sửa chứng nhận', 'cyber-services'),
            'new_item' => __('Chứng nhận mới', 'cyber-services'),
            'search_items' => __('Tìm chứng nhận', 'cyber-services'),
            'not_found' => __('Chưa có chứng nhận.', 'cyber-services'),
            'featured_image' => __('Ảnh chứng nhận', 'cyber-services'),
            'set_featured_image' => __('Chọn hoặc thay ảnh chứng nhận', 'cyber-services'),
            'remove_featured_image' => __('Xóa ảnh chứng nhận đã chọn', 'cyber-services'),
            'use_featured_image' => __('Dùng làm ảnh chứng nhận', 'cyber-services'),
        ],
        'public' => false,
        'publicly_queryable' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_nav_menus' => false,
        'show_in_rest' => true,
        'exclude_from_search' => true,
        'has_archive' => false,
        'rewrite' => false,
        'query_var' => false,
        'menu_icon' => 'dashicons-awards',
        'menu_position' => 22,
        'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'page-attributes'],
    ]);
}
add_action('init', 'cyber_services_register_certification_post_type');

function cyber_services_certification_meta_box(): void
{
    add_meta_box(
        'cyber-certification-link',
        __('Liên kết chứng nhận', 'cyber-services'),
        'cyber_services_certification_link_field',
        'cyber_certification',
        'side',
        'high'
    );
}
add_action('add_meta_boxes_cyber_certification', 'cyber_services_certification_meta_box');

function cyber_services_certification_link_field(WP_Post $certification): void
{
    wp_nonce_field('cyber_services_certification_link', 'cyber_services_certification_link_nonce');
    $url = (string) get_post_meta($certification->ID, '_cyber_services_certification_url', true);
    ?>
    <p><label for="cyber_services_certification_url"><?php esc_html_e('URL khi người dùng chọn slide', 'cyber-services'); ?></label></p>
    <input class="widefat" type="url" id="cyber_services_certification_url" name="cyber_services_certification_url" value="<?php echo esc_attr($url); ?>" placeholder="https://">
    <p class="description"><?php esc_html_e('Để trống nếu slide không có liên kết.', 'cyber-services'); ?></p>
    <?php
}

function cyber_services_save_certification_link(int $post_id): void
{
    if (!isset($_POST['cyber_services_certification_link_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cyber_services_certification_link_nonce'])), 'cyber_services_certification_link') || !current_user_can('edit_post', $post_id) || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    $url = isset($_POST['cyber_services_certification_url']) && is_string($_POST['cyber_services_certification_url']) ? esc_url_raw(wp_unslash($_POST['cyber_services_certification_url'])) : '';
    if ($url === '') {
        delete_post_meta($post_id, '_cyber_services_certification_url');
        return;
    }
    update_post_meta($post_id, '_cyber_services_certification_url', $url);
}
add_action('save_post_cyber_certification', 'cyber_services_save_certification_link');

/**
 * Migrate the shipped certification gallery into editable records once.
 * Existing records are never overwritten, so later admin edits persist.
 */
function cyber_services_seed_certifications(): void
{
    if (wp_installing() || get_option('cyber_services_certification_seed_version') === '1') {
        return;
    }

    $existing_posts = get_posts([
        'post_type' => 'cyber_certification',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'no_found_rows' => true,
    ]);
    $existing_slugs = [];
    $existing_titles = [];
    foreach ($existing_posts as $existing_post) {
        if (!($existing_post instanceof WP_Post)) {
            continue;
        }
        $existing_slugs[$existing_post->post_name] = true;
        $existing_titles[$existing_post->post_title] = true;
    }

    foreach (cyber_services_certification_defaults() as $order => $certification) {
        if (isset($existing_slugs[$certification['slug']]) || isset($existing_titles[$certification['label']])) {
            continue;
        }

        $certification_id = wp_insert_post([
            'post_type' => 'cyber_certification',
            'post_status' => 'publish',
            'post_title' => $certification['label'],
            'post_name' => $certification['slug'],
            'post_excerpt' => $certification['standards'],
            'post_content' => $certification['description'],
            'menu_order' => $order,
        ], true);
        if (is_wp_error($certification_id)) {
            return;
        }
        $certification_id = (int) $certification_id;
        update_post_meta($certification_id, '_cyber_services_certification_image_url', $certification['image']);
        update_post_meta($certification_id, '_cyber_services_external_image', esc_url_raw($certification['image']));
        update_post_meta($certification_id, '_cyber_services_certification_alt', $certification['alt']);

        $attachment_id = function_exists('attachment_url_to_postid') ? attachment_url_to_postid($certification['image']) : 0;
        if ($attachment_id > 0 && wp_attachment_is_image($attachment_id)) {
            set_post_thumbnail($certification_id, $attachment_id);
        }

        $existing_slugs[$certification['slug']] = true;
        $existing_titles[$certification['label']] = true;
    }

    update_option('cyber_services_certification_seed_version', '1', false);
}
add_action('init', 'cyber_services_seed_certifications', 20);

/**
 * Resolve shipped external certification images into featured attachments once.
 * Frontend rendering then reads the stored thumbnail ID without URL lookups.
 */
function cyber_services_migrate_certification_images(): void
{
    if (get_option('cyber_services_certification_image_migration') === '1') {
        return;
    }

    $certifications = get_posts([
        'post_type' => 'cyber_certification',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);

    foreach ($certifications as $certification) {
        if (get_post_thumbnail_id($certification) > 0) {
            continue;
        }

        $external_url = trim((string) get_post_meta($certification->ID, '_cyber_services_external_image', true));
        if ($external_url === '') {
            continue;
        }

        $attachment_id = attachment_url_to_postid($external_url);
        if ($attachment_id > 0) {
            set_post_thumbnail($certification->ID, $attachment_id);
        }
    }

    update_option('cyber_services_certification_image_migration', '1', false);
}
add_action('init', 'cyber_services_migrate_certification_images', 21);

/**
 * Refresh certification alt text from the shipped defaults, once per version.
 * Earlier seeds stored generic "Bối cảnh ..." captions that did not describe
 * the accreditation bodies rendered in the "Tổ chức công nhận" section.
 * Only untouched rows are rewritten, so manual edits in the admin survive.
 */
function cyber_services_refresh_certification_alt_text(): void
{
    $version = '2';
    if (get_option('cyber_services_certification_alt_revision') === $version) {
        return;
    }

    $certifications = get_posts([
        'post_type' => 'cyber_certification',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);

    foreach ($certifications as $certification) {
        if (!($certification instanceof WP_Post)) {
            continue;
        }

        $default = cyber_services_certification_default($certification);
        if ($default === []) {
            continue;
        }

        $stored_alt = trim((string) get_post_meta($certification->ID, '_cyber_services_certification_alt', true));
        if ($stored_alt !== '' && $stored_alt !== ($default['alt'] ?? '')) {
            continue; // Giữ nguyên alt do quản trị viên tự sửa.
        }

        update_post_meta($certification->ID, '_cyber_services_certification_alt', $default['alt']);

        $thumbnail_id = get_post_thumbnail_id($certification);
        if ($thumbnail_id > 0 && trim((string) get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true)) === '') {
            update_post_meta($thumbnail_id, '_wp_attachment_image_alt', $default['alt']);
        }
    }

    update_option('cyber_services_certification_alt_revision', $version, false);
}
add_action('init', 'cyber_services_refresh_certification_alt_text', 22);

function cyber_services_certification_default(WP_Post $certification): array
{
    foreach (cyber_services_certification_defaults() as $default) {
        if ($certification->post_name === $default['slug'] || $certification->post_title === $default['label']) {
            return $default;
        }
    }

    return [];
}

function cyber_services_certification_data(WP_Post $certification): array
{
    $default = cyber_services_certification_default($certification);
    $label = trim((string) get_the_title($certification));
    if ($label === '' && $default) {
        $label = $default['label'];
    }

    $thumbnail_id = get_post_thumbnail_id($certification);
    $image = '';
    $image_alt = '';
    $fallback_alt = trim((string) get_post_meta($certification->ID, '_cyber_services_certification_alt', true));
    if ($thumbnail_id > 0) {
        $image = (string) (wp_get_attachment_image_url($thumbnail_id, 'large') ?: '');
        $image_alt = $fallback_alt !== ''
            ? $fallback_alt
            : trim((string) get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true));
    }

    $fallback_image = trim((string) get_post_meta($certification->ID, '_cyber_services_certification_image_url', true));
    if ($fallback_image === '') {
        $fallback_image = trim((string) get_post_meta($certification->ID, '_cyber_services_external_image', true));
    }
    if ($image === '') {
        $image = $fallback_image;
    }

    if ($image_alt === '') {
        $image_alt = $fallback_alt !== '' ? $fallback_alt : ($default['alt'] ?? '');
    }

    return [
        'label' => $label,
        'standards' => trim(wp_strip_all_tags((string) $certification->post_excerpt)),
        'description' => trim(wp_strip_all_tags((string) $certification->post_content)),
        'image' => $image,
        'src' => esc_url_raw($image),
        'image_id' => $thumbnail_id,
        'thumbnail_id' => $thumbnail_id,
        'width' => 1200,
        'height' => 675,
        'alt' => $image_alt !== '' ? $image_alt : $label,
        'url' => (string) get_post_meta($certification->ID, '_cyber_services_certification_url', true),
    ];
}

function cyber_services_certifications(): array
{
    $certifications = get_posts([
        'post_type' => 'cyber_certification',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);

    if (!$certifications && get_option('cyber_services_certification_seed_version') !== '1') {
        return array_map(static function (array $certification): array {
            return [
                'label' => $certification['label'],
                'standards' => $certification['standards'],
                'description' => $certification['description'],
                'image_id' => 0,
                'src' => esc_url_raw($certification['image']),
                'image' => esc_url_raw($certification['image']),
                'thumbnail_id' => 0,
                'width' => 1200,
                'height' => 675,
                'alt' => $certification['alt'],
                'url' => '',
            ];
        }, cyber_services_certification_defaults());
    }

    return array_map('cyber_services_certification_data', $certifications);
}

function cyber_services_certification_columns(array $columns): array
{
    return [
        'cb' => $columns['cb'] ?? '<input type="checkbox">',
        'cyber_certification_image' => __('Ảnh', 'cyber-services'),
        'title' => $columns['title'] ?? __('Nhãn chứng nhận', 'cyber-services'),
        'cyber_certification_standards' => __('Tiêu chuẩn', 'cyber-services'),
        'cyber_certification_order' => __('Thứ tự', 'cyber-services'),
        'date' => $columns['date'] ?? __('Ngày', 'cyber-services'),
    ];
}
add_filter('manage_cyber_certification_posts_columns', 'cyber_services_certification_columns');

function cyber_services_certification_column(string $column, int $post_id): void
{
    if ($column === 'cyber_certification_standards') {
        echo esc_html((string) get_post_field('post_excerpt', $post_id));
        return;
    }
    if ($column === 'cyber_certification_order') {
        echo esc_html((string) get_post_field('menu_order', $post_id));
        return;
    }
    if ($column !== 'cyber_certification_image') {
        return;
    }

    $certification = get_post($post_id);
    if (!($certification instanceof WP_Post)) {
        return;
    }
    $data = cyber_services_certification_data($certification);
    if ($data['image'] !== '') {
        echo '<img src="' . esc_url($data['image']) . '" alt="" style="width:88px;height:56px;object-fit:cover">';
    } else {
        echo '<span aria-hidden="true">—</span>';
    }
}
add_action('manage_cyber_certification_posts_custom_column', 'cyber_services_certification_column', 10, 2);

function cyber_services_certification_sortable_columns(array $columns): array
{
    $columns['cyber_certification_order'] = 'menu_order';
    return $columns;
}
add_filter('manage_edit-cyber_certification_sortable_columns', 'cyber_services_certification_sortable_columns');

function cyber_services_certification_default_order(WP_Query $query): void
{
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'cyber_certification' || $query->get('orderby')) {
        return;
    }
    $query->set('orderby', ['menu_order' => 'ASC', 'title' => 'ASC']);
}
add_action('pre_get_posts', 'cyber_services_certification_default_order');

function cyber_services_certification_meta_boxes(): void
{
    if (!post_type_supports('cyber_certification', 'editor') || !post_type_supports('cyber_certification', 'excerpt')) {
        add_meta_box('cyber-certification-details', __('Thông tin chứng nhận', 'cyber-services'), 'cyber_services_certification_details_field', 'cyber_certification', 'normal', 'high');
    }
    remove_meta_box('postimagediv', 'cyber_certification', 'side');
    add_meta_box('cyber-certification-image', __('Ảnh chứng nhận', 'cyber-services'), 'cyber_services_certification_image_field', 'cyber_certification', 'side', 'high');
}
add_action('add_meta_boxes_cyber_certification', 'cyber_services_certification_meta_boxes');

function cyber_services_certification_details_field(WP_Post $certification): void
{
    $data = cyber_services_certification_data($certification);
    wp_nonce_field('cyber_services_certification_details', 'cyber_services_certification_details_nonce');
    ?>
    <p>
        <label for="cyber-certification-standards"><strong><?php esc_html_e('Tiêu chuẩn', 'cyber-services'); ?></strong></label>
        <textarea id="cyber-certification-standards" name="cyber_certification_standards" rows="3" class="large-text"><?php echo esc_textarea($data['standards']); ?></textarea>
    </p>
    <p>
        <label for="cyber-certification-description"><strong><?php esc_html_e('Mô tả', 'cyber-services'); ?></strong></label>
        <textarea id="cyber-certification-description" name="cyber_certification_description" rows="5" class="large-text"><?php echo esc_textarea($data['description']); ?></textarea>
    </p>
    <p>
        <label for="cyber-certification-alt"><strong><?php esc_html_e('Alt text ảnh', 'cyber-services'); ?></strong></label>
        <input id="cyber-certification-alt" name="cyber_certification_alt" type="text" class="large-text" value="<?php echo esc_attr($data['alt']); ?>">
    </p>
    <?php
}

function cyber_services_certification_image_field(WP_Post $certification): void
{
    $data = cyber_services_certification_data($certification);
    $preview = $data['image'];
    $thumbnail_id = (int) $data['thumbnail_id'];
    if ($thumbnail_id > 0) {
        $medium = wp_get_attachment_image_url($thumbnail_id, 'medium');
        if (is_string($medium) && $medium !== '') {
            $preview = $medium;
        }
    }
    wp_nonce_field('cyber_services_certification_image', 'cyber_services_certification_image_nonce');
    ?>
    <div data-cyber-certification-image>
        <div data-image-preview style="min-height:120px;display:grid;place-items:center;margin-bottom:12px;border:1px solid #dcdcde;background:#fff;padding:8px"><?php if ($preview !== '') : ?><img src="<?php echo esc_url($preview); ?>" alt="" style="max-width:100%;max-height:160px;object-fit:contain"><?php endif; ?></div>
        <input type="hidden" name="cyber_certification_image_id" value="<?php echo esc_attr((string) $thumbnail_id); ?>" data-image-id>
        <input type="hidden" name="cyber_certification_image_remove" value="0" data-image-remove>
        <p><button type="button" class="button button-primary" data-image-select><?php esc_html_e('Chọn / thay ảnh', 'cyber-services'); ?></button></p>
        <p><button type="button" class="button-link-delete" data-image-delete><?php esc_html_e('Xóa ảnh đã chọn', 'cyber-services'); ?></button></p>
        <p class="description"><?php esc_html_e('Chọn ảnh từ Media Library. Ảnh đã chọn sẽ thay thế ảnh dự phòng hiện tại.', 'cyber-services'); ?></p>
    </div>
    <?php
}

function cyber_services_save_certification(int $post_id): void
{
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) {
        return;
    }

    $certification = get_post($post_id);
    if (!($certification instanceof WP_Post) || $certification->post_type !== 'cyber_certification') {
        return;
    }

    $details_nonce_valid = isset($_POST['cyber_services_certification_details_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cyber_services_certification_details_nonce'])), 'cyber_services_certification_details');
    $image_nonce_valid = isset($_POST['cyber_services_certification_image_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cyber_services_certification_image_nonce'])), 'cyber_services_certification_image');
    if (!$details_nonce_valid && !$image_nonce_valid) {
        return;
    }

    if ($details_nonce_valid) {
        $post_update = ['ID' => $post_id];
        if (isset($_POST['cyber_certification_standards'])) {
            $post_update['post_excerpt'] = wp_slash(sanitize_textarea_field(wp_unslash($_POST['cyber_certification_standards'])));
        }
        if (isset($_POST['cyber_certification_description'])) {
            $post_update['post_content'] = wp_slash(sanitize_textarea_field(wp_unslash($_POST['cyber_certification_description'])));
        }

        static $updating = false;
        if (count($post_update) > 1 && !$updating) {
            $updating = true;
            wp_update_post($post_update);
            $updating = false;
        }
        if (isset($_POST['cyber_certification_alt'])) {
            update_post_meta($post_id, '_cyber_services_certification_alt', sanitize_text_field(wp_unslash($_POST['cyber_certification_alt'])));
        }
    }

    if ($image_nonce_valid) {
        $image_id = isset($_POST['cyber_certification_image_id']) ? absint($_POST['cyber_certification_image_id']) : 0;
        $remove_image = isset($_POST['cyber_certification_image_remove']) && (string) wp_unslash($_POST['cyber_certification_image_remove']) === '1';
        if ($remove_image) {
            delete_post_thumbnail($post_id);
            delete_post_meta($post_id, '_cyber_services_certification_image_url');
            delete_post_meta($post_id, '_cyber_services_external_image');
        } elseif ($image_id > 0 && wp_attachment_is_image($image_id)) {
            set_post_thumbnail($post_id, $image_id);
            delete_post_meta($post_id, '_cyber_services_certification_image_url');
            delete_post_meta($post_id, '_cyber_services_external_image');
        }
    }
}
add_action('save_post_cyber_certification', 'cyber_services_save_certification');

function cyber_services_certification_admin_assets(string $hook): void
{
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'cyber_certification') {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script('cyber-services-certification-admin', get_theme_file_uri('assets/js/certification-admin.js'), ['jquery'], cyber_services_asset_version('assets/js/certification-admin.js'), true);
    wp_localize_script('cyber-services-certification-admin', 'cyberCertificationAdmin', [
        'title' => __('Chọn ảnh chứng nhận', 'cyber-services'),
        'button' => __('Dùng ảnh này', 'cyber-services'),
    ]);
}
add_action('admin_enqueue_scripts', 'cyber_services_certification_admin_assets');

function cyber_services_asset_version(string $relative_path): string
{
    $path = get_theme_file_path($relative_path);
    return is_file($path) ? (string) filemtime($path) : (string) wp_get_theme()->get('Version');
}

function cyber_services_classic_editor_buttons(array $buttons): array
{
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, ['post', 'page'], true) || in_array('alignjustify', $buttons, true)) {
        return $buttons;
    }

    $align_right = array_search('alignright', $buttons, true);
    if ($align_right === false) {
        $buttons[] = 'alignjustify';
    } else {
        array_splice($buttons, $align_right + 1, 0, 'alignjustify');
    }

    return $buttons;
}
add_filter('mce_buttons', 'cyber_services_classic_editor_buttons');

function cyber_services_block_editor_assets(): void
{
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, ['post', 'page'], true)) {
        return;
    }

    wp_enqueue_script(
        'cyber-services-editor-justify',
        get_theme_file_uri('assets/js/editor-justify.js'),
        ['wp-block-editor', 'wp-components', 'wp-compose', 'wp-element', 'wp-hooks'],
        cyber_services_asset_version('assets/js/editor-justify.js'),
        true
    );
}
add_action('enqueue_block_editor_assets', 'cyber_services_block_editor_assets');

function cyber_services_assets(): void
{
    if (!is_front_page()) {
        wp_enqueue_style('cyber-services', get_stylesheet_uri(), [], cyber_services_asset_version('style.css'));
        wp_enqueue_style('cyber-services-menu', get_theme_file_uri('assets/css/menu.css'), ['cyber-services'], cyber_services_asset_version('assets/css/menu.css'));
        wp_enqueue_script('cyber-services', get_theme_file_uri('assets/js/cyber-services.js'), [], cyber_services_asset_version('assets/js/cyber-services.js'), true);
    } else {
        wp_enqueue_style('cyber-services-base', get_theme_file_uri('assets/css/base.css'), [], cyber_services_asset_version('assets/css/base.css'));
        wp_enqueue_style('cyber-services-page', get_theme_file_uri('assets/css/front-page.css'), ['cyber-services-base'], cyber_services_asset_version('assets/css/front-page.css'));
        wp_enqueue_style('cyber-services-menu', get_theme_file_uri('assets/css/menu.css'), ['cyber-services-page'], cyber_services_asset_version('assets/css/menu.css'));

        wp_enqueue_script('cyber-services', get_theme_file_uri('assets/js/cyber-services.js'), [], cyber_services_asset_version('assets/js/cyber-services.js'), true);
    }

    if (is_front_page() || is_page('lien-he')) {
        $contact_dependency = is_front_page() ? 'cyber-services-page' : 'cyber-services';
        wp_enqueue_style('cyber-services-contact', get_theme_file_uri('assets/css/contact.css'), [$contact_dependency], cyber_services_asset_version('assets/css/contact.css'));
    }

    wp_localize_script('cyber-services', 'cyberServicesTheme', [
        'contactActionUrl' => admin_url('admin-post.php'),
        'contactNonceUrl' => admin_url('admin-ajax.php?action=cyber_contact_nonce'),
        'formMessages' => [
            'loading' => __('Đang gửi…', 'cyber-services'),
            'success' => __('Cảm ơn bạn. Yêu cầu tư vấn đã được gửi thành công.', 'cyber-services'),
            'error' => __('Không thể gửi yêu cầu lúc này. Vui lòng thử lại hoặc liên hệ qua điện thoại.', 'cyber-services'),
        ],
    ]);
}
add_action('wp_enqueue_scripts', 'cyber_services_assets');

function cyber_services_ensure_page(string $path, string $slug, string $title): int
{
    $page = get_page_by_path($path, OBJECT, 'page');
    if ($page instanceof WP_Post) {
        return (int) $page->ID;
    }

    $page_id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => '',
    ], true);
    return is_wp_error($page_id) ? 0 : (int) $page_id;
}

function cyber_services_policy_pages(): array
{
    return [
        [
            'label' => 'Chính sách quyền riêng tư',
            'title' => 'Chính sách quyền riêng tư & Bảo vệ dữ liệu',
            'slug' => 'chinh-sach-quyen-rieng-tu',
            'path' => 'chinh-sach-quyen-rieng-tu',
            'excerpt' => 'Cam kết bảo mật dữ liệu khách hàng và minh bạch trong các dự án tư vấn tuân thủ tiêu chuẩn an ninh thông tin.',
            'content_file' => 'content/policies/chinh-sach-quyen-rieng-tu.html',
        ],
        [
            'label' => 'Chính sách sử dụng được chấp nhận',
            'title' => 'Chính sách sử dụng được chấp nhận (AUP)',
            'slug' => 'chinh-sach-su-dung-duoc-chap-nhan',
            'path' => 'chinh-sach-su-dung-duoc-chap-nhan',
            'excerpt' => 'Các nguyên tắc bắt buộc nhằm bảo đảm an toàn và toàn vẹn dữ liệu khi truy cập nền tảng dịch vụ của Cyber Services Việt Nam.',
            'content_file' => 'content/policies/chinh-sach-su-dung-duoc-chap-nhan.html',
        ],
        [
            'label' => 'Tính khách quan',
            'title' => 'Cam kết tính khách quan & Tiếp nhận khiếu nại độc lập',
            'slug' => 'tinh-khach-quan',
            'path' => 'tinh-khach-quan',
            'excerpt' => 'Tính khách quan, liêm chính và độc lập là nền tảng trong mọi hoạt động kiểm toán, đánh giá và cấp chứng nhận.',
            'content_file' => 'content/policies/tinh-khach-quan.html',
        ],
    ];
}

function cyber_services_policy_links(): array
{
    $links = [];
    foreach (cyber_services_policy_pages() as $policy) {
        $page = get_page_by_path($policy['path'], OBJECT, 'page');
        $url = $page instanceof WP_Post ? get_permalink($page) : home_url('/' . $policy['slug'] . '/');
        $links[] = [
            'label' => $policy['label'],
            'url' => (string) $url,
        ];
    }
    return $links;
}

function cyber_services_configure_policy_pages(): void
{
    if (get_option('cyber_services_policy_pages_version') === '1' || wp_installing()) {
        return;
    }

    $all_pages_created = true;
    foreach (cyber_services_policy_pages() as $policy) {
        if (get_page_by_path($policy['path'], OBJECT, 'page') instanceof WP_Post) {
            continue;
        }

        $content_path = get_theme_file_path($policy['content_file']);
        $content = is_readable($content_path) ? file_get_contents($content_path) : false;
        $page_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => $policy['title'],
            'post_name' => $policy['slug'],
            'post_excerpt' => $policy['excerpt'],
            'post_content' => is_string($content) ? $content : '',
        ], true);
        if (is_wp_error($page_id)) {
            $all_pages_created = false;
        }
    }

    if ($all_pages_created) {
        update_option('cyber_services_policy_pages_version', '1', false);
    }
}
add_action('init', 'cyber_services_configure_policy_pages', 21);

/**
 * Idempotently configure the public landing page, Blog archive and pretty URLs.
 * Existing content is preserved; missing structural pages are added only once.
 */
function cyber_services_configure_public_routes(): void
{
    if (get_option('cyber_services_public_routes_version') === '1' || wp_installing()) {
        return;
    }

    $home_id = cyber_services_ensure_page('trang-chu', 'trang-chu', 'Trang chủ');
    $blog_id = cyber_services_ensure_page('blog', 'blog', 'Blog');
    if ($home_id < 1 || $blog_id < 1) {
        return;
    }

    update_option('show_on_front', 'page');
    update_option('page_on_front', $home_id);
    update_option('page_for_posts', $blog_id);
    update_option('permalink_structure', '/%postname%/');
    flush_rewrite_rules(false);
    update_option('cyber_services_public_routes_version', '1', false);
}
add_action('init', 'cyber_services_configure_public_routes', 20);

function cyber_services_navigation_blueprint(): array
{
    return [
        ['label' => 'Trang chủ', 'title' => 'Trang chủ', 'slug' => 'trang-chu', 'path' => 'trang-chu', 'children' => []],
        ['label' => 'Giới thiệu', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'path' => 'gioi-thieu', 'children' => []],
        ['label' => 'Dịch vụ', 'title' => 'Dịch vụ', 'slug' => 'dich-vu', 'path' => 'dich-vu', 'children' => [
            ['label' => 'PCI', 'title' => 'PCI Compliance', 'slug' => 'pci-compliance', 'path' => 'pci-compliance', 'children' => [
                ['label' => 'PCI DSS', 'title' => 'PCI DSS', 'slug' => 'pci-dss', 'path' => 'pci-compliance/pci-dss', 'children' => []],
                ['label' => 'PCI PIN', 'title' => 'PCI PIN', 'slug' => 'pci-pin', 'path' => 'pci-compliance/pci-pin', 'children' => []],
                ['label' => 'PCI 3DS', 'title' => 'PCI 3DS', 'slug' => 'pci-3ds', 'path' => 'pci-3ds', 'children' => []],
                ['label' => 'PCI SSF', 'title' => 'PCI SSF', 'slug' => 'pci-ssf', 'path' => 'pci-ssf', 'children' => []],
                ['label' => 'PCI Card Production', 'title' => 'PCI Card Production', 'slug' => 'pci-card-production', 'path' => 'pci-card-production', 'children' => []],
            ]],
            ['label' => 'SOC Report', 'title' => 'SOC Report', 'slug' => 'soc-report', 'path' => 'soc-report', 'children' => [
                ['label' => 'SOC 1', 'title' => 'SOC 1', 'slug' => 'soc-1', 'path' => 'soc-1', 'children' => []],
                ['label' => 'SOC 2 Type I', 'title' => 'SOC 2 Type I', 'slug' => 'soc-2-type-i', 'path' => 'soc-2-type-i', 'children' => []],
                ['label' => 'SOC 2 Type II', 'title' => 'SOC 2 Type II', 'slug' => 'soc-2-type-ii', 'path' => 'soc-2-type-ii', 'children' => []],
            ]],
            ['label' => 'ISO Standard', 'title' => 'ISO Standard', 'slug' => 'iso-standard', 'path' => 'iso-standard', 'children' => [
                ['label' => 'ISO 27001', 'title' => 'ISO 27001', 'slug' => 'iso-27001', 'path' => 'iso-27001', 'children' => []],
                ['label' => 'ISO 27018', 'title' => 'ISO 27018', 'slug' => 'iso-27018', 'path' => 'iso-27018', 'children' => []],
                ['label' => 'ISO 27017', 'title' => 'ISO 27017', 'slug' => 'iso-27017', 'path' => 'iso-27017', 'children' => []],
                ['label' => 'ISO 42001', 'title' => 'ISO 42001', 'slug' => 'iso-42001', 'path' => 'iso-42001', 'children' => []],
            ]],
            ['label' => 'Dịch vụ khác', 'title' => 'Dịch vụ khác', 'slug' => 'dich-vu-khac', 'path' => 'dich-vu-khac', 'children' => [
                ['label' => 'Dịch vụ Email Phishing', 'title' => 'Dịch vụ Email Phishing', 'slug' => 'dich-vu-email-phishing', 'path' => 'dich-vu-email-phishing', 'children' => []],
                ['label' => 'Dịch vụ Pentest', 'title' => 'Dịch vụ Pentest', 'slug' => 'dich-vu-pentest', 'path' => 'dich-vu-pentest', 'children' => []],
                ['label' => 'Dịch vụ kiểm thử phần mềm', 'title' => 'Dịch vụ kiểm thử phần mềm', 'slug' => 'dich-vu-kiem-thu-phan-mem', 'path' => 'dich-vu-kiem-thu-phan-mem', 'children' => []],
            ]],
        ]],
        ['label' => 'Sản phẩm', 'title' => 'Sản phẩm', 'slug' => 'san-pham', 'path' => 'san-pham', 'children' => [
            ['label' => 'ASV Scan', 'title' => 'ASV Scan', 'slug' => 'asv-scan', 'path' => 'asv-scan', 'children' => []],
            ['label' => 'OT Scanner', 'title' => 'OT Scanner', 'slug' => 'ot-scanner', 'path' => 'ot-scanner', 'children' => []],
        ]],
        ['label' => 'Resource', 'title' => 'Resource', 'slug' => 'resource', 'path' => 'resource', 'children' => [
            ['label' => 'Tin tức (News)', 'title' => 'Blog', 'slug' => 'blog', 'path' => 'blog', 'children' => []],
            ['label' => 'Case Study', 'title' => 'Case study', 'slug' => 'case-study', 'path' => 'case-study', 'children' => []],
        ]],
        ['label' => 'Liên hệ', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'path' => 'lien-he', 'children' => []],
    ];
}

function cyber_services_disable_menu_auto_add(): void
{
    $options = get_option('nav_menu_options', []);
    if (!is_array($options)) {
        $options = [];
    }
    $options['auto_add'] = [];
    update_option('nav_menu_options', $options);
}

function cyber_services_create_legacy_menu_backup(): bool
{
    $menu = wp_get_nav_menu_object('Cyber Services (backup trước menu 3 cấp)');
    if (!$menu) {
        $menu_id = wp_create_nav_menu('Cyber Services (backup trước menu 3 cấp)');
        if (is_wp_error($menu_id)) {
            return false;
        }
        $menu = wp_get_nav_menu_object((int) $menu_id);
    }
    if (!$menu) {
        return false;
    }

    $existing_by_page = [];
    foreach (wp_get_nav_menu_items((int) $menu->term_id) ?: [] as $menu_item) {
        if ($menu_item->type === 'post_type' && $menu_item->object === 'page') {
            $existing_by_page[(int) $menu_item->object_id] = (int) $menu_item->ID;
        }
    }

    $pci_page = get_page_by_path('pci-compliance', OBJECT, 'page');
    $dss_page = get_page_by_path('pci-compliance/pci-dss', OBJECT, 'page');
    $pin_page = get_page_by_path('pci-compliance/pci-pin', OBJECT, 'page');
    if (!($pci_page instanceof WP_Post) || !($dss_page instanceof WP_Post) || !($pin_page instanceof WP_Post)) {
        return false;
    }

    $parent_item_id = wp_update_nav_menu_item((int) $menu->term_id, $existing_by_page[(int) $pci_page->ID] ?? 0, [
        'menu-item-object-id' => (int) $pci_page->ID,
        'menu-item-object' => 'page',
        'menu-item-type' => 'post_type',
        'menu-item-status' => 'publish',
        'menu-item-title' => 'PCI Compliance',
        'menu-item-parent-id' => 0,
        'menu-item-position' => 1,
    ]);
    if (is_wp_error($parent_item_id)) {
        return false;
    }

    foreach ([[$dss_page, 'PCI DSS'], [$pin_page, 'PCI PIN']] as $index => [$page, $label]) {
        $result = wp_update_nav_menu_item((int) $menu->term_id, $existing_by_page[(int) $page->ID] ?? 0, [
            'menu-item-object-id' => (int) $page->ID,
            'menu-item-object' => 'page',
            'menu-item-type' => 'post_type',
            'menu-item-status' => 'publish',
            'menu-item-title' => $label,
            'menu-item-parent-id' => (int) $parent_item_id,
            'menu-item-position' => $index + 2,
        ]);
        if (is_wp_error($result)) {
            return false;
        }
    }
    return true;
}

function cyber_services_install_navigation(): void
{
    if (wp_installing() || !is_admin() || !current_user_can('edit_theme_options')) {
        return;
    }

    $schema_version = (string) get_option('cyber_services_navigation_schema_version');
    if ($schema_version === '3') {
        return;
    }
    cyber_services_disable_menu_auto_add();
    if ($schema_version === '1') {
        if (cyber_services_create_legacy_menu_backup()) {
            update_option('cyber_services_navigation_schema_version', '2', false);
        }
        return;
    }

    $page_ids = [];
    $ensure_pages = static function (array $items) use (&$ensure_pages, &$page_ids): bool {
        foreach ($items as $item) {
            $page_id = cyber_services_ensure_page($item['path'], $item['slug'], $item['title']);
            if ($page_id < 1) {
                return false;
            }
            $page_ids[$item['path']] = $page_id;
            if (!$ensure_pages($item['children'])) {
                return false;
            }
        }
        return true;
    };
    if (!$ensure_pages(cyber_services_navigation_blueprint())) {
        return;
    }

    $locations = get_nav_menu_locations();
    $previous_primary = (int) ($locations['primary'] ?? 0);
    $menu = wp_get_nav_menu_object('Điều hướng chính');
    if (!$menu) {
        $menu_id = wp_create_nav_menu('Điều hướng chính');
        if (is_wp_error($menu_id)) {
            return;
        }
        $menu = wp_get_nav_menu_object((int) $menu_id);
    }
    if (!$menu) {
        return;
    }

    $existing_by_page = [];
    foreach (wp_get_nav_menu_items((int) $menu->term_id) ?: [] as $menu_item) {
        if ($menu_item->type === 'post_type' && $menu_item->object === 'page') {
            $existing_by_page[(int) $menu_item->object_id] = (int) $menu_item->ID;
        }
    }

    $position = 0;
    $sync_items = static function (array $items, int $parent_item_id = 0) use (&$sync_items, &$position, $page_ids, $existing_by_page, $menu): bool {
        foreach ($items as $item) {
            $page_id = $page_ids[$item['path']];
            $menu_item_id = $existing_by_page[$page_id] ?? 0;
            $position++;
            $result = wp_update_nav_menu_item((int) $menu->term_id, $menu_item_id, [
                'menu-item-object-id' => $page_id,
                'menu-item-object' => 'page',
                'menu-item-type' => 'post_type',
                'menu-item-status' => 'publish',
                'menu-item-title' => wp_slash($item['label']),
                'menu-item-parent-id' => $parent_item_id,
                'menu-item-position' => $position,
            ]);
            if (is_wp_error($result)) {
                return false;
            }
            if (!$sync_items($item['children'], (int) $result)) {
                return false;
            }
        }
        return true;
    };
    if (!$sync_items(cyber_services_navigation_blueprint())) {
        return;
    }

    if ($previous_primary > 0 && $previous_primary !== (int) $menu->term_id) {
        update_option('cyber_services_previous_primary_menu_id', $previous_primary, false);
    }
    $locations['primary'] = (int) $menu->term_id;
    set_theme_mod('nav_menu_locations', $locations);
    update_option('cyber_services_navigation_schema_version', '3', false);
}
add_action('admin_init', 'cyber_services_install_navigation');

function cyber_services_navigation(bool $landing = false): array
{
    $canonicalize_resource = static function (array $items): array {
        $resource_key = static function (array $item): ?string {
            $label = strtolower(trim((string) ($item[0] ?? '')));
            $slug = sanitize_title($label);
            $path = trim((string) parse_url((string) ($item[1] ?? ''), PHP_URL_PATH), '/');
            if ($slug === 'resource' || $path === 'resource') {
                return 'resource';
            }
            if (in_array($slug, ['tin-tuc', 'tin-tuc-news', 'blog', 'news'], true) || in_array($path, ['blog', 'tin-tuc', 'category/tin-tuc'], true)) {
                return 'news';
            }
            if ($slug === 'case-study' || $path === 'case-study') {
                return 'case-study';
            }
            return null;
        };

        $resource = null;
        $resource_position = null;
        $canonical_children = [];
        $custom_children = [];
        $top_level = [];
        foreach ($items as $item) {
            $key = $resource_key($item);
            if ($key === 'resource') {
                $resource_position ??= count($top_level);
                if ($resource === null) {
                    $resource = $item;
                } else {
                    $resource[2] = array_merge($resource[2], $item[2]);
                }
                continue;
            }
            if ($key === 'news' || $key === 'case-study') {
                $resource_position ??= count($top_level);
                if (!isset($canonical_children[$key])) {
                    $canonical_children[$key] = [$key === 'news' ? 'Tin tức (News)' : 'Case Study', $item[1], $item[2]];
                } else {
                    $custom_children[] = $item;
                }
                continue;
            }
            $top_level[] = $item;
        }

        if ($resource !== null) {
            $resource[0] = 'Resource';
            foreach ($resource[2] as $child) {
                $key = $resource_key($child);
                if (($key === 'news' || $key === 'case-study') && !isset($canonical_children[$key])) {
                    $canonical_children[$key] = [$key === 'news' ? 'Tin tức (News)' : 'Case Study', $child[1], $child[2]];
                    continue;
                }
                $custom_children[] = $child;
            }
        } else {
            $resource = ['Resource', home_url('/resource/'), []];
        }
        $canonical_children['news'] ??= ['Tin tức (News)', home_url('/blog/'), []];
        $canonical_children['case-study'] ??= ['Case Study', home_url('/case-study/'), []];
        $resource[2] = [$canonical_children['news'], $canonical_children['case-study'], ...$custom_children];

        if ($resource_position === null) {
            $resource_position = count($top_level);
            foreach ($top_level as $index => $item) {
                $label = strtolower(trim((string) ($item[0] ?? '')));
                $path = trim((string) parse_url((string) ($item[1] ?? ''), PHP_URL_PATH), '/');
                if ($label === 'liên hệ' || $path === 'lien-he') {
                    $resource_position = $index;
                    break;
                }
            }
        }
        array_splice($top_level, $resource_position, 0, [$resource]);
        return $top_level;
    };

    $locations = get_nav_menu_locations();
    $has_primary_location = isset($locations['primary']);
    $menu_items = $has_primary_location ? wp_get_nav_menu_items((int) $locations['primary']) : false;
    if ($has_primary_location) {
        if (!is_array($menu_items)) {
            return [];
        }
        $by_parent = [];
        foreach ($menu_items as $menu_item) {
            $by_parent[(int) $menu_item->menu_item_parent][] = $menu_item;
        }
        $tree = static function (int $parent) use (&$tree, $by_parent): array {
            $items = [];
            foreach ($by_parent[$parent] ?? [] as $menu_item) {
                $items[] = [$menu_item->title, $menu_item->url, $tree((int) $menu_item->ID)];
            }
            return $items;
        };
        return $canonicalize_resource($tree(0));
    }

    $home = $landing ? '' : home_url('/');
    $items = [
        ['Dịch vụ', $home . '#dich-vu', []],
        ['Vì sao chọn chúng tôi', $home . '#vi-sao', []],
        ['Quy trình', $home . '#quy-trinh', []],
        ['Khách hàng', $home . '#khach-hang', []],
        ['Liên hệ', $home . '#lien-he', []],
    ];
    $excluded = array_flip(array_filter([(int) get_option('page_on_front'), (int) get_option('page_for_posts')]));
    $pages = get_pages([
        'post_status' => 'publish',
        'sort_column' => 'menu_order,post_title',
    ]);
    $by_parent = [];
    foreach ($pages as $page) {
        $by_parent[(int) $page->post_parent][] = $page;
    }
    $tree = static function (int $parent) use (&$tree, $by_parent, $excluded): array {
        $items = [];
        foreach ($by_parent[$parent] ?? [] as $page) {
            $children = $tree((int) $page->ID);
            if (isset($excluded[(int) $page->ID])) {
                array_push($items, ...$children);
                continue;
            }
            $items[] = [get_the_title($page), get_permalink($page), $children];
        }
        return $items;
    };
    array_push($items, ...$tree(0));

    $blog_id = (int) get_option('page_for_posts');
    $items[] = ['Blog', $blog_id > 0 ? get_permalink($blog_id) : home_url('/blog/'), []];
    return $canonicalize_resource($items);
}

/**
 * Split the primary navigation into the two compact columns used by the
 * homepage footer. Both columns therefore follow the same menu as the header.
 */
function cyber_services_footer_navigation(array $navigation): array
{
    $services = [];
    $products = [];
    $company = [];
    foreach ($navigation as [$label, $href, $children]) {
        $path = trim((string) wp_parse_url($href, PHP_URL_PATH), '/');
        if ($path === 'dich-vu' || sanitize_title($label) === 'dich-vu') {
            $services = $children ? array_map(static fn(array $item): array => [$item[0], $item[1]], $children) : [[$label, $href]];
            continue;
        }
        if ($path === 'san-pham' || sanitize_title($label) === 'san-pham') {
            $products = $children ? array_map(static fn(array $item): array => [$item[0], $item[1]], $children) : [[$label, $href]];
            continue;
        }
        $company[] = [$label, $href];
        if (sanitize_title($label) === 'resource') {
            foreach ($children as [$child_label, $child_href]) {
                $company[] = [$child_label, $child_href];
            }
        }
    }
    $has_product_landing = false;
    foreach ($products as [$label, $href]) {
        if (sanitize_title($label) === 'san-pham' || trim((string) wp_parse_url($href, PHP_URL_PATH), '/') === 'san-pham') {
            $has_product_landing = true;
            break;
        }
    }
    if (!$has_product_landing) {
        array_unshift($products, ['Sản phẩm & giải pháp', home_url('/san-pham/')]);
    }
    foreach (cyber_services_products() as $product) {
        $product_title = trim((string) ($product[1] ?? ''));
        $product_href = (string) ($product[3] ?? home_url('/san-pham/'));
        if ($product_title === '') {
            continue;
        }
        $products[] = [$product_title, $product_href];
    }
    if (!$services) {
        foreach ($navigation as [$label, $href]) {
            if (sanitize_title($label) === 'dich-vu') {
                $services[] = [$label, $href];
                break;
            }
        }
    }
    return [$services, $products, $company];
}

function cyber_services_page_children(int $page_id): array
{
    $children = [];
    $find_navigation_children = static function (array $items) use (&$find_navigation_children, $page_id): array {
        foreach ($items as [, $href, $item_children]) {
            if (url_to_postid($href) === $page_id) {
                return $item_children;
            }
            $found = $find_navigation_children($item_children);
            if ($found) {
                return $found;
            }
        }
        return [];
    };

    foreach ($find_navigation_children(cyber_services_navigation(false)) as [, $href]) {
        $child_id = url_to_postid($href);
        $child = $child_id > 0 ? get_post($child_id) : null;
        if ($child instanceof WP_Post && $child->post_type === 'page' && $child->post_status === 'publish') {
            $children[$child->ID] = $child;
        }
    }

    foreach (get_pages([
        'parent' => $page_id,
        'post_status' => 'publish',
        'sort_column' => 'menu_order,post_title',
        'sort_order' => 'ASC',
    ]) as $child) {
        $children[$child->ID] = $child;
    }
    return array_values($children);
}

function cyber_services_page_excerpt(WP_Post $page, int $words = 28): string
{
    return cyber_services_excerpt($words, $page);
}

/**
 * Add stable anchors to article headings and return the generated table of
 * contents alongside the filtered article HTML.
 */
function cyber_services_article_content(string $content): string
{
    $content = apply_filters('the_content', $content);
    $used_ids = [];
    $counter = 0;
    $content = preg_replace_callback('/<h([23])([^>]*)>(.*?)<\/h\1>/is', static function (array $matches) use (&$used_ids, &$counter): string {
        $level = (int) $matches[1];
        $attributes = $matches[2];
        $heading_html = $matches[3];
        $title = trim(wp_strip_all_tags($heading_html));
        if ($title === '') {
            return $matches[0];
        }

        $id = '';
        if (preg_match('/\sid=(["\'])(.*?)\1/i', $attributes, $id_match)) {
            $id = trim(html_entity_decode($id_match[2], ENT_QUOTES, get_bloginfo('charset')));
        }
        if ($id === '') {
            $counter++;
            $id = sanitize_title($title) ?: 'muc-' . $counter;
            $base_id = $id;
            $suffix = 2;
            while (isset($used_ids[$id])) {
                $id = $base_id . '-' . $suffix;
                $suffix++;
            }
            $attributes .= ' id="' . esc_attr($id) . '"';
        }
        $used_ids[$id] = true;
        return '<h' . $level . $attributes . '>' . $heading_html . '</h' . $level . '>';
    }, $content) ?? $content;

    return $content;
}

function cyber_services_smtp_defaults(): array
{
    return [
        'enabled' => '0',
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => get_bloginfo('name'),
        'recipient_email' => (string) get_option('admin_email'),
    ];
}

function cyber_services_smtp_settings(): array
{
    $saved = get_option('cyber_services_smtp', []);
    return wp_parse_args(is_array($saved) ? $saved : [], cyber_services_smtp_defaults());
}

function cyber_services_sanitize_smtp_settings(array $input): array
{
    $current = cyber_services_smtp_settings();
    $encryption = isset($input['encryption']) ? sanitize_key($input['encryption']) : 'tls';
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $encryption = 'tls';
    }
    $password = isset($input['password']) ? trim((string) $input['password']) : '';
    return [
        'enabled' => !empty($input['enabled']) ? '1' : '0',
        'host' => isset($input['host']) ? sanitize_text_field($input['host']) : '',
        'port' => max(1, min(65535, (int) ($input['port'] ?? 587))),
        'encryption' => $encryption,
        'username' => isset($input['username']) ? sanitize_text_field($input['username']) : '',
        'password' => $password !== '' ? $password : (string) $current['password'],
        'from_email' => isset($input['from_email']) ? sanitize_email($input['from_email']) : '',
        'from_name' => isset($input['from_name']) ? sanitize_text_field($input['from_name']) : '',
        'recipient_email' => isset($input['recipient_email']) ? sanitize_email($input['recipient_email']) : '',
    ];
}

function cyber_services_register_smtp_settings(): void
{
    register_setting('cyber_services_smtp', 'cyber_services_smtp', [
        'type' => 'array',
        'sanitize_callback' => 'cyber_services_sanitize_smtp_settings',
        'default' => cyber_services_smtp_defaults(),
    ]);
}
add_action('admin_init', 'cyber_services_register_smtp_settings');

function cyber_services_add_smtp_settings_page(): void
{
    add_options_page(
        __('Cyber Services SMTP', 'cyber-services'),
        __('Cyber Services SMTP', 'cyber-services'),
        'manage_options',
        'cyber-services-smtp',
        'cyber_services_render_smtp_settings_page'
    );
}
add_action('admin_menu', 'cyber_services_add_smtp_settings_page');

function cyber_services_render_smtp_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $settings = cyber_services_smtp_settings();
    $test_status = isset($_GET['smtp_test']) ? sanitize_key(wp_unslash($_GET['smtp_test'])) : '';
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Cấu hình SMTP cho biểu mẫu liên hệ', 'cyber-services'); ?></h1>
        <p><?php esc_html_e('Nhập thông tin SMTP của hộp thư gửi. Mật khẩu để trống khi lưu nếu bạn không muốn thay đổi mật khẩu hiện tại.', 'cyber-services'); ?></p>
        <?php if ($test_status === 'sent') : ?><div class="notice notice-success"><p><?php esc_html_e('Email thử đã được gửi.', 'cyber-services'); ?></p></div><?php endif; ?>
        <?php if ($test_status === 'failed') : ?><div class="notice notice-error"><p><?php esc_html_e('Không gửi được email thử. Hãy kiểm tra host, cổng, kiểu mã hóa và tài khoản SMTP.', 'cyber-services'); ?></p></div><?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields('cyber_services_smtp'); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><?php esc_html_e('Bật SMTP', 'cyber-services'); ?></th><td><label><input type="checkbox" name="cyber_services_smtp[enabled]" value="1" <?php checked($settings['enabled'], '1'); ?>> <?php esc_html_e('Gửi biểu mẫu qua máy chủ SMTP này', 'cyber-services'); ?></label></td></tr>
                <tr><th scope="row"><label for="cyber-smtp-host"><?php esc_html_e('SMTP host', 'cyber-services'); ?></label></th><td><input class="regular-text" id="cyber-smtp-host" name="cyber_services_smtp[host]" value="<?php echo esc_attr($settings['host']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-smtp-port"><?php esc_html_e('Cổng', 'cyber-services'); ?></label></th><td><input class="small-text" id="cyber-smtp-port" name="cyber_services_smtp[port]" type="number" min="1" max="65535" value="<?php echo esc_attr((string) $settings['port']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-smtp-encryption"><?php esc_html_e('Mã hóa', 'cyber-services'); ?></label></th><td><select id="cyber-smtp-encryption" name="cyber_services_smtp[encryption]"><option value="tls" <?php selected($settings['encryption'], 'tls'); ?>>TLS</option><option value="ssl" <?php selected($settings['encryption'], 'ssl'); ?>>SSL</option><option value="none" <?php selected($settings['encryption'], 'none'); ?>><?php esc_html_e('Không mã hóa', 'cyber-services'); ?></option></select></td></tr>
                <tr><th scope="row"><label for="cyber-smtp-username"><?php esc_html_e('Tài khoản', 'cyber-services'); ?></label></th><td><input class="regular-text" id="cyber-smtp-username" name="cyber_services_smtp[username]" autocomplete="username" value="<?php echo esc_attr($settings['username']); ?>"></td></tr>
                <tr><th scope="row"><label for="cyber-smtp-password"><?php esc_html_e('Mật khẩu', 'cyber-services'); ?></label></th><td><input class="regular-text" id="cyber-smtp-password" name="cyber_services_smtp[password]" type="password" autocomplete="new-password" value="" placeholder="<?php esc_attr_e('Giữ mật khẩu hiện tại', 'cyber-services'); ?>"></td></tr>
                <tr><th scope="row"><label for="cyber-smtp-from-email"><?php esc_html_e('Email gửi', 'cyber-services'); ?></label></th><td><input class="regular-text" id="cyber-smtp-from-email" name="cyber_services_smtp[from_email]" type="email" value="<?php echo esc_attr($settings['from_email']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-smtp-from-name"><?php esc_html_e('Tên người gửi', 'cyber-services'); ?></label></th><td><input class="regular-text" id="cyber-smtp-from-name" name="cyber_services_smtp[from_name]" value="<?php echo esc_attr($settings['from_name']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-smtp-recipient"><?php esc_html_e('Email nhận yêu cầu', 'cyber-services'); ?></label></th><td><input class="regular-text" id="cyber-smtp-recipient" name="cyber_services_smtp[recipient_email]" type="email" value="<?php echo esc_attr($settings['recipient_email']); ?>" required></td></tr>
            </table>
            <?php submit_button(__('Lưu cấu hình SMTP', 'cyber-services')); ?>
        </form>
        <hr>
        <h2><?php esc_html_e('Kiểm tra kết nối', 'cyber-services'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="cyber_services_smtp_test">
            <?php wp_nonce_field('cyber_services_smtp_test'); ?>
            <?php submit_button(__('Gửi email thử', 'cyber-services'), 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php
}

function cyber_services_contact_defaults(): array
{
    return [
        'phone' => '+84 979 875 985',
        'email' => 'cybervn@cyberservices.com',
        'address' => 'LP08-2, P. Nguyễn Thị Duệ, Cầu Giấy, Hà Nội',
        'hours' => 'Hằng ngày: 7:00 – 18:00',
        'zalo_url' => 'https://zalo.me/0979875985',
        'telegram_url' => 'https://t.me/ok_vuivejn',
        'facebook_url' => 'https://web.facebook.com/cyberservicesvietnam',
        'youtube_url' => 'https://www.youtube.com/@CyberServicesVN',
        'linkedin_url' => 'https://www.linkedin.com/in/hung-tong-duy',
    ];
}

function cyber_services_contact_settings(): array
{
    $saved = get_option('cyber_services_contact', []);
    return wp_parse_args(is_array($saved) ? $saved : [], cyber_services_contact_defaults());
}

function cyber_services_sanitize_contact_settings(array $input): array
{
    $current = cyber_services_contact_settings();
    $text_value = static function (string $key) use ($input, $current): string {
        $value = isset($input[$key]) ? sanitize_text_field((string) $input[$key]) : '';
        return $value !== '' ? $value : (string) $current[$key];
    };
    $url_value = static function (string $key) use ($input, $current): string {
        $value = isset($input[$key]) ? esc_url_raw((string) $input[$key]) : '';
        return $value !== '' ? $value : (string) $current[$key];
    };
    $email = isset($input['email']) ? sanitize_email((string) $input['email']) : '';

    return [
        'phone' => $text_value('phone'),
        'email' => is_email($email) ? $email : (string) $current['email'],
        'address' => $text_value('address'),
        'hours' => $text_value('hours'),
        'zalo_url' => $url_value('zalo_url'),
        'telegram_url' => $url_value('telegram_url'),
        'facebook_url' => $url_value('facebook_url'),
        'youtube_url' => $url_value('youtube_url'),
        'linkedin_url' => $url_value('linkedin_url'),
    ];
}

function cyber_services_register_contact_settings(): void
{
    register_setting('cyber_services_contact', 'cyber_services_contact', [
        'type' => 'array',
        'sanitize_callback' => 'cyber_services_sanitize_contact_settings',
        'default' => cyber_services_contact_defaults(),
    ]);
}
add_action('admin_init', 'cyber_services_register_contact_settings');

function cyber_services_add_contact_settings_page(): void
{
    add_options_page(
        __('Cyber Services Contact', 'cyber-services'),
        __('Cyber Services Contact', 'cyber-services'),
        'manage_options',
        'cyber-services-contact',
        'cyber_services_render_contact_settings_page'
    );
}
add_action('admin_menu', 'cyber_services_add_contact_settings_page');

function cyber_services_render_contact_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $settings = cyber_services_contact_settings();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Thông tin liên hệ Cyber Services', 'cyber-services'); ?></h1>
        <p><?php esc_html_e('Các giá trị này được dùng ở trang chủ, trang Liên hệ và nút liên hệ nổi.', 'cyber-services'); ?></p>
        <form method="post" action="options.php">
            <?php settings_fields('cyber_services_contact'); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="cyber-contact-phone"><?php esc_html_e('Số điện thoại / Hotline', 'cyber-services'); ?></label></th><td><input class="regular-text" id="cyber-contact-phone" name="cyber_services_contact[phone]" value="<?php echo esc_attr($settings['phone']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-contact-email"><?php esc_html_e('Email liên hệ', 'cyber-services'); ?></label></th><td><input class="regular-text" id="cyber-contact-email" name="cyber_services_contact[email]" type="email" value="<?php echo esc_attr($settings['email']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-contact-address"><?php esc_html_e('Địa chỉ', 'cyber-services'); ?></label></th><td><input class="regular-text" id="cyber-contact-address" name="cyber_services_contact[address]" value="<?php echo esc_attr($settings['address']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-contact-hours"><?php esc_html_e('Giờ làm việc', 'cyber-services'); ?></label></th><td><input class="regular-text" id="cyber-contact-hours" name="cyber_services_contact[hours]" value="<?php echo esc_attr($settings['hours']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-contact-zalo"><?php esc_html_e('Zalo URL', 'cyber-services'); ?></label></th><td><input class="regular-text code" id="cyber-contact-zalo" name="cyber_services_contact[zalo_url]" type="url" value="<?php echo esc_attr($settings['zalo_url']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-contact-telegram"><?php esc_html_e('Telegram URL', 'cyber-services'); ?></label></th><td><input class="regular-text code" id="cyber-contact-telegram" name="cyber_services_contact[telegram_url]" type="url" value="<?php echo esc_attr($settings['telegram_url']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-contact-facebook"><?php esc_html_e('Facebook URL', 'cyber-services'); ?></label></th><td><input class="regular-text code" id="cyber-contact-facebook" name="cyber_services_contact[facebook_url]" type="url" value="<?php echo esc_attr($settings['facebook_url']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-contact-youtube"><?php esc_html_e('YouTube URL', 'cyber-services'); ?></label></th><td><input class="regular-text code" id="cyber-contact-youtube" name="cyber_services_contact[youtube_url]" type="url" value="<?php echo esc_attr($settings['youtube_url']); ?>" required></td></tr>
                <tr><th scope="row"><label for="cyber-contact-linkedin"><?php esc_html_e('LinkedIn URL', 'cyber-services'); ?></label></th><td><input class="regular-text code" id="cyber-contact-linkedin" name="cyber_services_contact[linkedin_url]" type="url" value="<?php echo esc_attr($settings['linkedin_url']); ?>" required></td></tr>
            </table>
            <?php submit_button(__('Lưu thông tin liên hệ', 'cyber-services')); ?>
        </form>
    </div>
    <?php
}

function cyber_services_configure_phpmailer($phpmailer): void
{
    $settings = cyber_services_smtp_settings();
    if ($settings['enabled'] !== '1' || $settings['host'] === '' || !is_email($settings['from_email'])) {
        return;
    }
    $phpmailer->isSMTP();
    $phpmailer->Host = $settings['host'];
    $phpmailer->Port = (int) $settings['port'];
    $phpmailer->SMTPAuth = $settings['username'] !== '';
    $phpmailer->Username = $settings['username'];
    $phpmailer->Password = $settings['password'];
    $phpmailer->SMTPSecure = $settings['encryption'] === 'none' ? '' : $settings['encryption'];
    $phpmailer->SMTPAutoTLS = $settings['encryption'] !== 'none';
    $phpmailer->setFrom($settings['from_email'], $settings['from_name'], false);
}
add_action('phpmailer_init', 'cyber_services_configure_phpmailer');

function cyber_services_handle_smtp_test(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'cyber-services'));
    }
    check_admin_referer('cyber_services_smtp_test');
    $settings = cyber_services_smtp_settings();
    $recipient = is_email($settings['recipient_email']) ? $settings['recipient_email'] : get_option('admin_email');
    $sent = wp_mail($recipient, __('Kiểm tra SMTP từ Cyber Services', 'cyber-services'), __('Cấu hình SMTP đang hoạt động.', 'cyber-services'));
    $url = add_query_arg(['page' => 'cyber-services-smtp', 'smtp_test' => $sent ? 'sent' : 'failed'], admin_url('options-general.php'));
    wp_safe_redirect($url);
    exit;
}
add_action('admin_post_cyber_services_smtp_test', 'cyber_services_handle_smtp_test');

function cyber_services_navigation_desktop(array $items): void
{
    foreach ($items as [$label, $href, $children]) {
        echo '<li class="desktopItem"><a class="desktopLink" href="' . esc_url($href) . '"' . cyber_services_navigation_current($href) . ($children ? ' aria-haspopup="true"' : '') . '>' . esc_html($label);
        if ($children) {
            echo '<span aria-hidden="true">+</span>';
        }
        echo '</a>';
        if ($children) {
            echo '<ul class="dropdown">';
            cyber_services_navigation_desktop($children);
            echo '</ul>';
        }
        echo '</li>';
    }
}

function cyber_services_navigation_drawer(array $items, string $path = 'menu', int $depth = 0): void
{
    foreach ($items as $index => [$label, $href, $children]) {
        $id = sanitize_html_class($path . '-' . $index);
        echo '<li class="drawerItem drawerDepth' . esc_attr((string) min($depth, 3)) . '"><div class="drawerRow"><span class="itemLabel"><a href="' . esc_url($href) . '"' . cyber_services_navigation_current($href) . '>' . esc_html($label) . '</a></span>';
        if ($children) {
            echo '<button type="button" aria-expanded="false" aria-controls="' . esc_attr($id) . '-children" aria-label="' . esc_attr(sprintf(__('Mở %s', 'cyber-services'), $label)) . '"><span aria-hidden="true">+</span></button>';
        }
        echo '</div>';
        if ($children) {
            echo '<div class="drawerChildren" id="' . esc_attr($id) . '-children" data-expanded="false" aria-hidden="true" inert><ul>';
            cyber_services_navigation_drawer($children, $id, $depth + 1);
            echo '</ul></div>';
        }
        echo '</li>';
    }
}

function cyber_services_navigation_current(string $url): string
{
    $current_id = get_queried_object_id();
    if ($current_id < 1 || is_front_page()) {
        return '';
    }
    $blog_id = (int) get_option('page_for_posts');
    if ($blog_id > 0 && (is_home() || is_singular('post')) && untrailingslashit($url) === untrailingslashit((string) get_permalink($blog_id))) {
        return ' aria-current="page"';
    }
    if (is_category()) {
        $category_url = get_category_link($current_id);
        if (!is_wp_error($category_url) && untrailingslashit($url) === untrailingslashit((string) $category_url)) {
            return ' aria-current="page"';
        }
    }
    $target_id = url_to_postid($url);
    return $target_id === $current_id ? ' aria-current="page"' : '';
}

function cyber_services_excerpt(int $words = 28, ?WP_Post $post = null): string
{
    $excerpt = wp_strip_all_tags(strip_shortcodes((string) get_the_excerpt($post)));
    return wp_trim_words($excerpt, $words, '…');
}

function cyber_services_default_services(): array
{
    return [
        ['PCI', 'Tư vấn, đánh giá & chứng nhận PCI DSS', 'Đồng hành từ đánh giá khoảng cách đến đạt chứng nhận PCI DSS cho ngân hàng, công ty công nghệ tài chính và đơn vị xử lý thẻ.', home_url('/pci-compliance/pci-dss/'), 'Ví dụ: 1-4 tuần', 'Ví dụ: 100$'],
        ['SOC', 'Tư vấn, kiểm toán & chứng nhận SOC 2', 'Báo cáo SOC 2 chuẩn AICPA cho doanh nghiệp phần mềm, doanh nghiệp khởi nghiệp và đơn vị phục vụ thị trường quốc tế.', home_url('/soc-2-type-i/'), 'Ví dụ: 1-4 tuần', 'Ví dụ: 100$'],
        ['PDP', 'Bảo vệ dữ liệu cá nhân', 'Tuân thủ Luật Bảo vệ dữ liệu cá nhân và các nghị định hướng dẫn mới nhất tại Việt Nam.', home_url('/dich-vu/'), 'Ví dụ: 1-4 tuần', 'Ví dụ: 100$'],
        ['PIN', 'Bảo mật mã PIN theo PCI', 'Tư vấn và đánh giá bảo mật xử lý mã PIN theo tiêu chuẩn PCI PIN cho tổ chức thanh toán.', home_url('/pci-compliance/pci-pin/'), 'Ví dụ: 1-4 tuần', 'Ví dụ: 100$'],
        ['SSF', 'Đánh giá tuân thủ PCI SSF', 'Đánh giá an toàn phần mềm thanh toán theo khung bảo mật phần mềm PCI SSF.', home_url('/pci-ssf/'), 'Ví dụ: 1-4 tuần', 'Ví dụ: 100$'],
        ['CMMI', 'Tư vấn & cấp chứng chỉ CMMI', 'Nâng mức độ trưởng thành quy trình phát triển phần mềm và đạt chứng chỉ CMMI.', home_url('/dich-vu/'), 'Ví dụ: 1-4 tuần', 'Ví dụ: 100$'],
        ['3DS', 'Đánh giá PCI 3DS', 'Đánh giá môi trường xác thực 3-D Secure cho các đơn vị cung cấp dịch vụ giao dịch thẻ.', home_url('/pci-3ds/'), 'Ví dụ: 1-4 tuần', 'Ví dụ: 100$'],
        ['ISO', 'Chứng nhận ISO/IEC 42001:2023', 'Tư vấn hệ thống quản lý trí tuệ nhân tạo theo ISO/IEC 42001 cho doanh nghiệp ứng dụng AI.', home_url('/iso-42001/'), 'Ví dụ: 1-4 tuần', 'Ví dụ: 100$'],
        ['ISMS', 'ISO 27001 & tiêu chuẩn khác', 'Xây dựng hệ thống quản lý an toàn thông tin và đáp ứng GDPR, HIPAA, CCPA theo nhu cầu.', home_url('/iso-standard/'), 'Ví dụ: 1-4 tuần', 'Ví dụ: 100$'],
    ];
}

function cyber_services_sanitize_services($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $services = [];
    foreach ($value as $service) {
        if (!is_array($service)) {
            continue;
        }
        $badge = sanitize_text_field((string) ($service['badge'] ?? $service[0] ?? ''));
        $title = sanitize_text_field((string) ($service['title'] ?? $service[1] ?? ''));
        $description = sanitize_textarea_field((string) ($service['description'] ?? $service[2] ?? ''));
        $url = esc_url_raw((string) ($service['url'] ?? $service[3] ?? ''));
        $timeline = sanitize_text_field((string) ($service['timeline'] ?? $service[4] ?? 'Ví dụ: 1-4 tuần (code update đc)'));
        $investment = sanitize_text_field((string) ($service['investment'] ?? $service[5] ?? 'Ví dụ: 100$'));
        if ($badge === '' && $title === '' && $description === '') {
            continue;
        }
        $services[] = [$badge, $title, $description, $url, $timeline, $investment];
    }
    return $services;
}

function cyber_services_services(): array
{
    $services = get_option('cyber_services_homepage_services', null);
    if ($services === null) {
        return cyber_services_default_services();
    }
    $services = cyber_services_sanitize_services($services);
    $fallback = home_url('/dich-vu/');
    return array_map(static function (array $service) use ($fallback): array {
        $service[3] = $service[3] !== '' ? $service[3] : $fallback;
        return $service;
    }, $services);
}

function cyber_services_default_products(): array
{
    return [
        ['CÔNG CỤ QUÉT', 'Công cụ kiểm tra & khắc phục lỗ hổng', 'Giải pháp tự động giúp phát hiện và hỗ trợ khắc phục các lỗi bảo mật.', home_url('/san-pham/'), 'Cyber Services / Partner', '24/7 (hoặc theo yêu cầu)'],
        ['PHẦN MỀM', 'Phần mềm quản lý rủi ro tuân thủ', 'Nền tảng quản lý tập trung các quy trình tuân thủ PCI DSS, SOC 2, ISO và các tiêu chuẩn liên quan.', home_url('/san-pham/'), 'Cyber Services', '24/7 Standard & Premium'],
        ['DỊCH VỤ SỐ', 'Nền tảng đào tạo nâng cao nhận thức bảo mật', 'Nội dung đào tạo và kiểm tra nhận thức an toàn thông tin cho nhân viên trực tuyến.', home_url('/san-pham/'), 'Đối tác ủy quyền', 'Hỗ trợ định kỳ / 24/7'],
    ];
}

function cyber_services_sanitize_products($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $products = [];
    foreach ($value as $product) {
        if (!is_array($product)) {
            continue;
        }
        $badge = sanitize_text_field((string) ($product['badge'] ?? $product[0] ?? ''));
        $title = sanitize_text_field((string) ($product['title'] ?? $product[1] ?? ''));
        $description = sanitize_textarea_field((string) ($product['description'] ?? $product[2] ?? ''));
        $url = esc_url_raw((string) ($product['url'] ?? $product[3] ?? ''));
        $manufacturer = sanitize_text_field((string) ($product['manufacturer'] ?? $product[4] ?? ''));
        $support = sanitize_text_field((string) ($product['support'] ?? $product[5] ?? ''));
        if ($badge === '' && $title === '' && $description === '') {
            continue;
        }
        $products[] = [$badge, $title, $description, $url, $manufacturer, $support];
    }
    return $products;
}

function cyber_services_products(): array
{
    $products = get_option('cyber_services_homepage_products', null);
    if ($products === null) {
        return cyber_services_default_products();
    }
    $products = cyber_services_sanitize_products($products);
    $fallback = home_url('/san-pham/');
    return array_map(static function (array $product) use ($fallback): array {
        $product[3] = $product[3] !== '' ? $product[3] : $fallback;
        return $product;
    }, $products);
}

function cyber_services_register_homepage_products_setting(): void
{
    register_setting('cyber_services_homepage_products', 'cyber_services_homepage_products', [
        'type' => 'array',
        'sanitize_callback' => 'cyber_services_sanitize_products',
        'default' => cyber_services_default_products(),
    ]);
}
add_action('admin_init', 'cyber_services_register_homepage_products_setting');

function cyber_services_homepage_products_menu(): void
{
    add_options_page(
        __('Homepage Products & Solutions', 'cyber-services'),
        __('Homepage Products & Solutions', 'cyber-services'),
        'manage_options',
        'cyber-services-homepage-products',
        'cyber_services_homepage_products_page'
    );
}
add_action('admin_menu', 'cyber_services_homepage_products_menu');

function cyber_services_homepage_products_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $saved = get_option('cyber_services_homepage_products', null);
    $products = $saved === null ? cyber_services_default_products() : cyber_services_sanitize_products($saved);
    ?>
    <div class="wrap" data-cyber-products-settings>
        <h1><?php esc_html_e('Homepage Products &amp; Solutions', 'cyber-services'); ?></h1>
        <p><?php esc_html_e('Add, remove, reorder, and edit the product and solution cards shown on the homepage.', 'cyber-services'); ?></p>
        <form action="options.php" method="post">
            <?php settings_fields('cyber_services_homepage_products'); ?>
            <input type="hidden" name="cyber_services_homepage_products[]" value="">
            <div data-product-list>
                <?php foreach ($products as $index => [$badge, $title, $description, $url, $manufacturer, $support]) : ?>
                    <fieldset class="cyber-product-setting" data-product-row>
                        <legend><?php echo esc_html(sprintf(__('Product card %d', 'cyber-services'), $index + 1)); ?></legend>
                        <label><?php esc_html_e('Badge', 'cyber-services'); ?><input type="text" name="cyber_services_homepage_products[<?php echo esc_attr((string) $index); ?>][badge]" value="<?php echo esc_attr($badge); ?>" maxlength="20" data-field="badge"></label>
                        <label><?php esc_html_e('Title', 'cyber-services'); ?><input type="text" name="cyber_services_homepage_products[<?php echo esc_attr((string) $index); ?>][title]" value="<?php echo esc_attr($title); ?>" data-field="title" required></label>
                        <label><?php esc_html_e('Description', 'cyber-services'); ?><textarea name="cyber_services_homepage_products[<?php echo esc_attr((string) $index); ?>][description]" rows="3" data-field="description" required><?php echo esc_textarea($description); ?></textarea></label>
                        <label><?php esc_html_e('Manufacturer', 'cyber-services'); ?><input type="text" name="cyber_services_homepage_products[<?php echo esc_attr((string) $index); ?>][manufacturer]" value="<?php echo esc_attr($manufacturer); ?>" data-field="manufacturer"></label>
                        <label><?php esc_html_e('Support', 'cyber-services'); ?><input type="text" name="cyber_services_homepage_products[<?php echo esc_attr((string) $index); ?>][support]" value="<?php echo esc_attr($support); ?>" data-field="support"></label>
                        <label><?php esc_html_e('Details URL', 'cyber-services'); ?><input type="url" name="cyber_services_homepage_products[<?php echo esc_attr((string) $index); ?>][url]" value="<?php echo esc_attr($url); ?>" data-field="url" placeholder="<?php echo esc_attr(home_url('/san-pham/')); ?>"></label>
                        <div class="cyber-product-actions"><button class="button" type="button" data-move="up" aria-label="<?php esc_attr_e('Move card up', 'cyber-services'); ?>">↑</button><button class="button" type="button" data-move="down" aria-label="<?php esc_attr_e('Move card down', 'cyber-services'); ?>">↓</button><button class="button button-link-delete" type="button" data-remove><?php esc_html_e('Delete card', 'cyber-services'); ?></button></div>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <p><button class="button" type="button" data-add-product><?php esc_html_e('Add product card', 'cyber-services'); ?></button></p>
            <?php submit_button(); ?>
        </form>
        <template data-product-template><fieldset class="cyber-product-setting" data-product-row><legend></legend><label><?php esc_html_e('Badge', 'cyber-services'); ?><input type="text" maxlength="20" data-field="badge"></label><label><?php esc_html_e('Title', 'cyber-services'); ?><input type="text" data-field="title" required></label><label><?php esc_html_e('Description', 'cyber-services'); ?><textarea rows="3" data-field="description" required></textarea></label><label><?php esc_html_e('Manufacturer', 'cyber-services'); ?><input type="text" data-field="manufacturer"></label><label><?php esc_html_e('Support', 'cyber-services'); ?><input type="text" data-field="support"></label><label><?php esc_html_e('Details URL', 'cyber-services'); ?><input type="url" data-field="url" placeholder="<?php echo esc_attr(home_url('/san-pham/')); ?>"></label><div class="cyber-product-actions"><button class="button" type="button" data-move="up" aria-label="<?php esc_attr_e('Move card up', 'cyber-services'); ?>">↑</button><button class="button" type="button" data-move="down" aria-label="<?php esc_attr_e('Move card down', 'cyber-services'); ?>">↓</button><button class="button button-link-delete" type="button" data-remove><?php esc_html_e('Delete card', 'cyber-services'); ?></button></div></fieldset></template>
    </div>
    <style>.cyber-product-setting{max-width:900px;display:grid;grid-template-columns:120px 1fr;gap:12px 18px;margin:18px 0;border:1px solid #c3c4c7;padding:18px;background:#fff}.cyber-product-setting legend{padding:0 6px;font-weight:700}.cyber-product-setting label{display:grid;grid-column:1/-1;grid-template-columns:120px 1fr;align-items:start;gap:18px;font-weight:600}.cyber-product-setting :is(input,textarea){width:100%;font-weight:400}.cyber-product-actions{grid-column:2;display:flex;gap:8px;align-items:center}@media(max-width:782px){.cyber-product-setting,.cyber-product-setting label{grid-template-columns:1fr}.cyber-product-actions{grid-column:1}}</style>
    <script>
    (() => {
        const root = document.querySelector('[data-cyber-products-settings]');
        const list = root.querySelector('[data-product-list]');
        const template = root.querySelector('[data-product-template]');
        const update = () => {
            const rows = [...list.querySelectorAll('[data-product-row]')];
            rows.forEach((row, index) => {
                row.querySelector('legend').textContent = `<?php echo esc_js(__('Product card', 'cyber-services')); ?> ${index + 1}`;
                row.querySelectorAll('[data-field]').forEach((field) => { field.name = `cyber_services_homepage_products[${index}][${field.dataset.field}]`; });
                row.querySelector('[data-move="up"]').disabled = index === 0;
                row.querySelector('[data-move="down"]').disabled = index === rows.length - 1;
            });
        };
        root.querySelector('[data-add-product]').addEventListener('click', () => { list.append(template.content.cloneNode(true)); update(); list.lastElementChild.querySelector('input').focus(); });
        root.addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove],[data-move]');
            const row = event.target.closest('[data-product-row]');
            if (!button || !row) return;
            if (button.hasAttribute('data-remove')) row.remove();
            if (button.dataset.move === 'up' && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
            if (button.dataset.move === 'down' && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
            update();
        });
        update();
    })();
    </script>
    <?php
}

function cyber_services_register_homepage_services_setting(): void
{
    register_setting('cyber_services_homepage_services', 'cyber_services_homepage_services', [
        'type' => 'array',
        'sanitize_callback' => 'cyber_services_sanitize_services',
        'default' => cyber_services_default_services(),
    ]);
}
add_action('admin_init', 'cyber_services_register_homepage_services_setting');

function cyber_services_homepage_services_menu(): void
{
    add_options_page(
        __('Homepage Services', 'cyber-services'),
        __('Homepage Services', 'cyber-services'),
        'manage_options',
        'cyber-services-homepage-services',
        'cyber_services_homepage_services_page'
    );
}
add_action('admin_menu', 'cyber_services_homepage_services_menu');

function cyber_services_homepage_services_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $saved = get_option('cyber_services_homepage_services', null);
    $services = $saved === null ? cyber_services_default_services() : cyber_services_sanitize_services($saved);
    ?>
    <div class="wrap" data-cyber-services-settings>
        <h1><?php esc_html_e('Homepage Services', 'cyber-services'); ?></h1>
        <p><?php esc_html_e('Add, remove, reorder, and edit the service cards shown on the homepage.', 'cyber-services'); ?></p>
        <form action="options.php" method="post">
            <?php settings_fields('cyber_services_homepage_services'); ?>
            <input type="hidden" name="cyber_services_homepage_services[]" value="">
            <div data-service-list>
                <?php foreach ($services as $index => [$badge, $title, $description, $url, $timeline, $investment]) : ?>
                    <fieldset class="cyber-service-setting" data-service-row>
                        <legend><?php echo esc_html(sprintf(__('Service card %d', 'cyber-services'), $index + 1)); ?></legend>
                        <label><?php esc_html_e('Badge', 'cyber-services'); ?><input type="text" name="cyber_services_homepage_services[<?php echo esc_attr((string) $index); ?>][badge]" value="<?php echo esc_attr($badge); ?>" maxlength="20" data-field="badge"></label>
                        <label><?php esc_html_e('Title', 'cyber-services'); ?><input type="text" name="cyber_services_homepage_services[<?php echo esc_attr((string) $index); ?>][title]" value="<?php echo esc_attr($title); ?>" data-field="title" required></label>
                        <label><?php esc_html_e('Description', 'cyber-services'); ?><textarea name="cyber_services_homepage_services[<?php echo esc_attr((string) $index); ?>][description]" rows="3" data-field="description" required><?php echo esc_textarea($description); ?></textarea></label>
                        <label><?php esc_html_e('Timeline', 'cyber-services'); ?><input type="text" name="cyber_services_homepage_services[<?php echo esc_attr((string) $index); ?>][timeline]" value="<?php echo esc_attr($timeline); ?>" data-field="timeline"></label>
                        <label><?php esc_html_e('Investment', 'cyber-services'); ?><input type="text" name="cyber_services_homepage_services[<?php echo esc_attr((string) $index); ?>][investment]" value="<?php echo esc_attr($investment); ?>" data-field="investment"></label>
                        <label><?php esc_html_e('Xem thêm URL', 'cyber-services'); ?><input type="url" name="cyber_services_homepage_services[<?php echo esc_attr((string) $index); ?>][url]" value="<?php echo esc_attr($url); ?>" data-field="url" placeholder="<?php echo esc_attr(home_url('/dich-vu/')); ?>"></label>
                        <div class="cyber-service-actions"><button class="button" type="button" data-move="up" aria-label="<?php esc_attr_e('Move card up', 'cyber-services'); ?>">↑</button><button class="button" type="button" data-move="down" aria-label="<?php esc_attr_e('Move card down', 'cyber-services'); ?>">↓</button><button class="button button-link-delete" type="button" data-remove><?php esc_html_e('Delete card', 'cyber-services'); ?></button></div>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <p><button class="button" type="button" data-add-service><?php esc_html_e('Add service card', 'cyber-services'); ?></button></p>
            <?php submit_button(); ?>
        </form>
        <template data-service-template><fieldset class="cyber-service-setting" data-service-row><legend></legend><label><?php esc_html_e('Badge', 'cyber-services'); ?><input type="text" maxlength="20" data-field="badge"></label><label><?php esc_html_e('Title', 'cyber-services'); ?><input type="text" data-field="title" required></label><label><?php esc_html_e('Description', 'cyber-services'); ?><textarea rows="3" data-field="description" required></textarea></label><label><?php esc_html_e('Timeline', 'cyber-services'); ?><input type="text" data-field="timeline" value="Ví dụ: 1-4 tuần"></label><label><?php esc_html_e('Investment', 'cyber-services'); ?><input type="text" data-field="investment" value="Ví dụ: 100$"></label><label><?php esc_html_e('Xem thêm URL', 'cyber-services'); ?><input type="url" data-field="url" placeholder="<?php echo esc_attr(home_url('/dich-vu/')); ?>"></label><div class="cyber-service-actions"><button class="button" type="button" data-move="up" aria-label="<?php esc_attr_e('Move card up', 'cyber-services'); ?>">↑</button><button class="button" type="button" data-move="down" aria-label="<?php esc_attr_e('Move card down', 'cyber-services'); ?>">↓</button><button class="button button-link-delete" type="button" data-remove><?php esc_html_e('Delete card', 'cyber-services'); ?></button></div></fieldset></template>
    </div>
    <style>.cyber-service-setting{max-width:900px;display:grid;grid-template-columns:120px 1fr;gap:12px 18px;margin:18px 0;border:1px solid #c3c4c7;padding:18px;background:#fff}.cyber-service-setting legend{padding:0 6px;font-weight:700}.cyber-service-setting label{display:grid;grid-column:1/-1;grid-template-columns:120px 1fr;align-items:start;gap:18px;font-weight:600}.cyber-service-setting :is(input,textarea){width:100%;font-weight:400}.cyber-service-actions{grid-column:2;display:flex;gap:8px;align-items:center}@media(max-width:782px){.cyber-service-setting,.cyber-service-setting label{grid-template-columns:1fr}.cyber-service-actions{grid-column:1}}</style>
    <script>
    (() => {
        const root = document.querySelector('[data-cyber-services-settings]');
        const list = root.querySelector('[data-service-list]');
        const template = root.querySelector('[data-service-template]');
        const update = () => {
            const rows = [...list.querySelectorAll('[data-service-row]')];
            rows.forEach((row, index) => {
                row.querySelector('legend').textContent = `<?php echo esc_js(__('Service card', 'cyber-services')); ?> ${index + 1}`;
                row.querySelectorAll('[data-field]').forEach((field) => { field.name = `cyber_services_homepage_services[${index}][${field.dataset.field}]`; });
                row.querySelector('[data-move="up"]').disabled = index === 0;
                row.querySelector('[data-move="down"]').disabled = index === rows.length - 1;
            });
        };
        root.querySelector('[data-add-service]').addEventListener('click', () => { list.append(template.content.cloneNode(true)); update(); list.lastElementChild.querySelector('input').focus(); });
        list.addEventListener('click', (event) => {
            const row = event.target.closest('[data-service-row]');
            if (!row) return;
            if (event.target.closest('[data-remove]')) row.remove();
            else if (event.target.closest('[data-move="up"]') && row.previousElementSibling) row.previousElementSibling.before(row);
            else if (event.target.closest('[data-move="down"]') && row.nextElementSibling) row.nextElementSibling.after(row);
            else return;
            update();
        });
        update();
    })();
    </script>
    <?php
}

function cyber_services_process(): array
{
    return [
        ['01', 'Đánh giá hiện trạng', 'Rà soát hạ tầng, ứng dụng, quy trình vận hành và tài liệu chính sách; xác định khoảng trống so với PCI DSS, SOC 2, ISO 27001 và quy định pháp luật.'],
        ['02', 'Thiết kế lộ trình', 'Xây dựng lộ trình theo từng giai đoạn: việc có thể hoàn thành nhanh, hạng mục ưu tiên, ngân sách dự kiến và mốc thời gian rõ ràng.'],
        ['03', 'Triển khai & khắc phục', 'Đồng hành cùng đội ngũ nội bộ: gia cố hệ thống, phân tách mạng, quản lý nhật ký, xây dựng chính sách và đào tạo nhận thức.'],
        ['04', 'Kiểm định & duy trì', 'Kiểm định độc lập, rà soát định kỳ và tối ưu liên tục; chuẩn bị hồ sơ làm việc với chuyên gia đánh giá, đối tác, ngân hàng và cơ quan quản lý.'],
    ];
}

function cyber_services_social_links(): array
{
    $settings = cyber_services_contact_settings();
    return [
        ['Facebook', $settings['facebook_url']],
        ['YouTube', $settings['youtube_url']],
        ['LinkedIn', $settings['linkedin_url']],
        ['Zalo', $settings['zalo_url']],
        ['Telegram', $settings['telegram_url']],
    ];
}

function cyber_services_contact_details(): array
{
    $settings = cyber_services_contact_settings();
    $phone = (string) $settings['phone'];
    $phone_number = preg_replace('/[^0-9+]/', '', $phone);
    $email = is_email((string) $settings['email']) ? (string) $settings['email'] : (string) cyber_services_contact_defaults()['email'];
    return [
        'phone' => $phone,
        'phone_url' => $phone_number !== '' ? 'tel:' . $phone_number : '',
        'zalo_url' => $settings['zalo_url'],
        'email' => $email,
        'email_url' => 'mailto:' . $email,
        'address' => $settings['address'],
        'hours' => $settings['hours'],
    ];
}

function cyber_services_contact_status(): array
{
    $status = isset($_GET['cyber_contact']) ? sanitize_key(wp_unslash($_GET['cyber_contact'])) : '';
    $messages = [
        'sent' => ['success', 'Cảm ơn bạn. Yêu cầu tư vấn đã được gửi thành công.'],
        'invalid' => ['error', 'Vui lòng kiểm tra và điền đầy đủ thông tin bắt buộc.'],
        'invalid_phone' => ['error', 'Số điện thoại phải gồm đúng 10 chữ số.'],
        'invalid_nonce' => ['error', 'Phiên gửi biểu mẫu đã hết hạn. Vui lòng tải lại trang và thử lại.'],
        'rate_limited' => ['error', 'Bạn đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau 10 phút.'],
        'configuration_error' => ['error', 'Không thể gửi yêu cầu lúc này. Vui lòng liên hệ qua điện thoại.'],
        'mail_error' => ['error', 'Không thể gửi yêu cầu lúc này. Vui lòng thử lại hoặc liên hệ qua điện thoại.'],
    ];
    return $messages[$status] ?? ['idle', ''];
}

/**
 * Read the structured About-page copy from the existing WordPress page body.
 * The legacy editor classes remain the content contract; the template owns
 * all presentation so inline editor styles never leak into the public page.
 */
function cyber_services_about_page_data(WP_Post $page): array
{
    $data = [
        'overview' => [],
        'pillars' => [],
        'values' => [],
        'services' => [],
        'commitment' => '',
    ];
    if (trim($page->post_content) === '' || !class_exists('DOMDocument')) {
        return $data;
    }

    $previous_errors = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><div id="cyber-about-source">' . $page->post_content . '</div>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous_errors);
    if (!$loaded) {
        return $data;
    }

    $xpath = new DOMXPath($document);
    $class = static fn (string $name): string => "contains(concat(' ', normalize-space(@class), ' '), ' " . $name . " ')";
    $text = static function ($node): string {
        $value = html_entity_decode((string) $node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($value)));
    };
    $first_text = static function (string $expression, $context = null) use ($xpath, $text): string {
        $nodes = $xpath->query($expression, $context);
        return $nodes instanceof DOMNodeList && $nodes->length > 0 ? $text($nodes->item(0)) : '';
    };
    $title_text = static function (string $value): string {
        return trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $value));
    };

    $overview_nodes = $xpath->query('//*[' . $class('cyber-text-block') . ']/p');
    if ($overview_nodes instanceof DOMNodeList) {
        foreach ($overview_nodes as $node) {
            $value = $text($node);
            if ($value !== '') {
                $data['overview'][] = $value;
            }
        }
    }

    $pillar_nodes = $xpath->query('//*[' . $class('cyber-vm-card') . ']');
    if ($pillar_nodes instanceof DOMNodeList) {
        foreach ($pillar_nodes as $node) {
            $title = $title_text($first_text('.//h2 | .//h3 | .//h4', $node));
            $description = $first_text('.//p', $node);
            if ($title !== '' && $description !== '') {
                $data['pillars'][] = [$title, $description];
            }
        }
    }

    $value_nodes = $xpath->query('//*[' . $class('cyber-core-box') . ']');
    if ($value_nodes instanceof DOMNodeList) {
        foreach ($value_nodes as $index => $node) {
            $title = $title_text($first_text('.//h2 | .//h3 | .//h4', $node));
            $description = $first_text('.//p', $node);
            if ($title !== '' && $description !== '') {
                $data['values'][] = [str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), $title, $description];
            }
        }
    }

    $service_nodes = $xpath->query('//*[' . $class('cyber-cap-item') . ']');
    if ($service_nodes instanceof DOMNodeList) {
        foreach ($service_nodes as $index => $node) {
            $title = $title_text($first_text('.//h2 | .//h3 | .//h4', $node));
            $description = $first_text('.//p', $node);
            if ($title !== '' && $description !== '') {
                $data['services'][] = [str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), $title, $description];
            }
        }
    }

    $commitment = $first_text('//*[' . $class('cyber-commitment') . ']');
    $commitment = (string) preg_replace('/^Cam kết từ Cyber Services Việt Nam:\s*/ui', '', $commitment);
    $data['commitment'] = trim($commitment, " \t\n\r\0\x0B\"“”");

    return $data;
}

function cyber_services_page_images(WP_Post $page, int $limit = PHP_INT_MAX): array
{
    $images = [];
    $featured_id = get_post_thumbnail_id($page);
    if ($featured_id > 0) {
        $source = wp_get_attachment_image_src($featured_id, 'large');
        if (is_array($source)) {
            $images[$source[0]] = [
                'src' => $source[0],
                'alt' => trim((string) get_post_meta($featured_id, '_wp_attachment_image_alt', true)) ?: get_the_title($page),
            ];
        }
    }

    if (preg_match_all('/<img\b[^>]*\bsrc=(["\'])(.*?)\1[^>]*>/is', $page->post_content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $src = esc_url_raw(html_entity_decode($match[2], ENT_QUOTES, get_bloginfo('charset')));
            if ($src === '' || isset($images[$src])) {
                continue;
            }
            $alt = '';
            if (preg_match('/\balt=(["\'])(.*?)\1/is', $match[0], $alt_match)) {
                $alt = sanitize_text_field(html_entity_decode($alt_match[2], ENT_QUOTES, get_bloginfo('charset')));
            }
            $images[$src] = ['src' => $src, 'alt' => $alt !== '' ? $alt : get_the_title($page)];
            if (count($images) >= $limit) {
                break;
            }
        }
    }

    return array_slice(array_values($images), 0, $limit);
}

function cyber_services_disable_emoji_assets(): void
{
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
}
add_action('init', 'cyber_services_disable_emoji_assets');

function cyber_services_asset(string $path): string
{
    return esc_url(get_theme_file_uri('assets/' . ltrim($path, '/')));
}

function cyber_services_brand_markup(): string
{
    $custom_logo_id = (int) get_theme_mod('custom_logo');
    $custom_logo = $custom_logo_id > 0
        ? wp_get_attachment_image($custom_logo_id, 'full', false, ['class' => 'custom-logo', 'alt' => '', 'loading' => 'eager'])
        : '';

    if ($custom_logo !== '') {
        return $custom_logo;
    }

    return '<img src="' . cyber_services_asset('images/logo.png') . '" alt="" width="220" height="268"><span><strong>CYBER SERVICES</strong><small>VIỆT NAM</small></span>';
}

function cyber_services_footer_logo_markup(): string
{
    $footer_logo = get_theme_mod('cyber_services_footer_logo', '');
    $footer_logo_url = is_string($footer_logo) ? trim($footer_logo) : '';

    if (is_numeric($footer_logo) && (int) $footer_logo > 0) {
        $footer_logo_url = (string) (wp_get_attachment_image_url((int) $footer_logo, 'full') ?: '');
    }

    if ($footer_logo_url !== '') {
        return '<img class="footerLogo footerLogoCustom" src="' . esc_url($footer_logo_url) . '" alt="" loading="lazy">';
    }

    return '<img class="footerLogo" src="' . cyber_services_asset('images/logo.png') . '" alt="" width="220" height="268" loading="lazy">';
}

function cyber_services_icon(string $name): string
{
    $paths = [
        'Facebook' => '<path d="M15.8 8.5h-2.4V6.9c0-.6.4-.8.8-.8h1.6V3.6h-2.2c-2.5 0-3.7 1.5-3.7 3.8v1.1H8v2.8h1.9V20h3.5v-8.7h2.1l.3-2.8Z"/>',
        'YouTube' => '<path d="M20.5 7.1a2.8 2.8 0 0 0-2-2C16.8 4.6 12 4.6 12 4.6s-4.8 0-6.5.5a2.8 2.8 0 0 0-2 2A29 29 0 0 0 3 12a29 29 0 0 0 .5 4.9 2.8 2.8 0 0 0 2 2c1.7.5 6.5.5 6.5.5s4.8 0 6.5-.5a2.8 2.8 0 0 0 2-2A29 29 0 0 0 21 12a29 29 0 0 0-.5-4.9ZM10.2 15.2V8.8l5.5 3.2-5.5 3.2Z"/>',
        'LinkedIn' => '<path d="M6.5 8.2H3.4V20h3.1V8.2ZM5 3A1.8 1.8 0 1 0 5 6.6 1.8 1.8 0 0 0 5 3Zm8.2 5.2h-3V20h3.1v-5.8c0-1.5.3-3 2.2-3 1.8 0 1.9 1.7 1.9 3.1V20h3.1v-6.4c0-3.2-.7-5.7-4.5-5.7-1.8 0-3 1-3.5 1.8h-.1V8.2h-3Z"/>',
        'Zalo' => '<path d="M4 4h16v16H4z" fill="none"/><path d="M6.1 7.1h6v1.6l-3.8 4.7h3.9V15H6v-1.6l3.8-4.7H6.1V7.1Zm8.1 2.3c2.3 0 3.8 1.1 3.8 2.9S16.5 15 14.2 15c-.6 0-1.1-.1-1.6-.2v-1.5c.4.1.9.2 1.3.2 1.2 0 1.9-.5 1.9-1.2 0-.8-.7-1.3-1.9-1.3h-1V9.5c.4-.1.8-.1 1.3-.1Z"/>',
        'Telegram' => '<path d="m21 4.5-3.1 14.6c-.2 1-1 1.2-1.8.8l-4.7-3.5-2.3 2.2c-.2.3-.5.5-1 .5l.4-4.8 8.7-7.9c.4-.3-.1-.5-.6-.2L5.9 13l-4.6-1.5c-1-.3-1-1 .2-1.5l18-6.9c.8-.3 1.6.2 1.5 1.4Z"/>',
        'Phone' => '<path d="M7 3h3l1.4 4-1.9 1.9a15.3 15.3 0 0 0 5.6 5.6l1.9-1.9L21 14v3a4 4 0 0 1-4 4A14 14 0 0 1 3 7a4 4 0 0 1 4-4Z"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['Phone']) . '</svg>';
}

function cyber_services_document_title(string $title): string
{
    return is_front_page() ? 'Cyber Services Việt Nam — Tuân thủ · An toàn · Vững tin' : $title;
}
add_filter('pre_get_document_title', 'cyber_services_document_title');

function cyber_services_legacy_redirects(): void
{
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    $request_path = trim((string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
    $home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($home_path !== '' && strpos($request_path, $home_path . '/') === 0) {
        $request_path = substr($request_path, strlen($home_path) + 1);
    }

    $redirects = [
        've-chung-toi' => '/gioi-thieu/',
        'admin' => '/wp-admin/',
    ];
    if (!isset($redirects[$request_path])) {
        return;
    }

    wp_safe_redirect(home_url($redirects[$request_path]), 301);
    exit;
}
add_action('template_redirect', 'cyber_services_legacy_redirects', 1);

function cyber_services_front_page_metadata(): void
{
    if (!is_front_page()) {
        return;
    }

    $name = 'Cyber Services Việt Nam';
    $description = 'Tư vấn, đánh giá và chứng nhận tuân thủ an ninh mạng cho doanh nghiệp tại Việt Nam.';
    $url = home_url('/');
    $social_preview = cyber_services_asset('images/logo.png');
    $contact = cyber_services_contact_details();
    $social_links = array_column(cyber_services_social_links(), 1);
    $schema = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Organization',
                '@id' => trailingslashit($url) . '#organization',
                'name' => $name,
                'url' => $url,
                'email' => $contact['email'],
                'telephone' => $contact['phone'],
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $contact['address'],
                    'addressCountry' => 'VN',
                ],
                'sameAs' => $social_links,
            ],
            [
                '@type' => 'WebSite',
                '@id' => trailingslashit($url) . '#website',
                'url' => $url,
                'name' => $name,
                'inLanguage' => 'vi',
                'publisher' => ['@id' => trailingslashit($url) . '#organization'],
            ],
        ],
    ];
    ?>
    <meta name="description" content="<?php echo esc_attr($description); ?>">
    <link rel="canonical" href="<?php echo esc_url($url); ?>">
    <meta name="theme-color" content="#0b0c0e">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="vi_VN">
    <meta property="og:url" content="<?php echo esc_url($url); ?>">
    <meta property="og:site_name" content="<?php echo esc_attr($name); ?>">
    <meta property="og:title" content="<?php echo esc_attr($name); ?>">
    <meta property="og:description" content="<?php echo esc_attr($description); ?>">
    <meta property="og:image" content="<?php echo esc_url($social_preview); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo esc_attr($name); ?>">
    <meta name="twitter:description" content="<?php echo esc_attr($description); ?>">
    <meta name="twitter:image" content="<?php echo esc_url($social_preview); ?>">
    <script type="application/ld+json"><?php echo wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
    <?php
}
add_action('wp_head', 'cyber_services_front_page_metadata', 2);

function cyber_services_contact_nonce(): void
{
    nocache_headers();
    wp_send_json_success(['nonce' => wp_create_nonce('cyber_contact_form')]);
}
add_action('wp_ajax_nopriv_cyber_contact_nonce', 'cyber_services_contact_nonce');
add_action('wp_ajax_cyber_contact_nonce', 'cyber_services_contact_nonce');

function cyber_services_contact_response(bool $success, string $code): void
{
    $requested_with = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REQUESTED_WITH'])))
        : '';
    $accepted_types = isset($_SERVER['HTTP_ACCEPT'])
        ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'])))
        : '';
    $expects_json = $requested_with === 'xmlhttprequest' || strpos($accepted_types, 'application/json') !== false;
    if ($expects_json) {
        $messages = [
            'sent' => __('Cảm ơn bạn. Yêu cầu tư vấn đã được gửi thành công.', 'cyber-services'),
            'invalid' => __('Vui lòng kiểm tra và điền đầy đủ thông tin bắt buộc.', 'cyber-services'),
            'invalid_phone' => __('Số điện thoại phải gồm đúng 10 chữ số.', 'cyber-services'),
            'invalid_nonce' => __('Phiên gửi biểu mẫu đã hết hạn. Vui lòng tải lại trang và thử lại.', 'cyber-services'),
            'rate_limited' => __('Bạn đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau 10 phút.', 'cyber-services'),
            'configuration_error' => __('Email nhận yêu cầu chưa được cấu hình hợp lệ.', 'cyber-services'),
            'mail_error' => __('Không thể gửi email lúc này. Vui lòng thử lại hoặc liên hệ qua điện thoại.', 'cyber-services'),
        ];
        $message = $messages[$code] ?? __('Không thể gửi yêu cầu lúc này. Vui lòng thử lại hoặc liên hệ qua điện thoại.', 'cyber-services');
        $status_code = $code === 'rate_limited' ? 429 : 422;
        $success ? wp_send_json_success(['message' => $message, 'code' => $code]) : wp_send_json_error(['message' => $message, 'code' => $code], $status_code);
    }

    $default_return = home_url('/#lien-he');
    $requested_return = isset($_POST['cyber_contact_return']) ? esc_url_raw(wp_unslash($_POST['cyber_contact_return'])) : $default_return;
    $return_url = wp_validate_redirect($requested_return, $default_return);
    $fragment = (string) wp_parse_url($return_url, PHP_URL_FRAGMENT);
    $return_url = remove_query_arg('cyber_contact', $return_url);
    $return_url = add_query_arg('cyber_contact', $code, strtok($return_url, '#'));
    wp_safe_redirect($return_url . ($fragment !== '' ? '#' . sanitize_title($fragment) : ''));
    exit;
}

function cyber_services_contact_rate_limit_reached(): bool
{
    $client_ip = '';
    foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $server_key) {
        $candidate = isset($_SERVER[$server_key]) ? sanitize_text_field(wp_unslash($_SERVER[$server_key])) : '';
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $client_ip = $candidate;
            break;
        }
    }
    if ($client_ip === '') {
        return false;
    }

    $key = 'cyber_contact_rate_' . hash_hmac('sha256', $client_ip, wp_salt('nonce'));
    $attempts = (int) get_transient($key);
    if ($attempts >= 3) {
        return true;
    }

    set_transient($key, $attempts + 1, 10 * MINUTE_IN_SECONDS);
    return false;
}

function cyber_services_handle_contact_form(): void
{
    $nonce = isset($_POST['cyber_contact_nonce']) ? sanitize_text_field(wp_unslash($_POST['cyber_contact_nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'cyber_contact_form')) {
        cyber_services_contact_response(false, 'invalid_nonce');
    }

    $honeypot = isset($_POST['company_website']) ? trim((string) wp_unslash($_POST['company_website'])) : '';
    if ($honeypot !== '') {
        cyber_services_contact_response(true, 'sent');
    }

    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
    $company = isset($_POST['company']) ? sanitize_text_field(wp_unslash($_POST['company'])) : '';
    $service = isset($_POST['service']) ? sanitize_text_field(wp_unslash($_POST['service'])) : '';
    $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

    if ($name === '' || !is_email($email) || $phone === '' || $service === '' || $message === '') {
        cyber_services_contact_response(false, 'invalid');
    }
    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        cyber_services_contact_response(false, 'invalid_phone');
    }

    $smtp_settings = cyber_services_smtp_settings();
    $default_recipient = is_email($smtp_settings['recipient_email']) ? $smtp_settings['recipient_email'] : get_option('admin_email');
    $recipient = (string) apply_filters('cyber_services_contact_recipient', $default_recipient);
    if (!is_email($recipient)) {
        cyber_services_contact_response(false, 'configuration_error');
    }
    if (cyber_services_contact_rate_limit_reached()) {
        cyber_services_contact_response(false, 'rate_limited');
    }

    $subject = sprintf(__('Yêu cầu tư vấn từ %s', 'cyber-services'), $name);
    $body = implode("\n\n", [
        sprintf(__('Họ và tên: %s', 'cyber-services'), $name),
        sprintf(__('Email: %s', 'cyber-services'), $email),
        sprintf(__('Số điện thoại: %s', 'cyber-services'), $phone),
        sprintf(__('Doanh nghiệp: %s', 'cyber-services'), $company !== '' ? $company : __('Không cung cấp', 'cyber-services')),
        sprintf(__('Dịch vụ: %s', 'cyber-services'), $service),
        sprintf(__("Nội dung:\n%s", 'cyber-services'), $message),
    ]);
    $headers = ['Content-Type: text/plain; charset=UTF-8', sprintf('Reply-To: %s <%s>', $name, $email)];

    $sent = wp_mail($recipient, $subject, $body, $headers);
    cyber_services_contact_response($sent, $sent ? 'sent' : 'mail_error');
}
add_action('admin_post_nopriv_cyber_contact', 'cyber_services_handle_contact_form');
add_action('admin_post_cyber_contact', 'cyber_services_handle_contact_form');
