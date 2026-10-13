-- ============================================================================
-- Migration 008: Consumption-target integrity check (FR-07)
-- A target may be zero (for example, an unoccupied scope) but cannot be
-- negative. The existing unique key prevents duplicate targets per scope,
-- utility type, and billing period.
-- ============================================================================

SET NAMES utf8mb4;

SET @db = DATABASE();
SET @tbl = 'consumption_targets';
SET @constraint_name = 'chk_target_value_nonnegative';

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = @tbl
       AND CONSTRAINT_NAME = @constraint_name) = 0,
    'ALTER TABLE `consumption_targets` ADD CONSTRAINT `chk_target_value_nonnegative` CHECK (`target_value` >= 0)',
    'SELECT ''Constraint chk_target_value_nonnegative already exists — skipped.'' AS migration_status'
);
PREPARE _mig FROM @sql;
EXECUTE _mig;
DEALLOCATE PREPARE _mig;
