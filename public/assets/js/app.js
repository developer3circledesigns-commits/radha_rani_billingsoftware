/* ============================================================
   Radha Rani Hotel Portal — global app JS (hand-written)
   ============================================================ */
(function () {
    'use strict';

    const APP = window.APP || {};

    // Server-rendered catalogue subset (window.APP.i18n) with English fallbacks.
    const S = APP.i18n || {};
    const txt = (key, fallback) => S[key] || fallback;

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
            if (bodyEl) bodyEl.textContent = form.getAttribute('data-confirm') || txt('js.confirm_default', 'Are you sure you want to continue?');
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
                    throw new Error(txt('js.invalid_response', 'Server returned an invalid response.'));
                }).then(function (data) {
                    if (!data.success) {
                        const error = new Error(data.message || txt('js.request_failed', 'Request failed.'));
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
        btn.setAttribute('aria-label', show
            ? txt('js.hide_password', 'Hide password')
            : txt('js.show_password', 'Show password'));
        const icon = btn.querySelector('i');
        if (icon) icon.className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye');
    });

    // ---------- Compliance settings: reveal the per-weekday deadline grid ----------
    // The grid stays in the DOM when hidden, so switching modes mid-form and
    // back does not lose what the owner typed.
    (function () {
        const mode = document.getElementById('c_mode');
        const wrap = document.getElementById('weekdayWrap');
        if (!mode || !wrap) return;
        mode.addEventListener('change', function () {
            wrap.hidden = mode.value !== 'per_weekday';
        });
    })();

    // ---------- Compliance settings: warn when nothing is required ----------
    // With both requirement switches off there is no gap a branch can have, so
    // the daily check is inert and no alert can ever be raised. That is a
    // legitimate choice for a hotel that takes no bills, but it is
    // indistinguishable from a broken feature if nothing says so - so the warning
    // appears as the owner unticks, before the save, not only after it.
    (function () {
        const cash = document.getElementById('s_cash');
        const card = document.getElementById('s_card');
        const warning = document.getElementById('requireNoneWarning');
        if (!cash || !card || !warning) return;

        const refresh = function () {
            warning.classList.toggle('d-none', cash.checked || card.checked);
        };
        cash.addEventListener('change', refresh);
        card.addEventListener('change', refresh);
        refresh();
    })();

    // ---------- Notification bell: read on open ----------
    // Server-rendered, so the list is already correct on load. Opening the
    // dropdown marks what is on screen as seen, which keeps the badge honest
    // without polling: a new alert appears on the owner's next page load.
    //
    // This must NOT submit the form. A form submit navigates, and the navigation
    // tears down the dropdown that was just opened, so the menu flashed and
    // vanished and could never be hovered. The POST goes out in the background
    // and the visible state is updated in place instead.
    //
    // The endpoint and the token come from window.APP rather than from the form
    // element: the form is only the no-JS fallback, and reading form.action
    // depends on the browser reflecting a URL off the element.
    (function () {
        const bell = document.getElementById('notifBell');
        if (!bell) return;

        const badge = bell.querySelector('[data-notif-badge]');
        if (!badge || !window.APP || typeof window.fetch !== 'function') return;

        const endpoint = (APP.BASE_URL || '') + '/notifications.php';

        const markVisibleRead = function () {
            badge.hidden = true;
            badge.classList.add('d-none');
            badge.setAttribute('aria-hidden', 'true');
            // The left rule on an unread row is the other read signal on screen.
            const menu = bell.parentElement ? bell.parentElement.querySelector('.notif-menu') : null;
            if (menu) {
                menu.querySelectorAll('.notif-item.is-unread').forEach(function (item) {
                    item.classList.remove('is-unread');
                });
            }
        };

        bell.addEventListener('show.bs.dropdown', function () {
            // Nothing unread means nothing to record. Without this guard every
            // click would POST, including on the empty state.
            if (badge.hidden || badge.classList.contains('d-none')) return;

            const body = new URLSearchParams();
            body.set('csrf_token', APP.CSRF);
            body.set('action', 'mark_all_read');
            body.set('return_to', window.location.pathname + window.location.search);

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            }).then(function (res) {
                // Only a real success clears the badge. On failure it is left
                // showing, so the owner is never told "seen" by a request the
                // server rejected.
                if (!res.ok) throw new Error('mark-read failed: ' + res.status);
                return res.json();
            }).then(function (data) {
                if (data && data.success) markVisibleRead();
            }).catch(function () {
                /* leave the badge; the next page load reconciles it */
            });
        });
    })();

    // ---------- Refresh once the daily compliance deadline passes ----------
    // Setting a deadline and then watching the page does nothing on its own: the
    // sweep only runs when the dashboard is loaded or the cron happens to fire,
    // so an alert could stay invisible for most of an hour. This arms ONE timer
    // for the moment the check becomes due, so the page updates itself. It is
    // deliberately not a poll - the value is null unless a check is genuinely
    // pending, so the timer fires at most once per page view.
    (function () {
        const dueAt = window.APP ? APP.COMPLIANCE_DUE_AT : null;
        if (!dueAt) return;

        // A few seconds of grace, so a page loaded at HH:MM:59.8 does not reload
        // just before the server agrees the deadline has passed.
        const delay = (dueAt * 1000) + 3000 - Date.now();
        if (delay <= 0) return;

        // setTimeout silently fires immediately past ~24.8 days, which would
        // reload the page at random. Far-off deadlines are the cron's problem.
        if (delay > 2147483647) return;

        const fire = function () {
            // Never reload out from under someone who is typing: on the settings
            // page that would silently discard a half-finished form.
            const el = document.activeElement;
            const busy = el && (
                el.tagName === 'INPUT' ||
                el.tagName === 'TEXTAREA' ||
                el.tagName === 'SELECT' ||
                el.isContentEditable
            );
            if (busy) {
                // Try again shortly. The check is idempotent, so a late reload is
                // harmless.
                setTimeout(fire, 30000);
                return;
            }
            window.location.reload();
        };

        setTimeout(fire, delay);
    })();
})();
