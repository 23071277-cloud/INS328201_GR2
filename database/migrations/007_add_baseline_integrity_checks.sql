-- ============================================================================
-- Migration 007: Baseline integrity checks (FR-06)
-- A moving-average baseline requires at least three periods. A manual baseline
-- requires a non-negative manual value; cached computed values are non-negative.
-- ============================================================================

SET NAMES utf8mb4;

SET @db = DATABASE();
SET @tbl = 'baselines';

SET @constraint_name = 'chk_baseline_average_periods';
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = @tbl
       AND CONSTRAINT_NAME = @constraint_name) = 0,
    'ALTER TABLE `baselines` ADD CONSTRAINT `chk_baseline_average_periods` CHECK ((`method` = ''trung_binh_n_ky'' AND `n_periods` >= 3) OR (`method` <> ''trung_binh_n_ky'' AND `n_periods` IS NULL))',
    'SELECT ''Constraint chk_baseline_average_periods already exists — skipped.'' AS migration_status'
);
PREPARE _mig FROM @sql;
EXECUTE _mig;
DEALLOCATE PREPARE _mig;

SET @constraint_name = 'chk_baseline_values';
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = @tbl
       AND CONSTRAINT_NAME = @constraint_name) = 0,
    'ALTER TABLE `baselines` ADD CONSTRAINT `chk_baseline_values` CHECK ((`method` <> ''nhap_tay'' OR `manual_value` IS NOT NULL) AND (`manual_value` IS NULL OR `manual_value` >= 0) AND (`computed_value` IS NULL OR `computed_value` >= 0))',
    'SELECT ''Constraint chk_baseline_values already exists — skipped.'' AS migration_status'
);
PREPARE _mig FROM @sql;
EXECUTE _mig;
DEALLOCATE PREPARE _mig;
