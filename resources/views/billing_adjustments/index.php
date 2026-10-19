<div class="page-head"><h1 class="page-title">Đối soát sau phát hành</h1></div>
<?php if (!$ready): ?><div class="alert alert-warning">Chưa triển khai migration. Chức năng chỉ đọc; chưa thể lập đề nghị.</div><?php endif; ?>
<div class="card"><p>Lập đề nghị từ trang chi tiết bản tính đã gửi phiếu. Không sửa phiếu gốc.</p>
<table class="table"><thead><tr><th>Đề nghị</th><th>Căn / kỳ</th><th>Trạng thái</th><th>Ghi nợ đề nghị</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><a href="<?= e(base_url('/billing-adjustments/'.$r['id'])) ?>">#<?= e($r['id']) ?></a></td><td><?= e($r['code'].' / '.$r['period_code']) ?></td><td><?= e(\App\Services\BillingAdjustmentService::statusLabel($r['status'])) ?></td><td><?= e(money_vnd($r['amount'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
