<?php
if (!defined('ABSPATH')) exit;

class Cyber_Hub_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'handle_admin_actions']);
    }

    public static function register_menu() {
        add_menu_page(
            'Khảo Sát ATTT',
            'Khảo Sát ATTT',
            'manage_options',
            'cyber-assessment-hub',
            [__CLASS__, 'render_hub_page'],
            'dashicons-shield-alt',
            25
        );

        add_submenu_page(
            'cyber-assessment-hub',
            'Cấu Hình Tự Động Hóa',
            'Cấu Hình Tự Động Hóa',
            'manage_options',
            'cyber-assessment-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function handle_admin_actions() {
        global $wpdb;
        $table_name = Cyber_Hub_DB::get_table_name();

        // A. Tải file sơ đồ bảo vệ
        if (isset($_GET['action']) && $_GET['action'] === 'download_secured_diagram' && isset($_GET['id'])) {
            if (!current_user_can('manage_options')) {
                wp_die('Truy cập bị từ chối: Quý khách không có quyền tải tài liệu này.', 'Unauthorized', ['response' => 403]);
            }
            $id = intval($_GET['id']);
            check_admin_referer('cyber_download_file_' . $id);
            
            $item = $wpdb->get_row($wpdb->prepare("SELECT attachment_url FROM $table_name WHERE id = %d", $id));

            if ($item && !empty($item->attachment_url)) {
                $file_path = $item->attachment_url;
                if (Cyber_Hub_Security::is_secure_upload_path($file_path) && file_exists($file_path)) {
                    $file_type_info = wp_check_filetype(basename($file_path));
                    $mime_type = $file_type_info['type'] ?: 'application/octet-stream';
                    
                    header('Content-Description: File Transfer');
                    header('Content-Type: ' . $mime_type);
                    header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    header('Content-Length: ' . filesize($file_path));
                    readfile($file_path);
                    exit;
                }
            }
            wp_die('Tệp đính kèm không tồn tại hoặc đã bị dọn dẹp.', 'File Not Found', ['response' => 404]);
        }

        if (!current_user_can('manage_options')) return;

        $delete_uploaded_file = function($file_path) {
            if (empty($file_path)) return;
            if (Cyber_Hub_Security::is_secure_upload_path($file_path) && file_exists($file_path)) {
                @unlink($file_path);
            }
        };

        $reset_auto_increment = function() use ($wpdb, $table_name) {
            $max_id = $wpdb->get_var("SELECT MAX(id) FROM $table_name");
            $next_id = $max_id ? ($max_id + 1) : 1;
            $wpdb->query("ALTER TABLE $table_name AUTO_INCREMENT = " . intval($next_id));
        };

        // B. Xuất file Word (.DOC)
        if (isset($_GET['action']) && $_GET['action'] === 'export_doc' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            check_admin_referer('cyber_export_doc_' . $id);
            $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
            if ($item) {
                $item = Cyber_Hub_Security::decrypt_record($item);
                $decrypted_raw = Cyber_Hub_Security::decrypt($item->service_data);
                $service_data = json_decode($decrypted_raw, true) ?: [];

                $service_names = [
                    'pci_dss'       => 'PCI DSS SCOPE DEFINITION',
                    'soc_report'    => 'SOC 1 / SOC 2 / ISAE SCOPING QUESTIONNAIRE',
                    'iso_standards' => 'ISO SCOPE DEFINITION FORM',
                    'iso_42001'     => 'ISO 42001 (AI) SCOPING DOCUMENT',
                    'pci_3ds'       => 'PCI 3DS SCOPE DEFINITION'
                ];

                $clean_company = preg_replace('/[^a-zA-Z0-9_-]/', '_', sanitize_file_name($item->company_name));
                $clean_company = substr($clean_company, 0, 30);
                $filename = 'Scoping_Document_' . ($clean_company ?: 'Company') . '_' . date('Ymd') . '.doc';

                header("Content-Type: application/vnd.ms-word; charset=utf-8");
                header("Content-Disposition: attachment; filename=\"$filename\"");
                header("Expires: 0");
                header("Cache-Control: must-revalidate, post-check=0, pre-check=0");

                echo "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>";
                echo "<head><meta charset='utf-8'><title>Scoping Document & E-NDA</title>";
                echo "<style>
                    body { font-family: 'Times New Roman', serif; font-size: 11pt; color: #111; line-height: 1.45; }
                    .header-table { width: 100%; border-bottom: 2px solid #0b3c5d; margin-bottom: 18px; padding-bottom: 8px; }
                    .title { font-size: 15pt; font-weight: bold; color: #0b3c5d; text-align: center; text-transform: uppercase; margin: 12px 0 4px 0; }
                    .subtitle { font-size: 10.5pt; color: #e67e22; text-align: center; font-weight: bold; margin-bottom: 18px; }
                    table.data-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
                    table.data-table th, table.data-table td { border: 1px solid #777; padding: 6px 9px; vertical-align: top; }
                    table.data-table th { background-color: #f2f5f8; color: #0b3c5d; font-weight: bold; width: 32%; text-align: left; }
                    .section-header { background-color: #0b3c5d; color: #fff; font-weight: bold; padding: 7px 9px; font-size: 12pt; margin-top: 18px; }
                    .nda-box { font-size: 9.5pt; color: #2d3748; border: 1px solid #cbd5e0; padding: 12px; margin-top: 10px; background: #f8fafc; line-height: 1.5; text-align: justify; }
                    .nda-box p { margin: 4px 0; }
                </style></head><body>";

                echo "<table class='header-table'><tr>";
                echo "<td style='font-size: 13pt; font-weight: bold; color: #0b3c5d;'>CYBER SERVICES VIETNAM</td>";
                echo "<td style='text-align: right; font-size: 9.5pt;'>Hotline: +84 979 875 985<br>Email: contacts@cyberservices.vn | Web: cyberservices.vn</td>";
                echo "</tr></table>";

                echo "<div class='title'>" . esc_html($service_names[$item->service_type] ?? 'SECURITY SCOPING DOCUMENT') . "</div>";
                echo "<div class='subtitle'>Hồ sơ khảo sát xác định phạm vi an toàn thông tin & Thỏa thuận bảo mật điện tử (E-NDA)</div>";

                echo "<div class='section-header'>I. THÔNG TIN DOANH NGHIỆP & LIÊN HỆ</div>";
                echo "<table class='data-table'>";
                echo "<tr><th>Tên Doanh nghiệp:</th><td><b>" . esc_html($item->company_name) . "</b></td></tr>";
                echo "<tr><th>Địa chỉ trụ sở:</th><td>" . esc_html($item->company_address) . "</td></tr>";
                echo "<tr><th>Người phụ trách & Chức vụ:</th><td>" . esc_html($item->contact_person) . "</td></tr>";
                echo "<tr><th>Email Doanh nghiệp:</th><td>" . esc_html($item->contact_email) . " (Đã xác thực OTP)</td></tr>";
                echo "<tr><th>Số điện thoại:</th><td>" . esc_html($item->contact_phone) . "</td></tr>";
                echo "<tr><th>Mô tả hoạt động:</th><td>" . nl2br(esc_html($item->business_description)) . "</td></tr>";
                echo "<tr><th>Thời gian xác lập:</th><td>" . esc_html($item->submitted_at) . "</td></tr>";
                echo "</table>";

                echo "<div class='section-header'>II. THÔNG SỐ KỸ THUẬT PHẠM VI KHẢO SÁT</div>";
                echo "<table class='data-table'>";
                foreach ($service_data as $key => $val) {
                    $display_val = is_array($val) ? implode(', ', $val) : (string)$val;
                    echo "<tr><th>" . esc_html(ucwords(str_replace('_', ' ', $key))) . "</th><td>" . nl2br(esc_html($display_val)) . "</td></tr>";
                }
                if ($item->attachment_url) {
                    echo "<tr><th>Tệp sơ đồ đính kèm:</th><td><i>Đã lưu trữ an toàn trong kho bảo mật nội bộ (Enterprise Secured Storage).</i></td></tr>";
                }
                echo "</table>";

                echo "<div class='section-header'>III. TOÀN VĂN THỎA THUẬN BẢO MẬT THÔNG TIN ĐIỆN TỬ (E-NDA)</div>";
                echo "<div class='nda-box'>";
                echo "<p><b>Điều 1 (Định nghĩa Thông tin Bí mật):</b> Bao gồm toàn bộ dữ liệu do Khách hàng cung cấp trong biểu mẫu này: dải địa chỉ IP, sơ đồ mạng, luồng dữ liệu, hạ tầng máy chủ, kiến trúc hệ thống, quy trình vận hành và thông tin liên hệ.</p>";
                echo "<p><b>Điều 2 (Cam kết của Cyber Services):</b> Cyber Services cam kết áp dụng các biện pháp an ninh kỹ thuật cao nhất (mã hóa dữ liệu AES-256) nhằm bảo vệ Thông tin Bí mật; chỉ sử dụng thông tin cho mục đích phân tích phạm vi, tư vấn kỹ thuật và lập dự toán đánh giá ATTT; không sao chép, thương mại hóa hoặc chuyển giao cho bất kỳ bên thứ ba nào khi chưa có sự chấp thuận bằng văn bản của Khách hàng.</p>";
                echo "<p><b>Điều 3 (Quyền sử dụng & Chia sẻ nội bộ của Cyber Services):</b> Khách hàng đồng ý cho phép Cyber Services được chia sẻ thông tin cho đội ngũ chuyên gia, kiểm toán viên (QSA/Lead Auditor) và chuyên viên kỹ thuật nội bộ của Cyber Services trên nguyên tắc 'cần biết' (need-to-know) nhằm phục vụ công tác xây dựng giải pháp.</p>";
                echo "<p><b>Điều 4 (Ngoại lệ bảo mật):</b> Nghĩa vụ bảo mật không áp dụng đối với thông tin đã được công khai hợp pháp, thông tin Cyber Services đã biết trước đó mà không vi phạm thỏa thuận, hoặc khi có yêu cầu cung cấp bắt buộc theo quyết định của cơ quan nhà nước có thẩm quyền theo quy định pháp luật.</p>";
                echo "<p><b>Điều 5 (Giới hạn trách nhiệm pháp lý):</b> Việc tiếp nhận phiếu khảo sát này là bước thẩm định kỹ thuật ban đầu, không cấu thành cam kết cấp chứng chỉ hay hợp đồng dịch vụ chính thức. Cyber Services hoàn toàn được miễn trừ mọi trách nhiệm đối với các rủi ro, lỗ hổng bảo mật sẵn có hoặc sự cố an ninh thông tin nội bộ của Khách hàng trước và trong quá trình khảo sát.</p>";
                echo "<p><b>Điều 6 (Thời hạn & Hiệu lực):</b> Thỏa thuận có hiệu lực pháp lý ràng buộc kể từ thời điểm Khách hàng xác nhận gửi dữ liệu trực tuyến và kéo dài trong thời hạn 03 (ba) năm.</p>";
                echo "<p><b>Điều 7 (Luật áp dụng & Giải quyết tranh chấp):</b> Thỏa thuận được giải thích và điều chỉnh theo pháp luật nước CHXHCN Việt Nam và Luật Giao dịch điện tử. Mọi tranh chấp (nếu có) sẽ được ưu tiên thương lượng hòa giải trước khi đưa ra Tòa án có thẩm quyền tại Việt Nam.</p>";
                echo "<p><b>Trạng thái xác nhận:</b> <b style='color:#0b3c5d;'>ĐÃ XÁC NHẬN ĐỒNG Ý ĐIỀU KHOẢN E-NDA QUA XÁC THỰC EMAIL OTP TRỰC TUYẾN</b>.</p>";
                echo "</div>";

                echo "<br><br><table style='width: 100%; border: none;'><tr>";
                echo "<td style='text-align: center; width: 50%; vertical-align: top;'><b>ĐẠI DIỆN KHÁCH HÀNG</b><br><br><br><br><i>(Đã xác thực OTP Email Doanh nghiệp)</i><br><br><b>" . esc_html($item->contact_person) . "</b></td>";
                echo "<td style='text-align: center; width: 50%; vertical-align: top;'><b>ĐẠI DIỆN CYBER SERVICES VIETNAM</b><br><br><br><br><br><br><b>Mr. Mạnh Hùng</b></td>";
                echo "</tr></table>";

                echo "</body></html>";
                exit;
            }
        }

        // C. Xuất CSV
        if (isset($_GET['action']) && $_GET['action'] === 'export_cyber_hub_csv') {
            check_admin_referer('cyber_export_csv');
            $results = $wpdb->get_results("SELECT id, submitted_at, service_type, company_name, contact_person, contact_email, contact_phone, nda_agreed, status FROM $table_name ORDER BY submitted_at DESC", ARRAY_A);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=cyber-services-scoping-' . date('Y-m-d') . '.csv');
            echo "\xEF\xBB\xBF";
            $output = fopen('php://output', 'w');
            // Chống CSV Formula Injection: giá trị bắt đầu bằng = + - @ hoặc tab
            // có thể bị Excel/LibreOffice thực thi như công thức khi mở file.
            $escape_csv_formula = function($value) {
                $value = (string) $value;
                if (isset($value[0]) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    return "'" . $value;
                }
                return $value;
            };
            if (!empty($results)) {
                fputcsv($output, ['ID', 'Thời gian', 'Dịch vụ', 'Tên doanh nghiệp', 'Người liên hệ', 'Email', 'SĐT', 'E-NDA', 'Trạng thái']);
                foreach ($results as $row) {
                    foreach (['company_name', 'contact_person', 'contact_email', 'contact_phone'] as $field) {
                        $row[$field] = $escape_csv_formula(Cyber_Hub_Security::decrypt($row[$field]));
                    }
                    fputcsv($output, $row);
                }
            }
            fclose($output);
            exit;
        }

        // D. Xóa đơn lẻ
        if (isset($_GET['action']) && $_GET['action'] === 'delete_hub_item' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            check_admin_referer('cyber_hub_delete_' . $id);

            $item = $wpdb->get_row($wpdb->prepare("SELECT attachment_url FROM $table_name WHERE id = %d", $id));
            if ($item && !empty($item->attachment_url)) {
                $delete_uploaded_file($item->attachment_url);
            }

            $wpdb->delete($table_name, ['id' => $id]);
            $reset_auto_increment();

            wp_redirect(admin_url('admin.php?page=cyber-assessment-hub&deleted=1'));
            exit;
        }

        // E. Xóa hàng loạt an toàn
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cyber_bulk_action']) && $_POST['cyber_bulk_action'] === 'delete') {
            check_admin_referer('cyber_hub_bulk_action_nonce');
            if (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
                $ids = array_filter(array_map('intval', $_POST['selected_ids']));

                if (!empty($ids)) {
                    $ids_sanitized = implode(',', $ids);

                    $items = $wpdb->get_results("SELECT attachment_url FROM $table_name WHERE id IN ($ids_sanitized)");
                    if (!empty($items)) {
                        foreach ($items as $row) {
                            if (!empty($row->attachment_url)) {
                                $delete_uploaded_file($row->attachment_url);
                            }
                        }
                    }

                    $deleted_count = $wpdb->query("DELETE FROM $table_name WHERE id IN ($ids_sanitized)");
                    $reset_auto_increment();

                    wp_redirect(admin_url('admin.php?page=cyber-assessment-hub&deleted_bulk=' . intval($deleted_count)));
                    exit;
                }
            }
            wp_redirect(admin_url('admin.php?page=cyber-assessment-hub&no_selection=1'));
            exit;
        }

        // F. Cập nhật trạng thái
        if (isset($_POST['cyber_hub_update_status_nonce']) && wp_verify_nonce($_POST['cyber_hub_update_status_nonce'], 'cyber_hub_update_status')) {
            $allowed_statuses = ['new', 'consulting', 'quoted', 'completed'];
            $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
            if (!in_array($status, $allowed_statuses, true)) {
                wp_die('Trạng thái hồ sơ không hợp lệ.', 'Invalid status', ['response' => 400]);
            }
            $wpdb->update(
                $table_name,
                ['status' => $status],
                ['id' => intval($_POST['item_id'])]
            );
            wp_redirect(admin_url('admin.php?page=cyber-assessment-hub&view_id=' . intval($_POST['item_id']) . '&updated=1'));
            exit;
        }
    }

    public static function render_settings_page() {
        if (isset($_POST['cyber_save_settings_nonce']) && wp_verify_nonce($_POST['cyber_save_settings_nonce'], 'cyber_save_settings')) {
            update_option('cyber_hub_tele_token', sanitize_text_field(wp_unslash($_POST['tele_token'] ?? '')), false);
            update_option('cyber_hub_tele_chatid', sanitize_text_field(wp_unslash($_POST['tele_chatid'] ?? '')), false);
            echo '<div class="notice notice-success is-dismissible"><p>✓ Cấu hình đã được lưu thành công!</p></div>';
        }

        $tele_token  = defined('CYBER_TELE_TOKEN') ? CYBER_TELE_TOKEN : get_option('cyber_hub_tele_token', '');
        $tele_chatid = defined('CYBER_TELE_CHATID') ? CYBER_TELE_CHATID : get_option('cyber_hub_tele_chatid', '');
        $is_constant = defined('CYBER_TELE_TOKEN') && defined('CYBER_TELE_CHATID');
        ?>
        <div class="wrap" style="max-width: 800px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            <h1 style="color: #0b3c5d; margin-bottom: 20px;">⚙️ Cấu Hình Tự Động Hóa Lead & Thông Báo</h1>
            <div style="background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;">
                <form method="POST" action="">
                    <?php wp_nonce_field('cyber_save_settings', 'cyber_save_settings_nonce'); ?>
                    
                    <h3 style="margin-top: 0; color: #0b3c5d; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px;">1. Cấu hình Telegram Bot (Nhận Lead Tức Thì)</h3>
                    
                    <?php if ($is_constant): ?>
                        <p style="color: #03543f; background: #def7ec; padding: 10px; border-radius: 4px;">
                            ✓ Telegram Bot đã được khóa cố định an toàn qua hằng số <code>CYBER_TELE_TOKEN</code> trong file <code>wp-config.php</code>.
                        </p>
                    <?php else: ?>
                        <p style="color: #718096; font-size: 13px;">Khi có khách gửi khảo sát, hệ thống sẽ gửi thông báo kèm thông tin liên hệ ngay vào nhóm Telegram.</p>
                    <?php endif; ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="tele_token">Telegram Bot Token</label></th>
                            <td>
                                <input name="tele_token" type="text" id="tele_token" value="<?php echo esc_attr($tele_token); ?>" class="regular-text" placeholder="Ví dụ: 123456789:ABCdefGhIJKlmNoPQRstuVWXyz" <?php echo $is_constant ? 'readonly style="background:#f1f5f9;"' : ''; ?>>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tele_chatid">Telegram Chat ID / Group ID</label></th>
                            <td>
                                <input name="tele_chatid" type="text" id="tele_chatid" value="<?php echo esc_attr($tele_chatid); ?>" class="regular-text" placeholder="Ví dụ: -100123456789 hoặc ID cá nhân" <?php echo $is_constant ? 'readonly style="background:#f1f5f9;"' : ''; ?>>
                            </td>
                        </tr>
                    </table>

                    <h3 style="margin-top: 25px; color: #0b3c5d; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px;">2. Cơ Chế Bảo Mật Enterprise Vault & OTP Protection</h3>
                    <ul style="color: #4a5568; font-size: 13px; line-height: 1.6;">
                        <li>✓ <b>Chống Race Condition & Giả Mạo OTP:</b> Sử dụng Session Token ngẫu nhiên 48 ký tự gắn liền với email sau khi xác thực.</li>
                        <li>✓ <b>Anti Brute-Force OTP:</b> Tự động hủy mã và khóa nếu nhập sai quá 5 lần.</li>
                        <li>✓ <b>Magic Bytes Validation:</b> Kiểm tra MIME thực tế bằng <code>finfo_file()</code> chống giả mạo đuôi tệp.</li>
                        <li>✓ <b>Hardened Upload Directory:</b> Vô hiệu hóa thực thi file PHP trong kho upload sơ đồ.</li>
                        <li>✓ <b>Word Export Sanitization:</b> Làm sạch chuỗi Header và loại trừ nguy cơ XSS/Header Injection.</li>
                    </ul>

                    <?php if (!$is_constant): ?>
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="Lưu Cấu Hình (Save Settings)">
                    </p>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php
    }

    public static function render_hub_page() {
        global $wpdb;
        $table_name = Cyber_Hub_DB::get_table_name();

        $service_names = [
            'pci_dss'       => '🛡️ PCI DSS',
            'soc_report'    => '📑 SOC 1 / SOC 2 / ISAE',
            'iso_standards' => '📋 Bộ Tiêu Chuẩn ISO',
            'iso_42001'     => '🤖 ISO 42001 (AI)',
            'pci_3ds'       => '💳 PCI 3DS'
        ];

        $status_badges = [
            'new'        => '<span style="background:#feebc8; color:#c05621; padding:3px 8px; border-radius:12px; font-weight:700; font-size:11px;">Mới tiếp nhận</span>',
            'consulting' => '<span style="background:#bee3f8; color:#2b6cb0; padding:3px 8px; border-radius:12px; font-weight:700; font-size:11px;">Đang tư vấn</span>',
            'quoted'     => '<span style="background:#e9d8fd; color:#6b46c1; padding:3px 8px; border-radius:12px; font-weight:700; font-size:11px;">Đã báo giá</span>',
            'completed'  => '<span style="background:#c6f6d5; color:#22543d; padding:3px 8px; border-radius:12px; font-weight:700; font-size:11px;">Hoàn tất</span>'
        ];
        ?>
        <style>
          .cs-dashboard-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin-right: 20px; }
          .cs-top-bar { display: flex; justify-content: space-between; align-items: center; background: #0b3c5d; color: #fff; padding: 18px 24px; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
          .cs-top-bar h1 { color: #fff; margin: 0; font-size: 20px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
          .cs-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 25px; }
          .cs-metric-card { background: #fff; border-radius: 8px; padding: 16px; border-left: 4px solid #0b3c5d; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
          .cs-metric-card .title { font-size: 11.5px; text-transform: uppercase; color: #718096; font-weight: 600; }
          .cs-metric-card .value { font-size: 24px; font-weight: bold; color: #2d3748; margin-top: 4px; }
          .cs-detail-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
          .cs-card { background: #fff; border-radius: 8px; padding: 22px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #edf2f7; }
          .cs-card-header { font-size: 15px; font-weight: bold; color: #0b3c5d; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 15px; }
          .cs-row { display: grid; grid-template-columns: 180px 1fr; padding: 8px 0; border-bottom: 1px dashed #f1f5f9; font-size: 13.5px; }
          .cs-row:last-child { border-bottom: none; }
          .cs-label { color: #64748b; font-weight: 600; }
          .cs-val { color: #1e293b; word-break: break-word; }
          .cs-filter-bar { background: #fff; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; border: 1px solid #e2e8f0; }
          .cs-bulk-bar { display: flex; align-items: center; gap: 8px; padding: 10px 15px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
          @media print { #adminmenumain, #wpadminbar, #wpfooter, .cs-non-printable { display: none !important; } .cs-dashboard-wrap { margin: 0 !important; } .cs-detail-grid { grid-template-columns: 1fr !important; } }
        </style>
        <?php

        // A. XEM CHI TIẾT HỒ SƠ
        if (isset($_GET['view_id'])) {
            $id = intval($_GET['view_id']);
            $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
            if ($item) {
                $item = Cyber_Hub_Security::decrypt_record($item);
                $decrypted_raw = Cyber_Hub_Security::decrypt($item->service_data);
                $service_data = json_decode($decrypted_raw, true) ?: [];
                ?>
                <div class="wrap cs-dashboard-wrap">
                    <div class="cs-top-bar">
                        <h1><span>🛡️</span> [<?php echo esc_html($service_names[$item->service_type] ?? $item->service_type); ?>] <?php echo esc_html($item->company_name); ?></h1>
                        <div class="cs-non-printable">
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?action=export_doc&id=' . $item->id), 'cyber_export_doc_' . $item->id)); ?>" class="button button-secondary" style="background:#fff; color:#0b3c5d; font-weight:bold; margin-right:5px;">⬇ Xuất Hồ Sơ Word (.DOC)</a>
                            <button onclick="window.print()" class="button button-secondary" style="margin-right: 5px;">🖨️ In Báo Cáo</button>
                            <a href="?page=cyber-assessment-hub" class="button button-primary">« Quay lại danh sách</a>
                        </div>
                    </div>

                    <div class="cs-detail-grid">
                        <div class="cs-col-main">
                            <div class="cs-card">
                                <div class="cs-card-header">1. Thông Tin Doanh Nghiệp & Người Đại Diện</div>
                                <div class="cs-row"><div class="cs-label">Tên Doanh nghiệp:</div><div class="cs-val"><strong><?php echo esc_html($item->company_name); ?></strong></div></div>
                                <div class="cs-row"><div class="cs-label">Địa chỉ trụ sở:</div><div class="cs-val"><?php echo esc_html($item->company_address); ?></div></div>
                                <div class="cs-row"><div class="cs-label">Người phụ trách:</div><div class="cs-val"><?php echo esc_html($item->contact_person); ?></div></div>
                                <div class="cs-row"><div class="cs-label">Email Doanh nghiệp:</div><div class="cs-val"><a href="mailto:<?php echo esc_attr($item->contact_email); ?>"><?php echo esc_html($item->contact_email); ?></a> <span style="color:#27ae60; font-weight:bold; font-size:11px;">[Đã xác thực OTP]</span></div></div>
                                <div class="cs-row"><div class="cs-label">Số điện thoại:</div><div class="cs-val"><a href="tel:<?php echo esc_attr($item->contact_phone); ?>"><strong><?php echo esc_html($item->contact_phone); ?></strong></a></div></div>
                                <div class="cs-row"><div class="cs-label">Mô tả hoạt động:</div><div class="cs-val"><?php echo nl2br(esc_html($item->business_description)) ?: '<em>Chưa cung cấp</em>'; ?></div></div>
                            </div>

                            <div class="cs-card">
                                <div class="cs-card-header">2. Thông Số Kỹ Thuật Phạm Vi (Giải mã AES-256 an toàn)</div>
                                <?php if ($item->service_type === 'pci_dss'): ?>
                                    <div class="cs-row"><div class="cs-label">Dữ liệu thẻ (CHD):</div><div class="cs-val"><strong><?php echo esc_html($service_data['store_process_transmit'] ?? '—'); ?></strong></div></div>
                                    <div class="cs-row"><div class="cs-label">Lượng GD/năm:</div><div class="cs-val"><?php echo esc_html($service_data['transaction_volume'] ?? '—'); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Nghiệp vụ thẻ:</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['payment_operations'] ?? '—')); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Vị trí xử lý/Quản lý:</div><div class="cs-val"><?php echo esc_html($service_data['card_processing_location'] ?? '—'); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Hosting & DR:</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['hosting_location_details'] ?? '—')); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Datacentre / Segment:</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['segment_details'] ?? '—')); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">IP Nội bộ:</div><div class="cs-val"><pre style="background:#f8fafc; padding:8px; border:1px solid #e2e8f0;"><?php echo esc_html($service_data['internal_ips'] ?? '—'); ?></pre></div></div>
                                    <div class="cs-row"><div class="cs-label">IP Public (ASV):</div><div class="cs-val"><pre style="background:#f8fafc; padding:8px; border:1px solid #e2e8f0;"><?php echo esc_html($service_data['public_ips'] ?? '—'); ?></pre></div></div>

                                <?php elseif ($item->service_type === 'soc_report'): ?>
                                    <div class="cs-row"><div class="cs-label">Chuẩn SOC / ISAE:</div><div class="cs-val"><strong><?php echo esc_html($service_data['soc_standard'] ?? '—'); ?></strong></div></div>
                                    <div class="cs-row"><div class="cs-label">Loại báo cáo & Thời gian:</div><div class="cs-val"><?php echo esc_html($service_data['report_type_period'] ?? '—'); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Tiêu chí tin cậy (TSC):</div><div class="cs-val"><?php echo !empty($service_data['trust_principles']) ? implode(', ', array_map('esc_html', (array)$service_data['trust_principles'])) : '—'; ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Đánh giá sẵn sàng:</div><div class="cs-val"><?php echo esc_html($service_data['readiness_needed'] ?? '—'); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Nhân sự trong scope:</div><div class="cs-val"><?php echo esc_html($service_data['personnel_count'] ?? '—'); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Bên thứ ba (Subservice):</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['subservice_org_details'] ?? '—')); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Hạ tầng & SDLC:</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['tech_nodes_infrastructure'] ?? '—')); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Cloud Offering:</div><div class="cs-val"><?php echo !empty($service_data['cloud_service_models']) ? implode(', ', array_map('esc_html', (array)$service_data['cloud_service_models'])) : '—'; ?></div></div>

                                <?php elseif ($item->service_type === 'iso_standards'): ?>
                                    <div class="cs-row"><div class="cs-label">Tiêu chuẩn ISO chọn:</div><div class="cs-val"><strong><?php echo !empty($service_data['iso_standards_target']) ? implode(', ', array_map('esc_html', (array)$service_data['iso_standards_target'])) : '—'; ?></strong></div></div>
                                    <div class="cs-row"><div class="cs-label">Quy trình trọng yếu:</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['critical_processes'] ?? '—')); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Nhân sự / Quy trình:</div><div class="cs-val"><?php echo esc_html($service_data['employee_count'] ?? '—'); ?> | <?php echo esc_html($service_data['process_count'] ?? '—'); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Tài sản IT Assets:</div><div class="cs-val"><?php echo esc_html($service_data['it_assets_count'] ?? '—'); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Trụ sở & Chi nhánh:</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['head_office_matrix'] ?? '—')); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">DR Site & Thuê ngoài:</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['dr_site_details'] ?? '—')); ?></div></div>

                                <?php elseif ($item->service_type === 'iso_42001'): ?>
                                    <div class="cs-row"><div class="cs-label">Lĩnh vực AI:</div><div class="cs-val"><?php echo esc_html($service_data['industry_sector'] ?? '—'); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">AI Use Cases:</div><div class="cs-val"><?php echo !empty($service_data['ai_use_cases']) ? implode(', ', array_map('esc_html', (array)$service_data['ai_use_cases'])) : '—'; ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Hệ thống AI Inventory:</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['ai_systems_inventory'] ?? '—')); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Quản trị & Giám sát:</div><div class="cs-val"><?php echo esc_html($service_data['ai_governance_framework'] ?? '—'); ?> | <?php echo esc_html($service_data['human_oversight'] ?? '—'); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Dữ liệu & Vòng đời AI:</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['ai_data_types_sources'] ?? '—')); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Hạ tầng & API bên thứ 3:</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['ai_infrastructure'] ?? '—')); ?></div></div>

                                <?php elseif ($item->service_type === 'pci_3ds'): ?>
                                    <div class="cs-row"><div class="cs-label">Phiên bản 3DS:</div><div class="cs-val"><strong><?php echo esc_html($service_data['implementation_version'] ?? '—'); ?></strong></div></div>
                                    <div class="cs-row"><div class="cs-label">Vai trò trong 3DS:</div><div class="cs-val"><?php echo !empty($service_data['role_in_ecosystem']) ? implode(', ', array_map('esc_html', (array)$service_data['role_in_ecosystem'])) : '—'; ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Thành phần 3DS:</div><div class="cs-val"><?php echo !empty($service_data['components_in_scope']) ? implode(', ', array_map('esc_html', (array)$service_data['components_in_scope'])) : '—'; ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Hạ tầng & Thiết bị mạng:</div><div class="cs-val"><?php echo nl2br(esc_html($service_data['infrastructure_servers'] ?? '—')); ?></div></div>
                                    <div class="cs-row"><div class="cs-label">Yêu cầu kiểm thử:</div><div class="cs-val"><?php echo !empty($service_data['testing_requirements']) ? implode(', ', array_map('esc_html', (array)$service_data['testing_requirements'])) : '—'; ?></div></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="cs-col-side">
                            <div class="cs-card">
                                <div class="cs-card-header">Xác Thực E-NDA & Email</div>
                                <div class="cs-row"><div class="cs-label">Trạng thái:</div><div class="cs-val"><span style="color:#27ae60; font-weight:bold;">✓ Đã xác thực OTP & E-NDA</span></div></div>
                                <div class="cs-row"><div class="cs-label">Thời gian:</div><div class="cs-val"><?php echo esc_html($item->submitted_at); ?></div></div>
                            </div>

                            <div class="cs-card">
                                <div class="cs-card-header">Trạng Thái Hồ Sơ</div>
                                <form method="POST" action="">
                                    <?php wp_nonce_field('cyber_hub_update_status', 'cyber_hub_update_status_nonce'); ?>
                                    <input type="hidden" name="item_id" value="<?php echo esc_attr($item->id); ?>">
                                    <select name="status" style="width:100%; margin-bottom:12px; padding:6px;">
                                        <option value="new" <?php selected($item->status, 'new'); ?>>Mới tiếp nhận</option>
                                        <option value="consulting" <?php selected($item->status, 'consulting'); ?>>Đang tư vấn</option>
                                        <option value="quoted" <?php selected($item->status, 'quoted'); ?>>Đã gửi báo giá</option>
                                        <option value="completed" <?php selected($item->status, 'completed'); ?>>Hoàn tất</option>
                                    </select>
                                    <button type="submit" class="button button-primary" style="width:100%;">Cập nhật Trạng thái</button>
                                </form>
                            </div>

                            <div class="cs-card">
                                <div class="cs-card-header">Tài Liệu Đính Kèm (Secured Vault)</div>
                                <div class="cs-row">
                                    <div class="cs-label">Sơ đồ mạng:</div>
                                    <div class="cs-val">
                                        <?php if ($item->attachment_url): 
                                            $download_secure_url = wp_nonce_url(admin_url('admin.php?action=download_secured_diagram&id=' . $item->id), 'cyber_download_file_' . $item->id);
                                        ?>
                                            <a href="<?php echo esc_url($download_secure_url); ?>" class="button button-primary" style="margin-top: 5px;">🔒 Tải Sơ Đồ An Toàn</a>
                                            <p style="font-size: 11px; color: #718096; margin-top: 4px;">File được lưu biệt lập và chỉ Admin mới có quyền tải.</p>
                                        <?php else: ?>
                                            <span style="color:#a0aec0;">Chưa đính kèm</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
                return;
            }
        }

        // B. DANH SÁCH & BỘ LỌC
        $selected_service = sanitize_text_field($_GET['filter_service'] ?? '');

        $total_all  = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pci  = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE service_type = 'pci_dss'");
        $total_soc  = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE service_type = 'soc_report'");
        $total_iso  = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE service_type = 'iso_standards'");
        $total_ai   = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE service_type = 'iso_42001'");
        $total_p3ds = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE service_type = 'pci_3ds'");

        if (!empty($selected_service)) {
            $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE service_type = %s ORDER BY submitted_at DESC", $selected_service));
        } else {
            $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY submitted_at DESC");
        }
        foreach ($results as $result) {
            Cyber_Hub_Security::decrypt_record($result);
        }
        ?>
        <div class="wrap cs-dashboard-wrap">
            <div class="cs-top-bar">
                <h1><span>🛡️</span> Cyber Services Assessment Hub</h1>
                <div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=cyber-assessment-settings')); ?>" class="button button-secondary" style="margin-right: 5px;">⚙️ Cấu Hình Telegram</a>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?action=export_cyber_hub_csv'), 'cyber_export_csv')); ?>" class="button button-secondary" style="background:#fff; color:#0b3c5d; font-weight:bold;">⬇ Xuất File Excel (CSV)</a>
                </div>
            </div>

            <?php if (isset($_GET['deleted'])): ?>
                <div class="notice notice-success is-dismissible"><p>✓ Đã xóa hồ sơ và dọn dẹp file đính kèm an toàn.</p></div>
            <?php elseif (isset($_GET['deleted_bulk'])): ?>
                <div class="notice notice-success is-dismissible"><p>✓ Đã xóa thành công <?php echo intval($_GET['deleted_bulk']); ?> hồ sơ và dọn dẹp toàn bộ file đính kèm liên quan.</p></div>
            <?php elseif (isset($_GET['no_selection'])): ?>
                <div class="notice notice-warning is-dismissible"><p>Vui lòng chọn ít nhất một hồ sơ để xóa.</p></div>
            <?php endif; ?>

            <div class="cs-metrics">
                <div class="cs-metric-card"><div class="title">Tổng Số Hồ Sơ</div><div class="value"><?php echo intval($total_all); ?></div></div>
                <div class="cs-metric-card" style="border-left-color:#3182ce;"><div class="title">PCI DSS</div><div class="value"><?php echo intval($total_pci); ?></div></div>
                <div class="cs-metric-card" style="border-left-color:#805ad5;"><div class="title">SOC 1/2</div><div class="value"><?php echo intval($total_soc); ?></div></div>
                <div class="cs-metric-card" style="border-left-color:#38a169;"><div class="title">Bộ ISO</div><div class="value"><?php echo intval($total_iso); ?></div></div>
                <div class="cs-metric-card" style="border-left-color:#d69e2e;"><div class="title">ISO 42001 (AI)</div><div class="value"><?php echo intval($total_ai); ?></div></div>
                <div class="cs-metric-card" style="border-left-color:#dd6b20;"><div class="title">PCI 3DS</div><div class="value"><?php echo intval($total_p3ds); ?></div></div>
            </div>

            <div class="cs-filter-bar">
                <span style="font-weight:bold; color:#4a5568;">Lọc theo Dịch vụ:</span>
                <a href="?page=cyber-assessment-hub" class="button <?php echo empty($selected_service) ? 'button-primary' : ''; ?>">Tất cả (<?php echo $total_all; ?>)</a>
                <a href="?page=cyber-assessment-hub&filter_service=pci_dss" class="button <?php echo ($selected_service === 'pci_dss') ? 'button-primary' : ''; ?>">PCI DSS (<?php echo $total_pci; ?>)</a>
                <a href="?page=cyber-assessment-hub&filter_service=soc_report" class="button <?php echo ($selected_service === 'soc_report') ? 'button-primary' : ''; ?>">SOC 1/2 (<?php echo $total_soc; ?>)</a>
                <a href="?page=cyber-assessment-hub&filter_service=iso_standards" class="button <?php echo ($selected_service === 'iso_standards') ? 'button-primary' : ''; ?>">Bộ ISO (<?php echo $total_iso; ?>)</a>
                <a href="?page=cyber-assessment-hub&filter_service=iso_42001" class="button <?php echo ($selected_service === 'iso_42001') ? 'button-primary' : ''; ?>">ISO 42001 AI (<?php echo $total_ai; ?>)</a>
                <a href="?page=cyber-assessment-hub&filter_service=pci_3ds" class="button <?php echo ($selected_service === 'pci_3ds') ? 'button-primary' : ''; ?>">PCI 3DS (<?php echo $total_p3ds; ?>)</a>
            </div>

            <form method="POST" action="" id="cyberBulkDeleteForm">
                <?php wp_nonce_field('cyber_hub_bulk_action_nonce'); ?>
                <input type="hidden" name="cyber_bulk_action" value="delete">

                <div class="cs-card" style="padding:0; overflow:hidden;">
                    <div class="cs-bulk-bar">
                        <button type="button" id="btnTriggerBulkDelete" class="button button-secondary" style="color: #e53e3e; border-color: #feb2b2; font-weight: 600;">
                            🗑️ Xóa Các Mục Đã Chọn (<span id="selectedCount">0</span>)
                        </button>
                    </div>

                    <table class="wp-list-table widefat fixed striped" style="border:none;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th style="width: 35px; padding: 12px 10px; text-align: center;">
                                    <input type="checkbox" id="selectAllCheckbox" title="Chọn tất cả">
                                </th>
                                <th style="width: 45px;">ID</th>
                                <th>Tiêu Chuẩn / Dịch Vụ</th>
                                <th>Tên Doanh Nghiệp</th>
                                <th>Người Phụ Trách</th>
                                <th>Email Doanh Nghiệp</th>
                                <th>E-NDA</th>
                                <th>Trạng Thái</th>
                                <th>Thời Gian</th>
                                <th style="width: 130px; text-align:center;">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($results)) : ?>
                                <tr><td colspan="10" style="text-align:center; padding:30px; color:#718096;">Chưa có bản khảo sát nào trong danh mục này.</td></tr>
                            <?php else : ?>
                                <?php foreach ($results as $row) : ?>
                                    <tr>
                                        <td style="text-align: center; padding: 12px 10px;">
                                            <input type="checkbox" name="selected_ids[]" value="<?php echo esc_attr($row->id); ?>" class="row-checkbox">
                                        </td>
                                        <td><?php echo esc_html($row->id); ?></td>
                                        <td><strong><?php echo esc_html($service_names[$row->service_type] ?? $row->service_type); ?></strong></td>
                                        <td><strong style="color:#0b3c5d;"><?php echo esc_html($row->company_name); ?></strong></td>
                                        <td><?php echo esc_html($row->contact_person); ?></td>
                                        <td><strong><?php echo esc_html($row->contact_email); ?></strong></td>
                                        <td><?php echo $row->nda_agreed ? '<span style="color:#27ae60; font-weight:bold;">✓ Đã Đồng Ý</span>' : '<span style="color:#e53e3e;">Chưa</span>'; ?></td>
                                        <td><?php echo $status_badges[$row->status] ?? esc_html($row->status); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($row->submitted_at)); ?></td>
                                        <td style="text-align:center;">
                                            <a href="?page=cyber-assessment-hub&view_id=<?php echo esc_attr($row->id); ?>" class="button button-small button-primary">Xem</a>
                                            <a href="<?php echo wp_nonce_url('admin.php?action=delete_hub_item&id=' . $row->id, 'cyber_hub_delete_' . $row->id); ?>" onclick="return confirm('Bạn có chắc chắn muốn xóa hồ sơ này kèm file đính kèm không?');" class="button button-small" style="color:#e53e3e;">Xóa</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>

        <script>
          document.addEventListener('DOMContentLoaded', function() {
            const selectAll = document.getElementById('selectAllCheckbox');
            const checkboxes = document.querySelectorAll('.row-checkbox');
            const selectedCountSpan = document.getElementById('selectedCount');
            const bulkDeleteBtn = document.getElementById('btnTriggerBulkDelete');
            const bulkForm = document.getElementById('cyberBulkDeleteForm');

            function updateCount() {
              let count = 0;
              checkboxes.forEach(cb => {
                if (cb.checked) count++;
              });
              if (selectedCountSpan) selectedCountSpan.textContent = count;
            }

            if (selectAll) {
              selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => {
                  cb.checked = selectAll.checked;
                });
                updateCount();
              });
            }

            checkboxes.forEach(cb => {
              cb.addEventListener('change', function() {
                if (!this.checked && selectAll) {
                  selectAll.checked = false;
                }
                updateCount();
              });
            });

            if (bulkDeleteBtn && bulkForm) {
              bulkDeleteBtn.addEventListener('click', function(e) {
                e.preventDefault();
                let count = 0;
                checkboxes.forEach(cb => {
                  if (cb.checked) count++;
                });

                if (count === 0) {
                  alert('Vui lòng tích chọn ít nhất một hồ sơ để xóa.');
                  return;
                }

                if (confirm('Bạn có chắc chắn muốn xóa vĩnh viễn ' + count + ' hồ sơ và toàn bộ file đính kèm đã chọn không? Thao tác này không thể khôi phục.')) {
                  bulkForm.submit();
                }
              });
            }
          });
        </script>
        <?php
    }
}
Cyber_Hub_Admin::init();
