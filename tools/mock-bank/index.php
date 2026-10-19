<?php

declare(strict_types=1);

/**
 * MOCK BANK - ứng dụng ngân hàng GIẢ LẬP độc lập (demo). KHÔNG thuộc Energy DSS: không dùng mã / phiên / đăng nhập / quyền của Energy DSS.
 * Demo local qua Apache: htdocs/mock-bank -> tools/mock-bank, mở http://localhost/mock-bank/ (.htaccess: chỉ index.php, chỉ máy local).
 * Cấu hình: config.local.php (không phát hành, chỉ máy demo) trả về ['secret' => ..., 'callback_url' => ...]; biến môi trường
 * MOCK_BANK_SECRET / MOCK_BANK_CALLBACK_URL được ưu tiên hơn (test chạy bằng php -S). secret phải trùng MOCK_BANK_SECRET trong .env Energy DSS.
 *
 * Bấm "Gửi callback": dựng payload JSON (mã thanh toán, số tiền, mã giao dịch, người chuyển, trạng thái SUCCESS), ký HMAC-SHA256 bằng
 * secret và POST tới callback của Energy DSS (header X-Signature). Mọi đối soát / thanh toán xử lý trong Energy DSS.
 * An toàn: chỉ gửi tới callback trên 127.0.0.1 / localhost (không gọi được production); Energy DSS tắt callback mặc định (404).
 */

date_default_timezone_set('Asia/Ho_Chi_Minh');   // cùng múi giờ với Energy DSS / MariaDB (+07) cho received_at
session_start();

$local = is_file(__DIR__ . '/config.local.php') ? (array) require __DIR__ . '/config.local.php' : [];
$secret = (string) (getenv('MOCK_BANK_SECRET') ?: ($local['secret'] ?? ''));
$callbackUrl = (string) (getenv('MOCK_BANK_CALLBACK_URL') ?: ($local['callback_url'] ?? 'http://localhost/energy-dss/public/payments/callback'));
$host = strtolower((string) parse_url($callbackUrl, PHP_URL_HOST));
$configErrors = [];
if (strlen($secret) < 16) {
    $configErrors[] = 'Thiếu MOCK_BANK_SECRET (≥ 16 ký tự, trùng MOCK_BANK_SECRET trong .env của Energy DSS).';
}
if (!in_array($host, ['127.0.0.1', 'localhost'], true) || !preg_match('#^https?://#', $callbackUrl)) {
    $configErrors[] = 'MOCK_BANK_CALLBACK_URL chỉ được trỏ tới máy local (127.0.0.1 / localhost) — Mock Bank chỉ dùng cho demo.';
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$_SESSION['history'] ??= [];
$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$newTxn = static fn (): string => 'MB' . date('ymdHis') . strtoupper(bin2hex(random_bytes(2)));
// Số tiền: "1996240", "1.996.240" (dấu chấm phân nghìn), "1996240.00" (chấm thập phân, 1-2 chữ số cuối), "1996240,5" (phẩy thập phân) -> "1996240.00" dạng chuẩn
$normAmount = static function (string $raw): string {
    $raw = str_replace(' ', '', trim($raw));
    if (!preg_match('/^\d+\.\d{1,2}$/', $raw)) {
        $raw = str_replace(['.', ','], ['', '.'], $raw);
    }

    return $raw;
};

$form = ['ref' => (string) ($_GET['ref'] ?? ''), 'amount' => (string) ($_GET['amount'] ?? ''), 'transaction_id' => $newTxn(), 'payer_name' => ''];
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = [
        'ref' => trim((string) ($_POST['ref'] ?? '')),
        'amount' => $normAmount((string) ($_POST['amount'] ?? '')),
        'transaction_id' => trim((string) ($_POST['transaction_id'] ?? '')),
        'payer_name' => trim((string) ($_POST['payer_name'] ?? '')),
    ];
    $errors = [];
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Phiên form hết hạn, vui lòng tải lại trang.';
    }
    if ($configErrors !== []) {
        $errors = array_merge($errors, $configErrors);
    }
    if ($form['ref'] === '') {
        $errors[] = 'Nhập mã thanh toán (vd EDSS000543).';
    }
    if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $form['amount'])) {
        $errors[] = 'Số tiền phải là số (vd 1996240).';
    }
    if (!preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $form['transaction_id'])) {
        $errors[] = 'Mã giao dịch 1–64 ký tự chữ / số / . _ -.';
    }
    if ($errors === []) {
        $payload = [
            'transaction_id' => $form['transaction_id'],
            'amount' => $form['amount'],
            'content' => $form['ref'],
            'payer_name' => $form['payer_name'],
            'status' => 'SUCCESS',
            'received_at' => date('Y-m-d H:i:s'),
            'bank_code' => 'MOCKBANK',
        ];
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ch = curl_init($callbackUrl);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Signature: ' . hash_hmac('sha256', $body, $secret)]]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $json = is_string($resp) ? json_decode($resp, true) : null;
        $result = ['ok' => $code === 200 && is_array($json) && ($json['ok'] ?? false) === true, 'code' => $code, 'json' => $json,
            'error' => $curlErr !== '' ? $curlErr : ($json['error'] ?? null), 'payload' => $payload];
        array_unshift($_SESSION['history'], ['at' => date('H:i:s'), 'txn' => $payload['transaction_id'], 'ref' => $payload['content'], 'amount' => $payload['amount'],
            'code' => $code, 'match' => $json['match_status'] ?? ($json['error'] ?? $curlErr), 'duplicate' => $json['duplicate'] ?? false]);
        $_SESSION['history'] = array_slice($_SESSION['history'], 0, 10);
        $form['transaction_id'] = $newTxn(); // lần gửi tiếp theo dùng mã mới (muốn thử callback lặp: nhập lại mã cũ)
    } else {
        $result = ['ok' => false, 'code' => 0, 'json' => null, 'error' => implode(' ', $errors), 'payload' => null];
    }
}
$matchText = ['matched' => 'Khớp tự động → bản tính ĐÃ THANH TOÁN', 'manual_matched' => 'Khớp thủ công', 'amount_mismatch' => 'Sai số tiền → Cần kiểm tra',
    'unknown_ref' => 'Không rõ mã thanh toán → Cần kiểm tra', 'not_payable' => 'Kỳ chưa khoá → Cần kiểm tra', 'already_paid' => 'Bản tính đã thanh toán (trả trùng) → Cần kiểm tra'];
