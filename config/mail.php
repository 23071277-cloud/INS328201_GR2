<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * STEP 25 - FN-10.03 cấu hình gửi email. Mọi giá trị lấy từ .env (KHÔNG có trên giao diện, KHÔNG lưu DB).
 * driver: smtp (gửi thật, vd Gmail SMTP + App Password) | log (demo offline: không gửi, chỉ ghi dòng tóm tắt vào
 * storage/logs/mail.log) | array (test tự động: giữ trong bộ nhớ). Mặc định "log" để không vô tình gửi ra Internet.
 */
return [
    'driver' => strtolower((string) Env::get('MAIL_DRIVER', 'log')),
    'host' => (string) Env::get('MAIL_HOST', 'smtp.gmail.com'),
    'port' => (int) Env::get('MAIL_PORT', 587),
    'username' => (string) Env::get('MAIL_USERNAME', ''),
    // App Password của Gmail - chỉ đọc khi gửi, không in ra log / giao diện / audit
    'password' => (string) Env::get('MAIL_PASSWORD', ''),
    'encryption' => strtolower((string) Env::get('MAIL_ENCRYPTION', 'tls')),
    'from_address' => (string) Env::get('MAIL_FROM_ADDRESS', ''),
    'from_name' => (string) Env::get('MAIL_FROM_NAME', 'Energy DSS'),
    'timeout' => (int) Env::get('MAIL_TIMEOUT', 15),
    // Chỉ dùng cho kiểm tra gửi thật thủ công (tests/support/gmail_smoke.php); để trống = bỏ qua
    'test_recipient' => (string) Env::get('MAIL_TEST_RECIPIENT', ''),
    'drivers' => ['smtp', 'log', 'array'],
];
