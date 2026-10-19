-- EXPLICIT MANUAL MIGRATION ONLY. No USE, data backfill, or startup hook.
-- Back up energy_dss before separately authorized application.
CREATE TABLE billing_adjustment_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 charge_id BIGINT UNSIGNED NOT NULL,
 initiated_by INT UNSIGNED NOT NULL,
 status ENUM('submitted','approved','rejected','withdrawn','stale_input') NOT NULL DEFAULT 'submitted',
 effective_date DATE NOT NULL,
 explanation VARCHAR(1000) NOT NULL,
 evidence VARCHAR(2000) NOT NULL,
 amount DECIMAL(14,2) NOT NULL,
 input_hash CHAR(64) NOT NULL,
 snapshot LONGTEXT NOT NULL,
 idempotency_key CHAR(64) NOT NULL UNIQUE,
 decided_by INT UNSIGNED NULL,
 decision_reason VARCHAR(1000) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 decided_at DATETIME NULL,
 CONSTRAINT fk_bar_charge FOREIGN KEY (charge_id) REFERENCES apartment_charges(id),
 CONSTRAINT fk_bar_initiator FOREIGN KEY (initiated_by) REFERENCES users(id),
 CONSTRAINT fk_bar_decider FOREIGN KEY (decided_by) REFERENCES users(id),
 CONSTRAINT chk_bar_amount CHECK (amount >= 0),
 CONSTRAINT chk_bar_separation CHECK (status <> 'approved' OR (decided_by IS NOT NULL AND decided_by <> initiated_by)),
 KEY idx_bar_charge (charge_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE billing_reconciliations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL UNIQUE,
 charge_id BIGINT UNSIGNED NOT NULL UNIQUE,
 approved_by INT UNSIGNED NOT NULL,
 input_hash CHAR(64) NOT NULL,
 snapshot LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_br_request FOREIGN KEY (request_id) REFERENCES billing_adjustment_requests(id),
 CONSTRAINT fk_br_charge FOREIGN KEY (charge_id) REFERENCES apartment_charges(id),
 CONSTRAINT fk_br_approver FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE supplementary_debits (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 reconciliation_id BIGINT UNSIGNED NOT NULL UNIQUE,
 charge_id BIGINT UNSIGNED NOT NULL UNIQUE,
 amount DECIMAL(14,2) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_sd_reconciliation FOREIGN KEY (reconciliation_id) REFERENCES billing_reconciliations(id),
 CONSTRAINT fk_sd_charge FOREIGN KEY (charge_id) REFERENCES apartment_charges(id),
 CONSTRAINT chk_sd_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Immutable financial documents; requests may transition, but submitted content may not change.
DELIMITER $$
CREATE TRIGGER br_no_update BEFORE UPDATE ON billing_reconciliations FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable reconciliation'; END$$
CREATE TRIGGER br_no_delete BEFORE DELETE ON billing_reconciliations FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable reconciliation'; END$$
CREATE TRIGGER sd_no_update BEFORE UPDATE ON supplementary_debits FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable supplementary debit'; END$$
CREATE TRIGGER sd_no_delete BEFORE DELETE ON supplementary_debits FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable supplementary debit'; END$$
CREATE TRIGGER bar_no_delete BEFORE DELETE ON billing_adjustment_requests FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Keep adjustment history'; END$$
CREATE TRIGGER bar_transition BEFORE UPDATE ON billing_adjustment_requests FOR EACH ROW
BEGIN
 IF OLD.status <> 'submitted' OR NEW.status NOT IN ('approved','rejected','withdrawn','stale_input')
 OR NOT (OLD.id <=> NEW.id) OR NOT (OLD.charge_id <=> NEW.charge_id) OR NOT (OLD.initiated_by <=> NEW.initiated_by)
 OR NOT (OLD.effective_date <=> NEW.effective_date) OR NOT (OLD.amount <=> NEW.amount)
 OR NOT (OLD.explanation <=> NEW.explanation) OR NOT (OLD.evidence <=> NEW.evidence)
 OR NOT (OLD.input_hash <=> NEW.input_hash) OR NOT (OLD.snapshot <=> NEW.snapshot)
 OR NOT (OLD.idempotency_key <=> NEW.idempotency_key) OR NOT (OLD.created_at <=> NEW.created_at)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable submitted request or invalid transition'; END IF;
END$$
DELIMITER ;
