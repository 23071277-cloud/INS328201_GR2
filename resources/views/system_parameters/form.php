<?php
/** @var array $p @var array $form */
$d = $p['def'];
$breadcrumbs = [[t('crumbs.system'), null], [t('menu.system_parameters'), '/system-parameters'], [$p['key'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$value = form_value($form, $p['row'], 'config_value');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.edit_parameter') ?> <code><?= e($p['key']) ?></code></h1>
        <p class="page-subtitle"><?= e(message_t($d['label'])) ?> — <?= e(message_t($d['applies'])) ?><?= t('ui.source') ?> <?= e(message_t($d['source'])) ?>.</p>
    </div>
</div>
<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url('/system-parameters/' . $p['row']['id'] . '/update')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="version_token" value="<?= e($p['version_token']) ?>">
        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'config_value') ? 'has-error' : '' ?>"><label for="config_value"><?= t('common.fields.value') ?> <span class="required">*</span></label>
                <?php if ($d['type'] === 'enum'): ?>
                    <select id="config_value" name="config_value"><?php foreach ($d['values'] as $k => $l): ?><option value="<?= e((string) $k) ?>" <?= $value === (string) $k ? 'selected' : '' ?>><?= e(message_t($l)) ?></option><?php endforeach; ?></select>
                <?php elseif ($d['type'] === 'integer'): ?>
                    <input id="config_value" name="config_value" inputmode="numeric" value="<?= e($value) ?>"><p class="form-hint"><?= t('ui.integer_from') ?> <?= (int) $d['min'] ?> <?= t('ui.to') ?> <?= (int) $d['max'] ?>.</p>
                <?php else: ?>
                    <input id="config_value" name="config_value" maxlength="<?= (int) $d['max'] ?>" value="<?= e($value) ?>">
                <?php endif; ?>
                <?= field_error($form, 'config_value') ?></div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><?= e(t('common.actions.save')) ?></button><a class="btn btn--secondary" href="<?= e(base_url('/system-parameters')) ?>"><?= e(t('common.cancel')) ?></a></div>
    </form>
</div>
