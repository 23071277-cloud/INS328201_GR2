-- ============================================================================
-- Migration 001: Dual-condition threshold support (Topic 09 Phase 2)
-- Adds min_absolute_diff to alert_rules to support:
--   percentage_change > threshold_value  AND  absolute_difference > min_absolute_diff
--
-- Backward-compatible: existing rows have min_absolute_diff = NULL, which means
-- the single-condition behaviour (percentage / absolute value only) is preserved.
--
-- Apply on any Energy DSS v2 database (safe to run multiple times via IF NOT EXISTS check):
--   mysql -u root energy_dss_v2 < database/migrations/001_add_min_absolute_diff_to_alert_rules.sql
-- ============================================================================

SET NAMES utf8mb4;

-- Add the column only if it does not already exist (idempotent)
SET @db   = DATABASE();
SET @tbl  = 'alert_rules';
SET @col  = 'min_absolute_diff';

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @tbl AND COLUMN_NAME = @col) = 0,
    CONCAT('ALTER TABLE `', @tbl, '`
      ADD COLUMN `', @col, '`  DECIMAL(12,4) NULL DEFAULT NULL
      COMMENT ''[Topic09 Phase2] Ngưỡng hiệu số tuyệt đối tối thiểu; NULL = chỉ dùng ngưỡng % / tuyệt đối đơn lẻ (tương thích ngược). Điều kiện kép: threshold_value VÀ min_absolute_diff phải cùng vượt ngưỡng.''
      AFTER `threshold_value`'),
    'SELECT ''Column min_absolute_diff already exists — skipped.'' AS migration_status'
);

PREPARE _mig FROM @sql;
EXECUTE _mig;
DEALLOCATE PREPARE _mig;
