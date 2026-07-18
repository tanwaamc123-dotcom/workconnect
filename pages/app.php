<?php

declare(strict_types=1);

function page_heading(string $eyebrow, string $heading, string $description = '', string $action = ''): void
{
    echo '<header class="app-heading"><div><span>' . e($eyebrow) . '</span><h1>' . e($heading) . '</h1>';
    if ($description !== '') echo '<p>' . e($description) . '</p>';
    echo '</div>' . $action . '</header>';
}

function post_fields(string $action, string $returnTo = ''): void
{
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="' . e($action) . '">';
    if ($returnTo !== '' && is_internal_path($returnTo)) echo '<input type="hidden" name="return_to" value="' . e($returnTo) . '">';
}

function service_rows(string $where = 'services.status="active"', array $params = []): array
{
    return fetch_all("SELECT services.*,categories.name AS category,categories.color,users.name AS seller,
        users.status AS seller_status,users.created_at AS seller_joined,
        COALESCE(AVG(reviews.rating),0) AS rating,COUNT(DISTINCT reviews.id) AS review_count,
        COUNT(DISTINCT CASE WHEN orders.status='completed' THEN orders.id END) AS completed_orders
        FROM services JOIN categories ON categories.id=services.category_id JOIN users ON users.id=services.seller_id
        LEFT JOIN orders ON orders.service_id=services.id LEFT JOIN reviews ON reviews.order_id=orders.id
        WHERE $where GROUP BY services.id ORDER BY services.created_at DESC", $params);
}

$lang = (($user['role'] ?? '') === 'admin') ? admin_ui_language($user) : ui_language($user);
$sharedMessagesPage = in_array($page, ['messages', 'seller-messages'], true);
$sharedProfilePage = in_array($page, ['profile', 'seller-profile'], true);
$sharedSettingsPage = in_array($page, ['settings', 'seller-settings'], true);
$workspaceServicesPage = 'marketplace';
$workspaceServiceDetailPage = 'marketplace-detail';

if (in_array($page, ['about', 'about-workspace'], true)):
    page_heading(t('about_company', $lang), t('about_heading', $lang), t('about_description', $lang));
?>
<section class="app-hero-band"><div><span class="kicker"><?= e(t('about_purpose', $lang)) ?></span><h2><?= e(t('about_good_work', $lang)) ?></h2><p><?= e(t('about_good_work_desc', $lang)) ?></p></div><div class="about-principles"><article><b>01</b><strong><?= e(t('about_principle_1_title', $lang)) ?></strong><p><?= e(t('about_principle_1_desc', $lang)) ?></p></article><article><b>02</b><strong><?= e(t('about_principle_2_title', $lang)) ?></strong><p><?= e(t('about_principle_2_desc', $lang)) ?></p></article><article><b>03</b><strong><?= e(t('about_principle_3_title', $lang)) ?></strong><p><?= e(t('about_principle_3_desc', $lang)) ?></p></article></div></section>
<section class="content-section"><div class="section-title"><h2><?= e(t('about_project_direction', $lang)) ?></h2><p><?= e(t('about_project_direction_desc', $lang)) ?></p></div><div class="metric-grid"><article><strong>48</strong><span><?= e(t('about_specialties', $lang)) ?></span></article><article><strong>3</strong><span><?= e(t('about_workspaces', $lang)) ?></span></article><article><strong>1</strong><span><?= e(t('about_record', $lang)) ?></span></article><article><strong>100%</strong><span><?= e(t('about_access', $lang)) ?></span></article></div></section>

<?php elseif ($page === 'privacy'): ?>
    <?php page_heading('Privacy', 'Privacy Policy', 'How WorkConnect handles your account, project, and usage data.'); ?>
    <section class="content-section narrow">
        <div class="section-title"><h2>What we collect</h2><p>We only collect the information needed to run the marketplace: account details, profile content, order data, messages, notifications, and wallet activity.</p></div>
        <div class="faq-grid">
            <details class="faq-item" open><summary>Why do we store your data?</summary><p>To let buyers and sellers communicate, place orders, track progress, manage payments, and receive notifications.</p></details>
            <details class="faq-item"><summary>Do we share personal information?</summary><p>No public sharing by default. Data is used inside WorkConnect for product operation, moderation, and support.</p></details>
            <details class="faq-item"><summary>How long is data kept?</summary><p>We keep account and transaction records while the account exists, and retain essential records if required for safety, support, or legal reasons.</p></details>
            <details class="faq-item"><summary>Can I delete my account?</summary><p>Yes. We can support account removal or data review when you are ready to close the account.</p></details>
        </div>
    </section>
    <section class="content-section narrow">
        <div class="section-title"><h2>Your controls</h2><p>You stay in control of your account data, profile content, and communication history as long as the account is active.</p></div>
        <div class="metric-grid">
            <article><strong>Profile</strong><span>Update name, bio, and contact details.</span></article>
            <article><strong>Messages</strong><span>Project chats stay tied to each order.</span></article>
            <article><strong>Wallet</strong><span>Top up and payment records stay visible in your workspace.</span></article>
            <article><strong>Review</strong><span>Ask us to check or remove data when needed.</span></article>
        </div>
    </section>

<?php elseif ($page === 'help-center'): ?>
    <?php page_heading('Support', 'Help Center', 'Short answers for the most common WorkConnect questions.'); ?>
    <section class="content-section narrow">
        <div class="faq-grid">
            <details class="faq-item" open><summary>How do I find a service?</summary><p>Go to Services, search by keyword or category, then open a service card to view details and pricing.</p></details>
            <details class="faq-item"><summary>How do I contact a seller?</summary><p>Open the service or order page and use Messages to keep all project communication in one place.</p></details>
            <details class="faq-item"><summary>What is Top up for?</summary><p>Top up adds wallet balance so you can pay inside the platform without leaving the workspace.</p></details>
            <details class="faq-item"><summary>Why is seller approval required?</summary><p>Seller approval keeps the marketplace cleaner and helps reduce low-quality or unsafe listings.</p></details>
            <details class="faq-item"><summary>What if I do not see my order?</summary><p>Check Orders and Messages first. If it still does not appear, reload once and confirm you are signed in to the correct account.</p></details>
        </div>
    </section>

<?php elseif ($page === 'safety'): ?>
    <?php page_heading('Safety', 'Safety & Trust', 'The rules and checks that keep WorkConnect usable and safe.'); ?>
    <section class="content-section narrow">
        <div class="app-hero-band"><div><span class="kicker">Safety</span><h2>Keep work clear, paid, and traceable.</h2><p>WorkConnect keeps order status, messages, and wallet actions in one record so both sides can review the full trail.</p></div><div class="about-principles"><article><b>01</b><strong>Verified sellers</strong><p>Seller accounts can be reviewed before activation.</p></article><article><b>02</b><strong>Message history</strong><p>Project chats stay attached to each order.</p></article><article><b>03</b><strong>Transparent payments</strong><p>Wallet and order flow are shown inside the workspace.</p></article></div></div>
        <div class="faq-grid">
            <details class="faq-item" open><summary>How do we reduce scams?</summary><p>By keeping orders, messages, and payment steps inside the platform and by making seller approval visible.</p></details>
            <details class="faq-item"><summary>What should users report?</summary><p>Report spam, fake listings, suspicious payment requests, abusive messages, or copied work.</p></details>
            <details class="faq-item"><summary>What happens if an account breaks the rules?</summary><p>Admins can suspend or remove access to protect the marketplace.</p></details>
        </div>
    </section>
    <section class="content-section narrow">
        <div class="section-title"><h2>Reporting flow</h2><p>When something looks wrong, keep the message trail, order number, and screenshots together so the review is faster.</p></div>
        <div class="metric-grid">
            <article><strong>1</strong><span>Open the order or message thread.</span></article>
            <article><strong>2</strong><span>Report the issue with a short note.</span></article>
            <article><strong>3</strong><span>Admin reviews and takes action if needed.</span></article>
            <article><strong>4</strong><span>Keep communication inside WorkConnect.</span></article>
        </div>
    </section>

<?php elseif ($page === 'community'): ?>
    <?php page_heading('Community', 'Community Guidelines', 'Simple rules for clean collaboration on WorkConnect.'); ?>
    <section class="content-section narrow">
        <div class="faq-grid">
            <details class="faq-item" open><summary>Be clear</summary><p>State scope, deadline, and expected output before starting work.</p></details>
            <details class="faq-item"><summary>Be respectful</summary><p>Keep messages professional and project-focused.</p></details>
            <details class="faq-item"><summary>Be honest</summary><p>Do not misrepresent skills, portfolio, or delivery progress.</p></details>
            <details class="faq-item"><summary>Be safe</summary><p>Use the platform tools for communication and payment whenever possible.</p></details>
        </div>
    </section>
    <section class="content-section narrow">
        <div class="section-title"><h2>Working together well</h2><p>Good projects are easier to finish when both sides keep the scope and status visible.</p></div>
        <div class="metric-grid">
            <article><strong>Scope</strong><span>Agree on what is included before work starts.</span></article>
            <article><strong>Updates</strong><span>Share progress early instead of waiting until the end.</span></article>
            <article><strong>Feedback</strong><span>Keep revisions specific and constructive.</span></article>
            <article><strong>Credit</strong><span>Respect original work and ownership.</span></article>
        </div>
    </section>

<?php elseif ($page === 'services' || $page === 'marketplace'):
    $q = trim((string) ($_GET['q'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $where = 'services.status="active"'; $params = [];
    if ($q !== '') { $where .= ' AND (services.title LIKE ? OR services.description LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
    if ($category !== '') { $where .= ' AND categories.name=?'; $params[] = $category; }
    $listedServices = service_rows($where, $params);
    $categoriesDb = fetch_all('SELECT * FROM categories ORDER BY id');
    page_heading(t('services_page_title', $lang), t('services_page_heading', $lang), t('services_page_desc', $lang));
?>
<form class="catalog-toolbar" method="get"><input type="hidden" name="page" value="<?= e($page === 'marketplace' ? $workspaceServicesPage : 'services') ?>"><label><span>⌕</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('services_search_placeholder', $lang)) ?>"></label><select name="category"><option value=""><?= e(t('services_all_categories', $lang)) ?></option><?php foreach ($categoriesDb as $cat): ?><option value="<?= e($cat['name']) ?>" <?= $category === $cat['name'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?></select><button class="button button-dark" type="submit"><?= e(t('search_button', $lang)) ?></button></form>
<div class="catalog-summary"><span><?= e(t('services_count', $lang, ['count' => count($listedServices)])) ?></span><small><?= e(t('services_price_note', $lang)) ?></small></div>
<section class="app-service-grid"><?php foreach ($listedServices as $item): $cover = service_cover_path($item); $detailPage = $page === 'marketplace' ? $workspaceServiceDetailPage : 'service-detail'; ?><article class="app-service-card"><a class="app-service-cover custom-cover" href="?page=<?= e($detailPage) ?>&id=<?= (int) $item['id'] ?>"><?php if ($cover !== ''): ?><img src="<?= e($cover) ?>" alt="<?= e($item['title']) ?>"><?php endif; ?><span><?= e($item['category']) ?></span><b><?= e(substr($item['title'],0,2)) ?></b></a><div><span class="seller-mini"><i><?= e(initials($item['seller'])) ?></i><?= e($item['seller']) ?></span><h2><a href="?page=<?= e($detailPage) ?>&id=<?= (int) $item['id'] ?>"><?= e($item['title']) ?></a></h2><p><?= e(mb_strimwidth($item['description'],0,88,'...')) ?></p><footer><span>★ <?= $item['rating'] > 0 ? number_format((float)$item['rating'],1) : e(t('new_label', $lang)) ?> <small>(<?= (int)$item['review_count'] ?>)</small></span><strong><?= e(t('from_label', $lang)) ?> <?= money($item['price']) ?></strong></footer></div></article><?php endforeach; ?><?php if (!$listedServices): ?><div class="app-empty"><b>⌕</b><h2><?= e(t('service_no_match', $lang)) ?></h2><p><?= e(t('service_no_match_desc', $lang)) ?></p></div><?php endif; ?></section>

<?php elseif ($page === 'service-detail' || $page === 'marketplace-detail'):
    $serviceId = (int) ($_GET['id'] ?? 0);
    $service = fetch_one('SELECT services.*,categories.name AS category,users.name AS seller,users.bio AS seller_bio,users.status AS seller_status,users.created_at AS seller_joined,
        COALESCE((SELECT COUNT(*) FROM orders WHERE seller_id=users.id AND status="completed"),0) AS completed_orders,
        COALESCE((SELECT COUNT(*) FROM reviews WHERE seller_id=users.id),0) AS review_count
        FROM services JOIN categories ON categories.id=services.category_id JOIN users ON users.id=services.seller_id WHERE services.id=? AND services.status="active"', [$serviceId]);
    $backToServicesPage = $page === 'marketplace-detail' ? $workspaceServicesPage : 'services';
    if (!$service) { echo empty_state_html(t('service_not_found', $lang), t('service_back_to_services', $lang), t('service_back_to_services', $lang), '?page=' . $backToServicesPage, '⌕'); return; }
    db()->prepare('UPDATE services SET views=views+1 WHERE id=?')->execute([$serviceId]);
    $reviews = fetch_all('SELECT reviews.*,users.name FROM reviews JOIN users ON users.id=reviews.customer_id JOIN orders ON orders.id=reviews.order_id WHERE orders.service_id=? ORDER BY reviews.created_at DESC', [$serviceId]);
    $favoriteReturn = e(safe_return_to((string) ($_SERVER['REQUEST_URI'] ?? ''), '?page=' . $backToServicesPage . '&id=' . $serviceId));
    page_heading($service['category'], $service['title'], t('service_by', $lang, ['name' => $service['seller']]), '<div class="row-actions"><a class="button button-light" href="?page=' . e($backToServicesPage) . '">← '.e(t('service_back_to_services', $lang)).'</a>'.(!empty($user) && ($user['role'] ?? '') === 'customer' ? '<form class="favorite-form" method="post"><input type="hidden" name="csrf_token" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="toggle_favorite"><input type="hidden" name="service_id" value="'.$serviceId.'"><input type="hidden" name="return_to" value="'.$favoriteReturn.'"><button class="button button-dark">'.(is_favorite_service((int) $user['id'], $serviceId) ? e(t('favorite_saved', $lang)) : e(t('favorite_save', $lang))).'</button></form>' : '').'</div>');
?>
<div class="detail-layout"><section class="detail-main"><?php $cover = service_cover_path($service); ?><div class="service-showcase custom-cover"><?php if ($cover !== ''): ?><img src="<?= e($cover) ?>" alt="<?= e($service['title']) ?>"><?php endif; ?><span><?= e(t('workconnect_selected_service', $lang)) ?></span><strong><?= e($service['title']) ?></strong></div><article class="detail-section"><h2><?= e(t('service_about', $lang)) ?></h2><p><?= nl2br(e($service['description'])) ?></p></article><article class="detail-section"><h2><?= e(t('service_included', $lang)) ?></h2><ul class="feature-list"><?php foreach (array_filter(explode("\n",$service['features'])) as $feature): ?><li><span>✓</span><?= e($feature) ?></li><?php endforeach; ?></ul></article><article class="detail-section"><h2><?= e(t('service_feedback', $lang)) ?></h2><?php if ($reviews): foreach ($reviews as $review): ?><div class="review-row"><span><?= e(initials($review['name'])) ?></span><div><strong><?= e($review['name']) ?> · <?= str_repeat('★',(int)$review['rating']) ?></strong><p><?= e($review['comment']) ?></p></div></div><?php endforeach; else: ?><p class="muted"><?= e(t('service_first_project_note', $lang)) ?></p><?php endif; ?></article></section><aside class="purchase-panel"><span><?= e(t('service_package', $lang)) ?></span><strong><?= money($service['price']) ?></strong><p><?= e(t('service_one_project', $lang)) ?></p><div class="trust-strip"><span class="trust-badge <?= $service['seller_status']==='active' ? 'good' : '' ?>"><?= e(t('trust_verified', $lang)) ?></span><span class="trust-badge good"><?= number_format((int)$service['completed_orders']) ?> <?= e(t('trust_orders_completed', $lang)) ?></span><span class="trust-badge"><?= number_format((int)$service['review_count']) ?> <?= e(t('trust_reviews', $lang)) ?></span></div><ul><li>✓ <?= e(t('service_delivery', $lang, ['days' => (int)$service['delivery_days']])) ?></li><li>✓ <?= e(t('service_payment_protection', $lang)) ?></li><li>✓ <?= e(t('service_messaging', $lang)) ?></li></ul><?php if (!empty($user) && ($user['role'] ?? '')==='customer'): ?><a class="button button-dark button-full" href="?page=checkout&id=<?= $serviceId ?>"><?= e(t('checkout_title', $lang)) ?></a><?php else: ?><p class="role-note"><?= e(t('customer_account_only', $lang)) ?></p><?php endif; ?><div class="seller-box"><span><?= e(initials($service['seller'])) ?></span><div><strong><?= e($service['seller']) ?></strong><small><?= e($service['seller_bio'] ?: t('service_verified_seller', $lang)) ?></small></div></div></aside></div>

<?php elseif ($page === 'search'):
    $query = trim((string) ($_GET['q'] ?? ''));
    $scope = in_array($_GET['scope'] ?? 'all', ['all', 'services', 'orders', 'messages', 'users'], true) ? (string) ($_GET['scope'] ?? 'all') : 'all';
    $viewer = current_user();
    page_heading(t('search_title', $lang), t('search_desc', $lang));
?>
<section class="search-page">
    <form class="search-toolbar" method="get">
        <input type="hidden" name="page" value="search">
        <div class="catalog-toolbar">
            <label><span>⌕</span><input type="search" name="q" value="<?= e($query) ?>" placeholder="<?= e(t('search_placeholder', $lang)) ?>"></label>
            <button class="button button-dark" type="submit"><?= e(t('search_title', $lang)) ?></button>
        </div>
    </form>
    <div class="table-filters">
        <?php foreach (['all' => t('search_all', $lang), 'services' => t('search_services', $lang), 'orders' => t('search_orders', $lang), 'messages' => t('search_messages', $lang), 'users' => t('search_users', $lang)] as $value => $label): ?>
            <a class="<?= $scope === $value ? 'active' : '' ?>" href="?page=search&q=<?= urlencode($query) ?>&scope=<?= $value ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($query === ''): ?>
        <?= empty_state_html(t('search_title', $lang), t('search_desc', $lang), t('dashboard_find_service', $lang), '?page=services', '⌕') ?>
    <?php else: ?>
        <?php if ($scope === 'all' || $scope === 'services'): $servicesFound = service_rows('(services.status="active" AND (services.title LIKE ? OR services.description LIKE ? OR users.name LIKE ? OR categories.name LIKE ?))', ["%$query%","%$query%","%$query%","%$query%"]); ?>
            <section class="search-section">
                <div class="section-heading"><h2><?= e(t('search_services', $lang)) ?></h2><p><?= e(t('services_page_desc', $lang)) ?></p></div>
                <div class="app-service-grid">
                    <?php foreach ($servicesFound as $service): require __DIR__ . '/../includes/service-card.php'; endforeach; ?>
                    <?php if (!$servicesFound): ?><div class="search-results-grid"><?= empty_state_html(t('search_no_results', $lang), t('services_search_placeholder', $lang), t('search_services', $lang), '?page=services', '⌕') ?></div><?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
        <?php if ($viewer && ($scope === 'all' || $scope === 'orders')): ?>
            <?php
                $orderWhere = $viewer['role'] === 'admin' ? '(orders.order_number LIKE ? OR services.title LIKE ? OR customer.name LIKE ? OR seller.name LIKE ?)' : '((orders.customer_id=? OR orders.seller_id=?) AND (orders.order_number LIKE ? OR services.title LIKE ?))';
                $orderParams = $viewer['role'] === 'admin' ? ["%$query%","%$query%","%$query%","%$query%"] : [(int)$viewer['id'], (int)$viewer['id'], "%$query%", "%$query%"];
                $foundOrders = fetch_all("SELECT orders.*,services.title,customer.name AS customer,seller.name AS seller FROM orders JOIN services ON services.id=orders.service_id JOIN users customer ON customer.id=orders.customer_id JOIN users seller ON seller.id=orders.seller_id WHERE $orderWhere ORDER BY orders.created_at DESC LIMIT 8", $orderParams);
            ?>
            <section class="search-section">
                <div class="section-heading"><h2><?= e(t('search_orders', $lang)) ?></h2><p><?= e(t('project_progress_desc', $lang)) ?></p></div>
                <div class="search-results-grid">
                    <?php foreach ($foundOrders as $order): ?>
                        <article class="search-result-card">
                            <strong><?= e($order['title']) ?></strong>
                            <p><?= e($order['order_number']) ?> · <?= e($order['seller']) ?> · <?= e(status_label($order['status'], $lang)) ?></p>
                            <small><?= short_date($order['created_at']) ?> · <?= money($order['total']) ?></small>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$foundOrders): ?><div class="search-results-grid"><?= empty_state_html(t('search_no_results', $lang), t('orders_page_desc', $lang), t('orders_page_title', $lang), '?page=orders', '▣') ?></div><?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
        <?php if ($viewer && ($scope === 'all' || $scope === 'messages')): ?>
            <?php
                $messageWhere = $viewer['role'] === 'admin' ? '(messages.body LIKE ? OR orders.order_number LIKE ?)' : '((messages.sender_id=? OR messages.receiver_id=?) AND (messages.body LIKE ? OR orders.order_number LIKE ?))';
                $messageParams = $viewer['role'] === 'admin' ? ["%$query%","%$query%"] : [(int)$viewer['id'], (int)$viewer['id'], "%$query%", "%$query%"];
                $foundMessages = fetch_all("SELECT messages.*,orders.order_number,users.name AS sender_name FROM messages LEFT JOIN orders ON orders.id=messages.order_id LEFT JOIN users ON users.id=messages.sender_id WHERE $messageWhere ORDER BY messages.created_at DESC LIMIT 8", $messageParams);
            ?>
            <section class="search-section">
                <div class="section-heading"><h2><?= e(t('search_messages', $lang)) ?></h2><p><?= e(t('project_conversations_desc', $lang)) ?></p></div>
                <div class="search-results-grid">
                    <?php foreach ($foundMessages as $message): ?>
                        <article class="search-result-card">
                            <strong><?= e($message['sender_name'] ?: t('messages_title', $lang)) ?></strong>
                            <p><?= e(mb_strimwidth($message['body'], 0, 90, '...')) ?></p>
                            <small><?= e($message['order_number'] ?: t('no_order_label', $lang)) ?> · <?= relative_time($message['created_at']) ?></small>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$foundMessages): ?><div class="search-results-grid"><?= empty_state_html(t('search_no_results', $lang), t('messages_title', $lang), t('messages_title', $lang), '?page=messages', '◇') ?></div><?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
        <?php if ($viewer && $viewer['role'] === 'admin' && ($scope === 'all' || $scope === 'users')): ?>
            <?php $foundUsers = fetch_all('SELECT users.*,roles.label AS role_label FROM users JOIN roles ON roles.id=users.role_id WHERE users.name LIKE ? OR users.email LIKE ? ORDER BY users.created_at DESC LIMIT 8', ["%$query%","%$query%"]); ?>
            <section class="search-section">
                <div class="section-heading"><h2><?= e(t('search_users', $lang)) ?></h2><p><?= e(t('admin_users_desc', $lang)) ?></p></div>
                <div class="search-results-grid">
                    <?php foreach ($foundUsers as $member): ?>
                        <article class="search-result-card">
                            <strong><?= e($member['name']) ?></strong>
                            <p><?= e($member['email']) ?> · <?= e($member['role_label']) ?></p>
                            <small><?= short_date($member['created_at']) ?> · <?= e(status_label($member['status'], $lang)) ?></small>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$foundUsers): ?><div class="search-results-grid"><?= empty_state_html(t('search_no_results', $lang), t('search_users', $lang), t('search_users', $lang), '?page=admin-users', '◎') ?></div><?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php elseif ($page === 'saved-services'):
    $savedServices = service_rows('services.id IN (SELECT service_id FROM favorites WHERE user_id=?) AND services.status="active"', [(int) $user['id']]);
    $savedCount = count($savedServices);
    page_heading(t('saved_services_title', $lang), t('saved_services_title', $lang), t('saved_services_desc', $lang), '<a class="button button-dark" href="?page=services">'.e(t('search_services', $lang)).'</a>');
?>
<section class="saved-services-shell">
    <div class="saved-services-hero">
        <div>
            <span class="kicker"><?= e(t('saved_services_title', $lang)) ?></span>
            <h2><?= e($savedCount) ?> <?= e($lang === 'th' ? 'รายการที่บันทึกไว้' : 'saved items') ?></h2>
            <p><?= e(t('saved_services_desc', $lang)) ?></p>
        </div>
        <div class="saved-services-summary">
            <article><strong><?= $savedCount ?></strong><span><?= e($lang === 'th' ? 'บริการ' : 'services') ?></span></article>
            <article><strong><?= $savedCount > 0 ? number_format(array_sum(array_map(fn($s) => (int) $s['review_count'], $savedServices))) : 0 ?></strong><span><?= e($lang === 'th' ? 'รีวิวรวม' : 'total reviews') ?></span></article>
            <article><strong><?= $savedCount > 0 ? money(array_sum(array_map(fn($s) => (float) $s['price'], $savedServices))) : money(0) ?></strong><span><?= e($lang === 'th' ? 'มูลค่ารวม' : 'saved value') ?></span></article>
        </div>
    </div>
    <?php if ($savedServices): ?>
        <div class="app-service-grid"><?php foreach ($savedServices as $service) require __DIR__ . '/../includes/service-card.php'; ?></div>
    <?php else: ?>
        <?= empty_state_html(t('saved_services_empty', $lang), t('saved_services_desc', $lang), t('search_services', $lang), '?page=services', '♥') ?>
    <?php endif; ?>
</section>

<?php elseif ($page === 'dashboard'):
    $stats = ['active'=>(int)scalar('SELECT COUNT(*) FROM orders WHERE customer_id=? AND status IN ("pending","in_progress")',[$user['id']]),'review'=>(int)scalar('SELECT COUNT(*) FROM orders WHERE customer_id=? AND status="review"',[$user['id']]),'completed'=>(int)scalar('SELECT COUNT(*) FROM orders WHERE customer_id=? AND status="completed"',[$user['id']]),'messages'=>(int)scalar('SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0',[$user['id']])];
    $recent = fetch_all('SELECT orders.*,services.title,users.name AS seller FROM orders JOIN services ON services.id=orders.service_id JOIN users ON users.id=orders.seller_id WHERE customer_id=? ORDER BY orders.created_at DESC LIMIT 5',[$user['id']]);
    $greeting = date('H') < 12 ? t('dashboard_morning', $lang) : t('dashboard_afternoon', $lang);
    page_heading(t('dashboard_customer', $lang), $greeting . ', ' . explode(' ',$user['name'])[0] . '.', t('dashboard_clear_view', $lang), '<a class="button button-dark" href="?page=services">'.e(t('dashboard_find_service', $lang)).' ＋</a>');
?>
<div class="metric-grid"><article><span class="metric-icon blue"><?= icon_svg('orders') ?></span><strong><?= $stats['active'] ?></strong><small><?= e(t('dashboard_active_orders', $lang)) ?></small></article><article><span class="metric-icon amber"><?= icon_svg('notifications') ?></span><strong><?= $stats['review'] ?></strong><small><?= e(t('dashboard_needs_review', $lang)) ?></small></article><article><span class="metric-icon green"><?= icon_svg('moderation') ?></span><strong><?= $stats['completed'] ?></strong><small><?= e(t('dashboard_completed', $lang)) ?></small></article><article><span class="metric-icon violet"><?= icon_svg('messages') ?></span><strong><?= $stats['messages'] ?></strong><small><?= e(t('dashboard_unread_messages', $lang)) ?></small></article></div><?= onboarding_checklist_html('customer', $user, $lang) ?><section class="data-panel progress-panel"><div class="panel-title"><div><h2><?= e(t('project_progress_title', $lang)) ?></h2><p><?= e(t('project_progress_desc', $lang)) ?></p></div><a href="?page=orders"><?= e(t('project_progress_overview', $lang)) ?> →</a></div><?php if ($recent): ?><div class="progress-list"><?php foreach ($recent as $order): $progress = order_progress_percent($order['status']); ?><article><div class="progress-row"><div><strong><?= e($order['title']) ?></strong><small><?= e($order['seller']) ?> · <?= e(status_label($order['status'], $lang)) ?></small></div><b><?= $progress ?>%</b></div><?= order_timeline_html($order['status'], $lang) ?><div class="progress-track"><i style="width:<?= $progress ?>%"></i></div><small><?= e(t('orders_due', $lang)) ?> <?= short_date($order['due_at']) ?></small></article><?php endforeach; ?></div><?php else: ?><?= empty_state_html(t('dashboard_first_project', $lang), t('dashboard_next_step_desc', $lang), t('dashboard_find_service', $lang), '?page=services', 'search') ?><?php endif; ?></section>
<?= activity_panel_html('customer', (int) $user['id'], $lang) ?>
<div class="dashboard-content-grid"><section class="data-panel"><div class="panel-title"><div><h2><?= e(t('dashboard_recent_orders', $lang)) ?></h2><p><?= e(t('dashboard_recent_desc', $lang)) ?></p></div><a href="?page=orders"><?= e(t('dashboard_view_all', $lang)) ?> →</a></div><?php if ($recent): ?><div class="order-rows"><?php foreach($recent as $order): ?><a href="?page=orders&id=<?= $order['id'] ?>"><span class="order-code"><?= e(substr($order['order_number'],-5)) ?></span><div><strong><?= e($order['title']) ?></strong><small><?= e($order['seller']) ?> · <?= short_date($order['created_at']) ?></small></div><span class="status <?= e($order['status']) ?>"><?= e(status_label($order['status'], $lang)) ?></span><b><?= money($order['total']) ?></b></a><?php endforeach; ?></div><?php else: ?><?= empty_state_html(t('dashboard_first_project', $lang), t('dashboard_next_step_desc', $lang), t('dashboard_find_service', $lang), '?page=services', '⌕') ?><?php endif; ?></section><aside class="data-panel next-step"><span><?= e(t('dashboard_next_step', $lang)) ?></span><h2><?= e(t('dashboard_next_step_title', $lang)) ?></h2><p><?= e(t('dashboard_next_step_desc', $lang)) ?></p><a class="button button-dark button-full" href="?page=services"><?= e(t('dashboard_explore_services', $lang)) ?></a></aside></div>

<?php elseif ($page === 'checkout'):
    $service = fetch_one('SELECT services.*,categories.name AS category,users.name AS seller FROM services JOIN categories ON categories.id=services.category_id JOIN users ON users.id=services.seller_id WHERE services.id=? AND services.status="active"',[(int)($_GET['id']??0)]);
    if (!$service) { redirect('?page=services'); }
    $walletBalance = (float) scalar('SELECT COALESCE(wallet_balance,0) FROM users WHERE id=?', [(int) $user['id']]);
    $checkoutTotal = (float) $service['price'];
    $hasEnoughWallet = $walletBalance >= $checkoutTotal;
    page_heading(t('checkout_title', $lang), t('checkout_heading', $lang), t('checkout_desc', $lang));
?>
<form class="checkout-layout" method="post"><section class="form-panel"><?php post_fields('place_order','?page=checkout&id='.$service['id']); ?><input type="hidden" name="service_id" value="<?= $service['id'] ?>"><input type="hidden" name="payment_method" value="wallet"><div class="form-section"><span>01</span><div><h2><?= e(t('checkout_requirements', $lang)) ?></h2><p><?= e(t('checkout_requirements_desc', $lang)) ?></p><label><?= e(t('checkout_brief', $lang)) ?><textarea name="requirements" rows="7" minlength="20" required placeholder="<?= e(t('checkout_brief_placeholder', $lang)) ?>"></textarea></label></div></div><div class="form-section"><span>02</span><div><h2><?= e(t('checkout_coupon', $lang)) ?></h2><p><?= e(t('checkout_coupon_desc', $lang)) ?></p><label><?= e(t('checkout_coupon_code', $lang)) ?><input type="text" name="coupon" placeholder="<?= e($lang === 'th' ? 'ไม่บังคับ' : 'Optional') ?>"></label></div></div><div class="form-section"><span>03</span><div><h2><?= e($lang === 'th' ? 'ชำระผ่าน Wallet' : 'Wallet payment') ?></h2><p><?= e($lang === 'th' ? 'ตัดยอดจาก Wallet ของคุณทันทีหลังยืนยันคำสั่งซื้อ' : 'Your wallet balance is charged immediately after you confirm the order.') ?></p><div class="topup-stat-grid"><article><span><?= e($lang === 'th' ? 'ยอด Wallet ปัจจุบัน' : 'Current wallet balance') ?></span><strong><?= money($walletBalance) ?></strong><small><?= e($hasEnoughWallet ? ($lang === 'th' ? 'ยอดเพียงพอสำหรับรายการนี้' : 'Enough balance for this order') : ($lang === 'th' ? 'ยอดไม่พอ ต้องเติมเงินก่อน' : 'Not enough balance yet')) ?></small></article><article><span><?= e($lang === 'th' ? 'ยอดที่ต้องชำระ' : 'Amount to pay') ?></span><strong><?= money($checkoutTotal) ?></strong><small><?= e($lang === 'th' ? 'หักจากกระเป๋าในระบบ' : 'Deducted from your platform wallet') ?></small></article></div><?php if (!$hasEnoughWallet): ?><p class="role-note"><?= e($lang === 'th' ? 'ยอดเงินใน Wallet ไม่พอ กรุณาเติมเงินผ่าน PromptPay ก่อนชำระ' : 'Your wallet balance is too low. Top up with PromptPay before checking out.') ?></p><?php endif; ?></div></div></section><aside class="checkout-summary"><span><?= e($service['category']) ?></span><h2><?= e($service['title']) ?></h2><p><?= e(t('service_by', $lang, ['name' => $service['seller']])) ?></p><dl><div><dt><?= e(t('checkout_service', $lang)) ?></dt><dd><?= money($service['price']) ?></dd></div><div><dt><?= e(t('checkout_delivery', $lang)) ?></dt><dd><?= (int)$service['delivery_days'] ?> <?= e($lang === 'th' ? 'วัน' : 'days') ?></dd></div><div><dt><?= e(t('checkout_platform', $lang)) ?></dt><dd><?= e(t('checkout_included', $lang)) ?></dd></div><div><dt><?= e($lang === 'th' ? 'Wallet คงเหลือ' : 'Wallet balance') ?></dt><dd><?= money($walletBalance) ?></dd></div><div class="total"><dt><?= e(t('checkout_total', $lang)) ?></dt><dd><?= money($checkoutTotal) ?></dd></div></dl><?php if ($hasEnoughWallet): ?><button class="button button-dark button-full" type="submit"><?= e(t('checkout_confirm', $lang)) ?></button><?php else: ?><a class="button button-dark button-full" href="?page=topup"><?= e($lang === 'th' ? 'ไปเติมเงินก่อน' : 'Top up wallet first') ?></a><?php endif; ?><small><?= e($lang === 'th' ? 'คำสั่งซื้อจะถูกสร้างหลังตัดยอดจาก Wallet สำเร็จเท่านั้น' : 'The order is created only after wallet payment succeeds.') ?></small></aside></form>

<?php elseif ($page === 'orders'):
    $orders = fetch_all('SELECT orders.*,services.title,users.name AS seller,(SELECT COUNT(*) FROM reviews WHERE reviews.order_id=orders.id) AS reviewed FROM orders JOIN services ON services.id=orders.service_id JOIN users ON users.id=orders.seller_id WHERE customer_id=? ORDER BY orders.created_at DESC',[$user['id']]);
    page_heading(t('orders_page_title', $lang), t('orders_page_heading', $lang), t('orders_page_desc', $lang), '<a class="button button-dark" href="?page=services">'.e(t('orders_new', $lang)).' ＋</a>');
?>
<section class="data-panel table-panel"><div class="table-filters"><button class="active" data-table-filter="all"><?= e(t('filter_all', $lang)) ?></button><?php foreach(['pending','in_progress','review','completed','cancelled'] as $st): ?><button data-table-filter="<?= $st ?>"><?= e(status_label($st, $lang)) ?></button><?php endforeach; ?></div><div class="responsive-table"><table><thead><tr><th><?= e(t('orders_order', $lang)) ?></th><th><?= e(t('orders_seller', $lang)) ?></th><th><?= e(t('orders_status', $lang)) ?></th><th><?= e(t('timeline_title', $lang)) ?></th><th><?= e(t('orders_due', $lang)) ?></th><th><?= e(t('orders_total', $lang)) ?></th><th></th></tr></thead><tbody><?php foreach($orders as $order): ?><tr data-status-row="<?= e($order['status']) ?>"><td><strong><?= e($order['title']) ?></strong><small><?= e($order['order_number']) ?> · <?= short_date($order['created_at']) ?></small></td><td><?= e($order['seller']) ?></td><td><span class="status <?= e($order['status']) ?>"><?= e(status_label($order['status'], $lang)) ?></span></td><td><?= order_timeline_html($order['status'], $lang) ?></td><td><?= short_date($order['due_at']) ?></td><td><strong><?= money($order['total']) ?></strong></td><td><div class="row-actions"><a href="?page=messages&order=<?= $order['id'] ?>"><?= e(t('orders_message', $lang)) ?></a><?php if(in_array($order['status'],['pending','review'],true)): ?><form method="post"><?php post_fields('update_order','?page=orders'); ?><input type="hidden" name="order_id" value="<?= $order['id'] ?>"><input type="hidden" name="status" value="<?= $order['status']==='review'?'completed':'cancelled' ?>"><button type="submit"><?= $order['status']==='review'?e(t('orders_approve', $lang)):e(t('orders_cancel', $lang)) ?></button></form><?php endif; ?></div></td></tr><?php if($order['status']==='completed' && !(int)$order['reviewed']): ?><tr class="review-form-row" data-status-row="completed"><td colspan="7"><form method="post" class="inline-review"><?php post_fields('submit_review'); ?><input type="hidden" name="order_id" value="<?= $order['id'] ?>"><label><?= e(t('orders_rate', $lang)) ?><select name="rating"><option value="5"><?= e(t('orders_stars', $lang, ['count' => 5])) ?></option><option value="4"><?= e(t('orders_stars', $lang, ['count' => 4])) ?></option><option value="3"><?= e(t('orders_stars', $lang, ['count' => 3])) ?></option></select></label><label><?= e(t('service_feedback', $lang)) ?><input name="comment" minlength="10" placeholder="<?= e($lang === 'th' ? 'มีอะไรที่ทำได้ดี?' : 'What went well?') ?>" required></label><button class="button button-dark button-small"><?= e(t('orders_submit_review', $lang)) ?></button></form></td></tr><?php endif; ?><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($sharedMessagesPage):
    $orderWhere = $user['role']==='seller' ? 'orders.seller_id=?' : ($user['role']==='admin' ? '1=1' : 'orders.customer_id=?');
    $params = $user['role']==='admin' ? [] : [$user['id']];
    $conversations = fetch_all("SELECT orders.*,services.title,customer.name AS customer,seller.name AS seller,
        customer.avatar AS customer_avatar,seller.avatar AS seller_avatar,
        (SELECT body FROM messages WHERE order_id=orders.id ORDER BY created_at DESC LIMIT 1) AS last_message,
        (SELECT COUNT(*) FROM messages WHERE order_id=orders.id AND receiver_id=? AND is_read=0) AS unread_messages
        FROM orders JOIN services ON services.id=orders.service_id JOIN users customer ON customer.id=orders.customer_id JOIN users seller ON seller.id=orders.seller_id
        WHERE $orderWhere ORDER BY orders.updated_at DESC", array_merge([$user['id']], $params));
    $selectedOrderId = (int)($_GET['order'] ?? ($conversations[0]['id'] ?? 0));
    $selected = null; foreach($conversations as $conv){ if((int)$conv['id']===$selectedOrderId)$selected=$conv; }
    if ($selected) db()->prepare('UPDATE messages SET is_read=1 WHERE order_id=? AND receiver_id=?')->execute([$selectedOrderId,$user['id']]);
    $messages = $selected ? fetch_all('SELECT messages.*,users.name,users.avatar FROM messages JOIN users ON users.id=messages.sender_id WHERE order_id=? ORDER BY messages.created_at',[$selectedOrderId]) : [];
    page_heading(t('communication_title', $lang), t('messages_title', $lang), t('project_conversations_desc', $lang));
?>
<div class="messenger">
    <aside class="conversation-list">
        <div><h2><?= e(t('conversations_title', $lang)) ?></h2><span><?= count($conversations) ?> <?= e(t('orders_label', $lang)) ?></span></div>
        <?php foreach($conversations as $conv): $other=$user['role']==='customer'?$conv['seller']:$conv['customer']; $otherAvatar=$user['role']==='customer'?$conv['seller_avatar']:$conv['customer_avatar']; ?>
            <a class="<?= (int)$conv['id']===$selectedOrderId?'active':'' ?>" href="?page=<?= e($page) ?>&order=<?= $conv['id'] ?>">
                <span class="message-avatar <?= $otherAvatar ? 'has-image' : '' ?>" <?= $otherAvatar ? 'style="background-image:url('.e(upload_url($otherAvatar)).')"' : '' ?>><?= $otherAvatar ? '' : e(initials($other)) ?></span>
                <div>
                    <strong><?= e($other) ?></strong>
                    <small><?= e($conv['title']) ?></small>
                    <p><?= e(mb_strimwidth($conv['last_message']?:t('no_messages_yet', $lang),0,45,'...')) ?></p>
                </div>
                <?php if ((int) $conv['unread_messages'] > 0): ?><b><?= (int) $conv['unread_messages'] ?></b><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </aside>
    <section class="chat-panel">
        <?php if($selected): $other=$user['role']==='customer'?$selected['seller']:$selected['customer']; $otherAvatar=$user['role']==='customer'?$selected['seller_avatar']:$selected['customer_avatar']; ?>
            <header>
                <span class="message-avatar <?= $otherAvatar ? 'has-image' : '' ?>" <?= $otherAvatar ? 'style="background-image:url('.e(upload_url($otherAvatar)).')"' : '' ?>><?= $otherAvatar ? '' : e(initials($other)) ?></span>
                <div>
                    <strong><?= e($other) ?></strong>
                    <small><?= e($selected['title']) ?> · <?= e($selected['order_number']) ?></small>
                </div>
                <i class="status <?= e($selected['status']) ?>"><?= e(status_label($selected['status'], $lang)) ?></i>
            </header>
            <div class="chat-progress">
                <div><strong><?= e(t('project_progress_label', $lang)) ?></strong><small><?= e(status_label($selected['status'], $lang)) ?></small></div>
                <span><i style="width:<?= order_progress_percent($selected['status']) ?>%"></i></span>
            </div>
            <div class="chat-messages">
                <?php foreach($messages as $message): $mine = (int)$message['sender_id']===(int)$user['id']; ?>
                    <article class="<?= $mine?'mine':'' ?>">
                        <span class="message-avatar <?= !empty($message['avatar']) ? 'has-image' : '' ?>" <?= !empty($message['avatar']) ? 'style="background-image:url('.e(upload_url($message['avatar'])).')"' : '' ?>><?= !empty($message['avatar']) ? '' : e(initials($message['name'])) ?></span>
                        <div>
                            <?php if($message['body']!==''): ?><p><?= nl2br(e($message['body'])) ?></p><?php endif; ?>
                            <?php if($message['attachment']): ?>
                                <?php $attachmentExt = strtolower(pathinfo($message['attachment'], PATHINFO_EXTENSION)); ?>
                                <?php if (in_array($attachmentExt, ['jpg','jpeg','png','webp','gif'], true)): ?>
                                    <a class="message-preview" href="<?= e(upload_url($message['attachment'])) ?>" target="_blank" rel="noopener"><img src="<?= e(upload_url($message['attachment'])) ?>" alt="<?= e(t('attachment_label', $lang)) ?>"></a>
                                <?php endif; ?>
                                <a class="message-attachment" href="<?= e(upload_url($message['attachment'])) ?>" target="_blank" rel="noopener"><?= e(t('attachment_label', $lang)) ?> · <?= e(basename($message['attachment'])) ?></a>
                            <?php endif; ?>
                            <small><?= e(relative_time($message['created_at'])) ?><?php if ($mine): ?> · <?= $message['is_read'] ? e(t('chat_seen', $lang)) : e(t('chat_sent', $lang)) ?><?php endif; ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if(!$messages): ?><?= empty_state_html(t('no_project_conversations', $lang), t('start_conversation', $lang), t('messages_title', $lang), '?page=messages', '◇') ?><?php endif; ?>
            </div>
            <form class="message-composer" method="post" enctype="multipart/form-data">
                <?php post_fields('send_message','?page='.$page.'&order='.$selectedOrderId); ?>
                <input type="hidden" name="order_id" value="<?= $selectedOrderId ?>">
                <label class="attach-button" title="<?= e(t('attach_file', $lang)) ?>"><input type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf,text/plain">＋</label>
                <textarea name="body" rows="2" placeholder="<?= e(t('write_message', $lang)) ?>"></textarea>
                <button class="button button-dark" type="submit"><?= e(t('send_message', $lang)) ?></button>
            </form>
        <?php else: ?>
            <?= empty_state_html(t('no_project_conversations', $lang), t('messages_available_after_order', $lang), t('search_services', $lang), '?page=services', '◇') ?>
        <?php endif; ?>
    </section>
</div>

<?php elseif ($page === 'notifications'):
    $filter = $_GET['filter'] ?? 'all';
    $notifications = fetch_all('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC',[$user['id']]);
    $notificationsUnread = array_values(array_filter($notifications, fn($note) => !(int) $note['is_read']));
    $notificationsVisible = $filter === 'unread' ? $notificationsUnread : $notifications;
    page_heading(
        t('nav_updates', $lang),
        t('side_notifications', $lang),
        t('notifications_desc', $lang),
        '<div class="row-actions">'
        . '<form method="post"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="test_notification"><input type="hidden" name="return_to" value="?page=notifications"><button class="button button-light">' . e($lang === 'th' ? 'ทดสอบแจ้งเตือน' : 'Test notification') . '</button></form>'
        . '<form method="post"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="mark_notifications"><button class="button button-dark">' . e(t('mark_all_read', $lang)) . '</button></form>'
        . '</div>'
    );
?>
<section class="data-panel notification-board">
    <div class="table-filters">
        <a class="<?= $filter === 'all' ? 'active' : '' ?>" href="?page=notifications&filter=all"><?= e(t('notifications_all', $lang)) ?></a>
        <a class="<?= $filter === 'unread' ? 'active' : '' ?>" href="?page=notifications&filter=unread"><?= e(t('notifications_unread', $lang)) ?></a>
    </div>
    <div class="notification-list">
        <?php foreach($notificationsVisible as $note): ?>
            <article class="notification-item <?= !$note['is_read']?'unread':'' ?>">
                <a class="notification-link" href="<?= e($note['link']?:'#') ?>">
                    <span><?= match($note['type']){'order'=>'▣','message'=>'◇','payment'=>'฿','review'=>'★',default=>'○'} ?></span>
                    <div>
                        <strong><?= e($note['title']) ?></strong>
                        <p><?= e($note['body']) ?></p>
                        <small><?= e(relative_time($note['created_at'])) ?></small>
                    </div>
                </a>
                <form method="post" class="notification-toggle">
                    <?php post_fields('toggle_notification', '?page=notifications&filter=' . $filter); ?>
                    <input type="hidden" name="notification_id" value="<?= (int) $note['id'] ?>">
                    <?php if (!(int) $note['is_read']): ?><input type="hidden" name="is_read" value="1"><?php endif; ?>
                    <button type="submit" class="button button-light button-small"><?= e((int) $note['is_read'] ? t('notifications_mark_unread', $lang) : t('notifications_mark_read', $lang)) ?></button>
                </form>
                <?php if(!$note['is_read']): ?><i></i><?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if(!$notificationsVisible): ?><?= empty_state_html(t('notifications_empty', $lang), t('notifications_desc', $lang), t('mark_all_read', $lang), '?page=notifications', '○') ?><?php endif; ?>
    </div>
</section>

<?php elseif ($page === 'topup'):
    $walletBalance = (float) scalar('SELECT COALESCE(wallet_balance,0) FROM users WHERE id=?', [$user['id']]);
    $walletHistory = fetch_all('SELECT * FROM wallet_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 8', [$user['id']]);
    $walletPending = fetch_all('SELECT * FROM payment_requests WHERE user_id=? AND request_type="topup" AND status="pending" ORDER BY created_at DESC LIMIT 8', [$user['id']]);
    $walletDetailRef = trim((string) ($_GET['tx'] ?? ''));
    $walletDetail = $walletDetailRef !== '' ? fetch_one('SELECT * FROM wallet_transactions WHERE user_id=? AND reference=?', [$user['id'], $walletDetailRef]) : null;
    $walletSummary = [
        'completed_sum' => (float) scalar('SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE user_id=? AND status="completed"', [$user['id']]),
        'pending_sum' => (float) scalar('SELECT COALESCE(SUM(amount),0) FROM payment_requests WHERE user_id=? AND request_type="topup" AND status="pending"', [$user['id']]),
        'total_count' => (int) scalar('SELECT COUNT(*) FROM wallet_transactions WHERE user_id=?', [$user['id']]),
        'pending_count' => (int) scalar('SELECT COUNT(*) FROM payment_requests WHERE user_id=? AND request_type="topup" AND status="pending"', [$user['id']]),
    ];
    $paymentInstructions = payment_instructions_setting($lang === 'th' ? 'กรอกยอดที่ต้องการ จากนั้นระบบจะพาไปหน้าชำระ PromptPay ของ Stripe และเพิ่มยอดให้อัตโนมัติเมื่อชำระสำเร็จ' : 'Enter the amount you want, continue to Stripe PromptPay, and your wallet will be credited automatically after payment succeeds.');
    page_heading(t('topup_title', $lang), t('topup_title', $lang), t('topup_description', $lang), '<a class="button button-light" href="?page=settings">' . e(t('settings_title', $lang)) . '</a>');
?>
<div class="topup-layout topup-layout-apple">
    <section class="topup-hero">
        <div class="topup-hero-copy">
            <span class="kicker"><?= e($lang === 'th' ? 'ระบบเติมเงิน' : 'Wallet top up') ?></span>
            <h2><?= e($lang === 'th' ? 'เติมเงินแบบมีขั้นตอน ชัดเจน และดูน่าเชื่อถือ' : 'A clearer, more trustworthy top up flow') ?></h2>
            <p><?= e($lang === 'th' ? 'กรอกยอดเองได้ เริ่มต้น 50 บาท แล้วชำระผ่านหน้า PromptPay ของ Stripe เพื่อให้ระบบอัปเดตให้อัตโนมัติ' : 'Choose your own amount from 50 THB and pay on Stripe’s PromptPay page for automatic wallet updates.') ?></p>
            <div class="topup-stat-grid">
                <article><span><?= e($lang === 'th' ? 'ยอดคงเหลือ' : 'Available balance') ?></span><strong><?= money($walletBalance) ?></strong><small><?= e($lang === 'th' ? 'ใช้จ่ายได้ทันที' : 'Ready to spend') ?></small></article>
                <article><span><?= e($lang === 'th' ? 'รอตรวจสอบ' : 'Pending review') ?></span><strong><?= money((float) ($walletSummary['pending_sum'] ?? 0)) ?></strong><small><?= e($lang === 'th' ? 'รายการโอนธนาคาร' : 'Bank transfer requests') ?></small></article>
                <article><span><?= e($lang === 'th' ? 'เติมแล้วทั้งหมด' : 'Completed top ups') ?></span><strong><?= money((float) ($walletSummary['completed_sum'] ?? 0)) ?></strong><small><?= e($lang === 'th' ? 'นับเฉพาะรายการสำเร็จ' : 'Only completed funds') ?></small></article>
            </div>
            <div class="topup-presets">
                <button type="button" data-topup-amount="50">฿50</button>
                <button type="button" data-topup-amount="100">฿100</button>
                <button type="button" data-topup-amount="300">฿300</button>
                <button type="button" data-topup-amount="500">฿500</button>
            </div>
            <small><?= e($lang === 'th' ? 'หลังชำระสำเร็จ Stripe จะส่งผลกลับมาเพื่อเพิ่มยอดอัตโนมัติ' : 'After payment, Stripe sends the result back so your balance can be credited automatically.') ?></small>
        </div>
        <div class="topup-hero-side">
            <div class="topup-hero-card">
                <span><?= e($lang === 'th' ? 'สรุปการใช้งาน' : 'Activity snapshot') ?></span>
                <strong><?= (int) ($walletSummary['total_count'] ?? 0) ?></strong>
                <p><?= e($lang === 'th' ? 'รายการเติมทั้งหมด' : 'Total wallet transactions') ?></p>
            </div>
            <div class="topup-hero-card subtle">
                <span><?= e($lang === 'th' ? 'รออนุมัติ' : 'Pending items') ?></span>
                <strong><?= (int) ($walletSummary['pending_count'] ?? 0) ?></strong>
                <p><?= e($lang === 'th' ? 'ถ้ามีรายการธนาคาร' : 'Bank transfer queue') ?></p>
            </div>
        </div>
    </section>
    <?php if (($_GET['stripe'] ?? '') === 'success'): ?>
        <section class="data-panel">
            <strong><?= e($lang === 'th' ? 'กำลังรอยืนยันการชำระเงิน' : 'Waiting for payment confirmation') ?></strong>
            <p><?= e($lang === 'th' ? 'หากชำระเงินสำเร็จ Stripe จะส่งผลกลับมาและระบบจะเพิ่มยอดให้อัตโนมัติ' : 'If payment succeeds, Stripe will send the result back and your wallet will be credited automatically.') ?></p>
        </section>
    <?php elseif (($_GET['stripe'] ?? '') === 'cancel'): ?>
        <section class="data-panel">
            <strong><?= e($lang === 'th' ? 'คุณยกเลิกการชำระเงิน' : 'Payment was canceled') ?></strong>
            <p><?= e($lang === 'th' ? 'คุณสามารถกรอกยอดใหม่แล้วลองอีกครั้งได้ทันที' : 'You can enter a new amount and try again right away.') ?></p>
        </section>
    <?php endif; ?>
    <form class="settings-card topup-card" method="post">
        <?php post_fields('topup_wallet', '?page=topup'); ?>
        <h2><?= e($lang === 'th' ? 'เติมเงินผ่าน PromptPay' : 'Top up with PromptPay') ?></h2>
        <p><?= e($paymentInstructions) ?></p>
        <div class="form-grid">
            <label class="full"><?= e(t('topup_amount', $lang)) ?><input id="topup-amount" type="number" name="amount" min="<?= (int) max(50, topup_minimum_setting(50)) ?>" step="10" value="<?= (int) max(50, topup_minimum_setting(50)) ?>" required></label>
            <label class="full"><?= e(t('topup_note', $lang)) ?><input type="text" name="note" placeholder="<?= e(t('topup_note_placeholder', $lang)) ?>"></label>
        </div>
        <footer><button class="button button-dark"><?= e($lang === 'th' ? 'ไปหน้าชำระเงิน' : 'Continue to payment') ?></button></footer>
    </form>
    <?php if ($walletDetail): ?>
        <section class="data-panel topup-detail">
            <div class="panel-title">
                <div>
                    <h2><?= e($lang === 'th' ? 'รายละเอียดรายการ' : 'Transaction details') ?></h2>
                    <p><?= e($lang === 'th' ? 'ดูข้อมูลของรายการเติมเงินแบบชัดเจนทีละรายการ' : 'Review one top up record in detail.') ?></p>
                </div>
                <a class="button button-light button-small" href="?page=topup"><?= e($lang === 'th' ? 'กลับไปหน้าหลัก' : 'Back to wallet') ?></a>
            </div>
            <div class="topup-detail-grid">
                <article><span><?= e($lang === 'th' ? 'สถานะ' : 'Status') ?></span><strong class="status <?= e($walletDetail['status']) ?>"><?= e(status_label($walletDetail['status'], $lang)) ?></strong></article>
                <article><span><?= e($lang === 'th' ? 'จำนวนเงิน' : 'Amount') ?></span><strong><?= money((float) $walletDetail['amount']) ?></strong></article>
                <article><span><?= e($lang === 'th' ? 'ช่องทาง' : 'Method') ?></span><strong><?= e(ucfirst($walletDetail['method'])) ?></strong></article>
                <article><span><?= e($lang === 'th' ? 'รหัสอ้างอิง' : 'Reference') ?></span><strong><?= e($walletDetail['reference']) ?></strong></article>
            </div>
            <div class="topup-detail-note">
                <small><?= e($lang === 'th' ? 'หมายเหตุ' : 'Note') ?></small>
                <p><?= e($walletDetail['note'] ?: ($lang === 'th' ? 'ไม่มีหมายเหตุ' : 'No note added.')) ?></p>
            </div>
            <?php if (!empty($walletDetail['slip_path'])): ?>
                <div class="topup-detail-note">
                    <small><?= e($lang === 'th' ? 'สลิปแนบ' : 'Attached slip') ?></small>
                    <p><a href="<?= e(upload_url($walletDetail['slip_path'])) ?>" target="_blank" rel="noopener"><?= e($lang === 'th' ? 'เปิดดูสลิปที่แนบไว้' : 'Open the attached slip') ?></a></p>
                </div>
            <?php endif; ?>
            <div class="topup-detail-meta">
                <span><?= e(short_date($walletDetail['created_at'])) ?></span>
                <a href="?page=topup"><?= e($lang === 'th' ? 'ดูรายการล่าสุด' : 'See latest transactions') ?></a>
            </div>
        </section>
    <?php endif; ?>
    <section class="data-panel topup-history">
        <div class="panel-title"><div><h2><?= e(t('topup_recent', $lang)) ?></h2><p><?= e(t('realtime_label', $lang)) ?></p></div></div>
        <?php if ($walletHistory): ?>
            <div class="topup-list">
                <?php foreach ($walletHistory as $item): ?>
                    <a href="?page=topup&tx=<?= e($item['reference']) ?>">
                        <span>฿</span>
                        <div>
                            <strong><?= money($item['amount']) ?> · <?= e(ucfirst($item['method'])) ?></strong>
                            <p><?= e($item['note'] ?: $item['reference']) ?></p>
                            <small><?= short_date($item['created_at']) ?></small>
                            <?php if (!empty($item['slip_path'])): ?><small><?= e($lang === 'th' ? 'มีสลิปแนบ' : 'Slip attached') ?></small><?php endif; ?>
                        </div>
                        <b class="status <?= e($item['status']) ?>"><?= e(status_label($item['status'], $lang)) ?></b>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?= empty_state_html(t('topup_empty', $lang), t('topup_description', $lang), t('topup_submit', $lang), '?page=topup', '฿') ?>
        <?php endif; ?>
    </section>
    <?php if ($walletPending): ?>
        <section class="data-panel topup-pending">
            <div class="panel-title">
                <div>
                    <h2><?= e($lang === 'th' ? 'รายการที่รอ Stripe ยืนยัน' : 'Waiting for Stripe confirmation') ?></h2>
                    <p><?= e($lang === 'th' ? 'ถ้าชำระแล้วแต่ยอดยังไม่เข้า ลองรีเฟรชหน้านี้อีกครั้งในอีกครู่' : 'If you already paid but the balance is not updated yet, refresh this page again in a moment.') ?></p>
                </div>
            </div>
            <div class="topup-list">
                <?php foreach ($walletPending as $item): ?>
                    <a href="?page=topup">
                        <span>⌛</span>
                        <div>
                            <strong><?= money($item['amount']) ?> · PromptPay</strong>
                            <p><?= e($item['title'] ?: $item['reference_code']) ?></p>
                            <small><?= short_date($item['created_at']) ?></small>
                        </div>
                        <b class="status pending"><?= e(status_label('pending', $lang)) ?></b>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php elseif ($sharedProfilePage):
    $returnPage=$user['role']==='seller'?'seller-profile':'profile';
    page_heading(t('group_account', $lang), t('profile_title', $lang), t('profile_desc', $lang));
?>
<div class="settings-layout"><aside class="profile-summary"><span class="<?= $user['avatar']?'has-image':'' ?>" <?= $user['avatar']?'style="background-image:url('.e(upload_url($user['avatar'])).')"':'' ?>><?= $user['avatar']?'':e(initials($user['name'])) ?></span><h2><?= e($user['name']) ?></h2><p><?= e($user['role_label']) ?></p><dl><div><dt><?= e(t('profile_member_since', $lang)) ?></dt><dd><?= short_date($user['created_at']) ?></dd></div><div><dt><?= e(t('profile_account_status', $lang)) ?></dt><dd><i class="status active">Active</i></dd></div></dl></aside><form class="settings-card" method="post" enctype="multipart/form-data"><?php post_fields('update_profile','?page='.$returnPage); ?><h2><?= e(t('profile_personal_info', $lang)) ?></h2><p><?= e(t('profile_personal_desc', $lang)) ?></p><div class="form-grid"><label><?= e(t('profile_full_name', $lang)) ?><input name="name" value="<?= e($user['name']) ?>" required></label><label>Email<input value="<?= e($user['email']) ?>" disabled></label><label><?= e(t('profile_phone', $lang)) ?><input name="phone" value="<?= e($user['phone']) ?>" placeholder="<?= e(t('profile_optional', $lang)) ?>"></label><label><?= e(t('profile_picture', $lang)) ?><input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"></label><label class="full"><?= e(t('profile_about_you', $lang)) ?><textarea name="bio" rows="5" placeholder="<?= e(t('profile_about_placeholder', $lang)) ?>"><?= e($user['bio']) ?></textarea></label></div><footer><button class="button button-dark"><?= e(t('profile_save', $lang)) ?></button></footer></form></div>

<?php elseif ($sharedSettingsPage):
    $returnPage=$user['role']==='seller'?'seller-settings':'settings';
    page_heading(t('group_account', $lang), t('settings_title', $lang), t('settings_description', $lang));
?>
<div class="settings-stack">
    <form class="settings-card" method="post" data-preference-form>
        <?php post_fields('update_preferences', '?page=' . $returnPage); ?>
        <h2><?= e(t('settings_appearance', $lang)) ?></h2>
        <p><?= e(t('preview_layout_desc', $lang)) ?></p>
        <div class="form-grid">
            <label><?= e(t('settings_theme', $lang)) ?><select name="theme" data-pref-input="theme"><option value="light" <?= $user['theme']==='light'?'selected':'' ?>><?= e(t('settings_theme_light', $lang)) ?></option><option value="dark" <?= $user['theme']==='dark'?'selected':'' ?>><?= e(t('settings_theme_dark', $lang)) ?></option><option value="auto" <?= $user['theme']==='auto'?'selected':'' ?>><?= e(t('settings_theme_auto', $lang)) ?></option></select></label>
            <label><?= e(t('settings_language', $lang)) ?><select name="language" data-pref-input="language"><option value="en" <?= $user['language']==='en'?'selected':'' ?>>English</option><option value="th" <?= $user['language']==='th'?'selected':'' ?>>ไทย</option></select></label>
            <label><?= e(t('settings_text_size', $lang)) ?><select name="text_scale" data-pref-input="text_scale"><option value="small" <?= $user['text_scale']==='small'?'selected':'' ?>><?= e(t('settings_text_small', $lang)) ?></option><option value="medium" <?= $user['text_scale']==='medium'?'selected':'' ?>><?= e(t('settings_text_medium', $lang)) ?></option><option value="large" <?= $user['text_scale']==='large'?'selected':'' ?>><?= e(t('settings_text_large', $lang)) ?></option><option value="xl" <?= $user['text_scale']==='xl'?'selected':'' ?>><?= e(t('settings_text_xl', $lang)) ?></option></select></label>
            <label><?= e(t('settings_ui_size', $lang)) ?><select name="ui_scale" data-pref-input="ui_scale"><option value="compact" <?= $user['ui_scale']==='compact'?'selected':'' ?>><?= e(t('settings_ui_compact', $lang)) ?></option><option value="comfortable" <?= $user['ui_scale']==='comfortable'?'selected':'' ?>><?= e(t('settings_ui_comfortable', $lang)) ?></option><option value="roomy" <?= $user['ui_scale']==='roomy'?'selected':'' ?>><?= e(t('settings_ui_roomy', $lang)) ?></option></select></label>
            <label class="toggle-row full"><span><strong><?= e(t('settings_email_notifications', $lang)) ?></strong><small><?= e(t('settings_email_notifications_desc', $lang)) ?></small></span><input type="checkbox" name="email_notifications" <?= $user['email_notifications']?'checked':'' ?>><i></i></label>
            <label class="toggle-row full"><span><strong><?= e(t('settings_realtime', $lang)) ?></strong><small><?= e(t('settings_realtime_desc', $lang)) ?></small></span><input type="checkbox" name="realtime_enabled" data-realtime-setting><i></i></label>
        </div>
        <footer><button class="button button-dark"><?= e(t('settings_save_preferences', $lang)) ?></button></footer>
    </form>
    <form class="settings-card" method="post"><?php post_fields('change_password','?page='.$returnPage); ?><h2><?= e(t('settings_password', $lang)) ?></h2><p><?= e(t('settings_password_desc', $lang)) ?></p><div class="form-grid"><label><?= e(t('settings_current_password', $lang)) ?><input type="password" name="current_password" required></label><label><?= e(t('settings_new_password', $lang)) ?><input type="password" name="new_password" minlength="8" required></label></div><footer><button class="button button-dark"><?= e(t('settings_update_password', $lang)) ?></button></footer></form>
</div>

<?php elseif ($page === 'seller-pending'):
    if (($user['role'] ?? '') !== 'seller' || ($user['status'] ?? '') !== 'pending_approval') {
        redirect(role_home($user['role']));
    }
    page_heading(t('seller_dashboard_title', $lang), t('seller_pending_title', $lang), t('seller_pending_desc', $lang));
?>
<section class="data-panel next-step" style="max-width:720px;margin:auto">
    <span><?= e(t('seller_quality', $lang)) ?></span>
    <h2><?= e(t('seller_pending_title', $lang)) ?></h2>
    <p><?= e(t('seller_pending_desc', $lang)) ?></p>
    <p><?= e(t('seller_pending_hint', $lang)) ?></p>
    <div class="row-actions" style="margin-top:18px">
        <a class="button button-dark" href="?page=home"><?= e(t('nav_home', $lang)) ?></a>
        <form method="post"><?php post_fields('logout','?page=seller-pending'); ?><button class="button button-light" type="submit"><?= e(t('seller_pending_logout', $lang)) ?></button></form>
    </div>
</section>

<?php elseif ($page === 'seller-dashboard'):
    $sellerStats=['revenue'=>(float)scalar('SELECT COALESCE(SUM(total),0) FROM orders WHERE seller_id=? AND status="completed"',[$user['id']]),'orders'=>(int)scalar('SELECT COUNT(*) FROM orders WHERE seller_id=? AND status IN ("pending","in_progress","review")',[$user['id']]),'services'=>(int)scalar('SELECT COUNT(*) FROM services WHERE seller_id=? AND status="active"',[$user['id']]),'rating'=>(float)scalar('SELECT COALESCE(AVG(rating),0) FROM reviews WHERE seller_id=?',[$user['id']])];
    $sellerOrders=fetch_all('SELECT orders.*,services.title,users.name AS customer FROM orders JOIN services ON services.id=orders.service_id JOIN users ON users.id=orders.customer_id WHERE orders.seller_id=? ORDER BY orders.created_at DESC LIMIT 6',[$user['id']]);
    page_heading(t('seller_dashboard_title', $lang),t('welcome_back', $lang, ['name' => explode(' ',$user['name'])[0]]),t('seller_dashboard_desc', $lang),'<a class="button button-dark" href="?page=seller-add-service">'.e(t('seller_add_service', $lang)).' ＋</a>');
?>
<?php if (seller_requires_approval($user)): ?><div class="flash info" style="max-width:1160px;margin:0 auto 18px">Your seller account is signed in, but seller actions stay locked until an admin approves your ID verification.</div><?php endif; ?>
<div class="metric-grid"><article><span class="metric-icon green"><?= icon_svg('wallet') ?></span><strong><?= money($sellerStats['revenue']) ?></strong><small><?= e(t('seller_completed_revenue', $lang)) ?></small></article><article><span class="metric-icon amber"><?= icon_svg('orders') ?></span><strong><?= $sellerStats['orders'] ?></strong><small><?= e(t('seller_active_orders', $lang)) ?></small></article><article><span class="metric-icon blue"><?= icon_svg('categories') ?></span><strong><?= $sellerStats['services'] ?></strong><small><?= e(t('seller_live_services', $lang)) ?></small></article><article><span class="metric-icon violet"><?= icon_svg('analytics') ?></span><strong><?= $sellerStats['rating']?number_format($sellerStats['rating'],1):e(t('new_label', $lang)) ?></strong><small><?= e(t('seller_average_rating', $lang)) ?></small></article></div><?= onboarding_checklist_html('seller', $user, $lang) ?><?= activity_panel_html('seller', (int) $user['id'], $lang) ?><div class="dashboard-content-grid"><section class="data-panel"><div class="panel-title"><div><h2><?= e(t('seller_recent_work', $lang)) ?></h2><p><?= e(t('seller_recent_desc', $lang)) ?></p></div><a href="?page=seller-orders"><?= e(t('seller_manage_all', $lang)) ?> →</a></div><div class="order-rows"><?php foreach($sellerOrders as $order): ?><a href="?page=seller-messages&order=<?= $order['id'] ?>"><span class="order-code"><?= e(initials($order['customer'])) ?></span><div><strong><?= e($order['title']) ?></strong><small><?= e($order['customer']) ?> · <?= e($order['order_number']) ?></small><?= order_timeline_html($order['status'], $lang) ?></div><span class="status <?= e($order['status']) ?>"><?= e(status_label($order['status'], $lang)) ?></span><b><?= money($order['total']) ?></b></a><?php endforeach; ?></div></section><aside class="data-panel next-step"><span><?= e(t('seller_quality', $lang)) ?></span><h2><?= e(t('seller_next_action', $lang)) ?></h2><p><?= e(t('seller_next_action_desc', $lang)) ?></p><a class="button button-dark button-full" href="?page=seller-orders"><?= e(t('seller_review_orders', $lang)) ?></a></aside></div>

<?php elseif ($page === 'seller-services'):
    $myServices=service_rows('services.seller_id=?',[$user['id']]);
    page_heading(t('seller_services_title', $lang),t('seller_my_services', $lang),t('seller_services_desc', $lang),'<a class="button button-dark" href="?page=seller-add-service">'.e(t('seller_add_service', $lang)).' ＋</a>');
?>
<?php if (seller_requires_approval($user)): ?><div class="flash info" style="max-width:1160px;margin:0 auto 18px">Seller tools are locked until an admin reviews and approves your Thai ID card.</div><?php endif; ?>
<section class="data-panel table-panel"><div class="responsive-table"><table><thead><tr><th><?= e(t('seller_service', $lang)) ?></th><th><?= e(t('seller_service_category', $lang)) ?></th><th><?= e(t('seller_service_price', $lang)) ?></th><th><?= e(t('seller_service_details', $lang)) ?></th><th><?= e(t('admin_status', $lang)) ?></th><th></th></tr></thead><tbody><?php foreach($myServices as $service): ?><tr><td><strong><?= e($service['title']) ?></strong><small><?= e(t('seller_service_day_delivery', $lang, ['days' => (int)$service['delivery_days']])) ?></small></td><td><?= e($service['category']) ?></td><td><?= money($service['price']) ?></td><td><?= (int)$service['views'] ?></td><td><span class="status <?= e($service['status']) ?>"><?= e(status_label($service['status'], $lang)) ?></span></td><td><div class="row-actions"><a href="?page=seller-add-service&id=<?= $service['id'] ?>"><?= e(t('seller_service_edit', $lang)) ?></a><form method="post" onsubmit="return confirm('<?= e(t('seller_service_remove_confirm', $lang)) ?>')"><?php post_fields('delete_service'); ?><input type="hidden" name="service_id" value="<?= $service['id'] ?>"><button><?= e(t('seller_service_remove', $lang)) ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($page === 'seller-add-service'):
    $editId=(int)($_GET['id']??0); $edit=$editId?fetch_one('SELECT * FROM services WHERE id=? AND seller_id=?',[$editId,$user['id']]):null; $cats=fetch_all('SELECT * FROM categories ORDER BY id');
    page_heading(t('seller_services_title', $lang),$edit?t('seller_edit_service', $lang):t('seller_new_service', $lang),$edit?t('seller_update_service_desc', $lang):t('seller_create_service_desc', $lang),'<a class="button button-light" href="?page=seller-services">← '.e(t('seller_my_services', $lang)).'</a>');
?>
<?php if (seller_requires_approval($user)): ?><div class="flash info" style="max-width:1160px;margin:0 auto 18px">You can view the seller workspace now, but publishing or editing services stays disabled until approval.</div><?php endif; ?>
<form class="editor-layout" method="post" enctype="multipart/form-data"><section class="settings-card"><?php post_fields('save_service'); ?><input type="hidden" name="service_id" value="<?= (int)($edit['id']??0) ?>"><h2><?= e(t('seller_service_details', $lang)) ?></h2><p><?= e(t('seller_service_details_desc', $lang)) ?></p><?php if (!$edit): ?><div class="flash info" style="margin-bottom:18px"><?= e(t('seller_service_review_notice', $lang)) ?></div><?php endif; ?><div class="form-grid"><label class="full"><?= e(t('seller_service_title', $lang)) ?><input name="title" minlength="5" value="<?= e($edit['title']??'') ?>" placeholder="e.g. Responsive business website" required></label><label><?= e(t('seller_service_category', $lang)) ?><select name="category_id" required><?php foreach($cats as $cat): ?><option value="<?= $cat['id'] ?>" <?= (int)($edit['category_id']??0)===(int)$cat['id']?'selected':'' ?>><?= e($cat['name']) ?></option><?php endforeach; ?></select></label><label><?= e(t('seller_service_price', $lang)) ?><input type="number" name="price" min="100" step="50" value="<?= e($edit['price']??'1500') ?>" required></label><label><?= e(t('seller_service_delivery_days', $lang)) ?><input type="number" name="delivery_days" min="1" max="60" value="<?= e($edit['delivery_days']??'7') ?>" required></label><label><?= e(t('seller_service_thumbnail', $lang)) ?><input type="file" name="thumbnail" accept="image/jpeg,image/png,image/webp"></label><label class="full"><?= e(t('seller_service_description', $lang)) ?><textarea name="description" rows="6" minlength="20" required><?= e($edit['description']??'') ?></textarea></label><label class="full"><?= e(t('seller_service_features', $lang)) ?><textarea name="features" rows="5" placeholder="<?= e(t('seller_service_feature_placeholder', $lang)) ?>"><?= e($edit['features']??'') ?></textarea></label></div><footer><button class="button button-dark"><?= $edit?e(t('seller_service_save_changes', $lang)):e(t('seller_service_publish', $lang)) ?></button></footer></section><aside class="editor-tips"><span><?= e(t('seller_service_details_tip', $lang)) ?></span><h2><?= e(t('seller_service_details_tip_title', $lang)) ?></h2><ul><li><?= e(t('seller_service_tips_1', $lang)) ?></li><li><?= e(t('seller_service_tips_2', $lang)) ?></li><li><?= e(t('seller_service_tips_3', $lang)) ?></li><li><?= e(t('seller_service_tips_4', $lang)) ?></li></ul></aside></form>

<?php elseif ($page === 'seller-orders'):
    $sellerOrders=fetch_all('SELECT orders.*,services.title,users.name AS customer FROM orders JOIN services ON services.id=orders.service_id JOIN users ON users.id=orders.customer_id WHERE orders.seller_id=? ORDER BY orders.created_at DESC',[$user['id']]);
    page_heading(t('seller_delivery', $lang),t('seller_manage_orders_title', $lang),t('seller_manage_orders_desc', $lang));
?>
<?php if (seller_requires_approval($user)): ?><div class="flash info" style="max-width:1160px;margin:0 auto 18px">Order updates and seller replies stay locked until your verification is approved.</div><?php endif; ?>
<section class="data-panel table-panel"><div class="responsive-table"><table><thead><tr><th><?= e(t('orders_order', $lang)) ?></th><th><?= e(t('admin_order_table_customer', $lang)) ?></th><th><?= e(t('orders_status', $lang)) ?></th><th><?= e(t('timeline_title', $lang)) ?></th><th><?= e(t('orders_due', $lang)) ?></th><th><?= e(t('orders_total', $lang)) ?></th><th><?= e(t('seller_manage_orders_title', $lang)) ?></th></tr></thead><tbody><?php foreach($sellerOrders as $order): ?><tr><td><strong><?= e($order['title']) ?></strong><small><?= e($order['order_number']) ?></small></td><td><?= e($order['customer']) ?></td><td><span class="status <?= e($order['status']) ?>"><?= e(status_label($order['status'], $lang)) ?></span></td><td><?= order_timeline_html($order['status'], $lang) ?></td><td><?= short_date($order['due_at']) ?></td><td><?= money($order['total']) ?></td><td><div class="row-actions"><a href="?page=seller-messages&order=<?= $order['id'] ?>"><?= e(t('orders_message', $lang)) ?></a><form method="post"><?php post_fields('update_order','?page=seller-orders'); ?><input type="hidden" name="order_id" value="<?= $order['id'] ?>"><select name="status" onchange="this.form.submit()"><?php foreach(['pending','in_progress','review','completed','cancelled'] as $st): ?><option value="<?= $st ?>" <?= $order['status']===$st?'selected':'' ?>><?= e(status_label($st, $lang)) ?></option><?php endforeach; ?></select></form></div></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($page === 'seller-earnings'):
    $transactions=fetch_all('SELECT payments.*,orders.order_number,services.title FROM payments JOIN orders ON orders.id=payments.order_id JOIN services ON services.id=orders.service_id WHERE orders.seller_id=? ORDER BY payments.paid_at DESC',[$user['id']]);
    $gross=(float)scalar('SELECT COALESCE(SUM(payments.amount),0) FROM payments JOIN orders ON orders.id=payments.order_id WHERE orders.seller_id=?',[$user['id']]); $fee=platform_fee_setting(10);
    page_heading(t('group_business', $lang),t('seller_earnings_title', $lang),t('seller_earnings_desc', $lang));
?>
<div class="finance-summary"><article><span><?= e(t('seller_gross_payments', $lang)) ?></span><strong><?= money($gross) ?></strong><small><?= e(t('seller_all_transactions', $lang)) ?></small></article><article><span><?= e(t('seller_platform_fee_label', $lang)) ?></span><strong><?= number_format($fee,0) ?>%</strong><small><?= money($gross*$fee/100) ?> <?= e(t('seller_total', $lang)) ?></small></article><article class="dark"><span><?= e(t('seller_estimated_net', $lang)) ?></span><strong><?= money($gross*(1-$fee/100)) ?></strong><small><?= e(t('seller_demo_balance', $lang)) ?></small></article></div><section class="data-panel table-panel"><div class="panel-title"><div><h2><?= e(t('seller_transactions_title', $lang)) ?></h2><p><?= e(t('seller_transactions_desc', $lang)) ?></p></div></div><div class="responsive-table"><table><thead><tr><th><?= e(t('seller_reference', $lang)) ?></th><th><?= e(t('seller_service', $lang)) ?></th><th><?= e(t('seller_date', $lang)) ?></th><th><?= e(t('seller_method', $lang)) ?></th><th><?= e(t('admin_status', $lang)) ?></th><th><?= e(t('seller_amount', $lang)) ?></th></tr></thead><tbody><?php foreach($transactions as $tx): ?><tr><td><strong><?= e($tx['transaction_ref']) ?></strong><small><?= e($tx['order_number']) ?></small></td><td><?= e($tx['title']) ?></td><td><?= short_date($tx['paid_at']) ?></td><td><?= e(ucfirst($tx['method'])) ?></td><td><span class="status completed"><?= e(status_label($tx['status'], $lang)) ?></span></td><td><strong><?= money($tx['amount']) ?></strong></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($page === 'seller-analytics'):
    $analytics=fetch_all('SELECT services.title,services.views,COUNT(DISTINCT orders.id) AS orders,COALESCE(SUM(CASE WHEN orders.status="completed" THEN orders.total ELSE 0 END),0) AS revenue FROM services LEFT JOIN orders ON orders.service_id=services.id WHERE services.seller_id=? GROUP BY services.id ORDER BY views DESC',[$user['id']]); $maxViews=max(1,...array_map(fn($a)=>(int)$a['views'],$analytics));
    page_heading(t('group_business', $lang),t('seller_analytics_title', $lang),t('seller_analytics_desc', $lang));
?>
<section class="data-panel analytics-panel"><div class="panel-title"><div><h2><?= e(t('seller_service_performance', $lang)) ?></h2><p><?= e(t('seller_views_orders', $lang)) ?></p></div></div><?php foreach($analytics as $row): ?><div class="analytics-row"><div><strong><?= e($row['title']) ?></strong><small><?= (int)$row['views'] ?> <?= e(t('seller_views', $lang)) ?> · <?= (int)$row['orders'] ?> <?= e(t('seller_orders', $lang)) ?></small></div><span><i style="width:<?= round((int)$row['views']/$maxViews*100) ?>%"></i></span><b><?= money($row['revenue']) ?></b></div><?php endforeach; ?></section>

<?php elseif ($page === 'admin-users'):
    $users=fetch_all('SELECT users.*,roles.label AS role_label,roles.name AS role,(SELECT COUNT(*) FROM orders WHERE customer_id=users.id OR seller_id=users.id) AS activity FROM users JOIN roles ON roles.id=users.role_id ORDER BY CASE WHEN users.status="pending_approval" THEN 0 ELSE 1 END, users.created_at DESC');
    page_heading(t('group_system', $lang),t('admin_users_title', $lang),t('admin_users_desc', $lang));
?>
<section class="data-panel table-panel"><div class="responsive-table"><table><thead><tr><th><?= e(t('admin_user_table_user', $lang)) ?></th><th><?= e(t('admin_role', $lang)) ?></th><th><?= e(t('admin_joined', $lang)) ?></th><th><?= e(t('admin_activity', $lang)) ?></th><th><?= e(t('admin_status', $lang)) ?></th><th></th></tr></thead><tbody><?php foreach($users as $member): $sellerAge = age_from_birth_date($member['birth_date'] ?? ''); ?><tr><td><strong><?= e($member['name']) ?></strong><small><?= e($member['email']) ?></small><?php if($member['role']==='seller'): ?><small><?= e($member['phone'] ?: ($lang === 'th' ? 'ยังไม่ระบุเบอร์โทร' : 'No phone on file')) ?></small><?php if(!empty($member['birth_date'])): ?><small><?= e($lang === 'th' ? 'อายุ' : 'Age') ?> <?= (int) ($sellerAge ?? 0) ?> · <?= e(short_date($member['birth_date'])) ?></small><?php endif; ?><?php if(!empty($member['id_card_number'])): ?><small><?= e($lang === 'th' ? 'บัตรประชาชน' : 'Thai ID') ?> <?= e(mask_id_card_number($member['id_card_number'])) ?></small><?php endif; ?><?php if(!empty($member['id_card_front'])): ?><small><a href="<?= e(upload_url($member['id_card_front'])) ?>" target="_blank" rel="noopener"><?= e($lang === 'th' ? 'เปิดดูรูปบัตร' : 'Open ID image') ?></a></small><?php endif; ?><?php endif; ?></td><td><?= e($member['role_label']) ?></td><td><?= short_date($member['created_at']) ?></td><td><?= (int)$member['activity'] ?> <?= e(t('records_label', $lang)) ?></td><td><span class="status <?= e($member['status']) ?>"><?= e(status_label($member['status'], $lang)) ?></span></td><td><?php if((int)$member['id']!==(int)$user['id']): ?><?php if($member['role']==='seller' && $member['status']==='pending_approval'): ?><div class="row-actions"><form method="post"><?php post_fields('admin_user_status'); ?><input type="hidden" name="user_id" value="<?= $member['id'] ?>"><input type="hidden" name="status" value="active"><button class="table-action"><?= e(t('admin_approve', $lang)) ?></button></form><form method="post"><?php post_fields('admin_user_status'); ?><input type="hidden" name="user_id" value="<?= $member['id'] ?>"><input type="hidden" name="status" value="suspended"><button class="table-action"><?= e(t('admin_reject', $lang)) ?></button></form></div><?php else: ?><form method="post"><?php post_fields('admin_user_status'); ?><input type="hidden" name="user_id" value="<?= $member['id'] ?>"><input type="hidden" name="status" value="<?= $member['status']==='active'?'suspended':'active' ?>"><button class="table-action"><?= $member['status']==='active'?e(t('admin_suspend', $lang)):e(t('admin_restore', $lang)) ?></button></form><?php endif; ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($page === 'admin-services'):
    $allServices=fetch_all('SELECT services.*,categories.name AS category,users.name AS seller,users.avatar AS seller_avatar FROM services JOIN categories ON categories.id=services.category_id JOIN users ON users.id=services.seller_id ORDER BY CASE services.status WHEN "pending" THEN 0 WHEN "rejected" THEN 1 WHEN "paused" THEN 2 ELSE 3 END, services.updated_at DESC'); page_heading(t('group_system', $lang),t('admin_services_title', $lang),t('admin_services_desc', $lang));
?>
<section class="data-panel table-panel"><div class="responsive-table"><table><thead><tr><th><?= e(t('admin_service_table_service', $lang)) ?></th><th><?= e(t('admin_service_table_seller', $lang)) ?></th><th><?= e(t('admin_service_table_category', $lang)) ?></th><th><?= e(t('admin_service_table_price', $lang)) ?></th><th><?= e(t('admin_service_table_views', $lang)) ?></th><th><?= e(t('admin_service_table_moderation', $lang)) ?></th></tr></thead><tbody><?php foreach($allServices as $service): ?><tr><td><strong><?= e($service['title']) ?></strong><small>#<?= $service['id'] ?></small></td><td><?= e($service['seller']) ?></td><td><?= e($service['category']) ?></td><td><?= money($service['price']) ?></td><td><?= (int)$service['views'] ?></td><td><form method="post"><?php post_fields('admin_service_status'); ?><input type="hidden" name="service_id" value="<?= $service['id'] ?>"><select name="status" onchange="this.form.submit()"><option value="pending" <?= $service['status']==='pending'?'selected':'' ?>><?= e(t('admin_service_pending', $lang)) ?></option><option value="active" <?= $service['status']==='active'?'selected':'' ?>><?= e(t('admin_service_active', $lang)) ?></option><option value="paused" <?= $service['status']==='paused'?'selected':'' ?>><?= e(t('admin_service_paused', $lang)) ?></option><option value="rejected" <?= $service['status']==='rejected'?'selected':'' ?>><?= e(t('admin_service_rejected', $lang)) ?></option></select></form></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($page === 'admin-orders'):
    $allOrders=fetch_all('SELECT orders.*,services.title,customer.name AS customer,seller.name AS seller FROM orders JOIN services ON services.id=orders.service_id JOIN users customer ON customer.id=orders.customer_id JOIN users seller ON seller.id=orders.seller_id ORDER BY orders.created_at DESC'); page_heading(t('group_system', $lang),t('admin_orders_title', $lang),t('admin_orders_desc', $lang));
?>
<section class="data-panel table-panel"><div class="responsive-table"><table><thead><tr><th><?= e(t('admin_order_table_order', $lang)) ?></th><th><?= e(t('admin_order_table_customer', $lang)) ?></th><th><?= e(t('admin_order_table_seller', $lang)) ?></th><th><?= e(t('admin_status', $lang)) ?></th><th><?= e(t('orders_total', $lang)) ?></th><th><?= e(t('admin_order_table_created', $lang)) ?></th></tr></thead><tbody><?php foreach($allOrders as $order): ?><tr><td><strong><?= e($order['title']) ?></strong><small><?= e($order['order_number']) ?></small></td><td><?= e($order['customer']) ?></td><td><?= e($order['seller']) ?></td><td><span class="status <?= e($order['status']) ?>"><?= e(status_label($order['status'], $lang)) ?></span></td><td><?= money($order['total']) ?></td><td><?= short_date($order['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($page === 'admin-messages'):
    $messageAudit=fetch_all('SELECT messages.*,sender.name AS sender,receiver.name AS receiver,orders.order_number FROM messages JOIN users sender ON sender.id=messages.sender_id JOIN users receiver ON receiver.id=messages.receiver_id LEFT JOIN orders ON orders.id=messages.order_id ORDER BY messages.created_at DESC LIMIT 100'); page_heading(t('group_system', $lang),t('admin_messages_title', $lang),t('admin_messages_desc', $lang));
?>
<section class="data-panel message-audit"><?php foreach($messageAudit as $msg): ?><article><span><?= e(initials($msg['sender'])) ?></span><div><strong><?= e($msg['sender']) ?> <i><?= e(t('admin_message_to', $lang)) ?></i> <?= e($msg['receiver']) ?></strong><p><?= e($msg['body']) ?></p><small><?= e($msg['order_number']?:t('admin_no_order', $lang)) ?> · <?= e(relative_time($msg['created_at'])) ?></small></div></article><?php endforeach; ?></section>

<?php elseif ($page === 'admin-approvals'):
    $pendingSellers = fetch_all('SELECT users.id,users.name,users.email,users.phone,users.birth_date,users.id_card_number,users.id_card_front,users.created_at FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name="seller" AND users.status="pending_approval" ORDER BY users.created_at DESC');
    page_heading(t('group_system', $lang), t('admin_approvals_title', $lang), t('admin_approvals_desc', $lang), '<a class="button button-dark" href="?page=admin-moderation">'.e(t('admin_moderation_title', $lang)).'</a>');
?>
<div class="metric-grid">
    <article><span class="metric-icon amber"><?= icon_svg('moderation') ?></span><strong><?= count($pendingSellers) ?></strong><small><?= e($lang === 'th' ? 'ผู้ขายรออนุมัติ' : 'Pending seller approvals') ?></small></article>
    <article><span class="metric-icon blue"><?= icon_svg('users') ?></span><strong><?= (int) scalar('SELECT COUNT(*) FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name="seller" AND users.status="active"') ?></strong><small><?= e($lang === 'th' ? 'ผู้ขายที่เปิดใช้งานแล้ว' : 'Approved sellers') ?></small></article>
    <article><span class="metric-icon green"><?= icon_svg('analytics') ?></span><strong><?= (int) scalar('SELECT COUNT(*) FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name="seller" AND users.created_at >= date("now","-7 days")') ?></strong><small><?= e($lang === 'th' ? 'สมัครใหม่ 7 วันล่าสุด' : 'New seller signups in 7 days') ?></small></article>
    <article><span class="metric-icon violet"><?= icon_svg('logs') ?></span><strong><?= (int) scalar('SELECT COUNT(*) FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name="seller" AND users.status="suspended"') ?></strong><small><?= e($lang === 'th' ? 'ผู้ขายที่ถูกปฏิเสธ/พัก' : 'Rejected or suspended sellers') ?></small></article>
</div>
<section class="data-panel table-panel">
    <div class="panel-title"><div><h2><?= e($lang === 'th' ? 'คิวอนุมัติผู้ขาย' : 'Seller approval queue') ?></h2><p><?= e($lang === 'th' ? 'ตรวจอายุ เอกสาร และข้อมูลติดต่อก่อนเปิดสิทธิ์ขาย' : 'Check age, ID document, and contact details before enabling seller access.') ?></p></div></div>
    <div class="responsive-table"><table><thead><tr><th><?= e(t('admin_user_table_user', $lang)) ?></th><th><?= e($lang === 'th' ? 'เอกสารยืนยันตัวตน' : 'Identity verification') ?></th><th><?= e(t('admin_joined', $lang)) ?></th><th></th></tr></thead><tbody><?php foreach($pendingSellers as $member): $sellerAge = age_from_birth_date($member['birth_date'] ?? ''); ?><tr><td><strong><?= e($member['name']) ?></strong><small><?= e($member['email']) ?></small><small><?= e($member['phone'] ?: ($lang === 'th' ? 'ยังไม่ระบุเบอร์โทร' : 'No phone on file')) ?></small></td><td><strong><?= e($lang === 'th' ? 'อายุ' : 'Age') ?> <?= (int) ($sellerAge ?? 0) ?></strong><small><?= e($lang === 'th' ? 'วันเกิด' : 'Birth date') ?> <?= e(short_date($member['birth_date'] ?? '')) ?></small><?php if(!empty($member['id_card_number'])): ?><small><?= e($lang === 'th' ? 'เลขบัตร' : 'ID number') ?> <?= e(mask_id_card_number($member['id_card_number'])) ?></small><?php endif; ?><?php if(!empty($member['id_card_front'])): ?><small><a href="<?= e(upload_url($member['id_card_front'])) ?>" target="_blank" rel="noopener"><?= e($lang === 'th' ? 'เปิดดูรูปบัตรประชาชน' : 'Open Thai ID image') ?></a></small><?php endif; ?></td><td><?= short_date($member['created_at']) ?></td><td><div class="row-actions"><form method="post"><?php post_fields('admin_user_status', '?page=admin-approvals'); ?><input type="hidden" name="user_id" value="<?= $member['id'] ?>"><input type="hidden" name="status" value="active"><button class="table-action"><?= e(t('admin_approve', $lang)) ?></button></form><form method="post"><?php post_fields('admin_user_status', '?page=admin-approvals'); ?><input type="hidden" name="user_id" value="<?= $member['id'] ?>"><input type="hidden" name="status" value="suspended"><button class="table-action"><?= e(t('admin_reject', $lang)) ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
    <?php if (!$pendingSellers): ?><div class="empty-state" style="margin:18px"><strong><?= e($lang === 'th' ? 'ไม่มีผู้ขายรออนุมัติ' : 'No pending seller approvals') ?></strong><p><?= e($lang === 'th' ? 'ตอนนี้คิวเอกสารว่างทั้งหมดแล้ว' : 'The seller verification queue is currently clear.') ?></p></div><?php endif; ?>
</section>
<section class="data-panel table-panel">
    <div class="panel-title"><div><h2><?= e($lang === 'th' ? 'ทางลัดสำหรับแอดมิน' : 'Admin shortcuts') ?></h2><p><?= e($lang === 'th' ? 'ไปต่อยังหน้าที่เกี่ยวข้องกับการตรวจสอบ' : 'Jump to the other review areas quickly.') ?></p></div></div>
    <div class="control-link-grid" style="max-width:none;margin:0">
        <a href="?page=admin-users"><strong><?= e(t('admin_users_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'ดูผู้ใช้ทั้งหมดและสถานะบัญชี' : 'See all users and account status') ?></small></a>
        <a href="?page=admin-moderation"><strong><?= e(t('admin_moderation_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'ดูคิวบริการและออเดอร์ที่ต้องตรวจ' : 'Review services and orders that need attention') ?></small></a>
        <a href="?page=admin-logs"><strong><?= e(t('admin_logs_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'ตรวจ log การอนุมัติย้อนหลัง' : 'Inspect approval history in the logs') ?></small></a>
    </div>
</section>

<?php elseif ($page === 'admin-moderation'):
    $pendingSellers = fetch_all('SELECT users.id,users.name,users.email,users.phone,users.birth_date,users.id_card_number,users.id_card_front,users.created_at FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name="seller" AND users.status="pending_approval" ORDER BY users.created_at DESC');
    $pendingOrders = fetch_all('SELECT orders.id,orders.order_number,orders.status,services.title,customer.name AS customer,seller.name AS seller FROM orders JOIN services ON services.id=orders.service_id JOIN users customer ON customer.id=orders.customer_id JOIN users seller ON seller.id=orders.seller_id WHERE orders.status IN ("pending","review") ORDER BY orders.created_at DESC LIMIT 30');
    $pendingServicesQueue = fetch_all('SELECT services.id,services.title,services.status,users.name AS seller,categories.name AS category,services.updated_at FROM services JOIN users ON users.id=services.seller_id JOIN categories ON categories.id=services.category_id WHERE services.status="pending" ORDER BY services.updated_at DESC LIMIT 30');
    $pausedServices = fetch_all('SELECT services.id,services.title,services.status,users.name AS seller,categories.name AS category FROM services JOIN users ON users.id=services.seller_id JOIN categories ON categories.id=services.category_id WHERE services.status IN ("paused","rejected") ORDER BY services.updated_at DESC LIMIT 20');
    page_heading(t('group_system', $lang), t('admin_moderation_title', $lang), t('admin_moderation_desc', $lang), '<a class="button button-dark" href="?page=admin-users">'.e(t('admin_users_title', $lang)).'</a>');
?>
<div class="metric-grid">
    <article><span class="metric-icon amber"><?= icon_svg('moderation') ?></span><strong><?= count($pendingSellers) ?></strong><small><?= e($lang === 'th' ? 'ผู้ขายรออนุมัติ' : 'Pending sellers') ?></small></article>
    <article><span class="metric-icon blue"><?= icon_svg('orders') ?></span><strong><?= count($pendingOrders) ?></strong><small><?= e($lang === 'th' ? 'ออเดอร์ที่ต้องดู' : 'Orders to review') ?></small></article>
    <article><span class="metric-icon violet"><?= icon_svg('categories') ?></span><strong><?= count($pendingServicesQueue) ?></strong><small><?= e($lang === 'th' ? 'บริการรอตรวจ' : 'Services pending review') ?></small></article>
    <article><span class="metric-icon green"><?= icon_svg('analytics') ?></span><strong><?= (int) scalar('SELECT COUNT(*) FROM orders WHERE status="completed"') ?></strong><small><?= e($lang === 'th' ? 'ออเดอร์เสร็จแล้ว' : 'Completed orders') ?></small></article>
</div>
<section class="data-panel table-panel">
    <div class="panel-title"><div><h2><?= e($lang === 'th' ? 'ผู้ขายรออนุมัติ' : 'Pending sellers') ?></h2><p><?= e($lang === 'th' ? 'อนุมัติหรือปฏิเสธจากหน้าจอนี้' : 'Approve or reject from here') ?></p></div></div>
    <div class="responsive-table"><table><thead><tr><th><?= e(t('admin_user_table_user', $lang)) ?></th><th><?= e($lang === 'th' ? 'ยืนยันตัวตน' : 'Verification') ?></th><th><?= e(t('admin_joined', $lang)) ?></th><th></th></tr></thead><tbody><?php foreach($pendingSellers as $member): $sellerAge = age_from_birth_date($member['birth_date'] ?? ''); ?><tr><td><strong><?= e($member['name']) ?></strong><small><?= e($member['email']) ?></small><small><?= e($member['phone'] ?: ($lang === 'th' ? 'ยังไม่ระบุเบอร์โทร' : 'No phone on file')) ?></small></td><td><strong><?= e($lang === 'th' ? 'อายุ' : 'Age') ?> <?= (int) ($sellerAge ?? 0) ?></strong><small><?= e($lang === 'th' ? 'เกิด' : 'Born') ?> <?= e(short_date($member['birth_date'] ?? '')) ?></small><?php if(!empty($member['id_card_number'])): ?><small><?= e($lang === 'th' ? 'เลขบัตร' : 'ID number') ?> <?= e(mask_id_card_number($member['id_card_number'])) ?></small><?php endif; ?><?php if(!empty($member['id_card_front'])): ?><small><a href="<?= e(upload_url($member['id_card_front'])) ?>" target="_blank" rel="noopener"><?= e($lang === 'th' ? 'เปิดดูรูปบัตรประชาชน' : 'Open Thai ID image') ?></a></small><?php endif; ?></td><td><?= short_date($member['created_at']) ?></td><td><div class="row-actions"><form method="post"><?php post_fields('admin_user_status'); ?><input type="hidden" name="user_id" value="<?= $member['id'] ?>"><input type="hidden" name="status" value="active"><button class="table-action"><?= e(t('admin_approve', $lang)) ?></button></form><form method="post"><?php post_fields('admin_user_status'); ?><input type="hidden" name="user_id" value="<?= $member['id'] ?>"><input type="hidden" name="status" value="suspended"><button class="table-action"><?= e(t('admin_reject', $lang)) ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
</section>
<section class="data-panel table-panel">
    <div class="panel-title"><div><h2><?= e($lang === 'th' ? 'ออเดอร์ที่ต้องตรวจ' : 'Orders needing review') ?></h2><p><?= e($lang === 'th' ? 'ตรวจงานที่ยังไม่ปิด' : 'Orders that still need attention') ?></p></div></div>
    <div class="responsive-table"><table><thead><tr><th><?= e(t('orders_order', $lang)) ?></th><th><?= e(t('admin_order_table_customer', $lang)) ?></th><th><?= e(t('admin_order_table_seller', $lang)) ?></th><th><?= e(t('orders_status', $lang)) ?></th></tr></thead><tbody><?php foreach($pendingOrders as $order): ?><tr><td><strong><?= e($order['title']) ?></strong><small><?= e($order['order_number']) ?></small></td><td><?= e($order['customer']) ?></td><td><?= e($order['seller']) ?></td><td><span class="status <?= e($order['status']) ?>"><?= e(status_label($order['status'], $lang)) ?></span></td></tr><?php endforeach; ?></tbody></table></div>
</section>
<section class="data-panel table-panel">
    <div class="panel-title"><div><h2><?= e($lang === 'th' ? 'บริการใหม่รอตรวจ' : 'New services waiting review') ?></h2><p><?= e($lang === 'th' ? 'อนุมัติก่อนให้ขึ้นหน้า marketplace' : 'Approve these before they go live in the marketplace') ?></p></div></div>
    <div class="responsive-table"><table><thead><tr><th><?= e(t('admin_service_table_service', $lang)) ?></th><th><?= e(t('admin_service_table_seller', $lang)) ?></th><th><?= e(t('admin_service_table_category', $lang)) ?></th><th><?= e(t('admin_status', $lang)) ?></th><th></th></tr></thead><tbody><?php foreach($pendingServicesQueue as $service): ?><tr><td><strong><?= e($service['title']) ?></strong><small>#<?= $service['id'] ?></small></td><td><?= e($service['seller']) ?></td><td><?= e($service['category']) ?></td><td><span class="status <?= e($service['status']) ?>"><?= e(status_label($service['status'], $lang)) ?></span></td><td><div class="row-actions"><form method="post"><?php post_fields('admin_service_status'); ?><input type="hidden" name="service_id" value="<?= $service['id'] ?>"><input type="hidden" name="status" value="active"><button class="table-action"><?= e(t('admin_approve', $lang)) ?></button></form><form method="post"><?php post_fields('admin_service_status'); ?><input type="hidden" name="service_id" value="<?= $service['id'] ?>"><input type="hidden" name="status" value="rejected"><button class="table-action"><?= e(t('admin_reject', $lang)) ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
</section>
<section class="data-panel table-panel">
    <div class="panel-title"><div><h2><?= e($lang === 'th' ? 'บริการที่ถูกพักหรือปฏิเสธ' : 'Paused or rejected services') ?></h2><p><?= e($lang === 'th' ? 'รายการที่ควรตรวจซ้ำ' : 'Items worth reviewing again') ?></p></div></div>
    <div class="responsive-table"><table><thead><tr><th><?= e(t('admin_service_table_service', $lang)) ?></th><th><?= e(t('admin_service_table_seller', $lang)) ?></th><th><?= e(t('admin_service_table_category', $lang)) ?></th><th><?= e(t('admin_status', $lang)) ?></th></tr></thead><tbody><?php foreach($pausedServices as $service): ?><tr><td><strong><?= e($service['title']) ?></strong><small>#<?= $service['id'] ?></small></td><td><?= e($service['seller']) ?></td><td><?= e($service['category']) ?></td><td><span class="status <?= e($service['status']) ?>"><?= e(status_label($service['status'], $lang)) ?></span></td></tr><?php endforeach; ?></tbody></table></div>
</section>

<?php elseif ($page === 'admin-categories'):
    $categories = fetch_all('SELECT categories.*,COUNT(services.id) AS service_count FROM categories LEFT JOIN services ON services.category_id=categories.id GROUP BY categories.id ORDER BY categories.id');
    page_heading(t('group_system', $lang), t('admin_categories_title', $lang), t('admin_categories_desc', $lang), '<a class="button button-dark" href="?page=services">'.e(t('admin_service_table_service', $lang)).'</a>');
?>
<div class="metric-grid">
    <article><span class="metric-icon blue"><?= icon_svg('categories') ?></span><strong><?= count($categories) ?></strong><small><?= e($lang === 'th' ? 'หมวดทั้งหมด' : 'Total categories') ?></small></article>
    <article><span class="metric-icon green"><?= icon_svg('analytics') ?></span><strong><?= array_sum(array_map(fn($c)=>(int)$c['service_count'],$categories)) ?></strong><small><?= e($lang === 'th' ? 'บริการที่อยู่ในหมวด' : 'Services grouped') ?></small></article>
    <article><span class="metric-icon violet"><?= icon_svg('reports') ?></span><strong><?= count(array_filter($categories, fn($c) => (int) $c['service_count'] === 0)) ?></strong><small><?= e($lang === 'th' ? 'หมวดที่ยังว่าง' : 'Empty categories') ?></small></article>
</div>
<form class="settings-card wide" method="post"><?php post_fields('admin_category_save'); ?><h2><?= e($lang === 'th' ? 'เพิ่มหมวดใหม่' : 'Add category') ?></h2><p><?= e($lang === 'th' ? 'ใช้หมวดใหม่เพื่อแยกบริการให้ค้นง่ายขึ้น' : 'Create a category to keep services organized.') ?></p><div class="form-grid"><label><?= e($lang === 'th' ? 'ชื่อหมวด' : 'Category name') ?><input name="name" required></label><label><?= e($lang === 'th' ? 'รหัสหมวด' : 'Category code') ?><input name="code" placeholder="WD"></label><label><?= e($lang === 'th' ? 'สี' : 'Color') ?><select name="color"><option value="blue">Blue</option><option value="green">Green</option><option value="violet">Violet</option><option value="amber">Amber</option></select></label></div><footer><button class="button button-dark"><?= e($lang === 'th' ? 'บันทึกหมวด' : 'Save category') ?></button></footer></form>
<section class="data-panel table-panel">
    <div class="panel-title"><div><h2><?= e($lang === 'th' ? 'รายการหมวดหมู่' : 'Category list') ?></h2><p><?= e($lang === 'th' ? 'แก้ชื่อ รหัส หรือสีได้จากตารางนี้' : 'Edit names, codes, and colors from this table.') ?></p></div></div>
    <div class="responsive-table"><table><thead><tr><th><?= e($lang === 'th' ? 'หมวด' : 'Category') ?></th><th><?= e($lang === 'th' ? 'รหัส' : 'Code') ?></th><th><?= e($lang === 'th' ? 'สี' : 'Color') ?></th><th><?= e($lang === 'th' ? 'บริการ' : 'Services') ?></th><th></th></tr></thead><tbody><?php foreach($categories as $category): ?><tr><form method="post"><?php post_fields('admin_category_save'); ?><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><td><input name="name" value="<?= e($category['name']) ?>"></td><td><input name="code" value="<?= e($category['code']) ?>"></td><td><select name="color"><option value="blue" <?= $category['color']==='blue'?'selected':'' ?>>Blue</option><option value="green" <?= $category['color']==='green'?'selected':'' ?>>Green</option><option value="violet" <?= $category['color']==='violet'?'selected':'' ?>>Violet</option><option value="amber" <?= $category['color']==='amber'?'selected':'' ?>>Amber</option></select></td><td><?= (int) $category['service_count'] ?></td><td><div class="row-actions"><button class="table-action" type="submit"><?= e($lang === 'th' ? 'บันทึก' : 'Save') ?></button></form><form method="post"><?php post_fields('admin_category_delete'); ?><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><button class="table-action" type="submit" <?= ((int) $category['service_count'] > 0) ? 'disabled' : '' ?>><?= e($lang === 'th' ? 'ลบ' : 'Delete') ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
</section>

<?php elseif ($page === 'admin-coupons'):
    $coupons = fetch_all('SELECT coupons.*,COUNT(orders.id) AS usage_count FROM coupons LEFT JOIN orders ON orders.coupon_code=coupons.code GROUP BY coupons.id ORDER BY coupons.id DESC');
    page_heading(t('group_system', $lang), t('admin_coupons_title', $lang), t('admin_coupons_desc', $lang), '<a class="button button-dark" href="?page=checkout">'.e(t('checkout_title', $lang)).'</a>');
?>
<div class="metric-grid">
    <article><span class="metric-icon blue"><?= icon_svg('coupon') ?></span><strong><?= count($coupons) ?></strong><small><?= e($lang === 'th' ? 'คูปองทั้งหมด' : 'Total coupons') ?></small></article>
    <article><span class="metric-icon green"><?= icon_svg('moderation') ?></span><strong><?= count(array_filter($coupons, fn($c)=>(int)$c['active']===1)) ?></strong><small><?= e($lang === 'th' ? 'คูปองที่เปิดอยู่' : 'Active coupons') ?></small></article>
    <article><span class="metric-icon violet"><?= icon_svg('reports') ?></span><strong><?= array_sum(array_map(fn($c)=>(int)$c['usage_count'],$coupons)) ?></strong><small><?= e($lang === 'th' ? 'การใช้งานรวม' : 'Total uses') ?></small></article>
</div>
<form class="settings-card wide" method="post"><?php post_fields('admin_coupon_save'); ?><h2><?= e($lang === 'th' ? 'สร้างคูปองใหม่' : 'Create coupon') ?></h2><p><?= e($lang === 'th' ? 'กำหนดโค้ดส่วนลด วันหมดอายุ และสถานะการใช้งาน' : 'Set a code, discount, expiry date, and activation state.') ?></p><div class="form-grid"><label><?= e($lang === 'th' ? 'โค้ด' : 'Code') ?><input name="code" required></label><label><?= e($lang === 'th' ? 'ส่วนลด %' : 'Discount %') ?><input type="number" name="discount_percent" min="1" max="90" value="10" required></label><label><?= e($lang === 'th' ? 'วันหมดอายุ' : 'Expiry date') ?><input type="datetime-local" name="expires_at"></label><label class="toggle-row"><span><strong><?= e($lang === 'th' ? 'เปิดใช้งาน' : 'Active') ?></strong><small><?= e($lang === 'th' ? 'คูปองนี้ใช้งานได้ทันที' : 'Coupon can be used immediately') ?></small></span><input type="checkbox" name="active" checked><i></i></label></div><footer><button class="button button-dark"><?= e($lang === 'th' ? 'บันทึกคูปอง' : 'Save coupon') ?></button></footer></form>
<section class="data-panel table-panel">
    <div class="panel-title"><div><h2><?= e($lang === 'th' ? 'รายการคูปอง' : 'Coupon list') ?></h2><p><?= e($lang === 'th' ? 'แก้ไข ปิดใช้งาน หรือเอาออกได้จากตรงนี้' : 'Edit, disable, or remove coupon codes.') ?></p></div></div>
    <div class="responsive-table"><table><thead><tr><th><?= e($lang === 'th' ? 'โค้ด' : 'Code') ?></th><th><?= e($lang === 'th' ? 'ส่วนลด' : 'Discount') ?></th><th><?= e($lang === 'th' ? 'ใช้งาน' : 'Active') ?></th><th><?= e($lang === 'th' ? 'หมดอายุ' : 'Expires') ?></th><th><?= e($lang === 'th' ? 'ใช้ไป' : 'Uses') ?></th><th></th></tr></thead><tbody><?php foreach($coupons as $coupon): ?><tr><form method="post"><?php post_fields('admin_coupon_save'); ?><input type="hidden" name="coupon_id" value="<?= (int) $coupon['id'] ?>"><td><input name="code" value="<?= e($coupon['code']) ?>"></td><td><input type="number" name="discount_percent" min="1" max="90" value="<?= (int) $coupon['discount_percent'] ?>"></td><td><label class="toggle-row"><span><strong><?= e($lang === 'th' ? 'เปิดอยู่' : 'On') ?></strong><small><?= e($lang === 'th' ? 'พร้อมใช้งาน' : 'Ready to use') ?></small></span><input type="checkbox" name="active" <?= (int) $coupon['active'] === 1 ? 'checked' : '' ?>><i></i></label></td><td><input name="expires_at" value="<?= e($coupon['expires_at'] ?? '') ?>" placeholder="2030-12-31 23:59:59"></td><td><?= (int) $coupon['usage_count'] ?></td><td><div class="row-actions"><button class="table-action" type="submit"><?= e($lang === 'th' ? 'บันทึก' : 'Save') ?></button></form><form method="post"><?php post_fields('admin_coupon_delete'); ?><input type="hidden" name="coupon_id" value="<?= (int) $coupon['id'] ?>"><button class="table-action" type="submit"><?= e($lang === 'th' ? 'ลบ' : 'Delete') ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
</section>

<?php elseif ($page === 'admin-logs'):
    $logSummary = fetch_all('SELECT event,COUNT(*) AS count FROM security_logs GROUP BY event ORDER BY count DESC');
    $logs = fetch_all('SELECT security_logs.*,users.name AS user_name,users.email AS user_email FROM security_logs LEFT JOIN users ON users.id=security_logs.user_id ORDER BY security_logs.created_at DESC LIMIT 150');
    $logTotals = fetch_one('SELECT COUNT(*) AS total, COUNT(DISTINCT user_id) AS users, COUNT(CASE WHEN event LIKE "login%" THEN 1 END) AS logins, COUNT(CASE WHEN event LIKE "wallet%" THEN 1 END) AS wallet_events, COUNT(CASE WHEN event IN ("seller_approved","seller_rejected","broadcast_sent") THEN 1 END) AS admin_actions FROM security_logs');
    page_heading(t('group_system', $lang), t('admin_logs_title', $lang), t('admin_logs_desc', $lang));
?>
<div class="metric-grid">
    <article><span class="metric-icon blue"><?= icon_svg('logs') ?></span><strong><?= (int) ($logTotals['total'] ?? 0) ?></strong><small><?= e($lang === 'th' ? 'บันทึกทั้งหมด' : 'Total log records') ?></small></article>
    <article><span class="metric-icon green"><?= icon_svg('analytics') ?></span><strong><?= (int) scalar('SELECT COUNT(*) FROM security_logs WHERE date(created_at)=date("now")') ?></strong><small><?= e($lang === 'th' ? 'วันนี้' : 'Today') ?></small></article>
    <article><span class="metric-icon violet"><?= icon_svg('users') ?></span><strong><?= (int) ($logTotals['users'] ?? 0) ?></strong><small><?= e($lang === 'th' ? 'ผู้ใช้ที่เกี่ยวข้อง' : 'Users involved') ?></small></article>
    <article><span class="metric-icon amber"><?= icon_svg('broadcast') ?></span><strong><?= (int) ($logTotals['admin_actions'] ?? 0) ?></strong><small><?= e($lang === 'th' ? 'การกระทำของแอดมิน' : 'Admin actions') ?></small></article>
</div>
<section class="data-panel analytics-panel">
    <div class="panel-title"><div><h2><?= e($lang === 'th' ? 'ประเภทเหตุการณ์' : 'Event breakdown') ?></h2><p><?= e($lang === 'th' ? 'เหตุการณ์ที่เกิดบ่อยที่สุด' : 'Most common security events') ?></p></div></div>
    <?php if ($logSummary): ?>
        <?php $maxEvent = max(1, ...array_map(fn($r)=>(int)$r['count'],$logSummary)); foreach($logSummary as $item): ?>
            <div class="analytics-row"><div><strong><?= e(security_event_label($item['event'], $lang)) ?></strong><small><?= e($item['event']) ?></small></div><span><i style="width:<?= round((int)$item['count']/$maxEvent*100) ?>%"></i></span><b><?= (int) $item['count'] ?></b></div>
        <?php endforeach; ?>
    <?php else: ?>
        <?= empty_state_html($lang === 'th' ? 'ยังไม่มีบันทึกความปลอดภัย' : 'No security logs yet', $lang === 'th' ? 'ระบบจะบันทึกเหตุการณ์สำคัญที่เกี่ยวกับการเข้าสู่ระบบ การอนุมัติ และการเปลี่ยนแปลงค่า' : 'Security events will appear here when users log in, approvals happen, or settings change.', $lang === 'th' ? 'ไปที่หน้าแดชบอร์ด' : 'Go to dashboard', '?page=admin-control', '◎') ?>
    <?php endif; ?>
</section>
<section class="data-panel message-audit">
    <?php if ($logs): foreach($logs as $log): ?><article><span><?= e(initials($log['user_name'] ?? 'System')) ?></span><div><strong><?= e($log['user_name'] ?? ($lang === 'th' ? 'ระบบ' : 'System')) ?> <i><?= e(security_event_label($log['event'], $lang)) ?></i></strong><p><?= e($log['user_email'] ?? '') ?><?= $log['ip_address'] ? ' · ' . e($log['ip_address']) : '' ?></p><small><?= e($log['event']) ?> · <?= e(relative_time($log['created_at'])) ?></small></div></article><?php endforeach; else: ?><?= empty_state_html($lang === 'th' ? 'ยังไม่มีบันทึก' : 'No logs found', $lang === 'th' ? 'เมื่อมีเหตุการณ์สำคัญ ระบบจะเก็บไว้ที่นี่' : 'Important system events will appear here.', '', '', '◎') ?><?php endif; ?>
</section>

<?php elseif ($page === 'admin-broadcast'):
    $currentBanner = announcement_banner_setting();
    $currentBannerDuration = announcement_banner_duration_setting();
    $announcementCount = (int) scalar('SELECT COUNT(*) FROM notifications WHERE type="announcement"');
    page_heading(t('group_system', $lang), t('admin_broadcast_title', $lang), t('admin_broadcast_desc', $lang));
?>
<div class="metric-grid">
    <article><span class="metric-icon blue"><?= icon_svg('broadcast') ?></span><strong><?= $announcementCount ?></strong><small><?= e($lang === 'th' ? 'ประกาศที่ส่งแล้ว' : 'Announcements sent') ?></small></article>
    <article><span class="metric-icon green"><?= icon_svg('users') ?></span><strong><?= (int) scalar('SELECT COUNT(*) FROM users WHERE status="active"') ?></strong><small><?= e($lang === 'th' ? 'ผู้ใช้ที่รับได้' : 'Reachable users') ?></small></article>
    <article><span class="metric-icon violet"><?= icon_svg('info') ?></span><strong><?= $currentBanner !== '' ? 1 : 0 ?></strong><small><?= e($lang === 'th' ? 'แถบประกาศที่ใช้อยู่' : 'Active banner') ?></small></article>
    <article><span class="metric-icon amber"><?= icon_svg('time') ?></span><strong><?= $currentBannerDuration ?>s</strong><small><?= e($lang === 'th' ? 'เวลาที่แสดง' : 'Display time') ?></small></article>
</div>
<form class="settings-card wide" method="post"><?php post_fields('admin_broadcast'); ?><h2><?= e($lang === 'th' ? 'ส่งประกาศถึงทุกคน' : 'Send a broadcast') ?></h2><p><?= e($lang === 'th' ? 'ข้อความนี้จะแสดงเป็นแถบประกาศบนเว็บ และส่งแจ้งเตือนให้ผู้ใช้ที่เปิดใช้งาน' : 'This banner appears on the site and can also notify active users.') ?></p><div class="form-grid"><label class="full"><?= e(t('admin_announcement', $lang)) ?><textarea name="announcement_banner" rows="4" placeholder="<?= e($lang === 'th' ? 'พิมพ์ประกาศสั้นๆ...' : 'Write a short announcement...') ?>"><?= e($currentBanner) ?></textarea></label><label><?= e($lang === 'th' ? 'แสดงประกาศนานเท่าไร' : 'How long should the banner stay visible?') ?><select name="announcement_banner_duration"><?php foreach ([10, 15, 20, 25, 30] as $seconds): ?><option value="<?= $seconds ?>" <?= $currentBannerDuration === $seconds ? 'selected' : '' ?>><?= $seconds ?> <?= e($lang === 'th' ? 'วินาที' : 'seconds') ?></option><?php endforeach; ?></select></label><label class="full toggle-row"><span><strong><?= e($lang === 'th' ? 'ส่งแจ้งเตือนด้วย' : 'Also send notifications') ?></strong><small><?= e($lang === 'th' ? 'ผู้ใช้ที่เปิดใช้งานจะได้รับแจ้งเตือนในระบบ' : 'Active users will receive an in-app notification.') ?></small></span><input type="checkbox" name="send_notification" checked><i></i></label></div><footer><button class="button button-dark"><?= e($lang === 'th' ? 'บันทึกประกาศ' : 'Save broadcast') ?></button></footer></form>

<?php elseif ($page === 'admin-export'):
    $user = require_role('admin');
    page_heading(t('group_system', $lang), t('admin_export_title', $lang), t('admin_export_desc', $lang), '<a class="button button-dark" href="?page=admin-finance">'.e(t('admin_finance_title', $lang)).'</a>');
?>
<section class="control-link-grid" style="max-width:1160px;margin:auto;">
    <a href="?page=admin-export&type=users"><strong><?= e($lang === 'th' ? 'ส่งออกผู้ใช้' : 'Export users') ?></strong><small><?= e($lang === 'th' ? 'ไฟล์ CSV ของบัญชีทั้งหมด' : 'CSV file for all accounts') ?></small></a>
    <a href="?page=admin-export&type=orders"><strong><?= e($lang === 'th' ? 'ส่งออกออเดอร์' : 'Export orders') ?></strong><small><?= e($lang === 'th' ? 'ข้อมูลคำสั่งซื้อทั้งหมด' : 'All order records') ?></small></a>
    <a href="?page=admin-export&type=finance"><strong><?= e($lang === 'th' ? 'ส่งออกรายได้' : 'Export finance') ?></strong><small><?= e($lang === 'th' ? 'ธุรกรรมการเงินและการจ่ายเงิน' : 'Payments and revenue data') ?></small></a>
    <a href="?page=admin-export&type=logs"><strong><?= e($lang === 'th' ? 'ส่งออกบันทึก' : 'Export logs') ?></strong><small><?= e($lang === 'th' ? 'เหตุการณ์ด้านความปลอดภัย' : 'Security event log') ?></small></a>
    <a href="?page=admin-export&type=categories"><strong><?= e($lang === 'th' ? 'ส่งออกหมวด' : 'Export categories') ?></strong><small><?= e($lang === 'th' ? 'รายการหมวดหมู่บริการ' : 'Service category list') ?></small></a>
</section>

<?php elseif ($page === 'admin-control'):
    $settings=[]; foreach(fetch_all('SELECT * FROM system_settings') as $setting)$settings[$setting['setting_key']]=$setting['setting_value'];
    $totalUsers = (int) scalar('SELECT COUNT(*) FROM users');
    $activeUsers = (int) scalar('SELECT COUNT(*) FROM users WHERE status="active"');
    $pendingSellers = (int) scalar('SELECT COUNT(*) FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name="seller" AND users.status="pending_approval"');
    $pendingOrders = (int) scalar('SELECT COUNT(*) FROM orders WHERE status IN ("pending","review")');
    $completedOrders = (int) scalar('SELECT COUNT(*) FROM orders WHERE status="completed"');
    $activeServices = (int) scalar('SELECT COUNT(*) FROM services WHERE status="active"');
    $unreadMessages = (int) scalar('SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0', [(int) $user['id']]);
    $grossRevenue = (float) scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status="paid"');
    $feeRate = platform_fee_setting(10);
    $platformRevenue = round($grossRevenue * $feeRate / 100, 2);
    $sellerPayouts = round($grossRevenue - $platformRevenue, 2);
    $pendingServices = (int) scalar('SELECT COUNT(*) FROM services WHERE status="pending"');
    $pendingTopups = (int) scalar('SELECT COUNT(*) FROM wallet_transactions WHERE status="pending"');
    $messageCountAll = (int) scalar('SELECT COUNT(*) FROM messages');
    $completionRate = (float) scalar('SELECT CASE WHEN COUNT(*)=0 THEN 0 ELSE SUM(status="completed")*100.0/COUNT(*) END FROM orders');
    $statusRows = fetch_all('SELECT status, COUNT(*) AS total FROM orders GROUP BY status');
    $statusCounts = ['pending' => 0, 'in_progress' => 0, 'review' => 0, 'completed' => 0, 'cancelled' => 0];
    foreach ($statusRows as $row) {
        $statusCounts[(string) $row['status']] = (int) $row['total'];
    }
    $statusTotal = max(1, array_sum($statusCounts));
    $trendRows = fetch_all('SELECT date(created_at) AS day, COUNT(*) AS orders, COALESCE(SUM(total),0) AS volume FROM orders WHERE created_at >= date("now","-13 days") GROUP BY day ORDER BY day');
    $trendMap = [];
    foreach ($trendRows as $row) {
        $trendMap[$row['day']] = ['orders' => (int) $row['orders'], 'volume' => (float) $row['volume']];
    }
    $adminTrend = [];
    for ($i = 13; $i >= 0; $i--) {
        $day = (new DateTimeImmutable('today'))->modify("-$i days")->format('Y-m-d');
        $adminTrend[] = [
            'day' => $day,
            'label' => (new DateTimeImmutable($day))->format('d M'),
            'orders' => $trendMap[$day]['orders'] ?? 0,
            'volume' => $trendMap[$day]['volume'] ?? 0.0,
        ];
    }
    $maxTrendVolume = max(1, ...array_map(fn($row) => (float) $row['volume'], $adminTrend));
    $maxTrendOrders = max(1, ...array_map(fn($row) => (int) $row['orders'], $adminTrend));
    $trendVolumePoints = [];
    $trendOrderPoints = [];
    foreach ($adminTrend as $index => $row) {
        $x = 28 + ($index * (424 / max(1, count($adminTrend) - 1)));
        $trendVolumePoints[] = round($x, 1) . ',' . round(178 - (((float) $row['volume'] / $maxTrendVolume) * 128), 1);
        $trendOrderPoints[] = round($x, 1) . ',' . round(178 - (((int) $row['orders'] / $maxTrendOrders) * 128), 1);
    }
    page_heading(t('group_system', $lang), t('admin_control_title', $lang), t('admin_control_desc', $lang), '<div class="row-actions"><a class="button button-light" href="?page=admin-settings">'.e(t('admin_settings_title', $lang)).'</a><a class="button button-dark" href="?page=admin-finance">'.e(t('admin_finance_title', $lang)).'</a></div>');
?>
<section class="control-hero">
    <div class="control-hero-copy">
        <span class="kicker"><?= e($lang === 'th' ? 'สถานะระบบ' : 'System status') ?></span>
        <h2><?= e($lang === 'th' ? 'จุดสำคัญของเว็บไซต์อยู่ตรงนี้' : 'The main controls live here') ?></h2>
        <p><?= e($lang === 'th' ? 'ใช้หน้านี้เช็กคิวที่ต้องจัดการ ค่าระบบสำคัญ และลิงก์ไปส่วนที่ต้องใช้บ่อย' : 'Use this page to monitor queues, switch key settings, and jump to the places you need most.') ?></p>
    </div>
    <div class="control-hero-actions">
        <a class="button button-dark" href="?page=admin-settings"><?= e(t('admin_settings_title', $lang)) ?></a>
        <a class="button button-light" href="?page=admin-users"><?= e(t('admin_users_title', $lang)) ?></a>
    </div>
</section>

<section class="command-snapshot">
    <div class="command-main-card">
        <span class="kicker"><?= e($lang === 'th' ? 'Command center' : 'Command center') ?></span>
        <h2><?= e($lang === 'th' ? 'ภาพรวมตลาดที่ต้องเห็นก่อนเริ่มจัดการ' : 'A fast read before you manage the marketplace') ?></h2>
        <div class="command-money-grid">
            <article><small><?= e($lang === 'th' ? 'ยอดชำระรวม' : 'Gross paid') ?></small><strong><?= money($grossRevenue) ?></strong></article>
            <article><small><?= e($lang === 'th' ? 'ค่าธรรมเนียมแพลตฟอร์ม' : 'Platform fee') ?></small><strong><?= money($platformRevenue) ?></strong></article>
            <article><small><?= e($lang === 'th' ? 'จ่ายให้ผู้ขาย' : 'Seller payouts') ?></small><strong><?= money($sellerPayouts) ?></strong></article>
        </div>
    </div>
    <aside class="command-health-card">
        <div>
            <span><?= e($lang === 'th' ? 'Completion rate' : 'Completion rate') ?></span>
            <strong><?= number_format($completionRate, 0) ?>%</strong>
        </div>
        <div class="command-ring" style="--value:<?= max(0, min(100, round($completionRate))) ?>%"><i></i></div>
        <p><?= e($lang === 'th' ? 'อัตรางานที่จบแล้วเทียบกับคำสั่งซื้อทั้งหมด' : 'Completed orders compared with all marketplace orders.') ?></p>
    </aside>
</section>

<section class="data-panel admin-chart-panel">
    <div class="panel-title">
        <div>
            <h2><?= e($lang === 'th' ? 'กราฟภาพรวมตลาด 14 วัน' : '14-day marketplace chart') ?></h2>
            <p><?= e($lang === 'th' ? 'ใช้ข้อมูลคำสั่งซื้อและยอดขายจริงจากระบบ' : 'Based on real order and revenue data from the system.') ?></p>
        </div>
        <div class="chart-legend">
            <span><i class="volume"></i><?= e($lang === 'th' ? 'ยอดขาย' : 'Revenue') ?></span>
            <span><i class="orders"></i><?= e($lang === 'th' ? 'ออเดอร์' : 'Orders') ?></span>
        </div>
    </div>
    <div class="apple-chart-wrap">
        <svg class="apple-line-chart" viewBox="0 0 480 220" role="img" aria-label="<?= e($lang === 'th' ? 'กราฟยอดขายและออเดอร์ 14 วัน' : 'Revenue and orders for 14 days') ?>">
            <defs>
                <linearGradient id="adminRevenueFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#0a84ff" stop-opacity=".22"/>
                    <stop offset="100%" stop-color="#0a84ff" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <g class="chart-grid">
                <line x1="28" y1="50" x2="452" y2="50"/>
                <line x1="28" y1="82" x2="452" y2="82"/>
                <line x1="28" y1="114" x2="452" y2="114"/>
                <line x1="28" y1="146" x2="452" y2="146"/>
                <line x1="28" y1="178" x2="452" y2="178"/>
            </g>
            <polyline class="chart-area-line" points="<?= e(implode(' ', $trendVolumePoints)) ?> 452,178 28,178"/>
            <polyline class="chart-line volume" points="<?= e(implode(' ', $trendVolumePoints)) ?>"/>
            <polyline class="chart-line orders" points="<?= e(implode(' ', $trendOrderPoints)) ?>"/>
            <?php foreach ($adminTrend as $index => $row): $x = 28 + ($index * (424 / max(1, count($adminTrend) - 1))); $barHeight = max(4, ((float) $row['volume'] / $maxTrendVolume) * 112); ?>
                <rect class="chart-bar" x="<?= round($x - 5, 1) ?>" y="<?= round(178 - $barHeight, 1) ?>" width="10" height="<?= round($barHeight, 1) ?>" rx="5">
                    <title><?= e($row['label']) ?> · <?= e(money($row['volume'])) ?> · <?= (int) $row['orders'] ?> <?= e(t('orders_label', $lang)) ?></title>
                </rect>
            <?php endforeach; ?>
        </svg>
        <div class="apple-chart-axis">
            <?php foreach (array_values(array_filter($adminTrend, fn($row, $index) => $index % 3 === 0 || $index === count($adminTrend) - 1, ARRAY_FILTER_USE_BOTH)) as $row): ?>
                <span><?= e($row['label']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<div class="metric-grid control-metrics">
    <article><span class="metric-icon blue"><?= icon_svg('users') ?></span><strong><?= $totalUsers ?></strong><small><?= e($lang === 'th' ? 'ผู้ใช้ทั้งหมด' : 'Total users') ?></small></article>
    <article><span class="metric-icon green"><?= icon_svg('moderation') ?></span><strong><?= $activeUsers ?></strong><small><?= e($lang === 'th' ? 'ผู้ใช้ที่เปิดใช้งาน' : 'Active users') ?></small></article>
    <article><span class="metric-icon violet"><?= icon_svg('categories') ?></span><strong><?= $activeServices ?></strong><small><?= e($lang === 'th' ? 'บริการที่เปิดอยู่' : 'Active services') ?></small></article>
    <article><span class="metric-icon amber"><?= icon_svg('orders') ?></span><strong><?= $pendingOrders ?></strong><small><?= e($lang === 'th' ? 'ออเดอร์ที่ต้องดูแล' : 'Orders needing attention') ?></small></article>
</div>

<section class="data-panel control-board">
    <div class="panel-title">
        <div>
            <h2><?= e($lang === 'th' ? 'สิ่งที่ต้องตัดสินใจตอนนี้' : 'What needs action now') ?></h2>
            <p><?= e($lang === 'th' ? 'คิวสำคัญที่ควรตรวจทุกครั้งก่อนปิดงาน' : 'The queues worth checking before you wrap up.') ?></p>
        </div>
    </div>
    <div class="control-queue">
        <a href="?page=admin-users"><strong><?= $pendingSellers ?></strong><span><?= e($lang === 'th' ? 'ผู้ขายรออนุมัติ' : 'Seller approvals pending') ?></span><small><?= e($lang === 'th' ? 'รีบดูให้ไม่ค้าง' : 'Keep the queue moving') ?></small></a>
        <a href="?page=admin-services"><strong><?= $pendingServices ?></strong><span><?= e($lang === 'th' ? 'บริการรอตรวจ' : 'Services waiting review') ?></span><small><?= e($lang === 'th' ? 'คุมคุณภาพหน้าร้าน' : 'Protect marketplace quality') ?></small></a>
        <a href="?page=admin-finance"><strong><?= $pendingTopups ?></strong><span><?= e($lang === 'th' ? 'เติมเงินรอตรวจ' : 'Top ups pending') ?></span><small><?= e($lang === 'th' ? 'รายการโอนที่ยังไม่เข้ายอด' : 'Manual wallet review') ?></small></a>
        <a href="?page=admin-orders"><strong><?= $pendingOrders ?></strong><span><?= e($lang === 'th' ? 'ออเดอร์ที่ต้องดูแล' : 'Orders needing care') ?></span><small><?= e($lang === 'th' ? 'ติดตามงานให้ครบ' : 'Follow up active work') ?></small></a>
        <a href="?page=admin-messages"><strong><?= $unreadMessages ?></strong><span><?= e($lang === 'th' ? 'ข้อความแอดมินยังไม่อ่าน' : 'Admin unread messages') ?></span><small><?= e($lang === 'th' ? 'ตอบเคสที่ค้าง' : 'Respond to pending threads') ?></small></a>
        <a href="?page=admin-reports"><strong><?= $messageCountAll ?></strong><span><?= e($lang === 'th' ? 'ข้อความในระบบทั้งหมด' : 'Total marketplace messages') ?></span><small><?= e($lang === 'th' ? 'ใช้ดูความเคลื่อนไหว' : 'Signal of marketplace activity') ?></small></a>
    </div>
</section>

<section class="data-panel command-status-panel">
    <div class="panel-title">
        <div>
            <h2><?= e($lang === 'th' ? 'สถานะคำสั่งซื้อ' : 'Order status breakdown') ?></h2>
            <p><?= e($lang === 'th' ? 'ดูสัดส่วนงานที่กำลังรอ ดำเนินการ รีวิว และเสร็จแล้วแบบเร็ว' : 'See pending, active, review, and completed work at a glance.') ?></p>
        </div>
        <strong><?= $completedOrders ?> <?= e($lang === 'th' ? 'งานเสร็จแล้ว' : 'completed') ?></strong>
    </div>
    <div class="command-status-bars">
        <?php foreach (['pending','in_progress','review','completed','cancelled'] as $status): $count = $statusCounts[$status] ?? 0; ?>
            <article>
                <div><strong><?= e(status_label($status, $lang)) ?></strong><small><?= $count ?> <?= e(t('orders_label', $lang)) ?></small></div>
                <span><i class="<?= e($status) ?>" style="width:<?= round($count / $statusTotal * 100) ?>%"></i></span>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<form class="settings-card wide" method="post">
    <?php post_fields('admin_ui_preferences', '?page=admin-control'); ?>
    <h2><?= e($lang === 'th' ? 'Appearance' : 'Appearance') ?></h2>
    <p><?= e($lang === 'th' ? 'เปลี่ยนภาษาและโหมดมืดของหน้าแอดมินเท่านั้น' : 'Change the admin page language and theme only.') ?></p>
    <div class="form-grid">
        <label><?= e(t('settings_theme', $lang)) ?><select name="theme" data-pref-input="theme"><option value="light" <?= $prefs['theme']==='light'?'selected':'' ?>><?= e(t('settings_theme_light', $lang)) ?></option><option value="dark" <?= $prefs['theme']==='dark'?'selected':'' ?>><?= e(t('settings_theme_dark', $lang)) ?></option><option value="auto" <?= $prefs['theme']==='auto'?'selected':'' ?>><?= e(t('settings_theme_auto', $lang)) ?></option></select></label>
        <label><?= e(t('settings_language', $lang)) ?><select name="language" data-pref-input="language"><option value="en" <?= $prefs['language']==='en'?'selected':'' ?>>English</option><option value="th" <?= $prefs['language']==='th'?'selected':'' ?>>ไทย</option></select></label>
        <label><?= e(t('settings_ui_size', $lang)) ?><select name="ui_scale" data-pref-input="ui_scale"><option value="compact" <?= $prefs['ui_scale']==='compact'?'selected':'' ?>><?= e(t('settings_ui_compact', $lang)) ?></option><option value="comfortable" <?= $prefs['ui_scale']==='comfortable'?'selected':'' ?>><?= e(t('settings_ui_comfortable', $lang)) ?></option><option value="roomy" <?= $prefs['ui_scale']==='roomy'?'selected':'' ?>><?= e(t('settings_ui_roomy', $lang)) ?></option></select></label>
    </div>
    <footer><button class="button button-dark"><?= e($lang === 'th' ? 'บันทึกการแสดงผลแอดมิน' : 'Save admin appearance') ?></button></footer>
</form>

<section class="data-panel control-switches">
    <div class="panel-title">
        <div>
            <h2><?= e($lang === 'th' ? 'สถานะระบบที่เปิดใช้อยู่' : 'Live system switches') ?></h2>
            <p><?= e($lang === 'th' ? 'สิ่งที่ส่งผลต่อการสมัคร การใช้งาน และการมองเห็นของผู้ใช้' : 'Settings that affect signups, access, and visibility.') ?></p>
        </div>
    </div>
    <div class="control-switch-grid">
        <article><span><?= e(t('admin_registration_open', $lang)) ?></span><strong><?= ($settings['registration_open']??'1')==='1' ? e($lang === 'th' ? 'เปิดอยู่' : 'On') : e($lang === 'th' ? 'ปิดอยู่' : 'Off') ?></strong><small><?= e(t('admin_registration_open_desc', $lang)) ?></small></article>
        <article><span><?= e(t('admin_seller_auto_approval', $lang)) ?></span><strong><?= ($settings['seller_auto_approval']??'0')==='1' ? e($lang === 'th' ? 'อัตโนมัติ' : 'Automatic') : e($lang === 'th' ? 'อนุมัติมือ' : 'Manual') ?></strong><small><?= e(t('admin_seller_auto_approval_desc', $lang)) ?></small></article>
        <article><span><?= e(t('admin_demo_mode', $lang)) ?></span><strong><?= ($settings['demo_mode']??'1')==='1' ? e($lang === 'th' ? 'เปิดเดโม' : 'Demo on') : e($lang === 'th' ? 'ปิดเดโม' : 'Demo off') ?></strong><small><?= e(t('admin_demo_mode_desc', $lang)) ?></small></article>
        <article><span><?= e(t('admin_maintenance', $lang)) ?></span><strong><?= ($settings['maintenance_mode']??'0')==='1' ? e($lang === 'th' ? 'กำลังซ่อมบำรุง' : 'Maintenance') : e($lang === 'th' ? 'ปกติ' : 'Normal') ?></strong><small><?= e(t('admin_maintenance_desc', $lang)) ?></small></article>
    </div>
</section>

<section class="data-panel control-links">
    <div class="panel-title">
        <div>
            <h2><?= e($lang === 'th' ? 'ทางลัดดูแลเว็บ' : 'Quick admin actions') ?></h2>
            <p><?= e($lang === 'th' ? 'เปิดหน้าที่ใช้บ่อยได้ทันที' : 'Jump to the pages you use most.') ?></p>
        </div>
    </div>
    <div class="control-link-grid">
        <a href="?page=admin-users"><strong><?= e(t('admin_users_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'อนุมัติ/ระงับผู้ใช้' : 'Approve or suspend users') ?></small></a>
        <a href="?page=admin-services"><strong><?= e(t('admin_services_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'ตรวจบริการและสถานะ' : 'Moderate services') ?></small></a>
        <a href="?page=admin-orders"><strong><?= e(t('admin_orders_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'ดูออเดอร์ทั้งหมด' : 'Review all orders') ?></small></a>
        <a href="?page=admin-messages"><strong><?= e(t('admin_messages_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'ดูข้อความสำคัญ' : 'Audit messages') ?></small></a>
        <a href="?page=admin-moderation"><strong><?= e(t('admin_moderation_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'คิวที่ต้องจัดการ' : 'Queues that need attention') ?></small></a>
        <a href="?page=admin-categories"><strong><?= e(t('admin_categories_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'จัดหมวดหมู่บริการ' : 'Organize service categories') ?></small></a>
        <a href="?page=admin-coupons"><strong><?= e(t('admin_coupons_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'จัดโปรโมชันและส่วนลด' : 'Create promotions and discounts') ?></small></a>
        <a href="?page=admin-logs"><strong><?= e(t('admin_logs_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'ดูบันทึกเหตุการณ์' : 'View security logs') ?></small></a>
        <a href="?page=admin-broadcast"><strong><?= e(t('admin_broadcast_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'ส่งประกาศถึงผู้ใช้' : 'Send an announcement') ?></small></a>
        <a href="?page=admin-finance"><strong><?= e(t('admin_finance_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'ดูรายรับและค่าธรรมเนียม' : 'Revenue and fee view') ?></small></a>
        <a href="?page=admin-export"><strong><?= e(t('admin_export_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'ดาวน์โหลดข้อมูลสำคัญ' : 'Download key data') ?></small></a>
        <a href="?page=admin-settings"><strong><?= e(t('admin_settings_title', $lang)) ?></strong><small><?= e($lang === 'th' ? 'ตั้งค่าระบบหลัก' : 'System settings') ?></small></a>
    </div>
</section>

<?= activity_panel_html('admin', (int) $user['id'], $lang) ?>

<?php elseif ($page === 'admin-reports'):
    $report=['users'=>(int)scalar('SELECT COUNT(*) FROM users'),'services'=>(int)scalar('SELECT COUNT(*) FROM services'),'orders'=>(int)scalar('SELECT COUNT(*) FROM orders'),'volume'=>(float)scalar('SELECT COALESCE(SUM(total),0) FROM orders'),'completion'=>(float)scalar('SELECT CASE WHEN COUNT(*)=0 THEN 0 ELSE SUM(status="completed")*100.0/COUNT(*) END FROM orders')];
    $categoryReport=fetch_all('SELECT categories.name,COUNT(services.id) AS services,COALESCE(SUM(orders.total),0) AS volume FROM categories LEFT JOIN services ON services.category_id=categories.id LEFT JOIN orders ON orders.service_id=services.id GROUP BY categories.id ORDER BY volume DESC');
    $categoryMaxVolume=max(1,...array_map(fn($r)=>(float)$r['volume'],$categoryReport));
    $categoryChartHeight=max(120, count($categoryReport) * 46 + 30);
    page_heading(t('group_system', $lang),t('admin_reports_title', $lang),t('admin_reports_desc', $lang));
?>
<section class="data-panel admin-category-chart">
    <div class="panel-title">
        <div>
            <h2><?= e($lang === 'th' ? 'กราฟรายได้ตามหมวดหมู่' : 'Revenue by category') ?></h2>
            <p><?= e($lang === 'th' ? 'กราฟนี้คำนวณจากยอดออเดอร์จริงในแต่ละหมวดบริการ' : 'This chart uses real order volume per service category.') ?></p>
        </div>
        <strong><?= e(t('admin_completion_rate', $lang, ['count' => number_format($report['completion'],0)])) ?></strong>
    </div>
    <div class="category-chart-wrap">
        <svg class="category-bar-chart" viewBox="0 0 760 <?= (int) $categoryChartHeight ?>" role="img" aria-label="<?= e($lang === 'th' ? 'กราฟรายได้ตามหมวดหมู่' : 'Revenue by category chart') ?>">
            <?php foreach ($categoryReport as $index => $row): $y = 22 + ($index * 46); $barWidth = max(6, ((float) $row['volume'] / $categoryMaxVolume) * 430); ?>
                <text class="category-chart-label" x="24" y="<?= $y + 14 ?>"><?= e(mb_strimwidth($row['name'], 0, 24, '...')) ?></text>
                <rect class="category-chart-track" x="220" y="<?= $y ?>" width="430" height="18" rx="9"></rect>
                <rect class="category-chart-bar" x="220" y="<?= $y ?>" width="<?= round($barWidth, 1) ?>" height="18" rx="9"></rect>
                <text class="category-chart-value" x="670" y="<?= $y + 14 ?>"><?= e(money((float) $row['volume'])) ?></text>
            <?php endforeach; ?>
        </svg>
    </div>
</section>
<div class="metric-grid"><article><span class="metric-icon blue"><?= icon_svg('users') ?></span><strong><?= $report['users'] ?></strong><small><?= e(t('admin_total_users', $lang)) ?></small></article><article><span class="metric-icon violet"><?= icon_svg('categories') ?></span><strong><?= $report['services'] ?></strong><small><?= e(t('admin_service_table_service', $lang)) ?></small></article><article><span class="metric-icon amber"><?= icon_svg('orders') ?></span><strong><?= $report['orders'] ?></strong><small><?= e(t('orders_order', $lang)) ?></small></article><article><span class="metric-icon green"><?= icon_svg('wallet') ?></span><strong><?= money($report['volume']) ?></strong><small><?= e(t('orders_total', $lang)) ?></small></article></div><?= onboarding_checklist_html('admin', $user, $lang) ?><?= activity_panel_html('admin', (int) $user['id'], $lang) ?><section class="data-panel analytics-panel"><div class="panel-title"><div><h2><?= e(t('admin_category_performance', $lang)) ?></h2><p><?= e(t('admin_services_inventory', $lang)) ?></p></div><strong><?= e(t('admin_completion_rate', $lang, ['count' => number_format($report['completion'],0)])) ?></strong></div><?php $maxVolume=max(1,...array_map(fn($r)=>(float)$r['volume'],$categoryReport)); foreach($categoryReport as $row): ?><div class="analytics-row"><div><strong><?= e($row['name']) ?></strong><small><?= (int)$row['services'] ?> <?= e(t('admin_service_table_service', $lang)) ?></small></div><span><i style="width:<?= round((float)$row['volume']/$maxVolume*100) ?>%"></i></span><b><?= money($row['volume']) ?></b></div><?php endforeach; ?></section>

<?php elseif ($page === 'admin-finance'):
    $feeRate = platform_fee_setting(10);
    $gross = (float) scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status="paid"');
    $paymentCount = (int) scalar('SELECT COUNT(*) FROM payments WHERE status="paid"');
    $platformRevenue = round($gross * $feeRate / 100, 2);
    $sellerPayouts = round($gross - $platformRevenue, 2);
    $avgOrder = $paymentCount > 0 ? $gross / $paymentCount : 0;
    $activeSellers = (int) scalar('SELECT COUNT(*) FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name="seller" AND users.status="active"');
    $pendingWalletRequests = fetch_all('SELECT wallet_transactions.*,users.name,users.email FROM wallet_transactions JOIN users ON users.id=wallet_transactions.user_id WHERE wallet_transactions.status="pending" AND wallet_transactions.slip_path<>"" ORDER BY wallet_transactions.created_at DESC LIMIT 12');
    $walletStatusSummary = fetch_one('SELECT COALESCE(SUM(CASE WHEN status="completed" THEN amount END),0) AS completed_sum, COALESCE(SUM(CASE WHEN status="pending" THEN amount END),0) AS pending_sum, COALESCE(SUM(CASE WHEN status="rejected" THEN amount END),0) AS rejected_sum, COUNT(*) AS total_count FROM wallet_transactions');
    $monthlyRows = fetch_all('SELECT strftime("%Y-%m", paid_at) AS month_key, SUM(amount) AS gross, COUNT(*) AS orders FROM payments WHERE status="paid" GROUP BY month_key ORDER BY month_key DESC LIMIT 12');
    $monthlyMap = [];
    foreach ($monthlyRows as $row) {
        $monthlyMap[$row['month_key']] = ['gross' => (float) $row['gross'], 'orders' => (int) $row['orders']];
    }
    $monthlySeries = [];
    for ($i = 11; $i >= 0; $i--) {
        $key = (new DateTimeImmutable('first day of this month'))->modify("-$i months")->format('Y-m');
        $grossValue = $monthlyMap[$key]['gross'] ?? 0.0;
        $monthlySeries[] = [
            'key' => $key,
            'label' => (new DateTimeImmutable($key . '-01'))->format('M'),
            'gross' => $grossValue,
            'fee' => round($grossValue * $feeRate / 100, 2),
            'net' => round($grossValue * (1 - $feeRate / 100), 2),
            'orders' => $monthlyMap[$key]['orders'] ?? 0,
        ];
    }
    $maxGross = max(1, ...array_map(fn($row) => (float) $row['gross'], $monthlySeries));
    page_heading(t('group_system', $lang), t('admin_finance_title', $lang), t('admin_finance_desc', $lang));
?>
<section class="finance-hero">
    <div class="finance-hero-copy">
        <span class="kicker"><?= e(t('admin_finance_title', $lang)) ?></span>
        <h2><?= e($lang === 'th' ? 'รายได้ของเว็ปทั้งหมดในมุมมองเดียว' : 'One view of platform revenue') ?></h2>
        <p><?= e($lang === 'th' ? 'ดูรายรับรวม ค่าธรรมเนียมแพลตฟอร์ม และยอดจ่ายออกให้ผู้ขายแบบชัดเจน' : 'Track gross income, platform fee, and seller payouts in one clean dashboard.') ?></p>
    </div>
    <div class="finance-hero-metrics">
        <article><span><?= e($lang === 'th' ? 'รายรับรวม' : 'Gross revenue') ?></span><strong><?= money($gross) ?></strong><small><?= $paymentCount ?> <?= e(t('orders_order', $lang)) ?></small></article>
        <article><span><?= e(t('seller_platform_fee_label', $lang)) ?></span><strong><?= number_format($platformRevenue, 0) ?></strong><small><?= number_format($feeRate, 1) ?>%</small></article>
        <article class="dark"><span><?= e($lang === 'th' ? 'จ่ายออกให้ผู้ขาย' : 'Seller payouts') ?></span><strong><?= money($sellerPayouts) ?></strong><small><?= e($lang === 'th' ? 'หลังหักค่าธรรมเนียม' : 'After platform fee') ?></small></article>
    </div>
</section>

<div class="metric-grid finance-metrics">
    <article><span class="metric-icon green"><?= icon_svg('wallet') ?></span><strong><?= money($gross) ?></strong><small><?= e($lang === 'th' ? 'เงินที่เว็ปทำได้ทั้งหมด' : 'Total money earned') ?></small></article>
    <article><span class="metric-icon blue"><?= icon_svg('analytics') ?></span><strong><?= money($platformRevenue) ?></strong><small><?= e($lang === 'th' ? 'รายได้จากค่าบริการเข้าเว็ป' : 'Platform fee revenue') ?></small></article>
    <article><span class="metric-icon violet"><?= icon_svg('orders') ?></span><strong><?= money($sellerPayouts) ?></strong><small><?= e($lang === 'th' ? 'ยอดจ่ายให้ผู้ขาย' : 'Payout to sellers') ?></small></article>
    <article><span class="metric-icon amber"><?= icon_svg('reports') ?></span><strong><?= money($avgOrder) ?></strong><small><?= e($lang === 'th' ? 'มูลค่าเฉลี่ยต่อออเดอร์' : 'Average order value') ?></small></article>
</div>

<div class="metric-grid finance-metrics">
    <article><span class="metric-icon green"><?= icon_svg('topup') ?></span><strong><?= money((float) ($walletStatusSummary['completed_sum'] ?? 0)) ?></strong><small><?= e($lang === 'th' ? 'ยอดเติมที่อนุมัติแล้ว' : 'Approved top ups') ?></small></article>
    <article><span class="metric-icon amber"><?= icon_svg('logs') ?></span><strong><?= money((float) ($walletStatusSummary['pending_sum'] ?? 0)) ?></strong><small><?= e($lang === 'th' ? 'ยอดที่รอตรวจสอบ' : 'Pending top ups') ?></small></article>
    <article><span class="metric-icon red"><?= icon_svg('reports') ?></span><strong><?= money((float) ($walletStatusSummary['rejected_sum'] ?? 0)) ?></strong><small><?= e($lang === 'th' ? 'ยอดที่ปฏิเสธ' : 'Rejected top ups') ?></small></article>
    <article><span class="metric-icon blue"><?= icon_svg('analytics') ?></span><strong><?= (int) ($walletStatusSummary['total_count'] ?? 0) ?></strong><small><?= e($lang === 'th' ? 'รายการเติมทั้งหมด' : 'All top up records') ?></small></article>
</div>

<?php if ($pendingWalletRequests): ?>
<section class="data-panel finance-pending">
    <div class="panel-title">
        <div>
            <h2><?= e($lang === 'th' ? 'คำขอเติมเงินแบบสลิปที่ยังค้างอยู่' : 'Pending legacy slip approvals') ?></h2>
            <p><?= e($lang === 'th' ? 'ส่วนนี้ใช้กับรายการโอนแบบเก่าที่แนบสลิปไว้เท่านั้น' : 'This section only covers older manual transfers that included a slip.') ?></p>
        </div>
        <strong><?= count($pendingWalletRequests) ?> <?= e($lang === 'th' ? 'รายการ' : 'items') ?></strong>
    </div>
    <div class="finance-pending-grid">
        <?php foreach ($pendingWalletRequests as $request): ?>
            <article>
                <div class="finance-pending-head">
                    <div>
                        <strong><?= e($request['name']) ?></strong>
                        <small><?= e($request['email']) ?></small>
                    </div>
                    <span class="status pending"><?= e(status_label('pending', $lang)) ?></span>
                </div>
                <div class="finance-pending-meta">
                    <span><?= money((float) $request['amount']) ?></span>
                    <small><?= e(ucfirst($request['method'])) ?> · <?= e($request['reference']) ?></small>
                    <?php if (!empty($request['slip_path'])): ?><small><a href="<?= e(upload_url($request['slip_path'])) ?>" target="_blank" rel="noopener"><?= e($lang === 'th' ? 'ดูสลิป' : 'View slip') ?></a></small><?php endif; ?>
                </div>
                <p><?= e($request['note'] ?: ($lang === 'th' ? 'ไม่มีหมายเหตุ' : 'No note provided.')) ?></p>
                <div class="row-actions">
                    <form method="post"><?php post_fields('admin_wallet_review', '?page=admin-finance'); ?><input type="hidden" name="transaction_id" value="<?= (int) $request['id'] ?>"><input type="hidden" name="decision" value="approve"><button class="table-action"><?= e($lang === 'th' ? 'อนุมัติ' : 'Approve') ?></button></form>
                    <form method="post"><?php post_fields('admin_wallet_review', '?page=admin-finance'); ?><input type="hidden" name="transaction_id" value="<?= (int) $request['id'] ?>"><input type="hidden" name="decision" value="reject"><button class="table-action"><?= e($lang === 'th' ? 'ปฏิเสธ' : 'Reject') ?></button></form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="data-panel finance-chart-panel">
    <div class="panel-title">
        <div>
            <h2><?= e($lang === 'th' ? 'แนวโน้มรายได้ 12 เดือน' : '12-month revenue trend') ?></h2>
            <p><?= e($lang === 'th' ? 'เส้นทางรายได้รวมและค่าบริการของแพลตฟอร์ม' : 'Gross revenue and fee trend over time') ?></p>
        </div>
        <strong><?= e(number_format($feeRate, 1)) ?>%</strong>
    </div>
    <div class="finance-chart">
        <?php foreach ($monthlySeries as $row): $grossPct = $row['gross'] > 0 ? max(6, round(($row['gross'] / $maxGross) * 100)) : 0; $feePct = $row['gross'] > 0 ? max(3, round(($row['fee'] / $maxGross) * 100)) : 0; ?>
            <div class="finance-chart-row">
                <div class="finance-chart-label"><strong><?= e($row['label']) ?></strong><small><?= e($row['key']) ?></small></div>
                <div class="finance-chart-bar">
                    <i class="gross" style="width:<?= $grossPct ?>%"></i>
                    <i class="fee" style="width:<?= $feePct ?>%"></i>
                </div>
                <div class="finance-chart-values">
                    <strong><?= money($row['gross']) ?></strong>
                    <small><?= e($row['orders']) ?> <?= e(t('orders_order', $lang)) ?></small>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="data-panel finance-breakdown">
    <div class="panel-title">
        <div>
            <h2><?= e($lang === 'th' ? 'สรุปตัวเลขสำคัญ' : 'Key financial details') ?></h2>
            <p><?= e($lang === 'th' ? 'ตัวเลขที่ควรดูทุกวันเพื่อคุมรายได้ของแพลตฟอร์ม' : 'Numbers to monitor daily for platform health') ?></p>
        </div>
    </div>
    <div class="analytics-row"><div><strong><?= e($lang === 'th' ? 'ผู้ขายที่ใช้งาน' : 'Active sellers') ?></strong><small><?= e($lang === 'th' ? 'บัญชีผู้ขายที่เปิดใช้งาน' : 'Seller accounts available') ?></small></div><span><i style="width:100%"></i></span><b><?= $activeSellers ?></b></div>
    <div class="analytics-row"><div><strong><?= e($lang === 'th' ? 'ธุรกรรมที่ชำระแล้ว' : 'Paid transactions') ?></strong><small><?= e($lang === 'th' ? 'ออเดอร์ที่ชำระเงินแล้ว' : 'Completed payment records') ?></small></div><span><i style="width:100%"></i></span><b><?= $paymentCount ?></b></div>
    <div class="analytics-row"><div><strong><?= e($lang === 'th' ? 'ค่าธรรมเนียมเฉลี่ย' : 'Average platform fee') ?></strong><small><?= e($lang === 'th' ? 'คำนวณจากรายรับรวม' : 'Based on gross revenue') ?></small></div><span><i style="width:<?= round($feeRate * 2) ?>%"></i></span><b><?= number_format($feeRate, 1) ?>%</b></div>
</section>

<?php elseif ($page === 'admin-settings'):
    $settings=[]; foreach(fetch_all('SELECT * FROM system_settings') as $setting)$settings[$setting['setting_key']]=$setting['setting_value']; page_heading(t('group_system', $lang),t('admin_settings_title', $lang),t('admin_settings_desc', $lang));
?>
<form method="post" class="settings-stack"><?php post_fields('admin_settings'); ?>
    <section class="settings-card wide" id="demo">
        <h2><?= e(t('admin_platform_config', $lang)) ?></h2>
        <p><?= e(t('admin_platform_config_desc', $lang)) ?></p>
        <div class="form-grid">
            <label><?= e(t('admin_site_name', $lang)) ?><input name="site_name" value="<?= e($settings['site_name']??'WorkConnect') ?>" required></label>
            <label><?= e(t('admin_site_tagline', $lang)) ?><input name="site_tagline" value="<?= e($settings['site_tagline']??'Connect. Collaborate. Succeed.') ?>" placeholder="Connect. Collaborate. Succeed."></label>
            <label><?= e(t('admin_support_email', $lang)) ?><input type="email" name="support_email" value="<?= e($settings['support_email']??'') ?>" required></label>
            <label><?= e(t('admin_support_phone', $lang)) ?><input name="support_phone" value="<?= e($settings['support_phone']??'') ?>" placeholder="+66..."></label>
            <label><?= e(t('admin_contact_ig', $lang)) ?><input name="contact_ig" value="<?= e($settings['contact_ig']??'https://www.instagram.com/waa_xzz/') ?>" placeholder="https://www.instagram.com/waa_xzz/"></label>
            <label><?= e(t('admin_currency', $lang)) ?><input name="currency_symbol" value="<?= e($settings['currency_symbol']??'฿') ?>" maxlength="4"></label>
        </div>
    </section>
    <section class="settings-card wide">
        <h2><?= e(t('group_marketplace', $lang) ?: 'Marketplace controls') ?></h2>
        <p><?= e('Control pricing, onboarding, and availability across the workspace.') ?></p>
        <div class="form-grid">
            <label><span><?= e($lang === 'th' ? 'โหมดชำระเงิน' : 'Payment mode') ?></span><input value="<?= e($settings['payment_mode']??'hosted_promptpay') ?>" readonly disabled><small><?= e($lang === 'th' ? 'โหมดนี้ถูกล็อกไว้เพื่อใช้ Stripe PromptPay แบบ hosted เท่านั้น' : 'This installation is locked to Stripe-hosted PromptPay.') ?></small></label>
            <label><?= e(t('admin_platform_fee', $lang)) ?><input type="number" name="platform_fee" min="0" max="50" step="0.1" value="<?= e($settings['platform_fee']??'10') ?>"></label>
            <label><?= e(t('admin_topup_minimum', $lang)) ?><input type="number" name="topup_minimum" min="50" step="1" value="<?= e($settings['topup_minimum']??'50') ?>"></label>
            <label class="toggle-row"><span><strong><?= e(t('admin_registration_open', $lang)) ?></strong><small><?= e(t('admin_registration_open_desc', $lang)) ?></small></span><input type="checkbox" name="registration_open" <?= ($settings['registration_open']??'1')==='1'?'checked':'' ?>><i></i></label>
            <label class="toggle-row"><span><strong><?= e(t('admin_seller_auto_approval', $lang)) ?></strong><small><?= e(t('admin_seller_auto_approval_desc', $lang)) ?></small></span><input type="checkbox" name="seller_auto_approval" <?= ($settings['seller_auto_approval']??'0')==='1'?'checked':'' ?>><i></i></label>
            <label class="toggle-row"><span><strong><?= e(t('admin_demo_mode', $lang)) ?></strong><small><?= e(t('admin_demo_mode_desc', $lang)) ?></small></span><input type="checkbox" name="demo_mode" <?= ($settings['demo_mode']??'1')==='1'?'checked':'' ?>><i></i></label>
            <label class="toggle-row"><span><strong><?= e(t('admin_maintenance', $lang)) ?></strong><small><?= e(t('admin_maintenance_desc', $lang)) ?></small></span><input type="checkbox" name="maintenance_mode" <?= ($settings['maintenance_mode']??'0')==='1'?'checked':'' ?>><i></i></label>
        </div>
    </section>
    <section class="settings-card wide">
        <h2><?= e($lang === 'th' ? 'ข้อความหน้า Stripe PromptPay' : 'Stripe PromptPay page text') ?></h2>
        <p><?= e($lang === 'th' ? 'ส่วนนี้ใช้บอกลูกค้าว่าหน้าชำระเงินจะทำงานอย่างไร ไม่ต้องกรอกเลขบัญชีเมื่อใช้ Stripe hosted payment' : 'Use this section to explain the Stripe-hosted payment flow. Bank fields are not required when Stripe hosts the payment page.') ?></p>
        <div class="form-grid">
            <label><?= e($lang === 'th' ? 'PromptPay ID' : 'PromptPay ID') ?><input name="promptpay_id" value="<?= e($settings['promptpay_id']??'') ?>" placeholder="0812345678"></label>
            <label><?= e($lang === 'th' ? 'ชื่อธนาคาร' : 'Bank name') ?><input name="bank_name" value="<?= e($settings['bank_name']??'') ?>" placeholder="Kasikornbank"></label>
            <label><?= e($lang === 'th' ? 'ชื่อบัญชี' : 'Account name') ?><input name="bank_account_name" value="<?= e($settings['bank_account_name']??'') ?>" placeholder="WorkConnect Co., Ltd."></label>
            <label><?= e($lang === 'th' ? 'เลขบัญชี' : 'Account number') ?><input name="bank_account_number" value="<?= e($settings['bank_account_number']??'') ?>" placeholder="123-4-56789-0"></label>
            <label class="full"><?= e($lang === 'th' ? 'คำแนะนำการชำระเงิน' : 'Payment instructions') ?><textarea name="payment_instructions" rows="3" placeholder="<?= e($lang === 'th' ? 'กรอกยอด แล้วไปจ่ายผ่านหน้า PromptPay ของ Stripe' : 'Enter the amount, then continue to the Stripe PromptPay page.') ?>"><?= e($settings['payment_instructions']??'') ?></textarea></label>
        </div>
    </section>
    <section class="settings-card wide">
        <h2><?= e(t('admin_announcement', $lang)) ?></h2>
        <p><?= e(t('admin_announcement_desc', $lang)) ?></p>
        <div class="form-grid">
            <label class="full"><?= e(t('admin_announcement', $lang)) ?><textarea name="announcement_banner" rows="3" placeholder="Optional short announcement for all users"><?= e($settings['announcement_banner']??'') ?></textarea></label>
        </div>
    </section>
    <section class="settings-card wide">
        <h2><?= e('Regular defaults') ?></h2>
        <p><?= e('These values become the starting point for guests and new accounts.') ?></p>
        <div class="form-grid">
            <label><?= e(t('settings_theme', $lang)) ?><select name="default_theme"><option value="light" <?= ($settings['default_theme']??'light')==='light'?'selected':'' ?>><?= e(t('settings_theme_light', $lang)) ?></option><option value="dark" <?= ($settings['default_theme']??'light')==='dark'?'selected':'' ?>><?= e(t('settings_theme_dark', $lang)) ?></option><option value="auto" <?= ($settings['default_theme']??'light')==='auto'?'selected':'' ?>><?= e(t('settings_theme_auto', $lang)) ?></option></select></label>
            <label><?= e(t('settings_language', $lang)) ?><select name="default_language"><option value="en" <?= ($settings['default_language']??'en')==='en'?'selected':'' ?>>English</option><option value="th" <?= ($settings['default_language']??'en')==='th'?'selected':'' ?>>ไทย</option></select></label>
            <label><?= e(t('settings_text_size', $lang)) ?><select name="default_text_scale"><option value="small" <?= ($settings['default_text_scale']??'medium')==='small'?'selected':'' ?>><?= e(t('settings_text_small', $lang)) ?></option><option value="medium" <?= ($settings['default_text_scale']??'medium')==='medium'?'selected':'' ?>><?= e(t('settings_text_medium', $lang)) ?></option><option value="large" <?= ($settings['default_text_scale']??'medium')==='large'?'selected':'' ?>><?= e(t('settings_text_large', $lang)) ?></option><option value="xl" <?= ($settings['default_text_scale']??'medium')==='xl'?'selected':'' ?>><?= e(t('settings_text_xl', $lang)) ?></option></select></label>
            <label><?= e(t('settings_ui_size', $lang)) ?><select name="default_ui_scale"><option value="compact" <?= ($settings['default_ui_scale']??'comfortable')==='compact'?'selected':'' ?>><?= e(t('settings_ui_compact', $lang)) ?></option><option value="comfortable" <?= ($settings['default_ui_scale']??'comfortable')==='comfortable'?'selected':'' ?>><?= e(t('settings_ui_comfortable', $lang)) ?></option><option value="roomy" <?= ($settings['default_ui_scale']??'comfortable')==='roomy'?'selected':'' ?>><?= e(t('settings_ui_roomy', $lang)) ?></option></select></label>
            <label class="toggle-row full"><span><strong><?= e(t('settings_email_notifications', $lang)) ?></strong><small><?= e('Default for new accounts') ?></small></span><input type="checkbox" name="default_email_notifications" <?= ($settings['default_email_notifications']??'1')==='1'?'checked':'' ?>><i></i></label>
        </div>
    </section>
    <section class="settings-card wide settings-save-bar">
        <div>
            <h2><?= e(t('admin_save_settings', $lang)) ?></h2>
            <p><?= e('Apply all changes across the admin panel, default user experience, and marketplace rules.') ?></p>
        </div>
        <footer><button class="button button-dark"><?= e(t('admin_save_settings', $lang)) ?></button></footer>
    </section>
</form>
<?php endif; ?>
