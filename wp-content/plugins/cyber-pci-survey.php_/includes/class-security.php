<?php
if (!defined('ABSPATH')) exit;

class Cyber_Hub_Security {

    const GUEST_COOKIE = 'cyber_hub_guest_id';

    public static function init() {
        add_action('init', [__CLASS__, 'ensure_guest_cookie'], 1);
        add_filter('nonce_user_logged_out', [__CLASS__, 'guest_nonce_user'], 10, 2);
    }

    public static function ensure_guest_cookie() {
        if (is_user_logged_in() || !empty($_COOKIE[self::GUEST_COOKIE])) return;
        try {
            $guest_id = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            return;
        }
        setcookie(self::GUEST_COOKIE, $guest_id, [
            'expires' => time() + DAY_IN_SECONDS,
            'path' => defined('COOKIEPATH') ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::GUEST_COOKIE] = $guest_id;
    }

    public static function guest_nonce_user($uid, $action) {
        $protected_actions = ['cyber_hub_ajax_nonce', 'cyber_hub_submit_action'];
        if ($uid || !in_array($action, $protected_actions, true) || empty($_COOKIE[self::GUEST_COOKIE])) return $uid;
        $guest_id = hash('sha256', sanitize_text_field(wp_unslash($_COOKIE[self::GUEST_COOKIE])));
        return (int) sprintf('%u', crc32($guest_id));
    }

    public static function encrypt($plain_text) {
        if (empty($plain_text)) return '';
        try {
            $iv = random_bytes(12);
        } catch (Exception $e) {
            return '';
        }
        $tag = '';
        $ciphertext = openssl_encrypt($plain_text, 'aes-256-gcm', CYBER_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || $tag === '') return '';

        return 'v2:' . base64_encode(wp_json_encode([
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct' => base64_encode($ciphertext),
        ]));
    }

    public static function decrypt($encrypted_text) {
        if (empty($encrypted_text)) return '';
        if (strpos($encrypted_text, 'v2:') === 0) {
            $encoded_payload = base64_decode(substr($encrypted_text, 3), true);
            $payload = $encoded_payload === false ? null : json_decode($encoded_payload, true);
            if (!is_array($payload) || empty($payload['iv']) || empty($payload['tag']) || !isset($payload['ct'])) return '';
            $iv = base64_decode($payload['iv'], true);
            $tag = base64_decode($payload['tag'], true);
            $ciphertext = base64_decode($payload['ct'], true);
            if ($iv === false || $tag === false || $ciphertext === false) return '';
            $plain_text = openssl_decrypt($ciphertext, 'aes-256-gcm', CYBER_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain_text === false ? '' : $plain_text;
        }

        // Backward-compatible reader for records encrypted before v7.7.
        $raw_data = base64_decode($encrypted_text, true);
        if ($raw_data === false || strpos($raw_data, '::') === false) {
            return $encrypted_text;
        }
        list($encrypted, $iv) = explode('::', $raw_data, 2);
        $plain_text = openssl_decrypt($encrypted, 'aes-256-cbc', CYBER_ENCRYPTION_LEGACY_KEY, 0, $iv);
        return $plain_text === false ? '' : $plain_text;
    }

    public static function decrypt_record($record) {
        foreach (['company_name', 'contact_person', 'contact_email', 'contact_phone', 'company_address', 'business_description'] as $field) {
            if (isset($record->$field)) $record->$field = self::decrypt($record->$field);
        }
        return $record;
    }

    public static function get_secure_upload_dir() {
        // This must be outside ABSPATH. Set CYBER_SECURE_UPLOAD_DIR in wp-config.php
        // when the server uses a non-standard document root.
        $secure_dir = defined('CYBER_SECURE_UPLOAD_DIR') ? CYBER_SECURE_UPLOAD_DIR : dirname(untrailingslashit(ABSPATH)) . '/cyber_secured_uploads';

        if (!file_exists($secure_dir)) {
            wp_mkdir_p($secure_dir);
            $htaccess_file = $secure_dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                $rules = "Deny from all\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<FilesMatch \"\.(php|php5|php7|php8|phtml|phar)$\">\n    Order Allow,Deny\n    Deny from all\n</FilesMatch>";
                @file_put_contents($htaccess_file, $rules);
            }
            $index_file = $secure_dir . '/index.php';
            if (!file_exists($index_file)) {
                @file_put_contents($index_file, "<?php // Silence is golden");
            }
        }
        return $secure_dir;
    }

    public static function is_secure_upload_path($file_path) {
        $root = realpath(self::get_secure_upload_dir());
        $file = realpath($file_path);
        if ($root === false || $file === false) return false;
        return strpos(wp_normalize_path($file), trailingslashit(wp_normalize_path($root))) === 0;
    }
}
Cyber_Hub_Security::init();
