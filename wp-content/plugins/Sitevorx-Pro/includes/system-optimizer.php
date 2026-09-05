<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 1. HỆ THỐNG BẢO MẬT (RECAPTCHA & LIMIT LOGIN)
 */
if (get_option('sv_sec_enable_recaptcha') == '1') {
    $sv_recaptcha_version = get_option('sv_sec_recaptcha_version', 'v2'); // 'v2' or 'v3'

    if ('v3' === $sv_recaptcha_version) {
        // ── reCAPTCHA v3 (invisible, score-based) ──────────────────────────
        add_action('login_enqueue_scripts', function() {
            $site_key = get_option('sv_sec_recaptcha_site_key');
            if (!$site_key) return;
            wp_enqueue_script('sitevorx-recaptcha-v3', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode($site_key), array(), null, true);
        });
        add_action('login_form', function() {
            $site_key = get_option('sv_sec_recaptcha_site_key');
            if (!$site_key) return;
            echo '<input type="hidden" name="g-recaptcha-response" id="sv-recaptcha-token" value="">';
            echo '<script>(function(){var k=' . wp_json_encode( (string) $site_key ) . ';if(typeof grecaptcha==="undefined"){var t=setInterval(function(){if(typeof grecaptcha!=="undefined"){clearInterval(t);g();}},200);}else{g();}function g(){grecaptcha.ready(function(){grecaptcha.execute(k,{action:"login"}).then(function(tok){var f=document.getElementById("sv-recaptcha-token");if(f){f.value=tok;}});});}})();</script>';
        });
        add_filter('authenticate', function($user, $username, $password) {
            if (is_wp_error($user) || empty($username) || empty($password)) return $user;
            $secret_key = get_option('sv_sec_recaptcha_secret_key');
            if (!$secret_key) return $user;
            $token = isset($_POST['g-recaptcha-response']) ? sanitize_text_field(wp_unslash($_POST['g-recaptcha-response'])) : '';
            if (empty($token)) return new WP_Error('recaptcha_empty', '<strong>' . esc_html__('BẢO MẬT', 'sitevorx') . '</strong>: ' . esc_html__('Không nhận được token reCAPTCHA. Vui lòng thử lại.', 'sitevorx'));
            $verify = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
                'timeout' => 15,
                'body'    => array('secret' => $secret_key, 'response' => $token),
            ));
            if (is_wp_error($verify)) return new WP_Error('recaptcha_failed', '<strong>' . esc_html__('BẢO MẬT', 'sitevorx') . '</strong>: ' . esc_html__('Không thể kết nối tới máy chủ reCAPTCHA.', 'sitevorx'));
            $body = json_decode(wp_remote_retrieve_body($verify));
            $threshold = (float) apply_filters('sv_recaptcha_v3_score_threshold', 0.5);
            if (is_object($body) && !empty($body->success) && isset($body->score) && (float) $body->score >= $threshold) {
                return $user;
            }
            return new WP_Error('recaptcha_failed', '<strong>' . esc_html__('BẢO MẬT', 'sitevorx') . '</strong>: ' . esc_html__('Phiên đăng nhập bị nghi ngờ là bot. Vui lòng thử lại.', 'sitevorx'));
        }, 100, 3);
    } else {
        // ── reCAPTCHA v2 (checkbox) ────────────────────────────────────────
        add_action('login_form', function() {
            $site_key = get_option('sv_sec_recaptcha_site_key');
            if($site_key) {
                echo '<div class="g-recaptcha" data-sitekey="'.esc_attr($site_key).'" style="margin-bottom: 15px;"></div>';
            }
        });
        add_action('login_enqueue_scripts', function() {
            $site_key = get_option('sv_sec_recaptcha_site_key');
            if (!$site_key) return;
            wp_enqueue_script('sitevorx-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true);
        });
        add_filter('script_loader_tag', function($tag, $handle) {
            if ('sitevorx-recaptcha' !== $handle) return $tag;
            if (false === strpos($tag, ' async')) $tag = str_replace(' src', ' async defer src', $tag);
            return $tag;
        }, 10, 2);
        add_filter('authenticate', function($user, $username, $password) {
            if (is_wp_error($user) || empty($username) || empty($password)) return $user;
            $secret_key = get_option('sv_sec_recaptcha_secret_key');
            if(!$secret_key) return $user;
            $recaptcha_response = isset($_POST['g-recaptcha-response']) ? sanitize_text_field(wp_unslash($_POST['g-recaptcha-response'])) : '';
            if(empty($recaptcha_response)) return new WP_Error('recaptcha_empty', '<strong>' . esc_html__('BẢO MẬT', 'sitevorx') . '</strong>: ' . esc_html__('Vui lòng xác minh reCAPTCHA.', 'sitevorx'));
            $verify = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
                'timeout' => 15,
                'body'    => array(
                    'secret'   => $secret_key,
                    'response' => $recaptcha_response,
                ),
            ));
            if (!is_wp_error($verify)) {
                $body = json_decode(wp_remote_retrieve_body($verify));
                if (is_object($body) && !empty($body->success)) return $user;
            }
            return new WP_Error('recaptcha_failed', '<strong>' . esc_html__('BẢO MẬT', 'sitevorx') . '</strong>: ' . esc_html__('Xác minh reCAPTCHA không thành công.', 'sitevorx'));
        }, 100, 3);
    }
}

// =====================================================================
// LOGIN LOCKOUT — limit failed attempts per IP with allowlist + diagnostics
// =====================================================================

