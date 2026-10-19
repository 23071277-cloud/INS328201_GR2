<?php

declare(strict_types=1);

/**
 * FN-06 Baseline, so sánh & mục tiêu (STEP 10) - nhãn ENUM của schema (khoá = giá trị DB, KHÔNG đổi)
 * và các tham số chưa được BA chốt. FN-06 KHÔNG sinh cảnh báo / kết luận bất thường (BR-29, FN-07).
 */
return [
    // baselines.method - [TL] FN-06.01 mục 5 (4 phương pháp); chọn phương pháp chính thức: J-20
    'baseline_method' => [
        'ky_truoc' => 'Kỳ trước liền kề',
        'trung_binh_n_ky' => 'Trung bình N kỳ gần nhất',
        'cung_ky_nam_truoc' => 'Cùng kỳ năm trước',
        'nhap_tay' => 'Giá trị nhập tay',
    ],

    // baselines.scope_type (có 'meter'); consumption_targets.scope_type không có 'meter'
    'scope_type' => [
        'meter' => 'Công tơ',
        'apartment' => 'Căn hộ',
        'zone' => 'Khu vực',
        'building' => 'Tòa nhà',
    ],
    'target_scope_types' => ['apartment', 'zone', 'building'],

    // Vòng đời hiển thị phiên bản baseline - tính theo ngày, không lưu (như FN-03 P2)
    'baseline_lifecycle' => [
        'dang_ap_dung' => 'Đang áp dụng',
        'sap_ap_dung' => 'Sắp áp dụng',
        'da_thay_the' => 'Đã thay thế',
        'het_hieu_luc' => 'Hết hiệu lực',
    ],

    // [ĐX] FN-06.01 mục 8: N < 3 -> cảnh báo độ tin cậy thấp ([TL] T2 câu 25: tối thiểu 3 kỳ)
    'min_reliable_periods' => 3,
    'default_n_periods' => 3,
    'max_n_periods' => 24,

    // Trạng thái dữ liệu của một phép đo (KHÔNG lưu DB - kết quả kỹ thuật cho UI / FN-07)
    'data_status' => [
        'ok' => 'Đủ dữ liệu',
        'no_data' => 'Không có dữ liệu',
        'incomplete' => 'Chưa đủ dữ liệu (còn chỉ số Nháp / chưa ghi số)',
        'meter_changed' => 'Có thay công tơ',
    ],

    // Kết quả theo dõi mục tiêu - hiển thị dẫn xuất, không lưu ([TL] KPI pass/fail; "Đạt" = thực tế ≤ mục tiêu [SL])
    'target_status' => [
        'dat' => 'Đạt',
        'khong_dat' => 'Không đạt',
        'chua_du_du_lieu' => 'Chưa đủ dữ liệu',
    ],

    // Mục tiêu = 0: tài liệu [ĐX] "> 0", yêu cầu STEP 10 "≥ 0" -> NEED BA CONFIRMATION; tạm cho phép 0
    'target_allow_zero' => true,
];
