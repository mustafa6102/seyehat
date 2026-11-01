-- AI Seyahat Planlayicisi v3.23 post-deployment verification
-- READ ONLY. MySQL 8 / phpMyAdmin. Expected after an approved corrective deployment.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT DATABASE() AS selected_database, VERSION() AS server_version, @@version_comment AS server_distribution;

-- A) Required tables. Every row must be PRESENT and InnoDB (migrations is Laravel-owned).
SELECT
    expected.table_name,
    CASE WHEN actual.TABLE_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS verification_status,
    actual.ENGINE,
    actual.TABLE_COLLATION
FROM (
    SELECT 'migrations' AS table_name
    UNION ALL SELECT 'jobs' UNION ALL SELECT 'job_batches' UNION ALL SELECT 'failed_jobs'
    UNION ALL SELECT 'outbox_events' UNION ALL SELECT 'provider_capabilities'
    UNION ALL SELECT 'trip_requests' UNION ALL SELECT 'flight_search_jobs' UNION ALL SELECT 'travelers'
    UNION ALL SELECT 'trip_request_flight_selections'
    UNION ALL SELECT 'accommodation_search_jobs'
    UNION ALL SELECT 'trip_request_accommodation_selections'
    UNION ALL SELECT 'poi_discovery_jobs' UNION ALL SELECT 'pois'
    UNION ALL SELECT 'trips' UNION ALL SELECT 'plan_revisions'
    UNION ALL SELECT 'how_operation_idempotency'
    UNION ALL SELECT 'events' UNION ALL SELECT 'event_discovery_jobs'
) AS expected
LEFT JOIN information_schema.TABLES AS actual
  ON actual.TABLE_SCHEMA = DATABASE() AND actual.TABLE_NAME = expected.table_name
ORDER BY expected.table_name;

-- B) Required application columns. Zero rows means type/nullability match.
SELECT
    expected.table_name,
    expected.column_name,
    expected.expected_type,
    expected.expected_nullable,
    CASE
        WHEN actual.COLUMN_NAME IS NULL THEN 'MISSING'
        WHEN LOWER(actual.COLUMN_TYPE) <> LOWER(expected.expected_type) THEN 'TYPE_MISMATCH'
        WHEN actual.IS_NULLABLE <> expected.expected_nullable THEN 'NULLABILITY_MISMATCH'
        ELSE 'MATCH'
    END AS verification_status,
    actual.COLUMN_TYPE AS actual_type,
    actual.IS_NULLABLE AS actual_nullable
