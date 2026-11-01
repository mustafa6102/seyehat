-- TRV-001 Basic Traveler deletion and information requests
-- (migration 2026_11_01_020000_create_traveler_subject_requests_table).
-- Target: MySQL 8.0+, InnoDB, utf8mb4. Run once on the selected database. Additive: no existing row or column changes.
-- Running this activates nothing; the screen stays behind config/traveler_basic.php.

CREATE TABLE `traveler_subject_requests` (
    `id` CHAR(36) NOT NULL,
    `trip_request_id` VARCHAR(255) NOT NULL,
    `trip_id` CHAR(36) NOT NULL,
    `traveler_id` VARCHAR(255) NOT NULL,
    `request_type` VARCHAR(16) NOT NULL,
    `status` VARCHAR(16) NOT NULL,
    `is_open` TINYINT(1) NULL,
    `subject_id` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    `processed_at` DATETIME(6) NULL,
    `processed_by` VARCHAR(255) NULL,
    `decision_ref` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `traveler_subject_requests_open_unique` (`traveler_id`, `request_type`, `is_open`),
    KEY `traveler_subject_requests_queue_index` (`is_open`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Records the same change for `php artisan migrate`.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_01_020000_create_traveler_subject_requests_table', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;

-- Verify: zero rows and two secondary indexes.
SELECT COUNT(*) AS `request_rows` FROM `traveler_subject_requests`;
SHOW INDEX FROM `traveler_subject_requests` WHERE `Key_name` <> 'PRIMARY';

-- Rollback (only while the table is empty; a filled table is the record of processed requests):
-- DROP TABLE `traveler_subject_requests`;
-- DELETE FROM `migrations` WHERE `migration` = '2026_11_01_020000_create_traveler_subject_requests_table';
