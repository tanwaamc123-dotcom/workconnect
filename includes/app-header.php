<?php
$prefs = ($user && ($user['role'] ?? '') === 'admin') ? admin_ui_preferences($user) : ui_preferences($user ?? null);
$realtimeBootstrap = realtime_summary($user);
$notificationCount = (int) $realtimeBootstrap['notifications'];
$messageCount = (int) $realtimeBootstrap['messages'];
$walletBalance = (float) $realtimeBootstrap['wallet_balance'];
?>
<!doctype html>
<html lang="<?= e($prefs['language']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="WorkConnect workspace">
    <title><?= e($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/apple-ui.css?v=<?= filemtime(__DIR__ . '/../assets/css/apple-ui.css') ?>">
</head>
<body class="workspace-page theme-<?= e($prefs['theme']) ?> apple-shell text-<?= e($prefs['text_scale']) ?> ui-<?= e($prefs['ui_scale']) ?>" data-page="<?= e($page) ?>" data-theme="<?= e($prefs['theme']) ?>" data-language="<?= e($prefs['language']) ?>" data-text-scale="<?= e($prefs['text_scale']) ?>" data-ui-scale="<?= e($prefs['ui_scale']) ?>" data-preference-source="<?= e(($user['role'] ?? '') === 'admin' ? 'admin' : 'user') ?>" data-unread-notifications="<?= $notificationCount ?>" data-unread-messages="<?= $messageCount ?>">
<a class="skip-link" href="#main-content"><?= e($prefs['language'] === 'th' ? 'ข้ามไปเนื้อหาหลัก' : 'Skip to main content') ?></a>
<?php
$navGroups = [
    'customer' => [
        [t('group_workspace', $prefs['language']), [['dashboard', t('side_overview', $prefs['language']), 'home'], ['marketplace', t('side_find_services', $prefs['language']), 'search'], ['saved-services', t('saved_services_title', $prefs['language']), 'saved'], ['orders', t('side_orders', $prefs['language']), 'orders'], ['messages', t('side_messages', $prefs['language']), 'messages'], ['disputes', $prefs['language'] === 'th' ? 'ข้อพิพาท' : 'Disputes', 'shield'], ['notifications', t('side_notifications', $prefs['language']), 'notifications'], ['topup', t('side_topup', $prefs['language']), 'wallet']]],
        [t('group_account', $prefs['language']), [['profile', t('side_profile', $prefs['language']), 'profile'], ['settings', t('side_settings', $prefs['language']), 'settings'], ['about-workspace', t('side_about', $prefs['language']), 'about']]],
    ],
    'seller' => [
        [t('group_workspace', $prefs['language']), [['seller-dashboard', t('side_overview', $prefs['language']), 'home'], ['seller-services', t('side_services', $prefs['language']), 'services'], ['seller-add-service', t('side_add_service', $prefs['language']), 'add'], ['seller-orders', t('side_manage_orders', $prefs['language']), 'orders'], ['seller-messages', t('side_messages', $prefs['language']), 'messages']]],
        [t('group_business', $prefs['language']), [['seller-earnings', t('side_earnings', $prefs['language']), 'finance'], ['seller-payouts', $prefs['language'] === 'th' ? 'รับเงิน' : 'Payouts', 'topup'], ['disputes', $prefs['language'] === 'th' ? 'ข้อพิพาท' : 'Disputes', 'shield'], ['seller-analytics', t('side_analytics', $prefs['language']), 'analytics'], ['seller-profile', t('side_profile', $prefs['language']), 'profile'], ['seller-settings', t('side_settings', $prefs['language']), 'settings']]],
    ],
    'admin' => [
        [t('group_management', $prefs['language']), [['admin-users', t('side_users', $prefs['language']), 'users'], ['admin-services', t('side_services', $prefs['language']), 'services'], ['admin-orders', t('side_orders', $prefs['language']), 'orders'], ['admin-messages', t('side_messages', $prefs['language']), 'messages'], ['admin-disputes', $prefs['language'] === 'th' ? 'ข้อพิพาท' : 'Disputes', 'shield'], ['admin-payouts', $prefs['language'] === 'th' ? 'การจ่ายเงิน' : 'Payouts', 'topup']]],
        [t('group_system', $prefs['language']), [['admin-control', t('side_control_center', $prefs['language']), 'home'], ['admin-approvals', t('side_approvals', $prefs['language']), 'profile'], ['admin-moderation', t('side_moderation', $prefs['language']), 'moderation'], ['admin-categories', t('side_categories', $prefs['language']), 'categories'], ['admin-coupons', t('side_coupons', $prefs['language']), 'coupon'], ['admin-logs', t('side_logs', $prefs['language']), 'logs'], ['admin-broadcast', t('side_broadcast', $prefs['language']), 'broadcast'], ['admin-export', t('side_export', $prefs['language']), 'export'], ['admin-reports', t('side_reports', $prefs['language']), 'analytics'], ['admin-finance', t('side_finance', $prefs['language']), 'finance'], ['admin-settings', t('side_system_settings', $prefs['language']), 'settings']]],
        [t('group_account', $prefs['language']), [['admin-security', $prefs['language'] === 'th' ? 'ความปลอดภัย' : 'Security', 'security']]],
    ],
];
$adminRouteCapabilities = [
    'admin-users' => 'users.view',
    'admin-services' => 'services.manage',
    'admin-orders' => 'orders.view',
    'admin-messages' => 'messages.view',
    'admin-disputes' => 'disputes.manage',
    'admin-payouts' => 'payout.manage',
    'admin-control' => 'reports.view',
    'admin-approvals' => 'users.manage',
    'admin-moderation' => 'services.manage',
    'admin-categories' => 'categories.manage',
    'admin-coupons' => 'coupons.manage',
    'admin-logs' => 'reports.view',
    'admin-broadcast' => 'broadcast.manage',
    'admin-export' => 'export.read',
    'admin-reports' => 'reports.view',
    'admin-finance' => 'finance.view',
    'admin-settings' => 'system.manage',
];
?>
<header class="workspace-topbar">
    <button class="workspace-menu" type="button" aria-label="Open workspace navigation" aria-controls="workspace-navigation" aria-expanded="false"><span></span><span></span></button>
    <a class="brand" href="<?= e(role_home($user['role'])) ?>"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span><span>WorkConnect</span></a>
    <span class="workspace-role"><?= e($user['role_label']) ?> workspace</span>
    <form class="workspace-search" action="" method="get" role="search">
        <input type="hidden" name="page" value="marketplace">
        <input type="search" name="q" placeholder="<?= e(t('search_placeholder', $prefs['language'])) ?>" aria-label="<?= e(t('search_placeholder', $prefs['language'])) ?>">
        <button type="submit" aria-label="<?= e(t('search_title', $prefs['language'])) ?>"><?= icon_svg('search') ?></button>
    </form>
    <div class="workspace-top-actions">
        <a class="top-icon" href="?page=notifications" aria-label="Notifications"><?= icon_svg('notifications') ?><?php if ($notificationCount): ?><b><?= $notificationCount ?></b><?php endif; ?></a>
        <?php if ($user['role'] === 'customer'): ?><a class="top-wallet" href="?page=topup" aria-label="<?= e(t('nav_topup', $prefs['language'])) ?>"><span><?= icon_svg('topup') ?></span><strong data-wallet-balance><?= money($walletBalance) ?></strong></a><?php endif; ?>
        <?php $profileHref = $user['role'] === 'seller' ? '?page=seller-profile' : ($user['role'] === 'admin' ? '?page=admin-security' : '?page=profile'); ?>
        <a class="top-profile" href="<?= e($profileHref) ?>"><span class="<?= $user['avatar'] ? 'has-image' : '' ?>" <?= $user['avatar'] ? 'style="background-image:url('.e(upload_url($user['avatar'])).')"' : '' ?>><?= $user['avatar'] ? '' : e(initials($user['name'])) ?></span><div><strong><?= e($user['name']) ?></strong><small><?= (int)$user['is_demo'] === 1 ? 'Demo · ' : '' ?><?= e($user['role_label']) ?></small></div></a>
    </div>
</header>
<aside class="workspace-sidebar" id="workspace-navigation">
    <nav aria-label="Workspace navigation">
        <?php foreach ($navGroups[$user['role']] as [$group, $items]): ?>
            <span class="nav-group-label"><?= e($group) ?></span>
            <?php foreach ($items as [$route, $label, $icon]): ?>
            <?php if ($user['role'] === 'admin' && isset($adminRouteCapabilities[$route]) && !admin_can($user, $adminRouteCapabilities[$route])) continue; ?>
            <a class="<?= $page === $route || ($route === 'messages' && $page === 'seller-messages') ? 'active' : '' ?>" href="?page=<?= e($route) ?>"><i><?= icon_svg($icon) ?></i><span><?= e($label) ?></span><?php if (str_contains($route, 'messages') && $messageCount): ?><b><?= $messageCount ?></b><?php endif; ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer-links"><?php if (demo_management_allowed($user) && demo_is_installed() && ($user['role'] !== 'admin' || admin_can($user, 'system.manage'))): ?><a class="sidebar-demo-link" href="?page=admin-settings#demo"><i></i><span><strong>Demo data active</strong><small>Manage demo</small></span></a><?php endif; ?><a class="workspace-home-link" href="?page=home"><?= icon_svg('arrow-left') ?><span>WorkConnect Home</span></a></div>
    <form method="post" class="sidebar-logout"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button type="submit"><i><?= icon_svg('logout') ?></i> Sign out</button></form>
</aside>
<main class="workspace-main" id="main-content" tabindex="-1">
    <?php foreach (pull_flashes() as $flash): ?><div class="flash <?= e($flash['type']) ?>" role="status"><span><?= icon_svg($flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'error' ? 'shield' : 'info')) ?></span><p><?= e($flash['message']) ?></p><button type="button" aria-label="Dismiss"><?= icon_svg('close') ?></button></div><?php endforeach; ?>
