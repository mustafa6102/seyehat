# v3.3.0

- Recorded ADR-009 as ACCEPTED: MySQL 8 only.
- Recorded current client scope as Laravel 12 web/API; ADR-007 remains OPEN and non-blocking.
- Moved `RANK-001` phase metadata to M1B without changing requirement semantics or IDs.
- Added versioned TRV, DerivedMoney, baggage/fee, reprice and provider-capability contract surfaces.
- Kept OD-001, OD-008, OD-012, OD-013, OD-015 and ADR-010 OPEN.
- Recorded ADR-011 as ACCEPTED: asynchronous job + polling; added FlightSearchJob lifecycle and start/poll response envelopes.
- Start request body remains absent because v3.2 does not define canonical search criteria; Idempotency-Key is the existing request input.
- Polling interval and job expiry TTL remain deployment configuration rather than invented contract constants.
- Added no provider-specific behavior and no production provider evidence.
