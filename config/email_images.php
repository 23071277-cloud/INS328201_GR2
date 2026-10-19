<?php

declare(strict_types=1);

/**
 * Ảnh nhúng trong email HTML (FN-10.03): Content-ID => file (tương đối BASE_PATH).
 * HTML tham chiếu bằng src="cid:<id>"; EmailNotificationService::inlineImages() chỉ gửi kèm ảnh mà HTML thật sự dùng.
 * Gmail không hiển thị ảnh data: và không tải được ảnh từ máy nội bộ -> phải nhúng. Hộp thư đi xem lại bằng file tĩnh cùng tên.
 */
return [
    'payment-qr' => 'public/assets/img/payment-qr.png',        // QR VietQR / Napas 247 do ngân hàng cấp (ảnh gốc, không tạo lại)
    'header-visual' => 'public/assets/img/email-header.png',  // hình tòa nhà ở đầu thư (ảnh do Ban Quản lý cung cấp, đã cắt vùng trong suốt + thu nhỏ)
];
