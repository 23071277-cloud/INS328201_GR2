<?php

declare(strict_types=1);

/**
 * FN-07 Cảnh báo & xử lý bất thường (STEP 11) - nhãn ENUM của schema (khoá = giá trị DB, KHÔNG đổi).
 * Không chứa giá trị ngưỡng: ngưỡng nằm trong bảng alert_rules do BQL cấu hình (BR-25, J-32).
 * Cảnh báo là tín hiệu cần xem xét, KHÔNG phải kết luận sự cố (BR-29).
 */
return [
    'utility_word' => ['dien' => 'điện', 'nuoc' => 'nước'],
    // Nhãn tiện ích hiển thị của cảnh báo (dashboard). 'both' = J-16 utility_type NULL (CHỈ với alert_type no_usage_consumption)
    'utility_label' => ['dien' => 'Điện', 'nuoc' => 'Nước', 'both' => 'Điện + Nước'],
    'unknown_label' => 'Không xác định',
    'measure_type' => ['tieu_thu' => 'Tiêu thụ', 'chi_phi' => 'Chi phí'],
    'compare_base' => ['ky_truoc' => 'So với kỳ trước (%)', 'baseline' => 'So với baseline (%)', 'gia_tri_tuyet_doi' => 'Giá trị tuyệt đối'],
    // alert_rules.operator - schema chỉ có gt/gte
    'operator' => ['gt' => '>', 'gte' => '≥'],
    'rule_scope_type' => ['toan_toa' => 'Toàn tòa', 'zone' => 'Khu vực', 'usage_type' => 'Loại sử dụng'],
    'rule_status' => ['bat' => 'Bật', 'tat' => 'Tắt'],
    'priority' => ['cao' => 'Cao', 'trung_binh' => 'Trung bình', 'thap' => 'Thấp'],
    'source' => ['rule' => 'Tự động (quy tắc)', 'thu_cong' => 'Thủ công', 'he_thong' => 'Hệ thống'],
    // alerts.alert_type (patch 005): threshold = FN-07; no_usage_consumption = [BA Proposal J-16], xem config/j16.php
    'alert_type' => ['threshold' => 'Ngưỡng / thủ công', 'no_usage_consumption' => 'Tiêu thụ tại căn khai báo không sử dụng'],
    // alerts.status - G2 / [ASSUMPTION A-02 - J-04]
    'status' => [
        'moi' => 'Mới',
        'da_review' => 'Đã review',
        'can_kiem_tra_lai' => 'Cần kiểm tra lại',
        'da_xac_nhan' => 'Đã xác nhận',
        'da_dong' => 'Đã đóng',
    ],
    // inspection_cases - G3 [ĐX], kết luận K-15 (ENUM schema)
    'case_status' => ['da_giao' => 'Đã giao', 'da_co_ket_qua' => 'Đã có kết quả', 'da_dong' => 'Đã đóng'],
    'conclusion' => [
        'canh_bao_dung' => 'Cảnh báo đúng',
        'canh_bao_sai' => 'Cảnh báo sai',
        'do_ghi_sai' => 'Do ghi sai chỉ số',
        'khong_xac_dinh' => 'Không xác định được',
    ],
    // [ASSUMPTION S15] ngưỡng % hợp lý ([ĐX] FN-07.01 mục 8)
    'max_percent_threshold' => 1000,
];
