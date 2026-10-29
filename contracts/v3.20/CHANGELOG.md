# v3.20.0

- OD-030 RESOLVED by user approval: Option A, user-provided visit duration.
- `SCH-ItineraryItem` gains nullable `planning_duration`; older revisions read it as `null` without rewriting stored JSON.
- `SCH-PlanRevision.change` gains `updated_item_ids`; older revisions read it as an empty list.
- New operation: `OP-setTripPlanItemDuration`, owner-only and revision-creating with `If-Match` and `Idempotency-Key`.
- No start/end time, transit, buffer, opening-hours or feasibility behavior was added.
