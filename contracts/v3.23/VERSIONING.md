# Contract Version 3.23.0

- Inherits immutable v3.22.0 and all earlier artifacts unless replaced in `registry/v3.23/contract_index.json`.
- Patch-level addition (`contracts/VERSIONING.md`): two required response fields on FlightOffer and new requirement records. No existing field, state, transition, operation or requirement text is removed or changed incompatibly. Precedent: v3.4 added `bookable`/`sources`/`segments` to FlightOffer under the same classification.
- Stored v3.4-shaped offer snapshots stay readable through the FlightOffer `x-legacy-read` rule (D2).
- OD-022 remains `PARTIALLY_RESOLVED`. Inherited OPEN OD/ADR records are unchanged.