function sv_login_get_max_attempts() {
    $max = (int) get_option( 'sv_sec_limit_login_max', 5 );
    return max( 3, min( 50, $max ) );
}

function sv_login_get_lockout_minutes() {
    $minutes = (int) get_option( 'sv_sec_limit_login_minutes', 1440 );
    return max( 5, min( 10080, $minutes ) );
}

function sv_login_get_allowlist() {
    $raw = (string) get_option( 'sv_sec_limit_login_allowlist', '' );
    if ( '' === trim( $raw ) ) {
        return array();
    }
    $items = preg_split( '/[\s,]+/', $raw );
    $clean = array();
    foreach ( (array) $items as $item ) {
        $item = trim( (string) $item );
        if ( '' === $item ) continue;
        if ( filter_var( $item, FILTER_VALIDATE_IP ) ) {
            $clean[] = $item;
        }
    }
    return array_values( array_unique( $clean ) );
}

function sv_login_ip_allowlisted( $ip ) {
    if ( '' === $ip ) return false;
    $allow = sv_login_get_allowlist();
    return in_array( $ip, $allow, true );
}

function sv_login_attempt_key( $ip ) {
    return 'sv_login_attempts_' . md5( $ip );
}

if ( get_option( 'sv_sec_limit_login' ) === '1' ) {
    add_filter( 'authenticate', function( $user, $username, $password ) {
        $ip = sv_get_client_ip();
        if ( sv_login_ip_allowlisted( $ip ) ) {
            return $user;
        }
        $attempts = (int) get_transient( sv_login_attempt_key( $ip ) );
        $max      = sv_login_get_max_attempts();
        if ( $attempts >= $max ) {
            $minutes = sv_login_get_lockout_minutes();
            $window  = $minutes >= 60 ? sprintf( _n( '%d giờ', '%d giờ', (int) round( $minutes / 60 ), 'sitevorx' ), (int) round( $minutes / 60 ) ) : sprintf( _n( '%d phút', '%d phút', $minutes, 'sitevorx' ), $minutes );
            return new WP_Error(
                'too_many_attempts',
                '<strong>' . esc_html__( 'BẢO MẬT', 'sitevorx' ) . '</strong>: ' . esc_html( sprintf( __( 'IP bị tạm khóa %s do nhập sai quá nhiều.', 'sitevorx' ), $window ) )
            );
        }
        return $user;
    }, 30, 3 );

    add_action( 'wp_login_failed', function( $username, $error = null ) {
        // Only genuine wrong-credential failures should count toward the IP
        // lockout. reCAPTCHA / honeypot / our own lockout WP_Errors must be
        // ignored here, otherwise a flaky reCAPTCHA or a tripped honeypot can
        // lock out a legitimate admin who typed the correct password.
        if ( $error instanceof WP_Error && array_intersect(
            array( 'too_many_attempts', 'recaptcha_empty', 'recaptcha_failed', 'honeypot_triggered' ),
            (array) $error->get_error_codes()
        ) ) {
            return;
        }
        $ip = sv_get_client_ip();
        if ( sv_login_ip_allowlisted( $ip ) ) {
            return;
        }
        $key      = sv_login_attempt_key( $ip );
        $attempts = (int) get_transient( $key );
        $attempts++;
        $minutes  = sv_login_get_lockout_minutes();
        set_transient( $key, $attempts, $minutes * MINUTE_IN_SECONDS );

        $max = sv_login_get_max_attempts();
        if ( $attempts === $max && function_exists( 'sv_audit_log' ) ) {
            sv_audit_log( 'login_lockout', array(
                'ip'            => $ip,
                'attempts'      => $attempts,
                'last_username' => sanitize_user( (string) $username, true ),
                'lockout_min'   => $minutes,
            ) );
        }
    }, 10, 2 );
}

/**
 * 2. CÁC TÙY CHỈNH TỐI ƯU
 */