$fmt = static fn (string $a): string => number_format((float) $a, 0, ',', '.') . ' đ';
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mock Bank — ngân hàng giả lập (demo)</title>
<style>
  :root { --navy: #0b1f66; --royal: #1f3fbf; --muted: #6b7591; --line: #e3e8f4; --bg: #eef2fa; --ok: #067647; --okbg: #ecfdf3; --bad: #b42318; --badbg: #fef3f2; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: var(--navy); }
  .top { background: var(--navy); color: #fff; padding: 18px 28px; display: flex; align-items: center; justify-content: space-between; }
  .brand { font-weight: 800; letter-spacing: 1px; font-size: 18px; } .brand small { display: block; font-weight: 500; letter-spacing: 0; font-size: 12px; opacity: .75; }
  .pill { background: rgba(255,255,255,.14); border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 600; }
  main { max-width: 980px; margin: 28px auto; padding: 0 16px; display: grid; grid-template-columns: 1.1fr 1fr; gap: 20px; }
  @media (max-width: 820px) { main { grid-template-columns: 1fr; } }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 24px; }
  h1 { font-size: 20px; margin: 0 0 4px; } .sub { color: var(--muted); font-size: 13px; margin: 0 0 18px; }
  label { display: block; font-size: 13px; font-weight: 600; color: #3b4560; margin: 14px 0 6px; }
  input { width: 100%; padding: 11px 12px; border: 1px solid #cfd8ea; border-radius: 10px; font-size: 15px; color: var(--navy); }
  input[readonly] { background: #f5f8fe; }
  .status { display: inline-block; margin-top: 2px; padding: 6px 14px; border-radius: 999px; background: var(--okbg); color: var(--ok); font-weight: 700; font-size: 13px; letter-spacing: .5px; }
  button { margin-top: 20px; width: 100%; padding: 13px; border: 0; border-radius: 10px; background: var(--royal); color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
  button:disabled { opacity: .5; cursor: not-allowed; }
  .msg { border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; font-size: 14px; line-height: 1.5; }
  .msg.ok { background: var(--okbg); color: var(--ok); border: 1px solid #abefc6; } .msg.bad { background: var(--badbg); color: var(--bad); border: 1px solid #fecdca; }
  .msg.warn { background: #fffaeb; color: #b54708; border: 1px solid #fedf89; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; } th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--line); } th { color: var(--muted); font-weight: 600; }
  code { background: #f2f5fb; padding: 1px 6px; border-radius: 6px; }
  .foot { color: var(--muted); font-size: 12px; margin-top: 14px; line-height: 1.5; word-break: break-all; }
</style>
</head>
<body>
<div class="top">
  <div class="brand">MOCK BANK<small>Ngân hàng giả lập — chỉ dùng demo, không chuyển tiền thật</small></div>
  <span class="pill">DEMO · local</span>
</div>
<main>
  <section class="card">
    <h1>Gửi giao dịch</h1>
    <p class="sub">Mô phỏng ngân hàng báo có giao dịch thành công tới Energy DSS (callback ký HMAC-SHA256).</p>
    <?php foreach ($configErrors as $e): ?><div class="msg warn" data-config-error><?= $h($e) ?></div><?php endforeach; ?>
    <?php if ($result !== null): ?>
      <?php if ($result['ok']): $m = (string) ($result['json']['match_status'] ?? ''); ?>
        <div class="msg ok" data-result="ok">
          <strong>Energy DSS đã nhận callback (HTTP <?= (int) $result['code'] ?>).</strong><br>
          Giao dịch <code><?= $h($result['json']['transaction_id'] ?? '') ?></code>:
          <?= ($result['json']['duplicate'] ?? false) ? 'đã được xử lý trước đó — không xử lý lại (idempotent).' : $h($matchText[$m] ?? $m) ?>
          <?= isset($result['json']['charge_ref']) && $result['json']['charge_ref'] !== null ? ' · Bản tính ' . $h($result['json']['charge_ref']) : '' ?>
        </div>
      <?php else: ?>
        <div class="msg bad" data-result="error"><strong>Không gửi được<?= $result['code'] ? ' (HTTP ' . (int) $result['code'] . ')' : '' ?>.</strong> <?= $h($result['error'] ?? 'Lỗi không xác định') ?></div>
      <?php endif; ?>
    <?php endif; ?>
    <form method="post" action="" data-mock-bank-form>
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
      <label for="ref">Mã thanh toán (nội dung chuyển khoản)</label>
      <input id="ref" name="ref" maxlength="100" required value="<?= $h($form['ref']) ?>" placeholder="VD: EDSS000543">
      <label for="amount">Số tiền (VNĐ)</label>
      <input id="amount" name="amount" inputmode="numeric" required value="<?= $h($form['amount']) ?>" placeholder="VD: 1996240">
      <label for="transaction_id">Mã giao dịch ngân hàng</label>
      <input id="transaction_id" name="transaction_id" maxlength="64" required value="<?= $h($form['transaction_id']) ?>">
      <label for="payer_name">Tên người chuyển</label>
      <input id="payer_name" name="payer_name" maxlength="150" value="<?= $h($form['payer_name']) ?>" placeholder="VD: Nguyen Van A">
      <label>Trạng thái giao dịch</label>
      <span class="status" data-status>SUCCESS</span>
      <button type="submit" <?= $configErrors !== [] ? 'disabled' : '' ?>>Gửi callback</button>
    </form>
    <div class="foot">Callback: <code><?= $h($callbackUrl) ?></code></div>
  </section>
  <section class="card">
    <h1>Lịch sử gửi (phiên này)</h1>
    <p class="sub">Gửi lại cùng mã giao dịch = callback lặp → Energy DSS không xử lý lại.</p>
    <?php if ($_SESSION['history'] === []): ?>
      <p class="sub">Chưa gửi giao dịch nào.</p>
    <?php else: ?>
      <table data-history><thead><tr><th>Lúc</th><th>Mã GD</th><th>Mã TT</th><th>Số tiền</th><th>Kết quả</th></tr></thead><tbody>
      <?php foreach ($_SESSION['history'] as $x): ?>
        <tr><td><?= $h($x['at']) ?></td><td><code><?= $h($x['txn']) ?></code></td><td><?= $h($x['ref']) ?></td><td><?= $h($fmt($x['amount'])) ?></td>
            <td><?= $x['code'] === 200 ? ($x['duplicate'] ? 'trùng (idempotent)' : $h($x['match'])) : 'HTTP ' . (int) $x['code'] . ' ' . $h($x['match']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
