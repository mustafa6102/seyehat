-- Duffel Flights test-mode provider capability activation
-- v3.22 / ProviderCapabilityMatrix gate (ADR-006, OD-001 test-mode path)
--
-- Prerequisites before running this file:
--   1. DUFFEL_ACCESS_TOKEN=duffel_test_<token> set in .env
--   2. DUFFEL_FLIGHTS_PASSENGER_POLICY=ALL_ADULT set in .env
--      (required only for legacy count-only TripRequests without explicit passenger_composition)
--   3. The provider_capabilities table exists
--      (migration 2026_09_27_000100_create_provider_capabilities_table)
--
-- Activates:
--   - duffel_flights / search  -> DuffelFlightProviderAdapter POST /air/offer_requests
--   - duffel_flights / reprice -> DuffelFlightRepriceGateway  GET  /air/offers/{id}
--
-- book is deliberately not activated: no order creation or payment is supported.
-- Run once in the target database; idempotent on re-run (updates existing rows).

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
    ('duffel_flights', 'search',  'production', 1, 'FRESH',
     'duffel-api-v2-test-mode-approved-2026-10-28', NOW()),
    ('duffel_flights', 'reprice', 'production', 1, 'FRESH',
     'duffel-api-v2-test-mode-approved-2026-10-28', NOW())
ON DUPLICATE KEY UPDATE
    `supported`   = VALUES(`supported`),
    `status`      = VALUES(`status`),
    `evidence`    = VALUES(`evidence`),
    `verified_at` = VALUES(`verified_at`);

-- Verify: her iki satir PRESENT gostermeli.
SELECT
    `provider_id`,
    `operation`,
    `environment`,
    `supported`,
    `status`,
    `verified_at`
FROM `provider_capabilities`
WHERE `provider_id` = 'duffel_flights'
ORDER BY `operation`;
