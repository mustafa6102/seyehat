# PRC-004 v3.20 non-normative contract proposal

Status: **PROPOSED / NON-NORMATIVE / NOT ACTIVE**  
Inherits conceptually from active v3.19; no active contract or registry file is changed.

Approved scope: exact canonical cohort matching, owner-only read-only API/UI, fail-closed unresolved configuration, no notification, target price, fabricated history or provider assumption.

Artifacts:

- `schemas/PriceSnapshot.schema.json`: append-only observed price evidence using supported canonical fields only.
- `schemas/PriceHistoryEvaluation.schema.json`: read-only evaluation result; no target/recommended price.
- `openapi/m1a.openapi.yaml`: proposed owner-scoped GET operation.
- `registries/*.yaml`: proposed permissions, lifecycle, reason/error, config and traceability records.
- `field-mapping.md`: current flight/accommodation field evidence and mapping gaps.
- `persistence-alternatives.md`: ADR-009-sensitive storage alternatives and future logical columns.
- `implementation-readiness.md`: independently implementable and blocked scope.

This proposal does not make `AC-PRC-004-02/03` implementation-ready. Exact numerical sufficiency values and the outlier method/parameters remain product decisions.
