/* =========================================================
   MS Booking – Xử lý modal đặt lịch (UI/UX nâng cấp)
   Hỗ trợ nhiều shortcode trên cùng trang.
   Dữ liệu (ajaxUrl, nonce) được truyền từ PHP qua window.msBooking
   ========================================================= */
(function () {
    'use strict';

    var cfg = window.msBooking || { ajaxUrl: '', nonce: '' };

    function escapeHtml(str) {
        var map = {
            '&': '&' + 'amp;',
            '<': '&' + 'lt;',
            '>': '&' + 'gt;',
            '"': '&' + 'quot;',
            "'": '&#' + '39;'
        };
        return String(str).replace(/[&<>"']/g, function (ch) {
            return map[ch] || ch;
        });
    }

    function initModal(overlay) {
        var uid = overlay.getAttribute('data-bk-uid');
        if (!uid) { return; }

        var timeSelect = overlay.querySelector('.bk-time-select');
        var dateInput = overlay.querySelector('.bk-date-input');
        var form = overlay.querySelector('.ultimate-booking-form');
        var submitBtn = overlay.querySelector('.bk-submit-btn');
        var openBtns = document.querySelectorAll('[data-bk-uid="' + uid + '"][data-bk-open]');

        function setOverlay(show) {
            overlay.classList.toggle('bk-is-open', !!show);
            overlay.setAttribute('aria-hidden', show ? 'false' : 'true');
            document.body.classList.toggle('bk-no-scroll', !!show);
            if (show) {
                var first = overlay.querySelector('input, select, button');
                if (first) { first.focus(); }
            }
        }

        openBtns.forEach(function (btn) {
            btn.addEventListener('click', function () { setOverlay(true); });
        });

        overlay.querySelectorAll('[data-bk-close]').forEach(function (btn) {
            btn.addEventListener('click', function () { setOverlay(false); });
        });

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { setOverlay(false); }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('bk-is-open')) { setOverlay(false); }
        });

        function fetchAvailableSlots(dateVal) {
            if (!dateVal) {
                timeSelect.innerHTML = '<option value="">-- Chọn ngày trước --</option>';
                timeSelect.disabled = true;
                return;
            }

            timeSelect.classList.add('bk-is-loading');
            timeSelect.innerHTML = '<option value="">Đang kiểm tra lịch trống...</option>';
            timeSelect.disabled = true;

            var formData = new FormData();
            formData.append('action', 'get_available_slots');
            formData.append('date', dateVal);
            formData.append('security', cfg.nonce);

            fetch(cfg.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (response) {
                    timeSelect.classList.remove('bk-is-loading');
                    if (response.success && Array.isArray(response.data) && response.data.length > 0) {
                        var html = '<option value="">-- Chọn khung giờ --</option>';
                        response.data.forEach(function (slot) {
                            var s = escapeHtml(slot);
                            html += '<option value="' + s + '">' + s + '</option>';
                        });
                        timeSelect.innerHTML = html;
                        timeSelect.disabled = false;
                    } else {
                        var msg = (response.data && typeof response.data === 'string') ? response.data : 'Hết lịch trống trong ngày';
                        timeSelect.innerHTML = '<option value="">' + escapeHtml(msg) + '</option>';
                        timeSelect.disabled = true;
                    }
                })
                .catch(function () {
                    timeSelect.classList.remove('bk-is-loading');
                    timeSelect.innerHTML = '<option value="">Lỗi kết nối kiểm tra lịch</option>';
                    timeSelect.disabled = true;
                });
        }

        if (dateInput) {
            dateInput.addEventListener('change', function () {
                fetchAvailableSlots(this.value);
            });
        }

        if (form) {
            form.addEventListener('submit', function () {
                if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    return; // để trình duyệt hiển thị validate
                }
                if (submitBtn) {
                    submitBtn.classList.add('bk-is-loading');
                    submitBtn.disabled = true;
                }
                // cho phép submit tự nhiên tiếp tục
            });
        }
    }

    function initAll() {
        var overlays = document.querySelectorAll('.bk-modal-overlay');
        overlays.forEach(initModal);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();