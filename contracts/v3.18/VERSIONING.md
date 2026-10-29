# Contract Version 3.18.0

- Inherits immutable v3.17.0 (and through it v3.16.0 - v3.2.2) artifacts unless replaced in `registry/v3.18/contract_index.yaml`.
- Minor addition: SCH-ItineraryItem, SCH-PlanRevision and SCH-TripPlan v3.18 replace their v3.16 versions with additive required fields (`day_assignment`, `change.moved_item_ids`, `day_frame`); stored v3.16 revisions are read as unassigned with an empty `moved_item_ids`. One operation (OP-assignTripPlanItemDay) and two reason codes added.
- Only OD-029 is resolved. OD-030..035 are recorded OPEN. No existing field, state, transition, operation or reason code is removed.
- v3.2-v3.17 artifacts are not modified in place.
