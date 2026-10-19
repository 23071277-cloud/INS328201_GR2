<?php
/** @var array<int, array{0:string,1:?string}> $breadcrumbs  [nhãn, đường dẫn|null] */
?>
<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(base_url('/dashboard')) ?>"><?= e(t('common.breadcrumb_home')) ?></a>
    <?php foreach ($breadcrumbs as [$crumbLabel, $crumbPath]): ?>
        <span class="breadcrumb__sep">/</span>
        <?php if ($crumbPath !== null): ?>
            <a href="<?= e(base_url($crumbPath)) ?>"><?= e($crumbLabel) ?></a>
        <?php else: ?>
            <span class="breadcrumb__current"><?= e($crumbLabel) ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
