<?php
// Phiếu thông báo phí cho cư dân (in / lưu PDF) = nội dung đầu ra nghiệp vụ: LUÔN tiếng Việt, không theo ngôn ngữ giao diện.
\App\Core\Lang::force('vi');
/** @var array $notices (BillNoticeService::forCharge) @var array $skipped @var array $period */
$units = config('pricing.utility_unit');
$feeTypes = config('pricing.fee_type');
$isBatch = count($notices) !== 1 || $skipped !== [];
?>
<div class="page-head no-print">
    <div>
        <h1 class="page-title">Phiếu thông báo phí — kỳ <?= e($period['period_code']) ?></h1>
        <p class="page-subtitle"><?= t('guide.notice_period') ?></p>
    </div>
    <div class="page-head__actions"><button type="button" class="btn btn--primary" onclick="window.print()">In / Lưu PDF</button></div>
</div>

<?php if (($email ?? null) !== null): $chargeId = (int) $notices[0]['charge']['id']; $fullContact = can('residents.view_phone'); ?>
<div class="panel no-print">
    <div class="panel__head"><h2 class="panel__title">Gửi qua email</h2>
        <?php if (can('email_notifications.view')): ?><a class="btn btn--ghost btn--sm" href="<?= e(url_with('/email-notifications', ['apartment' => (string) $notices[0]['charge']['apartment_code']])) ?>">Hộp thư đi</a><?php endif; ?></div>
    <?php if (can('email_notifications.send')): ?>
        <?php if ($email['recipients'] === []): ?>
            <div class="empty-state"><p>Căn hộ chưa có cư dân đang hiệu lực nào có email. BQL bổ sung email ở thông tin chủ hộ / khách thuê.</p></div>
        <?php else: ?>
            <form method="post" action="<?= e(base_url('/charges/' . $chargeId . '/email')) ?>" class="toolbar js-confirm" data-confirm-title="Gửi email thông báo phí" data-confirm-message="Gửi phiếu thông báo phí kỳ này tới người nhận đã chọn?" data-confirm-button="Gửi">
                <?= csrf_field() ?>
                <div class="form-group form-group--grow"><label for="resident_id">Người nhận</label>
                    <select id="resident_id" name="resident_id">
                        <?php foreach ($email['recipients'] as $r): ?>
                            <option value="<?= (int) $r['id'] ?>"><?= e($r['full_name'] . ' (' . label_of('resident_role', $r['resident_role']) . ') — ' . ($fullContact ? $r['email'] : mask_email($r['email']))) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <button type="submit" class="btn btn--primary btn--sm">Gửi qua email</button>
            </form>
            <p class="form-hint">Gửi tóm tắt phiếu dạng HTML + văn bản (không đính kèm PDF, không gửi SĐT). Không gửi trùng cùng nội dung tới cùng người nhận. Chế độ gửi hiện tại: <strong><?= e(config('mail.driver') === 'smtp' ? 'SMTP (gửi thật)' : 'demo "' . config('mail.driver') . '" — không gửi ra Internet') ?></strong>.</p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (($email['history'] ?? []) !== []): ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>#</th><th>Người nhận</th><th>Trạng thái</th><th class="num">Lần gửi</th><th>Gửi lúc</th><th>Lỗi</th></tr></thead>
            <tbody><?php foreach ($email['history'] as $h): ?>
                <tr><td><a href="<?= e(base_url('/email-notifications/' . $h['id'])) ?>">#<?= (int) $h['id'] ?></a></td>
                    <td><?= e(($h['recipient_name'] ?? '') . ' — ' . ($fullContact ? $h['recipient_email'] : mask_email($h['recipient_email']))) ?></td>
                    <td><?= email_status_badge($h['status']) ?></td><td class="num"><?= (int) $h['attempt_count'] ?></td>
                    <td><?= e($h['sent_at'] ?? '—') ?></td><td class="cell-wrap"><?= e($h['last_error'] ?? '') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (($zalo ?? null) !== null): $chargeId = (int) $notices[0]['charge']['id']; $fullPhone = can('residents.view_phone'); ?>
