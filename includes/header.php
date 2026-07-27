<?php
$prefs = ui_preferences($user ?? null);
$publicTheme = $prefs['theme'];
?>
<!doctype html>
<html lang="<?= e($prefs['language']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="WorkConnect connects clients with skilled freelancers for quality digital services.">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <?php if ($page !== 'home'): ?><link rel="stylesheet" href="assets/css/apple-ui.css?v=<?= filemtime(__DIR__ . '/../assets/css/apple-ui.css') ?>"><?php endif; ?>
</head>
<body class="public-page<?= $page !== 'home' ? ' apple-shell' : '' ?> theme-<?= e($publicTheme) ?> text-<?= e($prefs['text_scale']) ?> ui-<?= e($prefs['ui_scale']) ?>" data-page="<?= htmlspecialchars($page) ?>" data-theme="<?= e($publicTheme) ?>" data-language="<?= e($prefs['language']) ?>" data-text-scale="<?= e($prefs['text_scale']) ?>" data-ui-scale="<?= e($prefs['ui_scale']) ?>" data-preference-source="guest">
<a class="skip-link" href="#main-content"><?= e($prefs['language'] === 'th' ? 'ข้ามไปเนื้อหาหลัก' : 'Skip to main content') ?></a>
<?php $servicesPage = !empty($user) ? 'marketplace' : 'services'; $aboutPage = !empty($user) ? 'about-workspace' : 'about'; $searchPage = !empty($user) ? 'marketplace' : 'search'; ?>
<header class="site-header">
    <div class="container nav-wrap">
        <a class="brand" href="?page=home" aria-label="WorkConnect home">
            <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
            <span>WorkConnect</span>
        </a>
        <button class="menu-toggle" type="button" aria-label="Open menu" aria-controls="main-navigation" aria-expanded="false"><span></span><span></span></button>
        <nav class="main-nav" id="main-navigation" aria-label="Main navigation">
            <a class="<?= $page === 'home' ? 'active' : '' ?>" href="?page=home"><?= e(t('nav_home', $prefs['language'])) ?></a>
            <a class="<?= in_array($page, ['services', 'marketplace', 'service-detail', 'marketplace-detail'], true) ? 'active' : '' ?>" href="?page=<?= e($servicesPage) ?>"><?= e(t('nav_services', $prefs['language'])) ?></a>
            <a class="<?= in_array($page, ['about', 'about-workspace'], true) ? 'active' : '' ?>" href="?page=<?= e($aboutPage) ?>"><?= e(t('nav_about', $prefs['language'])) ?></a>
            <?php if (!empty($user)): ?><a href="<?= e(role_home($user['role'])) ?>"><?= e(t('nav_workspace', $prefs['language'])) ?></a><?php endif; ?>
            <a href="?page=home#how-it-works"><?= e(t('nav_how', $prefs['language'])) ?></a>
            <a href="#contact"><?= e(t('nav_contact', $prefs['language'])) ?></a>
        </nav>
        <form class="header-search" action="" method="get" role="search">
            <input type="hidden" name="page" value="<?= e($searchPage) ?>">
            <input type="search" name="q" placeholder="<?= e(t('search_placeholder', $prefs['language'])) ?>" aria-label="<?= e(t('search_placeholder', $prefs['language'])) ?>">
            <button type="submit" aria-label="<?= e(t('search_title', $prefs['language'])) ?>"><?= icon_svg('search') ?></button>
        </form>
        <div class="nav-actions">
            <?php if (!empty($user)): ?>
            <a class="text-link" href="?page=notifications"><?= e(t('nav_updates', $prefs['language'])) ?></a>
            <?php if (($user['role'] ?? '') === 'customer'): ?><a class="button button-light button-small" href="?page=topup"><?= e(t('nav_topup', $prefs['language'])) ?></a><?php endif; ?>
            <a class="button button-dark button-small" href="<?= e(role_home($user['role'])) ?>"><?= e(t('nav_open_workspace', $prefs['language'])) ?></a>
            <?php else: ?>
            <a class="text-link" href="?page=login"><?= e(t('nav_login', $prefs['language'])) ?></a>
            <a class="button button-dark button-small" href="?page=register"><?= e(t('nav_signup', $prefs['language'])) ?></a>
            <?php endif; ?>
        </div>
    </div>
</header>
<?php $announcement = trim(announcement_banner_setting()); $announcementDuration = announcement_banner_duration_setting(); $announcementId = hash('sha256', $announcement . '|' . (string) $announcementDuration); if ($announcement !== ''): ?>
<div class="site-announcement" role="status" data-announcement data-duration="<?= e((string) $announcementDuration) ?>" data-announcement-id="<?= e($announcementId) ?>">
    <div class="site-announcement-badge">
        <span><?= e(t('new_label', $prefs['language'])) ?></span>
        <small><?= e($prefs['language'] === 'th' ? 'ประกาศจากแอดมิน' : 'Admin update') ?></small>
    </div>
    <div class="site-announcement-copy">
        <strong><?= e($prefs['language'] === 'th' ? 'อัปเดตล่าสุด' : 'Latest announcement') ?></strong>
        <p><?= e($announcement) ?></p>
    </div>
    <button class="site-announcement-close" type="button" aria-label="<?= e($prefs['language'] === 'th' ? 'ปิดประกาศ' : 'Dismiss announcement') ?>" data-announcement-close>×</button>
    <i class="site-announcement-progress" aria-hidden="true"></i>
</div>
<?php endif; ?>
<main id="main-content" tabindex="-1">
<?php foreach (pull_flashes() as $flash): ?><div class="flash public-flash <?= e($flash['type']) ?>" role="status"><span><?= $page === 'home' ? ($flash['type'] === 'success' ? '✓' : '!') : icon_svg($flash['type'] === 'success' ? 'check-circle' : 'shield') ?></span><p><?= e($flash['message']) ?></p><button type="button" aria-label="Dismiss"><?= $page === 'home' ? '×' : icon_svg('close') ?></button></div><?php endforeach; ?>
