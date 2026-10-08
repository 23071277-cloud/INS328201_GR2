-- ============================================================================
-- Migration 002: Consumption Forecasting extension (Topic 09 optional)
-- Creates consumption_forecasts table for storing moving-average forecasts.
-- ISOLATION GUARANTEE: this table is read/write only by ConsumptionForecastService.
-- No FK to meter_readings, alerts, billing, tariff, or payment tables.
-- Safe to apply multiple times (idempotent via CREATE TABLE IF NOT EXISTS).
--
-- Apply on energy_dss_v2:
--   mysql -u root energy_dss_v2 < database/migrations/002_create_consumption_forecasts.sql
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `consumption_forecasts` (
  `id`                INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
  `scope_type`        ENUM('meter','apartment','zone','building') NOT NULL
                      COMMENT 'Phạm vi dự báo — khớp với scope_type của ScopeConsumptionService',
  `scope_id`          INT UNSIGNED      NOT NULL
                      COMMENT 'Id thực thể theo scope_type; không FK (đa hình như baselines)',
  `utility_type`      ENUM('dien','nuoc') NOT NULL,
  `forecast_period_id` INT UNSIGNED     NOT NULL
                      COMMENT 'Kỳ được dự báo (kỳ TIẾP THEO sau N kỳ lịch sử)',
  `method`            VARCHAR(30)       NOT NULL DEFAULT 'moving_average'
                      COMMENT 'Phương pháp: moving_average (duy nhất hiện tại)',
  `n_periods`         TINYINT UNSIGNED  NOT NULL
                      COMMENT 'Số kỳ lịch sử đã dùng (N trong công thức trung bình động)',
  `source_period_ids` JSON              NOT NULL
                      COMMENT 'Mảng JSON id các kỳ đã dùng tính trung bình, mới nhất trước',
  `forecast_value`    DECIMAL(12,2)     NOT NULL
                      COMMENT 'Giá trị dự báo (kWh hoặc m³, KHÔNG phải xu); làm tròn half-up',
  `generated_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `generated_by`      INT UNSIGNED      NULL
                      COMMENT 'BQL tạo dự báo; NULL = hệ thống tự sinh (nếu có)',

  -- Chống trùng: 1 dự báo duy nhất cho (scope, utility, forecast_period, method, N)
  UNIQUE KEY `uq_forecast` (scope_type, scope_id, utility_type, forecast_period_id, method, n_periods),

  CONSTRAINT `fk_forecast_period` FOREIGN KEY (forecast_period_id)
      REFERENCES billing_periods(id) ON DELETE CASCADE,
  CONSTRAINT `fk_forecast_creator` FOREIGN KEY (generated_by)
      REFERENCES users(id) ON DELETE SET NULL,

  INDEX `idx_forecast_scope` (scope_type, scope_id, utility_type),
  INDEX `idx_forecast_period` (forecast_period_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COMMENT='Topic 09 extension: dự báo tiêu thụ kỳ tới (moving average). Chỉ đọc/ghi bởi ConsumptionForecastService — KHÔNG liên quan đến meter_readings, billing, alerts.';
