-- Verification-only checks for v3.13-v3.16 production schema.
-- This file performs SELECT statements only; it does not create, alter or delete anything.
-- Run before the update (to review drift) and after the update (all required rows should be PRESENT/MATCH).

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT
    DATABASE() AS selected_database,
    VERSION() AS server_version,
    @@version_comment AS server_distribution,
    @@character_set_database AS database_charset,
    @@collation_database AS database_collation;

-- Required tables. A MISSING result is expected before the update and is an error afterward.
SELECT
    expected.table_name,
    CASE WHEN actual.TABLE_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS verification_status,
    actual.ENGINE,
    actual.TABLE_COLLATION
FROM (
    SELECT 'trip_requests' AS table_name
    UNION ALL SELECT 'trip_request_flight_selections'
    UNION ALL SELECT 'accommodation_search_jobs'
    UNION ALL SELECT 'trip_request_accommodation_selections'
    UNION ALL SELECT 'poi_discovery_jobs'
    UNION ALL SELECT 'pois'
    UNION ALL SELECT 'trips'
    UNION ALL SELECT 'plan_revisions'
    UNION ALL SELECT 'outbox_events'
) AS expected
LEFT JOIN information_schema.TABLES AS actual
    ON actual.TABLE_SCHEMA = DATABASE()
   AND actual.TABLE_NAME = expected.table_name
ORDER BY expected.table_name;

-- Every column required by application persistence. Missing rows require review.
SELECT
    expected.table_name,
    expected.column_name,
    expected.expected_type,
    CASE
        WHEN actual.COLUMN_NAME IS NULL THEN 'MISSING'
        WHEN LOWER(actual.COLUMN_TYPE) <> LOWER(expected.expected_type) THEN 'TYPE_MISMATCH'
        WHEN actual.IS_NULLABLE <> expected.expected_nullable THEN 'NULLABILITY_MISMATCH'
        ELSE 'MATCH'
    END AS verification_status,
    actual.COLUMN_TYPE AS actual_type,
    actual.IS_NULLABLE AS actual_nullable
