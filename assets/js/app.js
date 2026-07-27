document.documentElement.classList.add('js');

const menuToggle = document.querySelector('.menu-toggle');
const mainNav = document.querySelector('.main-nav');
const setMainNavigationState = (open) => {
    menuToggle?.setAttribute('aria-expanded', String(open));
    menuToggle?.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
};

menuToggle?.addEventListener('click', () => {
    const open = mainNav?.classList.toggle('open') ?? false;
    setMainNavigationState(open);
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
    if (document.body.classList.contains('auth-page')) {
        document.body.dataset.authRole = sellerSelected ? 'seller' : 'customer';
    }
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
const setWorkspaceNavigationState = (open) => {
    workspaceMenu?.setAttribute('aria-expanded', String(open));
    workspaceMenu?.setAttribute('aria-label', open ? 'Close workspace navigation' : 'Open workspace navigation');
};
const closeWorkspaceNavigation = () => {
    document.body.classList.remove('nav-open');
    setWorkspaceNavigationState(false);
};

workspaceMenu?.addEventListener('click', () => {
    const open = document.body.classList.toggle('nav-open');
    setWorkspaceNavigationState(open);
});
workspaceOverlay?.addEventListener('click', closeWorkspaceNavigation);
document.querySelectorAll('.workspace-sidebar a').forEach((link) => link.addEventListener('click', closeWorkspaceNavigation));
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeWorkspaceNavigation();
    mainNav?.classList.remove('open');
    setMainNavigationState(false);
});

const toastState = {
    stack: null,
    counter: 0,
};

function appleInterfaceIcon(name, className = 'wc-icon') {
    const paths = {
        success: '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.7 2.7 5.6-5.8"/>',
        error: '<path d="m12 3.3 7.2 3.3v5.2c0 4.6-2.9 7.3-7.2 8.9-4.3-1.6-7.2-4.3-7.2-8.9V6.6Z"/><path d="M12 8.2v4.5M12 16h.01"/>',
        warning: '<path d="M12 3.8 21 20H3Z"/><path d="M12 9v4.5M12 16.6h.01"/>',
        account: '<circle cx="12" cy="8.1" r="4.1"/><path d="M4.8 20a7.2 7.2 0 0 1 14.4 0"/>',
        order: '<path d="M5.1 8.2h13.8l-1 11.1H6.1Z"/><path d="M8.6 9V7.1a3.4 3.4 0 0 1 6.8 0V9"/>',
        message: '<path d="M4.2 5.6h15.6v11H10l-5.8 3.2Z"/><path d="M8 9.2h8M8 13h5.2"/>',
        payment: '<path d="M4.2 6.8h14.1a1.7 1.7 0 0 1 1.7 1.7v9.2H5.9A1.9 1.9 0 0 1 4 15.8V6.2a2 2 0 0 1 2-2h11.2"/><path d="M15.1 10.5H20v4.3h-4.9a2.2 2.2 0 1 1 0-4.3Z"/>',
        review: '<path d="m12 3.8 2.5 5 5.5.8-4 3.9.9 5.5-4.9-2.6L7.1 19l.9-5.5-4-3.9 5.5-.8Z"/>',
        announcement: '<path d="m4.2 10 11.1-4.8v13.6L4.2 14Z"/><path d="M4.2 10v4M8 15.7l1 4"/><path d="M18 8.2a5.2 5.2 0 0 1 0 7.6"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 10.7v5.7M12 7.5h.01"/>',
        close: '<path d="m6.2 6.2 11.6 11.6M17.8 6.2 6.2 17.8"/>',
    };
    const body = paths[name] || paths.info;
    return `<svg class="${className}" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round">${body}</svg>`;
}

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
    if (document.body.classList.contains('apple-shell')) {
        icon.innerHTML = appleInterfaceIcon(type);
    } else {
        icon.textContent = meta.icon;
    }

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
    if (document.body.classList.contains('apple-shell')) {
        closeButton.innerHTML = appleInterfaceIcon('close');
    } else {
        closeButton.textContent = '×';
    }

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
    [email, demoPassword].forEach((input) => input.dispatchEvent(new Event('input', { bubbles: true })));
    const card = document.querySelector('.auth-card');
    card?.classList.remove('demo-prefilled');
    window.requestAnimationFrame(() => card?.classList.add('demo-prefilled'));
    window.setTimeout(() => card?.classList.remove('demo-prefilled'), 900);
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
    if (!table.querySelector('caption')) {
        const heading = table.closest('section')?.querySelector('h1, h2, h3')?.textContent?.trim();
        const caption = document.createElement('caption');
        caption.className = 'sr-only';
        caption.textContent = heading || 'WorkConnect records';
        table.prepend(caption);
    }
    const body = table.querySelector('tbody');
    if (!body || body.children.length > 0) return;
    const columns = Math.max(1, table.querySelectorAll('thead th').length);
    const row = document.createElement('tr');
    row.className = 'generated-empty-row';
    const emptyIcon = document.body.classList.contains('apple-shell') ? appleInterfaceIcon('info') : '◇';
    row.innerHTML = `<td colspan="${columns}"><div><span>${emptyIcon}</span><strong>No records yet</strong><small>New activity will appear here automatically.</small></div></td>`;
    body.appendChild(row);
});