FROM (
    SELECT 'migrations' table_name, 'id' column_name, 'int unsigned' expected_type, 'NO' expected_nullable
    UNION ALL SELECT 'migrations','migration','varchar(255)','NO'
    UNION ALL SELECT 'migrations','batch','int','NO'

    UNION ALL SELECT 'jobs','id','bigint unsigned','NO'
    UNION ALL SELECT 'jobs','queue','varchar(255)','NO'
    UNION ALL SELECT 'jobs','payload','longtext','NO'
    UNION ALL SELECT 'jobs','attempts','tinyint unsigned','NO'
    UNION ALL SELECT 'jobs','reserved_at','int unsigned','YES'
    UNION ALL SELECT 'jobs','available_at','int unsigned','NO'
    UNION ALL SELECT 'jobs','created_at','int unsigned','NO'

    UNION ALL SELECT 'job_batches','id','varchar(255)','NO'
    UNION ALL SELECT 'job_batches','name','varchar(255)','NO'
    UNION ALL SELECT 'job_batches','total_jobs','int','NO'
    UNION ALL SELECT 'job_batches','pending_jobs','int','NO'
    UNION ALL SELECT 'job_batches','failed_jobs','int','NO'
    UNION ALL SELECT 'job_batches','failed_job_ids','longtext','NO'
    UNION ALL SELECT 'job_batches','options','mediumtext','YES'
    UNION ALL SELECT 'job_batches','cancelled_at','int','YES'
    UNION ALL SELECT 'job_batches','created_at','int','NO'
    UNION ALL SELECT 'job_batches','finished_at','int','YES'

    UNION ALL SELECT 'failed_jobs','id','bigint unsigned','NO'
    UNION ALL SELECT 'failed_jobs','uuid','varchar(255)','NO'
    UNION ALL SELECT 'failed_jobs','connection','text','NO'
    UNION ALL SELECT 'failed_jobs','queue','text','NO'
    UNION ALL SELECT 'failed_jobs','payload','longtext','NO'
    UNION ALL SELECT 'failed_jobs','exception','longtext','NO'
    UNION ALL SELECT 'failed_jobs','failed_at','timestamp','NO'

    UNION ALL SELECT 'outbox_events','trip_id','varchar(255)','NO'
    UNION ALL SELECT 'outbox_events','revision_id','varchar(255)','NO'
    UNION ALL SELECT 'outbox_events','sequence','bigint','YES'
    UNION ALL SELECT 'outbox_events','correlation_id','varchar(255)','YES'

    UNION ALL SELECT 'provider_capabilities','provider_id','varchar(255)','NO'
    UNION ALL SELECT 'provider_capabilities','operation','varchar(255)','NO'
    UNION ALL SELECT 'provider_capabilities','environment','varchar(255)','NO'
    UNION ALL SELECT 'provider_capabilities','supported','tinyint(1)','NO'
    UNION ALL SELECT 'provider_capabilities','status','varchar(255)','NO'
    UNION ALL SELECT 'provider_capabilities','evidence','text','YES'
    UNION ALL SELECT 'provider_capabilities','verified_at','timestamp','YES'

    UNION ALL SELECT 'trip_requests','id','bigint unsigned','NO'
    UNION ALL SELECT 'trip_requests','status','varchar(255)','NO'
    UNION ALL SELECT 'trip_requests','origin_place','json','YES'
    UNION ALL SELECT 'trip_requests','destination_place','json','YES'
    UNION ALL SELECT 'trip_requests','date_window','json','YES'
    UNION ALL SELECT 'trip_requests','journey_type','varchar(255)','YES'
    UNION ALL SELECT 'trip_requests','outbound_date','json','YES'
    UNION ALL SELECT 'trip_requests','return_date','json','YES'
    UNION ALL SELECT 'trip_requests','traveler_count','int unsigned','YES'
    UNION ALL SELECT 'trip_requests','passenger_composition','json','YES'
    UNION ALL SELECT 'trip_requests','room_count','int unsigned','YES'
    UNION ALL SELECT 'trip_requests','owner_issuer','varchar(255)','YES'
    UNION ALL SELECT 'trip_requests','owner_subject','varchar(255)','YES'
    UNION ALL SELECT 'trip_requests','preferences','json','YES'

    UNION ALL SELECT 'flight_search_jobs','search_id','char(36)','NO'
    UNION ALL SELECT 'flight_search_jobs','idempotency_key','varchar(255)','NO'
    UNION ALL SELECT 'flight_search_jobs','trip_request_id','varchar(255)','YES'
    UNION ALL SELECT 'flight_search_jobs','status','varchar(16)','NO'
    UNION ALL SELECT 'flight_search_jobs','result_json','json','YES'
    UNION ALL SELECT 'flight_search_jobs','error_code','varchar(255)','YES'
    UNION ALL SELECT 'flight_search_jobs','error_message_tr','text','YES'
    UNION ALL SELECT 'flight_search_jobs','expires_at','timestamp(6)','YES'
    UNION ALL SELECT 'flight_search_jobs','created_at','timestamp(6)','YES'
    UNION ALL SELECT 'flight_search_jobs','updated_at','timestamp(6)','YES'

    UNION ALL SELECT 'travelers','id','varchar(255)','NO'
    UNION ALL SELECT 'travelers','trip_id','varchar(255)','NO'
    UNION ALL SELECT 'travelers','age_category','varchar(255)','NO'
    UNION ALL SELECT 'travelers','nationalities','json','NO'
    UNION ALL SELECT 'travelers','travel_documents','json','NO'
    UNION ALL SELECT 'travelers','held_visas','json','NO'
    UNION ALL SELECT 'travelers','residence_permits','json','NO'

    UNION ALL SELECT 'trip_request_flight_selections','trip_request_id','varchar(255)','NO'
    UNION ALL SELECT 'trip_request_flight_selections','status','varchar(32)','NO'
    UNION ALL SELECT 'trip_request_flight_selections','offer_snapshot','longtext','NO'
    UNION ALL SELECT 'trip_request_flight_selections','provider_id','varchar(64)','NO'
    UNION ALL SELECT 'trip_request_flight_selections','provider_offer_id','varchar(255)','NO'
    UNION ALL SELECT 'trip_request_flight_selections','verified_price_minor_units','bigint','YES'
    UNION ALL SELECT 'trip_request_flight_selections','verified_price_currency','char(3)','YES'
    UNION ALL SELECT 'trip_request_flight_selections','verified_at','datetime(6)','NO'
    UNION ALL SELECT 'trip_request_flight_selections','expires_at','datetime(6)','NO'
    UNION ALL SELECT 'trip_request_flight_selections','created_at','datetime(6)','NO'
    UNION ALL SELECT 'trip_request_flight_selections','updated_at','datetime(6)','NO'

    UNION ALL SELECT 'accommodation_search_jobs','search_id','char(36)','NO'
    UNION ALL SELECT 'accommodation_search_jobs','idempotency_key','varchar(255)','NO'
    UNION ALL SELECT 'accommodation_search_jobs','trip_request_id','varchar(255)','NO'
    UNION ALL SELECT 'accommodation_search_jobs','status','varchar(16)','NO'
    UNION ALL SELECT 'accommodation_search_jobs','result_json','longtext','YES'
    UNION ALL SELECT 'accommodation_search_jobs','error_code','varchar(64)','YES'
    UNION ALL SELECT 'accommodation_search_jobs','error_message_tr','varchar(255)','YES'
    UNION ALL SELECT 'accommodation_search_jobs','expires_at','datetime(6)','YES'
    UNION ALL SELECT 'accommodation_search_jobs','created_at','datetime(6)','NO'
    UNION ALL SELECT 'accommodation_search_jobs','updated_at','datetime(6)','NO'

    UNION ALL SELECT 'trip_request_accommodation_selections','trip_request_id','varchar(255)','NO'
    UNION ALL SELECT 'trip_request_accommodation_selections','status','varchar(32)','NO'
    UNION ALL SELECT 'trip_request_accommodation_selections','offer_snapshot','longtext','NO'
    UNION ALL SELECT 'trip_request_accommodation_selections','provider_id','varchar(64)','NO'
    UNION ALL SELECT 'trip_request_accommodation_selections','provider_offer_id','varchar(255)','NO'
    UNION ALL SELECT 'trip_request_accommodation_selections','verified_price_minor_units','bigint','YES'
    UNION ALL SELECT 'trip_request_accommodation_selections','verified_price_currency','char(3)','YES'
    UNION ALL SELECT 'trip_request_accommodation_selections','verified_at','datetime(6)','NO'
    UNION ALL SELECT 'trip_request_accommodation_selections','expires_at','datetime(6)','NO'
    UNION ALL SELECT 'trip_request_accommodation_selections','created_at','datetime(6)','NO'
    UNION ALL SELECT 'trip_request_accommodation_selections','updated_at','datetime(6)','NO'

    UNION ALL SELECT 'poi_discovery_jobs','search_id','char(36)','NO'
    UNION ALL SELECT 'poi_discovery_jobs','idempotency_key','varchar(255)','NO'
    UNION ALL SELECT 'poi_discovery_jobs','trip_request_id','varchar(255)','NO'
    UNION ALL SELECT 'poi_discovery_jobs','status','varchar(16)','NO'
    UNION ALL SELECT 'poi_discovery_jobs','result_json','longtext','YES'
    UNION ALL SELECT 'poi_discovery_jobs','error_code','varchar(64)','YES'
    UNION ALL SELECT 'poi_discovery_jobs','error_message_tr','varchar(255)','YES'
    UNION ALL SELECT 'poi_discovery_jobs','expires_at','datetime(6)','YES'
    UNION ALL SELECT 'poi_discovery_jobs','created_at','datetime(6)','NO'
    UNION ALL SELECT 'poi_discovery_jobs','updated_at','datetime(6)','NO'

    UNION ALL SELECT 'pois','poi_id','char(36)','NO'
    UNION ALL SELECT 'pois','provider_id','varchar(64)','NO'
    UNION ALL SELECT 'pois','provider_place_id','varchar(64)','NO'
    UNION ALL SELECT 'pois','poi_json','longtext','NO'
    UNION ALL SELECT 'pois','created_at','datetime(6)','NO'
    UNION ALL SELECT 'pois','updated_at','datetime(6)','NO'

    UNION ALL SELECT 'trips','trip_id','char(36)','NO'
    UNION ALL SELECT 'trips','trip_request_id','varchar(255)','NO'
    UNION ALL SELECT 'trips','created_at','datetime(6)','NO'

    UNION ALL SELECT 'plan_revisions','trip_id','char(36)','NO'
    UNION ALL SELECT 'plan_revisions','version','int unsigned','NO'
    UNION ALL SELECT 'plan_revisions','status','varchar(16)','NO'
    UNION ALL SELECT 'plan_revisions','items_json','longtext','NO'
    UNION ALL SELECT 'plan_revisions','change_json','longtext','NO'
    UNION ALL SELECT 'plan_revisions','idempotency_key','varchar(255)','NO'
    UNION ALL SELECT 'plan_revisions','request_fingerprint','char(64)','NO'
    UNION ALL SELECT 'plan_revisions','created_at','datetime(6)','NO'

    UNION ALL SELECT 'how_operation_idempotency','scope_hash','char(64)','NO'
    UNION ALL SELECT 'how_operation_idempotency','principal_hash','char(64)','NO'
    UNION ALL SELECT 'how_operation_idempotency','operation','varchar(64)','NO'
    UNION ALL SELECT 'how_operation_idempotency','trip_request_id','varchar(255)','NO'
    UNION ALL SELECT 'how_operation_idempotency','item_id','varchar(255)','NO'
    UNION ALL SELECT 'how_operation_idempotency','base_revision_version','int unsigned','NO'
    UNION ALL SELECT 'how_operation_idempotency','idempotency_key','varchar(255)','NO'
    UNION ALL SELECT 'how_operation_idempotency','request_fingerprint','char(64)','NO'
    UNION ALL SELECT 'how_operation_idempotency','status','varchar(16)','NO'
    UNION ALL SELECT 'how_operation_idempotency','response_status','smallint unsigned','YES'
    UNION ALL SELECT 'how_operation_idempotency','response_json','longtext','YES'
    UNION ALL SELECT 'how_operation_idempotency','created_at','datetime(6)','NO'
    UNION ALL SELECT 'how_operation_idempotency','completed_at','datetime(6)','YES'
    UNION ALL SELECT 'events','event_id','char(36)','NO'
    UNION ALL SELECT 'events','provider_id','varchar(64)','NO'
    UNION ALL SELECT 'events','provider_event_id','varchar(128)','NO'
    UNION ALL SELECT 'events','event_json','longtext','NO'
    UNION ALL SELECT 'events','created_at','datetime(6)','NO'
    UNION ALL SELECT 'events','updated_at','datetime(6)','NO'
    UNION ALL SELECT 'event_discovery_jobs','search_id','char(36)','NO'
    UNION ALL SELECT 'event_discovery_jobs','idempotency_key','varchar(255)','NO'
    UNION ALL SELECT 'event_discovery_jobs','trip_request_id','varchar(255)','NO'
    UNION ALL SELECT 'event_discovery_jobs','status','varchar(16)','NO'
    UNION ALL SELECT 'event_discovery_jobs','result_json','longtext','YES'
    UNION ALL SELECT 'event_discovery_jobs','error_code','varchar(64)','YES'
    UNION ALL SELECT 'event_discovery_jobs','error_message_tr','varchar(255)','YES'
    UNION ALL SELECT 'event_discovery_jobs','expires_at','datetime(6)','YES'
    UNION ALL SELECT 'event_discovery_jobs','created_at','datetime(6)','NO'
    UNION ALL SELECT 'event_discovery_jobs','updated_at','datetime(6)','NO'
) AS expected
LEFT JOIN information_schema.COLUMNS AS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.table_name
 AND actual.COLUMN_NAME = expected.column_name
