<div class="adjustment-charge-page">
<div class="page-head"><div><h1 class="page-title">Đối soát bản tính #<?= e($id) ?></h1></div><div class="page-head__actions"><a class="btn btn--secondary" href="<?= e(base_url('/billing-adjustments')) ?>">Danh sách đề nghị</a></div></div>
<section class="adjustment-financial"><div class="adjustment-section-head"><h2>Phiếu gốc và phí bổ sung</h2><span class="form-hint">Khoản</span></div>
<div class="adjustment-summary-grid">
<article class="adjustment-summary-card"><h3>Bản tính gốc (giữ nguyên)</h3><dl>
<div class="adjustment-summary-main"><dt>Phải thu</dt><dd><?= e(money_vnd($balances['original'])) ?></dd></div>
<div><dt>Đã ghi nhận</dt><dd><?= e(money_vnd($balances['original_paid'])) ?></dd></div>
<div><dt>Còn lại</dt><dd><?= e(money_vnd($balances['original_remaining'])) ?></dd></div>
</dl></article>
<article class="adjustment-summary-card"><h3>Ghi nợ bổ sung<?= $balances['debit'] ? ' #'.e($balances['debit']['id']) : '' ?></h3><dl>
<div class="adjustment-summary-main"><dt>Phải thu</dt><dd><?= e(money_vnd($balances['supplementary'])) ?></dd></div>
<div><dt>Đã ghi nhận</dt><dd>0,00 VND</dd></div>
<div><dt>Còn lại</dt><dd><?= e(money_vnd($balances['supplementary_remaining'])) ?></dd></div>
</dl></article>
<article class="adjustment-summary-card adjustment-summary-total"><h3>Tổng nghĩa vụ:</h3><p class="adjustment-total-value"><?= e(money_vnd($balances['combined'])) ?></p></article>
</div>
<p class="form-hint adjustment-financial-note">Không phân bổ khoản đã trả sang phí bổ sung. Phiên bản này chưa hỗ trợ thu / gửi email phí bổ sung. Lịch sử thanh toán gốc giữ nguyên.</p></section>
<?php foreach ($form['errors'] as $errors): foreach ($errors as $message): ?><div class="alert alert-danger"><?= e($message) ?></div><?php endforeach; endforeach; ?>
<?php if (!$ready): ?><p>Chưa triển khai migration; không thể lập đề nghị.</p><?php endif; ?>
<?php if ($error): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endif; ?>
<?php if ($snapshot): ?>
<section class="card adjustment-proposal-card"><h2>Cơ sở đề nghị — <?= e($snapshot['period']['period_code']) ?></h2>
<p class="adjustment-proposal-note">Phí gửi xe bổ sung đề nghị: <strong><?= e(money_vnd($snapshot['amount'])) ?></strong>. Ngày nhập xe không tự quyết định kỳ áp dụng.</p>
<div class="table-wrap"><table class="table"><thead><tr><th>Loại phí</th><th>Số lượng</th><th>Đơn giá</th><th>Phiên bản / hiệu lực</th><th>Số tiền hiện tại</th></tr></thead><tbody>
<?php foreach ($snapshot['current']['fees'] as $fee): if ($fee['group'] !== 'parking') continue; ?><tr><td><?= e(config('pricing.fee_type')[$fee['fee_type']]['label'] ?? $fee['fee_type']) ?></td><td><?= e($fee['base_quantity']) ?></td><td><?= e(money_vnd($fee['unit_price'])) ?></td><td>#<?= e($fee['rate_id']) ?> / <?= e($fee['rate_effective_date']) ?></td><td><?= e(money_vnd($fee['amount'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if (can('billing_adjustments.submit')): ?><form class="adjustment-proposal-form" method="post" action="<?= e(base_url('/charges/'.$id.'/adjustments')) ?>">
<?= csrf_field() ?><input type="hidden" name="input_hash" value="<?= e(\App\Services\BillingAdjustmentService::fingerprint($snapshot)) ?>"><input type="hidden" name="idempotency_key" value="<?= e(bin2hex(random_bytes(32))) ?>">
<div class="form-group adjustment-form-wide"><label>Ngày bắt đầu áp dụng (phải là ngày đầu kỳ)</label><input class="form-control" type="date" name="effective_date" required value="<?= e($form['old']['effective_date'] ?? '') ?>"></div>
<div class="form-group adjustment-form-wide adjustment-confirm"><label><input type="checkbox" name="full_period" value="1" required> Xác nhận các số lượng xe trên áp dụng toàn kỳ này theo quyết định nghiệp vụ</label></div>
<div class="form-group"><label>Giải thích</label><textarea class="form-control" name="explanation" maxlength="1000" required><?= e($form['old']['explanation'] ?? '') ?></textarea></div>
<div class="form-group"><label>Bằng chứng / tham chiếu quyết định và ngày áp dụng</label><textarea class="form-control" name="evidence" maxlength="2000" required><?= e($form['old']['evidence'] ?? '') ?></textarea></div>
<div class="adjustment-form-actions"><button class="btn btn-primary btn--primary">Gửi đề nghị cho Ban quản lý</button></div></form><?php endif; ?></section><?php endif; ?>
<section class="adjustment-history"><?php require __DIR__.'/history.php'; ?><div class="adjustment-history-empty" aria-hidden="true">—</div></section>
</div>
