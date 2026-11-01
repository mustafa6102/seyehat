-- AI Seyahat Planlayicisi v3.23 production corrective SQL
-- STATUS: NOT READY - DO NOT EXECUTE AS A MIGRATION.
-- Production schema evidence is not available. No DDL/DML is authorized by this file yet.
-- This file intentionally contains no CREATE/ALTER/INSERT/UPDATE/DELETE statement.
-- MySQL DDL implicitly commits and CREATE TABLE IF NOT EXISTS cannot repair partial tables.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT
    'NOT_READY' AS execution_status,
    'Once production-schema-diagnostics-v3.23.sql ciktisi incelenmeli.' AS reason;

-- Ordered candidate groups after diagnostics (not executable instructions):
-- 01 Laravel runtime: jobs, job_batches, failed_jobs (only when QUEUE_CONNECTION=database).
-- 02 Shared foundation: outbox_events, provider_capabilities.
-- 03 TripRequest base and additive journey/passenger/owner/room columns.
-- 04 Flight search jobs and trip_request_id; flight selection.
-- 05 ACC-001 search jobs and accommodation selection.
-- 06 POI discovery and canonical pois.
-- 07 Trip/PlanRevision storage and the approved PlanRevision -> Trip FK.
-- 08 HOW operation idempotency ledger.
-- Cache and session are file-backed in the shipped production example; no cache/sessions table is authorized.
-- FlightOffer/reprice are cache-backed; ground presentation and localization add no production table.
-- Traveler relations/encryption/retention and incompatible identifier FKs remain blocked; no repair is invented.
-- Provider capability rows require real deployment evidence; no supported=1 seed is included.

-- Promotion rule:
-- Replace this NOT_READY file with reviewed additive DDL only after every diagnostic MISSING/MISMATCH
-- has been classified against the migration and contract. A partially present table requires explicit,
-- column-by-column repair; it must never be hidden by CREATE TABLE IF NOT EXISTS.
