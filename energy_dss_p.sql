-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 05, 2026 at 03:01 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `energy_dss.p`
--

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

CREATE TABLE `alerts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `alert_type` varchar(40) NOT NULL DEFAULT 'threshold' COMMENT 'threshold = FN-07 (rule / thủ công); no_usage_consumption = [BA Proposal J-16] (patch 005)',
  `alert_rule_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL nếu cảnh báo thủ công (FN-07.03)',
  `meter_id` int(10) UNSIGNED DEFAULT NULL,
  `dedupe_meter_id` int(10) UNSIGNED GENERATED ALWAYS AS (coalesce(`meter_id`,0)) STORED COMMENT 'Cột sinh cho khoá chống trùng: 0 = cảnh báo cấp căn hộ (không gắn công tơ)',
  `apartment_id` int(10) UNSIGNED NOT NULL,
  `billing_period_id` int(10) UNSIGNED NOT NULL,
  `utility_type` enum('dien','nuoc') DEFAULT NULL COMMENT 'Bắt buộc với FN-07; NULL chỉ hợp lệ khi alert_type = no_usage_consumption và nghĩa là Điện + Nước (patch 005)',
  `source` enum('rule','thu_cong','he_thong') NOT NULL DEFAULT 'rule' COMMENT '[TL] 2.3 Alert: KT Create; he_thong = quy tắc hệ thống J-16 (patch 005)',
  `actual_value` decimal(14,4) DEFAULT NULL,
  `compare_value` decimal(14,4) DEFAULT NULL COMMENT 'Giá trị kỳ trước/baseline dùng để so sánh',
  `percent_change` decimal(8,2) DEFAULT NULL COMMENT '[TL] BR-27',
  `priority` enum('cao','trung_binh','thap') NOT NULL DEFAULT 'trung_binh' COMMENT '[DX] BR-43, BQL có thể sửa',
  `status` enum('moi','da_review','can_kiem_tra_lai','da_xac_nhan','da_dong') NOT NULL DEFAULT 'moi' COMMENT '[ASSUMPTION A-02 - J-04]',
  `description` varchar(500) DEFAULT NULL COMMENT 'Mô tả hiện tượng khi source=thu_cong (bắt buộc theo VAL)',
  `snapshot_json` longtext DEFAULT NULL COMMENT 'J-16: dữ liệu lúc phát hiện / đánh giá lại (CL, công tơ, chỉ số, rule, thời điểm)',
  `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL = SYS-01 tự sinh',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `closed_by` int(10) UNSIGNED DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `close_reason` varchar(500) DEFAULT NULL,
  `reopen_reason` varchar(500) DEFAULT NULL,
  `conclusion` varchar(40) DEFAULT NULL COMMENT 'J-16 kết luận BQL: apartment_status_incorrect / reading_incorrect / real_consumption_confirmed / undetermined',
  `conclusion_note` varchar(500) DEFAULT NULL,
  `concluded_by` int(10) UNSIGNED DEFAULT NULL,
  `concluded_at` datetime DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL COMMENT 'J-16 kết luận undetermined: ngày theo dõi lại',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `system_dedupe_key` tinyint(3) UNSIGNED GENERATED ALWAYS AS (if(`source` = 'he_thong',1,NULL)) STORED COMMENT 'Chỉ khác NULL với cảnh báo hệ thống -> uq_alert_system_dedupe chỉ áp cho loại này'
) ;

-- --------------------------------------------------------

--
-- Table structure for table `alert_events`
--

CREATE TABLE `alert_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `alert_id` bigint(20) UNSIGNED NOT NULL,
  `event_type` enum('state_change','contact') NOT NULL,
  `old_status` enum('moi','da_review','can_kiem_tra_lai','da_xac_nhan','da_dong') DEFAULT NULL,
  `new_status` enum('moi','da_review','can_kiem_tra_lai','da_xac_nhan','da_dong') DEFAULT NULL,
  `old_priority` enum('cao','trung_binh','thap') DEFAULT NULL,
  `new_priority` enum('cao','trung_binh','thap') DEFAULT NULL,
  `contact_type` enum('phone','resident_response') DEFAULT NULL,
  `contact_time` datetime DEFAULT NULL,
  `contact_result` varchar(30) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `alert_rules`
--

CREATE TABLE `alert_rules` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `measure_type` enum('tieu_thu','chi_phi') NOT NULL COMMENT '[TL] FR-08',
  `utility_type` enum('dien','nuoc') NOT NULL,
  `compare_base` enum('ky_truoc','baseline','gia_tri_tuyet_doi') NOT NULL,
  `operator` enum('gt','gte') NOT NULL DEFAULT 'gt' COMMENT 'VD tăng > X% so baseline',
  `threshold_value` decimal(12,4) NOT NULL COMMENT '[TL] FR-08 ASSUMPTION - giá trị cụ thể CHƯA ĐƯỢC ĐẶC TẢ, xem J-32',
  `scope_type` enum('toan_toa','zone','usage_type') NOT NULL DEFAULT 'toan_toa',
  `scope_value` varchar(50) DEFAULT NULL,
  `priority_level` enum('cao','trung_binh','thap') NOT NULL DEFAULT 'trung_binh' COMMENT '[DX] BR-43',
  `status` enum('bat','tat') NOT NULL DEFAULT 'bat',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `alert_rules`
--

