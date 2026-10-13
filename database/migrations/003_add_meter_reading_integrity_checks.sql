-- ============================================================================
-- Migration 003: Meter-reading integrity checks (FR-04)
-- Enforces two rules for data entered or imported into meter_readings:
--   1. current_reading >= previous_reading
--   2. consumption = current_reading - previous_reading
--
-- This migration is idempotent. It preserves existing valid data and fails
-- clearly if historical rows violate either rule, so they can be reviewed
-- before the constraints are added.
-- ============================================================================

SET NAMES utf8mb4;

SET @db = DATABASE();
SET @tbl = 'meter_readings';

SET @constraint_name = 'chk_reading_order';
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db
       AND TABLE_NAME = @tbl
       AND CONSTRAINT_NAME = @constraint_name) = 0,
    'ALTER TABLE `meter_readings` ADD CONSTRAINT `chk_reading_order` CHECK (`current_reading` >= `previous_reading`)',
    'SELECT ''Constraint chk_reading_order already exists — skipped.'' AS migration_status'
);
PREPARE _mig FROM @sql;
EXECUTE _mig;
DEALLOCATE PREPARE _mig;

SET @constraint_name = 'chk_reading_consumption';
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db
       AND TABLE_NAME = @tbl
       AND CONSTRAINT_NAME = @constraint_name) = 0,
    'ALTER TABLE `meter_readings` ADD CONSTRAINT `chk_reading_consumption` CHECK (`consumption` = `current_reading` - `previous_reading`)',
    'SELECT ''Constraint chk_reading_consumption already exists — skipped.'' AS migration_status'
);
PREPARE _mig FROM @sql;
EXECUTE _mig;
DEALLOCATE PREPARE _mig;
