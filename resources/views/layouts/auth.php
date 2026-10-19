<?php
/** @var string $content được BaseController::view() gán trước khi require layout này. */
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($title) ? message_t($title) : t('js.login')) ?> - <?= e(config('app.name')) ?></title>
    <script>try{var s=localStorage.getItem('dssTextSize');if(s==='lg'||s==='xl'){document.documentElement.setAttribute('data-text',s);}}catch(e){}</script>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/accessibility.css')) ?>">
</head>
<body class="auth-body">
<?= $content ?>
<?php if (locale() !== 'vi'): ?><script type="application/json" id="i18nData"><?= json_encode(\App\Core\Lang::jsDictionary(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
