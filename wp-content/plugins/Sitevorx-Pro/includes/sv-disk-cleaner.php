<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function sv_get_disk_cleaner_cache_key() {
    return 'sv_disk_cleaner_candidates_' . get_current_user_id();
}

function sv_get_directory_breakdown( $dir, $path_prefix = '', $exclude = array(), $limit = 10 ) {
    $items = array();

    if ( empty( $dir ) || ! is_dir( $dir ) || ! is_readable( $dir ) ) {
        return $items;
    }

    try {
        $iterator = new DirectoryIterator( $dir );
        foreach ( $iterator as $entry ) {
            if ( $entry->isDot() ) {
                continue;
            }

            $name = $entry->getFilename();
            if ( in_array( $name, $exclude, true ) ) {
                continue;
            }

            $pathname = $entry->getPathname();
            if ( $entry->isDir() ) {
                $size = recurse_dirsize( $pathname );
            } elseif ( $entry->isFile() ) {
                $size = $entry->getSize();
            } else {
                continue;
            }

            if ( $size <= 0 ) {
                continue;
            }

            $items[] = array(
                'label' => $name,
                'path'  => rtrim( $path_prefix, '/' ) . '/' . $name,
                'size'  => (int) $size,
            );
        }
    } catch ( Exception $e ) {
        return array();
    }

    usort(
        $items,
        function( $a, $b ) {
            return $b['size'] <=> $a['size'];
        }
    );

    if ( $limit > 0 ) {
        $items = array_slice( $items, 0, $limit );
    }

    return $items;
}
function sv_display_disk_cleaner_page() {
    global $wpdb;

    if ( ! function_exists( 'recurse_dirsize' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'heavy';

    // --- Handle: clear storage cache ---
    if ( isset( $_POST['sv_refresh_storage'] ) && check_admin_referer( 'sv_refresh_storage' ) && current_user_can( 'manage_options' ) ) {
        delete_transient( 'sv_wpcontent_size' );
        delete_transient( 'sv_plugins_size' );
        delete_transient( 'sv_themes_size' );
        delete_transient( 'sv_uploads_size' );
        delete_transient( 'sv_disk_db_size' );
        delete_transient( 'sv_dashboard_db_size' );
        delete_transient( 'sv_dashboard_content_size' );
        delete_transient( 'sv_dashboard_upload_size' );
        delete_transient( 'sv_plugins_breakdown' );
        delete_transient( 'sv_themes_breakdown' );
        delete_transient( 'sv_uploads_breakdown' );
        delete_transient( 'sv_wpcontent_other_breakdown' );
    }

    // --- Handle: delete heavy files ---
    if ( isset( $_POST['sv_delete_heavy_files'] ) && check_admin_referer( 'sv_disk_nonce' ) && current_user_can( 'manage_options' ) ) {
        $files_to_delete = isset( $_POST['heavy_files'] ) ? (array) $_POST['heavy_files'] : [];
        $allowed_files   = get_transient( sv_get_disk_cleaner_cache_key() );
        $allowed_files   = is_array( $allowed_files ) ? $allowed_files : [];
        $deleted_count   = 0;
        $freed_space     = 0;
        foreach ( $files_to_delete as $file ) {
            $filepath = wp_normalize_path( realpath( wp_unslash( $file ) ) );
            if ( ! $filepath || ! isset( $allowed_files[ $filepath ] ) ) continue;
            if ( is_link( $filepath ) ) continue;
            if ( strpos( $filepath, wp_normalize_path( realpath( ABSPATH ) ) ) === 0 && is_file( $filepath ) && ! is_link( $filepath ) ) {
                // Read the size first, then only count it as freed if the
                // unlink actually succeeds — otherwise the success notice would
                // report more space freed than was really deleted.
                $file_size = (int) filesize( $filepath );
                if ( unlink( $filepath ) ) {
                    unset( $allowed_files[ $filepath ] );
                    $freed_space += $file_size;
                    $deleted_count++;
                }
            }
        }
        set_transient( sv_get_disk_cleaner_cache_key(), $allowed_files, 15 * MINUTE_IN_SECONDS );
        if ( function_exists( 'sv_audit_log' ) && $deleted_count > 0 ) {
            sv_audit_log( 'disk_files_deleted', array(
                'count' => $deleted_count,
                'freed' => $freed_space,
            ) );
        }
        if ( $deleted_count > 0 ) {
            echo '<div class="notice notice-success is-dismissible sv-notice"><p>' . sprintf( __( 'Đã xóa %1$d file. Giải phóng %2$s dung lượng.', 'sitevorx' ), $deleted_count, sv_format_size( $freed_space ) ) . '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible sv-notice"><p>' . esc_html__( 'Không có file hợp lệ nào được xóa. Nếu bạn vừa mở lại trang sau một lúc, hãy quét lại danh sách trước khi xóa.', 'sitevorx' ) . '</p></div>';
        }
    }

    // =====================================================================
    // TAB 1 DATA — Large files (only scan when on heavy tab)
    // =====================================================================
    $found_files      = [];
    $total_heavy_size = 0;

    if ( $active_tab === 'heavy' ) {
        $heavy_file_limit = 50 * 1024 * 1024;
        try {
            $dir_iterator = new RecursiveDirectoryIterator( ABSPATH, RecursiveDirectoryIterator::SKIP_DOTS );
            $iterator     = new RecursiveIteratorIterator( $dir_iterator, RecursiveIteratorIterator::SELF_FIRST );
            $start_time   = microtime( true );
            foreach ( $iterator as $file ) {
                if ( ( microtime( true ) - $start_time ) > 5.0 ) break;
                if ( $file->isFile() ) {
                    $size = $file->getSize();
                    if ( $size >= $heavy_file_limit ) {
                        $ext        = strtolower( $file->getExtension() );
                        $type_label = __( 'FILE DUNG LƯỢNG LỚN', 'sitevorx' );
                        if ( in_array( $ext, [ 'log', 'zip', 'sql', 'tar', 'gz', 'rar' ] ) || strtolower( $file->getFilename() ) === 'error_log' ) {
                            $type_label = __( 'BACKUP / LOG NẶNG', 'sitevorx' );
                        }
                        $total_heavy_size += $size;
                        $found_files[]     = [ 'path' => $file->getPathname(), 'name' => $file->getFilename(), 'size' => $size, 'date' => $file->getMTime(), 'label' => $type_label ];
                    }
                }
            }
        } catch ( Exception $e ) {}

        usort( $found_files, function( $a, $b ) { return $b['size'] <=> $a['size']; } );
        $allowed_map = [];
        foreach ( $found_files as $f ) {
            $rp = wp_normalize_path( realpath( $f['path'] ) );
            if ( $rp ) $allowed_map[ $rp ] = true;
        }
        set_transient( sv_get_disk_cleaner_cache_key(), $allowed_map, 15 * MINUTE_IN_SECONDS );
    }

    // =====================================================================
    // TAB 2 DATA — Detailed storage breakdown (only computed on storage tab)
    // =====================================================================
    $wpcontent_size = 0;
    $plugins_size   = 0;
    $themes_size    = 0;
    $uploads_size   = 0;
    $db_size        = 0;
    $other_size     = 0;
    $grand_total    = 0;
    $base           = 1;
    $wpcontent_base = 1;
    $plugin_items   = array();
    $theme_items    = array();
    $upload_items   = array();
    $other_items    = array();
    $pct            = function( $v ) { return 0; };

    if ( $active_tab === 'storage' ) {
        $wpcontent_size = get_transient( 'sv_wpcontent_size' );
        if ( $wpcontent_size === false ) {
            $wpcontent_size = recurse_dirsize( WP_CONTENT_DIR );
            set_transient( 'sv_wpcontent_size', $wpcontent_size, HOUR_IN_SECONDS );
        }

        $plugins_size = get_transient( 'sv_plugins_size' );
        if ( $plugins_size === false ) {
            $plugins_size = recurse_dirsize( WP_PLUGIN_DIR );
            set_transient( 'sv_plugins_size', $plugins_size, HOUR_IN_SECONDS );
        }

        $themes_size = get_transient( 'sv_themes_size' );
        if ( $themes_size === false ) {
            $themes_size = recurse_dirsize( get_theme_root() );
            set_transient( 'sv_themes_size', $themes_size, HOUR_IN_SECONDS );
        }

        $upload_dir   = wp_upload_dir();
        $uploads_size = get_transient( 'sv_uploads_size' );
        if ( $uploads_size === false ) {
            $uploads_size = recurse_dirsize( $upload_dir['basedir'] );
            set_transient( 'sv_uploads_size', $uploads_size, HOUR_IN_SECONDS );
        }

        $db_size = get_transient( 'sv_disk_db_size' );
        if ( $db_size === false ) {
            $db_size = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s", DB_NAME ) );
            set_transient( 'sv_disk_db_size', $db_size, HOUR_IN_SECONDS );
        }

        $plugins_size   = intval( $plugins_size );
        $themes_size    = intval( $themes_size );
        $uploads_size   = intval( $uploads_size );
        $wpcontent_size = intval( $wpcontent_size );
        $db_size        = intval( $db_size );
        $other_size     = max( 0, $wpcontent_size - $plugins_size - $themes_size - $uploads_size );
        $grand_total    = $wpcontent_size + $db_size;
        $base           = max( $grand_total, 1 );
        $wpcontent_base = max( $wpcontent_size, 1 );
        $pct            = function( $v ) use ( $wpcontent_base ) { return $v > 0 ? min( 100, (int) round( ( $v / $wpcontent_base ) * 100 ) ) : 0; };

        $plugin_items = get_transient( 'sv_plugins_breakdown' );
        if ( ! is_array( $plugin_items ) ) {
            $plugin_items = sv_get_directory_breakdown( WP_PLUGIN_DIR, '/wp-content/plugins', array(), 10 );
            set_transient( 'sv_plugins_breakdown', $plugin_items, HOUR_IN_SECONDS );
        }

        $theme_items = get_transient( 'sv_themes_breakdown' );
        if ( ! is_array( $theme_items ) ) {
            $theme_items = sv_get_directory_breakdown( get_theme_root(), '/wp-content/themes', array(), 10 );
            set_transient( 'sv_themes_breakdown', $theme_items, HOUR_IN_SECONDS );
        }

        $upload_items = get_transient( 'sv_uploads_breakdown' );
        if ( ! is_array( $upload_items ) ) {
            $upload_items = sv_get_directory_breakdown( $upload_dir['basedir'], '/wp-content/uploads', array(), 10 );
            set_transient( 'sv_uploads_breakdown', $upload_items, HOUR_IN_SECONDS );
        }

        $other_items = get_transient( 'sv_wpcontent_other_breakdown' );
        if ( ! is_array( $other_items ) ) {
            $other_items = sv_get_directory_breakdown( WP_CONTENT_DIR, '/wp-content', array( 'plugins', 'themes', 'uploads' ), 10 );
            set_transient( 'sv_wpcontent_other_breakdown', $other_items, HOUR_IN_SECONDS );
        }
    }
    ?>
    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar( 'disk-cleaner' ); ?>
            <div class="sv-content-area">

                <div class="sv-top-banner">
                    <h2><?php esc_html_e( 'Quản Lý Dung Lượng', 'sitevorx' ); ?></h2>
                    <p><?php esc_html_e( 'Xem chi tiết dung lượng từng thành phần hoặc quét tìm và xóa các file cỡ lớn (trên 50 MB) đang chiếm dụng không gian lưu trữ.', 'sitevorx' ); ?></p>
                </div>

                <div class="sv-tabs-nav">
                    <a href="?page=sv-disk-cleaner&tab=storage" class="sv-tab-btn <?php echo $active_tab === 'storage' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-chart-bar" style="font-size:15px;width:15px;height:15px;margin-right:5px;vertical-align:middle;"></span>
                        <?php esc_html_e( 'Dung Lượng Chi Tiết', 'sitevorx' ); ?>
                    </a>
                    <a href="?page=sv-disk-cleaner&tab=heavy" class="sv-tab-btn <?php echo $active_tab === 'heavy' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-search" style="font-size:15px;width:15px;height:15px;margin-right:5px;vertical-align:middle;"></span>
                        <?php esc_html_e( 'File Cỡ Lớn (>50 MB)', 'sitevorx' ); ?>
                    </a>
                </div>

                <?php if ( $active_tab === 'storage' ) : ?>
                <!-- ========== TAB: DUNG LƯỢNG CHI TIẾT ========== -->

                <div class="sv-content-box">
                    <div class="sv-box-header">
                        <span class="dashicons dashicons-portfolio" style="color:#10b981;"></span>
                        <h3><?php esc_html_e( 'Tổng Quan Dung Lượng', 'sitevorx' ); ?></h3>
                        <form method="post" style="margin-left:auto;">
                            <?php wp_nonce_field( 'sv_refresh_storage' ); ?>
                            <button type="submit" name="sv_refresh_storage" value="1" class="button sv-btn-refresh" title="<?php esc_attr_e( 'Làm mới dữ liệu', 'sitevorx' ); ?>" data-scan-overlay-msg="<?php echo esc_attr( __( 'Đang làm mới dữ liệu...', 'sitevorx' ) ); ?>" data-saving-text="<?php echo esc_attr( __( 'Đang làm mới...', 'sitevorx' ) ); ?>">
                                <span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;margin-right:4px;vertical-align:text-bottom;position:relative;top:1px;"></span>
                                <?php esc_html_e( 'Làm mới', 'sitevorx' ); ?>
                            </button>
                        </form>
                    </div>
                    <div style="padding: 24px;">

                        <!-- Summary cards -->
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 28px;">
                            <div class="sv-stat-card" style="flex-direction: column; align-items: flex-start; gap: 4px;">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                    <div class="sv-stat-icon sv-icon-green" style="width:34px;height:34px;"><span class="dashicons dashicons-portfolio"></span></div>
                                    <span class="sv-stat-label"><?php esc_html_e( 'WP CONTENT', 'sitevorx' ); ?></span>
                                </div>
                                <strong class="sv-stat-value" style="font-size:22px;"><?php echo esc_html( sv_format_size( $wpcontent_size ) ); ?></strong>
                                <span style="font-size:11px;color:var(--sv-text-secondary);">/wp-content</span>
                            </div>
                            <div class="sv-stat-card" style="flex-direction: column; align-items: flex-start; gap: 4px;">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                    <div class="sv-stat-icon sv-icon-purple" style="width:34px;height:34px;"><span class="dashicons dashicons-database"></span></div>
                                    <span class="sv-stat-label"><?php esc_html_e( 'DATABASE', 'sitevorx' ); ?></span>
                                </div>
                                <strong class="sv-stat-value" style="font-size:22px;"><?php echo esc_html( sv_format_size( $db_size ) ); ?></strong>
                                <span style="font-size:11px;color:var(--sv-text-secondary);"><?php echo esc_html( DB_NAME ); ?></span>
                            </div>
                            <div class="sv-stat-card" style="flex-direction: column; align-items: flex-start; gap: 4px; border: 2px solid #e2e8f0;">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                    <div class="sv-stat-icon sv-icon-blue" style="width:34px;height:34px;"><span class="dashicons dashicons-chart-pie"></span></div>
                                    <span class="sv-stat-label"><?php esc_html_e( 'TỔNG CỘNG', 'sitevorx' ); ?></span>
                                </div>
                                <strong class="sv-stat-value" style="font-size:22px; color: #1e40af;"><?php echo esc_html( sv_format_size( $grand_total ) ); ?></strong>
                                <span style="font-size:11px;color:var(--sv-text-secondary);"><?php esc_html_e( 'Content + Database', 'sitevorx' ); ?></span>
                            </div>
                        </div>

                        <!-- WP Content breakdown -->
                        <h4 style="font-size:14px;font-weight:700;color:var(--sv-text);margin:0 0 14px 0;padding-bottom:10px;border-bottom:1px solid var(--sv-border);">
                            <span class="dashicons dashicons-portfolio" style="color:#10b981;font-size:15px;width:15px;height:15px;margin-right:6px;vertical-align:middle;"></span>
                            <?php esc_html_e( 'Chi tiết /wp-content', 'sitevorx' ); ?>
                        </h4>
                        <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:28px;">
                            <?php
                            $rows = [
                                [ 'label' => __( 'Plugins', 'sitevorx' ),      'path' => '/wp-content/plugins',  'size' => $plugins_size, 'color' => 'sv-fill-blue' ],
                                [ 'label' => __( 'Themes', 'sitevorx' ),       'path' => '/wp-content/themes',   'size' => $themes_size,  'color' => 'sv-fill-green' ],
                                [ 'label' => __( 'Media / Uploads', 'sitevorx' ), 'path' => '/wp-content/uploads', 'size' => $uploads_size, 'color' => 'sv-fill-purple' ],
                                [ 'label' => __( 'Khác (cache, logs...)', 'sitevorx' ), 'path' => '/wp-content/…', 'size' => $other_size, 'color' => 'sv-fill-gray' ],
                            ];
                            foreach ( $rows as $row ) :
                                $p = $pct( $row['size'] );
                                $p_label = $row['size'] > 0 && 0 === $p ? '<1%' : $p . '%';
                            ?>
                            <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:#f8fafc;border-radius:10px;border:1px solid #edf2f7;">
                                <div style="width:130px;flex-shrink:0;">
                                    <span style="font-size:13px;font-weight:600;color:var(--sv-text);"><?php echo esc_html( $row['label'] ); ?></span>
                                    <span style="display:block;font-size:10.5px;color:var(--sv-text-secondary);font-family:monospace;"><?php echo esc_html( $row['path'] ); ?></span>
                                </div>
                                <div class="sv-storage-bar" style="flex:1;height:10px;">
                                    <div class="sv-storage-fill <?php echo esc_attr( $row['color'] ); ?>" style="width:<?php echo $p; ?>%"></div>
                                </div>
                                <div style="width:170px;text-align:right;flex-shrink:0;">
                                    <span style="display:block;font-size:12px;font-weight:700;color:var(--sv-text);"><?php echo esc_html( sv_format_size( $row['size'] ) ); ?></span>
                                    <span style="display:block;font-size:11px;color:var(--sv-text-secondary);"><?php echo esc_html( sprintf( __( 'Chiếm %s trong /wp-content', 'sitevorx' ), $p_label ) ); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php
                        $detail_sections = array(
                            array(
                                'title' => __( 'Top Plugin Theo Dung Lượng', 'sitevorx' ),
                                'items' => $plugin_items,
                                'base'  => $plugins_size,
                            ),
                            array(
                                'title' => __( 'Top Theme Theo Dung Lượng', 'sitevorx' ),
                                'items' => $theme_items,
                                'base'  => $themes_size,
                            ),
                            array(
                                'title' => __( 'Top Thư Mục Trong Uploads', 'sitevorx' ),
                                'items' => $upload_items,
                                'base'  => $uploads_size,
                            ),
                            array(
                                'title' => __( 'Top Mục Khác Trong /wp-content', 'sitevorx' ),
                                'items' => $other_items,
                                'base'  => $other_size,
                            ),
                        );

                        foreach ( $detail_sections as $section ) :
                            if ( empty( $section['items'] ) ) {
                                continue;
                            }
                            $section_base = max( 1, (int) $section['base'] );
                        ?>
                        <h4 style="font-size:14px;font-weight:700;color:var(--sv-text);margin:0 0 10px 0;padding-bottom:10px;border-bottom:1px solid var(--sv-border);">
                            <?php echo esc_html( $section['title'] ); ?>
                        </h4>
                        <p style="margin:-4px 0 12px;color:var(--sv-text-secondary);font-size:11.5px;">
                            <?php esc_html_e( 'Hiển thị 10 mục con lớn nhất trong nhóm này.', 'sitevorx' ); ?>
                        </p>
                        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:24px;">
                            <?php foreach ( $section['items'] as $item ) :
                                $item_pct       = $item['size'] > 0 ? min( 100, (int) round( ( $item['size'] / $section_base ) * 100 ) ) : 0;
                                $item_pct_label = $item['size'] > 0 && 0 === $item_pct ? '<1%' : $item_pct . '%';
                            ?>
                            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#ffffff;border-radius:10px;border:1px solid #edf2f7;">
                                <div style="width:220px;flex-shrink:0;">
                                    <span style="display:block;font-size:12.5px;font-weight:600;color:var(--sv-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $item['label'] ); ?>"><?php echo esc_html( $item['label'] ); ?></span>
                                    <span style="display:block;font-size:10.5px;color:var(--sv-text-secondary);font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $item['path'] ); ?>"><?php echo esc_html( $item['path'] ); ?></span>
                                </div>
                                <div class="sv-storage-bar" style="flex:1;height:8px;">
                                    <div class="sv-storage-fill sv-fill-blue" style="width:<?php echo $item_pct; ?>%"></div>
                                </div>
                                <div style="width:180px;text-align:right;flex-shrink:0;">
                                    <span style="display:block;font-size:12px;font-weight:600;color:var(--sv-text);"><?php echo esc_html( sv_format_size( $item['size'] ) ); ?></span>
                                    <span style="display:block;font-size:11px;color:var(--sv-text-secondary);"><?php echo esc_html( sprintf( __( 'Chiếm %s trong nhóm này', 'sitevorx' ), $item_pct_label ) ); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                        <!-- Database breakdown -->
                        <h4 style="font-size:14px;font-weight:700;color:var(--sv-text);margin:0 0 14px 0;padding-bottom:10px;border-bottom:1px solid var(--sv-border);">
                            <span class="dashicons dashicons-database" style="color:#8b5cf6;font-size:15px;width:15px;height:15px;margin-right:6px;vertical-align:middle;"></span>
                            <?php esc_html_e( 'Database', 'sitevorx' ); ?>
                        </h4>
                        <?php
                        $tables = $wpdb->get_results( $wpdb->prepare(
                            "SELECT table_name, data_length + index_length AS size FROM information_schema.TABLES WHERE table_schema = %s ORDER BY size DESC LIMIT 10",
                            DB_NAME
                        ) );
                        if ( $tables ) : ?>
                        <div style="display:flex;flex-direction:column;gap:8px;">
                            <?php foreach ( $tables as $table ) :
                                $tp = $db_size > 0 ? min( 100, (int) round( ( $table->size / $db_size ) * 100 ) ) : 0;
                                $tp_label = $table->size > 0 && 0 === $tp ? '<1%' : $tp . '%';
                            ?>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <span style="width:180px;flex-shrink:0;font-size:12px;font-family:monospace;color:var(--sv-text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $table->table_name ); ?>"><?php echo esc_html( $table->table_name ); ?></span>
                                <div class="sv-storage-bar" style="flex:1;height:7px;">
                                    <div class="sv-storage-fill sv-fill-purple" style="width:<?php echo $tp; ?>%"></div>
                                </div>
                                <div style="width:180px;text-align:right;flex-shrink:0;">
                                    <span style="display:block;font-size:12px;font-weight:600;color:var(--sv-text);"><?php echo esc_html( sv_format_size( $table->size ) ); ?></span>
                                    <span style="display:block;font-size:11px;color:var(--sv-text-secondary);"><?php echo esc_html( sprintf( __( 'Chiếm %s trong Database', 'sitevorx' ), $tp_label ) ); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else : ?>
                        <p style="color:var(--sv-text-secondary);font-size:13px;"><?php esc_html_e( 'Không lấy được thông tin bảng DB.', 'sitevorx' ); ?></p>
                        <?php endif; ?>

                        <p style="margin:20px 0 0;font-size:11.5px;color:var(--sv-text-secondary);">
                            <span class="dashicons dashicons-info-outline" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span>
                            <?php esc_html_e( 'Dữ liệu được cache 1 giờ. Nhấn "Làm mới" để quét lại ngay lập tức.', 'sitevorx' ); ?>
                        </p>
                    </div>
                </div>

                <?php else : ?>
                <!-- ========== TAB: FILE CỠ LỚN ========== -->

                <div class="sv-content-box">
                    <div class="sv-box-header"><span class="dashicons dashicons-chart-pie" style="color:#8e44ad;"></span><h3><?php esc_html_e( 'Thống kê File Cỡ Lớn', 'sitevorx' ); ?></h3></div>
                    <div style="padding: 20px;">
                        <div style="padding: 15px 20px; background: #fff8e5; border-left: 4px solid #f39c12; margin-bottom: 20px; border-radius: 8px; color: #111827;">
                            <span class="dashicons dashicons-warning" style="color:#f39c12;"></span> <strong style="color: #92400e;"><?php esc_html_e( 'Lưu ý:', 'sitevorx' ); ?></strong> <?php printf( __( 'Vui lòng nhìn kỹ %sTên file%s trước khi xóa. Tránh xóa nhầm các file quan trọng của bạn!', 'sitevorx' ), '<b>', '</b>' ); ?>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; text-align: center;">
                            <div style="padding: 20px; background: #fff2f1; border-radius: 8px; border: 1px solid #ffc9c9;">
                                <span style="display:block; color:#d63638; font-size: 14px; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Tổng Dung Lượng', 'sitevorx' ); ?></span>
                                <strong style="font-size: 24px; color: #d63638;"><?php echo esc_html( sv_format_size( $total_heavy_size ) ); ?></strong>
                            </div>
                            <div style="padding: 20px; background: #f0f6fc; border-radius: 8px; border: 1px solid #c3c4c7;">
                                <span style="display:block; color:#0073aa; font-size: 14px; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Số lượng file tìm thấy', 'sitevorx' ); ?></span>
                                <strong style="font-size: 24px; color: #0073aa;"><?php echo count( $found_files ); ?> <?php esc_html_e( 'file', 'sitevorx' ); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <?php wp_nonce_field( 'sv_disk_nonce' ); ?>
                    <input type="hidden" name="tab" value="heavy">
                    <div class="sv-content-box">
                        <div class="sv-box-header"><span class="dashicons dashicons-search" style="color:#d63638;"></span><h3><?php esc_html_e( 'Kết quả Quét đệ quy', 'sitevorx' ); ?></h3></div>
                        <div style="padding: 0 20px 20px 20px;">
                            <?php if ( empty( $found_files ) ) : ?>
                                <div style="padding: 40px 30px; text-align: center; color: #4b5563; background: #f9fafb; border-radius: 12px; border: 1px dashed #e5e7eb; margin-top:15px;">
                                    <span class="dashicons dashicons-smiley" style="font-size: 40px; width: 40px; height: 40px; color: #10b981; margin-bottom: 15px;"></span>
                                    <p style="margin: 0; font-size: 16px; font-weight: 500; color: #111827;"><?php esc_html_e( 'Máy chủ của bạn đang cực kỳ gọn gàng!', 'sitevorx' ); ?></p>
                                    <p style="margin: 5px 0 0 0; font-size: 14px;"><?php esc_html_e( 'Tuyệt vời! Không có tệp tin nào quá khổ chiếm dụng diện tích máy chủ.', 'sitevorx' ); ?></p>
                                </div>
                            <?php else : ?>
                                <table class="wp-list-table widefat fixed striped" style="border: 1px solid #e2e4e7; border-radius: 8px; overflow: hidden; margin-top: 15px;">
                                    <thead>
                                        <tr>
                                            <td class="manage-column column-cb check-column" style="width: 40px; padding: 10px;"><input type="checkbox" id="sv_check_all"></td>
                                            <th style="font-weight: 600; padding: 10px; width: 25%;"><?php esc_html_e( 'Tên File & Phân loại', 'sitevorx' ); ?></th>
                                            <th style="font-weight: 600; padding: 10px;"><?php esc_html_e( 'Đường dẫn chi tiết', 'sitevorx' ); ?></th>
                                            <th style="font-weight: 600; padding: 10px; width: 120px;"><?php esc_html_e( 'Dung lượng', 'sitevorx' ); ?></th>
                                            <th style="font-weight: 600; padding: 10px; width: 140px;"><?php esc_html_e( 'Ngày sửa đổi', 'sitevorx' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $found_files as $file ) : ?>
                                            <tr>
                                                <th class="check-column" style="padding: 10px;"><input type="checkbox" name="heavy_files[]" class="sv-junk-checkbox" value="<?php echo esc_attr( $file['path'] ); ?>"></th>
                                                <td style="padding: 10px; color: #1d2327;"><strong style="display:block; margin-bottom:4px;"><?php echo esc_html( $file['name'] ); ?></strong><span style="background:#646970; color:#fff; padding: 2px 6px; border-radius:4px; font-size:10px;"><?php echo esc_html( $file['label'] ); ?></span></td>
                                                <td style="padding: 10px; color: #646970; font-family: monospace; font-size: 12px; word-break: break-all;"><?php echo esc_html( str_replace( ABSPATH, '/', $file['path'] ) ); ?></td>
                                                <td style="padding: 10px; font-weight: 600; color: #d63638;"><?php echo esc_html( sv_format_size( $file['size'] ) ); ?></td>
                                                <td style="padding: 10px; color: #646970;"><?php echo esc_html( wp_date( 'd/m/Y H:i', $file['date'] ) ); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div class="sv-action-bar" style="margin-top: 20px; padding: 0; background: transparent; border-top: none; text-align: right;">
                                    <button type="submit" name="sv_delete_heavy_files" class="button button-primary" style="background:#d63638; border-color:#d63638;" data-confirm="<?php echo esc_attr( __( 'Hành động xóa sẽ không thể hoàn tác!', 'sitevorx' ) ); ?>"><?php esc_html_e( 'Xóa File Đã Chọn', 'sitevorx' ); ?></button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>

                <?php endif; ?>

            </div>
        </div>
    </div>
    <?php
}
