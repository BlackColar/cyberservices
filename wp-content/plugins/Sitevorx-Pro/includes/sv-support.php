<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================================================================
// SUPPORT CENTER PAGE
// ==========================================================================

function sv_get_support_config() {
    return apply_filters( 'sv_support_config', array(
        'hotline'      => '1900 9250',
        'ticket_url'   => 'https://portal.inet.vn/ticket',
        'helpdesk_url' => 'https://helpdesk.inet.vn',
    ) );
}

function sv_display_support_page() {
    $support_config = sv_get_support_config();
    ?>
    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar('support'); ?>
            <div class="sv-content-area">

                <div class="sv-top-banner">
                    <h2><?php esc_html_e('Trung Tâm Hỗ Trợ', 'sitevorx'); ?></h2>
                    <p><?php esc_html_e('Kênh hỗ trợ kỹ thuật và chăm sóc khách hàng ưu tiên dành riêng cho khách hàng iNET.', 'sitevorx'); ?></p>
                </div>

                <div class="sv-locked-container">
                    <div class="sv-content-box <?php echo !sv_is_inet_hosting() ? 'sv-locked-item' : ''; ?>">
                        <div class="sv-box-header">
                            <span class="dashicons dashicons-sos" style="color:var(--sv-primary);"></span>
                            <h3><?php esc_html_e('Liên Hệ Hỗ Trợ 24/7', 'sitevorx'); ?></h3>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; padding: 10px;">
                            
                            <!-- Hotline -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; text-align: center; transition: all 0.3s ease;">
                                <div style="width: 60px; height: 60px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                                    <span class="dashicons dashicons-phone" style="font-size: 30px; width: 30px; height: 30px;"></span>
                                </div>
                                <h4 style="margin: 0 0 10px; font-size: 16px; color: #1e293b;"><?php esc_html_e('Hotline CSKH', 'sitevorx'); ?></h4>
                                <strong style="display: block; font-size: 24px; color: #ef4444; margin-bottom: 15px;"><?php echo esc_html( $support_config['hotline'] ?? '' ); ?></strong>
                                <p style="font-size: 13px; color: #64748b; margin: 0;"><?php esc_html_e('Hỗ trợ giải đáp thắc mắc và xử lý sự cố lập tức. Vui lòng chuẩn bị sẵn Tên miền của bạn.', 'sitevorx'); ?></p>
                            </div>

                            <!-- Ticket -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; text-align: center; transition: all 0.3s ease;">
                                <div style="width: 60px; height: 60px; background: #e0f2fe; color: #0ea5e9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                                    <span class="dashicons dashicons-tickets-alt" style="font-size: 30px; width: 30px; height: 30px;"></span>
                                </div>
                                <h4 style="margin: 0 0 10px; font-size: 16px; color: #1e293b;"><?php esc_html_e('Hệ Thống Ticket', 'sitevorx'); ?></h4>
                                <a href="<?php echo esc_url( $support_config['ticket_url'] ?? '' ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="background:#0ea5e9; border-color:#0ea5e9; margin-bottom: 15px; border-radius:6px; padding:4px 20px; font-weight:600;"><?php esc_html_e('Gửi Yêu Cầu', 'sitevorx'); ?></a>
                                <p style="font-size: 13px; color: #64748b; margin: 0;"><?php esc_html_e('Gửi yêu cầu qua hệ thống Ticket để đội ngũ kỹ thuật có chuyên môn cao nhất xử lý sự cố.', 'sitevorx'); ?></p>
                            </div>

                            <!-- Helpdesk -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; text-align: center; transition: all 0.3s ease;">
                                <div style="width: 60px; height: 60px; background: #dcfce7; color: #22c55e; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                                    <span class="dashicons dashicons-book" style="font-size: 30px; width: 30px; height: 30px;"></span>
                                </div>
                                <h4 style="margin: 0 0 10px; font-size: 16px; color: #1e293b;"><?php esc_html_e('Tài Liệu Cấu Hình', 'sitevorx'); ?></h4>
                                <a href="<?php echo esc_url( $support_config['helpdesk_url'] ?? '' ); ?>" target="_blank" rel="noopener noreferrer" class="button" style="margin-bottom: 15px; border-radius:6px; padding:4px 20px; font-weight:600;"><?php esc_html_e('Xem Thư Viện', 'sitevorx'); ?></a>
                                <p style="font-size: 13px; color: #64748b; margin: 0;"><?php esc_html_e('Kho tàng hướng dẫn sử dụng, thao tác quản trị hosting, server và website trực quan nhất.', 'sitevorx'); ?></p>
                            </div>

                        </div>
                    </div>
                    <?php if ( !sv_is_inet_hosting() ) : ?>
                    <div class="sv-locked-overlay">
                        <span><span class="dashicons dashicons-lock"></span> <?php esc_html_e('Độc Quyền iNET', 'sitevorx'); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
    <?php
}