if (get_option('sv_opt_lazy_load') == '1') { add_filter('wp_lazy_loading_enabled', '__return_true'); }
if (get_option('sv_opt_allow_svg') == '1') {
    add_filter('upload_mimes', function($mimes) {
        if ( ! current_user_can('manage_options') ) {
            return $mimes;
        }

        $mimes['svg'] = 'image/svg+xml';
        return $mimes;
    });
    // Sanitize SVG on upload to prevent XSS via embedded scripts/event handlers
    add_filter('wp_handle_upload_prefilter', function($file) {
        if ( ! current_user_can('manage_options') ) {
            return $file;
        }

        $file_type = isset( $file['type'] ) ? (string) $file['type'] : '';
        $file_name = isset( $file['name'] ) ? (string) $file['name'] : '';
        $extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

        // Match on extension too: a .svg uploaded with a different/empty MIME
        // type must not slip past the sanitizer.
        if ( 'image/svg+xml' === $file_type || 'svg' === $extension ) {
            $filesystem = sv_get_filesystem();
            $tmp_name   = isset( $file['tmp_name'] ) ? wp_normalize_path( (string) $file['tmp_name'] ) : '';

            if ( ! $filesystem || '' === $tmp_name ) {
                $file['error'] = __( 'Không thể xử lý tệp SVG trên máy chủ này. Vui lòng kiểm tra quyền ghi tạm thời.', 'sitevorx' );
                return $file;
            }

            $content = $filesystem->get_contents( $tmp_name );
            if ( ! is_string( $content ) || '' === $content ) {
                $file['error'] = __( 'Không thể đọc nội dung tệp SVG để kiểm tra an toàn.', 'sitevorx' );
                return $file;
            }
            if ( preg_match('/<(?:script|iframe|object|embed|link|meta|base|foreignObject)\b/i', $content)
                 || preg_match('/<\?xml-stylesheet\b/i', $content)
                 || preg_match('/<!DOCTYPE[\s\S]*?\[/i', $content)
                 || preg_match('/<!ENTITY\b/i', $content)
                 || preg_match('/SYSTEM\s+["\x27]/i', $content)
                 || preg_match('/PUBLIC\s+["\x27]/i', $content) ) {
                $file['error'] = __( 'Tệp SVG chứa cấu trúc nguy hiểm và đã bị chặn.', 'sitevorx' );
                return $file;
            }
            $content = preg_replace('/<!DOCTYPE[\s\S]*?>/i', '', $content);
            $content = preg_replace('/<!ENTITY[\s\S]*?>/i', '', $content);
            $content = preg_replace('/<script[\s\S]*?<\/script>/i', '', $content);
            $content = preg_replace('/<foreignObject[\s\S]*?<\/foreignObject>/i', '', $content);
            $content = preg_replace('/<style[\s\S]*?<\/style>/i', '', $content);
            $content = preg_replace('/<animate\b[^>]*>/i', '', $content);
            $content = preg_replace('/<set\b[^>]*>/i', '', $content);
            $content = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);
            $content = preg_replace('/\bon\w+\s*=\s*\S+/i', '', $content);
            $content = preg_replace('/href\s*=\s*["\']?\s*javascript\s*:/i', 'href="', $content);
            $content = preg_replace('/xlink:href\s*=\s*["\']?\s*javascript\s*:/i', 'xlink:href="', $content);
            $content = preg_replace('/href\s*=\s*["\']?\s*data\s*:/i', 'href="', $content);
            $content = preg_replace('/xlink:href\s*=\s*["\']?\s*data\s*:/i', 'xlink:href="', $content);
            $content = preg_replace('/<use\b[^>]*xlink:href\s*=\s*["\']https?:\/\/[^"\']*["\'][^>]*\/?>/i', '', $content);
            if ( ! is_string( $content ) || ! $filesystem->put_contents( $tmp_name, $content, FS_CHMOD_FILE ) ) {
                $file['error'] = __( 'Không thể lưu tệp SVG sau khi lọc nội dung không an toàn.', 'sitevorx' );
            }
        }
        return $file;
    });
}
if (get_option('sv_opt_limit_revisions') == '1') { add_filter('wp_revisions_to_keep', function($num, $post) { return 5; }, 10, 2); }
if (get_option('sv_opt_disable_heartbeat') == '1') {
    add_filter('heartbeat_settings', function($settings) {
        $settings['interval'] = max( isset($settings['interval']) ? (int) $settings['interval'] : 15, 60 );
        return $settings;
    });
}
if (get_option('sv_sec_disable_editor') == '1') {
    // Scope the lock to the theme/plugin editor via the file_mod_allowed filter
    // only (matching the free edition). We deliberately do NOT
    // define( 'DISALLOW_FILE_EDIT', true ) at runtime: that constant also
    // disables the plugin/theme installer & updater (a heavier side effect than
    // intended), and defining it this late — after wp-config — is unreliable.
    add_filter('file_mod_allowed', function($allowed, $context) {
        if (in_array($context, array('capability_edit_themes', 'capability_edit_plugins'), true)) {
            return false;
        }

        return $allowed;
    }, 10, 2);
}
if (get_option('sv_sec_disable_xmlrpc') == '1') { add_filter('xmlrpc_enabled', '__return_false'); }

// --- Frontend bloat removal toggles ---------------------------------------

if ( get_option( 'sv_opt_disable_emojis' ) === '1' ) {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    add_filter( 'tiny_mce_plugins', function ( $plugins ) {
        return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
    } );
    add_filter( 'emoji_svg_url', '__return_false' );
}

if ( get_option( 'sv_opt_disable_embeds' ) === '1' ) {
    add_action( 'init', function () {
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        remove_action( 'wp_head', 'wp_oembed_add_host_js' );
        wp_deregister_script( 'wp-embed' );
    }, 9999 );
    add_filter( 'embed_oembed_discover', '__return_false' );
    add_filter( 'tiny_mce_plugins', function ( $plugins ) {
        return is_array( $plugins ) ? array_diff( $plugins, array( 'wpembed' ) ) : array();
    } );
    add_filter( 'rewrite_rules_array', function ( $rules ) {
        foreach ( $rules as $rule => $rewrite ) {
            if ( false !== strpos( $rewrite, 'embed=true' ) ) {
                unset( $rules[ $rule ] );
            }
        }
        return $rules;
    } );
}

if ( get_option( 'sv_opt_remove_jquery_migrate' ) === '1' ) {
    add_action( 'wp_default_scripts', function ( $scripts ) {
        if ( is_admin() ) return;
        if ( ! empty( $scripts->registered['jquery'] ) ) {
            $deps = $scripts->registered['jquery']->deps;
            $scripts->registered['jquery']->deps = array_diff( $deps, array( 'jquery-migrate' ) );
        }
    } );
}

