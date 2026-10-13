-- ============================================================================
-- Migration 004: Active-meter rule for meter readings (FR-04 / BR-06)
-- Blocks inserts and updates of meter_readings when the referenced meter is
-- inactive. Existing historical readings are preserved.
-- ============================================================================

SET NAMES utf8mb4;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_meter_readings_active_meter_insert$$
CREATE TRIGGER trg_meter_readings_active_meter_insert
BEFORE INSERT ON meter_readings
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM meters WHERE id = NEW.meter_id AND status = 'hoat_dong'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'FR-04: meter reading requires an active meter';
  END IF;
END$$

DROP TRIGGER IF EXISTS trg_meter_readings_active_meter_update$$
CREATE TRIGGER trg_meter_readings_active_meter_update
BEFORE UPDATE ON meter_readings
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM meters WHERE id = NEW.meter_id AND status = 'hoat_dong'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'FR-04: meter reading requires an active meter';
  END IF;
END$$

DELIMITER ;