INSERT INTO `alert_rules` (`id`, `name`, `measure_type`, `utility_type`, `compare_base`, `operator`, `threshold_value`, `scope_type`, `scope_value`, `priority_level`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Điện vượt baseline >30% [UAT - chưa chính thức, xem J-32]', 'tieu_thu', 'dien', 'baseline', 'gt', 30.0000, 'toan_toa', NULL, 'trung_binh', 'bat', 1, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(2, 'Nước vượt baseline >30% [UAT - chưa chính thức, xem J-32]', 'tieu_thu', 'nuoc', 'baseline', 'gt', 30.0000, 'toan_toa', NULL, 'trung_binh', 'bat', 1, '2026-10-04 11:40:19', '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `apartments`
--

CREATE TABLE `apartments` (
  `id` int(10) UNSIGNED NOT NULL,
  `building_id` int(10) UNSIGNED NOT NULL,
  `zone_id` int(10) UNSIGNED DEFAULT NULL,
  `code` varchar(30) NOT NULL COMMENT '[TL] 2.1 VD LK2, S01, BT4, 2305',
  `area` decimal(10,2) NOT NULL COMMENT '[TL] 2.1 DT, đơn vị m2, phải > 0 (BR-10)',
  `occupancy_status` enum('co_o','khong_phat_sinh') NOT NULL DEFAULT 'co_o' COMMENT '[ASSUMPTION A-12 - J-16], dùng cho BR-15',
  `status` enum('hoat_dong','ngung') NOT NULL DEFAULT 'hoat_dong' COMMENT '[TL] BR-05 không xoá cứng khi đã có chỉ số',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `apartments`
--

INSERT INTO `apartments` (`id`, `building_id`, `zone_id`, `code`, `area`, `occupancy_status`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'BT1', 80.00, 'co_o', 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(2, 1, 2, 'S01', 60.00, 'co_o', 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(3, 1, 3, 'CC-2305', 70.00, 'co_o', 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(4, 1, 3, 'CC-2306', 70.00, 'khong_phat_sinh', 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `apartment_charges`
--

CREATE TABLE `apartment_charges` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `apartment_id` int(10) UNSIGNED NOT NULL,
  `billing_period_id` int(10) UNSIGNED NOT NULL,
  `electricity_consumption` decimal(12,2) NOT NULL DEFAULT 0.00,
  `electricity_amount` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Trước VAT, [TL] BR-13',
  `electricity_vat_amount` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT '[TL] 8% theo BR-13',
  `water_consumption` decimal(12,2) NOT NULL DEFAULT 0.00,
  `water_amount` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT '[TL] BR-14; VAT nước = 0 theo [ASSUMPTION-J-07]',
  `service_fee_amount` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT '[TL] BR-15',
  `fire_insurance_amount` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT '[TL] BR-16',
  `parking_fee_amount` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT '[TL] BR-17',
  `total_arising` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Tổng phát sinh kỳ này',
  `old_debt_amount` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT '[ASSUMPTION A-15 - J-09]',
  `total_due` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT '[TL] BR-18 = total_arising + old_debt_amount',
  `status` enum('da_tinh','da_xac_nhan','bi_tra_lai','da_duyet','da_phat_hanh') NOT NULL DEFAULT 'da_tinh' COMMENT '[ASSUMPTION A-13 - J-18]',
  `confirmed_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'Kế toán (FN-05.06)',
  `confirmed_at` datetime DEFAULT NULL,
  `approved_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'BQL (nếu bắt buộc theo A-13)',
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL COMMENT '[TL] BR-24',
  `issued_at` datetime DEFAULT NULL COMMENT 'Thời điểm xuất phiếu FN-09.02',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `apartment_charges`
--

INSERT INTO `apartment_charges` (`id`, `apartment_id`, `billing_period_id`, `electricity_consumption`, `electricity_amount`, `electricity_vat_amount`, `water_consumption`, `water_amount`, `service_fee_amount`, `fire_insurance_amount`, `parking_fee_amount`, `total_arising`, `old_debt_amount`, `total_due`, `status`, `confirmed_by`, `confirmed_at`, `approved_by`, `approved_at`, `rejection_reason`, `issued_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 343.00, 804350.00, 64348.00, 16.00, 185120.00, 240000.00, 156240.00, 500000.00, 1950058.00, 0.00, 1950058.00, 'da_phat_hanh', 3, '2024-07-03 09:00:00', 1, '2024-07-03 14:00:00', NULL, '2024-07-04 08:00:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(2, 2, 1, 150.00, 585600.00, 46848.00, 30.00, 754800.00, 180000.00, 117180.00, 100000.00, 1784428.00, 0.00, 1784428.00, 'da_phat_hanh', 3, '2024-07-03 09:05:00', 1, '2024-07-03 14:00:00', NULL, '2024-07-04 08:00:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(3, 3, 1, 150.00, 291950.00, 23356.00, 12.00, 135340.00, 210000.00, 136710.00, 0.00, 797356.00, 0.00, 797356.00, 'da_phat_hanh', 3, '2024-07-03 09:10:00', 1, '2024-07-03 14:00:00', NULL, '2024-07-04 08:00:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(4, 4, 1, 0.00, 0.00, 0.00, 0.00, 0.00, 105000.00, 136710.00, 0.00, 241710.00, 0.00, 241710.00, 'da_phat_hanh', 3, '2024-07-03 09:15:00', 1, '2024-07-03 14:00:00', NULL, '2024-07-04 08:00:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL = hệ thống (SYS-01) [SL]',
  `action` varchar(100) NOT NULL COMMENT 'VD: create, update, approve, deactivate, login_failed,...',
  `entity_type` varchar(100) NOT NULL COMMENT 'Tên bảng/đối tượng nghiệp vụ, VD: meter_reading, tariff, alert',
  `entity_id` varchar(50) DEFAULT NULL,
  `old_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '[TL] NFR-05 giá trị cũ' CHECK (json_valid(`old_value`)),
  `new_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '[TL] NFR-05 giá trị mới' CHECK (json_valid(`new_value`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='[TL] BR-04: lưu >=24 tháng (Proposed), user thường không xoá/sửa được';

-- --------------------------------------------------------

--
-- Table structure for table `baselines`
--

CREATE TABLE `baselines` (
  `id` int(10) UNSIGNED NOT NULL,
  `scope_type` enum('meter','apartment','zone','building') NOT NULL,
  `scope_id` int(10) UNSIGNED NOT NULL COMMENT 'Id thực thể theo scope_type: meters/apartments/zones/buildings.id. Đa hình -> không FK, Service kiểm tra tồn tại',
  `utility_type` enum('dien','nuoc') NOT NULL,
  `method` enum('ky_truoc','trung_binh_n_ky','cung_ky_nam_truoc','nhap_tay') NOT NULL DEFAULT 'trung_binh_n_ky' COMMENT '[ASSUMPTION A-08 - J-20]',
  `n_periods` tinyint(3) UNSIGNED DEFAULT 3 COMMENT '[TL] T2 câu 25: tối thiểu 3 kỳ',
  `manual_value` decimal(12,2) DEFAULT NULL,
  `computed_value` decimal(12,2) DEFAULT NULL COMMENT 'Giá trị baseline tính được, cache lại để truy vấn nhanh',
  `effective_date` date NOT NULL,
  `status` enum('hieu_luc','het_hieu_luc') NOT NULL DEFAULT 'hieu_luc',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `baselines`
--

INSERT INTO `baselines` (`id`, `scope_type`, `scope_id`, `utility_type`, `method`, `n_periods`, `manual_value`, `computed_value`, `effective_date`, `status`, `created_by`, `created_at`) VALUES
(1, 'apartment', 1, 'dien', 'ky_truoc', NULL, NULL, 343.00, '2024-07-01', 'hieu_luc', 1, '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `billing_periods`
--

CREATE TABLE `billing_periods` (
  `id` int(10) UNSIGNED NOT NULL,
  `period_code` varchar(7) NOT NULL COMMENT 'Định dạng MM/YYYY [DX]',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reading_due_date` date DEFAULT NULL COMMENT '[DX] phục vụ KPI thời gian xử lý',
  `status` enum('mo','da_khoa','mo_lai') NOT NULL DEFAULT 'mo',
  `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT '[ASSUMPTION A-05 - J-10]',
  `locked_by` int(10) UNSIGNED DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `reopened_by` int(10) UNSIGNED DEFAULT NULL COMMENT '[TL] 2.3 chỉ BQL được mở lại',
  `reopened_at` datetime DEFAULT NULL,
  `reopen_reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `billing_periods`
--

INSERT INTO `billing_periods` (`id`, `period_code`, `start_date`, `end_date`, `reading_due_date`, `status`, `created_by`, `locked_by`, `locked_at`, `reopened_by`, `reopened_at`, `reopen_reason`, `created_at`, `updated_at`) VALUES
(1, '06/2024', '2024-06-01', '2024-06-30', NULL, 'da_khoa', 1, 1, '2024-07-05 09:00:00', NULL, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(2, '07/2024', '2024-07-01', '2024-07-31', NULL, 'mo', 1, NULL, NULL, NULL, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `buildings`
--

CREATE TABLE `buildings` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `status` enum('hoat_dong','ngung') NOT NULL DEFAULT 'hoat_dong',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `buildings`
--

INSERT INTO `buildings` (`id`, `code`, `name`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'CHUNGCU-A', 'Chung cư A', 'hoat_dong', 1, '2026-10-04 11:40:19', '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `charge_tier_details`
--

CREATE TABLE `charge_tier_details` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `apartment_charge_id` bigint(20) UNSIGNED NOT NULL,
  `meter_reading_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Chỉ số (công tơ + kỳ + CL) sinh ra dòng tiền này',
  `utility_type` enum('dien','nuoc') NOT NULL,
  `tier_order` tinyint(3) UNSIGNED NOT NULL,
  `tier_from` decimal(12,2) NOT NULL,
  `tier_to` decimal(12,2) DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL COMMENT 'Sản lượng rơi vào bậc này',
  `unit_price` decimal(12,2) NOT NULL,
  `amount` decimal(14,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `charge_tier_details`
--

INSERT INTO `charge_tier_details` (`id`, `apartment_charge_id`, `meter_reading_id`, `utility_type`, `tier_order`, `tier_from`, `tier_to`, `quantity`, `unit_price`, `amount`) VALUES
(1, 1, 1, 'dien', 1, 0.00, 50.00, 50.00, 1806.00, 90300.00),
(2, 1, 1, 'dien', 2, 50.00, 100.00, 50.00, 1866.00, 93300.00),
(3, 1, 1, 'dien', 3, 100.00, 200.00, 100.00, 2167.00, 216700.00),
(4, 1, 1, 'dien', 4, 200.00, 300.00, 100.00, 2729.00, 272900.00),
(5, 1, 1, 'dien', 5, 300.00, 400.00, 43.00, 3050.00, 131150.00),
(6, 1, 2, 'nuoc', 1, 0.00, 10.00, 10.00, 11045.00, 110450.00),
(7, 1, 2, 'nuoc', 2, 10.00, 20.00, 6.00, 12445.00, 74670.00),
(8, 2, 3, 'dien', 1, 0.00, NULL, 150.00, 3904.00, 585600.00),
(9, 2, 4, 'nuoc', 1, 0.00, NULL, 30.00, 25160.00, 754800.00),
(10, 3, 5, 'dien', 1, 0.00, 50.00, 50.00, 1806.00, 90300.00),
(11, 3, 5, 'dien', 2, 50.00, 100.00, 50.00, 1866.00, 93300.00),
(12, 3, 5, 'dien', 3, 100.00, 200.00, 50.00, 2167.00, 108350.00),
(13, 3, 6, 'nuoc', 1, 0.00, 10.00, 10.00, 11045.00, 110450.00),
(14, 3, 6, 'nuoc', 2, 10.00, 20.00, 2.00, 12445.00, 24890.00);

-- --------------------------------------------------------

--
-- Table structure for table `consumption_targets`
--

CREATE TABLE `consumption_targets` (
  `id` int(10) UNSIGNED NOT NULL,
  `scope_type` enum('apartment','zone','building') NOT NULL,
  `scope_id` int(10) UNSIGNED NOT NULL COMMENT 'Id thực thể theo scope_type: apartments/zones/buildings.id. Đa hình -> không FK, Service kiểm tra tồn tại',
  `utility_type` enum('dien','nuoc') NOT NULL,
  `billing_period_id` int(10) UNSIGNED NOT NULL COMMENT '[TL] FR-07 Acceptance "target tháng"',
  `target_value` decimal(12,2) NOT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `consumption_targets`
--

INSERT INTO `consumption_targets` (`id`, `scope_type`, `scope_id`, `utility_type`, `billing_period_id`, `target_value`, `created_by`, `created_at`) VALUES
(1, 'apartment', 1, 'dien', 2, 320.00, 1, '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `email_notifications`
--

CREATE TABLE `email_notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `notification_type` varchar(30) NOT NULL DEFAULT 'bill_notice' COMMENT 'bill_notice (phiếu phí) / consumption_verification (J-16, patch 005)',
  `resident_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Người nhận (chủ hộ / khách thuê) lúc gửi [A-EMAIL-01]',
  `apartment_id` int(10) UNSIGNED NOT NULL,
  `billing_period_id` int(10) UNSIGNED DEFAULT NULL,
  `apartment_charge_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Bản tính được thông báo (FN-09.02)',
  `alert_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Cảnh báo liên quan (J-16 consumption_verification)',
  `recipient_email` varchar(255) NOT NULL COMMENT 'Snapshot địa chỉ lúc gửi (chữ thường) - dữ liệu cá nhân BR-36',
  `recipient_name` varchar(150) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` mediumtext NOT NULL COMMENT 'Snapshot nội dung đã gửi',
  `body_text` mediumtext NOT NULL,
  `content_hash` char(64) NOT NULL COMMENT 'sha256 nội dung - chống gửi trùng cùng nội dung [A-EMAIL-07]',
  `transport` varchar(20) NOT NULL COMMENT 'smtp / log / array (MAIL_DRIVER lúc gửi)',
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempt_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL COMMENT 'Thông báo lỗi đã làm sạch - không chứa thông tin đăng nhập SMTP',
  `sent_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='FN-10.03 (Secondary) hộp thư đi / lịch sử gửi email - STEP 25, patch 004';

-- --------------------------------------------------------

--
-- Table structure for table `import_batches`
--

CREATE TABLE `import_batches` (
  `id` int(10) UNSIGNED NOT NULL,
  `billing_period_id` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `total_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `valid_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `error_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `control_total_diff` decimal(14,2) DEFAULT NULL COMMENT '[TL] BR-09 tóm tắt: tổng |difference| các chỉ tiêu; NULL nếu file không có dòng Tổng',
  `control_total_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Chi tiết theo chỉ tiêu: {"ma":{"label","expected","actual","difference"}}; NULL nếu không có dòng Tổng' CHECK (json_valid(`control_total_details`)),
  `status` enum('da_tai_len','da_kiem_tra','da_luu','da_huy') NOT NULL DEFAULT 'da_tai_len',
  `uploaded_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `import_errors`
--

CREATE TABLE `import_errors` (
  `id` int(10) UNSIGNED NOT NULL,
  `import_batch_id` int(10) UNSIGNED NOT NULL,
  `row_no` int(10) UNSIGNED NOT NULL COMMENT 'Đổi tên từ row_number vì là reserved keyword (window function) từ MySQL 8.0',
  `apartment_code` varchar(30) DEFAULT NULL,
  `error_message` varchar(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inspection_cases`
--

CREATE TABLE `inspection_cases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `alert_id` bigint(20) UNSIGNED NOT NULL,
  `content` varchar(500) NOT NULL COMMENT 'Nội dung yêu cầu kiểm tra',
  `assigned_to` int(10) UNSIGNED NOT NULL COMMENT 'Kỹ thuật được giao',
  `status` enum('da_giao','da_co_ket_qua','da_dong') NOT NULL DEFAULT 'da_giao',
  `result_conclusion` enum('canh_bao_dung','canh_bao_sai','do_ghi_sai','khong_xac_dinh','cong_to_binh_thuong','loi_ghi_chi_so','nghi_ngo_cong_to','khong_tiep_can','khac') DEFAULT NULL COMMENT '[DX] K-15; 5 giá trị cuối = kết quả kỹ thuật J-16 METER_OK / READING_ERROR / METER_SUSPECTED / UNABLE_TO_ACCESS / OTHER (patch 005)',
  `rechecked_value` decimal(12,2) DEFAULT NULL COMMENT 'Chỉ số đọc lại nếu có',
  `note` varchar(500) DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL COMMENT 'BQL',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meters`
--

CREATE TABLE `meters` (
  `id` int(10) UNSIGNED NOT NULL,
  `apartment_id` int(10) UNSIGNED NOT NULL,
  `meter_code` varchar(30) NOT NULL,
  `utility_type` enum('dien','nuoc') NOT NULL,
  `usage_type` enum('sinh_hoat','kinh_doanh') NOT NULL COMMENT '[TL] 2.2 quyết định biểu giá áp dụng',
  `display_label` varchar(50) DEFAULT NULL COMMENT '[TL] 2.1 VD hậu tố KD/ở/T1/T2',
  `initial_reading` decimal(12,2) DEFAULT 0.00 COMMENT '[DX] CSĐ cho kỳ đầu tiên',
  `status` enum('hoat_dong','ngung') NOT NULL DEFAULT 'hoat_dong',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `meters`
--

INSERT INTO `meters` (`id`, `apartment_id`, `meter_code`, `utility_type`, `usage_type`, `display_label`, `initial_reading`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'BT1-DIEN', 'dien', 'sinh_hoat', NULL, 1000.00, 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(2, 1, 'BT1-NUOC', 'nuoc', 'sinh_hoat', NULL, 200.00, 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(3, 2, 'S01-DIEN', 'dien', 'kinh_doanh', NULL, 500.00, 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(4, 2, 'S01-NUOC', 'nuoc', 'kinh_doanh', NULL, 100.00, 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(5, 3, 'CC2305-DIEN', 'dien', 'sinh_hoat', NULL, 300.00, 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(6, 3, 'CC2305-NUOC', 'nuoc', 'sinh_hoat', NULL, 50.00, 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(7, 4, 'CC2306-DIEN', 'dien', 'sinh_hoat', NULL, 0.00, 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(8, 4, 'CC2306-NUOC', 'nuoc', 'sinh_hoat', NULL, 0.00, 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `meter_readings`
--

CREATE TABLE `meter_readings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `meter_id` int(10) UNSIGNED NOT NULL,
  `billing_period_id` int(10) UNSIGNED NOT NULL,
  `previous_reading` decimal(12,2) NOT NULL COMMENT 'CSĐ [TL] 2.1',
  `current_reading` decimal(12,2) NOT NULL COMMENT 'CSC [TL] 2.1',
  `consumption` decimal(12,2) NOT NULL COMMENT 'CL = CSC - CSĐ, [TL] BR-12, lưu thay vì GENERATED để hỗ trợ case thay đồng hồ (FN-04.06)',
  `status` enum('nhap','da_xac_nhan') NOT NULL DEFAULT 'nhap' COMMENT '[TL/SL] 2.4 "số liệu nháp chưa duyệt"',
  `source` enum('thu_cong','import','dieu_chinh') NOT NULL DEFAULT 'thu_cong',
  `import_batch_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Lô import đã tạo bản ghi; NULL = nhập thủ công',
  `is_meter_changed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '[TL] FN-04.06 cờ thay đồng hồ',
  `old_meter_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Tham chiếu đồng hồ cũ khi is_meter_changed=1',
  `note` varchar(500) DEFAULT NULL COMMENT 'Ghi chú hiện trường / lý do điều chỉnh (VAL-04.15)',
  `entered_by` int(10) UNSIGNED NOT NULL,
  `confirmed_by` int(10) UNSIGNED DEFAULT NULL COMMENT '[TL] 2.3 BQL Approve',
  `confirmed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `meter_readings`
--

INSERT INTO `meter_readings` (`id`, `meter_id`, `billing_period_id`, `previous_reading`, `current_reading`, `consumption`, `status`, `source`, `import_batch_id`, `is_meter_changed`, `old_meter_id`, `note`, `entered_by`, `confirmed_by`, `confirmed_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1000.00, 1343.00, 343.00, 'da_xac_nhan', 'thu_cong', NULL, 0, NULL, NULL, 2, 1, '2024-07-02 10:00:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(2, 2, 1, 200.00, 216.00, 16.00, 'da_xac_nhan', 'thu_cong', NULL, 0, NULL, NULL, 2, 1, '2024-07-02 10:00:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(3, 3, 1, 500.00, 650.00, 150.00, 'da_xac_nhan', 'thu_cong', NULL, 0, NULL, NULL, 2, 1, '2024-07-02 10:05:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(4, 4, 1, 100.00, 130.00, 30.00, 'da_xac_nhan', 'thu_cong', NULL, 0, NULL, NULL, 2, 1, '2024-07-02 10:05:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(5, 5, 1, 300.00, 450.00, 150.00, 'da_xac_nhan', 'thu_cong', NULL, 0, NULL, NULL, 2, 1, '2024-07-02 10:10:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(6, 6, 1, 50.00, 62.00, 12.00, 'da_xac_nhan', 'thu_cong', NULL, 0, NULL, NULL, 2, 1, '2024-07-02 10:10:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(7, 7, 1, 0.00, 0.00, 0.00, 'da_xac_nhan', 'thu_cong', NULL, 0, NULL, NULL, 2, 1, '2024-07-02 10:15:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(8, 8, 1, 0.00, 0.00, 0.00, 'da_xac_nhan', 'thu_cong', NULL, 0, NULL, NULL, 2, 1, '2024-07-02 10:15:00', '2026-10-04 11:40:19', '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `parking_vehicles`
--

CREATE TABLE `parking_vehicles` (
  `id` int(10) UNSIGNED NOT NULL,
  `apartment_id` int(10) UNSIGNED NOT NULL,
  `vehicle_type` enum('xe_dap_dien','xe_may','o_to') NOT NULL COMMENT '[TL] 2.1',
  `quantity` smallint(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT '[TL] BR-10 >= 0',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `parking_vehicles`
--

INSERT INTO `parking_vehicles` (`id`, `apartment_id`, `vehicle_type`, `quantity`, `created_at`, `updated_at`) VALUES
(1, 1, 'o_to', 1, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(2, 2, 'xe_may', 2, '2026-10-04 11:40:19', '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `payment_transactions`
--

CREATE TABLE `payment_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `transaction_id` varchar(64) NOT NULL COMMENT 'Mã giao dịch phía ngân hàng - UNIQUE = idempotency (callback lặp không xử lý lại)',
  `source` enum('mock_bank') NOT NULL DEFAULT 'mock_bank' COMMENT 'Nguồn giao dịch - chỉ ngân hàng giả lập (không tích hợp ngân hàng thật)',
  `amount` decimal(14,2) NOT NULL COMMENT 'Số tiền nhận (VNĐ)',
  `transfer_content` varchar(255) NOT NULL DEFAULT '' COMMENT 'Nội dung chuyển khoản (chứa mã thanh toán)',
  `payer_name` varchar(150) DEFAULT NULL,
  `received_at` datetime NOT NULL COMMENT 'Thời điểm ngân hàng ghi nhận',
  `apartment_charge_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Bản tính khớp (NULL khi không xác định được mã thanh toán)',
  `match_status` enum('matched','manual_matched','amount_mismatch','unknown_ref','not_payable','already_paid','ignored') NOT NULL COMMENT 'Kết quả đối soát: matched / manual_matched = đã ghi nhận; amount_mismatch / unknown_ref / not_payable / already_paid = cần kiểm tra; ignored = đã xử lý ngoài hệ thống',
  `match_note` varchar(500) DEFAULT NULL COMMENT 'Lý do đối soát tự động',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'Kế toán xử lý giao dịch cần kiểm tra',
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL COMMENT 'Lý do khớp thủ công / bỏ qua (bắt buộc khi xử lý)',
  `raw_payload` text NOT NULL COMMENT 'Payload callback đã xác thực HMAC (JSON)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `pricing_plans`
--

CREATE TABLE `pricing_plans` (
  `id` int(10) UNSIGNED NOT NULL,
  `price_type` enum('utility','fixed_fee') NOT NULL,
  `utility_type` enum('dien','nuoc') DEFAULT NULL COMMENT 'Bắt buộc khi price_type = utility',
  `usage_type` enum('sinh_hoat','kinh_doanh') DEFAULT NULL COMMENT 'Bắt buộc khi price_type = utility - quyết định bậc thang / đồng giá',
  `fee_type` enum('dich_vu_co_o','dich_vu_khong_o','bao_hiem_chay_no','gui_xe_dap_dien','gui_xe_may','gui_o_to','mo_lai_dich_vu') DEFAULT NULL COMMENT 'Bắt buộc khi price_type = fixed_fee (giữ đủ 7 loại của fixed_fee_rates, gồm mo_lai_dich_vu - BR-19)',
  `vat_percent` decimal(5,2) DEFAULT NULL COMMENT '[TL] điện 8% (BR-13); [ASSUMPTION J-07] nước NULL; phí cố định NULL',
  `flat_unit_price` decimal(12,2) DEFAULT NULL COMMENT 'Đồng giá: utility/kinh_doanh (business_unit_price cũ) hoặc đơn giá phí cố định (unit_price cũ)',
  `effective_date` date NOT NULL COMMENT '[DX] BR-42 không hồi tố cho kỳ đã khoá',
  `origin` enum('direct','proposal') NOT NULL DEFAULT 'direct',
  `approval_status` enum('cho_duyet','da_duyet','tu_choi') NOT NULL DEFAULT 'da_duyet',
  `status` enum('hieu_luc','het_hieu_luc') DEFAULT NULL COMMENT 'Vòng đời phiên bản đã duyệt; NULL khi chưa duyệt / bị từ chối',
  `base_plan_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Phiên bản gốc được đề xuất sửa (tariff_proposals.tariff_id cũ)',
  `proposal_reason` varchar(500) DEFAULT NULL COMMENT 'Lý do đề xuất (tariff_proposals.reason cũ)',
  `rejection_reason` varchar(500) DEFAULT NULL COMMENT '[DX] VAL-03.06 bắt buộc khi từ chối',
  `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'Người tạo phiên bản / người đề xuất (proposed_by cũ)',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'Người duyệt (= approved_by cũ) hoặc từ chối',
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `pricing_plans`
--

INSERT INTO `pricing_plans` (`id`, `price_type`, `utility_type`, `usage_type`, `fee_type`, `vat_percent`, `flat_unit_price`, `effective_date`, `origin`, `approval_status`, `status`, `base_plan_id`, `proposal_reason`, `rejection_reason`, `created_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES
(1, 'utility', 'dien', 'sinh_hoat', NULL, 8.00, NULL, '2024-01-01', 'direct', 'da_duyet', 'hieu_luc', NULL, NULL, NULL, 1, 1, '2026-10-04 11:40:19', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(2, 'utility', 'dien', 'kinh_doanh', NULL, 8.00, 3904.00, '2024-01-01', 'direct', 'da_duyet', 'hieu_luc', NULL, NULL, NULL, 1, 1, '2026-10-04 11:40:19', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(3, 'utility', 'nuoc', 'sinh_hoat', NULL, NULL, NULL, '2024-01-01', 'direct', 'da_duyet', 'hieu_luc', NULL, NULL, NULL, 1, 1, '2026-10-04 11:40:19', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(4, 'utility', 'nuoc', 'kinh_doanh', NULL, NULL, 25160.00, '2024-01-01', 'direct', 'da_duyet', 'hieu_luc', NULL, NULL, NULL, 1, 1, '2026-10-04 11:40:19', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(5, 'fixed_fee', NULL, NULL, 'dich_vu_co_o', NULL, 3000.00, '2024-01-01', 'direct', 'da_duyet', 'hieu_luc', NULL, NULL, NULL, 1, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(6, 'fixed_fee', NULL, NULL, 'dich_vu_khong_o', NULL, 1500.00, '2024-01-01', 'direct', 'da_duyet', 'hieu_luc', NULL, NULL, NULL, 1, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(7, 'fixed_fee', NULL, NULL, 'bao_hiem_chay_no', NULL, 1953.00, '2024-01-01', 'direct', 'da_duyet', 'hieu_luc', NULL, NULL, NULL, 1, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(8, 'fixed_fee', NULL, NULL, 'gui_xe_dap_dien', NULL, 100000.00, '2024-01-01', 'direct', 'da_duyet', 'hieu_luc', NULL, NULL, NULL, 1, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(9, 'fixed_fee', NULL, NULL, 'gui_xe_may', NULL, 50000.00, '2024-01-01', 'direct', 'da_duyet', 'hieu_luc', NULL, NULL, NULL, 1, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(10, 'fixed_fee', NULL, NULL, 'gui_o_to', NULL, 500000.00, '2024-01-01', 'direct', 'da_duyet', 'hieu_luc', NULL, NULL, NULL, 1, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(11, 'fixed_fee', NULL, NULL, 'mo_lai_dich_vu', NULL, 20000.00, '2024-01-01', 'direct', 'da_duyet', 'hieu_luc', NULL, NULL, NULL, 1, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `pricing_tiers`
--

CREATE TABLE `pricing_tiers` (
  `id` int(10) UNSIGNED NOT NULL,
  `pricing_plan_id` int(10) UNSIGNED NOT NULL,
  `tier_order` tinyint(3) UNSIGNED NOT NULL,
  `tier_from` decimal(12,2) NOT NULL COMMENT 'Cận dưới, BAO GỒM',
  `tier_to` decimal(12,2) DEFAULT NULL COMMENT 'Cận trên, KHÔNG bao gồm. NULL = bậc cuối (VAL-03.03)',
  `unit_price` decimal(12,2) NOT NULL
) ;

--
-- Dumping data for table `pricing_tiers`
--

INSERT INTO `pricing_tiers` (`id`, `pricing_plan_id`, `tier_order`, `tier_from`, `tier_to`, `unit_price`) VALUES
(1, 1, 1, 0.00, 50.00, 1806.00),
(2, 1, 2, 50.00, 100.00, 1866.00),
(3, 1, 3, 100.00, 200.00, 2167.00),
(4, 1, 4, 200.00, 300.00, 2729.00),
(5, 1, 5, 300.00, 400.00, 3050.00),
(6, 1, 6, 400.00, NULL, 3151.00),
(7, 3, 1, 0.00, 10.00, 11045.00),
(8, 3, 2, 10.00, 20.00, 12445.00),
(9, 3, 3, 20.00, 30.00, 15745.00),
(10, 3, 4, 30.00, NULL, 17245.00);

-- --------------------------------------------------------

--
-- Table structure for table `recommendations`
--

CREATE TABLE `recommendations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `alert_id` bigint(20) UNSIGNED NOT NULL,
  `recommendation_rule_id` int(10) UNSIGNED NOT NULL,
  `recommendation_text` varchar(500) NOT NULL,
  `is_followed` tinyint(1) DEFAULT NULL COMMENT '[DX] đo "recommendation correctness"',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recommendation_rules`
--

CREATE TABLE `recommendation_rules` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `condition_type` enum('cl_zero_n_ky','vuot_nguong_lien_tiep','khong_dat_muc_tieu') NOT NULL COMMENT '[TL] FR-10 ví dụ CL=0 2 kỳ liên tiếp',
  `n_periods` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Số kỳ N (condition_params.n_periods cũ)',
  `utility_type` enum('dien','nuoc') DEFAULT NULL COMMENT 'NULL = mọi tiện ích (condition_params.utility_type cũ)',
  `recommendation_text` varchar(500) NOT NULL,
  `status` enum('bat','tat') NOT NULL DEFAULT 'bat',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `recommendation_rules`
--

INSERT INTO `recommendation_rules` (`id`, `name`, `condition_type`, `n_periods`, `utility_type`, `recommendation_text`, `status`, `created_by`, `created_at`) VALUES
(1, 'CL=0 trong 2 kỳ liên tiếp', 'cl_zero_n_ky', 2, NULL, 'Kiểm tra công tơ/căn hộ trống', 'bat', 1, '2026-10-04 11:40:19'),
(2, 'Tiêu thụ tăng bất thường', 'vuot_nguong_lien_tiep', 1, NULL, 'Đề xuất kiểm tra công tơ', 'bat', 1, '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `residents`
--

CREATE TABLE `residents` (
  `id` int(10) UNSIGNED NOT NULL,
  `apartment_id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `resident_role` enum('chu_ho','khach_thue') NOT NULL DEFAULT 'chu_ho',
  `phone` varchar(20) DEFAULT NULL COMMENT '[TL] NFR-06 dữ liệu cá nhân',
  `email` varchar(255) DEFAULT NULL COMMENT '[A-EMAIL-01] FN-10.03 - dữ liệu cá nhân BR-36 (patch 004)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '[ASSUMPTION] Đang hiệu lực/Hết hiệu lực',
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `residents`
--

INSERT INTO `residents` (`id`, `apartment_id`, `full_name`, `resident_role`, `phone`, `email`, `is_active`, `effective_from`, `effective_to`, `created_at`, `updated_at`) VALUES
(1, 1, 'Phạm Văn A', 'chu_ho', NULL, NULL, 1, '2023-01-01', NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(2, 2, 'Nguyễn Thị B', 'chu_ho', NULL, NULL, 1, '2023-01-01', NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(3, 3, 'Trần Văn C', 'khach_thue', NULL, NULL, 1, '2024-01-01', NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(4, 4, 'Lê Thị D', 'chu_ho', NULL, NULL, 1, '2023-06-01', NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `system_configs`
--

CREATE TABLE `system_configs` (
  `id` int(10) UNSIGNED NOT NULL,
  `config_key` varchar(100) NOT NULL,
  `config_value` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_configs`
--

INSERT INTO `system_configs` (`id`, `config_key`, `config_value`, `description`, `updated_by`, `updated_at`) VALUES
(1, 'session_timeout_minutes', '30', '[TL] NFR-01 - tự đăng xuất sau 30 phút không hoạt động', 1, '2026-10-04 11:40:19'),
(2, 'max_failed_login_attempts', '5', '[TL] BR-02 - khoá tạm sau 5 lần sai', 1, '2026-10-04 11:40:19'),
(3, 'account_lock_duration_minutes', '30', '[ASSUMPTION - J-03 chưa được tài liệu quy định cụ thể]', 1, '2026-10-04 11:40:19'),
(4, 'bank_account_info', 'Chưa cấu hình - CHƯA ĐƯỢC ĐẶC TẢ TRONG TÀI LIỆU', 'Thông tin tài khoản in trên phiếu thu (E1)', 1, '2026-10-04 11:40:19'),
(5, 'import_strict_mode', '0', '[ASSUMPTION A-06 - J-12] 0 = cho phép lưu partial (dòng hợp lệ), 1 = all-or-nothing', 1, '2026-10-04 11:40:19'),
(6, 'j16_low_max_kwh', '10', '[ASSUMPTION J-16-P] Chỉ gợi ý ưu tiên: CL điện < giá trị này -> gợi ý Thấp. BQL quyết định ưu tiên cuối.', NULL, '2026-10-04 11:40:19'),
(7, 'j16_high_min_kwh', '100', '[ASSUMPTION J-16-P] Chỉ gợi ý ưu tiên: CL điện >= giá trị này -> gợi ý Cao. BQL quyết định ưu tiên cuối.', NULL, '2026-10-04 11:40:19'),
(8, 'j16_low_max_m3', '1', '[ASSUMPTION J-16-P] Chỉ gợi ý ưu tiên: CL nước < giá trị này -> gợi ý Thấp. BQL quyết định ưu tiên cuối.', NULL, '2026-10-04 11:40:19'),
(9, 'j16_high_min_m3', '10', '[ASSUMPTION J-16-P] Chỉ gợi ý ưu tiên: CL nước >= giá trị này -> gợi ý Cao. BQL quyết định ưu tiên cuối.', NULL, '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL COMMENT '[ASSUMPTION] định danh đăng nhập - tài liệu chưa nói username hay email (J-03 liên quan)',
  `password_hash` varchar(255) NOT NULL COMMENT 'password_hash() bcrypt - [TL] NFR-01 >=8 ký tự, chữ hoa + số (kiểm tra ở Validator, không ở DB)',
  `full_name` varchar(150) NOT NULL,
  `role` enum('bql','ky_thuat','ke_toan') NOT NULL COMMENT '[TL] FR-01: 3 vai trò (J-01 đã chốt, patch 003)',
  `status` enum('hoat_dong','ngung','khoa_tam') NOT NULL DEFAULT 'hoat_dong' COMMENT '[TL] 2.3 Deactivate + FR-01 khoá tạm sau 5 lần sai (BR-02)',
  `failed_login_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '[TL] BR-02',
  `locked_until` datetime DEFAULT NULL COMMENT '[ASSUMPTION - J-03] thời gian khoá tạm chưa được tài liệu quy định cụ thể',
  `last_login_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `status`, `failed_login_count`, `locked_until`, `last_login_at`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'bql01', '$2y$12$PZJ9SiqstToYRUqfKynZ8e.xcWAcz8gFAgVbeGfxaoAfUOc7sgalC', 'Nguyễn Văn Quản', 'bql', 'hoat_dong', 0, NULL, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(2, 'kythuat01', '$2y$12$PZJ9SiqstToYRUqfKynZ8e.xcWAcz8gFAgVbeGfxaoAfUOc7sgalC', 'Trần Văn Kỹ', 'ky_thuat', 'hoat_dong', 0, NULL, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(3, 'ketoan01', '$2y$12$PZJ9SiqstToYRUqfKynZ8e.xcWAcz8gFAgVbeGfxaoAfUOc7sgalC', 'Lê Thị Kế', 'ke_toan', 'hoat_dong', 0, NULL, NULL, NULL, '2026-10-04 11:40:19', '2026-10-04 11:40:19');

-- --------------------------------------------------------

--
-- Table structure for table `zones`
--

CREATE TABLE `zones` (
  `id` int(10) UNSIGNED NOT NULL,
  `building_id` int(10) UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `zone_type` enum('lien_ke','shophouse','biet_thu','chung_cu') DEFAULT NULL COMMENT '[SL] 2.1',
  `status` enum('hoat_dong','ngung') NOT NULL DEFAULT 'hoat_dong',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `zones`
--

INSERT INTO `zones` (`id`, `building_id`, `code`, `name`, `zone_type`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'KHU-BT', 'Khu Biệt thự', 'biet_thu', 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(2, 1, 'KHU-SH', 'Khu Shophouse', 'shophouse', 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19'),
(3, 1, 'KHU-CC', 'Khu Chung cư', 'chung_cu', 'hoat_dong', '2026-10-04 11:40:19', '2026-10-04 11:40:19');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alerts`
--
ALTER TABLE `alerts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_alert_dedupe` (`alert_rule_id`,`apartment_id`,`dedupe_meter_id`,`billing_period_id`) COMMENT '[DX] BR-40 không sinh trùng cảnh báo rule/căn hộ/công tơ/kỳ; alert_rule_id NULL (thủ công) không bị chặn',
  ADD UNIQUE KEY `uq_alert_system_dedupe` (`alert_type`,`apartment_id`,`billing_period_id`,`system_dedupe_key`) COMMENT '[BA Proposal J-16] 1 cảnh báo hệ thống / (loại, căn, kỳ); phát hiện lại -> cập nhật snapshot / mở lại, không tạo mới',
  ADD KEY `fk_alert_meter` (`meter_id`),
  ADD KEY `fk_alert_apartment` (`apartment_id`),
  ADD KEY `fk_alert_creator` (`created_by`),
  ADD KEY `fk_alert_reviewer` (`reviewed_by`),
  ADD KEY `fk_alert_closer` (`closed_by`),
  ADD KEY `idx_alert_status` (`status`),
  ADD KEY `idx_alert_priority` (`priority`),
  ADD KEY `idx_alert_period` (`billing_period_id`),
  ADD KEY `fk_alert_concluder` (`concluded_by`),
  ADD KEY `idx_alert_type_status` (`alert_type`,`status`);

--
-- Indexes for table `alert_events`
--
ALTER TABLE `alert_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_event_user` (`created_by`),
  ADD KEY `idx_event_alert_time` (`alert_id`,`created_at`),
  ADD KEY `idx_event_contact` (`alert_id`,`event_type`,`contact_time`);

--
-- Indexes for table `alert_rules`
--
ALTER TABLE `alert_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_alertrule_creator` (`created_by`),
  ADD KEY `idx_alertrule_status` (`status`);

--
-- Indexes for table `apartments`
--
ALTER TABLE `apartments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_apartments_building_code` (`building_id`,`code`),
  ADD KEY `fk_apartment_zone` (`zone_id`),
  ADD KEY `idx_apartments_status` (`status`);

--
-- Indexes for table `apartment_charges`
--
ALTER TABLE `apartment_charges`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_charge_apartment_period` (`apartment_id`,`billing_period_id`),
  ADD KEY `fk_charge_confirmer` (`confirmed_by`),
  ADD KEY `fk_charge_approver` (`approved_by`),
  ADD KEY `idx_charge_period` (`billing_period_id`),
  ADD KEY `idx_charge_status` (`status`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `baselines`
--
ALTER TABLE `baselines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_baseline_creator` (`created_by`),
  ADD KEY `idx_baseline_scope` (`scope_type`,`scope_id`,`utility_type`,`status`);

--
-- Indexes for table `billing_periods`
--
ALTER TABLE `billing_periods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_period_code` (`period_code`),
  ADD KEY `fk_period_creator` (`created_by`),
  ADD KEY `fk_period_locker` (`locked_by`),
  ADD KEY `fk_period_reopener` (`reopened_by`),
  ADD KEY `idx_period_status` (`status`);

--
-- Indexes for table `buildings`
--
ALTER TABLE `buildings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_buildings_code` (`code`),
  ADD KEY `fk_building_creator` (`created_by`);

--
-- Indexes for table `charge_tier_details`
--
ALTER TABLE `charge_tier_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_tierdetail_charge` (`apartment_charge_id`),
  ADD KEY `idx_tierdetail_reading` (`meter_reading_id`);

--
-- Indexes for table `consumption_targets`
--
ALTER TABLE `consumption_targets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_target_scope_period` (`scope_type`,`scope_id`,`utility_type`,`billing_period_id`) COMMENT '[DX] BR-25 một mục tiêu / (phạm vi, tiện ích, kỳ)',
  ADD KEY `fk_target_period` (`billing_period_id`),
  ADD KEY `fk_target_creator` (`created_by`);

--
-- Indexes for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email_dedupe` (`apartment_charge_id`,`recipient_email`,`notification_type`,`content_hash`),
  ADD UNIQUE KEY `uq_email_alert_dedupe` (`alert_id`,`recipient_email`,`notification_type`,`content_hash`),
  ADD KEY `fk_email_resident` (`resident_id`),
  ADD KEY `fk_email_period` (`billing_period_id`),
  ADD KEY `fk_email_creator` (`created_by`),
  ADD KEY `idx_email_status` (`status`,`created_at`),
  ADD KEY `idx_email_apartment` (`apartment_id`);

--
-- Indexes for table `import_batches`
--
ALTER TABLE `import_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_import_period` (`billing_period_id`),
  ADD KEY `fk_import_uploader` (`uploaded_by`);

--
-- Indexes for table `import_errors`
--
ALTER TABLE `import_errors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_importerr_batch` (`import_batch_id`);

--
-- Indexes for table `inspection_cases`
--
ALTER TABLE `inspection_cases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_case_alert` (`alert_id`),
  ADD KEY `fk_case_creator` (`created_by`),
  ADD KEY `idx_case_assignee` (`assigned_to`),
  ADD KEY `idx_case_status` (`status`);

--
-- Indexes for table `meters`
--
ALTER TABLE `meters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_meters_code` (`meter_code`),
  ADD KEY `idx_meters_apartment` (`apartment_id`),
  ADD KEY `idx_meters_status` (`status`);

--
-- Indexes for table `meter_readings`
--
ALTER TABLE `meter_readings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_reading_meter_period` (`meter_id`,`billing_period_id`) COMMENT '[TL] BR-08',
  ADD KEY `fk_reading_old_meter` (`old_meter_id`),
  ADD KEY `fk_reading_enterer` (`entered_by`),
  ADD KEY `fk_reading_confirmer` (`confirmed_by`),
  ADD KEY `idx_reading_period` (`billing_period_id`),
  ADD KEY `idx_reading_status` (`status`),
  ADD KEY `idx_reading_import_batch` (`import_batch_id`);

--
-- Indexes for table `parking_vehicles`
--
ALTER TABLE `parking_vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vehicle_apartment_type` (`apartment_id`,`vehicle_type`);

--
-- Indexes for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payment_transaction` (`transaction_id`),
  ADD KEY `fk_payment_reviewer` (`reviewed_by`),
  ADD KEY `idx_payment_charge` (`apartment_charge_id`,`match_status`),
  ADD KEY `idx_payment_status` (`match_status`,`received_at`);

--
-- Indexes for table `pricing_plans`
--
ALTER TABLE `pricing_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pricing_base` (`base_plan_id`),
  ADD KEY `fk_pricing_creator` (`created_by`),
  ADD KEY `fk_pricing_reviewer` (`reviewed_by`),
  ADD KEY `idx_pricing_utility_lookup` (`price_type`,`utility_type`,`usage_type`,`status`,`effective_date`),
  ADD KEY `idx_pricing_fee_lookup` (`price_type`,`fee_type`,`status`,`effective_date`),
  ADD KEY `idx_pricing_workflow` (`origin`,`approval_status`);

--
-- Indexes for table `pricing_tiers`
--
ALTER TABLE `pricing_tiers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tier_order` (`pricing_plan_id`,`tier_order`);

--
-- Indexes for table `recommendations`
--
ALTER TABLE `recommendations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rec_alert_rule` (`alert_id`,`recommendation_rule_id`) COMMENT '[STEP 12 D-1] 1 khuyến nghị / (cảnh báo, quy tắc); Service vẫn khoá dòng cảnh báo trước khi ghi',
  ADD KEY `fk_rec_rule` (`recommendation_rule_id`);

--
-- Indexes for table `recommendation_rules`
--
ALTER TABLE `recommendation_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_recrule_creator` (`created_by`),
  ADD KEY `idx_recrule_status` (`status`,`condition_type`);

--
-- Indexes for table `residents`
--
ALTER TABLE `residents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_residents_apartment` (`apartment_id`);

--
-- Indexes for table `system_configs`
--
ALTER TABLE `system_configs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_system_configs_key` (`config_key`),
  ADD KEY `fk_sysconfig_user` (`updated_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_status` (`status`);

--
-- Indexes for table `zones`
--
ALTER TABLE `zones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_zones_building_code` (`building_id`,`code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alerts`
--
ALTER TABLE `alerts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `alert_events`
--
ALTER TABLE `alert_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `alert_rules`
--
ALTER TABLE `alert_rules`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `apartments`
--
ALTER TABLE `apartments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `apartment_charges`
--
ALTER TABLE `apartment_charges`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `baselines`
--
ALTER TABLE `baselines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `billing_periods`
--
ALTER TABLE `billing_periods`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `buildings`
--
ALTER TABLE `buildings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `charge_tier_details`
--
ALTER TABLE `charge_tier_details`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `consumption_targets`
--
ALTER TABLE `consumption_targets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `email_notifications`
--
ALTER TABLE `email_notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `import_batches`
--
ALTER TABLE `import_batches`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `import_errors`
--
ALTER TABLE `import_errors`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inspection_cases`
--
ALTER TABLE `inspection_cases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `meters`
--
ALTER TABLE `meters`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `meter_readings`
--
ALTER TABLE `meter_readings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `parking_vehicles`
--
ALTER TABLE `parking_vehicles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pricing_plans`
--
ALTER TABLE `pricing_plans`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pricing_tiers`
--
ALTER TABLE `pricing_tiers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recommendations`
--
ALTER TABLE `recommendations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recommendation_rules`
--
ALTER TABLE `recommendation_rules`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `residents`
--
ALTER TABLE `residents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `system_configs`
--
ALTER TABLE `system_configs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `zones`
--
ALTER TABLE `zones`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alerts`
--
ALTER TABLE `alerts`
  ADD CONSTRAINT `fk_alert_apartment` FOREIGN KEY (`apartment_id`) REFERENCES `apartments` (`id`),
  ADD CONSTRAINT `fk_alert_closer` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_alert_concluder` FOREIGN KEY (`concluded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_alert_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_alert_meter` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`),
  ADD CONSTRAINT `fk_alert_period` FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods` (`id`),
  ADD CONSTRAINT `fk_alert_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_alert_rule` FOREIGN KEY (`alert_rule_id`) REFERENCES `alert_rules` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `alert_events`
--
ALTER TABLE `alert_events`
  ADD CONSTRAINT `fk_event_alert` FOREIGN KEY (`alert_id`) REFERENCES `alerts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_event_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `alert_rules`
--
ALTER TABLE `alert_rules`
  ADD CONSTRAINT `fk_alertrule_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `apartments`
--
ALTER TABLE `apartments`
  ADD CONSTRAINT `fk_apartment_building` FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`),
  ADD CONSTRAINT `fk_apartment_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `apartment_charges`
--
ALTER TABLE `apartment_charges`
  ADD CONSTRAINT `fk_charge_apartment` FOREIGN KEY (`apartment_id`) REFERENCES `apartments` (`id`),
  ADD CONSTRAINT `fk_charge_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_charge_confirmer` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_charge_period` FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods` (`id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `baselines`
--
ALTER TABLE `baselines`
  ADD CONSTRAINT `fk_baseline_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `billing_periods`
--
ALTER TABLE `billing_periods`
  ADD CONSTRAINT `fk_period_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_period_locker` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_period_reopener` FOREIGN KEY (`reopened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `buildings`
--
ALTER TABLE `buildings`
  ADD CONSTRAINT `fk_building_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `charge_tier_details`
--
ALTER TABLE `charge_tier_details`
  ADD CONSTRAINT `fk_tierdetail_charge` FOREIGN KEY (`apartment_charge_id`) REFERENCES `apartment_charges` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tierdetail_reading` FOREIGN KEY (`meter_reading_id`) REFERENCES `meter_readings` (`id`);

--
-- Constraints for table `consumption_targets`
--
ALTER TABLE `consumption_targets`
  ADD CONSTRAINT `fk_target_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_target_period` FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods` (`id`);

--
-- Constraints for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD CONSTRAINT `fk_email_alert` FOREIGN KEY (`alert_id`) REFERENCES `alerts` (`id`),
  ADD CONSTRAINT `fk_email_apartment` FOREIGN KEY (`apartment_id`) REFERENCES `apartments` (`id`),
  ADD CONSTRAINT `fk_email_charge` FOREIGN KEY (`apartment_charge_id`) REFERENCES `apartment_charges` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_email_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_email_period` FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods` (`id`),
  ADD CONSTRAINT `fk_email_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `import_batches`
--
ALTER TABLE `import_batches`
  ADD CONSTRAINT `fk_import_period` FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods` (`id`),
  ADD CONSTRAINT `fk_import_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `import_errors`
--
ALTER TABLE `import_errors`
  ADD CONSTRAINT `fk_importerr_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inspection_cases`
--
ALTER TABLE `inspection_cases`
  ADD CONSTRAINT `fk_case_alert` FOREIGN KEY (`alert_id`) REFERENCES `alerts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_case_assignee` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_case_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `meters`
--
ALTER TABLE `meters`
  ADD CONSTRAINT `fk_meter_apartment` FOREIGN KEY (`apartment_id`) REFERENCES `apartments` (`id`);

--
-- Constraints for table `meter_readings`
--
ALTER TABLE `meter_readings`
  ADD CONSTRAINT `fk_reading_confirmer` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_reading_enterer` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_reading_import_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`),
  ADD CONSTRAINT `fk_reading_meter` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`),
  ADD CONSTRAINT `fk_reading_old_meter` FOREIGN KEY (`old_meter_id`) REFERENCES `meters` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_reading_period` FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods` (`id`);

--
-- Constraints for table `parking_vehicles`
--
ALTER TABLE `parking_vehicles`
  ADD CONSTRAINT `fk_vehicle_apartment` FOREIGN KEY (`apartment_id`) REFERENCES `apartments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD CONSTRAINT `fk_payment_charge` FOREIGN KEY (`apartment_charge_id`) REFERENCES `apartment_charges` (`id`),
  ADD CONSTRAINT `fk_payment_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `pricing_plans`
--
ALTER TABLE `pricing_plans`
  ADD CONSTRAINT `fk_pricing_base` FOREIGN KEY (`base_plan_id`) REFERENCES `pricing_plans` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pricing_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pricing_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `pricing_tiers`
--
ALTER TABLE `pricing_tiers`
  ADD CONSTRAINT `fk_tier_plan` FOREIGN KEY (`pricing_plan_id`) REFERENCES `pricing_plans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `recommendations`
--
ALTER TABLE `recommendations`
  ADD CONSTRAINT `fk_rec_alert` FOREIGN KEY (`alert_id`) REFERENCES `alerts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rec_rule` FOREIGN KEY (`recommendation_rule_id`) REFERENCES `recommendation_rules` (`id`);

--
-- Constraints for table `recommendation_rules`
--
ALTER TABLE `recommendation_rules`
  ADD CONSTRAINT `fk_recrule_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `residents`
--
ALTER TABLE `residents`
  ADD CONSTRAINT `fk_resident_apartment` FOREIGN KEY (`apartment_id`) REFERENCES `apartments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `system_configs`
--
ALTER TABLE `system_configs`
  ADD CONSTRAINT `fk_sysconfig_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `zones`
-
  
ALTER TABLE `zones`
  ADD CONSTRAINT `fk_zone_building` FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
