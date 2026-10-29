-- AI Seyahat v3.13-v3.16 production schema update
-- Target: MySQL 8.x / MariaDB 10.5+ using InnoDB and utf8mb4.
-- MANUAL EXECUTION ONLY. Review verify_production_schema.sql output first.
-- This script contains additive DDL only. It does not backfill, delete or rewrite business data.
-- MySQL/MariaDB DDL causes implicit commits and is not transactionally rollback-safe.
--
-- Prerequisites and execution order:
-- 1) Put the application in a controlled maintenance/deploy window and stop queue workers.
-- 2) Take and test a restorable logical + provider-level snapshot backup.
-- 3) Run database/manual/verify_production_schema.sql and archive its output.
-- 4) Review every TYPE_MISMATCH/NULLABILITY_MISMATCH and every pre-existing target table.
--    CREATE TABLE IF NOT EXISTS deliberately does not repair a partially existing table.
-- 5) Run this file once with an account allowed to CREATE/ALTER/INDEX/REFERENCES.
-- 6) Run verify_production_schema.sql again; resolve every non-MATCH/MISSING result.
-- 7) Deploy compatible application code, then restart workers and run application smoke tests.
--
-- Risk: CREATE/ALTER/INDEX operations may acquire metadata/table locks, auto-commit, and cannot
-- be rolled back as one transaction. Schedule for low traffic and verify free disk capacity.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Refuse accidental execution without an explicitly selected database.
DELIMITER $$
DROP PROCEDURE IF EXISTS `_require_selected_database`$$
CREATE PROCEDURE `_require_selected_database`()
BEGIN
    IF DATABASE() IS NULL OR DATABASE() = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Bir production veritabani secilmeden sema guncellemesi calistirilamaz.';
    END IF;
END$$
CALL `_require_selected_database`()$$
DROP PROCEDURE `_require_selected_database`$$
DELIMITER ;

-- Apply one additive ALTER only when the named column is absent.
DELIMITER $$
DROP PROCEDURE IF EXISTS `_add_column_if_missing`$$
CREATE PROCEDURE `_add_column_if_missing`(
    IN p_table_name VARCHAR(64),
    IN p_column_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_column_name
    ) THEN
        SET @schema_ddl = p_ddl;
        PREPARE schema_stmt FROM @schema_ddl;
        EXECUTE schema_stmt;
        DEALLOCATE PREPARE schema_stmt;
    END IF;
END$$
DELIMITER ;

-- v3.14 / OD-024: optional explicit room count. NULL preserves every legacy row.
CALL `_add_column_if_missing`(
    'trip_requests',
    'room_count',
    'ALTER TABLE `trip_requests` ADD COLUMN `room_count` INT UNSIGNED NULL AFTER `passenger_composition`'
);

-- v3.16 / RULE-PLT-AUTH-001-04: immutable creator PrincipalRef.
-- Nullable is intentional: ownerless legacy records remain ownerless and must not be claimed.
CALL `_add_column_if_missing`(
    'trip_requests',
    'owner_issuer',
    'ALTER TABLE `trip_requests` ADD COLUMN `owner_issuer` VARCHAR(255) NULL AFTER `room_count`'
);
CALL `_add_column_if_missing`(
    'trip_requests',
    'owner_subject',
    'ALTER TABLE `trip_requests` ADD COLUMN `owner_subject` VARCHAR(255) NULL AFTER `owner_issuer`'
);

DROP PROCEDURE `_add_column_if_missing`;

-- Add approved invariants only when absent. MySQL/MariaDB validates existing rows;
-- invalid room_count or half-populated owner pairs stop execution for manual cleanup.
DELIMITER $$
DROP PROCEDURE IF EXISTS `_add_check_if_missing`$$
CREATE PROCEDURE `_add_check_if_missing`(
    IN p_table_name VARCHAR(64),
    IN p_constraint_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND CONSTRAINT_NAME = p_constraint_name
          AND CONSTRAINT_TYPE = 'CHECK'
    ) THEN
        SET @schema_ddl = p_ddl;
        PREPARE schema_stmt FROM @schema_ddl;
        EXECUTE schema_stmt;
        DEALLOCATE PREPARE schema_stmt;
    END IF;