FROM (
    SELECT 'trip_requests' table_name, 'room_count' column_name, 'int unsigned' expected_type, 'YES' expected_nullable
    UNION ALL SELECT 'trip_requests', 'owner_issuer', 'varchar(255)', 'YES'
    UNION ALL SELECT 'trip_requests', 'owner_subject', 'varchar(255)', 'YES'

    UNION ALL SELECT 'trip_request_flight_selections', 'trip_request_id', 'varchar(255)', 'NO'
    UNION ALL SELECT 'trip_request_flight_selections', 'status', 'varchar(32)', 'NO'
    UNION ALL SELECT 'trip_request_flight_selections', 'offer_snapshot', 'longtext', 'NO'
    UNION ALL SELECT 'trip_request_flight_selections', 'provider_id', 'varchar(64)', 'NO'
    UNION ALL SELECT 'trip_request_flight_selections', 'provider_offer_id', 'varchar(255)', 'NO'
    UNION ALL SELECT 'trip_request_flight_selections', 'verified_price_minor_units', 'bigint', 'YES'
    UNION ALL SELECT 'trip_request_flight_selections', 'verified_price_currency', 'char(3)', 'YES'
    UNION ALL SELECT 'trip_request_flight_selections', 'verified_at', 'datetime(6)', 'NO'
    UNION ALL SELECT 'trip_request_flight_selections', 'expires_at', 'datetime(6)', 'NO'
    UNION ALL SELECT 'trip_request_flight_selections', 'created_at', 'datetime(6)', 'NO'
    UNION ALL SELECT 'trip_request_flight_selections', 'updated_at', 'datetime(6)', 'NO'

    UNION ALL SELECT 'accommodation_search_jobs', 'search_id', 'char(36)', 'NO'
    UNION ALL SELECT 'accommodation_search_jobs', 'idempotency_key', 'varchar(255)', 'NO'
    UNION ALL SELECT 'accommodation_search_jobs', 'trip_request_id', 'varchar(255)', 'NO'
    UNION ALL SELECT 'accommodation_search_jobs', 'status', 'varchar(16)', 'NO'
    UNION ALL SELECT 'accommodation_search_jobs', 'result_json', 'longtext', 'YES'
    UNION ALL SELECT 'accommodation_search_jobs', 'error_code', 'varchar(64)', 'YES'
    UNION ALL SELECT 'accommodation_search_jobs', 'error_message_tr', 'varchar(255)', 'YES'
    UNION ALL SELECT 'accommodation_search_jobs', 'expires_at', 'datetime(6)', 'YES'
    UNION ALL SELECT 'accommodation_search_jobs', 'created_at', 'datetime(6)', 'NO'
    UNION ALL SELECT 'accommodation_search_jobs', 'updated_at', 'datetime(6)', 'NO'

    UNION ALL SELECT 'trip_request_accommodation_selections', 'trip_request_id', 'varchar(255)', 'NO'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'status', 'varchar(32)', 'NO'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'offer_snapshot', 'longtext', 'NO'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'provider_id', 'varchar(64)', 'NO'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'provider_offer_id', 'varchar(255)', 'NO'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'verified_price_minor_units', 'bigint', 'YES'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'verified_price_currency', 'char(3)', 'YES'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'verified_at', 'datetime(6)', 'NO'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'expires_at', 'datetime(6)', 'NO'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'created_at', 'datetime(6)', 'NO'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'updated_at', 'datetime(6)', 'NO'

    UNION ALL SELECT 'poi_discovery_jobs', 'search_id', 'char(36)', 'NO'
    UNION ALL SELECT 'poi_discovery_jobs', 'idempotency_key', 'varchar(255)', 'NO'
    UNION ALL SELECT 'poi_discovery_jobs', 'trip_request_id', 'varchar(255)', 'NO'
    UNION ALL SELECT 'poi_discovery_jobs', 'status', 'varchar(16)', 'NO'
    UNION ALL SELECT 'poi_discovery_jobs', 'result_json', 'longtext', 'YES'
    UNION ALL SELECT 'poi_discovery_jobs', 'error_code', 'varchar(64)', 'YES'
    UNION ALL SELECT 'poi_discovery_jobs', 'error_message_tr', 'varchar(255)', 'YES'
    UNION ALL SELECT 'poi_discovery_jobs', 'expires_at', 'datetime(6)', 'YES'
    UNION ALL SELECT 'poi_discovery_jobs', 'created_at', 'datetime(6)', 'NO'
    UNION ALL SELECT 'poi_discovery_jobs', 'updated_at', 'datetime(6)', 'NO'

    UNION ALL SELECT 'pois', 'poi_id', 'char(36)', 'NO'
    UNION ALL SELECT 'pois', 'provider_id', 'varchar(64)', 'NO'
    UNION ALL SELECT 'pois', 'provider_place_id', 'varchar(64)', 'NO'
    UNION ALL SELECT 'pois', 'poi_json', 'longtext', 'NO'
    UNION ALL SELECT 'pois', 'created_at', 'datetime(6)', 'NO'
    UNION ALL SELECT 'pois', 'updated_at', 'datetime(6)', 'NO'

    UNION ALL SELECT 'trips', 'trip_id', 'char(36)', 'NO'
    UNION ALL SELECT 'trips', 'trip_request_id', 'varchar(255)', 'NO'
    UNION ALL SELECT 'trips', 'created_at', 'datetime(6)', 'NO'

    UNION ALL SELECT 'plan_revisions', 'trip_id', 'char(36)', 'NO'
    UNION ALL SELECT 'plan_revisions', 'version', 'int unsigned', 'NO'
    UNION ALL SELECT 'plan_revisions', 'status', 'varchar(16)', 'NO'
    UNION ALL SELECT 'plan_revisions', 'items_json', 'longtext', 'NO'
    UNION ALL SELECT 'plan_revisions', 'change_json', 'longtext', 'NO'
    UNION ALL SELECT 'plan_revisions', 'idempotency_key', 'varchar(255)', 'NO'
    UNION ALL SELECT 'plan_revisions', 'request_fingerprint', 'char(64)', 'NO'
    UNION ALL SELECT 'plan_revisions', 'created_at', 'datetime(6)', 'NO'
) AS expected
LEFT JOIN information_schema.COLUMNS AS actual
    ON actual.TABLE_SCHEMA = DATABASE()
   AND actual.TABLE_NAME = expected.table_name
   AND actual.COLUMN_NAME = expected.column_name
