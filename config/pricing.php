<?php

declare(strict_types=1);

/**
 * FN-03: nhãn hiển thị cho ENUM của schema (khoá = giá trị DB, KHÔNG đổi).
 * Không chứa đơn giá: mọi đơn giá/phí nằm trong DB (tariffs, tariff_tiers,
 * fixed_fee_rates) và chỉ được đổi qua màn hình FN-03.
 */
return [
    // tariffs.usage_type quyết định kiểu biểu giá - [TL] 2.1, 2.2
    'tariff_kind' => [
        'sinh_hoat' => 'Bậc thang',
        'kinh_doanh' => 'Đồng giá',
    ],

    // Đơn vị theo loại tiện ích
    'utility_unit' => [
        'dien' => 'kWh',
        'nuoc' => 'm³',
    ],

    // Vòng đời hiển thị - TÍNH theo ngày hiệu lực, không lưu DB [TECHNICAL ASSUMPTION P2]
    'tariff_lifecycle' => [
        'dang_ap_dung' => 'Đang áp dụng',
        'sap_ap_dung' => 'Sắp áp dụng',
        'da_thay_the' => 'Đã thay thế',
        'het_hieu_luc' => 'Hết hiệu lực',
    ],

    // fixed_fee_rates.fee_type - [TL] 2.1, BR-15..BR-19
    'fee_type' => [
        'dich_vu_co_o' => ['label' => 'Phí dịch vụ — căn có ở', 'unit' => 'đ/m²', 'rule' => 'Diện tích × đơn giá'],
        'dich_vu_khong_o' => ['label' => 'Phí dịch vụ — không phát sinh điện nước', 'unit' => 'đ/m²', 'rule' => 'Diện tích × đơn giá'],
        'bao_hiem_chay_no' => ['label' => 'Bảo hiểm cháy nổ (BHCN)', 'unit' => 'đ/m²', 'rule' => 'Diện tích × đơn giá theo đợt'],
        'gui_xe_dap_dien' => ['label' => 'Gửi xe đạp / xe điện', 'unit' => 'đ/xe', 'rule' => 'Số lượng × đơn giá'],
        'gui_xe_may' => ['label' => 'Gửi xe máy', 'unit' => 'đ/xe', 'rule' => 'Số lượng × đơn giá'],
        'gui_o_to' => ['label' => 'Gửi ô tô', 'unit' => 'đ/xe', 'rule' => 'Số lượng × đơn giá'],
        'mo_lai_dich_vu' => ['label' => 'Phí mở lại dịch vụ', 'unit' => 'đ/lần', 'rule' => 'theo lần — chưa có sự kiện kích hoạt'],
    ],

    // tariff_proposals.status - [TL] 2.3 Chờ duyệt / Đã duyệt, [ĐX] Từ chối (G7)
    'proposal_status' => [
        'cho_duyet' => 'Chờ duyệt',
        'da_duyet' => 'Đã duyệt',
        'tu_choi' => 'Từ chối',
    ],

    'max_tiers' => 20,
];
