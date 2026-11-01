-- TRV-001 encrypted sensitive storage (migration 2026_11_01_000000_create_traveler_sensitive_storage_tables).
-- Target: MySQL 8.0+, InnoDB. Run once on the selected database. Additive: no existing row or column changes.
-- Running this activates nothing: no route uses these tables and SensitiveTravelerActivation stays closed.
-- The collation is read from `travelers`, because a foreign key needs identical string columns.

SET @traveler_collation := (
    SELECT `TABLE_COLLATION` FROM `information_schema`.`TABLES`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'travelers'
);

SET @create_keys := CONCAT(
    'CREATE TABLE `traveler_data_keys` (',
    '`traveler_id` VARCHAR(255) NOT NULL, ',
    '`encrypted_dek` BLOB NOT NULL, ',
    '`key_version` VARCHAR(64) NOT NULL, ',
    '`created_at` DATETIME(6) NOT NULL, ',
    '`rotated_at` DATETIME(6) NULL, ',
    'PRIMARY KEY (`traveler_id`), ',
    'CONSTRAINT `traveler_data_keys_traveler_fk` FOREIGN KEY (`traveler_id`) REFERENCES `travelers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=', @traveler_collation
);
PREPARE create_keys FROM @create_keys;
EXECUTE create_keys;
DEALLOCATE PREPARE create_keys;

SET @create_fields := CONCAT(
    'CREATE TABLE `traveler_sensitive_fields` (',
    '`traveler_id` VARCHAR(255) NOT NULL, ',
    '`field_path` VARCHAR(32) NOT NULL, ',
    '`ciphertext` BLOB NOT NULL, ',
    '`aad_version` SMALLINT UNSIGNED NOT NULL, ',
    '`schema_version` VARCHAR(16) NOT NULL, ',
    '`updated_at` DATETIME(6) NOT NULL, ',
    'PRIMARY KEY (`traveler_id`, `field_path`), ',
    'CONSTRAINT `traveler_sensitive_fields_key_fk` FOREIGN KEY (`traveler_id`) REFERENCES `traveler_data_keys` (`traveler_id`) ON UPDATE RESTRICT ON DELETE RESTRICT',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=', @traveler_collation
);
PREPARE create_fields FROM @create_fields;
EXECUTE create_fields;
DEALLOCATE PREPARE create_fields;

-- Records the same change for `php artisan migrate`.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_01_000000_create_traveler_sensitive_storage_tables', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;

-- Verify: two tables, zero rows, two foreign keys.
SELECT COUNT(*) AS `key_rows` FROM `traveler_data_keys`;
SELECT COUNT(*) AS `field_rows` FROM `traveler_sensitive_fields`;
SELECT `CONSTRAINT_NAME` FROM `information_schema`.`REFERENTIAL_CONSTRAINTS`
WHERE `CONSTRAINT_SCHEMA` = DATABASE() AND `CONSTRAINT_NAME` IN ('traveler_data_keys_traveler_fk', 'traveler_sensitive_fields_key_fk');

-- Rollback (only while both tables are empty; dropping keys makes any stored ciphertext permanently unreadable):
-- DROP TABLE `traveler_sensitive_fields`;
-- DROP TABLE `traveler_data_keys`;
-- DELETE FROM `migrations` WHERE `migration` = '2026_11_01_000000_create_traveler_sensitive_storage_tables';
