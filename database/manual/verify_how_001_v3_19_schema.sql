-- Read-only verification for HOW-001 v3.19. Expected result: every row reports MATCH.

SELECT
    'table' AS check_type,
    'how_operation_idempotency' AS target,
    CASE WHEN COUNT(*) = 1 THEN 'MATCH' ELSE 'MISSING' END AS result
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'how_operation_idempotency'
UNION ALL
SELECT
    'column', expected.column_name,
    CASE
        WHEN actual.COLUMN_NAME IS NULL THEN 'MISSING'
        WHEN LOWER(actual.COLUMN_TYPE) <> expected.column_type OR actual.IS_NULLABLE <> expected.is_nullable THEN 'TYPE_OR_NULLABILITY_MISMATCH'
        ELSE 'MATCH'
    END
FROM (
    SELECT 'scope_hash' column_name, 'char(64)' column_type, 'NO' is_nullable
    UNION ALL SELECT 'principal_hash', 'char(64)', 'NO'
    UNION ALL SELECT 'operation', 'varchar(64)', 'NO'
    UNION ALL SELECT 'trip_request_id', 'varchar(255)', 'NO'
    UNION ALL SELECT 'item_id', 'varchar(255)', 'NO'
    UNION ALL SELECT 'base_revision_version', 'int unsigned', 'NO'
    UNION ALL SELECT 'idempotency_key', 'varchar(255)', 'NO'
    UNION ALL SELECT 'request_fingerprint', 'char(64)', 'NO'
    UNION ALL SELECT 'status', 'varchar(16)', 'NO'
    UNION ALL SELECT 'response_status', 'smallint unsigned', 'YES'
    UNION ALL SELECT 'response_json', 'longtext', 'YES'
    UNION ALL SELECT 'created_at', 'datetime(6)', 'NO'
    UNION ALL SELECT 'completed_at', 'datetime(6)', 'YES'
) expected
LEFT JOIN information_schema.COLUMNS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = 'how_operation_idempotency'
 AND actual.COLUMN_NAME = expected.column_name
UNION ALL
SELECT
    'index', expected.index_name,
    CASE WHEN actual.INDEX_NAME IS NULL THEN 'MISSING' ELSE 'MATCH' END
FROM (
    SELECT 'PRIMARY' index_name
    UNION ALL SELECT 'how_idem_trip_request_index'
    UNION ALL SELECT 'how_idem_status_created_index'
) expected
LEFT JOIN information_schema.STATISTICS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = 'how_operation_idempotency'
 AND actual.INDEX_NAME = expected.index_name
GROUP BY expected.index_name, actual.INDEX_NAME;
