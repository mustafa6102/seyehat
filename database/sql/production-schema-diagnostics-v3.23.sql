-- AI Seyahat Planlayicisi v3.23 production schema diagnostics
-- READ ONLY. MySQL 8 / phpMyAdmin. Bu dosya veri veya sema degistirmez.
-- Ciktiyi eksiksiz kaydedin; production-missing-changes-v3.23.sql bu cikti incelenmeden CALISTIRILAMAZ.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT
    DATABASE() AS selected_database,
    VERSION() AS server_version,
    @@version_comment AS server_distribution,
    @@character_set_database AS database_charset,
    @@collation_database AS database_collation,
    @@sql_mode AS sql_mode,
    @@lower_case_table_names AS lower_case_table_names;

-- 1) Beklenen Laravel migration kaydi ve runtime/application tablolari.
SELECT
    expected.table_name,
    CASE WHEN actual.TABLE_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS table_status,
    actual.ENGINE,
    actual.TABLE_COLLATION,
    actual.TABLE_ROWS,
    actual.DATA_LENGTH,
    actual.INDEX_LENGTH
FROM (
    SELECT 'migrations' AS table_name
    UNION ALL SELECT 'jobs'
    UNION ALL SELECT 'job_batches'
    UNION ALL SELECT 'failed_jobs'
    UNION ALL SELECT 'outbox_events'
    UNION ALL SELECT 'provider_capabilities'
    UNION ALL SELECT 'trip_requests'
    UNION ALL SELECT 'flight_search_jobs'
    UNION ALL SELECT 'travelers'
    UNION ALL SELECT 'trip_request_flight_selections'
    UNION ALL SELECT 'accommodation_search_jobs'
    UNION ALL SELECT 'trip_request_accommodation_selections'
    UNION ALL SELECT 'poi_discovery_jobs'
    UNION ALL SELECT 'pois'
    UNION ALL SELECT 'trips'
    UNION ALL SELECT 'plan_revisions'
    UNION ALL SELECT 'how_operation_idempotency'
    UNION ALL SELECT 'events'
    UNION ALL SELECT 'event_discovery_jobs'
) AS expected
LEFT JOIN information_schema.TABLES AS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.table_name
ORDER BY expected.table_name;

-- 2) Tum gercek kolon tanimlari. Tip, nullability, default, charset ve precision birlikte incelenir.
SELECT
    c.TABLE_NAME,
    c.ORDINAL_POSITION,
    c.COLUMN_NAME,
    c.COLUMN_TYPE,
    c.IS_NULLABLE,
    c.COLUMN_DEFAULT,
    c.EXTRA,
    c.CHARACTER_SET_NAME,
    c.COLLATION_NAME,
    c.DATETIME_PRECISION
FROM information_schema.COLUMNS AS c
WHERE c.TABLE_SCHEMA = DATABASE()
  AND c.TABLE_NAME IN (
      'migrations', 'jobs', 'job_batches', 'failed_jobs', 'outbox_events',
      'provider_capabilities', 'trip_requests', 'flight_search_jobs', 'travelers',
      'trip_request_flight_selections', 'accommodation_search_jobs',
      'trip_request_accommodation_selections', 'poi_discovery_jobs', 'pois',
      'trips', 'plan_revisions', 'how_operation_idempotency', 'events', 'event_discovery_jobs'
  )
ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION;

-- 3) Gercek indeksler; kolon sirasi ve prefix uzunlugu korunur.
SELECT
    s.TABLE_NAME,
    s.INDEX_NAME,
    MIN(s.NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(
        CONCAT(s.COLUMN_NAME, IF(s.SUB_PART IS NULL, '', CONCAT('(', s.SUB_PART, ')')))
        ORDER BY s.SEQ_IN_INDEX SEPARATOR ','
    ) AS indexed_columns,
    MIN(s.INDEX_TYPE) AS index_type
FROM information_schema.STATISTICS AS s
WHERE s.TABLE_SCHEMA = DATABASE()
  AND s.TABLE_NAME IN (
      'migrations', 'jobs', 'job_batches', 'failed_jobs', 'outbox_events',
      'provider_capabilities', 'trip_requests', 'flight_search_jobs', 'travelers',
      'trip_request_flight_selections', 'accommodation_search_jobs',
      'trip_request_accommodation_selections', 'poi_discovery_jobs', 'pois',
      'trips', 'plan_revisions', 'how_operation_idempotency', 'events', 'event_discovery_jobs'
  )
GROUP BY s.TABLE_NAME, s.INDEX_NAME
ORDER BY s.TABLE_NAME, s.INDEX_NAME;

-- 4) PK/UNIQUE/FK/CHECK envanteri ve CHECK govdeleri.
SELECT
    tc.TABLE_NAME,
    tc.CONSTRAINT_NAME,
    tc.CONSTRAINT_TYPE,
    cc.CHECK_CLAUSE
