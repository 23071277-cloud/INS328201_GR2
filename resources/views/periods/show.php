<?php
/** @var array $period (kèm stats, pending, active_meters, imports, previous) @var array $chargeCounts @var array $returned @var ?array $blockers @var array $form */
$breadcrumbs = [[t('crumbs.readings'), null], [t('page.periods'), '/periods'], [$period['period_code'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$stats = $period['stats'];
$locked = $period['status'] === 'da_khoa';
$billEmail ??= null;
$billZalo ??= null;
$emailDone = $billEmail !== null && \App\Services\PeriodBillEmailService::isComplete($billEmail);
// UI chi tiết kỳ: danh sách công tơ đang hoạt động (mặc định 5 dòng đầu) + trạng thái chỉ số của kỳ - chỉ hiển thị
$activeMeters = $period['active_meters'] ?? [];
$activeTotal = count($activeMeters);
$previewRows = 5;
$utilityShort = ['dien' => label_t('alerts.utility_label.dien', 'Điện'), 'nuoc' => label_t('alerts.utility_label.nuoc', 'Nước')];
$readingState = static fn (array $m): array => $m['reading_id'] === null
    ? [t('period_show.not_entered'), 'badge--warning']
    : ($m['reading_status'] === 'da_xac_nhan' ? [config_label('readings.reading_status', 'da_xac_nhan'), 'badge--active'] : [config_label('readings.reading_status', 'nhap'), 'badge--muted']);
$allConfirmed = $activeTotal > 0 && count(array_filter($activeMeters, static fn (array $m): bool => $m['reading_status'] === 'da_xac_nhan')) === $activeTotal;
$icon = static fn (string $path): string => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('period_show.heading', ['code' => $period['period_code']])) ?></h1>
        <p class="page-subtitle"><?= period_badge($period['status']) ?> <?= e(format_date($period['start_date'])) ?> – <?= e(format_date($period['end_date'])) ?><?= $period['reading_due_date'] ? ' · ' . e(t('period_show.reading_due')) . ' ' . e(format_date($period['reading_due_date'])) : '' ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (!$locked && can('meter_readings.create')): ?>
            <a class="btn btn--primary" href="<?= e(base_url('/periods/' . $period['id'] . '/readings/create')) ?>"><?= e(t('period_show.enter_readings')) ?></a>
        <?php endif; ?>
        <?php if (!$locked && can('meter_readings.import')): ?>
            <a class="btn btn--ghost" href="<?= e(base_url('/periods/' . $period['id'] . '/imports/create')) ?>"><?= e(t('ui.import_excel')) ?></a>
        <?php endif; ?>
        <?php if (!$locked && can('meter_readings.approve')): ?>
            <a class="btn btn--primary" href="<?= e(base_url('/periods/' . $period['id'] . '/approvals')) ?>"><?= e(t('period_show.approve_readings')) ?> (<?= $stats['draft'] ?>)</a>
        <?php endif; ?>
        <?php if (can('meter_readings.view')): ?>
            <a class="btn btn--ghost" href="<?= e(url_with('/readings', ['period_id' => $period['id']])) ?>"><?= e(t('period_show.lookup_readings')) ?></a>
        <?php endif; ?>
        <?php if (can('billing.view')): ?>
            <?php if ($locked): ?>
                <?php /* Kỳ đã khoá: không tính lại phí - nút hiển thị rõ là không khả dụng; xem bản tính ở khối "Tính phí & chốt kỳ" bên dưới */ ?>
                <span class="btn btn--ghost" role="link" aria-disabled="true" tabindex="0" data-billing-disabled
                      title="<?= e(t('period_show.billing_locked_hint')) ?>" aria-describedby="billingDisabledHint"><?= e(t('period_show.billing_button')) ?></span>
                <span id="billingDisabledHint" class="sr-only"><?= e(t('period_show.billing_locked_hint')) ?></span>
            <?php else: ?>
                <a class="btn btn--ghost" href="<?= e(base_url('/periods/' . $period['id'] . '/billing')) ?>"><?= e(t('period_show.billing_button')) ?></a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($billEmail !== null): ?>
            <?php /* FN-10.03: gửi Gmail phiếu thông báo phí THỦ CÔNG theo kỳ - chỉ kỳ đã khoá; hết thư cần gửi -> "Đã gửi Gmail" */ ?>
            <?php if ($emailDone): ?>
                <span class="btn btn--ghost is-done" data-bill-email-done aria-disabled="true"><?= e(t('period_show.gmail_done')) ?></span>
            <?php elseif (can('email_notifications.send')): ?>
                <form method="post" action="<?= e(base_url('/periods/' . $period['id'] . '/bill-emails/send')) ?>" class="inline-form js-confirm" data-bill-email-send
                      data-confirm-title="<?= e(t('period_show.gmail_send')) ?>" data-confirm-message="<?= e(t('period_show.gmail_confirm', ['code' => $period['period_code']])) ?>" data-confirm-button="<?= e(t('period_show.gmail_send_short')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="back" value="period">
                    <button type="submit" class="btn btn--primary"><?= e(t('period_show.gmail_send')) ?></button>
                </form>
            <?php endif; ?>
            <?php if ($billEmail['failed'] > 0 && can('email_notifications.send')): ?>
                <form method="post" action="<?= e(base_url('/periods/' . $period['id'] . '/bill-emails/retry-failed')) ?>" class="inline-form" data-bill-email-retry>
                    <?= csrf_field() ?><input type="hidden" name="back" value="period">
                    <button type="submit" class="btn btn--danger"><?= e(t('period_show.gmail_retry_failed')) ?> (<?= (int) $billEmail['failed'] ?>)</button>
                </form>
            <?php endif; ?>
            <a class="btn btn--ghost" href="<?= e(base_url('/periods/' . $period['id'] . '/bill-emails')) ?>"><?= e(t('period_show.period_outbox')) ?></a>
        <?php endif; ?>
        <?php if ($billZalo !== null): ?>
            <?php /* Gửi phiếu phí qua Zalo (ZNS) THỦ CÔNG theo kỳ - cùng quy tắc với Gmail */ ?>
            <?php if (\App\Services\PeriodBillEmailService::isComplete($billZalo)): ?>
                <span class="btn btn--ghost is-done" aria-disabled="true"><?= e(t('zalo.all_sent')) ?></span>
            <?php elseif (can('zalo_notifications.send')): ?>
                <form method="post" action="<?= e(base_url('/periods/' . $period['id'] . '/bill-zalo/send')) ?>" class="inline-form js-confirm"
                      data-confirm-title="<?= e(t('zalo.send_to_all')) ?>" data-confirm-message="<?= e(t('zalo.confirm_period_msg', ['period' => $period['period_code']])) ?>" data-confirm-button="<?= e(t('zalo.confirm_btn')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="back" value="period">
                    <button type="submit" class="btn btn--zalo"><?= e(t('zalo.send_to_all')) ?></button>
                </form>
            <?php endif; ?>
            <?php if ($billZalo['failed'] > 0 && can('zalo_notifications.send')): ?>
                <form method="post" action="<?= e(base_url('/periods/' . $period['id'] . '/bill-zalo/retry-failed')) ?>" class="inline-form">
                    <?= csrf_field() ?><input type="hidden" name="back" value="period">
                    <button type="submit" class="btn btn--danger"><?= e(t('zalo.resend_failed')) ?> (<?= (int) $billZalo['failed'] ?>)</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!$locked && can('billing_periods.lock')): ?>
            <button type="button" class="btn btn--danger" data-open-modal="lockModal"><?= e(t('common.actions.lock_period')) ?></button>
        <?php endif; ?>
        <?php if ($locked && can('billing_periods.reopen')): ?>
            <button type="button" class="btn btn--danger" data-open-modal="reopenModal"><?= e(t('common.actions.reopen_period')) ?></button>
        <?php endif; ?>
    </div>
</div>

<?php if ($locked): ?>
    <div class="notice-box" style="margin-bottom:16px"><?= e(t('period_show.locked')) ?><?= $period['locked_at'] ? ' ' . e(t('period_show.at')) . ' ' . e($period['locked_at']) : '' ?> — <?= e(t('period_show.locked_notice')) ?></div>
    <?php if ($billEmail !== null): ?>
        <div class="notice-box" style="margin-bottom:16px" data-bill-email-status>
            <?= e(t('period_show.gmail_summary')) ?>
            <strong data-count="sent"><?= (int) $billEmail['sent'] ?></strong> <?= e(label_t('email.status.sent', 'Đã gửi')) ?> ·
            <strong data-count="failed"><?= (int) $billEmail['failed'] ?></strong> <?= e(label_t('email.status.failed', 'Lỗi')) ?> ·
            <strong data-count="missing"><?= (int) $billEmail['missing'] ?></strong> <?= e(t('period_show.missing_email')) ?> ·
            <strong data-count="pending"><?= (int) $billEmail['pending'] ?></strong> <?= e(t('period_show.not_sent')) ?>
            <?= e(t('period_show.gmail_total', ['n' => (int) $billEmail['total']])) ?>
        </div>
    <?php endif; ?>
<?php elseif ($period['previous'] !== null && $period['previous']['status'] !== 'da_khoa'): ?>
    <div class="notice-box" style="margin-bottom:16px"><?= e(t('period_show.prev_unlocked', ['code' => $period['previous']['period_code']])) ?></div>
<?php endif; ?>
<?php if ($period['status'] === 'mo_lai'): ?>
    <div class="notice-box" style="margin-bottom:16px"><?= e(t('period_show.reopened')) ?><?= $period['reopened_at'] ? ' ' . e(t('period_show.at')) . ' ' . e($period['reopened_at']) : '' ?>. <?= e(t('period_show.reason')) ?> <?= e($period['reopen_reason'] ?? '') ?>. <?= e(t('period_show.reopened_notice')) ?></div>
<?php endif; ?>

<div class="card-grid">
    <div class="stat-card stat-card--icon">
        <span class="stat-card__icon stat-card__icon--primary"><?= $icon('<path d="M12 14l3.5-3.5"/><path d="M3.5 17a9 9 0 1 1 17 0"/><circle cx="12" cy="14" r="1.3"/>') ?></span>
        <div><div class="stat-card__label"><?= e(t('period_show.stat_active')) ?></div><div class="stat-card__value"><?= $stats['active_meters'] ?></div></div>
    </div>
    <div class="stat-card stat-card--icon">
        <span class="stat-card__icon stat-card__icon--warning"><?= $icon('<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>') ?></span>
        <div><div class="stat-card__label"><?= e(t('period_show.stat_pending')) ?></div><div class="stat-card__value"><?= $stats['pending'] ?></div></div>
    </div>
    <div class="stat-card stat-card--icon">
        <span class="stat-card__icon stat-card__icon--neutral"><?= $icon('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>') ?></span>
        <div><div class="stat-card__label"><?= e(t('period_show.stat_draft')) ?></div><div class="stat-card__value"><?= $stats['draft'] ?></div></div>
    </div>
    <div class="stat-card stat-card--icon">
        <span class="stat-card__icon stat-card__icon--success"><?= $icon('<circle cx="12" cy="12" r="8.5"/><path d="M8.5 12.2l2.4 2.4 4.6-4.8"/>') ?></span>
        <div><div class="stat-card__label"><?= e(t('period_show.stat_confirmed')) ?></div><div class="stat-card__value"><?= $stats['confirmed'] ?></div></div>
    </div>
</div>

<div class="panel" data-meter-list data-meter-list-preview="<?= $previewRows ?>">
    <div class="panel__head">
        <div>
            <h2 class="panel__title"><?= e(t('period_show.active_list')) ?> (<?= $activeTotal ?>)</h2>
            <p class="panel__subtitle"><?= e(t('period_show.active_list_hint')) ?></p>
        </div>
        <?php if ($activeTotal > $previewRows): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url_with('/readings', ['period_id' => $period['id']])) ?>" data-meter-list-toggle aria-expanded="false"
               data-label-more="<?= e(t('period_show.show_all', ['n' => $activeTotal])) ?>" data-label-less="<?= e(t('period_show.collapse')) ?>"><?= e(t('period_show.show_all', ['n' => $activeTotal])) ?></a>
        <?php endif; ?>
    </div>
    <?php if ($activeMeters === []): ?>
        <div class="empty-state"><p><?= e(t('period_show.no_active')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th class="num"><?= e(t('common.fields.no')) ?></th><th><?= e(t('common.fields.meter_code')) ?></th><th><?= e(t('common.fields.apartment')) ?></th><th><?= e(t('common.fields.type')) ?></th><th><?= e(t('period_show.reading_status_col')) ?></th><th><?= e(t('common.fields.note')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($activeMeters as $i => $m): [$stateLabel, $stateClass] = $readingState($m); ?>
                    <tr data-meter-row<?= $i >= $previewRows ? ' hidden' : '' ?>>
                        <td class="num"><?= $i + 1 ?></td>
                        <td><?= e($m['meter_code']) ?></td>
                        <td><?= e($m['building_code'] . ' / ' . $m['apartment_code']) ?></td>
                        <td><?= e($utilityShort[$m['utility_type']] ?? (string) $m['utility_type']) ?></td>
                        <td><span class="badge <?= $stateClass ?>"><?= e($stateLabel) ?></span></td>
                        <td><?= e(($m['reading_note'] ?? '') !== '' ? $m['reading_note'] : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="table-note" data-meter-list-summary>
            <?= e(t('period_show.showing')) ?> <span data-meter-list-shown><?= min($previewRows, $activeTotal) ?></span>/<?= $activeTotal ?> <?= e(t('period_show.meters_unit')) ?>.
            <?php if ($allConfirmed): ?><?= e(t('period_show.all_confirmed', ['n' => $activeTotal])) ?><?php endif; ?>
        </p>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel__head">
        <div>
            <h2 class="panel__title"><?= e(t('period_show.pending_list')) ?> (<?= count($period['pending']) ?>)</h2>
            <p class="panel__subtitle"><?= e(t('period_show.pending_hint')) ?></p>
        </div>
    </div>
    <?php if ($period['pending'] === []): ?>
        <div class="empty-state">
            <span class="empty-state__icon empty-state__icon--success"><?= $icon('<circle cx="12" cy="12" r="8.5"/><path d="M8.5 12.2l2.4 2.4 4.6-4.8"/>') ?></span>
            <p><strong><?= e(t('period_show.no_pending')) ?></strong></p>
            <p><?= e(t('period_show.all_have_readings', ['code' => $period['period_code']])) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.apartment')) ?></th><th>Khu</th><th><?= e(t('common.fields.meter_code')) ?></th><th><?= e(t('common.fields.utility')) ?></th><th><?= e(t('common.fields.usage_type')) ?></th><th><?= e(t('common.fields.tag')) ?></th><th class="actions"></th></tr></thead>
                <tbody>
                <?php foreach ($period['pending'] as $m): ?>
                    <tr>
                        <td><?= e($m['building_code'] . ' / ' . $m['apartment_code']) ?></td>
                        <td><?= e($m['zone_code'] ?? '—') ?></td>
                        <td><?= e($m['meter_code']) ?></td>
                        <td><?= e(label_of('utility_type', $m['utility_type'])) ?></td>
                        <td><?= e(label_of('usage_type', $m['usage_type'])) ?></td>
                        <td><?= e($m['display_label'] ?? '—') ?></td>
                        <td class="actions"><?php if (!$locked && can('meter_readings.create')): ?><a href="<?= e(url_with('/periods/' . $period['id'] . '/readings/create', ['meter_id' => $m['id']])) ?>"><?= e(t('period_show.enter')) ?></a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel__head">
        <div>
            <h2 class="panel__title"><?= e(t('period_show.imports')) ?> (<?= count($period['imports']) ?>)</h2>
            <p class="panel__subtitle"><?= e(t('period_show.imports_hint')) ?></p>
        </div>
    </div>
    <?php if ($period['imports'] === []): ?>
        <div class="empty-state"><p><?= e(t('period_show.no_imports')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('period_show.batch')) ?></th><th>File</th><th class="num"><?= e(t('common.fields.row')) ?></th><th class="num"><?= e(t('period_show.valid')) ?></th><th class="num"><?= e(t('common.fields.error')) ?></th><th class="num"><?= e(t('period_show.saved_readings')) ?></th><th><?= e(t('common.fields.status')) ?></th><th><?= e(t('period_show.uploaded_by')) ?></th><th><?= e(t('common.fields.time')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($period['imports'] as $b): ?>
                    <tr>
                        <td><a href="<?= e(base_url('/imports/' . $b['id'])) ?>">#<?= (int) $b['id'] ?></a></td>
                        <td><?= e($b['file_name']) ?></td>
                        <td class="num"><?= (int) $b['total_rows'] ?></td>
                        <td class="num"><?= (int) $b['valid_rows'] ?></td>
                        <td class="num"><?= (int) $b['error_rows'] ?></td>
                        <td class="num"><?= (int) $b['saved_readings'] ?></td>
                        <td><?= import_badge($b['status']) ?></td>
                        <td><?= e($b['uploaded_by_name'] ?? '—') ?></td>
                        <td><?= e($b['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel__head">
        <h2 class="panel__title"><?= e(t('period_show.billing_panel')) ?></h2>
        <?php if ($locked && can('billing.view')): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(base_url('/periods/' . $period['id'] . '/billing')) ?>"><?= e(t('period_show.view_bills')) ?></a>
        <?php endif; ?>
    </div>
    <p>
        <?= e(t('period_show.bills')) ?>
        <?php if ($chargeCounts === []): ?><?= e(t('period_show.none_yet')) ?><?php endif; ?>
        <?php foreach ($chargeCounts as $status => $n): ?><?= charge_badge($status) ?> <?= (int) $n ?> &nbsp;<?php endforeach; ?>
    </p>
    <?php if ($returned !== []): ?>
        <h3 class="section-title"><?= e(t('period_show.returned_title')) ?></h3>
        <ul class="rule-list">
            <?php foreach ($returned as $r): ?><li><?= e($r['building_code'] . ' / ' . $r['apartment_code']) ?> — <?= e($r['rejection_reason'] ?? '') ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($blockers !== null): ?>
        <?php if ($blockers === []): ?>
            <p class="form-hint"><?= e(t('period_show.lockable')) ?></p>
        <?php else: ?>
            <h3 class="section-title"><?= e(t('period_show.not_lockable')) ?></h3>
            <ul class="rule-list"><?php foreach ($blockers as $reason): ?><li><?= e(message_t($reason)) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!$locked && can('billing_periods.lock')): ?>
<div class="modal" id="lockModal" role="dialog" aria-modal="true" aria-labelledby="lockModalTitle" hidden>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog">
        <h2 class="modal__title" id="lockModalTitle"><?= e(t('period_show.lock_title', ['code' => $period['period_code']])) ?></h2>
        <form method="post" action="<?= e(base_url('/periods/' . $period['id'] . '/lock')) ?>">
            <?= csrf_field() ?>
            <p class="modal__message"><?= e(t('period_show.lock_message')) ?></p>
            <?php if (!empty($blockers)): ?>
                <ul class="rule-list"><?php foreach ($blockers as $reason): ?><li><?= e(message_t($reason)) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <p class="form-hint"><?= t('period_show.lock_hint_html') ?></p>
            <div class="modal__actions">
                <button type="button" class="btn btn--secondary" data-modal-close><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn--danger"><?= e(t('common.actions.lock_period')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($locked && can('billing_periods.reopen')): ?>
<div class="modal" id="reopenModal" role="dialog" aria-modal="true" aria-labelledby="reopenModalTitle" <?= has_error($form, 'reopen_reason') || has_error($form, 'confirm_payments') ? '' : 'hidden' ?>>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog">
        <h2 class="modal__title" id="reopenModalTitle"><?= e(t('period_show.reopen_title', ['code' => $period['period_code']])) ?></h2>
        <form method="post" action="<?= e(base_url('/periods/' . $period['id'] . '/reopen')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-group <?= has_error($form, 'reopen_reason') ? 'has-error' : '' ?>">
                <label for="reopen_reason"><?= e(t('period_show.reopen_reason')) ?> <span class="required">*</span></label>
                <textarea id="reopen_reason" name="reopen_reason" rows="4" maxlength="500" required><?= e(form_value($form, null, 'reopen_reason')) ?></textarea>
                <div class="form-hint"><?= t('period_show.reopen_hint_html') ?></div>
                <?= field_error($form, 'reopen_reason') ?>
            </div>
            <?php $paymentsRecorded ??= null; if ($paymentsRecorded !== null && $paymentsRecorded['charges'] > 0): ?>
                <div class="form-summary" data-reopen-payment-warning>
                    <?= t('period_show.payment_warning_html', ['n' => (int) $paymentsRecorded['charges'], 'amount' => e(money_vnd($paymentsRecorded['amount']))]) ?>
                    <?= t('period_show.payment_warning2_html') ?>
                </div>
                <div class="form-group <?= has_error($form, 'confirm_payments') ? 'has-error' : '' ?>">
                    <label><input type="checkbox" name="confirm_payments" value="1" required> <?= e(t('period_show.payment_confirm')) ?></label>
                    <?= field_error($form, 'confirm_payments') ?>
                </div>
            <?php endif; ?>
            <div class="modal__actions">
                <button type="button" class="btn btn--secondary" data-modal-close><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn--danger"><?= e(t('common.actions.reopen_period')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