<div class="panel no-print">
    <div class="panel__head"><h2 class="panel__title"><?= e(t('zalo.send_via')) ?></h2>
        <?php if (can('zalo_notifications.view')): ?><a class="btn btn--ghost btn--sm" href="<?= e(url_with('/zalo-notifications', ['apartment' => (string) $notices[0]['charge']['apartment_code']])) ?>"><?= e(t('zalo.title')) ?></a><?php endif; ?></div>
    <?php if (can('zalo_notifications.send')): ?>
        <?php if ($zalo['recipients'] === []): ?>
            <div class="empty-state"><p><?= e(t('zalo.no_recipient')) ?></p></div>
        <?php else: ?>
            <form method="post" action="<?= e(base_url('/charges/' . $chargeId . '/zalo')) ?>" class="toolbar js-confirm" data-confirm-title="<?= e(t('zalo.confirm_send_title')) ?>" data-confirm-message="<?= e(t('zalo.confirm_send_msg')) ?>" data-confirm-button="<?= e(t('zalo.confirm_btn')) ?>">
                <?= csrf_field() ?>
                <div class="form-group form-group--grow"><label for="zalo_resident_id"><?= e(t('zalo.recipient')) ?></label>
                    <select id="zalo_resident_id" name="resident_id">
                        <?php foreach ($zalo['recipients'] as $r): ?>
                            <option value="<?= (int) $r['id'] ?>"><?= e($r['full_name'] . ' (' . label_of('resident_role', $r['resident_role']) . ') — ' . ($fullPhone ? $r['phone'] : \App\Services\ZaloClient::maskPhone($r['phone84']))) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <button type="submit" class="btn btn--zalo btn--sm"><?= e(t('zalo.send_via')) ?></button>
            </form>
            <p class="form-hint"><?= e(t('zalo.hint_body')) ?> <strong><?= e($zalo['driver'] === 'zns' ? t('zalo.mode_real') : t('zalo.mode_demo', ['mode' => $zalo['driver']])) ?></strong>.</p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (($zalo['history'] ?? []) !== []): ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>#</th><th><?= e(t('zalo.recipient')) ?></th><th><?= e(t('zalo.status')) ?></th><th class="num"><?= e(t('zalo.attempts')) ?></th><th><?= e(t('zalo.sent_at')) ?></th><th><?= e(t('zalo.error')) ?></th></tr></thead>
            <tbody><?php foreach ($zalo['history'] as $h): ?>
                <tr><td><a href="<?= e(base_url('/zalo-notifications/' . $h['id'])) ?>">#<?= (int) $h['id'] ?></a></td>
                    <td><?= e(($h['recipient_name'] ?? '') . ' — ' . ($fullPhone ? '0' . substr((string) $h['recipient_phone'], 2) : \App\Services\ZaloClient::maskPhone($h['recipient_phone']))) ?></td>
                    <td><?= email_status_badge($h['status']) ?></td><td class="num"><?= (int) $h['attempt_count'] ?></td>
                    <td><?= e($h['sent_at'] ?? '—') ?></td><td class="cell-wrap"><?= e($h['last_error'] ?? '') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($skipped !== []): ?>
    <div class="notice-box no-print" style="margin-bottom:16px"><strong>Không xuất <?= count($skipped) ?> phiếu:</strong>
        <ul><?php foreach ($skipped as $s): ?><li><?= e($s['apartment_code']) ?> — <?= e($s['reason']) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($notices === []): ?><div class="empty-state"><p>Không có phiếu nào đủ điều kiện xuất cho kỳ này.</p></div><?php endif; ?>

