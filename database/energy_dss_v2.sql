-- ============================================================================
-- Energy & Utility DSS - Topic 09 (Nhóm 2)
-- File: database/energy_dss_v2.sql   [FILE CSDL DUY NHẤT CỦA BẢN NỘP CUỐI]
--
-- Schema 3NF hiện hành (27 bảng) + DỮ LIỆU DEMO TỔNG HỢP (không phải dữ liệu thật):
--   3 tài khoản demo (bql01 / kythuat01 / ketoan01, mật khẩu Password@123 - CHỈ DÙNG DEMO),
--   1 tòa nhà, 3 khu, 4 căn hộ, 4 cư dân (tên giả), 8 công tơ, 2 xe,
--   biểu giá điện / nước (4 phiên bản, 10 bậc) + 7 loại phí cố định,
--   kỳ 06/2024 ĐÃ KHÓA (8 chỉ số đã xác nhận, 4 bản tính phí, 14 dòng chi tiết bậc),
--   kỳ 07/2024 MỞ (chưa có chỉ số - dùng để demo), baseline + mục tiêu BT1,
--   2 quy tắc cảnh báo, 2 quy tắc khuyến nghị, 9 tham số hệ thống.
--   Căn CC-2306 khai báo "Không phát sinh điện nước" (dùng cho demo J-16).
--
-- Cài đặt (DB rỗng):
--   mysql -u root -e "CREATE DATABASE energy_dss_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
--   mysql -u root energy_dss_v2 < database/energy_dss_v2.sql
-- File KHÔNG có CREATE DATABASE / USE / DROP: chỉ nạp vào DB đang chọn; báo lỗi nếu bảng đã tồn tại (không ghi đè).
-- Thiết kế schema: docs/DB_3NF_V2_DESIGN.md | Tóm tắt: docs/DATABASE.md
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- FN-01.03 Quản lý tài khoản người dùng | [TL] FR-01, T3-VN 2.3
-- role: đúng 3 vai trò của tài liệu nghiệp vụ chính thức (FR-01): BQL, Kỹ thuật, Kế toán.
--   J-01 đã chốt: KHÔNG có vai trò Quản trị viên (bỏ 'admin' khỏi ENUM - patch 003).
CREATE TABLE users (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username         VARCHAR(50)  NOT NULL COMMENT '[ASSUMPTION] định danh đăng nhập - tài liệu chưa nói username hay email (J-03 liên quan)',
  password_hash    VARCHAR(255) NOT NULL COMMENT 'password_hash() bcrypt - [TL] NFR-01 >=8 ký tự, chữ hoa + số (kiểm tra ở Validator, không ở DB)',
  full_name        VARCHAR(150) NOT NULL,
  role             ENUM('bql','ky_thuat','ke_toan') NOT NULL COMMENT '[TL] FR-01: 3 vai trò (J-01 đã chốt, patch 003)',
  status           ENUM('hoat_dong','ngung','khoa_tam') NOT NULL DEFAULT 'hoat_dong' COMMENT '[TL] 2.3 Deactivate + FR-01 khoá tạm sau 5 lần sai (BR-02)',
  failed_login_count TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '[TL] BR-02',
  locked_until     DATETIME NULL COMMENT '[ASSUMPTION - J-03] thời gian khoá tạm chưa được tài liệu quy định cụ thể',
  last_login_at    DATETIME NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  INDEX idx_users_role (role),
  INDEX idx_users_status (status)
) ENGINE=InnoDB;

