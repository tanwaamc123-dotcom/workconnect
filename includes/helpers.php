<?php

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pick_value(string $value, array $allowed, string $default): string
{
    return in_array($value, $allowed, true) ? $value : $default;
}

function is_internal_path(string $value): bool
{
    $value = trim($value);
    if ($value === '' || preg_match('/[\r\n]/', $value)) {
        return false;
    }
    if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $value)) {
        return false;
    }
    if (str_starts_with($value, '//')) {
        return false;
    }
    return str_starts_with($value, '?') || str_starts_with($value, '/');
}

function safe_return_to(?string $value, string $fallback = '?page=home'): string
{
    $candidate = trim((string) $value);
    return is_internal_path($candidate) ? $candidate : $fallback;
}

function redirect_back(string $fallback = '?page=home'): never
{
    redirect(safe_return_to((string) ($_POST['return_to'] ?? ''), $fallback));
}

function demo_mode_enabled(): bool
{
    return system_setting('demo_mode', '0') === '1';
}

function maintenance_mode_enabled(): bool
{
    return system_setting('maintenance_mode', '0') === '1';
}

function demo_management_allowed(?array $user = null): bool
{
    $user ??= current_user();
    return demo_mode_enabled() && !empty($user) && ($user['role'] ?? '') === 'admin';
}

