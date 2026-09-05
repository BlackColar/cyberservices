<?php
/**
 * Kira AI SEO Auditor - NLP Keyword Extractor
 *
 * @package Kira_AI_SA
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kira_SA_Keyword_Extractor
{
    public function extract_keywords($text, $limit = 18)
    {
        if (empty($text)) {
            return array();
        }

        $normalized_text = $this->normalize_text($text);

        $tokens = $this->tokenize($normalized_text);
        if (count($tokens) < 4) {
            return array();
        }

        $ngram_counts = array();
        foreach (array(2, 3, 4) as $n) {
            for ($i = 0; $i <= count($tokens) - $n; $i++) {
                $gram = array_slice($tokens, $i, $n);
                $gram_key = implode(' ', $gram);
                if (!isset($ngram_counts[$gram_key])) {
                    $ngram_counts[$gram_key] = 0;
                }
                $ngram_counts[$gram_key]++;
            }
        }

        $filtered = array();
        foreach ($ngram_counts as $gram_key => $count) {
            $words = explode(' ', $gram_key);
            $all_stopword = true;
            $meaningful = 0;
            foreach ($words as $w) {
                if (!$this->is_stopword($w)) {
                    $all_stopword = false;
                    $meaningful++;
                }
            }
            if ($all_stopword) {
                continue;
            }
            $skip = false;
            foreach ($words as $w) {
                if (mb_strlen($w, 'UTF-8') < 2) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $filtered[] = array('gram' => $gram_key, 'count' => $count, 'len' => count($words), 'meaningful' => $meaningful);
        }

        usort($filtered, function ($a, $b) {
            if ($a['len'] !== $b['len']) return $b['len'] - $a['len'];
            if ($a['count'] !== $b['count']) return $b['count'] - $a['count'];
            return strcmp($a['gram'], $b['gram']);
        });

        $unique_keywords = array();
        $seen_lower = array();
        foreach ($filtered as $entry) {
            $lower = mb_strtolower($entry['gram'], 'UTF-8');
            $is_substring = false;
            foreach ($seen_lower as $kept) {
                if (mb_strpos($kept, $lower) !== false || mb_strpos($lower, $kept) !== false) {
                    $is_substring = true;
                    break;
                }
            }
            if ($is_substring) continue;
            $unique_keywords[] = $entry;
            $seen_lower[] = $lower;
            if (count($unique_keywords) >= ($limit * 2)) break;
        }

        $scored = array();
        foreach ($unique_keywords as $entry) {
            $importance_bonus = 0;
            $lower_gram = mb_strtolower($entry['gram'], 'UTF-8');
            foreach (array('mua', 'giá', 'bảo hành', 'dịch vụ', 'công ty', 'tại', 'uy tín', 'tốt nhất', 'chất lượng', 'rẻ', 'khuyến mãi', 'compare', 'review', 'top', 'best', 'price', 'service') as $kw) {
                if (mb_strpos($lower_gram, $kw) !== false) $importance_bonus += 2;
            }
            $score = ($entry['count'] * 2) + ($entry['len'] * 3) + $importance_bonus;
            $scored[] = array('keyword' => $entry['gram'], 'count' => $entry['count'], 'score' => $score, 'len' => $entry['len']);
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) return $b['score'] - $a['score'];
            return $b['count'] - $a['count'];
        });

        $top_keywords = array_slice($scored, 0, $limit);

        $total_words = count($tokens);
        $estimated_words = str_word_count($this->normalize_latin_text($text));
        $article_blocks = max(1, (int) ceil($estimated_words / 200));

        $result = array();
        foreach ($top_keywords as $kw) {
            $recommended = $this->calculate_recommended_frequency($kw['count'], $article_blocks, $total_words);
            $result[] = array(
                'keyword'          => $kw['keyword'],
                'count'            => $kw['count'],
                'recommended_freq' => $recommended,
                'suggested_places' => $this->suggest_places($kw['keyword'], $kw['count']),
            );
        }

        return $result;
    }

    private function normalize_text($text)
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/https?:\/\/\S+/u', ' ', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private function normalize_latin_text($text)
    {
        $text = Kira_SA_Helper::strip_diacritics($text);
        return preg_replace('/[^A-Za-z0-9\s]/', ' ', $text);
    }

    private function tokenize($text)
    {
        $tokens = preg_split('/\s+/u', $text);
        $tokens = array_filter($tokens, function ($t) {
            $t = trim($t);
            return $t !== '' && mb_strlen($t, 'UTF-8') >= 2;
        });
        return array_values($tokens);
    }

    private function is_stopword($word)
    {
        static $stopwords = null;
        if (null === $stopwords) {
            $stopwords = array_merge($this->get_vietnamese_stopwords(), $this->get_english_stopwords());
        }
        return in_array($word, $stopwords, true);
    }

    private function calculate_recommended_frequency($count, $article_blocks, $total_words)
    {
        $recommended = (int) ceil($count / 2);
        if ($recommended < 1) $recommended = 1;
        if ($recommended > 8) $recommended = 8;
        if ($article_blocks >= 10) $recommended = min(10, $recommended + 1);
        return (int) $recommended;
    }

    private function suggest_places($keyword, $count)
    {
        $places = array('Tiêu đề (Title)', 'Thẻ H2/H3', 'Đoạn mở đầu');
        if ($count >= 5) $places[] = 'Nội dung thân bài';
        if (mb_strlen($keyword, 'UTF-8') >= 15) $places[] = 'FAQ';
        return $places;
    }

    private function get_vietnamese_stopwords()
    {
        return array(
            'và', 'của', 'có', 'những', 'được', 'các', 'không', 'cho', 'khi', 'về',
            'trên', 'này', 'để', 'với', 'cũng', 'từ', 'theo', 'sau', 'như', 'là',
            'vào', 'nếu', 'đã', 'sẽ', 'đang', 'rất', 'nhiều', 'một', 'hai', 'ba',
            'người', 'bạn', 'tôi', 'chúng', 'anh', 'chị', 'em', 'ông', 'bà', 'nó',
            'họ', 'ta', 'mình', 'tại', 'vì', 'do', 'thì', 'lại', 'còn', 'đó', 'ấy',
            'nên', 'hay', 'hoặc', 'đến', 'ra', 'lên', 'xuống', 'qua', 'lại', 'đi',
            'làm', 'việc', 'thời', 'gian', 'trong', 'ngoài', 'giữa', 'khoảng', 'mỗi',
            'nhưng', 'tuy', 'song', 'đều', 'chỉ', 'mới', 'cả', 'ngay', 'luôn', 'vậy',
            'sao', 'gì', 'nào', 'đâu', 'đấy', 'nhé', 'ạ', 'à', 'ơi', 'hả', 'vâng',
            'dạ', 'được', 'phải', 'cần', 'muốn', 'thích', 'biết', 'hiểu', 'nghĩ',
            'thấy', 'nghe', 'nói', 'gọi', 'đặt', 'bị', 'được', 'cho', 'từng', 'đủ',
            'ước', 'chừng', 'khoảng', 'theo', 'với', 'khỏi', 'trước', 'sau', 'trên',
            'dưới', 'phía', 'bên', 'ngoài', 'trong', 'lúc', 'khi', 'hồi', 'hôm',
            'năm', 'tháng', 'ngày', 'giờ', 'hôm', 'nay', 'mai', 'kia', 'khác', 'điều',
        );
    }

    private function get_english_stopwords()
    {
        return array(
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'has', 'he',
            'in', 'is', 'it', 'its', 'of', 'on', 'that', 'the', 'to', 'was', 'were',
            'will', 'with', 'the', 'this', 'but', 'they', 'have', 'had', 'not', 'you',
            'your', 'we', 'our', 'or', 'so', 'if', 'can', 'would', 'could', 'should',
            'may', 'might', 'must', 'shall', 'about', 'into', 'over', 'after', 'before',
            'between', 'out', 'up', 'down', 'off', 'than', 'then', 'there', 'here',
            'what', 'which', 'who', 'whom', 'whose', 'when', 'where', 'why', 'how',
            'all', 'any', 'both', 'each', 'few', 'more', 'most', 'other', 'some', 'such',
            'only', 'own', 'same', 'too', 'very', 'just', 'also', 'because', 'while',
            'during', 'under', 'again', 'further', 'once', 'here', 'there', 'when',
            'always', 'never', 'often', 'usually', 'today', 'tomorrow', 'yesterday',
            'now', 'then', 'still', 'yet', 'already', 'ever', 'even', 'well', 'much',
        );
    }

}
