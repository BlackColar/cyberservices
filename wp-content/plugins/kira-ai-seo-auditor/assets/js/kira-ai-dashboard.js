/**
 * Kira AI SEO Auditor - Dashboard JS
 */
jQuery(document).ready(function ($) {
    var params = window.kira_sa_dashboard_params || {};
    if (!params || !params.ajax_url) return;

    // Test API connection
    $('#kira-sa-test-api-btn').on('click', function () {
        var $btn = $(this);
        var $status = $('#kira-sa-test-api-status');
        $btn.prop('disabled', true).text('Đang kiểm tra...');
        $status.css('color', '#64748b').text('Đang kết nối API...');

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'kira_sa_dashboard_test_api',
                _ajax_nonce: params.nonce
            },
            dataType: 'json',
            timeout: 30000,
            success: function (resp) {
                $btn.prop('disabled', false).text('🔄 Kiểm tra kết nối');
                if (resp && resp.success) {
                    $status.css('color', '#16a34a').text('✅ ' + (resp.data.message || 'Kết nối thành công!'));
                } else {
                    var msg = (resp && resp.data) ? resp.data : 'Kết nối thất bại.';
                    $status.css('color', '#dc2626').text('❌ ' + msg);
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('🔄 Kiểm tra kết nối');
                $status.css('color', '#dc2626').text('❌ Không thể kết nối đến server.');
            }
        });
    });

    // Test scrape
    $('#kira-sa-test-scrape-btn').on('click', function () {
        var url = $.trim($('#kira-sa-test-url').val());
        if (!url) {
            $('#kira-sa-test-scrape-result').html('<span style="color:#dc2626;">Vui lòng nhập URL.</span>');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Đang scrape...');
        $('#kira-sa-test-scrape-result').html('<span style="color:#64748b;">Đang phân tích...</span>');

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'kira_sa_dashboard_test_scrape',
                _ajax_nonce: params.nonce,
                url: url
            },
            dataType: 'json',
            timeout: 45000,
            success: function (resp) {
                $btn.prop('disabled', false).text('Scrape');
                if (resp && resp.success) {
                    var d = resp.data;
                    var cacheTag = d.from_cache ? ' <em>(dùng cache)</em>' : '';
                    var headingPreview = '';
                    if (d.headings && d.headings.length) {
                        headingPreview = '<ul style="margin:6px 0 0 20px; max-height:120px; overflow:auto;">' +
                            d.headings.slice(0, 10).map(function (h) {
                                return '<li><code>' + h.level.toUpperCase() + '</code> — ' + $('<div>').text(h.text).html() + '</li>';
                            }).join('') +
                            '</ul>';
                    }
                    $('#kira-sa-test-scrape-result').html(
                        '<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 14px;">' +
                        '<strong>Tiêu đề:</strong> ' + $('<div>').text(d.title || '—').html() + cacheTag + '<br/>' +
                        '<strong>Số heading:</strong> ' + (d.headings ? d.headings.length : 0) + '<br/>' +
                        '<strong>Số từ:</strong> ~' + (d.word_count || 0) + '<br/>' +
                        (headingPreview ? '<strong>Preview:</strong>' + headingPreview : '') +
                        '</div>'
                    );
                } else {
                    var msg = (resp && resp.data) ? resp.data : 'Scrape thất bại.';
                    $('#kira-sa-test-scrape-result').html('<span style="color:#dc2626;">❌ ' + $('<div>').text(msg).html() + '</span>');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('Scrape');
                $('#kira-sa-test-scrape-result').html('<span style="color:#dc2626;">❌ Không thể kết nối đến server.</span>');
            }
        });
    });
});