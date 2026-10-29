-- HOW-001 v3.19 operation-idempotency ledger
-- Target: MySQL 8.x / MariaDB 10.5+, InnoDB, utf8mb4.
-- MANUAL EXECUTION ONLY. Additive DDL; no migration is generated.
-- Run database/manual/verify_how_001_v3_19_schema.sql before and after this file.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS `_require_selected_database`$$
CREATE PROCEDURE `_require_selected_database`()
BEGIN
    IF DATABASE() IS NULL OR DATABASE() = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Bir veritabani secilmeden HOW-001 sema guncellemesi calistirilamaz.';
    END IF;
END$$
CALL `_require_selected_database`()$$
DROP PROCEDURE `_require_selected_database`$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS `how_operation_idempotency` (
    `scope_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `principal_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `operation` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `trip_request_id` VARCHAR(255) NOT NULL,
    `item_id` VARCHAR(255) NOT NULL,
    `base_revision_version` INT UNSIGNED NOT NULL,
    `idempotency_key` VARCHAR(255) NOT NULL,
    `request_fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `response_status` SMALLINT UNSIGNED NULL,
    `response_json` LONGTEXT NULL,
    `created_at` DATETIME(6) NOT NULL,
    `completed_at` DATETIME(6) NULL,
    PRIMARY KEY (`scope_hash`, `idempotency_key`),
    KEY `how_idem_trip_request_index` (`trip_request_id`),
    KEY `how_idem_status_created_index` (`status`, `created_at`),
    CONSTRAINT `how_idem_version_chk` CHECK (`base_revision_version` >= 1),
    CONSTRAINT `how_idem_status_chk` CHECK (`status` IN ('IN_PROGRESS', 'COMPLETED')),
    CONSTRAINT `how_idem_response_chk` CHECK (
        (`status` = 'IN_PROGRESS' AND `response_status` IS NULL AND `response_json` IS NULL AND `completed_at` IS NULL)
        OR
        (`status` = 'COMPLETED' AND `response_status` IS NOT NULL AND `response_json` IS NOT NULL AND `completed_at` IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No FK is added to trip_requests: its current id is BIGINT UNSIGNED while contract-facing trip_request_id is VARCHAR(255).
-- OD-013 remains OPEN. When the approved TripRequest deletion flow runs, rows with the same trip_request_id must be deleted.
-- No numeric retention duration, cleanup schedule, PlanRevision relation or outbox relation is introduced.
