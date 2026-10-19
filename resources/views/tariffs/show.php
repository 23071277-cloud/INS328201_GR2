<?php
/** @var array $tariff (kèm tiers, versions, proposals, edit_block_reason) */
$unit = config('pricing.utility_unit')[$tariff['utility_type']] ?? '';
$seriesLabel = label_of('utility_type', $tariff['utility_type']) . ' · ' . label_of('usage_type', $tariff['usage_type']);
$isLatest = isset($tariff['versions'][0]) && (int) $tariff['versions'][0]['id'] === (int) $tariff['id'];
$breadcrumbs = [[t('menu.groups.pricing'), null], [t('menu.tariffs'), '/tariffs'], ['#' . $tariff['id'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($seriesLabel) ?> — v<?= (int) $tariff['version_no'] ?></h1>
        <p class="page-subtitle"><?= lifecycle_badge($tariff['lifecycle']) ?> <?= t('ui.tariff') ?><?= (int) $tariff['id'] ?><?= t('ui.effective_from') ?> <?= e(format_date($tariff['effective_date'])) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('tariffs.update') && $tariff['edit_block_reason'] === null): ?>
            <a class="action-edit btn btn--ghost" href="<?= e(base_url('/tariffs/' . $tariff['id'] . '/edit')) ?>"><?= t('ui.edit_not_yet_in_effect') ?></a>
        <?php endif; ?>
        <?php if (can('tariffs.create')): ?>
            <a class="btn btn--primary" href="<?= e(url_with('/tariffs/create', ['from' => $tariff['id']])) ?>"><?= t('ui.create_a_new_version_from_this') ?></a>
        <?php endif; ?>
        <?php if (can('tariff_proposals.create') && $isLatest): ?>
            <a class="btn btn--primary" href="<?= e(url_with('/tariff-proposals/create', ['tariff_id' => $tariff['id']])) ?>"><?= t('ui.propose_an_update') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if (can('tariffs.update') && $tariff['edit_block_reason'] !== null): ?>
    <div class="notice-box" style="margin-bottom:16px"><?= e(message_t($tariff['edit_block_reason'])) ?></div>
<?php endif; ?>

<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('common.fields.utility_type') ?></dt><dd><?= e(label_of('utility_type', $tariff['utility_type'])) ?></dd></div>
        <div><dt><?= t('common.fields.usage_type') ?></dt><dd><?= e(label_of('usage_type', $tariff['usage_type'])) ?> — <?= e(config_labels('pricing.tariff_kind')[$tariff['usage_type']] ?? '') ?></dd></div>
        <div><dt><?= t('common.fields.version') ?></dt><dd>v<?= (int) $tariff['version_no'] ?> (#<?= (int) $tariff['id'] ?>)</dd></div>
        <div><dt><?= t('ui.effective_date') ?></dt><dd><?= e(format_date($tariff['effective_date'])) ?></dd></div>
        <div><dt><?= t('ui.vat') ?></dt><dd><?= e(format_percent($tariff['vat_percent'])) ?></dd></div>
        <div><dt><?= t('ui.created_by_approved_by') ?></dt><dd><?= e(($tariff['created_by_name'] ?? '—') . ' / ' . ($tariff['approved_by_name'] ?? '—')) ?></dd></div>
    </dl>
</div>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= $tariff['usage_type'] === 'kinh_doanh' ? t('lit.flat_unit_price') : t('lit.tier_table') ?></h2></div>
    <?php if ($tariff['usage_type'] === 'kinh_doanh'): ?>
        <p class="price-big"><?= e(format_money($tariff['business_unit_price'])) ?> / <?= e($unit) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.tier')) ?></th><th><?= t('ui.consumption_range') ?></th><th><?= t('ui.half_open_interval') ?></th><th class="num"><?= e(t('common.fields.unit_price')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($tariff['tiers'] as $tier): ?>
                    <tr>
                        <td><?= (int) $tier['tier_order'] ?></td>
                        <td><?= e(tier_range($tier['tier_from'], $tier['tier_to'], $unit)) ?></td>
                        <td><code>[<?= e(format_decimal($tier['tier_from'])) ?>, <?= e($tier['tier_to'] === null ? '∞' : format_decimal($tier['tier_to'])) ?>)</code></td>
                        <td class="num"><?= e(format_money($tier['unit_price'])) ?>/<?= e($unit) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= t('ui.version_history') ?> <?= e($seriesLabel) ?></h2></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th><?= e(t('common.fields.version')) ?></th><th><?= e(t('common.fields.effective_from')) ?></th><th>VAT</th><th><?= e(t('common.fields.status')) ?></th><th><?= t('ui.approved_by') ?></th><th class="actions"></th></tr></thead>
            <tbody>
            <?php foreach ($tariff['versions'] as $version): ?>
                <tr class="<?= (int) $version['id'] === (int) $tariff['id'] ? 'row-current' : '' ?>">
                    <td>v<?= (int) $version['version_no'] ?> (#<?= (int) $version['id'] ?>)</td>
                    <td><?= e(format_date($version['effective_date'])) ?></td>
                    <td><?= e(format_percent($version['vat_percent'])) ?></td>
                    <td><?= lifecycle_badge($version['lifecycle']) ?></td>
                    <td><?= e($version['approved_by_name'] ?? '—') ?></td>
                    <td class="actions"><?php if ((int) $version['id'] !== (int) $tariff['id']): ?><a href="<?= e(base_url('/tariffs/' . $version['id'])) ?>">Xem</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (can('tariff_proposals.view') && $tariff['proposals'] !== []): ?>
<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= t('ui.proposals_based_on_this_version') ?></h2></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th><?= t('ui.proposal_2') ?></th><th><?= e(t('common.fields.proposed_effective')) ?></th><th><?= e(t('common.fields.proposer')) ?></th><th><?= e(t('common.fields.sent_at')) ?></th><th><?= e(t('common.fields.status')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($tariff['proposals'] as $p): ?>
                <tr>
                    <td><a href="<?= e(base_url('/tariff-proposals/' . $p['id'])) ?>">#<?= (int) $p['id'] ?></a></td>
                    <td><?= e(format_date($p['effective_date'])) ?></td>
                    <td><?= e($p['proposed_by_name'] ?? '—') ?></td>
                    <td><?= e($p['created_at']) ?></td>
                    <td><?= proposal_badge($p['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