WHERE actual.COLUMN_NAME IS NULL
   OR LOWER(actual.COLUMN_TYPE) <> LOWER(expected.expected_type)
   OR actual.IS_NULLABLE <> expected.expected_nullable
ORDER BY expected.table_name, expected.column_name;

-- C) Required indexes. Zero rows means name, uniqueness and ordered columns match.
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
    SELECT 'jobs' table_name, 'PRIMARY' index_name, 0 expected_non_unique, 'id' expected_columns
    UNION ALL SELECT 'jobs','jobs_queue_index',1,'queue'
    UNION ALL SELECT 'job_batches','PRIMARY',0,'id'
    UNION ALL SELECT 'failed_jobs','PRIMARY',0,'id'
    UNION ALL SELECT 'failed_jobs','failed_jobs_uuid_unique',0,'uuid'
    UNION ALL SELECT 'outbox_events','PRIMARY',0,'trip_id,revision_id'
    UNION ALL SELECT 'provider_capabilities','PRIMARY',0,'provider_id,operation,environment'
    UNION ALL SELECT 'trip_requests','PRIMARY',0,'id'
    UNION ALL SELECT 'trip_requests','trip_requests_owner_issuer_owner_subject_index',1,'owner_issuer,owner_subject'
    UNION ALL SELECT 'flight_search_jobs','PRIMARY',0,'search_id'
    UNION ALL SELECT 'flight_search_jobs','flight_search_jobs_idempotency_key_unique',0,'idempotency_key'
    UNION ALL SELECT 'flight_search_jobs','flight_search_jobs_status_expires_at_index',1,'status,expires_at'
    UNION ALL SELECT 'travelers','PRIMARY',0,'id'
    UNION ALL SELECT 'travelers','travelers_trip_id_index',1,'trip_id'
    UNION ALL SELECT 'trip_request_flight_selections','PRIMARY',0,'trip_request_id'
    UNION ALL SELECT 'trip_request_flight_selections','trfs_provider_offer_status_idx',1,'provider_id,provider_offer_id,status'
    UNION ALL SELECT 'accommodation_search_jobs','PRIMARY',0,'search_id'
    UNION ALL SELECT 'accommodation_search_jobs','accommodation_search_jobs_idempotency_key_unique',0,'idempotency_key'
    UNION ALL SELECT 'accommodation_search_jobs','accommodation_search_jobs_trip_request_id_status_index',1,'trip_request_id,status'
    UNION ALL SELECT 'accommodation_search_jobs','accommodation_search_jobs_status_expires_at_index',1,'status,expires_at'
    UNION ALL SELECT 'trip_request_accommodation_selections','PRIMARY',0,'trip_request_id'
    UNION ALL SELECT 'trip_request_accommodation_selections','tras_provider_offer_status_idx',1,'provider_id,provider_offer_id,status'
    UNION ALL SELECT 'poi_discovery_jobs','PRIMARY',0,'search_id'
    UNION ALL SELECT 'poi_discovery_jobs','poi_discovery_jobs_idempotency_key_unique',0,'idempotency_key'
    UNION ALL SELECT 'poi_discovery_jobs','poi_discovery_jobs_trip_request_id_status_index',1,'trip_request_id,status'
    UNION ALL SELECT 'poi_discovery_jobs','poi_discovery_jobs_status_expires_at_index',1,'status,expires_at'
    UNION ALL SELECT 'pois','PRIMARY',0,'poi_id'
    UNION ALL SELECT 'pois','pois_provider_place_unique',0,'provider_id,provider_place_id'
    UNION ALL SELECT 'trips','PRIMARY',0,'trip_id'
    UNION ALL SELECT 'trips','trips_trip_request_id_unique',0,'trip_request_id'
    UNION ALL SELECT 'plan_revisions','PRIMARY',0,'trip_id,version'
    UNION ALL SELECT 'plan_revisions','plan_revisions_trip_id_idempotency_key_unique',0,'trip_id,idempotency_key'
    UNION ALL SELECT 'plan_revisions','plan_revisions_trip_id_status_index',1,'trip_id,status'
    UNION ALL SELECT 'how_operation_idempotency','PRIMARY',0,'scope_hash,idempotency_key'
    UNION ALL SELECT 'how_operation_idempotency','how_idem_trip_request_index',1,'trip_request_id'
    UNION ALL SELECT 'how_operation_idempotency','how_idem_status_created_index',1,'status,created_at'
    UNION ALL SELECT 'events','uq_events_provider',0,'provider_id,provider_event_id'
    UNION ALL SELECT 'event_discovery_jobs','uq_event_discovery_jobs_key',0,'idempotency_key'
    UNION ALL SELECT 'event_discovery_jobs','idx_event_discovery_jobs_trip',1,'trip_request_id'
) AS expected
LEFT JOIN (
    SELECT TABLE_NAME, INDEX_NAME, MIN(NON_UNIQUE) AS non_unique,
           GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS indexed_columns
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    GROUP BY TABLE_NAME, INDEX_NAME
) AS actual
  ON actual.TABLE_NAME = expected.table_name AND actual.INDEX_NAME = expected.index_name
