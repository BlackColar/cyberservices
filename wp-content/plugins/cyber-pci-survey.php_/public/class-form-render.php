<?php
if (!defined('ABSPATH')) exit;

class Cyber_Hub_Form_Render {

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_shortcode('cyber_assessment_form', [__CLASS__, 'render_shortcode']);
        add_shortcode('pci_dss_form', function() { return self::render_shortcode(['service' => 'pci_dss']); });
        add_shortcode('pci_scope_form', function() { return self::render_shortcode(['service' => 'pci_dss']); });
        add_shortcode('soc_report_form', function() { return self::render_shortcode(['service' => 'soc_report']); });
        add_shortcode('iso_standards_form', function() { return self::render_shortcode(['service' => 'iso_standards']); });
        add_shortcode('iso_42001_form', function() { return self::render_shortcode(['service' => 'iso_42001']); });
        add_shortcode('pci_3ds_form', function() { return self::render_shortcode(['service' => 'pci_3ds']); });
    }

    public static function register_assets() {
        $css_ver = file_exists(CYBER_HUB_PATH . 'public/css/form-style.css') ? filemtime(CYBER_HUB_PATH . 'public/css/form-style.css') : CYBER_HUB_VERSION;
        $js_ver  = file_exists(CYBER_HUB_PATH . 'public/js/form-otp.js') ? filemtime(CYBER_HUB_PATH . 'public/js/form-otp.js') : CYBER_HUB_VERSION;

        wp_register_style('cyber-hub-form-css', CYBER_HUB_URL . 'public/css/form-style.css', [], $css_ver);
        wp_register_script('cyber-hub-form-js', CYBER_HUB_URL . 'public/js/form-otp.js', [], $js_ver, true);

        wp_localize_script('cyber-hub-form-js', 'cyberHubVars', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cyber_hub_ajax_nonce')
        ]);
    }

    public static function render_shortcode($atts) {
        wp_enqueue_style('cyber-hub-form-css');
        wp_enqueue_script('cyber-hub-form-js');

        $a = shortcode_atts(['service' => 'pci_dss'], $atts);
        $service_type = $a['service'];

        $service_titles = [
            'pci_dss'       => 'PCI DSS Scope Definition Form',
            'soc_report'    => 'SOC 1 / SOC 2 / ISAE 3000/3402 Scoping Questionnaire',
            'iso_standards' => 'ISO 27001 / ISO Scope Definition Form',
            'iso_42001'     => 'ISO 42001 (AI Management System) Scoping Form',
            'pci_3ds'       => 'PCI 3DS Scope Definition Form'
        ];

        ob_start();
        $message = '';
        $is_submitted_success = false;

        // Xử lý POST Form Submit
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cyber_hub_nonce']) && wp_verify_nonce($_POST['cyber_hub_nonce'], 'cyber_hub_submit_action')) {
            $_POST = wp_unslash($_POST);
            global $wpdb;
            $table_name = Cyber_Hub_DB::get_table_name();

            if (!empty($_POST['cyber_honeypot_check'])) {
                return '<div class="hub-alert hub-alert-danger">Phát hiện hành vi gửi tự động (Spam Detected).</div>';
            }

            $email = sanitize_email($_POST['contact_email'] ?? '');
            $raw_phone = sanitize_text_field($_POST['contact_phone'] ?? '');
            $phone = preg_replace('/[^0-9]/', '', $raw_phone);
            $allowed_services = ['pci_dss', 'soc_report', 'iso_standards', 'iso_42001', 'pci_3ds'];
            // The shortcode decides the questionnaire; never trust the hidden field.
            $submitted_service = in_array($service_type, $allowed_services, true) ? $service_type : 'pci_dss';
            $nda_agreed = isset($_POST['nda_agreement']) ? 1 : 0;
            $session_token = sanitize_text_field($_POST['cyber_session_token'] ?? '');

            $email_hash = hash_hmac('sha256', strtolower(trim($email)), wp_salt('nonce'));
            $saved_token = get_transient('cyber_session_token_' . $email_hash);

            $saved_file_path = '';
            $upload_error = '';

            // Kiểm tra File Upload nghiêm ngặt (Magic Bytes & MIME check)
            if (!empty($_FILES['diagram_file']['name'])) {
                $file = $_FILES['diagram_file'];
                $max_size = 10 * 1024 * 1024; // 10MB

                if (!isset($file['error'], $file['tmp_name'], $file['size']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                    $upload_error = 'Tệp đính kèm không hợp lệ hoặc quá trình tải lên bị lỗi.';
                } elseif ($file['size'] > $max_size) {
                    $upload_error = 'Dung lượng tệp đính kèm vượt quá 10MB.';
                } else {
                    $allowed_mimes = [
                        'pdf'  => 'application/pdf',
                        'jpg'  => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png'  => 'image/png'
                    ];

                    $file_info = wp_check_filetype($file['name']);
                    $ext = strtolower($file_info['ext']);

                    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
                    $real_mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
                    if ($finfo) finfo_close($finfo);

                    if (!array_key_exists($ext, $allowed_mimes) || !in_array($real_mime, $allowed_mimes, true) || $allowed_mimes[$ext] !== $real_mime) {
                        $upload_error = 'Định dạng tệp không hợp lệ. Chỉ chấp nhận tệp PNG, JPG hoặc PDF thực tế.';
                    } else {
                        $secure_folder = Cyber_Hub_Security::get_secure_upload_dir();
                        $unique_filename = 'diagram_' . time() . '_' . wp_generate_password(24, false) . '.' . $ext;
                        $target_file = $secure_folder . '/' . $unique_filename;

                        if (move_uploaded_file($file['tmp_name'], $target_file)) {
                            $saved_file_path = $target_file;
                        } else {
                            $upload_error = 'Không thể lưu trữ tệp đính kèm an toàn. Vui lòng thử lại.';
                        }
                    }
                }
            }

            $is_token_valid = (!empty($saved_token) && !empty($session_token) && hash_equals($saved_token, hash_hmac('sha256', $session_token, wp_salt('auth'))));

            if (empty(sanitize_text_field($_POST['company_name'] ?? '')) || empty(sanitize_text_field($_POST['company_address'] ?? '')) || empty(sanitize_text_field($_POST['contact_person'] ?? ''))) {
                $message = '<div class="hub-alert hub-alert-danger">Vui lòng điền đầy đủ thông tin doanh nghiệp bắt buộc.</div>';
            } elseif (!is_email($email)) {
                $message = '<div class="hub-alert hub-alert-danger">Email không đúng định dạng. Quý khách vui lòng kiểm tra lại.</div>';
            } elseif (!$is_token_valid) {
                $message = '<div class="hub-alert hub-alert-danger">Phiên xác thực Email đã hết hạn hoặc không khớp. Vui lòng bấm nhận và xác thực mã OTP trước khi gửi form.</div>';
            } elseif (!preg_match('/^(03|05|07|08|09)[0-9]{8}$/', $phone)) {
                $message = '<div class="hub-alert hub-alert-danger">Số điện thoại không hợp lệ! Vui lòng nhập đúng 10 số di động (bắt đầu bằng 03, 05, 07, 08, 09).</div>';
            } elseif (!$nda_agreed) {
                $message = '<div class="hub-alert hub-alert-danger">Quý khách vui lòng tích chọn đồng ý với các điều khoản Thỏa thuận bảo mật thông tin (E-NDA).</div>';
            } elseif (!empty($upload_error)) {
                $message = '<div class="hub-alert hub-alert-danger">' . esc_html($upload_error) . '</div>';
            } else {
                $service_data = [];

                if ($submitted_service === 'pci_dss') {
                    $service_data = [
                        'locations_details'        => sanitize_textarea_field($_POST['pci_locations_details'] ?? ''),
                        'card_services'            => sanitize_textarea_field($_POST['pci_card_services'] ?? ''),
                        'payment_operations'       => sanitize_textarea_field($_POST['pci_payment_operations'] ?? ''),
                        'card_processing_location' => sanitize_text_field($_POST['pci_card_processing_location'] ?? ''),
                        'it_management_location'   => sanitize_text_field($_POST['pci_it_management_location'] ?? ''),
                        'hosting_location_details' => sanitize_textarea_field($_POST['pci_hosting_location_details'] ?? ''),
                        'dr_location'              => sanitize_text_field($_POST['pci_dr_location'] ?? ''),
                        'other_locations'          => sanitize_text_field($_POST['pci_other_locations'] ?? ''),
                        'dc_scope_details'         => sanitize_textarea_field($_POST['pci_dc_scope_details'] ?? ''),
                        'segment_details'          => sanitize_textarea_field($_POST['pci_segment_details'] ?? ''),
                        'store_process_transmit'   => sanitize_text_field($_POST['pci_store_process_transmit'] ?? ''),
                        'transaction_volume'       => sanitize_text_field($_POST['pci_transaction_volume'] ?? ''),
                        'internal_ips'             => sanitize_textarea_field($_POST['pci_internal_ips'] ?? ''),
                        'public_ips'               => sanitize_textarea_field($_POST['pci_public_ips'] ?? '')
                    ];
                } elseif ($submitted_service === 'soc_report') {
                    $service_data = [
                        'soc_standard'             => sanitize_text_field($_POST['soc_standard'] ?? ''),
                        'existing_certifications'  => sanitize_text_field($_POST['soc_existing_certifications'] ?? ''),
                        'readiness_needed'         => sanitize_text_field($_POST['soc_readiness_needed'] ?? ''),
                        'report_type_period'       => sanitize_text_field($_POST['soc_report_type_period'] ?? ''),
                        'other_compliance'         => sanitize_text_field($_POST['soc_other_compliance'] ?? ''),
                        'client_deadlines'         => sanitize_text_field($_POST['soc_client_deadlines'] ?? ''),
                        'service_scope_desc'       => sanitize_textarea_field($_POST['soc_service_scope_desc'] ?? ''),
                        'target_industry'          => sanitize_text_field($_POST['soc_target_industry'] ?? ''),
                        'personnel_count'          => sanitize_text_field($_POST['soc_personnel_count'] ?? ''),
                        'anticipated_changes'      => sanitize_textarea_field($_POST['soc_anticipated_changes'] ?? ''),
                        'report_distribution'      => sanitize_text_field($_POST['soc_report_distribution'] ?? ''),
                        'control_objectives'       => sanitize_text_field($_POST['soc_control_objectives'] ?? ''),
                        'trust_principles'         => isset($_POST['soc_principles']) ? array_map('sanitize_text_field', $_POST['soc_principles']) : [],
                        'personnel_locations'      => sanitize_textarea_field($_POST['soc_personnel_locations'] ?? ''),
                        'it_infra_locations'       => sanitize_textarea_field($_POST['soc_it_infra_locations'] ?? ''),
                        'subservice_org_details'   => sanitize_textarea_field($_POST['soc_subservice_org_details'] ?? ''),
                        'subservice_audit_status'  => sanitize_text_field($_POST['soc_subservice_audit_status'] ?? ''),
                        'tech_nodes_infrastructure'=> sanitize_textarea_field($_POST['soc_tech_nodes_infrastructure'] ?? ''),
                        'sdlc_dev_tools'           => sanitize_textarea_field($_POST['soc_sdlc_dev_tools'] ?? ''),
                        'cloud_characteristics'    => isset($_POST['soc_cloud_chars']) ? array_map('sanitize_text_field', $_POST['soc_cloud_chars']) : [],
                        'cloud_service_models'     => isset($_POST['soc_cloud_models']) ? array_map('sanitize_text_field', $_POST['soc_cloud_models']) : [],
                        'cloud_deployment_models'  => isset($_POST['soc_cloud_deploy']) ? array_map('sanitize_text_field', $_POST['soc_cloud_deploy']) : [],
                        'transnational_data'       => sanitize_text_field($_POST['soc_transnational_data'] ?? '')
                    ];
                } elseif ($submitted_service === 'iso_standards') {
                    $service_data = [
                        'iso_standards_target'     => isset($_POST['iso_targets']) ? array_map('sanitize_text_field', $_POST['iso_targets']) : [],
                        'critical_processes'       => sanitize_textarea_field($_POST['iso_critical_processes'] ?? ''),
                        'outsourced_processes'     => sanitize_textarea_field($_POST['iso_outsourced_processes'] ?? ''),
                        'dr_site_details'          => sanitize_textarea_field($_POST['iso_dr_site_details'] ?? ''),
                        'it_assets_count'          => sanitize_text_field($_POST['iso_it_assets_count'] ?? ''),
                        'process_count'            => sanitize_text_field($_POST['iso_process_count'] ?? ''),
                        'application_count'        => sanitize_text_field($_POST['iso_application_count'] ?? ''),
                        'employee_count'           => sanitize_text_field($_POST['iso_employee_count'] ?? ''),
                        'existing_certifications'  => sanitize_text_field($_POST['iso_existing_certifications'] ?? ''),
                        'confidential_info_status' => sanitize_text_field($_POST['iso_confidential_info_status'] ?? ''),
                        'head_office_matrix'       => sanitize_textarea_field($_POST['iso_head_office_matrix'] ?? ''),
                        'branch_locations_matrix'  => sanitize_textarea_field($_POST['iso_branch_locations_matrix'] ?? '')
                    ];
                } elseif ($submitted_service === 'iso_42001') {
                    $service_data = [
                        'industry_sector'          => sanitize_text_field($_POST['ai_industry_sector'] ?? ''),
                        'employee_locations_count' => sanitize_text_field($_POST['ai_employee_locations_count'] ?? ''),
                        'functions_in_scope'       => sanitize_textarea_field($_POST['ai_functions_in_scope'] ?? ''),
                        'ai_systems_inventory'     => sanitize_textarea_field($_POST['ai_systems_inventory'] ?? ''),
                        'ai_use_cases'             => isset($_POST['ai_use_cases']) ? array_map('sanitize_text_field', $_POST['ai_use_cases']) : [],
                        'ai_governance_framework'  => sanitize_text_field($_POST['ai_governance_framework'] ?? ''),
                        'ai_risk_management'       => isset($_POST['ai_risks']) ? array_map('sanitize_text_field', $_POST['ai_risks']) : [],
                        'ai_data_types_sources'    => sanitize_textarea_field($_POST['ai_data_types_sources'] ?? ''),
                        'ai_lifecycle_stages'      => isset($_POST['ai_lifecycle']) ? array_map('sanitize_text_field', $_POST['ai_lifecycle']) : [],
                        'ai_infrastructure'        => sanitize_textarea_field($_POST['ai_infrastructure'] ?? ''),
                        'ai_third_party_vendors'   => sanitize_textarea_field($_POST['ai_third_party_vendors'] ?? ''),
                        'human_oversight'          => sanitize_text_field($_POST['ai_human_oversight'] ?? ''),
                        'target_audit_date'        => sanitize_text_field($_POST['ai_target_audit_date'] ?? '')
                    ];
                } elseif ($submitted_service === 'pci_3ds') {
                    $service_data = [
                        'business_unit_desc'       => sanitize_textarea_field($_POST['p3ds_business_unit_desc'] ?? ''),
                        'implementation_version'   => sanitize_text_field($_POST['p3ds_implementation_version'] ?? ''),
                        'role_in_ecosystem'        => isset($_POST['p3ds_roles']) ? array_map('sanitize_text_field', $_POST['p3ds_roles']) : [],
                        'architecture_description' => sanitize_textarea_field($_POST['p3ds_architecture_description'] ?? ''),
                        'application_details'      => sanitize_textarea_field($_POST['p3ds_application_details'] ?? ''),
                        'infrastructure_servers'   => sanitize_textarea_field($_POST['p3ds_infrastructure_servers'] ?? ''),
                        'network_devices'          => sanitize_textarea_field($_POST['p3ds_network_devices'] ?? ''),
                        'components_in_scope'      => isset($_POST['p3ds_components']) ? array_map('sanitize_text_field', $_POST['p3ds_components']) : [],
                        'transaction_channels'     => isset($_POST['p3ds_channels']) ? array_map('sanitize_text_field', $_POST['p3ds_channels']) : [],
                        'third_party_integrations' => sanitize_textarea_field($_POST['p3ds_third_party_integrations'] ?? ''),
                        'security_controls'        => isset($_POST['p3ds_controls']) ? array_map('sanitize_text_field', $_POST['p3ds_controls']) : [],
                        'testing_requirements'     => isset($_POST['p3ds_testing']) ? array_map('sanitize_text_field', $_POST['p3ds_testing']) : [],
                        'testing_environment'      => sanitize_text_field($_POST['p3ds_testing_environment'] ?? ''),
                        'timeline_scope_size'      => sanitize_textarea_field($_POST['p3ds_timeline_scope_size'] ?? '')
                    ];
                }

                $encrypted_payload = Cyber_Hub_Security::encrypt(wp_json_encode($service_data, JSON_UNESCAPED_UNICODE));

                $data = [
                    'service_type'         => $submitted_service,
                    'company_name'         => Cyber_Hub_Security::encrypt(sanitize_text_field($_POST['company_name'] ?? '')),
                    'contact_person'       => Cyber_Hub_Security::encrypt(sanitize_text_field($_POST['contact_person'] ?? '')),
                    'contact_email'        => Cyber_Hub_Security::encrypt($email),
                    'contact_phone'        => Cyber_Hub_Security::encrypt($phone),
                    'company_address'      => Cyber_Hub_Security::encrypt(sanitize_textarea_field($_POST['company_address'] ?? '')),
                    'business_description' => Cyber_Hub_Security::encrypt(sanitize_textarea_field($_POST['business_description'] ?? '')),
                    'service_data'         => $encrypted_payload,
                    'nda_agreed'           => $nda_agreed,
                    'nda_version'          => CYBER_HUB_NDA_VERSION,
                    'nda_agreed_at'        => current_time('mysql'),
                    'attachment_url'       => $saved_file_path,
                    'status'               => 'new',
                    'submitted_at'         => current_time('mysql')
                ];

                $inserted = $wpdb->insert($table_name, $data);

                if ($inserted !== false) {
                    // Chỉ hủy phiên OTP sau khi hồ sơ đã được lưu thành công,
                    // tránh buộc khách làm lại toàn bộ quy trình nếu DB lỗi.
                    delete_transient('cyber_session_token_' . $email_hash);
                    $is_submitted_success = true;
                    $title_current = $service_titles[$submitted_service] ?? $submitted_service;
                    $message = '<div class="hub-alert hub-alert-success">✓ Xác thực thành công! Hồ sơ khảo sát & Thỏa thuận E-NDA đã được gửi tới Cyber Services. Chuyên gia Mr Mạnh Hùng (+84 979 875 985) sẽ phân tích và liên hệ với Quý khách trong thời gian sớm nhất.</div>';

                    $notification_data = Cyber_Hub_Security::decrypt_record((object) $data);
                    Cyber_Hub_Notifications::send_telegram((array) $notification_data, $title_current, !empty($saved_file_path));
                    Cyber_Hub_Notifications::send_client_autoresponder($notification_data->contact_email, $notification_data->contact_person, $title_current, $notification_data->company_name);
                } else {
                    // Ghi log lỗi DB thực tế để admin chẩn đoán (không lộ ra ngoài).
                    error_log('[Cyber Hub] Insert failed: ' . $wpdb->last_error . ' | Query data size: ' . strlen(wp_json_encode($data)));
                    $message = '<div class="hub-alert hub-alert-danger">Lỗi khi lưu dữ liệu. Quý khách vui lòng thử lại hoặc liên hệ Hotline Mr Mạnh Hùng: +84 979 875 985.</div>';
                }
            }
        }

        // Dọn dẹp file mồ côi: nếu bất kỳ bước validation nào thất bại
        // (hoặc insert DB lỗi), file sơ đồ đã upload không được gắn với
        // bản ghi nào và phải bị xóa khỏi kho bảo mật ngay lập tức.
        if (!$is_submitted_success && !empty($saved_file_path) && Cyber_Hub_Security::is_secure_upload_path($saved_file_path) && file_exists($saved_file_path)) {
            @unlink($saved_file_path);
        }

        // Helper phục hồi dữ liệu khi có lỗi
        $val = function($field, $default = '') use ($is_submitted_success) {
            if ($is_submitted_success) return $default;
            return isset($_POST[$field]) ? esc_attr($_POST[$field]) : $default;
        };

        $val_textarea = function($field, $default = '') use ($is_submitted_success) {
            if ($is_submitted_success) return $default;
            return isset($_POST[$field]) ? esc_textarea($_POST[$field]) : $default;
        };

        $val_checkbox = function($field, $item_val) use ($is_submitted_success) {
            if ($is_submitted_success) return '';
            if (isset($_POST[$field]) && is_array($_POST[$field]) && in_array($item_val, $_POST[$field])) {
                return 'checked';
            }
            return '';
        };

        $val_select = function($field, $option_val) use ($is_submitted_success) {
            if ($is_submitted_success) return '';
            if (isset($_POST[$field]) && $_POST[$field] === $option_val) {
                return 'selected';
            }
            return '';
        };

        // Only restore a verified state when the submitted email and token still
        // match the server-side session. Never trust a hidden POST value by itself.
        $posted_email = sanitize_email($_POST['contact_email'] ?? '');
        $posted_token = sanitize_text_field($_POST['cyber_session_token'] ?? '');
        $posted_token_hash = is_email($posted_email) ? hash_hmac('sha256', strtolower(trim($posted_email)), wp_salt('nonce')) : '';
        $stored_token = $posted_token_hash ? get_transient('cyber_session_token_' . $posted_token_hash) : '';
        $is_retained_session_valid = !$is_submitted_success && !empty($posted_token) && !empty($stored_token) && hash_equals($stored_token, hash_hmac('sha256', $posted_token, wp_salt('auth')));
        $retained_token = $is_retained_session_valid ? esc_attr($posted_token) : '';
        ?>

        <div class="hub-wizard-container">
          <div class="hub-header">
            <div class="hub-brand-mark"><span aria-hidden="true">✦</span> CYBER SERVICES VIETNAM</div>
            <h2><?php echo esc_html($service_titles[$service_type] ?? 'Phiếu Khảo Sát & Xác Định Phạm Vi Đánh Giá'); ?></h2>
            <div class="subtitle">Hoàn thiện trong khoảng 5–10 phút · Thông tin được bảo vệ và mã hóa</div>
            <div class="hub-trust-row"><span>🔒 Mã hóa dữ liệu</span><span>✉️ Xác thực email</span><span>📄 E‑NDA điện tử</span></div>
          </div>

          <?php echo $message; ?>

          <?php if (!$is_submitted_success): ?>
          <form method="POST" action="" enctype="multipart/form-data" id="cyberHubForm" novalidate>
            <?php wp_nonce_field('cyber_hub_submit_action', 'cyber_hub_nonce'); ?>
            <input type="hidden" name="service_type" value="<?php echo esc_attr($service_type); ?>">
            <input type="hidden" name="cyber_session_token" id="cyberSessionToken" value="<?php echo $retained_token; ?>">
            <input type="text" name="cyber_honeypot_check" style="display:none !important;" tabindex="-1" autocomplete="off">

            <div class="hub-progress" aria-label="Tiến trình hoàn thiện hồ sơ">
              <span class="is-active"><b>1</b> Thông tin & OTP</span><span><b>2</b> Phạm vi</span><span><b>3</b> Tài liệu</span><span><b>4</b> Xác nhận</span>
            </div>

            <!-- Phần 1: Thông tin chung & OTP -->
            <div class="hub-step-title"><span>01</span> Thông Tin Doanh Nghiệp & Xác Thực Email OTP</div>
            <div class="hub-form-group">
              <label>Tên Doanh nghiệp / Tổ chức <span class="hub-required">*</span> <span>Name of the Organization</span></label>
              <input type="text" name="company_name" class="hub-req" value="<?php echo $val('company_name'); ?>" placeholder="Ví dụ: Công ty Cổ phần ABC">
            </div>
            <div class="hub-form-group">
              <label>Địa chỉ Trụ sở chính / Địa điểm thực hiện <span class="hub-required">*</span> <span>Head Office Address</span></label>
              <input type="text" name="company_address" class="hub-req" value="<?php echo $val('company_address'); ?>" placeholder="Địa chỉ trụ sở, văn phòng...">
            </div>
            <div class="hub-form-group">
              <label>Họ tên người phụ trách & Chức vụ <span class="hub-required">*</span> <span>Contact Person Name & Designation</span></label>
              <input type="text" name="contact_person" class="hub-req" value="<?php echo $val('contact_person'); ?>" placeholder="Ví dụ: Nguyễn Văn A - Trưởng phòng An toàn thông tin">
            </div>

            <div class="hub-grid-2">
              <div class="hub-form-group">
                <label>Email Doanh nghiệp <span class="hub-required">*</span> <span id="emailVerifiedBadge" style="<?php echo !empty($retained_token) ? 'display:inline-flex;' : 'display:none;'; ?>" class="otp-verified-badge">✓ Đã xác thực</span> <span>Corporate Email (Không dùng @gmail, @yahoo...)</span></label>
                <div class="otp-wrap">
                  <input type="email" name="contact_email" id="hubEmailInput" class="hub-req" value="<?php echo $val('contact_email'); ?>" placeholder="name@company.com" <?php echo !empty($retained_token) ? 'readonly style="background-color:#f1f5f9;"' : ''; ?>>
                  <button type="button" id="btnSendOtp" class="otp-btn" style="<?php echo !empty($retained_token) ? 'display:none;' : ''; ?>">1. Nhận mã OTP</button>
                </div>
                <div id="otpStatusMsg" class="otp-status-msg" aria-live="polite"></div>
              </div>
              
              <div class="hub-form-group">
                <label>Nhập mã xác thực OTP (6 chữ số) <span class="hub-required">*</span> <span>Kiểm tra email và nhập mã để mở khóa gửi form</span></label>
                <div class="otp-wrap">
                  <input type="text" id="hubOtpInput" maxlength="6" placeholder="Nhập 6 số OTP" style="letter-spacing: 2px; font-weight: bold; text-align: center; <?php echo !empty($retained_token) ? 'background-color:#f1f5f9;' : ''; ?>" <?php echo !empty($retained_token) ? 'readonly' : ''; ?>>
                  <button type="button" id="btnVerifyOtp" class="otp-btn" style="background:#e67e22; <?php echo !empty($retained_token) ? 'display:none;' : ''; ?>">2. Xác thực OTP</button>
                </div>
                <div id="otpVerifyResult" class="otp-status-msg" aria-live="polite"><?php echo !empty($retained_token) ? '✓ Phiên xác thực email đã được thiết lập.' : ''; ?></div>
              </div>
            </div>

            <div class="hub-form-group">
              <label>Số điện thoại liên hệ <span class="hub-required">*</span> <span>Contact Number (Bắt buộc đúng 10 số di động, VD: 0979875985)</span></label>
              <input 
                type="tel" 
                name="contact_phone" 
                id="hubPhoneInput" 
                class="hub-req" 
                value="<?php echo $val('contact_phone'); ?>" 
                placeholder="09xxxxxxxx"
                maxlength="10"
                inputmode="numeric"
                autocomplete="tel"
              >
              <small id="phoneErrorMsg" style="color: #e53e3e; font-size: 12px; display: none; margin-top: 4px; font-weight: 500;">
                Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng 03, 05, 07, 08, 09.
              </small>
            </div>

            <div class="hub-form-group">
              <label>Mô tả hoạt động/quy trình kinh doanh của Công ty <span>Nature of Business / Scope Description</span></label>
              <textarea name="business_description" placeholder="Mô tả tóm tắt hoạt động, sản phẩm, dịch vụ cốt lõi..."><?php echo $val_textarea('business_description'); ?></textarea>
            </div>

            <!-- Phần 2: Nội dung câu hỏi theo từng dịch vụ -->
            <div class="hub-step-title" id="hubStep2"><span>02</span> Thông Số Kỹ Thuật Phạm Vi Đánh Giá <em>Scoping Details</em></div>

            <?php if ($service_type === 'pci_dss'): ?>
              <div class="hub-form-group">
                <label>Danh sách dịch vụ & nghiệp vụ xử lý thẻ (Quyết toán, đối chiếu, thu nhận, bồi hoàn...) <span>Card payment processing operations</span></label>
                <textarea name="pci_payment_operations" placeholder="Danh sách các dịch vụ liên quan đến thẻ tín dụng/ghi nợ..."><?php echo $val_textarea('pci_payment_operations'); ?></textarea>
              </div>
              <div class="hub-grid-2">
                <div class="hub-form-group">
                  <label>Hệ thống có Lưu trữ, Xử lý hoặc Truyền dữ liệu thẻ (CHD) không? <span>Store, process or transmit credit card data?</span></label>
                  <select name="pci_store_process_transmit">
                    <option value="Có (Lưu trữ, Xử lý và Truyền)" <?php echo $val_select('pci_store_process_transmit', 'Có (Lưu trữ, Xử lý và Truyền)'); ?>>Có (Lưu trữ, Xử lý và Truyền)</option>
                    <option value="Chỉ Xử lý/Truyền (Không lưu số thẻ)" <?php echo $val_select('pci_store_process_transmit', 'Chỉ Xử lý/Truyền (Không lưu số thẻ)'); ?>>Chỉ Xử lý/Truyền (Không lưu số thẻ)</option>
                    <option value="Không có dữ liệu thẻ" <?php echo $val_select('pci_store_process_transmit', 'Không có dữ liệu thẻ'); ?>>Không có dữ liệu thẻ</option>
                  </select>
                </div>
                <div class="hub-form-group">
                  <label>Số lượng giao dịch dự kiến mỗi năm <span>Tentative number of transactions per year</span></label>
                  <input type="text" name="pci_transaction_volume" value="<?php echo $val('pci_transaction_volume'); ?>" placeholder="Ví dụ: Dưới 1 triệu GD / Trên 6 triệu GD">
                </div>
              </div>
              <div class="hub-form-group">
                <label>Vị trí xử lý thẻ & Vị trí quản lý CNTT/Hạ tầng <span>Locations of Card Processing & IT Management</span></label>
                <input type="text" name="pci_card_processing_location" value="<?php echo $val('pci_card_processing_location'); ?>" placeholder="Vị trí xử lý thanh toán, văn phòng quản trị...">
              </div>
              <div class="hub-form-group">
                <label>Vị trí lưu trữ máy chủ (Hosting/Cloud bên thứ 3) & Trung tâm DR <span>Hosting / Cloud (AWS, Azure, Viettel...) & DR Site</span></label>
                <textarea name="pci_hosting_location_details" placeholder="Nhà cung cấp hosting, trạng thái PCI DSS của bên thứ ba, địa điểm DR..."><?php echo $val_textarea('pci_hosting_location_details'); ?></textarea>
              </div>
              <div class="hub-form-group">
                <label>Phạm vi Datacentre / Segment <span>Entire datacentre or specific segment?</span></label>
                <textarea name="pci_segment_details" placeholder="Đánh giá toàn bộ DC hay chỉ một phân vùng (segment)? Mô hình dịch vụ..."><?php echo $val_textarea('pci_segment_details'); ?></textarea>
              </div>
              <div class="hub-grid-2">
                <div class="hub-form-group">
                  <label>Danh sách IP nội bộ (Internal IPs in Scope)</label>
                  <textarea name="pci_internal_ips" placeholder="Dải IP/Subnet hệ thống nội bộ..."><?php echo $val_textarea('pci_internal_ips'); ?></textarea>
                </div>
                <div class="hub-form-group">
                  <label>Danh sách IP Public (External IPs / ASV Scan)</label>
                  <textarea name="pci_public_ips" placeholder="Dải IP Public cần rà quét ASV..."><?php echo $val_textarea('pci_public_ips'); ?></textarea>
                </div>
              </div>

            <?php elseif ($service_type === 'soc_report'): ?>
              <div class="hub-grid-2">
                <div class="hub-form-group">
                  <label>Tiêu chuẩn báo cáo cân nhắc <span>SOC 1 / SOC 2 / ISAE 3000/3402</span></label>
                  <select name="soc_standard">
                    <option value="SOC 2" <?php echo $val_select('soc_standard', 'SOC 2'); ?>>SOC 2</option>
                    <option value="SOC 1 / SSAE 18" <?php echo $val_select('soc_standard', 'SOC 1 / SSAE 18'); ?>>SOC 1 / SSAE 18</option>
                    <option value="ISAE 3000 / ISAE 3402" <?php echo $val_select('soc_standard', 'ISAE 3000 / ISAE 3402'); ?>>ISAE 3000 / ISAE 3402</option>
                    <option value="SOC 3" <?php echo $val_select('soc_standard', 'SOC 3'); ?>>SOC 3</option>
                    <option value="Chưa xác định / Cần tư vấn" <?php echo $val_select('soc_standard', 'Chưa xác định / Cần tư vấn'); ?>>Chưa xác định / Cần tư vấn</option>
                  </select>
                </div>
                <div class="hub-form-group">
                  <label>Loại Báo cáo (Type I hay Type II) <span>Type I (Thời điểm) hay Type II (Khoảng thời gian)</span></label>
                  <input type="text" name="soc_report_type_period" value="<?php echo $val('soc_report_type_period'); ?>" placeholder="VD: Type II (Giai đoạn 6 tháng: 01/01 - 30/06)">
                </div>
              </div>
              <div class="hub-form-group">
                <label>Các nguyên tắc tin cậy áp dụng (Trust Services Criteria - SOC 2)</label>
                <div class="hub-checkbox-group">
                  <label class="hub-checkbox-item"><input type="checkbox" name="soc_principles[]" value="Security" <?php echo $val_checkbox('soc_principles', 'Security') ?: 'checked'; ?>> Security (Bảo mật)</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="soc_principles[]" value="Availability" <?php echo $val_checkbox('soc_principles', 'Availability'); ?>> Availability (Tính sẵn sàng)</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="soc_principles[]" value="Confidentiality" <?php echo $val_checkbox('soc_principles', 'Confidentiality'); ?>> Confidentiality (Tính bảo mật)</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="soc_principles[]" value="Privacy" <?php echo $val_checkbox('soc_principles', 'Privacy'); ?>> Privacy (Tính riêng tư)</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="soc_principles[]" value="Processing Integrity" <?php echo $val_checkbox('soc_principles', 'Processing Integrity'); ?>> Processing Integrity (Toàn vẹn)</label>
                </div>
              </div>
              <div class="hub-grid-2">
                <div class="hub-form-group">
                  <label>Cần đánh giá sẵn sàng (Readiness/Pre-assessment)?</label>
                  <select name="soc_readiness_needed">
                    <option value="Có" <?php echo $val_select('soc_readiness_needed', 'Có'); ?>>Có (Cần đánh giá thử nghiệm trước)</option>
                    <option value="Không" <?php echo $val_select('soc_readiness_needed', 'Không'); ?>>Không (Lập báo cáo chính thức)</option>
                  </select>
                </div>
                <div class="hub-form-group">
                  <label>Số lượng nhân sự thực hiện dịch vụ trong scope</label>
                  <input type="text" name="soc_personnel_count" value="<?php echo $val('soc_personnel_count'); ?>" placeholder="Ví dụ: 35 nhân sự">
                </div>
              </div>
              <div class="hub-form-group">
                <label>Phạm vi dịch vụ & Quy trình thuê ngoài (Subservice Organization/Third-party)</label>
                <textarea name="soc_subservice_org_details" placeholder="Có thuê ngoài quy trình/hạ tầng cho bên thứ ba (AWS, Datacenter...) không?"><?php echo $val_textarea('soc_subservice_org_details'); ?></textarea>
              </div>
              <div class="hub-form-group">
                <label>Hạ tầng CNTT (Mạng, OS, Database - Số nodes) & Công cụ SDLC</label>
                <textarea name="soc_tech_nodes_infrastructure" placeholder="Mô tả số lượng server/nodes, DB, OS và công cụ quản lý SDLC..."><?php echo $val_textarea('soc_tech_nodes_infrastructure'); ?></textarea>
              </div>
              <div class="hub-form-group">
                <label>Mô hình Cloud Offering (Nếu có cung cấp dịch vụ Cloud)</label>
                <div class="hub-checkbox-group">
                  <label class="hub-checkbox-item"><input type="checkbox" name="soc_cloud_models[]" value="SaaS" <?php echo $val_checkbox('soc_cloud_models', 'SaaS'); ?>> SaaS</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="soc_cloud_models[]" value="PaaS" <?php echo $val_checkbox('soc_cloud_models', 'PaaS'); ?>> PaaS</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="soc_cloud_models[]" value="IaaS" <?php echo $val_checkbox('soc_cloud_models', 'IaaS'); ?>> IaaS</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="soc_cloud_deploy[]" value="Public Cloud" <?php echo $val_checkbox('soc_cloud_deploy', 'Public Cloud'); ?>> Public Cloud</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="soc_cloud_deploy[]" value="Private Cloud" <?php echo $val_checkbox('soc_cloud_deploy', 'Private Cloud'); ?>> Private Cloud</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="soc_cloud_deploy[]" value="Hybrid Cloud" <?php echo $val_checkbox('soc_cloud_deploy', 'Hybrid Cloud'); ?>> Hybrid Cloud</label>
                </div>
              </div>

            <?php elseif ($service_type === 'iso_standards'): ?>
              <div class="hub-form-group">
                <label>Tiêu chuẩn ISO cần đánh giá / chứng nhận <span>Chọn 1 hoặc nhiều tiêu chuẩn</span></label>
                <div class="hub-checkbox-group">
                  <label class="hub-checkbox-item"><input type="checkbox" name="iso_targets[]" value="ISO/IEC 27001" <?php echo $val_checkbox('iso_targets', 'ISO/IEC 27001') ?: 'checked'; ?>> ISO/IEC 27001 (ISMS)</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="iso_targets[]" value="ISO/IEC 27017" <?php echo $val_checkbox('iso_targets', 'ISO/IEC 27017'); ?>> ISO/IEC 27017 (Cloud Security)</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="iso_targets[]" value="ISO/IEC 27018" <?php echo $val_checkbox('iso_targets', 'ISO/IEC 27018'); ?>> ISO/IEC 27018 (Cloud Privacy)</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="iso_targets[]" value="ISO 9001" <?php echo $val_checkbox('iso_targets', 'ISO 9001'); ?>> ISO 9001 (QMS)</label>
                </div>
              </div>
              <div class="hub-form-group">
                <label>Mô tả các hoạt động/quy trình trọng yếu & Nghĩa vụ pháp lý liên quan <span>Critical activities/processes</span></label>
                <textarea name="iso_critical_processes" placeholder="Quy trình phát triển phần mềm, vận hành hệ thống, trung tâm dữ liệu..."><?php echo $val_textarea('iso_critical_processes'); ?></textarea>
              </div>
              <div class="hub-grid-2">
                <div class="hub-form-group">
                  <label>Tổng số nhân sự (Employees)</label>
                  <input type="text" name="iso_employee_count" value="<?php echo $val('iso_employee_count'); ?>" placeholder="Ví dụ: 120 người">
                </div>
                <div class="hub-form-group">
                  <label>Số lượng quy trình & Ứng dụng</label>
                  <input type="text" name="iso_process_count" value="<?php echo $val('iso_process_count'); ?>" placeholder="Ví dụ: 8 quy trình, 5 ứng dụng">
                </div>
              </div>
              <div class="hub-form-group">
                <label>Tổng số tài sản CNTT (IT Assets overall)</label>
                <input type="text" name="iso_it_assets_count" value="<?php echo $val('iso_it_assets_count'); ?>" placeholder="Ví dụ: 20 Servers, 10 Network Devices, 150 Laptops">
              </div>
              <div class="hub-form-group">
                <label>Thông tin Trụ sở chính & Các chi nhánh (Số user, DC, Server, Laptop)</label>
                <textarea name="iso_head_office_matrix" placeholder="Trụ sở chính: ... / Chi nhánh khác: ..."><?php echo $val_textarea('iso_head_office_matrix'); ?></textarea>
              </div>
              <div class="hub-form-group">
                <label>Quy trình thuê ngoài & Thông tin địa điểm dự phòng thảm họa (DR Site)</label>
                <textarea name="iso_dr_site_details" placeholder="Các dịch vụ thuê ngoài và thông tin địa điểm DR..."><?php echo $val_textarea('iso_dr_site_details'); ?></textarea>
              </div>

            <?php elseif ($service_type === 'iso_42001'): ?>
              <div class="hub-grid-2">
                <div class="hub-form-group">
                  <label>Lĩnh vực ngành nghề & Số địa điểm <span>Industry Sector & Locations</span></label>
                  <input type="text" name="ai_industry_sector" value="<?php echo $val('ai_industry_sector'); ?>" placeholder="Ví dụ: Fintech, Y tế, EdTech...">
                </div>
                <div class="hub-form-group">
                  <label>Các ca sử dụng AI (AI Use Cases)</label>
                  <div class="hub-checkbox-group">
                    <label class="hub-checkbox-item"><input type="checkbox" name="ai_use_cases[]" value="Fraud detection" <?php echo $val_checkbox('ai_use_cases', 'Fraud detection'); ?>> Phát hiện gian lận</label>
                    <label class="hub-checkbox-item"><input type="checkbox" name="ai_use_cases[]" value="Chatbots" <?php echo $val_checkbox('ai_use_cases', 'Chatbots'); ?>> Chatbots / LLM</label>
                    <label class="hub-checkbox-item"><input type="checkbox" name="ai_use_cases[]" value="Recommendation engines" <?php echo $val_checkbox('ai_use_cases', 'Recommendation engines'); ?>> Gợi ý sản phẩm</label>
                    <label class="hub-checkbox-item"><input type="checkbox" name="ai_use_cases[]" value="Predictive analytics" <?php echo $val_checkbox('ai_use_cases', 'Predictive analytics'); ?>> Phân tích dự báo</label>
                    <label class="hub-checkbox-item"><input type="checkbox" name="ai_use_cases[]" value="Automation tools" <?php echo $val_checkbox('ai_use_cases', 'Automation tools'); ?>> Tự động hóa</label>
                  </div>
                </div>
              </div>
              <div class="hub-form-group">
                <label>Danh mục hệ thống AI trong phạm vi (AI Systems Inventory)</label>
                <textarea name="ai_systems_inventory" placeholder="Tên mô hình AI | Mục đích sử dụng | Mức độ rủi ro | Môi trường lưu trữ..."><?php echo $val_textarea('ai_systems_inventory'); ?></textarea>
              </div>
              <div class="hub-grid-2">
                <div class="hub-form-group">
                  <label>Quản trị AI (AI Governance Framework/Policy)</label>
                  <select name="ai_governance_framework">
                    <option value="Đã có khung quản trị & Chính sách AI" <?php echo $val_select('ai_governance_framework', 'Đã có khung quản trị & Chính sách AI'); ?>>Đã có khung quản trị & Chính sách AI</option>
                    <option value="Đang xây dựng" <?php echo $val_select('ai_governance_framework', 'Đang xây dựng'); ?>>Đang xây dựng</option>
                    <option value="Chưa có" <?php echo $val_select('ai_governance_framework', 'Chưa có'); ?>>Chưa có</option>
                  </select>
                </div>
                <div class="hub-form-group">
                  <label>Sự giám sát của con người (Human Oversight)</label>
                  <select name="ai_human_oversight">
                    <option value="Con người có thể xem xét & can thiệp quyết định AI" <?php echo $val_select('ai_human_oversight', 'Con người có thể xem xét & can thiệp quyết định AI'); ?>>Con người có thể xem xét & can thiệp quyết định AI</option>
                    <option value="Tự động hoàn toàn" <?php echo $val_select('ai_human_oversight', 'Tự động hoàn toàn'); ?>>Tự động hoàn toàn</option>
                  </select>
                </div>
              </div>
              <div class="hub-form-group">
                <label>Dữ liệu sử dụng cho AI & Quản lý vòng đời (Data Sources & Lifecycle)</label>
                <textarea name="ai_data_types_sources" placeholder="Loại dữ liệu, nguồn dữ liệu, quy trình huấn luyện & kiểm thử..."><?php echo $val_textarea('ai_data_types_sources'); ?></textarea>
              </div>
              <div class="hub-form-group">
                <label>Dịch vụ AI bên thứ ba & Hạ tầng triển khai (AWS Bedrock, OpenAI, On-prem...)</label>
                <textarea name="ai_infrastructure" placeholder="Nhà cung cấp đám mây, server GPU, API bên thứ ba sử dụng..."><?php echo $val_textarea('ai_infrastructure'); ?></textarea>
              </div>

            <?php elseif ($service_type === 'pci_3ds'): ?>
              <div class="hub-grid-2">
                <div class="hub-form-group">
                  <label>Phiên bản triển khai 3DS <span>3DS Implementation Overview</span></label>
                  <select name="p3ds_implementation_version">
                    <option value="EMV 3-D Secure 2.x" <?php echo $val_select('p3ds_implementation_version', 'EMV 3-D Secure 2.x'); ?>>EMV 3-D Secure 2.x</option>
                    <option value="3-D Secure 1.0" <?php echo $val_select('p3ds_implementation_version', '3-D Secure 1.0'); ?>>3-D Secure 1.0</option>
                    <option value="Cả hai phiên bản" <?php echo $val_select('p3ds_implementation_version', 'Cả hai phiên bản'); ?>>Cả hai phiên bản</option>
                  </select>
                </div>
                <div class="hub-form-group">
                  <label>Vai trò trong hệ sinh thái 3DS (Role in 3DS Ecosystem)</label>
                  <div class="hub-checkbox-group">
                    <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_roles[]" value="Merchant" <?php echo $val_checkbox('p3ds_roles', 'Merchant'); ?>> Merchant</label>
                    <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_roles[]" value="Issuer" <?php echo $val_checkbox('p3ds_roles', 'Issuer'); ?>> Issuer</label>
                    <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_roles[]" value="Acquirer" <?php echo $val_checkbox('p3ds_roles', 'Acquirer'); ?>> Acquirer</label>
                    <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_roles[]" value="3DS Server Provider" <?php echo $val_checkbox('p3ds_roles', '3DS Server Provider'); ?>> 3DS Server Provider</label>
                    <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_roles[]" value="ACS Provider" <?php echo $val_checkbox('p3ds_roles', 'ACS Provider'); ?>> ACS Provider</label>
                    <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_roles[]" value="Directory Server" <?php echo $val_checkbox('p3ds_roles', 'Directory Server'); ?>> Directory Server</label>
                  </div>
                </div>
              </div>
              <div class="hub-form-group">
                <label>Thành phần 3DS triển khai (Components in Scope)</label>
                <div class="hub-checkbox-group">
                  <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_components[]" value="3DS Server" <?php echo $val_checkbox('p3ds_components', '3DS Server'); ?>> 3DS Server</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_components[]" value="Access Control Server (ACS)" <?php echo $val_checkbox('p3ds_components', 'Access Control Server (ACS)'); ?>> Access Control Server (ACS)</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_components[]" value="Directory Server" <?php echo $val_checkbox('p3ds_components', 'Directory Server'); ?>> Directory Server</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_components[]" value="SDK / Mobile" <?php echo $val_checkbox('p3ds_components', 'SDK / Mobile'); ?>> SDK / Mobile Integration</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_components[]" value="Payment Gateway" <?php echo $val_checkbox('p3ds_components', 'Payment Gateway'); ?>> Cổng thanh toán (Gateway)</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_components[]" value="Risk Engine" <?php echo $val_checkbox('p3ds_components', 'Risk Engine'); ?>> Risk Engine</label>
                </div>
              </div>
              <div class="hub-form-group">
                <label>Hạ tầng & Thiết bị Mạng trong Scope (App Server, DB, Firewall, WAF...)</label>
                <textarea name="p3ds_infrastructure_servers" placeholder="Chi tiết số lượng máy chủ ứng dụng, máy chủ xác thực, DB, Firewall..."><?php echo $val_textarea('p3ds_infrastructure_servers'); ?></textarea>
              </div>
              <div class="hub-form-group">
                <label>Kiểm soát bảo mật & Yêu cầu kiểm thử an toàn (Testing Requirements)</label>
                <div class="hub-checkbox-group">
                  <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_testing[]" value="Vulnerability Assessment" <?php echo $val_checkbox('p3ds_testing', 'Vulnerability Assessment'); ?>> Rà quét lỗ hổng (VA)</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_testing[]" value="Penetration Testing" <?php echo $val_checkbox('p3ds_testing', 'Penetration Testing'); ?>> Thử nghiệm xâm nhập (Pentest)</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_testing[]" value="Web/API Security Testing" <?php echo $val_checkbox('p3ds_testing', 'Web/API Security Testing'); ?>> Kiểm thử Web & API</label>
                  <label class="hub-checkbox-item"><input type="checkbox" name="p3ds_testing[]" value="Architecture Review" <?php echo $val_checkbox('p3ds_testing', 'Architecture Review'); ?>> Đánh giá kiến trúc</label>
                </div>
              </div>
            <?php endif; ?>

            <!-- Phần 3: Đính kèm Sơ đồ -->
            <div class="hub-step-title"><span>03</span> Sơ Đồ Mạng & Luồng Dữ Liệu <em>Architecture Diagram</em></div>
            <div class="hub-form-group hub-upload-group">
              <label>Đính kèm sơ đồ kiến trúc hệ thống / Luồng dữ liệu <span>Upload Network Diagram (Chấp nhận .pdf, .jpg, .png – Tối đa 10MB)</span></label>
              <input type="file" name="diagram_file" id="hubFileInput" accept=".pdf,.jpg,.jpeg,.png">
              <div id="hubFileHint" class="hub-file-hint">Không bắt buộc · File được lưu trong kho bảo mật riêng.</div>
            </div>

            <!-- Phần 4: Thỏa thuận Bảo mật Thông tin (E-NDA) -->
            <div class="hub-step-title"><span>04</span> Thỏa Thuận Bảo Mật Thông Tin Điện Tử <em>E‑NDA</em></div>
            <div class="hub-nda-box">
              <h4>THỎA THUẬN BẢO MẬT THÔNG TIN ĐIỆN TỬ (NON-DISCLOSURE AGREEMENT)</h4>
              <p><b>Điều 1 (Thông tin Bí mật):</b> Toàn bộ dữ liệu kỹ thuật, dải IP, sơ đồ mạng, hạ tầng máy chủ, quy trình vận hành và thông tin liên hệ do Khách hàng cung cấp trong biểu mẫu này được xác định là Thông tin Bí mật.</p>
              <p><b>Điều 2 (Nghĩa vụ Bảo mật của Cyber Services):</b> Cyber Services cam kết áp dụng các biện pháp an ninh kỹ thuật cao nhất (mã hóa dữ liệu AES-256) nhằm bảo vệ Thông tin Bí mật; chỉ sử dụng cho mục đích khảo sát, phân tích phạm vi và tư vấn phương án kỹ thuật; không tiết lộ cho bất kỳ bên thứ ba nào khi chưa có sự đồng ý bằng văn bản của Khách hàng.</p>
              <p><b>Điều 3 (Quyền sử dụng thông tin của Cyber Services):</b> Khách hàng đồng ý cho phép Cyber Services được chia sẻ thông tin cho đội ngũ chuyên gia kỹ thuật và kiểm toán viên (QSA/Lead Auditor) nội bộ trên nguyên tắc 'cần biết' để lập hồ sơ đánh giá.</p>
              <p><b>Điều 4 (Giới hạn trách nhiệm):</b> Việc tiếp nhận khảo sát là hoạt động hỗ trợ thẩm định ban đầu, không cấu thành hợp đồng dịch vụ chính thức. Cyber Services không chịu trách nhiệm đối với các rủi ro, lỗ hổng bảo mật sẵn có trong hệ thống của Khách hàng trước và trong quá trình khảo sát.</p>
              <p><b>Điều 5 (Thời hạn & Giá trị pháp lý):</b> Thỏa thuận có hiệu lực 03 năm kể từ thời điểm gửi dữ liệu trực tuyến và có giá trị pháp lý ràng buộc bình đẳng giữa hai bên theo quy định của Luật Giao dịch điện tử và Bộ luật Dân sự Việt Nam.</p>
            </div>

            <div class="hub-form-group hub-nda-consent">
              <label>
                <input type="checkbox" name="nda_agreement" id="ndaAgreementCheckbox" value="1" <?php echo !empty($_POST['nda_agreement']) ? 'checked' : ''; ?> style="width: auto;">
                Tôi xác nhận đã đọc, hiểu rõ và đồng ý với các điều khoản trong Thỏa thuận bảo mật thông tin (E-NDA) nêu trên.
              </label>
            </div>

            <button type="submit" class="hub-btn-submit" id="hubSubmitBtn" <?php echo empty($retained_token) ? 'disabled' : ''; ?>>
              <?php echo !empty($retained_token) ? 'Xác Nhận E-NDA & Gửi Phiếu Khảo Sát' : 'Vui Lòng Xác Thực Email OTP Để Gửi Phiếu'; ?>
            </button>
          </form>
          <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
Cyber_Hub_Form_Render::init();