if ( get_option( 'sv_opt_clean_wp_head' ) === '1' ) {
    remove_action( 'wp_head', 'wp_generator' );
    remove_action( 'wp_head', 'rsd_link' );
    remove_action( 'wp_head', 'wlwmanifest_link' );
    remove_action( 'wp_head', 'wp_shortlink_wp_head' );
    remove_action( 'wp_head', 'feed_links_extra', 3 );
    remove_action( 'wp_head', 'rest_output_link_wp_head' );
    add_filter( 'the_generator', '__return_empty_string' );
}

if ( get_option( 'sv_opt_disable_pingbacks' ) === '1' ) {
    add_filter( 'xmlrpc_methods', function ( $methods ) {
        unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
        return $methods;
    } );
    add_filter( 'wp_headers', function ( $headers ) {
        unset( $headers['X-Pingback'] );
        return $headers;
    } );
    add_filter( 'pings_open', '__return_false' );
    add_filter( 'pre_option_default_ping_status', function () { return 'closed'; } );
}

/**
 * 3. BẢO MẬT URL (KHÔNG COOKIE)
 */
$secret_key = get_option('sv_sec_login_key', '');
if (!empty($secret_key) && get_option('sv_sec_enable_login_key') == '1') {

    // Block direct access to wp-login.php (runs before login form renders)
    add_action('login_init', function() use ($secret_key) {
        // Allow special actions: logout, postpass, rp, resetpass, register, confirmaction
        $allowed_actions = ['logout', 'postpass', 'rp', 'resetpass', 'register', 'confirmaction', 'lostpassword'];
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        if ($action && in_array($action, $allowed_actions, true)) {
            return;
        }
        // If secret key is present → allow login page and set cookie for POST submissions
        if (isset($_GET[$secret_key])) {
            setcookie('sv_login_auth', hash_hmac('sha256', $secret_key, NONCE_SALT), 0, COOKIEPATH, COOKIE_DOMAIN, sv_is_effectively_ssl(), true);
            return;
        }
        // Allow POST requests only if the auth cookie is set (prevents direct brute-force POST bypass)
        $request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
        if ( 'POST' === $request_method ) {
            if ( isset( $_COOKIE['sv_login_auth'] ) ) {
                $cookie_val = sanitize_text_field( wp_unslash( $_COOKIE['sv_login_auth'] ) );
                if ( hash_equals( hash_hmac( 'sha256', $secret_key, NONCE_SALT ), $cookie_val ) ) {
                    return;
                }
            }
            wp_safe_redirect(home_url('/'));
            exit;
        }
        // Otherwise block access
        wp_safe_redirect(home_url('/'));
        exit;
    });

    // Block wp-admin access for non-logged-in users (runs after user session is ready)
    add_action('wp_loaded', function() use ($secret_key) {
        if (defined('DOING_AJAX') || defined('DOING_CRON')) {
            return;
        }
        // admin-post.php handles front-end (nopriv) form submissions & webhooks
        // for logged-out visitors — never block it or those forms break.
        $pagenow_admin = isset($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : '';
        $script_name   = isset($_SERVER['SCRIPT_NAME']) ? wp_basename(sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME']))) : '';
        if ('admin-post.php' === $pagenow_admin || 'admin-post.php' === $script_name) {
            return;
        }
        if (is_admin() && !is_user_logged_in()) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    });

    // Handle homepage entry point: /?secretkey → redirect to wp-login.php?secretkey
    add_action('template_redirect', function() use ($secret_key) {
        if (isset($_GET[$secret_key]) && !is_admin()) {
            wp_safe_redirect(site_url('wp-login.php?' . $secret_key));
            exit;
        }
    });

    // Carry the secret key on the login FORM action (scheme 'login_post') so the
    // submitted POST is authorised by ?key directly — not only by the cookie set
    // during the GET. Without this the form posts to a bare wp-login.php; if that
    // GET login page was page-cached (CDN / cache plugin) the auth cookie was
    // never set, so login_init bounced the POST to the homepage BEFORE
    // authentication — user lands on the front page instead of wp-admin.
    add_filter('site_url', function($url, $path, $scheme) use ($secret_key) {
        if ('login_post' === $scheme && false !== strpos((string) $path, 'wp-login.php')) {
            return add_query_arg($secret_key, '', $url);
        }
        return $url;
    }, 10, 3);
}
add_filter('login_url', function($url) {
    $key = get_option('sv_sec_login_key');
    return ($key && get_option('sv_sec_enable_login_key') == '1') ? add_query_arg($key, '', $url) : $url;
});

/**
 * 4. GIAO DIỆN QUẢN TRỊ
 */
function sv_login_get_current_lockouts() {
    global $wpdb;
    $max  = sv_login_get_max_attempts();
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
        $wpdb->esc_like( '_transient_sv_login_attempts_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_' ) . '%'
    ) );
    $list = array();
    foreach ( $rows as $row ) {
        $attempts = (int) $row->option_value;
        if ( $attempts < $max ) {
            continue;
        }
        $hash    = substr( $row->option_name, strlen( '_transient_sv_login_attempts_' ) );
        $timeout = (int) get_option( '_transient_timeout_sv_login_attempts_' . $hash, 0 );
        $list[]  = array(
            'hash'       => $hash,
            'attempts'   => $attempts,
            'expires_at' => $timeout,
        );
    }
    return $list;
}

function sv_login_unlock_by_hash( $hash ) {
    if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $hash ) ) {
        return false;
    }
    return delete_transient( 'sv_login_attempts_' . $hash );
}

