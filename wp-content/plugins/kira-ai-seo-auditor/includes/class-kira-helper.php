<?php
/**
 * Kira AI SEO Auditor - Helper dùng chung
 *
 * Tập trung các hàm tiện ích dùng chung cho nhiều class
 * (tránh lặp code giữa Scraper, Keyword Extractor, Outline Generator).
 *
 * @package Kira_AI_SA
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kira_SA_Helper
{
    /**
     * Chuyển UTF-8 thành numeric HTML entities trước khi đưa vào DOMDocument.
     *
     * Thay thế cho mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')
     * đã bị deprecated từ PHP 8.2 (tránh warning phá layout admin).
     *
     * @param string $html
     * @return string
     */
    public static function html_entities_for_dom($html)
    {
        if (function_exists('mb_encode_numericentity')) {
            return mb_encode_numericentity($html, array(0x80, 0x10FFFF, 0, 0x1FFFFF), 'UTF-8');
        }
        // Fallback cho PHP cũ không có mb_encode_numericentity
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        }
        return $html;
    }

    /**
     * Loại bỏ dấu tiếng Việt (không dấu) để so sánh / đếm từ chính xác.
     *
     * @param string $str
     * @return string
     */
    public static function strip_diacritics($str)
    {
        $transliteration = array(
            'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a', 'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e', 'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o', 'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u', 'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y', 'đ' => 'd', 'Đ' => 'D',
            'À' => 'A', 'Á' => 'A', 'Ả' => 'A', 'Ã' => 'A', 'Ạ' => 'A', 'Ă' => 'A', 'Ằ' => 'A', 'Ắ' => 'A', 'Ẳ' => 'A', 'Ẵ' => 'A', 'Ặ' => 'A', 'Â' => 'A', 'Ầ' => 'A', 'Ấ' => 'A', 'Ẩ' => 'A', 'Ẫ' => 'A', 'Ậ' => 'A',
            'È' => 'E', 'É' => 'E', 'Ẻ' => 'E', 'Ẽ' => 'E', 'Ẹ' => 'E', 'Ê' => 'E', 'Ề' => 'E', 'Ế' => 'E', 'Ể' => 'E', 'Ễ' => 'E', 'Ệ' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Ỉ' => 'I', 'Ĩ' => 'I', 'Ị' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ỏ' => 'O', 'Õ' => 'O', 'Ọ' => 'O', 'Ô' => 'O', 'Ồ' => 'O', 'Ố' => 'O', 'Ổ' => 'O', 'Ỗ' => 'O', 'Ộ' => 'O', 'Ơ' => 'O', 'Ờ' => 'O', 'Ớ' => 'O', 'Ở' => 'O', 'Ỡ' => 'O', 'Ợ' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Ủ' => 'U', 'Ũ' => 'U', 'Ụ' => 'U', 'Ư' => 'U', 'Ừ' => 'U', 'Ứ' => 'U', 'Ử' => 'U', 'Ữ' => 'U', 'Ự' => 'U',
            'Ỳ' => 'Y', 'Ý' => 'Y', 'Ỷ' => 'Y', 'Ỹ' => 'Y', 'Ỵ' => 'Y',
        );
        return strtr($str, $transliteration);
    }
}