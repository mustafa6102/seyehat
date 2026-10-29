-- EVT-001 v3.22: canonical event storage and event discovery job tables.
-- Run once in the target database; idempotent on re-run (IF NOT EXISTS).
-- Prerequisites: MySQL 8.0+, utf8mb4 charset, a selected database.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS `_require_selected_database`$$
CREATE PROCEDURE `_require_selected_database`()
BEGIN
    IF DATABASE() IS NULL OR DATABASE() = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Bir veritabani secilmeden calistirilamaz.';
    END IF;
END$$
CALL `_require_selected_database`()$$
DROP PROCEDURE `_require_selected_database`$$
DELIMITER ;

-- events: canonical SCH-Event storage, one row per (provider_id, provider_event_id).
-- Only the SCH-Event JSON (with event_id) is stored; raw Ticketmaster responses are never persisted.
CREATE TABLE IF NOT EXISTS `events` (
    `event_id`          CHAR(36)        NOT NULL COMMENT 'Server-generated UUID (primary key)',
    `provider_id`       VARCHAR(64)     NOT NULL COMMENT 'e.g. ticketmaster',
    `provider_event_id` VARCHAR(128)    NOT NULL COMMENT 'Ticketmaster event id',
    `event_json`        LONGTEXT        NOT NULL COMMENT 'Full SCH-Event JSON including event_id',
    `created_at`        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at`        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`event_id`),
    UNIQUE KEY `uq_events_provider` (`provider_id`, `provider_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- event_discovery_jobs: EVT-001 discovery job lifecycle.
-- Terminal states (COMPLETED, FAILED, EXPIRED) are immutable; no partial update after settlement.
CREATE TABLE IF NOT EXISTS `event_discovery_jobs` (
    `search_id`         CHAR(36)        NOT NULL COMMENT 'Server-generated UUID',
    `idempotency_key`   VARCHAR(255)    NOT NULL COMMENT 'Client idempotency key',
    `trip_request_id`   VARCHAR(255)    NOT NULL COMMENT 'Owning TripRequest id',
    `status`            VARCHAR(16)     NOT NULL DEFAULT 'QUEUED' COMMENT 'QUEUED | RUNNING | COMPLETED | FAILED | EXPIRED',
    `result_json`       LONGTEXT        NULL     COMMENT 'SCH-Event list JSON when COMPLETED; null otherwise',
    `error_code`        VARCHAR(64)     NULL     COMMENT 'Machine error code when FAILED',
    `error_message_tr`  VARCHAR(255)    NULL     COMMENT 'Turkish user-facing error when FAILED',
    `expires_at`        DATETIME(6)     NULL     COMMENT 'Optional TTL for EXPIRED transition',
    `created_at`        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at`        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`search_id`),
    UNIQUE KEY `uq_event_discovery_jobs_key` (`idempotency_key`),
    KEY `idx_event_discovery_jobs_trip` (`trip_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify: both tables should appear.
SHOW TABLES LIKE 'events';
SHOW TABLES LIKE 'event_discovery_jobs';