WHERE actual.INDEX_NAME IS NULL
   OR actual.non_unique <> expected.expected_non_unique
   OR actual.indexed_columns <> expected.expected_columns
ORDER BY expected.table_name, expected.index_name;

-- D) Required FK and named CHECK constraints.
SELECT
    'plan_revisions_trip_fk' AS expected_constraint,
    CASE WHEN COUNT(*) = 1 THEN 'MATCH' ELSE 'MISSING_OR_MISMATCH' END AS verification_status
FROM information_schema.KEY_COLUMN_USAGE AS kcu
JOIN information_schema.REFERENTIAL_CONSTRAINTS AS rc
  ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
  AND kcu.TABLE_NAME = 'plan_revisions'
  AND kcu.CONSTRAINT_NAME = 'plan_revisions_trip_fk'
  AND kcu.COLUMN_NAME = 'trip_id'
  AND kcu.REFERENCED_TABLE_NAME = 'trips'
  AND kcu.REFERENCED_COLUMN_NAME = 'trip_id'
  AND rc.UPDATE_RULE = 'RESTRICT'
  AND rc.DELETE_RULE = 'RESTRICT';

SELECT expected.table_name, expected.constraint_name,
       CASE WHEN actual.CONSTRAINT_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT_REVIEW_DEFINITION' END AS verification_status
