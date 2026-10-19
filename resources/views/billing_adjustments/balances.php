<div class="card"><h2>Phiếu gốc và phí bổ sung</h2>
<table class="table"><thead><tr><th>Khoản</th><th>Phải thu</th><th>Đã ghi nhận</th><th>Còn lại</th></tr></thead><tbody>
<tr><td>Bản tính gốc (giữ nguyên)</td><td><?= e(money_vnd($balances['original'])) ?></td><td><?= e(money_vnd($balances['original_paid'])) ?></td><td><?= e(money_vnd($balances['original_remaining'])) ?></td></tr>
<tr><td>Ghi nợ bổ sung<?= $balances['debit'] ? ' #'.e($balances['debit']['id']) : '' ?></td><td><?= e(money_vnd($balances['supplementary'])) ?></td><td>0,00 VND</td><td><?= e(money_vnd($balances['supplementary_remaining'])) ?></td></tr>
</tbody></table><p><strong>Tổng nghĩa vụ: <?= e(money_vnd($balances['combined'])) ?></strong></p>
<p class="form-hint">Không phân bổ khoản đã trả sang phí bổ sung. Phiên bản này chưa hỗ trợ thu / gửi email phí bổ sung. Lịch sử thanh toán gốc giữ nguyên.</p></div>
