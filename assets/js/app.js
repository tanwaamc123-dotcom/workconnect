document.documentElement.classList.add('js');

const menuToggle = document.querySelector('.menu-toggle');
const mainNav = document.querySelector('.main-nav');

menuToggle?.addEventListener('click', () => {
    const open = mainNav?.classList.toggle('open') ?? false;
    menuToggle.setAttribute('aria-expanded', String(open));
});

const passwordToggle = document.querySelector('#toggle-password');
const password = document.querySelector('#password');

passwordToggle?.addEventListener('click', () => {
    if (!password) return;
    const show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    passwordToggle.textContent = show ? 'Hide' : 'Show';
    passwordToggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});

const adminRoleInputs = [...document.querySelectorAll('input[name="role"]')];
const adminCodeField = document.querySelector('[data-admin-code-field]');
const sellerVerificationFields = document.querySelector('[data-seller-fields]');
const refreshAdminCodeField = () => {
    if (adminRoleInputs.length === 0) return;
    const adminSelected = document.querySelector('input[name="role"][value="admin"]')?.checked ?? false;
    const sellerSelected = document.querySelector('input[name="role"][value="seller"]')?.checked ?? false;
    if (adminCodeField) adminCodeField.hidden = !adminSelected;
    if (sellerVerificationFields) sellerVerificationFields.hidden = !sellerSelected;
    const adminCodeInput = adminCodeField?.querySelector('input[name="admin_code"]');
    if (adminCodeInput) adminCodeInput.required = adminSelected;
    sellerVerificationFields?.querySelectorAll('input').forEach((input) => {
        if (!(input instanceof HTMLInputElement)) return;
        if (['phone', 'birth_date', 'id_card_number', 'id_card_front'].includes(input.name)) {
            input.required = sellerSelected;
        }
    });
};
adminRoleInputs.forEach((input) => input.addEventListener('change', refreshAdminCodeField));
refreshAdminCodeField();

const workspaceMenu = document.querySelector('.workspace-menu');
const workspaceOverlay = document.querySelector('.workspace-overlay');
const closeWorkspaceNavigation = () => document.body.classList.remove('nav-open');

workspaceMenu?.addEventListener('click', () => document.body.classList.toggle('nav-open'));
workspaceOverlay?.addEventListener('click', closeWorkspaceNavigation);
document.querySelectorAll('.workspace-sidebar a').forEach((link) => link.addEventListener('click', closeWorkspaceNavigation));

const toastState = {
    stack: null,
    counter: 0,
};

function ensureToastStack() {
    if (toastState.stack) return toastState.stack;
    const stack = document.createElement('div');
    stack.className = 'toast-stack';
    stack.setAttribute('aria-live', 'polite');
    stack.setAttribute('aria-atomic', 'true');
    document.body.appendChild(stack);
    toastState.stack = stack;
    return stack;
}

function toastMeta(type = 'info') {
    switch (type) {
        case 'success':
            return { title: 'Success', icon: '✓', className: 'is-success' };
        case 'error':
            return { title: 'Something went wrong', icon: '!', className: 'is-error' };
        case 'warning':
            return { title: 'Warning', icon: '!', className: 'is-warning' };
        case 'account':
            return { title: 'Account update', icon: '⌁', className: 'is-account' };
        case 'order':
            return { title: 'Order update', icon: '▣', className: 'is-order' };
        case 'message':
            return { title: 'New message', icon: '◇', className: 'is-message' };
        case 'payment':
            return { title: 'Payment update', icon: '฿', className: 'is-payment' };
        case 'review':
            return { title: 'Review update', icon: '★', className: 'is-review' };
        case 'announcement':
            return { title: 'Announcement', icon: '◌', className: 'is-announcement' };
        default:
            return { title: 'WorkConnect', icon: 'i', className: 'is-info' };
    }
}

