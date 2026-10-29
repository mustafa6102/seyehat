-- Ticketmaster event discovery provider capability activation
-- v3.22 / ProviderCapabilityMatrix gate (ADR-006, OD-EVT-001-TM)
--
-- Prerequisites before running this file:
--   1. TICKETMASTER_API_KEY=<key> set in .env
--   2. The provider_capabilities table exists (migration 2026_09_27_000100_create_provider_capabilities_table)
--   3. evt_001_schema.sql has been run (events + event_discovery_jobs tables)
--
-- Activates:
--   - ticketmaster / event_discovery -> TicketmasterEventAdapter GET /discovery/v2/events.json
--
-- Run once in the target database; idempotent on re-run (ON DUPLICATE KEY UPDATE).

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

INSERT INTO `provider_capabilities`
    (`provider_id`, `operation`, `environment`, `supported`, `status`, `evidence`, `verified_at`)
VALUES
    ('ticketmaster', 'event_discovery', 'production', 1, 'FRESH',
     'ticketmaster-discovery-api-v2-od-evt-001-tm-2026-10-28', NOW())
ON DUPLICATE KEY UPDATE
    `supported`   = VALUES(`supported`),
    `status`      = VALUES(`status`),
    `evidence`    = VALUES(`evidence`),
    `verified_at` = VALUES(`verified_at`);

-- Verify: row should be PRESENT with supported=1.
SELECT `provider_id`, `operation`, `environment`, `supported`, `status`, `verified_at`
FROM `provider_capabilities`
WHERE `provider_id` = 'ticketmaster'
ORDER BY `operation`;
