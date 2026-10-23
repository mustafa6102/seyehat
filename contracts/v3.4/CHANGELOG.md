# v3.4.0

- Added `SCH-FlightSearchStartRequest` and a required request body for `OP-startFlightSearch`: a search references one existing, SEARCHABLE `TripRequest` via `trip_request_id`. Search input is read server-side from the stored TripRequest; clients do not resend criteria.
- PRE-RELEASE BREAKING: `OP-startFlightSearch` previously accepted no body. No external consumer or production flight search exists (OD-001 OPEN), so the contract-versioning pre-release exception applies. Closes the ADR-011 remaining gap "canonical search request body" for the TripRequest association only.
- `SCH-FlightSearchJob` v3.4 adds `trip_request_id` (null only for jobs created before v3.4; no backfill is invented). Lifecycle, idempotency, partial snapshot, error and expiry semantics are unchanged. Reusing an Idempotency-Key with a different `trip_request_id` returns `RC-CONFLICT-IDEMPOTENCY-KEY`.
- `SCH-FlightOffer` v3.4 exposes `bookable`, `sources` and `segments` required by FR-FLT-002-01, FR-FLT-002-03, RULE-FLT-002-03/04/05 and AC-FLT-002-01/02/03/04/06, reusing the registry enums ProviderRole, DistributionChannel and CarrierModel and the Section 6.4 time representation.
- `SCH-FlightSearchResult`, `SCH-OfferSnapshot` and `SCH-RepriceResult` are re-versioned only to reference v3.4 FlightOffer; their semantics are unchanged.
- Recorded new OPEN decisions: OD-020 (Traveler age_category value set), OD-021 (Trip vs TripRequest relationship / Traveler.trip_id target), OD-022 (TripRequest -> provider search criteria mapping, segment endpoint locations).
- No new reason/error semantics. v3.2 and v3.3 artifacts are unchanged.
