<?php
if (!defined('ABSPATH')) exit;

class Cyber_Hub_Ajax_OTP {

    private static function identifier($value) {
        return hash_hmac('sha256', strtolower(trim($value)), wp_salt('nonce'));
    }

    private static function client_ip_key() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        return self::identifier($ip);
    }

    public static function init() {
        add_action('wp_ajax_cyber_send_otp', [__CLASS__, 'send_otp']);
        add_action('wp_ajax_nopriv_cyber_send_otp', [__CLASS__, 'send_otp']);
        add_action('wp_ajax_cyber_verify_otp', [__CLASS__, 'verify_otp']);
        add_action('wp_ajax_nopriv_cyber_verify_otp', [__CLASS__, 'verify_otp']);
    }

    public static function send_otp() {
        $_POST = wp_unslash($_POST);
        check_ajax_referer('cyber_hub_ajax_nonce', 'security');

        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Địa chỉ email không hợp lệ.']);
        }

        $blocked_domains = [
            'gmail.com', 'yahoo.com', 'yahoo.com.vn', 'hotmail.com', 'outlook.com', 
            'live.com', 'icloud.com', 'mail.com', 'yandex.com', 'zoho.com', 'protonmail.com', 'aol.com'
        ];
        $email_domain = strtolower(substr(strrchr($email, "@"), 1));
        if (in_array($email_domain, $blocked_domains)) {
            wp_send_json_error(['message' => 'Hệ thống chỉ chấp nhận Email Doanh nghiệp (không dùng @' . esc_html($email_domain) . ').']);
        }

        $email_hash = self::identifier($email);
        $rate_key = 'cyber_otp_rate_' . $email_hash;
        $ip_rate_key = 'cyber_otp_ip_rate_' . self::client_ip_key();
        $global_rate_key = 'cyber_otp_global_rate';
        if (get_transient($rate_key)) {
            wp_send_json_error(['message' => 'Vui lòng đợi 60 giây trước khi yêu cầu mã mới.']);
        }
        $ip_requests = (int) get_transient($ip_rate_key);
        if ($ip_requests >= 5) {
            wp_send_json_error(['message' => 'Địa chỉ mạng này đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau 15 phút.']);
        }
        $global_requests = (int) get_transient($global_rate_key);
        if ($global_requests >= 100) {
            wp_send_json_error(['message' => 'Hệ thống đang giới hạn yêu cầu OTP. Vui lòng thử lại sau.']);
        }

        try {
            $otp = (string) random_int(100000, 999999);
        } catch (Exception $e) {
            $otp = (string) wp_rand(100000, 999999);
        }

        set_transient('cyber_email_otp_' . $email_hash, hash_hmac('sha256', $otp, wp_salt('auth')), 600);
        set_transient($rate_key, 1, 60);
        set_transient($ip_rate_key, $ip_requests + 1, 15 * MINUTE_IN_SECONDS);
        set_transient($global_rate_key, $global_requests + 1, HOUR_IN_SECONDS);
        delete_transient('cyber_otp_attempts_' . $email_hash);
        delete_transient('cyber_session_token_' . $email_hash);

        $subject = 'Cyber Services - Ma phien lam viec khao sat [' . $otp . ']';
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Cyber Services Vietnam <contacts@cyberservices.vn>',
            'Reply-To: contacts@cyberservices.vn'
        ];

        $body = "
        <!DOCTYPE html>
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='margin: 0; padding: 20px 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; color: #333;'>
            <table align='center' border='0' cellpadding='0' cellspacing='0' width='100%' style='max-width: 560px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; margin: 0 auto;'>
                <tr><td style='background-color: #0b3c5d; padding: 20px; text-align: center;'><h2 style='margin: 0; color: #fff; font-size: 18px; text-transform: uppercase;'>Cyber Services Vietnam</h2></td></tr>
                <tr><td style='padding: 25px 25px 15px 25px;'>
                    <p style='margin-top: 0; font-size: 14px; color: #2d3748;'>Kính gửi Quý khách hàng,</p>
                    <p style='font-size: 14px; color: #2d3748;'>Dưới đây là mã phiên làm việc dùng để tiếp tục hoàn thiện phiếu khảo sát:</p>
                    <div style='text-align: center; margin: 20px 0;'>
                        <span style='display: inline-block; font-size: 26px; font-weight: bold; letter-spacing: 5px; color: #0b3c5d; background-color: #f0f4f8; padding: 12px 24px; border: 1px dashed #0b3c5d; border-radius: 4px;'>" . esc_html($otp) . "</span>
                    </div>
                    <p style='font-size: 13px; color: #718096;'>Mã có giá trị sử dụng trong 10 phút. Nếu Quý khách không thực hiện thao tác này, xin vui lòng bỏ qua.</p>
                </td></tr>
                <tr><td style='background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px; font-size: 12px; line-height: 1.6; color: #64748b;'>
                    <p style='margin: 0 0 4px 0; font-weight: bold; color: #334155;'>CÔNG TY CỔ PHẦN CYBER SERVICES VIỆT NAM</p>
                    <p style='margin: 0 0 2px 0;'>Địa chỉ: LP08, P. Nguyễn Thị Duệ, Cầu Giấy, Hà Nội</p>
                    <p style='margin: 0 0 2px 0;'>Hotline: +84 979 875 985 | Email: contacts@cyberservices.vn</p>
                    <p style='margin: 0 0 8px 0;'>Website: <a href='https://cyberservices.vn' style='color: #0b3c5d;'>cyberservices.vn</a></p>
                </td></tr>
            </table>
        </body>
        </html>";

        if (@wp_mail($email, $subject, $body, $headers)) {
            wp_send_json_success(['message' => 'Đã gửi mã đến email ' . esc_html($email) . '. Vui lòng kiểm tra hộp thư.']);
        } else {
            wp_send_json_error(['message' => 'Không thể gửi email. Vui lòng thử lại sau.']);
        }
    }

    public static function verify_otp() {
        $_POST = wp_unslash($_POST);
        check_ajax_referer('cyber_hub_ajax_nonce', 'security');

        $email = sanitize_email($_POST['email'] ?? '');
        $otp_entered = sanitize_text_field(trim($_POST['otp'] ?? ''));

        if (!is_email($email) || empty($otp_entered)) {
            wp_send_json_error(['message' => 'Vui lòng nhập đầy đủ Email và mã OTP.']);
        }

        $email_hash = self::identifier($email);
        $cached_otp = get_transient('cyber_email_otp_' . $email_hash);
        $attempts_key = 'cyber_otp_attempts_' . $email_hash;
        $attempts = (int) get_transient($attempts_key);

        if (empty($cached_otp)) {
            wp_send_json_error(['message' => 'Mã đã hết hạn hoặc chưa được tạo.']);
        }

        if ($attempts >= 5) {
            delete_transient('cyber_email_otp_' . $email_hash);
            delete_transient($attempts_key);
            wp_send_json_error(['message' => 'Bạn đã nhập sai quá 5 lần. Mã đã bị hủy.']);
        }

        if (hash_equals((string)$cached_otp, hash_hmac('sha256', $otp_entered, wp_salt('auth')))) {
            $session_token = wp_generate_password(48, false);
            set_transient('cyber_session_token_' . $email_hash, hash_hmac('sha256', $session_token, wp_salt('auth')), 1800);
            
            delete_transient('cyber_email_otp_' . $email_hash);
            delete_transient($attempts_key);

            wp_send_json_success([
                'message' => '✓ Xác thực Email thành công! Bạn có thể gửi phiếu khảo sát.',
                'session_token' => $session_token
            ]);
        } else {
            $attempts++;
            set_transient($attempts_key, $attempts, 600);
            $remaining = 5 - $attempts;
            wp_send_json_error(['message' => "Mã xác thực không chính xác. Còn lại {$remaining} lần thử."]);
        }
    }
}
Cyber_Hub_Ajax_OTP::init();
