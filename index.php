<?php

declare(strict_types=1);

if (ob_get_level() === 0) {
    ob_start();
}

require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/platform.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/stripe.php';
require __DIR__ . '/includes/actions.php';
require __DIR__ . '/includes/routes.php';

header('X-Request-ID: ' . request_id());

if (($_GET['page'] ?? '') === 'health-live') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'timestamp' => gmdate(DATE_ATOM), 'request_id' => request_id()], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    db();
    initialize_ledger_opening_balances();
} catch (Throwable $error) {
    app_log('error', 'Application initialization failed.', ['type' => $error::class]);
    if (($_GET['page'] ?? '') === 'health-ready') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
        echo json_encode(['ok' => false, 'checks' => ['database' => ['ok' => false, 'detail' => 'unavailable']], 'request_id' => request_id()], JSON_UNESCAPED_SLASHES);
        exit;
    }
    throw $error;
}

if (($_GET['page'] ?? '') === 'health-ready') {
    $readiness = readiness_report();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($readiness['ok'] ? 200 : 503);
    echo json_encode($readiness, JSON_UNESCAPED_SLASHES);
    exit;
}

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
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; connect-src 'self'; form-action 'self' https://checkout.stripe.com; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");

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
    $eventId = trim((string) ($event['id'] ?? ''));
    $type = trim((string) ($event['type'] ?? ''));
    if ($eventId === '' || $type === '') {
        http_response_code(400);
        exit('Missing event identity');
    }
    try {
        if (!claim_provider_event($eventId, $type, $payload)) {
            http_response_code(200);
            exit('duplicate');
        }
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
        finish_provider_event($eventId, true);
        http_response_code(200);
        exit('ok');
    } catch (Throwable $error) {
        finish_provider_event($eventId, false, $error->getMessage());
        app_log('error', 'Stripe webhook handling failed.', ['event_id' => $eventId, 'type' => $type]);
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
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-store');
    echo "retry: 60000\n";
    echo 'data: ' . json_encode(realtime_summary($user, $orderId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    exit;
}

if (($_GET['page'] ?? '') === 'file') {
    $reference = trim((string) ($_GET['ref'] ?? ''));
    $storedPath = upload_reference_decode($reference);
    $localPath = upload_local_path($storedPath);
    $user = current_user();
    $isObject = str_starts_with($storedPath, 'object:uploads/');
    if ((!$isObject && ($localPath === null || !is_file($localPath))) || !is_upload_reference($storedPath)) {
        http_response_code(404);
        exit('File not found');
    }
    $sensitiveUpload = is_sensitive_upload($storedPath);
    if (!can_view_upload($user, $storedPath)) {
        if ($sensitiveUpload) {
            audit_event($user ? (int) $user['id'] : null, 'file_access_denied', 'upload', 0, ['reference' => hash('sha256', $storedPath)]);
        }
        http_response_code(403);
        exit('Access denied');
    }
    if ($sensitiveUpload) {
        audit_event($user ? (int) $user['id'] : null, 'file_accessed', 'upload', 0, ['reference' => hash('sha256', $storedPath)]);
    }
    if ($isObject) {
        try {
            $object = object_storage_fetch($storedPath);
        } catch (Throwable $error) {
            app_log('error', 'Unable to read object storage upload.', ['reference' => hash('sha256', $storedPath)]);
            http_response_code(404);
            exit('File not found');
        }
        $contents = (string) $object['body'];
        $mime = strtolower(trim(explode(';', (string) $object['content_type'])[0])) ?: 'application/octet-stream';
        $size = strlen($contents);
    } else {
        $contents = null;
        $mime = strtolower((string) (mime_content_type((string) $localPath) ?: 'application/octet-stream'));
        $size = filesize((string) $localPath);
    }
    $allowedResponseTypes = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf', 'application/zip', 'text/plain',
    ];
    if (!in_array($mime, $allowedResponseTypes, true)) {
        $mime = 'application/octet-stream';
    }
    $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '-', basename(str_replace('object:', '', $storedPath))) ?: 'download';
    $disposition = str_starts_with($mime, 'image/') || in_array($mime, ['application/pdf', 'text/plain'], true)
        ? 'inline'
        : 'attachment';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) $size);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
    header('Cache-Control: private, max-age=300');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    if ($contents !== null) {
        echo $contents;
    } else {
        readfile((string) $localPath);
    }
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

