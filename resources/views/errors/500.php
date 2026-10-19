<?php
// Trang lỗi dùng được cả khi lỗi xảy ra sớm: nếu chưa có hàm t() thì dùng chữ Việt mặc định.
$tr = static function (string $key, string $fallback): string {
    try { return function_exists('t') ? (string) t($key) : $fallback; } catch (\Throwable $e) { return $fallback; }
};
$lang = (function_exists('locale') ? locale() : 'vi') === 'en' ? 'en' : 'vi';
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>500 - <?= htmlspecialchars($tr('ui.error_500_title', 'Lỗi hệ thống'), ENT_QUOTES, 'UTF-8') ?></title>
    <!-- Style inline có chủ đích: trang này phải hiển thị được cả khi lỗi xảy ra trước khi tải được CSS của ứng dụng. -->
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background:#f4f6fa; color:#344054; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:16px; box-sizing:border-box; }
        .box { width:100%; max-width:460px; text-align:center; background:#fff; border:1px solid #e4e8ef; border-radius:16px; padding:40px 36px; box-shadow:0 4px 12px rgba(16,24,40,.08); box-sizing:border-box; }
        .code { font-size:56px; font-weight:800; letter-spacing:-.03em; line-height:1; color:#b42318; margin:0 0 14px; }
        .msg { margin:0 0 24px; line-height:1.6; }
        a { display:inline-block; padding:9px 16px; border-radius:8px; background:#2b59c3; color:#fff; font-weight:600; font-size:14px; text-decoration:none; }
        a:hover { background:#2248a3; }
    </style>
</head>
<body>
<div class="box">
    <p class="code">500</p>
    <p class="msg"><?= htmlspecialchars($tr('ui.error_500_msg', 'Đã có lỗi xảy ra. Vui lòng thử lại sau.'), ENT_QUOTES, 'UTF-8') ?></p>
    <p><a href="<?= htmlspecialchars(function_exists('base_url') ? base_url('/') : '/', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tr('ui.back_home', 'Về trang chủ'), ENT_QUOTES, 'UTF-8') ?></a></p>
</div>
</body>
</html>
