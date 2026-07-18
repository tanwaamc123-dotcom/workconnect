<?php $lang = ui_language($user ?? null); ?>
<section class="hero">
    <div class="container hero-grid">
        <div class="hero-copy">
            <span class="eyebrow"><b></b><?= e($lang === 'th' ? 'ระบบกำลังปรับปรุง' : 'Maintenance mode') ?></span>
            <h1><?= e($lang === 'th' ? 'WorkConnect กำลังปิดปรับปรุงชั่วคราว' : 'WorkConnect is temporarily unavailable') ?></h1>
            <p><?= e($lang === 'th' ? 'แอดมินกำลังอัปเดตระบบเพื่อให้กลับมาเสถียรขึ้น กรุณาลองใหม่อีกครั้งในอีกสักครู่' : 'An administrator is updating the platform. Please check back in a few minutes.') ?></p>
            <div class="row-actions">
                <a class="button button-dark" href="?page=login"><?= e($lang === 'th' ? 'เข้าสู่ระบบแอดมิน' : 'Admin sign in') ?></a>
                <a class="button button-light" href="?page=home"><?= e($lang === 'th' ? 'กลับหน้าแรก' : 'Back to home') ?></a>
            </div>
        </div>
        <div class="hero-media">
            <div class="hero-float hero-float-a">
                <strong><?= e($lang === 'th' ? 'ปรับปรุงความเสถียร' : 'Stability update') ?></strong>
                <small><?= e($lang === 'th' ? 'ระบบจะกลับมาออนไลน์หลังจากตรวจสอบเสร็จ' : 'The site will return after checks complete.') ?></small>
            </div>
            <div class="hero-float hero-float-b">
                <strong><?= e($lang === 'th' ? 'ขออภัยในความไม่สะดวก' : 'Thanks for your patience') ?></strong>
                <small><?= e($lang === 'th' ? 'ข้อมูลเดิมยังถูกเก็บไว้ตามปกติ' : 'Existing data remains intact.') ?></small>
            </div>
        </div>
    </div>
</section>