function showToast({ type = 'info', title, message, actionLabel = '', actionHref = '', timeout = 5400 } = {}) {
    const stack = ensureToastStack();
    const meta = toastMeta(type);
    const toast = document.createElement('article');
    toast.className = `toast ${meta.className}`;
    toast.dataset.toastId = String(++toastState.counter);
    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = meta.icon;

    const copy = document.createElement('div');
    copy.className = 'toast-copy';
    const heading = document.createElement('strong');
    heading.textContent = title || meta.title;
    const body = document.createElement('p');
    body.textContent = message || '';
    copy.append(heading, body);

    if (actionLabel && actionHref) {
        const action = document.createElement('a');
        action.href = actionHref;
        action.textContent = actionLabel;
        copy.append(action);
    }

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'toast-close';
    closeButton.setAttribute('aria-label', 'Dismiss notification');
    closeButton.textContent = '×';

    toast.append(icon, copy, closeButton);
    const close = () => {
        if (!toast.isConnected) return;
        toast.classList.add('is-leaving');
        window.setTimeout(() => toast.remove(), 220);
    };
    closeButton.addEventListener('click', close);
    stack.appendChild(toast);
    window.setTimeout(close, timeout);
    return toast;
}

function collectFlashToasts() {
    document.querySelectorAll('.flash, .public-flash').forEach((flash) => {
        const type = flash.classList.contains('error') ? 'error' : flash.classList.contains('success') ? 'success' : flash.classList.contains('info') ? 'info' : 'warning';
        const message = flash.querySelector('p')?.textContent?.trim() || flash.textContent.trim();
        showToast({ type, message });
        flash.remove();
    });
}

collectFlashToasts();

const announcementBanner = document.querySelector('[data-announcement]');
const announcementDismissKey = 'workconnect-dismissed-announcement';

if (announcementBanner instanceof HTMLElement) {
    const duration = Number.parseInt(announcementBanner.dataset.duration || '15', 10);
    const safeDuration = Number.isFinite(duration) ? Math.min(30, Math.max(10, duration)) : 15;
    const announcementId = announcementBanner.dataset.announcementId || '';
    const dismissedAnnouncementId = storageGet(announcementDismissKey);
    if (announcementId && dismissedAnnouncementId === announcementId) {
        announcementBanner.remove();
    } else {
    const dismissAnnouncement = () => {
        if (!announcementBanner.isConnected) return;
        if (announcementId) storageSet(announcementDismissKey, announcementId);
        announcementBanner.classList.add('is-leaving');
        window.setTimeout(() => announcementBanner.remove(), 260);
    };

    announcementBanner.querySelector('[data-announcement-close]')?.addEventListener('click', dismissAnnouncement);
    announcementBanner.style.setProperty('--announcement-duration', `${safeDuration}s`);
    window.setTimeout(dismissAnnouncement, safeDuration * 1000);
    }
}

document.querySelectorAll('[data-demo-email]').forEach((button) => {
    button.addEventListener('click', () => fillDemoCredentials(button.dataset.demoEmail));
});

function fillDemoCredentials(emailAddress) {
    const email = document.querySelector('input[name="email"]');
    const demoPassword = document.querySelector('input[name="password"]');
    if (!email || !demoPassword || !emailAddress) return;
    email.value = emailAddress;
    demoPassword.value = 'Demo1234!';
    email.focus();
}

const demoRole = new URLSearchParams(window.location.search).get('demo');
const demoEmails = {
    customer: 'customer@workconnect.test',
    seller: 'seller@workconnect.test',
    admin: 'admin@workconnect.test',
};

if (demoRole && demoEmails[demoRole]) {
    fillDemoCredentials(demoEmails[demoRole]);
    document.querySelector('.auth-card')?.classList.add('demo-prefilled');
}

const demoClearDialog = document.querySelector('#demo-clear-dialog');
document.querySelector('[data-open-demo-clear]')?.addEventListener('click', () => demoClearDialog?.showModal());
document.querySelectorAll('[data-close-demo-clear]').forEach((button) => button.addEventListener('click', () => demoClearDialog?.close()));
demoClearDialog?.addEventListener('click', (event) => {
    if (event.target === demoClearDialog) demoClearDialog.close();
});

document.querySelectorAll('.demo-install-form, #demo-clear-dialog form').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"]');
        if (!button) return;
        button.disabled = true;
        button.textContent = form.classList.contains('demo-install-form') ? 'Preparing demo...' : 'Clearing demo...';
    });
});

const tableFilterButtons = [...document.querySelectorAll('[data-table-filter]')];
const statusRows = [...document.querySelectorAll('[data-status-row]')];

