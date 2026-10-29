# PRC-004 implementation-readiness checklist

## Independently implementable after this proposal is activated

- [ ] Owner-only read operation using existing TripRequest ownership checks.
- [ ] 401/403/404 evaluation order and API/web read channels.
- [ ] Fail-closed `CONFIGURATION_UNRESOLVED` response when any required policy version is absent.
- [ ] Turkish states:
  - `CONFIGURATION_UNRESOLVED`: “Fiyat geçmişi değerlendirmesi henüz yapılandırılmadı.”
  - `INSUFFICIENT_DATA`: “Bu teklif için karşılaştırılabilir geçmiş fiyat verisi yetersiz.”
  - `AVAILABLE`: snapshot count, date range and sources only; no target/recommended/guaranteed price.
- [ ] No provider call, notification, PlanRevision, outbox or mutation from GET.

These items are contract-ready proposals, not implementation authorization while v3.19 remains active.

## Product decisions still required

### Sufficiency

Exact values still required:

- `minimum_snapshot_count`: one explicitly approved positive integer.
- `minimum_observation_span_value`: one explicitly approved positive quantity, if time coverage is required.
- `minimum_observation_span_unit`: explicit unit paired with the span value.

Bounded structural alternatives supported by the proposal:

1. One global config for both product types.
2. One config per product type.
3. A versioned cohort/provider matrix.

No repository evidence supports choosing among them or supplying a number. Missing config remains fail-closed.

### Outlier

Exact values still required:

- approved deterministic method name and definition;
- every parameter name, numeric value and unit used by that method;
- behavior for insufficient observations for the selected method;
- policy owner and version.

Bounded alternatives supported by current evidence:

1. Product/Data supplies a deterministic method and all parameters.
2. No outlier evaluation until such a policy exists.

Current canonical schemas contain no provider outlier flag, so provider-declared outlier behavior is not presently supported.

## Blocked requirements

- `FR-PRC-004-01`: ADR-009 is ACCEPTED as MySQL 8; persistence still awaits OD-013, approved snapshot schema and manual-schema design.
- `AC-PRC-004-02` / `TS-PRC-004-02`: blocked by canonical cohort mappings and exact sufficiency values.
- `AC-PRC-004-03` / `TS-PRC-004-03`: blocked by an approved outlier method and parameters.
- `NFR-AUD-001`: full audit cannot be completed until policy/config versions exist.

## Contract activation checklist

- [ ] Product owner approves exact canonical field mappings for flight and/or accommodation.
- [ ] Product/Data supplies sufficiency values and version.
- [ ] Product/Data supplies outlier method, parameters and version.
- [x] ADR-009 is inherited as ACCEPTED / MySQL 8 without changing it.
- [ ] OD-013 implications are explicitly approved without silent resolution.
- [ ] `OD-006` remains notification-only or its M2-wide impact is explicitly clarified in the active registry.
- [ ] Proposed schemas and OpenAPI pass JSON/OpenAPI validation.
- [ ] A new contract version activates operation, schemas, permissions, lifecycle, reasons and traceability together.

Production MySQL 2002 remains a deployment-only verification blocker, not a PRC-004 product requirement.
