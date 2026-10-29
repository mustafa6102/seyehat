# Contract Version 3.19.0

- Inherits immutable v3.18.0 and earlier artifacts.
- Additive minor delta: two public schemas, one internal schema, two operations and three reason codes.
- Existing PlanRevision, DAY-001, idempotency/outbox and authorization semantics are not changed.
- HOW-001 remains partial: only POI plan items are supported; other story contexts are not silently inferred.
- Open OD/ADR records inherited from v3.18 remain open. Scoped reuse of a source resilience profile does not resolve ADR-006.
