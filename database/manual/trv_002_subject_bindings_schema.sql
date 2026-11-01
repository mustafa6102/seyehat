-- TRV-002 Traveler subject bindings (migration 2026_11_01_010000_create_traveler_subject_bindings_table).
-- Target: MySQL 8.0+, InnoDB. Run once on the selected database, after trv_001_sensitive_storage_schema.sql.
-- Additive: no existing row or column changes. Running this activates nothing and creates no binding.
-- The collation is read from `travelers`, because a foreign key needs identical string columns.

SET @traveler_collation := (
    SELECT `TABLE_COLLATION` FROM `information_schema`.`TABLES`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'travelers'
);

SET @create_bindings := CONCAT(
    'CREATE TABLE `traveler_subject_bindings` (',
    '`traveler_id` VARCHAR(255) NOT NULL, ',
    '`trip_id` CHAR(36) NOT NULL, ',
    '`issuer` VARCHAR(255) NOT NULL, ',
    '`subject` VARCHAR(255) NOT NULL, ',
    '`status` VARCHAR(16) NOT NULL, ',
    '`proof_ref` VARCHAR(255) NOT NULL, ',
    '`verified_at` DATETIME(6) NOT NULL, ',
    '`revoked_at` DATETIME(6) NULL, ',
    'PRIMARY KEY (`traveler_id`), ',
    'UNIQUE KEY `traveler_subject_bindings_principal_unique` (`trip_id`, `issuer`, `subject`), ',
    'CONSTRAINT `traveler_subject_bindings_traveler_fk` FOREIGN KEY (`traveler_id`) REFERENCES `travelers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT, ',
    'CONSTRAINT `traveler_subject_bindings_trip_fk` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`trip_id`) ON UPDATE RESTRICT ON DELETE RESTRICT',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=', @traveler_collation
);
PREPARE create_bindings FROM @create_bindings;
EXECUTE create_bindings;
DEALLOCATE PREPARE create_bindings;

-- Records the same change for `php artisan migrate`.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_01_010000_create_traveler_subject_bindings_table', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;

-- Verify: one table, zero rows, two foreign keys.
SELECT COUNT(*) AS `binding_rows` FROM `traveler_subject_bindings`;
SELECT `CONSTRAINT_NAME` FROM `information_schema`.`REFERENTIAL_CONSTRAINTS`
WHERE `CONSTRAINT_SCHEMA` = DATABASE() AND `CONSTRAINT_NAME` IN ('traveler_subject_bindings_traveler_fk', 'traveler_subject_bindings_trip_fk');

-- Rollback (only while the table is empty):
-- DROP TABLE `traveler_subject_bindings`;
-- DELETE FROM `migrations` WHERE `migration` = '2026_11_01_010000_create_traveler_subject_bindings_table';
