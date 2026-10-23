# Contract Versioning v3.3

- Baseline: `3.3.0`.
- Inherits immutable v3.2.2 artifacts unless a v3.3 replacement is listed in `registry/v3.3/contract_index.yaml`.
- v3.2 artifacts are not modified or superseded in place.
- Replaced contracts carry `x-replaces`; unchanged requirement IDs retain their original meaning.
- `RANK-001` changes phase metadata from M1A to M1B only; RULE/FR/AC/TS semantics and IDs are unchanged.
- `OP-startFlightSearch` and `OP-pollFlightSearch` remain PROVISIONAL while ADR-011 is OPEN.
- `OP-createPartnerRedirect` remains PROVISIONAL while OD-015 is OPEN; redirect URL/token lifecycle is intentionally absent.
- ADR-009 is ACCEPTED as MySQL 8 for the Laravel 12 web/API OLTP scope.
- ADR-007 remains OPEN and non-blocking while no mobile client is in scope.