function sv_display_optimizer_page() {
    global $wpdb;
    $active_tab = isset( $_GET[ 'tab' ] ) ? sanitize_text_field(wp_unslash($_GET[ 'tab' ])) : 'optimizer';

    if ( current_user_can('manage_options') && isset($_POST['sv_unlock_ip']) && check_admin_referer('sv_opt_nonce') ) {
        $unlock_hash = isset( $_POST['unlock_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['unlock_hash'] ) ) : '';
        if ( sv_login_unlock_by_hash( $unlock_hash ) ) {
            if ( function_exists( 'sv_audit_log' ) ) {
                sv_audit_log( 'login_unlock', array( 'hash' => $unlock_hash ) );
            }
            echo '<div class="notice notice-success is-dismissible sv-notice"><p>' . esc_html__( 'Đã mở khóa IP.', 'sitevorx' ) . '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible sv-notice"><p>' . esc_html__( 'Không tìm thấy bản ghi khóa cần mở.', 'sitevorx' ) . '</p></div>';
        }
        $active_tab = 'security';
    }

    if ( current_user_can('manage_options') && isset($_POST['sv_save_optimizer']) && check_admin_referer('sv_opt_nonce') ) {
        $opt_keys = array(
            'sv_opt_allow_svg'         => array( 'post' => 'opt_allow_svg',         'label' => __( 'Upload SVG', 'sitevorx' ) ),
            'sv_opt_limit_revisions'   => array( 'post' => 'opt_limit_revisions',   'label' => __( 'Giới hạn bản nháp', 'sitevorx' ) ),
            'sv_opt_disable_heartbeat' => array( 'post' => 'opt_disable_heartbeat', 'label' => __( 'Giảm tải Heartbeat', 'sitevorx' ) ),
            'sv_opt_lazy_load'         => array( 'post' => 'opt_lazy_load',         'label' => __( 'Lazy Load ảnh', 'sitevorx' ) ),
            'sv_opt_disable_emojis'    => array( 'post' => 'opt_disable_emojis',    'label' => __( 'Tắt Emoji', 'sitevorx' ) ),
            'sv_opt_disable_embeds'    => array( 'post' => 'opt_disable_embeds',    'label' => __( 'Tắt oEmbed', 'sitevorx' ) ),
            'sv_opt_clean_wp_head'     => array( 'post' => 'opt_clean_wp_head',     'label' => __( 'Dọn wp_head', 'sitevorx' ) ),
            'sv_opt_remove_jquery_migrate' => array( 'post' => 'opt_remove_jquery_migrate', 'label' => __( 'Gỡ jQuery Migrate', 'sitevorx' ) ),
            'sv_opt_disable_pingbacks' => array( 'post' => 'opt_disable_pingbacks', 'label' => __( 'Tắt Trackback/Pingback', 'sitevorx' ) ),
        );
        $before = array();
        $after  = array();
        $spec   = array();
        foreach ( $opt_keys as $opt_key => $meta ) {
            $before[ $opt_key ] = get_option( $opt_key, '0' );
            $after[ $opt_key ]  = isset( $_POST[ $meta['post'] ] ) ? '1' : '0';
            $spec[ $opt_key ]   = array( 'label' => $meta['label'], 'type' => 'bool' );
            update_option( $opt_key, $after[ $opt_key ] );
        }
        if ( function_exists( 'sv_audit_log' ) ) {
            $summary = sv_audit_summarize_diff( $before, $after, $spec );
            sv_audit_log( 'optimizer_saved', array(
                'summary' => $summary !== '' ? $summary : __( 'Lưu lại không có thay đổi', 'sitevorx' ),
            ) );
        }
        echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . esc_html__('Đã cập nhật các thiết lập tối ưu hệ thống.', 'sitevorx') . '</p></div>';
    }

    if ( current_user_can('manage_options') && isset($_POST['sv_save_security']) && check_admin_referer('sv_opt_nonce') ) {
        $sec_errors = [];

        $login_key_val    = sanitize_key( wp_unslash( $_POST['sec_login_key'] ?? '' ) );
        $enable_login_key = isset( $_POST['sec_enable_login_key'] );
        if ( $enable_login_key && $login_key_val === '' ) {
            $sec_errors[] = __( 'Vui lòng nhập từ khóa bảo mật trước khi bật "Đổi Đường Dẫn Đăng Nhập".', 'sitevorx' );
        }

        $rc_site          = sanitize_text_field( wp_unslash( $_POST['sec_recaptcha_site_key'] ?? '' ) );
        $rc_secret        = sanitize_text_field( wp_unslash( $_POST['sec_recaptcha_secret_key'] ?? '' ) );
        $enable_recaptcha = isset( $_POST['sec_enable_recaptcha'] );
        if ( $enable_recaptcha && ( $rc_site === '' || $rc_secret === '' ) ) {
            $sec_errors[] = __( 'Vui lòng nhập đầy đủ Site Key và Secret Key trước khi bật "Khóa Tự Động Đăng Nhập".', 'sitevorx' );
        }

        if ( empty( $sec_errors ) ) {
            $rc_version_in = sanitize_key( wp_unslash( $_POST['sec_recaptcha_version'] ?? 'v2' ) );
            $rc_version_in = in_array( $rc_version_in, array( 'v2', 'v3' ), true ) ? $rc_version_in : 'v2';

            $max_input     = isset( $_POST['sec_limit_login_max'] ) ? absint( wp_unslash( $_POST['sec_limit_login_max'] ) ) : 5;
            $minutes_input = isset( $_POST['sec_limit_login_minutes'] ) ? absint( wp_unslash( $_POST['sec_limit_login_minutes'] ) ) : 1440;
            $max_clamped     = max( 3, min( 50, $max_input ) );
            $minutes_clamped = max( 5, min( 10080, $minutes_input ) );

            $allowlist_raw = isset( $_POST['sec_limit_login_allowlist'] ) ? wp_unslash( $_POST['sec_limit_login_allowlist'] ) : '';
            $allowlist_raw = is_string( $allowlist_raw ) ? $allowlist_raw : '';
            $allow_items   = preg_split( '/[\r\n,\s]+/', $allowlist_raw );
            $allow_clean   = array();
            foreach ( (array) $allow_items as $item ) {
                $item = trim( (string) $item );
                if ( '' === $item ) continue;
                if ( filter_var( $item, FILTER_VALIDATE_IP ) ) {
                    $allow_clean[] = $item;
                }
            }
            $allowlist_clean = implode( "\n", array_unique( $allow_clean ) );

            $sec_spec = array(
                'sv_sec_enable_login_key'      => array( 'label' => __( 'Đổi URL đăng nhập', 'sitevorx' ),    'type' => 'bool' ),
                'sv_sec_disable_editor'        => array( 'label' => __( 'Khóa chỉnh sửa mã nguồn', 'sitevorx' ), 'type' => 'bool' ),
                'sv_sec_disable_xmlrpc'        => array( 'label' => __( 'Khóa XML-RPC', 'sitevorx' ),         'type' => 'bool' ),
                'sv_sec_enable_recaptcha'      => array( 'label' => __( 'reCAPTCHA đăng nhập', 'sitevorx' ),  'type' => 'bool' ),
                'sv_sec_limit_login'           => array( 'label' => __( 'Khóa IP truy cập trái phép', 'sitevorx' ), 'type' => 'bool' ),
                'sv_sec_login_key'             => array( 'label' => __( 'từ khóa URL đăng nhập', 'sitevorx' ),  'type' => 'value' ),
                'sv_sec_recaptcha_version'     => array( 'label' => __( 'phiên bản reCAPTCHA', 'sitevorx' ),    'type' => 'value' ),
                'sv_sec_recaptcha_site_key'    => array( 'label' => __( 'reCAPTCHA Site Key', 'sitevorx' ),     'type' => 'value' ),
                'sv_sec_recaptcha_secret_key'  => array( 'label' => __( 'reCAPTCHA Secret Key', 'sitevorx' ),   'type' => 'value' ),
                'sv_sec_limit_login_max'       => array( 'label' => __( 'số lần sai tối đa', 'sitevorx' ),      'type' => 'value' ),
                'sv_sec_limit_login_minutes'   => array( 'label' => __( 'thời gian khóa (phút)', 'sitevorx' ),  'type' => 'value' ),
                'sv_sec_limit_login_allowlist' => array( 'label' => __( 'danh sách IP miễn khóa', 'sitevorx' ), 'type' => 'value' ),
            );
            $before = array();
            foreach ( $sec_spec as $opt_key => $_ ) {
                $before[ $opt_key ] = get_option( $opt_key, '' );
            }

            update_option( 'sv_sec_login_key', $login_key_val );
            update_option( 'sv_sec_enable_login_key', ( $enable_login_key && $login_key_val !== '' ) ? '1' : '0' );
            update_option( 'sv_sec_disable_editor', isset( $_POST['sec_disable_editor'] ) ? '1' : '0' );
            update_option( 'sv_sec_disable_xmlrpc', isset( $_POST['sec_disable_xmlrpc'] ) ? '1' : '0' );
            update_option( 'sv_sec_recaptcha_site_key', $rc_site );
            update_option( 'sv_sec_recaptcha_secret_key', $rc_secret );
            update_option( 'sv_sec_recaptcha_version', $rc_version_in );
            update_option( 'sv_sec_enable_recaptcha', ( $enable_recaptcha && $rc_site !== '' && $rc_secret !== '' ) ? '1' : '0' );
            update_option( 'sv_sec_limit_login', isset( $_POST['sec_limit_login'] ) ? '1' : '0' );
            update_option( 'sv_sec_limit_login_max', $max_clamped );
            update_option( 'sv_sec_limit_login_minutes', $minutes_clamped );
            update_option( 'sv_sec_limit_login_allowlist', $allowlist_clean );
            // sv_sec_trusted_proxy_ips: UI removed in this release because the
            // option is footgun-prone for normal users (mis-fill = IP spoofing
            // window). Existing values are preserved on disk and still honored
            // by sv_get_trusted_proxy_ips(); power users can extend the list
            // via the `sv_trusted_proxy_ips` filter from wp-config or a
            // mu-plugin instead of the admin UI.

            if ( function_exists( 'sv_audit_log' ) ) {
                $after = array();
                foreach ( $sec_spec as $opt_key => $_ ) {
                    $after[ $opt_key ] = get_option( $opt_key, '' );
                }
                $summary = sv_audit_summarize_diff( $before, $after, $sec_spec );
                sv_audit_log( 'security_saved', array(
                    'summary' => $summary !== '' ? $summary : __( 'Lưu lại không có thay đổi', 'sitevorx' ),
                ) );
            }
            echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>' . esc_html__( 'Cấu hình bảo mật đã được áp dụng thành công.', 'sitevorx' ) . '</p></div>';
        } else {
            foreach ( $sec_errors as $err ) {
                echo '<div class="notice notice-error is-dismissible sv-notice"><p>' . esc_html( $err ) . '</p></div>';
            }
        }
    }

    if ( current_user_can('manage_options') && isset($_POST['sv_run_cleanup']) && check_admin_referer('sv_opt_nonce') ) {
        $cleaned_items = array();
        if (isset($_POST['clean_revisions'])) { $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'"); if ($deleted > 0) $cleaned_items[] = "<strong>$deleted</strong> " . __('bản nháp', 'sitevorx'); }
        if (isset($_POST['clean_spam'])) { $deleted = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved IN ('spam', 'trash')"); if ($deleted > 0) $cleaned_items[] = "<strong>$deleted</strong> " . __('bình luận rác', 'sitevorx'); }
        if (isset($_POST['clean_transients'])) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%%' AND option_value < %d", time()));
            $del_trans = $wpdb->query("DELETE a FROM {$wpdb->options} a LEFT JOIN {$wpdb->options} b ON CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12)) = b.option_name WHERE a.option_name LIKE '_transient_%' AND a.option_name NOT LIKE '_transient_timeout_%' AND b.option_name IS NULL");
            if ($del_trans > 0) $cleaned_items[] = "<strong>$del_trans</strong> " . __('bộ nhớ đệm', 'sitevorx');
        }

        if ( function_exists( 'sv_audit_log' ) ) {
            $picked = array();
            if ( isset( $_POST['clean_revisions'] ) )  $picked[] = __( 'bản nháp', 'sitevorx' );
            if ( isset( $_POST['clean_spam'] ) )       $picked[] = __( 'bình luận rác', 'sitevorx' );
            if ( isset( $_POST['clean_transients'] ) ) $picked[] = __( 'bộ nhớ đệm', 'sitevorx' );
            $summary = empty( $picked )
                ? __( 'Không chọn mục nào', 'sitevorx' )
                : sprintf( __( 'Dọn: %1$s — tổng %2$d nhóm', 'sitevorx' ), implode( ', ', $picked ), count( $cleaned_items ) );
            sv_audit_log( 'cleanup_run', array( 'summary' => $summary ) );
        }

        if (empty($cleaned_items)) {
            echo '<div class="notice notice-info is-dismissible sv-notice" style="display:none;"><p>✨ ' . esc_html__('Hệ thống của bạn đang rất sạch sẽ, không có rác cục bộ nào cần dọn lúc này!', 'sitevorx') . '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible sv-notice" style="display:none;"><p>🎉 <strong>' . esc_html__('Dọn dẹp thành công!', 'sitevorx') . '</strong> ' . esc_html__('Đã giải phóng:', 'sitevorx') . ' ' . implode(', ', $cleaned_items) . '.</p></div>';
        } 
    }
    ?>
    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar('optimizer'); ?>
            <div class="sv-content-area">
                
                <div class="sv-top-banner">
                    <h2><?php esc_html_e('Tối Ưu Tốc Độ', 'sitevorx'); ?></h2>
                    <p><?php esc_html_e('Dọn rác hệ thống, giảm tải máy chủ, gỡ các script không cần thiết — giúp website tải nhanh hơn mà không cần plugin cache.', 'sitevorx'); ?></p>
                    <p style="margin-top:6px; font-size:13px; color:#64748b;"><?php echo sv_kses_basic( __( 'Các cấu hình bảo mật và quét mã độc đã chuyển sang <a href="?page=sv-security-center" style="color:#dc2626; font-weight:600;">Trung tâm Bảo mật</a>.', 'sitevorx' ) ); ?></p>
                </div>

                <?php if ( true ) : ?>
                <form method="POST">
                    <?php wp_nonce_field('sv_opt_nonce'); ?>
                    <div class="sv-content-box">
                        <div class="sv-box-header"><span class="dashicons dashicons-database" style="color:#0073aa;"></span><h3><?php esc_html_e('Dọn Rác Hệ Thống', 'sitevorx'); ?></h3></div>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Xóa Bản Nháp (Revisions)', 'sitevorx'); ?></strong><p><?php esc_html_e('Các bài viết cũ thường lưu rất nhiều bản nháp gây nặng máy chủ. Nút này sẽ dọn dẹp chúng.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="clean_revisions" value="1" checked><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Dọn Bình Luận Rác', 'sitevorx'); ?></strong><p><?php esc_html_e('Xóa vĩnh viễn các bình luận đang nằm trong thùng rác hoặc bị đánh dấu là spam.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="clean_spam" value="1" checked><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Dọn File Tạm (Transients)', 'sitevorx'); ?></strong><p><?php esc_html_e('Bộ nhớ tạm thời của website đôi khi bị đầy gây chậm, xóa đi sẽ dọn sạch để máy chủ nhẹ hơn.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="clean_transients" value="1" checked><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-footer"><button type="submit" name="sv_run_cleanup" class="button button-primary" style="background:#d63638; border:none;"><?php esc_html_e('Chạy dọn dẹp', 'sitevorx'); ?></button></div>
                    </div>
                    <div class="sv-content-box">
                        <div class="sv-box-header"><span class="dashicons dashicons-admin-generic" style="color:#27ae60;"></span><h3><?php esc_html_e('Tối Ưu Nâng Cao', 'sitevorx'); ?></h3></div>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Tải Ảnh Chậm (Lazy Load)', 'sitevorx'); ?></strong><p><?php esc_html_e('Chỉ tải hình ảnh khi người dùng cuộn tới. Giúp trang chủ tải cực nhanh và tiết kiệm băng thông.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="opt_lazy_load" value="1" <?php checked(get_option('sv_opt_lazy_load'), '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-group sv-form-group-stacked-note">
                            <div class="sv-form-label"><strong><?php esc_html_e('Giảm Tải Máy Chủ (Heartbeat)', 'sitevorx'); ?></strong><p><?php esc_html_e('Tắt các tiến trình ngầm không quan trọng (Heartbeat API) để Hosting/VPS của bạn không bị quá tải.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input sv-form-input-with-note"><label class="sv-switch"><input type="checkbox" name="opt_disable_heartbeat" value="1" <?php checked(get_option('sv_opt_disable_heartbeat'), '1'); ?>><span class="sv-slider"></span></label><p class="sv-option-note"><?php esc_html_e('Khi bật, Heartbeat được giảm tần suất xuống 60 giây để vẫn giữ autosave và khóa bài viết.', 'sitevorx'); ?></p></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Giới Hạn Bản Nháp (Tối đa 5)', 'sitevorx'); ?></strong><p><?php esc_html_e('Ngăn chặn việc 1 bài viết lưu hàng trăm bản nháp làm phình to dung lượng Database.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="opt_limit_revisions" value="1" <?php checked(get_option('sv_opt_limit_revisions'), '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label"><strong><?php esc_html_e('Cho phép Upload file SVG', 'sitevorx'); ?></strong><p><?php esc_html_e('Mở khóa tính năng tải lên Icon và hình ảnh vector định dạng .svg siêu nhẹ và sắc nét.', 'sitevorx'); ?></p></div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="opt_allow_svg" value="1" <?php checked(get_option('sv_opt_allow_svg'), '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                    </div>

                    <div class="sv-content-box">
                        <div class="sv-box-header"><span class="dashicons dashicons-trash" style="color:#7c3aed;"></span><h3><?php esc_html_e('Gỡ thành phần không cần thiết', 'sitevorx'); ?></h3></div>
                        <div class="sv-form-group">
                            <div class="sv-form-label">
                                <strong><?php esc_html_e('Tắt Emoji', 'sitevorx'); ?></strong>
                                <p><?php esc_html_e('Trình duyệt hiện đại hiển thị emoji 😀 sẵn — bỏ phần WordPress chèn thêm để mỗi trang nhẹ hơn ~15KB và tải nhanh hơn.', 'sitevorx'); ?></p>
                            </div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="opt_disable_emojis" value="1" <?php checked(get_option('sv_opt_disable_emojis'), '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label">
                                <strong><?php esc_html_e('Tắt nhúng link tự động', 'sitevorx'); ?></strong>
                                <p><?php esc_html_e('Khi bạn dán link YouTube/Twitter vào bài viết, WordPress nhúng tự động (cần thêm ~12KB JavaScript). Tắt nếu bạn không dùng tính năng này — nhúng video bằng iframe vẫn hoạt động bình thường.', 'sitevorx'); ?></p>
                            </div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="opt_disable_embeds" value="1" <?php checked(get_option('sv_opt_disable_embeds'), '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label">
                                <strong><?php esc_html_e('Bỏ thư viện JavaScript cũ', 'sitevorx'); ?></strong>
                                <p><?php esc_html_e('Bỏ một thư viện JavaScript chỉ cần cho theme/plugin rất cũ (~10KB). Hầu hết theme hiện đại không cần nữa — nếu site vẫn chạy bình thường sau khi bật thì để bật luôn.', 'sitevorx'); ?></p>
                            </div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="opt_remove_jquery_migrate" value="1" <?php checked(get_option('sv_opt_remove_jquery_migrate'), '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label">
                                <strong><?php esc_html_e('Ẩn thông tin phiên bản WordPress', 'sitevorx'); ?></strong>
                                <p><?php esc_html_e('Mặc định WordPress in ra version đang dùng trong source code — hacker có thể dùng để tìm lỗ hổng tương ứng. Bật để ẩn thông tin này và xóa vài dòng HTML rác kèm theo.', 'sitevorx'); ?></p>
                            </div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="opt_clean_wp_head" value="1" <?php checked(get_option('sv_opt_clean_wp_head'), '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-group">
                            <div class="sv-form-label">
                                <strong><?php esc_html_e('Tắt thông báo liên kết tự động', 'sitevorx'); ?></strong>
                                <p><?php esc_html_e('Tính năng cũ của WordPress: khi có blog khác đăng link tới bài viết của bạn, hệ thống tự tạo bình luận thông báo. Thực tế chỉ gây spam và bị hacker lợi dụng để tấn công. Khuyên nên tắt.', 'sitevorx'); ?></p>
                            </div>
                            <div class="sv-form-input"><label class="sv-switch"><input type="checkbox" name="opt_disable_pingbacks" value="1" <?php checked(get_option('sv_opt_disable_pingbacks'), '1'); ?>><span class="sv-slider"></span></label></div>
                        </div>
                        <div class="sv-form-footer"><button type="submit" name="sv_save_optimizer" class="button button-primary"><?php esc_html_e('Lưu tùy chỉnh', 'sitevorx'); ?></button></div>
                    </div>
                </form>
                <?php endif; ?>

                <?php
                // Hiển thị mục Dọn Dẹp Tự Động (WP-Cron) chỉ ở tab Tăng Tốc Website
                if ( function_exists( 'sv_render_cron_settings' ) ) {
                    sv_render_cron_settings();
                }
                ?>

            </div>
        </div>
    </div>
    <?php
}
