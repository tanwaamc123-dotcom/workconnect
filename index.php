<?php

declare(strict_types=1);

if (ob_get_level() === 0) {
    ob_start();
}

require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/stripe.php';
require __DIR__ . '/includes/actions.php';
require __DIR__ . '/includes/routes.php';

db();

if (app_is_production()) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    if (!request_is_https()) {
        $host = preg_match('/^[a-z0-9.\-:]+$/i', (string) ($_SERVER['HTTP_HOST'] ?? '')) ? $_SERVER['HTTP_HOST'] : '';
        if ($host !== '') redirect('https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'));
    }
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; connect-src 'self'; form-action 'self' https://checkout.stripe.com; frame-ancestors 'self'; base-uri 'self'; object-src 'none'");

if (($_GET['page'] ?? '') === 'stripe-webhook') {
    $payload = file_get_contents('php://input');
    $signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
    if (!is_string($payload) || $payload === '') {
        http_response_code(400);
        exit('Missing payload');
    }
    if (!stripe_webhook_is_configured()) {
        http_response_code(500);
        exit('Webhook secret is not configured');
    }
    if (!stripe_signature_is_valid($payload, $signature, stripe_webhook_secret())) {
        http_response_code(400);
        exit('Invalid signature');
    }
    $event = json_decode($payload, true);
    if (!is_array($event)) {
        http_response_code(400);
        exit('Invalid event');
    }
    try {
        $type = (string) ($event['type'] ?? '');
        $session = $event['data']['object'] ?? null;
        if (is_array($session)) {
            if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
                fulfill_payment_request_from_session($session);
            } elseif (in_array($type, ['checkout.session.async_payment_failed', 'checkout.session.expired'], true)) {
                $request = payment_request_by_session((string) ($session['id'] ?? ''));
                if ($request) {
                    mark_payment_request_failed($request, $type === 'checkout.session.expired' ? 'expired' : 'failed');
                }
            }
        }
        http_response_code(200);
        exit('ok');
    } catch (Throwable $error) {
        http_response_code(500);
        exit('Webhook handling failed');
    }
}

function stream_csv(string $filename, array $headers, iterable $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_map(static function ($value) {
            $text = (string) $value;
            return preg_match('/^[=+\-@]/', $text) ? "'" . $text : $text;
        }, $row));
    }
    fclose($out);
    exit;
}

