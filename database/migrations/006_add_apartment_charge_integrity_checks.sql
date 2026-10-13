-- ============================================================================
-- Migration 006: Apartment-charge integrity checks (FR-05)
-- Ensures non-negative usage/cost values and preserves the calculation trail:
-- total_arising = all current-period components
-- total_due     = total_arising + old_debt_amount
-- ============================================================================

SET NAMES utf8mb4;

SET @db = DATABASE();
SET @tbl = 'apartment_charges';

SET @constraint_name = 'chk_charge_values_nonnegative';
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = @tbl
       AND CONSTRAINT_NAME = @constraint_name) = 0,
    'ALTER TABLE `apartment_charges` ADD CONSTRAINT `chk_charge_values_nonnegative` CHECK (`electricity_consumption` >= 0 AND `electricity_amount` >= 0 AND `electricity_vat_amount` >= 0 AND `water_consumption` >= 0 AND `water_amount` >= 0 AND `service_fee_amount` >= 0 AND `fire_insurance_amount` >= 0 AND `parking_fee_amount` >= 0 AND `total_arising` >= 0 AND `old_debt_amount` >= 0 AND `total_due` >= 0)',
    'SELECT ''Constraint chk_charge_values_nonnegative already exists — skipped.'' AS migration_status'
);
PREPARE _mig FROM @sql;
EXECUTE _mig;
DEALLOCATE PREPARE _mig;

SET @constraint_name = 'chk_charge_totals';
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = @tbl
       AND CONSTRAINT_NAME = @constraint_name) = 0,
    'ALTER TABLE `apartment_charges` ADD CONSTRAINT `chk_charge_totals` CHECK (`total_arising` = `electricity_amount` + `electricity_vat_amount` + `water_amount` + `service_fee_amount` + `fire_insurance_amount` + `parking_fee_amount` AND `total_due` = `total_arising` + `old_debt_amount`)',
    'SELECT ''Constraint chk_charge_totals already exists — skipped.'' AS migration_status'
);
PREPARE _mig FROM @sql;
EXECUTE _mig;
DEALLOCATE PREPARE _mig;
