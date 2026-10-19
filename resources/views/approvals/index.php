<?php
/** @var array $period @var array $drafts @var bool $zeroOnly */
$breadcrumbs = [[t('menu.groups.readings'), null], [t('menu.periods'), '/periods'], [$period['period_code'], '/periods/' . $period['id']], [t('period_show.approve_readings'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.approve_readings_period') ?> <?= e($period['period_code']) ?></h1>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--ghost btn--sm" href="<?= e(url_with('/periods/' . $period['id'] . '/approvals', $zeroOnly ? [] : ['flag' => 'cl0'])) ?>">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 3 2 3l8 9.46V19l4 2V12.46z"/></svg>
            <?= $zeroOnly ? t('lit.show_all') : t('lit.only_consumption_0') ?>
        </a>
    </div>
</div>

<?php if ($period['status'] === 'da_khoa'): ?>
    <div class="notice-box"><?= t('ui.the_period_is_locked_readings_cannot') ?></div>
<?php elseif ($drafts === []): ?>
    <div class="dash-empty-state" style="margin-top:8px">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <p><?= e(t('ui.no_draft_readings_awaiting_approval')) ?></p>
    </div>
<?php else: ?>
    <form method="post" action="<?= e(base_url('/periods/' . $period['id'] . '/approvals')) ?>" class="js-confirm"
          data-confirm-title="<?= t('period_show.approve_readings') ?>"
          data-confirm-message="<?= t('ui.approve_the_selected_readings_readings_edited') ?>"
          data-confirm-button="<?= t('common.actions.approve') ?>">
        <?= csrf_field() ?>

        <!-- Approval action bar -->
        <div class="apv-bar">
            <span class="apv-bar__count">
                <span id="apvSelectedCount">0</span> / <?= count($drafts) ?> readings selected
            </span>
            <button type="submit" class="btn btn--primary apv-bar__btn" id="apvSubmitBtn" disabled>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                <?= e(t('ui.approve_selected_readings')) ?>
            </button>
        </div>

        <!-- Table card -->
        <div class="apv-card">
            <div class="table-wrap apv-table-wrap">
                <table class="data-table apv-table">
                    <thead>
                        <tr>
                            <th class="apv-th-check">
                                <input type="checkbox" data-check-all aria-label="<?= e(t('ui.select_all')) ?>">
                            </th>
                            <th><?= e(t('common.fields.apartment')) ?></th>
                            <th><?= e(t('common.fields.meter')) ?></th>
                            <th><?= e(t('common.fields.utility')) ?></th>
                            <th class="num"><?= e(t('common.fields.prev_reading')) ?></th>
                            <th class="num"><?= e(t('common.fields.curr_reading')) ?></th>
                            <th class="num"><?= e(t('common.fields.consumption')) ?></th>
                            <th><?= e(t('common.fields.source')) ?></th>
                            <th><?= e(t('common.fields.entered_by')) ?></th>
                            <th><?= e(t('common.fields.note')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($drafts as $r): ?>
                        <tr class="apv-row<?= (float) $r['consumption'] == 0.0 ? ' apv-row--zero' : '' ?>">
                            <td class="apv-td-check">
                                <input type="checkbox" name="reading_ids[]" value="<?= (int) $r['id'] ?>" data-check-item class="apv-checkbox">
                                <input type="hidden" name="token_<?= (int) $r['id'] ?>" value="<?= e($r['version_token']) ?>">
                            </td>
                            <td class="apv-td-apt"><?= e($r['building_code'] . ' / ' . $r['apartment_code']) ?></td>
                            <td><a href="<?= e(base_url('/readings/' . $r['id'])) ?>" class="apv-meter-link"><?= e($r['meter_code']) ?></a></td>
                            <td>
                                <?php if ($r['utility_type'] === 'dien'): ?>
                                    <span class="apv-badge apv-badge--elec">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>
                                        <?= e(label_of('utility_type', $r['utility_type'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="apv-badge apv-badge--water">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2C6 10 4 14 4 16a8 8 0 0 0 16 0c0-2-2-6-8-14z"/></svg>
                                        <?= e(label_of('utility_type', $r['utility_type'])) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="num apv-num"><?= e(format_decimal($r['previous_reading'])) ?></td>
                            <td class="num apv-num"><?= e(format_decimal($r['current_reading'])) ?></td>
                            <td class="num apv-num apv-num--consumption">
                                <?= e(format_decimal($r['consumption'])) ?>
                                <?= (float) $r['consumption'] == 0.0 ? '<span class="tag-note" style="margin-left:4px">CL=0</span>' : '' ?>
                            </td>
                            <td class="apv-td-src"><?= e(config_labels('readings.reading_source')[$r['source']] ?? $r['source']) ?></td>
                            <td class="apv-td-user"><?= e($r['entered_by_name'] ?? '—') ?></td>
                            <td class="apv-td-note"><?= e($r['note'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </form>
<?php endif; ?>

<script>
(function () {
    var checkAll  = document.querySelector('[data-check-all]');
    var items     = document.querySelectorAll('[data-check-item]');
    var countEl   = document.getElementById('apvSelectedCount');
    var submitBtn = document.getElementById('apvSubmitBtn');

    function update() {
        var n = 0;
        items.forEach(function (cb) { if (cb.checked) n++; });
        if (countEl)   countEl.textContent = n;
        if (submitBtn) submitBtn.disabled  = (n === 0);
        if (checkAll)  checkAll.indeterminate = (n > 0 && n < items.length);
        if (checkAll && n === items.length && items.length > 0) checkAll.checked = true;
        else if (checkAll && n === 0) checkAll.checked = false;
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            items.forEach(function (cb) { cb.checked = checkAll.checked; });
            update();
        });
    }
    items.forEach(function (cb) { cb.addEventListener('change', update); });
    update();
}());
</script>
