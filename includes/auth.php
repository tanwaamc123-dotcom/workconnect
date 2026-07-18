<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => request_is_https(),
    ]);
    session_start();
}

function current_user(): ?array
{
    static $cachedUser = false;
    if ($cachedUser !== false) {
        return $cachedUser ?: null;
    }
    if (empty($_SESSION['user_id'])) {
        $cachedUser = null;
        return null;
    }
    $tokenHash = hash('sha256', session_id());
    $session = fetch_one('SELECT user_id FROM sessions WHERE user_id=? AND token_hash=? AND last_activity>=?', [(int) $_SESSION['user_id'], $tokenHash, date('Y-m-d H:i:s', time() - 43200)]);
    if (!$session) {
        $_SESSION = [];
        $cachedUser = null;
        return null;
    }
    db()->prepare('UPDATE sessions SET last_activity=CURRENT_TIMESTAMP WHERE user_id=? AND token_hash=?')->execute([(int) $_SESSION['user_id'], $tokenHash]);
    $stmt = db()->prepare('SELECT users.*, roles.name AS role, roles.label AS role_label FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $cachedUser = $stmt->fetch() ?: null;
    return $cachedUser;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    $token = hash('sha256', session_id());
    $stmt = db()->prepare('INSERT INTO sessions (user_id,token_hash,ip_address,user_agent) VALUES (?,?,?,?)');
    $stmt->execute([(int) $user['id'], $token, client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
    log_security((int) $user['id'], 'login_success');
}

function logout_user(): void
{
    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare('DELETE FROM sessions WHERE user_id=? AND token_hash=?');
        $stmt->execute([(int) $_SESSION['user_id'], hash('sha256', session_id())]);
        log_security((int) $_SESSION['user_id'], 'logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        $_SESSION['intended_url'] = safe_return_to((string) ($_SERVER['REQUEST_URI'] ?? ''), '?page=home');
        flash('info', 'Please sign in to continue.');
        redirect('?page=login');
    }
    if (($user['status'] ?? '') === 'suspended') {
        logout_user();
        session_start();
        flash('error', 'This account has been suspended.');
        redirect('?page=login');
    }
    return $user;
}

function require_role(array|string $roles): array
{
    $user = require_auth();
    if (!in_array($user['role'], (array) $roles, true)) {
        flash('error', 'You do not have access to that workspace.');
        redirect(role_home($user['role']));
    }
    return $user;
}

function seller_requires_approval(array $user): bool
{
    return ($user['role'] ?? '') === 'seller' && ($user['status'] ?? '') === 'pending_approval';
}

function ensure_seller_approved(array $user): void
{
    if (!seller_requires_approval($user)) {
        return;
    }
    throw new RuntimeException('Your seller account is waiting for admin approval before you can use seller tools.');
}

function role_home(string $role): string
{
    return match ($role) {
        'seller' => '?page=seller-dashboard',
        'admin' => '?page=admin-users',
        default => '?page=dashboard',
    };
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        exit('Your session has expired. Please go back and try again.');
    }
}

function log_security(?int $userId, string $event): void
{
    $stmt = db()->prepare('INSERT INTO security_logs (user_id,event,ip_address) VALUES (?,?,?)');
    $stmt->execute([$userId, $event, client_ip()]);
}

function client_ip(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'local'));
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'local';
}

function enforce_rate_limit(string $scope, string $identity, int $limit = 8, int $windowSeconds = 900): void
{
    $key = hash('sha256', $scope . '|' . strtolower(trim($identity)) . '|' . client_ip());
    $row = fetch_one('SELECT * FROM rate_limits WHERE rate_key=?', [$key]);
    if ($row && !empty($row['blocked_until']) && strtotime((string) $row['blocked_until']) > time()) {
        throw new RuntimeException('Too many attempts. Please wait a few minutes and try again.');
    }
    if (!$row || strtotime((string) $row['window_started_at']) < time() - $windowSeconds) {
        db()->prepare('INSERT INTO rate_limits (rate_key,attempts,window_started_at,blocked_until) VALUES (?,1,CURRENT_TIMESTAMP,NULL) ON CONFLICT(rate_key) DO UPDATE SET attempts=1,window_started_at=CURRENT_TIMESTAMP,blocked_until=NULL')->execute([$key]);
        return;
    }
    $attempts = (int) $row['attempts'] + 1;
    $blockedUntil = $attempts > $limit ? date('Y-m-d H:i:s', time() + $windowSeconds) : null;
    db()->prepare('UPDATE rate_limits SET attempts=?,blocked_until=? WHERE rate_key=?')->execute([$attempts, $blockedUntil, $key]);
    if ($blockedUntil !== null) {
        throw new RuntimeException('Too many attempts. Please wait a few minutes and try again.');
    }
}

function clear_rate_limit(string $scope, string $identity): void
{
    $key = hash('sha256', $scope . '|' . strtolower(trim($identity)) . '|' . client_ip());
    db()->prepare('DELETE FROM rate_limits WHERE rate_key=?')->execute([$key]);
}