FROM (
    SELECT 'trip_requests' table_name, 'trip_requests_room_count_chk' constraint_name
    UNION ALL SELECT 'trip_requests','trip_requests_owner_pair_chk'
    UNION ALL SELECT 'trip_request_flight_selections','trfs_status_chk'
    UNION ALL SELECT 'trip_request_flight_selections','trfs_verified_price_pair_chk'
    UNION ALL SELECT 'accommodation_search_jobs','accommodation_search_jobs_status_chk'
    UNION ALL SELECT 'trip_request_accommodation_selections','tras_status_chk'
    UNION ALL SELECT 'trip_request_accommodation_selections','tras_verified_price_pair_chk'
    UNION ALL SELECT 'poi_discovery_jobs','poi_discovery_jobs_status_chk'
    UNION ALL SELECT 'plan_revisions','plan_revisions_status_chk'
    UNION ALL SELECT 'plan_revisions','plan_revisions_version_chk'
    UNION ALL SELECT 'how_operation_idempotency','how_idem_version_chk'
    UNION ALL SELECT 'how_operation_idempotency','how_idem_status_chk'
    UNION ALL SELECT 'how_operation_idempotency','how_idem_response_chk'
) AS expected
LEFT JOIN information_schema.TABLE_CONSTRAINTS AS actual
  ON actual.CONSTRAINT_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.table_name
 AND actual.CONSTRAINT_NAME = expected.constraint_name
 AND actual.CONSTRAINT_TYPE = 'CHECK'