END$$
DELIMITER ;

CALL `_add_check_if_missing`(
    'trip_requests',
    'trip_requests_room_count_chk',
    'ALTER TABLE `trip_requests` ADD CONSTRAINT `trip_requests_room_count_chk` CHECK (`room_count` IS NULL OR `room_count` >= 1)'
);
CALL `_add_check_if_missing`(
    'trip_requests',
    'trip_requests_owner_pair_chk',
    'ALTER TABLE `trip_requests` ADD CONSTRAINT `trip_requests_owner_pair_chk` CHECK ((`owner_issuer` IS NULL AND `owner_subject` IS NULL) OR (`owner_issuer` IS NOT NULL AND `owner_subject` IS NOT NULL))'
);

DROP PROCEDURE `_add_check_if_missing`;

-- v3.13 / SCH-TripRequestFlightSelection.
-- One row per TripRequest is enforced by the primary key.
CREATE TABLE IF NOT EXISTS `trip_request_flight_selections` (
    `trip_request_id` VARCHAR(255) NOT NULL,
    `status` VARCHAR(32) NOT NULL,
    `offer_snapshot` LONGTEXT NOT NULL,
    `provider_id` VARCHAR(64) NOT NULL,
    `provider_offer_id` VARCHAR(255) NOT NULL,
    `verified_price_minor_units` BIGINT NULL,
    `verified_price_currency` CHAR(3) NULL,
    `verified_at` DATETIME(6) NOT NULL,
    `expires_at` DATETIME(6) NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`trip_request_id`),
    KEY `trfs_provider_offer_status_idx` (`provider_id`, `provider_offer_id`, `status`),
    CONSTRAINT `trfs_status_chk`
        CHECK (`status` IN ('CURRENT', 'RECONFIRMATION_REQUIRED', 'EXPIRED')),
    CONSTRAINT `trfs_verified_price_pair_chk`
        CHECK (
            (`verified_price_minor_units` IS NULL AND `verified_price_currency` IS NULL)
            OR
            (`verified_price_minor_units` IS NOT NULL AND `verified_price_currency` IS NOT NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v3.14 / SCH-AccommodationSearchJob.
CREATE TABLE IF NOT EXISTS `accommodation_search_jobs` (
    `search_id` CHAR(36) NOT NULL,
    `idempotency_key` VARCHAR(255) NOT NULL,
    `trip_request_id` VARCHAR(255) NOT NULL,
    `status` VARCHAR(16) NOT NULL,
    `result_json` LONGTEXT NULL,
    `error_code` VARCHAR(64) NULL,
    `error_message_tr` VARCHAR(255) NULL,
    `expires_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`search_id`),
    UNIQUE KEY `accommodation_search_jobs_idempotency_key_unique` (`idempotency_key`),
    KEY `accommodation_search_jobs_status_expires_at_index` (`status`, `expires_at`),
    CONSTRAINT `accommodation_search_jobs_status_chk`
        CHECK (`status` IN ('QUEUED', 'RUNNING', 'PARTIAL', 'COMPLETED', 'FAILED', 'EXPIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v3.14 / SCH-TripRequestAccommodationSelection.
CREATE TABLE IF NOT EXISTS `trip_request_accommodation_selections` (
    `trip_request_id` VARCHAR(255) NOT NULL,
    `status` VARCHAR(32) NOT NULL,
    `offer_snapshot` LONGTEXT NOT NULL,
    `provider_id` VARCHAR(64) NOT NULL,
    `provider_offer_id` VARCHAR(255) NOT NULL,
    `verified_price_minor_units` BIGINT NULL,
    `verified_price_currency` CHAR(3) NULL,
    `verified_at` DATETIME(6) NOT NULL,
    `expires_at` DATETIME(6) NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`trip_request_id`),
    KEY `tras_provider_offer_status_idx` (`provider_id`, `provider_offer_id`, `status`),
    CONSTRAINT `tras_status_chk`
        CHECK (`status` IN ('CURRENT', 'RECONFIRMATION_REQUIRED', 'EXPIRED')),
    CONSTRAINT `tras_verified_price_pair_chk`
        CHECK (
            (`verified_price_minor_units` IS NULL AND `verified_price_currency` IS NULL)
            OR
            (`verified_price_minor_units` IS NOT NULL AND `verified_price_currency` IS NOT NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v3.15 / SCH-PoiDiscoveryJob.
CREATE TABLE IF NOT EXISTS `poi_discovery_jobs` (
    `search_id` CHAR(36) NOT NULL,
    `idempotency_key` VARCHAR(255) NOT NULL,
    `trip_request_id` VARCHAR(255) NOT NULL,
    `status` VARCHAR(16) NOT NULL,
    `result_json` LONGTEXT NULL,
    `error_code` VARCHAR(64) NULL,
    `error_message_tr` VARCHAR(255) NULL,
    `expires_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`search_id`),
    UNIQUE KEY `poi_discovery_jobs_idempotency_key_unique` (`idempotency_key`),
    KEY `poi_discovery_jobs_status_expires_at_index` (`status`, `expires_at`),
    CONSTRAINT `poi_discovery_jobs_status_chk`
        CHECK (`status` IN ('QUEUED', 'RUNNING', 'PARTIAL', 'COMPLETED', 'FAILED', 'EXPIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v3.15 / SCH-Poi. The composite unique key is required by insertOrIgnore
-- so concurrent discoveries reuse one canonical poi_id per provider element.
CREATE TABLE IF NOT EXISTS `pois` (
    `poi_id` CHAR(36) NOT NULL,
    `provider_id` VARCHAR(64) NOT NULL,
    `provider_place_id` VARCHAR(64) NOT NULL,
    `poi_json` LONGTEXT NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    `updated_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`poi_id`),
    UNIQUE KEY `pois_provider_place_unique` (`provider_id`, `provider_place_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v3.16 / SCH-Trip. A TripRequest has at most one Trip.
CREATE TABLE IF NOT EXISTS `trips` (
    `trip_id` CHAR(36) NOT NULL,
    `trip_request_id` VARCHAR(255) NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`trip_id`),
    UNIQUE KEY `trips_trip_request_id_unique` (`trip_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v3.16 / SCH-PlanRevision + PLT-CONC-001.
-- Composite PK makes version monotonic uniqueness explicit; the second unique key
-- makes idempotency Trip-scoped. Previous revision content stays append-only.
CREATE TABLE IF NOT EXISTS `plan_revisions` (
    `trip_id` CHAR(36) NOT NULL,
    `version` INT UNSIGNED NOT NULL,
    `status` VARCHAR(16) NOT NULL,
    `items_json` LONGTEXT NOT NULL,
    `change_json` LONGTEXT NOT NULL,
    `idempotency_key` VARCHAR(255) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`trip_id`, `version`),
    UNIQUE KEY `plan_revisions_trip_id_idempotency_key_unique` (`trip_id`, `idempotency_key`),
    KEY `plan_revisions_trip_id_status_index` (`trip_id`, `status`),
    CONSTRAINT `plan_revisions_status_chk`
        CHECK (`status` IN ('CURRENT', 'SUPERSEDED')),
    CONSTRAINT `plan_revisions_version_chk`
        CHECK (`version` >= 1),
    CONSTRAINT `plan_revisions_trip_fk`
        FOREIGN KEY (`trip_id`) REFERENCES `trips` (`trip_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentionally not added here:
-- 1) FK from VARCHAR(255) trip_request_id columns to trip_requests.id BIGINT UNSIGNED.
--    The types are incompatible; resolving this requires an approved identifier/data migration.
-- 2) FK from travelers.trip_id VARCHAR(255) to trips.trip_id CHAR(36).
--    Existing rows and type alignment require review; TRV-001 remains blocked.
-- 3) FK from outbox_events to plan_revisions. Existing revision_id is VARCHAR while
--    PlanRevision identity is (trip_id, version); ADR-008/event ordering remains unresolved.
-- 4) Owner backfill. Legacy ownerless TripRequests must remain unclaimable.