WHERE actual.COLUMN_NAME IS NULL
   OR LOWER(actual.COLUMN_TYPE) <> LOWER(expected.expected_type)
   OR actual.IS_NULLABLE <> expected.expected_nullable
ORDER BY expected.table_name, expected.column_name;

-- Required primary/unique/index definitions. Zero rows means every expected index matches.
SELECT
    expected.table_name,
    expected.index_name,
    expected.expected_non_unique,
    expected.expected_columns,
    CASE
        WHEN actual.INDEX_NAME IS NULL THEN 'MISSING'
        WHEN actual.non_unique <> expected.expected_non_unique THEN 'UNIQUENESS_MISMATCH'
        WHEN actual.indexed_columns <> expected.expected_columns THEN 'COLUMN_ORDER_MISMATCH'
        ELSE 'MATCH'
    END AS verification_status,
    actual.non_unique AS actual_non_unique,
    actual.indexed_columns AS actual_columns
FROM (
    SELECT 'trip_request_flight_selections' table_name, 'PRIMARY' index_name, 0 expected_non_unique, 'trip_request_id' expected_columns
    UNION ALL SELECT 'trip_request_flight_selections', 'trfs_provider_offer_status_idx', 1, 'provider_id,provider_offer_id,status'
    UNION ALL SELECT 'accommodation_search_jobs', 'PRIMARY', 0, 'search_id'
    UNION ALL SELECT 'accommodation_search_jobs', 'accommodation_search_jobs_idempotency_key_unique', 0, 'idempotency_key'
    UNION ALL SELECT 'accommodation_search_jobs', 'accommodation_search_jobs_status_expires_at_index', 1, 'status,expires_at'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'PRIMARY', 0, 'trip_request_id'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'tras_provider_offer_status_idx', 1, 'provider_id,provider_offer_id,status'
    UNION ALL SELECT 'poi_discovery_jobs', 'PRIMARY', 0, 'search_id'
    UNION ALL SELECT 'poi_discovery_jobs', 'poi_discovery_jobs_idempotency_key_unique', 0, 'idempotency_key'
    UNION ALL SELECT 'poi_discovery_jobs', 'poi_discovery_jobs_status_expires_at_index', 1, 'status,expires_at'
    UNION ALL SELECT 'pois', 'PRIMARY', 0, 'poi_id'
    UNION ALL SELECT 'pois', 'pois_provider_place_unique', 0, 'provider_id,provider_place_id'
    UNION ALL SELECT 'trips', 'PRIMARY', 0, 'trip_id'
    UNION ALL SELECT 'trips', 'trips_trip_request_id_unique', 0, 'trip_request_id'
    UNION ALL SELECT 'plan_revisions', 'PRIMARY', 0, 'trip_id,version'
    UNION ALL SELECT 'plan_revisions', 'plan_revisions_trip_id_idempotency_key_unique', 0, 'trip_id,idempotency_key'
    UNION ALL SELECT 'plan_revisions', 'plan_revisions_trip_id_status_index', 1, 'trip_id,status'
) AS expected
LEFT JOIN (
    SELECT
        TABLE_NAME,
        INDEX_NAME,
        MIN(NON_UNIQUE) AS non_unique,
        GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS indexed_columns
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    GROUP BY TABLE_NAME, INDEX_NAME
) AS actual
    ON actual.TABLE_NAME = expected.table_name
   AND actual.INDEX_NAME = expected.index_name
WHERE actual.INDEX_NAME IS NULL
   OR actual.non_unique <> expected.expected_non_unique
   OR actual.indexed_columns <> expected.expected_columns
ORDER BY expected.table_name, expected.index_name;

-- Required safe FK. Other potential FKs are intentionally blocked by incompatible identifier types.
SELECT
    'plan_revisions_trip_fk' AS expected_constraint,
    CASE WHEN COUNT(*) = 1 THEN 'MATCH' ELSE 'MISSING_OR_MISMATCH' END AS verification_status