function slugify_text(string $value): string
{
    $value = trim(mb_strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? $value;
    $value = trim($value, '-');
    return $value !== '' ? $value : 'item';
}

function service_cover_path(array $service): string
{
    $thumbnail = trim((string) ($service['thumbnail'] ?? ''));
    if ($thumbnail !== '' && $thumbnail !== 'website') {
        if (is_upload_reference($thumbnail)) return upload_url($thumbnail);
        if (str_starts_with($thumbnail, 'assets/') && is_file(dirname(__DIR__) . '/' . $thumbnail)) return $thumbnail;
        $legacy = dirname(__DIR__) . '/assets/images/services/titles/' . slugify_text((string) ($service['title'] ?? '')) . '.jpg';
        if (is_file($legacy)) return 'assets/images/services/titles/' . basename($legacy);
    }

    $query = http_build_query([
        'title' => (string) ($service['title'] ?? 'Creative Service'),
        'category' => (string) ($service['category'] ?? 'WorkConnect'),
        'seller' => (string) ($service['seller'] ?? 'Verified Seller'),
    ]);

    return 'assets/service-cover.php?' . $query;
}

function ui_preferences(?array $user = null): array
{
    $defaults = [
        'theme' => default_theme_setting('light'),
        'language' => default_language_setting('en'),
        'text_scale' => default_text_scale_setting('medium'),
        'ui_scale' => default_ui_scale_setting('comfortable'),
    ];
    if (!$user) {
        $defaults['theme'] = 'light';
        return $defaults;
    }
    return [
        'theme' => pick_value((string) ($user['theme'] ?? 'light'), ['light', 'dark', 'auto'], 'light'),
        'language' => pick_value((string) ($user['language'] ?? 'en'), ['en', 'th'], 'en'),
        'text_scale' => pick_value((string) ($user['text_scale'] ?? 'medium'), ['small', 'medium', 'large', 'xl'], 'medium'),
        'ui_scale' => pick_value((string) ($user['ui_scale'] ?? 'comfortable'), ['compact', 'comfortable', 'roomy'], 'comfortable'),
    ];
}

function admin_ui_preferences(?array $user = null): array
{
    $prefs = ui_preferences($user);
    if (($user['role'] ?? '') !== 'admin') {
        return $prefs;
    }
    if (isset($_SESSION['admin_ui_theme'])) {
        $prefs['theme'] = pick_value((string) $_SESSION['admin_ui_theme'], ['light', 'dark', 'auto'], $prefs['theme']);
    }
    if (isset($_SESSION['admin_ui_language'])) {
        $prefs['language'] = pick_value((string) $_SESSION['admin_ui_language'], ['en', 'th'], $prefs['language']);
    }
    if (isset($_SESSION['admin_ui_ui_scale'])) {
        $prefs['ui_scale'] = pick_value((string) $_SESSION['admin_ui_ui_scale'], ['compact', 'comfortable', 'roomy'], $prefs['ui_scale']);
    }
    return $prefs;
}

function ui_theme(?array $user = null): string
{
    return ui_preferences($user)['theme'];
}

function admin_ui_theme(?array $user = null): string
{
    return admin_ui_preferences($user)['theme'];
}

function ui_language(?array $user = null): string
{
    return ui_preferences($user)['language'];
}

function admin_ui_language(?array $user = null): string
{
    return admin_ui_preferences($user)['language'];
}

function ui_text_scale(?array $user = null): string
{
    return ui_preferences($user)['text_scale'];
}

function ui_density(?array $user = null): string
{
    return ui_preferences($user)['ui_scale'];
}

function activity_heatmap(string $scope, int $userId, int $days = 365): array
{
    $days = max(28, $days);
    $start = (new DateTimeImmutable('today'))->sub(new DateInterval('P' . ($days - 1) . 'D'))->format('Y-m-d 00:00:00');
    $end = (new DateTimeImmutable('tomorrow'))->format('Y-m-d 00:00:00');
    $rows = [];
    $params = [];

    if ($scope === 'seller') {
        $sql = "
            SELECT date(created_at) AS day, COUNT(*) AS count FROM (
                SELECT created_at FROM orders WHERE seller_id=?
                UNION ALL SELECT created_at FROM messages WHERE sender_id=? OR receiver_id=?
                UNION ALL SELECT created_at FROM reviews WHERE seller_id=?
                UNION ALL SELECT created_at FROM services WHERE seller_id=?
                UNION ALL SELECT created_at FROM notifications WHERE user_id=?
            ) events
            WHERE created_at >= ? AND created_at < ?
            GROUP BY day
        ";
        $params = [$userId, $userId, $userId, $userId, $userId, $userId, $start, $end];
    } elseif ($scope === 'admin') {
        $sql = "
            SELECT date(created_at) AS day, COUNT(*) AS count FROM (
                SELECT created_at FROM orders
                UNION ALL SELECT created_at FROM messages
                UNION ALL SELECT created_at FROM reviews
                UNION ALL SELECT created_at FROM notifications
                UNION ALL SELECT created_at FROM security_logs
                UNION ALL SELECT created_at FROM users
            ) events
            WHERE created_at >= ? AND created_at < ?
            GROUP BY day
        ";
        $params = [$start, $end];
    } elseif ($scope === 'public') {
        $sql = "
            SELECT date(created_at) AS day, COUNT(*) AS count FROM (
                SELECT created_at FROM users WHERE status='active'
                UNION ALL SELECT created_at FROM services WHERE status='active'
                UNION ALL SELECT created_at FROM orders
                UNION ALL SELECT created_at FROM reviews
            ) events
            WHERE created_at >= ? AND created_at < ?
            GROUP BY day
        ";
        $params = [$start, $end];
    } else {
        $sql = "
            SELECT date(created_at) AS day, COUNT(*) AS count FROM (
                SELECT created_at FROM orders WHERE customer_id=?
                UNION ALL SELECT created_at FROM messages WHERE sender_id=? OR receiver_id=?
                UNION ALL SELECT created_at FROM notifications WHERE user_id=?
                UNION ALL SELECT created_at FROM wallet_transactions WHERE user_id=?
                UNION ALL SELECT created_at FROM reviews WHERE customer_id=?
            ) events
            WHERE created_at >= ? AND created_at < ?
            GROUP BY day
        ";
        $params = [$userId, $userId, $userId, $userId, $userId, $userId, $start, $end];
    }

    foreach (fetch_all($sql, $params) as $row) {
        $rows[$row['day']] = (int) $row['count'];
    }

    $cells = [];
    $date = new DateTimeImmutable($start);
    for ($i = 0; $i < $days; $i++) {
        $day = $date->format('Y-m-d');
        $count = $rows[$day] ?? 0;
        $level = $count === 0 ? 0 : min(4, (int) floor(log($count + 1, 2)));
        $cells[] = ['date' => $day, 'count' => $count, 'level' => $level];
        $date = $date->modify('+1 day');
    }

    return $cells;
}

function activity_summary(array $cells): array
{
    $total = array_sum(array_column($cells, 'count'));
    $activeDays = count(array_filter($cells, fn($cell) => $cell['count'] > 0));
    return ['total' => $total, 'active_days' => $activeDays];
}

function activity_panel_html(string $scope, int $userId, string $lang): string
{
    $cells = activity_heatmap($scope, $userId);
    $summary = activity_summary($cells);
    $weeks = array_chunk($cells, 7);
    foreach ($weeks as &$week) {
        while (count($week) < 7) {
            $week[] = null;
        }
    }
    unset($week);

    $monthLabels = [];
    $lastMonth = '';
    foreach ($weeks as $week) {
        $month = (new DateTimeImmutable($week[0]['date']))->format('M');
        $monthLabels[] = $month !== $lastMonth ? $month : '';
        $lastMonth = $month;
    }

    ob_start(); ?>
<section class="data-panel activity-panel">
    <div class="panel-title activity-panel-title">
        <div>
            <h2><?= e(t('activity_title', $lang)) ?></h2>
            <p><?= e(t('activity_desc', $lang)) ?></p>
        </div>
        <strong><?= e(t('activity_total', $lang, ['count' => number_format((int) $summary['total']), 'days' => number_format((int) $summary['active_days'])])) ?></strong>
    </div>
    <div class="activity-panel-body">
        <div class="activity-scroll">
            <div class="activity-months" aria-hidden="true">
                <span></span>
                <?php foreach ($monthLabels as $label): ?>
                    <span><?= e($label) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="activity-heatmap">
                <div class="activity-axis" aria-hidden="true">
                    <span>Sun</span>
                    <span>Mon</span>
                    <span>Tue</span>
                    <span>Wed</span>
                    <span>Thu</span>
                    <span>Fri</span>
                    <span>Sat</span>
                </div>
                <div class="activity-grid" aria-label="<?= e(t('activity_title', $lang)) ?>">
                    <?php foreach ($weeks as $week): ?>
                        <div class="activity-week">
                            <?php foreach ($week as $cell): ?>
                                <?php if ($cell === null): ?>
                                    <span class="activity-day is-empty" aria-hidden="true"></span>
                                <?php else: ?>
                                    <span class="activity-day level-<?= (int) $cell['level'] ?>" title="<?= e(short_date($cell['date'])) ?> · <?= e((string) $cell['count']) ?>"></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="activity-foot">
            <span class="activity-legend">
                <i class="level-0"></i>
                <i class="level-1"></i>
                <i class="level-2"></i>
                <i class="level-3"></i>
                <i class="level-4"></i>
                <small><?= e(t('activity_scale_low', $lang)) ?></small>
                <small><?= e(t('activity_scale_high', $lang)) ?></small>
            </span>
            <small><?= $summary['total'] > 0 ? e(t('activity_desc', $lang)) : e(t('activity_empty', $lang)) ?></small>
        </div>
    </div>
</section>
<?php
    return (string) ob_get_clean();
}

function interface_icon_markup(string $icon): string
{
    $map = [
        '◇' => 'info',
        '⌕' => 'search',
        '◎' => 'analytics',
        '◌' => 'analytics',
        '●' => 'users',
        '✓' => 'moderation',
        '▣' => 'orders',
        '▤' => 'categories',
        '฿' => 'wallet',
        '⌁' => 'topup',
        '⌛' => 'logs',
        '×' => 'logs',
        '!' => 'notifications',
        '★' => 'analytics',
        '↗' => 'orders',
        '♥' => 'saved',
        '○' => 'info',
    ];

    $iconName = $map[$icon] ?? $icon;
    return icon_svg($iconName);
}

function empty_state_html(string $title, string $description, string $actionLabel = '', string $actionHref = '', string $icon = '◇'): string
{
    ob_start(); ?>
<div class="empty-state empty-card">
    <span class="empty-state-icon" aria-hidden="true"><?= interface_icon_markup($icon) ?></span>
    <div>
        <strong><?= e($title) ?></strong>
        <p><?= e($description) ?></p>
    </div>
    <?php if ($actionLabel !== '' && $actionHref !== ''): ?>
        <a class="button button-light" href="<?= e($actionHref) ?>"><?= e($actionLabel) ?></a>
    <?php endif; ?>
</div>
<?php
    return (string) ob_get_clean();
}

function icon_svg(string $name): string
{
    $icons = [
        'home' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 11.5 12 4l8 7.5V20H4Z"/><path d="M9 20v-7h6v7"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16.2 16.2 4.3 4.3"/></svg>',
        'saved' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4.5h12v16l-6-3.8L6 20.5Z"/></svg>',
        'orders' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="2"/><path d="M8 9h8M8 13h8"/><path d="M9 5v4M15 5v4"/></svg>',
        'messages' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6.5h12a4 4 0 0 1 4 4v2a4 4 0 0 1-4 4H11l-5.5 4v-4H6a4 4 0 0 1-4-4v-2a4 4 0 0 1 4-4Z"/></svg>',
        'notifications' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 17h12l-1.3-1.8c-.4-.6-.7-1.3-.7-2V10a4 4 0 1 0-8 0v3.2c0 .7-.2 1.4-.7 2Z"/><path d="M10 18a2 2 0 0 0 4 0"/></svg>',
        'wallet' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h14a2 2 0 0 1 2 2v8H6a2 2 0 0 1-2-2Z"/><path d="M6 7V6a2 2 0 0 1 2-2h10"/><circle cx="16" cy="13" r="1"/></svg>',
        'profile' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12.2a4.2 4.2 0 1 0 0-8.4 4.2 4.2 0 0 0 0 8.4Z"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 4.5h3l.5 2a6.2 6.2 0 0 1 1.5.8l1.9-1 2.1 2.1-1 1.9c.3.5.6 1 .8 1.5l2 .5v3l-2 .5a6.2 6.2 0 0 1-.8 1.5l1 1.9-2.1 2.1-1.9-1c-.5.3-1 .6-1.5.8l-.5 2h-3l-.5-2a6.2 6.2 0 0 1-1.5-.8l-1.9 1-2.1-2.1 1-1.9a6.2 6.2 0 0 1-.8-1.5l-2-.5v-3l2-.5c.2-.5.5-1 .8-1.5l-1-1.9L7.5 6.3l1.9 1c.5-.3 1-.6 1.5-.8Z"/><circle cx="12" cy="12" r="3.2"/></svg>',
        'about' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7" r="1.25"/><path d="M12 11v7"/><path d="M7.5 20h9"/></svg>',
        'add' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
        'analytics' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19V5"/><path d="M5 19h14"/><path d="M8 15.5v-4"/><path d="M12 15.5V8"/><path d="M16 15.5v-6"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h12v16H6z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM16.5 10a2.5 2.5 0 1 0 0-5"/><path d="M4.5 19a5.5 5.5 0 0 1 11 0"/><path d="M16.2 19a4.7 4.7 0 0 1 3.3-4.5"/></svg>',
        'categories' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6.5h7l2 2h7v9.5H4z"/><path d="M7 12h10"/></svg>',
        'coupon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 7.5h15v3a2.5 2.5 0 1 0 0 5v3h-15v-3a2.5 2.5 0 1 0 0-5z"/><path d="M9 10.5h6M9 13.5h6"/></svg>',
        'logs' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4.5h8l3.5 3.5v11.5H8z"/><path d="M11 10.5h5M11 14h5M11 17.5h3"/><path d="M16 4.5V8h3.5"/></svg>',
        'broadcast' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 15V9l9-4v14z"/><path d="M5 15h2"/><path d="M17 8a4 4 0 0 1 0 8"/><path d="M19.5 6.5a7 7 0 0 1 0 11"/></svg>',
        'time' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v5l3 2"/></svg>',
        'export' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11"/><path d="m8 9 4-4 4 4"/><path d="M5 16v3h14v-3"/></svg>',
        'moderation' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 4.5 7v5.5c0 4.7 3.1 6.9 7.5 8 4.4-1.1 7.5-3.3 7.5-8V7z"/><path d="M9.5 12l1.8 1.8L14.8 10"/></svg>',
        'topup' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v16M7.5 8.5A4 4 0 0 1 12 6.5c2.2 0 4 .9 4 2.8 0 4-8.5 2.6-8.5 6.2 0 1.9 1.8 2.8 4 2.8a5 5 0 0 0 4.5-2.2"/></svg>',
        'info' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 10.5v6"/><path d="M12 7.5h.01"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5"/><path d="M14 9l4 3-4 3"/><path d="M18 12H8"/></svg>',
    ];
    return $icons[$name] ?? $icons['info'];
}

function onboarding_checklist_html(string $role, ?array $user, string $lang): string
{
    $items = [];
    if ($role === 'seller') {
        $services = (int) scalar('SELECT COUNT(*) FROM services WHERE seller_id=?', [(int) $user['id']]);
        $orders = (int) scalar('SELECT COUNT(*) FROM orders WHERE seller_id=?', [(int) $user['id']]);
        $items = [
            ['label' => t('onboarding_profile', $lang), 'done' => trim((string) ($user['bio'] ?? '')) !== ''],
            ['label' => t('onboarding_approval', $lang), 'done' => ($user['status'] ?? '') === 'active'],
            ['label' => t('onboarding_service', $lang), 'done' => $services > 0],
            ['label' => t('onboarding_orders', $lang), 'done' => $orders > 0],
        ];
    } elseif ($role === 'admin') {
        $pendingSellers = (int) scalar('SELECT COUNT(*) FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name="seller" AND users.status="pending_approval"');
        $items = [
            ['label' => t('onboarding_platform', $lang), 'done' => true],
            ['label' => t('onboarding_approval_queue', $lang), 'done' => $pendingSellers === 0],
            ['label' => t('onboarding_reports', $lang), 'done' => true],
            ['label' => t('onboarding_activity', $lang), 'done' => true],
        ];
    } else {
        $hasOrders = (int) scalar('SELECT COUNT(*) FROM orders WHERE customer_id=?', [(int) $user['id']]);
        $hasNotifications = (int) scalar('SELECT COUNT(*) FROM notifications WHERE user_id=?', [(int) $user['id']]);
        $items = [
            ['label' => t('onboarding_profile', $lang), 'done' => trim((string) ($user['bio'] ?? '')) !== ''],
            ['label' => t('onboarding_explore', $lang), 'done' => true],
            ['label' => t('onboarding_first_order', $lang), 'done' => $hasOrders > 0],
            ['label' => t('onboarding_notifications', $lang), 'done' => $hasNotifications > 0],
        ];
    }

    $doneCount = count(array_filter($items, fn($item) => $item['done']));
    ob_start(); ?>
<section class="data-panel onboarding-panel">
    <div class="panel-title">
        <div>
            <h2><?= e(t('onboarding_title', $lang)) ?></h2>
            <p><?= e(t('onboarding_desc', $lang)) ?></p>
        </div>
        <strong><?= $doneCount ?>/<?= count($items) ?></strong>
    </div>
    <div class="onboarding-checklist">
        <?php foreach ($items as $item): ?>
            <article class="<?= $item['done'] ? 'done' : '' ?>">
                <span><?= $item['done'] ? '✓' : '○' ?></span>
                <strong><?= e($item['label']) ?></strong>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php
    return (string) ob_get_clean();
}

function order_timeline_html(string $status, string $lang): string
{
    $steps = [
        'pending' => t('timeline_pending', $lang),
        'in_progress' => t('timeline_in_progress', $lang),
        'review' => t('timeline_review', $lang),
        'completed' => t('timeline_completed', $lang),
    ];
    $order = ['pending', 'in_progress', 'review', 'completed'];
    $currentIndex = array_search($status, $order, true);
    if ($currentIndex === false) {
        $currentIndex = 0;
    }

    ob_start(); ?>
<div class="order-timeline" aria-label="<?= e(t('timeline_title', $lang)) ?>">
    <?php foreach ($order as $index => $step): ?>
        <span class="<?= $index <= $currentIndex ? 'active' : '' ?>">
            <i></i>
            <small><?= e($steps[$step]) ?></small>
        </span>
    <?php endforeach; ?>
</div>
<?php
    return (string) ob_get_clean();
}

function favorite_ids(int $userId): array
{
    return array_map('intval', array_column(fetch_all('SELECT service_id FROM favorites WHERE user_id=?', [$userId]), 'service_id'));
}

function is_favorite_service(int $userId, int $serviceId): bool
{
    return (bool) scalar('SELECT COUNT(*) FROM favorites WHERE user_id=? AND service_id=?', [$userId, $serviceId]);
}

function t(string $key, ?string $language = null, array $replace = []): string
{
    static $dictionary = [
        'en' => [
            'nav_home' => 'Home',
            'nav_services' => 'Services',
            'nav_about' => 'About',
            'nav_workspace' => 'Workspace',
            'nav_how' => 'How it works',
            'nav_contact' => 'Contact',
            'nav_updates' => 'Notifications',
            'nav_login' => 'Log in',
            'nav_signup' => 'Sign up',
            'nav_open_workspace' => 'Open workspace',
            'nav_topup' => 'Top up',
            'group_workspace' => 'Workspace',
            'group_account' => 'Account',
            'group_business' => 'Business',
            'group_management' => 'Management',
            'group_system' => 'System',
            'group_marketplace' => 'Marketplace',
            'side_overview' => 'Overview',
            'side_find_services' => 'Find services',
            'side_orders' => 'Orders',
            'side_messages' => 'Messages',
            'side_notifications' => 'Notifications',
            'side_profile' => 'Profile',
            'side_settings' => 'Settings',
            'side_about' => 'About WorkConnect',
            'side_topup' => 'Top up',
            'side_earnings' => 'Earnings',
            'side_analytics' => 'Analytics',
            'side_add_service' => 'Add service',
            'side_manage_orders' => 'Manage orders',
            'side_users' => 'Users',
            'side_services' => 'Services',
            'side_reports' => 'Reports',
            'side_finance' => 'Finance',
            'side_control_center' => 'Control center',
            'side_approvals' => 'Approvals',
            'side_moderation' => 'Moderation',
            'side_categories' => 'Categories',
            'side_coupons' => 'Coupons',
            'side_logs' => 'Logs',
            'side_broadcast' => 'Broadcast',
            'side_export' => 'Export',
            'side_system_settings' => 'System settings',
            'hero_eyebrow' => 'Connect. Collaborate. Succeed.',
            'hero_title' => "Find the right talent.\nGet the work done.",
            'hero_description' => 'WorkConnect brings clients and skilled freelancers together for reliable digital services, all in one place.',
            'hero_search' => 'What service are you looking for?',
            'home_start_title' => 'Start here',
            'home_start_desc' => 'Pick the path that matches what you want to do next.',
            'home_start_customer_title' => 'Hire a specialist',
            'home_start_customer_desc' => 'Browse services, compare options, and place an order.',
            'home_start_seller_title' => 'Offer your services',
            'home_start_seller_desc' => 'Apply, get approved, then publish services.',
            'home_start_admin_title' => 'Manage the platform',
            'home_start_admin_desc' => 'Review users, services, and system settings.',
            'onboarding_title' => 'Getting started',
            'onboarding_desc' => 'Complete the essentials that make the workspace feel finished.',
            'onboarding_profile' => 'Complete your profile',
            'onboarding_approval' => 'Finish approval',
            'onboarding_service' => 'Publish a service',
            'onboarding_orders' => 'Handle your orders',
            'onboarding_platform' => 'Set up the platform',
            'onboarding_approval_queue' => 'Review approval queue',
            'onboarding_reports' => 'Open reports',
            'onboarding_activity' => 'Monitor activity',
            'onboarding_explore' => 'Explore services',
            'onboarding_first_order' => 'Place your first order',
            'onboarding_notifications' => 'Turn on notifications',
            'timeline_title' => 'Order timeline',
            'timeline_pending' => 'Placed',
            'timeline_in_progress' => 'In progress',
            'timeline_review' => 'Review',
            'timeline_completed' => 'Done',
            'search_title' => 'Quick find',
            'search_desc' => 'Search services, orders, messages, and users from one place.',
            'search_placeholder' => 'Search WorkConnect',
            'search_all' => 'All',
            'search_services' => 'Services',
            'search_orders' => 'Orders',
            'search_messages' => 'Messages',
            'search_users' => 'Users',
            'search_no_results' => 'No matches found.',
            'saved_services_title' => 'Saved services',
            'saved_services_desc' => 'Keep the services you want to revisit later.',
            'saved_services_empty' => 'Nothing saved yet.',
            'favorite_save' => 'Save',
            'favorite_saved' => 'Saved',
            'favorite_hint' => 'Saved to your list',
            'trust_verified' => 'Verified',
            'trust_orders_completed' => 'Completed orders',
            'trust_reviews' => 'Reviews',
            'settings_title' => 'Settings',
            'settings_description' => 'Control appearance, language, notifications, and your account security.',
            'settings_appearance' => 'Appearance',
            'settings_theme' => 'Theme',
            'settings_language' => 'Language',
            'settings_text_size' => 'Text size',
            'settings_ui_size' => 'UI size',
            'settings_email_notifications' => 'Account updates',
            'settings_email_notifications_desc' => 'In-app updates for orders, messages, payments, and security',
            'settings_realtime' => 'Realtime sync',
            'settings_realtime_desc' => 'Keep notifications and project updates live while you are inside WorkConnect.',
            'register_role_customer' => 'Service user',
            'register_role_seller' => 'Offer services',
            'register_role_admin' => 'Admin access',
            'register_admin_code' => 'Admin code',
            'register_admin_code_desc' => 'Required only for admin accounts.',
            'register_seller_note' => 'Seller accounts wait for admin approval before they can access the seller workspace.',
            'seller_pending_title' => 'Seller account pending approval',
            'seller_pending_desc' => 'Your profile was created successfully. An admin must approve seller access before you can open the seller workspace.',
            'seller_pending_hint' => 'You can log out now or wait here while approval is reviewed.',
            'seller_pending_logout' => 'Log out',
            'settings_save_preferences' => 'Save preferences',
            'settings_password' => 'Change password',
            'settings_password_desc' => 'Use at least eight characters and avoid reusing an old password.',
            'settings_update_password' => 'Update password',
            'settings_current_password' => 'Current password',
            'settings_new_password' => 'New password',
            'mark_all_read' => 'Mark all read',
            'notifications_all' => 'All notifications',
            'notifications_unread' => 'Unread only',
            'notifications_mark_read' => 'Mark read',
            'notifications_mark_unread' => 'Mark unread',
            'notifications_empty' => 'You are all caught up.',
            'project_progress_title' => 'Project progress',
            'project_progress_desc' => 'Quick view of where each active order stands.',
            'project_progress_overview' => 'Progress overview',
            'project_progress_label' => 'Progress',
            'chat_seen' => 'Seen',
            'chat_sent' => 'Sent',
            'settings_theme_light' => 'Light',
            'settings_theme_dark' => 'Dark',
            'settings_theme_auto' => 'System',
            'settings_text_small' => 'Small',
            'settings_text_medium' => 'Default',
            'settings_text_large' => 'Large',
            'settings_text_xl' => 'Extra large',
            'settings_ui_compact' => 'Compact',
            'settings_ui_comfortable' => 'Comfortable',
            'settings_ui_roomy' => 'Roomy',
            'topup_title' => 'Top up wallet',
            'topup_description' => 'Enter your own amount from 50 THB and pay on Stripe PromptPay to credit your wallet automatically.',
            'topup_balance' => 'Wallet balance',
            'topup_amount' => 'Top up amount (THB)',
            'topup_method' => 'Payment method',
            'topup_note' => 'Note',
            'topup_submit' => 'Add funds',
            'topup_recent' => 'Recent top ups',
            'topup_empty' => 'No wallet transactions yet.',
            'topup_success' => 'PromptPay payment started. Your wallet updates automatically after confirmation.',
            'realtime_label' => 'Live sync',
            'realtime_desc' => 'Notifications and order updates refresh automatically.',
            'search_button' => 'Search',
            'real_accounts' => '{count} real {label}',
            'marketplace_clean_ready' => 'A clean marketplace, ready for its first service.',
            'demo_status_active' => 'Demo environment active',
            'demo_status_clean' => 'Clean database',
            'demo_status_active_desc' => 'Synthetic records are isolated from real data.',
            'demo_status_clean_desc' => 'No sample marketplace data is currently installed.',
            'demo_preview_active_title' => 'Explore every side of WorkConnect.',
            'demo_preview_clean_title' => 'See the complete product in one click.',
            'demo_preview_active_desc' => 'Customer, Seller, and Admin workspaces are ready. All demo accounts use the same clearly labeled password.',
            'demo_preview_clean_desc' => 'Install a small, connected dataset with users, services, orders, messages, payments, and reviews. Remove it later without touching real accounts.',
            'demo_users' => 'Demo users',
            'demo_services' => 'Services',
            'demo_orders' => 'Orders',
            'demo_open_customer' => 'Open Customer',
            'demo_open_seller' => 'Open Seller',
            'demo_open_admin' => 'Open Admin',
            'demo_clear' => 'Clear demo',
            'demo_install_data' => 'Install demo data',
            'demo_install_note' => 'Real accounts and uploads remain untouched.',
            'demo_clear_title' => 'Clear demo data?',
            'demo_clear_desc' => 'This removes only records labeled as Demo, including demo users, services, orders, messages, and payments. Real data stays in place.',
            'demo_clear_confirm' => 'Clear demo data',
            'demo_clear_keep' => 'Keep demo',
            'category_kicker' => 'Explore by category',
            'category_title' => 'Everything your project needs',
            'view_all_services' => 'View all services',
            'how_kicker' => 'Simple from start to finish',
            'how_title' => 'How WorkConnect works',
            'how_description' => 'Four clear steps, with communication and progress visible along the way.',
            'step_search_title' => 'Search',
            'step_search_desc' => 'Find a service that fits your goal and budget.',
            'step_order_title' => 'Place an order',
            'step_order_desc' => 'Share requirements and confirm your order.',
            'step_collaborate_title' => 'Collaborate',
            'step_collaborate_desc' => 'Chat, exchange files, and track progress.',
            'step_done_title' => 'Get it done',
            'step_done_desc' => 'Review the result and complete the order.',
            'popular_chosen_kicker' => 'Chosen by clients',
            'popular_marketplace_kicker' => 'Marketplace',
            'popular_title' => 'Popular services',
            'latest_title' => 'Latest services',
            'browse_all' => 'Browse all',
            'home_no_services_title' => 'No services published yet',
            'home_no_services_desc' => 'Create a Seller account to publish the first real service, or install the isolated demo above.',
            'home_create_seller' => 'Create seller account',
            'benefit_secure_title' => 'Secure & safe',
            'benefit_secure_desc' => 'Protected project records',
            'benefit_comm_title' => 'Easy communication',
            'benefit_comm_desc' => 'Built-in messages and files',
            'benefit_progress_title' => 'Track progress',
            'benefit_progress_desc' => 'Clear status at every step',
            'benefit_quality_title' => 'Quality work',
            'benefit_quality_desc' => 'Reviews from real clients',
            'service_label' => 'service',
            'services_label' => 'services',
            'communication_title' => 'Communication',
            'messages_title' => 'Messages',
            'conversations_title' => 'Conversations',
            'orders_label' => 'orders',
            'no_messages_yet' => 'No messages yet',
            'start_conversation' => 'Start the conversation with a clear project update.',
            'attach_file' => 'Attach image, PDF, or text file',
            'write_message' => 'Write a message...',
            'send_message' => 'Send',
            'project_conversations_desc' => 'Project conversations stay connected to their order.',
            'messages_available_after_order' => 'Messages become available after an order is created.',
            'no_project_conversations' => 'No project conversations yet',
            'notifications_desc' => 'Order, payment, review, and account activity in one place.',
            'no_order_label' => 'No order',
            'attachment_label' => 'Attachment',
            'topup_note_placeholder' => 'Optional note for this top up',
            'about_company' => 'Company',
            'about_heading' => 'Built around better working relationships',
            'about_description' => 'WorkConnect gives clients and specialists one calm, accountable place to move digital projects forward.',
            'about_purpose' => 'Our purpose',
            'about_good_work' => 'Good work starts with clarity.',
            'about_good_work_desc' => 'We designed WorkConnect to reduce the uncertainty around finding talent, agreeing on scope, following progress, and completing a project with confidence.',
            'about_principle_1_title' => 'Useful by default',
            'about_principle_1_desc' => 'Every screen supports a real decision or action.',
            'about_principle_2_title' => 'Trust is visible',
            'about_principle_2_desc' => 'Orders, messages, payments, and status stay connected.',
            'about_principle_3_title' => 'People stay in control',
            'about_principle_3_desc' => 'Clear expectations on both sides of every project.',
            'about_project_direction' => 'Project direction',
            'about_project_direction_desc' => 'A student-built platform with production-minded foundations.',
            'about_specialties' => 'Service specialties',
            'about_workspaces' => 'Purpose-built workspaces',
            'about_record' => 'Connected project record',
            'about_access' => 'Role-aware access',
            'services_page_title' => 'Marketplace',
            'services_page_heading' => 'Find the right specialist',
            'services_page_desc' => 'Browse focused services from verified WorkConnect sellers.',
            'services_search_placeholder' => 'Search by service or skill',
            'services_all_categories' => 'All categories',
            'services_count' => '{count} services',
            'services_price_note' => 'Prices shown in Thai baht',
            'service_no_match' => 'No matching services',
            'service_no_match_desc' => 'Try a broader keyword or clear the category filter.',
            'service_not_found' => 'Service not found',
            'service_back_to_services' => 'Back to services',
            'service_by' => 'Service by {name}',
            'service_about' => 'About this service',
            'service_included' => 'What is included',
            'service_feedback' => 'Client feedback',
            'service_first_project_note' => 'No reviews yet. This service is ready for its first project.',
            'service_package' => 'Service package',
            'service_one_project' => 'One complete project based on the scope described.',
            'service_delivery' => '{days}-day delivery',
            'service_payment_protection' => 'Protected payment record',
            'service_messaging' => 'Project messaging included',
            'service_selected_banner' => 'WorkConnect selected service',
            'service_customer_only_note' => 'Checkout is available from a Customer account.',
            'service_verified_seller' => 'Verified WorkConnect seller',
            'checkout_title' => 'Checkout',
            'checkout_heading' => 'Confirm your project',
            'checkout_desc' => 'Review the service, share a useful brief, and pay from your wallet balance.',
            'checkout_requirements' => 'Project requirements',
            'checkout_requirements_desc' => 'Describe the goal, audience, preferred style, and anything the seller should know.',
            'checkout_brief' => 'Project brief',
            'checkout_brief_placeholder' => 'I need this project to...',
            'checkout_coupon' => 'Coupon',
            'checkout_coupon_desc' => 'Apply a coupon code if you have one.',
            'checkout_coupon_code' => 'Coupon code',
            'checkout_payment_sim' => 'Payment simulation',
            'checkout_payment_desc' => 'No real card details are collected. A successful test transaction will be recorded.',
            'checkout_card' => 'Card simulation',
            'checkout_promptpay' => 'PromptPay simulation',
            'checkout_instant' => 'Instant confirmation',
            'checkout_service' => 'Service',
            'checkout_delivery' => 'Delivery',
            'checkout_platform' => 'Platform protection',
            'checkout_included' => 'Included',
            'checkout_total' => 'Total before coupon',
            'checkout_confirm' => 'Confirm and pay',
            'checkout_footer' => 'By confirming, your wallet balance is charged and the order is created.',
            'dashboard_customer' => 'Customer workspace',
            'welcome_back' => 'Welcome back, {name}.',
            'dashboard_morning' => 'Good morning',
            'dashboard_afternoon' => 'Good afternoon',
            'dashboard_clear_view' => 'A clear view of your projects, decisions, and recent updates.',
            'dashboard_find_service' => 'Find a service',
            'dashboard_active_orders' => 'Active orders',
            'dashboard_needs_review' => 'Needs review',
            'dashboard_completed' => 'Completed',
            'dashboard_unread_messages' => 'Unread messages',
            'dashboard_recent_orders' => 'Recent orders',
            'dashboard_recent_desc' => 'Latest activity across your projects',
            'dashboard_view_all' => 'View all',
            'dashboard_first_project' => 'Your first project will appear here.',
            'dashboard_next_step' => 'Next step',
            'dashboard_next_step_title' => 'Bring the next idea into focus.',
            'dashboard_next_step_desc' => 'Browse specialist services with clear pricing and delivery expectations.',
            'dashboard_explore_services' => 'Explore services',
            'activity_title' => 'Activity map',
            'activity_desc' => 'Real usage across the last 12 months.',
            'activity_empty' => 'No activity recorded yet.',
            'activity_scale_low' => 'Less',
            'activity_scale_high' => 'More',
            'activity_total' => '{count} actions · {days} active days',
            'orders_page_title' => 'Projects',
            'orders_page_heading' => 'My orders',
            'orders_page_desc' => 'Track delivery, approve completed work, and keep every project record together.',
            'orders_new' => 'New order',
            'orders_order' => 'Order',
            'orders_seller' => 'Seller',
            'orders_status' => 'Status',
            'orders_due' => 'Due',
            'orders_total' => 'Total',
            'orders_message' => 'Message',
            'orders_approve' => 'Approve',
            'orders_cancel' => 'Cancel',
            'orders_rate' => 'Rate',
            'orders_submit_review' => 'Submit review',
            'orders_stars' => '{count} stars',
            'profile_title' => 'Profile',
            'profile_desc' => 'Keep the details people use to recognize and work with you.',
            'profile_member_since' => 'Member since',
            'profile_account_status' => 'Account status',
            'profile_personal_info' => 'Personal information',
            'profile_personal_desc' => 'These details appear in your WorkConnect workspace.',
            'profile_full_name' => 'Full name',
            'profile_phone' => 'Phone',
            'profile_optional' => 'Optional',
            'profile_picture' => 'Profile picture',
            'profile_about_you' => 'About you',
            'profile_about_placeholder' => 'A short, useful introduction',
            'profile_save' => 'Save profile',
            'seller_dashboard_title' => 'Seller workspace',
            'seller_dashboard_desc' => 'Your business performance and the work that needs attention today.',
            'seller_completed_revenue' => 'Completed revenue',
            'seller_active_orders' => 'Active orders',
            'seller_live_services' => 'Live services',
            'seller_average_rating' => 'Average rating',
            'seller_recent_work' => 'Recent client work',
            'seller_recent_desc' => 'Orders sorted by latest activity',
            'seller_manage_all' => 'Manage all',
            'seller_quality' => 'Seller quality',
            'seller_next_action' => 'Keep the next action obvious.',
            'seller_next_action_desc' => 'Fast status updates and clear messages build client confidence.',
            'seller_review_orders' => 'Review active orders',
            'seller_services_title' => 'My services',
            'seller_services_desc' => 'Manage pricing, delivery, availability, and service content.',
            'seller_add_service' => 'Add service',
            'seller_edit_service' => 'Edit service',
            'seller_new_service' => 'Add a new service',
            'seller_update_service_desc' => 'Update the offer while keeping expectations precise.',
            'seller_create_service_desc' => 'Create a focused offer with clear scope, price, and delivery. New services go live after admin approval.',
            'seller_my_services' => 'My services',
            'seller_delivery' => 'Delivery',
            'seller_service_details' => 'Service details',
            'seller_service_title' => 'Title',
            'seller_service_category' => 'Category',
            'seller_service_price' => 'Price (THB)',
            'seller_service_delivery_days' => 'Delivery days',
            'seller_service_thumbnail' => 'Thumbnail',
            'seller_service_description' => 'Description',
            'seller_service_features' => 'Included features',
            'seller_service_feature_placeholder' => 'One feature per line',
            'seller_service_details_tip' => 'Good service writing',
            'seller_service_details_tip_title' => 'Specific beats impressive.',
            'seller_service_details_desc' => 'Use plain language and describe a deliverable a client can confidently order.',
            'seller_service_save_changes' => 'Save changes',
            'seller_service_publish' => 'Submit for review',
            'seller_service_review_notice' => 'New services must be approved by an admin before they appear in the marketplace.',
            'seller_service_edit' => 'Edit',
            'seller_service_remove' => 'Remove',
            'seller_service_remove_confirm' => 'Remove this service?',
            'seller_service_day_delivery' => '{days}-day delivery',
            'seller_service_tips_1' => 'Lead with the outcome.',
            'seller_service_tips_2' => 'Name what is included.',
            'seller_service_tips_3' => 'Set a realistic delivery time.',
            'seller_service_tips_4' => 'Keep revisions and limits clear.',
            'seller_manage_orders_title' => 'Manage orders',
            'seller_manage_orders_desc' => 'Move each project through a clear, client-visible workflow.',
            'seller_earnings_title' => 'Earnings',
            'seller_earnings_desc' => 'A transparent record of simulated payments and platform fees.',
            'seller_gross_payments' => 'Gross payments',
            'seller_all_transactions' => 'All recorded transactions',
            'seller_platform_fee_label' => 'Platform fee',
            'seller_total' => 'total',
            'seller_estimated_net' => 'Estimated net',
            'seller_demo_balance' => 'Demo balance',
            'seller_transactions_title' => 'Transactions',
            'seller_transactions_desc' => 'Payment records linked to your orders',
            'seller_reference' => 'Reference',
            'seller_service' => 'Service',
            'seller_date' => 'Date',
            'seller_method' => 'Method',
            'seller_amount' => 'Amount',
            'seller_analytics_title' => 'Analytics',
            'seller_analytics_desc' => 'Understand which services attract attention and convert into work.',
            'seller_service_performance' => 'Service performance',
            'seller_views_orders' => 'Views, orders, and completed revenue',
            'seller_views' => 'views',
            'seller_orders' => 'orders',
            'admin_users_title' => 'Users',
            'admin_users_desc' => 'Account status, roles, and marketplace activity.',
            'admin_user_table_user' => 'User',
            'admin_role' => 'Role',
            'admin_joined' => 'Joined',
            'admin_activity' => 'Activity',
            'admin_status' => 'Status',
            'admin_suspend' => 'Suspend',
            'admin_restore' => 'Restore',
            'admin_approve' => 'Approve',
            'admin_reject' => 'Reject',
            'admin_services_title' => 'Services',
            'admin_services_desc' => 'Moderate marketplace listings and review seller activity.',
            'admin_service_table_service' => 'Service',
            'admin_service_table_seller' => 'Seller',
            'admin_service_table_category' => 'Category',
            'admin_service_table_price' => 'Price',
            'admin_service_table_views' => 'Views',
            'admin_service_table_moderation' => 'Moderation',
            'admin_service_pending' => 'Pending review',
            'admin_service_active' => 'Active',
            'admin_service_paused' => 'Paused',
            'admin_service_rejected' => 'Rejected',
            'admin_orders_title' => 'Orders',
            'admin_orders_desc' => 'Monitor project flow, status, payment value, and participants.',
            'admin_order_table_order' => 'Order',
            'admin_order_table_customer' => 'Customer',
            'admin_order_table_seller' => 'Seller',
            'admin_order_table_created' => 'Created',
            'admin_messages_title' => 'Message audit',
            'admin_messages_desc' => 'Read-only oversight for support, disputes, and platform safety.',
            'admin_message_to' => 'to',
            'admin_no_order' => 'No order',
            'admin_moderation_title' => 'Moderation queue',
            'admin_moderation_desc' => 'Pending approvals and items that need quick attention.',
            'admin_approvals_title' => 'Approval center',
            'admin_approvals_desc' => 'Review seller identity documents and approve accounts from one place.',
            'admin_categories_title' => 'Categories',
            'admin_categories_desc' => 'Organize services into clear groups and keep the marketplace easy to scan.',
            'admin_coupons_title' => 'Coupons',
            'admin_coupons_desc' => 'Create and manage discount codes for promotions.',
            'admin_logs_title' => 'Activity logs',
            'admin_logs_desc' => 'Security and system events for audits and troubleshooting.',
            'admin_broadcast_title' => 'Broadcast message',
            'admin_broadcast_desc' => 'Send a banner or notification to all users.',
            'admin_export_title' => 'Export & backup',
            'admin_export_desc' => 'Download key data for reporting or offline backups.',
            'admin_reports_title' => 'Reports',
            'admin_reports_desc' => 'A concise operational view of the WorkConnect marketplace.',
            'admin_finance_title' => 'Revenue dashboard',
            'admin_finance_desc' => 'Gross income, platform fee, payouts, and monthly trends.',
            'admin_control_title' => 'Control center',
            'admin_control_desc' => 'Everything important for keeping the platform organized and healthy.',
            'admin_total_users' => 'Total users',
            'admin_category_performance' => 'Category performance',
            'admin_completion_rate' => '{count}% completion rate',
            'admin_services_inventory' => 'Service inventory and order value',
            'admin_settings_title' => 'System settings',
            'admin_settings_desc' => 'Core marketplace identity, support, fees, and availability.',
            'admin_platform_config' => 'Platform configuration',
            'admin_platform_config_desc' => 'Changes are stored immediately and used across operational reports.',
            'admin_registration_code' => 'Admin code',
            'admin_registration_code_desc' => 'Required for creating admin accounts.',
            'admin_site_name' => 'Site name',
            'admin_site_tagline' => 'Site tagline',
            'admin_support_email' => 'Support email',
            'admin_support_phone' => 'Support phone',
            'admin_contact_ig' => 'Contact Instagram',
            'admin_platform_fee' => 'Platform fee (%)',
            'admin_currency' => 'Currency symbol',
            'admin_topup_minimum' => 'Minimum top up',
            'admin_maintenance' => 'Maintenance mode',
            'admin_maintenance_desc' => 'Temporarily pause non-admin workspace access',
            'admin_registration_open' => 'New registrations',
            'admin_registration_open_desc' => 'Let new users create accounts',
            'admin_seller_auto_approval' => 'Auto-approve sellers',
            'admin_seller_auto_approval_desc' => 'Approve sellers immediately after registration',
            'admin_demo_mode' => 'Demo mode',
            'admin_demo_mode_desc' => 'Show demo labels and keep sandbox data active',
            'admin_announcement' => 'Announcement banner',
            'admin_announcement_desc' => 'Optional short message shown at the top of the site',
            'admin_save_settings' => 'Save system settings',
            'status_pending' => 'Pending',
            'status_in_progress' => 'In progress',
            'status_review' => 'Needs review',
            'status_completed' => 'Completed',
            'status_cancelled' => 'Cancelled',
            'status_active' => 'Active',
            'status_paused' => 'Paused',
            'status_rejected' => 'Rejected',
            'status_suspended' => 'Suspended',
            'status_paid' => 'Paid',
            'filter_all' => 'All',
            'new_label' => 'New',
            'preview_layout_desc' => 'Preview your preferred layout immediately and save it for future sessions.',
            'customer_account_only' => 'Checkout is available from a Customer account.',
            'verified_seller_note' => 'Verified WorkConnect seller',
            'workconnect_selected_service' => 'WorkConnect selected service',
            'records_label' => 'records',
            'from_label' => 'From',
        ],
        'th' => [
            'nav_home' => 'หน้าแรก',
            'nav_services' => 'บริการ',
            'nav_about' => 'เกี่ยวกับ',
            'nav_workspace' => 'พื้นที่ทำงาน',
            'nav_how' => 'วิธีใช้งาน',
            'nav_contact' => 'ติดต่อ',
            'nav_updates' => 'การแจ้งเตือน',
            'nav_login' => 'เข้าสู่ระบบ',
            'nav_signup' => 'สมัครสมาชิก',
            'nav_open_workspace' => 'เปิดพื้นที่ทำงาน',
            'nav_topup' => 'เติมเงิน',
            'group_workspace' => 'พื้นที่ทำงาน',
            'group_account' => 'บัญชี',
            'group_business' => 'ธุรกิจ',
            'group_management' => 'การจัดการ',
            'group_system' => 'ระบบ',
            'group_marketplace' => 'มาร์เก็ตเพลส',
            'side_overview' => 'ภาพรวม',
            'side_find_services' => 'ค้นหาบริการ',
            'side_orders' => 'คำสั่งซื้อ',
            'side_messages' => 'ข้อความ',
            'side_notifications' => 'แจ้งเตือน',
            'side_profile' => 'โปรไฟล์',
            'side_settings' => 'ตั้งค่า',
            'side_about' => 'เกี่ยวกับ WorkConnect',
            'side_topup' => 'เติมเงิน',
            'side_earnings' => 'รายได้',
            'side_analytics' => 'วิเคราะห์',
            'side_add_service' => 'เพิ่มบริการ',
            'side_manage_orders' => 'จัดการคำสั่งซื้อ',
            'side_users' => 'ผู้ใช้',
            'side_services' => 'บริการ',
            'side_reports' => 'รายงาน',
            'side_finance' => 'การเงิน',
            'side_control_center' => 'ศูนย์ควบคุม',
            'side_approvals' => 'อนุมัติ',
            'side_moderation' => 'ตรวจสอบ',
            'side_categories' => 'หมวดหมู่',
            'side_coupons' => 'คูปอง',
            'side_logs' => 'บันทึก',
            'side_broadcast' => 'ประกาศ',
            'side_export' => 'ส่งออก',
            'side_system_settings' => 'ตั้งค่าระบบ',
            'hero_eyebrow' => 'เชื่อมต่อ ร่วมงาน ส่งมอบงานให้สำเร็จ',
            'hero_title' => "หาคนที่ใช่\nแล้วให้งานเดินต่อ",
            'hero_description' => 'WorkConnect รวมลูกค้าและฟรีแลนซ์ไว้ในที่เดียว เพื่อให้บริการดิจิทัลดำเนินงานได้จริงและไว้ใจได้',
            'hero_search' => 'คุณกำลังมองหาบริการแบบไหน?',
            'home_start_title' => 'เริ่มตรงนี้',
            'home_start_desc' => 'เลือกทางที่ตรงกับสิ่งที่คุณต้องการทำต่อ',
            'home_start_customer_title' => 'จ้างผู้เชี่ยวชาญ',
            'home_start_customer_desc' => 'ค้นหาบริการ เปรียบเทียบ และสั่งงานได้เลย',
            'home_start_seller_title' => 'เปิดบริการของคุณ',
            'home_start_seller_desc' => 'สมัคร รออนุมัติ แล้วค่อยเผยแพร่บริการ',
            'home_start_admin_title' => 'ดูแลแพลตฟอร์ม',
            'home_start_admin_desc' => 'ตรวจผู้ใช้ บริการ และตั้งค่าระบบ',
            'onboarding_title' => 'เริ่มต้นใช้งาน',
            'onboarding_desc' => 'เก็บสิ่งจำเป็นให้ครบเพื่อให้พื้นที่ทำงานสมบูรณ์',
            'onboarding_profile' => 'กรอกโปรไฟล์ให้ครบ',
            'onboarding_approval' => 'รออนุมัติให้เรียบร้อย',
            'onboarding_service' => 'เผยแพร่บริการ',
            'onboarding_orders' => 'จัดการคำสั่งซื้อ',
            'onboarding_platform' => 'ตั้งค่าแพลตฟอร์ม',
            'onboarding_approval_queue' => 'ตรวจคิวอนุมัติ',
            'onboarding_reports' => 'เปิดรายงาน',
            'onboarding_activity' => 'ติดตามกิจกรรม',
            'onboarding_explore' => 'สำรวจบริการ',
            'onboarding_first_order' => 'สร้างคำสั่งซื้อแรก',
            'onboarding_notifications' => 'เปิดการแจ้งเตือน',
            'timeline_title' => 'ไทม์ไลน์คำสั่งซื้อ',
            'timeline_pending' => 'สร้างแล้ว',
            'timeline_in_progress' => 'กำลังทำ',
            'timeline_review' => 'ตรวจงาน',
            'timeline_completed' => 'เสร็จสิ้น',
            'search_title' => 'ค้นหาด่วน',
            'search_desc' => 'ค้นหาบริการ คำสั่งซื้อ ข้อความ และผู้ใช้ได้ในที่เดียว',
            'search_placeholder' => 'ค้นหาใน WorkConnect',
            'search_all' => 'ทั้งหมด',
            'search_services' => 'บริการ',
            'search_orders' => 'คำสั่งซื้อ',
            'search_messages' => 'ข้อความ',
            'search_users' => 'ผู้ใช้',
            'search_no_results' => 'ไม่พบรายการที่ตรงกัน',
            'saved_services_title' => 'บริการที่บันทึกไว้',
            'saved_services_desc' => 'เก็บบริการที่อยากกลับมาดูอีกครั้ง',
            'saved_services_empty' => 'ยังไม่มีบริการที่บันทึกไว้',
            'favorite_save' => 'บันทึก',
            'favorite_saved' => 'บันทึกแล้ว',
            'favorite_hint' => 'บันทึกไว้ในลิสต์ของคุณ',
            'trust_verified' => 'ยืนยันแล้ว',
            'trust_orders_completed' => 'คำสั่งซื้อที่เสร็จแล้ว',
            'trust_reviews' => 'รีวิว',
            'settings_title' => 'ตั้งค่า',
            'settings_description' => 'ปรับหน้าตา ภาษา การแจ้งเตือน และความปลอดภัยของบัญชี',
            'settings_appearance' => 'รูปแบบการแสดงผล',
            'settings_theme' => 'ธีม',
            'settings_language' => 'ภาษา',
            'settings_text_size' => 'ขนาดตัวหนังสือ',
            'settings_ui_size' => 'ขนาด UI',
            'settings_email_notifications' => 'การอัปเดตบัญชี',
            'settings_email_notifications_desc' => 'การแจ้งเตือนในระบบเกี่ยวกับคำสั่งซื้อ ข้อความ การชำระเงิน และความปลอดภัย',
            'settings_realtime' => 'ซิงก์เรียลไทม์',
            'settings_realtime_desc' => 'ให้การแจ้งเตือนและอัปเดตงานสดอยู่เสมอขณะใช้งาน WorkConnect',
            'register_role_customer' => 'ผู้ใช้บริการ',
            'register_role_seller' => 'เสนอขายบริการ',
            'register_role_admin' => 'สิทธิ์ผู้ดูแล',
            'register_admin_code' => 'รหัสผู้ดูแล',
            'register_admin_code_desc' => 'ต้องกรอกเฉพาะบัญชีแอดมิน',
            'register_seller_note' => 'บัญชีผู้ขายต้องรอแอดมินอนุมัติก่อนเข้าใช้งานพื้นที่ผู้ขาย',
            'seller_pending_title' => 'บัญชีผู้ขายรอการอนุมัติ',
            'seller_pending_desc' => 'สร้างบัญชีเรียบร้อยแล้ว แต่ต้องให้แอดมินอนุมัติก่อนจึงจะเข้าใช้งานพื้นที่ผู้ขายได้',
            'seller_pending_hint' => 'จะออกจากระบบเลย หรือรออยู่หน้านี้ระหว่างตรวจสอบก็ได้',
            'seller_pending_logout' => 'ออกจากระบบ',
            'settings_save_preferences' => 'บันทึกการตั้งค่า',
            'settings_password' => 'เปลี่ยนรหัสผ่าน',
            'settings_password_desc' => 'ใช้รหัสผ่านอย่างน้อย 8 ตัวอักษร และไม่ควรใช้ซ้ำกับรหัสเดิม',
            'settings_update_password' => 'อัปเดตรหัสผ่าน',
            'settings_current_password' => 'รหัสผ่านปัจจุบัน',
            'settings_new_password' => 'รหัสผ่านใหม่',
            'mark_all_read' => 'ทำเครื่องหมายว่าอ่านทั้งหมดแล้ว',
            'notifications_all' => 'ทั้งหมด',
            'notifications_unread' => 'ยังไม่อ่าน',
            'notifications_mark_read' => 'อ่านแล้ว',
            'notifications_mark_unread' => 'ยังไม่อ่าน',
            'notifications_empty' => 'คุณอัปเดตครบแล้ว',
            'project_progress_title' => 'ความคืบหน้าโปรเจกต์',
            'project_progress_desc' => 'ดูสถานะของคำสั่งซื้อที่กำลังดำเนินอยู่แบบย่อ',
            'project_progress_overview' => 'ภาพรวมความคืบหน้า',
            'project_progress_label' => 'ความคืบหน้า',
            'chat_seen' => 'อ่านแล้ว',
            'chat_sent' => 'ส่งแล้ว',
            'settings_theme_light' => 'สว่าง',
            'settings_theme_dark' => 'มืด',
            'settings_theme_auto' => 'ระบบ',
            'settings_text_small' => 'เล็ก',
            'settings_text_medium' => 'ปกติ',
            'settings_text_large' => 'ใหญ่',
            'settings_text_xl' => 'ใหญ่พิเศษ',
            'settings_ui_compact' => 'กระชับ',
            'settings_ui_comfortable' => 'พอดี',
            'settings_ui_roomy' => 'โล่ง',
            'topup_title' => 'เติมเงินกระเป๋า',
            'topup_description' => 'กรอกจำนวนเงินเอง เริ่มต้น 50 บาท แล้วจ่ายผ่าน Stripe PromptPay เพื่อเพิ่มยอดอัตโนมัติ',
            'topup_balance' => 'ยอดคงเหลือ',
            'topup_amount' => 'จำนวนเติม (บาท)',
            'topup_method' => 'ช่องทางชำระเงิน',
            'topup_note' => 'หมายเหตุ',
            'topup_submit' => 'เติมเงิน',
            'topup_recent' => 'รายการเติมล่าสุด',
            'topup_empty' => 'ยังไม่มีรายการเติมเงิน',
            'topup_success' => 'เริ่มชำระผ่าน PromptPay แล้ว ระบบจะเพิ่มยอดให้อัตโนมัติเมื่อยืนยันสำเร็จ',
            'realtime_label' => 'ซิงก์สด',
            'realtime_desc' => 'การแจ้งเตือนและอัปเดตงานจะรีเฟรชอัตโนมัติ',
            'search_button' => 'ค้นหา',
            'real_accounts' => '{count} บัญชีจริง',
            'marketplace_clean_ready' => 'มาร์เก็ตเพลสที่สะอาด พร้อมสำหรับบริการแรก',
            'demo_status_active' => 'สภาพแวดล้อมเดโมเปิดอยู่',
            'demo_status_clean' => 'ฐานข้อมูลว่าง',
            'demo_status_active_desc' => 'ข้อมูลจำลองแยกจากข้อมูลจริงโดยสิ้นเชิง',
            'demo_status_clean_desc' => 'ตอนนี้ยังไม่มีข้อมูลตัวอย่างในระบบ',
            'demo_preview_active_title' => 'สำรวจ WorkConnect ได้ครบทุกมุม',
            'demo_preview_clean_title' => 'ดูโปรดักต์ครบชุดได้ในคลิกเดียว',
            'demo_preview_active_desc' => 'มีพื้นที่ทำงานสำหรับ Customer, Seller และ Admin พร้อมใช้งาน รหัสผ่านเดโมใช้ตัวเดียวกันและระบุชัดเจน',
            'demo_preview_clean_desc' => 'ติดตั้งชุดข้อมูลตัวอย่างขนาดเล็กที่เชื่อมกันครบ ทั้งผู้ใช้ บริการ คำสั่งซื้อ ข้อความ การชำระเงิน และรีวิว ลบออกได้ภายหลังโดยไม่กระทบบัญชีจริง',
            'demo_users' => 'ผู้ใช้เดโม',
            'demo_services' => 'บริการ',
            'demo_orders' => 'คำสั่งซื้อ',
            'demo_open_customer' => 'เปิด Customer',
            'demo_open_seller' => 'เปิด Seller',
            'demo_open_admin' => 'เปิด Admin',
            'demo_clear' => 'ลบเดโม',
            'demo_install_data' => 'ติดตั้งข้อมูลเดโม',
            'demo_install_note' => 'บัญชีจริงและไฟล์อัปโหลดจะไม่ถูกกระทบ',
            'demo_clear_title' => 'ลบข้อมูลเดโม?',
            'demo_clear_desc' => 'การลบนี้จะเอาออกเฉพาะข้อมูลที่ติดป้าย Demo ได้แก่ ผู้ใช้เดโม บริการ คำสั่งซื้อ ข้อความ และการชำระเงิน ข้อมูลจริงจะยังอยู่',
            'demo_clear_confirm' => 'ลบข้อมูลเดโม',
            'demo_clear_keep' => 'เก็บเดโมไว้',
            'category_kicker' => 'สำรวจตามหมวด',
            'category_title' => 'ทุกอย่างที่โปรเจกต์ของคุณต้องการ',
            'view_all_services' => 'ดูบริการทั้งหมด',
            'how_kicker' => 'เริ่มต้นถึงจบงานแบบเข้าใจง่าย',
            'how_title' => 'WorkConnect ทำงานอย่างไร',
            'how_description' => '4 ขั้นตอนชัดเจน พร้อมให้เห็นการสื่อสารและความคืบหน้าตลอดทาง',
            'step_search_title' => 'ค้นหา',
            'step_search_desc' => 'หาบริการที่ตรงกับเป้าหมายและงบประมาณ',
            'step_order_title' => 'สั่งงาน',
            'step_order_desc' => 'บอกรายละเอียดและยืนยันคำสั่งซื้อ',
            'step_collaborate_title' => 'ร่วมงาน',
            'step_collaborate_desc' => 'คุย ส่งไฟล์ และติดตามความคืบหน้า',
            'step_done_title' => 'ส่งงาน',
            'step_done_desc' => 'ตรวจผลลัพธ์และปิดงานให้เรียบร้อย',
            'popular_chosen_kicker' => 'ลูกค้าเลือกบ่อย',
            'popular_marketplace_kicker' => 'มาร์เก็ตเพลส',
            'popular_title' => 'บริการยอดนิยม',
            'latest_title' => 'บริการล่าสุด',
            'browse_all' => 'ดูทั้งหมด',
            'home_no_services_title' => 'ยังไม่มีบริการที่เผยแพร่',
            'home_no_services_desc' => 'สร้างบัญชี Seller เพื่อเผยแพร่บริการจริงรายการแรก หรือใช้เดโมแยกที่อยู่ด้านบน',
            'home_create_seller' => 'สร้างบัญชี Seller',
            'benefit_secure_title' => 'ปลอดภัยและเชื่อถือได้',
            'benefit_secure_desc' => 'บันทึกโปรเจกต์ถูกป้องกัน',
            'benefit_comm_title' => 'สื่อสารง่าย',
            'benefit_comm_desc' => 'มีข้อความและไฟล์ในระบบ',
            'benefit_progress_title' => 'ติดตามความคืบหน้า',
            'benefit_progress_desc' => 'เห็นสถานะชัดทุกขั้นตอน',
            'benefit_quality_title' => 'งานมีคุณภาพ',
            'benefit_quality_desc' => 'รีวิวจากลูกค้าจริง',
            'service_label' => 'บริการ',
            'services_label' => 'บริการ',
            'communication_title' => 'การสื่อสาร',
            'messages_title' => 'ข้อความ',
            'conversations_title' => 'บทสนทนา',
            'orders_label' => 'รายการ',
            'no_messages_yet' => 'ยังไม่มีข้อความ',
            'start_conversation' => 'เริ่มคุยด้วยการอัปเดตงานให้ชัดเจน',
            'attach_file' => 'แนบรูป PDF หรือไฟล์ข้อความ',
            'write_message' => 'พิมพ์ข้อความ...',
            'send_message' => 'ส่ง',
            'project_conversations_desc' => 'บทสนทนาของโปรเจกต์จะเชื่อมกับคำสั่งซื้อเสมอ',
            'messages_available_after_order' => 'สามารถใช้ข้อความได้หลังจากสร้างคำสั่งซื้อแล้ว',
            'no_project_conversations' => 'ยังไม่มีบทสนทนาในโปรเจกต์',
            'notifications_desc' => 'กิจกรรมคำสั่งซื้อ การชำระเงิน รีวิว และบัญชีรวมไว้ในที่เดียว',
            'no_order_label' => 'ไม่มีคำสั่งซื้อ',
            'attachment_label' => 'ไฟล์แนบ',
            'topup_note_placeholder' => 'หมายเหตุเพิ่มเติม (ถ้ามี)',
            'about_company' => 'บริษัท',
            'about_heading' => 'สร้างบนความสัมพันธ์การทำงานที่ดีกว่า',
            'about_description' => 'WorkConnect ให้ลูกค้าและผู้เชี่ยวชาญมีพื้นที่ที่สงบและรับผิดชอบร่วมกันสำหรับขับเคลื่อนโปรเจกต์ดิจิทัล',
            'about_purpose' => 'จุดมุ่งหมายของเรา',
            'about_good_work' => 'งานที่ดีเริ่มจากความชัดเจน',
            'about_good_work_desc' => 'เราออกแบบ WorkConnect ให้ลดความไม่แน่นอนของการหาคน ตกลงขอบเขต ติดตามความคืบหน้า และปิดงานอย่างมั่นใจ',
            'about_principle_1_title' => 'มีประโยชน์โดยค่าเริ่มต้น',
            'about_principle_1_desc' => 'ทุกหน้าช่วยให้ตัดสินใจหรือทำงานได้จริง',
            'about_principle_2_title' => 'ความเชื่อใจมองเห็นได้',
            'about_principle_2_desc' => 'คำสั่งซื้อ ข้อความ การชำระเงิน และสถานะเชื่อมถึงกัน',
            'about_principle_3_title' => 'ผู้ใช้ยังคุมงานได้',
            'about_principle_3_desc' => 'ตั้งความคาดหวังให้ชัดทั้งสองฝั่งในทุกโปรเจกต์',
            'about_project_direction' => 'ทิศทางของโปรเจกต์',
            'about_project_direction_desc' => 'แพลตฟอร์มที่นักศึกษาสร้างขึ้นบนแนวคิดพร้อมใช้งานจริง',
            'about_specialties' => 'ความเชี่ยวชาญบริการ',
            'about_workspaces' => 'พื้นที่ทำงานเฉพาะทาง',
            'about_record' => 'ประวัติโปรเจกต์ที่เชื่อมกัน',
            'about_access' => 'สิทธิ์ตามบทบาท',
            'services_page_title' => 'มาร์เก็ตเพลส',
            'services_page_heading' => 'หาผู้เชี่ยวชาญที่ใช่',
            'services_page_desc' => 'ค้นหาบริการเฉพาะทางจากผู้ขาย WorkConnect ที่ยืนยันตัวตนแล้ว',
            'services_search_placeholder' => 'ค้นหาจากบริการหรือทักษะ',
            'services_all_categories' => 'ทุกหมวด',
            'services_count' => '{count} บริการ',
            'services_price_note' => 'ราคาที่แสดงเป็นเงินบาท',
            'service_no_match' => 'ไม่พบบริการที่ตรงกัน',
            'service_no_match_desc' => 'ลองใช้คำค้นที่กว้างขึ้น หรือเคลียร์ตัวกรองหมวดหมู่',
            'service_not_found' => 'ไม่พบบริการ',
            'service_back_to_services' => 'กลับไปหน้าบริการ',
            'service_by' => 'บริการโดย {name}',
            'service_about' => 'เกี่ยวกับบริการนี้',
            'service_included' => 'สิ่งที่รวมอยู่',
            'service_feedback' => 'ความเห็นจากลูกค้า',
            'service_first_project_note' => 'ยังไม่มีรีวิว บริการนี้พร้อมสำหรับโปรเจกต์แรกแล้ว',
            'service_package' => 'แพ็กเกจบริการ',
            'service_one_project' => 'หนึ่งโปรเจกต์ครบตามขอบเขตที่ระบุไว้',
            'service_delivery' => 'ส่งภายใน {days} วัน',
            'service_payment_protection' => 'บันทึกการชำระเงินแบบมีการป้องกัน',
            'service_messaging' => 'รวมระบบข้อความของโปรเจกต์',
            'service_selected_banner' => 'บริการที่ WorkConnect เลือก',
            'service_customer_only_note' => 'Checkout ใช้ได้จากบัญชี Customer เท่านั้น',
            'service_verified_seller' => 'ผู้ขาย WorkConnect ที่ยืนยันแล้ว',
            'checkout_title' => 'ชำระเงิน',
            'checkout_heading' => 'ยืนยันโปรเจกต์ของคุณ',
            'checkout_desc' => 'ตรวจบริการ เขียนบรีฟให้มีประโยชน์ และชำระผ่านยอด Wallet ของคุณ',
            'checkout_requirements' => 'ความต้องการของโปรเจกต์',
            'checkout_requirements_desc' => 'อธิบายเป้าหมาย กลุ่มเป้าหมาย สไตล์ที่ต้องการ และสิ่งที่ผู้ขายควรรู้',
            'checkout_brief' => 'บรีฟงาน',
            'checkout_brief_placeholder' => 'ผม/ฉันต้องการให้โปรเจกต์นี้...',
            'checkout_coupon' => 'คูปอง',
            'checkout_coupon_desc' => 'ใส่โค้ดส่วนลดได้ถ้ามี',
            'checkout_coupon_code' => 'โค้ดคูปอง',
            'checkout_payment_sim' => 'จำลองการชำระเงิน',
            'checkout_payment_desc' => 'ไม่มีการเก็บข้อมูลบัตรจริง ระบบจะบันทึกธุรกรรมทดสอบที่สำเร็จ',
            'checkout_card' => 'จำลองการจ่ายด้วยบัตร',
            'checkout_promptpay' => 'จำลอง PromptPay',
            'checkout_instant' => 'ยืนยันได้ทันที',
            'checkout_service' => 'บริการ',
            'checkout_delivery' => 'ระยะเวลา',
            'checkout_platform' => 'การป้องกันของแพลตฟอร์ม',
            'checkout_included' => 'รวมอยู่แล้ว',
            'checkout_total' => 'รวมก่อนใช้คูปอง',
            'checkout_confirm' => 'ยืนยันและชำระเงิน',
            'checkout_footer' => 'เมื่อยืนยัน ระบบจะตัดยอดจาก Wallet และสร้างคำสั่งซื้อทันที',
            'dashboard_customer' => 'พื้นที่ทำงานของลูกค้า',
            'welcome_back' => 'ยินดีต้อนรับกลับ {name}',
            'dashboard_morning' => 'สวัสดีตอนเช้า',
            'dashboard_afternoon' => 'สวัสดีตอนบ่าย',
            'dashboard_clear_view' => 'มุมมองที่ชัดเจนของโปรเจกต์ การตัดสินใจ และอัปเดตล่าสุด',
            'dashboard_find_service' => 'หาบริการ',
            'dashboard_active_orders' => 'คำสั่งซื้อที่กำลังดำเนินการ',
            'dashboard_needs_review' => 'ต้องตรวจสอบ',
            'dashboard_completed' => 'เสร็จแล้ว',
            'dashboard_unread_messages' => 'ข้อความที่ยังไม่ได้อ่าน',
            'dashboard_recent_orders' => 'คำสั่งซื้อล่าสุด',
            'dashboard_recent_desc' => 'กิจกรรมล่าสุดของโปรเจกต์ของคุณ',
            'dashboard_view_all' => 'ดูทั้งหมด',
            'dashboard_first_project' => 'โปรเจกต์แรกของคุณจะปรากฏที่นี่',
            'dashboard_next_step' => 'ขั้นตอนถัดไป',
            'dashboard_next_step_title' => 'ทำให้ไอเดียถัดไปชัดขึ้น',
            'dashboard_next_step_desc' => 'ค้นหาบริการเฉพาะทางที่มีราคาชัดและความคาดหวังในการส่งงานที่ชัดเจน',
            'dashboard_explore_services' => 'สำรวจบริการ',
            'activity_title' => 'แผนผังกิจกรรม',
            'activity_desc' => 'การใช้งานจริงย้อนหลัง 12 เดือน',
            'activity_empty' => 'ยังไม่มีกิจกรรมที่บันทึกไว้',
            'activity_scale_low' => 'น้อย',
            'activity_scale_high' => 'มากขึ้น',
            'activity_total' => '{count} ครั้ง · {days} วันที่มีการใช้งาน',
            'orders_page_title' => 'โปรเจกต์',
            'orders_page_heading' => 'คำสั่งซื้อของฉัน',
            'orders_page_desc' => 'ติดตามงาน อนุมัติงานที่เสร็จแล้ว และเก็บประวัติโปรเจกต์ไว้รวมกัน',
            'orders_new' => 'สร้างคำสั่งซื้อ',
            'orders_order' => 'คำสั่งซื้อ',
            'orders_seller' => 'ผู้ขาย',
            'orders_status' => 'สถานะ',
            'orders_due' => 'กำหนดส่ง',
            'orders_total' => 'ยอดรวม',
            'orders_message' => 'ข้อความ',
            'orders_approve' => 'อนุมัติ',
            'orders_cancel' => 'ยกเลิก',
            'orders_rate' => 'ให้คะแนน',
            'orders_submit_review' => 'ส่งรีวิว',
            'orders_stars' => '{count} ดาว',
            'profile_title' => 'โปรไฟล์',
            'profile_desc' => 'เก็บรายละเอียดที่คนใช้จดจำและทำงานกับคุณ',
            'profile_member_since' => 'เป็นสมาชิกตั้งแต่',
            'profile_account_status' => 'สถานะบัญชี',
            'profile_personal_info' => 'ข้อมูลส่วนตัว',
            'profile_personal_desc' => 'รายละเอียดเหล่านี้จะแสดงในพื้นที่ทำงาน WorkConnect',
            'profile_full_name' => 'ชื่อ-นามสกุล',
            'profile_phone' => 'โทรศัพท์',
            'profile_optional' => 'ไม่บังคับ',
            'profile_picture' => 'รูปโปรไฟล์',
            'profile_about_you' => 'เกี่ยวกับคุณ',
            'profile_about_placeholder' => 'แนะนำตัวสั้น ๆ ที่มีประโยชน์',
            'profile_save' => 'บันทึกโปรไฟล์',
            'seller_dashboard_title' => 'พื้นที่ทำงานของผู้ขาย',
            'seller_dashboard_desc' => 'ผลงานธุรกิจและงานที่ต้องดูแลวันนี้',
            'seller_completed_revenue' => 'รายได้ที่เสร็จสิ้นแล้ว',
            'seller_active_orders' => 'คำสั่งซื้อที่กำลังทำ',
            'seller_live_services' => 'บริการที่เปิดอยู่',
            'seller_average_rating' => 'คะแนนเฉลี่ย',
            'seller_recent_work' => 'งานลูกค้าล่าสุด',
            'seller_recent_desc' => 'เรียงคำสั่งซื้อตามกิจกรรมล่าสุด',
            'seller_manage_all' => 'จัดการทั้งหมด',
            'seller_quality' => 'คุณภาพของผู้ขาย',
            'seller_next_action' => 'ทำให้ขั้นตอนถัดไปชัดเจน',
            'seller_next_action_desc' => 'อัปเดตสถานะเร็วและสื่อสารชัด จะช่วยสร้างความมั่นใจให้ลูกค้า',
            'seller_review_orders' => 'ตรวจคำสั่งซื้อที่กำลังทำ',
            'seller_services_title' => 'บริการของฉัน',
            'seller_services_desc' => 'จัดการราคา ระยะเวลา ความพร้อมใช้งาน และเนื้อหาบริการ',
            'seller_add_service' => 'เพิ่มบริการ',
            'seller_edit_service' => 'แก้ไขบริการ',
            'seller_new_service' => 'เพิ่มบริการใหม่',
            'seller_update_service_desc' => 'อัปเดตข้อเสนอโดยยังคงความคาดหวังให้ชัดเจน',
            'seller_create_service_desc' => 'สร้างข้อเสนอที่โฟกัสชัด มีขอบเขต ราคา และระยะเวลาชัดเจน โดยบริการใหม่จะขึ้นขายได้หลังแอดมินอนุมัติ',
            'seller_my_services' => 'บริการของฉัน',
            'seller_delivery' => 'การส่งงาน',
            'seller_service_details' => 'รายละเอียดบริการ',
            'seller_service_title' => 'ชื่อเรื่อง',
            'seller_service_category' => 'หมวดหมู่',
            'seller_service_price' => 'ราคา (บาท)',
            'seller_service_delivery_days' => 'จำนวนวันส่งงาน',
            'seller_service_thumbnail' => 'รูปปก',
            'seller_service_description' => 'คำอธิบาย',
            'seller_service_features' => 'สิ่งที่รวมอยู่',
            'seller_service_feature_placeholder' => 'หนึ่งบรรทัดต่อหนึ่งรายการ',
            'seller_service_details_tip' => 'แนวทางเขียนบริการที่ดี',
            'seller_service_details_tip_title' => 'ความเฉพาะเจาะจงชนะความอลังการ',
            'seller_service_details_desc' => 'ใช้ภาษาตรงไปตรงมาและอธิบายสิ่งที่ลูกค้าสามารถสั่งได้อย่างมั่นใจ',
            'seller_service_save_changes' => 'บันทึกการแก้ไข',
            'seller_service_publish' => 'ส่งให้แอดมินตรวจ',
            'seller_service_review_notice' => 'บริการใหม่ทุกชิ้นต้องผ่านการอนุมัติจากแอดมินก่อนจึงจะแสดงใน marketplace',
            'seller_service_edit' => 'แก้ไข',
            'seller_service_remove' => 'ลบ',
            'seller_service_remove_confirm' => 'ลบบริการนี้ใช่ไหม?',
            'seller_service_day_delivery' => 'ส่งภายใน {days} วัน',
            'seller_service_tips_1' => 'เริ่มด้วยผลลัพธ์ที่ลูกค้าจะได้',
            'seller_service_tips_2' => 'ระบุให้ชัดว่าอะไรถูก รวมอยู่',
            'seller_service_tips_3' => 'ตั้งเวลาส่งที่สมเหตุสมผล',
            'seller_service_tips_4' => 'กำหนดรอบแก้และขอบเขตให้ชัด',
            'seller_manage_orders_title' => 'จัดการคำสั่งซื้อ',
            'seller_manage_orders_desc' => 'พาแต่ละโปรเจกต์ผ่าน workflow ที่ชัดและลูกค้ามองเห็นได้',
            'seller_earnings_title' => 'รายได้',
            'seller_earnings_desc' => 'บันทึกการชำระเงินจำลองและค่าธรรมเนียมแพลตฟอร์มอย่างโปร่งใส',
            'seller_gross_payments' => 'ยอดรับรวม',
            'seller_all_transactions' => 'ธุรกรรมทั้งหมดที่บันทึกไว้',
            'seller_platform_fee_label' => 'ค่าธรรมเนียมแพลตฟอร์ม',
            'seller_total' => 'รวม',
            'seller_estimated_net' => 'ยอดสุทธิประมาณการ',
            'seller_demo_balance' => 'ยอดเดโม',
            'seller_transactions_title' => 'ธุรกรรม',
            'seller_transactions_desc' => 'บันทึกการชำระเงินที่เชื่อมกับคำสั่งซื้อของคุณ',
            'seller_reference' => 'อ้างอิง',
            'seller_service' => 'บริการ',
            'seller_date' => 'วันที่',
            'seller_method' => 'วิธีชำระเงิน',
            'seller_amount' => 'จำนวนเงิน',
            'seller_analytics_title' => 'วิเคราะห์',
            'seller_analytics_desc' => 'ดูว่าบริการไหนดึงความสนใจและแปลงเป็นงานได้',
            'seller_service_performance' => 'ประสิทธิภาพบริการ',
            'seller_views_orders' => 'จำนวนวิว คำสั่งซื้อ และรายได้ที่เสร็จแล้ว',
            'seller_views' => 'วิว',
            'seller_orders' => 'คำสั่งซื้อ',
            'admin_users_title' => 'ผู้ใช้',
            'admin_users_desc' => 'สถานะบัญชี บทบาท และกิจกรรมในมาร์เก็ตเพลส',
            'admin_user_table_user' => 'ผู้ใช้',
            'admin_role' => 'บทบาท',
            'admin_joined' => 'เข้าร่วมเมื่อ',
            'admin_activity' => 'กิจกรรม',
            'admin_status' => 'สถานะ',
            'admin_suspend' => 'ระงับ',
            'admin_restore' => 'คืนค่า',
            'admin_approve' => 'อนุมัติ',
            'admin_reject' => 'ปฏิเสธ',
            'admin_services_title' => 'บริการ',
            'admin_services_desc' => 'ตรวจรายการบริการและรีวิวกิจกรรมผู้ขาย',
            'admin_service_table_service' => 'บริการ',
            'admin_service_table_seller' => 'ผู้ขาย',
            'admin_service_table_category' => 'หมวดหมู่',
            'admin_service_table_price' => 'ราคา',
            'admin_service_table_views' => 'วิว',
            'admin_service_table_moderation' => 'การตรวจสอบ',
            'admin_service_pending' => 'รอตรวจ',
            'admin_service_active' => 'เปิดใช้งาน',
            'admin_service_paused' => 'พักไว้',
            'admin_service_rejected' => 'ปฏิเสธ',
            'admin_orders_title' => 'คำสั่งซื้อ',
            'admin_orders_desc' => 'ติดตาม flow งาน สถานะ มูลค่าชำระเงิน และผู้เกี่ยวข้อง',
            'admin_order_table_order' => 'คำสั่งซื้อ',
            'admin_order_table_customer' => 'ลูกค้า',
            'admin_order_table_seller' => 'ผู้ขาย',
            'admin_order_table_created' => 'สร้างเมื่อ',
            'admin_messages_title' => 'ตรวจข้อความ',
            'admin_messages_desc' => 'ดูแบบอ่านอย่างเดียวเพื่อสนับสนุน การข้อพิพาท และความปลอดภัยของแพลตฟอร์ม',
            'admin_message_to' => 'ถึง',
            'admin_no_order' => 'ไม่มีคำสั่งซื้อ',
            'admin_moderation_title' => 'คิวตรวจสอบ',
            'admin_moderation_desc' => 'รายการรออนุมัติและสิ่งที่ต้องดูด่วน',
            'admin_approvals_title' => 'ศูนย์อนุมัติ',
            'admin_approvals_desc' => 'ตรวจเอกสารผู้ขายและอนุมัติบัญชีจากหน้าเดียว',
            'admin_categories_title' => 'หมวดหมู่',
            'admin_categories_desc' => 'จัดบริการเป็นกลุ่มให้ชัดและค้นง่าย',
            'admin_coupons_title' => 'คูปอง',
            'admin_coupons_desc' => 'สร้างและจัดการโค้ดส่วนลดสำหรับโปรโมชัน',
            'admin_logs_title' => 'บันทึกกิจกรรม',
            'admin_logs_desc' => 'เหตุการณ์ด้านความปลอดภัยและระบบสำหรับตรวจสอบย้อนหลัง',
            'admin_broadcast_title' => 'ประกาศถึงผู้ใช้',
            'admin_broadcast_desc' => 'ส่งแถบประกาศหรือแจ้งเตือนไปยังผู้ใช้ทั้งหมด',
            'admin_export_title' => 'ส่งออกและสำรอง',
            'admin_export_desc' => 'ดาวน์โหลดข้อมูลสำคัญเพื่อรายงานหรือสำรองไว้ใช้นอกระบบ',
            'admin_reports_title' => 'รายงาน',
            'admin_reports_desc' => 'มุมมองภาพรวมการปฏิบัติงานของ WorkConnect',
            'admin_finance_title' => 'แดชบอร์ดรายได้',
            'admin_finance_desc' => 'รายรับรวม ค่าบริการแพลตฟอร์ม การจ่ายให้ผู้ขาย และแนวโน้มรายเดือน',
            'admin_control_title' => 'ศูนย์ควบคุม',
            'admin_control_desc' => 'ทุกอย่างสำคัญสำหรับดูแลและจัดการแพลตฟอร์มให้อยู่ในสภาพดี',
            'admin_total_users' => 'ผู้ใช้ทั้งหมด',
            'admin_category_performance' => 'ประสิทธิภาพตามหมวด',
            'admin_completion_rate' => 'อัตราการเสร็จ {count}%',
            'admin_services_inventory' => 'สินค้าคงคลังบริการและมูลค่าคำสั่งซื้อ',
            'admin_settings_title' => 'ตั้งค่าระบบ',
            'admin_settings_desc' => 'ตัวตนหลักของมาร์เก็ตเพลส การสนับสนุน ค่าธรรมเนียม และสถานะใช้งาน',
            'admin_platform_config' => 'การตั้งค่าแพลตฟอร์ม',
            'admin_platform_config_desc' => 'การเปลี่ยนแปลงจะถูกบันทึกทันทีและนำไปใช้ในรายงานทุกส่วน',
            'admin_registration_code' => 'รหัสผู้ดูแล',
            'admin_registration_code_desc' => 'ใช้สำหรับการสร้างบัญชีแอดมิน',
            'admin_site_name' => 'ชื่อเว็บไซต์',
            'admin_site_tagline' => 'สโลแกนเว็บไซต์',
            'admin_support_email' => 'อีเมลซัพพอร์ต',
            'admin_support_phone' => 'เบอร์ซัพพอร์ต',
            'admin_contact_ig' => 'อินสตาแกรมสำหรับติดต่อ',
            'admin_platform_fee' => 'ค่าธรรมเนียมแพลตฟอร์ม (%)',
            'admin_currency' => 'สัญลักษณ์สกุลเงิน',
            'admin_topup_minimum' => 'ยอดเติมขั้นต่ำ',
            'admin_maintenance' => 'โหมดซ่อมบำรุง',
            'admin_maintenance_desc' => 'พักสิทธิ์เข้าพื้นที่ทำงานของผู้ที่ไม่ใช่แอดมินชั่วคราว',
            'admin_registration_open' => 'เปิดให้สมัครสมาชิก',
            'admin_registration_open_desc' => 'ให้ผู้ใช้ใหม่สร้างบัญชีได้',
            'admin_seller_auto_approval' => 'อนุมัติผู้ขายอัตโนมัติ',
            'admin_seller_auto_approval_desc' => 'อนุมัติผู้ขายทันทีหลังสมัคร',
            'admin_demo_mode' => 'โหมดเดโม',
            'admin_demo_mode_desc' => 'แสดงป้ายเดโมและใช้ข้อมูลจำลองต่อไป',
            'admin_announcement' => 'แถบประกาศ',
            'admin_announcement_desc' => 'ข้อความสั้นๆ ที่แสดงด้านบนของเว็บไซต์',
            'admin_save_settings' => 'บันทึกการตั้งค่าระบบ',
            'status_pending' => 'รอดำเนินการ',
            'status_in_progress' => 'กำลังทำ',
            'status_review' => 'ต้องตรวจสอบ',
            'status_completed' => 'เสร็จแล้ว',
            'status_cancelled' => 'ยกเลิกแล้ว',
            'status_active' => 'เปิดใช้งาน',
            'status_paused' => 'พักไว้',
            'status_rejected' => 'ปฏิเสธแล้ว',
            'status_suspended' => 'ระงับแล้ว',
            'status_paid' => 'ชำระแล้ว',
            'filter_all' => 'ทั้งหมด',
            'new_label' => 'ใหม่',
            'preview_layout_desc' => 'ดูรูปแบบที่ชอบได้ทันทีและบันทึกไว้ใช้ในครั้งต่อไป',
            'customer_account_only' => 'Checkout ใช้ได้จากบัญชี Customer เท่านั้น',
            'verified_seller_note' => 'ผู้ขาย WorkConnect ที่ยืนยันแล้ว',
            'workconnect_selected_service' => 'บริการที่ WorkConnect เลือก',
            'records_label' => 'รายการ',
            'from_label' => 'เริ่มต้นที่',
        ],
    ];
    $language = $language && isset($dictionary[$language]) ? $language : 'en';
    $text = $dictionary[$language][$key] ?? $dictionary['en'][$key] ?? $key;
    foreach ($replace as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function deliver_password_reset_link(string $email, string $url): void
{
    $subject = 'Reset your WorkConnect password';
    $message = "Use this link within 30 minutes to reset your password:\n\n" . $url . "\n\nIf you did not request this, ignore this email.";
    if (strtolower((string) env_value('MAIL_TRANSPORT', 'log')) === 'mail') {
        $from = (string) env_value('MAIL_FROM', 'hello@workconnect.test');
        if (@mail($email, $subject, $message, 'From: ' . $from)) return;
        throw new RuntimeException('Password reset email could not be sent.');
    }
    $directory = dirname(__DIR__) . '/storage/private/mail';
    ensure_upload_protection($directory);
    $log = $directory . '/password-resets.log';
    file_put_contents($log, date(DATE_ATOM) . "\t" . $email . "\t" . $url . PHP_EOL, FILE_APPEND | LOCK_EX);
    @chmod($log, 0660);
}

function money(float|int|string $amount): string
{
    return currency_symbol_setting('฿') . number_format((float) $amount, 0);
}

function short_date(?string $date): string
{
    return $date ? date('d M Y', strtotime($date)) : 'Not set';
}

function relative_time(string $date): string
{
    $seconds = max(0, time() - strtotime($date));
    return match (true) {
        $seconds < 60 => 'Just now',
        $seconds < 3600 => floor($seconds / 60) . 'm ago',
        $seconds < 86400 => floor($seconds / 3600) . 'h ago',
        default => date('d M', strtotime($date)),
    };
}

function initials(string $name): string
{
    $words = preg_split('/\s+/', trim($name));
    return strtoupper(substr($words[0] ?? 'U', 0, 1) . substr($words[1] ?? '', 0, 1));
}

function age_from_birth_date(?string $date): ?int
{
    $date = trim((string) $date);
    if ($date === '') {
        return null;
    }
    try {
        $birthDate = new DateTimeImmutable($date);
        $today = new DateTimeImmutable('today');
        return $birthDate->diff($today)->y;
    } catch (Throwable $error) {
        return null;
    }
}

function mask_id_card_number(?string $value): string
{
    $value = decrypt_sensitive((string) $value);
    $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) <= 4) {
        return $digits;
    }
    return substr($digits, 0, 1) . '-' . str_repeat('x', max(0, strlen($digits) - 5)) . '-' . substr($digits, -4);
}

function status_label(string $status, ?string $language = null): string
{
    return match ($status) {
        'pending' => t('status_pending', $language),
        'in_progress' => t('status_in_progress', $language),
        'review' => t('status_review', $language),
        'completed' => t('status_completed', $language),
        'cancelled' => t('status_cancelled', $language),
        'rejected' => t('status_rejected', $language),
        'active' => t('status_active', $language),
        'paused' => t('status_paused', $language),
        'suspended' => t('status_suspended', $language),
        'pending_approval' => $language === 'th' ? 'รออนุมัติ' : 'Pending approval',
        'paid' => t('status_paid', $language),
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function security_event_label(string $event, ?string $language = null): string
{
    return match ($event) {
        'login_success' => $language === 'th' ? 'เข้าสู่ระบบสำเร็จ' : 'Login success',
        'login_failed' => $language === 'th' ? 'เข้าสู่ระบบไม่สำเร็จ' : 'Login failed',
        'password_changed' => $language === 'th' ? 'เปลี่ยนรหัสผ่าน' : 'Password changed',
        'seller_approved' => $language === 'th' ? 'อนุมัติผู้ขาย' : 'Seller approved',
        'seller_rejected' => $language === 'th' ? 'ปฏิเสธผู้ขาย' : 'Seller rejected',
        'wallet_topup' => $language === 'th' ? 'เติมเงิน' : 'Wallet top up',
        'wallet_review_approve' => $language === 'th' ? 'อนุมัติเติมเงิน' : 'Top up approved',
        'wallet_review_reject' => $language === 'th' ? 'ปฏิเสธเติมเงิน' : 'Top up rejected',
        'broadcast_sent' => $language === 'th' ? 'ส่งประกาศ' : 'Broadcast sent',
        'profile_updated' => $language === 'th' ? 'อัปเดตโปรไฟล์' : 'Profile updated',
        default => ucfirst(str_replace('_', ' ', $event)),
    };
}

function default_theme_setting(string $default = 'light'): string
{
    return pick_value((string) system_setting('default_theme', $default), ['light', 'dark', 'auto'], $default);
}

function default_language_setting(string $default = 'en'): string
{
    return pick_value((string) system_setting('default_language', $default), ['en', 'th'], $default);
}

function default_text_scale_setting(string $default = 'medium'): string
{
    return pick_value((string) system_setting('default_text_scale', $default), ['small', 'medium', 'large', 'xl'], $default);
}

function default_ui_scale_setting(string $default = 'comfortable'): string
{
    return pick_value((string) system_setting('default_ui_scale', $default), ['compact', 'comfortable', 'roomy'], $default);
}

function default_email_notifications_setting(int $default = 1): int
{
    return system_setting('default_email_notifications', (string) $default) === '1' ? 1 : 0;
}

function site_name_setting(string $default = 'WorkConnect'): string
{
    return (string) system_setting('site_name', $default);
}

function support_email_setting(string $default = 'hello@workconnect.test'): string
{
    return (string) system_setting('support_email', $default);
}

function support_phone_setting(string $default = ''): string
{
    return (string) system_setting('support_phone', $default);
}

function currency_symbol_setting(string $default = '฿'): string
{
    return (string) system_setting('currency_symbol', $default);
}

function contact_ig_setting(string $default = 'https://www.instagram.com/waa_xzz/'): string
{
    return (string) system_setting('contact_ig', $default);
}

function announcement_banner_setting(string $default = ''): string
{
    return (string) system_setting('announcement_banner', $default);
}

function announcement_banner_duration_setting(int $default = 15): int
{
    $value = (int) system_setting('announcement_banner_duration', (string) $default);
    return max(10, min(30, $value));
}

function platform_fee_setting(float $default = 10.0): float
{
    return (float) system_setting('platform_fee', (string) $default);
}

function topup_minimum_setting(float $default = 0): float
{
    return (float) system_setting('topup_minimum', (string) $default);
}

function payment_mode_setting(string $default = 'hosted_promptpay'): string
{
    return (string) system_setting('payment_mode', $default);
}

function payment_instructions_setting(string $default = ''): string
{
    return (string) system_setting('payment_instructions', $default);
}

function bank_account_name_setting(string $default = ''): string
{
    return (string) system_setting('bank_account_name', $default);
}

function bank_name_setting(string $default = ''): string
{
    return (string) system_setting('bank_name', $default);
}

function bank_account_number_setting(string $default = ''): string
{
    return (string) system_setting('bank_account_number', $default);
}

function promptpay_id_setting(string $default = ''): string
{
    return (string) system_setting('promptpay_id', $default);
}

function system_setting(string $key, mixed $default = ''): mixed
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (fetch_all('SELECT setting_key, setting_value FROM system_settings') as $row) {
            $cache[(string) $row['setting_key']] = $row['setting_value'];
        }
    }
    $value = $cache[$key] ?? null;
    return $value === false || $value === null || $value === '' ? $default : $value;
}

function set_system_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO system_settings (setting_key,setting_value,updated_at) VALUES (?,?,CURRENT_TIMESTAMP) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP')->execute([$key, $value]);
}

