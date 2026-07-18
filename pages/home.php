<?php $lang = ui_language($user ?? null); ?>
<section class="hero">
    <div class="container hero-grid">
        <div class="hero-copy">
            <span class="eyebrow"><b></b><?= e(t('hero_eyebrow', $lang)) ?></span>
            <h1><?= nl2br(e(t('hero_title', $lang))) ?></h1>
            <p><?= e(t('hero_description', $lang)) ?></p>
            <form class="hero-search" action="" method="get">
                <input type="hidden" name="page" value="services">
                <label class="sr-only" for="hero-query"><?= e(t('search_button', $lang)) ?> services</label>
                <input id="hero-query" name="q" type="search" placeholder="<?= e(t('hero_search', $lang)) ?>">
                <button class="button button-dark" type="submit"><?= e(t('search_button', $lang)) ?> <span aria-hidden="true">→</span></button>
            </form>
            <div class="trust-line"><?php if ($marketplaceHasData): ?><span class="avatar-stack"><i>W</i><i>C</i></span><span><strong><?= e(t('real_accounts', $lang, ['count' => number_format($publicUserCount), 'label' => $publicUserCount === 1 ? 'account' : 'accounts'])) ?></strong> growing with WorkConnect.</span><?php else: ?><span class="clean-signal">○</span><span><?= e(t('marketplace_clean_ready', $lang)) ?></span><?php endif; ?></div>
            <div class="hero-highlights">
                <article><b>✓</b><span><strong><?= e(t('benefit_secure_title', $lang)) ?></strong><small><?= e(t('benefit_secure_desc', $lang)) ?></small></span></article>
                <article><b>↗</b><span><strong><?= e(t('benefit_comm_title', $lang)) ?></strong><small><?= e(t('benefit_comm_desc', $lang)) ?></small></span></article>
                <article><b>◷</b><span><strong><?= e(t('benefit_progress_title', $lang)) ?></strong><small><?= e(t('benefit_progress_desc', $lang)) ?></small></span></article>
            </div>
        </div>
        <div class="hero-media">
            <picture><source srcset="assets/images/workconnect-hero.webp" type="image/webp"><img src="assets/images/workconnect-hero.png" alt="WorkConnect marketplace shown on a laptop and phone" width="1536" height="1024" fetchpriority="high"></picture>
            <div class="hero-float hero-float-a">
                <strong><?= e(t('dashboard_completed', $lang)) ?></strong>
                <small><?= e(t('benefit_progress_desc', $lang)) ?></small>
            </div>
            <div class="hero-float hero-float-b">
                <strong><?= e(t('messages_title', $lang)) ?></strong>
                <small><?= e(t('project_conversations_desc', $lang)) ?></small>
            </div>
        </div>
    </div>
</section>

<?php if (demo_management_allowed($user ?? null)): ?>
<section class="demo-section" id="demo">
    <div class="container demo-console <?= $demoInstalled ? 'is-active' : '' ?>" data-animate="fade-up">
        <div class="demo-status"><span><i></i><?= $demoInstalled ? e(t('demo_status_active', $lang)) : e(t('demo_status_clean', $lang)) ?></span><small><?= $demoInstalled ? e(t('demo_status_active_desc', $lang)) : e(t('demo_status_clean_desc', $lang)) ?></small></div>
        <div class="demo-copy"><span class="kicker">Interactive preview</span><h2><?= $demoInstalled ? e(t('demo_preview_active_title', $lang)) : e(t('demo_preview_clean_title', $lang)) ?></h2><p><?= $demoInstalled ? e(t('demo_preview_active_desc', $lang)) : e(t('demo_preview_clean_desc', $lang)) ?></p></div>
        <?php if ($demoInstalled): ?>
        <div class="demo-metrics"><div><strong><?= $demoCounts['users'] ?></strong><span><?= e(t('demo_users', $lang)) ?></span></div><div><strong><?= $demoCounts['services'] ?></strong><span><?= e(t('demo_services', $lang)) ?></span></div><div><strong><?= $demoCounts['orders'] ?></strong><span><?= e(t('demo_orders', $lang)) ?></span></div><div><strong><?= $demoCounts['messages'] ?></strong><span><?= e(t('messages_title', $lang)) ?></span></div><div><strong><?= $demoCounts['payments'] ?></strong><span>Payments</span></div></div>
        <div class="demo-actions"><a class="button button-dark" href="?page=login&demo=customer"><?= e(t('demo_open_customer', $lang)) ?></a><a class="button button-light" href="?page=login&demo=seller"><?= e(t('demo_open_seller', $lang)) ?></a><a class="button button-light" href="?page=login&demo=admin"><?= e(t('demo_open_admin', $lang)) ?></a><button class="demo-clear-trigger" type="button" data-open-demo-clear><?= e(t('demo_clear', $lang)) ?></button></div>
        <?php else: ?>
        <form method="post" class="demo-install-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="install_demo"><button class="button button-dark" type="submit"><span>＋</span> <?= e(t('demo_install_data', $lang)) ?></button><small><?= e(t('demo_install_note', $lang)) ?></small></form>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<section class="home-activity-section">
    <div class="container">
        <?= activity_panel_html('public', 0, $lang) ?>
    </div>
