<?php
$user = auth_user();
$appPrefix = rtrim((string) parse_url((string) config('app.url', ''), PHP_URL_PATH), '/');
$currentUri = (string) ($_SERVER['REQUEST_URI'] ?? '/dashboard');
$backPath = $appPrefix !== '' && str_starts_with($currentUri, $appPrefix) ? (substr($currentUri, strlen($appPrefix)) ?: '/') : $currentUri;

// Avatar: 1-2 chữ cái đầu của full_name
$fullName  = $user['full_name'] ?? '';
$nameParts = array_filter(explode(' ', $fullName));
$initials  = '';
if (count($nameParts) >= 2) {
    $parts    = array_values($nameParts);
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
} elseif ($nameParts !== []) {
    $initials = mb_strtoupper(mb_substr(array_values($nameParts)[0], 0, 2));
}
if ($initials === '') {
    $initials = '?';
}

// Màu avatar theo vai trò
$avatarColors = [
    'bql'      => '#2b59c3',
    'ke_toan'  => '#067647',
    'ky_thuat' => '#b54708',
];
$avatarBg = $avatarColors[$user['role'] ?? ''] ?? '#475467';
?>
<header class="app-header">
    <button class="app-header__toggle" type="button" id="sidebarToggle" aria-label="<?= e(t('topbar.toggle_menu')) ?>">&#9776;</button>

    <div class="app-header__title"><?= e(message_t($title ?? '')) ?></div>

    <div class="app-header__user">
        <div class="hdr-tools no-print">
            <div class="text-size" role="group" aria-label="<?= e(t('a11y.text_size')) ?>">
                <button type="button" data-size="sm" aria-pressed="true" title="<?= e(t('a11y.size_sm')) ?>" aria-label="<?= e(t('a11y.size_sm')) ?>">A</button>
                <button type="button" data-size="lg" aria-pressed="false" title="<?= e(t('a11y.size_lg')) ?>" aria-label="<?= e(t('a11y.size_lg')) ?>">A</button>
                <button type="button" data-size="xl" aria-pressed="false" title="<?= e(t('a11y.size_xl')) ?>" aria-label="<?= e(t('a11y.size_xl')) ?>">A</button>
            </div>
            <a class="help-link" href="<?= e(base_url('/help')) ?>" title="<?= e(t('a11y.help_title')) ?>"><span aria-hidden="true">❓</span><span><?= e(t('a11y.help')) ?></span></a>
        </div>
        <!-- Đổi ngôn ngữ -->
        <details class="lang-switch" data-lang-switch>
            <summary class="lang-switch__toggle" aria-label="<?= e(t('lang.switch')) ?>" title="<?= e(t('lang.label')) ?>">
                <span class="lang-switch__globe" aria-hidden="true">🌐</span>
                <span class="lang-switch__code"><?= e(strtoupper(locale())) ?></span>
                <span class="lang-switch__caret" aria-hidden="true">▾</span>
            </summary>
            <form method="post" action="<?= e(base_url('/locale')) ?>" class="lang-switch__menu">
                <?= csrf_field() ?>
                <input type="hidden" name="back" value="<?= e($backPath) ?>">
                <?php foreach (\App\Core\Lang::SUPPORTED as $code): ?>
                    <button type="submit" name="locale" value="<?= e($code) ?>" lang="<?= e($code) ?>"
                        class="lang-switch__option<?= locale() === $code ? ' is-active' : '' ?>"
                        <?= locale() === $code ? 'aria-current="true"' : '' ?>>
                        <span class="lang-switch__option-code"><?= e(strtoupper($code)) ?></span> <?= e(t('lang.' . $code)) ?>
                    </button>
                <?php endforeach; ?>
            </form>
        </details>

        <!-- User pill: avatar + tên + role + logout -->
        <div class="hdr-user">
            <span class="hdr-user__avatar" style="background:<?= e($avatarBg) ?>" aria-hidden="true"><?= e($initials) ?></span>
            <div class="hdr-user__info">
                <span class="hdr-user__name"><?= e($fullName) ?></span>
                <span class="hdr-user__role"><?= e(isset($user['role']) ? label_of('user_role', $user['role']) : '') ?></span>
            </div>
            <form method="post" action="<?= e(base_url('/logout')) ?>" class="hdr-user__logout">
                <?= csrf_field() ?>
                <button type="submit" class="hdr-user__logout-btn" title="<?= e(t('topbar.logout')) ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    <span class="hdr-user__logout-label"><?= e(t('topbar.logout')) ?></span>
                </button>
            </form>
        </div>
    </div>
</header>
