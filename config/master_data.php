<?php

declare(strict_types=1);

/**
 * FN-02: nhãn hiển thị cho các ENUM của schema (database/energy_dss_v2.sql).
 * Khoá mảng = giá trị lưu DB (KHÔNG đổi), giá trị = nhãn hiển thị.
 * Đây là nguồn duy nhất cho cả dropdown (View) và rule `in` (Service),
 * nên không có danh sách giá trị hợp lệ nào bị hardcode lặp lại.
 */
return [
    'record_status' => [
        'hoat_dong' => 'Hoạt động',
        'ngung' => 'Ngừng',
    ],

    // [SL] 2.1 - zones.zone_type (nullable)
    'zone_type' => [
        'lien_ke' => 'Liền kề',
        'shophouse' => 'Shophouse',
        'biet_thu' => 'Biệt thự',
        'chung_cu' => 'Chung cư',
    ],

    // [ASSUMPTION A-12 - J-16] - BQL khai báo thủ công, dùng cho BR-15
    'occupancy_status' => [
        'co_o' => 'Có ở (phát sinh điện nước)',
        'khong_phat_sinh' => 'Không phát sinh điện nước',
    ],

    'utility_type' => [
        'dien' => 'Điện (ELECTRICITY)',
        'nuoc' => 'Nước (WATER)',
    ],

    // [TL] 2.2 - quyết định biểu giá áp dụng (BR-06)
    'usage_type' => [
        'sinh_hoat' => 'Sinh hoạt (RESIDENTIAL)',
        'kinh_doanh' => 'Kinh doanh (BUSINESS)',
    ],

    'resident_role' => [
        'chu_ho' => 'Chủ hộ',
        'khach_thue' => 'Khách thuê',
    ],

    // [TL] 2.1 - parking_vehicles.vehicle_type
    'vehicle_type' => [
        'xe_dap_dien' => 'Xe đạp / xe điện',
        'xe_may' => 'Xe máy',
        'o_to' => 'Ô tô',
    ],

    // FN-01.03 (STEP 15) - users.role / users.status. Đúng 3 vai trò (FR-01; J-01 đã chốt).
    'user_role' => [
        'bql' => 'Ban quản lý (BQL)',
        'ky_thuat' => 'Kỹ thuật',
        'ke_toan' => 'Kế toán',
    ],
    'user_status' => [
        'hoat_dong' => 'Hoạt động',
        'ngung' => 'Ngừng (vô hiệu hóa)',
        'khoa_tam' => 'Khóa tạm',
    ],

    'per_page' => 20,
];
