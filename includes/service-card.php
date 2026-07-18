<?php
$thumb = service_cover_path($service);
$rating = (float) ($service['rating'] ?? 0);
$reviews = (int) ($service['review_count'] ?? $service['reviews'] ?? 0);
$completedOrders = (int) ($service['completed_orders'] ?? 0);
$level = (string) ($service['level'] ?? ($service['category'] ?? ''));
$isFavorite = !empty($user) && ($user['role'] ?? '') === 'customer' ? is_favorite_service((int) $user['id'], (int) $service['id']) : false;
$returnTo = e(safe_return_to((string) ($_SERVER['REQUEST_URI'] ?? ''), '?page=services'));
$detailPage = (!empty($user) && in_array(($page ?? ''), ['marketplace', 'marketplace-detail'], true)) ? 'marketplace-detail' : 'service-detail';
?>
<article class="service-card" data-category="<?= htmlspecialchars($service['category']) ?>" data-title="<?= htmlspecialchars(strtolower($service['title'])) ?>">
    <div class="service-thumb custom-cover">
        <img src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($service['title']) ?>">
        <a class="service-cover" href="?page=<?= e($detailPage) ?>&id=<?= (int) $service['id'] ?>" aria-label="View <?= htmlspecialchars($service['title']) ?>"></a>
        <span><?= htmlspecialchars($service['category']) ?></span>
        <?php if (!empty($user) && ($user['role'] ?? '') === 'customer'): ?>
        <form class="favorite-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle_favorite">
            <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
            <input type="hidden" name="return_to" value="<?= $returnTo ?>">
            <button class="button button-light button-small <?= $isFavorite ? 'active' : '' ?>" type="submit"><?= $isFavorite ? e(t('favorite_saved', $lang ?? 'en')) : e(t('favorite_save', $lang ?? 'en')) ?></button>
        </form>
        <?php endif; ?>
    </div>
    <div class="service-body">
        <div class="seller-line"><span class="seller-avatar"><?= strtoupper(substr($service['seller'], 0, 1)) ?></span><span><strong><?= htmlspecialchars($service['seller']) ?></strong><small><?= htmlspecialchars($level) ?></small></span></div>
        <h3><a href="?page=<?= e($detailPage) ?>&id=<?= (int) $service['id'] ?>"><?= htmlspecialchars($service['title']) ?></a></h3>
        <div class="service-badges">
            <span class="<?= (($service['seller_status'] ?? 'active') === 'active') ? 'good' : '' ?>"><?= e(t('trust_verified', $lang ?? 'en')) ?></span>
            <span><?= number_format($completedOrders) ?> <?= e(t('trust_orders_completed', $lang ?? 'en')) ?></span>
            <span><?= number_format($reviews) ?> <?= e(t('trust_reviews', $lang ?? 'en')) ?></span>
        </div>
        <div class="service-meta"><span class="rating"><?= $rating > 0 ? '★ ' . number_format($rating, 1) . ' (' . $reviews . ')' : 'New service' ?></span><strong>฿<?= number_format($service['price']) ?></strong></div>
    </div>
</article>
