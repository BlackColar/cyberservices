<?php
/**
 * Kira AI SEO Auditor - API Client (độc lập)
 *
 * Tự chứa toàn bộ logic gọi API Kira AI, KHÔNG phụ thuộc plugin Kira AI cũ.
 * Đọc API key & model từ cùng option để người dùng không cần cấu hình lại.
 *
 * @package Kira_AI_SA
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kira_SA_Api_Client
{
    const BASE_URL = 'https://kiraai.vn';

    /**
     * Gọi chat completions API.
     *
     * @param string $prompt          User prompt.
     * @param string $system_msg      System message (optional).
     * @param array  $additional_args Extra payload args (e.g. response_format).
     * @param string $api_key         Override API key (optional).
     * @param string $model           Override model (optional).
     * @return string|WP_Error Response text.
     */
    public function chat($prompt, $system_msg = '', $additional_args = array(), $api_key = '', $model = '')
    {
        $api_key = $api_key ? $api_key : get_option('kira_ai_api_key', '');
        if (empty($api_key)) {
            return new WP_Error('kira_sa_no_api_key', 'Vui lòng cấu hình API Key trong plugin Kira AI hoặc trang cấu hình.');
        }

        $model = $model ? $model : get_option('kira_ai_text_model', 'kira-3.5-flash');

        $endpoint = rtrim(self::BASE_URL, '/') . '/api/v1/chat/completions';

        $messages = array();
        if (!empty($system_msg)) {
            $messages[] = array(
                'role'    => 'system',
                'content' => $system_msg,
            );
        }
        $messages[] = array(
            'role'    => 'user',
            'content' => $prompt,
        );

        $payload = array(
            'model'       => $model,
            'messages'    => $messages,
            'stream'      => false,
            'temperature' => 0.7,
        );
        if (!empty($additional_args)) {
            $payload = array_merge($payload, $additional_args);
        }

        $max_retries = 2;
        $last_error  = null;

        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_post($endpoint, array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ),
                'body'    => wp_json_encode($payload),
                'timeout' => 120,
            ));

            if (is_wp_error($response)) {
                $last_error = $response;
                if ($attempt < $max_retries) {
                    usleep(500000 * ($attempt + 1));
                }
                continue;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body          = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                $data = json_decode($body, true);
                if (isset($data['error'])) {
                    return new WP_Error('kira_sa_error', $data['error']['message'] ?? 'Lỗi không xác định từ Kira AI.');
                }
                return $data['choices'][0]['message']['content'] ?? '';
            }

            // Retry on server errors (5xx)
            if ($response_code >= 500 && $attempt < $max_retries) {
                usleep(500000 * ($attempt + 1));
                continue;
            }

            $data    = json_decode($body, true);
            $err_msg = isset($data['error']['message']) ? $data['error']['message'] : '';
            if (empty($err_msg)) {
                $err_msg = 'Máy chủ phản hồi mã lỗi HTTP: ' . $response_code;
            }
            return new WP_Error('kira_sa_http_error', $err_msg);
        }

        if ($last_error) {
            return $last_error;
        }

        return new WP_Error('kira_sa_unknown_error', 'Lỗi không xác định khi gọi API Kira AI.');
    }

    /**
     * Parse JSON từ phản hồi text của LLM (hỗ trợ markdown code block).
     *
     * @param string $raw_content
     * @return array|false
     */
    public function clean_json($raw_content)
    {
        $clean_content = trim($raw_content);

        if (preg_match('/```json\s*([\s\S]*?)\s*```/i', $clean_content, $matches)) {
            $clean_content = trim($matches[1]);
        } elseif (preg_match('/```\s*([\s\S]*?)\s*```/i', $clean_content, $matches)) {
            $clean_content = trim($matches[1]);
        }

        $first_brace = strpos($clean_content, '{');
        $last_brace  = strrpos($clean_content, '}');
        if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
            $clean_content = substr($clean_content, $first_brace, $last_brace - $first_brace + 1);
        }

        $decoded = json_decode($clean_content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $escaped_content = preg_replace_callback(
            '/"([^"\\\\]|\\\\.)*"/',
            function ($matches) {
                return str_replace(
                    array("\r", "\n", "\t"),
                    array('', '\n', '\t'),
                    $matches[0]
                );
            },
            $clean_content
        );

        $decoded = json_decode($escaped_content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        error_log('Kira AI SA JSON Decode Failed: ' . json_last_error_msg());

        return false;
    }

    /**
     * Kiểm tra kết nối API.
     *
     * @param string $api_key
     * @param string $model
     * @return bool|WP_Error
     */
    public function test_connection($api_key = '', $model = '')
    {
        $response = $this->chat(
            'Hãy trả về duy nhất chữ "OK" để xác nhận kết nối hoạt động.',
            'Bạn là trợ lý hệ thống kiểm tra kết nối API.',
            array(),
            $api_key,
            $model
        );

        if (is_wp_error($response)) {
            return $response;
        }
        if (empty($response)) {
            return new WP_Error('kira_sa_empty', 'Kết nối thành công nhưng nhận phản hồi trống.');
        }

        return true;
    }
}