FROM information_schema.TABLE_CONSTRAINTS AS tc
LEFT JOIN information_schema.CHECK_CONSTRAINTS AS cc
  ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
 AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
  AND tc.TABLE_NAME IN (
      'jobs', 'job_batches', 'failed_jobs', 'outbox_events', 'provider_capabilities',
      'trip_requests', 'flight_search_jobs', 'travelers', 'trip_request_flight_selections',
      'accommodation_search_jobs', 'trip_request_accommodation_selections',
      'poi_discovery_jobs', 'pois', 'trips', 'plan_revisions', 'how_operation_idempotency', 'events', 'event_discovery_jobs'
  )
ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_TYPE, tc.CONSTRAINT_NAME;

SELECT
    kcu.TABLE_NAME,
    kcu.CONSTRAINT_NAME,
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_NAME,
    kcu.REFERENCED_COLUMN_NAME,
    rc.UPDATE_RULE,
    rc.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE AS kcu
LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS AS rc
  ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
 AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION;

-- 5) Bilincli FK blokajlari: tipler uyusmadan FK eklenmeyecek.
SELECT
    parent.TABLE_NAME AS parent_table,
    parent.COLUMN_NAME AS parent_column,
    parent.COLUMN_TYPE AS parent_type,
    child.TABLE_NAME AS child_table,
    child.COLUMN_NAME AS child_column,
    child.COLUMN_TYPE AS child_type,
    CASE WHEN parent.COLUMN_TYPE = child.COLUMN_TYPE
         THEN 'TYPE_COMPATIBLE_REVIEW_FK'
         ELSE 'BLOCKED_TYPE_MISMATCH'
    END AS review_status
FROM information_schema.COLUMNS AS parent
JOIN information_schema.COLUMNS AS child
  ON child.TABLE_SCHEMA = parent.TABLE_SCHEMA
WHERE parent.TABLE_SCHEMA = DATABASE()
  AND (
      (parent.TABLE_NAME = 'trip_requests' AND parent.COLUMN_NAME = 'id'
       AND child.TABLE_NAME IN (
           'flight_search_jobs', 'trip_request_flight_selections', 'accommodation_search_jobs',
           'trip_request_accommodation_selections', 'poi_discovery_jobs', 'trips',
           'how_operation_idempotency', 'event_discovery_jobs'
       )
       AND child.COLUMN_NAME = 'trip_request_id')
      OR
      (parent.TABLE_NAME = 'trips' AND parent.COLUMN_NAME = 'trip_id'
       AND child.TABLE_NAME = 'travelers' AND child.COLUMN_NAME = 'trip_id')
  )
ORDER BY parent.TABLE_NAME, child.TABLE_NAME;

-- 6) Laravel migration tablosu varsa phpMyAdmin'da AYRI ve salt-okunur olarak calistirilacak sorgu:
-- SELECT migration, batch FROM migrations ORDER BY batch, migration;
-- Beklenen migration adlari:
-- 0001_01_01_000002_create_jobs_table
-- 2026_09_27_000000_create_outbox_events_table
-- 2026_09_27_000100_create_provider_capabilities_table
-- 2026_09_27_000200_create_trip_requests_table
-- 2026_09_27_000300_create_flight_search_jobs_table
-- 2026_09_27_000400_create_travelers_table
-- 2026_09_30_000000_add_trip_request_id_to_flight_search_jobs_table
-- 2026_10_01_000000_add_journey_dates_to_trip_requests_table
-- 2026_10_02_000000_add_passenger_composition_to_trip_requests_table
-- 2026_10_29_000000_create_active_feature_storage_tables
-- 2026_10_29_010000_create_evt_001_storage_tables

-- 7) provider_capabilities varsa AYRI salt-okunur sorgu olarak calistirin ve ciktiyi kaydedin:
-- SELECT provider_id, operation, environment, supported, status, evidence, verified_at
-- FROM provider_capabilities
-- ORDER BY provider_id, operation, environment;
-- Eksik satir otomatik olarak supported=1 yapilamaz; capability evidence deploy ortamina ozeldir.

-- 8) Kritik mevcut-veri on kontrolleri. Ilgili tablo/kolon PRESENT ise AYRI calistirin.
-- SELECT COUNT(*) AS invalid_room_count FROM trip_requests WHERE room_count IS NOT NULL AND room_count < 1;
-- SELECT COUNT(*) AS half_owned_requests FROM trip_requests
-- WHERE (owner_issuer IS NULL) <> (owner_subject IS NULL);
-- SELECT status, COUNT(*) FROM flight_search_jobs GROUP BY status;
-- SELECT status, COUNT(*) FROM trip_request_flight_selections GROUP BY status;
-- SELECT status, COUNT(*) FROM accommodation_search_jobs GROUP BY status;
-- SELECT status, COUNT(*) FROM trip_request_accommodation_selections GROUP BY status;
-- SELECT status, COUNT(*) FROM poi_discovery_jobs GROUP BY status;
-- SELECT status, COUNT(*) FROM plan_revisions GROUP BY status;
-- SELECT status, COUNT(*) FROM how_operation_idempotency GROUP BY status;

SELECT
    'DIAGNOSTICS_COMPLETE' AS status,
    'Ciktilar incelenmeden production-missing-changes-v3.23.sql hazir degildir.' AS next_step;
