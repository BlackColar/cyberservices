<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function sv_premium_get_theme_slug( $theme_data ) {
    if ( isset( $theme_data['slug'] ) && '' !== trim( (string) $theme_data['slug'] ) ) {
        return sanitize_key( $theme_data['slug'] );
    }

    $zip_url = isset( $theme_data['zip_url'] ) ? (string) $theme_data['zip_url'] : '';
    $path    = wp_parse_url( $zip_url, PHP_URL_PATH );
    $slug    = $path ? basename( $path, '.zip' ) : '';

    return sanitize_key( $slug );
}

function sv_premium_is_allowed_zip_url( $zip_url ) {
    $parts = wp_parse_url( $zip_url );
    if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
        return false;
    }

    return 'https' === strtolower( $parts['scheme'] )
        && 'theme.trungtq.io.vn' === strtolower( $parts['host'] )
        && '.zip' === strtolower( substr( $parts['path'], -4 ) );
}

function sv_premium_find_theme( $theme_data ) {
    $theme_slug = sv_premium_get_theme_slug( $theme_data );
    if ( '' !== $theme_slug ) {
        $theme = wp_get_theme( $theme_slug );
        if ( $theme->exists() ) {
            return $theme;
        }
    }

    $theme_name = isset( $theme_data['name'] ) ? trim( (string) $theme_data['name'] ) : '';
    if ( '' !== $theme_name ) {
        foreach ( wp_get_themes() as $theme ) {
            if ( 0 === strcasecmp( $theme->get( 'Name' ), $theme_name ) ) {
                return $theme;
            }
        }
    }

    return wp_get_theme( '' !== $theme_slug ? $theme_slug : 'sitevorx-theme-not-found' );
}

// ==========================================================================
// PREMIUM PAGE — Combined MyThemeShop + Rank Math SEO Pro
// ==========================================================================

function sv_display_premium_page() {
    $active_tab = isset($_GET['tab']) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'themes';
    if ( ! in_array( $active_tab, array( 'themes', 'rankmath' ), true ) ) {
        $active_tab = 'themes';
    }
    ?>
    <div id="sv-loading-overlay" class="sv-loading-overlay">
        <span class="dashicons dashicons-update sv-loading-spinner"></span>
        <h3 class="sv-loading-text"><?php esc_html_e('Hệ thống đang xử lý... Vui lòng không đóng trang!', 'sitevorx'); ?></h3>
    </div>

    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar('premium'); ?>
            <div class="sv-content-area">

                <div class="sv-top-banner">
                    <h2><?php esc_html_e('Premium', 'sitevorx'); ?></h2>
                    <p><?php esc_html_e('Kho giao diện MyThemeShop bản quyền và Rank Math SEO Pro dành riêng cho khách hàng iNET.', 'sitevorx'); ?></p>
                </div>

                <!-- Tab Navigation -->
                <div class="sv-tab-nav" style="margin-bottom:20px;">
                    <a href="?page=sv-premium&tab=themes" class="sv-tab-link <?php echo $active_tab === 'themes' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e('Kho Giao Diện', 'sitevorx'); ?>
                    </a>
                    <a href="?page=sv-premium&tab=rankmath" class="sv-tab-link <?php echo $active_tab === 'rankmath' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-chart-pie"></span> <?php esc_html_e('Rank Math SEO Pro', 'sitevorx'); ?>
                    </a>
                </div>

                <?php
                if ( $active_tab === 'themes' ) {
                    sv_render_premium_themes_tab();
                } else {
                    sv_render_premium_rankmath_tab();
                }
                ?>

            </div>
        </div>
    </div>
    <?php
}

