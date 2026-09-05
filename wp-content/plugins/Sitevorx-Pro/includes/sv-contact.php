<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Enqueue floating-contact CSS only when needed (WP.org standard)
add_action( 'wp_enqueue_scripts', function() {
    $phone = get_option( 'sv_contact_phone' );
    $zalo  = get_option( 'sv_contact_zalo' );
    $fb    = get_option( 'sv_contact_fb' );
    if ( ! $phone && ! $zalo && ! $fb ) return;

    wp_register_style( 'sv-floating-contact', false, array(), SV_PLUGIN_VERSION );
    wp_enqueue_style( 'sv-floating-contact' );
    $css = '.sv-floating-contact{position:fixed;bottom:30px;right:30px;z-index:999999;display:flex;flex-direction:column;gap:15px;}'
         . '.sv-item-btn{width:55px;height:55px;border-radius:50%;display:flex !important;align-items:center !important;justify-content:center !important;box-shadow:0 4px 15px rgba(0,0,0,0.15);transition:0.3s;text-decoration:none !important;}'
         . '.sv-item-btn svg{width:34px;height:34px;fill:#fff;display:block;}'
         . '.sv-phone{background:#d63638;animation:sv-ringing 2s infinite;}'
         . '.sv-zalo{background:#0068ff;}.sv-fb{background:#0084ff;}'
         . '@keyframes sv-ringing{0%{transform:scale(1);}10%{transform:scale(1.1) rotate(5deg);}20%{transform:scale(1.1) rotate(-5deg);}30%{transform:scale(1.1) rotate(5deg);}40%{transform:scale(1);}}';
    wp_add_inline_style( 'sv-floating-contact', $css );
});

add_action('wp_footer', function() {
    $phone = get_option('sv_contact_phone'); $zalo = get_option('sv_contact_zalo'); $fb = get_option('sv_contact_fb');
    if (!$phone && !$zalo && !$fb) return;
    ?>
    <div class="sv-floating-contact">
        <?php if($fb): ?><a href="<?php echo esc_url($fb); ?>" target="_blank" rel="noopener noreferrer" aria-label="Messenger" class="sv-item-btn sv-fb"><svg viewBox="0 0 24 24"><path d="M12 2C6.477 2 2 6.145 2 11.258c0 2.903 1.46 5.498 3.746 7.225V22l3.322-1.822c.9.25 1.855.385 2.932.385 5.523 0 10-4.145 10-9.258C22 6.145 17.523 2 12 2zm1.077 12.396l-2.59-2.76-5.06 2.76 5.56-5.906 2.66 2.76 4.99-2.76-5.56 5.906z"/></svg></a><?php endif; ?>
        <?php if($zalo): ?><a href="<?php echo esc_url( strpos($zalo, 'http') === 0 ? $zalo : 'https://zalo.me/' . $zalo ); ?>" target="_blank" rel="noopener noreferrer" aria-label="Zalo" class="sv-item-btn sv-zalo"><svg viewBox="0 0 24 24"><path d="M12 2C6.477 2 2 6.477 2 12c0 2.925 1.488 5.498 3.75 7.225l-1.322 4.108c-.126.478.383.895.816.643l4.902-2.852c1.2.378 2.478.576 3.854.576 5.523 0 10-4.477 10-10S17.523 2 12 2zm3.5 13.5h-4.5v-1.5l2.6-3.4H11V9h4.5v1.5l-2.6 3.4h2.6v1.6z"/></svg></a><?php endif; ?>
        <?php if($phone): ?><a href="tel:<?php echo esc_attr($phone); ?>" aria-label="Phone" class="sv-item-btn sv-phone"><svg viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.82 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg></a><?php endif; ?>
    </div>
    <?php
});