WHERE actual.CONSTRAINT_NAME IS NULL
ORDER BY expected.table_name, expected.constraint_name;

-- E) Reference data is evidence, not an automatic seed. Review all rows; do not infer support from absence.
SELECT expected.migration,
       CASE WHEN actual.migration IS NULL THEN 'MISSING_TRACKING_RECORD' ELSE 'PRESENT' END AS tracking_status,
       actual.batch
FROM (
    SELECT '0001_01_01_000002_create_jobs_table' AS migration
    UNION ALL SELECT '2026_09_27_000000_create_outbox_events_table'
    UNION ALL SELECT '2026_09_27_000100_create_provider_capabilities_table'
    UNION ALL SELECT '2026_09_27_000200_create_trip_requests_table'
    UNION ALL SELECT '2026_09_27_000300_create_flight_search_jobs_table'
    UNION ALL SELECT '2026_09_27_000400_create_travelers_table'
    UNION ALL SELECT '2026_09_30_000000_add_trip_request_id_to_flight_search_jobs_table'
    UNION ALL SELECT '2026_10_01_000000_add_journey_dates_to_trip_requests_table'
    UNION ALL SELECT '2026_10_02_000000_add_passenger_composition_to_trip_requests_table'
    UNION ALL SELECT '2026_10_29_000000_create_active_feature_storage_tables'
    UNION ALL SELECT '2026_10_29_010000_create_evt_001_storage_tables'
) AS expected
LEFT JOIN migrations AS actual ON actual.migration = expected.migration
ORDER BY expected.migration;

SELECT provider_id, operation, environment, supported, status, evidence, verified_at
FROM provider_capabilities
ORDER BY provider_id, operation, environment;

SELECT 'VERIFY_COMPLETE' AS status,
       'A/B/C/D discrepancy result sets must be empty; table list must be PRESENT. Capability rows require human evidence review.' AS acceptance_rule;
