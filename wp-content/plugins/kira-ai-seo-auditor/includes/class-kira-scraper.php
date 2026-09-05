<?php
/**
 * Kira AI SEO Auditor - Competitor Scraper
 *
 * @package Kira_AI_SA
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kira_SA_Scraper
{
    public function scrape_url($url)
    {
        $url = esc_url_raw($url);
        if (empty($url) || !wp_http_validate_url($url)) {
            return new WP_Error('kira_sa_invalid_url', 'URL không hợp lệ.');
        }

        $cache_key = 'kira_sa_scrape_' . md5($url);
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached)) {
            $cached['from_cache'] = true;
            return $cached;
        }

        $response = wp_remote_get($url, array(
            'timeout'    => 30,
            'redirection' => 5,
            'user-agent'  => $this->get_fake_user_agent(),
            'headers'     => array(
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7',
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error('kira_sa_http_error', 'Máy chủ phản hồi mã lỗi HTTP: ' . $response_code);
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return new WP_Error('kira_sa_empty_html', 'Không nhận được nội dung HTML.');
        }

        $data = $this->parse_html($html);
        if (is_wp_error($data)) {
            return $data;
        }

        $data['url'] = $url;
        $data['from_cache'] = false;

        $strip_diacritics = Kira_SA_Helper::strip_diacritics($data['main_text']);
        $data['word_count'] = str_word_count(preg_replace('/[^A-Za-z0-9\s]/', ' ', $strip_diacritics));

        set_transient($cache_key, $data, HOUR_IN_SECONDS);

        return $data;
    }

    public function scrape_urls(array $urls)
    {
        $urls = array_filter(array_map('trim', $urls));
        $urls = array_values(array_unique($urls));
        if (empty($urls)) {
            return new WP_Error('kira_sa_no_urls', 'Vui lòng nhập ít nhất 1 URL.');
        }

        $results = array();
        $errors = array();
        foreach ($urls as $url) {
            $data = $this->scrape_url($url);
            if (is_wp_error($data)) {
                $errors[] = $data->get_error_message() . ' (' . $url . ')';
                continue;
            }
            $results[] = $data;
        }

        if (empty($results)) {
            return new WP_Error('kira_sa_scrape_failed', !empty($errors) ? implode(' | ', array_slice($errors, 0, 3)) : 'Không thể phân tích.');
        }

        return $this->merge_scrape_results($results);
    }

    private function merge_scrape_results(array $results)
    {
        $titles = array();
        $meta_descriptions = array();
        $headings_map = array();
        $total_words = 0;
        $sources = array();

        foreach ($results as $res) {
            if (!empty($res['title'])) $titles[] = $res['title'];
            if (!empty($res['meta_description'])) $meta_descriptions[] = $res['meta_description'];
            if (!empty($res['url'])) $sources[] = $res['url'];
            $total_words += (int) ($res['word_count'] ?? 0);

            foreach (($res['headings'] ?? array()) as $heading) {
                $text = trim($heading['text'] ?? '');
                $level = $heading['level'] ?? 'h2';
                if (empty($text)) continue;
                $norm = mb_strtolower($text, 'UTF-8');
                if (isset($headings_map[$norm])) {
                    $headings_map[$norm]['sources']++;
                } else {
                    $headings_map[$norm] = array('level' => $level, 'text' => $text, 'sources' => 1);
                }
            }
        }

        $headings = array_values($headings_map);
        usort($headings, function ($a, $b) {
            if ($a['sources'] !== $b['sources']) return $b['sources'] - $a['sources'];
            return strcmp($a['level'], $b['level']);
        });

        $avg_word_count = (int) round($total_words / max(1, count($results)));
        if ($avg_word_count < 500) $avg_word_count = 1800;

        // Gom nội dung văn bản chính từ tất cả nguồn (phục vụ keyword extractor)
        $merged_text = '';
        foreach ($results as $res) {
            if (!empty($res['main_text'])) {
                $merged_text .= $res['main_text'] . "\n\n";
            }
        }
        $merged_text = trim($merged_text);

        return array(
            'urls'             => $sources,
            'titles'           => $titles,
            'title'            => !empty($titles) ? $titles[0] : '',
            'meta_description' => !empty($meta_descriptions) ? $meta_descriptions[0] : '',
            'headings'         => $headings,
            'main_text'        => $merged_text,
            'word_count'       => $avg_word_count,
            'sources_count'    => count($results),
        );
    }

    private function parse_html($html)
    {
        if (!class_exists('DOMDocument')) {
            return new WP_Error('kira_sa_no_domdocument', 'Server thiếu DOMDocument.');
        }

        $previous_libxml = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // Thay mb_convert_encoding(..., 'HTML-ENTITIES', ...) deprecated từ PHP 8.2
        $html = Kira_SA_Helper::html_entities_for_dom($html);
        $loaded = $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_libxml);

        if (!$loaded) {
            return new WP_Error('kira_sa_parse_failed', 'Không thể parse HTML.');
        }

        $xpath = new DOMXPath($dom);

        $title = '';
        $title_node = $xpath->query('//head//title')->item(0);
        if ($title_node) $title = trim($title_node->textContent);

        $meta_description = '';
        $meta_nodes = $xpath->query('//head//meta[@name="description"]');
        if ($meta_nodes && $meta_nodes->length > 0) {
            $meta_description = trim($meta_nodes->item(0)->getAttribute('content'));
        }

        $headings_raw = array();
        $heading_nodes = $xpath->query('//h1 | //h2 | //h3');
        if ($heading_nodes) {
            foreach ($heading_nodes as $node) {
                $text = trim($node->textContent);
                $text = preg_replace('/\s+/u', ' ', $text);
                if (empty($text)) continue;
                $headings_raw[] = array('level' => strtolower($node->nodeName), 'text' => $text);
            }
        }

        $headings = array_filter($headings_raw, function ($h) {
            $lower = mb_strtolower($h['text'], 'UTF-8');
            $skip_terms = array('menu', 'đăng nhập', 'đăng ký', 'giỏ hàng', 'liên hệ', 'about us', 'sign in', 'sign up', 'cart', 'contact us', 'footer', 'sidebar', 'danh mục sản phẩm', 'product category', 'bài viết mới nhất', 'recent posts', 'bình luận', 'comments', 'chuyên mục', 'categories');
            foreach ($skip_terms as $term) {
                if (mb_strpos($lower, $term) !== false) return false;
            }
            return true;
        });
        $headings = array_values($headings);

        $main_text = $this->extract_main_text($xpath);
        $main_text = preg_replace('/[ \t]+/u', ' ', $main_text);
        $main_text = preg_replace('/\n{3,}/u', "\n\n", $main_text);

        return array(
            'title'            => sanitize_text_field($title),
            'meta_description' => sanitize_text_field($meta_description),
            'headings'         => $headings,
            'main_text'        => $main_text,
        );
    }

    private function extract_main_text($xpath)
    {
        foreach (array('script', 'style', 'nav', 'footer', 'header', 'aside', 'form', 'iframe', 'noscript', 'svg', 'canvas', 'template') as $tag) {
            $nodes = $xpath->query("//{$tag}");
            if ($nodes) {
                foreach ($nodes as $node) $node->parentNode->removeChild($node);
            }
        }
        $container = $xpath->query('//article')->item(0);
        if (!$container) $container = $xpath->query('//main')->item(0);
        if (!$container) $container = $xpath->query('//body')->item(0);
        if (!$container) return '';

        $text = '';
        $paragraphs = $xpath->query('.//p | .//h1 | .//h2 | .//h3 | .//h4 | .//li | .//blockquote | .//td', $container);
        if ($paragraphs) {
            foreach ($paragraphs as $node) {
                $node_text = trim($node->textContent);
                $node_text = preg_replace('/\s+/u', ' ', $node_text);
                if (!empty($node_text)) $text .= $node_text . "\n";
            }
        }
        return trim($text);
    }

    private function get_fake_user_agent()
    {
        $agents = array(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        );
        return $agents[array_rand($agents)];
    }

}
