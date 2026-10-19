<?php
/** @var array $filters @var array $pager @var int[] $years */
$breadcrumbs = [[t('crumbs.readings'), null], [t('page.periods'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.periods')) ?></h1>
    </div>
    <div class="page-head__actions">
        <?php if (can('billing_periods.create')): ?>
            <a class="btn btn--primary" href="<?= e(base_url('/periods/create')) ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?= e(t('common.actions.new_period')) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filter card -->
<div class="per-filter-card">
    <form method="get" action="<?= e(base_url('/periods')) ?>" class="per-filter-form">
        <div class="per-filter-form__fields">
            <div class="form-group">
                <label for="status"><?= e(t('common.fields.status')) ?></label>
                <select id="status" name="status">
                    <option value=""><?= e(t('common.options.all')) ?></option>
                    <?php foreach (config_labels('readings.period_status') as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="year"><?= e(t('common.fields.year')) ?></label>
                <select id="year" name="year">
                    <option value=""><?= e(t('common.options.all')) ?></option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int) $y ?>" <?= $filters['year'] === (string) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="per-filter-form__actions">
            <button type="submit" class="btn btn--primary per-filter-form__btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                <?= e(t('common.actions.filter')) ?>
            </button>
            <a class="btn btn--secondary per-filter-form__btn" href="<?= e(base_url('/periods')) ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <?= e(t('common.actions.clear_filters')) ?>
            </a>
        </div>
    </form>
</div>

<!-- Table card -->
<?php if ($pager['data'] === []): ?>
    <div class="per-data-card">
        <div class="dash-empty-state">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <p><?= e(t('landing.periods.empty')) ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="per-data-card">
        <div class="table-wrap per-table-wrap">
            <table class="data-table per-table">
                <thead>
                    <tr>
                        <th><?= e(t('common.fields.period')) ?></th>
                        <th><?= e(t('common.fields.from_date')) ?></th>
                        <th><?= e(t('common.fields.to_date')) ?></th>
                        <th><?= e(t('ui.reading_due')) ?></th>
                        <th><?= e(t('common.fields.status')) ?></th>
                        <th class="num"><?= e(t('ui.readings_entered')) ?></th>
                        <th class="num"><?= e(t('ui.draft')) ?></th>
                        <th><?= e(t('common.fields.created_by')) ?></th>
                        <th class="per-th-action"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pager['data'] as $row): ?>
                    <tr class="per-row">
                        <td class="per-td-code">
                            <a href="<?= e(base_url('/periods/' . $row['id'])) ?>" class="per-code-link">
                                <?= e($row['period_code']) ?>
                            </a>
                        </td>
                        <td class="per-td-date"><?= e(format_date($row['start_date'])) ?></td>
                        <td class="per-td-date"><?= e(format_date($row['end_date'])) ?></td>
                        <td class="per-td-date"><?= e(format_date($row['reading_due_date'])) ?></td>
                        <td><?= period_badge($row['status']) ?></td>
                        <td class="num per-td-num">
                            <?php $rc = (int) $row['reading_count']; ?>
                            <span class="per-count<?= $rc === 0 ? ' per-count--zero' : '' ?>"><?= $rc ?></span>
                        </td>
                        <td class="num per-td-num">
                            <?php $dc = (int) $row['draft_count']; ?>
                            <?php if ($dc > 0): ?>
                                <span class="per-draft-badge"><?= $dc ?></span>
                            <?php else: ?>
                                <span class="per-count--zero">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="per-td-user"><?= e($row['created_by_name'] ?? '—') ?></td>
                        <td class="per-td-action">
                            <a href="<?= e(base_url('/periods/' . $row['id'])) ?>" class="per-detail-btn">
                                <?= e(t('common.actions.details')) ?>
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php $basePath = '/periods'; require BASE_PATH . '/resources/views/partials/pagination.php'; ?>
