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
            'topup_wallet' => action_topup_wallet(),
            'admin_wallet_review' => action_admin_wallet_review(),
            'save_service' => action_save_service(),
            'delete_service' => action_delete_service(),
            'update_order' => action_update_order(),
            'submit_review' => action_submit_review(),
            'toggle_favorite' => action_toggle_favorite(),
            'admin_user_status' => action_admin_user_status(),
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
        flash('error', $error->getMessage());
        redirect_back('?page=home');
    }
}

function action_install_demo(): never
{
    $admin = require_role('admin');
    if (!demo_management_allowed($admin)) {
        throw new RuntimeException('Demo management is disabled.');
    }
    install_demo_data(db());
    flash('success', 'Demo workspace is ready. Choose a role to explore the complete workflow.');
    redirect('?page=admin-settings#demo');
}

function action_clear_demo(): never
{
    $admin = require_role('admin');
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
    login_user($user);
    flash('success', 'Welcome back, ' . explode(' ', $user['name'])[0] . '.');
    $destination = safe_return_to((string) ($_SESSION['intended_url'] ?? ''), role_home($user['role']));
    unset($_SESSION['intended_url']);
    redirect($destination);
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
    if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        throw new RuntimeException('Please provide a valid name, email, and password of at least 8 characters.');
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
        if (!is_string($idCardDigits) || strlen($idCardDigits) !== 13) {
            throw new RuntimeException('Seller registration requires a valid 13-digit Thai ID card number.');
        }
        $idCardNumber = encrypt_sensitive($idCardDigits);
    }
    $idCardFront = $role === 'seller' ? store_upload('id_card_front', image_upload_types(), 5242880) : '';
    if ($role === 'seller' && $idCardFront === '') {
        throw new RuntimeException('Seller registration requires a Thai ID card image upload.');
    }
    $sellerAutoApproval = system_setting('seller_auto_approval', '0') === '1';
    $status = $role === 'seller' && !$sellerAutoApproval ? 'pending_approval' : 'active';
    try {
        $stmt = db()->prepare('INSERT INTO users (role_id,name,email,password_hash,phone,status,theme,language,text_scale,ui_scale,email_notifications,birth_date,id_card_number,id_card_front,id_card_back) VALUES ((SELECT id FROM roles WHERE name=?),?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
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
    if (strlen($token) !== 64 || strlen($password) < 8 || !hash_equals($password, $confirmation)) {
        throw new RuntimeException('The reset link or password is invalid.');
    }
    $reset = fetch_one("SELECT * FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP", [hash('sha256', $token)]);
    if (!$reset) throw new RuntimeException('This password reset link is invalid or has expired.');
    db()->beginTransaction();
    try {
        db()->prepare('UPDATE users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), (int) $reset['user_id']]);
        $consume = db()->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE id=? AND used_at IS NULL');
        $consume->execute([(int) $reset['id']]);
        if ($consume->rowCount() !== 1) throw new RuntimeException('This reset link was already used.');
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
    $service = fetch_one("SELECT * FROM services WHERE id=? AND status='active'", [$serviceId]);
    if (!$service) {
        throw new RuntimeException('This service is not available.');
    }
    $requirements = trim((string) ($_POST['requirements'] ?? ''));
    if (mb_strlen($requirements) < 20) {
        throw new RuntimeException('Please describe your requirements in at least 20 characters.');
    }
    $discount = 0.0;
    $couponCode = strtoupper(trim((string) ($_POST['coupon'] ?? '')));
    if ($couponCode !== '') {
        $coupon = fetch_one('SELECT * FROM coupons WHERE code=? AND active=1 AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)', [$couponCode]);
        if (!$coupon) {
            throw new RuntimeException('Coupon code is invalid or expired.');
        }
        $discount = round((float) $service['price'] * ((int) $coupon['discount_percent'] / 100), 2);
    }
    $paymentMethod = pick_value((string) ($_POST['payment_method'] ?? 'wallet'), ['wallet'], 'wallet');
    $total = max(0.0, (float) $service['price'] - $discount);
    $walletBalance = (float) scalar('SELECT COALESCE(wallet_balance,0) FROM users WHERE id=?', [(int) $user['id']]);
    if ($paymentMethod !== 'wallet') {
        throw new RuntimeException('This checkout currently accepts wallet payments only.');
    }
    if ($walletBalance < $total) {
        throw new RuntimeException('Your wallet balance is not enough for this order. Please top up before checking out.');
    }
    $orderNumber = 'WC-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $dueAt = date('Y-m-d H:i:s', strtotime('+' . (int) $service['delivery_days'] . ' days'));
    db()->beginTransaction();
    try {
        $isDemo = (int) $service['is_demo'];
        $stmt = db()->prepare("INSERT INTO orders (order_number,customer_id,seller_id,service_id,status,requirements,subtotal,discount,total,due_at,is_demo,coupon_code) VALUES (?,?,?,?,'pending',?,?,?,?,?,?,?)");
        $stmt->execute([$orderNumber,(int) $user['id'],(int) $service['seller_id'],$serviceId,$requirements,(float) $service['price'],$discount,$total,$dueAt,$isDemo,$couponCode]);
        $orderId = database_last_insert_id();
        $debit = db()->prepare('UPDATE users SET wallet_balance=wallet_balance-?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND wallet_balance>=?');
        $debit->execute([$total, (int) $user['id'], $total]);
        if ($debit->rowCount() < 1) {
            throw new RuntimeException('Your wallet balance changed before checkout finished. Please try again.');
        }
        $payment = db()->prepare("INSERT INTO payments (order_id,amount,method,status,transaction_ref,is_demo) VALUES (?,?,?,'paid',?,?)");
        $payment->execute([$orderId,$total,'wallet','PAY-' . strtoupper(bin2hex(random_bytes(5))),$isDemo]);
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
    $user = require_auth();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $order = fetch_order_for_user($user, $orderId);
    if (!$order) {
        throw new RuntimeException('Conversation not found.');
    }
    if ($user['role'] === 'seller') {
        ensure_seller_approved($user);
    }
    $body = trim((string) ($_POST['body'] ?? ''));
    $attachment = store_upload('attachment', image_upload_types() + ['application/pdf'=>'pdf','text/plain'=>'txt']);
    if ($body === '' && $attachment === '') throw new RuntimeException('Add a message or attachment before sending.');
    $receiverId = (int) $user['id'] === (int) $order['customer_id'] ? (int) $order['seller_id'] : (int) $order['customer_id'];
    $stmt = db()->prepare('INSERT INTO messages (order_id,sender_id,receiver_id,body,attachment,is_demo) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$orderId,(int) $user['id'],$receiverId,$body,$attachment,(int)$order['is_demo']]);
    notify($receiverId, 'message', 'New message from ' . $user['name'], mb_substr($body ?: 'Sent an attachment', 0, 90), '?page=' . ($user['role'] === 'customer' ? 'seller-messages' : 'messages') . '&order=' . $orderId, (bool)$order['is_demo']);
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
            db()->prepare('UPDATE users SET name=?,phone=?,bio=?,avatar=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,$phone,$bio,$avatar,(int) $user['id']]);
        } catch (Throwable $error) {
            delete_stored_upload($avatar);
            throw $error;
        }
        delete_stored_upload((string) ($user['avatar'] ?? ''));
    } else {
        db()->prepare('UPDATE users SET name=?,phone=?,bio=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,$phone,$bio,(int) $user['id']]);
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
    db()->prepare('UPDATE users SET email_notifications=?,theme=?,language=?,text_scale=?,ui_scale=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$enabled,$theme,$language,$textScale,$uiScale,(int) $user['id']]);
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
    db()->prepare('UPDATE users SET theme=?,language=?,ui_scale=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
        ->execute([$theme, $language, $uiScale, (int) $user['id']]);
    flash('success', 'Admin page appearance saved.');
    redirect_back('?page=admin-control');
}

function action_topup_wallet(): never
{
    $user = require_role('customer');
    $amount = (float) ($_POST['amount'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    if (!stripe_is_configured()) {
        throw new RuntimeException('Stripe PromptPay is not configured yet.');
    }
    $minimum = max(50.0, topup_minimum_setting(50));
    if (!is_finite($amount) || $amount < $minimum || $amount > 1000000) {
        throw new RuntimeException('Please enter a top up amount of at least ' . money($minimum) . '.');
    }
    $requestId = create_payment_request('topup', (int) $user['id'], $amount, 'Wallet top up', ['note' => $note]);
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
    $admin = require_role('admin');
    $transactionId = (int) ($_POST['transaction_id'] ?? 0);
    $decision = pick_value((string) ($_POST['decision'] ?? ''), ['approve', 'reject'], '');
    $transaction = fetch_one('SELECT * FROM wallet_transactions WHERE id=?', [$transactionId]);
    if (!$transaction || (string) $transaction['status'] !== 'pending') {
        throw new RuntimeException('Pending top up request not found.');
    }
    if (trim((string) ($transaction['slip_path'] ?? '')) === '') {
        throw new RuntimeException('Manual review is only available for legacy slip-based top ups.');
    }

    db()->beginTransaction();
    try {
        if ($decision === 'approve') {
            $review = db()->prepare("UPDATE wallet_transactions SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
            $review->execute(['completed', $transactionId]);
            if ($review->rowCount() !== 1) {
                throw new RuntimeException('This top up was already reviewed.');
            }
            db()->prepare('UPDATE users SET wallet_balance=COALESCE(wallet_balance,0)+?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(float) $transaction['amount'], (int) $transaction['user_id']]);
            notify((int) $transaction['user_id'], 'payment', 'Wallet top up approved', 'Your top up of ' . money((float) $transaction['amount']) . ' has been approved.', '?page=topup&tx=' . $transaction['reference'], (bool) $transaction['is_demo']);
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
    if (!password_verify($current, $user['password_hash']) || strlen($new) < 8) {
        throw new RuntimeException('Current password is incorrect or the new password is too short.');
    }
    db()->prepare('UPDATE users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT),(int) $user['id']]);
    db()->prepare('DELETE FROM sessions WHERE user_id=? AND token_hash<>?')->execute([(int) $user['id'], hash('sha256', session_id())]);
    log_security((int) $user['id'], 'password_changed');
    flash('success', 'Password changed successfully.');
    redirect_back('?page=settings');
}

function action_save_service(): never
{
    $user = require_role('seller');
    ensure_seller_approved($user);
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $price = (float) ($_POST['price'] ?? 0);
    $days = max(1, (int) ($_POST['delivery_days'] ?? 7));
    $features = trim((string) ($_POST['features'] ?? ''));
    if (mb_strlen($title) < 5 || mb_strlen($title) > 180 || mb_strlen($description) < 20 || mb_strlen($description) > 10000 || !is_finite($price) || $price < 100 || $price > 1000000 || !$categoryId || $days > 365) {
        throw new RuntimeException('Complete the service title, description, category, and a price of at least ฿100.');
    }
    if (!(bool) scalar('SELECT COUNT(*) FROM categories WHERE id=?', [$categoryId])) {
        throw new RuntimeException('The selected category does not exist.');
    }
    $thumbnail = store_upload('thumbnail', image_upload_types(), 5242880);
    try {
    if ($serviceId) {
        if ($thumbnail !== '') {
            $previousThumbnail = (string) scalar('SELECT thumbnail FROM services WHERE id=? AND seller_id=?', [$serviceId, (int) $user['id']]);
            $stmt = db()->prepare('UPDATE services SET category_id=?,title=?,description=?,price=?,delivery_days=?,features=?,thumbnail=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND seller_id=?');
            $stmt->execute([$categoryId,$title,$description,$price,$days,$features,$thumbnail,$serviceId,(int) $user['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Service not found.');
            }
            delete_stored_upload($previousThumbnail);
        } else {
            $stmt = db()->prepare('UPDATE services SET category_id=?,title=?,description=?,price=?,delivery_days=?,features=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND seller_id=?');
            $stmt->execute([$categoryId,$title,$description,$price,$days,$features,$serviceId,(int) $user['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Service not found.');
            }
        }
        flash('success', 'Service updated.');
    } else {
        $stmt = db()->prepare('INSERT INTO services (seller_id,category_id,title,description,price,delivery_days,features,thumbnail,status,is_demo) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([(int) $user['id'],$categoryId,$title,$description,$price,$days,$features,$thumbnail ?: 'website','pending',(int)$user['is_demo']]);
        $serviceId = database_last_insert_id();
        $admins = fetch_all("SELECT users.id FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name='admin' AND users.status='active'");
        foreach ($admins as $admin) {
            notify((int) $admin['id'], 'service', 'Service approval required', $user['name'] . ' submitted "' . $title . '" for review.', '?page=admin-services', (bool) $user['is_demo']);
        }
        notify((int) $user['id'], 'service', 'Service submitted for review', 'Your new service is waiting for admin approval before it appears in the marketplace.', '?page=seller-services', (bool) $user['is_demo']);
        flash('info', 'Service submitted for admin approval.');
    }
    } catch (Throwable $error) {
        delete_stored_upload($thumbnail);
        throw $error;
    }
    redirect('?page=seller-services');
}

function action_delete_service(): never
{
    $user = require_role('seller');
    ensure_seller_approved($user);
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    if (scalar('SELECT COUNT(*) FROM orders WHERE service_id=?', [$serviceId])) {
        db()->prepare("UPDATE services SET status='paused' WHERE id=? AND seller_id=?")->execute([$serviceId,(int) $user['id']]);
        flash('info', 'This service has order history, so it was paused instead of deleted.');
    } else {
        db()->prepare('DELETE FROM services WHERE id=? AND seller_id=?')->execute([$serviceId,(int) $user['id']]);
        flash('success', 'Service deleted.');
    }
    redirect('?page=seller-services');
}

function action_update_order(): never
{
    $user = require_auth();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $allowed = ['pending','in_progress','review','completed','cancelled'];
    $order = fetch_one('SELECT * FROM orders WHERE id=?', [$orderId]);
    if (!$order || !in_array($status, $allowed, true)) {
        throw new RuntimeException('Order update is invalid.');
    }
    $owns = $user['role'] === 'admin' || ($user['role'] === 'seller' && (int) $order['seller_id'] === (int) $user['id']) || ($user['role'] === 'customer' && (int) $order['customer_id'] === (int) $user['id'] && (($status === 'completed' && $order['status'] === 'review') || ($status === 'cancelled' && $order['status'] === 'pending')));
    if (!$owns) {
        throw new RuntimeException('You cannot update this order.');
    }
    if ($user['role'] === 'seller') {
        ensure_seller_approved($user);
    }
    $transitions = [
        'pending' => ['in_progress', 'cancelled'],
        'in_progress' => ['review', 'cancelled'],
        'review' => ['in_progress', 'completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];
    if (!in_array($status, $transitions[(string) $order['status']] ?? [], true)) {
        throw new RuntimeException('This order status change is not allowed.');
    }
    db()->beginTransaction();
    try {
        $transition = db()->prepare('UPDATE orders SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status=?');
        $transition->execute([$status,$orderId,$order['status']]);
        if ($transition->rowCount() !== 1) {
            throw new RuntimeException('The order changed before your update finished. Please try again.');
        }
        if ($status === 'cancelled' && $order['status'] !== 'cancelled') {
            $payment = fetch_one("SELECT * FROM payments WHERE order_id=? AND status='paid'", [$orderId]);
            $refundReference = 'REFUND-' . (string) $order['order_number'];
            if ($payment && !(bool) scalar('SELECT COUNT(*) FROM wallet_transactions WHERE reference=?', [$refundReference])) {
                db()->prepare("INSERT INTO wallet_transactions (user_id,amount,method,status,reference,note,is_demo) VALUES (?,?,'refund','completed',?,?,?)")->execute([(int) $order['customer_id'],(float) $order['total'],$refundReference,'Refund for cancelled order ' . $order['order_number'],(int) $order['is_demo']]);
                db()->prepare('UPDATE users SET wallet_balance=COALESCE(wallet_balance,0)+?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(float) $order['total'],(int) $order['customer_id']]);
                db()->prepare("UPDATE payments SET status='refunded' WHERE id=?")->execute([(int) $payment['id']]);
                notify((int) $order['customer_id'], 'payment', 'Order payment refunded', money((float) $order['total']) . ' was returned to your wallet.', '?page=orders', (bool) $order['is_demo']);
            }
        }
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
    $recipient = $user['role'] === 'customer' ? (int) $order['seller_id'] : (int) $order['customer_id'];
    notify($recipient, 'order', 'Order status updated', $order['order_number'] . ' is now ' . status_label($status) . '.', '?page=orders', (bool)$order['is_demo']);
    flash('success', 'Order status updated.');
    redirect_back(role_home($user['role']));
}

function action_submit_review(): never
{
    $user = require_role('customer');
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $order = fetch_one("SELECT * FROM orders WHERE id=? AND customer_id=? AND status='completed'", [$orderId,(int) $user['id']]);
    $rating = max(1, min(5, (int) ($_POST['rating'] ?? 5)));
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if (!$order || mb_strlen($comment) < 10) {
        throw new RuntimeException('A completed order and a short review are required.');
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
    if (!(bool) scalar('SELECT COUNT(*) FROM services WHERE id=?', [$serviceId])) {
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
    $admin = require_role('admin');
    $userId = (int) ($_POST['user_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['active','suspended','pending_approval'], true) ? (string) $_POST['status'] : 'active';
    if ($userId === (int) $admin['id']) {
        throw new RuntimeException('You cannot suspend your own account.');
    }
    $member = fetch_one('SELECT users.*,roles.name AS role FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?', [$userId]);
    if (!$member) {
        throw new RuntimeException('User not found.');
    }
    db()->prepare('UPDATE users SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$userId]);
    if ($member['role'] === 'seller' && $member['status'] === 'pending_approval' && in_array($status, ['active', 'suspended'], true)) {
        delete_stored_upload((string) ($member['id_card_front'] ?? ''));
        delete_stored_upload((string) ($member['id_card_back'] ?? ''));
        db()->prepare("UPDATE users SET id_card_number='',id_card_front='',id_card_back='',verification_notes=? WHERE id=?")
            ->execute(['Identity documents removed after verification on ' . date('Y-m-d'), $userId]);
    }
    if ($member['role'] === 'seller' && $member['status'] === 'pending_approval' && $status === 'active') {
        notify($userId, 'account', 'Seller account approved', 'Your seller workspace is now available.', '?page=seller-dashboard');
    }
    if ($member['role'] === 'seller' && $member['status'] === 'pending_approval' && $status === 'suspended') {
        notify($userId, 'account', 'Seller account not approved', 'Your seller request was not approved.', '?page=home');
    }
    flash('success', 'User status updated.');
    redirect('?page=admin-users');
}

function action_admin_service_status(): never
{
    require_role('admin');
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['pending','active','paused','rejected'], true) ? (string) $_POST['status'] : 'paused';
    $service = fetch_one('SELECT services.*, users.name AS seller_name FROM services JOIN users ON users.id=services.seller_id WHERE services.id=?', [$serviceId]);
    if (!$service) {
        throw new RuntimeException('Service not found.');
    }
    db()->prepare('UPDATE services SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$serviceId]);
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
    require_role('admin');
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
    if ($id > 0) {
        db()->prepare('UPDATE categories SET name=?,code=?,color=? WHERE id=?')->execute([$name, $code, $color, $id]);
        flash('success', 'Category updated.');
    } else {
        db()->prepare('INSERT INTO categories (name,code,color) VALUES (?,?,?)')->execute([$name, $code, $color]);
        flash('success', 'Category created.');
    }
    redirect('?page=admin-categories');
}

function action_admin_category_delete(): never
{
    require_role('admin');
    $id = (int) ($_POST['category_id'] ?? 0);
    $category = fetch_one('SELECT * FROM categories WHERE id=?', [$id]);
    if (!$category) {
        throw new RuntimeException('Category not found.');
    }
    if ((int) scalar('SELECT COUNT(*) FROM services WHERE category_id=?', [$id]) > 0) {
        throw new RuntimeException('Delete or move the services inside this category first.');
    }
    db()->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
    flash('success', 'Category removed.');
    redirect('?page=admin-categories');
}

function action_admin_coupon_save(): never
{
    require_role('admin');
    $id = (int) ($_POST['coupon_id'] ?? 0);
    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $discount = max(1, min(90, (int) ($_POST['discount_percent'] ?? 0)));
    $active = isset($_POST['active']) ? 1 : 0;
    $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
    if (mb_strlen($code) < 3) {
        throw new RuntimeException('Coupon code is required.');
    }
    if ($id > 0) {
        db()->prepare('UPDATE coupons SET code=?,discount_percent=?,active=?,expires_at=? WHERE id=?')->execute([$code,$discount,$active,$expiresAt !== '' ? $expiresAt : null,$id]);
        flash('success', 'Coupon updated.');
    } else {
        db()->prepare('INSERT INTO coupons (code,discount_percent,active,expires_at,is_demo) VALUES (?,?,?,?,0)')->execute([$code,$discount,$active,$expiresAt !== '' ? $expiresAt : null]);
        flash('success', 'Coupon created.');
    }
    redirect('?page=admin-coupons');
}

function action_admin_coupon_delete(): never
{
    require_role('admin');
    $id = (int) ($_POST['coupon_id'] ?? 0);
    $coupon = fetch_one('SELECT * FROM coupons WHERE id=?', [$id]);
    if (!$coupon) {
        throw new RuntimeException('Coupon not found.');
    }
    db()->prepare('DELETE FROM coupons WHERE id=?')->execute([$id]);
    flash('success', 'Coupon removed.');
    redirect('?page=admin-coupons');
}

function action_admin_broadcast(): never
{
    require_role('admin');
    $banner = trim((string) ($_POST['announcement_banner'] ?? ''));
    $duration = (int) ($_POST['announcement_banner_duration'] ?? 15);
    if (!in_array($duration, [10, 15, 20, 25, 30], true)) {
        $duration = 15;
    }
    $send = isset($_POST['send_notification']);
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
    require_role('admin');
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
    foreach ($fields as $key => $type) {
        $value = $type === 'toggle' ? (isset($_POST[$key]) ? '1' : '0') : trim((string) ($_POST[$key] ?? ''));
        if ($key === 'payment_mode') {
            $value = 'hosted_promptpay';
        }
        if ($key === 'topup_slip_required') {
            $value = '0';
        }
        set_system_setting($key, $value);
    }
    flash('success', 'System settings saved.');
    redirect('?page=admin-settings');
}