// ==========================================================================
// TAB: MyThemeShop Themes
// ==========================================================================
function sv_render_premium_themes_tab() {
    if ( ! sv_is_inet_hosting() ) {
        ?>
        <div class="sv-locked-container">
            <div class="sv-content-box sv-locked-item">
                <div class="sv-box-header"><span class="dashicons dashicons-lock" style="color:#8b5cf6;"></span><h3><?php esc_html_e('Kho Giao Diện MyThemeShop', 'sitevorx'); ?></h3></div>
                <p style="color:#64748b; margin:0;"><?php esc_html_e('Danh sách theme Premium chỉ được tải và hiển thị sau khi Sitevorx Pro xác minh website đang chạy trong hệ sinh thái iNET.', 'sitevorx'); ?></p>
            </div>
            <div class="sv-locked-overlay">
                <span><span class="dashicons dashicons-lock"></span> <?php esc_html_e('Độc Quyền iNET', 'sitevorx'); ?></span>
            </div>
        </div>
        <?php
        return;
    }

    $mts_themes = get_transient('sv_premium_themes_list');
    
    if (false === $mts_themes || empty($mts_themes)) {
        $api_url = 'https://theme.trungtq.io.vn/themes.json';
        $response = wp_remote_get($api_url, array(
            'timeout'    => 15,
            'user-agent' => 'Sitevorx-Pro/' . SV_PLUGIN_VERSION,
        ));
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            $parsed = json_decode($body, true);
            if (is_array($parsed) && !empty($parsed)) {
                $mts_themes = $parsed;
                set_transient('sv_premium_themes_list', $mts_themes, 12 * HOUR_IN_SECONDS);
            }
        }
    }

    if (empty($mts_themes)) {
        $fallback_json = '[
            {"name": "Schema", "image": "https://theme.trungtq.io.vn/mythemeshop-data/mts_schema.png", "version": "Mới nhất", "author": "MyThemeShop", "zip_url": "https://theme.trungtq.io.vn/mythemeshop-data/mts_schema.zip"}
        ]';
        $mts_themes = json_decode($fallback_json, true);
    }

    $active_theme = wp_get_theme();
    $active_stylesheet = $active_theme->get_stylesheet();

    // === INSTALL THEME ===
    if ( isset($_GET['install_theme']) && !empty($mts_themes) && current_user_can('install_themes') && isset($_GET['_wpnonce']) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sv_install_theme') && sv_is_inet_hosting() ) {
        $theme_index = intval($_GET['install_theme']);
        if (isset($mts_themes[$theme_index])) {
            $theme_data = $mts_themes[$theme_index];
            $zip_url = isset($theme_data['zip_url']) ? $theme_data['zip_url'] : '';
            if ( empty( $zip_url ) ) {
                echo '<div class="notice notice-error is-dismissible sv-notice"><p>' . esc_html__( 'Dữ liệu giao diện không hợp lệ (thiếu zip_url).', 'sitevorx' ) . '</p></div>';
                return;
            }
            if ( ! sv_premium_is_allowed_zip_url( $zip_url ) ) {
                echo '<div class="notice notice-error is-dismissible sv-notice"><p>' . esc_html__( 'Invalid theme data: zip_url is not allowed.', 'sitevorx' ) . '</p></div>';
                return;
            }
            $theme_slug = sv_premium_get_theme_slug( $theme_data );
            if ( '' === $theme_slug ) {
                echo '<div class="notice notice-error is-dismissible sv-notice"><p>' . esc_html__( 'Invalid theme data: theme slug could not be detected.', 'sitevorx' ) . '</p></div>';
                return;
            }
            $theme_name_raw = isset($theme_data['name']) ? (string) $theme_data['name'] : $theme_slug;
            $theme_name_clean = trim(preg_replace('/[\s\t\n\r\0\x0B\pZ]+/u', ' ', $theme_name_raw));

            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/misc.php');
            require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
            if ( ! WP_Filesystem() ) {
                echo '<div class="notice notice-error is-dismissible sv-notice"><p>' . esc_html__( 'WordPress could not initialize the filesystem for theme installation.', 'sitevorx' ) . '</p></div>';
                return;
            }

            $inject_auth_header = function($args, $url) {
                if (strpos($url, '.zip') !== false) {
                    $args['user-agent'] = 'iNET-Premium-Downloader-V1';
                }
                return $args;
            };
            add_filter('http_request_args', $inject_auth_header, 10, 2);
            $tmp_file = download_url($zip_url);
            remove_filter('http_request_args', $inject_auth_header, 10);

            if ( is_wp_error($tmp_file) ) {
                echo '<div class="notice notice-error is-dismissible sv-notice" style="display:block;"><p><strong>' . esc_html__('Cài đặt thất bại!', 'sitevorx') . '</strong><br><span style="display:block; margin-top:5px; color:#646970;">' . sprintf(esc_html__('Máy chủ từ chối cấp phát giao diện. (Chi tiết: %s)', 'sitevorx'), esc_html($tmp_file->get_error_message())) . '</span></p></div>';
            } else {
                $theme_dir = get_theme_root();
                $unzip_result = unzip_file($tmp_file, $theme_dir);
                if ( file_exists( $tmp_file ) ) {
                    wp_delete_file( $tmp_file );
                }

                if ( is_wp_error($unzip_result) ) {
                    echo '<div class="notice notice-error is-dismissible sv-notice"><p>' . sprintf(esc_html__('Lỗi giải nén: %s', 'sitevorx'), esc_html($unzip_result->get_error_message())) . '</p></div>';
                } else {
                    $activated_ok = false;
                    $new_theme = sv_premium_find_theme($theme_data);
                    if ($new_theme->exists()) {
                        switch_theme($new_theme->get_stylesheet());
                        $activated_ok = true;
                        $active_stylesheet = $new_theme->get_stylesheet();
                    }

                    // AUTO-LICENSE MTS
                    $license_ok = false;
                    $key_url = sv_get_premium_api_url( 'get-mts-key.php', 'mts_license', 'inet_premium_' );
                    $key_response = wp_remote_get($key_url, array(
                        'timeout'    => 10,
                        'user-agent' => 'iNET-Toolkit-Secure-Agent-V1',
                    ));

                    if (!is_wp_error($key_response) && wp_remote_retrieve_response_code($key_response) === 200) {
                        $key_body = json_decode(wp_remote_retrieve_body($key_response), true);
                        if (isset($key_body['status']) && $key_body['status'] === 'success' && !empty($key_body['api_key'])) {
                            $api_key = sanitize_text_field($key_body['api_key']);
                            $connect_data = sv_premium_build_connect_data( $api_key, $key_body );
                            if ( function_exists( 'sv_mts_inject_license_to_options' ) ) {
                                sv_mts_inject_license_to_options( $connect_data );
                            } else {
                                update_site_option('mts_connect_data', $connect_data);
                                update_site_option('mts_theme_connected', 1);
                                update_option('mts_connect_data', $connect_data);
                                update_option('mts_theme_connected', 1);
                            }
                            delete_transient('sv_mts_api_data');
                            set_transient('sv_mts_api_data', $connect_data, 12 * HOUR_IN_SECONDS);
                            $license_ok = true;
                        }
                    }

                    if ($activated_ok && $license_ok) {
                        echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . sprintf(__('Cài đặt và %1$skích hoạt tự động%2$s giao diện %3$s%4$s%5$s thành công! Bản quyền MTS đã được kích hoạt.', 'sitevorx'), '<strong>', '</strong>', '<strong>', esc_html($theme_name_clean), '</strong>') . '</p></div>';
                    } elseif ($activated_ok) {
                        echo '<div class="notice notice-warning is-dismissible sv-notice"><p>' . sprintf(__('Cài đặt và kích hoạt giao diện %1$s%2$s%3$s thành công, nhưng %4$schưa kích hoạt được bản quyền MTS%5$s. Vui lòng tải lại trang.', 'sitevorx'), '<strong>', esc_html($theme_name_clean), '</strong>', '<strong>', '</strong>') . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error is-dismissible sv-notice"><p>' . sprintf(esc_html__('Theme package was extracted, but WordPress could not detect a valid theme for %s.', 'sitevorx'), '<strong>' . esc_html($theme_name_clean) . '</strong>') . '</p></div>';
                    }
                }
            }
        }
    }

    // Refresh cache
    if (isset($_POST['sv_refresh_theme_cache']) && current_user_can('manage_options') && check_admin_referer('sv_refresh_cache_nonce')) {
        delete_transient('sv_premium_themes_list');
        echo '<script>window.location.href="' . esc_url(admin_url('admin.php?page=sv-premium&tab=themes')) . '";</script>';
    }

    // Activate theme
    if (isset($_GET['activate_theme']) && current_user_can('switch_themes') && isset($_GET['_wpnonce']) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sv_activate_theme')) {
        $slug_to_activate = sanitize_text_field( wp_unslash( $_GET['activate_theme'] ) );
        $theme_to_activate = wp_get_theme($slug_to_activate);
        if ($theme_to_activate->exists()) {
            switch_theme($theme_to_activate->get_stylesheet());
            $active_stylesheet = $theme_to_activate->get_stylesheet();
            echo '<div class="notice notice-success is-dismissible sv-notice"><p>' . sprintf(__('Đã kích hoạt giao diện %1$s%2$s%3$s!', 'sitevorx'), '<strong>', esc_html($theme_to_activate->get('Name')), '</strong>') . '</p></div>';
        }
    }

    $total = count($mts_themes);
    $installed_count = 0;
    foreach ($mts_themes as $d) {
        if (sv_premium_find_theme($d)->exists()) $installed_count++;
    }
    ?>

    <div class="sv-locked-container">
        <div class="<?php echo !sv_is_inet_hosting() ? 'sv-locked-item' : ''; ?>">

        <!-- TOOLBAR -->
        <div class="sv-theme-toolbar">
            <div class="sv-toolbar-left">
                <div class="sv-theme-counter">
                    <span class="dashicons dashicons-admin-appearance sv-icon-purple"></span>
                    <?php echo sprintf(esc_html__('Tổng: %s theme', 'sitevorx'), '<span id="sv-theme-count">' . $total . '</span>'); ?>
                    <small class="sv-theme-installed-count">(<?php echo sprintf(esc_html__('Đã cài: %d', 'sitevorx'), $installed_count); ?>)</small>
                </div>
                <form method="POST" style="margin:0;"><?php wp_nonce_field('sv_refresh_cache_nonce'); ?><button type="submit" name="sv_refresh_theme_cache" class="sv-btn-refresh"><?php esc_html_e('Làm mới', 'sitevorx'); ?></button></form>
            </div>
            <div class="sv-toolbar-right">
                <div class="sv-theme-filters">
                    <button type="button" class="sv-filter-btn active" data-filter="all"><?php esc_html_e('Tất cả', 'sitevorx'); ?></button>
                    <button type="button" class="sv-filter-btn" data-filter="not-installed"><?php esc_html_e('Chưa cài', 'sitevorx'); ?></button>
                    <button type="button" class="sv-filter-btn" data-filter="installed"><?php esc_html_e('Đã cài', 'sitevorx'); ?></button>
                </div>
                <input type="text" id="soThemeSearch" class="sv-theme-search" placeholder="<?php esc_attr_e('Tìm theme...', 'sitevorx'); ?>">
            </div>
        </div>
        
        <div class="sv-theme-grid" id="soThemeGrid">
            <?php foreach ($mts_themes as $index => $data): 
                $zip_url = isset($data['zip_url']) ? $data['zip_url'] : '';
                if ( empty( $zip_url ) ) continue;
                $theme_slug = sv_premium_get_theme_slug($data);
                if ( '' === $theme_slug ) continue;
                $theme_obj = sv_premium_find_theme($data);
                $is_installed = $theme_obj->exists();
                $theme_stylesheet = $is_installed ? $theme_obj->get_stylesheet() : $theme_slug;
                $is_active = ($theme_stylesheet === $active_stylesheet);
                $name_raw = isset($data['name']) ? (string) $data['name'] : $theme_slug;
                $clean_display_name = trim(preg_replace('/[\s\t\n\r\0\x0B\pZ]+/u', ' ', $name_raw));
                $image_url = isset($data['image']) ? $data['image'] : '';
                $author_txt = isset($data['author']) ? trim( (string) $data['author'] ) : '';
                $version_txt = isset($data['version']) ? trim( (string) $data['version'] ) : '';
            ?>
            <div class="sv-theme-card theme-item" data-installed="<?php echo $is_installed ? '1' : '0'; ?>">
                <div class="sv-theme-thumb">
                    <img src="<?php echo esc_url($image_url); ?>" onerror="this.src='https://placehold.co/400x250/1a1a2e/e94560?text=<?php echo urlencode($clean_display_name); ?>';" alt="<?php echo esc_attr($clean_display_name); ?>">
                    <?php if ($is_active) : ?>
                        <span class="sv-theme-status-badge sv-badge-active"><?php esc_html_e('ĐANG DÙNG', 'sitevorx'); ?></span>
                    <?php elseif ($is_installed) : ?>
                        <span class="sv-theme-status-badge sv-badge-installed"><?php esc_html_e('ĐÃ CÀI', 'sitevorx'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="sv-theme-info">
                    <div class="theme-name"><?php echo esc_html($clean_display_name); ?></div>
                    <div class="sv-theme-meta">
                        <?php echo esc_html(sprintf(__('Tác giả: %s', 'sitevorx'), $author_txt)); ?> &bull;
                        <?php echo esc_html($version_txt); ?>
                    </div>
                    
                    <?php if($is_active): ?>
                        <span class="sv-theme-btn-installed" style="background:#f4e9f7; color:#8e44ad;"><?php esc_html_e('Theme đang sử dụng', 'sitevorx'); ?></span>
                    <?php elseif($is_installed): ?>
                        <div class="sv-theme-actions">
                            <a href="<?php echo esc_url(wp_nonce_url('?page=sv-premium&tab=themes&activate_theme=' . urlencode($theme_stylesheet), 'sv_activate_theme')); ?>" class="button sv-theme-btn-activate" style="flex:1; text-align:center;"><?php esc_html_e('Kích hoạt', 'sitevorx'); ?></a>
                        </div>
                    <?php else: ?>
                        <?php if ( sv_is_inet_hosting() ) : ?>
                        <a href="<?php echo esc_url(wp_nonce_url('?page=sv-premium&tab=themes&install_theme=' . $index, 'sv_install_theme')); ?>" class="button sv-theme-btn-install" style="width:100%; display:block; text-align:center; padding: 6px 0; font-size: 13px;" onclick="document.getElementById('sv-loading-overlay').style.display='flex';"><?php esc_html_e('Cài đặt ngay', 'sitevorx'); ?></a>
                        <?php else : ?>
                        <span class="button" style="width:100%; display:block; text-align:center; padding:6px 0; font-size:13px; opacity:0.5; cursor:not-allowed;" title="<?php esc_attr_e('Chỉ khả dụng trong hệ sinh thái iNET', 'sitevorx'); ?>"><?php esc_html_e('Yêu cầu môi trường iNET', 'sitevorx'); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div id="sv-theme-pagination" class="sv-theme-pagination"></div>

        </div>
        <?php if ( !sv_is_inet_hosting() ) : ?>
        <div class="sv-locked-overlay">
            <span><span class="dashicons dashicons-lock"></span> <?php esc_html_e('Độc Quyền iNET', 'sitevorx'); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// ==========================================================================
// TAB: Rank Math SEO Pro
// ==========================================================================
function sv_render_premium_rankmath_tab() {
    // Notice after install
    if (isset($_GET['rm_installed']) && $_GET['rm_installed'] === 'success') {
        echo '<div style="padding:12px 16px; background:#d4edda; border-left:4px solid #28a745; border-radius:4px; margin-bottom:20px;"><strong style="color:#155724;">' . esc_html__('Cài đặt và kích hoạt Rank Math SEO Pro thành công!', 'sitevorx') . '</strong></div>';
    }
    if (isset($_GET['rm_error'])) {
        echo '<div style="padding:12px 16px; background:#f8d7da; border-left:4px solid #dc3545; border-radius:4px; margin-bottom:20px;"><strong style="color:#721c24;">' . esc_html(urldecode( wp_unslash( $_GET['rm_error'] ) )) . '</strong></div>';
    }
    if (isset($_GET['rm_warning'])) {
        echo '<div style="padding:12px 16px; background:#fff7ed; border-left:4px solid #f59e0b; border-radius:4px; margin-bottom:20px;"><strong style="color:#92400e;">' . esc_html(urldecode( wp_unslash( $_GET['rm_warning'] ) )) . '</strong></div>';
    }

    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $rm_free_active = is_plugin_active('seo-by-rank-math/rank-math.php');
    $rm_pro_active  = is_plugin_active('seo-by-rank-math-pro/rank-math-pro.php');

    // Use Rank Math's own connectivity check when it's available — that
    // method handles the encrypted-blob format introduced in current Rank
    // Math versions, where reading `rank_math_connect_data` directly via
    // get_option returns a ciphertext string that does NOT prove the site
    // is actually connected. Falling back to the raw option is fine for
    // older Rank Math builds.
    if ( class_exists( '\\RankMath\\Helper' ) && method_exists( '\\RankMath\\Helper', 'is_site_connected' ) ) {
        $rm_connected = (bool) \RankMath\Helper::is_site_connected();
    } else {
        $raw          = get_option( 'rank_math_connect_data' );
        $rm_connected = is_array( $raw ) ? ! empty( $raw['connected'] ) : ! empty( $raw );
    }
    $all_done = ( $rm_free_active && $rm_pro_active && $rm_connected );
    ?>

    <div class="sv-locked-container">
        <div class="sv-content-box <?php echo !sv_is_inet_hosting() ? 'sv-locked-item' : ''; ?>">
            <div class="sv-box-header"><span class="dashicons dashicons-chart-pie" style="color:#8b5cf6;"></span><h3>Rank Math SEO Pro</h3></div>
        
            <div class="sv-form-group" style="border:none; flex-direction:column; gap:15px;">
                
                <?php if ($all_done) : ?>
                <div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; background:#d4edda; color:#155724; border-radius:6px; font-weight:600; font-size:13px;">
                            <span class="dashicons dashicons-yes-alt" style="font-size:18px;"></span> <?php esc_html_e('Rank Math SEO Pro đã kích hoạt thành công', 'sitevorx'); ?>
                        </span>
                    </div>
                </div>
                <?php elseif ( $rm_free_active && $rm_pro_active && ! $rm_connected ) : ?>
                <?php
                    // Pro plugin is installed and active, but the license
                    // was not saved (typically because PHP timed out before
                    // the license step finished). Offer a one-click retry
                    // that skips the heavy plugin downloads.
                    //
                    // The diagnostic panel is intentionally OFF by default —
                    // end users should never see internal step labels or raw
                    // API bodies. Support staff can enable it ad-hoc by
                    // appending `?sv_debug=1` to the URL, or globally via
                    // WP_DEBUG.
                    $sv_show_rm_debug = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
                        || ( isset( $_GET['sv_debug'] ) && '1' === $_GET['sv_debug'] && current_user_can( 'manage_options' ) );
                    $debug = $sv_show_rm_debug ? get_transient( 'sv_rm_last_debug' ) : null;
                ?>
                <div>
                    <strong style="font-size:14px; color:#b45309;">
                        <span class="dashicons dashicons-warning" style="color:#f59e0b;"></span>
                        <?php esc_html_e( 'Rank Math Pro đã cài nhưng chưa kích hoạt license', 'sitevorx' ); ?>
                    </strong>
                    <p style="color:#64748b; margin:5px 0 15px;">
                        <?php esc_html_e( 'Bước cài plugin đã xong, nhưng bước lấy license bị gián đoạn (thường do PHP max_execution_time). Bấm bên dưới để thử lại — chỉ chạy bước license, không tải lại plugin.', 'sitevorx' ); ?>
                    </p>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=sv-premium&sv_activate_rm_license=1' ), 'sv_activate_rm_license_nonce' ) ); ?>"
                       class="button button-primary" style="background:#f59e0b; border:none; padding:10px 24px; font-size:13px; height:auto;"
                       onclick="this.style.opacity='0.7'; this.style.pointerEvents='none'; document.getElementById('sv-loading-overlay').style.display='flex';">
                        <?php esc_html_e( 'Kích hoạt lại license', 'sitevorx' ); ?>
                    </a>
                    <?php if ( $sv_show_rm_debug && is_array( $debug ) && ! empty( $debug ) ) : ?>
                    <details style="margin-top:12px; font-size:12px; color:#64748b;">
                        <summary style="cursor:pointer;"><?php esc_html_e( 'Chi tiết debug lần chạy gần nhất', 'sitevorx' ); ?></summary>
                        <pre style="margin-top:8px; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; font-size:11px; white-space:pre-wrap; word-break:break-word;"><?php echo esc_html( wp_json_encode( $debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                    </details>
                    <?php endif; ?>
                </div>
                <?php else : ?>
                <div>
                    <strong style="font-size:14px;"><?php esc_html_e('Cài đặt & Kích hoạt tự động', 'sitevorx'); ?></strong>
                    <p style="color:#64748b; margin:5px 0 15px;"><?php esc_html_e('Hệ thống sẽ tự động tải, cài đặt và kích hoạt bản quyền Rank Math SEO Pro chỉ với 1 click.', 'sitevorx'); ?></p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=sv-premium&sv_install_rm=1'), 'sv_install_rm_nonce')); ?>" 
                       class="button button-primary" style="background:#8b5cf6; border:none; padding:10px 24px; font-size:13px; height:auto;"
                       onclick="this.style.opacity='0.7'; this.style.pointerEvents='none'; document.getElementById('sv-loading-overlay').style.display='flex';">
                        <?php esc_html_e('Cài đặt & Kích hoạt Rank Math Pro', 'sitevorx'); ?>
                    </a>
                    <span style="display:block; margin-top:8px; font-size:12px; color:#94a3b8;"><?php esc_html_e('Quá trình mất khoảng 15-30 giây, vui lòng không đóng trang.', 'sitevorx'); ?></span>
                </div>
                <?php endif; ?>

                <div style="margin-top:20px; padding-top:25px; border-top:1px dashed #e2e8f0;">
                    <h4 style="margin:0 0 20px 0; font-size:15px; color:#1e293b; font-weight:700;"><?php esc_html_e('Các Chức Năng Độc Quyền Bản Pro', 'sitevorx'); ?></h4>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                        <?php
                        $features = array(
                            array(__('Tích Hợp Google Analytics 4', 'sitevorx'), __('Theo dõi toàn bộ lưu lượng Data và SEO Metrics ngay trong Dashboard WordPress.', 'sitevorx')),
                            array(__('Trình Cấu Trúc Dữ Liệu Nâng Cao', 'sitevorx'), __('Hỗ trợ đa dạng Schema JSON-LD Pro để chiếm định dạng Rich Snippet hiển thị đẹp trên Google.', 'sitevorx')),
                            array(__('Không Giới Hạn Từ Khóa', 'sitevorx'), __('Tối ưu hóa hàng tá focus keywords trong cùng 1 bài viết để chăn dắt SEO hiệu quả hơn.', 'sitevorx')),
                            array(__('Tối Ưu WooCommerce & EDD SEO', 'sitevorx'), __('Đẩy mạnh thứ hạng sản phẩm e-commerce tự động tích hợp thông minh các biến thể.', 'sitevorx')),
                        );
                        foreach ($features as $feat) :
                        ?>
                        <div style="display:flex; align-items:flex-start; gap:12px;">
                            <div style="width:32px; height:32px; border-radius:8px; background:#f0fdf4; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <span class="dashicons dashicons-yes" style="color:var(--sv-green); font-size:18px; width:18px; height:18px;"></span>
                            </div>
                            <div>
                                <strong style="display:block; font-size:13px; color:#334155; margin-bottom:3px;"><?php echo esc_html($feat[0]); ?></strong>
                                <span style="font-size:12px; color:#64748b; line-height:1.4; display:block;"><?php echo esc_html($feat[1]); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php if ( !sv_is_inet_hosting() ) : ?>
        <div class="sv-locked-overlay">
            <span><span class="dashicons dashicons-lock"></span> <?php esc_html_e('Độc Quyền iNET', 'sitevorx'); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