<?php foreach ($notices as $n): $c = $n['charge']; ?>
<section class="bill panel">
    <h2 class="bill__title">THÔNG BÁO KIÊM PHIẾU THU TIỀN</h2>
    <p class="bill__sub">Kỳ <?= e($c['period_code']) ?> (<?= e(format_date($c['start_date'])) ?> – <?= e(format_date($c['end_date'])) ?>)</p>
    <dl class="detail-grid">
        <div><dt>Căn hộ</dt><dd><strong><?= e($c['building_code'] . ' / ' . $c['apartment_code']) ?></strong><?= $c['zone_code'] ? ' · khu ' . e($c['zone_code']) : '' ?></dd></div>
        <div><dt>Chủ hộ</dt><dd><?= e($n['owner'] ?? '—') ?></dd></div>
        <div><dt>Diện tích</dt><dd><?= e(format_decimal($c['area'])) ?> m²</dd></div>
        <div><dt>Trạng thái bản tính</dt><dd><?= e(config_labels('billing.charge_status')[$c['status']] ?? $c['status']) ?></dd></div>
    </dl>

    <?php foreach (['dien' => 'Tiền điện', 'nuoc' => 'Tiền nước'] as $u => $label): ?>
        <?php $meters = array_filter($n['meters'], static fn ($m) => $m['reading']['utility_type'] === $u); ?>
        <h3 class="section-title"><?= e($label) ?></h3>
        <?php if ($meters === []): ?><p class="form-hint">Không phát sinh.</p><?php endif; ?>
        <?php foreach ($meters as $m): $r = $m['reading']; $s = $m['snapshot'] ?? null; ?>
            <table class="data-table bill__table">
                <thead><tr><th colspan="5">Công tơ <?= e($r['meter_code']) ?> — <?= e(label_of('usage_type', $r['usage_type'])) ?> · CSĐ <?= e(format_decimal($r['previous_reading'])) ?> · CSC <?= e(format_decimal($r['current_reading'])) ?> · CL <?= e(format_decimal($r['reading_consumption'])) ?> <?= e($units[$u]) ?></th></tr>
                    <tr><th>Bậc</th><th>Khoảng</th><th class="num">Sản lượng</th><th class="num">Đơn giá</th><th class="num">Thành tiền</th></tr></thead>
                <tbody><?php foreach ($m['lines'] as $l): ?><tr><td><?= (int) $l['tier_order'] ?></td><td><?= e(tier_range($l['tier_from'], $l['tier_to'], $units[$u])) ?></td><td class="num"><?= e(format_decimal($l['quantity'])) ?></td><td class="num"><?= e(money_vnd($l['unit_price'])) ?></td><td class="num"><?= e(money_vnd($l['amount'])) ?></td></tr><?php endforeach; ?></tbody>
                <tfoot><tr><th colspan="4">Thành tiền trước thuế</th><th class="num"><?= e(money_vnd($m['subtotal'], true)) ?></th></tr>
                    <?php if ($s !== null): ?><tr><th colspan="4">VAT <?= $s['vat_percent'] === null ? '(không tách VAT)' : e(format_decimal($s['vat_percent'])) . '%' ?></th><th class="num"><?= e(money_vnd($s['vat'])) ?></th></tr><?php endif; ?></tfoot>
            </table>
        <?php endforeach; ?>
        <?php if ($u === 'dien'): ?>
            <p class="bill__sum">Tiền điện: <?= e(money_vnd($c['electricity_amount'])) ?> + VAT <?= e(money_vnd($c['electricity_vat_amount'])) ?> = <strong><?= e(money_vnd($n['columns']['electricity_amount'] + $n['columns']['electricity_vat_amount'], true)) ?></strong></p>
        <?php else: ?>
            <p class="bill__sum">Tiền nước: <strong><?= e(money_vnd($c['water_amount'])) ?></strong></p>
        <?php endif; ?>
    <?php endforeach; ?>

    <h3 class="section-title">Phí cố định &amp; gửi xe</h3>
    <?php if ($n['fees'] !== null): ?>
        <table class="data-table bill__table"><thead><tr><th>Khoản</th><th class="num">Cơ sở tính</th><th class="num">Đơn giá</th><th class="num">Thành tiền</th></tr></thead>
            <tbody><?php foreach ($n['fees'] as $fee): ?><tr><td><?= e($feeTypes[$fee['fee_type']]['label'] ?? $fee['fee_type']) ?></td><td class="num"><?= e(format_decimal($fee['base_quantity'])) ?> <?= e($fee['base_unit']) ?></td><td class="num"><?= e(money_vnd($fee['unit_price'])) ?></td><td class="num"><?= e(money_vnd($fee['amount'])) ?></td></tr><?php endforeach; ?></tbody></table>
    <?php else: ?>
        <table class="data-table bill__table"><tbody>
            <tr><td>Phí dịch vụ</td><td class="num"><?= e(money_vnd($c['service_fee_amount'])) ?></td></tr>
            <tr><td>Bảo hiểm cháy nổ</td><td class="num"><?= e(money_vnd($c['fire_insurance_amount'])) ?></td></tr>
            <tr><td>Gửi xe</td><td class="num"><?= e(money_vnd($c['parking_fee_amount'])) ?></td></tr>
        </tbody></table>
        <p class="form-hint">Bản tính không có snapshot cơ sở tính (dữ liệu cũ) — chỉ có số tiền theo khoản.</p>
    <?php endif; ?>

    <table class="data-table bill__table bill__total"><tbody>
        <tr><td>Tổng phát sinh kỳ này</td><td class="num"><?= e(money_vnd($c['total_arising'])) ?></td></tr>
        <tr><td>Còn nợ tháng trước</td><td class="num"><?= e(money_vnd($c['old_debt_amount'])) ?></td></tr>
        <tr><th>TỔNG PHẢI THU</th><th class="num"><?= e(money_vnd($c['total_due'])) ?></th></tr>
    </tbody></table>
    <p class="form-hint">Thông tin tài khoản thanh toán: <?= e($n['bank_account'] ?? 'Chưa cấu hình') ?>.</p>
    <?php $pay = (new \App\Services\PaymentStatusService())->forCharge($c); /* Payment module: null nếu DB chưa có bảng thanh toán */ ?>
    <?php if ($pay !== null): ?>
        <p class="bill__payment" data-payment-ref="<?= e($pay['ref']) ?>">Mã thanh toán (ghi vào nội dung chuyển khoản): <strong><?= e($pay['ref']) ?></strong> &nbsp;·&nbsp; Trạng thái thanh toán: <?= payment_status_badge($pay['status']) ?></p>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<?php \App\Core\Lang::force(null); ?>
