-- Read-only verification for Laravel database queue runtime tables.
-- Expected result: every table PRESENT and every column MATCH.

SELECT
    DATABASE() AS selected_database,
    VERSION() AS server_version;

SELECT
    expected.table_name,
    CASE WHEN actual.TABLE_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS verification_status,
    actual.ENGINE
FROM (
    SELECT 'jobs' AS table_name
    UNION ALL SELECT 'job_batches'
    UNION ALL SELECT 'failed_jobs'
) AS expected
LEFT JOIN information_schema.TABLES AS actual
    ON actual.TABLE_SCHEMA = DATABASE()
   AND actual.TABLE_NAME = expected.table_name
ORDER BY expected.table_name;

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
    SELECT 'jobs' table_name, 'id' column_name, 'bigint unsigned' expected_type, 'NO' expected_nullable
    UNION ALL SELECT 'jobs', 'queue', 'varchar(255)', 'NO'
    UNION ALL SELECT 'jobs', 'payload', 'longtext', 'NO'
    UNION ALL SELECT 'jobs', 'attempts', 'tinyint unsigned', 'NO'
    UNION ALL SELECT 'jobs', 'reserved_at', 'int unsigned', 'YES'
    UNION ALL SELECT 'jobs', 'available_at', 'int unsigned', 'NO'
    UNION ALL SELECT 'jobs', 'created_at', 'int unsigned', 'NO'
    UNION ALL SELECT 'job_batches', 'id', 'varchar(255)', 'NO'
    UNION ALL SELECT 'job_batches', 'name', 'varchar(255)', 'NO'
    UNION ALL SELECT 'job_batches', 'total_jobs', 'int', 'NO'
    UNION ALL SELECT 'job_batches', 'pending_jobs', 'int', 'NO'
    UNION ALL SELECT 'job_batches', 'failed_jobs', 'int', 'NO'
    UNION ALL SELECT 'job_batches', 'failed_job_ids', 'longtext', 'NO'
    UNION ALL SELECT 'job_batches', 'options', 'mediumtext', 'YES'
    UNION ALL SELECT 'job_batches', 'cancelled_at', 'int', 'YES'
    UNION ALL SELECT 'job_batches', 'created_at', 'int', 'NO'
    UNION ALL SELECT 'job_batches', 'finished_at', 'int', 'YES'
    UNION ALL SELECT 'failed_jobs', 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'failed_jobs', 'uuid', 'varchar(255)', 'NO'
    UNION ALL SELECT 'failed_jobs', 'connection', 'text', 'NO'
    UNION ALL SELECT 'failed_jobs', 'queue', 'text', 'NO'
    UNION ALL SELECT 'failed_jobs', 'payload', 'longtext', 'NO'
    UNION ALL SELECT 'failed_jobs', 'exception', 'longtext', 'NO'
    UNION ALL SELECT 'failed_jobs', 'failed_at', 'timestamp', 'NO'
) AS expected
LEFT JOIN information_schema.COLUMNS AS actual
    ON actual.TABLE_SCHEMA = DATABASE()
   AND actual.TABLE_NAME = expected.table_name
   AND actual.COLUMN_NAME = expected.column_name
ORDER BY expected.table_name, expected.column_name;

SELECT
    expected.table_name,
    expected.index_name,
    CASE WHEN actual.INDEX_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS verification_status
FROM (
    SELECT 'jobs' table_name, 'PRIMARY' index_name
    UNION ALL SELECT 'jobs', 'jobs_queue_index'
    UNION ALL SELECT 'job_batches', 'PRIMARY'
    UNION ALL SELECT 'failed_jobs', 'PRIMARY'
    UNION ALL SELECT 'failed_jobs', 'failed_jobs_uuid_unique'
) AS expected
LEFT JOIN information_schema.STATISTICS AS actual
    ON actual.TABLE_SCHEMA = DATABASE()
   AND actual.TABLE_NAME = expected.table_name
   AND actual.INDEX_NAME = expected.index_name
GROUP BY expected.table_name, expected.index_name, actual.INDEX_NAME
ORDER BY expected.table_name, expected.index_name;