tableFilterButtons.forEach((button) => button.addEventListener('click', () => {
    tableFilterButtons.forEach((item) => item.classList.remove('active'));
    button.classList.add('active');
    statusRows.forEach((row) => {
        row.hidden = button.dataset.tableFilter !== 'all' && row.dataset.statusRow !== button.dataset.tableFilter;
    });
}));

document.querySelectorAll('.responsive-table table').forEach((table) => {
    const body = table.querySelector('tbody');
    if (!body || body.children.length > 0) return;
    const columns = Math.max(1, table.querySelectorAll('thead th').length);
    const row = document.createElement('tr');
    row.className = 'generated-empty-row';
    row.innerHTML = `<td colspan="${columns}"><div><span>◇</span><strong>No records yet</strong><small>New activity will appear here automatically.</small></div></td>`;
    body.appendChild(row);
});

const preferenceForm = document.querySelector('[data-preference-form]');
const preferenceState = {
    theme: document.body.dataset.theme || 'light',
    language: document.body.dataset.language || 'en',
    textScale: document.body.dataset.textScale || 'medium',
    uiScale: document.body.dataset.uiScale || 'comfortable',
};
const realtimePreferenceKey = 'workconnect-realtime-enabled';

function storageGet(key) {
    try {
        return window.localStorage?.getItem(key) ?? null;
    } catch (error) {
        return null;
    }
}

function storageSet(key, value) {
    try {
        window.localStorage?.setItem(key, value);
    } catch (error) {
        // Storage can be unavailable in restricted browser contexts.
    }
}

function writePreferenceStorage() {
    storageSet('workconnect-theme', preferenceState.theme);
    storageSet('workconnect-language', preferenceState.language);
    storageSet('workconnect-text-scale', preferenceState.textScale);
    storageSet('workconnect-ui-scale', preferenceState.uiScale);
}

function isRealtimeEnabled() {
    const stored = storageGet(realtimePreferenceKey);
    return stored === null ? true : stored === '1';
}

function setRealtimePreference(enabled) {
    storageSet(realtimePreferenceKey, enabled ? '1' : '0');
    const input = document.querySelector('[data-realtime-setting]');
    if (input) input.checked = enabled;
    updateRealtimeToggleButton(enabled);
    if (enabled) startRealtime();
    else stopRealtime();
}

function themeToAppliedValue(theme) {
    if (theme !== 'auto') return theme;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function scaleToNumber(scale) {
    return ({ small: 0.95, medium: 1, large: 1.08, xl: 1.16 })[scale] || 1;
}

function uiToNumber(scale) {
    return ({ compact: 0.95, comfortable: 1, roomy: 1.06 })[scale] || 1;
}

const colorSchemeQuery = window.matchMedia('(prefers-color-scheme: dark)');

function applyPreferences(nextState) {
    Object.assign(preferenceState, nextState);
    document.body.classList.remove('theme-light', 'theme-dark', 'theme-auto', 'text-small', 'text-medium', 'text-large', 'text-xl', 'ui-compact', 'ui-comfortable', 'ui-roomy');
    document.body.classList.add(`theme-${themeToAppliedValue(preferenceState.theme)}`, `text-${preferenceState.textScale}`, `ui-${preferenceState.uiScale}`);
    document.body.dataset.theme = preferenceState.theme;
    document.body.dataset.language = preferenceState.language;
    document.body.dataset.textScale = preferenceState.textScale;
    document.body.dataset.uiScale = preferenceState.uiScale;
    document.documentElement.lang = preferenceState.language;
    document.body.style.setProperty('--text-scale', String(scaleToNumber(preferenceState.textScale)));
    document.body.style.setProperty('--ui-scale', String(uiToNumber(preferenceState.uiScale)));
    writePreferenceStorage();
}

colorSchemeQuery.addEventListener?.('change', () => {
    if (preferenceState.theme === 'auto') {
        applyPreferences(preferenceState);
    }
});

if (preferenceState.theme === 'auto') {
    applyPreferences(preferenceState);
}

if (document.body.dataset.preferenceSource === 'guest') {
    const storedTheme = storageGet('workconnect-theme');
    if (storedTheme) {
        applyPreferences({
            theme: storedTheme,
            language: storageGet('workconnect-language') || preferenceState.language,
            textScale: storageGet('workconnect-text-scale') || preferenceState.textScale,
            uiScale: storageGet('workconnect-ui-scale') || preferenceState.uiScale,
        });
    }
}

if (preferenceForm) {
    preferenceForm.querySelectorAll('[data-pref-input]').forEach((input) => {
        input.addEventListener('change', () => {
            const nextState = {
                theme: preferenceForm.querySelector('[name="theme"]')?.value || preferenceState.theme,
                language: preferenceForm.querySelector('[name="language"]')?.value || preferenceState.language,
                textScale: preferenceForm.querySelector('[name="text_scale"]')?.value || preferenceState.textScale,
                uiScale: preferenceForm.querySelector('[name="ui_scale"]')?.value || preferenceState.uiScale,
            };
            applyPreferences(nextState);
        });
    });
}

const realtimeSettingInput = document.querySelector('[data-realtime-setting]');
if (realtimeSettingInput) {
    realtimeSettingInput.checked = isRealtimeEnabled();
    realtimeSettingInput.addEventListener('change', () => setRealtimePreference(realtimeSettingInput.checked));
}

document.querySelectorAll('[data-topup-amount]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.querySelector('#topup-amount');
        if (!input) return;
        input.value = button.dataset.topupAmount || input.value;
        input.focus();
        button.animate?.([
            { transform: 'translateY(0) scale(1)' },
            { transform: 'translateY(-1px) scale(1.03)' },
            { transform: 'translateY(0) scale(1)' },
        ], { duration: 220, easing: 'ease-out' });
    });
});

