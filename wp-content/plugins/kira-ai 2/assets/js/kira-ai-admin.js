/**
 * JavaScript for Kira AI Admin Content Generator & Bulk Queue Engine
 */
jQuery(document).ready(function ($) {
    // Inject the button next to "Add New" button in WP admin
    if (!kira_ai_params.is_media) {
        var $addNewBtn = $('.wrap a.page-title-action');
        if ($addNewBtn.length) {
            var $aiBtn = $('<a href="#" class="page-title-action kira-ai-trigger-btn">' +
                '<span class="dashicons dashicons-admin-customizer"></span> Tạo nội dung AI' +
                '</a>');

            $addNewBtn.first().after($aiBtn);

            $aiBtn.on('click', function (e) {
                e.preventDefault();
                $('#kira-ai-modal-backdrop').addClass('show');
            });
        }
    }

    // Modal Close Trigger Handlers
    $('.kira-ai-modal-close, .kira-ai-modal-backdrop, .kira-ai-btn-secondary').on('click', function (e) {
        if (e.target !== this && !$(this).hasClass('kira-ai-modal-close') && !$(this).hasClass('kira-ai-btn-secondary')) {
            return;
        }
        $('#kira-ai-modal-backdrop').removeClass('show');
        resetModal();
    });

    $('.kira-ai-modal').on('click', function (e) {
        e.stopPropagation();
    });

    function resetModal() {
        $('#kira-ai-form').show();
        $('.kira-ai-loading-container').hide();
        $('.kira-ai-error-msg').hide().text('');
        $('#kira-ai-submit').prop('disabled', false);
        $('#kira-ai-prompt').val('');
        $('#kira-ai-keyword').val('');
        $('#kira-ai-pillar-url').val('');
        $('#kira-ai-pillar-keyword').val('');
        $('#kira-ai-max-internal-links').val('3');
        $('.kira-ai-loading-text').text('AI đang soạn thảo nội dung...');
        $('#kira-step-text').attr('class', 'step-pending');
        $('#kira-step-image').attr('class', 'step-pending');
        $('#kira-step-save').attr('class', 'step-pending');
    }

    // Single Form Submit
    $('#kira-ai-submit').on('click', function (e) {
        e.preventDefault();

        var keyword = $('#kira-ai-keyword').val().trim();
        var prompt = $('#kira-ai-prompt').val().trim();
        var post_status = $('#kira-ai-post-status').val() || 'draft';
        var gen_image = $('#kira-ai-gen-image').is(':checked') ? 1 : 0;
        var pillar_url = $('#kira-ai-pillar-url').val().trim();
        var pillar_keyword = $('#kira-ai-pillar-keyword').val().trim();
        var max_internal_links = parseInt($('#kira-ai-max-internal-links').val(), 10) || 0;

        if (!keyword) {
            showError('Vui lòng nhập từ khóa chính.');
            return;
        }
        if (!prompt) {
            showError('Vui lòng nhập Prompt / Yêu cầu viết bài.');
            return;
        }

        $('#kira-ai-form').hide();
        $('.kira-ai-loading-text').text('Hệ thống đang tiến hành xử lý...');
        $('.kira-ai-loading-subtext').text('Vui lòng không đóng cửa sổ này cho đến khi hoàn tất.');

        $('#kira-step-text').attr('class', 'step-active');
        $('#kira-step-image').attr('class', 'step-pending');
        $('#kira-step-save').attr('class', 'step-pending');

        $('.kira-ai-loading-container').show();
        $('.kira-ai-error-msg').hide();
        $('#kira-ai-submit').prop('disabled', true);

        // Step 1: Generate Text
        $.ajax({
            url: kira_ai_params.ajax_url,
            type: 'POST',
            data: {
                action: 'kira_ai_generate_post_text',
                _ajax_nonce: kira_ai_params.nonce,
                post_type: kira_ai_params.post_type || 'post',
                keyword: keyword,
                prompt: prompt,
                post_status: post_status,
                pillar_url: pillar_url,
                pillar_keyword: pillar_keyword,
                max_internal_links: max_internal_links
            },
            dataType: 'json',
            timeout: 180000,
            success: function (textResponse) {
                if (textResponse.success && textResponse.data && textResponse.data.post_id) {
                    var postId = textResponse.data.post_id;
                    var postTitle = textResponse.data.title;
                    var redirectUrl = textResponse.data.edit_url || (kira_ai_params.ajax_url.replace('admin-ajax.php', 'post.php?post=' + postId + '&action=edit'));

                    $('#kira-step-text').attr('class', 'step-completed');

                    if (gen_image) {
                        $('#kira-step-image').attr('class', 'step-active');

                        $.ajax({
                            url: kira_ai_params.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'kira_ai_generate_post_image',
                                _ajax_nonce: kira_ai_params.nonce,
                                post_id: postId,
                                title: postTitle
                            },
                            dataType: 'json',
                            timeout: 180000,
                            success: function (imgResponse) {
                                $('#kira-step-image').attr('class', 'step-completed');
                                $('#kira-step-save').attr('class', 'step-active');

                                setTimeout(function () {
                                    $('#kira-step-save').attr('class', 'step-completed');
                                    var finalUrl = (imgResponse.success && imgResponse.data && imgResponse.data.redirect_url)
                                        ? imgResponse.data.redirect_url
                                        : redirectUrl;

                                    $('.kira-ai-loading-text').text('Hoàn tất! Đang chuyển hướng...');
                                    window.location.href = finalUrl;
                                }, 1000);
                            },
                            error: function () {
                                $('#kira-step-image').attr('class', 'step-skipped');
                                $('#kira-step-save').attr('class', 'step-active');

                                setTimeout(function () {
                                    $('#kira-step-save').attr('class', 'step-completed');
                                    $('.kira-ai-loading-text').text('Hoàn tất (Không tạo được ảnh)! Đang chuyển hướng...');
                                    window.location.href = redirectUrl;
                                }, 1000);
                            }
                        });
                    } else {
                        $('#kira-step-image').attr('class', 'step-skipped');
                        $('#kira-step-save').attr('class', 'step-active');

                        setTimeout(function () {
                            $('#kira-step-save').attr('class', 'step-completed');
                            $('.kira-ai-loading-text').text('Hoàn tất! Đang chuyển hướng...');
                            window.location.href = redirectUrl;
                        }, 1000);
                    }
                } else {
                    var errorMsg = textResponse.data || 'Đã xảy ra lỗi không xác định khi soạn thảo bài viết.';
                    showError(errorMsg);
                    showFormAfterError();
                }
            },
            error: function () {
                showError('Không thể kết nối tới máy chủ. Vui lòng kiểm tra lại đường truyền.');
                showFormAfterError();
            }
        });
    });

    function showError(msg) {
        $('.kira-ai-error-msg').text(msg).show();
    }

    function showFormAfterError() {
        $('#kira-ai-form').show();
        $('.kira-ai-loading-container').hide();
        $('#kira-ai-submit').prop('disabled', false);
    }

    // ==========================================
    // BULK GENERATOR & SCHEDULE ENGINE (QUEUE)
    // ==========================================
    var bulkQueue = [];
    var currentQueueIndex = 0;
    var isQueueRunning = false;
    var isQueuePaused = false;

    // Toggle Schedule Options
    $('input[name="kira_bulk_status"]').on('change', function () {
        if ($(this).val() === 'future') {
            $('#kira-bulk-schedule-options').slideDown();
        } else {
            $('#kira-bulk-schedule-options').slideUp();
        }
    });

    // Count keywords & import file
    $('#kira-bulk-keywords').on('input', function () {
        updateKeywordCount();
    });

    function updateKeywordCount() {
        var lines = getKeywordList();
        $('#kira-bulk-kw-count').text(lines.length + ' từ khóa được phát hiện');
    }

    function getKeywordList() {
        var raw = $('#kira-bulk-keywords').val();
        return raw.split('\n').map(function (k) {
            return k.trim();
        }).filter(function (k) {
            return k.length > 0;
        });
    }

    $('#kira-bulk-file-import').on('change', function (e) {
        var file = e.target.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function (event) {
            var contents = event.target.result;
            $('#kira-bulk-keywords').val(contents);
            updateKeywordCount();
            showToast('Đã nhập danh sách từ khóa từ file thành công!', 'success');
        };
        reader.readAsText(file);
    });

    // Helper calculate schedule dates
    function calculateScheduleTime(startIndex, baseTimestamp, intervalVal, intervalUnit) {
        var multiplier = 60 * 1000; // minutes
        if (intervalUnit === 'hours') multiplier = 60 * 60 * 1000;
        if (intervalUnit === 'days') multiplier = 24 * 60 * 60 * 1000;

        var itemTimeMs = baseTimestamp + (startIndex * intervalVal * multiplier);
        var d = new Date(itemTimeMs);

        var YYYY = d.getFullYear();
        var MM = String(d.getMonth() + 1).padStart(2, '0');
        var DD = String(d.getDate()).padStart(2, '0');
        var HH = String(d.getHours()).padStart(2, '0');
        var II = String(d.getMinutes()).padStart(2, '0');
        var SS = '00';

        return YYYY + '-' + MM + '-' + DD + ' ' + HH + ':' + II + ':' + SS;
    }

    // Retry failed queue item
    $(document).on('click', '.kira-bulk-retry-btn', function (e) {
        e.preventDefault();
        var index = parseInt($(this).data('index'), 10);
        var item = bulkQueue[index];
        if (!item || !isQueueRunning) return;

        currentQueueIndex = index;

        var $row = $('#kira-bulk-row-' + item.index);
        var $statusCell = $row.find('.status-cell');
        var $actionCell = $row.find('.action-cell');

        $actionCell.html('–');
        processNextQueueItem();
    });

    // Start Bulk Generator
    $('#kira-bulk-start-btn').on('click', function (e) {
        e.preventDefault();

        if (isQueueRunning && isQueuePaused) {
            // Resume
            isQueuePaused = false;
            $('#kira-bulk-pause-btn').show().html('<span class="dashicons dashicons-controls-pause"></span> Tạm dừng tiến trình');
            $('#kira-bulk-start-btn').hide();
            processNextQueueItem();
            return;
        }

        var keywords = getKeywordList();
        if (keywords.length === 0) {
            alert('Vui lòng nhập ít nhất 1 từ khóa để bắt đầu.');
            return;
        }

        var postType = $('#kira-bulk-post-type').val();
        var commonPrompt = $('#kira-bulk-prompt').val().trim();
        var postStatus = $('input[name="kira_bulk_status"]:checked').val();
        var genImage = $('#kira-bulk-gen-image').is(':checked') ? 1 : 0;

        var startTimeStr = $('#kira-bulk-start-time').val();
        var baseTimestamp = startTimeStr ? new Date(startTimeStr).getTime() : Date.now();
        var intervalVal = parseInt($('#kira-bulk-interval-val').val(), 10) || 2;
        var intervalUnit = $('#kira-bulk-interval-unit').val();

        // Build Queue Items
        bulkQueue = [];
        var $tbody = $('#kira-bulk-table tbody');
        $tbody.empty();

        $.each(keywords, function (index, kw) {
            var schedTime = '';
            var schedLabel = '<span style="color:#64748b;">Lưu nháp</span>';

            if (postStatus === 'publish') {
                schedLabel = '<span style="color:#22c55e; font-weight:600;">Đăng ngay</span>';
            } else if (postStatus === 'future') {
                schedTime = calculateScheduleTime(index, baseTimestamp, intervalVal, intervalUnit);
                schedLabel = '<span style="color:#0284c7; font-weight:600; font-size:12px;">' + schedTime.substring(0, 16) + '</span>';
            }

            bulkQueue.push({
                index: index,
                keyword: kw,
                postType: postType,
                prompt: commonPrompt,
                postStatus: postStatus,
                scheduledTime: schedTime,
                genImage: genImage,
                status: 'pending',
                postId: 0,
                postTitle: '',
                editUrl: '',
                viewUrl: '',
                error: ''
            });

            var rowHtml = '<tr id="kira-bulk-row-' + index + '">' +
                '<td>' + (index + 1) + '</td>' +
                '<td><strong>' + escapeHtml(kw) + '</strong></td>' +
                '<td>' + schedLabel + '</td>' +
                '<td class="status-cell"><span class="kira-badge kira-badge-pending">Chờ xử lý</span></td>' +
                '<td class="action-cell">–</td>' +
                '</tr>';
            $tbody.append(rowHtml);
        });

        currentQueueIndex = 0;
        isQueueRunning = true;
        isQueuePaused = false;

        $('#kira-bulk-progress-bar').css('width', '0%');
        $('#kira-bulk-progress-text').text('0 / ' + bulkQueue.length + ' bài');

        $('#kira-bulk-start-btn').hide();
        $('#kira-bulk-pause-btn').show().html('<span class="dashicons dashicons-controls-pause"></span> Tạm dừng tiến trình');
        $('#kira-bulk-keywords, #kira-bulk-prompt, #kira-bulk-post-type, input[name="kira_bulk_status"]').prop('disabled', true);

        processNextQueueItem();
    });

    // Pause Bulk Generator
    $('#kira-bulk-pause-btn').on('click', function (e) {
        e.preventDefault();
        isQueuePaused = true;
        $(this).hide();
        $('#kira-bulk-start-btn').show().html('<span class="dashicons dashicons-controls-play"></span> Tiếp tục tiến trình');
        showToast('Tiến trình đã tạm dừng. Bạn có thể bấm Tiếp tục bất kỳ lúc nào.', 'loading');
    });

    // Process each Queue item sequentially
    function processNextQueueItem() {
        if (!isQueueRunning || isQueuePaused) return;

        if (currentQueueIndex >= bulkQueue.length) {
            // Queue Finished
            isQueueRunning = false;
            $('#kira-bulk-pause-btn').hide();
            $('#kira-bulk-start-btn').show().html('<span class="dashicons dashicons-controls-play"></span> Bắt đầu lượt mới');
            $('#kira-bulk-keywords, #kira-bulk-prompt, #kira-bulk-post-type, input[name="kira_bulk_status"]').prop('disabled', false);
            showToast('Tất cả bài viết trong hàng đợi đã được xử lý hoàn tất!', 'success');
            return;
        }

        var item = bulkQueue[currentQueueIndex];
        var $row = $('#kira-bulk-row-' + item.index);
        var $statusCell = $row.find('.status-cell');
        var $actionCell = $row.find('.action-cell');

        $statusCell.html('<span class="kira-badge kira-badge-active"><span class="dashicons dashicons-update spin"></span> Đang viết nội dung...</span>');

        // Phase 1: Generate Text
        $.ajax({
            url: kira_ai_params.ajax_url,
            type: 'POST',
            data: {
                action: 'kira_ai_generate_post_text',
                _ajax_nonce: kira_ai_params.nonce,
                post_type: item.postType,
                keyword: item.keyword,
                prompt: item.prompt,
                post_status: item.postStatus,
                scheduled_time: item.scheduledTime
            },
            dataType: 'json',
            timeout: 180000,
            success: function (textResp) {
                if (textResp.success && textResp.data && textResp.data.post_id) {
                    item.postId = textResp.data.post_id;
                    item.postTitle = textResp.data.title;
                    item.editUrl = textResp.data.edit_url;
                    item.viewUrl = textResp.data.view_url;

                    if (item.genImage) {
                        $statusCell.html('<span class="kira-badge kira-badge-active"><span class="dashicons dashicons-format-image spin"></span> Đang tạo 3 ảnh WebP & Logo...</span>');

                        // Phase 2: Generate 3 Images & Watermark & Assign Thumbnail
                        $.ajax({
                            url: kira_ai_params.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'kira_ai_generate_post_image',
                                _ajax_nonce: kira_ai_params.nonce,
                                post_id: item.postId,
                                title: item.postTitle
                            },
                            dataType: 'json',
                            timeout: 180000,
                            success: function (imgResp) {
                                if (imgResp.success) {
                                    finalizeQueueItemSuccess(item, $statusCell, $actionCell);
                                } else {
                                    // Tạo text thành công nhưng ảnh lỗi
                                    $statusCell.html('<span class="kira-badge kira-badge-warning">Thiếu ảnh</span>');
                                    $actionCell.html(buildActionLinks(item, false));
                                    currentQueueIndex++;
                                    updateProgressBar();
                                    setTimeout(processNextQueueItem, 1000);
                                }
                            },
                            error: function () {
                                $statusCell.html('<span class="kira-badge kira-badge-warning">Thiếu ảnh</span>');
                                $actionCell.html(buildActionLinks(item, false));
                                currentQueueIndex++;
                                updateProgressBar();
                                setTimeout(processNextQueueItem, 1000);
                            }
                        });
                    } else {
                        finalizeQueueItemSuccess(item, $statusCell, $actionCell);
                    }
                } else {
                    var err = textResp.data || 'Lỗi soạn thảo';
                    finalizeQueueItemError(item, $statusCell, err);
                }
            },
            error: function () {
                finalizeQueueItemError(item, $statusCell, 'Mất kết nối máy chủ');
            }
        });
    }

    function buildActionLinks(item, hasPost) {
        var html = '';
        if (hasPost && item.editUrl) {
            html += '<a href="' + item.editUrl + '" target="_blank" class="button button-small" title="Sửa bài"><span class="dashicons dashicons-edit"></span></a>';
        }
        if (hasPost && item.viewUrl) {
            html += ' <a href="' + item.viewUrl + '" target="_blank" class="button button-small" title="Xem bài"><span class="dashicons dashicons-visibility"></span></a>';
        }
        if (!hasPost) {
            html += '<button type="button" class="button button-small kira-bulk-retry-btn" data-index="' + item.index + '" title="Thử lại"><span class="dashicons dashicons-update"></span></button>';
        }
        return html || '–';
    }

    function finalizeQueueItemSuccess(item, $statusCell, $actionCell) {
        $statusCell.html('<span class="kira-badge kira-badge-success">✓ Hoàn tất</span>');
        $actionCell.html(buildActionLinks(item, true));

        currentQueueIndex++;
        updateProgressBar();
        setTimeout(processNextQueueItem, 1000);
    }

    function finalizeQueueItemError(item, $statusCell, errorMsg) {
        $statusCell.html('<span class="kira-badge kira-badge-error" title="' + escapeHtml(errorMsg) + '">✕ Lỗi API</span>');
        $actionCell.html(buildActionLinks(item, false));
        currentQueueIndex++;
        updateProgressBar();
        setTimeout(processNextQueueItem, 1000);
    }

    function updateProgressBar() {
        var total = bulkQueue.length;
        var percent = Math.round((currentQueueIndex / total) * 100);
        $('#kira-bulk-progress-bar').css('width', percent + '%');
        $('#kira-bulk-progress-text').text(currentQueueIndex + ' / ' + total + ' bài (' + percent + '%)');
    }

    function escapeHtml(str) {
        return $('<div>').text(str).html();
    }

    // --- Row actions for existing posts & terms ---
    $(document).on('click', '.kira-ai-row-action', function (e) {
        e.preventDefault();

        var actionType = $(this).data('action');

        if (actionType.indexOf('term_') === 0) {
            var termId = $(this).data('term-id');
            var termName = $(this).data('term-name');
            var taxonomy = $(this).data('taxonomy');

            $('#kira-ai-action-post-id').val(termId);
            $('#kira-ai-action-type').val(actionType);
            $('#kira-ai-action-form').data('taxonomy', taxonomy);

            var titleText = 'AI Xử lý Phân loại';
            var descText = '';
            var placeholderText = 'Nhập thêm các yêu cầu đặc biệt của bạn...';

            if (actionType === 'term_gen_image') {
                titleText = 'AI Tạo ảnh đại diện';
                descText = 'Hệ thống sẽ dựa vào tên phân loại và mô tả hiện tại để vẽ ảnh đại diện tỉ lệ 16:9 với AI cho phân loại: "<strong>' + termName + '</strong>"';
                placeholderText = 'Ví dụ: ảnh chụp cảnh khu đô thị đông đúc, phong cách chụp flycam từ trên cao... (Tùy chọn)';
            } else if (actionType === 'term_gen_desc') {
                titleText = 'AI Tạo mô tả';
                descText = 'AI sẽ tự động soạn thảo đoạn văn mô tả chuẩn SEO và súc tích nhất cho phân loại: "<strong>' + termName + '</strong>"';
                placeholderText = 'Ví dụ: viết khoảng 100 từ, giọng văn chuyên nghiệp giới thiệu tiềm năng... (Tùy chọn)';
            }

            $('#kira-ai-action-modal-title').text(titleText);
            $('#kira-ai-action-description').html(descText);
            $('#kira-ai-action-custom-prompt').attr('placeholder', placeholderText).val('');
            $('.kira-ai-action-error-msg').hide().text('');

        } else {
            var postId = $(this).data('post-id');
            var postTitle = $(this).data('post-title');

            $('#kira-ai-action-post-id').val(postId);
            $('#kira-ai-action-type').val(actionType);
            $('#kira-ai-action-form').data('taxonomy', '');

            var titleText = 'AI Xử lý bài viết';
            var descText = '';
            var placeholderText = 'Nhập thêm các yêu cầu đặc biệt của bạn...';

            if (actionType === 'gen_image') {
                titleText = 'AI Tạo ảnh đại diện';
                descText = 'Hệ thống sẽ dựa vào tiêu đề và nội dung bài viết hiện tại để vẽ ảnh đại diện tỉ lệ 16:9 với AI cho bài viết: "<strong>' + postTitle + '</strong>"';
                placeholderText = 'Ví dụ: ảnh chụp phong cảnh hoàng hôn, ảnh vẽ minh họa nghệ thuật phẳng... (Tùy chọn)';
            } else if (actionType === 'rewrite_title') {
                titleText = 'AI Viết lại Tiêu đề';
                descText = 'Hệ thống sẽ dựa vào tiêu đề và nội dung cũ để viết lại tiêu đề chuẩn SEO cho bài viết: "<strong>' + postTitle + '</strong>"';
                placeholderText = 'Ví dụ: thêm emoji, viết ngắn gọn dưới 60 chữ, tạo tò mò... (Tùy chọn)';
            } else if (actionType === 'rewrite_content') {
                titleText = 'AI Viết lại Nội dung';
                descText = 'Hệ thống sẽ tối ưu hóa và cấu trúc lại toàn bộ nội dung (HTML) để bài viết mượt mà và chuẩn SEO hơn cho bài viết: "<strong>' + postTitle + '</strong>"';
                placeholderText = 'Ví dụ: bổ sung số liệu thống kê giả định, nhấn mạnh tính năng du lịch nghỉ dưỡng... (Tùy chọn)';
            }

            $('#kira-ai-action-modal-title').text(titleText);
            $('#kira-ai-action-description').html(descText);
            $('#kira-ai-action-custom-prompt').attr('placeholder', placeholderText).val('');
            $('.kira-ai-action-error-msg').hide().text('');
        }

        $('#kira-ai-action-form').show();
        $('.kira-ai-action-loading-container').hide();
        $('#kira-ai-action-submit').prop('disabled', false);

        $('#kira-ai-action-modal-backdrop').addClass('show');
    });

    $('.kira-ai-action-modal-close, .kira-ai-action-btn-secondary').on('click', function (e) {
        $('#kira-ai-action-modal-backdrop').removeClass('show');
    });

    $('#kira-ai-action-modal-backdrop').on('click', function (e) {
        if (e.target === this) {
            $(this).removeClass('show');
        }
    });

    $('#kira-ai-action-submit').on('click', function (e) {
        e.preventDefault();

        var id = $('#kira-ai-action-post-id').val();
        var actionType = $('#kira-ai-action-type').val();
        var customPrompt = $('#kira-ai-action-custom-prompt').val().trim();
        var taxonomy = $('#kira-ai-action-form').data('taxonomy') || '';
        var pillarUrl = $('#kira-ai-action-pillar-url').val().trim();
        var pillarKeyword = $('#kira-ai-action-pillar-keyword').val().trim();
        var actionMaxInternalLinks = parseInt($('#kira-ai-action-max-internal-links').val(), 10) || 0;

        if (!id || !actionType) {
            showActionError('Thiếu dữ liệu hoặc thao tác thực hiện.');
            return;
        }

        $('#kira-ai-action-form').hide();

        var loadingTitle = 'AI đang xử lý yêu cầu...';
        var loadingSub = 'Quá trình này có thể mất từ 15 đến 45 giây. Vui lòng không đóng cửa sổ này.';

        if (actionType === 'gen_image') {
            loadingTitle = 'AI đang vẽ tranh đại diện 16:9...';
            loadingSub = 'Hệ thống đang gọi API và xử lý chuyển đổi hình ảnh. Vui lòng chờ.';
        } else if (actionType === 'rewrite_title') {
            loadingTitle = 'AI đang tối ưu lại tiêu đề...';
        } else if (actionType === 'rewrite_content') {
            loadingTitle = 'AI đang soạn thảo lại nội dung bài viết...';
            loadingSub = 'Tối ưu hóa nội dung chi tiết thường mất nhiều thời gian hơn. Vui lòng kiên nhẫn.';
        } else if (actionType === 'term_gen_image') {
            loadingTitle = 'AI đang vẽ ảnh phân loại 16:9...';
            loadingSub = 'Hệ thống đang sinh ảnh phân loại. Vui lòng chờ.';
        } else if (actionType === 'term_gen_desc') {
            loadingTitle = 'AI đang viết mô tả phân loại...';
            loadingSub = 'Hệ thống đang sinh mô tả chuẩn SEO cho phân loại này.';
        }

        $('.kira-ai-action-loading-text').text(loadingTitle);
        $('.kira-ai-action-loading-subtext').text(loadingSub);
        $('.kira-ai-action-loading-container').show();
        $('.kira-ai-action-error-msg').hide();
        $('#kira-ai-action-submit').prop('disabled', true);

        var ajaxData = {
            _ajax_nonce: kira_ai_params.nonce,
            custom_prompt: customPrompt
        };

        if (actionType.indexOf('term_') === 0) {
            ajaxData.action = 'kira_ai_process_existing_term';
            ajaxData.term_id = id;
            ajaxData.taxonomy = taxonomy;
            ajaxData.action_type = actionType;
        } else {
            ajaxData.action = 'kira_ai_process_existing_post';
            ajaxData.post_id = id;
            ajaxData.action_type = actionType;
            ajaxData.pillar_url = pillarUrl;
            ajaxData.pillar_keyword = pillarKeyword;
            ajaxData.max_internal_links = actionMaxInternalLinks;
        }

        $.ajax({
            url: kira_ai_params.ajax_url,
            type: 'POST',
            data: ajaxData,
            dataType: 'json',
            timeout: 180000,
            success: function (response) {
                if (response.success) {
                    $('.kira-ai-action-loading-text').text('Thao tác thành công!');
                    $('.kira-ai-action-loading-subtext').text('Đang tải lại trang để cập nhật kết quả mới...');

                    setTimeout(function () {
                        window.location.reload();
                    }, 1500);
                } else {
                    var errorMsg = response.data || 'Đã xảy ra lỗi không xác định từ hệ thống.';
                    showActionError(errorMsg);
                    showActionFormAfterError();
                }
            },
            error: function () {
                showActionError('Không thể kết nối tới máy chủ. Vui lòng kiểm tra lại đường truyền.');
                showActionFormAfterError();
            }
        });
    });

    function showActionError(msg) {
        $('.kira-ai-action-error-msg').text(msg).show();
    }

    function showActionFormAfterError() {
        $('#kira-ai-action-form').show();
        $('.kira-ai-action-loading-container').hide();
        $('#kira-ai-action-submit').prop('disabled', false);
    }

    // --- Standalone Media Image Generator ---
    if (kira_ai_params.is_media) {
        var $addNewMediaBtn = $('.wrap a.page-title-action');
        if ($addNewMediaBtn.length) {
            var $mediaAiBtn = $('<a href="#" class="page-title-action kira-ai-media-trigger-btn">' +
                '<span class="dashicons dashicons-admin-customizer"></span> Tạo ảnh AI' +
                '</a>');
            $addNewMediaBtn.first().after($mediaAiBtn);

            $mediaAiBtn.on('click', function (e) {
                e.preventDefault();
                resetMediaModal();
                $('#kira-ai-media-modal-backdrop').addClass('show');
            });
        }
    }

    $('.kira-ai-media-modal-close, .kira-ai-media-btn-secondary').on('click', function (e) {
        $('#kira-ai-media-modal-backdrop').removeClass('show');
        resetMediaModal();
    });

    $('#kira-ai-media-modal-backdrop').on('click', function (e) {
        if (e.target === this) {
            $(this).removeClass('show');
            resetMediaModal();
        }
    });

    function resetMediaModal() {
        $('#kira-ai-media-form').show();
        $('.kira-ai-media-loading-container').hide();
        $('.kira-ai-media-error-msg').hide().text('');
        $('#kira-ai-media-submit').prop('disabled', false);
        $('#kira-ai-media-prompt').val('');
        $('#kira-ai-media-aspect-ratio').val('16:9');
    }

    $('#kira-ai-media-submit').on('click', function (e) {
        e.preventDefault();

        var prompt = $('#kira-ai-media-prompt').val().trim();
        var aspectRatio = $('#kira-ai-media-aspect-ratio').val();

        if (!prompt) {
            showMediaError('Vui lòng nhập yêu cầu vẽ ảnh.');
            return;
        }

        $('#kira-ai-media-form').hide();
        $('.kira-ai-media-loading-container').show();
        $('.kira-ai-media-error-msg').hide();
        $('#kira-ai-media-submit').prop('disabled', true);

        $.ajax({
            url: kira_ai_params.ajax_url,
            type: 'POST',
            data: {
                action: 'kira_ai_generate_standalone_image',
                _ajax_nonce: kira_ai_params.nonce,
                prompt: prompt,
                aspect_ratio: aspectRatio
            },
            dataType: 'json',
            timeout: 180000,
            success: function (response) {
                if (response.success) {
                    $('.kira-ai-media-loading-text').text('Tạo ảnh thành công!');
                    $('.kira-ai-media-loading-subtext').text('Đang tải lại thư viện...');
                    setTimeout(function () {
                        window.location.reload();
                    }, 1500);
                } else {
                    var errorMsg = response.data || 'Đã xảy ra lỗi khi tạo ảnh.';
                    showMediaError(errorMsg);
                    showMediaFormAfterError();
                }
            },
            error: function () {
                showMediaError('Không thể kết nối tới máy chủ. Vui lòng kiểm tra lại đường truyền.');
                showMediaFormAfterError();
            }
        });
    });

    function showMediaError(msg) {
        $('.kira-ai-media-error-msg').text(msg).show();
    }

    function showMediaFormAfterError() {
        $('#kira-ai-media-form').show();
        $('.kira-ai-media-loading-container').hide();
        $('#kira-ai-media-submit').prop('disabled', false);
    }

    // --- Connection Test Handler ---
    $('#kira-ai-test-connection-btn').on('click', function (e) {
        e.preventDefault();
        var apiKey = $('#kira_ai_api_key').val().trim();
        var textModel = $('#kira_ai_text_model').val();

        if (!apiKey) {
            $('#kira-ai-connection-status').css('color', '#ef4444').text('Vui lòng nhập API Key trước khi test.');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Đang kiểm tra...');
        $('#kira-ai-connection-status').css('color', '#64748b').text('Đang kết nối API...');

        $.ajax({
            url: kira_ai_params.ajax_url,
            type: 'POST',
            data: {
                action: 'kira_ai_test_connection',
                _ajax_nonce: kira_ai_params.nonce,
                api_key: apiKey,
                text_model: textModel
            },
            dataType: 'json',
            timeout: 30000,
            success: function (response) {
                $btn.prop('disabled', false).text('Kiểm tra kết nối');
                if (response.success) {
                    $('#kira-ai-connection-status').css('color', '#22c55e').text('Kết nối thành công! API Key hợp lệ.');
                } else {
                    var errorMsg = response.data || 'Kết nối thất bại.';
                    $('#kira-ai-connection-status').css('color', '#ef4444').text('Thất bại: ' + errorMsg);
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('Kiểm tra kết nối');
                $('#kira-ai-connection-status').css('color', '#ef4444').text('Không thể kết nối đến server WordPress.');
            }
        });
    });

    // --- Test Facebook Connection Handler ---
    $('#kira-ai-test-facebook-btn').on('click', function (e) {
        e.preventDefault();
        var pageId = $('#kira_ai_fb_page_id').val().trim();
        var token = $('#kira_ai_fb_access_token').val().trim();

        if (!pageId) {
            $('#kira-ai-facebook-status').css('color', '#ef4444').text('Vui lòng nhập Facebook Page ID.');
            return;
        }
        if (!token) {
            $('#kira-ai-facebook-status').css('color', '#ef4444').text('Vui lòng nhập Page Access Token.');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Đang kiểm tra...');
        $('#kira-ai-facebook-status').css('color', '#64748b').text('Đang kết nối Facebook API...');

        $.ajax({
            url: kira_ai_params.ajax_url,
            type: 'POST',
            data: {
                action: 'kira_ai_test_facebook',
                _ajax_nonce: kira_ai_params.nonce,
                page_id: pageId,
                token: token
            },
            dataType: 'json',
            timeout: 30000,
            success: function (response) {
                $btn.prop('disabled', false).text('Kiểm tra kết nối');
                if (response.success) {
                    $('#kira-ai-facebook-status').css('color', '#22c55e').text(response.data);
                } else {
                    var errorMsg = response.data || 'Kết nối thất bại.';
                    $('#kira-ai-facebook-status').css('color', '#ef4444').text('Thất bại: ' + errorMsg);
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('Kiểm tra kết nối');
                $('#kira-ai-facebook-status').css('color', '#ef4444').text('Không thể kết nối đến server WordPress.');
            }
        });
    });

    // --- Sync Models Handler ---
    $('#kira-ai-sync-models-btn').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $icon = $btn.find('.dashicons');
        
        $btn.prop('disabled', true);
        $icon.addClass('spin');
        showToast('Đang đồng bộ danh sách model từ Kira AI...', 'loading');

        $.ajax({
            url: kira_ai_params.ajax_url,
            type: 'POST',
            data: {
                action: 'kira_ai_sync_models',
                _ajax_nonce: kira_ai_params.nonce
            },
            dataType: 'json',
            timeout: 30000,
            success: function (response) {
                $btn.prop('disabled', false);
                $icon.removeClass('spin');
                
                if (response.success && response.data) {
                    var data = response.data;
                    var textModelSelect = $('#kira_ai_text_model');
                    var imageModelSelect = $('#kira_ai_image_model');
                    
                    var currentTextModel = textModelSelect.val();
                    var currentImageModel = imageModelSelect.val();
                    
                    if (data.text_models && data.text_models.length > 0) {
                        textModelSelect.empty();
                        $.each(data.text_models, function (i, model) {
                            var label = model.name;
                            var option = $('<option></option>').val(model.id).text(label);
                            if (model.id === currentTextModel) {
                                option.prop('selected', true);
                            }
                            textModelSelect.append(option);
                        });
                    }
                    
                    if (data.image_models && data.image_models.length > 0) {
                        imageModelSelect.empty();
                        $.each(data.image_models, function (i, model) {
                            var label = model.name;
                            var option = $('<option></option>').val(model.id).text(label);
                            if (model.id === currentImageModel) {
                                option.prop('selected', true);
                            }
                            imageModelSelect.append(option);
                        });
                    }
                    
                    showToast('Đồng bộ danh sách model thành công!', 'success');
                } else {
                    showToast('Đồng bộ thất bại. Không thể tải danh sách model.', 'error');
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                $icon.removeClass('spin');
                showToast('Không thể kết nối đến máy chủ để đồng bộ.', 'error');
            }
        });
    });

    function showToast(message, type) {
        $('.kira-ai-toast').remove();
        
        var iconHtml = '';
        if (type === 'loading') {
            iconHtml = '<span class="dashicons dashicons-update spin" style="margin-right: 8px; font-size: 16px; width: 16px; height: 16px;"></span>';
        } else if (type === 'success') {
            iconHtml = '<span class="dashicons dashicons-yes" style="margin-right: 8px; color: #22c55e; font-size: 16px; width: 16px; height: 16px;"></span>';
        } else if (type === 'error') {
            iconHtml = '<span class="dashicons dashicons-warning" style="margin-right: 8px; color: #ef4444; font-size: 16px; width: 16px; height: 16px;"></span>';
        }
        
        var $toast = $('<div class="kira-ai-toast kira-ai-toast-' + type + '">' + iconHtml + '<span>' + message + '</span></div>');
        $('body').append($toast);
        
        $toast.each(function() { this.offsetHeight; });
        $toast.addClass('show');
        
        if (type !== 'loading') {
            setTimeout(function() {
                $toast.removeClass('show');
                setTimeout(function() {
                    $toast.remove();
                }, 300);
            }, 3000);
        }
    }
});