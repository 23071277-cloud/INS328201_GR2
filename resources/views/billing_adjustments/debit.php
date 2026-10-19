<?php
$snapshot = json_decode($d['snapshot'],true);
$parkingCents = 0;
foreach ($snapshot['current']['fees'] as $fee) { if ($fee['group'] === 'parking') $parkingCents += \App\Services\MoneyCalculator::fromDb($fee['amount']); }
?>
<div class="page-head"><h1 class="page-title">Phiếu ghi nợ bổ sung #<?= e($d['id']) ?></h1><a class="btn" href="<?= e(base_url('/billing-adjustments/'.$d['request_id'])) ?>">Đề nghị và quyết định duyệt</a></div>
<div class="card"><p>Bản tính gốc #<?= e($d['charge_id']) ?> · <?= e($snapshot['current']['apartment']['code']) ?> · Kỳ <?= e($snapshot['period']['period_code']) ?></p>
<p>Đối soát #<?= e($d['reconciliation_id']) ?> · Người duyệt #<?= e($d['approved_by']) ?> · Lập lúc <?= e($d['created_at']) ?></p>
<p><strong>Ghi nợ bổ sung: <?= e(money_vnd($d['amount'])) ?></strong></p>
<p>Phí gửi xe gốc: <?= e(money_vnd($snapshot['charge']['parking_fee_amount'])) ?>. Nghĩa vụ gửi xe được đối soát: <?= e(money_vnd($parkingCents,true)) ?>.</p>
<table class="table"><thead><tr><th>Loại phí</th><th>Số lượng</th><th>Đơn giá</th><th>Phiên bản / hiệu lực</th><th>Số tiền</th></tr></thead><tbody>
<?php foreach ($snapshot['current']['fees'] as $f): if ($f['group'] !== 'parking') continue; ?><tr><td><?= e(config('pricing.fee_type')[$f['fee_type']]['label'] ?? $f['fee_type']) ?></td><td><?= e($f['base_quantity']) ?></td><td><?= e(money_vnd($f['unit_price'])) ?></td><td>#<?= e($f['rate_id']) ?> / <?= e($f['rate_effective_date']) ?></td><td><?= e(money_vnd($f['amount'])) ?></td></tr><?php endforeach; ?></tbody></table>
<p>Phiếu bất biến, không thay thế bản gốc. Chưa phát hành email và chưa ghi nhận thanh toán phí bổ sung.</p></div>
<?php require __DIR__.'/balances.php'; require __DIR__.'/history.php'; ?>
