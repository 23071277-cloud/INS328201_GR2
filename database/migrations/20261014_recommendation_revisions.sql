-- PROPOSED ONLY: NOT APPLIED TO energy_dss. Explicit manual approval/backup required.
-- Existing recommendations, unique keys, rules and audit events remain unchanged.
CREATE TABLE recommendation_revisions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 alert_id BIGINT UNSIGNED NOT NULL,
 recommendation_rule_id INT UNSIGNED NOT NULL,
 legacy_recommendation_id BIGINT UNSIGNED NULL,
 previous_revision_id BIGINT UNSIGNED NULL,
 workflow_stage VARCHAR(40) NOT NULL,
 category VARCHAR(80) NOT NULL,
 recommendation_text VARCHAR(500) NOT NULL,
 context_hash CHAR(64) NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_rec_revision_context (alert_id,recommendation_rule_id,context_hash),
 KEY idx_rec_revision_alert (alert_id,id),
 CONSTRAINT fk_recr_alert FOREIGN KEY (alert_id) REFERENCES alerts(id),
 CONSTRAINT fk_recr_rule FOREIGN KEY (recommendation_rule_id) REFERENCES recommendation_rules(id),
 CONSTRAINT fk_recr_legacy FOREIGN KEY (legacy_recommendation_id) REFERENCES recommendations(id),
 CONSTRAINT fk_recr_previous FOREIGN KEY (previous_revision_id) REFERENCES recommendation_revisions(id),
 CONSTRAINT fk_recr_actor FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
DELIMITER $$
CREATE TRIGGER rec_revision_no_update BEFORE UPDATE ON recommendation_revisions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable recommendation revision'; END$$
CREATE TRIGGER rec_revision_no_delete BEFORE DELETE ON recommendation_revisions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Preserve recommendation revision history'; END$$
DELIMITER ;
