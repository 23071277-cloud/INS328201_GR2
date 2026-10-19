<?php
$snapshot = json_decode($q['snapshot'],true);
$statusClasses = ['submitted'=>'badge--warning','approved'=>'badge--active','rejected'=>'badge--danger','withdrawn'=>'badge--muted','stale_input'=>'badge--warning'];
?>
<div class="adjustment-detail-page">
<header class="card adjustment-detail-header">
<div><h1 class="page-title">Đề nghị đối soát #<?= e($q['id']) ?></h1><span class="badge <?= e($statusClasses[$q['status']] ?? 'badge--muted') ?>"><?= e(\App\Services\BillingAdjustmentService::statusLabel($q['status'])) ?></span></div>
<a class="btn btn--secondary" href="<?= e(base_url('/charges/'.$q['charge_id'].'/adjustments')) ?>">Bản gốc và lịch sử</a>
</header>
<?php if ($q['status'] === 'approved' && !$reconciliationCurrent): ?><div class="alert alert-warning">Dữ liệu hiện tại không còn khớp quyết định đã duyệt. Phiếu bổ sung giữ nguyên; đối soát này không cho phép khóa kỳ.</div><?php endif; ?>
<section class="card adjustment-detail-info"><h2>Thông tin đề nghị</h2>
<dl class="adjustment-info-grid">
<div><dt>Kỳ</dt><dd><?= e($snapshot['period']['period_code']) ?></dd></div>
<div><dt>Căn hộ</dt><dd><?= e($snapshot['current']['apartment']['code']) ?></dd></div>
<div><dt>Bản tính gốc</dt><dd>#<?= e($q['charge_id']) ?></dd></div>
<div><dt>Ngày bắt đầu áp dụng</dt><dd><?= e(format_date($q['effective_date'])) ?></dd></div>
<div class="adjustment-info-amount"><dt>Ghi nợ đề nghị</dt><dd><?= e(money_vnd($q['amount'])) ?></dd></div>
</dl>
<div class="adjustment-content-grid">
<div class="adjustment-content-block"><h3>Giải thích</h3><p><?= e($q['explanation']) ?></p></div>
<div class="adjustment-content-block"><h3>Bằng chứng / tham chiếu</h3><p><?= e($q['evidence']) ?></p></div>
</div></section>
<section class="card adjustment-detail-fees"><h2>Phí gửi xe</h2>
<div class="adjustment-detail-table-wrap" tabindex="0" role="region" aria-label="Phí gửi xe">
<table class="table"><thead><tr><th>Loại phí</th><th>Số lượng</th><th>Đơn giá</th><th>Phiên bản</th><th>Số tiền</th></tr></thead><tbody>
<?php foreach ($snapshot['current']['fees'] as $f): if ($f['group'] !== 'parking') continue; ?><tr><td><?= e(config('pricing.fee_type')[$f['fee_type']]['label'] ?? $f['fee_type']) ?></td><td><?= e($f['base_quantity']) ?></td><td><?= e(money_vnd($f['unit_price'])) ?></td><td>#<?= e($f['rate_id']) ?> / <?= e($f['rate_effective_date']) ?></td><td><?= e(money_vnd($f['amount'])) ?></td></tr><?php endforeach; ?></tbody></table>
</div><div class="adjustment-fees-total"><span>Ghi nợ đề nghị</span><strong><?= e(money_vnd($q['amount'])) ?></strong></div></section>
<?php if ($q['status'] === 'submitted'): ?>
<?php foreach (['approve'=>'Duyệt đối soát và lập ghi nợ','reject'=>'Từ chối','withdraw'=>'Rút đề nghị'] as $action=>$label):
if ($action === 'withdraw' ? (!can('billing_adjustments.submit') || auth_user()['id'] != $q['initiated_by']) : (!can('billing_adjustments.decide') || auth_user()['id'] == $q['initiated_by'])) continue; ?>
<?php if ($action === 'withdraw'): ?><details class="card adjustment-detail-withdraw"><summary>Rút đề nghị</summary><?php else: ?><section class="card adjustment-detail-decision"><?php endif; ?>
<form method="post" action="<?= e(base_url('/billing-adjustments/'.$q['id'].'/'.$action)) ?>"><?= csrf_field() ?><div class="form-group"><label>Lý do <?= e($label) ?></label><textarea class="form-control" name="reason" maxlength="1000" required></textarea></div><button class="btn <?= $action === 'approve' ? 'btn--primary' : 'btn--danger-ghost' ?>"><?= e($label) ?></button></form>
<?php if ($action === 'withdraw'): ?></details><?php else: ?></section><?php endif; ?>
<?php endforeach; endif; ?>
<section class="adjustment-detail-financial"><h2>Phiếu gốc và phí bổ sung</h2>
<div class="adjustment-detail-summary-grid">
<article class="card adjustment-detail-summary"><h3>Bản tính gốc (giữ nguyên)</h3><dl>
<div class="adjustment-detail-summary-main"><dt>Phải thu</dt><dd><?= e(money_vnd($balances['original'])) ?></dd></div>
<div><dt>Đã ghi nhận</dt><dd><?= e(money_vnd($balances['original_paid'])) ?></dd></div>
<div><dt>Còn lại</dt><dd><?= e(money_vnd($balances['original_remaining'])) ?></dd></div>
</dl></article>
<article class="card adjustment-detail-summary"><h3>Ghi nợ bổ sung<?= $balances['debit'] ? ' #'.e($balances['debit']['id']) : '' ?></h3><dl>
<div class="adjustment-detail-summary-main"><dt>Phải thu</dt><dd><?= e(money_vnd($balances['supplementary'])) ?></dd></div>
<div><dt>Đã ghi nhận</dt><dd>0,00 VND</dd></div>
<div><dt>Còn lại</dt><dd><?= e(money_vnd($balances['supplementary_remaining'])) ?></dd></div>
</dl></article>
<article class="card adjustment-detail-summary adjustment-detail-summary-total"><h3>Tổng nghĩa vụ:</h3><p><?= e(money_vnd($balances['combined'])) ?></p></article>
</div><p class="form-hint">Không phân bổ khoản đã trả sang phí bổ sung. Phiên bản này chưa hỗ trợ thu / gửi email phí bổ sung. Lịch sử thanh toán gốc giữ nguyên.</p></section>
<section class="card adjustment-detail-history"><h2>Lịch sử đối soát</h2>
<div class="adjustment-detail-table-wrap" tabindex="0" role="region" aria-label="Lịch sử đối soát">
<table class="table"><thead><tr><th>Đề nghị</th><th>Trạng thái</th><th>Người lập / quyết định</th><th>Ghi nợ</th><th>Lý do</th></tr></thead><tbody>
<?php foreach ($history as $r): ?><tr><td><a href="<?= e(base_url('/billing-adjustments/'.$r['id'])) ?>">#<?= e($r['id']) ?></a><br><?= e($r['created_at']) ?></td><td><span class="badge <?= e($statusClasses[$r['status']] ?? 'badge--muted') ?>"><?= e(\App\Services\BillingAdjustmentService::statusLabel($r['status'])) ?></span><?php if ($r['reconciliation_id']): ?><br>Đối soát #<?= e($r['reconciliation_id']) ?><?php endif; ?></td><td>#<?= e($r['initiated_by']) ?> / <?= e($r['decided_by'] ?? '—') ?><br><?= e($r['decided_at'] ?? '') ?></td><td><?= e(money_vnd($r['amount'])) ?><?php if ($r['debit_id']): ?><br><a href="<?= e(base_url('/supplementary-debits/'.$r['debit_id'])) ?>">Phiếu bổ sung #<?= e($r['debit_id']) ?></a><?php endif; ?></td><td class="adjustment-history-reason"><?= e($r['decision_reason'] ?? $r['explanation']) ?></td></tr><?php endforeach; ?></tbody></table>
</div></section>
</div>
