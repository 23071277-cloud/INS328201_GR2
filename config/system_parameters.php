<?php

declare(strict_types=1);

/**
 * FN-01.07 Cấu hình tham số hệ thống (STEP 15) - WHITELIST các khoá được sửa trên giao diện (V8).
 * Chỉ khoá có trong bảng system_configs và thuộc nhóm "runtime" ([ĐX] FN-01.07 mục 5). KHÔNG có trên UI:
 * cấu hình triển khai trong .env (DB_*, APP_DEBUG, APP_URL, APP_ENV…) và tham số nghiệp vụ của FN-03/06/07 (BR-25).
 * 'env' = biến .env làm giá trị mặc định khi DB không đọc được / giá trị lưu không hợp lệ.
 */
return [
    'session_timeout_minutes' => [
        'label' => 'Thời gian hết phiên (phút không hoạt động)',
        'type' => 'integer', 'min' => 5, 'max' => 480, 'env' => 'SESSION_TIMEOUT_MINUTES', 'default' => '30',
        'source' => 'Mặc định 30 phút; cho phép 5–480',
        'applies' => 'Mọi phiên, từ request kế tiếp',
    ],
    'max_failed_login_attempts' => [
        'label' => 'Số lần đăng nhập sai trước khi khóa tạm',
        'type' => 'integer', 'min' => 3, 'max' => 20, 'env' => 'MAX_FAILED_LOGIN_ATTEMPTS', 'default' => '5',
        'source' => 'Mặc định 5 lần; cho phép 3–20',
        'applies' => 'Lần đăng nhập sai kế tiếp',
    ],
    'account_lock_duration_minutes' => [
        'label' => 'Thời gian khóa tạm (phút)',
        'type' => 'integer', 'min' => 1, 'max' => 1440, 'env' => 'ACCOUNT_LOCK_DURATION_MINUTES', 'default' => '30',
        'source' => 'Mặc định 30 phút; cho phép 1–1440',
        'applies' => 'Lần khóa tạm kế tiếp',
    ],
    'bank_account_info' => [
        'label' => 'Thông tin tài khoản thanh toán in trên phiếu',
        'type' => 'string', 'min' => 1, 'max' => 255, 'env' => null, 'default' => 'Chưa cấu hình',
        'source' => 'Cấu hình hệ thống',
        'applies' => 'Phiếu thông báo xuất sau khi đổi',
    ],
    'import_strict_mode' => [
        'label' => 'Nhập chỉ số từ file: lưu tất cả hoặc không lưu gì',
        'type' => 'enum', 'values' => ['0' => '0 — lưu từng phần (dòng hợp lệ)', '1' => '1 — còn dòng lỗi thì không lưu dòng nào'],
        'env' => null, 'default' => '0',
        'source' => 'Cấu hình hệ thống',
        'applies' => 'Lần xác nhận import kế tiếp',
    ],
];
