<?php $authPrefs = ui_preferences($user ?? null); ?>
<!doctype html>
<html lang="<?= e($authPrefs['language']) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= htmlspecialchars($title) ?></title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>"><link rel="stylesheet" href="assets/css/apple-ui.css?v=<?= filemtime(__DIR__ . '/../assets/css/apple-ui.css') ?>"></head>
<body class="auth-page apple-shell theme-<?= e($authPrefs['theme']) ?> text-<?= e($authPrefs['text_scale']) ?> ui-<?= e($authPrefs['ui_scale']) ?>" data-page="<?= e($page) ?>" data-theme="<?= e($authPrefs['theme']) ?>" data-language="<?= e($authPrefs['language']) ?>" data-text-scale="<?= e($authPrefs['text_scale']) ?>" data-ui-scale="<?= e($authPrefs['ui_scale']) ?>" data-preference-source="guest">
<a class="skip-link" href="#main-content"><?= e($authPrefs['language'] === 'th' ? 'ข้ามไปเนื้อหาหลัก' : 'Skip to main content') ?></a>
<main class="auth-shell" id="main-content" tabindex="-1">
    <?php
    $isForgot = $page === 'forgot-password';
    $isReset = $page === 'reset-password';
    $isMfa = $page === 'mfa';
    $isLogin = $page === 'login';
    $isRegister = $page === 'register';
    $storyTitle = $isRegister ? 'A calmer way to turn an idea into finished work.' : 'Pick up exactly where the project left off.';
    $storyCopy = $isRegister ? 'Find the right specialist, agree on the work, and keep every milestone in one shared view.' : 'Projects, conversations, payments, and progress stay connected from the first brief to final delivery.';
    ?>
    <a class="brand auth-brand" href="?page=home"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span><span>WorkConnect</span></a>
    <section class="auth-story" aria-labelledby="auth-story-title">
        <span class="auth-story-orb auth-story-orb-a" aria-hidden="true"></span>
        <span class="auth-story-orb auth-story-orb-b" aria-hidden="true"></span>
        <div class="auth-story-copy">
            <span class="auth-story-label"><i aria-hidden="true"></i><?= $isRegister ? 'A better way to begin' : 'Your work, still in motion' ?></span>
            <h2 id="auth-story-title"><?= e($storyTitle) ?></h2>
            <p><?= e($storyCopy) ?></p>
        </div>
        <div class="auth-preview-card" aria-hidden="true">
            <header>
                <span class="auth-live-status"><i></i>Live workspace</span>
                <span>WC · 0248</span>
            </header>
            <div class="auth-preview-title">
                <div>
                    <span><?= $isRegister ? 'Your first project' : 'Website & App' ?></span>
                    <h3><?= $isRegister ? 'Launch something remarkable' : 'Mobile App UI Design' ?></h3>
                </div>
                <strong><?= $isRegister ? 'Ready' : '72%' ?></strong>
            </div>
            <div class="auth-preview-progress"><i></i></div>
            <div class="auth-preview-steps">
                <span class="is-complete"><b>1</b>Brief</span>
                <span class="is-active"><b>2</b><?= $isRegister ? 'Match' : 'Create' ?></span>
                <span><b>3</b>Review</span>
                <span><b>4</b>Done</span>
            </div>
            <footer>
                <div class="auth-preview-avatars"><i>AM</i><i>AP</i><i>+2</i></div>
                <p><strong>One shared view</strong><small>Everyone knows what happens next.</small></p>
                <span><?= icon_svg('messages') ?> 6</span>
            </footer>
        </div>
        <div class="auth-story-notes">
            <span><?= icon_svg('security') ?>Protected payments</span>
            <span><?= icon_svg('messages') ?>Project-linked chat</span>
            <span><?= icon_svg('analytics') ?>Visible progress</span>
        </div>
    </section>
    <section class="auth-card">
        <header class="auth-card-header">
            <span class="auth-kicker"><?= $isMfa ? 'Protected admin access' : ($isForgot || $isReset ? 'Account recovery' : ($isLogin ? 'Welcome back' : 'Start something great')) ?></span>
            <h1><?= $isMfa ? 'Verify your sign in' : ($isForgot ? 'Reset your password' : ($isReset ? 'Choose a new password' : ($isLogin ? 'Sign in to WorkConnect' : 'Create your account'))) ?></h1>
            <p><?= $isMfa ? 'Enter the six-digit code from your authenticator app.' : ($isForgot ? 'Enter your email and we will send a secure reset link.' : ($isReset ? 'Use a strong password you have not used before.' : ($isLogin ? 'Continue to your projects and conversations.' : 'Join as a customer or seller.'))) ?></p>
        </header>
        <?php foreach (pull_flashes() as $flash): ?><div class="flash <?= e($flash['type']) ?>" role="status"><span><?= icon_svg($flash['type'] === 'error' ? 'shield' : 'info') ?></span><p><?= e($flash['message']) ?></p></div><?php endforeach; ?>
        <form class="auth-form" action="" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $isMfa ? 'verify_mfa' : ($isForgot ? 'request_password_reset' : ($isReset ? 'reset_password' : ($page === 'login' ? 'login' : 'register'))) ?>">
            <?php if ($isReset): ?><input type="hidden" name="token" value="<?= e((string) ($_GET['token'] ?? '')) ?>"><?php endif; ?>
            <?php if ($page === 'register'): ?><label>Full name<input type="text" name="name" placeholder="Your full name" required></label><?php endif; ?>
            <?php if (!$isReset && !$isMfa): ?><label>Email<input type="email" name="email" placeholder="name@example.com" required></label><?php endif; ?>
            <?php if ($isMfa): ?><label>Authenticator code<input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" autofocus required></label><?php elseif (!$isForgot): $passwordMinimum = ($isReset || $page === 'register') ? 10 : 8; ?><label><?= $isReset ? 'New password' : 'Password' ?><span class="password-wrap"><input id="password" type="password" name="password" placeholder="<?= $passwordMinimum >= 10 ? 'At least 10 characters with English letters and numbers' : 'Your password' ?>" minlength="<?= $passwordMinimum ?>" autocomplete="<?= $page === 'login' ? 'current-password' : 'new-password' ?>" required><button id="toggle-password" type="button" aria-label="Show password">Show</button></span></label><?php if ($passwordMinimum >= 10): ?><small class="muted auth-password-hint">Use 10+ characters with at least one English letter and one number.</small><?php endif; ?><?php endif; ?>
            <?php if ($isReset): ?><label>Confirm new password<input type="password" name="password_confirmation" placeholder="Type your password again" minlength="10" autocomplete="new-password" required></label><?php endif; ?>
            <?php if ($isRegister): ?><label>Confirm password<input type="password" name="password_confirmation" placeholder="Type your password again" minlength="10" autocomplete="new-password" required></label><fieldset><legend>I want to</legend><div class="role-options"><label><input type="radio" name="role" value="customer" checked><span><i><?= icon_svg('profile') ?></i><b><?= e(t('register_role_customer', ui_language($user ?? null))) ?></b><small>Find and hire trusted specialists</small></span></label><label><input type="radio" name="role" value="seller"><span><i><?= icon_svg('services') ?></i><b><?= e(t('register_role_seller', ui_language($user ?? null))) ?></b><small>Offer services and grow your work</small></span></label></div></fieldset><div class="seller-verification-fields" data-seller-fields hidden><label>Phone number<input type="tel" name="phone" placeholder="08x-xxx-xxxx"></label><label>Date of birth<input type="date" name="birth_date" max="<?= e(date('Y-m-d')) ?>"></label><label class="auth-full">Thai ID card number<input type="text" name="id_card_number" inputmode="numeric" placeholder="1-2345-67890-12-3"></label><label class="auth-full">Thai ID card front<input type="file" name="id_card_front" accept="image/jpeg,image/png,image/webp"></label><small class="muted auth-full">Seller accounts require age 18+, Thai ID verification, and admin approval before going live.</small></div><small class="muted auth-role-note"><?= e(t('register_seller_note', ui_language($user ?? null))) ?></small><?php elseif ($isLogin): ?><div class="form-row"><label class="check-label"><input type="checkbox" name="remember"> Remember me</label><a href="?page=forgot-password">Forgot password?</a></div><?php endif; ?>
            <button class="button button-dark button-full auth-submit" type="submit"><span><?= $isMfa ? 'Verify and continue' : ($isForgot ? 'Send reset link' : ($isReset ? 'Reset password' : ($isLogin ? 'Sign in' : 'Create account'))) ?></span><?= icon_svg('arrow-right') ?></button>
        </form>
        <?php if ($isLogin && demo_mode_enabled() && demo_is_installed()): ?><div class="demo-accounts"><span><b>Explore a demo workspace</b><small>Password <strong>Demo1234!</strong></small></span><button type="button" data-demo-email="customer@workconnect.test">Customer</button><button type="button" data-demo-email="seller@workconnect.test">Seller</button><button type="button" data-demo-email="admin@workconnect.test">Admin</button></div><?php endif; ?>
        <div class="auth-switch"><?= $isMfa ? 'Need to restart?' : ($isForgot || $isReset ? 'Remembered your password?' : ($page === 'login' ? "Don't have an account?" : 'Already a member?')) ?> <a href="?page=<?= $isMfa || $isForgot || $isReset ? 'login' : ($page === 'login' ? 'register' : 'login') ?>"><?= $isMfa ? 'Back to sign in' : ($isForgot || $isReset ? 'Sign in' : ($page === 'login' ? 'Sign up' : 'Sign in')) ?></a></div>
    </section>
    <a class="back-link" href="?page=home"><?= icon_svg('arrow-left') ?>Back to home</a>
</main>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script></body></html>
