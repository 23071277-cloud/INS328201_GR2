<!doctype html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= t('ui.403_session_expired') ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="error-body">
<div class="error-card">
    <div class="error-card__code">403</div>
    <p class="error-card__message"><?= t('ui.your_session_has_expired_or_the') ?></p>
    <a href="<?= e(base_url('/login')) ?>" class="btn btn--primary"><?= t('ui.back_to_sign_in') ?></a>
</div>
</body>
</html>
