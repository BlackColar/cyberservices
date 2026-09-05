document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('cyberHubForm');
  if (!form) return;

  const fileInput = document.getElementById('hubFileInput');
  const submitBtn = document.getElementById('hubSubmitBtn');
  const emailInput = document.getElementById('hubEmailInput');
  const otpInput = document.getElementById('hubOtpInput');
  const sessionTokenInput = document.getElementById('cyberSessionToken');
  const btnSendOtp = document.getElementById('btnSendOtp');
  const btnVerifyOtp = document.getElementById('btnVerifyOtp');
  const otpStatusMsg = document.getElementById('otpStatusMsg');
  const otpVerifyResult = document.getElementById('otpVerifyResult');
  const emailVerifiedBadge = document.getElementById('emailVerifiedBadge');
  const phoneInput = document.getElementById('hubPhoneInput');
  const phoneErrorMsg = document.getElementById('phoneErrorMsg');
  const ndaCheckbox = document.getElementById('ndaAgreementCheckbox');
  const fileHint = document.getElementById('hubFileHint');
  const progressItems = Array.from(form.querySelectorAll('.hub-progress span'));
  const stepTitles = Array.from(form.querySelectorAll('.hub-step-title'));

  // Highlight the section currently being completed on long questionnaires.
  if ('IntersectionObserver' in window && progressItems.length === stepTitles.length) {
    const observer = new IntersectionObserver(entries => {
      const visible = entries.filter(entry => entry.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (!visible) return;
      const index = stepTitles.indexOf(visible.target);
      progressItems.forEach((item, itemIndex) => item.classList.toggle('is-active', itemIndex <= index));
    }, { rootMargin: '-20% 0px -65% 0px', threshold: [0.1, 0.5] });
    stepTitles.forEach(title => observer.observe(title));
  }

  // Kiểm tra token đã có sẵn từ session/reload
  let isEmailVerified = Boolean(sessionTokenInput && sessionTokenInput.value.trim().length > 0);

  if (isEmailVerified && submitBtn) {
    submitBtn.disabled = false;
  }

  const ajaxUrl = cyberHubVars.ajaxUrl;
  const ajaxNonce = cyberHubVars.nonce;

  const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
  const phoneRegex = /^(03|05|07|08|09)[0-9]{8}$/;

  const blockedDomains = [
    'gmail.com', 'yahoo.com', 'yahoo.com.vn', 'hotmail.com', 'outlook.com',
    'live.com', 'icloud.com', 'mail.com', 'yandex.com', 'zoho.com', 'protonmail.com', 'aol.com'
  ];

  // Hiển thị lỗi inline thay vì alert — thân thiện hơn, không gián đoạn
  function showFieldError(input, msgEl, message) {
    if (input) {
      input.style.border = '2px solid #e53e3e';
      input.focus();
    }
    if (msgEl) {
      msgEl.style.color = '#9b1c1c';
      msgEl.innerText = message;
    }
  }

  function clearFieldError(input, msgEl) {
    if (input) input.style.border = '';
    if (msgEl) msgEl.innerText = '';
  }

  // 1. Ràng buộc số điện thoại theo thời gian thực (Chỉ cho phép gõ số, đúng 10 số di động)
  if (phoneInput) {
    phoneInput.addEventListener('input', function() {
      this.value = this.value.replace(/[^0-9]/g, '');

      if (this.value.length === 10) {
        if (!phoneRegex.test(this.value)) {
          this.style.border = '2px solid #e53e3e';
          if (phoneErrorMsg) phoneErrorMsg.style.display = 'block';
        } else {
          this.style.border = '1.5px solid #31c48d';
          if (phoneErrorMsg) phoneErrorMsg.style.display = 'none';
        }
      } else {
        if (phoneErrorMsg) phoneErrorMsg.style.display = 'none';
        this.style.border = '';
      }
    });

    phoneInput.addEventListener('blur', function() {
      if (this.value.trim() !== '' && !phoneRegex.test(this.value.trim())) {
        this.style.border = '2px solid #e53e3e';
        if (phoneErrorMsg) phoneErrorMsg.style.display = 'block';
      } else if (this.value.trim() !== '') {
        this.style.border = '1.5px solid #31c48d';
        if (phoneErrorMsg) phoneErrorMsg.style.display = 'none';
      }
    });
  }

  // OTP chỉ cho phép nhập số
  if (otpInput) {
    otpInput.addEventListener('input', function() {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
      clearFieldError(otpInput, otpVerifyResult);
    });

    // Enter trong ô OTP = bấm xác thực
    otpInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (btnVerifyOtp && !btnVerifyOtp.disabled) btnVerifyOtp.click();
      }
    });
  }

  // Enter trong ô Email = bấm gửi OTP
  if (emailInput && btnSendOtp) {
    emailInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !emailInput.readOnly) {
        e.preventDefault();
        if (!btnSendOtp.disabled) btnSendOtp.click();
      }
    });
  }

  // 2. Nhận mã OTP
  if (btnSendOtp) {
    btnSendOtp.addEventListener('click', function() {
      const emailVal = emailInput.value.trim();

      if (!emailVal || !emailRegex.test(emailVal)) {
        showFieldError(emailInput, otpStatusMsg, 'Vui lòng nhập email doanh nghiệp hợp lệ.');
        return;
      }

      const domain = emailVal.split('@')[1].toLowerCase();
      if (blockedDomains.includes(domain)) {
        showFieldError(emailInput, otpStatusMsg, 'Hệ thống chỉ chấp nhận Email Doanh nghiệp (không dùng @' + domain + ').');
        return;
      }

      clearFieldError(emailInput, otpStatusMsg);
      btnSendOtp.disabled = true;
      btnSendOtp.innerText = 'Đang gửi...';
      otpStatusMsg.style.color = '#0b3c5d';
      otpStatusMsg.innerText = 'Đang gửi mã...';

      const formData = new FormData();
      formData.append('action', 'cyber_send_otp');
      formData.append('security', ajaxNonce);
      formData.append('email', emailVal);

      fetch(ajaxUrl, {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          otpStatusMsg.style.color = '#03543f';
          otpStatusMsg.innerText = data.data.message;

          let countdown = 60;
          const timer = setInterval(() => {
            countdown--;
            btnSendOtp.innerText = 'Gửi lại (' + countdown + 's)';
            if (countdown <= 0) {
              clearInterval(timer);
              btnSendOtp.disabled = false;
              btnSendOtp.innerText = '1. Nhận mã OTP';
            }
          }, 1000);
        } else {
          btnSendOtp.disabled = false;
          btnSendOtp.innerText = '1. Nhận mã OTP';
          otpStatusMsg.style.color = '#9b1c1c';
          otpStatusMsg.innerText = data.data.message;
        }
      })
      .catch(err => {
        btnSendOtp.disabled = false;
        btnSendOtp.innerText = '1. Nhận mã OTP';
        otpStatusMsg.style.color = '#9b1c1c';
        otpStatusMsg.innerText = 'Lỗi kết nối máy chủ.';
      });
    });
  }

  // 3. Xác thực OTP
  if (btnVerifyOtp) {
    btnVerifyOtp.addEventListener('click', function() {
      const emailVal = emailInput.value.trim();
      const otpVal = otpInput.value.trim();

      if (!emailVal) {
        showFieldError(emailInput, otpVerifyResult, 'Vui lòng nhập Email Doanh nghiệp.');
        return;
      }

      if (!otpVal || otpVal.length !== 6 || isNaN(otpVal)) {
        showFieldError(otpInput, otpVerifyResult, 'Vui lòng nhập chính xác 6 số OTP.');
        return;
      }

      btnVerifyOtp.disabled = true;
      btnVerifyOtp.innerText = 'Đang kiểm tra...';
      otpVerifyResult.style.color = '#0b3c5d';
      otpVerifyResult.innerText = 'Đang xác thực mã...';

      const formData = new FormData();
      formData.append('action', 'cyber_verify_otp');
      formData.append('security', ajaxNonce);
      formData.append('email', emailVal);
      formData.append('otp', otpVal);

      fetch(ajaxUrl, {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        btnVerifyOtp.disabled = false;
        btnVerifyOtp.innerText = '2. Xác thực OTP';

        if (data.success) {
          isEmailVerified = true;
          sessionTokenInput.value = data.data.session_token;
          otpVerifyResult.style.color = '#03543f';
          otpVerifyResult.innerText = data.data.message;

          emailInput.readOnly = true;
          emailInput.style.backgroundColor = '#f8fafc';
          btnSendOtp.style.display = 'none';
          btnVerifyOtp.style.display = 'none';
          otpInput.readOnly = true;
          otpInput.style.backgroundColor = '#f8fafc';
          emailVerifiedBadge.style.display = 'inline-flex';

          submitBtn.disabled = false;
          submitBtn.innerText = 'Xác Nhận E-NDA & Gửi Phiếu Khảo Sát';

          // Tự động cuộn tới phần tiếp theo sau khi xác thực thành công
          const nextStep = document.getElementById('hubStep2');
          if (nextStep) {
            setTimeout(() => nextStep.scrollIntoView({ behavior: 'smooth', block: 'start' }), 400);
          }
        } else {
          otpVerifyResult.style.color = '#9b1c1c';
          otpVerifyResult.innerText = data.data.message;
          otpInput.focus();
          otpInput.select();
        }
      })
      .catch(err => {
        btnVerifyOtp.disabled = false;
        btnVerifyOtp.innerText = '2. Xác thực OTP';
        otpVerifyResult.style.color = '#9b1c1c';
        otpVerifyResult.innerText = 'Lỗi kết nối khi xác thực OTP.';
      });
    });
  }

  // 4. Kiểm tra tệp sơ đồ sơ bộ
  if (fileInput) {
    fileInput.addEventListener('change', function() {
      if (this.files && this.files[0]) {
        const file = this.files[0];
        const fileName = file.name.toLowerCase();
        const allowedExts = ['.pdf', '.jpg', '.jpeg', '.png'];
        const isValid = allowedExts.some(ext => fileName.endsWith(ext));
        const maxSize = 10 * 1024 * 1024; // 10MB

        if (!isValid) {
          alert('Chỉ chấp nhận file định dạng PDF, JPG hoặc PNG.');
          this.value = '';
          if (fileHint) {
            fileHint.textContent = 'Không bắt buộc · File được lưu trong kho bảo mật riêng.';
            fileHint.classList.remove('is-selected');
          }
          return;
        }

        if (file.size > maxSize) {
          alert('Dung lượng file vượt quá 10MB.');
          this.value = '';
          if (fileHint) {
            fileHint.textContent = 'Không bắt buộc · File được lưu trong kho bảo mật riêng.';
            fileHint.classList.remove('is-selected');
          }
          return;
        }

        if (fileHint) {
          fileHint.textContent = '✓ Đã chọn: ' + file.name + ' (' + Math.ceil(file.size / 1024) + ' KB)';
          fileHint.classList.add('is-selected');
        }
      }
    });
  }

  // 5. Chặn gửi nếu chưa xác thực OTP hoặc sai thông tin
  form.addEventListener('submit', function(e) {
    const currentToken = sessionTokenInput ? sessionTokenInput.value.trim() : '';
    if (!currentToken) {
      e.preventDefault();
      alert('Quý khách vui lòng hoàn tất bước Xác thực OTP trước khi gửi biểu mẫu.');
      if (otpInput) otpInput.focus();
      return;
    }

    const reqInputs = form.querySelectorAll('.hub-req');
    let firstInvalid = null;

    reqInputs.forEach(input => {
      if (!input.value.trim()) {
        input.style.border = '2px solid #e53e3e';
        if (!firstInvalid) firstInvalid = input;
      } else {
        input.style.border = '';
      }
    });

    if (firstInvalid) {
      e.preventDefault();
      alert('Vui lòng điền đầy đủ các thông tin bắt buộc (*)');
      firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      firstInvalid.focus({ preventScroll: true });
      return;
    }

    const rawPhone = phoneInput ? phoneInput.value.trim().replace(/[^0-9]/g, '') : '';
    if (!phoneRegex.test(rawPhone)) {
      e.preventDefault(); // Chặn reload hoàn toàn
      if (phoneInput) {
        phoneInput.style.border = '2px solid #e53e3e';
        phoneInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        phoneInput.focus({ preventScroll: true });
      }
      if (phoneErrorMsg) phoneErrorMsg.style.display = 'block';
      alert('Số điện thoại không hợp lệ! Vui lòng nhập đúng 10 số di động (bắt đầu bằng 03, 05, 07, 08, 09).');
      return;
    }

    if (ndaCheckbox && !ndaCheckbox.checked) {
      e.preventDefault();
      alert('Quý khách vui lòng tích chọn đồng ý với Thỏa thuận bảo mật thông tin (E-NDA).');
      ndaCheckbox.scrollIntoView({ behavior: 'smooth', block: 'center' });
      ndaCheckbox.focus({ preventScroll: true });
      return;
    }

    submitBtn.disabled = true;
    submitBtn.innerText = 'Đang hoàn tất ký E-NDA & gửi dữ liệu...';
  });
});