<?php
/**
 * FN-10.03 email thông báo phí (HTML) - bố cục "statement" khổ lớn (800px) kiểu ngân hàng: navy + xanh nhạt + trắng.
 * Tương thích Gmail: bố cục bảng, CSS inline (khối <style> chỉ để xếp chồng cột trên điện thoại), không ảnh data: / SVG;
 * ảnh nhúng bằng Content-ID: hình tòa nhà đầu thư (cid:header-visual) + QR thanh toán (cid:payment-qr) - config/email_images.php.
 * Mọi giá trị qua e(). Số tiền lấy nguyên từ render() - template không tính toán.
 * @var array{name:string, apartment:string, period:string, period_code:string, rows:array, total:string, pay:array, qr_src:string, header_src:string} $data
 */
$f = "font-family:'Segoe UI',Roboto,'Helvetica Neue',Helvetica,Arial,sans-serif;";
$navy = '#0b1f66';
$royal = '#1f3fbf';
$text = '#33405e';
$muted = '#6b7591';
$line = '#e9edf6';
$summaryAt = 5;      // render(): 0-4 khoản phí, 5 = Tổng phát sinh, 6 = Còn nợ tháng trước
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="x-apple-disable-message-reformatting">
<title><?= e($data['period']) ?></title>
<style>
  @media only screen and (max-width: 640px) {
    .st-pad { padding-left: 20px !important; padding-right: 20px !important; }
    .st-col { display: block !important; width: 100% !important; box-sizing: border-box; }
    .st-head-text { padding: 26px 22px 6px !important; }
    .st-head-img { padding: 0 0 4px !important; text-align: center !important; }
    .st-head-img img { width: 100% !important; max-width: 420px !important; }
    .st-title { font-size: 26px !important; white-space: normal !important; }
    .st-total { font-size: 28px !important; }
    .st-total-label { font-size: 16px !important; }
    .st-qr { padding: 22px 0 8px !important; text-align: center !important; }
    .st-info { padding: 14px 20px 22px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#eef2fa;<?= $f ?>color:<?= $navy ?>;-webkit-font-smoothing:antialiased;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#eef2fa;">Tổng phải thu <?= e($data['total']) ?> · <?= e($data['period']) ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef2fa;">
  <tr><td align="center" style="padding:28px 10px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:800px;background:#ffffff;border:1px solid #e1e8f6;border-radius:22px;border-collapse:separate;overflow:hidden;">

      <!-- Header: chữ bên trái, hình tòa nhà bên phải (full ngang thẻ) -->
      <tr><td style="padding:0;background:#e9f0fd;background-image:linear-gradient(120deg,#f4f7ff 0%,#eaf1fe 45%,#dce7fc 100%);">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td class="st-col st-head-text" valign="middle" style="padding:40px 10px 36px 44px;<?= $f ?>">
              <div style="font-size:12px;font-weight:600;letter-spacing:1.6px;color:<?= $royal ?>;">ENERGY DSS &nbsp;·&nbsp; BAN QUẢN LÝ</div>
              <div class="st-title" style="white-space:nowrap;margin-top:14px;font-size:32px;line-height:1.15;font-weight:700;letter-spacing:-.6px;color:<?= $navy ?>;">Thông báo phí căn hộ</div>
              <div style="margin-top:8px;font-size:18px;font-weight:700;color:<?= $royal ?>;"><?= e($data['apartment']) ?></div>
              <div style="margin-top:16px;font-size:14px;color:<?= $muted ?>;"><?= e($data['period']) ?></div>
            </td>
            <td class="st-col st-head-img" width="360" valign="bottom" align="right" style="padding:18px 0 0;">
              <img src="<?= e($data['header_src']) ?>" width="360" alt="" style="display:block;width:360px;max-width:100%;height:auto;border:0;margin-left:auto;">
            </td>
          </tr>
        </table>
      </td></tr>

      <!-- Lời chào -->
      <tr><td class="st-pad" style="padding:32px 44px 8px;<?= $f ?>font-size:16px;line-height:1.65;color:<?= $text ?>;">
        Kính gửi <strong style="color:<?= $navy ?>;"><?= e($data['name']) ?></strong>,<br>
        <span style="color:<?= $muted ?>;">Ban Quản lý xin gửi thông báo phí của căn hộ trong kỳ như sau:</span>
      </td></tr>

      <!-- Chi tiết phí -->
      <tr><td class="st-pad" style="padding:20px 44px 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid <?= $line ?>;border-radius:18px;border-collapse:separate;">
          <tr><td style="padding:26px 30px 6px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="<?= $f ?>font-size:19px;font-weight:700;color:<?= $navy ?>;">Chi tiết phí</td>
                <td align="right" style="<?= $f ?>font-size:13px;color:<?= $muted ?>;">Đơn vị: VNĐ</td>
              </tr>
              <tr><td colspan="2" style="<?= $f ?>padding:4px 0 14px;font-size:14px;color:<?= $muted ?>;">Các khoản phí phát sinh trong kỳ <?= e($data['period_code']) ?></td></tr>
              <?php foreach ($data['rows'] as $i => [$label, $value]):
                  $summary = $i === $summaryAt;
                  $bg = $summary ? 'background:#f2f6ff;' : '';
                  $cell = $f . $bg . 'border-top:1px solid ' . ($summary ? '#dfe6f5' : $line) . ';padding:15px ' . ($summary ? '14px' : '0') . ';font-size:15px;';
              ?>
              <tr>
                <td style="<?= $cell ?><?= $summary ? "font-weight:700;color:{$navy};border-radius:10px 0 0 10px;" : "color:{$text};" ?>"><?= e($label) ?></td>
                <td align="right" style="<?= $cell ?>white-space:nowrap;color:<?= $navy ?>;font-weight:<?= $summary ? '700' : '600' ?>;<?= $summary ? 'border-radius:0 10px 10px 0;' : '' ?>"><?= e($value) ?></td>
              </tr>
              <?php endforeach; ?>
            </table>
          </td></tr>
          <!-- Tổng phải thu: khối lớn full ngang -->
          <tr><td style="padding:14px 18px 18px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#e8effd;background-image:linear-gradient(100deg,#eef3ff 0%,#dfe8fc 100%);border-radius:14px;border-collapse:separate;">
              <tr>
                <td class="st-total-label" style="<?= $f ?>padding:26px 26px;font-size:19px;font-weight:700;color:<?= $navy ?>;">Tổng phải thu</td>
                <td align="right" style="<?= $f ?>padding:26px 26px;white-space:nowrap;"><span class="st-total" style="font-size:40px;line-height:1;font-weight:800;letter-spacing:-1px;color:<?= $navy ?>;"><?= e($data['total']) ?></span></td>
              </tr>
            </table>
          </td></tr>
        </table>
      </td></tr>

      <!-- Thanh toán QR: full ngang, QR lớn bên trái, thông tin tài khoản bên phải -->
      <tr><td class="st-pad" style="padding:24px 44px 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f6ff;border:1px solid #e1e8f6;border-radius:18px;border-collapse:separate;">
          <tr>
            <td class="st-col st-qr" width="266" valign="middle" align="center" style="padding:26px 0 26px 26px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="background:#ffffff;border:1px solid #e1e8f6;border-radius:16px;border-collapse:separate;">
                <tr><td style="padding:14px;">
                  <img src="<?= e($data['qr_src']) ?>" width="212" alt="Mã QR thanh toán <?= e($data['pay']['bank']) ?> — <?= e($data['pay']['account_name']) ?>"
                       style="display:block;width:212px;max-width:100%;height:auto;border:0;">
                </td></tr>
              </table>
            </td>
            <td class="st-col st-info" valign="middle" style="padding:26px 30px 26px 26px;<?= $f ?>">
              <span style="display:inline-block;padding:4px 12px;background:#dfe8fc;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:1.2px;color:<?= $royal ?>;">THANH TOÁN</span>
              <div style="margin-top:10px;font-size:22px;font-weight:700;color:<?= $navy ?>;">Quét QR để thanh toán</div>
              <div style="margin-top:6px;font-size:14px;line-height:1.5;color:<?= $muted ?>;">Mở ứng dụng ngân hàng, quét mã QR để chuyển khoản nhanh chóng và an toàn.</div>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;background:#ffffff;border:1px solid #e1e8f6;border-radius:14px;border-collapse:separate;">
                <tr>
                  <td style="<?= $f ?>padding:14px 18px;font-size:14px;color:<?= $muted ?>;border-bottom:1px solid <?= $line ?>;">Chủ tài khoản</td>
                  <td align="right" style="<?= $f ?>padding:14px 18px;font-size:15px;font-weight:700;letter-spacing:.3px;color:<?= $navy ?>;border-bottom:1px solid <?= $line ?>;"><?= e($data['pay']['account_name']) ?></td>
                </tr>
                <tr>
                  <td style="<?= $f ?>padding:14px 18px;font-size:14px;color:<?= $muted ?>;border-bottom:1px solid <?= $line ?>;">Số tài khoản</td>
                  <td align="right" style="<?= $f ?>padding:14px 18px;font-size:15px;font-weight:700;letter-spacing:1px;color:<?= $navy ?>;border-bottom:1px solid <?= $line ?>;"><?= e($data['pay']['account_number_masked']) ?></td>
                </tr>
                <tr>
                  <td style="<?= $f ?>padding:14px 18px;font-size:14px;color:<?= $muted ?>;">Ngân hàng</td>
                  <td align="right" style="<?= $f ?>padding:14px 18px;color:<?= $navy ?>;">
                    <div style="font-size:15px;font-weight:800;color:<?= $royal ?>;"><span><?= e($data['pay']['bank']) ?></span></div>
                    <div style="font-size:12px;color:<?= $muted ?>;"><?= e($data['pay']['bank_full_name']) ?></div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td></tr>

      <!-- Footer -->
      <tr><td class="st-pad" style="padding:28px 44px 32px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid <?= $line ?>;">
          <tr>
            <td class="st-col" valign="middle" style="<?= $f ?>padding-top:18px;font-size:12px;line-height:1.65;color:#8f99b3;">
              Email được gửi tự động từ hệ thống <span style="color:<?= $royal ?>;font-weight:600;">Energy DSS</span> theo yêu cầu của Ban Quản lý.<br>
              Vui lòng không trả lời email này. Mọi thắc mắc xin liên hệ Ban Quản lý.
            </td>
            <td class="st-col" valign="middle" align="right" style="<?= $f ?>padding-top:18px;font-size:14px;font-weight:800;letter-spacing:1px;color:<?= $royal ?>;white-space:nowrap;">ENERGY DSS</td>
          </tr>
        </table>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
