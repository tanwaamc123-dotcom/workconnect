<?php

declare(strict_types=1);

function handle_post_action(string $action): never
{
    verify_csrf();

    try {
        match ($action) {
            'install_demo' => action_install_demo(),
            'clear_demo' => action_clear_demo(),
            'login' => action_login(),
            'verify_mfa' => action_verify_mfa(),
            'admin_mfa_begin' => action_admin_mfa_begin(),
            'admin_mfa_confirm' => action_admin_mfa_confirm(),
            'admin_mfa_disable' => action_admin_mfa_disable(),
            'register' => action_register(),
            'request_password_reset' => action_request_password_reset(),
            'reset_password' => action_reset_password(),
            'logout' => action_logout(),
            'subscribe' => action_subscribe(),
            'place_order' => action_place_order(),
            'send_message' => action_send_message(),
            'mark_notifications' => action_mark_notifications(),
            'toggle_notification' => action_toggle_notification(),
            'test_notification' => action_test_notification(),
            'update_profile' => action_update_profile(),
            'update_preferences' => action_update_preferences(),
            'change_password' => action_change_password(),
            'revoke_other_sessions' => action_revoke_other_sessions(),
            'request_account_export' => action_request_account_export(),
            'request_account_deletion' => action_request_account_deletion(),
            'cancel_account_deletion' => action_cancel_account_deletion(),
            'topup_wallet' => action_topup_wallet(),
            'admin_wallet_review' => action_admin_wallet_review(),
            'save_service' => action_save_service(),
            'delete_service' => action_delete_service(),
            'update_order' => action_update_order(),
            'submit_delivery' => action_submit_delivery(),
            'request_revision' => action_request_revision(),
            'open_dispute' => action_open_dispute(),
            'add_dispute_evidence' => action_add_dispute_evidence(),
            'admin_resolve_dispute' => action_admin_resolve_dispute(),
            'request_payout' => action_request_payout(),
            'admin_review_payout' => action_admin_review_payout(),
            'submit_review' => action_submit_review(),
            'toggle_favorite' => action_toggle_favorite(),
            'admin_user_status' => action_admin_user_status(),
            'admin_assign_role' => action_admin_assign_role(),
            'admin_account_request' => action_admin_account_request(),
            'admin_service_status' => action_admin_service_status(),
            'admin_category_save' => action_admin_category_save(),
            'admin_category_delete' => action_admin_category_delete(),
            'admin_coupon_save' => action_admin_coupon_save(),
            'admin_coupon_delete' => action_admin_coupon_delete(),
            'admin_broadcast' => action_admin_broadcast(),
            'admin_settings' => action_admin_settings(),
            'admin_ui_preferences' => action_admin_ui_preferences(),
            default => throw new RuntimeException('Unknown action.'),
        };
    } catch (Throwable $error) {
        flash('error', public_error_message($error));
        // Registration errors must keep the user on the form even when a browser omits Referer.
        $fallback = $action === 'register' ? '?page=register' : '?page=home';
        redirect_back($fallback);
    }
}

function action_install_demo(): never
{
    $admin = require_admin_capability('system.manage');
    if (!demo_management_allowed($admin)) {
        throw new RuntimeException('Demo management is disabled.');
    }
    install_demo_data(db());
    flash('success', 'Demo workspace is ready. Choose a role to explore the complete workflow.');
    redirect('?page=admin-settings#demo');
}

function action_clear_demo(): never
{
    $admin = require_admin_capability('system.manage');
    if (!demo_management_allowed($admin)) {
        throw new RuntimeException('Demo management is disabled.');
    }
    if (!demo_is_installed()) {
        flash('info', 'There is no demo data to clear.');
        redirect('?page=admin-settings#demo');
    }
    $user = current_user();
    $demoSession = $user && (int) $user['is_demo'] === 1;
    if ($demoSession) logout_user();
    $deleted = clear_demo_data(db());
    if ($demoSession) session_start();
    flash('success', sprintf('Demo cleared: %d users, %d services, and %d orders removed. Real data was kept.', $deleted['users'], $deleted['services'], $deleted['orders']));
    redirect('?page=admin-settings#demo');
}

function action_subscribe(): never
{
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please enter a valid email address.');
    }
    db()->prepare('INSERT INTO newsletter_subscribers (email) VALUES (?) ON CONFLICT(email) DO NOTHING')->execute([$email]);
    flash('success', 'You are subscribed to WorkConnect updates.');
    redirect('?page=home#contact');
}

function action_login(): never
{
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    enforce_rate_limit('login', $email, 8, 900);
    $user = fetch_one('SELECT users.*,roles.name AS role FROM users JOIN roles ON roles.id=users.role_id WHERE users.email=?', [$email]);
    if (!$user || !password_verify($password, $user['password_hash']) || in_array($user['status'], ['suspended'], true)) {
        log_security($user ? (int) $user['id'] : null, 'login_failed');
        throw new RuntimeException('Email or password is incorrect.');
    }
    clear_rate_limit('login', $email);
    $remember = isset($_POST['remember']);
    if (
        (string) $user['role'] === 'admin'
        && (int) ($user['admin_mfa_enabled'] ?? 0) === 1
        && trim((string) ($user['admin_mfa_secret'] ?? '')) !== ''
    ) {
        session_regenerate_id(true);
        $_SESSION['pending_mfa_user_id'] = (int) $user['id'];
        $_SESSION['pending_mfa_expires'] = time() + 300;
        $_SESSION['pending_mfa_remember'] = $remember;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
        audit_event((int) $user['id'], 'mfa_challenge_started');
        redirect('?page=mfa');
    }
    login_user($user, $remember);
    flash('success', 'Welcome back, ' . explode(' ', $user['name'])[0] . '.');
    $destination = safe_return_to((string) ($_SESSION['intended_url'] ?? ''), role_home($user['role']));
    unset($_SESSION['intended_url']);
    redirect($destination);
}

function action_verify_mfa(): never
{
    $userId = (int) ($_SESSION['pending_mfa_user_id'] ?? 0);
    $expires = (int) ($_SESSION['pending_mfa_expires'] ?? 0);
    $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? '')) ?? '';
    enforce_rate_limit('mfa', (string) $userId, 6, 600);
    if ($userId < 1 || $expires < time()) {
        unset($_SESSION['pending_mfa_user_id'], $_SESSION['pending_mfa_expires'], $_SESSION['pending_mfa_remember']);
        throw new PublicRuntimeException('The verification session expired. Please sign in again.');
    }
    $user = fetch_one('SELECT users.*,roles.name AS role FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?', [$userId]);
    $secret = $user ? decrypt_sensitive((string) ($user['admin_mfa_secret'] ?? '')) : '';
    $counter = $user ? verify_totp($secret, $code, (int) ($user['mfa_last_counter'] ?? -1)) : null;
    if (!$user || $counter === null || (string) $user['role'] !== 'admin') {
        audit_event($userId ?: null, 'mfa_failed');
        throw new PublicRuntimeException('The verification code is incorrect.');
    }
    db()->prepare('UPDATE users SET mfa_last_counter=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$counter, $userId]);
    clear_rate_limit('mfa', (string) $userId);
    $remember = (bool) ($_SESSION['pending_mfa_remember'] ?? false);
    login_user($user, $remember);
    audit_event($userId, 'mfa_success');
    $destination = safe_return_to((string) ($_SESSION['intended_url'] ?? ''), role_home('admin'));
    unset($_SESSION['intended_url']);
    redirect($destination);
}

function action_admin_mfa_begin(): never
{
    $admin = require_role('admin');
    $secret = base32_encode_secret(random_bytes(20));
    $_SESSION['mfa_setup_secret'] = $secret;
    $_SESSION['mfa_setup_expires'] = time() + 600;
    audit_event((int) $admin['id'], 'mfa_setup_started');
    flash('info', 'Authenticator setup started. Enter the six-digit code to finish enabling MFA.');
    redirect('?page=admin-security#mfa');
}

function action_admin_mfa_confirm(): never
{
    $admin = require_role('admin');
    $secret = (string) ($_SESSION['mfa_setup_secret'] ?? '');
    $expires = (int) ($_SESSION['mfa_setup_expires'] ?? 0);
    $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? '')) ?? '';
    $counter = $expires >= time() ? verify_totp($secret, $code) : null;
    if ($secret === '' || $counter === null) {
        throw new PublicRuntimeException('The authenticator code is invalid or setup expired.');
    }
    db()->prepare(
        'UPDATE users SET admin_mfa_secret=?,admin_mfa_enabled=1,mfa_last_counter=?,updated_at=CURRENT_TIMESTAMP WHERE id=?'
    )->execute([encrypt_sensitive($secret), $counter, (int) $admin['id']]);
    unset($_SESSION['mfa_setup_secret'], $_SESSION['mfa_setup_expires']);
    audit_event((int) $admin['id'], 'mfa_enabled', 'user', (int) $admin['id']);
    flash('success', 'Multi-factor authentication is now enabled.');
    redirect('?page=admin-security#mfa');
}

function action_admin_mfa_disable(): never
{
    $admin = require_role('admin');
    $password = (string) ($_POST['current_password'] ?? '');
    enforce_rate_limit('mfa_disable', (string) $admin['id'], 5, 900);
    if (!password_verify($password, (string) $admin['password_hash'])) {
        audit_event((int) $admin['id'], 'mfa_disable_password_failed', 'user', (int) $admin['id']);
        throw new PublicRuntimeException('Your current password is incorrect.');
    }
    $statement = db()->prepare(
        "UPDATE users SET admin_mfa_secret='',admin_mfa_enabled=0,mfa_last_counter=-1,updated_at=CURRENT_TIMESTAMP
         WHERE id=? AND status='active' AND admin_mfa_enabled=1"
    );
    $statement->execute([(int) $admin['id']]);
    if ($statement->rowCount() !== 1) {
        throw new PublicRuntimeException('MFA is already disabled or this account is no longer active.');
    }
    clear_rate_limit('mfa_disable', (string) $admin['id']);
    audit_event((int) $admin['id'], 'mfa_disabled', 'user', (int) $admin['id']);
    flash('success', 'Multi-factor authentication was disabled.');
    redirect('?page=admin-security#mfa');
}

function action_request_account_export(): never
{
    $user = require_auth();
    $password = (string) ($_POST['current_password'] ?? '');
    enforce_rate_limit('account_export', (string) $user['id'], 5, 900);
    if (!password_verify($password, (string) $user['password_hash'])) {
        audit_event((int) $user['id'], 'account_export_password_failed', 'user', (int) $user['id']);
        throw new PublicRuntimeException('Your current password is incorrect.');
    }
    clear_rate_limit('account_export', (string) $user['id']);
    db()->prepare(
        "INSERT INTO account_requests (user_id,request_type,status,notes) VALUES (?,'export','ready','One-time JSON export')"
    )->execute([(int) $user['id']]);
    $requestId = database_last_insert_id();
    $_SESSION['account_export_request_id'] = $requestId;
    $_SESSION['account_export_expires'] = time() + 120;
    audit_event((int) $user['id'], 'account_export_requested', 'account_request', $requestId);
    redirect('?page=account-export');
}

function action_request_account_deletion(): never
{
    $user = require_auth();
    enforce_rate_limit('account_deletion', (string) $user['id'], 5, 3600);
    if (($user['role'] ?? '') === 'admin') {
        throw new PublicRuntimeException('Administrator accounts must be transferred or disabled by another owner.');
    }
    if ((int) ($user['is_demo'] ?? 0) === 1) {
        throw new PublicRuntimeException('Demo accounts cannot be deleted individually.');
    }
    $password = (string) ($_POST['current_password'] ?? '');
    if (!password_verify($password, (string) $user['password_hash'])) {
        audit_event((int) $user['id'], 'account_deletion_password_failed', 'user', (int) $user['id']);
        throw new PublicRuntimeException('Your current password is incorrect.');
    }
    if ((int) scalar(
        "SELECT COUNT(*) FROM account_requests WHERE user_id=? AND request_type='deletion' AND status='pending'",
        [(int) $user['id']]
    ) > 0) {
        throw new PublicRuntimeException('An account deletion request is already pending.');
    }
    $notes = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 1000);
    db()->prepare(
        "INSERT INTO account_requests (user_id,request_type,status,notes) VALUES (?,'deletion','pending',?)"
    )->execute([(int) $user['id'], $notes]);
    $requestId = database_last_insert_id();
    audit_event((int) $user['id'], 'account_deletion_requested', 'account_request', $requestId);
    foreach (fetch_all(
        "SELECT users.id FROM users JOIN roles ON roles.id=users.role_id
         WHERE roles.name='admin' AND users.admin_role='owner' AND users.status='active'"
    ) as $owner) {
        notify(
            (int) $owner['id'],
            'account',
            'Account deletion review required',
            'A user submitted an account deletion request.',
            '?page=admin-users'
        );
    }
    flash('success', 'Your deletion request was submitted. You can cancel it until an owner completes the review.');
    redirect_back('?page=settings');
}

function action_cancel_account_deletion(): never
{
    $user = require_auth();
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $statement = db()->prepare(
        "UPDATE account_requests SET status='cancelled',completed_at=CURRENT_TIMESTAMP
         WHERE id=? AND user_id=? AND request_type='deletion' AND status='pending'"
    );
    $statement->execute([$requestId, (int) $user['id']]);
    if ($statement->rowCount() !== 1) {
        throw new PublicRuntimeException('Pending deletion request not found.');
    }
    audit_event((int) $user['id'], 'account_deletion_cancelled', 'account_request', $requestId);
    flash('success', 'Your account deletion request was cancelled.');
    redirect_back('?page=settings');
}