if (($_GET['page'] ?? '') === 'sync') {
    $user = require_auth();
    $orderId = authorized_order_id_for_user($user, (int) ($_GET['order'] ?? 0));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(realtime_summary($user, $orderId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_GET['page'] ?? '') === 'stream') {
    $user = require_auth();
    $orderId = authorized_order_id_for_user($user, (int) ($_GET['order'] ?? 0));
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', 'off');
    @set_time_limit(0);
    ignore_user_abort(true);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    echo "retry: 10000\n\n";
    @flush();
    $lastHash = '';
    $startedAt = time();
    while (!connection_aborted() && (time() - $startedAt) < 90) {
        $payload = realtime_summary($user, $orderId);
        $hash = md5(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($hash !== $lastHash) {
            $lastHash = $hash;
            echo 'id: ' . $hash . "\n";
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            @flush();
        }
        echo ": ping\n\n";
        @flush();
        sleep(5);
    }
    exit;
}

if (($_GET['page'] ?? '') === 'file') {
    $reference = trim((string) ($_GET['ref'] ?? ''));
    $storedPath = upload_reference_decode($reference);
    $localPath = upload_local_path($storedPath);
    $user = current_user();
    if ($localPath === null || !is_file($localPath)) {
        http_response_code(404);
        exit('File not found');
    }
    if (!can_view_upload($user, $storedPath)) {
        http_response_code(403);
        exit('Access denied');
    }
    $mime = mime_content_type($localPath) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($localPath));
    header('Content-Disposition: inline; filename="' . basename($localPath) . '"');
    header('Cache-Control: private, max-age=300');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    readfile($localPath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $maintenanceUser = current_user();
    if (maintenance_mode_enabled() && ($maintenanceUser['role'] ?? '') !== 'admin' && (string) ($_POST['action'] ?? '') !== 'login') {
        http_response_code(503);
        exit('Maintenance mode is active.');
    }
    handle_post_action((string) ($_POST['action'] ?? ''));
}

$page = (string) ($_GET['page'] ?? 'home');
$routes = route_configuration();
$guestPages = $routes['guest'];
$workspacePages = $routes['workspace'];
$rolePages = $routes['roles'];
$publicLayoutPages = $routes['public_layout'];
$allPages = $routes['all'];

if (!in_array($page, $allPages, true)) {
    http_response_code(404);
    $page = 'home';
}

$user = current_user();
if (!in_array($page, $guestPages, true)) {
    $user = require_auth();
}
foreach ($rolePages as $role => $pages) {
    if (in_array($page, $pages, true)) {
        $user = require_role($role);
    }
}
if ($page === 'seller-pending' && (!$user || ($user['role'] ?? '') !== 'seller' || ($user['status'] ?? '') !== 'pending_approval')) {
    redirect($user ? role_home($user['role']) : '?page=login');
}
if ($user && in_array($page, ['login', 'register'], true)) {
    redirect(role_home($user['role']));
}
if ($user && $page === 'services') {
    $serviceQuery = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
    $serviceQuery = preg_replace('/(^|&)page=services(&|$)/', '$1', $serviceQuery) ?? $serviceQuery;
    $serviceQuery = trim($serviceQuery, '&');
    redirect('?page=marketplace' . ($serviceQuery !== '' ? '&' . $serviceQuery : ''));
}
if ($user && $page === 'about') {
    redirect('?page=about-workspace');
}
if ($user && $page === 'search') {
    $searchQuery = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
    $searchQuery = preg_replace('/(^|&)page=search(&|$)/', '$1', $searchQuery) ?? $searchQuery;
    $searchQuery = preg_replace('/(^|&)scope=[^&]*(&|$)/', '$1', $searchQuery) ?? $searchQuery;
    $searchQuery = trim($searchQuery, '&');
    redirect('?page=marketplace' . ($searchQuery !== '' ? '&' . $searchQuery : ''));
}
if ($user && $page === 'service-detail') {
    $serviceId = (int) ($_GET['id'] ?? 0);
    redirect('?page=marketplace-detail&id=' . $serviceId);
}
if (maintenance_mode_enabled() && ($user['role'] ?? '') !== 'admin' && !in_array($page, ['login', 'file', 'maintenance'], true)) {
    $page = 'maintenance';
}

$pageTitles = page_titles();
$title = ($pageTitles[$page] ?? 'WorkConnect') . ' | WorkConnect';

if ($page === 'admin-export') {
    $user = require_role('admin');
    $type = (string) ($_GET['type'] ?? 'overview');
    $prefix = 'workconnect-' . date('Ymd-His') . '-';
    if ($type === 'users') {
        $rows = fetch_all('SELECT users.name,users.email,roles.label AS role,users.status,users.created_at FROM users JOIN roles ON roles.id=users.role_id ORDER BY users.created_at DESC');
        stream_csv($prefix . 'users.csv', ['Name','Email','Role','Status','Created At'], array_map(fn($r) => [$r['name'],$r['email'],$r['role'],$r['status'],$r['created_at']], $rows));
    }
    if ($type === 'orders') {
        $rows = fetch_all('SELECT order_number,title,status,total,created_at FROM orders JOIN services ON services.id=orders.service_id ORDER BY orders.created_at DESC');
        stream_csv($prefix . 'orders.csv', ['Order Number','Service','Status','Total','Created At'], array_map(fn($r) => [$r['order_number'],$r['title'],$r['status'],$r['total'],$r['created_at']], $rows));
    }
    if ($type === 'finance') {
        $rows = fetch_all('SELECT payments.transaction_ref,orders.order_number,payments.amount,payments.method,payments.status,payments.paid_at FROM payments JOIN orders ON orders.id=payments.order_id ORDER BY payments.paid_at DESC');
        stream_csv($prefix . 'finance.csv', ['Transaction Ref','Order Number','Amount','Method','Status','Paid At'], array_map(fn($r) => [$r['transaction_ref'],$r['order_number'],$r['amount'],$r['method'],$r['status'],$r['paid_at']], $rows));
    }
    if ($type === 'logs') {
        $rows = fetch_all('SELECT security_logs.created_at,users.email AS user_email,security_logs.event,security_logs.ip_address FROM security_logs LEFT JOIN users ON users.id=security_logs.user_id ORDER BY security_logs.created_at DESC');
        stream_csv($prefix . 'logs.csv', ['Created At','User','Event','IP Address'], array_map(fn($r) => [$r['created_at'],$r['user_email'] ?? '',$r['event'],$r['ip_address']], $rows));
    }
    if ($type === 'categories') {
        $rows = fetch_all('SELECT name,code,color,id FROM categories ORDER BY id');
        stream_csv($prefix . 'categories.csv', ['Name','Code','Color','ID'], array_map(fn($r) => [$r['name'],$r['code'],$r['color'],$r['id']], $rows));
    }
}

if (in_array($page, ['login', 'register', 'forgot-password', 'reset-password'], true)) {
    require __DIR__ . '/pages/auth.php';
    exit;
}

if (in_array($page, $publicLayoutPages, true)) {
    require __DIR__ . '/includes/header.php';
    if ($page === 'home') {
        require __DIR__ . '/includes/data.php';
        require __DIR__ . '/pages/home.php';
    } elseif ($page === 'maintenance') {
        require __DIR__ . '/pages/maintenance.php';
    } else {
        require __DIR__ . '/pages/app.php';
    }
    require __DIR__ . '/includes/footer.php';
    exit;
}

require __DIR__ . '/includes/app-header.php';
require __DIR__ . '/pages/app.php';
require __DIR__ . '/includes/app-footer.php';
