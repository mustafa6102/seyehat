-- TRV-001 Basic Traveler storage (migration 2026_10_31_000000_add_basic_enrollment_to_travelers_table).
-- Target: MySQL 8.0+, InnoDB, utf8mb4. Run once on the selected database. Additive: existing rows keep NULL values.
-- Running this does not activate the feature; activation stays behind config/traveler_basic.php.

ALTER TABLE `travelers`
    ADD COLUMN `created_at` DATETIME(6) NULL,
    ADD COLUMN `idempotency_key` VARCHAR(255) NULL,
    ADD COLUMN `request_fingerprint` CHAR(64) NULL,
    ADD UNIQUE KEY `travelers_trip_id_idempotency_key_unique` (`trip_id`, `idempotency_key`);

-- Records the same change for `php artisan migrate`.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_31_000000_add_basic_enrollment_to_travelers_table', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;

-- Verify: three columns and two index rows (trip_id, idempotency_key).
SHOW COLUMNS FROM `travelers` WHERE `Field` IN ('created_at', 'idempotency_key', 'request_fingerprint');
SHOW INDEX FROM `travelers` WHERE `Key_name` = 'travelers_trip_id_idempotency_key_unique';

-- Rollback:
-- ALTER TABLE `travelers`
--     DROP INDEX `travelers_trip_id_idempotency_key_unique`,
--     DROP COLUMN `created_at`, DROP COLUMN `idempotency_key`, DROP COLUMN `request_fingerprint`;
-- DELETE FROM `migrations` WHERE `migration` = '2026_10_31_000000_add_basic_enrollment_to_travelers_table';
