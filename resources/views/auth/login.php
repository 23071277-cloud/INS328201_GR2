<?php
/**
 * Trang đăng nhập (UI). Nghiệp vụ không đổi: POST /login (CSRF, khoá tạm khi sai nhiều lần, session) do AuthController xử lý.
 * Lỗi đăng nhập: flash của server hiển thị trong thẻ [data-login-alert]; có JS thì gửi bằng fetch (không tải lại trang) và
 * chỉ cập nhật thẻ này - thông điệp vẫn là thông điệp nghiệp vụ của server, không lộ lỗi kỹ thuật.
 */
$errors = \App\Core\Flash::get('error');
$infos = array_merge(\App\Core\Flash::get('success'), \App\Core\Flash::get('info'));
?>
<div class="login-scene" aria-hidden="true">
    <div class="login-scene__image">
        <!-- điểm năng lượng phát sáng (vị trí theo % của ảnh nền) -->
        <span class="login-pulse" style="left:6.9%;top:49.5%"></span>
        <span class="login-pulse login-pulse--d1" style="left:24.2%;top:36.6%"></span>
        <span class="login-pulse login-pulse--d2" style="left:77.2%;top:31.6%"></span>
        <span class="login-pulse login-pulse--d3" style="left:93.9%;top:51.3%"></span>
        <span class="login-pulse login-pulse--d1" style="left:87.7%;top:81.4%"></span>
    </div>
    <div class="login-scene__overlay"></div>
</div>

<main class="login-wrap">
    <section class="login-card" aria-labelledby="loginTitle">
        <div class="login-card__logo" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>
        </div>
        <h1 class="login-card__title" id="loginTitle"><?= e(config('app.name')) ?></h1>
        <p class="login-card__subtitle"><?= t('ui.sign_in') ?></p>

        <div class="login-alert" data-login-alert role="alert" aria-live="assertive"<?= $errors === [] ? ' hidden' : '' ?>>
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
            <span data-login-alert-text><?= e(implode(' ', $errors)) ?></span>
        </div>
        <?php foreach ($infos as $msg): ?>
            <div class="login-note" role="status"><?= e(message_t($msg)) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= e(base_url('/login')) ?>" novalidate class="login-form" data-login-form>
            <?= csrf_field() ?>

            <div class="login-field">
                <label for="username"><?= t('ui.username') ?> <span class="required">*</span></label>
                <div class="login-input">
                    <svg class="login-input__icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                    <input type="text" id="username" name="username" value="<?= old('username') ?>" required autofocus autocomplete="username" placeholder="<?= t('ui.enter_username') ?>">
                </div>
            </div>

            <div class="login-field">
                <label for="password"><?= t('ui.password') ?> <span class="required">*</span></label>
                <div class="login-input">
                    <svg class="login-input__icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="<?= t('ui.enter_password') ?>">
                    <button type="button" class="login-input__toggle" data-toggle-password aria-controls="password" aria-pressed="false" aria-label="<?= t('js.show_password') ?>" hidden>
                        <svg class="icon-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A9.8 9.8 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a9.6 9.6 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="login-submit" data-login-submit>
                <svg class="login-submit__icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg>
                <span class="login-submit__spinner" aria-hidden="true"></span>
                <span data-login-submit-text><?= t('js.login') ?></span>
            </button>
        </form>

        <div class="login-demo">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>
            <div style="flex:1">
                <div style="font-weight:600;margin-bottom:6px;color:#1e293b"><?= t('ui.demo_accounts_database_energy_dss_v2') ?></div>
                <div style="display:flex;flex-direction:column;gap:4px;font-size:13px">
                    <div>🏢 <strong>Ban Quản Lý:</strong> <code>bql01</code></div>
                    <div>🔧 <strong>Kỹ Thuật:</strong> <code>kythuat01</code></div>
                    <div>💼 <strong>Kế Toán:</strong> <code>ketoan01</code></div>
                    <div style="margin-top:4px;padding-top:4px;border-top:1px dashed #cbd5e1">🔑 <strong>Mật khẩu demo:</strong> <code>Password@123</code></div>
                </div>
            </div>
        </div>
    </section>
</main>
