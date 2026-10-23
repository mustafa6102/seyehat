# Contract Versioning v3.4

- Baseline: `3.4.0`.
- Inherits immutable v3.3.0 (and through it v3.2.2) artifacts unless a v3.4 replacement is listed in `registry/v3.4/contract_index.yaml`.
- v3.2 and v3.3 artifacts are not modified or superseded in place; replaced contracts carry `x-replaces`.
- Requirement IDs and meanings are unchanged; v3.4 only adds contract surface for existing TRIP-001 / FLT-002 requirements.
- `OP-startFlightSearch` body is a PRE-RELEASE BREAKING change (see CHANGELOG).
- OD-020, OD-021 and OD-022 are OPEN; no product decision is taken by this delta.