function order_progress_percent(string $status): int
{
    return match ($status) {
        'pending' => 20,
        'in_progress' => 55,
        'review' => 80,
        'completed' => 100,
        'cancelled' => 0,
        default => 0,
    };
}

function notify(int $userId, string $type, string $title, string $body, string $link = '', bool $isDemo = false): void
{
    $stmt = db()->prepare('INSERT INTO notifications (user_id,type,title,body,link,is_demo) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$userId, $type, $title, $body, $link, $isDemo ? 1 : 0]);
}

function realtime_summary(array $user, int $orderId = 0): array
{
    $params = [(int) $user['id'], (int) $user['id'], (int) $user['id'], (int) $user['id']];
    $sql = 'SELECT
        (SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0) AS notifications,
        (SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0) AS messages,
        (SELECT COALESCE(wallet_balance,0) FROM users WHERE id=?) AS wallet_balance,
        (SELECT COALESCE(MAX(created_at), "") FROM notifications WHERE user_id=?) AS notification_version';
    if ($orderId > 0) {
        $sql .= ', (SELECT COALESCE(MAX(created_at), "") FROM messages WHERE order_id=?) AS order_version';
        $params[] = $orderId;
    }
    $row = fetch_one($sql, $params) ?? [];
    return [
        'notifications' => (int) ($row['notifications'] ?? 0),
        'messages' => (int) ($row['messages'] ?? 0),
        'wallet_balance' => (float) ($row['wallet_balance'] ?? 0),
        'notification_version' => (string) ($row['notification_version'] ?? ''),
        'order_version' => $orderId > 0 ? (string) ($row['order_version'] ?? '') : '',
    ];
}

function authorized_order_id_for_user(array $user, int $orderId = 0): int
{
    if ($orderId < 1) {
        return 0;
    }
    $params = [$orderId];
    $sql = 'SELECT id FROM orders WHERE id=?';
    if (($user['role'] ?? '') !== 'admin') {
        $sql .= ' AND (customer_id=? OR seller_id=?)';
        $params[] = (int) $user['id'];
        $params[] = (int) $user['id'];
    }
    return (int) scalar($sql, $params);
}

function fetch_order_for_user(array $user, int $orderId): ?array
{
    $params = [$orderId];
    $sql = 'SELECT * FROM orders WHERE id=?';
    if (($user['role'] ?? '') !== 'admin') {
        $sql .= ' AND (customer_id=? OR seller_id=?)';
        $params[] = (int) $user['id'];
        $params[] = (int) $user['id'];
    }
    return fetch_one($sql, $params);
}

function scalar(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare(database_portable_sql($sql));
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function fetch_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare(database_portable_sql($sql));
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare(database_portable_sql($sql));
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function upload_storage_root(): string
{
    return dirname(__DIR__) . '/storage/private/uploads';
}

function upload_legacy_root(): string
{
    return dirname(__DIR__) . '/assets/uploads';
}

function ensure_upload_protection(string $directory): void
{
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    $htaccess = $directory . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
}

function upload_reference_encode(string $path): string
{
    return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
}

function upload_reference_decode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return (string) base64_decode(strtr($value, '-_', '+/'), true);
}

function is_upload_reference(?string $path): bool
{
    $path = trim((string) $path);
    return str_starts_with($path, 'storage/private/uploads/') || str_starts_with($path, 'assets/uploads/');
}

function upload_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (!is_upload_reference($path)) {
        return $path;
    }
    return '?page=file&ref=' . rawurlencode(upload_reference_encode($path));
}

