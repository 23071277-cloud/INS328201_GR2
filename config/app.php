<?php

declare(strict_types=1);

/**
 * Cấu hình chung của ứng dụng, đọc từ .env (có giá trị mặc định an toàn).
 *
 * [ASSUMPTION - J-03]: account_lock_duration_minutes = 30 phút CHƯA ĐƯỢC
 * ĐẶC TẢ TRONG TÀI LIỆU (tài liệu chỉ nói "khoá tạm", không nói bao lâu).
 * max_failed_login_attempts = 5 là [TL] BR-02 (đúng theo tài liệu, không phải giả định).
 */
return [
    'name' => \App\Core\Env::get('APP_NAME', 'Energy & Utility DSS'),
    'env' => \App\Core\Env::get('APP_ENV', 'local'),
    'debug' => filter_var(\App\Core\Env::get('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN),
    'url' => rtrim((string) \App\Core\Env::get('APP_URL', 'http://localhost/energy-dss/public'), '/'),
    'timezone' => \App\Core\Env::get('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'),

    'session_timeout_minutes' => (int) \App\Core\Env::get('SESSION_TIMEOUT_MINUTES', 30),
    'max_failed_login_attempts' => (int) \App\Core\Env::get('MAX_FAILED_LOGIN_ATTEMPTS', 5),
    'account_lock_duration_minutes' => (int) \App\Core\Env::get('ACCOUNT_LOCK_DURATION_MINUTES', 30),
];
