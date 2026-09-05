/**
 * Sitevorx — Admin JS v1.0.0
 * Toast notifications, sidebar toggle,
 * loading spinner, confirm modal, tab switching
 */
(function($) {
    'use strict';

    // ==========================================================================
    // 1. TOAST NOTIFICATION SYSTEM (with close button)
    // ==========================================================================
    window.soToast = function(message, type) {
        type = type || 'success';
        var container = document.getElementById('sv-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'sv-toast-container';
            container.className = 'sv-toast-container';
            document.body.appendChild(container);
        }

        var icons = {
            success: 'yes-alt',
            error:   'dismiss',
            warning: 'warning',
            info:    'info'
        };

        var toast = document.createElement('div');
        toast.className = 'sv-toast ' + type;
        var closeTitle = (typeof svToolkit !== 'undefined' && svToolkit.i18n) ? svToolkit.i18n.close : 'ÄÃ³ng';
        toast.innerHTML = '<span class="dashicons dashicons-' + (icons[type] || 'info') + '"></span><span class="sv-toast-msg">' + message + '</span><button class="sv-toast-close" onclick="this.parentElement.remove()" title="' + closeTitle + '">&times;</button>';
        container.appendChild(toast);

        // Auto-remove after 5s
        setTimeout(function() {
            if (toast.parentNode) {
                toast.classList.add('sv-toast-exit');
                setTimeout(function() {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, 400);
            }
        }, 5000);
    };

    // Convert Sitevorx-emitted notices (.notice.sv-notice) to toast.
    // We deliberately match only notices that carry our own .sv-notice marker
    // class — hijacking every .notice on the page would also steal admin
    // notices emitted by unrelated plugins (Rank Math, CartFlows, SureForms…)
    // and break their dismiss flow, because our toast close button only
    // removes the DOM element and never calls the third-party plugin's
    // dismiss AJAX endpoint. Result was that those plugins' notices flashed
    // as toasts on every Sitevorx tab switch with no way to silence them.
    $(document).ready(function() {
        $('.notice.sv-notice').each(function() {
            var $n = $(this);
            if ($n.closest('#wpbody-content').length === 0) return;
            var msg = '';
            if ($n.find('p').length > 0) {
                $n.find('p').each(function() {
                    msg += $(this).html() + '<br>';
                });
            } else {
                msg = $n.text();
            }
            if (!msg || msg.replace(/<br>/g, '').trim() === '') return;
            var type = 'info';
            if ($n.hasClass('notice-success')) type = 'success';
            else if ($n.hasClass('notice-error')) type = 'error';
            else if ($n.hasClass('notice-warning')) type = 'warning';
            soToast(msg, type);
            $n.remove();
        });
    });

    // ==========================================================================
    // 2. SIDEBAR TOGGLE
    // ==========================================================================
    $(document).ready(function() {
        var sidebar = document.querySelector('.sv-sidebar');
        var toggleBtn = document.querySelector('.sv-sidebar-toggle');
        if (sidebar && toggleBtn) {
            if (localStorage.getItem('sv_sidebar_collapsed') === '1') {
                sidebar.classList.add('collapsed');
                toggleBtn.innerHTML = '&#9654;';
            }
            toggleBtn.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                var isCollapsed = sidebar.classList.contains('collapsed');
                localStorage.setItem('sv_sidebar_collapsed', isCollapsed ? '1' : '0');
                toggleBtn.innerHTML = isCollapsed ? '&#9654;' : '&#9664;';
            });
        }
    });

    // ==========================================================================
    // 3. LOADING SPINNER ON SAVE BUTTONS
    // ==========================================================================
    $(document).ready(function() {
        $(document).on('click', 'form button[type="submit"], form input[type="submit"]', function() {
            $(this.form).data('sv-submit-button', this);
        });

        $(document).on('submit', 'form', function(e) {
            var submitter = null;
            if (e && e.originalEvent && e.originalEvent.submitter) {
                submitter = e.originalEvent.submitter;
            } else {
                submitter = $(this).data('sv-submit-button') || null;
            }

            var $clicked = submitter ? $(submitter) : $();
            if ($clicked.length === 0 || !$clicked.is('[name]')) return;
            $(this).removeData('sv-submit-button');
            // Don't add spinner to delete/reset buttons
            if ($clicked.attr('name') && ($clicked.attr('name').indexOf('delete') > -1 || $clicked.attr('name').indexOf('reset') > -1)) return;
            
            // Fix: Append hidden field so the server still receives the button's name
            var btnName = $clicked.attr('name');
            var btnVal = $clicked.attr('value') || '1';
            if (btnName) {
                $(this).append('<input type="hidden" name="' + btnName + '" value="' + btnVal + '">');
            }
            
            // Show full-page overlay for the malware scanner (long-running POST)
            var overlayMsg = $clicked.data('scan-overlay-msg');
            var overlaySub = $clicked.data('scan-overlay-sub');
            if (overlayMsg) {
                var $overlay = $('<div id="sv-scan-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(248,249,250,0.96);z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;">'
                    + '<span class="dashicons dashicons-shield-alt sv-spin" style="font-size:52px;width:52px;height:52px;color:#e74c3c;"></span>'
                    + '<p style="margin-top:20px;font-size:16px;font-weight:600;color:#2c3338;">'
                    + overlayMsg + '</p>'
                    + '<p style="margin-top:6px;font-size:13px;color:#646970;">'
                    + (overlaySub || '') + '</p>'
                    + '</div>');
                $('body').append($overlay);
            }

            $clicked.prop('disabled', true);
            var origText = $clicked.html();
            var savingText = $clicked.data('saving-text') ||
                ((typeof svToolkit !== 'undefined' && svToolkit.i18n) ? svToolkit.i18n.saving : 'Äang lÆ°u...');
            $clicked.html('<span class="dashicons dashicons-update sv-spin"></span> ' + savingText);
            $clicked.data('orig-text', origText);
        });
    });

    // ==========================================================================
    // 4. REFRESH OVERLAY (for long GET navigations like cache clear)
    // ==========================================================================
    $(document).ready(function() {
        $(document).on('click', '.sv-btn-refresh', function(e) {
            var href = $(this).attr('href');
            if (!href) return;
            e.preventDefault();
            var $overlay = $('<div id="sv-refresh-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(248,249,250,0.96);z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;">'
                + '<span class="dashicons dashicons-update sv-spin" style="font-size:42px;width:42px;height:42px;color:#10b981;"></span>'
                + '<p style="margin-top:18px;font-size:15px;font-weight:600;color:#2c3338;">'
                + ($(this).data('overlay-msg') || 'Äang lÃ m má»›i dá»¯ liá»‡u...') + '</p>'
                + '</div>');
            $('body').append($overlay);
            window.location.href = href;
        });
    });

    // ==========================================================================
    // 5. ERROR LOG TOGGLE
    // ==========================================================================
    $(document).ready(function() {
        var toggle = document.getElementById('sv_toggle_terminal');
        if (toggle) {
            toggle.addEventListener('change', function() {
                var wrapper = document.getElementById('sv_terminal_wrapper');
                if (wrapper) {
                    wrapper.style.display = this.checked ? 'block' : 'none';
                    if (this.checked) {
                        var ta = wrapper.querySelector('textarea');
                        if (ta) ta.scrollTop = ta.scrollHeight;
                    }
                }
            });
        }
    });

    // ==========================================================================
    // 6. IMPORT/EXPORT — COPY & DOWNLOAD
    // ==========================================================================
    window.soCopyExport = function(textareaId) {
        var ta = document.getElementById(textareaId);
        if (!ta) return;
        ta.select();
        document.execCommand('copy');
        var copiedMsg = (typeof svToolkit !== 'undefined' && svToolkit.i18n) ? svToolkit.i18n.copied : 'ÄÃ£ sao chÃ©p cáº¥u hÃ¬nh vÃ o clipboard!';
        soToast(copiedMsg, 'success');
    };

    window.soDownloadExport = function(textareaId) {
        var ta = document.getElementById(textareaId);
        if (!ta) return;
        var blob = new Blob([ta.value], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'sitevorx-settings-' + new Date().toISOString().slice(0, 10) + '.json';
        a.click();
        URL.revokeObjectURL(url);
        var downloadedMsg = (typeof svToolkit !== 'undefined' && svToolkit.i18n) ? svToolkit.i18n.downloaded : 'ÄÃ£ táº£i file cáº¥u hÃ¬nh xuá»‘ng!';
        soToast(downloadedMsg, 'success');
    };

    // ==========================================================================
    // 7. CUSTOM CONFIRM MODAL (replaces ugly browser confirm())
    // ==========================================================================
    window.soConfirm = function(message, onConfirm) {
        // Remove existing modal
        var existing = document.getElementById('sv-confirm-modal');
        if (existing) existing.remove();

        var overlay = document.createElement('div');
        overlay.id = 'sv-confirm-modal';
        overlay.className = 'sv-modal-overlay';
        var _i = (typeof svToolkit !== 'undefined' && svToolkit.i18n) ? svToolkit.i18n : {};
        overlay.innerHTML = '<div class="sv-modal-box">'
            + '<div class="sv-modal-icon"><span class="dashicons dashicons-warning" style="color: #f39c12; font-size: 40px; width: 40px; height: 40px;"></span></div>'
            + '<div class="sv-modal-body">'
            + '<h3>' + (_i.confirmTitle || 'Xác nhận hành động') + '</h3>'
            + '<p>' + message + '</p>'
            + '</div>'
            + '<div class="sv-modal-actions">'
            + '<button class="button sv-modal-cancel">' + (_i.confirmCancel || 'Hủy bỏ') + '</button>'
            + '<button class="button button-primary sv-modal-ok" style="background:#d63638; border-color:#d63638;">' + (_i.confirmOk || 'Xác nhận') + '</button>'
            + '</div>'
            + '</div>';
        document.body.appendChild(overlay);

        // Animate in
        requestAnimationFrame(function() { overlay.classList.add('active'); });

        overlay.querySelector('.sv-modal-cancel').addEventListener('click', function() {
            overlay.classList.remove('active');
            setTimeout(function() { overlay.remove(); }, 300);
        });
        overlay.querySelector('.sv-modal-ok').addEventListener('click', function() {
            overlay.classList.remove('active');
            setTimeout(function() { overlay.remove(); }, 300);
            if (onConfirm) onConfirm();
        });
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                overlay.classList.remove('active');
                setTimeout(function() { overlay.remove(); }, 300);
            }
        });
    };

    // Replace browser confirm() on forms with [data-confirm] or onsubmit="return confirm(...)"
    $(document).ready(function() {
        // For forms with onsubmit confirm
        $(document).on('submit', 'form[onsubmit*="confirm"]', function(e) {
            e.preventDefault();
            var $form = $(this);
            var origOnsubmit = $form.attr('onsubmit');
            var msgMatch = origOnsubmit.match(/confirm\(['"](.+?)['"]\)/);
            var defaultConfirm = (typeof svToolkit !== 'undefined' && svToolkit.i18n) ? svToolkit.i18n.confirmDefault : 'Bạn có chắc chắn?';
            var msg = msgMatch ? msgMatch[1] : defaultConfirm;
            $form.removeAttr('onsubmit');
            soConfirm(msg, function() {
                $form[0].submit();
            });
        });

        // For elements with data-confirm
        $(document).on('click', '[data-confirm]', function(e) {
            e.preventDefault();
            var $el = $(this);
            var msg = $el.data('confirm');
            soConfirm(msg, function() {
                if ($el.is('a')) {
                    window.location.href = $el.attr('href');
                } else if ($el.is('button, input[type="submit"]')) {
                    $el.removeAttr('data-confirm');
                    $el[0].click();
                }
            });
        });
    });

    // ==========================================================================
    // 8. LANGUAGE TOGGLE (VI / EN)
    // ==========================================================================
    $(document).ready(function() {
        if (typeof svToolkit === 'undefined') return;

        $(document).on('click', '.sv-lang-btn', function() {
            var $btn = $(this);
            var $toggle = $btn.closest('.sv-lang-toggle');
            var $buttons = $toggle.find('.sv-lang-btn');
            var lang = $btn.data('lang');
            var previousLang = $buttons.filter('.active').data('lang');
            var i18n = svToolkit.i18n || {};

            if (!$toggle.length || $btn.hasClass('active') || $toggle.hasClass('is-loading')) return;

            var allowExternalTranslate = '0';
            if (lang === 'en_US' && svToolkit.autoTranslateConsent !== '1') {
                if ($btn.data('allow-external-translate') !== '1') {
                    var consentMessage = i18n.externalTranslateConsent || 'Some untranslated interface strings may be sent to Google Translate. Continue only if you agree to use this external service.';
                    if (typeof window.soConfirm === 'function') {
                        window.soConfirm(consentMessage, function() {
                            $btn.data('allow-external-translate', '1');
                            $btn.trigger('click');
                        });
                    } else if (window.confirm(consentMessage)) {
                        $btn.data('allow-external-translate', '1');
                        $btn.trigger('click');
                    }
                    return;
                }
                allowExternalTranslate = '1';
                $btn.removeData('allow-external-translate');
            }

            $toggle.addClass('is-loading');
            $buttons.prop('disabled', true).removeClass('active');
            $btn.addClass('active');

            if (typeof window.soToast === 'function') {
                soToast(i18n.switching || 'Äang chuyá»ƒn ngÃ´n ngá»¯...', 'info');
            }

            $.ajax({
                url: svToolkit.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'sv_toggle_language',
                    nonce: svToolkit.langNonce,
                    language: lang,
                    allow_external_translate: allowExternalTranslate
                }
            }).done(function(res) {
                if (res && res.success) {
                    if (typeof window.soToast === 'function') {
                        soToast(i18n.switchSuccess || 'ÄÃ£ lÆ°u ngÃ´n ngá»¯. Äang táº£i láº¡i...', 'success');
                    }
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 450);
                    return;
                }

                $buttons.removeClass('active');
                $buttons.filter('[data-lang="' + previousLang + '"]').addClass('active');
                if (typeof window.soToast === 'function') {
                    soToast(i18n.switchError || 'KhÃ´ng thá»ƒ chuyá»ƒn ngÃ´n ngá»¯. Vui lÃ²ng thá»­ láº¡i.', 'error');
                }
            }).fail(function() {
                $buttons.removeClass('active');
                $buttons.filter('[data-lang="' + previousLang + '"]').addClass('active');
                if (typeof window.soToast === 'function') {
                    soToast(i18n.switchError || 'KhÃ´ng thá»ƒ chuyá»ƒn ngÃ´n ngá»¯. Vui lÃ²ng thá»­ láº¡i.', 'error');
                }
            }).always(function() {
                $toggle.removeClass('is-loading');
                $buttons.prop('disabled', false);
            });
        });
    });

    // ==========================================================================
    // 9. MAINTENANCE PAGE — INLINE PLUGIN UPDATES
    // ==========================================================================
    $(document).ready(function() {
        if (typeof svToolkit === 'undefined') return;

        $(document).on('click', '.sv-plugin-update-btn', function() {
            var $btn = $(this);
            var plugin = $btn.data('plugin');
            var $row = $btn.closest('.sv-plugin-update-item');
            var $status = $row.find('.sv-plugin-update-status');
            var $version = $row.find('.sv-plugin-update-version');
            var $countBadge = $('#sv-plugin-update-count');
            var $emptyState = $('#sv-plugin-update-empty');
            var i18n = svToolkit.i18n || {};
            var originalText = $btn.html();

            if (!plugin || $btn.prop('disabled')) return;

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update sv-spin"></span> ' + (i18n.pluginUpdating || 'Đang cập nhật...'));
            $status.text(i18n.pluginUpdating || 'Đang cập nhật...').css('color', '#2563eb');

            $.ajax({
                url: svToolkit.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'sv_update_plugin',
                    nonce: svToolkit.pluginUpdateNonce,
                    plugin: plugin
                }
            }).done(function(res) {
                if (!(res && res.success && res.data)) {
                    var errorMsg = res && res.data && res.data.message ? res.data.message : (i18n.pluginUpdateFailed || 'Cập nhật thất bại. Vui lòng thử lại.');
                    $btn.prop('disabled', false).html(originalText);
                    $status.text(errorMsg).css('color', '#b91c1c');
                    if (typeof window.soToast === 'function') soToast(errorMsg, 'error');
                    return;
                }

                var data = res.data;
                var message = data.message || (i18n.pluginUpdated || 'Đã cập nhật');
                if (data.version) {
                    var versionText = i18n.pluginCurrentVersion || 'Phiên bản hiện tại: %s';
                    $version.text(versionText.replace('%s', data.version));
                } else {
                    $version.text(i18n.pluginUpdated || 'Đã cập nhật');
                }
                $status.text(message).css('color', '#166534');
                $btn.replaceWith('<span class="button" style="pointer-events:none; opacity:.75;">' + (i18n.pluginUpdated || 'Đã cập nhật') + '</span>');

                if ($countBadge.length) {
                    var remaining = typeof data.remaining_count === 'number' ? data.remaining_count : 0;
                    if (remaining > 0) {
                        $countBadge.text(remaining);
                    } else {
                        $countBadge.remove();
                        $emptyState.show();
                    }
                }

                if (typeof window.soToast === 'function') soToast(message, 'success');
            }).fail(function(xhr) {
                var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : (i18n.pluginUpdateFailed || 'Cập nhật thất bại. Vui lòng thử lại.');
                $btn.prop('disabled', false).html(originalText);
                $status.text(message).css('color', '#b91c1c');
                if (typeof window.soToast === 'function') soToast(message, 'error');
            });
        });
    });

    // (Dark mode feature has been removed as per request)

    // ==========================================================================
    // 9. DASHBOARD ANIMATIONS (health score counter + progress bar)
    // ==========================================================================
    $(document).ready(function() {
        // 9a. Animate health score number from 0 â†’ target
        var $score = $('.sv-overview-health-score[data-score]');
        if ($score.length) {
            var target = parseInt($score.data('score'), 10) || 0;
            var $span  = $score.find('span').detach();
            $score.text('0').append($span);
            var current = 0;
            var steps   = 40;
            var delay   = 16; // ~60fps
            var inc     = Math.max(1, Math.ceil(target / steps));
            var timer   = setInterval(function() {
                current = Math.min(current + inc, target);
                $score.html(current + '<span>/100</span>');
                if (current >= target) clearInterval(timer);
            }, delay);
        }

        // 9b. Trigger CSS width transition for all [data-width] progress fills
        setTimeout(function() {
            $('[data-width]').each(function() {
                $(this).css('width', $(this).data('width'));
            });
        }, 120);

        // 10. Login key preview (Security tab, optimizer page)
        var $keyInput = $('input[name="sec_login_key"]');
        if ($keyInput.length) {
            $keyInput.on('input', function() {
                var val = this.value;
                var $wrap = $('#link_preview_wrap');
                if (val) {
                    $wrap.css('display', 'block');
                    $('#link_preview').text(window.location.origin + '/?' + val);
                } else {
                    $wrap.css('display', 'none');
                }
            });
        }

        // 11. Check-all toggle for heavy files (Disk Cleaner page)
        var $checkAll = $('#sv_check_all');
        if ($checkAll.length) {
            $checkAll.on('change', function() {
                $('.sv-junk-checkbox').prop('checked', this.checked);
            });
        }

        // 12. Generic "toggle block" checkbox → show/hide target element by ID
        $(document).on('change', 'input[type="checkbox"][data-sv-toggle]', function() {
            var targetId = $(this).data('sv-toggle');
            var $target = $('#' + targetId);
            if ($target.length) {
                $target.css('display', this.checked ? 'block' : 'none');
            }
        });

        // 13. Import/Export buttons (replace inline onclick)
        $(document).on('click', '[data-sv-copy-export]', function() {
            var tid = $(this).data('sv-copy-export');
            if (typeof window.soCopyExport === 'function') window.soCopyExport(tid);
        });
        $(document).on('click', '[data-sv-download-export]', function() {
            var tid = $(this).data('sv-download-export');
            if (typeof window.soDownloadExport === 'function') window.soDownloadExport(tid);
        });
    });

    // ==========================================================================
    // 14. PREMIUM THEME GRID SEARCH / FILTER / PAGINATION
    // ==========================================================================
    $(document).ready(function() {
        var $grid = $('#soThemeGrid');
        if (!$grid.length) return;

        var $items = $grid.find('.theme-item');
        var $search = $('#soThemeSearch');
        var $pager = $('#sv-theme-pagination');
        var perPage = 12;
        var currentPage = 1;
        var currentFilter = 'all';

        function itemMatches($item, query) {
            var installed = String($item.data('installed')) === '1';
            if (currentFilter === 'installed' && !installed) return false;
            if (currentFilter === 'not-installed' && installed) return false;
            if (!query) return true;
            return $item.text().toLowerCase().indexOf(query) !== -1;
        }

        function renderPager(totalPages) {
            if (!$pager.length) return;
            $pager.empty();
            if (totalPages <= 1) return;

            for (var i = 1; i <= totalPages; i++) {
                var $btn = $('<button type="button" class="button sv-theme-page-btn"></button>');
                $btn.text(i);
                $btn.attr('data-page', i);
                if (i === currentPage) $btn.addClass('button-primary active');
                $pager.append($btn);
            }
        }

        function applyThemeGridState() {
            var query = $.trim($search.val() || '').toLowerCase();
            var matches = [];

            $items.each(function() {
                var $item = $(this);
                if (itemMatches($item, query)) {
                    matches.push(this);
                }
                $item.hide();
            });

            var totalPages = Math.max(1, Math.ceil(matches.length / perPage));
            if (currentPage > totalPages) currentPage = totalPages;

            var start = (currentPage - 1) * perPage;
            var end = start + perPage;
            for (var i = start; i < end && i < matches.length; i++) {
                $(matches[i]).show();
            }

            $('#sv-theme-count').text(matches.length);
            renderPager(totalPages);
        }

        $(document).on('click', '.sv-filter-btn', function() {
            $('.sv-filter-btn').removeClass('active');
            $(this).addClass('active');
            currentFilter = $(this).data('filter') || 'all';
            currentPage = 1;
            applyThemeGridState();
        });

        $search.on('input', function() {
            currentPage = 1;
            applyThemeGridState();
        });

        $(document).on('click', '.sv-theme-page-btn', function() {
            currentPage = parseInt($(this).data('page'), 10) || 1;
            applyThemeGridState();
        });

        applyThemeGridState();
    });

})(jQuery);
