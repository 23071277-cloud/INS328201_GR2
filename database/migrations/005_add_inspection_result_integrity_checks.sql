-- ============================================================================
-- Migration 005: Inspection-result integrity checks (FR-09)
-- A completed or closed inspection requires a recorded conclusion. Any
-- rechecked meter value, when recorded, must be non-negative.
-- ============================================================================

SET NAMES utf8mb4;

SET @db = DATABASE();
SET @tbl = 'inspection_cases';

SET @constraint_name = 'chk_case_result_required';
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db
       AND TABLE_NAME = @tbl
       AND CONSTRAINT_NAME = @constraint_name) = 0,
    'ALTER TABLE `inspection_cases` ADD CONSTRAINT `chk_case_result_required` CHECK (`status` = ''da_giao'' OR `result_conclusion` IS NOT NULL)',
    'SELECT ''Constraint chk_case_result_required already exists — skipped.'' AS migration_status'
);
PREPARE _mig FROM @sql;
EXECUTE _mig;
DEALLOCATE PREPARE _mig;

SET @constraint_name = 'chk_case_rechecked_value';
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db
       AND TABLE_NAME = @tbl
       AND CONSTRAINT_NAME = @constraint_name) = 0,
    'ALTER TABLE `inspection_cases` ADD CONSTRAINT `chk_case_rechecked_value` CHECK (`rechecked_value` IS NULL OR `rechecked_value` >= 0)',
    'SELECT ''Constraint chk_case_rechecked_value already exists — skipped.'' AS migration_status'
);
PREPARE _mig FROM @sql;
EXECUTE _mig;
DEALLOCATE PREPARE _mig;