const animateTargets = [...document.querySelectorAll('[data-animate]')];
if (animateTargets.length) {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reducedMotion || !('IntersectionObserver' in window)) {
        animateTargets.forEach((element) => element.classList.add('is-visible'));
    } else {
        const animateObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.14, rootMargin: '0px 0px -8% 0px' });
        animateTargets.forEach((element) => animateObserver.observe(element));
    }
}

const realtimeState = {
    notificationVersion: null,
    orderVersion: null,
    notificationCount: Number(document.body.dataset.unreadNotifications || 0),
    messageCount: Number(document.body.dataset.unreadMessages || 0),
    loading: false,
    source: null,
    fallbackTimer: null,
    fallbackStarted: false,
};

function updateRealtimeToggleButton(enabled = isRealtimeEnabled()) {
    const button = document.querySelector('[data-realtime-toggle]');
    if (!button) return;
    button.classList.toggle('is-off', !enabled);
    button.setAttribute('aria-label', enabled ? 'Turn realtime off' : 'Turn realtime on');
    button.title = enabled ? 'Realtime on' : 'Realtime off';
    button.textContent = enabled ? '↻' : '⏸';
}

function stopRealtime() {
    if (realtimeState.source) {
        try { realtimeState.source.close(); } catch (error) {}
        realtimeState.source = null;
    }
    if (realtimeState.fallbackTimer) {
        window.clearInterval(realtimeState.fallbackTimer);
        realtimeState.fallbackTimer = null;
    }
    realtimeState.fallbackStarted = false;
}

function formatBaht(value) {
    const amount = Number(value || 0);
    return `฿${Math.round(amount).toLocaleString('en-US')}`;
}

