<?php
/**
 * [BA Proposal / Assumption J-16] chi tiết cảnh báo "Phát sinh tiêu thụ tại căn khai báo không sử dụng" - khối 1 → 6 (khối 7 Timeline = lịch sử ở show.php).
 * Không kết luận nguyên nhân; mọi thao tác ghi do BQL, Kỹ thuật chỉ cập nhật kết quả case được giao.
 * @var array $d @var array $a @var array $form @var string $token @var string $status @var ?array $open @var int $me
 */
$snap = $d['snapshot'];
$units = config('pricing.utility_unit');
$closed = $status === 'da_dong';
$conclusions = config_labels('j16.conclusions');
$canContact = can('alerts.contact_resident') && !$closed;
$canConclude = can('alerts.conclude');
$showEmail = can('residents.view_phone'); // BR-36: chỉ BQL thấy email đầy đủ
$field = function (string $name, string $label, string $control, bool $required = false) use ($form): string {
    return '<div class="form-group ' . (has_error($form, $name) ? 'has-error' : '') . '"><label for="j16_' . $name . '">' . e($label) . ($required ? ' <span class="required">*</span>' : '')
        . '</label>' . $control . field_error($form, $name) . '</div>';
};
$select = function (string $name, array $options, string $placeholder) use ($form): string {
    $html = '<select id="j16_' . $name . '" name="' . $name . '"><option value="">' . e($placeholder) . '</option>';
    foreach ($options as $k => $l) {
        $html .= '<option value="' . e((string) $k) . '"' . (form_value($form, null, $name) === (string) $k ? ' selected' : '') . '>' . e(is_array($l) ? $l['label'] : $l) . '</option>';
    }

    return $html . '</select>';
};
$nowLocal = date('Y-m-d\TH:i');
?>
<div class="alert alert--info" role="status">
    <strong><?= e(label_t('j16.label', (string) config('j16.label'))) ?></strong>
    <div><?= e(label_t('j16.review_message', (string) config('j16.review_message'))) ?></div>
</div>

<!-- 1. Phát hiện -->
<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= e(t('alert_j16.detection')) ?></h2></div>
    <p class="form-hint"><?= t('alert_j16.detection_hint_html') ?></p>
    <dl class="detail-grid">
        <div style="grid-column:1/-1"><dt><?= e(t('common.fields.content')) ?></dt><dd><?= e($a['description'] ?? '') ?></dd></div>
        <div><dt><?= e(t('common.fields.rule')) ?></dt><dd><?= e($snap['rule'] ?? '') ?> · <?= e(t('alert_j16.source')) ?> <?= e($snap['source'] ?? '') ?></dd></div>
        <div><dt><?= e(t('alert_j16.detected_at')) ?></dt><dd><?= e($snap['detected_at'] ?? $a['created_at']) ?></dd></div>
        <div><dt><?= e(t('alert_j16.last_evaluated')) ?></dt><dd><?= e($snap['evaluated_at'] ?? '—') ?> (<?= e($snap['trigger'] ?? '') ?>)</dd></div>
        <div><dt><?= e(t('common.fields.utility')) ?></dt><dd><?= e(\App\Services\NoUsageConsumptionService::utilityLabel($a)) ?></dd></div>
        <div style="grid-column:1/-1"><dt><?= e(t('alert_j16.suggested_priority')) ?></dt><dd><?= e($snap['priority_suggestion']['basis'] ?? '—') ?></dd></div>
    </dl>
    <?php if (!empty($snap['data_no_longer_matches'])): ?>
        <div class="notice-box" style="margin-top:12px"><strong><?= e(t('alert_j16.re_evaluated')) ?></strong> <?= e($snap['data_no_longer_matches_reason'] ?? '') ?> — <?= e(t('alert_j16.history_kept')) ?></div>
    <?php endif; ?>
</div>

