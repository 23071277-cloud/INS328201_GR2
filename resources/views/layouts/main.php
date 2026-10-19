<?php
/** @var string $content được BaseController::view() gán trước khi require layout này. */
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($title) ? message_t($title) : t('menu.dashboard')) ?> - <?= e(config('app.name')) ?></title>
    <script>try{var s=localStorage.getItem('dssTextSize');if(s==='lg'||s==='xl'){document.documentElement.setAttribute('data-text',s);}}catch(e){}</script>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/accessibility.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php require BASE_PATH . '/resources/views/partials/sidebar.php'; ?>

    <div class="app-main">
        <?php require BASE_PATH . '/resources/views/partials/header.php'; ?>

        <main class="app-content">
            <?php require BASE_PATH . '/resources/views/partials/flash.php'; ?>
            <?= $content ?>
        </main>
    </div>
</div>

<!-- Modal xác nhận dùng chung cho các form có class js-confirm (vd: Ngừng bản ghi) -->
<div class="modal" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle" hidden>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog">
        <h2 class="modal__title" id="confirmModalTitle"><?= e(t('common.confirm')) ?></h2>
        <p class="modal__message" id="confirmModalMessage"></p>
        <div class="modal__actions">
            <button type="button" class="btn btn--secondary" data-modal-close><?= e(t('common.cancel')) ?></button>
            <button type="button" class="btn btn--danger" id="confirmModalOk"><?= e(t('common.confirm')) ?></button>
        </div>
    </div>
</div>
<!-- Thông báo nổi (toast) cho thao tác không tải lại trang -->
<div class="toast-stack" id="toastStack" role="status" aria-live="polite" aria-atomic="false"></div>
<?php if (locale() !== 'vi'): ?><script type="application/json" id="i18nData"><?= json_encode(\App\Core\Lang::jsDictionary(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
