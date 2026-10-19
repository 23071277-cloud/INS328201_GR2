<?php

declare(strict_types=1);

/**
 * Ma trận quyền tĩnh [ASSUMPTION A-14]: không có bảng roles/permissions động
 * trong DB vì tài liệu không mô tả màn hình quản trị quyền động — ma trận này
 * là nguồn chính thức duy nhất, lấy từ STEP 1 mục 6 (Permission Matrix).
 *
 * Format: 'module.action' => ['role1', 'role2', ...]
 * Role hợp lệ: 'bql', 'ky_thuat', 'ke_toan' - đúng 3 vai trò của tài liệu nghiệp vụ (FR-01; J-01 đã chốt, không có Admin).
 *
 * STEP 15: users.* / audit_logs.* / system_parameters.* là route thật (thay các khoá
 * placeholder users.create/update/deactivate, audit-logs.view, settings.* của STEP 4).
 */
return [
    'dashboard.view'   => ['bql', 'ky_thuat', 'ke_toan'],

    // FN-01.03 [TL] 2.3 "Tài khoản người dùng & phân quyền": BQL.
    'users.view'           => ['bql'],
    'users.manage'         => ['bql'],
    'users.change_status'  => ['bql'],
    'users.reset_password' => ['bql'],

    // FN-01.06 [ĐX] chỉ BQL xem log.
    'audit_logs.view'  => ['bql'],

    // FN-01.07 actor: BQL (không có Quản trị viên - J-01 đã chốt); quyền chưa có trong 2.3 (J-02).
    'system_parameters.view'   => ['bql'],
    'system_parameters.manage' => ['bql'],

    // ------------------------------------------------------------------
    // FN-02 Quản lý dữ liệu danh mục (STEP 6) - theo ma trận 2.3 (BQL quản trị danh mục).
    // ------------------------------------------------------------------

    // FN-02.01 Tòa nhà - [TL] 2.3: BQL Create/View/Update/Deactivate; KT, KTo View
    'buildings.view'       => ['bql', 'ky_thuat', 'ke_toan'],
    'buildings.create'     => ['bql'],
    'buildings.update'     => ['bql'],
    'buildings.deactivate' => ['bql'],

    // FN-02.02 Khu vực - 2.3 CHƯA có dòng -> [NEED BA CONFIRMATION J-02]; tạm áp như căn hộ
    'zones.view'       => ['bql', 'ky_thuat', 'ke_toan'],
    'zones.create'     => ['bql'],
    'zones.update'     => ['bql'],
    'zones.deactivate' => ['bql'],

    // FN-02.03 Căn hộ - [TL] 2.3: BQL Create/View/Update/Deactivate; KT, KTo View
    'apartments.view'       => ['bql', 'ky_thuat', 'ke_toan'],
    'apartments.create'     => ['bql'],
    'apartments.update'     => ['bql'],
    'apartments.deactivate' => ['bql'],

    // FN-02.04 Chủ hộ/khách thuê - [TL] 2.3 "như tòa nhà"
    'residents.view'       => ['bql', 'ky_thuat', 'ke_toan'],
    'residents.create'     => ['bql'],
    'residents.update'     => ['bql'],
    'residents.deactivate' => ['bql'],
    // SĐT là dữ liệu cá nhân (NFR-06, BR-36): [TECHNICAL ASSUMPTION D10] chỉ BQL
    // xem đầy đủ, role khác thấy dạng che -> [NEED BA CONFIRMATION]
    'residents.view_phone' => ['bql'],

    // FN-02.05 Công tơ - 2.3 CHƯA có dòng -> [NEED BA CONFIRMATION J-02]; tạm áp như căn hộ ([ĐX] FN-02.05)
    'meters.view'       => ['bql', 'ky_thuat', 'ke_toan'],
    'meters.create'     => ['bql'],
    'meters.update'     => ['bql'],
    'meters.deactivate' => ['bql'],

    // FN-02.06 Phương tiện gửi xe - 2.3 CHƯA có dòng; tài liệu "[ĐX] BQL hoặc Kế toán"
    // -> [NEED BA CONFIRMATION J-02]; tạm chỉ BQL sửa
    'vehicles.view'   => ['bql', 'ky_thuat', 'ke_toan'],
    'vehicles.update' => ['bql'],

    // ------------------------------------------------------------------
    // FN-03 Biểu giá & phí (STEP 7) - [TL] 2.3 dòng "Biểu giá (điện/nước/DV/BHCN)":
    //   BQL Create/View/Update/Approve; Kế toán View + Propose update (chờ BQL Approve).
    // Kỹ thuật: 2.3 ghi View NHƯNG acceptance FR-02 ghi "Kỹ thuật bị 403 khi vào màn
    //   hình biểu giá" -> mâu thuẫn [NEED BA CONFIRMATION J-06]; tạm KHÔNG cấp
    //   (an toàn hơn). Khi BA chốt View: thêm 'ky_thuat' vào tariffs.view, fees.view.
    // Kế toán tạo/sửa trực tiếp (stakeholder matrix) -> [NEED BA CONFIRMATION J-06]; tạm chỉ đề xuất.
    // ------------------------------------------------------------------

    // FN-03.01 Biểu giá điện/nước
    'tariffs.view'   => ['bql', 'ke_toan'],
    'tariffs.create' => ['bql'],
    'tariffs.update' => ['bql'],

    // FN-03.02 Phí cố định & phí gửi xe (đề xuất phí cố định: KHÔNG làm - B9/J-06)
    'fees.view'   => ['bql', 'ke_toan'],
    'fees.create' => ['bql'],
    'fees.update' => ['bql'],

    // FN-03.03 / 03.04 Đề xuất biểu giá
    'tariff_proposals.view'    => ['bql', 'ke_toan'],
    'tariff_proposals.create'  => ['ke_toan'],
    'tariff_proposals.approve' => ['bql'], // duyệt và từ chối

    // ------------------------------------------------------------------
    // FN-04 Kỳ ghi chỉ số & chỉ số công tơ (STEP 8) - [TL] 2.3 dòng "Chỉ số công tơ":
    //   Kỹ thuật Create/View/Update (trước khi khoá kỳ); BQL View/Approve; Kế toán View.
    // Tạo kỳ: tài liệu chưa nói ai (J-10, [ĐX] "BQL hoặc Kế toán") -> [ASSUMPTION J-10] BQL.
    // Import thuộc quyền nhập chỉ số của Kỹ thuật (FR-04).
    // ------------------------------------------------------------------
    'billing_periods.view'   => ['bql', 'ky_thuat', 'ke_toan'],
    'billing_periods.create' => ['bql'], // [ASSUMPTION J-10]

    'meter_readings.view'    => ['bql', 'ky_thuat', 'ke_toan'],
    'meter_readings.create'  => ['ky_thuat'],
    'meter_readings.update'  => ['ky_thuat'], // trước khi khoá kỳ; sửa bản đã duyệt -> Nháp [ASSUMPTION J-13]
    'meter_readings.import'  => ['ky_thuat'],
    'meter_readings.approve' => ['bql'],      // "Trả lại" BLOCKED (B6/J-13)

    // ------------------------------------------------------------------
    // FN-05 Tính tiêu thụ, chi phí & chốt kỳ (STEP 9) - [TL] 2.3 dòng "Tính phí / phiếu thu":
    //   Kế toán Create/View/Update trước khoá kỳ; BQL View, Approve. Kỹ thuật: 2.3 không có quyền
    //   (chỉ "nhận dữ liệu trả lại" [ĐX] -> hiển thị danh sách bị trả lại trên trang kỳ, không kèm tiền).
    // Tính phí theo lệnh của Kế toán [ASSUMPTION J-14]. BQL duyệt sau xác nhận: TUỲ CHỌN [ASSUMPTION A-13,
    //   NEED BA CONFIRMATION J-18]. Khoá kỳ: [ASSUMPTION J-10] Kế toán ([ĐX] FN-05.07). Mở lại: chỉ BQL [TL].
    // ------------------------------------------------------------------
    'billing.view'        => ['bql', 'ke_toan'],
    'billing_adjustments.view' => ['bql', 'ke_toan'],
    'billing_adjustments.submit' => ['ke_toan'],
    'billing_adjustments.decide' => ['bql'],
    'billing.calculate'   => ['ke_toan'],              // [ASSUMPTION J-14]
    'billing.confirm'     => ['ke_toan'],              // xác nhận + trả lại (UC-03)
    'billing.update_debt' => ['ke_toan'],              // chỉ nợ cũ [ASSUMPTION A-15 - J-09]
    'billing.approve'     => ['bql'],                  // tuỳ chọn [J-18]
    'cost_allocation.view' => ['bql', 'ke_toan'],

    'billing_periods.lock'   => ['ke_toan'],           // [ASSUMPTION J-10]
    'billing_periods.reopen' => ['bql'],               // [TL] 2.3

    // ------------------------------------------------------------------
    // FN-06 Baseline, so sánh & mục tiêu (STEP 10).
    // Baseline: 2.3 KHÔNG có dòng (J-02); [SL] Stakeholder matrix "BQL phê duyệt baseline" -> chỉ BQL.
    // So sánh: [TL] BQL View; vai trò khác chỉ [SL] ("Báo cáo/Dashboard") -> KHÔNG tự mở (NEED BA CONFIRMATION J-02).
    // Mục tiêu: [TL] 2.3 "Threshold & Target": BQL Create/View/Update; Kỹ thuật, Kế toán View.
    // ------------------------------------------------------------------
    'baselines.view'   => ['bql'],
    'baselines.manage' => ['bql'],          // lập / sửa / ngừng phiên bản baseline
    'comparisons.view' => ['bql'],
    'targets.view'     => ['bql', 'ky_thuat', 'ke_toan'], // gồm theo dõi mức đạt (FN-06.04)
    'targets.manage'   => ['bql'],

    // ------------------------------------------------------------------
    // FN-07 Cảnh báo & xử lý bất thường (STEP 11) - [TL] 2.3 "Ngưỡng & Target": BQL Create/View/Update; KT, KTo View.
    // [TL] 2.3 "Cảnh báo": BQL View/Review/Approve; Kế toán View/Review (phạm vi Review chưa rõ - J-24 -> KHÔNG cấp);
    //   Kỹ thuật View + Create (khi phát hiện bất thường lúc ghi số). Kỹ thuật chỉ thấy cảnh báo mình tạo / case được
    //   giao ([ĐX] K-18 - lọc ở Service). Đánh giá ngưỡng do BQL kích hoạt [ASSUMPTION J-22].
    // Điều chỉnh chỉ số sau kiểm tra: BQL qua FN-04 service [ASSUMPTION J-13]. Mở lại: BQL [ASSUMPTION J-24].
    // ------------------------------------------------------------------
    'alert_rules.view'   => ['bql', 'ky_thuat', 'ke_toan'],
    'alert_rules.manage' => ['bql'],

    'alerts.view'                 => ['bql', 'ke_toan', 'ky_thuat'],
    'alerts.evaluate'             => ['bql'],
    'alerts.create_manual'        => ['ky_thuat'],
    'alerts.review'               => ['bql'],      // review + đổi ưu tiên (Kế toán: NEED BA J-24)
    'alerts.request_verification' => ['bql'],
    'alerts.update_verification'  => ['ky_thuat'], // chỉ case giao cho mình (Service)
    'alerts.confirm'              => ['bql'],      // xác nhận / điều chỉnh (J-13)
    'alerts.close'                => ['bql'],      // đóng + mở lại (J-24)
    // [BA Proposal J-16] liên hệ cư dân (email xác minh, ghi nhận gọi điện / phản hồi) + kết luận & CTA - chỉ BQL; Kế toán chỉ xem
    'alerts.contact_resident'     => ['bql'],
    'alerts.conclude'             => ['bql'],

    // ------------------------------------------------------------------
    // FN-08 Khuyến nghị (STEP 12) - 2.3 KHÔNG có dòng (J-02). [ĐX] FN-08.01: BQL quản lý rule.
    // [TL] T2 2.1: BQL xem khuyến nghị. Kế toán xem chỉ [SL]; Kỹ thuật không có nguồn -> KHÔNG tự mở.
    // Sinh khuyến nghị do BQL bấm [ASSUMPTION T3].
    // ------------------------------------------------------------------
    'recommendation_rules.view'   => ['bql'],
    'recommendation_rules.manage' => ['bql'],
    'recommendations.view'        => ['bql'],
    'recommendations.generate'    => ['bql'],

    // ------------------------------------------------------------------
    // FN-09 Dashboard & báo cáo (STEP 13) - [TL] 2.3: Dashboard BQL View/Export, Kế toán View/Export, Kỹ thuật View giới hạn;
    //   Phiếu thu: Kế toán Create, BQL View/Approve; Báo cáo: BQL, Kế toán View/Export, Kỹ thuật View giới hạn, không Export.
    // Từng khối dashboard/report còn kiểm quyền sẵn có (billing.view, comparisons.view, alerts.view, recommendations.view,
    //   residents.view) -> Kỹ thuật không thấy tiền / baseline / khuyến nghị / họ tên.
    // ------------------------------------------------------------------
    'dashboard.decision' => ['bql', 'ke_toan', 'ky_thuat'],
    'bills.view'         => ['ke_toan', 'bql'],

    // STEP 25 - FN-10.03 (Secondary): tài liệu không có ma trận quyền cho email [ASSUMPTION A-EMAIL-06] -
    // người xuất phiếu (Kế toán, BQL) được gửi / xem hộp thư đi; Kỹ thuật không.
    'email_notifications.send' => ['ke_toan', 'bql'],
    'email_notifications.view' => ['ke_toan', 'bql'],
    // Gửi phiếu phí qua Zalo (ZNS): cùng người được gửi / xem email
    'zalo_notifications.send' => ['ke_toan', 'bql'],
    'zalo_notifications.view' => ['ke_toan', 'bql'],
    // Payment module: xem trạng thái / dashboard thanh toán; Kế toán xử lý giao dịch cần kiểm tra (Mock Bank là ứng dụng độc lập - không có quyền trong Energy DSS)
    'payments.view'      => ['bql', 'ke_toan'],
    'payments.review'    => ['ke_toan'],
    'reports.view'       => ['bql', 'ke_toan', 'ky_thuat'],
    'reports.export'     => ['bql', 'ke_toan'],

    // FN-10: không thuộc phạm vi.
];
