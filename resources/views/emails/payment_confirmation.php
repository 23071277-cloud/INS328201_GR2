<?php
/**
 * Payment module: email xác nhận đã nhận thanh toán (HTML) - cùng phong cách "statement" với email thông báo phí (navy + xanh nhạt + trắng),
 * tương thích Gmail (bảng + CSS inline; ảnh đầu thư nhúng cid:header-visual). Mọi giá trị qua e(); số tiền lấy nguyên từ giao dịch.
 * @var array{name:string, apartment:string, period_code:string, amount:string, transaction_id:string, received_at:string, ref:string, header_src:string} $data
 */
$f = "font-family:'Segoe UI',Roboto,'Helvetica Neue',Helvetica,Arial,sans-serif;";
$navy = '#0b1f66';
$royal = '#1f3fbf';
$muted = '#6b7591';
$line = '#e9edf6';
$rows = [
    ['Căn hộ', $data['apartment']],
    ['Kỳ thanh toán', $data['period_code']],
    ['Mã thanh toán', $data['ref']],
    ['Mã giao dịch', $data['transaction_id']],
    ['Thời gian ghi nhận', $data['received_at']],
];
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="x-apple-disable-message-reformatting">
<title>Xác nhận thanh toán <?= e($data['period_code']) ?></title>
<style>
  @media only screen and (max-width: 640px) {
    .pc-pad { padding-left: 20px !important; padding-right: 20px !important; }
    .pc-col { display: block !important; width: 100% !important; box-sizing: border-box; }
    .pc-head-text { padding: 26px 22px 6px !important; }
    .pc-head-img { padding: 0 0 4px !important; text-align: center !important; }
    .pc-head-img img { width: 100% !important; max-width: 420px !important; }
    .pc-amount { font-size: 30px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#eef2fa;<?= $f ?>color:<?= $navy ?>;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#eef2fa;">Đã nhận thanh toán <?= e($data['amount']) ?> · kỳ <?= e($data['period_code']) ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef2fa;">
  <tr><td align="center" style="padding:28px 10px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:800px;background:#ffffff;border:1px solid #e1e8f6;border-radius:22px;border-collapse:separate;overflow:hidden;">
      <tr><td style="padding:0;background:#e9f0fd;background-image:linear-gradient(120deg,#f4f7ff 0%,#eaf1fe 45%,#dce7fc 100%);">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td class="pc-col pc-head-text" valign="middle" style="padding:40px 10px 36px 44px;<?= $f ?>">
              <div style="font-size:12px;font-weight:600;letter-spacing:1.6px;color:<?= $royal ?>;">ENERGY DSS &nbsp;·&nbsp; BAN QUẢN LÝ</div>
              <div style="margin-top:14px;font-size:32px;line-height:1.15;font-weight:700;letter-spacing:-.6px;color:<?= $navy ?>;">Xác nhận thanh toán</div>
              <div style="margin-top:8px;font-size:18px;font-weight:700;color:<?= $royal ?>;"><?= e($data['apartment']) ?></div>
              <div style="margin-top:16px;font-size:14px;color:<?= $muted ?>;">Kỳ <?= e($data['period_code']) ?></div>
            </td>
            <td class="pc-col pc-head-img" width="360" valign="bottom" align="right" style="padding:18px 0 0;">
              <img src="<?= e($data['header_src']) ?>" width="360" alt="" style="display:block;width:360px;max-width:100%;height:auto;border:0;margin-left:auto;">
            </td>
          </tr>
        </table>
      </td></tr>

      <tr><td class="pc-pad" style="padding:32px 44px 8px;<?= $f ?>font-size:16px;line-height:1.65;color:#33405e;">
        Kính gửi <strong style="color:<?= $navy ?>;"><?= e($data['name']) ?></strong>,<br>
        <span style="color:<?= $muted ?>;">Ban Quản lý xác nhận đã nhận khoản thanh toán phí căn hộ của Quý cư dân.</span>
      </td></tr>

      <tr><td class="pc-pad" style="padding:20px 44px 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#e8effd;background-image:linear-gradient(100deg,#eef3ff 0%,#dfe8fc 100%);border-radius:16px;border-collapse:separate;">
          <tr>
            <td style="<?= $f ?>padding:26px 26px;">
              <span style="display:inline-block;padding:4px 12px;background:#ffffff;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:1.2px;color:#067647;">ĐÃ THANH TOÁN</span>
              <div style="margin-top:10px;font-size:15px;font-weight:600;color:#33405e;">Số tiền đã nhận</div>
            </td>
            <td align="right" style="<?= $f ?>padding:26px 26px;white-space:nowrap;"><span class="pc-amount" style="font-size:40px;line-height:1;font-weight:800;letter-spacing:-1px;color:<?= $navy ?>;"><?= e($data['amount']) ?></span></td>
          </tr>
        </table>
      </td></tr>

      <tr><td class="pc-pad" style="padding:22px 44px 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid <?= $line ?>;border-radius:18px;border-collapse:separate;">
          <tr><td style="padding:10px 30px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <?php foreach ($rows as $i => [$label, $value]): ?>
              <tr>
                <td style="<?= $f ?><?= $i > 0 ? "border-top:1px solid {$line};" : '' ?>padding:14px 0;font-size:15px;color:<?= $muted ?>;"><?= e($label) ?></td>
                <td align="right" style="<?= $f ?><?= $i > 0 ? "border-top:1px solid {$line};" : '' ?>padding:14px 0;font-size:15px;font-weight:700;color:<?= $navy ?>;"><?= e($value) ?></td>
              </tr>
              <?php endforeach; ?>
            </table>
          </td></tr>
        </table>
      </td></tr>

      <tr><td class="pc-pad" style="padding:28px 44px 32px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid <?= $line ?>;">
          <tr>
            <td class="pc-col" valign="middle" style="<?= $f ?>padding-top:18px;font-size:12px;line-height:1.65;color:#8f99b3;">
              Email được gửi tự động từ hệ thống <span style="color:<?= $royal ?>;font-weight:600;">Energy DSS</span> theo yêu cầu của Ban Quản lý.<br>
              Vui lòng không trả lời email này. Mọi thắc mắc xin liên hệ Ban Quản lý.
            </td>
            <td class="pc-col" valign="middle" align="right" style="<?= $f ?>padding-top:18px;font-size:14px;font-weight:800;letter-spacing:1px;color:<?= $royal ?>;white-space:nowrap;">ENERGY DSS</td>
          </tr>
        </table>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