<!-- 2. Dữ liệu công tơ -->
<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= e(t('alert_j16.meter_data', ['code' => $a['period_code']])) ?></h2></div>
    <dl class="detail-grid" style="margin-bottom:12px">
        <div><dt><?= e(t('alert_j16.cl_elec')) ?></dt><dd class="price-big"><?= e(format_decimal($snap['electricity_consumption'] ?? '0')) ?> <?= e($units['dien']) ?></dd></div>
        <div><dt><?= e(t('alert_j16.cl_water')) ?></dt><dd class="price-big"><?= e(format_decimal($snap['water_consumption'] ?? '0')) ?> <?= e($units['nuoc']) ?></dd></div>
    </dl>
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th><?= e(t('common.fields.meter')) ?></th><th><?= e(t('common.fields.utility')) ?></th><th class="num"><?= e(t('common.fields.prev_reading')) ?></th><th class="num"><?= e(t('common.fields.curr_reading')) ?></th><th class="num"><?= e(t('common.fields.consumption')) ?></th><th><?= e(t('alert_j16.recorded_at')) ?></th><th><?= e(t('alert_j16.live_status')) ?></th><th><?= e(t('alert_j16.reading')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($snap['meters'] ?? [] as $m): $live = $d['readings'][(int) $m['reading_id']] ?? null; $changed = $live !== null && (string) $live['current_reading'] !== (string) $m['current_reading']; ?>
            <tr>
                <td><?php if (can('meters.view')): ?><a href="<?= e(base_url('/meters/' . $m['meter_id'])) ?>"><?= e($m['meter_code']) ?></a><?php else: ?><?= e($m['meter_code']) ?><?php endif; ?></td>
                <td><?= e(label_of('utility_type', $m['utility_type'])) ?></td>
                <td class="num"><?= e(format_decimal($m['previous_reading'])) ?></td>
                <td class="num"><?= e(format_decimal($m['current_reading'])) ?><?= $changed ? '<div class="form-hint">' . e(t('alert_j16.now')) . ' ' . e(format_decimal($live['current_reading'])) . '</div>' : '' ?></td>
                <td class="num"><strong><?= e(format_decimal($m['consumption'])) ?></strong> <?= e($units[$m['utility_type']] ?? '') ?></td>
                <td><?= e($m['entered_at']) ?><?= $m['confirmed_at'] ? '<div class="form-hint">' . e(t('alert_j16.approved')) . ' ' . e($m['confirmed_at']) . '</div>' : '' ?></td>
                <td><?= $live !== null ? e(config_labels('readings.reading_status')[$live['status']] ?? $live['status']) : '—' ?></td>
                <td><?php if (can('meter_readings.view')): ?><a href="<?= e(base_url('/readings/' . $m['reading_id'])) ?>">#<?= (int) $m['reading_id'] ?></a><?php else: ?>#<?= (int) $m['reading_id'] ?><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="form-hint"><?= t('alert_j16.confirmed_only_html') ?></p>
</div>

<!-- 3. Trạng thái căn hộ -->
<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= e(t('alert_j16.apartment_status')) ?></h2></div>
    <dl class="detail-grid">
        <div><dt><?= e(t('common.fields.apartment')) ?></dt><dd><?php if (can('apartments.view')): ?><a href="<?= e(base_url('/apartments/' . $a['apartment_id'])) ?>"><?= e($a['building_code'] . ' / ' . $a['apartment_code']) ?></a><?php else: ?><?= e($a['building_code'] . ' / ' . $a['apartment_code']) ?><?php endif; ?></dd></div>
        <div><dt><?= e(t('alert_j16.declared_then')) ?></dt><dd><?= e(label_of('occupancy_status', $snap['declared_usage_status'] ?? null)) ?></dd></div>
        <div><dt><?= e(t('alert_j16.declared_now')) ?></dt><dd><?= e(label_of('occupancy_status', $a['occupancy_status'])) ?></dd></div>
    </dl>
    <?php if ($a['conclusion'] === 'apartment_status_incorrect' && $canConclude && !$closed): ?>
        <?php if ($a['occupancy_status'] === config('j16.declared_status')): ?>
            <h3 class="section-title"><?= e(t('alert_j16.update_apartment_a')) ?></h3>
            <form method="post" action="<?= e(base_url('/alerts/' . $a['id'] . '/j16/apartment-status')) ?>" novalidate class="js-confirm"
                  data-confirm-title="<?= e(t('alert_j16.update_apartment')) ?>" data-confirm-message="<?= e(t('alert_j16.update_apartment_message', ['code' => $a['apartment_code']])) ?>" data-confirm-button="<?= e(t('alert_j16.update')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="version_token" value="<?= e($token) ?>">
                <label class="checkbox-line <?= has_error($form, 'confirm_apartment') ? 'has-error' : '' ?>"><input type="checkbox" name="confirm_apartment" value="1"> <?= e(t('alert_j16.confirm_apartment')) ?></label>
                <?= field_error($form, 'confirm_apartment') ?>
                <div class="form-actions"><button type="submit" class="btn btn--primary"><?= e(t('alert_j16.update_apartment')) ?></button></div>
                <p class="form-hint"><?= e(t('alert_j16.no_auto_change')) ?></p>
            </form>
        <?php else: ?>
            <p class="form-hint"><?= e(t('alert_j16.apartment_updated')) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- 4. Liên hệ cư dân -->
<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= e(t('alert_j16.contact')) ?></h2></div>
    <h3 class="section-title"><?= e(t('alert_j16.verification_email')) ?></h3>
    <?php if ($d['recipients'] === []): ?>
        <div class="notice-box"><?= e(t('alert_j16.no_email')) ?></div>
    <?php elseif ($canContact): ?>
        <form method="post" action="<?= e(base_url('/alerts/' . $a['id'] . '/j16/email')) ?>" novalidate class="toolbar js-confirm"
              data-confirm-title="<?= e(t('alert_j16.send_verification')) ?>" data-confirm-message="<?= e(t('alert_j16.send_verification_message')) ?>" data-confirm-button="<?= e(t('alert_j16.send')) ?>">
            <?= csrf_field() ?>
            <div class="form-group form-group--grow <?= has_error($form, 'resident_id') ? 'has-error' : '' ?>"><label for="j16_resident_id"><?= e(t('common.fields.recipient')) ?></label>
                <select id="j16_resident_id" name="resident_id"><option value=""><?= e(t('common.options.select_resident')) ?></option>
                    <?php foreach ($d['recipients'] as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['full_name'] . ' — ' . label_of('resident_role', $r['resident_role']) . ' — ' . ($showEmail ? $r['email'] : mask_email($r['email']))) ?></option><?php endforeach; ?>
                </select><?= field_error($form, 'resident_id') ?></div>
            <button type="submit" class="btn btn--primary btn--sm"><?= e(t('alert_j16.send_verification')) ?></button>
        </form>
    <?php endif; ?>
    <?php if ($d['emails'] !== []): ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>#</th><th><?= e(t('common.fields.recipient')) ?></th><th><?= e(t('common.fields.status')) ?></th><th><?= e(t('alert_j16.attempts')) ?></th><th><?= e(t('common.fields.sent_at')) ?></th><th><?= e(t('common.fields.error')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($d['emails'] as $n): ?>
                <tr><td><?php if (can('email_notifications.view')): ?><a href="<?= e(base_url('/email-notifications/' . $n['id'])) ?>">#<?= (int) $n['id'] ?></a><?php else: ?>#<?= (int) $n['id'] ?><?php endif; ?></td>
                    <td><?= e(($n['recipient_name'] ?? '') . ' — ' . ($showEmail ? $n['recipient_email'] : mask_email($n['recipient_email']))) ?></td>
                    <td><?= e(label_t('email.status.' . $n['status'], ['pending' => 'Đang gửi', 'sent' => 'Đã gửi', 'failed' => 'Lỗi'][$n['status']] ?? $n['status'])) ?></td>
                    <td><?= (int) $n['attempt_count'] ?></td><td><?= e($n['sent_at'] ?? '—') ?></td><td><?= e(message_t($n['last_error'] ?? '')) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="form-hint"><?= htmlspecialchars(t('alert_j16.failed_hint'), ENT_NOQUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if ($canContact): ?>
        <div class="form-grid" style="margin-top:12px">
            <form method="post" action="<?= e(base_url('/alerts/' . $a['id'] . '/j16/contact')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="contact_type" value="phone">
                <h3 class="section-title"><?= htmlspecialchars(t('alert_j16.record_call'), ENT_NOQUOTES, 'UTF-8') ?></h3>
                <?= $field('contact_time', t('alert_j16.call_time'), '<input type="datetime-local" id="j16_contact_time" name="contact_time" value="' . e(form_value($form, null, 'contact_time', $nowLocal)) . '">', true) ?>
                <?= $field('result', t('alert_j16.call_result'), $select('result', config_labels('j16.phone_results'), t('common.options.select')), true) ?>
                <?= $field('contact_note', t('common.fields.note'), '<textarea id="j16_contact_note" name="contact_note" rows="2" maxlength="500"></textarea>') ?>
                <button type="submit" class="btn btn--ghost btn--sm"><?= e(t('alert_j16.record_call_button')) ?></button>
                <p class="form-hint"><?= e(t('alert_j16.no_auto_call')) ?></p>
            </form>
            <form method="post" action="<?= e(base_url('/alerts/' . $a['id'] . '/j16/contact')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="contact_type" value="resident_response">
                <h3 class="section-title"><?= e(t('alert_j16.record_response')) ?></h3>
                <div class="form-group"><label for="j16_resp_time"><?= e(t('alert_j16.response_time')) ?> <span class="required">*</span></label><input type="datetime-local" id="j16_resp_time" name="contact_time" value="<?= e($nowLocal) ?>"></div>
                <div class="form-group"><label for="j16_resp_result"><?= e(t('alert_j16.response')) ?> <span class="required">*</span></label>
                    <select id="j16_resp_result" name="result"><option value=""><?= e(t('common.options.select')) ?></option><?php foreach (config_labels('j16.resident_responses') as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="j16_resp_note"><?= e(t('common.fields.note')) ?></label><textarea id="j16_resp_note" name="contact_note" rows="2" maxlength="500"></textarea></div>
                <button type="submit" class="btn btn--ghost btn--sm"><?= e(t('alert_j16.record_response_button')) ?></button>
                <p class="form-hint"><?= e(t('alert_j16.no_portal')) ?></p>
            </form>
        </div>
    <?php endif; ?>
    <?php if ($d['contacts'] !== []): ?>
        <div class="table-wrap" style="margin-top:12px"><table class="data-table">
            <thead><tr><th><?= e(t('common.fields.timestamp')) ?></th><th><?= e(t('common.fields.type')) ?></th><th><?= e(t('common.fields.result')) ?></th><th><?= e(t('common.fields.note')) ?></th><th><?= e(t('alert_j16.recorded_by')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($d['contacts'] as $l): ?>
                <tr><td><?= e($l['contact_time']) ?></td><td><?= e($l['contact_type'] === 'phone' ? t('alert_j16.called_owner') : t('alert_j16.resident_response')) ?></td>
                    <td><?= e(($l['contact_type'] === 'phone' ? config_labels('j16.phone_results') : config_labels('j16.resident_responses'))[$l['result']] ?? $l['result']) ?></td>
                    <td><?= e($l['note'] ?? '') ?></td><td><?= e($l['created_by_name'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<!-- 5. Kết quả Kỹ thuật -->
<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= e(t('alert_j16.tech_result')) ?></h2></div>
    <p class="form-hint"><?= e(t('alert_j16.check_reason')) ?> <?= e(config('j16.tech_reason')) ?>. <?= e(t('alert_j16.tech_scope')) ?></p>
    <?php if ($d['cases'] === []): ?>
        <div class="empty-state"><p><?= e(t('alert_j16.not_assigned')) ?><?= can('alerts.request_verification') && $status === 'da_review' ? htmlspecialchars(t('alert_j16.use_request_button'), ENT_NOQUOTES, 'UTF-8') : '' ?>.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th><?= e(t('ui.case_label')) ?></th><th><?= e(t('common.fields.content')) ?></th><th><?= e(t('ui.assigned_to')) ?></th><th><?= e(t('common.fields.status')) ?></th><th><?= e(t('common.fields.result')) ?></th><th><?= e(t('common.fields.note')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($d['cases'] as $k): ?>
                <tr><td>#<?= (int) $k['id'] ?></td><td><?= e($k['content']) ?></td><td><?= e($k['assigned_to_name'] ?? '') ?></td><td><?= e(config_labels('alerts.case_status')[$k['status']]) ?></td>
                    <td><?= $k['result_conclusion'] ? e((config_labels('j16.tech_results') + config_labels('alerts.conclusion'))[$k['result_conclusion']] ?? $k['result_conclusion']) : '—' ?></td><td><?= e($k['note'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <?php if ($open !== null && $open['status'] === 'da_giao' && can('alerts.update_verification') && (int) $open['assigned_to'] === $me): ?>
        <h3 class="section-title"><?= e(t('alert_show.update_result', ['id' => (int) $open['id']])) ?></h3>
        <form method="post" action="<?= e(base_url('/alerts/' . $a['id'] . '/verification-result')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-grid">
                <?= $field('result_conclusion', t('common.fields.result'), $select('result_conclusion', config_labels('j16.tech_results'), t('common.options.select')), true) ?>
                <div class="form-group <?= has_error($form, 'note') ? 'has-error' : '' ?>" style="grid-column:1/-1"><label for="j16_tech_note"><?= e(t('common.fields.note')) ?></label><textarea id="j16_tech_note" name="note" rows="2" maxlength="500"><?= e(form_value($form, null, 'note')) ?></textarea><?= field_error($form, 'note') ?></div>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><?= e(t('alert_show.send_result')) ?></button></div>
        </form>
    <?php endif; ?>
</div>

<!-- 6. Kết luận BQL -->
<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= e(t('alert_j16.bql_conclusion')) ?></h2></div>
    <?php if ($a['conclusion'] !== null): ?>
        <dl class="detail-grid" style="margin-bottom:12px">
            <div><dt><?= e(t('alert_show.conclusion')) ?></dt><dd><strong><?= e($conclusions[$a['conclusion']]['label'] ?? $a['conclusion']) ?></strong></dd></div>
            <div><dt><?= e(t('alert_j16.by_at')) ?></dt><dd><?= e(($a['concluded_by_name'] ?? '') . ' · ' . ($a['concluded_at'] ?? '')) ?></dd></div>
            <?php if ($a['follow_up_date']): ?><div><dt><?= e(t('alert_j16.follow_up')) ?></dt><dd><?= e(format_date($a['follow_up_date'])) ?></dd></div><?php endif; ?>
            <div style="grid-column:1/-1"><dt><?= e(t('alert_j16.basis')) ?></dt><dd><?= e($a['conclusion_note'] ?? '') ?></dd></div>
        </dl>
    <?php else: ?>
        <p class="form-hint"><?= t('alert_j16.no_conclusion_html') ?></p>
    <?php endif; ?>
    <?php if (($a['conclusion'] === null || !$conclusions[$a['conclusion']]['resolves'])): ?>
        <div class="notice-box"><?= t('alert_j16.gate_html') ?></div>
    <?php endif; ?>

    <?php if ($canConclude && !$closed && $status !== 'moi'): ?>
        <h3 class="section-title"><?= e($a['conclusion'] === null ? t('alert_j16.conclude_after') : t('alert_j16.change_conclusion')) ?></h3>
        <form method="post" action="<?= e(base_url('/alerts/' . $a['id'] . '/j16/conclude')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="version_token" value="<?= e($token) ?>">
            <div class="form-grid">
                <?= $field('conclusion', t('alert_show.conclusion'), str_replace('<select ', '<select data-conclusion-select ', $select('conclusion', $conclusions, t('common.options.select'))), true) ?>
                <?php $isD = form_value($form, null, 'conclusion') === 'undetermined'; ?>
                <div class="form-group <?= has_error($form, 'follow_up_date') ? 'has-error' : '' ?>" data-followup-field<?= $isD ? '' : ' hidden' ?>>
                    <label for="j16_follow_up_date"><?= e(t('alert_j16.follow_up_date')) ?> <span class="required">*</span></label>
                    <input type="date" id="j16_follow_up_date" name="follow_up_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e($isD ? form_value($form, null, 'follow_up_date') : '') ?>"
                           aria-describedby="j16_follow_up_hint"<?= $isD ? ' required aria-required="true"' : '' ?>>
                    <div class="form-hint" id="j16_follow_up_hint"><?= e(t('alert_j16.follow_up_hint')) ?></div>
                    <?= field_error($form, 'follow_up_date') ?>
                </div>
                <div class="form-group <?= has_error($form, 'conclusion_note') ? 'has-error' : '' ?>" style="grid-column:1/-1"><label for="j16_conclusion_note"><?= e(t('alert_j16.conclusion_basis')) ?> <span class="required">*</span></label>
                    <textarea id="j16_conclusion_note" name="conclusion_note" rows="2" maxlength="500"><?= e(form_value($form, null, 'conclusion_note')) ?></textarea><?= field_error($form, 'conclusion_note') ?></div>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><?= e(t('alert_show.save_conclusion')) ?></button></div>
            <p class="form-hint"><?= e(t('alert_j16.abc_hint')) ?></p>
        </form>
    <?php elseif ($canConclude && $status === 'moi'): ?>
        <p class="form-hint"><?= e(t('alert_j16.review_first')) ?></p>
    <?php endif; ?>

    <?php if ($a['conclusion'] === 'reading_incorrect' && $canConclude && !$closed && $d['readings'] !== []): ?>
        <h3 class="section-title"><?= e(t('alert_j16.adjust_b')) ?></h3>
        <?php foreach ($d['readings'] as $rid => $r): ?>
            <?php if ($r['period_status'] === 'da_khoa') { echo '<p class="form-hint">' . e(t('alert_j16.locked_reading', ['id' => (int) $rid])) . '</p>'; continue; } ?>
            <form method="post" action="<?= e(base_url('/alerts/' . $a['id'] . '/j16/adjust-reading')) ?>" novalidate class="toolbar js-confirm"
                  data-confirm-title="<?= e(t('alert_j16.adjust_reading')) ?>" data-confirm-message="<?= e(t('alert_j16.adjust_message', ['id' => (int) $rid])) ?>" data-confirm-button="<?= e(t('alert_j16.adjust')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="version_token" value="<?= e($token) ?>">
                <input type="hidden" name="reading_id" value="<?= (int) $rid ?>">
                <input type="hidden" name="reading_token" value="<?= e($r['version_token']) ?>">
                <div class="form-group"><label><?= e(t('alert_j16.reading_values', ['meter' => $r['meter_code'], 'previous' => format_decimal($r['previous_reading']), 'current' => format_decimal($r['current_reading'])])) ?></label>
                    <input name="current_reading" inputmode="decimal" placeholder="<?= e(t('alert_j16.new_csc')) ?>"></div>
                <div class="form-group form-group--grow"><label><?= e(t('alert_j16.adjust_reason')) ?></label><input name="adjust_note" maxlength="400"></div>
                <button type="submit" class="btn btn--ghost btn--sm"><?= e(t('alert_j16.adjust')) ?></button>
            </form>
        <?php endforeach; ?>
        <?= field_error($form, 'adjust_note') ?><?= field_error($form, 'current_reading') ?>
    <?php endif; ?>
    <?php if ($a['conclusion'] === 'real_consumption_confirmed'): ?>
        <p class="form-hint"><?= e(t('alert_j16.c_hint')) ?></p>
    <?php endif; ?>
</div>
