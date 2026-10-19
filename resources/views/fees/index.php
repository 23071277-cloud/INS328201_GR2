<?php
/** @var array $filters @var array<string, array{current:?array,upcoming:array,history:array}> $groups */
$breadcrumbs = [[t('crumbs.pricing'), null], [t('menu.fees'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$feeTypes = config_labels('pricing.fee_type');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.fees')) ?></h1>
        <p class="page-subtitle"><?= t('ui.fn_03_02_unit_prices_come') ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('fees.create')): ?>
            <a class="btn btn--primary" href="<?= e(url_with('/fees/create', ['fee_type' => $filters['fee_type']])) ?>"><?= e(t('common.actions.add_unit_price')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <form method="get" action="<?= e(base_url('/fees')) ?>" class="toolbar">
        <div class="form-group form-group--grow">
            <label for="fee_type"><?= e(t('common.fields.fee_type')) ?></label>
            <select id="fee_type" name="fee_type">
                <option value=""><?= e(t('common.options.all_fee_types')) ?></option>
                <?php foreach ($feeTypes as $value => $meta): ?>
                    <option value="<?= e($value) ?>" <?= $filters['fee_type'] === $value ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.actions.filter')) ?></button>
        <a class="btn btn--ghost btn--sm" href="<?= e(base_url('/fees')) ?>"><?= e(t('common.actions.clear_filters')) ?></a>
    </form>
</div>

<?php foreach ($groups as $type => $group): ?>
    <?php $meta = $feeTypes[$type]; ?>
    <div class="panel" id="fee-<?= e($type) ?>">
        <div class="panel__head">
            <div>
                <h2 class="panel__title"><?= e($meta['label']) ?></h2>
                <div class="form-hint"><?= e(message_t($meta['rule'])) ?></div>
            </div>
            <div class="page-head__actions">
                <span class="price-tag"><?= $group['current'] !== null ? e(format_money($group['current']['unit_price'])) . ' <small>' . e(message_t($meta['unit'])) . '</small>' : t('lit.em_no_unit_price_in_effect') ?></span>
                <?php if (can('fees.create')): ?>
                    <a class="btn btn--ghost btn--sm" href="<?= e(url_with('/fees/create', ['fee_type' => $type])) ?>"><?= t('ui.new_unit_price') ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($group['history'] === []): ?>
            <div class="empty-state"><p><?= e(t('landing.fees.empty')) ?></p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th><?= e(t('common.fields.code')) ?></th><th class="num"><?= e(t('common.fields.unit_price')) ?></th><th><?= e(t('common.fields.effective_from')) ?></th><th><?= e(t('common.fields.status')) ?></th><th><?= e(t('common.fields.created_by')) ?></th><th class="actions"><?= e(t('common.fields.actions')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($group['history'] as $row): ?>
                        <tr class="<?= in_array($row['lifecycle'], ['da_thay_the', 'het_hieu_luc'], true) ? 'row-muted' : '' ?>">
                            <td>#<?= (int) $row['id'] ?></td>
                            <td class="num"><?= e(format_money($row['unit_price'])) ?> <small><?= e(message_t($meta['unit'])) ?></small></td>
                            <td><?= e(format_date($row['effective_date'])) ?></td>
                            <td><?= lifecycle_badge($row['lifecycle']) ?></td>
                            <td><?= e($row['created_by_name'] ?? '—') ?></td>
                            <td class="actions">
                                <?php if (can('fees.update') && $row['edit_block_reason'] === null): ?>
                                    <a class="action-edit" href="<?= e(base_url('/fees/' . $row['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