-- FN-01.07 Cấu hình tham số hệ thống | [TL] T2 2.1 "technical configurations"
CREATE TABLE system_configs (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  config_key    VARCHAR(100) NOT NULL,
  config_value  VARCHAR(255) NOT NULL,
  description   VARCHAR(255) NULL,
  updated_by    INT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_system_configs_key (config_key),
  CONSTRAINT fk_sysconfig_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- FN-01.05/01.06 Ghi & tra cứu nhật ký audit | [TL] NFR-05, BR-04
CREATE TABLE audit_logs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NULL COMMENT 'NULL = hệ thống (SYS-01) [SL]',
  action        VARCHAR(100) NOT NULL COMMENT 'VD: create, update, approve, deactivate, login_failed,...',
  entity_type   VARCHAR(100) NOT NULL COMMENT 'Tên bảng/đối tượng nghiệp vụ, VD: meter_reading, tariff, alert',
  entity_id     VARCHAR(50)  NULL,
  old_value     JSON NULL COMMENT '[TL] NFR-05 giá trị cũ',
  new_value     JSON NULL COMMENT '[TL] NFR-05 giá trị mới',
  ip_address    VARCHAR(45)  NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_user (user_id),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB COMMENT='[TL] BR-04: lưu >=24 tháng (Proposed), user thường không xoá/sửa được';

-- FN-02.01 | [TL] FR-03
CREATE TABLE buildings (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(30)  NOT NULL,
  name        VARCHAR(150) NOT NULL,
  status      ENUM('hoat_dong','ngung') NOT NULL DEFAULT 'hoat_dong',
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_buildings_code (code),
  CONSTRAINT fk_building_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- FN-02.02 | [SL] suy từ 2.1 "định dạng mã theo khu" - J-02 (chưa có trong ma trận quyền gốc)
CREATE TABLE zones (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  building_id INT UNSIGNED NOT NULL,
  code        VARCHAR(30)  NOT NULL,
  name        VARCHAR(150) NOT NULL,
  zone_type   ENUM('lien_ke','shophouse','biet_thu','chung_cu') NULL COMMENT '[SL] 2.1',
  status      ENUM('hoat_dong','ngung') NOT NULL DEFAULT 'hoat_dong',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_zones_building_code (building_id, code),
  CONSTRAINT fk_zone_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- FN-02.03 | [TL] FR-03, BR-05, BR-10
-- occupancy_status: [ASSUMPTION A-12 - J-16] "không phát sinh điện nước" do BQL
--   khai báo thủ công, KHÔNG tự suy từ CL=0 (tài liệu chưa chốt công thức).
CREATE TABLE apartments (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  building_id       INT UNSIGNED NOT NULL,
  zone_id           INT UNSIGNED NULL,
  code              VARCHAR(30)  NOT NULL COMMENT '[TL] 2.1 VD LK2, S01, BT4, 2305',
  area              DECIMAL(10,2) NOT NULL COMMENT '[TL] 2.1 DT, đơn vị m2, phải > 0 (BR-10)',
  occupancy_status  ENUM('co_o','khong_phat_sinh') NOT NULL DEFAULT 'co_o' COMMENT '[ASSUMPTION A-12 - J-16], dùng cho BR-15',
  status            ENUM('hoat_dong','ngung') NOT NULL DEFAULT 'hoat_dong' COMMENT '[TL] BR-05 không xoá cứng khi đã có chỉ số',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_apartments_building_code (building_id, code),
  CONSTRAINT fk_apartment_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_apartment_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
  INDEX idx_apartments_status (status)
) ENGINE=InnoDB;

-- FN-02.04 | [TL] FR-03 (VN), 2.1 ; BR-36 (dữ liệu cá nhân chỉ hiện role có quyền)
CREATE TABLE residents (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  apartment_id    INT UNSIGNED NOT NULL,
  full_name       VARCHAR(150) NOT NULL,
  resident_role   ENUM('chu_ho','khach_thue') NOT NULL DEFAULT 'chu_ho',
  phone           VARCHAR(20) NULL COMMENT '[TL] NFR-06 dữ liệu cá nhân',
  email           VARCHAR(255) NULL COMMENT '[A-EMAIL-01] FN-10.03 - dữ liệu cá nhân BR-36 (patch 004)',
  is_active       TINYINT(1) NOT NULL DEFAULT 1 COMMENT '[ASSUMPTION] Đang hiệu lực/Hết hiệu lực',
  effective_from  DATE NULL,
  effective_to    DATE NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_resident_apartment FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE CASCADE,
  INDEX idx_residents_apartment (apartment_id)
) ENGINE=InnoDB;

-- FN-02.05 | [TL] FR-03 (EN) meter ID/utility type/status, BR 2.2, BR-06, BR-08
CREATE TABLE meters (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  apartment_id    INT UNSIGNED NOT NULL,
  meter_code      VARCHAR(30) NOT NULL,
  utility_type    ENUM('dien','nuoc') NOT NULL,
  usage_type      ENUM('sinh_hoat','kinh_doanh') NOT NULL COMMENT '[TL] 2.2 quyết định biểu giá áp dụng',
  display_label   VARCHAR(50) NULL COMMENT '[TL] 2.1 VD hậu tố KD/ở/T1/T2',
  initial_reading DECIMAL(12,2) NULL DEFAULT 0 COMMENT '[DX] CSĐ cho kỳ đầu tiên',
  status          ENUM('hoat_dong','ngung') NOT NULL DEFAULT 'hoat_dong',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_meters_code (meter_code),
  CONSTRAINT fk_meter_apartment FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE RESTRICT,
  INDEX idx_meters_apartment (apartment_id),
  INDEX idx_meters_status (status)
) ENGINE=InnoDB;

-- FN-02.06 | [SL] 2.1 nhóm phí giữ xe, BR-10, BR-17
CREATE TABLE parking_vehicles (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  apartment_id  INT UNSIGNED NOT NULL,
  vehicle_type  ENUM('xe_dap_dien','xe_may','o_to') NOT NULL COMMENT '[TL] 2.1',
  quantity      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '[TL] BR-10 >= 0',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vehicle_apartment_type (apartment_id, vehicle_type),
  CONSTRAINT fk_vehicle_apartment FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- MODULE FN-03: Quản lý biểu giá & phí  [V2 - GỘP tariffs + fixed_fee_rates + tariff_proposals]
-- ============================================================================
-- Một dòng pricing_plans = MỘT PHIÊN BẢN GIÁ (đã duyệt hoặc đang đề xuất / bị từ chối):
--   price_type = 'utility'   : biểu giá điện / nước theo usage_type (thay tariffs + tariff_proposals)
--                              - sinh_hoat : bậc thang ở pricing_tiers, flat_unit_price NULL      [TL] BR-13/14
--                              - kinh_doanh: đồng giá flat_unit_price, không có bậc               [TL] BR-13/14
--   price_type = 'fixed_fee' : phí cố định theo fee_type, đơn giá flat_unit_price (thay fixed_fee_rates)
--                              dịch vụ có ở / không ở (BR-15), BHCN (BR-16), gửi xe (BR-17), mở lại dịch vụ (BR-19)
-- Hai vòng đời TÁCH cột (không gộp vào 1 cột status để không lẫn 2 máy trạng thái):
--   approval_status : cho_duyet -> da_duyet | tu_choi    (luồng đề xuất FN-03.03/03.04; phiên bản tạo trực tiếp = da_duyet)
--   status          : hieu_luc -> het_hieu_luc            (chỉ có khi đã duyệt; NULL khi đang chờ / bị từ chối)
-- origin = 'proposal' : phiên bản đi qua luồng đề xuất (Kế toán đề xuất, BQL duyệt / từ chối) - ĐỀ XUẤT ĐÃ DUYỆT
--                       CHÍNH LÀ phiên bản hiệu lực (không nhân bản dữ liệu giá sang dòng thứ hai);
-- origin = 'direct'   : BQL tạo phiên bản trực tiếp (FN-03.01) / phí cố định (FN-03.02).
-- base_plan_id        : phiên bản gốc mà đề xuất sửa (= tariff_proposals.tariff_id cũ); chuỗi base_plan_id + effective_date
--                       + audit_logs = lịch sử thay đổi giá (không ghi đè phiên bản cũ - BR-42).
-- reviewed_by/at      : người / thời điểm DUYỆT (= tariffs.approved_by cũ) hoặc TỪ CHỐI đề xuất.
-- Bỏ JSON proposed_data: bậc của đề xuất là dòng pricing_tiers thật (1NF).
-- Đơn vị tính của phí (m², xe, lần) phụ thuộc fee_type -> KHÔNG lưu (config/pricing.php) để tránh phụ thuộc bắc cầu.
CREATE TABLE pricing_plans (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  price_type        ENUM('utility','fixed_fee') NOT NULL,
  utility_type      ENUM('dien','nuoc') NULL COMMENT 'Bắt buộc khi price_type = utility',
  usage_type        ENUM('sinh_hoat','kinh_doanh') NULL COMMENT 'Bắt buộc khi price_type = utility - quyết định bậc thang / đồng giá',
  fee_type          ENUM('dich_vu_co_o','dich_vu_khong_o','bao_hiem_chay_no','gui_xe_dap_dien','gui_xe_may','gui_o_to','mo_lai_dich_vu') NULL
                    COMMENT 'Bắt buộc khi price_type = fixed_fee (giữ đủ 7 loại của fixed_fee_rates, gồm mo_lai_dich_vu - BR-19)',
  vat_percent       DECIMAL(5,2)  NULL COMMENT '[TL] điện 8% (BR-13); [ASSUMPTION J-07] nước NULL; phí cố định NULL',
  flat_unit_price   DECIMAL(12,2) NULL COMMENT 'Đồng giá: utility/kinh_doanh (business_unit_price cũ) hoặc đơn giá phí cố định (unit_price cũ)',
  effective_date    DATE NOT NULL COMMENT '[DX] BR-42 không hồi tố cho kỳ đã khoá',
  origin            ENUM('direct','proposal') NOT NULL DEFAULT 'direct',
  approval_status   ENUM('cho_duyet','da_duyet','tu_choi') NOT NULL DEFAULT 'da_duyet',
  status            ENUM('hieu_luc','het_hieu_luc') NULL COMMENT 'Vòng đời phiên bản đã duyệt; NULL khi chưa duyệt / bị từ chối',
  base_plan_id      INT UNSIGNED NULL COMMENT 'Phiên bản gốc được đề xuất sửa (tariff_proposals.tariff_id cũ)',
  proposal_reason   VARCHAR(500) NULL COMMENT 'Lý do đề xuất (tariff_proposals.reason cũ)',
  rejection_reason  VARCHAR(500) NULL COMMENT '[DX] VAL-03.06 bắt buộc khi từ chối',
  created_by        INT UNSIGNED NULL COMMENT 'Người tạo phiên bản / người đề xuất (proposed_by cũ)',
  reviewed_by       INT UNSIGNED NULL COMMENT 'Người duyệt (= approved_by cũ) hoặc từ chối',
  reviewed_at       DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_pricing_subtype CHECK (
       (price_type = 'utility'   AND utility_type IS NOT NULL AND usage_type IS NOT NULL AND fee_type IS NULL)
    OR (price_type = 'fixed_fee' AND fee_type IS NOT NULL AND utility_type IS NULL AND usage_type IS NULL AND vat_percent IS NULL)),
  CONSTRAINT chk_pricing_flat_price CHECK (
       (price_type = 'fixed_fee' AND flat_unit_price IS NOT NULL)
    OR (price_type = 'utility' AND usage_type = 'kinh_doanh' AND flat_unit_price IS NOT NULL)
    OR (price_type = 'utility' AND usage_type = 'sinh_hoat' AND flat_unit_price IS NULL)),
  CONSTRAINT chk_pricing_values CHECK ((flat_unit_price IS NULL OR flat_unit_price >= 0) AND (vat_percent IS NULL OR vat_percent BETWEEN 0 AND 100)),
  CONSTRAINT chk_pricing_lifecycle CHECK (
       (approval_status = 'da_duyet' AND status IS NOT NULL)
    OR (approval_status IN ('cho_duyet','tu_choi') AND status IS NULL)),
  CONSTRAINT chk_pricing_origin CHECK (
       (origin = 'direct' AND approval_status = 'da_duyet' AND base_plan_id IS NULL AND proposal_reason IS NULL AND rejection_reason IS NULL)
    OR (origin = 'proposal')),
  CONSTRAINT chk_pricing_rejection CHECK (approval_status <> 'tu_choi' OR rejection_reason IS NOT NULL),
  CONSTRAINT fk_pricing_base     FOREIGN KEY (base_plan_id) REFERENCES pricing_plans(id) ON DELETE SET NULL,
  CONSTRAINT fk_pricing_creator  FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pricing_reviewer FOREIGN KEY (reviewed_by)  REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_pricing_utility_lookup (price_type, utility_type, usage_type, status, effective_date),
  INDEX idx_pricing_fee_lookup (price_type, fee_type, status, effective_date),
  INDEX idx_pricing_workflow (origin, approval_status)
) ENGINE=InnoDB COMMENT='[V2] Gộp tariffs + fixed_fee_rates + tariff_proposals - xem docs/DB_3NF_V2_DESIGN.md';

-- Chi tiết bậc thang của phiên bản giá (đã duyệt HOẶC đề xuất) | [TL] BR-13, BR-14 (điện 6 bậc, nước 4 bậc)
-- [Schema Review A1] Bậc là NỬA KHOẢNG [tier_from, tier_to); bậc k bắt đầu ĐÚNG tại tier_to của bậc k-1
--   (liên dòng -> kiểm ở Service). Chỉ phiên bản utility / sinh_hoat có bậc (liên bảng -> kiểm ở Service).
--   Billing Algorithm: qty_bậc = GREATEST(0, LEAST(CL, COALESCE(tier_to, CL)) - tier_from); KHÔNG +1.
CREATE TABLE pricing_tiers (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pricing_plan_id  INT UNSIGNED NOT NULL,
  tier_order       TINYINT UNSIGNED NOT NULL,
  tier_from        DECIMAL(12,2) NOT NULL COMMENT 'Cận dưới, BAO GỒM',
  tier_to          DECIMAL(12,2) NULL     COMMENT 'Cận trên, KHÔNG bao gồm. NULL = bậc cuối (VAL-03.03)',
  unit_price       DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_tier_plan FOREIGN KEY (pricing_plan_id) REFERENCES pricing_plans(id) ON DELETE CASCADE,
  UNIQUE KEY uq_tier_order (pricing_plan_id, tier_order),
  CONSTRAINT chk_tier_range CHECK (tier_from >= 0 AND (tier_to IS NULL OR tier_to > tier_from) AND unit_price >= 0)
) ENGINE=InnoDB COMMENT='[V2] = tariff_tiers + bậc trong tariff_proposals.proposed_data (JSON) cũ';

-- FN-04.01/05.07/05.08 | [TL] T2 Workflow 3.1, 2.3
-- created_by/locked_by nullable: actor chưa xác định (J-10, ASSUMPTION A-05
--   dưới đây để RBAC không chặn cứng ai tạo/khoá kỳ ở tầng DB, kiểm soát ở Service).
CREATE TABLE billing_periods (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_code        VARCHAR(7) NOT NULL COMMENT 'Định dạng MM/YYYY [DX]',
  start_date         DATE NOT NULL,
  end_date           DATE NOT NULL,
  reading_due_date   DATE NULL COMMENT '[DX] phục vụ KPI thời gian xử lý',
  status             ENUM('mo','da_khoa','mo_lai') NOT NULL DEFAULT 'mo',
  created_by         INT UNSIGNED NULL COMMENT '[ASSUMPTION A-05 - J-10]',
  locked_by          INT UNSIGNED NULL,
  locked_at          DATETIME NULL,
  reopened_by        INT UNSIGNED NULL COMMENT '[TL] 2.3 chỉ BQL được mở lại',
  reopened_at        DATETIME NULL,
  reopen_reason      VARCHAR(500) NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_period_code (period_code),
  CONSTRAINT fk_period_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_period_locker  FOREIGN KEY (locked_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_period_reopener FOREIGN KEY (reopened_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_period_status (status)
) ENGINE=InnoDB;

-- FN-04.03/04.05 | [TL] FR-04, 2.4 (control total)
-- [ASSUMPTION A-06 - J-12]: hỗ trợ partial import (dòng hợp lệ vẫn lưu),
--   is_strict_mode có thể bật qua system_configs nếu BQL muốn all-or-nothing.
-- [Schema Review C4] control_total_details (JSON) lưu chênh lệch theo TỪNG chỉ tiêu:
--   {"electricity_consumption": {"label": "CL điện", "expected": <dòng Tổng>,
--     "actual": <tổng chi tiết>, "difference": actual - expected}, ...}
--   JSON vì danh sách cột đối chiếu chưa chốt ([ĐX] FN-04.05, J-12) - thêm chỉ
--   tiêu không cần ALTER; là ảnh chụp chỉ đọc theo lô, không lọc theo chỉ tiêu.
CREATE TABLE import_batches (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  billing_period_id   INT UNSIGNED NOT NULL,
  file_name           VARCHAR(255) NOT NULL,
  total_rows          INT UNSIGNED NOT NULL DEFAULT 0,
  valid_rows          INT UNSIGNED NOT NULL DEFAULT 0,
  error_rows          INT UNSIGNED NOT NULL DEFAULT 0,
  control_total_diff  DECIMAL(14,2) NULL COMMENT '[TL] BR-09 tóm tắt: tổng |difference| các chỉ tiêu; NULL nếu file không có dòng Tổng',
  control_total_details JSON NULL COMMENT 'Chi tiết theo chỉ tiêu: {"ma":{"label","expected","actual","difference"}}; NULL nếu không có dòng Tổng',
  status              ENUM('da_tai_len','da_kiem_tra','da_luu','da_huy') NOT NULL DEFAULT 'da_tai_len',
  uploaded_by         INT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_import_period FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id) ON DELETE RESTRICT,
  CONSTRAINT fk_import_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE import_errors (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_batch_id   INT UNSIGNED NOT NULL,
  row_no            INT UNSIGNED NOT NULL COMMENT 'Đổi tên từ row_number vì là reserved keyword (window function) từ MySQL 8.0',
  apartment_code    VARCHAR(30) NULL,
  error_message     VARCHAR(500) NOT NULL,
  CONSTRAINT fk_importerr_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- FN-04.02/03/04/06/07/08 | [TL] FR-04, 2.4, BR-07, BR-08, BR-11, BR-12, BR-41
CREATE TABLE meter_readings (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meter_id            INT UNSIGNED NOT NULL,
  billing_period_id   INT UNSIGNED NOT NULL,
  previous_reading    DECIMAL(12,2) NOT NULL COMMENT 'CSĐ [TL] 2.1',
  current_reading     DECIMAL(12,2) NOT NULL COMMENT 'CSC [TL] 2.1',
  consumption         DECIMAL(12,2) NOT NULL COMMENT 'CL = CSC - CSĐ, [TL] BR-12, lưu thay vì GENERATED để hỗ trợ case thay đồng hồ (FN-04.06)',
  status              ENUM('nhap','da_xac_nhan') NOT NULL DEFAULT 'nhap' COMMENT '[TL/SL] 2.4 "số liệu nháp chưa duyệt"',
  source              ENUM('thu_cong','import','dieu_chinh') NOT NULL DEFAULT 'thu_cong',
  import_batch_id     INT UNSIGNED NULL COMMENT 'Lô import đã tạo bản ghi; NULL = nhập thủ công',
  is_meter_changed    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '[TL] FN-04.06 cờ thay đồng hồ',
  old_meter_id        INT UNSIGNED NULL COMMENT 'Tham chiếu đồng hồ cũ khi is_meter_changed=1',
  note                VARCHAR(500) NULL COMMENT 'Ghi chú hiện trường / lý do điều chỉnh (VAL-04.15)',
  entered_by          INT UNSIGNED NOT NULL,
  confirmed_by        INT UNSIGNED NULL COMMENT '[TL] 2.3 BQL Approve',
  confirmed_at        DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reading_meter_period (meter_id, billing_period_id) COMMENT '[TL] BR-08',
  CONSTRAINT fk_reading_meter FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reading_period FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reading_old_meter FOREIGN KEY (old_meter_id) REFERENCES meters(id) ON DELETE SET NULL,
  CONSTRAINT fk_reading_enterer FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reading_confirmer FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_reading_import_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE RESTRICT,
  INDEX idx_reading_period (billing_period_id),
  INDEX idx_reading_status (status),
  INDEX idx_reading_import_batch (import_batch_id)
) ENGINE=InnoDB;

-- FN-05.01-05.06 | [TL] FR-05, NFR-04, BR-18, BR-20, BR-24
-- old_debt_amount: [ASSUMPTION A-15 - K-12/J-09] nhập thủ công bởi Kế toán,
--   KHÔNG có module công nợ/thanh toán đầy đủ (ngoài phạm vi theo 4.5).
-- status: 2 bước xác nhận theo [ASSUMPTION A-13 - J-18] (Kế toán xác nhận
--   RỒI BQL duyệt); có thể rút gọn còn 1 bước nếu BA xác nhận khác.
CREATE TABLE apartment_charges (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  apartment_id             INT UNSIGNED NOT NULL,
  billing_period_id        INT UNSIGNED NOT NULL,
  electricity_consumption  DECIMAL(12,2) NOT NULL DEFAULT 0,
  electricity_amount       DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Trước VAT, [TL] BR-13',
  electricity_vat_amount   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '[TL] 8% theo BR-13',
  water_consumption        DECIMAL(12,2) NOT NULL DEFAULT 0,
  water_amount             DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '[TL] BR-14; VAT nước = 0 theo [ASSUMPTION-J-07]',
  service_fee_amount       DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '[TL] BR-15',
  fire_insurance_amount    DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '[TL] BR-16',
  parking_fee_amount       DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '[TL] BR-17',
  total_arising            DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Tổng phát sinh kỳ này',
  old_debt_amount          DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '[ASSUMPTION A-15 - J-09]',
  total_due                DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '[TL] BR-18 = total_arising + old_debt_amount',
  status                   ENUM('da_tinh','da_xac_nhan','bi_tra_lai','da_duyet','da_phat_hanh') NOT NULL DEFAULT 'da_tinh' COMMENT '[ASSUMPTION A-13 - J-18]',
  confirmed_by             INT UNSIGNED NULL COMMENT 'Kế toán (FN-05.06)',
  confirmed_at             DATETIME NULL,
  approved_by              INT UNSIGNED NULL COMMENT 'BQL (nếu bắt buộc theo A-13)',
  approved_at              DATETIME NULL,
  rejection_reason         VARCHAR(500) NULL COMMENT '[TL] BR-24',
  issued_at                DATETIME NULL COMMENT 'Thời điểm xuất phiếu FN-09.02',
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_charge_apartment_period (apartment_id, billing_period_id),
  CONSTRAINT fk_charge_apartment FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_charge_period FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id) ON DELETE RESTRICT,
  CONSTRAINT fk_charge_confirmer FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_charge_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_charge_period (billing_period_id),
  INDEX idx_charge_status (status)
) ENGINE=InnoDB;

-- Chi tiết bậc thang để giải trình | [TL] "lưu chi tiết từng bậc để giải trình" (FN-05.02)
-- [Schema Review A4] meter_reading_id: tiền được giải trình theo TỪNG công tơ
--   (BR-06: một căn có thể có công tơ sinh hoạt + kinh doanh). Chuỗi truy vết:
--   apartments -> apartment_charges -> charge_tier_details -> meter_readings -> meters.
--   Biểu giá kinh doanh (đồng giá): 1 dòng tier_order=1, tier_from=0, tier_to=NULL.
--   utility_type giữ nguyên, phải khớp meters.utility_type của chỉ số.
CREATE TABLE charge_tier_details (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  apartment_charge_id BIGINT UNSIGNED NOT NULL,
  meter_reading_id    BIGINT UNSIGNED NOT NULL COMMENT 'Chỉ số (công tơ + kỳ + CL) sinh ra dòng tiền này',
  utility_type        ENUM('dien','nuoc') NOT NULL,
  tier_order          TINYINT UNSIGNED NOT NULL,
  tier_from           DECIMAL(12,2) NOT NULL,
  tier_to             DECIMAL(12,2) NULL,
  quantity            DECIMAL(12,2) NOT NULL COMMENT 'Sản lượng rơi vào bậc này',
  unit_price          DECIMAL(12,2) NOT NULL,
  amount              DECIMAL(14,2) NOT NULL,
  CONSTRAINT fk_tierdetail_charge FOREIGN KEY (apartment_charge_id) REFERENCES apartment_charges(id) ON DELETE CASCADE,
  CONSTRAINT fk_tierdetail_reading FOREIGN KEY (meter_reading_id) REFERENCES meter_readings(id) ON DELETE RESTRICT,
  INDEX idx_tierdetail_reading (meter_reading_id)
) ENGINE=InnoDB;

-- FN-06.01 | [SL] FR-06, KPI, Stakeholder matrix
-- [ASSUMPTION A-08 - J-20]: method mặc định 'trung_binh_n_ky' với n=3 (K-08)
-- [Schema Review A2] scope_id NOT NULL = id thực thể theo scope_type
--   (meters/apartments/zones/buildings.id). Không dùng NULL làm "toàn tòa".
--   Scope đa hình nên KHÔNG đặt FK trực tiếp; Service kiểm tra tồn tại.
CREATE TABLE baselines (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope_type      ENUM('meter','apartment','zone','building') NOT NULL,
  scope_id        INT UNSIGNED NOT NULL COMMENT 'Id thực thể theo scope_type: meters/apartments/zones/buildings.id. Đa hình -> không FK, Service kiểm tra tồn tại',
  utility_type    ENUM('dien','nuoc') NOT NULL,
  method          ENUM('ky_truoc','trung_binh_n_ky','cung_ky_nam_truoc','nhap_tay') NOT NULL DEFAULT 'trung_binh_n_ky' COMMENT '[ASSUMPTION A-08 - J-20]',
  n_periods       TINYINT UNSIGNED NULL DEFAULT 3 COMMENT '[TL] T2 câu 25: tối thiểu 3 kỳ',
  manual_value    DECIMAL(12,2) NULL,
  computed_value  DECIMAL(12,2) NULL COMMENT 'Giá trị baseline tính được, cache lại để truy vấn nhanh',
  effective_date  DATE NOT NULL,
  status          ENUM('hieu_luc','het_hieu_luc') NOT NULL DEFAULT 'hieu_luc',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_baseline_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_baseline_scope (scope_type, scope_id, utility_type, status)
) ENGINE=InnoDB;

-- FN-06.03/06.04 | [TL] FR-07, KPI target-attainment rate, BR-25, BR-34
-- [Schema Review A2] scope_id NOT NULL để uq_target_scope_period chặn được trùng
--   ở mọi phạm vi (MySQL/MariaDB coi các NULL là khác nhau trong UNIQUE).
CREATE TABLE consumption_targets (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope_type          ENUM('apartment','zone','building') NOT NULL,
  scope_id            INT UNSIGNED NOT NULL COMMENT 'Id thực thể theo scope_type: apartments/zones/buildings.id. Đa hình -> không FK, Service kiểm tra tồn tại',
  utility_type        ENUM('dien','nuoc') NOT NULL,
  billing_period_id   INT UNSIGNED NOT NULL COMMENT '[TL] FR-07 Acceptance "target tháng"',
  target_value        DECIMAL(12,2) NOT NULL,
  created_by          INT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_target_scope_period (scope_type, scope_id, utility_type, billing_period_id) COMMENT '[DX] BR-25 một mục tiêu / (phạm vi, tiện ích, kỳ)',
  CONSTRAINT fk_target_period FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id) ON DELETE RESTRICT,
  CONSTRAINT fk_target_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_target_value_nonnegative CHECK (target_value >= 0)
) ENGINE=InnoDB;

-- FN-07.01 | [TL] FR-08 (giá trị ngưỡng là ASSUMPTION trong chính tài liệu, J-32)
CREATE TABLE alert_rules (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(150) NOT NULL,
  measure_type    ENUM('tieu_thu','chi_phi') NOT NULL COMMENT '[TL] FR-08',
  utility_type    ENUM('dien','nuoc') NOT NULL,
  compare_base    ENUM('ky_truoc','baseline','gia_tri_tuyet_doi') NOT NULL,
  operator        ENUM('gt','gte') NOT NULL DEFAULT 'gt' COMMENT 'VD tăng > X% so baseline',
  threshold_value DECIMAL(12,4) NOT NULL COMMENT '[TL] FR-08 ASSUMPTION - giá trị cụ thể CHƯA ĐƯỢC ĐẶC TẢ, xem J-32',
  min_absolute_diff DECIMAL(12,4) NULL DEFAULT NULL COMMENT 'FR-08: Ngưỡng hiệu số tuyệt đối tối thiểu. NULL giữ quy tắc một điều kiện; khi có giá trị, cả threshold_value và min_absolute_diff phải được vượt.',
  scope_type      ENUM('toan_toa','zone','usage_type') NOT NULL DEFAULT 'toan_toa',
  scope_value     VARCHAR(50) NULL,
  priority_level  ENUM('cao','trung_binh','thap') NOT NULL DEFAULT 'trung_binh' COMMENT '[DX] BR-43',
  status          ENUM('bat','tat') NOT NULL DEFAULT 'bat',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_alertrule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_alertrule_status (status)
) ENGINE=InnoDB;

-- FN-07.02-07.10 | [TL] FR-08, FR-09, AC FR-08/09, BR-28..BR-32, BR-40, BR-43
-- status: bộ trạng thái HỢP NHẤT theo [ASSUMPTION A-02 - J-04 CHƯA CHỐT]
--   (tài liệu có 3 phiên bản khác nhau: T2 KPI / FR-09 EN / FR-09 VN).
-- [Schema Review A3] Chống trùng (BR-40 - [DX] Đề xuất BA) theo khoá
--   rule + căn hộ + COALESCE(công tơ, 0) + kỳ, qua cột sinh dedupe_meter_id:
--   cảnh báo theo công tơ và cảnh báo cấp căn hộ (chi phí, meter_id NULL) đều
--   bị chặn trùng; cảnh báo thủ công (alert_rule_id NULL) không bị chặn.
--   fk_alert_meter dùng RESTRICT (MySQL 8+ cấm SET NULL trên cột gốc của cột sinh STORED).
CREATE TABLE alerts (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alert_type         VARCHAR(40) NOT NULL DEFAULT 'threshold' COMMENT 'threshold = FN-07 (rule / thủ công); no_usage_consumption = [BA Proposal J-16] (patch 005)',
  alert_rule_id      INT UNSIGNED NULL COMMENT 'NULL nếu cảnh báo thủ công (FN-07.03)',
  meter_id           INT UNSIGNED NULL,
  dedupe_meter_id    INT UNSIGNED AS (COALESCE(meter_id, 0)) STORED COMMENT 'Cột sinh cho khoá chống trùng: 0 = cảnh báo cấp căn hộ (không gắn công tơ)',
  apartment_id       INT UNSIGNED NOT NULL,
  billing_period_id  INT UNSIGNED NOT NULL,
  utility_type       ENUM('dien','nuoc') NULL COMMENT 'Bắt buộc với FN-07; NULL chỉ hợp lệ khi alert_type = no_usage_consumption và nghĩa là Điện + Nước (patch 005)',
  source             ENUM('rule','thu_cong','he_thong') NOT NULL DEFAULT 'rule' COMMENT '[TL] 2.3 Alert: KT Create; he_thong = quy tắc hệ thống J-16 (patch 005)',
  actual_value       DECIMAL(14,4) NULL,
  compare_value      DECIMAL(14,4) NULL COMMENT 'Giá trị kỳ trước/baseline dùng để so sánh',
  percent_change     DECIMAL(8,2) NULL COMMENT '[TL] BR-27',
  priority           ENUM('cao','trung_binh','thap') NOT NULL DEFAULT 'trung_binh' COMMENT '[DX] BR-43, BQL có thể sửa',
  status             ENUM('moi','da_review','can_kiem_tra_lai','da_xac_nhan','da_dong') NOT NULL DEFAULT 'moi' COMMENT '[ASSUMPTION A-02 - J-04]',
  description        VARCHAR(500) NULL COMMENT 'Mô tả hiện tượng khi source=thu_cong (bắt buộc theo VAL)',
  snapshot_json      LONGTEXT NULL COMMENT 'J-16: dữ liệu lúc phát hiện / đánh giá lại (CL, công tơ, chỉ số, rule, thời điểm)',
  created_by         INT UNSIGNED NULL COMMENT 'NULL = SYS-01 tự sinh',
  reviewed_by        INT UNSIGNED NULL,
  reviewed_at        DATETIME NULL,
  closed_by          INT UNSIGNED NULL,
  closed_at          DATETIME NULL,
  close_reason       VARCHAR(500) NULL,
  reopen_reason      VARCHAR(500) NULL,
  conclusion         VARCHAR(40) NULL COMMENT 'J-16 kết luận BQL: apartment_status_incorrect / reading_incorrect / real_consumption_confirmed / undetermined',
  conclusion_note    VARCHAR(500) NULL,
  concluded_by       INT UNSIGNED NULL,
  concluded_at       DATETIME NULL,
  follow_up_date     DATE NULL COMMENT 'J-16 kết luận undetermined: ngày theo dõi lại',
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  system_dedupe_key  TINYINT UNSIGNED AS (IF(source = 'he_thong', 1, NULL)) STORED COMMENT 'Chỉ khác NULL với cảnh báo hệ thống -> uq_alert_system_dedupe chỉ áp cho loại này',
  CONSTRAINT chk_alert_snapshot_json CHECK (snapshot_json IS NULL OR JSON_VALID(snapshot_json)),
  CONSTRAINT chk_alert_utility_type CHECK (utility_type IS NOT NULL OR alert_type = 'no_usage_consumption'),
  CONSTRAINT fk_alert_rule FOREIGN KEY (alert_rule_id) REFERENCES alert_rules(id) ON DELETE SET NULL,
  CONSTRAINT fk_alert_meter FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE RESTRICT,
  CONSTRAINT fk_alert_apartment FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_alert_period FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id) ON DELETE RESTRICT,
  CONSTRAINT fk_alert_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_alert_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_alert_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_alert_concluder FOREIGN KEY (concluded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_alert_status (status),
  INDEX idx_alert_priority (priority),
  INDEX idx_alert_period (billing_period_id),
  INDEX fk_alert_concluder (concluded_by),
  UNIQUE KEY uq_alert_dedupe (alert_rule_id, apartment_id, dedupe_meter_id, billing_period_id) COMMENT '[DX] BR-40 không sinh trùng cảnh báo rule/căn hộ/công tơ/kỳ; alert_rule_id NULL (thủ công) không bị chặn',
  UNIQUE KEY uq_alert_system_dedupe (alert_type, apartment_id, billing_period_id, system_dedupe_key) COMMENT '[BA Proposal J-16] 1 cảnh báo hệ thống / (loại, căn, kỳ); phát hiện lại -> cập nhật snapshot / mở lại, không tạo mới',
  INDEX idx_alert_type_status (alert_type, status)
) ENGINE=InnoDB;

-- [V2 - GỘP alert_status_history + alert_contact_logs] Dòng thời gian của cảnh báo
-- event_type = 'state_change' : đổi trạng thái và/hoặc ưu tiên ([TL] BR-31) - 1 dòng có thể đổi cả hai (như lịch sử cũ)
-- event_type = 'contact'      : [BA Proposal J-16] liên hệ cư dân do BQL ghi tay (không gọi điện tự động)
--   contact_type   : phone | resident_response
--   contact_time   : thời điểm liên hệ / nhận phản hồi do BQL nhập (khác created_at = lúc ghi nhận)
--   contact_result : whitelist ở config/j16.php (phone_results / resident_responses) - VARCHAR để không hardcode danh mục vào DB
-- Cột theo loại sự kiện NULL được; chk_event_shape bảo đảm đúng tập cột cho từng loại.
-- created_by: lịch sử trạng thái NULL = hệ thống (SYS-01); liên hệ bắt buộc người ghi (như NOT NULL cũ).
-- [STEP 4A P-4A-2] fk_event_user ON DELETE RESTRICT: giữ truy vết người ghi sự kiện (MariaDB không chạy CHECK cho hành động
--   FK SET NULL); vòng đời tài khoản là ngừng, không xoá cứng (FN-01).
CREATE TABLE alert_events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alert_id        BIGINT UNSIGNED NOT NULL,
  event_type      ENUM('state_change','contact') NOT NULL,
  old_status      ENUM('moi','da_review','can_kiem_tra_lai','da_xac_nhan','da_dong') NULL,
  new_status      ENUM('moi','da_review','can_kiem_tra_lai','da_xac_nhan','da_dong') NULL,
  old_priority    ENUM('cao','trung_binh','thap') NULL,
  new_priority    ENUM('cao','trung_binh','thap') NULL,
  contact_type    ENUM('phone','resident_response') NULL,
  contact_time    DATETIME NULL,
  contact_result  VARCHAR(30) NULL,
  note            VARCHAR(500) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_event_shape CHECK (
       (event_type = 'state_change' AND new_status IS NOT NULL
          AND contact_type IS NULL AND contact_time IS NULL AND contact_result IS NULL)
    OR (event_type = 'contact' AND contact_type IS NOT NULL AND contact_time IS NOT NULL AND contact_result IS NOT NULL AND created_by IS NOT NULL
          AND old_status IS NULL AND new_status IS NULL AND old_priority IS NULL AND new_priority IS NULL)),
  CONSTRAINT fk_event_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_user  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_event_alert_time (alert_id, created_at),
  INDEX idx_event_contact (alert_id, event_type, contact_time)
) ENGINE=InnoDB COMMENT='[V2] Gộp alert_status_history + alert_contact_logs - xem docs/DB_3NF_V2_DESIGN.md';

-- FN-07.07/07.08 | [TL] FR-09 EN, UC-02, T2 2.1 "cập nhật kết quả kiểm tra"
-- [ASSUMPTION A-03 - J-05]: chỉ giữ bản tối thiểu (Core); lịch/ảnh/hạn xử lý
--   (Secondary FN-10.01) KHÔNG có cột riêng ở đây, sẽ mở rộng bảng khi J-05 chốt.
CREATE TABLE inspection_cases (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alert_id            BIGINT UNSIGNED NOT NULL,
  content             VARCHAR(500) NOT NULL COMMENT 'Nội dung yêu cầu kiểm tra',
  assigned_to         INT UNSIGNED NOT NULL COMMENT 'Kỹ thuật được giao',
  status              ENUM('da_giao','da_co_ket_qua','da_dong') NOT NULL DEFAULT 'da_giao',
  result_conclusion   ENUM('canh_bao_dung','canh_bao_sai','do_ghi_sai','khong_xac_dinh',
                           'cong_to_binh_thuong','loi_ghi_chi_so','nghi_ngo_cong_to','khong_tiep_can','khac') NULL
                      COMMENT '[DX] K-15; 5 giá trị cuối = kết quả kỹ thuật J-16 METER_OK / READING_ERROR / METER_SUSPECTED / UNABLE_TO_ACCESS / OTHER (patch 005)',
  rechecked_value     DECIMAL(12,2) NULL COMMENT 'Chỉ số đọc lại nếu có',
  note                VARCHAR(500) NULL,
  created_by          INT UNSIGNED NOT NULL COMMENT 'BQL',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_case_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
  CONSTRAINT fk_case_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_case_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_case_assignee (assigned_to),
  INDEX idx_case_status (status)
) ENGINE=InnoDB;

-- FN-08.01 | [SL] AC FR-10 "approved recommendation rule"
-- [V2] Bỏ condition_params (JSON) -> cột chuẩn hoá theo đúng 2 tham số rule hiện dùng (RecommendationRuleService):
--   n_periods    : số kỳ N - bắt buộc với cl_zero_n_ky / vuot_nguong_lien_tiep (config/recommendations.php needs_n_periods),
--                  NULL với khong_dat_muc_tieu. Giới hạn trên (max_n_periods) giữ ở config, không hardcode vào CHECK.
--   utility_type : giới hạn tiện ích của rule; NULL = mọi tiện ích.
CREATE TABLE recommendation_rules (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                 VARCHAR(150) NOT NULL,
  condition_type       ENUM('cl_zero_n_ky','vuot_nguong_lien_tiep','khong_dat_muc_tieu') NOT NULL COMMENT '[TL] FR-10 ví dụ CL=0 2 kỳ liên tiếp',
  n_periods            TINYINT UNSIGNED NULL COMMENT 'Số kỳ N (condition_params.n_periods cũ)',
  utility_type         ENUM('dien','nuoc') NULL COMMENT 'NULL = mọi tiện ích (condition_params.utility_type cũ)',
  recommendation_text  VARCHAR(500) NOT NULL,
  status               ENUM('bat','tat') NOT NULL DEFAULT 'bat',
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_recrule_n_periods CHECK (
       (condition_type IN ('cl_zero_n_ky','vuot_nguong_lien_tiep') AND n_periods IS NOT NULL AND n_periods >= 1)
    OR (condition_type = 'khong_dat_muc_tieu' AND n_periods IS NULL)),
  CONSTRAINT fk_recrule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_recrule_status (status, condition_type)
) ENGINE=InnoDB;

-- FN-08.02 | [TL] FR-10, US-05
CREATE TABLE recommendations (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alert_id                BIGINT UNSIGNED NOT NULL,
  recommendation_rule_id  INT UNSIGNED NOT NULL,
  recommendation_text     VARCHAR(500) NOT NULL,
  is_followed             TINYINT(1) NULL COMMENT '[DX] đo "recommendation correctness"',
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rec_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_rule FOREIGN KEY (recommendation_rule_id) REFERENCES recommendation_rules(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_rec_alert_rule (alert_id, recommendation_rule_id) COMMENT '[STEP 12 D-1] 1 khuyến nghị / (cảnh báo, quy tắc); Service vẫn khoá dòng cảnh báo trước khi ghi'
) ENGINE=InnoDB;

-- ============================================================================
-- FN-10.03 (Secondary) - Gửi thông báo phí qua email (STEP 25, patch 004)
-- ============================================================================
CREATE TABLE email_notifications (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  notification_type   VARCHAR(30)  NOT NULL DEFAULT 'bill_notice' COMMENT 'bill_notice (phiếu phí) / consumption_verification (J-16, patch 005)',
  resident_id         INT UNSIGNED NULL COMMENT 'Người nhận (chủ hộ / khách thuê) lúc gửi [A-EMAIL-01]',
  apartment_id        INT UNSIGNED NOT NULL,
  billing_period_id   INT UNSIGNED NULL,
  apartment_charge_id BIGINT UNSIGNED NULL COMMENT 'Bản tính được thông báo (FN-09.02)',
  alert_id            BIGINT UNSIGNED NULL COMMENT 'Cảnh báo liên quan (J-16 consumption_verification)',
  recipient_email     VARCHAR(255) NOT NULL COMMENT 'Snapshot địa chỉ lúc gửi (chữ thường) - dữ liệu cá nhân BR-36',
  recipient_name      VARCHAR(150) NULL,
  subject             VARCHAR(255) NOT NULL,
  body_html           MEDIUMTEXT   NOT NULL COMMENT 'Snapshot nội dung đã gửi',
  body_text           MEDIUMTEXT   NOT NULL,
  content_hash        CHAR(64)     NOT NULL COMMENT 'sha256 nội dung - chống gửi trùng cùng nội dung [A-EMAIL-07]',
  transport           VARCHAR(20)  NOT NULL COMMENT 'smtp / log / array (MAIL_DRIVER lúc gửi)',
  status              ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempt_count       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error          VARCHAR(500) NULL COMMENT 'Thông báo lỗi đã làm sạch - không chứa thông tin đăng nhập SMTP',
  sent_at             DATETIME NULL,
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_email_resident FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE SET NULL,
  CONSTRAINT fk_email_apartment FOREIGN KEY (apartment_id) REFERENCES apartments(id),
  CONSTRAINT fk_email_period FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id),
  CONSTRAINT fk_email_charge FOREIGN KEY (apartment_charge_id) REFERENCES apartment_charges(id) ON DELETE SET NULL,
  CONSTRAINT fk_email_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_email_alert FOREIGN KEY (alert_id) REFERENCES alerts(id),
  UNIQUE KEY uq_email_dedupe (apartment_charge_id, recipient_email, notification_type, content_hash),
  UNIQUE KEY uq_email_alert_dedupe (alert_id, recipient_email, notification_type, content_hash),
  INDEX idx_email_status (status, created_at),
  INDEX idx_email_apartment (apartment_id)
) ENGINE=InnoDB COMMENT='FN-10.03 (Secondary) hộp thư đi / lịch sử gửi email - STEP 25, patch 004';

-- Thanh toán (Payment module): giao dịch ngân hàng nhận qua callback (Mock Bank - chỉ local/demo) + kết quả đối soát.
-- Trạng thái thanh toán của bản tính (UNPAID / PAID / PAYMENT_REVIEW) KHÔNG lưu - tính từ các giao dịch (3NF; không ghi vào
-- apartment_charges, kể cả bản tính của kỳ đã khoá). Không hỗ trợ thanh toán một phần: chỉ khớp đúng số tiền.
-- Mã thanh toán (payment_ref) suy ra từ id bản tính (config/payment.php) - không lưu cột riêng; đối soát đọc từ nội dung chuyển khoản.
CREATE TABLE payment_transactions (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id      VARCHAR(64)  NOT NULL COMMENT 'Mã giao dịch phía ngân hàng - UNIQUE = idempotency (callback lặp không xử lý lại)',
  source              ENUM('mock_bank') NOT NULL DEFAULT 'mock_bank' COMMENT 'Nguồn giao dịch - chỉ ngân hàng giả lập (không tích hợp ngân hàng thật)',
  amount              DECIMAL(14,2) NOT NULL COMMENT 'Số tiền nhận (VNĐ)',
  transfer_content    VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Nội dung chuyển khoản (chứa mã thanh toán)',
  payer_name          VARCHAR(150) NULL,
  received_at         DATETIME     NOT NULL COMMENT 'Thời điểm ngân hàng ghi nhận',
  apartment_charge_id BIGINT UNSIGNED NULL COMMENT 'Bản tính khớp (NULL khi không xác định được mã thanh toán)',
  match_status        ENUM('matched','manual_matched','amount_mismatch','unknown_ref','not_payable','already_paid','ignored') NOT NULL
                      COMMENT 'Kết quả đối soát: matched / manual_matched = đã ghi nhận; amount_mismatch / unknown_ref / not_payable / already_paid = cần kiểm tra; ignored = đã xử lý ngoài hệ thống',
  match_note          VARCHAR(500) NULL COMMENT 'Lý do đối soát tự động',
  reviewed_by         INT UNSIGNED NULL COMMENT 'Kế toán xử lý giao dịch cần kiểm tra',
  reviewed_at         DATETIME     NULL,
  review_note         VARCHAR(500) NULL COMMENT 'Lý do khớp thủ công / bỏ qua (bắt buộc khi xử lý)',
  raw_payload         TEXT         NOT NULL COMMENT 'Payload callback đã xác thực HMAC (JSON)',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_transaction (transaction_id),
  CONSTRAINT fk_payment_charge FOREIGN KEY (apartment_charge_id) REFERENCES apartment_charges(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_payment_amount CHECK (amount > 0),
  CONSTRAINT chk_payment_payload CHECK (json_valid(raw_payload)),
  CONSTRAINT chk_payment_matched_charge CHECK (match_status NOT IN ('matched','manual_matched','amount_mismatch','not_payable','already_paid') OR apartment_charge_id IS NOT NULL),
  CONSTRAINT chk_payment_review CHECK (match_status NOT IN ('manual_matched','ignored') OR (reviewed_at IS NOT NULL AND review_note IS NOT NULL)),
  INDEX idx_payment_charge (apartment_charge_id, match_status),
  INDEX idx_payment_status (match_status, received_at)
) ENGINE=InnoDB COMMENT='Payment module: giao dịch ngân hàng (Mock Bank) + đối soát tự động';

-- ============================================================================
-- DỮ LIỆU DEMO (tổng hợp, không chứa dữ liệu thật)
-- ============================================================================
INSERT INTO `alert_rules` (`id`, `name`, `measure_type`, `utility_type`, `compare_base`, `operator`, `threshold_value`, `scope_type`, `scope_value`, `priority_level`, `status`, `created_by`, `created_at`, `updated_at`) VALUES (1,'Điện vượt baseline >30% [UAT - chưa chính thức, xem J-32]','tieu_thu','dien','baseline','gt',30.0000,'toan_toa',NULL,'trung_binh','bat',1,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `alert_rules` (`id`, `name`, `measure_type`, `utility_type`, `compare_base`, `operator`, `threshold_value`, `scope_type`, `scope_value`, `priority_level`, `status`, `created_by`, `created_at`, `updated_at`) VALUES (2,'Nước vượt baseline >30% [UAT - chưa chính thức, xem J-32]','tieu_thu','nuoc','baseline','gt',30.0000,'toan_toa',NULL,'trung_binh','bat',1,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `apartment_charges` (`id`, `apartment_id`, `billing_period_id`, `electricity_consumption`, `electricity_amount`, `electricity_vat_amount`, `water_consumption`, `water_amount`, `service_fee_amount`, `fire_insurance_amount`, `parking_fee_amount`, `total_arising`, `old_debt_amount`, `total_due`, `status`, `confirmed_by`, `confirmed_at`, `approved_by`, `approved_at`, `rejection_reason`, `issued_at`, `created_at`, `updated_at`) VALUES (1,1,1,343.00,804350.00,64348.00,16.00,185120.00,240000.00,156240.00,500000.00,1950058.00,0.00,1950058.00,'da_phat_hanh',3,'2024-07-03 09:00:00',1,'2024-07-03 14:00:00',NULL,'2024-07-04 08:00:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `apartment_charges` (`id`, `apartment_id`, `billing_period_id`, `electricity_consumption`, `electricity_amount`, `electricity_vat_amount`, `water_consumption`, `water_amount`, `service_fee_amount`, `fire_insurance_amount`, `parking_fee_amount`, `total_arising`, `old_debt_amount`, `total_due`, `status`, `confirmed_by`, `confirmed_at`, `approved_by`, `approved_at`, `rejection_reason`, `issued_at`, `created_at`, `updated_at`) VALUES (2,2,1,150.00,585600.00,46848.00,30.00,754800.00,180000.00,117180.00,100000.00,1784428.00,0.00,1784428.00,'da_phat_hanh',3,'2024-07-03 09:05:00',1,'2024-07-03 14:00:00',NULL,'2024-07-04 08:00:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `apartment_charges` (`id`, `apartment_id`, `billing_period_id`, `electricity_consumption`, `electricity_amount`, `electricity_vat_amount`, `water_consumption`, `water_amount`, `service_fee_amount`, `fire_insurance_amount`, `parking_fee_amount`, `total_arising`, `old_debt_amount`, `total_due`, `status`, `confirmed_by`, `confirmed_at`, `approved_by`, `approved_at`, `rejection_reason`, `issued_at`, `created_at`, `updated_at`) VALUES (3,3,1,150.00,291950.00,23356.00,12.00,135340.00,210000.00,136710.00,0.00,797356.00,0.00,797356.00,'da_phat_hanh',3,'2024-07-03 09:10:00',1,'2024-07-03 14:00:00',NULL,'2024-07-04 08:00:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `apartment_charges` (`id`, `apartment_id`, `billing_period_id`, `electricity_consumption`, `electricity_amount`, `electricity_vat_amount`, `water_consumption`, `water_amount`, `service_fee_amount`, `fire_insurance_amount`, `parking_fee_amount`, `total_arising`, `old_debt_amount`, `total_due`, `status`, `confirmed_by`, `confirmed_at`, `approved_by`, `approved_at`, `rejection_reason`, `issued_at`, `created_at`, `updated_at`) VALUES (4,4,1,0.00,0.00,0.00,0.00,0.00,105000.00,136710.00,0.00,241710.00,0.00,241710.00,'da_phat_hanh',3,'2024-07-03 09:15:00',1,'2024-07-03 14:00:00',NULL,'2024-07-04 08:00:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `apartments` (`id`, `building_id`, `zone_id`, `code`, `area`, `occupancy_status`, `status`, `created_at`, `updated_at`) VALUES (1,1,1,'BT1',80.00,'co_o','hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `apartments` (`id`, `building_id`, `zone_id`, `code`, `area`, `occupancy_status`, `status`, `created_at`, `updated_at`) VALUES (2,1,2,'S01',60.00,'co_o','hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `apartments` (`id`, `building_id`, `zone_id`, `code`, `area`, `occupancy_status`, `status`, `created_at`, `updated_at`) VALUES (3,1,3,'CC-2305',70.00,'co_o','hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `apartments` (`id`, `building_id`, `zone_id`, `code`, `area`, `occupancy_status`, `status`, `created_at`, `updated_at`) VALUES (4,1,3,'CC-2306',70.00,'khong_phat_sinh','hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `baselines` (`id`, `scope_type`, `scope_id`, `utility_type`, `method`, `n_periods`, `manual_value`, `computed_value`, `effective_date`, `status`, `created_by`, `created_at`) VALUES (1,'apartment',1,'dien','ky_truoc',NULL,NULL,343.00,'2024-07-01','hieu_luc',1,'2026-10-04 11:40:19');
INSERT INTO `billing_periods` (`id`, `period_code`, `start_date`, `end_date`, `reading_due_date`, `status`, `created_by`, `locked_by`, `locked_at`, `reopened_by`, `reopened_at`, `reopen_reason`, `created_at`, `updated_at`) VALUES (1,'06/2024','2024-06-01','2024-06-30',NULL,'da_khoa',1,1,'2024-07-05 09:00:00',NULL,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `billing_periods` (`id`, `period_code`, `start_date`, `end_date`, `reading_due_date`, `status`, `created_by`, `locked_by`, `locked_at`, `reopened_by`, `reopened_at`, `reopen_reason`, `created_at`, `updated_at`) VALUES (2,'07/2024','2024-07-01','2024-07-31',NULL,'mo',1,NULL,NULL,NULL,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `buildings` (`id`, `code`, `name`, `status`, `created_by`, `created_at`, `updated_at`) VALUES (1,'CHUNGCU-A','Chung cư A','hoat_dong',1,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (1,1,1,'dien',1,0.00,50.00,50.00,1806.00,90300.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (2,1,1,'dien',2,50.00,100.00,50.00,1866.00,93300.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (3,1,1,'dien',3,100.00,200.00,100.00,2167.00,216700.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (4,1,1,'dien',4,200.00,300.00,100.00,2729.00,272900.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (5,1,1,'dien',5,300.00,400.00,43.00,3050.00,131150.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (6,1,2,'nuoc',1,0.00,10.00,10.00,11045.00,110450.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (7,1,2,'nuoc',2,10.00,20.00,6.00,12445.00,74670.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (8,2,3,'dien',1,0.00,NULL,150.00,3904.00,585600.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (9,2,4,'nuoc',1,0.00,NULL,30.00,25160.00,754800.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (10,3,5,'dien',1,0.00,50.00,50.00,1806.00,90300.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (11,3,5,'dien',2,50.00,100.00,50.00,1866.00,93300.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (12,3,5,'dien',3,100.00,200.00,50.00,2167.00,108350.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (13,3,6,'nuoc',1,0.00,10.00,10.00,11045.00,110450.00);
INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES (14,3,6,'nuoc',2,10.00,20.00,2.00,12445.00,24890.00);
INSERT INTO `consumption_targets` (`id`, `scope_type`, `scope_id`, `utility_type`, `billing_period_id`, `target_value`, `created_by`, `created_at`) VALUES (1,'apartment',1,'dien',2,320.00,1,'2026-10-04 11:40:19');
INSERT INTO `meter_readings` (`id`, `meter_id`, `billing_period_id`, `previous_reading`, `current_reading`, `consumption`, `status`, `source`, `import_batch_id`, `is_meter_changed`, `old_meter_id`, `note`, `entered_by`, `confirmed_by`, `confirmed_at`, `created_at`, `updated_at`) VALUES (1,1,1,1000.00,1343.00,343.00,'da_xac_nhan','thu_cong',NULL,0,NULL,NULL,2,1,'2024-07-02 10:00:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meter_readings` (`id`, `meter_id`, `billing_period_id`, `previous_reading`, `current_reading`, `consumption`, `status`, `source`, `import_batch_id`, `is_meter_changed`, `old_meter_id`, `note`, `entered_by`, `confirmed_by`, `confirmed_at`, `created_at`, `updated_at`) VALUES (2,2,1,200.00,216.00,16.00,'da_xac_nhan','thu_cong',NULL,0,NULL,NULL,2,1,'2024-07-02 10:00:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meter_readings` (`id`, `meter_id`, `billing_period_id`, `previous_reading`, `current_reading`, `consumption`, `status`, `source`, `import_batch_id`, `is_meter_changed`, `old_meter_id`, `note`, `entered_by`, `confirmed_by`, `confirmed_at`, `created_at`, `updated_at`) VALUES (3,3,1,500.00,650.00,150.00,'da_xac_nhan','thu_cong',NULL,0,NULL,NULL,2,1,'2024-07-02 10:05:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meter_readings` (`id`, `meter_id`, `billing_period_id`, `previous_reading`, `current_reading`, `consumption`, `status`, `source`, `import_batch_id`, `is_meter_changed`, `old_meter_id`, `note`, `entered_by`, `confirmed_by`, `confirmed_at`, `created_at`, `updated_at`) VALUES (4,4,1,100.00,130.00,30.00,'da_xac_nhan','thu_cong',NULL,0,NULL,NULL,2,1,'2024-07-02 10:05:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meter_readings` (`id`, `meter_id`, `billing_period_id`, `previous_reading`, `current_reading`, `consumption`, `status`, `source`, `import_batch_id`, `is_meter_changed`, `old_meter_id`, `note`, `entered_by`, `confirmed_by`, `confirmed_at`, `created_at`, `updated_at`) VALUES (5,5,1,300.00,450.00,150.00,'da_xac_nhan','thu_cong',NULL,0,NULL,NULL,2,1,'2024-07-02 10:10:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meter_readings` (`id`, `meter_id`, `billing_period_id`, `previous_reading`, `current_reading`, `consumption`, `status`, `source`, `import_batch_id`, `is_meter_changed`, `old_meter_id`, `note`, `entered_by`, `confirmed_by`, `confirmed_at`, `created_at`, `updated_at`) VALUES (6,6,1,50.00,62.00,12.00,'da_xac_nhan','thu_cong',NULL,0,NULL,NULL,2,1,'2024-07-02 10:10:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meter_readings` (`id`, `meter_id`, `billing_period_id`, `previous_reading`, `current_reading`, `consumption`, `status`, `source`, `import_batch_id`, `is_meter_changed`, `old_meter_id`, `note`, `entered_by`, `confirmed_by`, `confirmed_at`, `created_at`, `updated_at`) VALUES (7,7,1,0.00,0.00,0.00,'da_xac_nhan','thu_cong',NULL,0,NULL,NULL,2,1,'2024-07-02 10:15:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meter_readings` (`id`, `meter_id`, `billing_period_id`, `previous_reading`, `current_reading`, `consumption`, `status`, `source`, `import_batch_id`, `is_meter_changed`, `old_meter_id`, `note`, `entered_by`, `confirmed_by`, `confirmed_at`, `created_at`, `updated_at`) VALUES (8,8,1,0.00,0.00,0.00,'da_xac_nhan','thu_cong',NULL,0,NULL,NULL,2,1,'2024-07-02 10:15:00','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meters` (`id`, `apartment_id`, `meter_code`, `utility_type`, `usage_type`, `display_label`, `initial_reading`, `status`, `created_at`, `updated_at`) VALUES (1,1,'BT1-DIEN','dien','sinh_hoat',NULL,1000.00,'hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meters` (`id`, `apartment_id`, `meter_code`, `utility_type`, `usage_type`, `display_label`, `initial_reading`, `status`, `created_at`, `updated_at`) VALUES (2,1,'BT1-NUOC','nuoc','sinh_hoat',NULL,200.00,'hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meters` (`id`, `apartment_id`, `meter_code`, `utility_type`, `usage_type`, `display_label`, `initial_reading`, `status`, `created_at`, `updated_at`) VALUES (3,2,'S01-DIEN','dien','kinh_doanh',NULL,500.00,'hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meters` (`id`, `apartment_id`, `meter_code`, `utility_type`, `usage_type`, `display_label`, `initial_reading`, `status`, `created_at`, `updated_at`) VALUES (4,2,'S01-NUOC','nuoc','kinh_doanh',NULL,100.00,'hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meters` (`id`, `apartment_id`, `meter_code`, `utility_type`, `usage_type`, `display_label`, `initial_reading`, `status`, `created_at`, `updated_at`) VALUES (5,3,'CC2305-DIEN','dien','sinh_hoat',NULL,300.00,'hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meters` (`id`, `apartment_id`, `meter_code`, `utility_type`, `usage_type`, `display_label`, `initial_reading`, `status`, `created_at`, `updated_at`) VALUES (6,3,'CC2305-NUOC','nuoc','sinh_hoat',NULL,50.00,'hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meters` (`id`, `apartment_id`, `meter_code`, `utility_type`, `usage_type`, `display_label`, `initial_reading`, `status`, `created_at`, `updated_at`) VALUES (7,4,'CC2306-DIEN','dien','sinh_hoat',NULL,0.00,'hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `meters` (`id`, `apartment_id`, `meter_code`, `utility_type`, `usage_type`, `display_label`, `initial_reading`, `status`, `created_at`, `updated_at`) VALUES (8,4,'CC2306-NUOC','nuoc','sinh_hoat',NULL,0.00,'hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `parking_vehicles` (`id`, `apartment_id`, `vehicle_type`, `quantity`, `created_at`, `updated_at`) VALUES (1,1,'o_to',1,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `parking_vehicles` (`id`, `apartment_id`, `vehicle_type`, `quantity`, `created_at`, `updated_at`) VALUES (2,2,'xe_may',2,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES (1,'utility','dien','sinh_hoat',NULL,8.00,NULL,'2024-01-01','direct','da_duyet','hieu_luc',NULL,NULL,NULL,1,1,'2026-10-04 11:40:19','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES (2,'utility','dien','kinh_doanh',NULL,8.00,3904.00,'2024-01-01','direct','da_duyet','hieu_luc',NULL,NULL,NULL,1,1,'2026-10-04 11:40:19','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES (3,'utility','nuoc','sinh_hoat',NULL,NULL,NULL,'2024-01-01','direct','da_duyet','hieu_luc',NULL,NULL,NULL,1,1,'2026-10-04 11:40:19','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES (4,'utility','nuoc','kinh_doanh',NULL,NULL,25160.00,'2024-01-01','direct','da_duyet','hieu_luc',NULL,NULL,NULL,1,1,'2026-10-04 11:40:19','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES (5,'fixed_fee',NULL,NULL,'dich_vu_co_o',NULL,3000.00,'2024-01-01','direct','da_duyet','hieu_luc',NULL,NULL,NULL,1,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES (6,'fixed_fee',NULL,NULL,'dich_vu_khong_o',NULL,1500.00,'2024-01-01','direct','da_duyet','hieu_luc',NULL,NULL,NULL,1,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES (7,'fixed_fee',NULL,NULL,'bao_hiem_chay_no',NULL,1953.00,'2024-01-01','direct','da_duyet','hieu_luc',NULL,NULL,NULL,1,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES (8,'fixed_fee',NULL,NULL,'gui_xe_dap_dien',NULL,100000.00,'2024-01-01','direct','da_duyet','hieu_luc',NULL,NULL,NULL,1,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES (9,'fixed_fee',NULL,NULL,'gui_xe_may',NULL,50000.00,'2024-01-01','direct','da_duyet','hieu_luc',NULL,NULL,NULL,1,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES (10,'fixed_fee',NULL,NULL,'gui_o_to',NULL,500000.00,'2024-01-01','direct','da_duyet','hieu_luc',NULL,NULL,NULL,1,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES (11,'fixed_fee',NULL,NULL,'mo_lai_dich_vu',NULL,20000.00,'2024-01-01','direct','da_duyet','hieu_luc',NULL,NULL,NULL,1,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `pricing_tiers` (`id`, `pricing_plan_id`, `tier_order`, `tier_from`, `tier_to`, `unit_price`) VALUES (1,1,1,0.00,50.00,1806.00);
INSERT INTO `pricing_tiers` (`id`, `pricing_plan_id`, `tier_order`, `tier_from`, `tier_to`, `unit_price`) VALUES (2,1,2,50.00,100.00,1866.00);
INSERT INTO `pricing_tiers` (`id`, `pricing_plan_id`, `tier_order`, `tier_from`, `tier_to`, `unit_price`) VALUES (3,1,3,100.00,200.00,2167.00);
INSERT INTO `pricing_tiers` (`id`, `pricing_plan_id`, `tier_order`, `tier_from`, `tier_to`, `unit_price`) VALUES (4,1,4,200.00,300.00,2729.00);
INSERT INTO `pricing_tiers` (`id`, `pricing_plan_id`, `tier_order`, `tier_from`, `tier_to`, `unit_price`) VALUES (5,1,5,300.00,400.00,3050.00);
INSERT INTO `pricing_tiers` (`id`, `pricing_plan_id`, `tier_order`, `tier_from`, `tier_to`, `unit_price`) VALUES (6,1,6,400.00,NULL,3151.00);
INSERT INTO `pricing_tiers` (`id`, `pricing_plan_id`, `tier_order`, `tier_from`, `tier_to`, `unit_price`) VALUES (7,3,1,0.00,10.00,11045.00);
INSERT INTO `pricing_tiers` (`id`, `pricing_plan_id`, `tier_order`, `tier_from`, `tier_to`, `unit_price`) VALUES (8,3,2,10.00,20.00,12445.00);
INSERT INTO `pricing_tiers` (`id`, `pricing_plan_id`, `tier_order`, `tier_from`, `tier_to`, `unit_price`) VALUES (9,3,3,20.00,30.00,15745.00);
INSERT INTO `pricing_tiers` (`id`, `pricing_plan_id`, `tier_order`, `tier_from`, `tier_to`, `unit_price`) VALUES (10,3,4,30.00,NULL,17245.00);
INSERT INTO `recommendation_rules` (`id`, `name`, `condition_type`, `n_periods`, `utility_type`, `recommendation_text`, `status`, `created_by`, `created_at`) VALUES (1,'CL=0 trong 2 kỳ liên tiếp','cl_zero_n_ky',2,NULL,'Kiểm tra công tơ/căn hộ trống','bat',1,'2026-10-04 11:40:19');
INSERT INTO `recommendation_rules` (`id`, `name`, `condition_type`, `n_periods`, `utility_type`, `recommendation_text`, `status`, `created_by`, `created_at`) VALUES (2,'Tiêu thụ tăng bất thường','vuot_nguong_lien_tiep',1,NULL,'Đề xuất kiểm tra công tơ','bat',1,'2026-10-04 11:40:19');
INSERT INTO `residents` (`id`, `apartment_id`, `full_name`, `resident_role`, `phone`, `email`, `is_active`, `effective_from`, `effective_to`, `created_at`, `updated_at`) VALUES (1,1,'Phạm Văn A','chu_ho',NULL,NULL,1,'2023-01-01',NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `residents` (`id`, `apartment_id`, `full_name`, `resident_role`, `phone`, `email`, `is_active`, `effective_from`, `effective_to`, `created_at`, `updated_at`) VALUES (2,2,'Nguyễn Thị B','chu_ho',NULL,NULL,1,'2023-01-01',NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `residents` (`id`, `apartment_id`, `full_name`, `resident_role`, `phone`, `email`, `is_active`, `effective_from`, `effective_to`, `created_at`, `updated_at`) VALUES (3,3,'Trần Văn C','khach_thue',NULL,NULL,1,'2024-01-01',NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `residents` (`id`, `apartment_id`, `full_name`, `resident_role`, `phone`, `email`, `is_active`, `effective_from`, `effective_to`, `created_at`, `updated_at`) VALUES (4,4,'Lê Thị D','chu_ho',NULL,NULL,1,'2023-06-01',NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `system_configs` (`id`, `config_key`, `config_value`, `description`, `updated_by`, `updated_at`) VALUES (1,'session_timeout_minutes','30','[TL] NFR-01 - tự đăng xuất sau 30 phút không hoạt động',1,'2026-10-04 11:40:19');
INSERT INTO `system_configs` (`id`, `config_key`, `config_value`, `description`, `updated_by`, `updated_at`) VALUES (2,'max_failed_login_attempts','5','[TL] BR-02 - khoá tạm sau 5 lần sai',1,'2026-10-04 11:40:19');
INSERT INTO `system_configs` (`id`, `config_key`, `config_value`, `description`, `updated_by`, `updated_at`) VALUES (3,'account_lock_duration_minutes','30','[ASSUMPTION - J-03 chưa được tài liệu quy định cụ thể]',1,'2026-10-04 11:40:19');
INSERT INTO `system_configs` (`id`, `config_key`, `config_value`, `description`, `updated_by`, `updated_at`) VALUES (4,'bank_account_info','Chưa cấu hình - CHƯA ĐƯỢC ĐẶC TẢ TRONG TÀI LIỆU','Thông tin tài khoản in trên phiếu thu (E1)',1,'2026-10-04 11:40:19');
INSERT INTO `system_configs` (`id`, `config_key`, `config_value`, `description`, `updated_by`, `updated_at`) VALUES (5,'import_strict_mode','0','[ASSUMPTION A-06 - J-12] 0 = cho phép lưu partial (dòng hợp lệ), 1 = all-or-nothing',1,'2026-10-04 11:40:19');
INSERT INTO `system_configs` (`id`, `config_key`, `config_value`, `description`, `updated_by`, `updated_at`) VALUES (6,'j16_low_max_kwh','10','[ASSUMPTION J-16-P] Chỉ gợi ý ưu tiên: CL điện < giá trị này -> gợi ý Thấp. BQL quyết định ưu tiên cuối.',NULL,'2026-10-04 11:40:19');
INSERT INTO `system_configs` (`id`, `config_key`, `config_value`, `description`, `updated_by`, `updated_at`) VALUES (7,'j16_high_min_kwh','100','[ASSUMPTION J-16-P] Chỉ gợi ý ưu tiên: CL điện >= giá trị này -> gợi ý Cao. BQL quyết định ưu tiên cuối.',NULL,'2026-10-04 11:40:19');
INSERT INTO `system_configs` (`id`, `config_key`, `config_value`, `description`, `updated_by`, `updated_at`) VALUES (8,'j16_low_max_m3','1','[ASSUMPTION J-16-P] Chỉ gợi ý ưu tiên: CL nước < giá trị này -> gợi ý Thấp. BQL quyết định ưu tiên cuối.',NULL,'2026-10-04 11:40:19');
INSERT INTO `system_configs` (`id`, `config_key`, `config_value`, `description`, `updated_by`, `updated_at`) VALUES (9,'j16_high_min_m3','10','[ASSUMPTION J-16-P] Chỉ gợi ý ưu tiên: CL nước >= giá trị này -> gợi ý Cao. BQL quyết định ưu tiên cuối.',NULL,'2026-10-04 11:40:19');
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `status`, `failed_login_count`, `locked_until`, `last_login_at`, `created_by`, `created_at`, `updated_at`) VALUES (1,'bql01','$2y$12$PZJ9SiqstToYRUqfKynZ8e.xcWAcz8gFAgVbeGfxaoAfUOc7sgalC','Nguyễn Văn Quản','bql','hoat_dong',0,NULL,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `status`, `failed_login_count`, `locked_until`, `last_login_at`, `created_by`, `created_at`, `updated_at`) VALUES (2,'kythuat01','$2y$12$PZJ9SiqstToYRUqfKynZ8e.xcWAcz8gFAgVbeGfxaoAfUOc7sgalC','Trần Văn Kỹ','ky_thuat','hoat_dong',0,NULL,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `status`, `failed_login_count`, `locked_until`, `last_login_at`, `created_by`, `created_at`, `updated_at`) VALUES (3,'ketoan01','$2y$12$PZJ9SiqstToYRUqfKynZ8e.xcWAcz8gFAgVbeGfxaoAfUOc7sgalC','Lê Thị Kế','ke_toan','hoat_dong',0,NULL,NULL,NULL,'2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `zones` (`id`, `building_id`, `code`, `name`, `zone_type`, `status`, `created_at`, `updated_at`) VALUES (1,1,'KHU-BT','Khu Biệt thự','biet_thu','hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `zones` (`id`, `building_id`, `code`, `name`, `zone_type`, `status`, `created_at`, `updated_at`) VALUES (2,1,'KHU-SH','Khu Shophouse','shophouse','hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');
INSERT INTO `zones` (`id`, `building_id`, `code`, `name`, `zone_type`, `status`, `created_at`, `updated_at`) VALUES (3,1,'KHU-CC','Khu Chung cư','chung_cu','hoat_dong','2026-10-04 11:40:19','2026-10-04 11:40:19');

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- TABLE COUNT: 27
-- ============================================================================
