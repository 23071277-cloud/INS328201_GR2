<!doctype html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= t('ui.404_page_not_found') ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="error-body">
<div class="error-card">
    <div class="error-card__code">404</div>
    <p class="error-card__message"><?= t('ui.the_requested_page_was_not_found') ?></p>
    <a href="<?= e(base_url('/')) ?>" class="btn btn--primary"><?= e(t('common.actions.home')) ?></a>
</div>
</body>
</html>
