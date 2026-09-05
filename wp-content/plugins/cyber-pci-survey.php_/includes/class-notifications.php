<?php
if (!defined('ABSPATH')) exit;

class Cyber_Hub_Notifications {

    public static function send_telegram($data, $service_title, $has_attachment = false) {
        $token = defined('CYBER_TELE_TOKEN') ? CYBER_TELE_TOKEN : get_option('cyber_hub_tele_token', '');
        $chat_id = defined('CYBER_TELE_CHATID') ? CYBER_TELE_CHATID : get_option('cyber_hub_tele_chatid', '');

        if (empty($token) || empty($chat_id)) return;

        // Plain text avoids user-provided Markdown changing the notification.
        $text  = "🚨 [HỒ SƠ KHẢO SÁT MỚI & XÁC NHẬN E-NDA] 🚨\n\n";
        $text .= "🛡️ Dịch vụ: " . $service_title . "\n";
        $text .= "🏢 Doanh nghiệp: " . $data['company_name'] . "\n";
        $text .= "👤 Người phụ trách: " . $data['contact_person'] . "\n";
        $text .= "📞 Số điện thoại: " . $data['contact_phone'] . "\n";
        $text .= "📧 Email: " . $data['contact_email'] . " (Đã xác thực OTP)\n";
        $text .= "📍 Địa chỉ: " . $data['company_address'] . "\n";
        $text .= "🤝 E-NDA: Đã đồng ý điều khoản bảo mật\n";
        $text .= "📎 File sơ đồ: " . ($has_attachment ? "Đã lưu trữ an toàn" : "Không đính kèm") . "\n";
        $text .= "\n⏰ Thời gian: " . current_time('d/m/Y H:i:s');

        wp_remote_post("https://api.telegram.org/bot{$token}/sendMessage", [
            'body' => [
                'chat_id' => $chat_id,
                'text' => $text,
                'disable_web_page_preview' => true
            ],
            'timeout' => 10
        ]);
    }

    public static function send_client_autoresponder($client_email, $client_name, $service_title, $company_name) {
        $subject = 'Cyber Services - Tiep nhan thong tin phieu khao sat: ' . $service_title;
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
            <table align='center' border='0' cellpadding='0' cellspacing='0' width='100%' style='max-width: 600px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; margin: 0 auto;'>
                <tr><td style='background-color: #0b3c5d; padding: 22px; text-align: center;'><h2 style='margin: 0; color: #fff; font-size: 19px; text-transform: uppercase;'>CYBER SERVICES VIỆT NAM</h2></td></tr>
                <tr><td style='padding: 25px 25px 15px 25px; line-height: 1.6; color: #2d3748;'>
                    <p style='margin-top: 0;'>Kính gửi <b>" . esc_html($client_name) . "</b> (Đơn vị: <b>" . esc_html($company_name) . "</b>),</p>
                    <p>Hệ thống đã tiếp nhận hồ sơ khảo sát: <b style='color: #0b3c5d;'>" . esc_html($service_title) . "</b> kèm thỏa thuận E-NDA do Quý đơn vị hoàn tất trực tuyến.</p>
                    <div style='background-color: #f8fafc; border-left: 4px solid #0b3c5d; padding: 12px 16px; margin: 18px 0;'>
                        <p style='margin: 0;'><b>Trạng thái:</b> Đã tiếp nhận hồ sơ</p>
                        <p style='margin: 5px 0 0 0;'><b>Chuyên gia phụ trách:</b> Mr Mạnh Hùng – Hotline: <b>+84 979 875 985</b></p>
                    </div>
                    <p>Đội ngũ chuyên gia kỹ thuật sẽ liên hệ trao đổi phương án với Quý đơn vị trong thời gian sớm nhất.</p>
                </td></tr>
                <tr><td style='background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px; font-size: 12px; line-height: 1.6; color: #64748b;'>
                    <p style='margin: 0 0 4px 0; font-weight: bold; color: #334155;'>CÔNG TY CỔ PHẦN CYBER SERVICES VIỆT NAM</p>
                    <p style='margin: 0 0 2px 0;'>Địa chỉ: LP08, P. Nguyễn Thị Duệ, Cầu Giấy, Hà Nội</p>
                    <p style='margin: 0 0 2px 0;'>Hotline: +84 979 875 985 | Email: contacts@cyberservices.vn</p>
                </td></tr>
            </table>
        </body>
        </html>";

        @wp_mail($client_email, $subject, $body, $headers);
    }
}