function upload_local_path(string $path): ?string
{
    $path = trim($path);
    if ($path === '' || str_contains($path, '..')) {
        return null;
    }
    return match (true) {
        str_starts_with($path, 'storage/private/uploads/') => dirname(__DIR__) . '/' . $path,
        str_starts_with($path, 'assets/uploads/') => dirname(__DIR__) . '/' . $path,
        default => null,
    };
}

function can_view_upload(?array $user, string $path): bool
{
    if ($path === '') {
        return false;
    }

    $service = fetch_one('SELECT seller_id,status FROM services WHERE thumbnail=?', [$path]);
    if ($service) {
        if (($service['status'] ?? '') === 'active') {
            return true;
        }
        return !empty($user) && (($user['role'] ?? '') === 'admin' || (int) ($service['seller_id'] ?? 0) === (int) ($user['id'] ?? 0));
    }

    $message = fetch_one('SELECT sender_id,receiver_id FROM messages WHERE attachment=?', [$path]);
    if ($message) {
        return !empty($user) && (($user['role'] ?? '') === 'admin' || in_array((int) ($user['id'] ?? 0), [(int) $message['sender_id'], (int) $message['receiver_id']], true));
    }

    $wallet = fetch_one('SELECT user_id FROM wallet_transactions WHERE slip_path=?', [$path]);
    if ($wallet) {
        return !empty($user) && (($user['role'] ?? '') === 'admin' || (int) ($wallet['user_id'] ?? 0) === (int) ($user['id'] ?? 0));
    }

    $idCardOwner = fetch_one('SELECT id FROM users WHERE id_card_front=? OR id_card_back=?', [$path, $path]);
    if ($idCardOwner) {
        return !empty($user) && (($user['role'] ?? '') === 'admin' || (int) ($idCardOwner['id'] ?? 0) === (int) ($user['id'] ?? 0));
    }

    $avatarOwner = fetch_one('SELECT id FROM users WHERE avatar=?', [$path]);
    if ($avatarOwner) {
        if (empty($user)) {
            return false;
        }
        if (($user['role'] ?? '') === 'admin' || (int) ($avatarOwner['id'] ?? 0) === (int) ($user['id'] ?? 0)) {
            return true;
        }
        if ((int) scalar('SELECT COUNT(*) FROM services WHERE seller_id=? AND status="active"', [(int) $avatarOwner['id']]) > 0) {
            return true;
        }
        return (int) scalar('SELECT COUNT(*) FROM orders WHERE (customer_id=? AND seller_id=?) OR (customer_id=? AND seller_id=?)', [(int) $user['id'], (int) $avatarOwner['id'], (int) $avatarOwner['id'], (int) $user['id']]) > 0;
    }

    return !empty($user) && ($user['role'] ?? '') === 'admin';
}

function store_upload(string $field, array $allowedMime, int $maxBytes = 5242880): string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $maxBytes) {
        throw new RuntimeException('The uploaded file is too large or could not be received.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowedMime[$mime])) {
        throw new RuntimeException('This file type is not supported.');
    }
    $directory = upload_storage_root() . '/' . date('Ym');
    ensure_upload_protection(upload_storage_root());
    ensure_upload_protection($directory);
    ensure_upload_protection(upload_legacy_root());
    $filename = date('Ymd') . '-' . bin2hex(random_bytes(16)) . '.' . $allowedMime[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('The uploaded file could not be stored.');
    }
    return 'storage/private/uploads/' . date('Ym') . '/' . $filename;
}

function delete_stored_upload(string $path): void
{
    if (!str_starts_with($path, 'storage/private/uploads/')) {
        return;
    }
    $localPath = upload_local_path($path);
    if ($localPath !== null && is_file($localPath)) {
        @unlink($localPath);
    }
}

function image_upload_types(): array
{
    return ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
}
