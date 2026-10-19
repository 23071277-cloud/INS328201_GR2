<?php
/**
 * FN-04.02 Nhập chỉ số (UX worklist): trái = danh sách công tơ của kỳ + trạng thái ghi số; phải = thông tin công tơ đang chọn + nhập CSC.
 * Nghiệp vụ không đổi: CSĐ do hệ thống xác định, chỉ nhập CSC, CL do server tính khi lưu, lưu = Nháp chờ BQL duyệt.
 * @var array $period @var array $worklist @var ?array $context @var array $listFilters @var bool $doneAll @var array $form
 */
$breadcrumbs = [[t('crumbs.readings'), null], [t('page.periods'), '/periods'], [$period['period_code'], '/periods/' . $period['id']], [t('period_show.enter_readings'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';

$isLocked = $period['status'] === 'da_khoa';
$canEnter = !$isLocked && can('meter_readings.create');
$stateLabels = config_labels('readings.worklist_status');
$stateBadge = ['chua_ghi' => 'badge--danger', 'nhap' => 'badge--warning', 'da_xac_nhan' => 'badge--active'];
$units = config('pricing.utility_unit');
$periodPath = '/periods/' . $period['id'];
$entryPath = $periodPath . '/readings/create';

// Một công tơ của worklist -> dữ liệu hiển thị (dùng cho cả bảng, panel server-side và JSON cho JS)
$toItem = static function (array $row, array $previous, string $state, ?int $readingId) use ($stateLabels, $units): array {
    $source = match ($previous['source']) {
        'initial' => t('worklist.src_initial'),
        'reading' => t('worklist.src_reading', ['code' => $previous['from_period']]),
        'saved' => t('worklist.src_saved'),
        default => t('worklist.src_unknown'),
    };

    return [
        'id' => (int) $row['id'],
        'apartment' => $row['building_code'] . ' / ' . $row['apartment_code'],
        'buildingCode' => $row['building_code'],
        'apartmentCode' => $row['apartment_code'],
        'code' => $row['meter_code'],
        'utility' => $row['utility_type'],
        'utilityLabel' => trim((string) preg_replace('/\s*\(.*\)$/u', '', label_of('utility_type', $row['utility_type']))),
        'unit' => $units[$row['utility_type']] ?? '',
        'label' => (string) ($row['display_label'] ?? ''),
        'usageLabel' => trim((string) preg_replace('/\s*\(.*\)$/u', '', label_of('usage_type', $row['usage_type']))),
        'csd' => $previous['value'] !== null ? format_decimal($previous['value']) : '—',
        'csdInput' => $previous['value'] !== null ? format_input($previous['value']) : '',
        'csdSource' => $source,
        'state' => $state,
        'stateLabel' => $stateLabels[$state],
        'blocked' => $previous['blocked'],
        'readingId' => $readingId,
        'csc' => isset($row['current_reading']) && $row['current_reading'] !== null ? format_decimal($row['current_reading']) : '',
        'cl' => isset($row['consumption']) && $row['consumption'] !== null ? format_decimal($row['consumption']) : '',
    ];
};

$items = [];
$counts = ['chua_ghi' => 0, 'nhap' => 0, 'da_xac_nhan' => 0];
$blockedCount = 0;
foreach ($worklist as $row) {
    $item = $toItem($row, $row['previous'], $row['state'], $row['reading_id'] !== null ? (int) $row['reading_id'] : null);
    $items[$item['id']] = $item;
    $counts[$item['state']]++;
    $blockedCount += $item['state'] === 'chua_ghi' && $item['blocked'] !== null ? 1 : 0;
}
$total = count($items);

// Công tơ đang chọn: ưu tiên dòng worklist; công tơ ngoài worklist (vd đã ngừng, chưa có chỉ số) -> lấy từ entryContext, backend vẫn kiểm tra khi lưu
$selected = null;
if ($context !== null) {
    $selected = $items[(int) $context['meter']['id']]
        ?? $toItem($context['meter'], $context['previous'], $context['existing_id'] !== null ? 'nhap' : 'chua_ghi', null);
}
$selectedId = $selected['id'] ?? 0;
$doneAll = $selected === null && $doneAll; // ?meter_id=none sau "Lưu & nhập tiếp" khi không còn công tơ Chưa ghi

$matches = static function (array $item) use ($listFilters): bool {
    if ($listFilters['status'] !== '' && $item['state'] !== $listFilters['status']) {
        return false;
    }
    if ($listFilters['utility'] !== '' && $item['utility'] !== $listFilters['utility']) {
        return false;
    }
    $q = mb_strtolower($listFilters['q']);

    return $q === '' || str_contains(mb_strtolower($item['apartment'] . ' ' . $item['code'] . ' ' . $item['label']), $q);
};
$visible = count(array_filter($items, $matches));
$percent = static fn (int $n): string => $total > 0 ? format_decimal(round($n * 100 / $total, 1)) . '%' : '0%';
$rowUrl = static fn (int $id): string => url_with($entryPath, $listFilters, ['meter_id' => $id]);
$hasFieldError = has_error($form, 'current_reading');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('worklist.heading', ['code' => $period['period_code']])) ?></h1>
    </div>
    <div class="page-head__actions">
        <a class="btn wl-back-btn" href="<?= e(base_url($periodPath)) ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            <?= e(t('common.actions.back_to_period')) ?>
        </a>
    </div>
</div>

<?php if ($isLocked): ?>
    <div class="notice-box"><?= e(t('worklist.locked')) ?></div>
<?php endif; ?>

<div class="wl-stats" role="list" aria-label="<?= e(t('worklist.overview', ['code' => $period['period_code']])) ?>">
    <div class="wl-stat wl-stat--total" role="listitem">
        <div class="wl-stat__icon wl-stat__icon--blue" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        </div>
        <div>
            <span class="wl-stat__label"><?= e(t('worklist.total')) ?></span>
            <span class="wl-stat__value" data-wl-count="total"><?= $total ?></span>
        </div>
    </div>
    <div class="wl-stat wl-stat--danger" role="listitem">
        <div class="wl-stat__icon wl-stat__icon--orange" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div>
            <span class="wl-stat__label"><?= e(config_label('readings.worklist_status', 'chua_ghi')) ?></span>
            <span class="wl-stat__value" data-wl-count="chua_ghi"><?= $counts['chua_ghi'] ?></span>
            <span class="wl-stat__pct"><?= e($percent($counts['chua_ghi'])) ?><?= $blockedCount > 0 ? ' · ' . $blockedCount . ' ' . e(t('worklist.blocked_count')) : '' ?></span>
        </div>
    </div>
    <div class="wl-stat wl-stat--warning" role="listitem">
        <div class="wl-stat__icon wl-stat__icon--yellow" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div>
            <span class="wl-stat__label"><?= e(config_label('readings.worklist_status', 'nhap')) ?></span>
            <span class="wl-stat__value" data-wl-count="nhap"><?= $counts['nhap'] ?></span>
            <span class="wl-stat__pct"><?= e($percent($counts['nhap'])) ?></span>
        </div>
    </div>
    <div class="wl-stat wl-stat--success" role="listitem">
        <div class="wl-stat__icon wl-stat__icon--green" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div>
            <span class="wl-stat__label"><?= e(config_label('readings.worklist_status', 'da_xac_nhan')) ?></span>
            <span class="wl-stat__value" data-wl-count="da_xac_nhan"><?= $counts['da_xac_nhan'] ?></span>
            <span class="wl-stat__pct"><?= e($percent($counts['da_xac_nhan'])) ?></span>
        </div>
    </div>
</div>

<div class="wl-layout">
    <section class="panel wl-list wl-card" aria-labelledby="wl-list-title">
        <h2 class="panel__title" id="wl-list-title"><?= e(t('worklist.list')) ?> (<?= $total ?>)</h2>
        <form method="get" action="<?= e(base_url($entryPath)) ?>" class="wl-filters" data-wl-filters role="search">
            <?php if ($selectedId > 0): ?><input type="hidden" name="meter_id" value="<?= $selectedId ?>"><?php endif; ?>
            <div class="form-group wl-filters__search">
                <label class="sr-only" for="wl_q"><?= e(t('worklist.search_meter')) ?></label>
                <input type="search" id="wl_q" name="q" value="<?= e($listFilters['q']) ?>" placeholder="<?= e(t('common.placeholders.worklist_search')) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="sr-only" for="wl_status"><?= e(t('worklist.filter_status')) ?></label>
                <select id="wl_status" name="status">
                    <option value=""><?= e(t('common.options.all_statuses')) ?></option>
                    <?php foreach ($stateLabels as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $listFilters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="sr-only" for="wl_utility"><?= e(t('worklist.filter_utility')) ?></label>
                <select id="wl_utility" name="utility">
                    <option value=""><?= e(t('common.options.both_utilities')) ?></option>
                    <option value="dien" <?= $listFilters['utility'] === 'dien' ? 'selected' : '' ?>><?= e(label_t('alerts.utility_label.dien', 'Điện')) ?></option>
                    <option value="nuoc" <?= $listFilters['utility'] === 'nuoc' ? 'selected' : '' ?>><?= e(label_t('alerts.utility_label.nuoc', 'Nước')) ?></option>
                </select>
            </div>
            <noscript><button type="submit" class="btn btn--secondary btn--sm"><?= e(t('common.actions.filter')) ?></button></noscript>
        </form>
        <p class="wl-count" aria-live="polite" data-wl-visible><?= e(t('worklist.visible', ['visible' => $visible, 'total' => $total])) ?></p>

        <?php if ($total === 0): ?>
            <div class="empty-state"><p><?= e(t('worklist.empty')) ?></p></div>
        <?php else: ?>
            <div class="table-wrap wl-table-wrap">
                <table class="data-table wl-table">
                    <thead>
                        <tr><th scope="col"><?= e(t('common.fields.apartment')) ?></th><th scope="col"><?= e(t('common.fields.meter_code')) ?></th><th scope="col"><?= e(t('common.fields.utility')) ?></th><th scope="col"><?= e(t('common.fields.tag')) ?></th><th scope="col" class="num"><?= e(t('common.fields.prev_reading')) ?></th><th scope="col"><?= e(t('common.fields.status')) ?></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php $isSel = $item['id'] === $selectedId; ?>
                            <tr class="wl-row<?= $isSel ? ' is-selected' : '' ?>" data-wl-row="<?= $item['id'] ?>" data-state="<?= e($item['state']) ?>" data-utility="<?= e($item['utility']) ?>"
                                data-search="<?= e(mb_strtolower($item['apartment'] . ' ' . $item['code'] . ' ' . $item['label'])) ?>"<?= $matches($item) ? '' : ' hidden' ?>>
                                <td data-label="<?= e(t('common.fields.apartment')) ?>"><span class="wl-apt"><span class="wl-apt__bldg"><?= e($item['buildingCode']) ?> /</span> <span class="wl-apt__code"><?= e($item['apartmentCode']) ?></span></span></td>
                                <td data-label="<?= e(t('common.fields.meter_code')) ?>"><a class="wl-row__link" href="<?= e($rowUrl($item['id'])) ?>"<?= $isSel ? ' aria-current="true"' : '' ?>><?= e($item['code']) ?></a></td>
                                <td data-label="<?= e(t('common.fields.utility')) ?>"><span class="wl-utility wl-utility--<?= e($item['utility']) ?>"><?= e($item['utilityLabel']) ?></span></td>
                                <td data-label="<?= e(t('common.fields.tag')) ?>"><?= $item['label'] !== '' ? e($item['label']) : '—' ?></td>
                                <td data-label="<?= e(t('common.fields.prev_reading')) ?>" class="num"><?= e($item['csd']) ?></td>
                                <td data-label="<?= e(t('common.fields.status')) ?>">
                                    <span class="badge <?= $stateBadge[$item['state']] ?>"><?= e($item['stateLabel']) ?></span>
                                    <?php if ($item['blocked'] !== null): ?><span class="tag-note" title="<?= e(t('worklist.blocked_title')) ?>"><?= e(t('worklist.blocked_title')) ?></span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="wl-empty" data-wl-empty<?= $visible > 0 ? ' hidden' : '' ?>><?= e(t('worklist.no_match')) ?></p>
        <?php endif; ?>
    </section>

    <section class="panel wl-panel wl-card" id="wl-panel" aria-labelledby="wl-panel-title" tabindex="-1">
        <div class="panel__head">
            <h2 class="panel__title" id="wl-panel-title"><?= e(t('worklist.info')) ?></h2>
            <span class="badge <?= $selected !== null ? $stateBadge[$selected['state']] : '' ?>" data-wl-field="stateBadge"<?= $selected === null ? ' hidden' : '' ?>><?= e($selected['stateLabel'] ?? '') ?></span>
        </div>

        <div class="wl-panel__none" data-wl-section="none"<?= $selected !== null ? ' hidden' : '' ?>>
            <p><?= e($doneAll ? t('worklist.select_done') : t('worklist.select_prompt')) ?></p>
        </div>

        <div data-wl-section="info"<?= $selected === null ? ' hidden' : '' ?>>
            <dl class="wl-info">
                <div><dt><?= e(t('common.fields.apartment')) ?></dt><dd data-wl-field="apartment"><?= e($selected['apartment'] ?? '') ?></dd></div>
                <div><dt><?= e(t('common.fields.meter_code')) ?></dt><dd data-wl-field="code"><?= e($selected['code'] ?? '') ?></dd></div>
                <div><dt><?= e(t('common.fields.utility')) ?></dt><dd data-wl-field="utilityLabel"><?= e($selected['utilityLabel'] ?? '') ?></dd></div>
                <div><dt><?= e(t('common.fields.tag')) ?></dt><dd data-wl-field="label"><?= e(($selected['label'] ?? '') !== '' ? $selected['label'] : '—') ?></dd></div>
                <div><dt><?= e(t('common.fields.usage_type')) ?></dt><dd data-wl-field="usageLabel"><?= e($selected['usageLabel'] ?? '') ?></dd></div>
                <div><dt><?= e(t('worklist.csd_full')) ?></dt><dd><span data-wl-field="csd"><?= e($selected['csd'] ?? '') ?></span> <span data-wl-field="unit"><?= e($selected['unit'] ?? '') ?></span></dd></div>
                <div class="wl-info__wide"><dt><?= e(t('worklist.csd_source')) ?></dt><dd data-wl-field="csdSource"><?= e($selected['csdSource'] ?? '') ?></dd></div>
            </dl>
        </div>

        <?php if ($isLocked): ?>
            <div class="notice-box" data-wl-section="locked"<?= $selected === null ? ' hidden' : '' ?>><?= e(t('worklist.locked_view')) ?></div>
        <?php endif; ?>

        <div class="notice-box" data-wl-section="blocked" role="status"<?= $selected === null || $isLocked || $selected['state'] !== 'chua_ghi' || $selected['blocked'] === null ? ' hidden' : '' ?>>
            <strong><?= e(t('worklist.blocked_label')) ?></strong> <span data-wl-field="blocked"><?= e($selected['blocked'] ?? '') ?></span>
        </div>

        <div class="wl-saved" data-wl-section="saved"<?= $selected === null || $selected['state'] === 'chua_ghi' ? ' hidden' : '' ?>>
            <p class="wl-saved__msg" data-wl-field="savedMsg"><?= e(($selected['state'] ?? '') === 'da_xac_nhan' ? t('worklist.saved_approved') : t('worklist.saved_draft')) ?></p>
            <dl class="wl-info">
                <div><dt><?= e(t('worklist.csc_recorded')) ?></dt><dd><span data-wl-field="csc"><?= e($selected['csc'] ?? '') ?></span> <span data-wl-field="unit"><?= e($selected['unit'] ?? '') ?></span></dd></div>
                <div><dt><?= e(t('worklist.cl_system')) ?></dt><dd><span data-wl-field="cl"><?= e($selected['cl'] ?? '') ?></span> <span data-wl-field="unit"><?= e($selected['unit'] ?? '') ?></span></dd></div>
            </dl>
            <div class="wl-saved__links">
                <a class="btn btn--ghost btn--sm" data-wl-link="detail" href="<?= e(base_url('/readings/' . ($selected['readingId'] ?? 0))) ?>"><?= e(t('worklist.view_reading')) ?></a>
                <?php if (!$isLocked && can('meter_readings.update')): ?>
                    <a class="action-edit btn btn--ghost btn--sm" data-wl-link="edit" href="<?= e(base_url('/readings/' . ($selected['readingId'] ?? 0) . '/edit')) ?>"<?= ($selected['state'] ?? '') !== 'nhap' ? ' hidden' : '' ?>><?= e(t('worklist.edit_draft')) ?></a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canEnter): ?>
            <div data-wl-section="entry"<?= $selected === null || $selected['state'] !== 'chua_ghi' || $selected['blocked'] !== null ? ' hidden' : '' ?>>
                <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
                <form method="post" action="<?= e(base_url($periodPath . '/readings')) ?>" novalidate data-wl-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="meter_id" value="<?= $selectedId ?>" data-wl-input="meter_id">
                    <input type="hidden" name="q" value="<?= e($listFilters['q']) ?>" data-wl-keep="q">
                    <input type="hidden" name="status" value="<?= e($listFilters['status']) ?>" data-wl-keep="status">
                    <input type="hidden" name="utility" value="<?= e($listFilters['utility']) ?>" data-wl-keep="utility">
                    <h3 class="wl-panel__sub"><?= e(t('period_show.enter_readings')) ?></h3>
                    <p class="wl-help" id="csc_hint"><?= e(t('worklist.help')) ?></p>
                    <div class="wl-entry-grid">
                        <div class="form-group">
                            <label for="wl_previous"><?= e(t('worklist.csd_readonly')) ?> (<span data-wl-field="unit"><?= e($selected['unit'] ?? '') ?></span>)</label>
                            <input type="text" id="wl_previous" value="<?= e($selected['csdInput'] ?? '') ?>" readonly data-wl-input="csd">
                        </div>
                        <div class="form-group <?= $hasFieldError ? 'has-error' : '' ?>">
                            <label for="current_reading"><?= e(t('worklist.csc_label')) ?> <span class="required">*</span></label>
                            <input type="text" inputmode="decimal" id="current_reading" name="current_reading" autocomplete="off"
                                   value="<?= e(form_value($form, null, 'current_reading')) ?>" data-current-reading data-previous="<?= e($selected['csdInput'] ?? '') ?>"
                                   aria-describedby="csc_hint csc_client_error<?= $hasFieldError ? ' csc_server_error' : '' ?>"<?= $hasFieldError ? ' aria-invalid="true"' : '' ?>>
                            <div class="form-error" id="csc_client_error" role="alert" hidden></div>
                            <?php if ($hasFieldError): ?><div class="form-error" id="csc_server_error" data-wl-server-error><?= e($form['errors']['current_reading'][0]) ?></div><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="wl_cl"><?= e(t('worklist.cl_preview')) ?> (<span data-wl-field="unit"><?= e($selected['unit'] ?? '') ?></span>)</label>
                            <input type="text" id="wl_cl" value="" readonly data-consumption-preview placeholder="<?= e(t('worklist.cl_formula')) ?>" aria-describedby="wl_cl_hint">
                            <div class="form-hint" id="wl_cl_hint"><?= e(t('worklist.cl_hint')) ?></div>
                        </div>
                    </div>
                    <div class="form-group <?= has_error($form, 'note') ? 'has-error' : '' ?>">
                        <label for="note"><?= e(t('worklist.field_note')) ?></label>
                        <input type="text" id="note" name="note" maxlength="500" value="<?= e(form_value($form, null, 'note')) ?>" placeholder="<?= e(t('worklist.note_placeholder')) ?>">
                        <?= field_error($form, 'note') ?>
                    </div>
                    <?php if (has_error($form, 'meter_reset')): ?>
                        <div class="notice-box" style="margin-bottom:16px" data-wl-server-error>
                            <?= t('worklist.csc_lt_1_html') ?>
                            <?= t('worklist.csc_lt_2_html') ?>
                            <?= t('worklist.csc_lt_3_html') ?>
                            <?= t('worklist.csc_lt_4_html') ?>
                        </div>
                    <?php endif; ?>
                    <!-- Thứ tự DOM: CTA chính trước để phím Enter = "Lưu & nhập công tơ tiếp theo"; CSS đảo thứ tự hiển thị -->
                    <div class="wl-actions">
                        <button type="submit" class="btn btn--primary" name="next" value="1"><?= e(t('worklist.save_next')) ?></button>
                        <button type="submit" class="btn btn--secondary" name="next" value="0"><?= e(t('worklist.save_draft')) ?></button>
                        <a class="btn btn--ghost" href="<?= e(base_url($periodPath)) ?>"><?= e(t('common.actions.back_to_period')) ?></a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </section>
</div>

<script type="application/json" id="wl-data"><?= json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
