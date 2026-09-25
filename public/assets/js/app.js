/* ============================================================
   Radha Rani Hotel Portal — global app JS (hand-written)
   ============================================================ */
(function () {
    'use strict';

    const APP = window.APP || {};

    // ---------- Sidebar toggle (mobile) ----------
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle = document.getElementById('sidebarToggle');

    function sidebarFocusables() {
        return Array.prototype.filter.call(sidebar.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'), function (el) {
            return el.offsetParent !== null || el === document.activeElement;
        });
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('show');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
            if (lastSidebarFocus) { lastSidebarFocus.focus(); lastSidebarFocus = null; }
        }
    }

    let lastSidebarFocus = null;
    if (toggle && sidebar && overlay) {
        toggle.setAttribute('aria-expanded', 'false');
        toggle.addEventListener('click', function () {
            const isOpen = sidebar.classList.contains('open');
            if (!isOpen) {
                lastSidebarFocus = (document.activeElement && document.activeElement.nodeName === 'BODY') ? toggle : document.activeElement;
            }
            sidebar.classList.toggle('open');
            const open = sidebar.classList.contains('open');
            overlay.classList.toggle('show', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                const items = sidebarFocusables();
                if (items.length) items[0].focus();
            } else {
                if (lastSidebarFocus) { lastSidebarFocus.focus(); lastSidebarFocus = null; }
            }
        });
        overlay.addEventListener('click', closeSidebar);

        // Trap Tab/Shift+Tab within the sidebar while the drawer is open.
        sidebar.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Tab' || !sidebar.classList.contains('open')) return;
            const items = sidebarFocusables();
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (ev.shiftKey && document.activeElement === first) {
                ev.preventDefault(); last.focus();
            } else if (!ev.shiftKey && document.activeElement === last) {
                ev.preventDefault(); first.focus();
            }
        });
    }

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') closeSidebar();
    });

    // ---------- Reusable confirm modal (forms with data-confirm) ----------
    const confirmModalEl = document.getElementById('confirmModal');
    if (confirmModalEl && window.bootstrap) {
        const confirmModal = bootstrap.Modal.getOrCreateInstance(confirmModalEl);
        const bodyEl = document.getElementById('confirmModalBody');
        const okBtn = document.getElementById('confirmModalOk');
        let pendingForm = null;

        document.addEventListener('submit', function (ev) {
            const form = ev.target;
            if (!form || typeof form.matches !== 'function' || !form.matches('form[data-confirm]')) return;
            ev.preventDefault();
            pendingForm = form;
            if (bodyEl) bodyEl.textContent = form.getAttribute('data-confirm') || 'Are you sure you want to continue?';
            confirmModal.show();
        });

        okBtn.addEventListener('click', function () {
            confirmModal.hide();
            if (pendingForm) { const f = pendingForm; pendingForm = null; f.submit(); }
        });
    }

    // ---------- Bootstrap toasts ----------
    window.RRToast = function (message, type) {
        type = type || 'success';
        const bscType = type === 'danger' ? 'danger' : type;
        const icon = type === 'success' ? 'bi-check-circle-fill'
            : type === 'danger' ? 'bi-exclamation-octagon-fill'
            : type === 'warning' ? 'bi-exclamation-triangle-fill'
            : 'bi-info-circle-fill';

        const container = document.getElementById('appToasts');
        if (!container) return;

        const el = document.createElement('div');
        el.className = 'toast align-items-center show';
        el.style.cssText = 'background:#fff;color:#212a34;';

        const wrap = document.createElement('div');
        wrap.className = 'd-flex';

        const body = document.createElement('div');
        body.className = 'toast-body';
        const iconEl = document.createElement('i');
        iconEl.className = 'bi ' + icon + ' me-2';
        iconEl.style.color = type === 'success' ? '#198754' : type === 'danger' ? '#a02840' : '#c9a24b';
        body.appendChild(iconEl);
        body.appendChild(document.createTextNode(message));

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close me-2 m-auto';
        closeBtn.setAttribute('data-bs-dismiss', 'toast');
        closeBtn.setAttribute('aria-label', 'Close');

        wrap.appendChild(body);
        wrap.appendChild(closeBtn);
        el.appendChild(wrap);
        container.appendChild(el);

        setTimeout(function () {
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 400);
        }, 4200);
    };

    // ---------- Shared fetch helper ----------
    window.RRAPI = function (url, options) {
        options = options || {};
        const headers = options.headers || {};
        headers['X-Requested-With'] = 'XMLHttpRequest';
        if (options.csrf) headers['X-CSRF-Token'] = APP.CSRF;
        return fetch(url, Object.assign({}, options, { headers: headers, credentials: 'same-origin' }))
            .then(function (res) {
                return res.json().catch(function () {
                    throw new Error('Server returned an invalid response.');
                }).then(function (data) {
                    if (!data.success) {
                        const error = new Error(data.message || 'Request failed.');
                        error.status = res.status;
                        error.data = data;
                        throw error;
                    }
                    return data;
                });
            });
    };

    // ---------- Auto-dismiss server flash alerts ----------
    if (window.bootstrap) {
        document.querySelectorAll('.toast-flash').forEach(function (el) {
            setTimeout(function () {
                try {
                    bootstrap.Alert.getOrCreateInstance(el).close();
                } catch (err) { /* noop */ }
            }, 5000);
        });
    }

    // ---------- Password visibility toggle (data-pw-toggle="<input id>") ----------
    document.addEventListener('click', function (ev) {
        const btn = ev.target && ev.target.closest ? ev.target.closest('[data-pw-toggle]') : null;
        if (!btn) return;
        const input = document.getElementById(btn.getAttribute('data-pw-toggle'));
        if (!input || (input.type !== 'password' && input.type !== 'text')) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        const icon = btn.querySelector('i');
        if (icon) icon.className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye');
    });
})();