<?php

declare(strict_types=1);

/**
 * [BA Proposal / Assumption J-16] "Status–Consumption Consistency Verification" - NOT ORIGINAL CONFIRMED REQUIREMENT.
 * Căn khai báo occupancy_status = 'khong_phat_sinh' nhưng chỉ số ĐÃ XÁC NHẬN của kỳ có CL > 0 -> cảnh báo hệ thống để con người xác minh.
 * Hệ thống KHÔNG kết luận nguyên nhân, KHÔNG tự đổi trạng thái căn / chỉ số / phí; BQL quyết định cuối cùng.
 * Khoá = giá trị lưu DB (patch 005), KHÔNG đổi.
 */
return [
    'type' => 'no_usage_consumption',
    'rule' => 'J16_NO_USAGE_CONSUMPTION',
    'source' => 'SYSTEM_RULE',
    'declared_status' => 'khong_phat_sinh',
    'label' => 'Phát sinh tiêu thụ tại căn khai báo không sử dụng',
    'tag' => '',
    'message' => "Căn hộ đang được khai báo 'Không phát sinh điện nước' nhưng kỳ hiện tại ghi nhận tiêu thụ > 0. Cần xác minh trạng thái căn hộ và dữ liệu công tơ.",
    'review_message' => 'Phát hiện tiêu thụ không phù hợp với trạng thái khai báo của căn hộ. Vui lòng xác minh trước khi kết luận.',
    'tech_reason' => 'Căn khai báo không phát sinh nhưng có tiêu thụ',
    'billing_warning' => 'Đang xác minh bất nhất giữa trạng thái căn hộ và dữ liệu tiêu thụ.',
    'gate_message' => "Chưa thể xác nhận bản tính. Căn hộ đang có cảnh báo 'Phát sinh tiêu thụ tại căn khai báo không sử dụng' và chưa được BQL kết luận.",
    'dashboard_label' => 'Cảnh báo trạng thái căn / tiêu thụ không khớp',

    // Kết quả kỹ thuật (inspection_cases.result_conclusion) - Kỹ thuật KHÔNG kết luận trạng thái căn
    'tech_results' => [
        'cong_to_binh_thuong' => 'Công tơ hoạt động bình thường',
        'loi_ghi_chi_so' => 'Lỗi ghi chỉ số',
        'nghi_ngo_cong_to' => 'Nghi ngờ công tơ, cần theo dõi',
        'khong_tiep_can' => 'Không tiếp cận được công tơ',
        'khac' => 'Khác',
    ],

    // Liên hệ cư dân (alert_contact_logs.result) - ghi nhận tay, không gọi điện tự động
    'phone_results' => [
        'contacted' => 'Đã liên hệ được',
        'no_answer' => 'Không nghe máy',
        'wrong_number' => 'Sai số',
        'call_back_later' => 'Hẹn gọi lại',
    ],
    'resident_responses' => [
        'co_nguoi_o' => 'Có người ở',
        'dang_sua_chua' => 'Đang sửa chữa',
        'dang_cho_thue' => 'Đang cho thuê',
        'khong_su_dung' => 'Không có người sử dụng',
        'khong_xac_dinh' => 'Không xác định',
        'khac' => 'Khác',
    ],

    // Kết luận BQL (alerts.conclusion). resolves = gỡ chặn xác nhận bản tính; undetermined KHÔNG gỡ chặn (quyết định 2026-10-03)
    'conclusions' => [
        'apartment_status_incorrect' => ['label' => 'A. Trạng thái căn hộ khai báo sai', 'resolves' => true],
        'reading_incorrect' => ['label' => 'B. Chỉ số công tơ ghi sai', 'resolves' => true],
        'real_consumption_confirmed' => ['label' => 'C. Tiêu thụ thực tế được xác nhận', 'resolves' => true],
        'undetermined' => ['label' => 'D. Chưa xác định nguyên nhân (cần theo dõi)', 'resolves' => false],
    ],

    // [ASSUMPTION J-16-P] khoá system_configs - CHỈ gợi ý ưu tiên lúc tạo; không kết luận / chặn / đổi trạng thái / đóng cảnh báo
    'priority_keys' => [
        'dien' => ['low' => 'j16_low_max_kwh', 'high' => 'j16_high_min_kwh'],
        'nuoc' => ['low' => 'j16_low_max_m3', 'high' => 'j16_high_min_m3'],
    ],

    'email' => [
        'type' => 'consumption_verification',
        // Kết quả xác minh gửi cư dân (VERIFICATION_RESULT) - PARTIAL, chưa làm trong STEP này
    ],
];
