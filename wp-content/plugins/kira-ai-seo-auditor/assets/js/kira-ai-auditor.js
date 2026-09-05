/**
 * Kira AI - Competitor Outline & NLP Keyword Auditor (Live Sidebar)
 * 
 * Simplified dual-column comparison layout: existing headings vs. suggested checklist.
 * No complex DOM insertion — just copy to clipboard.
 */
jQuery(document).ready(function ($) {
    var params = window.kira_ai_auditor_params || {};
    if (!params || !params.ajax_url) {
        return;
    }

    var $modal = $('#kira-auditor-modal');
    var $modalBody = $('#kira-auditor-modal-body');
    var $root = $('#kira-ai-auditor-root');
    var $launcher = $('#kira-ai-auditor-launcher');

    var $panel = $root;
    if (!$panel.length) { return; }
    $root = $panel;

    var postId = parseInt($launcher.data('post-id'), 10) || parseInt(params.post_id, 10) || 0;
    var nonce = params.nonce;
    var hasApiKey = params.has_api_key || false;

    window.onerror = function (msg, source, lineno) {
        var $err = $modalBody.find('.kira-auditor-js-error');
        if (!$err.length && $modalBody.length) {
            $modalBody.prepend('<div class="kira-auditor-js-error">⚠️ Lỗi JavaScript: ' + escapeHtml(String(msg).slice(0, 300)) + ' (dòng ' + (lineno || '?') + ')</div>');
        }
    };

    function resolvePostId() {
        var id = 0;
        if (window.wp && window.wp.data && wp.data.select('core/editor')) {
            try {
                var gId = wp.data.select('core/editor').getCurrentPostId();
                if (gId) { id = parseInt(gId, 10); }
            } catch (e) { /* ignore */ }
        }
        if (!id) {
            var $field = $('#post_ID');
            if ($field.length) { id = parseInt($field.val(), 10) || 0; }
        }
        if (!id) { id = parseInt(params.post_id, 10) || 0; }
        return id;
    }

    function pushContentToEditor(content) {
        if (window.wp && window.wp.data && wp.data.dispatch('core/editor')) {
            try { wp.data.dispatch('core/editor').editPost({ content: content }); return true; } catch (e) { /* fall through */ }
        }
        var $textarea = $('#content');
        if (typeof window.tinymce !== 'undefined') {
            try {
                var ed = window.tinymce.get('content');
                if (ed) { ed.setContent(content); ed.save(); return true; }
            } catch (e) { /* fall through */ }
        }
        if ($textarea.length) { $textarea.val(content).trigger('change'); return true; }
        return false;
    }

    var state = {
        hasData: false, title: '', outline: [], outlineStatus: [], keywords: [], keywordStatus: [],
        missingTopics: [], competitor: {}, score: null, focusKeyword: '', recommendedWordCount: 0,
        loading: false, activeTab: 'headings', existingHeadingNodes: []
    };

    // ================= Utilities =================
    function escapeHtml(str) { if (!str) return ''; return $('<div>').text(String(str)).html(); }
    function debounce(fn, wait) { var t; return function () { var args = arguments, ctx = this; clearTimeout(t); t = setTimeout(function () { fn.apply(ctx, args); }, wait || 800); }; }

    function fillPanels(containerClass, html) {
        var $rootC = $root.find(containerClass);
        if ($rootC.length) $rootC.html(html);
        if ($modalBody.length && $modalBody[0] !== $root[0]) {
            var $modalC = $modalBody.find(containerClass);
            if ($modalC.length) $modalC.html(html);
        }
    }

    function saFind(selector, context) {
        if (context && context.length) { var $ctx = context.find(selector); if ($ctx.length) return $ctx; }
        var $el = $root.find(selector);
        if (!$el.length && $modalBody.length && $modalBody[0] !== $root[0]) { $el = $modalBody.find(selector); }
        return $el;
    }

    function getEditorContent() {
        if (window.wp && window.wp.data && wp.data.select('core/editor')) {
            try { var content = wp.data.select('core/editor').getEditedPostContent(); if (typeof content === 'string') return content; } catch (e) { /* ignore */ }
        }
        if (typeof window.tinymce !== 'undefined') {
            try { var ed = window.tinymce.get('content'); if (ed && !ed.isHidden()) { var tinymceContent = ed.getContent(); if (typeof tinymceContent === 'string') return tinymceContent; } } catch (e) { /* ignore */ }
        }
        var $textarea = $('#content');
        if ($textarea.length) { return $textarea.val() || ''; }
        return '';
    }

    function doAjax(action, data, onSuccess, onError) {
        data = data || {};
        data.action = action;
        data._ajax_nonce = nonce;
        data.post_id = resolvePostId() || postId;
        $.ajax({
            url: params.ajax_url, type: 'POST', data: data, dataType: 'json', timeout: 180000,
            success: function (resp) {
                if (resp && resp.success) { if (onSuccess) onSuccess(resp.data); }
                else { var msg = (resp && resp.data) ? resp.data : 'Đã xảy ra lỗi không xác định.'; if (onError) onError(msg); }
            },
            error: function () { if (onError) onError('Không thể kết nối đến máy chủ. Vui lòng kiểm tra lại.'); }
        });
    }

    // ================= Rendering =================
    function init() {
        if (!$root.length) return;
        if (!hasApiKey) {
            $root.html('<div class="kira-auditor-box"><div class="kira-auditor-error">⚠️ ' + escapeHtml(params.i18n.error_api_key) + ' <a href="' + (window.kira_ai_params ? kira_ai_params.admin_url + 'admin.php?page=kira-ai-settings' : '#') + '" target="_blank">Cấu hình ngay</a></div></div>');
            return;
        }
        renderShell();
        var currentPostId = resolvePostId() || postId;
        if (!currentPostId) { return; }
        doAjax('kira_sa_auditor_get_data', {}, function (data) {
            state.hasData = data.has_data;
            state.title = data.title || '';
            state.outline = data.outline || [];
            state.outlineStatus = data.outline_status || [];
            state.keywords = data.keywords || [];
            state.keywordStatus = data.keyword_status || [];
            state.missingTopics = data.missing_topics || [];
            state.competitor = data.competitor || {};
            state.focusKeyword = data.focus_keyword || '';
            state.recommendedWordCount = data.recommended_word_count || 0;
            state.existingHeadingNodes = data.existing_heading_nodes || [];

            var editorMode = detectEditorMode();
            var $mode = $root.find('.kira-auditor-mode');
            if ($mode.length) { $mode.html(editorMode); }
            renderAll();
            startLiveMonitor();
        }, function (msg) {
            $root.find('.kira-auditor-error').remove();
            $root.find('.kira-auditor-setup').after('<div class="kira-auditor-error">' + escapeHtml(msg) + '</div>');
            renderShell();
        });
    }

    function renderShell() {
        var html = '';
        html += '<div class="kira-auditor-guide"><div class="kira-auditor-guide-title">📖 Cách dùng</div><ol class="kira-auditor-guide-list"><li>Dán <strong>URL đối thủ</strong> → bấm <strong>Phân tích</strong></li><li>Bấm <strong>✨ Tạo dàn ý chuẩn SEO</strong></li><li><strong>Viết bài tại khung soạn thảo bên phải</strong></li><li>Quay lại đây: Xem cột trái (bài viết) vs cột phải (dàn ý mẫu)</li><li>Bấm <strong>📋 Copy</strong> để chép heading + nội dung mẫu vào clipboard → tự paste vào bài</li></ol></div>';
        html += '<div class="kira-auditor-setup"><div class="kira-auditor-setup-title">🔍 Phân tích đối thủ & Tạo dàn ý</div>';
        html += '<div class="kira-auditor-mode"></div>';
        html += '<label class="kira-auditor-label">URL bài viết đối thủ <em style="color:#94a3b8;font-weight:400;">(mỗi dòng 1 URL, tối đa 5)</em></label>';
        html += '<div class="kira-auditor-url-row"><textarea class="kira-auditor-url" rows="2" placeholder="https://doithu1.com/bai-viet...&#10;https://doithu2.com/bai-viet..."></textarea><button type="button" class="kira-auditor-btn kira-auditor-btn-ghost kira-auditor-scrape-btn">Phân tích</button></div>';
        html += '<label class="kira-auditor-label" style="margin-top:8px;">Từ khóa mục tiêu (Focus Keyword)</label>';
        html += '<input type="text" class="kira-auditor-focus-kw" placeholder="Ví dụ: dịch vụ SEO tổng thể"/>';
        html += '<label class="kira-auditor-label" style="margin-top:8px;">Yêu cầu bổ sung (Prompt) <em style="color:#94a3b8;font-weight:400;">tùy chọn</em></label>';
        html += '<textarea class="kira-auditor-extra-prompt" rows="2" placeholder="VD: nhấn mạnh giá rẻ, thêm bảng so sánh..."></textarea>';
        html += '<button type="button" class="kira-auditor-btn kira-auditor-btn-primary kira-auditor-generate-btn" style="width:100%; margin-top:10px;">✨ Tạo dàn ý chuẩn SEO từ đối thủ</button>';
        html += '<button type="button" class="kira-auditor-btn kira-auditor-btn-ghost kira-auditor-auto-fix-btn" style="width:100%; margin-top:6px; border-color:#22c55e; color:#15803d;">🚀 AI Tự động bổ sung bài viết</button>';
        html += '<div class="kira-auditor-scrape-preview" style="display:none;"><div class="kira-auditor-preview-title"></div><div class="kira-auditor-preview-meta"></div><div class="kira-auditor-preview-headings"></div><div class="kira-auditor-preview-keywords"></div></div>';
        html += '<div class="kira-auditor-status"></div></div>';
        html += '<div class="kira-auditor-tabs"><button type="button" class="kira-auditor-tab kira-auditor-tab-active" data-tab="headings">📋 So sánh dàn ý</button><button type="button" class="kira-auditor-tab" data-tab="keywords">🎯 ' + escapeHtml(params.i18n.tab_keywords) + '</button><button type="button" class="kira-auditor-tab" data-tab="score">🏆 SEO Score</button></div>';
        html += '<div class="kira-auditor-tab-panel kira-auditor-tab-panel-active" data-panel="headings"><div class="kira-auditor-compare-wrap"><div class="kira-auditor-col-left"><div class="kira-auditor-col-title">📄 Bài viết hiện tại</div><div class="kira-auditor-existing-list"></div></div><div class="kira-auditor-col-mid"><div class="kira-auditor-col-title">🔄 Bố cục cần thay đổi</div><div class="kira-auditor-changed-list"></div></div><div class="kira-auditor-col-right"><div class="kira-auditor-col-title">✅ Dàn ý đề xuất (Checklist)</div><div class="kira-auditor-headings-list"></div></div></div></div>';
        html += '<div class="kira-auditor-tab-panel" data-panel="keywords"><div class="kira-auditor-keywords-list"></div></div>';
        html += '<div class="kira-auditor-tab-panel" data-panel="score"><div class="kira-auditor-score-list"></div></div>';
        $root.html(html);
        if ($modalBody.length && $modalBody[0] !== $root[0]) { $modalBody.html(html); }
    }

    // ================= Open/Close =================
    function openPanel() {
        var $details = $launcher.find('details.kira-auditor-details');
        if ($details.length) { $details.attr('open', 'open'); } else { $root.show(); }
        if ($modal.length) { $modal.removeClass('kira-auditor-modal-hidden'); }
    }
    function closePanel() { if ($modal.length) { $modal.addClass('kira-auditor-modal-hidden'); } }

    function bindLaunchers() {
        $(document).on('click', '#kira-auditor-fab', openPanel);
        $(document).on('click', '.kira-auditor-modal-close', closePanel);
        $(document).on('click', '.kira-auditor-modal-overlay', closePanel);
        $(document).on('click', '.kira-auditor-auto-fix-btn-static, .kira-auditor-auto-fix-btn', handleAutoFix);
        $(document).on('click', '.kira-auditor-tab', function () {
            var tab = $(this).data('tab');
            state.activeTab = tab;
            var $wrap = $(this).closest('.kira-auditor-tabs').parent();
            $wrap.find('.kira-auditor-tab').removeClass('kira-auditor-tab-active');
            $(this).addClass('kira-auditor-tab-active');
            $wrap.find('.kira-auditor-tab-panel').removeClass('kira-auditor-tab-panel-active');
            $wrap.find('.kira-auditor-tab-panel[data-panel="' + tab + '"]').addClass('kira-auditor-tab-panel-active');
        });
        $(document).on('click', '.kira-auditor-kw-insert-btn', function () { insertKeywordSentence($(this).data('keyword') || '', $(this)); });
        $(document).on('click', '.kira-auditor-scrape-btn', handleScrape);
        $(document).on('click', '.kira-auditor-generate-btn', handleGenerate);
        // Copy to clipboard (simple, no DOM insert)
        $(document).on('click', '.kira-auditor-copy-btn', function () {
            var text = $(this).data('clipboard') || '';
            copyToClipboard(text);
        });
        // Generate section content (AI writes paragraph)
        $(document).on('click', '.kira-auditor-write-btn', function () {
            generateSectionIntro($(this).data('heading'), $(this).data('keywords') || [], $(this));
        });
        $(document).on('click', '.kira-auditor-kw-name', function () { var text = $(this).text(); copyToClipboard(text); });
    }

    function renderAll() {
        renderCompareTab();
        renderKeywordsTab();
        renderScoreTab();
        var $info = $root.find('.kira-auditor-found-info');
        if (state.hasData && state.title) {
            if ($info.length) { $info.html('<strong>Dàn ý đã được tạo:</strong> ' + escapeHtml(state.title)); }
            else { $root.find('.kira-auditor-setup').prepend('<div class="kira-auditor-found-info"><strong>Dàn ý đã được tạo:</strong> ' + escapeHtml(state.title) + '</div>'); }
        } else if ($info.length) { $info.remove(); }
    }

    // ================= Compare Tab: 3-column layout =================
    function renderCompareTab() {
        renderExistingHeadings();   // Col 1: bài viết hiện tại
        renderChangedLayout();      // Col 2: bố cục cần thay đổi (diff)
        renderHeadingsTab();        // Col 3: checklist + actions/copy
    }

    // Middle column: diff view — Giữ nguyên / Chỉnh sửa / Thêm mới
    function renderChangedLayout() {
        var $container = $root.find('.kira-auditor-changed-list');
        if (!$container.length) return;

        var items = state.outlineStatus.length ? state.outlineStatus : state.outline;
        if (!state.hasData || !items.length) {
            $container.html('<div class="kira-auditor-empty">Tạo dàn ý để xem bố cục cần thay đổi.</div>');
            return;
        }

        var html = '';
        items.forEach(function (item) {
            var level = (item.level || 'h2').toUpperCase();
            var text = item.text || '';
            var status = item.status || 'missing';

            var badgeHtml = '';
            var changeCls = '';
            if (status === 'met') { badgeHtml = '<span class="kira-auditor-badge kira-auditor-badge-met">✓ Giữ nguyên</span>'; changeCls = 'compare-changed-met'; }
            else if (status === 'partial') { badgeHtml = '<span class="kira-auditor-badge kira-auditor-badge-partial">◐ Chỉnh sửa</span>'; changeCls = 'compare-changed-partial'; }
            else { badgeHtml = '<span class="kira-auditor-badge kira-auditor-badge-missing">➕ Thêm mới</span>'; changeCls = 'compare-changed-missing'; }

            var posHtml = '';
            if (item.recommendation && item.recommendation.label) {
                posHtml = '<div class="kira-auditor-rec">📍 ' + item.recommendation.label + '</div>';
            }

            html += '<div class="kira-auditor-compare-item ' + changeCls + '">' +
                '<span class="kira-auditor-level-tag">' + escapeHtml(level) + '</span>' +
                '<span class="kira-auditor-heading-text">' + escapeHtml(text) + '</span>' +
                badgeHtml +
                '</div>' +
                posHtml;
        });

        $container.html(html);
        if ($modalBody.length && $modalBody[0] !== $root[0]) {
            $modalBody.find('.kira-auditor-changed-list').html(html);
        }
    }

    function renderExistingHeadings() {
        var $container = $root.find('.kira-auditor-existing-list');
        if (!$container.length) return;
        var nodes = state.existingHeadingNodes || [];
        if (!nodes.length) {
            $container.html('<div class="kira-auditor-empty">Bài viết chưa có heading nào.</div>');
            return;
        }
        var html = '';
        nodes.forEach(function (node) {
            var lvl = (node.level || 'h2').toUpperCase();
            html += '<div class="kira-auditor-compare-item">' +
                '<span class="kira-auditor-level-tag">' + lvl + '</span>' +
                '<span class="kira-auditor-heading-text">' + escapeHtml(node.text || '') + '</span>' +
                '</div>';
        });
        $container.html(html);
        // Sync right panel
        if ($modalBody.length && $modalBody[0] !== $root[0]) {
            $modalBody.find('.kira-auditor-existing-list').html(html);
        }
    }

    function renderHeadingsTab() {
        var $container = $root.find('.kira-auditor-headings-list');
        if (!$container.length) return;

        var items = state.outlineStatus.length ? state.outlineStatus : state.outline;
        if (!state.hasData || !items.length) {
            $container.html('<div class="kira-auditor-empty">Chưa có dàn ý. Hãy tạo dàn ý từ URL đối thủ.</div>');
            fillPanels('.kira-auditor-headings-list', $container.html());
            return;
        }

        var html = '';
        items.forEach(function (item, idx) {
            var level = item.level || 'h2';
            var text = item.text || '';
            var status = item.status || 'missing';
            var notes = item.notes || '';
            var keywords = item.keywords || [];

            var badgeHtml = '';
            if (status === 'met') { badgeHtml = '<span class="kira-auditor-badge kira-auditor-badge-met">✓ Đã có</span>'; }
            else if (status === 'partial') { badgeHtml = '<span class="kira-auditor-badge kira-auditor-badge-partial">◐ Một phần</span>'; }
            else { badgeHtml = '<span class="kira-auditor-badge kira-auditor-badge-missing">✗ Thiếu</span>'; }

            var kwHtml = '';
            if (keywords.length) {
                kwHtml = '<div class="kira-auditor-item-keywords">' + keywords.map(function (k) { return '<span class="kira-auditor-mini-tag">' + escapeHtml(k) + '</span>'; }).join('') + '</div>';
            }
            var notesHtml = notes ? '<div class="kira-auditor-item-notes">💡 ' + escapeHtml(notes) + '</div>' : '';

            // Build clipboard content: heading + placeholder content
            var levelNum = parseInt(level.replace('h', ''), 10) || 2;
            var headingTag = '<h' + levelNum + '>' + text + '</h' + levelNum + '>';
            var placeholderPara = '<p>[' + text + ' — nội dung mục này sẽ được viết theo chuyên đề.]</p>';
            var clipboardContent = headingTag + '\n' + placeholderPara;

            // Action buttons
            var actionsHtml = '';
            if (status !== 'met') {
                actionsHtml += '<button type="button" class="kira-auditor-write-btn" data-heading="' + escapeHtml(text) + '" data-keywords="' + escapeHtml(JSON.stringify(keywords || [])) + '" data-index="' + idx + '" title="AI viết đoạn mở đầu cho mục này">✍️ Tạo nội dung</button>';
            }
            actionsHtml += '<button type="button" class="kira-auditor-copy-btn" data-clipboard="' + escapeHtml(clipboardContent) + '" title="Copy heading + nội dung mẫu vào clipboard">📋 Copy</button>';

            // Recommendation label briefly
            var recHtml = '';
            if (item.recommendation && item.recommendation.label) {
                recHtml = '<div class="kira-auditor-rec">📍 ' + item.recommendation.label + '</div>';
            }

            html += '<div class="kira-auditor-heading-item kira-auditor-status-' + status + '">' +
                '<div class="kira-auditor-heading-row">' +
                '<span class="kira-auditor-level-tag">' + level.toUpperCase() + '</span>' +
                '<span class="kira-auditor-heading-text">' + escapeHtml(text) + '</span>' +
                badgeHtml +
                '</div>' +
                recHtml +
                kwHtml +
                notesHtml +
                '<div class="kira-auditor-item-actions">' + actionsHtml + '</div>' +
                '</div>';
        });

        // Missing topics
        if (state.missingTopics && state.missingTopics.length) {
            html += '<div class="kira-auditor-section-title" style="margin-top:16px;">🧩 Chủ đề đối thủ đang thiếu (Content Gap)</div>';
            state.missingTopics.forEach(function (topic) {
                html += '<div class="kira-auditor-gap-topic">➤ ' + escapeHtml(topic) + '</div>';
            });
        }

        $container.html(html);
        fillPanels('.kira-auditor-headings-list', html);
    }

    // ================= Keywords Tab =================
    function renderKeywordsTab() {
        var $container = $root.find('.kira-auditor-keywords-list');
        if (!$container.length) return;
        var items = state.keywordStatus.length ? state.keywordStatus : state.keywords;
        if (!state.hasData || !items.length) {
            var emptyHtml = '<div class="kira-auditor-empty">Chưa có danh sách từ khóa mục tiêu.<br/>Tạo dàn ý từ URL đối thủ để nhận danh sách từ khóa + tần suất đề xuất.</div>';
            if (state.competitorKeywords && state.competitorKeywords.length) { emptyHtml += renderCompetitorKeywords(); }
            $container.html(emptyHtml);
            fillPanels('.kira-auditor-keywords-list', emptyHtml);
            return;
        }
        var metCount = 0, partialCount = 0, missingCount = 0, untrackedCount = 0, itemsHtml = '';
        items.forEach(function (kw) {
            var keyword = kw.keyword || '';
            var count = typeof kw.count === 'number' ? kw.count : 0;
            var recommended = parseInt(kw.recommended_freq, 10) || 1;
            if (recommended < 1) recommended = 1;
            var status = kw.status || 'missing';
            var places = kw.suggested_places || [];
            var isTracked = kw.tracked === true;
            var badgeClass = 'kira-auditor-badge-missing', badgeText = 'Chưa dùng', countText = count + '/' + recommended, progressPct = 0, progressHtml = '';
            if (isTracked) {
                if (status === 'met') { badgeClass = 'kira-auditor-badge-met'; badgeText = '✓ Đủ'; metCount++; }
                else if (status === 'partial') { badgeClass = 'kira-auditor-badge-partial'; badgeText = '◐ Đang viết'; partialCount++; }
                else { missingCount++; }
                progressPct = Math.min(100, Math.round((count / recommended) * 100));
                progressHtml = '<div class="kira-auditor-progress"><div style="width:' + progressPct + '%"></div></div>';
            } else {
                badgeClass = 'kira-auditor-badge-partial'; badgeText = '⏳ Chờ viết bài'; countText = '— / ' + recommended; untrackedCount++;
            }
            var placesHtml = '';
            if (places && places.length) { placesHtml = '<div class="kira-auditor-kw-places">' + places.map(function (p) { return '<span>' + escapeHtml(p) + '</span>'; }).join('') + '</div>'; }
            itemsHtml += '<div class="kira-auditor-kw-item"><div class="kira-auditor-kw-row"><span class="kira-auditor-kw-name" title="Click để copy từ khóa">' + escapeHtml(keyword) + '</span><span class="kira-auditor-kw-count">' + countText + '</span><span class="kira-auditor-badge ' + badgeClass + '">' + badgeText + '</span><button type="button" class="kira-auditor-kw-insert-btn" data-keyword="' + escapeHtml(keyword) + '" title="AI viết câu có chứa từ khóa này">✚ Chèn từ khóa</button></div>' + progressHtml + placesHtml + '</div>';
        });
        var html = '<div class="kira-auditor-section-title">Từ khóa mục tiêu & Tần suất đề xuất</div>';
        var summaryParts = [];
        if (metCount + partialCount + missingCount > 0) { summaryParts.push('✓ Đủ <strong>' + metCount + '</strong>'); summaryParts.push('◐ Đang viết <strong>' + partialCount + '</strong>'); summaryParts.push('✗ Thiếu <strong>' + missingCount + '</strong>'); }
        if (untrackedCount > 0) { summaryParts.push('⏳ Chờ viết bài <strong>' + untrackedCount + '</strong>'); }
        if (summaryParts.length) { html += '<div class="kira-auditor-kw-summary">' + summaryParts.join(' · ') + '</div>'; }
        html += itemsHtml;
        if (state.competitorKeywords && state.competitorKeywords.length) { html += renderCompetitorKeywords(); }
        $container.html(html);
        fillPanels('.kira-auditor-keywords-list', html);
        $container.find('.kira-auditor-kw-name').on('click', function () { var text = $(this).text(); copyToClipboard(text); });
    }

    function renderCompetitorKeywords() {
        var kws = state.competitorKeywords || [];
        if (!kws.length) return '';
        var html = '<div class="kira-auditor-section-title" style="margin-top:14px;">🔍 Từ khóa đối thủ đang dùng (gợi ý)</div>';
        html += '<div class="kira-auditor-competitor-kws">' + kws.slice(0, 12).map(function (k) { return '<span class="kira-auditor-mini-tag">' + escapeHtml(k.keyword) + '</span>'; }).join('') + '</div>';
        html += '<div class="kira-auditor-preview-empty">Đây là gợi ý từ nội dung đối thủ — dàn ý AI sẽ chọn lọc các từ khóa thực sự phù hợp với bài viết của bạn.</div>';
        return html;
    }

    // ================= Score Tab =================
    function renderScoreTab() {
        var $container = $root.find('.kira-auditor-score-list');
        if (!$container.length) return;
        if (!state.hasData) { $container.html('<div class="kira-auditor-empty">Tạo dàn ý từ URL đối thủ để xem <strong>điểm SEO tổng thể</strong> cho bài viết của bạn.</div>'); return; }
        var score = state.score;
        if (!score) { $container.html('<div class="kira-auditor-empty">Đang tính điểm... vui lòng chờ một chút.</div>'); return; }
        var scoreVal = score.score || 0;
        var scoreColor = '#ef4444', scoreLabel = 'Cần cải thiện';
        if (scoreVal >= 80) { scoreColor = '#22c55e'; scoreLabel = 'Rất tốt'; }
        else if (scoreVal >= 60) { scoreColor = '#f59e0b'; scoreLabel = 'Khá'; }
        else if (scoreVal >= 40) { scoreColor = '#f97316'; scoreLabel = 'Trung bình'; }
        var r = 20, c = 2 * Math.PI * r, offset = c - ((scoreVal / 100) * c);
        var html = '<div class="kira-auditor-score-hero"><svg viewBox="0 0 52 52" class="kira-auditor-score-ring"><circle class="bg" cx="26" cy="26" r="20" fill="none" stroke="#e2e8f0" stroke-width="5"/><circle class="fg" cx="26" cy="26" r="20" fill="none" stroke="' + scoreColor + '" stroke-width="5" stroke-dasharray="' + c + '" stroke-dashoffset="' + offset + '" stroke-linecap="round"/></svg><div class="kira-auditor-score-number" style="color:' + scoreColor + ';">' + scoreVal + '</div><div class="kira-auditor-score-label" style="color:' + scoreColor + ';">' + scoreLabel + '</div></div>';
        if (score.needs_more_words && score.needs_more_words > 0) { html += '<div class="kira-auditor-score-cta">📝 Cần thêm <strong>~' + score.needs_more_words + ' từ</strong> để đạt độ dài khuyến nghị.</div>'; }
        else if (score.word_count && score.word_count > 0) { html += '<div class="kira-auditor-score-cta kira-auditor-score-cta-ok">✅ Đạt độ dài khuyến nghị: ' + score.word_count + ' từ.</div>'; }
        html += '<div class="kira-auditor-score-checklist">';
        if (score.items && score.items.length) {
            score.items.forEach(function (item) {
                var ok = item.status === 'met', icon = ok ? '✅' : '⚠️', cls = ok ? 'kira-auditor-score-item-ok' : 'kira-auditor-score-item-warn';
                html += '<div class="kira-auditor-score-item ' + cls + '"><span class="kira-auditor-score-item-icon">' + icon + '</span><div class="kira-auditor-score-item-body"><div class="kira-auditor-score-item-label">' + escapeHtml(item.label) + '</div><div class="kira-auditor-score-item-detail">' + escapeHtml(item.detail || '') + '</div></div></div>';
            });
        }
        html += '</div>';
        $container.html(html);
        fillPanels('.kira-auditor-score-list', html);
    }

    // ================= Generate Section Intro (AI Writer) =================
    function generateSectionIntro(heading, keywords, $btn) {
        var focusKw = saFind('.kira-auditor-focus-kw', $btn.closest('.kira-auditor-setup')).val() || state.focusKeyword || '';
        if (!focusKw) { showToast('Vui lòng nhập từ khóa mục tiêu trước.', 'error'); return; }
        $btn.prop('disabled', true).html('<span class="kira-auditor-spin"></span> Đang viết...');
        doAjax('kira_sa_auditor_generate_section', { focus_keyword: focusKw, heading: heading, keywords: keywords || [] }, function (data) {
            $btn.prop('disabled', false).text('✍️ Tạo nội dung');
            if (data && data.html) {
                // Build full clipboard content: heading + content
                var levelNum = 2;
                var headingTag = '<h' + levelNum + '>' + heading + '</h' + levelNum + '>';
                var clipboardContent = headingTag + '\n' + data.html;
                copyToClipboard(clipboardContent);
                showToast('✅ Đã copy heading + nội dung vào clipboard. Dán (Ctrl+V) vào bài viết.', 'success');
            } else { showToast('AI trả về nội dung trống. Vui lòng thử lại.', 'error'); }
        }, function (msg) { $btn.prop('disabled', false).text('✍️ Tạo nội dung'); showToast(msg, 'error'); });
    }

    // ================= Insert Keyword Sentence (AI) =================
    function insertKeywordSentence(keyword, $btn) {
        if (!keyword) { showToast('Thiếu từ khóa cần chèn.', 'error'); return; }
        var focusKw = state.focusKeyword || saFind('.kira-auditor-focus-kw').val() || '';
        $btn.prop('disabled', true).html('<span class="kira-auditor-spin"></span> Đang viết...');
        doAjax('kira_sa_auditor_generate_keyword_sentence', { keyword: keyword, focus_keyword: focusKw }, function (data) {
            $btn.prop('disabled', false).html('✚ Chèn từ khóa');
            if (data && data.html) { copyToClipboard(data.html); showToast('✅ Đã copy câu chứa từ khóa vào clipboard. Dán (Ctrl+V) vào bài viết.', 'success'); }
            else { showToast('AI trả về nội dung trống. Vui lòng thử lại.', 'error'); }
        }, function (msg) { $btn.prop('disabled', false).html('✚ Chèn từ khóa'); showToast(msg, 'error'); });
    }

    // ================= Scrape Handler =================
    function handleScrape() {
        var $setup = $(this).closest('.kira-auditor-setup');
        var raw = saFind('.kira-auditor-url', $setup).val() || '';
        var urls = raw.split('\n').map(function (u) { return $.trim(u); }).filter(function (u) { return u.length > 0; });
        if (!urls.length) { showStatus(params.i18n.no_url, 'error'); return; }
        if (urls.length > 5) { urls = urls.slice(0, 5); showStatus('Chỉ phân tích tối đa 5 URL đối thủ. Đã lấy 5 URL đầu tiên.', 'info'); }
        var $btn = $(this);
        if (!$btn.is('.kira-auditor-scrape-btn')) $btn = saFind('.kira-auditor-scrape-btn', $setup);
        $btn.prop('disabled', true).text('Đang phân tích ' + urls.length + ' URL...');
        showStatus(params.i18n.scraping, 'loading');
        doAjax('kira_sa_auditor_scrape_urls', { urls: urls }, function (data) {
            $btn.prop('disabled', false).text('Phân tích');
            state.competitor = { title: data.title, meta_description: data.meta_description, headings: data.headings, sources_count: data.sources_count || 1, word_count: data.word_count || 0 };
            state.competitorKeywords = data.keywords || [];
            var $preview = saFind('.kira-auditor-scrape-preview');
            $preview.show();
            var $previewM = $modalBody.find('.kira-auditor-scrape-preview');
            if ($previewM.length && $modalBody[0] !== $root[0]) $previewM.show();
            var srcCount = data.sources_count || 1;
            var headingHtml = '<div class="kira-auditor-preview-label">Cấu trúc Heading đã merge từ ' + srcCount + ' nguồn (' + (data.headings || []).length + ' heading)</div>';
            if (data.headings && data.headings.length) {
                headingHtml += data.headings.slice(0, 18).map(function (h) {
                    return '<div class="kira-auditor-preview-h"><span class="kira-auditor-level-tag">' + escapeHtml(h.level.toUpperCase()) + '</span> ' + escapeHtml(h.text) + '</div>';
                }).join('');
            } else { headingHtml += '<div class="kira-auditor-preview-empty">Không tìm thấy heading</div>'; }
            var kwHtml = '<div class="kira-auditor-preview-label" style="margin-top:10px;">Từ khóa & thực thể quan trọng (Top ' + (data.keywords || []).length + ')</div>';
            if (data.keywords && data.keywords.length) {
                kwHtml += '<div class="kira-auditor-preview-kws">' + data.keywords.slice(0, 12).map(function (k) { return '<span class="kira-auditor-mini-tag">' + escapeHtml(k.keyword) + '</span>'; }).join('') + '</div>';
            } else { kwHtml += '<div class="kira-auditor-preview-empty">Sử dụng heading merge + từ khóa mục tiêu để tạo dàn ý</div>'; }
            $preview.find('.kira-auditor-preview-title').html('<strong>Phân tích ' + srcCount + ' đối thủ:</strong> ' + escapeHtml(data.title || 'Không có tiêu đề'));
            $preview.find('.kira-auditor-preview-meta').html('<span class="kira-auditor-mini-tag">📊 Độ dài trung bình ~' + (data.word_count || 1800) + ' từ</span>' + (data.meta_description ? ' ' + escapeHtml(data.meta_description) : ''));
            $preview.find('.kira-auditor-preview-headings').html(headingHtml);
            $preview.find('.kira-auditor-preview-keywords').html(kwHtml);
            showStatus('Phân tích ' + srcCount + ' đối thủ thành công! Nhập từ khóa mục tiêu rồi bấm <strong>✨ Tạo dàn ý chuẩn SEO</strong>.', 'success');
        }, function (msg) { $btn.prop('disabled', false).text('Phân tích'); showStatus(msg, 'error'); });
    }

    // ================= Generate Outline Handler =================
    function handleGenerate() {
        var $setup = $(this).closest('.kira-auditor-setup');
        var focusKw = $.trim(saFind('.kira-auditor-focus-kw', $setup).val());
        if (!focusKw) { showStatus(params.i18n.no_kw, 'error'); return; }
        if (!state.competitor || (!state.competitor.title && (!state.competitor.headings || !state.competitor.headings.length))) { showStatus('Hãy phân tích URL đối thủ trước khi tạo dàn ý.', 'error'); return; }
        var extraPrompt = $.trim(saFind('.kira-auditor-extra-prompt', $setup).val());
        var $btn = $(this);
        if (!$btn.is('.kira-auditor-generate-btn')) $btn = saFind('.kira-auditor-generate-btn', $setup);
        $btn.prop('disabled', true).html('<span class="kira-auditor-spin"></span> AI đang tạo dàn ý...');
        showStatus(params.i18n.generating, 'loading');
        doAjax('kira_sa_auditor_generate_outline', { focus_keyword: focusKw, extra_prompt: extraPrompt, competitor: state.competitor, competitor_keywords: state.competitorKeywords || [] }, function (data) {
            $btn.prop('disabled', false).html('✨ Tạo dàn ý chuẩn SEO từ đối thủ');
            state.hasData = true; state.title = data.title || ''; state.outline = data.outline || []; state.outlineStatus = data.outline || [];
            state.keywords = data.target_keywords || [];
            if (!state.keywordStatus || !state.keywordStatus.length) { state.keywordStatus = data.target_keywords || []; }
            state.missingTopics = data.missing_topics || []; state.focusKeyword = focusKw;
            if (state.competitor && state.competitor.word_count) { state.recommendedWordCount = state.competitor.word_count; }
            renderAll(); refreshStatus();
            showStatus('✅ Dàn ý đã sẵn sàng! Xem cột trái (bài viết) vs cột phải (dàn ý mẫu). Bấm <strong>📋 Copy</strong> để chép nội dung vào clipboard.', 'success');
        }, function (msg) { $btn.prop('disabled', false).html('✨ Tạo dàn ý chuẩn SEO từ đối thủ'); showStatus(msg, 'error'); });
    }

    // ================= Auto-Fix Handler =================
    function handleAutoFix() {
        var $btn = $(this);
        if (!$btn.is('.kira-auditor-auto-fix-btn')) { $btn = saFind('.kira-auditor-auto-fix-btn'); }
        $btn.prop('disabled', true).html('<span class="kira-auditor-spin"></span> AI đang bổ sung...');
        var content = getEditorContent();
        doAjax('kira_sa_auditor_auto_fix', { content: content }, function (data) {
            $btn.prop('disabled', false).html('🚀 AI Tự động bổ sung bài viết');
            var pushed = false;
            if (data && data.html_append) {
                var newContent = content + '\n\n' + data.html_append;
                pushed = pushContentToEditor(newContent);
            }
            if (pushed) { showToast('✅ Đã bổ sung ' + (data.added_headings || 0) + ' heading + ' + (data.ai_sections || 0) + ' đoạn AI vào bài viết.', 'success'); setTimeout(refreshStatus, 500); }
            else { showToast('Không thể chèn vào trình soạn thảo.', 'error'); }
        }, function (msg) { $btn.prop('disabled', false).html('🚀 AI Tự động bổ sung bài viết'); showToast(msg, 'error'); });
    }

    function detectEditorMode() {
        if (window.wp && window.wp.data && wp.data.select('core/editor')) { return '🟦 Gutenberg Editor'; }
        if (typeof window.tinymce !== 'undefined' && window.tinymce.get('content')) { return '📝 Classic Editor (Visual)'; }
        if ($('#content').length) { return '📝 Classic Editor'; }
        return 'Unknown';
    }

    // ================= Live Monitor =================
    var liveTimer = null;
    function startLiveMonitor() {
        if (liveTimer) { clearInterval(liveTimer); }
        liveTimer = setInterval(function () { if (state.loading) return; var content = getEditorContent(); if (content !== lastMonitoredContent) { lastMonitoredContent = content; refreshStatus(); } }, 15000);
        if (window.wp && window.wp.data && wp.data.subscribe) {
            var lastContent = getEditorContent();
            wp.data.subscribe(function () { var content = getEditorContent(); if (content !== lastContent) { lastContent = content; lastMonitoredContent = content; debouncedRefreshStatus(); } });
        }
        $(document).on('input', '#content', function () { lastMonitoredContent = getEditorContent(); debouncedRefreshStatus(); });
    }
    var lastMonitoredContent = '';
    var debouncedRefreshStatus = debounce(refreshStatus, 1200);

    function refreshStatus() {
        if (state.loading) return;
        state.loading = true;
        var content = getEditorContent();
        doAjax('kira_sa_auditor_refresh_status', { content: content }, function (data) {
            state.outlineStatus = data.outline_status || state.outlineStatus;
            state.keywordStatus = data.keyword_status || state.keywordStatus;
            state.score = data.score || state.score;
            state.loading = false;
            renderCompareTab(); renderKeywordsTab(); renderScoreTab();
        }, function () { state.loading = false; });
    }

    // ================= Toast / Status / Copy =================
    function showStatus(msg, type) {
        var cls = 'kira-auditor-status-info';
        if (type === 'success') cls = 'kira-auditor-status-success';
        if (type === 'error') cls = 'kira-auditor-status-error';
        if (type === 'loading') cls = 'kira-auditor-status-loading';
        var $status = $root.find('.kira-auditor-status');
        if ($status.length) $status.attr('class', 'kira-auditor-status ' + cls).html(msg);
        if ($modalBody.length && $modalBody[0] !== $root[0]) {
            var $statusM = $modalBody.find('.kira-auditor-status');
            if ($statusM.length) $statusM.attr('class', 'kira-auditor-status ' + cls).html(msg);
        }
    }

    function showToast(message, type) {
        $('.kira-auditor-toast').remove();
        var icon = '';
        if (type === 'success') { icon = '<span class="dashicons dashicons-yes" style="color:#22c55e;"></span>'; }
        else if (type === 'error') { icon = '<span class="dashicons dashicons-warning" style="color:#ef4444;"></span>'; }
        else { icon = '<span class="dashicons dashicons-update spin" style="color:#ea580c;"></span>'; }
        var $toast = $('<div class="kira-auditor-toast kira-auditor-toast-' + (type || 'info') + '">' + icon + '<span>' + escapeHtml(message) + '</span></div>');
        $('body').append($toast);
        $toast.each(function () { this.offsetHeight; });
        $toast.addClass('kira-auditor-toast-show');
        if (type !== 'loading') { setTimeout(function () { $toast.removeClass('kira-auditor-toast-show'); setTimeout(function () { $toast.remove(); }, 300); }, 3500); }
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { showToast('✅ Đã copy vào clipboard. Dán (Ctrl+V) vào bài viết.', 'success'); }).catch(function () { legacyCopy(text); });
        } else { legacyCopy(text); }
    }
    function legacyCopy(text) {
        var $tmp = $('<textarea>').val(text).appendTo('body').select();
        try { document.execCommand('copy'); showToast('✅ Đã copy vào clipboard. Dán (Ctrl+V) vào bài viết.', 'success'); } catch (e) { showToast('Không copy được. Hãy bôi đen và bấm Ctrl+C.', 'error'); }
        $tmp.remove();
    }

    // ================= Boot =================
    bindLaunchers();
    init();
});