</section>

<section class="onboarding-strip">
    <div class="container">
        <div class="section-heading centered" data-animate="fade-up"><span class="kicker"><?= e(t('home_start_title', $lang)) ?></span><h2><?= e(t('home_start_desc', $lang)) ?></h2></div>
        <div class="onboarding-grid">
            <a class="onboarding-card" href="?page=services" data-animate="fade-up"><span>01</span><div><strong><?= e(t('home_start_customer_title', $lang)) ?></strong><p><?= e(t('home_start_customer_desc', $lang)) ?></p></div></a>
            <a class="onboarding-card" href="?page=register" data-animate="fade-up"><span>02</span><div><strong><?= e(t('home_start_seller_title', $lang)) ?></strong><p><?= e(t('home_start_seller_desc', $lang)) ?></p></div></a>
            <a class="onboarding-card" href="?page=login" data-animate="fade-up"><span>03</span><div><strong><?= e(t('home_start_admin_title', $lang)) ?></strong><p><?= e(t('home_start_admin_desc', $lang)) ?></p></div></a>
        </div>
    </div>
</section>

<?php if ($demoInstalled && demo_management_allowed($user ?? null)): ?><dialog class="confirm-dialog" id="demo-clear-dialog"><button type="button" class="dialog-x" data-close-demo-clear aria-label="Close">×</button><span class="dialog-symbol">−</span><h2><?= e(t('demo_clear_title', $lang)) ?></h2><p><?= e(t('demo_clear_desc', $lang)) ?></p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="clear_demo"><button class="button button-danger button-full" type="submit"><?= e(t('demo_clear_confirm', $lang)) ?></button><button class="button button-light button-full" type="button" data-close-demo-clear><?= e(t('demo_clear_keep', $lang)) ?></button></form></dialog><?php endif; ?>

<section class="category-strip">
    <div class="container">
        <div class="section-heading row-heading" data-animate="fade-up"><div><span class="kicker"><?= e(t('category_kicker', $lang)) ?></span><h2><?= e(t('category_title', $lang)) ?></h2></div><a class="arrow-link" href="?page=services"><?= e(t('view_all_services', $lang)) ?> →</a></div>
        <div class="category-grid">
            <?php foreach ($categories as $category): ?>
            <a class="category-item" href="?page=services&category=<?= urlencode($category['name']) ?>" data-animate="fade-up">
                <span class="category-icon <?= $category['color'] ?>"><?= htmlspecialchars($category['code']) ?></span>
                <span><strong><?= htmlspecialchars($category['name']) ?></strong><small><?= (int)$category['count'] ?> <?= (int)$category['count'] === 1 ? e(t('service_label', $lang)) : e(t('services_label', $lang)) ?></small></span><b aria-hidden="true">→</b>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="how-section" id="how-it-works">
    <div class="container">
        <div class="section-heading centered" data-animate="fade-up"><span class="kicker"><?= e(t('how_kicker', $lang)) ?></span><h2><?= e(t('how_title', $lang)) ?></h2><p><?= e(t('how_description', $lang)) ?></p></div>
        <div class="steps-grid">
            <?php foreach ([['01','step_search_title','step_search_desc'],['02','step_order_title','step_order_desc'],['03','step_collaborate_title','step_collaborate_desc'],['04','step_done_title','step_done_desc']] as $step): ?>
            <article class="step" data-animate="fade-up"><span><?= $step[0] ?></span><h3><?= e(t($step[1], $lang)) ?></h3><p><?= e(t($step[2], $lang)) ?></p></article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="popular-section">
    <div class="container">
        <div class="section-heading row-heading" data-animate="fade-up"><div><span class="kicker"><?= $services ? e(t('popular_chosen_kicker', $lang)) : e(t('popular_marketplace_kicker', $lang)) ?></span><h2><?= $services ? e(t('popular_title', $lang)) : e(t('latest_title', $lang)) ?></h2></div><a class="arrow-link" href="?page=services"><?= e(t('browse_all', $lang)) ?> →</a></div>
        <?php if ($services): ?><div class="service-grid home-services"><?php foreach ($services as $service) require __DIR__ . '/../includes/service-card.php'; ?></div><?php else: ?><div class="home-empty" data-animate="fade-up"><span>◇</span><div><h3><?= e(t('home_no_services_title', $lang)) ?></h3><p><?= e(t('home_no_services_desc', $lang)) ?></p></div><a class="button button-light" href="?page=register"><?= e(t('home_create_seller', $lang)) ?></a></div><?php endif; ?>
        <div class="benefits-band benefits-band-home" data-animate="fade-up">
            <div><b>✓</b><span><strong><?= e(t('benefit_secure_title', $lang)) ?></strong><small><?= e(t('benefit_secure_desc', $lang)) ?></small></span></div>
            <div><b>↗</b><span><strong><?= e(t('benefit_comm_title', $lang)) ?></strong><small><?= e(t('benefit_comm_desc', $lang)) ?></small></span></div>
            <div><b>◷</b><span><strong><?= e(t('benefit_progress_title', $lang)) ?></strong><small><?= e(t('benefit_progress_desc', $lang)) ?></small></span></div>
        </div>
    </div>
</section>
