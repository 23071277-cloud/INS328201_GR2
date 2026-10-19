<?php
/**
 * [BA Proposal J-16] email yêu cầu xác minh tiêu thụ (HTML) - nội dung trung tính, KHÔNG kết luận nguyên nhân; mọi giá trị qua e().
 * @var array{name:string, apartment:string, period:string, electricity:string, water:string} $data
 */
?>
<!doctype html>
<html lang="vi">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Yêu cầu xác minh tiêu thụ - <?= e($data['apartment']) ?></title></head>
<body style="margin:0;padding:0;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;color:#101828;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fa;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #e4e8ef;border-radius:12px;">
      <tr><td style="padding:22px 28px;border-bottom:1px solid #e4e8ef;">
        <div style="font-size:13px;color:#2b59c3;font-weight:bold;letter-spacing:.3px;">ENERGY DSS · BAN QUẢN LÝ</div>
        <div style="font-size:20px;font-weight:bold;margin-top:6px;">Yêu cầu xác minh tiêu thụ điện/nước</div>
        <div style="font-size:14px;color:#667085;margin-top:4px;">Căn <?= e($data['apartment']) ?> · kỳ <?= e($data['period']) ?></div>
      </td></tr>
      <tr><td style="padding:22px 28px;font-size:14px;line-height:1.6;">
        <p style="margin:0 0 14px;">Kính gửi <strong><?= e($data['name']) ?></strong>,</p>
        <p style="margin:0 0 14px;">Ban Quản lý ghi nhận căn hộ <strong><?= e($data['apartment']) ?></strong> hiện đang được khai báo ở trạng thái 'Không phát sinh điện nước'. Tuy nhiên, dữ liệu công tơ kỳ <?= e($data['period']) ?> ghi nhận mức tiêu thụ:</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;margin-bottom:14px;">
          <tr><td style="padding:8px 0;border-bottom:1px solid #eef1f5;color:#344054;">Điện</td><td align="right" style="padding:8px 0;border-bottom:1px solid #eef1f5;"><?= e($data['electricity']) ?></td></tr>
          <tr><td style="padding:8px 0;border-bottom:1px solid #eef1f5;color:#344054;">Nước</td><td align="right" style="padding:8px 0;border-bottom:1px solid #eef1f5;"><?= e($data['water']) ?></td></tr>
        </table>
        <p style="margin:0 0 10px;">Ban Quản lý đang thực hiện kiểm tra lại chỉ số và tình trạng công tơ.</p>
        <p style="margin:0 0 10px;"><strong>Đây chưa phải kết luận về nguyên nhân phát sinh tiêu thụ.</strong></p>
        <p style="margin:0;">Nếu căn hộ hiện có người sử dụng, sửa chữa, cho thuê hoặc có hoạt động liên quan đến điện/nước, vui lòng phản hồi Ban Quản lý để hỗ trợ xác minh.</p>
      </td></tr>
      <tr><td style="padding:16px 28px;border-top:1px solid #e4e8ef;font-size:12px;color:#98a2b3;line-height:1.5;">
        Email gửi tự động từ hệ thống Energy DSS theo yêu cầu của Ban Quản lý. Vui lòng không trả lời email này; xin liên hệ trực tiếp Ban Quản lý.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
