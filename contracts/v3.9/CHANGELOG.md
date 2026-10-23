# v3.9.0

- OD-022 JOURNEY + DATE delta from the approved product decision (user, 2026-09-29).
- New `SCH-TravelDate` v3.9: one direction's date, `EXACT {date}` or `FLEXIBLE {start_date, end_date}` with the unchanged v3.7 meaning. FLEXIBLE bounds are inclusive; values are calendar dates without timezone semantics; `earliest`/`latest` are defined for ordering.
- `SCH-TripRequest` v3.9 replaces v3.2 (PRE-RELEASE BREAKING): writable `journey_type` (`ONE_WAY | ROUND_TRIP`), `outbound_date`, `return_date`. ONE_WAY forbids `return_date`; ROUND_TRIP requires it; `latest(return_date) < earliest(outbound_date)` is invalid. `journey_type` is never inferred from dates.
- Legacy: `date_window` becomes `readOnly` + `deprecated`. It is no longer writable; pre-v3.9 records keep it unchanged with null journey fields, are not reinterpreted as outbound or trip span, and cannot produce flight criteria.
- `SCH-FlightSearchCriteria` v3.9 replaces v3.8: `date_window` is replaced by `journey_type`, `outbound_date` and (ROUND_TRIP only) `return_date`, carried unchanged. Location semantics unchanged from v3.8.
- OpenAPI delta: `OP-createTripRequest` / `OP-getTripRequest` reference SCH-TripRequest v3.9. Error codes reused (`SCHEMA_VALIDATION_FAILED`, `TRIP_REQUEST_INCOMPLETE`); GAP-FLT-CRITERIA-001 remains for jobs that cannot produce criteria.
- TRIP-001 additions: RULE-TRIP-001-04, RULE-TRIP-001-05, FR-TRIP-001-03, AC-TRIP-001-03, TS-TRIP-001-05..07 (`registry/v3.9/story_overrides.yaml`).
- OD-022 remains PARTIALLY RESOLVED (LOCATION runtime profile and PASSENGER open). v3.2-v3.8 artifacts are unchanged.
