(function() {
    // 1. Nhận biến từ PHP truyền sang
    if (typeof aiChatboxData === 'undefined') {
        console.error('Lỗi: Chưa nhận được dữ liệu aiChatboxData từ PHP.');
        return;
    }
    const AJAX_URL = aiChatboxData.ajax_url;
    const SECURITY_NONCE = aiChatboxData.nonce;

    // 2. Khởi tạo Session
    let sessionId = localStorage.getItem('ai_chat_session_id');
    if (!sessionId) {
        sessionId = 'sess_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
        localStorage.setItem('ai_chat_session_id', sessionId);
    }

    let leadCaptured = localStorage.getItem('ai_lead_captured_' + sessionId);
    // FIX trùng lặp tin nhắn Telegram: thay biến đếm lastMsgCount bằng ID tin nhắn cuối cùng trong DB.
    // (Số đếm thủ công dễ lệch khỏi DB -> polling 5s bị lặp tin nhắn Admin trả lời)
    let lastDbMsgId = parseInt(localStorage.getItem('ai_chat_last_msg_id_' + sessionId), 10) || 0;

    // FIX tin nhắn khách bị hiện 2 lần: bubble đã được vẽ ngay khi khách gửi, nhưng AI trả lời
    // mất vài giây -> polling 5s thấy tin đó trong DB (id > lastDbMsgId) và vẽ lại lần nữa.
    // Hai lớp chống trùng:
    //   1) sendInFlight: tạm dừng polling trong lúc đang chờ phản hồi của lượt gửi.
    //   2) pendingUserMsg: nếu polling vẫn lướt thấy tin nhắn vừa gửi (đã vẽ locally) thì bỏ qua,
    //      chỉ sync ID (hạn 60 giây để không chặn nhầm khi khách chủ động gửi lại nội dung cũ).
    let sendInFlight = false;
    let pendingUserMsg = null;

    // 3. Lấy các phần tử DOM
    const chatBtn = document.getElementById('ai-chat-btn');
    const chatBox = document.getElementById('ai-chat-box');
    const chatClose = document.getElementById('ai-chat-close');
    const chatSendBtn = document.getElementById('ai-chat-send-btn');
    const chatInput = document.getElementById('ai-chat-input');
    const chatBody = document.getElementById('ai-chat-body');

    // Hàm lọc dữ liệu
    function sanitizeAndFilter(text) {
        if (!text) return '';
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function formatMarkdown(text) {
        let safe = sanitizeAndFilter(text);
        safe = safe.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        safe = safe.replace(/\*(.*?)\*/g, '<em>$1</em>');
        return safe.replace(/\n/g, '<br/>');
    }

    // 4. Xử lý Đóng/Mở Chatbox & Tự động mở thông minh (Hiển thị Form ngay lập tức)
    let chatOpened = false;

    function toggleBox() {
        if (!chatBox) return;
        const isOpen = chatBox.style.display === 'flex';
        chatBox.style.display = isOpen ? 'none' : 'flex';
        chatOpened = true; // Đánh dấu là user đã tương tác
        
        if (!isOpen) {
            // Hiện ngay form thông tin nếu chưa nhập (không chờ load lịch sử)
            if (leadCaptured !== 'yes') {
                appendLeadForm();
            }
            if (chatBody && chatBody.children.length === 0) {
                loadHistory();
            }
        }
    }

    function autoOpenChat() {
        if (!chatOpened && chatBox && chatBox.style.display !== 'flex') {
            chatOpened = true;
            chatBox.style.display = 'flex';
            
            // Hiện ngay form thông tin nếu chưa nhập
            if (leadCaptured !== 'yes') {
                appendLeadForm();
            }
            if (chatBody && chatBody.children.length === 0) {
                loadHistory();
            }
        }
    }

    if (chatBtn) chatBtn.addEventListener('click', toggleBox);
    if (chatClose) chatClose.addEventListener('click', () => chatBox.style.display = 'none');

    // Tự động mở sau 5 giây
    setTimeout(autoOpenChat, 5000);

    // Tự động mở khi cuộn 50% trang
    window.addEventListener('scroll', function() {
        if (chatOpened) return;
        let scrollPercent = (window.scrollY / (document.documentElement.scrollHeight - window.innerHeight)) * 100;
        if (scrollPercent >= 30) autoOpenChat();
    });

    // 5. Thêm tin nhắn vào khung chat (Kèm Hiệu ứng gõ chữ & Avatar)
    function appendMsg(sender, text, isInstant = false) {
        if (!chatBody) return;
        
        const row = document.createElement('div');
        const bubble = document.createElement('div');
        
        if (sender === 'bot') {
            row.className = 'ai-msg-row-bot';
            
            // Thêm Avatar
            const avatar = document.createElement('div');
            avatar.className = 'ai-bot-avatar-inline';
            avatar.innerHTML = 'CS';
            row.appendChild(avatar);

            bubble.className = 'ai-msg ai-msg-bot';
            row.appendChild(bubble);
            chatBody.appendChild(row);

            // Hiệu ứng Typewriter (Chỉ chạy khi không phải tải lại lịch sử và không chứa Form/Quick Reply)
            if (!isInstant && !text.includes('ai-quick-replies') && !text.includes('ai-lead-inline-card')) {
                let formattedText = formatMarkdown(text);
                let i = 0;
                bubble.innerHTML = '';
                
                // Mẹo: Đặt text ẩn để giữ độ cao khung chat
                bubble.insertAdjacentHTML('beforeend', `<span style="opacity:0; position:absolute; pointer-events:none;">${formattedText}</span>`);
                const typingContent = document.createElement('span');
                bubble.appendChild(typingContent);

                function typeWriter() {
                    if (i < text.length) {
                        typingContent.innerHTML = formatMarkdown(text.substring(0, i + 1));
                        i++;
                        chatBody.scrollTop = chatBody.scrollHeight;
                        setTimeout(typeWriter, 15); // Tốc độ gõ
                    }
                }
                typeWriter();
            } else {
                // In ngay lập tức
                bubble.innerHTML = text.includes('ai-quick-replies') ? text : formatMarkdown(text);
            }

        } else {
            row.className = 'ai-msg-row-user';
            bubble.className = 'ai-msg ai-msg-user';
            bubble.textContent = text;
            row.appendChild(bubble);
            chatBody.appendChild(row);
        }

        chatBody.scrollTop = chatBody.scrollHeight;
    }

    // 6. Hiển thị Form lấy thông tin (Lead Form)
    function appendLeadForm() {
        if (!chatBody) return;
        const existing = document.getElementById('ai-lead-card-box');
        if (existing) return;

        const formBox = document.createElement('div');
        formBox.className = 'ai-lead-inline-card';
        formBox.id = 'ai-lead-card-box';
        formBox.innerHTML = `
            <div class="ai-lead-input-group">
                <div class="ai-lead-input-wrap" id="wrap-lead-name">
                    <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                    <input type="text" id="ai-lead-name" placeholder="Họ và tên của bạn *" autocomplete="off" />
                </div>
                <span class="ai-lead-error" id="err-lead-name">Vui lòng nhập họ và tên</span>
            </div>

            <div class="ai-lead-input-group">
                <div class="ai-lead-input-wrap" id="wrap-lead-phone">
                    <svg viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
                    <input type="tel" id="ai-lead-phone" placeholder="Số điện thoại (10 số) *" maxlength="10" autocomplete="off" />
                </div>
                <span class="ai-lead-error" id="err-lead-phone">SĐT gồm 10 số (đầu 03, 05, 07, 08, 09)</span>
            </div>

            <div class="ai-lead-input-group">
                <div class="ai-lead-input-wrap" id="wrap-lead-email">
                    <svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                    <input type="email" id="ai-lead-email" placeholder="Email liên hệ (tùy chọn)" autocomplete="off" />
                </div>
                <span class="ai-lead-error" id="err-lead-email">Email không đúng định dạng</span>
            </div>

            <button type="button" id="ai-lead-btn-submit" class="ai-lead-inline-btn">
                Bắt đầu kết nối ➔
            </button>
        `;
        chatBody.appendChild(formBox);
        chatBody.scrollTop = chatBody.scrollHeight;

        // Xử lý sự kiện Submit Form
        const nameInput = document.getElementById('ai-lead-name');
        const phoneInput = document.getElementById('ai-lead-phone');
        const emailInput = document.getElementById('ai-lead-email');
        const wrapName = document.getElementById('wrap-lead-name');
        const wrapPhone = document.getElementById('wrap-lead-phone');
        const wrapEmail = document.getElementById('wrap-lead-email');
        const errName = document.getElementById('err-lead-name');
        const errPhone = document.getElementById('err-lead-phone');
        const errEmail = document.getElementById('err-lead-email');

        if(phoneInput) {
            phoneInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }

        const btnSubmit = document.getElementById('ai-lead-btn-submit');
        if (btnSubmit) {
            btnSubmit.addEventListener('click', function() {
                let isValid = true;
                const name = nameInput.value.trim();
                const phone = phoneInput.value.trim();
                const email = emailInput.value.trim();

                if (!name) {
                    wrapName.classList.add('is-invalid');
                    errName.style.display = 'block';
                    isValid = false;
                } else {
                    wrapName.classList.remove('is-invalid');
                    errName.style.display = 'none';
                }

                const phoneRegex = /^(03|05|07|08|09)[0-9]{8}$/;
                if (!phoneRegex.test(phone)) {
                    wrapPhone.classList.add('is-invalid');
                    errPhone.style.display = 'block';
                    isValid = false;
                } else {
                    wrapPhone.classList.remove('is-invalid');
                    errPhone.style.display = 'none';
                }

                if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    wrapEmail.classList.add('is-invalid');
                    errEmail.style.display = 'block';
                    isValid = false;
                } else {
                    wrapEmail.classList.remove('is-invalid');
                    errEmail.style.display = 'none';
                }

                if (!isValid) return;

                this.disabled = true;
                this.textContent = 'Đang kết nối...';

                const formData = new FormData();
                formData.append('action', 'ai_chat_save_lead');
                formData.append('security', SECURITY_NONCE);
                formData.append('session_id', sessionId);
                formData.append('name', name);
                formData.append('phone', phone);
                formData.append('email', email);
                formData.append('current_url', window.location.href);

                fetch(AJAX_URL, { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(res => {
                        leadCaptured = 'yes';
                        localStorage.setItem('ai_lead_captured_' + sessionId, 'yes');
                        formBox.remove();
                        
                        // BẮT NGỮ CẢNH TRANG & GỢI Ý DỊCH VỤ (PAGE-AWARE)
                        let url = window.location.href.toLowerCase();
                        let greeting = 'Dạ cảm ơn anh/chị **' + name + '**! Em là Mai Hoa trợ lý tư vấn trực tuyến. Em có thể hỗ trợ thông tin gì cho mình ạ?';
                        
                        if (url.includes('pci')) {
                            greeting = 'Dạ cảm ơn anh/chị **' + name + '**! Em thấy mình đang tìm hiểu về chứng nhận **PCI DSS**. Em có thể gửi bảng checklist hoặc báo giá nhanh cho mình tham khảo nhé!';
                        } else if (url.includes('soc')) {
                            greeting = 'Dạ cảm ơn anh/chị **' + name + '**! Em thấy mình đang quan tâm đến báo cáo **SOC 2**. Anh/chị cần tư vấn quy trình đánh giá hay nhận báo giá ạ?';
                        } else if (url.includes('iso') || url.includes('27001')) {
                            greeting = 'Dạ cảm ơn anh/chị **' + name + '**! Hệ thống **ISO 27001** đang là dịch vụ mũi nhọn bên em. Anh/chị cần hỗ trợ thông tin gì ạ?';
                        }

                        // Lời chào in thành bong bóng text sạch (không nhét nút vào trong bubble)
                        appendMsg('bot', greeting, true);

                        // NÚT TRẢ LỜI NHANH (QUICK REPLIES) - hàng chip nhỏ gọn nằm NGOÀI bubble
                        const qrRow = document.createElement('div');
                        qrRow.className = 'ai-msg-row-bot ai-qr-row';
                        const qrWrap = document.createElement('div');
                        qrWrap.className = 'ai-quick-replies';
                        qrWrap.innerHTML =
                            '<button type="button" data-msg="Báo giá trong 15 phút">⚡ Báo giá trong 15 phút</button>' +
                            '<button type="button" data-msg="Gặp chuyên gia tư vấn trực tiếp">📞 Gặp chuyên gia tư vấn</button>' +
                            '<button type="button" data-msg="Gửi tài liệu tham khảo">📄 Gửi tài liệu tham khảo</button>';
                        qrRow.appendChild(qrWrap);
                        if (chatBody) {
                            chatBody.appendChild(qrRow);
                            chatBody.scrollTop = chatBody.scrollHeight;
                        }

                        // Ủy quyền sự kiện: 1 listener cho cả hàng chip (nhẹ hơn inline onclick)
                        qrWrap.addEventListener('click', function(e) {
                            const btn = e.target.closest('button[data-msg]');
                            if (!btn) return;
                            qrRow.remove();
                            if (chatInput) chatInput.value = btn.getAttribute('data-msg');
                            const sendBtn = document.getElementById('ai-chat-send-btn');
                            if (sendBtn) sendBtn.click();
                        });
                        // Lưu ý: lời chào chỉ hiển thị phía client, KHÔNG lưu vào DB,
                        // nên KHÔNG đụng vào lastDbMsgId ở đây (polling so với DB luôn chính xác)
                    })
                    .catch(() => {
                        leadCaptured = 'yes';
                        localStorage.setItem('ai_lead_captured_' + sessionId, 'yes');
                        formBox.remove();
                    });
            });
        }
    }

    // 7. Hiệu ứng đang gõ (Dấu 3 chấm)
    function showTyping() {
        if (!chatBody) return;
        const row = document.createElement('div');
        row.className = 'ai-msg-row-bot';
        row.id = 'ai-chat-typing-row';

        const avatar = document.createElement('div');
        avatar.className = 'ai-bot-avatar-inline';
        avatar.innerHTML = 'CS';

        const typing = document.createElement('div');
        typing.className = 'ai-typing';
        typing.innerHTML = '<span></span><span></span><span></span>';

        row.appendChild(avatar);
        row.appendChild(typing);
        chatBody.appendChild(row);
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    function removeTyping() {
        const typingRow = document.getElementById('ai-chat-typing-row');
        if (typingRow) typingRow.remove();
    }

    // FIX: cập nhật & lưu ID tin nhắn cuối cùng trong DB (dùng cho polling Telegram)
    function syncLastMsgId(items) {
        (items || []).forEach(item => {
            const id = parseInt(item.id, 10);
            if (!isNaN(id) && id > lastDbMsgId) lastDbMsgId = id;
        });
        localStorage.setItem('ai_chat_last_msg_id_' + sessionId, String(lastDbMsgId));
    }

    // 8. Tải lịch sử & Gửi tin nhắn
    function loadHistory() {
        if (!chatBody) return;
        chatBody.innerHTML = '';
        
        fetch(AJAX_URL + '?action=ai_chat_load_history&security=' + SECURITY_NONCE + '&session_id=' + encodeURIComponent(sessionId))
            .then(res => res.json())
            .then(data => {
                if (!data || data.length === 0) {
                    appendMsg('bot', 'Chào bạn! Tôi có thể giúp gì cho bạn hôm nay?', true);
                    lastDbMsgId = 0;
                    localStorage.setItem('ai_chat_last_msg_id_' + sessionId, '0'); // DB rỗng -> reset luôn cả localStorage
                    if (leadCaptured !== 'yes') appendLeadForm();
                } else {
                    // Tải lịch sử thì in tức thì (true) để không bắt chạy lại hiệu ứng gõ phím
                    data.forEach(item => appendMsg(item.sender, item.message, true));
                    syncLastMsgId(data);
                    if (leadCaptured !== 'yes') appendLeadForm();
                }
            })
            .catch(() => {
                appendMsg('bot', 'Chào bạn! Tôi có thể giúp gì cho bạn hôm nay?', true);
                if (leadCaptured !== 'yes') appendLeadForm();
            });
    }

    // 9. ĐỒNG BỘ TELEGRAM: Lặp 5 giây / lần để quét tin nhắn mới từ Admin
    setInterval(() => {
        // Đang chờ phản hồi của lượt gửi tin -> bỏ qua lần quét này (chống vẽ trùng tin nhắn)
        if (sendInFlight) return;
        if (chatBox && chatBox.style.display === 'flex' && leadCaptured === 'yes') {
            fetch(AJAX_URL + '?action=ai_chat_load_history&security=' + SECURITY_NONCE + '&session_id=' + encodeURIComponent(sessionId))
            .then(res => res.json())
            .then(data => {
                // FIX: so sánh theo ID trong DB thay vì số đếm -> không còn hiện tượng trùng tin nhắn
                const newMsgs = (data || []).filter(item => (parseInt(item.id, 10) || 0) > lastDbMsgId);
                if (newMsgs.length > 0) {
                    // Tin nhắn khách vừa gửi đã được vẽ locally rồi -> bỏ qua, không vẽ lại
                    const toRender = newMsgs.filter(item => {
                        if (item.sender === 'user' && pendingUserMsg &&
                            item.message === pendingUserMsg.text &&
                            (Date.now() - pendingUserMsg.time) < 60000) {
                            return false;
                        }
                        return true;
                    });
                    toRender.forEach(item => appendMsg(item.sender, item.message, true));
                    // Sync ID của TOÀN BỘ tin nhắn mới (kể cả cái đã bỏ qua) để không quét lại lần sau
                    syncLastMsgId(newMsgs);
                }
            })
            .catch(() => {}); // Lỗi mạng tạm thời: bỏ qua, lần quét 5s sau sẽ thử lại
        }
    }, 5000);

    function handleSendMessage() {
        if (!chatInput) return;
        const msg = chatInput.value.trim();
        if (!msg) return;

        if (leadCaptured !== 'yes') {
            alert('Vui lòng hoàn thành form thông tin trước khi gửi câu hỏi nhé!');
            return;
        }

        appendMsg('user', msg);
        pendingUserMsg = { text: msg, time: Date.now() }; // đã vẽ bubble, chờ server xác nhận để chống trùng
        chatInput.value = '';
        chatInput.disabled = true;
        
        // Xóa hàng gợi ý nhanh nếu user tự nhập tin nhắn
        const existingQrRow = document.querySelector('.ai-qr-row');
        if (existingQrRow) existingQrRow.remove();

        showTyping();

        const formData = new FormData();
        formData.append('action', 'ai_chat_send_message');
        formData.append('security', SECURITY_NONCE);
        formData.append('session_id', sessionId);
        formData.append('message', msg);
        formData.append('current_url', window.location.href);

        sendInFlight = true; // tạm dừng polling cho tới khi nhận xong phản hồi
        fetch(AJAX_URL, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
            sendInFlight = false;
            removeTyping();
            chatInput.disabled = false;
            chatInput.focus();
            if (res.success && res.data && res.data.reply) {
                appendMsg('bot', res.data.reply);
                // Server trả về ID thật trong DB của tin nhắn bot -> đồng bộ tuyệt đối khi polling
                syncLastMsgId([{ id: res.data.bot_msg_id }]);
            } else {
                const errText = (res.data && res.data.error) ? res.data.error : 'Hệ thống đang bận, bạn thử lại nhé!';
                appendMsg('bot', errText);
                // Tin nhắn khách VẪN đã được lưu vào DB trước khi API lỗi ->
                // phải sync ID để polling 5s không vẽ lại tin nhắn đó (tránh lặp)
                if (res.data && res.data.user_msg_id) {
                    syncLastMsgId([{ id: res.data.user_msg_id }]);
                }
            }
        })
        .catch(() => {
            sendInFlight = false;
            // Giữ pendingUserMsg thêm 60s: request thất bại nhưng tin nhắn vẫn có thể đã
            // được lưu vào DB -> polling không được vẽ lại bubble đang có sẵn trên màn hình
            removeTyping();
            chatInput.disabled = false;
            appendMsg('bot', 'Không thể kết nối máy chủ, vui lòng thử lại.');
        });
    }

    if (chatSendBtn) chatSendBtn.addEventListener('click', handleSendMessage);
    if (chatInput) {
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleSendMessage();
            }
        });
    }
})();