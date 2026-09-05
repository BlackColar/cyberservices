<?php
/**
 * Kira AI SEO Auditor - AI Outline & Content Gap Generator
 *
 * @package Kira_AI_SA
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kira_SA_Outline_Generator
{
    private $api;

    // Các pattern đoạn text tự xưng AI/model cần loại bỏ triệt để khỏi output.
    const SELF_IDENTITY_PATTERNS = array(
        '/tôi (?:là|là một|là trợ lý|là mô hình|là ai|được phát triển)[^.\n]*\.?/iu',
        '/tôi (?:xưng|tự giới thiệu)[^.\n]*\.?/iu',
        '/i am (?:an? |a |the )?(?:ai|model|assistant|language model)[^.\n]*\.?/iu',
        '/as an? (?:ai|language model|assistant)[^.\n]*[,\.]/iu',
        '/được phát triển bởi (?:kira ai|kiraai)[^.\n]*\.?/iu',
        '/developed by (?:kira ai|kiraai)[^.\n]*\.?/iu',
        '/https?:\/\/kiraai\.vn[^\s]*/iu',
        '/kira\s?-\s?\d+[.\d]*(?:\s*(pro|flash|turbo|mini))?/iu',
        '/mô hình (?:ai|ngôn ngữ)(?:[^.\n]*)?/iu',
        '/ai model(l)?[^.\n]*/iu',
    );

    public function __construct()
    {
        $this->api = new Kira_SA_Api_Client();
    }

    public function generate_outline($focus_keyword, $competitor_data, $keywords, $extra_prompt = '')
    {
        $system_msg = 'Bạn là một Chuyên gia SEO Master và Content Strategist cấp cao. Nhiệm vụ là tạo ra dàn ý bài viết chuẩn SEO Top 1 Google dựa trên phân tích đối thủ. Bạn luôn trả về kết quả JSON chuẩn và tuân thủ tuyệt đối cấu trúc đầu ra.';

        $headings_text = $this->format_headings_for_prompt($competitor_data['headings'] ?? array());
        $keywords_text = $this->format_keywords_for_prompt($keywords);

        $ai_prompt = "Hãy tạo một DÀN Ý bài viết chuẩn SEO bằng tiếng Việt dựa trên phân tích đối thủ cạnh tranh.\n\n" .
            "1. THÔNG TIN BỐI CẢNH:\n" .
            "- Từ khóa mục tiêu cần tối ưu: {$focus_keyword}\n" .
            "- Tiêu đề bài viết đối thủ: " . ($competitor_data['title'] ?? 'Không rõ') . "\n" .
            "- Meta Description đối thủ: " . ($competitor_data['meta_description'] ?? 'Không rõ') . "\n\n" .

            "2. CẤU TRÚC HEADING ĐỐI THỦ ĐANG XẾP HẠNG TỐT (H1/H2/H3):\n" .
            ($headings_text ?: "Không trích xuất được headings.\n") . "\n" .

            "3. TỪ KHÓA / THỰC THỂ QUAN TRỌNG ĐỐI THỦ ĐANG DÙNG (Kèm tần suất đề xuất):\n" .
            ($keywords_text ?: "Không trích xuất được từ khóa.\n") . "\n" .

            "4. YÊU CẦU TẠO DÀN Ý MỚI (Content Gap & Information Gain):\n" .
            "- Giữ lại cấu trúc cốt lõi tốt nhất của đối thủ (các H2 chính, luồng logic).\n" .
            "- BỔ SUNG các mục còn THIẾU hoặc yếu ở đối thủ (Information Gain) để bài viết vượt trội hơn, ví dụ: bảng so sánh, số liệu thống kê, ví dụ thực tế, câu hỏi thường gặp, mẹo chuyên sâu, phân tích chi phí.\n" .
            "- Tối ưu cluster từ khóa LSI/Long-tail bằng cách gài các từ khóa mục tiêu vào đúng heading H2/H3.\n" .
            "- Không tạo quá 8 mục H2 chính. Mỗi H2 có 2-4 H3 con phù hợp.\n" .
            "- Ghi rõ từ khóa được gài cho từng heading (nếu có).\n\n" .

            "5. ĐỊNH DẠNG ĐẦU RA - CHỈ TRẢ VỀ JSON DUY NHẤT:\n" .
            "{\n" .
            "  \"title\": \"Tiêu đề bài viết đề xuất hấp dẫn chuẩn SEO\",\n" .
            "  \"outline\": [\n" .
            "    {\"level\": \"h2\", \"text\": \"Nội dung heading\", \"keywords\": [\"từ khóa gài vào\", \"từ khóa phụ\"], \"notes\": \"Ghi chú bổ sung / thông tin cần đề cập\"},\n" .
            "    {\"level\": \"h3\", \"text\": \"Nội dung heading con\", \"keywords\": [], \"notes\": \"\"}\n" .
            "  ],\n" .
            "  \"missing_topics\": [\"Chủ đề đối thủ đang thiếu cần bổ sung\"],\n" .
            "  \"target_keywords\": [" .
            "    {\"keyword\": \"từ khóa\", \"recommended_freq\": số lần đề xuất, \"status\": \"met|partial|missing\"}" .
            "  ]\n" .
            "}\n\n" .
            "Quy tắc:\n" .
            "- CHỈ trả về JSON thuần, không bọc markdown, không giải thích.\n" .
            "- Dùng dấu nháy đơn cho text bên trong JSON để tránh lỗi escape.\n" .
            "- target_keywords: đánh giá trạng thái từ khóa dựa trên mức độ quan trọng.\n";

        if (!empty($extra_prompt)) {
            $ai_prompt .= "\n6. YÊU CẦU BỔ SUNG TỪ NGƯỜI DÙNG:\n" . $extra_prompt . "\n";
        }

        $additional_args = array(
            'response_format' => array('type' => 'json_object'),
        );

        $response = $this->api->chat($ai_prompt, $system_msg, $additional_args);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = $this->api->clean_json($response);
        if (!$data) {
            return new WP_Error('kira_sa_invalid_json', 'AI trả về định dạng JSON không hợp lệ. Vui lòng thử lại.');
        }

        return $this->normalize_outline_data($data);
    }

    public function audit_headings($outline, $draft_content)
    {
        if (empty($outline) || !is_array($outline)) {
            return array();
        }

        // Danh sách heading THỰC SỰ có trong bài viết kèm cấp độ (H1/H2/H3/H4)
        $draft_heading_nodes = $this->extract_heading_nodes($draft_content);
        $draft_headings = array_column($draft_heading_nodes, 'text');
        $draft_headings_norm = array();
        foreach ($draft_headings as $dh) {
            $draft_headings_norm[] = $this->normalize_for_compare($dh);
        }

        // Toàn bộ nội dung (để kiểm tra heading được nhắc dưới dạng đoạn văn)
        $draft_text = wp_strip_all_tags($draft_content);
        $draft_text_norm = $this->normalize_for_compare($draft_text);

        $result = array();

        // Theo dõi H2 dàn ý đứng trước để gợi ý vị trí chèn H3 (Smart Recommendation)
        $last_h2_text = '';
        $last_h2_status = '';

        foreach ($outline as $item) {
            $text_norm = $this->normalize_for_compare($item['text'] ?? '');

            $status = 'missing';
            $matched_draft = '';
            $best_ratio = 0;

            if (!empty($text_norm)) {
                // BƯỚC 1: So heading-dàn-ý với TỪNG heading có trong bài (token overlap)
                // → Heading gần giống nhau thì coi là ĐÃ CÓ, tránh báo nhầm "thiếu"
                //   khi bài dùng heading dài hơn / cấu trúc lại câu chữ.
                foreach ($draft_headings as $index => $dh) {
                    if ($index >= count($draft_headings_norm) || empty($draft_headings_norm[$index])) {
                        continue;
                    }
                    $ratio = $this->heading_similarity_ratio($text_norm, $draft_headings_norm[$index]);
                    if ($ratio > $best_ratio) {
                        $best_ratio = $ratio;
                        $matched_draft = $dh;
                    }
                }
                if ($best_ratio >= 0.8) {
                    $status = 'met';
                }
            }

            // BƯỚC 2: Chưa có heading → kiểm tra nội dung có nhắc tới chuỗi heading
            //          (dạng đoạn văn) → "một phần", KHÔNG so từng từ lẻ với cả bài.
            if ($status === 'missing' && !empty($text_norm)) {
                if (mb_strpos($draft_text_norm, $text_norm) !== false) {
                    $status = 'partial';
                }
            }

            $level = $item['level'] ?? 'h2';

            // SMART RECOMMENDATION: gợi ý vị trí chèn tối ưu cho heading chưa có
            $recommendation = array();
            if ($status !== 'met' && !empty($item['text'])) {
                $recommendation = $this->build_recommendation(
                    $level,
                    $item['text'],
                    $last_h2_text,
                    $last_h2_status,
                    $draft_headings
                );
            }

            // Cập nhật H2 dàn ý đứng trước cho heading tiếp theo
            if ($level === 'h2') {
                $last_h2_text = $item['text'] ?? '';
                $last_h2_status = $status;
            }

            $result[] = array(
                'level'          => $level,
                'text'           => $item['text'] ?? '',
                'keywords'       => $item['keywords'] ?? array(),
                'notes'          => $item['notes'] ?? '',
                'status'         => $status,
                'matched_draft'  => $status === 'met' ? $matched_draft : '',
                'recommendation' => $recommendation,
                // Danh sách heading hiện có kèm cấp độ → dropdown hiển thị [H1/H2/H3] & chèn trước/sau
                'available_anchors' => $draft_heading_nodes,
            );
        }

        return $result;
    }

    /**
     * Xây dựng gợi ý vị trí chèn tối ưu cho một heading chưa có trong bài viết.
     *
     * @param string $level            h2|h3|...
     * @param string $text             Text heading dàn ý.
     * @param string $last_h2_text     H2 dàn ý đứng trước (nếu có).
     * @param string $last_h2_status   Trạng thái H2 dàn ý đứng trước.
     * @param array  $draft_headings   Danh sách heading hiện có trong bài viết.
     * @return array
     */
    private function build_recommendation($level, $text, $last_h2_text, $last_h2_status, $draft_headings)
    {
        $label = '';
        $anchor = '';
        $position = 'append';

        if ($level === 'h3' && !empty($last_h2_text)) {
            // H3 → gợi ý chèn dưới H2 tương ứng của dàn ý
            $h2_exists = false;
            foreach ($draft_headings as $dh) {
                $ratio = $this->heading_similarity_ratio($this->normalize_for_compare($last_h2_text), $this->normalize_for_compare($dh));
                if ($ratio >= 0.8) {
                    $h2_exists = true;
                    $anchor = $dh;
                    break;
                }
            }
            if ($h2_exists) {
                $position = 'after';
                $label = 'Gợi ý: chèn sau heading <strong>' . esc_html($dh) . '</strong> (làm mục con H3 của mục này)';
            } else {
                $position = 'append';
                $label = 'Gợi ý: thêm cả cụm <strong>' . esc_html($last_h2_text) . '</strong> rồi đặt <strong>' . esc_html($text) . '</strong> làm mục con ngay bên dưới';
            }
            return array('label' => $label, 'anchor' => $anchor, 'position' => $position);
        }

        // H2 / H1 → chèn sau heading bài viết gần nhất (đầu bài) để đảm bảo luồng logic
        if (!empty($draft_headings)) {
            $anchor = $draft_headings[0];
            $position = 'after';
            $first_norm = $this->normalize_for_compare($draft_headings[0]);
            if ($first_norm !== $this->normalize_for_compare($text)) {
                $label = 'Gợi ý: chèn sau <strong>' . esc_html($draft_headings[0]) . '</strong>';
            } else {
                // Trùng chính nó → thêm cuối
                if (count($draft_headings) > 1) {
                    $anchor = $draft_headings[count($draft_headings) - 1];
                    $label = 'Gợi ý: chèn gần cuối bài sau <strong>' . esc_html($anchor) . '</strong>';
                } else {
                    $label = 'Gợi ý: thêm vào cuối bài viết hiện tại';
                    $position = 'append';
                }
            }
            return array('label' => $label, 'anchor' => $anchor, 'position' => $position);
        }

        $label = 'Gợi ý: thêm vào giữa bài viết (sau phần mở đầu)';
        return array('label' => $label, 'anchor' => $anchor, 'position' => $position);
    }

    /**
     * Tính độ tương đồng giữa 2 chuỗi heading (bỏ dấu, lowercase) dựa trên token overlap.
     *
     * @param string $a Heading chuẩn hóa từ dàn ý.
     * @param string $b Heading chuẩn hóa từ bài viết.
     * @return float 0..1
     */
    private function heading_similarity_ratio($a, $b)
    {
        $words_a = preg_split('/\s+/u', trim($a));
        $words_b = preg_split('/\s+/u', trim($b));
        if (empty($words_a) || empty($words_b)) {
            return 0;
        }

        // Chỉ đếm các từ có nghĩa (dài > 2) — bỏ từ nối ngắn
        $significant_a = array();
        foreach ($words_a as $w) {
            if (mb_strlen($w, 'UTF-8') > 2) {
                $significant_a[] = $w;
            }
        }
        if (empty($significant_a)) {
            $significant_a = $words_a;
        }

        $matched = 0;
        foreach ($significant_a as $wa) {
            foreach ($words_b as $wb) {
                if ($wa === $wb) {
                    $matched++;
                    break;
                }
            }
        }
        return $matched / count($significant_a);
    }

    /**
     * Lọc sạch output AI: loại bỏ mọi câu tự xưng, giới thiệu model, URL, chào hỏi.
     * Đảm bảo output bắt đầu ngay bằng nội dung thực.
     *
     * @param string $text
     * @return string
     */
    private function sanitize_ai_output($text)
    {
        $text = trim($text);
        if (empty($text)) {
            return '';
        }

        // Loại bỏ các pattern tự xưng AI/model
        foreach (self::SELF_IDENTITY_PATTERNS as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }

        // Loại bỏ dòng trống thừa ở đầu
        $text = preg_replace('/^(?:<br\s*\/?>\s*)+/i', '', $text);
        $text = preg_replace('/^(?:\s*<p>\s*<\/p>\s*)+/i', '', $text);
        $text = trim($text);

        return $text;
    }

    public function generate_keyword_sentence($keyword, $focus_keyword = '')
    {
        $system_msg = 'Bạn là chuyên gia thực thụ trong lĩnh vực của bài viết. Viết 1 câu duy nhất, súc tích, có chứa từ khóa yêu cầu. Giọng dứt khoát, không lý thuyết suông. Trả về <p>...</p> thuần, không thêm thẻ khác.';
        $context = !empty($focus_keyword) ? " Bối cảnh: bài viết về {$focus_keyword}." : '';
        $ai_prompt = "Viết đúng 1 câu (20-40 từ) có chứa CHÍNH XÁC cụm từ: \"{$keyword}\".{$context}\n\n" .
            "⚠️ CẤM TUYỆT ĐỐI: Không chào hỏi, không tự giới thiệu bản thân, không nhắc tên model (Kira, AI, ChatGPT, v.v.), không đề cập địa chỉ URL. Output phải bắt đầu ngay lập tức bằng nội dung thực, không có tiền tố mở đầu.\n" .
            "QUYẮT ĐỊNH: Không mở đầu kiểu \"Trong bối cảnh\", \"Như chúng ta đã biết\", \"Hãy cùng tìm hiểu\", \"Tóm lại\". Không dùng tính từ phóng đại, lời khuyên chung chung.\n" .
            "1. Đi thẳng vào trọng tâm, câu văn gãy gọn, giàu insight thực chiến.\n" .
            "2. Dùng <p>...</p>, KHÔNG thêm gì khác.\n" .
            "3. CHỈ trả về HTML thuần.";

        $response = $this->api->chat($ai_prompt, $system_msg);

        if (is_wp_error($response)) {
            return $response;
        }

        $response = $this->sanitize_ai_output($response);
        $response = preg_replace('/<h[1-4][^>]*>.*?<\/h[1-4]>/is', '', $response);
        if (empty($response)) {
            return new WP_Error('kira_sa_empty_sentence', 'AI không trả về nội dung. Vui lòng thử lại.');
        }

        return $response;
    }

    public function generate_section_intro($focus_keyword, $heading, $keywords = array())
    {
        $system_msg = 'Bạn là Subject Matter Expert — chuyên gia thực thụ, không phải AI content rẻ tiền. Viết 2-3 đoạn văn ngắn (80-120 từ) cho mục bài viết. Giọng chuyên sâu, không sáo rỗng. Trả về <p>...</p> thuần.';

        $kw_text = !empty($keywords) ? ' - Gài tự nhiên các từ khóa sau: ' . implode(', ', $keywords) : '';

        $ai_prompt = "Viết nội dung cho mục có tiêu đề sau trong bài viết chuyên sâu:\n" .
            "- Tiêu đề mục: {$heading}\n" .
            "- Chủ đề chính toàn bài: {$focus_keyword}\n" .
            "{$kw_text}\n\n" .
            "⚠️ CẤM TUYỆT ĐỐI: Không chào hỏi, không tự giới thiệu bản thân, không nhắc tên model (Kira, AI, ChatGPT, v.v.), không đề cập địa chỉ URL. Output phải bắt đầu ngay lập tức bằng nội dung <p> thực.\n" .
            "YÊU CẦU NGHIÊM NGẶT:\n" .
            "- Cấm tuyệt đối: \"Trong bối cảnh hiện nay\", \"Như chúng ta đã biết\", \"Hãy cùng tìm hiểu\", \"Tóm lại\", \"Có thể thấy rằng\" và mọi mở đầu/kết bài khuôn mẫu.\n" .
            "- Cấm tính từ phóng đại, lời khuyên lý thuyết chung chung.\n" .
            "- Đi thẳng vào trọng tâm: phân tích nguyên nhân - kết quả, số liệu cụ thể, insight thực chiến.\n" .
            "- Câu văn gãy gọn, mạch lạc, giàu chiều sâu. Gắn kết logic với đoạn trước và sau.\n" .
            "- Dùng đúng thẻ HTML: <p>...</p> cho mỗi đoạn. KHÔNG bọc <h2>/<h3>. CHỈ trả về HTML thuần.";

        $response = $this->api->chat($ai_prompt, $system_msg);

        if (is_wp_error($response)) {
            return $response;
        }

        $response = $this->sanitize_ai_output($response);
        $response = preg_replace('/<h[1-4][^>]*>.*?<\/h[1-4]>/is', '', $response);

        if (empty($response)) {
            return new WP_Error('kira_sa_empty_section', 'AI không trả về nội dung. Vui lòng thử lại.');
        }

        return $response;
    }

    public function track_keywords($target_keywords, $draft_content)
    {
        if (empty($target_keywords) || !is_array($target_keywords)) {
            return array();
        }

        $draft_text = wp_strip_all_tags($draft_content);
        $draft_text_norm = $this->normalize_for_compare($draft_text);

        $result = array();
        foreach ($target_keywords as $kw) {
            $keyword = $kw['keyword'] ?? '';
            if (empty($keyword)) {
                continue;
            }

            $keyword_norm = $this->normalize_for_compare($keyword);
            $count = $this->count_occurrences($draft_text_norm, $keyword_norm);

            $recommended = isset($kw['recommended_freq']) ? (int) $kw['recommended_freq'] : 1;
            if ($recommended < 1) {
                $recommended = 1;
            }

            $status = 'missing';
            if ($count > 0) {
                $status = 'partial';
            }
            if ($count >= $recommended) {
                $status = 'met';
            }

            $result[] = array(
                'keyword'          => $keyword,
                'count'            => $count,
                'recommended_freq' => $recommended,
                'suggested_places' => $kw['suggested_places'] ?? array(),
                'status'           => $status,
                'tracked'          => true,
            );
        }

        return $result;
    }

    public function audit_seo_score($draft_content, $target_keywords, $recommended_word_count = 0, $post_title = '')
    {
        $draft_text = wp_strip_all_tags($draft_content);
        $draft_text_flat = Kira_SA_Helper::strip_diacritics($draft_text);
        $word_count = str_word_count(preg_replace('/[^A-Za-z0-9\s]/', ' ', $draft_text_flat));

        $word_ok = true;
        if ($recommended_word_count > 0 && $word_count < ($recommended_word_count * 0.8)) {
            $word_ok = false;
        }
        $needs_more_words = $recommended_word_count > 0 ? max(0, $recommended_word_count - $word_count) : 0;

        $headings_count = count($this->extract_heading_texts($draft_content));
        $heading_ok = $headings_count >= 4;

        $h2_count = preg_match_all('/<h2[^>]*>/is', $draft_content, $m) ? count($m[0]) : 0;
        $h3_count = preg_match_all('/<h3[^>]*>/is', $draft_content, $m) ? count($m[0]) : 0;
        $structure_ok = ($h2_count >= 2 && ($h2_count + $h3_count) >= 4);

        $kw_met = 0;
        $kw_total = count($target_keywords);
        foreach ($target_keywords as $kw) {
            $kw_text = $kw['keyword'] ?? '';
            if (empty($kw_text)) continue;
            $norm = $this->normalize_for_compare($kw_text);
            if ($this->count_occurrences($this->normalize_for_compare($draft_text), $norm) > 0) {
                $kw_met++;
            }
        }
        $keyword_ratio = $kw_total > 0 ? ($kw_met / $kw_total) : 1;
        $keyword_ok = $keyword_ratio >= 0.6;

        $img_count = preg_match_all('/<img[^>]*>/is', $draft_content, $m) ? count($m[0]) : 0;
        $alt_count = preg_match_all('/<img[^>]*\salt=["\'][^"\']+["\']/is', $draft_content, $m) ? count($m[0]) : 0;
        $image_ok = ($img_count >= 2 && $alt_count >= 2);

        $title_ok = false;
        if (!empty($target_keywords)) {
            $first_kw = $target_keywords[0]['keyword'] ?? '';
            if (!empty($first_kw) && !empty($post_title)) {
                if (mb_strpos($this->normalize_for_compare($post_title), $this->normalize_for_compare($first_kw)) !== false) {
                    $title_ok = true;
                }
            }
        }

        $home = home_url();
        $internal_links = preg_match_all('/<a[^>]*href=["\'](?:' . preg_quote(trailingslashit($home), '/') . '|' . preg_quote($home, '/') . ')[^"\']*["\']/is', $draft_content, $m) ? count($m[0]) : 0;
        $link_ok = $internal_links >= 1;

        $weights = array(
            'word_count' => 20, 'headings' => 15, 'structure' => 15,
            'keywords' => 20, 'images' => 10, 'title' => 10, 'links' => 10,
        );

        $passed = 0;
        $check_map = array(
            'word_count' => $word_ok, 'headings' => $heading_ok, 'structure' => $structure_ok,
            'keywords' => $keyword_ok, 'images' => $image_ok, 'title' => $title_ok, 'links' => $link_ok,
        );
        foreach ($check_map as $key => $ok) {
            if ($ok) $passed += $weights[$key];
        }
        $score = (int) round($passed / 5) * 5;

        $items = array(
            array('label' => 'Độ dài bài viết', 'detail' => $recommended_word_count > 0 ? "Đang có {$word_count} từ / khuyến nghị ~{$recommended_word_count} từ" . ($needs_more_words > 0 ? " (cần thêm ~{$needs_more_words} từ)" : '') : "Đang có {$word_count} từ", 'status' => $word_ok ? 'met' : 'partial', 'weight' => $weights['word_count']),
            array('label' => 'Heading coverage', 'detail' => "Có {$headings_count} heading (khuyến nghị ≥ 4)", 'status' => $heading_ok ? 'met' : 'partial', 'weight' => $weights['headings']),
            array('label' => 'Cấu trúc H2/H3', 'detail' => "H2: {$h2_count}, H3: {$h3_count}", 'status' => $structure_ok ? 'met' : 'partial', 'weight' => $weights['structure']),
            array('label' => 'Từ khóa mục tiêu', 'detail' => "Đạt {$kw_met}/{$kw_total} từ khóa (≥ 60% là đạt)", 'status' => $keyword_ok ? 'met' : 'partial', 'weight' => $weights['keywords']),
            array('label' => 'Ảnh & thẻ Alt', 'detail' => "Có {$img_count} ảnh, {$alt_count} ảnh có Alt", 'status' => $image_ok ? 'met' : 'partial', 'weight' => $weights['images']),
            array('label' => 'Từ khóa trong tiêu đề', 'detail' => $title_ok ? 'Từ khóa chính xuất hiện trong tiêu đề' : 'Từ khóa chính chưa xuất hiện trong tiêu đề', 'status' => $title_ok ? 'met' : 'partial', 'weight' => $weights['title']),
            array('label' => 'Internal links', 'detail' => "Có {$internal_links} liên kết nội bộ (khuyến nghị ≥ 1)", 'status' => $link_ok ? 'met' : 'partial', 'weight' => $weights['links']),
        );

        return array(
            'score'            => $score,
            'word_count'       => $word_count,
            'needs_more_words' => $needs_more_words,
            'items'            => $items,
        );
    }

    private function format_headings_for_prompt($headings)
    {
        if (empty($headings)) return '';
        $lines = array();
        foreach ($headings as $h) {
            $level = strtoupper($h['level'] ?? 'H2');
            $text = $h['text'] ?? '';
            if (!empty($text)) $lines[] = "  [{$level}] {$text}";
        }
        return implode("\n", $lines);
    }

    private function format_keywords_for_prompt($keywords)
    {
        if (empty($keywords)) return '';
        $lines = array();
        foreach ($keywords as $kw) {
            $keyword = $kw['keyword'] ?? '';
            $count = $kw['count'] ?? 0;
            $freq = $kw['recommended_freq'] ?? 1;
            if (!empty($keyword)) $lines[] = "  - {$keyword} (đối thủ dùng {$count} lần, đề xuất {$freq} lần)";
        }
        return implode("\n", $lines);
    }

    private function normalize_outline_data($data)
    {
        $outline = array();
        if (isset($data['outline']) && is_array($data['outline'])) {
            foreach ($data['outline'] as $item) {
                $outline[] = array(
                    'level'    => in_array($item['level'] ?? '', array('h1', 'h2', 'h3', 'h4')) ? $item['level'] : 'h2',
                    'text'     => sanitize_text_field($item['text'] ?? ''),
                    'keywords' => isset($item['keywords']) && is_array($item['keywords']) ? array_map('sanitize_text_field', $item['keywords']) : array(),
                    'notes'    => sanitize_textarea_field($item['notes'] ?? ''),
                );
            }
        }

        $missing_topics = array();
        if (isset($data['missing_topics']) && is_array($data['missing_topics'])) {
            foreach ($data['missing_topics'] as $topic) {
                $missing_topics[] = sanitize_text_field($topic);
            }
        }

        $target_keywords = array();
        if (isset($data['target_keywords']) && is_array($data['target_keywords'])) {
            foreach ($data['target_keywords'] as $kw) {
                $keyword = sanitize_text_field($kw['keyword'] ?? '');
                if (empty($keyword)) continue;
                $target_keywords[] = array(
                    'keyword'          => $keyword,
                    'recommended_freq' => max(1, isset($kw['recommended_freq']) ? (int) $kw['recommended_freq'] : 1),
                    'suggested_places' => array('Tiêu đề (Title)', 'Thẻ H2/H3', 'Đoạn mở đầu'),
                    'status'           => 'missing',
                    'count'            => 0,
                    'tracked'          => false,
                );
            }
        }

        return array(
            'title'           => sanitize_text_field($data['title'] ?? ''),
            'outline'         => $outline,
            'missing_topics'  => $missing_topics,
            'target_keywords' => $target_keywords,
        );
    }

    /**
     * Trích xuất danh sách heading (H1-H4) kèm cấp độ từ nội dung bài viết.
     *
     * @param string $content
     * @return array[] List of ['level' => 'h2', 'text' => '...'] — bỏ item rỗng.
     */
    public function extract_heading_nodes($content)
    {
        $nodes = array();
        if (preg_match_all('/<h([1-4])[^>]*>(.*?)<\/h[1-4]>/is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $text = trim(wp_strip_all_tags($m[2]));
                if (empty($text)) {
                    continue;
                }
                $nodes[] = array(
                    'level' => 'h' . $m[1],
                    'text'  => $text,
                );
            }
        }
        return $nodes;
    }

    private function extract_heading_texts($content)
    {
        $nodes = $this->extract_heading_nodes($content);
        $headings = array();
        foreach ($nodes as $node) {
            $headings[] = $node['text'];
        }
        return $headings;
    }

    private function normalize_for_compare($text)
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = Kira_SA_Helper::strip_diacritics($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private function count_occurrences($text, $keyword_norm)
    {
        if (empty($keyword_norm) || empty($text)) return 0;
        $count = 0;
        $offset = 0;
        $keyword_len = mb_strlen($keyword_norm, 'UTF-8');
        while (($pos = mb_strpos($text, $keyword_norm, $offset, 'UTF-8')) !== false) {
            $count++;
            $offset = $pos + $keyword_len;
        }
        return $count;
    }
}