function action_register(): never
{
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['customer', 'seller'], true) ? (string) $_POST['role'] : 'customer';
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
    $idCardNumber = trim((string) ($_POST['id_card_number'] ?? ''));
    if (system_setting('registration_open', '1') !== '1') {
        throw new RuntimeException('Registration is currently closed.');
    }
    if (mb_strlen($name) < 2 || !preg_match('/\p{L}/u', $name)) {
        throw new PublicRuntimeException('Please enter your full name using letters, not only numbers.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new PublicRuntimeException('Please enter a valid email address, for example name@example.com.');
    }
    if (strlen($password) < 10) {
        throw new PublicRuntimeException('Your password must be at least 10 characters long.');
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        throw new PublicRuntimeException('Your password must include at least one English letter and one number.');
    }
    if (!hash_equals($password, $passwordConfirmation)) {
        throw new RuntimeException('Password confirmation does not match.');
    }
    enforce_rate_limit('register', $email, 5, 3600);
    if (scalar('SELECT COUNT(*) FROM users WHERE email=?', [$email])) {
        throw new RuntimeException('An account with this email already exists.');
    }
    if ($role === 'seller') {
        $age = age_from_birth_date($birthDate);
        $idCardDigits = preg_replace('/\D+/', '', $idCardNumber);
        if ($phone === '' || !preg_match('/^[0-9+\-\s]{8,20}$/', $phone)) {
            throw new RuntimeException('Seller registration requires a valid phone number.');
        }
        if ($age === null || $age < 18) {
            throw new RuntimeException('Seller accounts require an age of at least 18 years.');
        }
        if (!is_string($idCardDigits) || !thai_id_is_valid($idCardDigits)) {
            throw new RuntimeException('Seller registration requires a valid 13-digit Thai ID card number.');
        }
        $idFingerprint = sensitive_fingerprint($idCardDigits);
        if ((int) scalar("SELECT COUNT(*) FROM users WHERE id_card_fingerprint<>'' AND id_card_fingerprint=?", [$idFingerprint]) > 0) {
            throw new PublicRuntimeException('This Thai ID card is already linked to another seller account.');
        }
        $idCardNumber = encrypt_sensitive($idCardDigits);
    } else {
        $idFingerprint = '';
    }
    $idCardFront = $role === 'seller' ? store_upload('id_card_front', image_upload_types(), 5242880) : '';
    if ($role === 'seller' && $idCardFront === '') {
        throw new RuntimeException('Seller registration requires a Thai ID card image upload.');
    }
    $sellerAutoApproval = system_setting('seller_auto_approval', '0') === '1';
    $status = $role === 'seller' && !$sellerAutoApproval ? 'pending_approval' : 'active';
    try {
        $stmt = db()->prepare('INSERT INTO users (role_id,name,email,password_hash,phone,status,theme,language,text_scale,ui_scale,email_notifications,birth_date,id_card_number,id_card_fingerprint,id_card_front,id_card_back) VALUES ((SELECT id FROM roles WHERE name=?),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
        $role,
        $name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $phone,
        $status,
        default_theme_setting('light'),
        default_language_setting('en'),
        default_text_scale_setting('medium'),
        default_ui_scale_setting('comfortable'),
        default_email_notifications_setting(1),
        $birthDate,
        $idCardNumber,
        $idFingerprint,
        $idCardFront,
        '',
        ]);
    } catch (Throwable $error) {
        delete_stored_upload($idCardFront);
        throw $error;
    }
    clear_rate_limit('register', $email);
    $user = fetch_one('SELECT users.*,roles.name AS role FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?', [database_last_insert_id()]);
    login_user($user);
    notify((int) $user['id'], 'account', 'Welcome to WorkConnect', 'Your workspace is ready. Complete your profile when you have a moment.', '?page=profile');
    if ($role === 'seller' && $status === 'pending_approval') {
        $adminIds = fetch_all("SELECT users.id FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name='admin' AND users.status='active'");
        foreach ($adminIds as $admin) {
            notify((int) $admin['id'], 'account', 'Seller approval required', $name . ' has requested seller access.', '?page=admin-users');
        }
        flash('info', 'Your seller account is waiting for admin approval.');
        redirect(role_home($role));
    }
    redirect(role_home($role));
}

function action_logout(): never
{
    logout_user();
    session_start();
    flash('success', 'You have been signed out.');
    redirect('?page=home');
}

