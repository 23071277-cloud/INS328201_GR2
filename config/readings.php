<?php

declare(strict_types=1);

/**
 * FN-04: nhãn ENUM (khoá = giá trị DB) + cấu hình import. Không có dữ liệu nghiệp vụ.
 */
return [
    // billing_periods.status - G4 [TL]: Mở / Đã khóa / Mở lại (khoá/mở lại thuộc FN-05.07/05.08)
    'period_status' => [
        'mo' => 'Mở',
        'da_khoa' => 'Đã khóa',
        'mo_lai' => 'Mở lại',
    ],

    // Nhãn trạng thái ghi số trên worklist màn Nhập chỉ số (UX, suy ra từ dữ liệu, không phải trạng thái mới).
    // "Đã lưu nháp" và "Chờ BQL duyệt" là CÙNG một trạng thái (Nháp): nghiệp vụ không có bước "gửi duyệt" riêng.
    'worklist_status' => [
        'chua_ghi' => 'Chưa ghi',
        'nhap' => 'Nháp · chờ BQL duyệt',
        'da_xac_nhan' => 'Đã duyệt',
    ],

    // meter_readings.status - G5 [SL 2.4]: Nháp / Đã xác nhận
    'reading_status' => [
        'nhap' => 'Nháp',
        'da_xac_nhan' => 'Đã xác nhận',
    ],

    'reading_source' => [
        'thu_cong' => 'Nhập tay',
        'import' => 'Nhập từ Excel',
        'dieu_chinh' => 'Điều chỉnh',
    ],

    // import_batches.status - [ĐX] FN-04.03 mục 11
    'import_status' => [
        'da_tai_len' => 'Đã tải lên',
        'da_kiem_tra' => 'Đã kiểm tra — chờ xác nhận',
        'da_luu' => 'Đã lưu',
        'da_huy' => 'Đã hủy',
    ],

    'import' => [
        // Mẫu file hệ thống: 9 cột đầu cố định theo thứ tự (lấy từ nhóm cột
        // chỉ số của cấu trúc chuẩn 2.4). Cột sau đó được bỏ qua, trừ cột tuỳ chọn bên dưới (nhận theo TÊN, vị trí bất kỳ).
        // "Mã công tơ điện" / "Mã công tơ nước": nhận diện công tơ bằng meter_code (ưu tiên); thiếu cột / ô trống -> cách cũ
        // (mã căn + hậu tố nhãn, hoặc công tơ duy nhất của loại tiện ích) - file cũ vẫn import được.
        'headers' => ['STT', 'CH', 'Họ tên', 'Điện CSĐ', 'Điện CSC', 'Điện CL', 'Nước CSĐ', 'Nước CSC', 'Nước CL'],
        'optional_headers' => ['Tổng cộng', 'Mã công tơ điện', 'Mã công tơ nước'],
        'meter_code_headers' => ['dien' => 'Mã công tơ điện', 'nuoc' => 'Mã công tơ nước'],
        'total_row_labels' => ['tổng', 'tổng cộng'],
        'max_bytes' => 5 * 1024 * 1024,
        'max_rows' => 2000,
        'allowed_extensions' => ['xlsx'],
        'allowed_mimes' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        // Chỉ tiêu control total (FN-04.05) - mở rộng bằng cách thêm phần tử, không cần ALTER.
        // actual: 'consumption:<utility>' = Σ(CSC − CSĐ) các dòng chi tiết; 'column' = Σ cột cùng tên.
        'control_metrics' => [
            'electricity_consumption' => ['label' => 'Tổng CL điện', 'column' => 'Điện CL', 'actual' => 'consumption:dien'],
            'water_consumption' => ['label' => 'Tổng CL nước', 'column' => 'Nước CL', 'actual' => 'consumption:nuoc'],
            'grand_total' => ['label' => 'Tổng cộng (tiền)', 'column' => 'Tổng cộng', 'actual' => 'column'],
        ],
    ],
];