if (($_GET['page'] ?? '') === 'account-export') {
    $exportUser = require_auth();
    $requestId = (int) ($_SESSION['account_export_request_id'] ?? 0);
    $expires = (int) ($_SESSION['account_export_expires'] ?? 0);
    $request = $requestId > 0 ? fetch_one(
        "SELECT id FROM account_requests WHERE id=? AND user_id=? AND request_type='export' AND status='ready'",
        [$requestId, (int) $exportUser['id']]
    ) : null;
    if (!$request || $expires < time()) {
        unset($_SESSION['account_export_request_id'], $_SESSION['account_export_expires']);
        http_response_code(403);
        exit('Export authorization expired. Return to Settings and confirm your password again.');
    }
    $payload = account_export_payload($exportUser);
    db()->prepare(
        "UPDATE account_requests SET status='completed',completed_at=CURRENT_TIMESTAMP WHERE id=? AND status='ready'"
    )->execute([$requestId]);
    audit_event((int) $exportUser['id'], 'account_export_downloaded', 'account_request', $requestId);
    unset($_SESSION['account_export_request_id'], $_SESSION['account_export_expires']);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="workconnect-account-' . (int) $exportUser['id'] . '.json"');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
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
if ($page === 'mfa' && ((int) ($_SESSION['pending_mfa_user_id'] ?? 0) < 1 || (int) ($_SESSION['pending_mfa_expires'] ?? 0) < time())) {
    redirect('?page=login');
}
if ($user && ($user['role'] ?? '') === 'admin') {
    $adminPageCapabilities = [
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
    if (isset($adminPageCapabilities[$page]) && !admin_can($user, $adminPageCapabilities[$page])) {
        flash('error', 'Your admin role does not have access to that page.');
        redirect(admin_start_page($user));
    }
    if (app_is_production() && (int) ($user['admin_mfa_enabled'] ?? 0) !== 1 && $page !== 'admin-security') {
        flash('error', 'Enable multi-factor authentication before using production admin tools.');
        redirect('?page=admin-security#mfa');
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
if ($page === 'checkout') {
    $checkoutServiceId = (int) ($_GET['id'] ?? 0);
    $checkoutServiceExists = $checkoutServiceId > 0 && (int) scalar(
        "SELECT COUNT(*) FROM services JOIN users ON users.id=services.seller_id
         WHERE services.id=? AND services.status='active' AND users.status='active'",
        [$checkoutServiceId]
    ) === 1;
    if (!$checkoutServiceExists) {
        flash('error', 'Choose an available service before checking out.');
        redirect('?page=marketplace');
    }
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
    $exportAllowed = match ($type) {
        'users' => admin_can($user, 'export.read') && admin_can($user, 'users.view'),
        'orders' => admin_can($user, 'export.read') && admin_can($user, 'orders.view'),
        'finance' => admin_can($user, 'finance.view')
            && (admin_can($user, 'export.read') || admin_can($user, 'export.finance')),
        'logs', 'categories' => admin_can($user, 'export.read') && admin_can($user, 'reports.view'),
        'overview' => admin_can($user, 'export.read'),
        default => false,
    };
    if (!$exportAllowed) {
        audit_event((int) $user['id'], 'admin_export_denied', 'export', 0, ['type' => $type]);
        flash('error', 'Your admin role cannot export that data set.');
        redirect('?page=admin-export');
    }
    if ($type === 'users') {
        $rows = fetch_all('SELECT users.name,users.email,roles.label AS role,users.status,users.created_at FROM users JOIN roles ON roles.id=users.role_id ORDER BY users.created_at DESC');
        stream_csv($prefix . 'users.csv', ['Name','Email','Role','Status','Created At'], array_map(fn($r) => [$r['name'],$r['email'],$r['role'],$r['status'],$r['created_at']], $rows));
    }
    if ($type === 'orders') {
        $rows = fetch_all('SELECT order_number,title,status,total_satang,created_at FROM orders JOIN services ON services.id=orders.service_id ORDER BY orders.created_at DESC');
        stream_csv($prefix . 'orders.csv', ['Order Number','Service','Status','Total THB','Created At'], array_map(fn($r) => [$r['order_number'],$r['title'],$r['status'],satang_to_decimal((int)$r['total_satang']),$r['created_at']], $rows));
    }
    if ($type === 'finance') {
        $rows = fetch_all('SELECT payments.transaction_ref,orders.order_number,payments.amount_satang,payments.refunded_satang,payments.method,payments.status,payments.paid_at FROM payments JOIN orders ON orders.id=payments.order_id ORDER BY payments.paid_at DESC');
        stream_csv($prefix . 'finance.csv', ['Transaction Ref','Order Number','Amount THB','Refunded THB','Method','Status','Paid At'], array_map(fn($r) => [$r['transaction_ref'],$r['order_number'],satang_to_decimal((int)$r['amount_satang']),satang_to_decimal((int)$r['refunded_satang']),$r['method'],$r['status'],$r['paid_at']], $rows));
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

if (in_array($page, ['login', 'mfa', 'register', 'forgot-password', 'reset-password'], true)) {
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
