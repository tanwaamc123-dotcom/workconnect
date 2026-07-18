<?php

declare(strict_types=1);

$categories = fetch_all('SELECT categories.*,COUNT(services.id) AS count FROM categories LEFT JOIN services ON services.category_id=categories.id AND services.status="active" GROUP BY categories.id ORDER BY categories.id');
$services = fetch_all('SELECT services.id,services.title,categories.name AS category,users.name AS seller,
    COALESCE(AVG(reviews.rating),0) AS rating,COUNT(DISTINCT reviews.id) AS reviews,services.price,services.thumbnail AS image,
    CASE WHEN users.is_demo=1 THEN "Demo seller" ELSE "Verified seller" END AS level
    FROM services JOIN categories ON categories.id=services.category_id JOIN users ON users.id=services.seller_id
    LEFT JOIN orders ON orders.service_id=services.id LEFT JOIN reviews ON reviews.order_id=orders.id
    WHERE services.status="active" GROUP BY services.id ORDER BY services.views DESC,services.created_at DESC LIMIT 4');
$publicUserCount = (int) scalar('SELECT COUNT(*) FROM users WHERE is_demo=0');
$marketplaceHasData = (bool) scalar('SELECT COUNT(*) FROM services');
$demoInstalled = demo_is_installed();
$demoCounts = [
    'users' => (int) scalar('SELECT COUNT(*) FROM users WHERE is_demo=1'),
    'services' => (int) scalar('SELECT COUNT(*) FROM services WHERE is_demo=1'),
    'orders' => (int) scalar('SELECT COUNT(*) FROM orders WHERE is_demo=1'),
    'messages' => (int) scalar('SELECT COUNT(*) FROM messages WHERE is_demo=1'),
    'payments' => (int) scalar('SELECT COUNT(*) FROM payments WHERE is_demo=1'),
];
