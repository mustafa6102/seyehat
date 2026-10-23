# Contract Version 3.16.0

- Inherits immutable v3.15.0 and all earlier artifacts unless replaced in `registry/v3.16/contract_index.yaml`.
- Patch-level addition: Trip, PlanRevision, ItineraryItem (unscheduled FLEXIBLE POI subset) and TripPlan schemas; three plan operations; transition TRN-PLANREVISION-02; OD-021 and OD-027 resolved. No existing field, state, transition, operation or reason code is removed; TRN-PLANREVISION-01 keeps its meaning (idempotency key source resolved).
- Requirement clarifications only (IDs and acceptance intent preserved): FR-POI-001-04, AC-POI-001-06, TS-POI-001-06 aligned with OD-025 provider-aware evidence semantics.
- ADR-010 authentication portion resolved: generic OIDC issuer, Bearer JWT, principal SCH-PrincipalRef (iss, sub, INTERNAL), two config records. The v3.16 `bearerAuth` definition supersedes the v3.2 OPEN definition (same name, now concrete); no reason code added.
- PLT-AUTH-001 ownership rules (RULE-04..08, FR-02..03) are ACTIVE; no public schema exposes owner/principal identity.
- Plan operations are ACTIVE; shipping requires OP-createTripRequest owner binding (od_impact ADR-010). Day/time assignment and time feasibility remain out of scope (DAY-001).
