(() => {
  "use strict";

  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

  function initCardSwap() {
    document.querySelectorAll("[data-card-swap]").forEach((root) => {
      const cards = [...root.querySelectorAll("[data-swap-card]")];
      if (cards.length < 2) return;
      let active = 0;
      let paused = false;
      let interval = 0;
      const delay = Math.max(1000, Number(root.dataset.cardSwapInterval) || 4200);

      const render = () => cards.forEach((card, index) => {
        const position = (index - active + cards.length) % cards.length;
        card.dataset.position = String(position);
        card.setAttribute("aria-hidden", position === 0 ? "false" : "true");
      });
      const advance = () => { active = (active + 1) % cards.length; render(); };
      const restart = () => {
        window.clearInterval(interval);
        if (!paused && !reducedMotion.matches) interval = window.setInterval(advance, delay);
      };
      const setPaused = (value) => { paused = value; restart(); };

      root.addEventListener("click", advance);
      root.addEventListener("keydown", (event) => {
        if (event.key !== "Enter" && event.key !== " ") return;
        event.preventDefault();
        advance();
      });
      root.addEventListener("pointerenter", () => setPaused(true));
      root.addEventListener("pointerleave", () => setPaused(false));
      root.addEventListener("focus", () => setPaused(true));
      root.addEventListener("blur", () => setPaused(false));
      reducedMotion.addEventListener("change", restart);
      render();
      restart();
    });
  }

  function initPointerCards() {
    document.querySelectorAll("[data-spotlight-card], [data-process-card]").forEach((card) => {
      card.addEventListener("pointermove", (event) => {
        const rect = card.getBoundingClientRect();
        card.style.setProperty("--mouse-x", `${event.clientX - rect.left}px`);
        card.style.setProperty("--mouse-y", `${event.clientY - rect.top}px`);
      });
    });
  }

  function initMenu() {
    const root = document.querySelector("[data-staggered-menu]");
    if (!root) return;
    const trigger = root.querySelector(".trigger");
    const panel = root.querySelector(".panel");
    const overlay = root.querySelector(".overlay");
    const closeButton = root.querySelector(".close");
    const destinations = [...panel.querySelectorAll("a[href]")];
    const toggles = [...panel.querySelectorAll(".drawerRow > button[aria-controls]")];
    const desktopItems = [...root.querySelectorAll(".desktopItem")];
    const desktop = window.matchMedia("(min-width: 1181px)");
    let previousOverflow = "";
    let focusTimer = 0;
    let dismissedDesktopItem = null;

    const focusables = () => [...panel.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')]
      .filter((element) => !element.closest("[inert]"));
    const alignDesktopSubmenu = (item) => {
      const submenu = item.querySelector(":scope > .dropdown");
      if (!submenu) return;
      submenu.classList.remove("opensStart");
      submenu.removeAttribute("data-menu-compact");
      submenu.removeAttribute("data-menu-scroll");
      submenu.style.removeProperty("max-height");
      submenu.style.setProperty("--menu-shift-y", "0px");
      if (submenu.getBoundingClientRect().right > window.innerWidth - 16) submenu.classList.add("opensStart");
      window.requestAnimationFrame(() => {
        const rect = submenu.getBoundingClientRect();
        let shift = 0;
        if (rect.bottom > window.innerHeight - 12) shift = window.innerHeight - 12 - rect.bottom;
        if (rect.top + shift < 12) shift = 12 - rect.top;
        submenu.style.setProperty("--menu-shift-y", `${Math.round(shift)}px`);
        const availableHeight = Math.floor(window.innerHeight - Math.max(12, rect.top + shift) - 12);
        if (submenu.scrollHeight > availableHeight && availableHeight > 0) submenu.dataset.menuCompact = "true";
        if (submenu.scrollHeight > availableHeight && availableHeight > 0) {
          submenu.dataset.menuScroll = "true";
          submenu.style.maxHeight = `${availableHeight}px`;
        }
      });
    };
    const open = () => {
      previousOverflow = document.body.style.overflow;
      document.body.style.overflow = "hidden";
      root.dataset.open = "true";
      trigger.setAttribute("aria-expanded", "true");
      panel.setAttribute("aria-hidden", "false");
      panel.removeAttribute("inert");
      window.clearTimeout(focusTimer);
      focusTimer = window.setTimeout(() => focusables()[0]?.focus(), reducedMotion.matches ? 0 : 260);
    };
    const close = (restoreFocus = false) => {
      window.clearTimeout(focusTimer);
      root.dataset.open = "false";
      trigger.setAttribute("aria-expanded", "false");
      panel.setAttribute("aria-hidden", "true");
      panel.setAttribute("inert", "");
      document.body.style.overflow = previousOverflow;
      if (restoreFocus) trigger.focus();
    };

    trigger.addEventListener("click", open);
    overlay.addEventListener("click", () => close(false));
    closeButton.addEventListener("click", () => close(true));
    destinations.forEach((link) => link.addEventListener("click", () => close(false)));
    toggles.forEach((button) => button.addEventListener("click", () => {
      const children = document.getElementById(button.getAttribute("aria-controls"));
      const expanded = button.getAttribute("aria-expanded") === "true";
      button.setAttribute("aria-expanded", expanded ? "false" : "true");
      button.setAttribute("aria-label", `${expanded ? "Mở" : "Thu gọn"} ${button.previousElementSibling.textContent.trim()}`);
      if (children) {
        children.dataset.expanded = expanded ? "false" : "true";
        children.setAttribute("aria-hidden", expanded ? "true" : "false");
        children.inert = expanded;
      }
    }));
    desktopItems.forEach((item) => {
      const submenu = item.querySelector(":scope > .dropdown");
      item.addEventListener("pointerenter", () => {
        submenu?.setAttribute("data-menu-open", "true");
        root.dataset.desktopDismissed = "false";
        dismissedDesktopItem = null;
        alignDesktopSubmenu(item);
      });
      item.addEventListener("pointerleave", () => submenu?.removeAttribute("data-menu-open"));
      item.addEventListener("focusin", () => {
        submenu?.setAttribute("data-menu-open", "true");
        if (dismissedDesktopItem && dismissedDesktopItem !== item) {
          root.dataset.desktopDismissed = "false";
          dismissedDesktopItem = null;
        }
        alignDesktopSubmenu(item);
      });
    });
    window.addEventListener("resize", () => {
      desktopItems.forEach((item) => {
        if (item.matches(":hover") || item.contains(document.activeElement)) alignDesktopSubmenu(item);
      });
    }, { passive: true });
    root.querySelector(".desktop")?.addEventListener("pointerleave", () => {
      root.dataset.desktopDismissed = "false";
      dismissedDesktopItem = null;
    });
    desktop.addEventListener("change", () => { if (desktop.matches) close(false); });
    document.addEventListener("keydown", (event) => {
      if (desktop.matches && event.key === "Escape" && root.contains(document.activeElement)) {
        const item = document.activeElement.closest(".desktopItem");
        item?.querySelector(":scope > .desktopLink")?.focus();
        dismissedDesktopItem = item;
        root.dataset.desktopDismissed = "true";
        return;
      }
      if (root.dataset.open !== "true") return;
      if (event.key === "Escape") { close(true); return; }
      if (event.key !== "Tab") return;
      const items = focusables();
      const first = items[0];
      const last = items.at(-1);
      if (!first || !last) return;
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
  }

  function initContactLauncher() {
    const root = document.querySelector("[data-contact-launcher]");
    if (!root) return;
    const button = root.querySelector(".contactLauncherButton");
    const panel = root.querySelector(".contactLauncherPanel");
    const setOpen = (open) => {
      root.dataset.open = open ? "true" : "false";
      button.setAttribute("aria-expanded", open ? "true" : "false");
      button.setAttribute("aria-label", open ? "Đóng các kênh liên hệ" : "Mở các kênh liên hệ");
      panel.setAttribute("aria-hidden", open ? "false" : "true");
      if (open) panel.removeAttribute("inert");
      else panel.setAttribute("inert", "");
    };
    button.addEventListener("click", () => setOpen(root.dataset.open !== "true"));
    document.addEventListener("pointerdown", (event) => {
      if (root.dataset.open === "true" && !root.contains(event.target)) setOpen(false);
    });
    document.addEventListener("keydown", (event) => { if (event.key === "Escape" && root.dataset.open === "true") { setOpen(false); button.focus(); } });
  }

  function initAiWidget() {
    const enhance = () => {
      const root = document.querySelector("#ai-chat-root");
      const submit = root?.querySelector("#ai-lead-btn-submit");
      if (!root || !submit) return false;
      submit.textContent = "🎁 Nhận ưu đãi & Tư vấn ngay ➜";
      submit.setAttribute("aria-label", "Nhận ưu đãi và tư vấn ngay");
      if (root.querySelector("[data-cyber-ai-offer]")) return true;
      const offer = document.createElement("aside");
      offer.className = "cyberAiOffer";
      offer.dataset.cyberAiOffer = "";
      offer.setAttribute("aria-label", "Ưu đãi đặc quyền: giảm 30% - 50% chi phí dịch vụ");
      const label = document.createElement("div");
      label.className = "cyberAiOfferLabel";
      const gift = document.createElement("span");
      gift.setAttribute("aria-hidden", "true");
      gift.textContent = "🎁";
      const title = document.createElement("strong");
      title.textContent = "ƯU ĐÃI ĐẶC QUYỀN:";
      const intro = document.createElement("span");
      intro.textContent = "Giảm ngay";
      label.append(gift, title, intro);
      const amount = document.createElement("strong");
      amount.className = "cyberAiOfferAmount";
      amount.textContent = "30% - 50%";
      const service = document.createElement("span");
      service.className = "cyberAiOfferService";
      service.textContent = "chi phí dịch vụ";
      const timing = document.createElement("span");
      timing.className = "cyberAiOfferTiming";
      timing.textContent = "khi đăng ký tư vấn hôm nay";
      offer.append(label, amount, service, timing);
      submit.before(offer);
      return true;
    };

    if (enhance()) return;
    if (!document.body) return;
    const observer = new MutationObserver(() => { if (enhance()) observer.disconnect(); });
    observer.observe(document.body, { childList: true, subtree: true });
    window.addEventListener("pagehide", () => observer.disconnect(), { once: true });
  }

  function ensureHiddenField(form, name, value = "") {
    let input = form.querySelector(`input[name="${name}"]`);
    if (!input) {
      input = document.createElement("input");
      input.type = "hidden";
      input.name = name;
      form.prepend(input);
    }
    input.value = value;
    return input;
  }

  function enhanceLegacyContactForm(form) {
    const config = window.cyberServicesTheme || {};
    form.dataset.contactForm = "";
    form.action = config.contactActionUrl || form.action;
    form.method = "post";

    const textInputs = form.querySelectorAll('input[type="text"]');
    if (textInputs[0]) textInputs[0].name = "name";
    if (textInputs[1]) textInputs[1].name = "company";
    const email = form.querySelector('input[type="email"]');
    const phone = form.querySelector('input[type="tel"]');
    const service = form.querySelector("select");
    const message = form.querySelector("textarea");
    if (email) email.name = "email";
    if (phone) phone.name = "phone";
    if (service) { service.name = "service"; service.required = true; }
    if (message) { message.name = "message"; message.required = true; }

    ensureHiddenField(form, "action", "cyber_contact");
    ensureHiddenField(form, "cyber_contact_nonce");

    if (!form.querySelector("[data-form-status]")) {
      const status = document.createElement("p");
      status.className = "cyber-form-status";
      status.dataset.formStatus = "";
      status.dataset.state = "idle";
      status.setAttribute("role", "status");
      status.setAttribute("aria-live", "polite");
      form.append(status);
    }
  }

  async function refreshContactNonce(form) {
    const config = window.cyberServicesTheme || {};
    if (!config.contactNonceUrl) throw new Error(config.formMessages?.error || "Không thể gửi yêu cầu lúc này.");
    const response = await fetch(config.contactNonceUrl, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });
    const result = await parseContactResponse(response, config.formMessages?.error);
    if (!response.ok || !result.success || !result?.data?.nonce) {
      throw new Error(config.formMessages?.error || "Không thể gửi yêu cầu lúc này.");
    }
    ensureHiddenField(form, "cyber_contact_nonce", result.data.nonce);
  }

  async function parseContactResponse(response, fallbackMessage) {
    const contentType = response.headers.get("content-type") || "";
    if (!contentType.toLowerCase().includes("application/json")) {
      throw new Error(fallbackMessage || "Không thể gửi yêu cầu lúc này.");
    }

    try {
      return await response.json();
    } catch {
      throw new Error(fallbackMessage || "Không thể gửi yêu cầu lúc này.");
    }
  }

  function initContactForms() {
    const forms = document.querySelectorAll("[data-contact-form], .cyber-contact-form form");
    if (!forms.length) return;
    forms.forEach((form) => {
      if (!form.matches("[data-contact-form]")) enhanceLegacyContactForm(form);
      const phone = form.querySelector('input[name="phone"]');
      phone?.addEventListener("input", () => {
        phone.value = phone.value.replace(/\D/g, "").slice(0, 10);
      });
      const button = form.querySelector('button[type="submit"]');
      const label = button?.childNodes[0];
      const status = form.querySelector("[data-form-status]");
      if (!button || !label || !status) return;
      const idleLabel = label.nodeValue;
      const messages = window.cyberServicesTheme?.formMessages || {};
      const setStatus = (state, message) => {
        status.dataset.state = state;
        status.textContent = message;
      };

      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        button.disabled = true;
        label.nodeValue = messages.loading || "Đang gửi…";
        setStatus("idle", "");

        try {
          await refreshContactNonce(form);
          const response = await fetch(form.getAttribute("action"), {
            method: "POST",
            body: new FormData(form),
            credentials: "same-origin",
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
          });
          const result = await parseContactResponse(response, messages.error);
          if (!response.ok || !result.success) throw new Error(result?.data?.message || messages.error);
          form.reset();
          setStatus("success", result?.data?.message || messages.success || "Đã gửi thành công.");
        } catch (error) {
          setStatus("error", error instanceof Error && error.message ? error.message : (messages.error || "Không thể gửi yêu cầu lúc này."));
        } finally {
          button.disabled = false;
          label.nodeValue = idleLabel;
        }
      });
    });
  }

  function initCursorGrid() {
    const container = document.querySelector("[data-cursor-grid]");
    const canvas = container?.querySelector("canvas");
    const target = container?.parentElement;
    const pointerMotion = window.matchMedia("(prefers-reduced-motion: reduce), (hover: none), (pointer: coarse)");
    if (!container || !canvas || !target || pointerMotion.matches) return;
    const context = canvas.getContext("2d");
    if (!context) return;

    const cellSize = 54;
    const radius = 150;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    let columns = 0, rows = 0, width = 0, height = 0, frame = 0, lastFrame = 0;
    let running = false;
    let canvasVisible = false;
    let alphas = new Float32Array(0);
    let touched = new Float64Array(0);
    const pulses = [];

    const rebuild = () => {
      width = container.offsetWidth;
      height = container.offsetHeight;
      canvas.width = Math.max(1, Math.round(width * dpr));
      canvas.height = Math.max(1, Math.round(height * dpr));
      canvas.style.width = `${width}px`;
      canvas.style.height = `${height}px`;
      context.setTransform(dpr, 0, 0, dpr, 0, 0);
      columns = Math.ceil(width / cellSize);
      rows = Math.ceil(height / cellSize);
      alphas = new Float32Array(columns * rows);
      touched = new Float64Array(columns * rows);
    };
    const energize = (x, y) => {
      const now = performance.now();
      for (let row = Math.max(0, Math.floor((y - radius) / cellSize)); row <= Math.min(rows - 1, Math.floor((y + radius) / cellSize)); row += 1) {
        for (let column = Math.max(0, Math.floor((x - radius) / cellSize)); column <= Math.min(columns - 1, Math.floor((x + radius) / cellSize)); column += 1) {
          const index = row * columns + column;
          const distance = Math.hypot(column * cellSize + cellSize / 2 - x, row * cellSize + cellSize / 2 - y);
          if (distance > radius) continue;
          const strength = 1 - distance / radius;
          alphas[index] = Math.max(alphas[index], strength * strength * (3 - 2 * strength) * 0.72);
          touched[index] = now;
        }
      }
    };
    const stop = () => {
      if (frame) window.cancelAnimationFrame(frame);
      frame = 0;
      running = false;
    };
    const draw = (now) => {
      if (!canvasVisible || document.hidden) { stop(); return; }
      const elapsed = Math.min(now - lastFrame, 50);
      lastFrame = now;
      context.clearRect(0, 0, width, height);
      for (let pulseIndex = pulses.length - 1; pulseIndex >= 0; pulseIndex -= 1) {
        const pulse = pulses[pulseIndex];
        const ringRadius = ((now - pulse.startedAt) / 1000) * 600;
        if (ringRadius > Math.hypot(width, height)) { pulses.splice(pulseIndex, 1); continue; }
        for (let index = 0; index < alphas.length; index += 1) {
          const column = index % columns;
          const row = Math.floor(index / columns);
          const distance = Math.hypot(column * cellSize + cellSize / 2 - pulse.x, row * cellSize + cellSize / 2 - pulse.y);
          if (Math.abs(distance - ringRadius) < cellSize / 2) { alphas[index] = Math.max(alphas[index], 0.72); touched[index] = now; }
        }
      }
      let hasActivity = pulses.length > 0;
      for (let index = 0; index < alphas.length; index += 1) {
        let alpha = alphas[index];
        if (alpha <= 0) continue;
        if (now - touched[index] > 180) { alpha = Math.max(0, alpha - elapsed / 650); alphas[index] = alpha; }
        if (alpha <= 0) continue;
        hasActivity = true;
        const column = index % columns;
        const row = Math.floor(index / columns);
        const centerX = column * cellSize + cellSize / 2;
        const centerY = row * cellSize + cellSize / 2;
        const gradient = context.createRadialGradient(centerX, centerY, 2, centerX, centerY, cellSize);
        gradient.addColorStop(0, `rgba(182, 255, 92, ${alpha})`);
        gradient.addColorStop(1, "rgba(182, 255, 92, 0)");
        context.beginPath();
        context.rect(column * cellSize + 0.5, row * cellSize + 0.5, cellSize - 1, cellSize - 1);
        context.strokeStyle = gradient;
        context.lineWidth = 1.2;
        context.stroke();
      }
      if (hasActivity) frame = requestAnimationFrame(draw); else running = false;
    };
    const wake = () => {
      if (!canvasVisible || document.hidden || running) return;
      running = true;
      lastFrame = performance.now();
      frame = requestAnimationFrame(draw);
    };
    target.addEventListener("pointermove", (event) => { const rect = container.getBoundingClientRect(); energize(event.clientX - rect.left, event.clientY - rect.top); wake(); });
    target.addEventListener("pointerdown", (event) => { const rect = container.getBoundingClientRect(); pulses.push({ x: event.clientX - rect.left, y: event.clientY - rect.top, startedAt: performance.now() }); wake(); });
    new ResizeObserver(rebuild).observe(container);
    const visibility = new IntersectionObserver(([entry]) => {
      canvasVisible = entry.isIntersecting;
      if (canvasVisible) wake(); else stop();
    }, { threshold: 0.01 });
    visibility.observe(container);
    const handleVisibility = () => { if (document.hidden) stop(); else if (canvasVisible) wake(); };
    document.addEventListener("visibilitychange", handleVisibility);
    rebuild();
    window.addEventListener("pagehide", () => {
      stop();
      visibility.disconnect();
      document.removeEventListener("visibilitychange", handleVisibility);
    }, { once: true });
  }

  function initMotion() {
    const elements = [...document.querySelectorAll("[data-animate], [data-hero-copy], [data-hero-visual]")];
    if (!elements.length) return;

    const reveal = (element) => element.setAttribute("data-motion-visible", "true");
    if (reducedMotion.matches || !("IntersectionObserver" in window)) {
      elements.forEach(reveal);
      return;
    }

    elements.forEach((element) => element.setAttribute("data-motion-pending", "true"));
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        reveal(entry.target);
        observer.unobserve(entry.target);
      });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.08 });
    elements.forEach((element) => observer.observe(element));
    reducedMotion.addEventListener("change", () => {
      if (!reducedMotion.matches) return;
      elements.forEach(reveal);
      observer.disconnect();
    }, { once: true });
  }

  function init() {
    initCardSwap();
    initPointerCards();
    initMenu();
    initContactLauncher();
    initAiWidget();
    initContactForms();
    initCursorGrid();
    initMotion();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init, { once: true });
  else init();
})();
