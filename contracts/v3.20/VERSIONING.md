# Contract Version 3.20.0

- Inherits immutable v3.19.0 and earlier artifacts.
- Additive DAY-001 delta: OD-030 Option A adds a user-provided visit duration to POI plan items and one owner-only mutation operation.
- User-provided duration is always `USER_PROVIDED` / `UNVERIFIED`; it is not provider evidence and does not imply a time window, opening-hours validation or feasibility.
- Existing PlanRevision immutability, optimistic concurrency, idempotency, authorization and transactional outbox rules are unchanged.
- OD-031..035 and inherited OD/ADR records remain open.
