<?php
/** @var array $filters @var array $pager */
$breadcrumbs = [[t('crumbs.pricing'), null], [t('page.tariffs'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.tariffs')) ?></h1>
        <p class="page-subtitle"><?= t('ui.fn_03_01_each_row_is') ?> <strong><?= t('ui.version') ?></strong><?= t('ui.creating_a_new_tariff_never_overwrites') ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('tariff_proposals.view')): ?>
            <a class="btn btn--ghost" href="<?= e(base_url('/tariff-proposals')) ?>"><?= t('menu.tariff_proposals') ?></a>
        <?php endif; ?>
        <?php if (can('tariffs.create')): ?>
            <a class="btn btn--primary" href="<?= e(base_url('/tariffs/create')) ?>"><?= e(t('common.actions.add_version')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <form method="get" action="<?= e(base_url('/tariffs')) ?>" class="toolbar">
        <div class="form-group">
            <label for="utility_type"><?= e(t('common.fields.utility')) ?></label>
            <select id="utility_type" name="utility_type">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (master_labels('utility_type') as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['utility_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="usage_type"><?= e(t('common.fields.usage_type')) ?></label>
            <select id="usage_type" name="usage_type">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (master_labels('usage_type') as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['usage_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="lifecycle"><?= e(t('common.fields.status')) ?></label>
            <select id="lifecycle" name="lifecycle">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (config_labels('pricing.tariff_lifecycle') as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['lifecycle'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="effective_from"><?= e(t('common.fields.effective_from_date')) ?></label>
            <input type="date" id="effective_from" name="effective_from" value="<?= e($filters['effective_from']) ?>">
        </div>
        <div class="form-group">
            <label for="effective_to"><?= t('ui.to_date') ?></label>
            <input type="date" id="effective_to" name="effective_to" value="<?= e($filters['effective_to']) ?>">
        </div>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.actions.filter')) ?></button>
        <a class="btn btn--ghost btn--sm" href="<?= e(base_url('/tariffs')) ?>"><?= e(t('common.actions.clear_filters')) ?></a>
    </form>

    <?php if ($pager['data'] === []): ?>
        <div class="empty-state"><div class="empty-state__icon">💰</div><p><?= e(t('landing.tariffs.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th><?= e(t('common.fields.code')) ?></th><th><?= e(t('common.fields.utility')) ?></th><th><?= e(t('common.fields.usage_type')) ?></th><th><?= e(t('common.fields.version')) ?></th><th><?= t('ui.kind') ?></th><th><?= t('ui.price') ?></th><th>VAT</th><th><?= e(t('common.fields.effective_from')) ?></th><th><?= e(t('common.fields.status')) ?></th><th class="actions"><?= e(t('common.fields.actions')) ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($pager['data'] as $row): ?>
                    <tr class="<?= in_array($row['lifecycle'], ['da_thay_the', 'het_hieu_luc'], true) ? 'row-muted' : '' ?>">
                        <td><a href="<?= e(base_url('/tariffs/' . $row['id'])) ?>"><strong>#<?= (int) $row['id'] ?></strong></a></td>
                        <td><?= e(label_of('utility_type', $row['utility_type'])) ?></td>
                        <td><?= e(label_of('usage_type', $row['usage_type'])) ?></td>
                        <td>v<?= (int) $row['version_no'] ?></td>
                        <td><?= e(config_labels('pricing.tariff_kind')[$row['usage_type']] ?? '') ?></td>
                        <td>
                            <?php if ($row['usage_type'] === 'kinh_doanh'): ?>
                                <?= e(format_money($row['business_unit_price'])) ?>/<?= e(config('pricing.utility_unit')[$row['utility_type']] ?? '') ?>
                            <?php else: ?>
                                <?= (int) $row['tier_count'] ?> <?= t('ui.tiers') ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e(format_percent($row['vat_percent'])) ?></td>
                        <td><?= e(format_date($row['effective_date'])) ?></td>
                        <td><?= lifecycle_badge($row['lifecycle']) ?></td>
                        <td class="actions"><a href="<?= e(base_url('/tariffs/' . $row['id'])) ?>"><?= e(t('common.actions.details')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php $basePath = '/tariffs'; require BASE_PATH . '/resources/views/partials/pagination.php'; ?>
</div>