document.querySelectorAll('[data-auto-submit]').forEach((input) => {
    input.addEventListener('change', () => input.form?.requestSubmit());
});

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm || 'Continue?')) event.preventDefault();
    });
});

document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented || form.dataset.submitting === '1') {
            if (form.dataset.submitting === '1') event.preventDefault();
            return;
        }
        if (!form.checkValidity()) return;
        form.dataset.submitting = '1';
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((control) => {
            control.disabled = true;
            control.setAttribute('aria-disabled', 'true');
        });
    });
});

const preferenceForm = document.querySelector('[data-preference-form]');
const preferenceState = {
    theme: document.body.dataset.theme || 'light',
    language: document.body.dataset.language || 'en',
    textScale: document.body.dataset.textScale || 'medium',
    uiScale: document.body.dataset.uiScale || 'comfortable',
};

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
    fallbackTimer: null,
};

function stopRealtime() {
    if (realtimeState.fallbackTimer) {
        window.clearInterval(realtimeState.fallbackTimer);
        realtimeState.fallbackTimer = null;
    }
}

function formatBaht(value) {
    const amount = Number(value || 0);
    const fractionDigits = Number.isInteger(amount) ? 0 : 2;
    return `฿${amount.toLocaleString('en-US', {
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: 2,
    })}`;
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
    if (typeof data.wallet_balance !== 'undefined') {
        document.querySelectorAll('[data-wallet-balance]').forEach((walletValue) => {
            walletValue.textContent = formatBaht(data.wallet_balance);
        });
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
    if (realtimeState.loading || !document.body.dataset.page) return;
    const order = new URLSearchParams(window.location.search).get('order');
    const url = new URL(window.location.href);
    url.search = `?page=sync${order ? `&order=${encodeURIComponent(order)}` : ''}`;
    realtimeState.loading = true;
    try {
        const response = await fetch(url.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) return;
        applyRealtimeData(await response.json());
    } catch (error) {
        // Silent retry on the next interval.
    } finally {
        realtimeState.loading = false;
    }
}

function startRealtime() {
    if (document.body.dataset.preferenceSource === 'public' || !document.body.dataset.page || ['login', 'register'].includes(document.body.dataset.page)) return;
    stopRealtime();
    pollRealtime();
    realtimeState.fallbackTimer = window.setInterval(pollRealtime, 20000);
}

startRealtime();
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') pollRealtime();
});
window.addEventListener('focus', () => {
    pollRealtime();
});
