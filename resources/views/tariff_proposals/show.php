<?php
/** @var array $proposal (kèm data, base, approve_block_reason) @var array $form */
$unit = config('pricing.utility_unit')[$proposal['utility_type']] ?? '';
$isBusiness = $proposal['usage_type'] === 'kinh_doanh';
$isPending = $proposal['status'] === 'cho_duyet';
$canReview = can('tariff_proposals.approve') && $isPending;
$base = $proposal['base'];
$breadcrumbs = [[t('menu.groups.pricing'), null], [t('menu.tariff_proposals'), '/tariff-proposals'], ['#' . $proposal['id'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.proposal') ?><?= (int) $proposal['id'] ?> — <?= e(label_of('utility_type', $proposal['utility_type']) . ' · ' . label_of('usage_type', $proposal['usage_type'])) ?></h1>
        <p class="page-subtitle"><?= proposal_badge($proposal['status']) ?> <?= t('ui.proposed_effective_from') ?> <?= e(format_date($proposal['effective_date'])) ?></p>
    </div>
    <?php if ($canReview): ?>
        <div class="page-head__actions">
            <?php if ($proposal['approve_block_reason'] === null): ?>
                <form method="post" action="<?= e(base_url('/tariff-proposals/' . $proposal['id'] . '/approve')) ?>" class="inline-form js-confirm"
                      data-confirm-title="<?= e(t('lit.approve_proposal_title', ['id' => (int) $proposal['id']])) ?>"
                      data-confirm-message="<?= e(t('lit.approve_proposal_message', ['date' => format_date($proposal['effective_date'])])) ?>"
                      data-confirm-button="<?= t('common.actions.approve') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--primary"><?= e(t('common.actions.approve')) ?></button>
                </form>
            <?php else: ?>
                <button type="button" class="btn btn--primary" disabled title="<?= e(message_t($proposal['approve_block_reason'])) ?>"><?= e(t('common.actions.approve')) ?></button>
            <?php endif; ?>
            <button type="button" class="btn btn--danger" data-open-modal="rejectModal"><?= e(t('common.actions.reject')) ?></button>
        </div>
    <?php endif; ?>
</div>

<?php if ($canReview && $proposal['approve_block_reason'] !== null): ?>
    <div class="notice-box" style="margin-bottom:16px"><?= t('ui.cannot_approve') ?> <?= e(message_t($proposal['approve_block_reason'])) ?></div>
<?php endif; ?>

<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('common.fields.proposer') ?></dt><dd><?= e($proposal['proposed_by_name'] ?? '—') ?></dd></div>
        <div><dt><?= t('common.fields.sent_at') ?></dt><dd><?= e($proposal['created_at']) ?></dd></div>
        <div><dt><?= t('ui.based_on_version') ?></dt><dd><?php if ($base !== null): ?><a href="<?= e(base_url('/tariffs/' . $base['id'])) ?>">v<?= (int) $base['version_no'] ?> (#<?= (int) $base['id'] ?>)</a><?php else: ?>—<?php endif; ?></dd></div>
        <div><dt><?= t('common.fields.handler') ?></dt><dd><?= e($proposal['reviewed_by_name'] ?? '—') ?><?= $proposal['reviewed_at'] ? ' · ' . e($proposal['reviewed_at']) : '' ?></dd></div>
        <div style="grid-column: 1 / -1"><dt><?= t('ui.reason_basis') ?></dt><dd><?= e($proposal['reason'] ?? '—') ?></dd></div>
        <?php if ($proposal['status'] === 'tu_choi'): ?>
            <div style="grid-column: 1 / -1"><dt><?= t('ui.rejection_reason') ?></dt><dd class="text-danger"><?= e($proposal['rejection_reason'] ?? '') ?></dd></div>
        <?php endif; ?>
    </dl>
</div>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= t('ui.current_proposed_comparison') ?></h2></div>
    <div class="compare-grid">
        <div>
            <h3 class="section-title"><?= t('ui.original_version') ?><?= $base !== null ? ' (v' . (int) $base['version_no'] . ')' : '' ?></h3>
            <p>VAT: <strong><?= e(format_percent($base['vat_percent'] ?? null)) ?></strong></p>
            <?php if ($isBusiness): ?>
                <p class="price-big"><?= e(format_money($base['business_unit_price'] ?? 0)) ?>/<?= e($unit) ?></p>
            <?php else: ?>
                <table class="data-table"><thead><tr><th><?= e(t('common.fields.range')) ?></th><th class="num"><?= e(t('common.fields.unit_price')) ?></th></tr></thead><tbody>
                <?php foreach ($base['tiers'] ?? [] as $t): ?>
                    <tr><td><?= e(tier_range($t['tier_from'], $t['tier_to'], $unit)) ?></td><td class="num"><?= e(format_money($t['unit_price'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <div>
            <h3 class="section-title"><?= t('ui.proposed_effective') ?> <?= e(format_date($proposal['effective_date'])) ?>)</h3>
            <p>VAT: <strong><?= e(format_percent($proposal['data']['vat_percent'])) ?></strong></p>
            <?php if ($isBusiness): ?>
                <p class="price-big"><?= e(format_money($proposal['data']['business_unit_price'])) ?>/<?= e($unit) ?></p>
            <?php else: ?>
                <table class="data-table"><thead><tr><th><?= e(t('common.fields.range')) ?></th><th class="num"><?= e(t('common.fields.unit_price')) ?></th></tr></thead><tbody>
                <?php foreach ($proposal['data']['tiers'] as $i => $t): ?>
                    <?php $old = $base['tiers'][$i] ?? null; $changed = $old === null || (string) $old['unit_price'] !== (string) $t['unit_price'] || (string) $old['tier_to'] !== (string) $t['tier_to']; ?>
                    <tr class="<?= $changed ? 'row-changed' : '' ?>"><td><?= e(tier_range($t['tier_from'], $t['tier_to'], $unit)) ?></td><td class="num"><?= e(format_money($t['unit_price'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($canReview): ?>
<div class="modal" id="rejectModal" role="dialog" aria-modal="true" aria-labelledby="rejectModalTitle" <?= has_error($form, 'rejection_reason') ? '' : 'hidden' ?>>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog">
        <h2 class="modal__title" id="rejectModalTitle"><?= t('ui.reject_proposal') ?><?= (int) $proposal['id'] ?></h2>
        <form method="post" action="<?= e(base_url('/tariff-proposals/' . $proposal['id'] . '/reject')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-group <?= has_error($form, 'rejection_reason') ? 'has-error' : '' ?>">
                <label for="rejection_reason"><?= t('ui.rejection_reason') ?> <span class="required">*</span></label>
                <textarea id="rejection_reason" name="rejection_reason" rows="4" maxlength="500" required><?= e(form_value($form, null, 'rejection_reason')) ?></textarea>
                <div class="form-hint"><?= t('ui.required_val_03_06_the_current') ?></div>
                <?= field_error($form, 'rejection_reason') ?>
            </div>
            <div class="modal__actions">
                <button type="button" class="btn btn--secondary" data-modal-close><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn--danger"><?= e(t('common.actions.reject')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
