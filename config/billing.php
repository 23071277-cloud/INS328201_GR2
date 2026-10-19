<?php

declare(strict_types=1);

/**
 * FN-05 Tính tiêu thụ, chi phí & chốt kỳ (STEP 9).
 * KHÔNG chứa đơn giá: biểu giá / phí luôn đọc từ DB (FN-03). File này chỉ giữ các QUY TẮC
 * chưa được BA chốt, để khi BA quyết định khác chỉ phải đổi cấu hình tại một chỗ.
 */
return [
    // apartment_charges.status - G6 / [ASSUMPTION A-13 - J-18] (khoá = giá trị DB, KHÔNG đổi)
    'charge_status' => [
        'da_tinh' => 'Đã tính',
        'da_xac_nhan' => 'Đã xác nhận',
        'bi_tra_lai' => 'Bị trả lại',
        'da_duyet' => 'Đã duyệt',
        'da_phat_hanh' => 'Đã phát hành',
    ],

    // [ASSUMPTION J-15] Biểu giá / phí áp dụng = phiên bản có hiệu lực tại NGÀY KẾT THÚC KỲ
    // ([ĐX] FN-05.02 mục 9). Hiện chỉ hỗ trợ 'period_end'.
    'rate_reference_date' => 'period_end',

    // [ASSUMPTION J-15] Làm tròn tập trung (App\Services\MoneyCalculator).
    //   scale 2 = tới xu (độ chính xác DECIMAL(14,2), không làm tròn nghiệp vụ thêm); 0 = tới đồng.
    //   Điểm làm tròn: từng dòng bậc, VAT từng công tơ, từng phí theo m², TB/căn.
    'rounding' => [
        'scale' => 2,
        'mode' => 'half_up',
    ],

    // [ASSUMPTION J-16 / A-12] Phí dịch vụ theo trạng thái căn do BQL khai báo (apartments.occupancy_status).
    'service_fee' => [
        'co_o' => 'dich_vu_co_o',
        'khong_phat_sinh' => 'dich_vu_khong_o',
    ],

    // [ASSUMPTION J-08] BHCN = DT × đơn giá cho MỖI kỳ tháng (như seed). false = không tính trên phiếu tháng.
    'fire_insurance' => [
        'enabled' => true,
        'fee_type' => 'bao_hiem_chay_no',
    ],

    // BR-17: loại xe (FN-02.06) -> loại phí (FN-03.02)
    'parking' => [
        'xe_dap_dien' => 'gui_xe_dap_dien',
        'xe_may' => 'gui_xe_may',
        'o_to' => 'gui_o_to',
    ],

    // BR-19 phí mở lại dịch vụ: BLOCKED J-09 / B11 (không có sự kiện kích hoạt, không có cột lưu).
    'reconnection_fee' => [
        'calculated' => false,
        'note' => 'NOT CALCULATED: hệ thống không có sự kiện cắt/mở lại dịch vụ và phiếu không có cột lưu khoản này.',
    ],

    // Q13: bản tính được phép tính lại khi dữ liệu đổi (da_phat_hanh: phiếu thay thế BLOCKED J-19/B12)
    'recalculable_statuses' => ['da_tinh', 'bi_tra_lai', 'da_xac_nhan', 'da_duyet'],

    // Q15: trạng thái bản tính đủ điều kiện khoá kỳ ([ĐX] FN-05.07 "Đã xác nhận/Đã duyệt")
    'lockable_statuses' => ['da_xac_nhan', 'da_duyet', 'da_phat_hanh'],

    // Nhãn khoản phí trong chi tiết
    'fee_group' => [
        'service' => 'Phí dịch vụ',
        'fire_insurance' => 'Bảo hiểm cháy nổ',
        'parking' => 'Phí gửi xe',
    ],
];
