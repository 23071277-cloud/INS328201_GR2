<?php
/** @var array $d chi tiết từ AlertService::detail @var array $form */
$a = $d['alert'];
$breadcrumbs = [[t('crumbs.alerts_fn07'), null], [t('alert_show.list'), '/alerts'], ['#' . $a['id'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$status = $a['status'];
$snap = $d['rule_snapshot'];
$unit = $snap !== null && $snap['measure_type'] === 'chi_phi' ? 'đ' : (config('pricing.utility_unit')[$a['utility_type']] ?? '');
$open = $d['open_case'];
$me = (int) (auth_user()['id'] ?? 0);
$token = $a['version_token'];
$can = fn (string $action) => \App\Services\AlertStateMachine::can($action, $status);
$diff = null;
if ($a['actual_value'] !== null && $a['compare_value'] !== null) {
    $diff = bcsub((string) $a['actual_value'], (string) $a['compare_value'], 4);
}
$modal = function (string $id, string $title, string $action, string $body, string $button, string $btnClass = 'btn--primary', array $errorFields = []) use ($form, $token): string {
    $show = false;
    foreach ($errorFields as $f) {
        $show = $show || has_error($form, $f);
    }

    return '<div class="modal" id="' . $id . '" role="dialog" aria-modal="true" aria-labelledby="' . $id . 'Title"' . ($show ? '' : ' hidden') . '>'
        . '<div class="modal__backdrop" data-modal-close></div><div class="modal__dialog"><h2 class="modal__title" id="' . $id . 'Title">' . e($title) . '</h2>'
        . '<form method="post" action="' . e(base_url($action)) . '" novalidate>' . csrf_field()
        . '<input type="hidden" name="version_token" value="' . e($token) . '">' . $body
        . '<div class="modal__actions"><button type="button" class="btn btn--secondary" data-modal-close>' . e(t('common.cancel')) . '</button><button type="submit" class="btn ' . $btnClass . '">' . e($button) . '</button></div></form></div></div>';
};
$prioritySelect = function (string $current) use ($form): string {
    $sel = form_value($form, null, 'priority', $current);
    $html = '<div class="form-group ' . (has_error($form, 'priority') ? 'has-error' : '') . '"><label>' . e(t('alert_show.priority_label')) . '</label><select name="priority">';
    foreach (config_labels('alerts.priority') as $k => $l) {
        $html .= '<option value="' . e($k) . '"' . ($sel === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }

    return $html . '</select>' . field_error($form, 'priority') . '</div>';
};
$textarea = fn (string $name, string $label, bool $required, string $default = '') => '<div class="form-group ' . (has_error($form, $name) ? 'has-error' : '') . '"><label for="m_' . $name . '">' . e($label) . ($required ? ' <span class="required">*</span>' : '') . '</label><textarea id="m_' . $name . '" name="' . $name . '" rows="3" maxlength="500">' . e(form_value($form, null, $name, $default)) . '</textarea>' . field_error($form, $name) . '</div>';
$isJ16 = !empty($d['j16']); //
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('alert_show.heading', ['id' => (int) $a['id']])) ?> — <?= e($a['building_code'] . ' / ' . $a['apartment_code']) ?><?= $a['meter_code'] ? ' / ' . e($a['meter_code']) : '' ?></h1>
        <p class="page-subtitle"><?= alert_status_badge($status) ?> <?= alert_priority_badge($a['priority']) ?> <?= e(config_labels('alerts.source')[$a['source']]) ?><?= $isJ16 ? ' · ' . e(label_t('j16.label', (string) config('j16.label'))) : '' ?> · <?= e(\App\Services\NoUsageConsumptionService::utilityLabel($a)) ?> · <?= e(t('alert_show.period', ['code' => $a['period_code']])) ?> · <?= e(t('alert_show.created', ['at' => $a['created_at']])) ?> · <?= e(t('alert_show.open_days', ['n' => (int) $a['days_open']])) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('alerts.review') && $can('review')): ?><button type="button" class="btn btn--primary" data-open-modal="reviewModal">Review</button><?php endif; ?>
        <?php if (can('alerts.review') && in_array($status, \App\Services\AlertStateMachine::PRIORITY_EDITABLE, true)): ?><button type="button" class="btn asd-priority-btn" data-open-modal="priorityModal"><?= e(t('alert_show.change_priority')) ?></button><?php endif; ?>
        <?php if (can('alerts.request_verification') && $can('request_verification')): ?><button type="button" class="btn btn--primary" data-open-modal="requestModal"><?= e(t('alert_show.request_check')) ?></button><?php endif; ?>
        <?php if (can('alerts.close') && $can('close')): ?><button type="button" class="btn btn--danger" data-open-modal="closeModal"><?= e(t('common.actions.close')) ?></button><?php endif; ?>
        <?php if (can('alerts.close') && $can('reopen')): ?><button type="button" class="btn btn--danger" data-open-modal="reopenModal"><?= e(t('common.actions.reopen')) ?></button><?php endif; ?>
    </div>
</div>

<?php if ($isJ16): ?>
    <?php require BASE_PATH . '/resources/views/alerts/_j16.php'; ?>
<?php else: ?>
<div class="panel asd-card">
    <div class="asd-card__head">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <h2 class="asd-card__title"><?= e(t('alert_show.why_title')) ?></h2>
    </div>
    <?php if ($a['source'] === 'thu_cong'): ?>
        <dl class="detail-grid">
            <div style="grid-column:1/-1"><dt><?= e(t('alert_show.tech_description')) ?></dt><dd><?= e($a['description'] ?? '') ?></dd></div>
            <div><dt><?= e(t('common.fields.created_by')) ?></dt><dd><?= e($a['created_by_name'] ?? '—') ?></dd></div>
        </dl>
    <?php else: ?>
        <div class="asd-metrics">
            <div class="asd-metric">
                <div class="asd-metric__label"><?= e(t('alert_show.actual')) ?></div>
                <div class="asd-metric__value"><?= $a['actual_value'] === null ? '—' : e(format_decimal($a['actual_value'])) ?></div>
                <?php if ($a['actual_value'] !== null): ?><div class="asd-metric__unit"><?= e($unit) ?></div><?php endif; ?>
            </div>
            <div class="asd-metric">
                <div class="asd-metric__label"><?= e($snap !== null && $snap['compare_base'] === 'gia_tri_tuyet_doi' ? t('alert_show.abs_threshold') : ($snap !== null && $snap['compare_base'] === 'ky_truoc' ? t('alert_show.previous_period') : 'Baseline')) ?></div>
                <div class="asd-metric__value"><?= $a['compare_value'] === null ? '—' : e(format_decimal($a['compare_value'])) ?></div>
                <?php if ($a['compare_value'] !== null): ?><div class="asd-metric__unit"><?= e($unit) ?></div><?php endif; ?>
            </div>
            <div class="asd-metric">
                <div class="asd-metric__label"><?= e(t('common.fields.difference')) ?></div>
                <div class="asd-metric__value"><?= $diff === null ? '—' : e(format_decimal($diff)) ?></div>
                <?php if ($diff !== null): ?><div class="asd-metric__unit"><?= e($unit) ?></div><?php endif; ?>
            </div>
            <div class="asd-metric">
                <div class="asd-metric__label"><?= e(t('common.fields.change_pct')) ?></div>
                <div class="asd-metric__value">
                    <?php if ($a['percent_change'] === null): ?>
                        <span class="asd-metric__na">N/A</span>
                    <?php else: ?>
                        <?php $pv = (float) $a['percent_change']; ?>
                        <span class="asd-pct-badge <?= $pv > 0 ? 'asd-pct-badge--up' : ($pv < 0 ? 'asd-pct-badge--down' : 'asd-pct-badge--eq') ?>">
                            <?= $pv > 0 ? '▲' : ($pv < 0 ? '▼' : '=') ?> <?= e(pct_text($a['percent_change'])) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <dl class="detail-grid" style="margin-top:14px">
            <div><dt><?= e(t('alert_show.threshold_at')) ?></dt><dd><?= $snap === null ? '—' : e(config('alerts.operator')[$snap['operator']] . ' ' . \App\Services\RuleConditionEvaluator::plain((string) $snap['threshold_value']) . ($snap['compare_base'] === 'gia_tri_tuyet_doi' ? ' ' . $unit : '%')) ?></dd></div>
            <div><dt><?= e(t('common.fields.rule')) ?></dt><dd><?php if ($a['alert_rule_id']): ?><?php if (can('alert_rules.view')): ?><a href="<?= e(base_url('/alert-rules/' . $a['alert_rule_id'])) ?>">#<?= (int) $a['alert_rule_id'] ?></a><?php else: ?>#<?= (int) $a['alert_rule_id'] ?><?php endif; ?> <?= e($snap['name'] ?? $a['rule_name'] ?? '') ?><?= $a['rule_status'] === 'tat' ? '' : '' ?><?php else: ?>—<?php endif; ?></dd></div>
            <div style="grid-column:1/-1"><dt><?= e(t('alert_show.explanation')) ?></dt><dd><?= e($a['description'] ?? '') ?></dd></div>
        </dl>
    <?php endif; ?>
</div>

<?php $c = $d['comparison_now']; ?>
<?php if ($c !== null): ?>
<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= e(t('alert_show.comparison')) ?></h2></div>
    <dl class="detail-grid">
        <div><dt><?= e(t('common.fields.current')) ?></dt><dd><?= $c['current_value'] === null ? e(config_labels('analytics.data_status')[$c['data_status']] ?? '—') : e(format_decimal($c['current_value'])) ?></dd></div>
        <div><dt><?= e(t('alert_show.previous_code', ['code' => $c['previous_period_code'] ?? '—'])) ?></dt><dd><?= $c['previous_value'] === null ? '—' : e(format_decimal($c['previous_value'])) . ' (' . e(pct_text($c['previous_percentage_change'])) . ')' ?></dd></div>
        <div><dt><?= e(t('crumbs.baseline')) ?></dt><dd><?= $c['baseline_value'] === null ? '—' : e(format_decimal($c['baseline_value'])) . ' (' . e(pct_text($c['percentage_change'])) . ')' ?></dd></div>
        <div><dt><?= e(t('alert_show.trend')) ?></dt><dd><?= e(trend_text($c['trend'])) ?></dd></div>
    </dl>
    <?php if ($c['message']): ?><p class="form-hint"><?= e(message_t($c['message'])) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<div class="panel asd-card">
    <div class="asd-card__head">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        <h2 class="asd-card__title"><?= e(t('alert_show.recheck')) ?></h2>
    </div>
    <?php if ($d['cases'] === []): ?>
        <div class="dash-empty-state">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <p><?= e(t('alert_show.no_recheck')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap asd-table-wrap"><table class="data-table asd-table">
            <thead><tr><th>Case</th><th><?= e(t('common.fields.content')) ?></th><th><?= e(t('alert_show.assigned_to')) ?></th><th><?= e(t('common.fields.status')) ?></th><th><?= e(t('alert_show.conclusion')) ?></th><th class="num"><?= e(t('alert_show.reread')) ?></th><th><?= e(t('common.fields.note')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($d['cases'] as $k): ?>
                <tr class="asd-tr"><td>#<?= (int) $k['id'] ?></td><td><?= e($k['content']) ?></td><td><?= e($k['assigned_to_name'] ?? '') ?></td><td><?= e(config_labels('alerts.case_status')[$k['status']]) ?></td>
                    <td><?= $k['result_conclusion'] ? e(config_labels('alerts.conclusion')[$k['result_conclusion']]) : '—' ?></td><td class="num"><?= $k['rechecked_value'] === null ? '—' : e((string) (float) $k['rechecked_value']) ?></td><td><?= e($k['note'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

    <?php if ($open !== null && $open['status'] === 'da_giao' && can('alerts.update_verification') && (int) $open['assigned_to'] === $me): ?>
        <h3 class="section-title"><?= e(t('alert_show.update_result', ['id' => (int) $open['id']])) ?></h3>
        <form method="post" action="<?= e(base_url('/alerts/' . $a['id'] . '/verification-result')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="form-group <?= has_error($form, 'result_conclusion') ? 'has-error' : '' ?>"><label for="result_conclusion"><?= e(t('alert_show.conclusion')) ?> <span class="required">*</span></label>
                    <select id="result_conclusion" name="result_conclusion"><option value=""><?= e(t('common.options.select')) ?></option><?php foreach (config_labels('alerts.conclusion') as $k => $l): ?><option value="<?= e($k) ?>" <?= form_value($form, null, 'result_conclusion') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= field_error($form, 'result_conclusion') ?></div>
                <div class="form-group <?= has_error($form, 'rechecked_value') ? 'has-error' : '' ?>"><label for="rechecked_value"><?= e(t('alert_show.reread_optional')) ?></label><input id="rechecked_value" name="rechecked_value" inputmode="decimal" value="<?= e(form_value($form, null, 'rechecked_value')) ?>"><?= field_error($form, 'rechecked_value') ?></div>
                <div class="form-group <?= has_error($form, 'note') ? 'has-error' : '' ?>" style="grid-column:1/-1"><label for="result_note"><?= e(t('alert_show.short_note')) ?></label><textarea id="result_note" name="note" rows="2" maxlength="500"><?= e(form_value($form, null, 'note')) ?></textarea><?= field_error($form, 'note') ?></div>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><?= e(t('alert_show.send_result')) ?></button></div>
            <p class="form-hint"><?= e(t('alert_show.tech_no_close')) ?></p>
        </form>
    <?php endif; ?>

    <?php if ($open !== null && $open['status'] === 'da_co_ket_qua' && can('alerts.confirm') && $can('confirm')): ?>
        <?php $r = $d['reading']; $locked = $r !== null && $r['period_status'] === 'da_khoa'; ?>
        <h3 class="section-title"><?= e(t('alert_show.confirm_adjust')) ?></h3>
        <form method="post" action="<?= e(base_url('/alerts/' . $a['id'] . '/confirm')) ?>" novalidate class="js-confirm" data-confirm-title="<?= e(t('alert_show.conclude_title')) ?>" data-confirm-message="<?= e(t('alert_show.conclude_message')) ?>" data-confirm-button="<?= e(t('common.actions.save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="version_token" value="<?= e($token) ?>">
            <?php if ($r !== null): ?><input type="hidden" name="reading_token" value="<?= e($r['version_token']) ?>"><?php endif; ?>
            <div class="form-grid">
                <div class="form-group <?= has_error($form, 'decision') ? 'has-error' : '' ?>"><label><?= e(t('alert_show.decision')) ?> <span class="required">*</span></label>
                    <label class="checkbox-line"><input type="radio" name="decision" value="confirm" <?= form_value($form, null, 'decision', 'confirm') === 'confirm' ? 'checked' : '' ?>> <?= e(t('alert_show.decision_confirm')) ?></label>
                    <label class="checkbox-line"><input type="radio" name="decision" value="adjust" <?= form_value($form, null, 'decision') === 'adjust' ? 'checked' : '' ?> <?= $r === null || $locked ? 'disabled' : '' ?>> <?= e(t('alert_show.decision_adjust')) ?></label>
                    <?= field_error($form, 'decision') ?></div>
                <?php if ($r !== null): ?>
                   <div class="form-group <?= has_error($form, 'current_reading') ? 'has-error' : '' ?>">
    <label for="current_reading"><?= e(t('alert_show.new_csc', [
        'current' => (string) (float) $r['current_reading'],
        'previous' => (string) (float) $r['previous_reading']
    ])) ?></label>
                        <input id="current_reading" name="current_reading" inputmode="decimal" value="<?= e(form_value($form, null, 'current_reading', format_input($open['rechecked_value'] ?? ''))) ?>" <?= $locked ? 'disabled' : '' ?>><?= field_error($form, 'current_reading') ?></div>
                <?php endif; ?>
                <div class="form-group <?= has_error($form, 'note') ? 'has-error' : '' ?>" style="grid-column:1/-1"><label for="confirm_note"><?= e(t('alert_show.adjust_note')) ?></label><textarea id="confirm_note" name="note" rows="2" maxlength="500"><?= e(form_value($form, null, 'note')) ?></textarea><?= field_error($form, 'note') ?></div>
            </div>
            <?php if ($r === null): ?><p class="form-hint"><?= e(t('alert_show.no_reading')) ?></p><?php endif; ?>
            <?php if ($locked): ?><div class="notice-box"><?= e(t('alert_show.period_locked', ['code' => $a['period_code']])) ?></div><?php endif; ?>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><?= e(t('alert_show.save_conclusion')) ?></button></div>
        </form>
    <?php endif; ?>
</div>

<?php endif; ?>

<div class="panel asd-card">
    <div class="asd-card__head">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="12 8 12 12 14 14"/><path d="M3.05 11a9 9 0 1 1 .5 4"/><polyline points="3 16 3 11 8 11"/></svg>
        <h2 class="asd-card__title"><?= e($isJ16 ? t('alert_show.timeline_j16') : t('alert_show.history')) ?></h2>
    </div>
    <div class="table-wrap asd-table-wrap"><table class="data-table asd-table">
        <thead><tr><th><?= e(t('common.fields.time')) ?></th><th><?= e(t('common.fields.person')) ?></th><th><?= e(t('common.fields.status')) ?></th><th><?= e(t('common.fields.priority')) ?></th><th><?= e(t('common.fields.note')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($d['history'] as $h): ?>
            <tr class="asd-tr"><td class="asd-td-ts"><?= e($h['changed_at']) ?></td><td><?= e($h['changed_by_name'] ?? t('alert_show.system')) ?></td>
                <td><?= e(($h['old_status'] ? (config_labels('alerts.status')[$h['old_status']] ?? $h['old_status']) . ' → ' : '') . (config_labels('alerts.status')[$h['new_status']] ?? $h['new_status'])) ?></td>
                <td><?= $h['new_priority'] ? e(($h['old_priority'] ? config_labels('alerts.priority')[$h['old_priority']] . ' → ' : '') . config_labels('alerts.priority')[$h['new_priority']]) : '—' ?></td>
                <td><?= e($h['note'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($a['close_reason']): ?><p class="form-hint" style="margin-top:10px"><?= e(t('alert_show.close_reason')) ?> <?= e($a['close_reason']) ?></p><?php endif; ?>
    <?php if ($a['reopen_reason']): ?><p class="form-hint"><?= e(t('alert_show.last_reopen_reason')) ?> <?= e($a['reopen_reason']) ?></p><?php endif; ?>
</div>

<?php if ($recommendations !== null): ?>
<div class="panel asd-card fn08-recommendations">
    <div class="asd-card__head">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <h2 class="asd-card__title"><?= e(t('alert_show.recommendations')) ?></h2>
        <?php if (can('recommendations.generate') && $status !== 'da_dong' && ($recommendationPreview['actionable'] ?? false) && (!($recommendationPreview['needs_revision'] ?? true) || ($recommendationPreview['revision_storage_ready'] ?? false))): ?>
            <form method="post" action="<?= e(base_url('/alerts/' . $a['id'] . '/recommendations')) ?>" class="inline-form" style="margin-left:auto">
                <?= csrf_field() ?>
                <button type="submit" class="btn asd-gen-btn">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    <?= e(t('alert_show.generate')) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($recommendationPreview !== null): $workflow = $recommendationPreview['context']; ?>
        <div class="fn08-context">
            <div class="fn08-context-head"><h3><?= e($workflow['category']) ?></h3><span class="badge badge--<?= $workflow['stage'] === 'verified_confirm' ? 'active' : 'info' ?>">Đề xuất theo ngữ cảnh — chưa tự thực hiện</span></div>
            <?php if ($recommendationPreview['actionable']): ?><p class="fn08-context-text"><?= e($workflow['text']) ?></p>
            <?php else: ?><p>Không có khuyến nghị hành động phù hợp với quy tắc đang bật và bằng chứng hiện tại.</p><?php endif; ?>
            <p class="fn08-context-reason"><strong>Lý do:</strong> <?= e($workflow['reason']) ?></p>
            <?php foreach ($recommendationPreview['matched_rules'] as $match): ?><p class="form-hint">Quy tắc #<?= (int)$match['rule_id'] ?>: <?= e($match['reason']) ?></p><?php endforeach; ?>
            <details class="fn08-evidence"><summary>Điều kiện cảnh báo và bằng chứng xác minh</summary>
                <pre><?= e(json_encode($workflow['evidence'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
            </details>
            <?php if (!$recommendationPreview['revision_storage_ready']): ?><p class="fn08-storage-note"><?= e($recommendationPreview['revision_storage']['message']) ?></p><?php endif; ?>
            <p class="form-hint"><?= e($recommendationPreview['note']) ?></p>
        </div>
    <?php endif; ?>
    <?php if ($recommendations === []): ?>
        <div class="dash-empty-state">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <p><?= e(t('alert_show.no_recommendations')) ?><?= $status === 'da_dong' ? e(t('alert_show.closed_no_new')) : '' ?>.</p>
        </div>
    <?php else: ?>
        <details class="fn08-history" open><summary>Lịch sử khuyến nghị đã lưu — giữ nguyên nội dung lúc sinh</summary><div class="asd-reco-list">
            <?php foreach ($recommendations as $rec): ?>
                <div class="asd-reco-item">
                    <span class="badge <?= ($rec['history_label'] ?? '') === 'Hiện hành' ? 'badge--active' : 'badge--muted' ?>"><?= e($rec['history_label'] ?? 'Lịch sử khuyến nghị gốc') ?></span>
                    <div class="asd-reco-item__text"><?= e($rec['recommendation_text']) ?></div>
                    <div class="asd-reco-item__meta">
                        <span><?= e(t('alert_show.rule')) ?>
                            <?php if (can('recommendation_rules.view')): ?>
                                <a href="<?= e(base_url('/recommendation-rules/' . $rec['recommendation_rule_id'])) ?>">#<?= (int) $rec['recommendation_rule_id'] ?></a>
                            <?php else: ?>#<?= (int) $rec['recommendation_rule_id'] ?><?php endif; ?>
                            «<?= e($rec['rule_name']) ?>»
                        </span>
                        <span>· <?= e(t('alert_show.generated_at', ['at' => $rec['created_at']])) ?><?= $rec['why'] && $rec['why']['generated_by'] ? ' ' . e(t('alert_show.by')) . ' ' . e($rec['why']['generated_by']) : '' ?></span>
                        <?= $rec['rule_status'] === 'tat' ? '<span class="tag-note">' . e(t('alert_show.rule_off')) . '</span>' : '' ?>
                        <?= $rec['rule_changed'] ? '<span class="tag-note">' . e(t('alert_show.rule_changed')) . '</span>' : '' ?>
                    </div>
                    <?php if ($rec['why'] !== null): ?>
                        <div class="asd-reco-item__why">
                            "<?= e(rule_condition_t('recommendation', $rec['why']['rule'])) ?>" <?= e(t('alert_show.met')) ?> — <?= e($rec['why']['reason']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div></details>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
if (can('alerts.review') && $can('review')) {
    echo $modal('reviewModal', t('alert_show.review_title', ['id' => $a['id']]), '/alerts/' . $a['id'] . '/review', $prioritySelect($a['priority']) . $textarea('note', t('common.fields.note'), false), t('alert_show.save_review'), 'btn--primary', ['priority', 'note']);
}
if (can('alerts.review') && in_array($status, \App\Services\AlertStateMachine::PRIORITY_EDITABLE, true)) {
    echo $modal('priorityModal', t('alert_show.change_priority_title'), '/alerts/' . $a['id'] . '/priority', $prioritySelect($a['priority']) . $textarea('note', t('common.fields.note'), false), t('common.actions.save'));
}
if (can('alerts.request_verification') && $can('request_verification')) {
    $techs = '<div class="form-group ' . (has_error($form, 'assigned_to') ? 'has-error' : '') . '"><label>' . e(t('alert_show.assign_tech')) . ' <span class="required">*</span></label><select name="assigned_to"><option value="">' . e(t('common.options.select')) . '</option>';
    foreach ($d['technicians'] as $t) {
        $techs .= '<option value="' . (int) $t['id'] . '"' . (form_value($form, null, 'assigned_to') === (string) $t['id'] ? ' selected' : '') . '>' . e($t['full_name']) . '</option>';
    }
    $techs .= '</select>' . field_error($form, 'assigned_to') . '</div>';
    echo $modal('requestModal', t('alert_show.request_check'), '/alerts/' . $a['id'] . '/request-verification', $textarea('content', t('alert_show.check_content'), true, $isJ16 ? (string) config('j16.tech_reason') : '') . $techs, t('alert_show.send_request'), 'btn--primary', ['content', 'assigned_to']);
}
if (can('alerts.close') && $can('close')) {
    echo $modal('closeModal', t('alert_show.close_title'), '/alerts/' . $a['id'] . '/close', $textarea('close_reason', t('alert_show.close_reason_label'), true), t('common.actions.close'), 'btn--danger', ['close_reason']);
}
if (can('alerts.close') && $can('reopen')) {
    echo $modal('reopenModal', t('alert_show.reopen_title'), '/alerts/' . $a['id'] . '/reopen', $textarea('reopen_reason', t('alert_show.reopen_reason_label'), true), t('common.actions.reopen'), 'btn--danger', ['reopen_reason']);
}
?>
