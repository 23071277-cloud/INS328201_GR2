<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Thanh toán.
 *  - Khối "Quét QR để thanh toán" trong EMAIL thông báo phí (FN-10.03): tài khoản hiển thị dạng đã che; ảnh QR (VietQR / Napas 247
 *    do ngân hàng cấp) nhúng bằng Content-ID - xem config/email_images.php.
 *  - Payment module: mã thanh toán (nội dung chuyển khoản) suy ra từ id bản tính; Mock Bank (ngân hàng GIẢ LẬP, không tích hợp
 *    ngân hàng thật) CHỈ bật khi .env / biến môi trường có MOCK_BANK_ENABLED=1 VÀ MOCK_BANK_SECRET (>= 16 ký tự) - mặc định TẮT.
 */
return [
    'account_name' => 'NGUYEN HONG PHUONG',
    'account_number_masked' => '941*****999',
    'bank' => 'MB',
    'bank_full_name' => 'Ngân hàng TMCP Quân đội',
    'qr_cid' => 'payment-qr',

    // Mã thanh toán = tiền tố + id bản tính (đủ 6 chữ số), vd EDSS000012. Đối soát tìm mã này trong nội dung chuyển khoản.
    'ref_prefix' => 'EDSS',
    'ref_digits' => 6,

    'mock_bank' => [
        'enabled' => in_array(strtolower((string) Env::get('MOCK_BANK_ENABLED', '0')), ['1', 'true', 'yes', 'on'], true),
        'secret' => (string) Env::get('MOCK_BANK_SECRET', ''),
        'bank_code' => 'MOCKBANK',
    ],
];