FROM information_schema.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'plan_revisions'
  AND CONSTRAINT_NAME = 'plan_revisions_trip_fk'
  AND COLUMN_NAME = 'trip_id'
  AND REFERENCED_TABLE_NAME = 'trips'
  AND REFERENCED_COLUMN_NAME = 'trip_id';

-- Required named CHECK constraints. Definitions must also be reviewed in SHOW CREATE TABLE output.
SELECT
    expected.table_name,
    expected.constraint_name,
    CASE WHEN actual.CONSTRAINT_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT_REVIEW_DEFINITION' END AS verification_status
FROM (
    SELECT 'trip_requests' table_name, 'trip_requests_room_count_chk' constraint_name
    UNION ALL SELECT 'trip_requests', 'trip_requests_owner_pair_chk'
    UNION ALL SELECT 'trip_request_flight_selections', 'trfs_status_chk'
    UNION ALL SELECT 'trip_request_flight_selections', 'trfs_verified_price_pair_chk'
    UNION ALL SELECT 'accommodation_search_jobs', 'accommodation_search_jobs_status_chk'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'tras_status_chk'
    UNION ALL SELECT 'trip_request_accommodation_selections', 'tras_verified_price_pair_chk'
    UNION ALL SELECT 'poi_discovery_jobs', 'poi_discovery_jobs_status_chk'
    UNION ALL SELECT 'plan_revisions', 'plan_revisions_status_chk'
    UNION ALL SELECT 'plan_revisions', 'plan_revisions_version_chk'
) AS expected
LEFT JOIN information_schema.TABLE_CONSTRAINTS AS actual
  ON actual.CONSTRAINT_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.table_name
 AND actual.CONSTRAINT_NAME = expected.constraint_name
 AND actual.CONSTRAINT_TYPE = 'CHECK'
WHERE actual.CONSTRAINT_NAME IS NULL
ORDER BY expected.table_name, expected.constraint_name;

-- Review actual checks/FKs and compare them with the approved script.
SELECT
    tc.TABLE_NAME,
    tc.CONSTRAINT_NAME,
    tc.CONSTRAINT_TYPE
FROM information_schema.TABLE_CONSTRAINTS AS tc
WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
  AND tc.TABLE_NAME IN (
      'trip_request_flight_selections',
      'accommodation_search_jobs',
      'trip_request_accommodation_selections',
      'poi_discovery_jobs',
      'pois',
      'trips',
      'plan_revisions'
  )
ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_TYPE, tc.CONSTRAINT_NAME;

-- Identifier-type blockers: these must remain review items, not silently coerced FKs.
SELECT
    parent.TABLE_NAME AS parent_table,
    parent.COLUMN_NAME AS parent_column,
    parent.COLUMN_TYPE AS parent_type,
    child.TABLE_NAME AS child_table,
    child.COLUMN_NAME AS child_column,
    child.COLUMN_TYPE AS child_type,
    CASE
        WHEN parent.COLUMN_TYPE = child.COLUMN_TYPE THEN 'TYPE_COMPATIBLE_REVIEW_FK'
        ELSE 'BLOCKED_TYPE_MISMATCH'
    END AS verification_status
FROM information_schema.COLUMNS AS parent
JOIN information_schema.COLUMNS AS child
  ON child.TABLE_SCHEMA = parent.TABLE_SCHEMA
WHERE parent.TABLE_SCHEMA = DATABASE()
  AND (
      (parent.TABLE_NAME = 'trip_requests' AND parent.COLUMN_NAME = 'id'
       AND child.TABLE_NAME IN ('trip_request_flight_selections', 'accommodation_search_jobs', 'trip_request_accommodation_selections', 'poi_discovery_jobs', 'trips')
       AND child.COLUMN_NAME = 'trip_request_id')
      OR
      (parent.TABLE_NAME = 'trips' AND parent.COLUMN_NAME = 'trip_id'
       AND child.TABLE_NAME = 'travelers' AND child.COLUMN_NAME = 'trip_id')
  )
ORDER BY parent.TABLE_NAME, child.TABLE_NAME;