function action_request_password_reset(): never
{
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    enforce_rate_limit('password_reset', $email, 3, 3600);
    $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? fetch_one('SELECT id,email FROM users WHERE email=? AND status<>"suspended"', [$email]) : null;
    if ($user) {
        $token = bin2hex(random_bytes(32));
        db()->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=? AND used_at IS NULL')->execute([(int) $user['id']]);
        db()->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at) VALUES (?,?,?)')
            ->execute([(int) $user['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 1800)]);
        deliver_password_reset_link((string) $user['email'], app_base_url() . '/?page=reset-password&token=' . rawurlencode($token));
        log_security((int) $user['id'], 'password_reset_requested');
    }
    flash('success', 'If that email belongs to an account, a password reset link has been sent.');
    redirect('?page=forgot-password');
}

function action_reset_password(): never
{
    $token = trim((string) ($_POST['token'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if (
        strlen($token) !== 64
        || strlen($password) < 10
        || !preg_match('/[A-Za-z]/', $password)
        || !preg_match('/\d/', $password)
        || !hash_equals($password, $confirmation)
    ) {
        throw new PublicRuntimeException('The reset link or password is invalid.');
    }
    $reset = fetch_one("SELECT * FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP", [hash('sha256', $token)]);
    if (!$reset) throw new PublicRuntimeException('This password reset link is invalid or has expired.');
    db()->beginTransaction();
    try {
        db()->prepare('UPDATE users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), (int) $reset['user_id']]);
        $consume = db()->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE id=? AND used_at IS NULL');
        $consume->execute([(int) $reset['id']]);
        if ($consume->rowCount() !== 1) throw new PublicRuntimeException('This reset link was already used.');
        db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([(int) $reset['user_id']]);
        log_security((int) $reset['user_id'], 'password_reset_completed');
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
    flash('success', 'Your password has been reset. You can sign in now.');
    redirect('?page=login');
}

function action_place_order(): never
{
    $user = require_role('customer');
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $service = fetch_one(
        "SELECT services.* FROM services JOIN users seller ON seller.id=services.seller_id
         WHERE services.id=? AND services.status='active' AND seller.status='active'",
        [$serviceId]
    );
    if (!$service) {
        throw new RuntimeException('This service is not available.');
    }
    $requirements = trim((string) ($_POST['requirements'] ?? ''));
    if (mb_strlen($requirements) < 20) {
        throw new RuntimeException('Please describe your requirements in at least 20 characters.');
    }
    $subtotalSatang = value_satang($service, 'price_satang', 'price');
    $discountSatang = 0;
    $coupon = null;
    $couponCode = strtoupper(trim((string) ($_POST['coupon'] ?? '')));
    $paymentMethod = pick_value((string) ($_POST['payment_method'] ?? 'wallet'), ['wallet'], 'wallet');
    if ($paymentMethod !== 'wallet') {
        throw new RuntimeException('This checkout currently accepts wallet payments only.');
    }
    $orderNumber = 'WC-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $dueAt = gmdate('Y-m-d H:i:s', time() + ((int) $service['delivery_days'] * 86400));
    db()->beginTransaction();
    try {
        lock_financial_accounts([(int) $user['id'], (int) $service['seller_id']]);
        $freshService = fetch_one(
            "SELECT services.* FROM services JOIN users seller ON seller.id=services.seller_id
             WHERE services.id=? AND services.status='active' AND seller.status='active'",
            [$serviceId]
        );
        if (!$freshService || value_satang($freshService, 'price_satang', 'price') !== $subtotalSatang) {
            throw new PublicRuntimeException('The service availability or price changed. Reload checkout and try again.');
        }
        $service = $freshService;
        $dueAt = gmdate('Y-m-d H:i:s', time() + ((int) $service['delivery_days'] * 86400));
        if ($couponCode !== '') {
            $coupon = fetch_one(
                'SELECT * FROM coupons WHERE code=? AND active=1 AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)',
                [$couponCode]
            );
            if (!$coupon) {
                throw new PublicRuntimeException('Coupon code is invalid or expired.');
            }
            $couponLock = db()->prepare('UPDATE coupons SET active=active WHERE id=?');
            $couponLock->execute([(int) $coupon['id']]);
            $coupon = fetch_one(
                'SELECT * FROM coupons WHERE id=? AND code=? AND active=1 AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)',
                [(int) $coupon['id'], $couponCode]
            );
            if ($couponLock->rowCount() !== 1 || !$coupon) {
                throw new PublicRuntimeException('Coupon code is no longer available.');
            }
            if ($subtotalSatang < (int) ($coupon['minimum_satang'] ?? 0)) {
                throw new PublicRuntimeException('This order does not reach the coupon minimum.');
            }
            $totalUses = (int) scalar('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=?', [(int) $coupon['id']]);
            $userUses = (int) scalar('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=? AND user_id=?', [(int) $coupon['id'], (int) $user['id']]);
            if ($coupon['max_uses'] !== null && $totalUses >= (int) $coupon['max_uses']) {
                throw new PublicRuntimeException('This coupon has reached its usage limit.');
            }
            if ($userUses >= (int) ($coupon['per_user_limit'] ?? 1)) {
                throw new PublicRuntimeException('You have already used this coupon the maximum number of times.');
            }
            $discountSatang = intdiv(($subtotalSatang * (int) $coupon['discount_percent']) + 50, 100);
        }
        $totalSatang = max(0, $subtotalSatang - $discountSatang);
        $feeBps = platform_fee_bps();
        $platformFeeSatang = calculate_platform_fee_satang($totalSatang, $feeBps);
        $sellerNetSatang = $totalSatang - $platformFeeSatang;
        $debit = db()->prepare(
            'UPDATE users SET wallet_balance_satang=wallet_balance_satang-?,updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status=? AND wallet_balance_satang>=?'
        );
        $debit->execute([$totalSatang, (int) $user['id'], 'active', $totalSatang]);
        if ($debit->rowCount() !== 1) {
            throw new PublicRuntimeException('Your wallet balance changed or is not enough. Please top up and try again.');
        }
        db()->prepare('UPDATE users SET wallet_balance=wallet_balance_satang/100.0 WHERE id=?')->execute([(int) $user['id']]);
        $isDemo = (int) $service['is_demo'];
        $stmt = db()->prepare(
            "INSERT INTO orders
             (order_number,customer_id,seller_id,service_id,status,requirements,subtotal,discount,total,
              subtotal_satang,discount_satang,total_satang,fee_rate_bps,platform_fee_satang,seller_net_satang,
              due_at,is_demo,coupon_code)
             VALUES (?,?,?,?,'pending',?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $orderNumber,
            (int) $user['id'],
            (int) $service['seller_id'],
            $serviceId,
            $requirements,
            satang_to_float($subtotalSatang),
            satang_to_float($discountSatang),
            satang_to_float($totalSatang),
            $subtotalSatang,
            $discountSatang,
            $totalSatang,
            $feeBps,
            $platformFeeSatang,
            $sellerNetSatang,
            $dueAt,
            $isDemo,
            $couponCode,
        ]);
        $orderId = database_last_insert_id();
        if ($coupon) {
            db()->prepare(
                'INSERT INTO coupon_redemptions (coupon_id,order_id,user_id,discount_satang) VALUES (?,?,?,?)'
            )->execute([(int) $coupon['id'], $orderId, (int) $user['id'], $discountSatang]);
        }
        $payment = db()->prepare(
            "INSERT INTO payments (order_id,amount,amount_satang,method,status,transaction_ref,is_demo) VALUES (?,?,?,?,'paid',?,?)"
        );
        $payment->execute([$orderId,satang_to_float($totalSatang),$totalSatang,'wallet','PAY-' . strtoupper(bin2hex(random_bytes(8))),$isDemo]);
        ledger_post('ORDER-' . $orderNumber, 'order_charge', [
            ['account_code' => 'customer_wallet', 'owner_type' => 'user', 'owner_id' => (int) $user['id'], 'amount_satang' => -$totalSatang],
            ['account_code' => 'platform_escrow', 'owner_type' => 'order', 'owner_id' => $orderId, 'amount_satang' => $totalSatang],
        ], ['order_id' => $orderId, 'user_id' => (int) $user['id']]);
        record_order_event($orderId, (int) $user['id'], 'order_placed', null, 'pending', '', [
            'payment_method' => 'wallet',
            'total_satang' => $totalSatang,
        ]);
        notify((int) $service['seller_id'], 'order', 'New order received', $orderNumber . ' is ready for your review.', '?page=seller-orders', (bool) $isDemo);
        notify((int) $user['id'], 'payment', 'Payment confirmed', 'Wallet payment completed for order ' . $orderNumber . '.', '?page=orders', (bool) $isDemo);
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
    flash('success', 'Order ' . $orderNumber . ' was placed successfully.');
    redirect('?page=orders');
}

function action_send_message(): never
{
    $user = require_role(['customer', 'seller']);
    enforce_rate_limit('message', (string) $user['id'], 60, 60);
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $order = fetch_order_for_user($user, $orderId);
    if (!$order) {
        throw new RuntimeException('Conversation not found.');
    }
    if ($user['role'] === 'seller') {
        ensure_seller_approved($user);
    }
    $body = trim((string) ($_POST['body'] ?? ''));
    if (mb_strlen($body) > 5000) {
        throw new PublicRuntimeException('Messages cannot exceed 5,000 characters.');
    }
    $attachment = store_upload('attachment', image_upload_types() + ['application/pdf'=>'pdf','text/plain'=>'txt']);
    if ($body === '' && $attachment === '') throw new RuntimeException('Add a message or attachment before sending.');
    $receiverId = (int) $user['id'] === (int) $order['customer_id'] ? (int) $order['seller_id'] : (int) $order['customer_id'];
    db()->beginTransaction();
    try {
        lock_financial_accounts([(int) $user['id'], $receiverId]);
        $availableParties = (int) scalar(
            "SELECT COUNT(*) FROM users WHERE id IN (?,?) AND status<>'suspended'",
            [(int) $user['id'], $receiverId]
        );
        if ($availableParties !== 2) {
            throw new PublicRuntimeException('This conversation is unavailable because one account is no longer active.');
        }
        $stmt = db()->prepare('INSERT INTO messages (order_id,sender_id,receiver_id,body,attachment,is_demo) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$orderId,(int) $user['id'],$receiverId,$body,$attachment,(int)$order['is_demo']]);
        notify($receiverId, 'message', 'New message from ' . $user['name'], mb_substr($body ?: 'Sent an attachment', 0, 90), '?page=' . ($user['role'] === 'customer' ? 'seller-messages' : 'messages') . '&order=' . $orderId, (bool)$order['is_demo']);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        delete_stored_upload($attachment);
        throw $error;
    }
    redirect_back('?page=messages&order=' . $orderId);
}

function action_mark_notifications(): never
{
    $user = require_auth();
    db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([(int) $user['id']]);
    redirect('?page=notifications');
}

function action_toggle_notification(): never
{
    $user = require_auth();
    $notificationId = (int) ($_POST['notification_id'] ?? 0);
    $notification = fetch_one('SELECT * FROM notifications WHERE id=? AND user_id=?', [$notificationId, (int) $user['id']]);
    if (!$notification) {
        throw new RuntimeException('Notification not found.');
    }
    $nextReadState = isset($_POST['is_read']) ? 1 : 0;
    db()->prepare('UPDATE notifications SET is_read=? WHERE id=? AND user_id=?')->execute([$nextReadState, $notificationId, (int) $user['id']]);
    redirect_back('?page=notifications');
}

function action_test_notification(): never
{
    $user = require_auth();
    enforce_rate_limit('test_notification', (string) $user['id'], 5, 300);
    notify((int) $user['id'], 'system', 'Test notification', 'This is what an in-app notification looks like.', '?page=notifications');
    flash('success', 'Test notification sent.');
    redirect_back('?page=notifications');
}

function action_update_profile(): never
{
    $user = require_auth();
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $bio = trim((string) ($_POST['bio'] ?? ''));
    if (mb_strlen($name) < 2) {
        throw new RuntimeException('Name is required.');
    }
    if (mb_strlen($name) > 120 || mb_strlen($phone) > 30 || mb_strlen($bio) > 2000) {
        throw new RuntimeException('Profile information is too long.');
    }
    $avatar = store_upload('avatar', image_upload_types(), 3145728);
    if ($avatar !== '') {
        try {
            $statement = db()->prepare(
                "UPDATE users SET name=?,phone=?,bio=?,avatar=?,updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND status='active'"
            );
            $statement->execute([$name,$phone,$bio,$avatar,(int) $user['id']]);
            if ($statement->rowCount() !== 1) {
                throw new PublicRuntimeException('This account can no longer update its profile.');
            }
        } catch (Throwable $error) {
            delete_stored_upload($avatar);
            throw $error;
        }
        delete_stored_upload((string) ($user['avatar'] ?? ''));
    } else {
        $statement = db()->prepare(
            "UPDATE users SET name=?,phone=?,bio=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active'"
        );
        $statement->execute([$name,$phone,$bio,(int) $user['id']]);
        if ($statement->rowCount() !== 1) {
            throw new PublicRuntimeException('This account can no longer update its profile.');
        }
    }
    flash('success', 'Profile updated.');
    redirect_back('?page=profile');
}

function action_update_preferences(): never
{
    $user = require_auth();
    $enabled = isset($_POST['email_notifications']) ? 1 : 0;
    $theme = pick_value((string) ($_POST['theme'] ?? 'light'), ['light', 'dark', 'auto'], 'light');
    $language = pick_value((string) ($_POST['language'] ?? 'en'), ['en', 'th'], 'en');
    $textScale = pick_value((string) ($_POST['text_scale'] ?? 'medium'), ['small', 'medium', 'large', 'xl'], 'medium');
    $uiScale = pick_value((string) ($_POST['ui_scale'] ?? 'comfortable'), ['compact', 'comfortable', 'roomy'], 'comfortable');
    $statement = db()->prepare(
        "UPDATE users SET email_notifications=?,theme=?,language=?,text_scale=?,ui_scale=?,updated_at=CURRENT_TIMESTAMP
         WHERE id=? AND status='active'"
    );
    $statement->execute([$enabled,$theme,$language,$textScale,$uiScale,(int) $user['id']]);
    if ($statement->rowCount() !== 1) {
        throw new PublicRuntimeException('This account can no longer update its preferences.');
    }
    flash('success', 'Notification preferences saved.');
    redirect_back('?page=settings');
}

function action_admin_ui_preferences(): never
{
    $user = require_role('admin');
    $theme = pick_value((string) ($_POST['theme'] ?? 'light'), ['light', 'dark', 'auto'], 'light');
    $language = pick_value((string) ($_POST['language'] ?? 'en'), ['en', 'th'], 'en');
    $uiScale = pick_value((string) ($_POST['ui_scale'] ?? 'comfortable'), ['compact', 'comfortable', 'roomy'], 'comfortable');
    $_SESSION['admin_ui_theme'] = $theme;
    $_SESSION['admin_ui_language'] = $language;
    $_SESSION['admin_ui_ui_scale'] = $uiScale;
    $statement = db()->prepare(
        "UPDATE users SET theme=?,language=?,ui_scale=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active'"
    );
    $statement->execute([$theme, $language, $uiScale, (int) $user['id']]);
    if ($statement->rowCount() !== 1) {
        throw new PublicRuntimeException('This administrator account is no longer active.');
    }
    flash('success', 'Admin page appearance saved.');
    redirect_back('?page=admin-control');
}

function action_topup_wallet(): never
{
    $user = require_role('customer');
    enforce_rate_limit('topup_request', (string) $user['id'], 10, 3600);
    $amountSatang = amount_to_satang($_POST['amount'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    if (!stripe_checkout_is_configured()) {
        throw new PublicRuntimeException('PromptPay payments are not available yet. No payment was created.');
    }
    $minimumSatang = max(5000, amount_to_satang((string) topup_minimum_setting(50)));
    if ($amountSatang < $minimumSatang || $amountSatang > 100000000) {
        throw new RuntimeException('Please enter a top up amount of at least ' . money_satang($minimumSatang) . '.');
    }
    db()->beginTransaction();
    try {
        lock_financial_accounts([(int) $user['id']]);
        if ((string) scalar('SELECT status FROM users WHERE id=?', [(int) $user['id']]) !== 'active') {
            throw new PublicRuntimeException('This account cannot create a new payment session.');
        }
        $requestId = create_payment_request('topup', (int) $user['id'], $amountSatang, 'Wallet top up', ['note' => mb_substr($note, 0, 500)]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    $request = fetch_one('SELECT * FROM payment_requests WHERE id=?', [$requestId]);
    if (!$request) {
        throw new RuntimeException('Unable to create the payment request.');
    }
    try {
        $session = create_stripe_checkout_session_for_payment_request($request);
    } catch (Throwable $error) {
        mark_payment_request_failed($request);
        throw $error;
    }
    update_payment_request_session($requestId, $session);
    redirect((string) ($session['url'] ?? '?page=topup'));
}

function action_admin_wallet_review(): never
{
    $admin = require_admin_capability('finance.manage');
    $transactionId = (int) ($_POST['transaction_id'] ?? 0);
    $decision = pick_value((string) ($_POST['decision'] ?? ''), ['approve', 'reject'], '');
    if ($decision === '') {
        throw new PublicRuntimeException('Choose whether to approve or reject this top up.');
    }
    $transaction = fetch_one('SELECT * FROM wallet_transactions WHERE id=?', [$transactionId]);
    if (!$transaction || (string) $transaction['status'] !== 'pending') {
        throw new RuntimeException('Pending top up request not found.');
    }
    if (trim((string) ($transaction['slip_path'] ?? '')) === '') {
        throw new RuntimeException('Manual review is only available for legacy slip-based top ups.');
    }

    db()->beginTransaction();
    try {
        lock_financial_accounts([(int) $transaction['user_id']]);
        $transaction = fetch_one('SELECT * FROM wallet_transactions WHERE id=?', [$transactionId]);
        if (!$transaction || (string) $transaction['status'] !== 'pending') {
            throw new PublicRuntimeException('This top up was already reviewed.');
        }
        if ((string) scalar('SELECT status FROM users WHERE id=?', [(int) $transaction['user_id']]) !== 'active') {
            throw new PublicRuntimeException('The destination account is no longer active.');
        }
        if ($decision === 'approve') {
            $review = db()->prepare("UPDATE wallet_transactions SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
            $review->execute(['completed', $transactionId]);
            if ($review->rowCount() !== 1) {
                throw new RuntimeException('This top up was already reviewed.');
            }
            $amountSatang = value_satang($transaction, 'amount_satang', 'amount');
            db()->prepare('UPDATE users SET wallet_balance_satang=wallet_balance_satang+?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$amountSatang, (int) $transaction['user_id']]);
            db()->prepare('UPDATE users SET wallet_balance=wallet_balance_satang/100.0 WHERE id=?')->execute([(int) $transaction['user_id']]);
            ledger_post('TOPUP-' . (string) $transaction['reference'], 'manual_topup', [
                ['account_code' => 'customer_wallet', 'owner_type' => 'user', 'owner_id' => (int) $transaction['user_id'], 'amount_satang' => $amountSatang],
                ['account_code' => 'payment_clearing', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => -$amountSatang],
            ], ['user_id' => (int) $transaction['user_id']]);
            notify((int) $transaction['user_id'], 'payment', 'Wallet top up approved', 'Your top up of ' . money_satang($amountSatang) . ' has been approved.', '?page=topup&tx=' . $transaction['reference'], (bool) $transaction['is_demo']);
            $message = 'Top up approved and credited.';
        } else {
            $review = db()->prepare("UPDATE wallet_transactions SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
            $review->execute(['rejected', $transactionId]);
            if ($review->rowCount() !== 1) {
                throw new RuntimeException('This top up was already reviewed.');
            }
            notify((int) $transaction['user_id'], 'payment', 'Wallet top up rejected', 'Your top up request was rejected.', '?page=topup&tx=' . $transaction['reference'], (bool) $transaction['is_demo']);
            $message = 'Top up rejected.';
        }
        log_security((int) $admin['id'], 'wallet_review_' . $decision);
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }

    flash('success', $message);
    redirect_back('?page=admin-finance');
}

function action_change_password(): never
{
    $user = require_auth();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['new_password_confirmation'] ?? '');
    $minimum = ($user['role'] ?? '') === 'admin' ? 12 : 10;
    if (strlen($new) < $minimum || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        throw new PublicRuntimeException('Use at least ' . $minimum . ' characters with both letters and numbers.');
    }
    if (!hash_equals($new, $confirmation)) {
        throw new PublicRuntimeException('New password confirmation does not match.');
    }
    enforce_rate_limit('password_change', (string) $user['id'], 8, 900);
    db()->beginTransaction();
    try {
        lock_financial_accounts([(int) $user['id']]);
        $freshUser = fetch_one('SELECT password_hash,status FROM users WHERE id=?', [(int) $user['id']]);
        if (!$freshUser || (string) $freshUser['status'] !== 'active') {
            throw new PublicRuntimeException('This account can no longer change its password.');
        }
        if (!password_verify($current, (string) $freshUser['password_hash'])) {
            audit_event((int) $user['id'], 'password_change_failed', 'user', (int) $user['id']);
            throw new PublicRuntimeException('Your current password is incorrect.');
        }
        db()->prepare('UPDATE users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([password_hash($new, PASSWORD_DEFAULT),(int) $user['id']]);
        db()->prepare('DELETE FROM sessions WHERE user_id=? AND token_hash<>?')
            ->execute([(int) $user['id'], hash('sha256', session_id())]);
        log_security((int) $user['id'], 'password_changed');
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    clear_rate_limit('password_change', (string) $user['id']);
    flash('success', 'Password changed successfully.');
    redirect_back('?page=settings');
}

function action_revoke_other_sessions(): never
{
    $user = require_auth();
    db()->prepare('DELETE FROM sessions WHERE user_id=? AND token_hash<>?')
        ->execute([(int) $user['id'], hash('sha256', session_id())]);
    audit_event((int) $user['id'], 'other_sessions_revoked', 'user', (int) $user['id']);
    flash('success', 'Other signed-in sessions were revoked.');
    redirect_back(($user['role'] ?? '') === 'admin' ? '?page=admin-security' : '?page=settings');
}

function action_save_service(): never
{
    $user = require_role('seller');
    ensure_seller_approved($user);
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $priceSatang = amount_to_satang($_POST['price'] ?? '');
    $price = satang_to_float($priceSatang);
    $days = max(1, (int) ($_POST['delivery_days'] ?? 7));
    $features = trim((string) ($_POST['features'] ?? ''));
    if (mb_strlen($title) < 5 || mb_strlen($title) > 180 || mb_strlen($description) < 20 || mb_strlen($description) > 10000 || $priceSatang < 10000 || $priceSatang > 100000000 || !$categoryId || $days > 365) {
        throw new RuntimeException('Complete the service title, description, category, and a price of at least ฿100.');
    }
    if (!(bool) scalar('SELECT COUNT(*) FROM categories WHERE id=?', [$categoryId])) {
        throw new RuntimeException('The selected category does not exist.');
    }
    $thumbnail = store_upload('thumbnail', image_upload_types(), 5242880);
    $previousThumbnailToDelete = '';
    db()->beginTransaction();
    try {
        lock_financial_accounts([(int) $user['id']]);
        if ((string) scalar('SELECT status FROM users WHERE id=?', [(int) $user['id']]) !== 'active') {
            throw new PublicRuntimeException('This seller account can no longer change services.');
        }
        if ($serviceId) {
            $existing = fetch_one('SELECT * FROM services WHERE id=? AND seller_id=?', [$serviceId, (int) $user['id']]);
            if (!$existing) {
                throw new RuntimeException('Service not found.');
            }
            $materialChange = (string) $existing['title'] !== $title
                || (string) $existing['description'] !== $description
                || (int) $existing['category_id'] !== $categoryId
                || value_satang($existing, 'price_satang', 'price') !== $priceSatang
                || (string) $existing['features'] !== $features
                || $thumbnail !== '';
            $nextStatus = $materialChange && (string) $existing['status'] === 'active' ? 'pending' : (string) $existing['status'];
            if ($thumbnail !== '') {
                $stmt = db()->prepare('UPDATE services SET category_id=?,title=?,description=?,price=?,price_satang=?,delivery_days=?,features=?,thumbnail=?,status=?,moderation_version=moderation_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND seller_id=?');
                $stmt->execute([$categoryId,$title,$description,$price,$priceSatang,$days,$features,$thumbnail,$nextStatus,$serviceId,(int) $user['id']]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Service not found.');
                }
                $previousThumbnailToDelete = (string) $existing['thumbnail'];
            } else {
                $stmt = db()->prepare('UPDATE services SET category_id=?,title=?,description=?,price=?,price_satang=?,delivery_days=?,features=?,status=?,moderation_version=moderation_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND seller_id=?');
                $stmt->execute([$categoryId,$title,$description,$price,$priceSatang,$days,$features,$nextStatus,$serviceId,(int) $user['id']]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Service not found.');
                }
            }
            if ($nextStatus === 'pending' && (string) $existing['status'] === 'active') {
                foreach (fetch_all("SELECT users.id FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name='admin' AND users.status='active'") as $admin) {
                    notify((int) $admin['id'], 'service', 'Updated service requires review', $user['name'] . ' updated "' . $title . '".', '?page=admin-services', (bool) $user['is_demo']);
                }
                flash('info', 'Service updated and sent for approval because public content changed.');
            } else {
                flash('success', 'Service updated.');
            }
        } else {
            $stmt = db()->prepare('INSERT INTO services (seller_id,category_id,title,description,price,price_satang,delivery_days,features,thumbnail,status,is_demo) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([(int) $user['id'],$categoryId,$title,$description,$price,$priceSatang,$days,$features,$thumbnail ?: 'website','pending',(int)$user['is_demo']]);
            $serviceId = database_last_insert_id();
            $admins = fetch_all("SELECT users.id FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name='admin' AND users.status='active'");
            foreach ($admins as $admin) {
                notify((int) $admin['id'], 'service', 'Service approval required', $user['name'] . ' submitted "' . $title . '" for review.', '?page=admin-services', (bool) $user['is_demo']);
            }
            notify((int) $user['id'], 'service', 'Service submitted for review', 'Your new service is waiting for admin approval before it appears in the marketplace.', '?page=seller-services', (bool) $user['is_demo']);
            flash('info', 'Service submitted for admin approval.');
        }
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        delete_stored_upload($thumbnail);
        throw $error;
    }
    delete_stored_upload($previousThumbnailToDelete);
    redirect('?page=seller-services');
}

function action_delete_service(): never
{
    $user = require_role('seller');
    ensure_seller_approved($user);
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $thumbnailToDelete = '';
    $paused = false;
    db()->beginTransaction();
    try {
        lock_financial_accounts([(int) $user['id']]);
        if ((string) scalar('SELECT status FROM users WHERE id=?', [(int) $user['id']]) !== 'active') {
            throw new PublicRuntimeException('This seller account can no longer change services.');
        }
        $service = fetch_one('SELECT id,thumbnail FROM services WHERE id=? AND seller_id=?', [$serviceId, (int) $user['id']]);
        if (!$service) {
            throw new PublicRuntimeException('Service not found.');
        }
        if ((int) scalar('SELECT COUNT(*) FROM orders WHERE service_id=?', [$serviceId]) > 0) {
            $statement = db()->prepare(
                "UPDATE services SET status='paused',updated_at=CURRENT_TIMESTAMP WHERE id=? AND seller_id=?"
            );
            $statement->execute([$serviceId,(int) $user['id']]);
            $paused = true;
        } else {
            $statement = db()->prepare('DELETE FROM services WHERE id=? AND seller_id=?');
            $statement->execute([$serviceId,(int) $user['id']]);
            if ($statement->rowCount() !== 1) {
                throw new PublicRuntimeException('Service not found.');
            }
            $thumbnailToDelete = (string) $service['thumbnail'];
        }
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    if ($paused) {
        flash('info', 'This service has order history, so it was paused instead of deleted.');
    } else {
        delete_stored_upload($thumbnailToDelete);
        flash('success', 'Service deleted.');
    }
    redirect('?page=seller-services');
}

function action_update_order(): never
{
    $user = require_auth();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $allowed = ['pending','in_progress','review','completed','cancelled'];
    $order = fetch_one('SELECT * FROM orders WHERE id=?', [$orderId]);
    if (!$order || !in_array($status, $allowed, true)) {
        throw new PublicRuntimeException('Order update is invalid.');
    }
    $roleTransitions = [];
    if ($user['role'] === 'admin') {
        if (!admin_can($user, 'orders.manage')) {
            throw new PublicRuntimeException('Your admin role cannot change order status.');
        }
        $roleTransitions = [
            'pending' => ['in_progress', 'cancelled'],
            'in_progress' => ['review', 'cancelled'],
            'review' => ['in_progress', 'completed', 'cancelled'],
        ];
    } elseif ($user['role'] === 'seller' && (int) $order['seller_id'] === (int) $user['id']) {
        ensure_seller_approved($user);
        $roleTransitions = [
            'pending' => ['in_progress', 'cancelled'],
            'in_progress' => ['cancelled'],
            'review' => ['cancelled'],
        ];
    } elseif ($user['role'] === 'customer' && (int) $order['customer_id'] === (int) $user['id']) {
        $roleTransitions = [
            'pending' => ['cancelled'],
            'review' => ['completed'],
        ];
    } else {
        throw new PublicRuntimeException('You cannot update this order.');
    }
    if (order_has_active_dispute($orderId)) {
        throw new PublicRuntimeException('This order is locked while its dispute is under review.');
    }
    if (!in_array($status, $roleTransitions[(string) $order['status']] ?? [], true)) {
        throw new PublicRuntimeException('This order status change is not allowed.');
    }
    $usesDeliveryWorkflow = (int) scalar("SELECT COUNT(*) FROM order_events WHERE order_id=? AND event='order_placed'", [$orderId]) > 0;
    if ($usesDeliveryWorkflow && $user['role'] === 'seller' && $status === 'review') {
        throw new PublicRuntimeException('Submit the finished work through the delivery form instead of changing status directly.');
    }
    if ($usesDeliveryWorkflow && $user['role'] === 'customer' && $status === 'completed'
        && (int) scalar('SELECT COUNT(*) FROM order_deliveries WHERE order_id=?', [$orderId]) < 1) {
        throw new PublicRuntimeException('This order has no delivery record to approve.');
    }
    if ($status === 'cancelled' && mb_strlen($reason) < 5) {
        throw new PublicRuntimeException('Please provide a short cancellation reason.');
    }
    db()->beginTransaction();
    try {
        lock_order_record($orderId);
        lock_financial_accounts([(int) $order['customer_id'], (int) $order['seller_id']]);
        $freshOrder = fetch_one('SELECT * FROM orders WHERE id=?', [$orderId]);
        if (!$freshOrder || (string) $freshOrder['status'] !== (string) $order['status']) {
            throw new PublicRuntimeException('The order changed before your update started. Please reload and try again.');
        }
        $order = $freshOrder;
        if (order_has_active_dispute($orderId)) {
            throw new PublicRuntimeException('This order is locked while its dispute is under review.');
        }
        $payment = fetch_one("SELECT * FROM payments WHERE order_id=? AND status='paid'", [$orderId]);
        if (!$payment) {
            throw new RuntimeException('A paid transaction was not found for this order.');
        }
        $totalSatang = value_satang($order, 'total_satang', 'total');
        if ($status === 'cancelled' && (string) $payment['method'] !== 'wallet') {
            stripe_refund_payment(
                (string) $payment['transaction_ref'],
                $totalSatang,
                'refund-' . (string) $order['order_number']
            );
        }
        $transition = db()->prepare(
            'UPDATE orders SET status=?,cancellation_reason=?,accepted_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status=?'
        );
        $transition->execute([
            $status,
            $status === 'cancelled' ? mb_substr($reason, 0, 1000) : (string) ($order['cancellation_reason'] ?? ''),
            $status === 'completed' ? gmdate('Y-m-d H:i:s') : ($order['accepted_at'] ?? null),
            $orderId,
            $order['status'],
        ]);
        if ($transition->rowCount() !== 1) {
            throw new RuntimeException('The order changed before your update finished. Please try again.');
        }
        if ($status === 'cancelled') {
            $refundReference = 'REFUND-' . (string) $order['order_number'];
            if ((string) $payment['method'] === 'wallet') {
                db()->prepare(
                    "INSERT INTO wallet_transactions (user_id,amount,amount_satang,method,status,reference,note,is_demo)
                     VALUES (?,?,?,'refund','completed',?,?,?)"
                )->execute([
                    (int) $order['customer_id'],
                    satang_to_float($totalSatang),
                    $totalSatang,
                    $refundReference,
                    'Refund for cancelled order ' . $order['order_number'],
                    (int) $order['is_demo'],
                ]);
                db()->prepare(
                    'UPDATE users SET wallet_balance_satang=wallet_balance_satang+?,updated_at=CURRENT_TIMESTAMP WHERE id=?'
                )->execute([$totalSatang, (int) $order['customer_id']]);
                db()->prepare('UPDATE users SET wallet_balance=wallet_balance_satang/100.0 WHERE id=?')
                    ->execute([(int) $order['customer_id']]);
                ledger_post($refundReference, 'order_refund_wallet', [
                    ['account_code' => 'platform_escrow', 'owner_type' => 'order', 'owner_id' => $orderId, 'amount_satang' => -$totalSatang],
                    ['account_code' => 'customer_wallet', 'owner_type' => 'user', 'owner_id' => (int) $order['customer_id'], 'amount_satang' => $totalSatang],
                ], ['order_id' => $orderId, 'user_id' => (int) $order['customer_id']]);
                $refundMessage = money_satang($totalSatang) . ' was returned to your wallet.';
            } else {
                ledger_post($refundReference, 'order_refund_provider', [
                    ['account_code' => 'platform_escrow', 'owner_type' => 'order', 'owner_id' => $orderId, 'amount_satang' => -$totalSatang],
                    ['account_code' => 'payment_clearing', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => $totalSatang],
                ], ['order_id' => $orderId, 'user_id' => (int) $order['customer_id']]);
                $refundMessage = money_satang($totalSatang) . ' was submitted back to the original payment method.';
            }
            db()->prepare("UPDATE payments SET status='refunded',refunded_satang=? WHERE id=?")
                ->execute([$totalSatang, (int) $payment['id']]);
            notify((int) $order['customer_id'], 'payment', 'Order payment refunded', $refundMessage, '?page=orders', (bool) $order['is_demo']);
        } elseif ($status === 'completed') {
            $fee = (int) ($order['platform_fee_satang'] ?? 0);
            if ($fee === 0 && $totalSatang > 0) {
                $fee = calculate_platform_fee_satang($totalSatang, (int) ($order['fee_rate_bps'] ?? platform_fee_bps()));
            }
            $sellerNet = max(0, $totalSatang - $fee);
            db()->prepare(
                'UPDATE orders SET platform_fee_satang=?,seller_net_satang=? WHERE id=?'
            )->execute([$fee, $sellerNet, $orderId]);
            ledger_post('COMPLETE-' . (string) $order['order_number'], 'order_completed', [
                ['account_code' => 'platform_escrow', 'owner_type' => 'order', 'owner_id' => $orderId, 'amount_satang' => -$totalSatang],
                ['account_code' => 'seller_payable', 'owner_type' => 'user', 'owner_id' => (int) $order['seller_id'], 'amount_satang' => $sellerNet],
                ['account_code' => 'platform_revenue', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => $fee],
            ], ['order_id' => $orderId, 'user_id' => (int) $order['seller_id']]);
        }
        record_order_event(
            $orderId,
            (int) $user['id'],
            $status === 'cancelled' ? 'order_cancelled' : ($status === 'completed' ? 'order_accepted' : 'status_changed'),
            (string) $order['status'],
            $status,
            $reason
        );
        audit_event((int) $user['id'], 'order_status_changed', 'order', $orderId, ['from' => $order['status'], 'to' => $status]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    $recipient = $user['role'] === 'customer' ? (int) $order['seller_id'] : (int) $order['customer_id'];
    notify($recipient, 'order', 'Order status updated', $order['order_number'] . ' is now ' . status_label($status) . '.', '?page=orders', (bool)$order['is_demo']);
    flash('success', 'Order status updated.');
    redirect_back(role_home($user['role']));
}

function action_submit_delivery(): never
{
    $seller = require_role('seller');
    ensure_seller_approved($seller);
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $message = trim((string) ($_POST['delivery_message'] ?? ''));
    $order = fetch_one(
        "SELECT * FROM orders WHERE id=? AND seller_id=? AND status='in_progress'",
        [$orderId, (int) $seller['id']]
    );
    if (!$order) {
        throw new PublicRuntimeException('Only an in-progress order can receive a delivery.');
    }
    if (order_has_active_dispute($orderId)) {
        throw new PublicRuntimeException('This order is locked while its dispute is under review.');
    }
    $attachment = store_upload(
        'delivery_attachment',
        image_upload_types() + [
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'text/plain' => 'txt',
        ],
        15728640
    );
    if (mb_strlen($message) < 10 && $attachment === '') {
        throw new PublicRuntimeException('Add a delivery note of at least 10 characters or attach the finished work.');
    }
    db()->beginTransaction();
    try {
        lock_order_record($orderId);
        $freshOrder = fetch_one(
            "SELECT * FROM orders WHERE id=? AND seller_id=? AND status='in_progress'",
            [$orderId, (int) $seller['id']]
        );
        if (!$freshOrder || order_has_active_dispute($orderId)) {
            throw new PublicRuntimeException('The order changed or entered dispute review before delivery finished.');
        }
        $order = $freshOrder;
        db()->prepare(
            'INSERT INTO order_deliveries (order_id,seller_id,message,attachment,revision_number,status) VALUES (?,?,?,?,?,?)'
        )->execute([
            $orderId,
            (int) $seller['id'],
            $message,
            $attachment,
            (int) $order['revision_count'],
            'submitted',
        ]);
        $transition = db()->prepare(
            "UPDATE orders SET status='review',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='in_progress'"
        );
        $transition->execute([$orderId]);
        if ($transition->rowCount() !== 1) {
            throw new RuntimeException('The order changed before delivery finished.');
        }
        record_order_event($orderId, (int) $seller['id'], 'delivery_submitted', 'in_progress', 'review', $message, [
            'attachment' => $attachment !== '',
            'revision_number' => (int) $order['revision_count'],
        ]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        delete_stored_upload($attachment);
        throw $error;
    }
    notify((int) $order['customer_id'], 'order', 'Work submitted for review', $order['order_number'] . ' is ready for your review.', '?page=orders', (bool) $order['is_demo']);
    flash('success', 'Delivery submitted for customer review.');
    redirect_back('?page=seller-orders');
}

function action_request_revision(): never
{
    $customer = require_role('customer');
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $feedback = trim((string) ($_POST['revision_feedback'] ?? ''));
    $order = fetch_one(
        "SELECT * FROM orders WHERE id=? AND customer_id=? AND status='review'",
        [$orderId, (int) $customer['id']]
    );
    if (!$order) {
        throw new PublicRuntimeException('This order is not waiting for your review.');
    }
    if (order_has_active_dispute($orderId)) {
        throw new PublicRuntimeException('This order is locked while its dispute is under review.');
    }
    if (mb_strlen($feedback) < 10 || mb_strlen($feedback) > 3000) {
        throw new PublicRuntimeException('Describe the requested changes in 10 to 3,000 characters.');
    }
    if ((int) $order['revision_count'] >= (int) $order['revision_limit']) {
        throw new PublicRuntimeException('The included revision limit has been reached. Open a dispute if support is needed.');
    }
    db()->beginTransaction();
    try {
        lock_order_record($orderId);
        $freshOrder = fetch_one(
            "SELECT * FROM orders WHERE id=? AND customer_id=? AND status='review'",
            [$orderId, (int) $customer['id']]
        );
        if (!$freshOrder || order_has_active_dispute($orderId)) {
            throw new PublicRuntimeException('The order changed or entered dispute review before the revision request finished.');
        }
        $order = $freshOrder;
        $transition = db()->prepare(
            "UPDATE orders SET status='in_progress',revision_count=revision_count+1,updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='review' AND revision_count<revision_limit"
        );
        $transition->execute([$orderId]);
        if ($transition->rowCount() !== 1) {
            throw new RuntimeException('The order changed before the revision request finished.');
        }
        db()->prepare(
            "UPDATE order_deliveries SET status='revision_requested'
             WHERE id=(SELECT id FROM order_deliveries WHERE order_id=? ORDER BY id DESC LIMIT 1)"
        )->execute([$orderId]);
        record_order_event($orderId, (int) $customer['id'], 'revision_requested', 'review', 'in_progress', $feedback, [
            'revision_number' => (int) $order['revision_count'] + 1,
        ]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    notify((int) $order['seller_id'], 'order', 'Revision requested', $order['order_number'] . ' needs an update: ' . mb_substr($feedback, 0, 120), '?page=seller-orders', (bool) $order['is_demo']);
    flash('success', 'Revision request sent to the seller.');
    redirect_back('?page=orders');
}

function action_open_dispute(): never
{
    $member = require_auth();
    enforce_rate_limit('open_dispute', (string) $member['id'], 6, 86400);
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $reason = pick_value(
        (string) ($_POST['reason'] ?? ''),
        ['not_delivered', 'quality', 'payment', 'conduct', 'other'],
        ''
    );
    $details = trim((string) ($_POST['details'] ?? ''));
    $order = fetch_order_for_user($member, $orderId);
    if (!$order || $member['role'] === 'admin' || (string) $order['status'] === 'cancelled') {
        throw new PublicRuntimeException('This order is not eligible for a dispute.');
    }
    if ($reason === '' || mb_strlen($details) < 20 || mb_strlen($details) > 5000) {
        throw new PublicRuntimeException('Choose a reason and explain the issue in 20 to 5,000 characters.');
    }
    if ((int) scalar("SELECT COUNT(*) FROM disputes WHERE order_id=? AND status IN ('open','investigating')", [$orderId]) > 0) {
        throw new PublicRuntimeException('An active dispute already exists for this order.');
    }
    if ((string) $order['status'] === 'completed' && strtotime((string) $order['updated_at']) < time() - 1209600) {
        throw new PublicRuntimeException('Completed orders can be disputed within 14 days.');
    }
    $againstUserId = (int) $member['id'] === (int) $order['customer_id']
        ? (int) $order['seller_id']
        : (int) $order['customer_id'];
    db()->beginTransaction();
    try {
        lock_order_record($orderId);
        lock_financial_accounts([(int) $order['customer_id'], (int) $order['seller_id']]);
        $freshOrder = fetch_order_for_user($member, $orderId);
        if (!$freshOrder || $member['role'] === 'admin' || (string) $freshOrder['status'] === 'cancelled') {
            throw new PublicRuntimeException('This order is no longer eligible for a dispute.');
        }
        $order = $freshOrder;
        if (order_has_active_dispute($orderId)) {
            throw new PublicRuntimeException('An active dispute already exists for this order.');
        }
        if ((string) $order['status'] === 'completed' && strtotime((string) $order['updated_at']) < time() - 1209600) {
            throw new PublicRuntimeException('Completed orders can be disputed within 14 days.');
        }
        db()->prepare(
            "INSERT INTO disputes (order_id,opened_by,against_user_id,reason,details,status) VALUES (?,?,?,?,?,'open')"
        )->execute([$orderId, (int) $member['id'], $againstUserId, $reason, $details]);
        $disputeId = database_last_insert_id();
        if ((string) $order['status'] === 'completed') {
            $sellerNet = (int) $order['seller_net_satang'];
            if ($sellerNet > 0) {
                if (ledger_balance('seller_payable', 'user', (int) $order['seller_id']) < $sellerNet) {
                    throw new PublicRuntimeException('This completed order has already entered payout processing. Contact support for manual review.');
                }
                ledger_post('DISPUTE-HOLD-' . $disputeId, 'dispute_hold', [
                    ['account_code' => 'seller_payable', 'owner_type' => 'user', 'owner_id' => (int) $order['seller_id'], 'amount_satang' => -$sellerNet],
                    ['account_code' => 'dispute_reserve', 'owner_type' => 'dispute', 'owner_id' => $disputeId, 'amount_satang' => $sellerNet],
                ], ['order_id' => $orderId, 'user_id' => (int) $order['seller_id']]);
            }
        }
        record_order_event($orderId, (int) $member['id'], 'dispute_opened', (string) $order['status'], (string) $order['status'], $details, ['dispute_id' => $disputeId, 'reason' => $reason]);
        audit_event((int) $member['id'], 'dispute_opened', 'dispute', $disputeId, ['order_id' => $orderId]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    foreach (fetch_all("SELECT users.id FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name='admin' AND users.status='active'") as $admin) {
        notify((int) $admin['id'], 'dispute', 'New dispute opened', $order['order_number'] . ' requires support review.', '?page=admin-disputes');
    }
    notify($againstUserId, 'dispute', 'A dispute was opened', $order['order_number'] . ' is now under support review.', '?page=disputes', (bool) $order['is_demo']);
    flash('success', 'Dispute opened. Support can now review the order history and evidence.');
    redirect('?page=disputes');
}

function action_add_dispute_evidence(): never
{
    $member = require_auth();
    $disputeId = (int) ($_POST['dispute_id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    $dispute = fetch_one(
        'SELECT disputes.*,orders.customer_id,orders.seller_id FROM disputes JOIN orders ON orders.id=disputes.order_id WHERE disputes.id=?',
        [$disputeId]
    );
    if (!$dispute || !in_array((int) $member['id'], [(int) $dispute['customer_id'], (int) $dispute['seller_id']], true) || !in_array((string) $dispute['status'], ['open', 'investigating'], true)) {
        throw new PublicRuntimeException('This dispute cannot receive evidence.');
    }
    $attachment = store_upload(
        'evidence_attachment',
        image_upload_types() + ['application/pdf' => 'pdf', 'text/plain' => 'txt'],
        10485760
    );
    if (mb_strlen($note) < 5 && $attachment === '') {
        throw new PublicRuntimeException('Add a short note or evidence attachment.');
    }
    try {
        db()->prepare(
            'INSERT INTO dispute_evidence (dispute_id,uploaded_by,note,attachment) VALUES (?,?,?,?)'
        )->execute([$disputeId, (int) $member['id'], mb_substr($note, 0, 3000), $attachment]);
        db()->prepare("UPDATE disputes SET status='investigating',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='open'")
            ->execute([$disputeId]);
    } catch (Throwable $error) {
        delete_stored_upload($attachment);
        throw $error;
    }
    audit_event((int) $member['id'], 'dispute_evidence_added', 'dispute', $disputeId, ['attachment' => $attachment !== '']);
    flash('success', 'Evidence added to the dispute.');
    redirect('?page=disputes&id=' . $disputeId);
}

function action_admin_resolve_dispute(): never
{
    $admin = require_admin_capability('disputes.manage');
    $disputeId = (int) ($_POST['dispute_id'] ?? 0);
    $decision = pick_value((string) ($_POST['decision'] ?? ''), ['release', 'refund'], '');
    $resolution = trim((string) ($_POST['resolution'] ?? ''));
    $dispute = fetch_one(
        'SELECT disputes.*,disputes.status AS dispute_status,orders.*,orders.status AS order_status,
         payments.id AS payment_id,payments.method AS payment_method,
         payments.status AS payment_status,payments.transaction_ref
         FROM disputes JOIN orders ON orders.id=disputes.order_id
         JOIN payments ON payments.order_id=orders.id WHERE disputes.id=?',
        [$disputeId]
    );
    if (!$dispute || !in_array((string) $dispute['dispute_status'], ['open', 'investigating'], true)) {
        throw new PublicRuntimeException('Active dispute not found.');
    }
    if ($decision === '' || mb_strlen($resolution) < 10 || mb_strlen($resolution) > 5000) {
        throw new PublicRuntimeException('Choose an outcome and provide a resolution note of at least 10 characters.');
    }
    db()->beginTransaction();
    try {
        lock_order_record((int) $dispute['order_id']);
        lock_financial_accounts([(int) $dispute['customer_id'], (int) $dispute['seller_id']]);
        $freshDispute = fetch_one(
            'SELECT disputes.*,disputes.status AS dispute_status,orders.*,orders.status AS order_status,
             payments.id AS payment_id,payments.method AS payment_method,
             payments.status AS payment_status,payments.transaction_ref
             FROM disputes JOIN orders ON orders.id=disputes.order_id
             JOIN payments ON payments.order_id=orders.id WHERE disputes.id=?',
            [$disputeId]
        );
        if (!$freshDispute || !in_array((string) $freshDispute['dispute_status'], ['open', 'investigating'], true)) {
            throw new PublicRuntimeException('The dispute changed before the resolution started.');
        }
        $dispute = $freshDispute;
        $totalSatang = value_satang($dispute, 'total_satang', 'total');
        if ($decision === 'refund' && (string) $dispute['payment_method'] !== 'wallet' && (string) $dispute['payment_status'] === 'paid') {
            stripe_refund_payment((string) $dispute['transaction_ref'], $totalSatang, 'dispute-refund-' . $disputeId);
        }
        $hasReserve = (int) scalar('SELECT COUNT(*) FROM ledger_transactions WHERE reference=?', ['DISPUTE-HOLD-' . $disputeId]) > 0;
        if ($decision === 'refund') {
            if ((string) $dispute['payment_status'] !== 'paid') {
                throw new RuntimeException('The order payment is not refundable.');
            }
            $entries = [];
            if ($hasReserve) {
                $sellerNet = (int) $dispute['seller_net_satang'];
                $fee = (int) $dispute['platform_fee_satang'];
                $entries[] = ['account_code' => 'dispute_reserve', 'owner_type' => 'dispute', 'owner_id' => $disputeId, 'amount_satang' => -$sellerNet];
                if ($fee > 0) {
                    $entries[] = ['account_code' => 'platform_revenue', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => -$fee];
                }
            } elseif ((string) $dispute['order_status'] === 'completed') {
                $sellerNet = (int) $dispute['seller_net_satang'];
                $fee = (int) $dispute['platform_fee_satang'];
                if (ledger_balance('seller_payable', 'user', (int) $dispute['seller_id']) < $sellerNet) {
                    throw new PublicRuntimeException('Seller funds have already entered payout processing. Reconcile the payout before refunding.');
                }
                $entries[] = ['account_code' => 'seller_payable', 'owner_type' => 'user', 'owner_id' => (int) $dispute['seller_id'], 'amount_satang' => -$sellerNet];
                if ($fee > 0) {
                    $entries[] = ['account_code' => 'platform_revenue', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => -$fee];
                }
            } else {
                $entries[] = ['account_code' => 'platform_escrow', 'owner_type' => 'order', 'owner_id' => (int) $dispute['order_id'], 'amount_satang' => -$totalSatang];
            }
            if ((string) $dispute['payment_method'] === 'wallet') {
                $entries[] = ['account_code' => 'customer_wallet', 'owner_type' => 'user', 'owner_id' => (int) $dispute['customer_id'], 'amount_satang' => $totalSatang];
                db()->prepare('UPDATE users SET wallet_balance_satang=wallet_balance_satang+?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
                    ->execute([$totalSatang, (int) $dispute['customer_id']]);
                db()->prepare('UPDATE users SET wallet_balance=wallet_balance_satang/100.0 WHERE id=?')
                    ->execute([(int) $dispute['customer_id']]);
                db()->prepare(
                    "INSERT INTO wallet_transactions (user_id,amount,amount_satang,method,status,reference,note,is_demo)
                     VALUES (?,?,?,'refund','completed',?,?,?)"
                )->execute([
                    (int) $dispute['customer_id'],
                    satang_to_float($totalSatang),
                    $totalSatang,
                    'DISPUTE-REFUND-' . $disputeId,
                    'Dispute refund for ' . $dispute['order_number'],
                    (int) $dispute['is_demo'],
                ]);
            } else {
                $entries[] = ['account_code' => 'payment_clearing', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => $totalSatang];
            }
            ledger_post('DISPUTE-REFUND-' . $disputeId, 'dispute_refund', $entries, [
                'order_id' => (int) $dispute['order_id'],
                'user_id' => (int) $dispute['customer_id'],
            ]);
            db()->prepare("UPDATE payments SET status='refunded',refunded_satang=? WHERE id=?")
                ->execute([$totalSatang, (int) $dispute['payment_id']]);
            db()->prepare("UPDATE orders SET status='cancelled',cancellation_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute(['Dispute refund: ' . mb_substr($resolution, 0, 900), (int) $dispute['order_id']]);
            record_order_event((int) $dispute['order_id'], (int) $admin['id'], 'dispute_refunded', (string) $dispute['order_status'], 'cancelled', $resolution, ['dispute_id' => $disputeId]);
        } elseif ($decision === 'release') {
            if ((string) $dispute['order_status'] === 'cancelled') {
                throw new PublicRuntimeException('A cancelled order cannot release funds to the seller.');
            }
            if ($hasReserve) {
                $sellerNet = (int) $dispute['seller_net_satang'];
                ledger_post('DISPUTE-RELEASE-' . $disputeId, 'dispute_release', [
                    ['account_code' => 'dispute_reserve', 'owner_type' => 'dispute', 'owner_id' => $disputeId, 'amount_satang' => -$sellerNet],
                    ['account_code' => 'seller_payable', 'owner_type' => 'user', 'owner_id' => (int) $dispute['seller_id'], 'amount_satang' => $sellerNet],
                ], ['order_id' => (int) $dispute['order_id'], 'user_id' => (int) $dispute['seller_id']]);
            } elseif ((string) $dispute['order_status'] !== 'completed') {
                if ((string) $dispute['payment_status'] !== 'paid') {
                    throw new PublicRuntimeException('The order payment is not available for release.');
                }
                $fee = (int) ($dispute['platform_fee_satang'] ?? 0);
                if ($fee === 0 && $totalSatang > 0) {
                    $fee = calculate_platform_fee_satang($totalSatang, (int) ($dispute['fee_rate_bps'] ?? platform_fee_bps()));
                }
                $sellerNet = max(0, $totalSatang - $fee);
                ledger_post('DISPUTE-RELEASE-' . $disputeId, 'dispute_release', [
                    ['account_code' => 'platform_escrow', 'owner_type' => 'order', 'owner_id' => (int) $dispute['order_id'], 'amount_satang' => -$totalSatang],
                    ['account_code' => 'seller_payable', 'owner_type' => 'user', 'owner_id' => (int) $dispute['seller_id'], 'amount_satang' => $sellerNet],
                    ['account_code' => 'platform_revenue', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => $fee],
                ], ['order_id' => (int) $dispute['order_id'], 'user_id' => (int) $dispute['seller_id']]);
                db()->prepare(
                    "UPDATE orders SET status='completed',platform_fee_satang=?,seller_net_satang=?,
                     accepted_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?"
                )->execute([$fee, $sellerNet, (int) $dispute['order_id']]);
                record_order_event(
                    (int) $dispute['order_id'],
                    (int) $admin['id'],
                    'dispute_released',
                    (string) $dispute['order_status'],
                    'completed',
                    $resolution,
                    ['dispute_id' => $disputeId]
                );
            }
        }
        $resolve = db()->prepare(
            "UPDATE disputes SET status='resolved',assigned_to=?,resolution=?,resolution_action=?,resolved_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status IN ('open','investigating')"
        );
        $resolve->execute([(int) $admin['id'], $resolution, $decision, $disputeId]);
        if ($resolve->rowCount() !== 1) {
            throw new RuntimeException('The dispute changed before the resolution could be saved.');
        }
        audit_event((int) $admin['id'], 'dispute_resolved', 'dispute', $disputeId, ['decision' => $decision]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    notify((int) $dispute['customer_id'], 'dispute', 'Dispute resolved', $resolution, '?page=disputes&id=' . $disputeId, (bool) $dispute['is_demo']);
    notify((int) $dispute['seller_id'], 'dispute', 'Dispute resolved', $resolution, '?page=disputes&id=' . $disputeId, (bool) $dispute['is_demo']);
    flash('success', 'Dispute resolved and the financial records were updated.');
    redirect('?page=admin-disputes');
}

function action_request_payout(): never
{
    $seller = require_role('seller');
    ensure_seller_approved($seller);
    enforce_rate_limit('payout_request', (string) $seller['id'], 10, 3600);
    $amountSatang = amount_to_satang($_POST['amount'] ?? '');
    $destination = trim((string) ($_POST['destination_label'] ?? ''));
    if ($amountSatang < 10000 || mb_strlen($destination) < 5 || mb_strlen($destination) > 180) {
        throw new PublicRuntimeException('Payouts require at least ฿100 and a valid destination label.');
    }
    db()->beginTransaction();
    try {
        lock_financial_accounts([(int) $seller['id']]);
        $payable = ledger_balance('seller_payable', 'user', (int) $seller['id']);
        $reserved = (int) scalar(
            "SELECT COALESCE(SUM(amount_satang),0) FROM payouts WHERE seller_id=? AND status IN ('requested','approved','processing','on_hold')",
            [(int) $seller['id']]
        );
        $available = max(0, $payable - $reserved);
        if ($amountSatang > $available) {
            throw new PublicRuntimeException('Requested payout exceeds your available balance of ' . money_satang($available) . '.');
        }
        $reference = 'PAYOUT-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
        db()->prepare(
            "INSERT INTO payouts (seller_id,amount_satang,status,destination_label,reference,is_demo) VALUES (?,?,'requested',?,?,?)"
        )->execute([(int) $seller['id'], $amountSatang, encrypt_sensitive($destination), $reference, (int) $seller['is_demo']]);
        $payoutId = database_last_insert_id();
        audit_event((int) $seller['id'], 'payout_requested', 'payout', $payoutId, ['amount_satang' => $amountSatang]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    foreach (fetch_all("SELECT users.id FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name='admin' AND users.status='active'") as $admin) {
        notify((int) $admin['id'], 'payout', 'Payout request received', $seller['name'] . ' requested ' . money_satang($amountSatang) . '.', '?page=admin-payouts');
    }
    flash('success', 'Payout request submitted for finance review.');
    redirect('?page=seller-payouts');
}

function action_admin_review_payout(): never
{
    $admin = require_admin_capability('payout.manage');
    $payoutId = (int) ($_POST['payout_id'] ?? 0);
    $decision = pick_value((string) ($_POST['decision'] ?? ''), ['approve', 'reject', 'mark_paid'], '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $payout = fetch_one('SELECT payouts.*,users.name FROM payouts JOIN users ON users.id=payouts.seller_id WHERE payouts.id=?', [$payoutId]);
    if (!$payout || $decision === '') {
        throw new PublicRuntimeException('Payout request is invalid.');
    }
    db()->beginTransaction();
    try {
        lock_financial_accounts([(int) $payout['seller_id']]);
        if ($decision === 'approve') {
            $update = db()->prepare(
                "UPDATE payouts SET status='approved',reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,rejection_reason='' WHERE id=? AND status='requested'"
            );
            $update->execute([(int) $admin['id'], $payoutId]);
            $message = 'Your payout request was approved and is waiting for transfer.';
        } elseif ($decision === 'reject') {
            if (mb_strlen($reason) < 5) {
                throw new PublicRuntimeException('Provide a short reason for rejecting the payout.');
            }
            $update = db()->prepare(
                "UPDATE payouts SET status='rejected',reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,rejection_reason=? WHERE id=? AND status IN ('requested','approved','on_hold')"
            );
            $update->execute([(int) $admin['id'], mb_substr($reason, 0, 1000), $payoutId]);
            $message = 'Your payout request was rejected: ' . $reason;
        } else {
            $amountSatang = (int) $payout['amount_satang'];
            if (ledger_balance('seller_payable', 'user', (int) $payout['seller_id']) < $amountSatang) {
                throw new PublicRuntimeException('Seller payable balance is lower than this payout. Reconcile the account first.');
            }
            $update = db()->prepare(
                "UPDATE payouts SET status='paid',reviewed_by=COALESCE(reviewed_by,?),reviewed_at=COALESCE(reviewed_at,CURRENT_TIMESTAMP),paid_at=CURRENT_TIMESTAMP WHERE id=? AND status='approved'"
            );
            $update->execute([(int) $admin['id'], $payoutId]);
            if ($update->rowCount() === 1) {
                ledger_post((string) $payout['reference'], 'seller_payout', [
                    ['account_code' => 'seller_payable', 'owner_type' => 'user', 'owner_id' => (int) $payout['seller_id'], 'amount_satang' => -$amountSatang],
                    ['account_code' => 'payment_clearing', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => $amountSatang],
                ], ['user_id' => (int) $payout['seller_id']]);
            }
            $message = 'Your payout was marked as transferred.';
        }
        if ($update->rowCount() !== 1) {
            throw new PublicRuntimeException('This payout changed before the review finished.');
        }
        audit_event((int) $admin['id'], 'payout_' . $decision, 'payout', $payoutId, ['reason' => $reason]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    notify((int) $payout['seller_id'], 'payout', 'Payout status updated', $message, '?page=seller-payouts', (bool) $payout['is_demo']);
    flash('success', 'Payout status updated.');
    redirect('?page=admin-payouts');
}

function action_submit_review(): never
{
    $user = require_role('customer');
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $order = fetch_one("SELECT * FROM orders WHERE id=? AND customer_id=? AND status='completed'", [$orderId,(int) $user['id']]);
    $rating = max(1, min(5, (int) ($_POST['rating'] ?? 5)));
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if (!$order || mb_strlen($comment) < 10 || mb_strlen($comment) > 3000) {
        throw new RuntimeException('A completed order and a short review are required.');
    }
    if (order_has_active_dispute($orderId)) {
        throw new PublicRuntimeException('Reviews are paused while this order is under dispute review.');
    }
    db()->prepare('INSERT INTO reviews (order_id,customer_id,seller_id,rating,comment,is_demo) VALUES (?,?,?,?,?,?) ON CONFLICT(order_id) DO UPDATE SET customer_id=excluded.customer_id,seller_id=excluded.seller_id,rating=excluded.rating,comment=excluded.comment,is_demo=excluded.is_demo')->execute([$orderId,(int) $user['id'],(int) $order['seller_id'],$rating,$comment,(int)$order['is_demo']]);
    notify((int) $order['seller_id'], 'review', 'New client review', 'A client left a ' . $rating . '-star review.', '?page=seller-dashboard', (bool)$order['is_demo']);
    flash('success', 'Thank you for your review.');
    redirect('?page=orders');
}

function action_toggle_favorite(): never
{
    $user = require_role('customer');
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    if (!(bool) scalar(
        "SELECT COUNT(*) FROM services JOIN users ON users.id=services.seller_id
         WHERE services.id=? AND services.status='active' AND users.status='active'",
        [$serviceId]
    )) {
        throw new RuntimeException('Service not found.');
    }
    $exists = (bool) scalar('SELECT COUNT(*) FROM favorites WHERE user_id=? AND service_id=?', [(int) $user['id'], $serviceId]);
    if ($exists) {
        db()->prepare('DELETE FROM favorites WHERE user_id=? AND service_id=?')->execute([(int) $user['id'], $serviceId]);
        flash('success', 'Removed from saved services.');
    } else {
        db()->prepare('INSERT INTO favorites (user_id, service_id) VALUES (?, ?)')->execute([(int) $user['id'], $serviceId]);
        flash('success', 'Saved to your services list.');
    }
    redirect_back('?page=services');
}

function action_admin_user_status(): never
{
    $admin = require_admin_capability('users.manage');
    $userId = (int) ($_POST['user_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    if (!in_array($status, ['active','suspended'], true)) {
        throw new PublicRuntimeException('The selected user status is invalid.');
    }
    if ($userId === (int) $admin['id']) {
        throw new RuntimeException('You cannot suspend your own account.');
    }
    $identityUploads = [];
    db()->beginTransaction();
    try {
        $lockIds = array_column(fetch_all(
            "SELECT users.id FROM users JOIN roles ON roles.id=users.role_id
             WHERE roles.name='admin' AND users.status='active' AND users.admin_role='owner'"
        ), 'id');
        $lockIds[] = (int) $admin['id'];
        $lockIds[] = $userId;
        lock_financial_accounts($lockIds);

        $freshAdmin = fetch_one(
            'SELECT users.*,roles.name AS role FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?',
            [(int) $admin['id']]
        );
        $member = fetch_one(
            'SELECT users.*,roles.name AS role FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?',
            [$userId]
        );
        if (!$freshAdmin || (string) $freshAdmin['status'] !== 'active' || !admin_can($freshAdmin, 'users.manage')) {
            throw new PublicRuntimeException('Your administrator access changed before this action completed.');
        }
        if (!$member) {
            throw new PublicRuntimeException('User not found.');
        }
        if ($member['role'] === 'admin' && !admin_can($freshAdmin, 'system.manage')) {
            throw new PublicRuntimeException('Only an owner can change another administrator account.');
        }
        if (
            $member['role'] === 'admin'
            && (string) ($member['admin_role'] ?? '') === 'owner'
            && $status !== 'active'
            && (int) scalar(
                "SELECT COUNT(*) FROM users JOIN roles ON roles.id=users.role_id
                 WHERE roles.name='admin' AND users.admin_role='owner' AND users.status='active'"
            ) <= 1
        ) {
            throw new PublicRuntimeException('The last active owner cannot be suspended.');
        }

        $statement = db()->prepare('UPDATE users SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $statement->execute([$status,$userId]);
        if ($statement->rowCount() !== 1) {
            throw new PublicRuntimeException('User status changed before this action completed.');
        }
        if ($member['role'] === 'seller' && $member['status'] === 'pending_approval') {
            $identityUploads = array_filter([
                (string) ($member['id_card_front'] ?? ''),
                (string) ($member['id_card_back'] ?? ''),
            ]);
            db()->prepare(
                "UPDATE users SET id_card_number='',id_card_front='',id_card_back='',verification_notes=? WHERE id=?"
            )->execute(['Identity documents removed after verification on ' . date('Y-m-d'), $userId]);
            if ($status === 'active') {
                notify($userId, 'account', 'Seller account approved', 'Your seller workspace is now available.', '?page=seller-dashboard');
            } else {
                notify($userId, 'account', 'Seller account not approved', 'Your seller request was not approved.', '?page=home');
            }
        }
        if ($status === 'suspended') {
            db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$userId]);
        }
        audit_event((int) $admin['id'], 'admin_user_status_changed', 'user', $userId, ['from' => $member['status'], 'to' => $status]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    foreach ($identityUploads as $upload) {
        delete_stored_upload((string) $upload);
    }
    flash('success', 'User status updated.');
    redirect('?page=admin-users');
}

function action_admin_assign_role(): never
{
    $admin = require_admin_capability('system.manage');
    $userId = (int) ($_POST['user_id'] ?? 0);
    $adminRole = pick_value(
        (string) ($_POST['admin_role'] ?? ''),
        ['owner', 'finance', 'support', 'moderator', 'analyst'],
        ''
    );
    if ($userId < 1 || $adminRole === '') {
        throw new PublicRuntimeException('The selected admin role is invalid.');
    }
    if ($userId === (int) $admin['id']) {
        throw new PublicRuntimeException('Ask another owner to change your own admin role.');
    }
    db()->beginTransaction();
    try {
        $lockIds = array_column(fetch_all(
            "SELECT users.id FROM users JOIN roles ON roles.id=users.role_id
             WHERE roles.name='admin' AND users.status='active' AND users.admin_role='owner'"
        ), 'id');
        $lockIds[] = (int) $admin['id'];
        $lockIds[] = $userId;
        lock_financial_accounts($lockIds);

        $freshAdmin = fetch_one(
            'SELECT users.*,roles.name AS role FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?',
            [(int) $admin['id']]
        );
        $member = fetch_one(
            "SELECT users.id,users.admin_role,users.status FROM users
             JOIN roles ON roles.id=users.role_id WHERE users.id=? AND roles.name='admin'",
            [$userId]
        );
        if (!$freshAdmin || (string) $freshAdmin['status'] !== 'active' || !admin_can($freshAdmin, 'system.manage')) {
            throw new PublicRuntimeException('Your owner access changed before this action completed.');
        }
        if (!$member) {
            throw new PublicRuntimeException('Administrator account not found.');
        }
        if (
            (string) $member['admin_role'] === 'owner'
            && $adminRole !== 'owner'
            && (int) scalar(
                "SELECT COUNT(*) FROM users JOIN roles ON roles.id=users.role_id
                 WHERE roles.name='admin' AND users.admin_role='owner' AND users.status='active'"
            ) <= 1
        ) {
            throw new PublicRuntimeException('At least one active owner must remain.');
        }
        $statement = db()->prepare('UPDATE users SET admin_role=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $statement->execute([$adminRole, $userId]);
        if ($statement->rowCount() !== 1) {
            throw new PublicRuntimeException('Administrator access changed before this action completed.');
        }
        audit_event(
            (int) $admin['id'],
            'admin_role_changed',
            'user',
            $userId,
            ['from' => $member['admin_role'], 'to' => $adminRole]
        );
        notify($userId, 'account', 'Admin access updated', 'Your administrator access level is now ' . $adminRole . '.', admin_start_page(['role' => 'admin', 'admin_role' => $adminRole]));
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    flash('success', 'Administrator access updated.');
    redirect('?page=admin-users');
}

function action_admin_account_request(): never
{
    $admin = require_admin_capability('system.manage');
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $decision = pick_value((string) ($_POST['decision'] ?? ''), ['complete', 'reject'], '');
    $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 1000);
    $request = fetch_one(
        "SELECT account_requests.*,users.name,users.email,users.avatar,users.id_card_front,users.id_card_back,
         users.wallet_balance_satang,users.is_demo,roles.name AS role
         FROM account_requests JOIN users ON users.id=account_requests.user_id
         JOIN roles ON roles.id=users.role_id
         WHERE account_requests.id=? AND account_requests.request_type='deletion' AND account_requests.status='pending'",
        [$requestId]
    );
    if (!$request || $decision === '') {
        throw new PublicRuntimeException('Pending account request not found.');
    }
    if ((int) $request['user_id'] === (int) $admin['id'] || (string) $request['role'] === 'admin') {
        throw new PublicRuntimeException('Administrator accounts cannot be removed through this workflow.');
    }
    $userId = (int) $request['user_id'];
    if ($decision === 'reject') {
        if (mb_strlen($reason) < 5) {
            throw new PublicRuntimeException('Add a clear reason before rejecting the request.');
        }
        db()->beginTransaction();
        try {
            lock_financial_accounts([(int) $admin['id'], $userId]);
            $freshAdmin = fetch_one(
                'SELECT users.*,roles.name AS role FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?',
                [(int) $admin['id']]
            );
            $freshRequest = fetch_one(
                "SELECT account_requests.notes,account_requests.user_id
                 FROM account_requests WHERE id=? AND request_type='deletion' AND status='pending'",
                [$requestId]
            );
            if (!$freshAdmin || (string) $freshAdmin['status'] !== 'active' || !admin_can($freshAdmin, 'system.manage')) {
                throw new PublicRuntimeException('Your owner access changed before this action completed.');
            }
            if (!$freshRequest || (int) $freshRequest['user_id'] !== $userId) {
                throw new PublicRuntimeException('This deletion request was already reviewed.');
            }
            $notes = trim((string) $freshRequest['notes'] . "\nOwner response: " . $reason);
            $statement = db()->prepare(
                "UPDATE account_requests SET status='rejected',notes=?,completed_at=CURRENT_TIMESTAMP
                 WHERE id=? AND status='pending'"
            );
            $statement->execute([$notes, $requestId]);
            if ($statement->rowCount() !== 1) {
                throw new PublicRuntimeException('This deletion request was already reviewed.');
            }
            notify(
                $userId,
                'account',
                'Account deletion request needs attention',
                $reason,
                '?page=settings'
            );
            audit_event((int) $admin['id'], 'account_deletion_rejected', 'account_request', $requestId);
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $error;
        }
        flash('success', 'The account deletion request was rejected with a reason.');
        redirect('?page=admin-users');
    }

    $activeOrders = (int) scalar(
        "SELECT COUNT(*) FROM orders WHERE (customer_id=? OR seller_id=?) AND status IN ('pending','in_progress','review')",
        [$userId, $userId]
    );
    $activeDisputes = (int) scalar(
        "SELECT COUNT(*) FROM disputes JOIN orders ON orders.id=disputes.order_id
         WHERE (orders.customer_id=? OR orders.seller_id=?) AND disputes.status IN ('open','investigating')",
        [$userId, $userId]
    );
    $sellerPayable = ledger_balance('seller_payable', 'user', $userId);
    $openPayouts = (int) scalar(
        "SELECT COUNT(*) FROM payouts WHERE seller_id=? AND status IN ('requested','approved','processing','on_hold')",
        [$userId]
    );
    $pendingPayments = (int) scalar(
        "SELECT COUNT(*) FROM payment_requests WHERE user_id=? AND status IN ('pending','processing')",
        [$userId]
    );
    if ($activeOrders > 0 || $activeDisputes > 0 || (int) $request['wallet_balance_satang'] !== 0 || $sellerPayable !== 0 || $openPayouts > 0 || $pendingPayments > 0) {
        throw new PublicRuntimeException('Resolve active orders, disputes, wallet funds, payment sessions, and seller payouts before completing deletion.');
    }

    $uploads = [];
    $anonymousEmail = 'deleted+' . $userId . '+' . bin2hex(random_bytes(5)) . '@invalid.local';
    $anonymousName = 'Deleted user #' . $userId;
    $disabledPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    db()->beginTransaction();
    try {
        lock_financial_accounts([(int) $admin['id'], $userId]);
        $freshAdmin = fetch_one(
            'SELECT users.*,roles.name AS role FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?',
            [(int) $admin['id']]
        );
        $freshRequest = fetch_one(
            "SELECT account_requests.*,users.name,users.email,users.avatar,users.id_card_front,users.id_card_back,
             users.wallet_balance_satang,users.is_demo,roles.name AS role
             FROM account_requests JOIN users ON users.id=account_requests.user_id
             JOIN roles ON roles.id=users.role_id
             WHERE account_requests.id=? AND account_requests.request_type='deletion'
             AND account_requests.status='pending'",
            [$requestId]
        );
        if (!$freshAdmin || (string) $freshAdmin['status'] !== 'active' || !admin_can($freshAdmin, 'system.manage')) {
            throw new PublicRuntimeException('Your owner access changed before this action completed.');
        }
        if (
            !$freshRequest
            || (int) $freshRequest['user_id'] !== $userId
            || (string) $freshRequest['role'] === 'admin'
        ) {
            throw new PublicRuntimeException('This deletion request was already reviewed or is no longer eligible.');
        }
        $request = $freshRequest;
        $freshAccount = fetch_one('SELECT wallet_balance_satang FROM users WHERE id=?', [$userId]);
        $activeOrders = (int) scalar(
            "SELECT COUNT(*) FROM orders WHERE (customer_id=? OR seller_id=?) AND status IN ('pending','in_progress','review')",
            [$userId, $userId]
        );
        $activeDisputes = (int) scalar(
            "SELECT COUNT(*) FROM disputes JOIN orders ON orders.id=disputes.order_id
             WHERE (orders.customer_id=? OR orders.seller_id=?) AND disputes.status IN ('open','investigating')",
            [$userId, $userId]
        );
        $sellerPayable = ledger_balance('seller_payable', 'user', $userId);
        $openPayouts = (int) scalar(
            "SELECT COUNT(*) FROM payouts WHERE seller_id=? AND status IN ('requested','approved','processing','on_hold')",
            [$userId]
        );
        $pendingPayments = (int) scalar(
            "SELECT COUNT(*) FROM payment_requests WHERE user_id=? AND status IN ('pending','processing')",
            [$userId]
        );
        if (
            !$freshAccount
            || $activeOrders > 0
            || $activeDisputes > 0
            || (int) $freshAccount['wallet_balance_satang'] !== 0
            || $sellerPayable !== 0
            || $openPayouts > 0
            || $pendingPayments > 0
        ) {
            throw new PublicRuntimeException('The account gained a new obligation during review. Resolve it before completing deletion.');
        }
        $uploadRows = array_merge(
            [['path' => $request['avatar']], ['path' => $request['id_card_front']], ['path' => $request['id_card_back']]],
            fetch_all('SELECT thumbnail AS path FROM services WHERE seller_id=?', [$userId]),
            fetch_all("SELECT attachment AS path FROM messages WHERE sender_id=? AND attachment<>''", [$userId]),
            fetch_all("SELECT attachment AS path FROM order_deliveries WHERE seller_id=? AND attachment<>''", [$userId]),
            fetch_all("SELECT attachment AS path FROM dispute_evidence WHERE uploaded_by=? AND attachment<>''", [$userId]),
            fetch_all("SELECT slip_path AS path FROM wallet_transactions WHERE user_id=? AND slip_path<>''", [$userId])
        );
        $uploads = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => trim((string) ($row['path'] ?? '')),
            $uploadRows
        ))));
        db()->prepare("UPDATE services SET status='paused',description='Removed after account deletion.',features='',thumbnail='',updated_at=CURRENT_TIMESTAMP WHERE seller_id=?")->execute([$userId]);
        db()->prepare("UPDATE messages SET body='[Removed after account deletion]',attachment='' WHERE sender_id=?")->execute([$userId]);
        db()->prepare("UPDATE order_deliveries SET message='[Removed after account deletion]',attachment='' WHERE seller_id=?")->execute([$userId]);
        db()->prepare("UPDATE dispute_evidence SET note='[Removed after account deletion]',attachment='' WHERE uploaded_by=?")->execute([$userId]);
        db()->prepare("UPDATE wallet_transactions SET note='',slip_path='' WHERE user_id=?")->execute([$userId]);
        db()->prepare("UPDATE orders SET requirements='[Removed after account deletion]' WHERE customer_id=?")->execute([$userId]);
        db()->prepare("UPDATE reviews SET comment='Review text removed with account.' WHERE customer_id=?")->execute([$userId]);
        db()->prepare('DELETE FROM favorites WHERE user_id=?')->execute([$userId]);
        db()->prepare('DELETE FROM notifications WHERE user_id=?')->execute([$userId]);
        db()->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([$userId]);
        db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$userId]);
        db()->prepare('DELETE FROM newsletter_subscribers WHERE email=?')->execute([(string) $request['email']]);
        db()->prepare(
            "UPDATE users SET name=?,email=?,password_hash=?,avatar='',phone='',bio='',status='suspended',
             email_notifications=0,wallet_balance=0,wallet_balance_satang=0,birth_date='',id_card_number='',
             id_card_fingerprint='',id_card_front='',id_card_back='',verification_notes='Account anonymized',
             admin_role='',admin_mfa_secret='',admin_mfa_enabled=0,mfa_last_counter=-1,updated_at=CURRENT_TIMESTAMP WHERE id=?"
        )->execute([$anonymousName, $anonymousEmail, $disabledPassword, $userId]);
        $completeRequest = db()->prepare(
            "UPDATE account_requests SET status='completed',completed_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'"
        );
        $completeRequest->execute([$requestId]);
        if ($completeRequest->rowCount() !== 1) {
            throw new RuntimeException('The account request changed before deletion completed.');
        }
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    foreach ($uploads as $upload) {
        delete_stored_upload($upload);
    }
    audit_event((int) $admin['id'], 'account_deletion_completed', 'user', $userId, ['request_id' => $requestId]);
    flash('success', 'The account was anonymized and access was revoked. Financial audit records were retained.');
    redirect('?page=admin-users');
}

function action_admin_service_status(): never
{
    $admin = require_admin_capability('services.manage');
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $moderationVersion = (int) ($_POST['moderation_version'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    if (!in_array($status, ['pending','active','paused','rejected'], true)) {
        throw new PublicRuntimeException('The selected service status is invalid.');
    }
    if ($moderationVersion < 1) {
        throw new PublicRuntimeException('Refresh the moderation queue before reviewing this service.');
    }
    $service = fetch_one('SELECT services.*, users.name AS seller_name FROM services JOIN users ON users.id=services.seller_id WHERE services.id=?', [$serviceId]);
    if (!$service) {
        throw new RuntimeException('Service not found.');
    }
    $statement = db()->prepare(
        'UPDATE services SET status=?,moderation_version=moderation_version+1,updated_at=CURRENT_TIMESTAMP
         WHERE id=? AND moderation_version=?'
    );
    $statement->execute([$status,$serviceId,$moderationVersion]);
    if ($statement->rowCount() !== 1) {
        throw new PublicRuntimeException('The seller changed this service while it was being reviewed. Refresh and inspect the latest version.');
    }
    audit_event((int) $admin['id'], 'admin_service_status_changed', 'service', $serviceId, ['from' => $service['status'], 'to' => $status]);
    if ($status === 'active' && $service['status'] !== 'active') {
        notify((int) $service['seller_id'], 'service', 'Service approved', 'Your service "' . $service['title'] . '" is now live.', '?page=seller-services', (bool) $service['is_demo']);
    } elseif ($status === 'rejected') {
        notify((int) $service['seller_id'], 'service', 'Service rejected', 'Your service "' . $service['title'] . '" was not approved yet. Please revise it and try again.', '?page=seller-services', (bool) $service['is_demo']);
    } elseif ($status === 'paused') {
        notify((int) $service['seller_id'], 'service', 'Service paused', 'Your service "' . $service['title'] . '" was paused by an admin.', '?page=seller-services', (bool) $service['is_demo']);
    }
    flash('success', 'Service moderation status updated.');
    redirect('?page=admin-services');
}

function action_admin_category_save(): never
{
    $admin = require_admin_capability('categories.manage');
    $id = (int) ($_POST['category_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $color = pick_value((string) ($_POST['color'] ?? 'blue'), ['blue','green','violet','amber'], 'blue');
    if (mb_strlen($name) < 2) {
        throw new RuntimeException('Category name is required.');
    }
    if ($code === '') {
        $code = strtoupper(substr(slugify_text($name), 0, 4));
    }
    if (!preg_match('/^[A-Z0-9_-]{1,12}$/', $code)) {
        throw new PublicRuntimeException('Category code may contain A-Z, numbers, underscore, or dash only.');
    }
    if ($id > 0) {
        $statement = db()->prepare('UPDATE categories SET name=?,code=?,color=? WHERE id=?');
        $statement->execute([$name, $code, $color, $id]);
        if ($statement->rowCount() !== 1) {
            throw new PublicRuntimeException('Category not found.');
        }
        flash('success', 'Category updated.');
    } else {
        db()->prepare('INSERT INTO categories (name,code,color) VALUES (?,?,?)')->execute([$name, $code, $color]);
        $id = database_last_insert_id();
        flash('success', 'Category created.');
    }
    audit_event((int) $admin['id'], 'admin_category_saved', 'category', $id, ['code' => $code]);
    redirect('?page=admin-categories');
}

function action_admin_category_delete(): never
{
    $admin = require_admin_capability('categories.manage');
    $id = (int) ($_POST['category_id'] ?? 0);
    $category = fetch_one('SELECT * FROM categories WHERE id=?', [$id]);
    if (!$category) {
        throw new RuntimeException('Category not found.');
    }
    if ((int) scalar('SELECT COUNT(*) FROM services WHERE category_id=?', [$id]) > 0) {
        throw new RuntimeException('Delete or move the services inside this category first.');
    }
    db()->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
    audit_event((int) $admin['id'], 'admin_category_deleted', 'category', $id, ['name' => $category['name']]);
    flash('success', 'Category removed.');
    redirect('?page=admin-categories');
}

function action_admin_coupon_save(): never
{
    $admin = require_admin_capability('coupons.manage');
    $id = (int) ($_POST['coupon_id'] ?? 0);
    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $discount = max(1, min(90, (int) ($_POST['discount_percent'] ?? 0)));
    $active = isset($_POST['active']) ? 1 : 0;
    $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
    $maxUses = trim((string) ($_POST['max_uses'] ?? ''));
    $maxUsesValue = $maxUses === '' ? null : max(1, min(1000000, (int) $maxUses));
    $perUserLimit = max(1, min(100, (int) ($_POST['per_user_limit'] ?? 1)));
    $minimumSatang = amount_to_satang($_POST['minimum_amount'] ?? '0');
    if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
        throw new RuntimeException('Coupon code is required.');
    }
    if ($expiresAt !== '' && strtotime($expiresAt) === false) {
        throw new PublicRuntimeException('Coupon expiry date is invalid.');
    }
    if ($id > 0) {
        $statement = db()->prepare(
            'UPDATE coupons SET code=?,discount_percent=?,active=?,expires_at=?,max_uses=?,per_user_limit=?,minimum_satang=? WHERE id=?'
        );
        $statement->execute([$code,$discount,$active,$expiresAt !== '' ? $expiresAt : null,$maxUsesValue,$perUserLimit,$minimumSatang,$id]);
        if ($statement->rowCount() !== 1) {
            throw new PublicRuntimeException('Coupon not found.');
        }
        flash('success', 'Coupon updated.');
    } else {
        db()->prepare('INSERT INTO coupons (code,discount_percent,active,expires_at,max_uses,per_user_limit,minimum_satang,is_demo) VALUES (?,?,?,?,?,?,?,0)')->execute([$code,$discount,$active,$expiresAt !== '' ? $expiresAt : null,$maxUsesValue,$perUserLimit,$minimumSatang]);
        $id = database_last_insert_id();
        flash('success', 'Coupon created.');
    }
    audit_event((int) $admin['id'], 'admin_coupon_saved', 'coupon', $id, ['code' => $code]);
    redirect('?page=admin-coupons');
}

function action_admin_coupon_delete(): never
{
    $admin = require_admin_capability('coupons.manage');
    $id = (int) ($_POST['coupon_id'] ?? 0);
    $coupon = fetch_one('SELECT * FROM coupons WHERE id=?', [$id]);
    if (!$coupon) {
        throw new RuntimeException('Coupon not found.');
    }
    if ((int) scalar('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=?', [$id]) > 0) {
        db()->prepare('UPDATE coupons SET active=0 WHERE id=?')->execute([$id]);
        flash('info', 'Coupon has redemption history, so it was disabled instead of deleted.');
    } else {
        db()->prepare('DELETE FROM coupons WHERE id=?')->execute([$id]);
        flash('success', 'Coupon removed.');
    }
    audit_event((int) $admin['id'], 'admin_coupon_deleted', 'coupon', $id, ['code' => $coupon['code']]);
    redirect('?page=admin-coupons');
}

function action_admin_broadcast(): never
{
    $admin = require_admin_capability('broadcast.manage');
    $banner = trim((string) ($_POST['announcement_banner'] ?? ''));
    if (mb_strlen($banner) > 500) {
        throw new PublicRuntimeException('Announcement must not exceed 500 characters.');
    }
    $duration = (int) ($_POST['announcement_banner_duration'] ?? 15);
    if (!in_array($duration, [10, 15, 20, 25, 30], true)) {
        $duration = 15;
    }
    $send = isset($_POST['send_notification']);
    audit_event((int) $admin['id'], 'broadcast_sent', 'system', 0, ['notifications' => $send, 'length' => mb_strlen($banner)]);
    set_system_setting('announcement_banner', $banner);
    set_system_setting('announcement_banner_duration', (string) $duration);
    if ($send && $banner !== '') {
        $users = fetch_all("SELECT id,name FROM users WHERE status='active'");
        foreach ($users as $member) {
            notify((int) $member['id'], 'announcement', 'WorkConnect announcement', $banner, '?page=notifications');
        }
    }
    flash('success', 'Announcement updated.');
    redirect('?page=admin-broadcast');
}

function action_admin_settings(): never
{
    $admin = require_admin_capability('system.manage');
    $fields = [
        'site_name' => 'text',
        'site_tagline' => 'text',
        'support_email' => 'text',
        'support_phone' => 'text',
        'contact_ig' => 'text',
        'currency_symbol' => 'text',
        'payment_mode' => 'text',
        'payment_instructions' => 'textarea',
        'bank_account_name' => 'text',
        'bank_name' => 'text',
        'bank_account_number' => 'text',
        'promptpay_id' => 'text',
        'platform_fee' => 'text',
        'topup_minimum' => 'text',
        'topup_slip_required' => 'toggle',
        'announcement_banner' => 'textarea',
        'default_theme' => 'text',
        'default_language' => 'text',
        'default_text_scale' => 'text',
        'default_ui_scale' => 'text',
        'default_email_notifications' => 'toggle',
        'registration_open' => 'toggle',
        'seller_auto_approval' => 'toggle',
        'demo_mode' => 'toggle',
        'maintenance_mode' => 'toggle',
    ];
    $validated = [];
    foreach ($fields as $key => $type) {
        $value = $type === 'toggle' ? (isset($_POST[$key]) ? '1' : '0') : trim((string) ($_POST[$key] ?? ''));
        if ($key === 'payment_mode') {
            $value = 'hosted_promptpay';
        }
        if ($key === 'topup_slip_required') {
            $value = '0';
        }
        $validated[$key] = $value;
    }
    if (!filter_var($validated['support_email'], FILTER_VALIDATE_EMAIL)) {
        throw new PublicRuntimeException('Support email address is invalid.');
    }
    $validated['platform_fee'] = (string) max(0, min(50, (float) $validated['platform_fee']));
    $validated['topup_minimum'] = satang_to_decimal(max(5000, amount_to_satang($validated['topup_minimum'])));
    $validated['default_theme'] = pick_value($validated['default_theme'], ['light', 'dark', 'auto'], 'light');
    $validated['default_language'] = pick_value($validated['default_language'], ['en', 'th'], 'en');
    $validated['default_text_scale'] = pick_value($validated['default_text_scale'], ['small', 'medium', 'large', 'xl'], 'medium');
    $validated['default_ui_scale'] = pick_value($validated['default_ui_scale'], ['compact', 'comfortable', 'roomy'], 'comfortable');
    foreach ($validated as $key => $value) {
        set_system_setting($key, mb_substr($value, 0, $key === 'payment_instructions' || $key === 'announcement_banner' ? 2000 : 255));
    }
    audit_event((int) $admin['id'], 'system_settings_changed', 'system', 0, ['keys' => array_keys($validated)]);
    flash('success', 'System settings saved.');
    redirect('?page=admin-settings');
}
