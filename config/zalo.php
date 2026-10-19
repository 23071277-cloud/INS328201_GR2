<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Gửi phiếu thông báo phí qua Zalo (ZNS - Zalo Notification Service). Mọi giá trị lấy từ .env (KHÔNG lưu DB, KHÔNG hiện trên giao diện).
 * driver: log (mặc định - demo offline: không gửi, chỉ ghi dòng tóm tắt vào storage/logs/zalo.log)
 *       | zns (gửi thật qua Zalo Official Account) | array (test tự động).
 * Mẫu ZNS (template) phải được Zalo duyệt trước; tên tham số của mẫu khai báo ở 'bill_params' (khoá nội bộ => tên tham số trong mẫu).
 * Khoá nội bộ: customer_name, apartment, period, total_due, payment_ref, due_date. Tham số nào mẫu không có -> xoá khỏi bản đồ.
 */
return [
    'driver' => strtolower((string) Env::get('ZALO_DRIVER', 'log')),
    'drivers' => ['log', 'zns', 'array'],
    'api_url' => (string) Env::get('ZALO_API_URL', 'https://business.openapi.zalo.me/message/template'),
    'oauth_url' => (string) Env::get('ZALO_OAUTH_URL', 'https://oauth.zaloapp.com/v4/oa/access_token'),
    'app_id' => (string) Env::get('ZALO_APP_ID', ''),
    'app_secret' => (string) Env::get('ZALO_APP_SECRET', ''),
    // Token ban đầu (lấy ở Zalo OA / Zalo for Developers). Sau lần làm mới đầu tiên, token mới được lưu ở token_file.
    'access_token' => (string) Env::get('ZALO_ACCESS_TOKEN', ''),
    'refresh_token' => (string) Env::get('ZALO_REFRESH_TOKEN', ''),
    'token_file' => BASE_PATH . '/storage/zalo_token.json',
    'template_bill' => (string) Env::get('ZALO_TEMPLATE_BILL', ''),
    // "development" = gửi thử, chỉ tới số quản trị OA, không tốn hạn mức. Để trống khi chạy thật.
    'mode' => strtolower((string) Env::get('ZALO_MODE', '')),
    'timeout' => (int) Env::get('ZALO_TIMEOUT', 15),

    'bill_params' => [
        'customer_name' => 'customer_name',
        'apartment' => 'apartment_code',
        'period' => 'period_code',
        'total_due' => 'total_due',
        'payment_ref' => 'payment_code',
    ],
    // Giới hạn độ dài tham số (ký tự) theo quy định mẫu ZNS - cắt bớt để Zalo không từ chối
    'param_max_length' => ['customer_name' => 30, 'apartment' => 30, 'period' => 30, 'total_due' => 30, 'payment_ref' => 30],
];
