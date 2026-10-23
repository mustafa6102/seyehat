# Contract Versioning v3.9

- Baseline: `3.9.0`.
- Inherits immutable v3.8.0 (and through it v3.7.0 - v3.2.2) artifacts unless a v3.9 replacement is listed in `registry/v3.9/contract_index.yaml`.
- v3.2-v3.8 artifacts are not modified in place.
- Scope is OD-022 JOURNEY + DATE only. PRE-RELEASE BREAKING for the writable TripRequest date model; `date_window` is kept as read-only legacy data.
- OD-022 remains PARTIALLY RESOLVED; OD-020, OD-021 and ADR-006 remain OPEN.
