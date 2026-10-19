<?php

declare(strict_types=1);

/**
 * FN-08 Khuyến nghị hỗ trợ quyết định (STEP 12) - nhãn ENUM schema (khoá = giá trị DB, KHÔNG đổi).
 * Không có AI: khuyến nghị sinh từ rule Bật + dữ liệu FN-06/FN-07 + điều kiện tất định. Nội dung lấy từ DB.
 */
return [
    // recommendation_rules.condition_type - [TL] FR-10 / [ĐX] FN-08.01
    'condition_type' => [
        'cl_zero_n_ky' => 'CL = 0 trong N kỳ liên tiếp',
        'vuot_nguong_lien_tiep' => 'Vượt ngưỡng (cùng quy tắc cảnh báo) N kỳ liên tiếp',
        'khong_dat_muc_tieu' => 'Không đạt mục tiêu tiêu thụ của căn hộ',
    ],
    // loại cần tham số n_periods
    'needs_n_periods' => ['cl_zero_n_ky', 'vuot_nguong_lien_tiep'],
    'max_n_periods' => 12,
    'rule_status' => ['bat' => 'Bật', 'tat' => 'Tắt'],
    // placeholder cho phép trong nội dung (T6) - thay bằng giá trị thuần, escape khi hiển thị
    'placeholders' => ['{utility}', '{period}', '{apartment}', '{meter}', '{n_periods}'],
];
