<?php
/**
 * Bộ sửa bậc thang dùng chung (biểu giá FN-03.01 và đề xuất FN-03.03).
 * Quy ước Schema Review A1: nửa khoảng [Từ, Đến) — bậc sau bắt đầu ĐÚNG tại "Đến" của bậc trước,
 * bậc cuối để trống "Đến". JS chỉ hỗ trợ nhập; mọi ràng buộc kiểm ở TariffService::validateTiers().
 * @var array $tierRows  [['tier_from'=>..,'tier_to'=>..,'unit_price'=>..], ...]
 * @var array $form      trạng thái form (lỗi 'tiers' và 'tiers.{i}')
 * @var string $unitLabel đơn vị (kWh / m³)
 */
if ($tierRows === []) {
    $tierRows = [['tier_from' => '0', 'tier_to' => '', 'unit_price' => '']];
}
?>
<div class="tier-editor" data-tier-editor>
    <div class="table-wrap">
        <table class="data-table tier-table">
            <thead>
            <tr><th><?= e(t('common.fields.tier')) ?></th><th><?= t('ui.from') ?><span data-unit-label><?= e($unitLabel) ?></span>)</th><th><?= t('ui.to_exclusive') ?></th><th><?= t('ui.unit_price_vnd') ?></th><th></th></tr>
            </thead>
            <tbody data-tier-rows>
            <?php foreach (array_values($tierRows) as $i => $row): ?>
                <tr data-tier-row class="<?= has_error($form, 'tiers.' . $i) ? 'has-error-row' : '' ?>">
                    <td data-tier-no><?= $i + 1 ?></td>
                    <td><input type="text" inputmode="decimal" name="tiers[<?= $i ?>][tier_from]" value="<?= e(format_input($row['tier_from'] ?? '')) ?>" data-tier-from></td>
                    <td><input type="text" inputmode="decimal" name="tiers[<?= $i ?>][tier_to]" value="<?= e(format_input($row['tier_to'] ?? '')) ?>" placeholder="<?= t('ui.leave_empty_for_the_last_tier') ?>" data-tier-to></td>
                    <td><input type="text" inputmode="decimal" name="tiers[<?= $i ?>][unit_price]" value="<?= e(format_input($row['unit_price'] ?? '')) ?>"></td>
                    <td><button type="button" class="btn btn--ghost btn--sm" data-tier-remove title="<?= t('ui.remove_tier') ?>">✕</button></td>
                </tr>
                <?php foreach ($form['errors']['tiers.' . $i] ?? [] as $message): ?>
                    <tr class="tier-error-row"><td colspan="5"><div class="form-error"><?= e(message_t($message)) ?></div></td></tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= field_error($form, 'tiers') ?>
    <div class="form-actions">
        <button type="button" class="btn btn--ghost btn--sm" data-tier-add><?= t('ui.add_tier') ?></button>
    </div>
    <div class="form-hint">
        <?= t('ui.tiers_use_half_open_intervals') ?> <strong><?= t('ui.from_to') ?></strong><?= t('ui.tier_1_starts_at_0_each') ?> <strong><?= t('ui.exactly_at') ?></strong> <?= t('ui.the_previous_tier_s_to_e') ?>
    </div>
</div>
