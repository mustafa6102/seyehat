# Contract Versioning v3.8

- Baseline: `3.8.0`.
- Inherits immutable v3.7.0 (and through it v3.6.0 - v3.2.2) artifacts unless a v3.8 replacement is listed in `registry/v3.8/contract_index.yaml`.
- v3.2-v3.7 artifacts are not modified in place.
- Scope is OD-022 LOCATION only (aviation identity, LocationRef, FlightSearchCriteria references). Public SCH-TripRequest and OpenAPI are unchanged.
- OD-022 remains PARTIALLY RESOLVED; OD-020, OD-021 and ADR-006 remain OPEN.