function applyRealtimeData(data) {
    const page = document.body.dataset.page;
    const nextNotificationCount = Number(data.notifications || 0);
    const nextMessageCount = Number(data.messages || 0);
    const notificationLink = document.querySelector('.top-icon');
    let notificationBadge = notificationLink?.querySelector('b') || null;
    if (notificationLink) {
        if (nextNotificationCount > 0) {
            if (!notificationBadge) {
                notificationBadge = document.createElement('b');
                notificationLink.appendChild(notificationBadge);
            }
            notificationBadge.hidden = false;
            notificationBadge.textContent = String(nextNotificationCount);
        } else if (notificationBadge) {
            notificationBadge.remove();
        }
    }
    document.querySelectorAll('.workspace-sidebar a').forEach((link) => {
        let badge = link.querySelector('b');
        if (!link.href.includes('messages')) return;
        if (nextMessageCount > 0) {
            if (!badge) {
                badge = document.createElement('b');
                link.appendChild(badge);
            }
            badge.hidden = false;
            badge.textContent = String(nextMessageCount);
        } else if (badge) {
            badge.remove();
        }
    });
    const walletValue = document.querySelector('.top-wallet strong, .topup-summary strong');
    if (walletValue && typeof data.wallet_balance !== 'undefined') {
        walletValue.textContent = formatBaht(data.wallet_balance);
    }
    if (page === 'topup') {
        const balanceValue = document.querySelector('.topup-summary strong');
        if (balanceValue && typeof data.wallet_balance !== 'undefined') {
            balanceValue.textContent = formatBaht(data.wallet_balance);
        }
    }
    if (realtimeState.notificationCount !== null && nextNotificationCount > realtimeState.notificationCount) {
        showToast({
            type: 'announcement',
            title: 'New notification',
            message: `You have ${nextNotificationCount.toLocaleString()} unread notification${nextNotificationCount === 1 ? '' : 's'}.`,
            actionLabel: 'Open notifications',
            actionHref: '?page=notifications',
        });
    }
    if (realtimeState.messageCount !== null && nextMessageCount > realtimeState.messageCount) {
        showToast({
            type: 'message',
            title: 'New message',
            message: `You have ${nextMessageCount.toLocaleString()} unread message${nextMessageCount === 1 ? '' : 's'}.`,
            actionLabel: 'Open messages',
            actionHref: '?page=messages',
        });
    }
    if (page === 'messages' || page === 'seller-messages') {
        if (realtimeState.orderVersion !== null && data.order_version !== realtimeState.orderVersion) {
            showToast({
                type: 'order',
                title: 'Conversation updated',
                message: 'Your order thread changed. Reloading to show the latest messages.',
            });
            window.setTimeout(() => window.location.reload(), 650);
            return;
        }
        realtimeState.orderVersion = data.order_version ?? null;
    }
    if (page === 'notifications' && realtimeState.notificationVersion !== null && data.notification_version !== realtimeState.notificationVersion) {
        showToast({
            type: 'announcement',
            title: 'Notification updated',
            message: 'Your notification list changed. Reloading to show the latest items.',
        });
        window.setTimeout(() => window.location.reload(), 650);
        return;
    }
    realtimeState.notificationVersion = data.notification_version ?? null;
    realtimeState.notificationCount = nextNotificationCount;
    realtimeState.messageCount = nextMessageCount;
}

async function pollRealtime() {
    if (realtimeState.loading || !document.body.dataset.page || document.visibilityState === 'hidden') return;
    const order = new URLSearchParams(window.location.search).get('order');
    const url = new URL(window.location.href);
    url.search = `?page=sync${order ? `&order=${encodeURIComponent(order)}` : ''}`;
    realtimeState.loading = true;
    try {
        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        applyRealtimeData(await response.json());
    } catch (error) {
        // Silent retry on the next interval.
    } finally {
        realtimeState.loading = false;
    }
}

function startRealtime() {
    const realtimePages = new Set(['dashboard', 'orders', 'messages', 'notifications', 'topup', 'seller-dashboard', 'seller-orders', 'seller-messages', 'admin-control', 'admin-orders', 'admin-messages', 'admin-finance']);
    updateRealtimeToggleButton();
    if (!isRealtimeEnabled()) return;
    if (document.body.dataset.preferenceSource === 'public' || !document.body.dataset.page || ['login', 'register'].includes(document.body.dataset.page) || !realtimePages.has(document.body.dataset.page)) return;
    stopRealtime();
    const order = new URLSearchParams(window.location.search).get('order');
    const url = new URL(window.location.href);
    url.search = `?page=stream${order ? `&order=${encodeURIComponent(order)}` : ''}`;
    if (window.EventSource) {
        const source = new EventSource(url.toString(), { withCredentials: true });
        realtimeState.source = source;
        source.onmessage = (event) => {
            try {
                applyRealtimeData(JSON.parse(event.data));
            } catch (error) {
                // Ignore malformed stream frames.
            }
        };
        source.onerror = () => {
            if (realtimeState.fallbackStarted) return;
            realtimeState.fallbackStarted = true;
            try { source.close(); } catch (error) {}
            pollRealtime();
            realtimeState.fallbackTimer = window.setInterval(pollRealtime, 15000);
        };
        return;
    }
    pollRealtime();
    realtimeState.fallbackTimer = window.setInterval(pollRealtime, 15000);
}

updateRealtimeToggleButton();
startRealtime();
