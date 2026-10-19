<?php
/** @var array $filters @var array $pager @var array $latestVersions (chỉ cho người được đề xuất) */
$breadcrumbs = [[t('crumbs.pricing'), null], [t('menu.tariff_proposals'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.tariff_proposals')) ?></h1>
        <p class="page-subtitle"><?= e(t('landing.tariff_proposals.subtitle')) ?></p>
    </div>
</div>

<?php if (can('tariff_proposals.create') && $latestVersions !== []): ?>
    <div class="panel">
        <div class="panel__head"><h2 class="panel__title"><?= t('ui.create_a_proposal_for_tariff') ?></h2></div>
        <div class="choice-grid">
            <?php foreach ($latestVersions as $v): ?>
                <a class="choice-card" href="<?= e(url_with('/tariff-proposals/create', ['tariff_id' => $v['id']])) ?>">
                    <strong><?= e(label_of('utility_type', $v['utility_type'])) ?></strong>
                    <span><?= e(label_of('usage_type', $v['usage_type'])) ?></span>
                    <small><?= t('ui.latest_v') ?><?= (int) $v['version_no'] ?> <?= t('billing_show.from') ?> <?= e(format_date($v['effective_date'])) ?></small>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="form-hint"><?= t('ui.proposals_for_fixed_fees_service_fire') ?></p>
    </div>
<?php endif; ?>

<div class="panel">
    <form method="get" action="<?= e(base_url('/tariff-proposals')) ?>" class="toolbar">
        <div class="form-group">
            <label for="status"><?= e(t('common.fields.status')) ?></label>
            <select id="status" name="status">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (config_labels('pricing.proposal_status') as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="utility_type"><?= e(t('common.fields.utility')) ?></label>
            <select id="utility_type" name="utility_type">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (master_labels('utility_type') as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['utility_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.actions.filter')) ?></button>
        <a class="btn btn--ghost btn--sm" href="<?= e(base_url('/tariff-proposals')) ?>"><?= e(t('common.actions.clear_filters')) ?></a>
    </form>

    <?php if ($pager['data'] === []): ?>
        <div class="empty-state"><div class="empty-state__icon">📝</div><p><?= e(t('landing.tariff_proposals.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.code')) ?></th><th><?= e(t('common.fields.tariff')) ?></th><th><?= e(t('common.fields.proposed_effective')) ?></th><th><?= e(t('common.fields.proposer')) ?></th><th><?= e(t('common.fields.sent_at')) ?></th><th><?= e(t('common.fields.status')) ?></th><th><?= e(t('common.fields.handler')) ?></th><th class="actions"></th></tr></thead>
                <tbody>
                <?php foreach ($pager['data'] as $row): ?>
                    <tr>
                        <td><a href="<?= e(base_url('/tariff-proposals/' . $row['id'])) ?>"><strong>#<?= (int) $row['id'] ?></strong></a></td>
                        <td><?= e(label_of('utility_type', $row['utility_type']) . ' · ' . label_of('usage_type', $row['usage_type'])) ?></td>
                        <td><?= e(format_date($row['effective_date'])) ?></td>
                        <td><?= e($row['proposed_by_name'] ?? '—') ?></td>
                        <td><?= e($row['created_at']) ?></td>
                        <td><?= proposal_badge($row['status']) ?></td>
                        <td><?= e($row['reviewed_by_name'] ?? '—') ?></td>
                        <td class="actions"><a href="<?= e(base_url('/tariff-proposals/' . $row['id'])) ?>"><?= e(t('common.actions.details')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php $basePath = '/tariff-proposals'; require BASE_PATH . '/resources/views/partials/pagination.php'; ?>
</div>
