# Flight Passenger Composition Design

## Goal

Allow flight pricing searches to carry an optional passenger composition without creating Traveler records or defining OD-020/OD-021 Traveler semantics.

## Decision

`TripRequest` may carry optional `passenger_composition` with non-negative integer `adults`, `children`, and `infants`. When present, `traveler_count` must equal their sum and the sum must be at least one. The composition is flight-search/pricing input only.

Legacy TripRequests without composition retain their existing meaning. They are not assigned a composition. The explicit `DUFFEL_FLIGHTS_PASSENGER_POLICY=ALL_ADULT` setting remains the only legacy count-only Duffel compatibility path.

## Provider Boundary

New all-adult compositions map to Duffel passenger entries with `type: adult`. Child and infant counts do not include the exact ages Duffel needs for under-18 searches, so they fail before provider HTTP with a canonical Turkish message. No age, DOB, identity, or document value is fabricated.

## Contract

Version 3.12 adds `SCH-PassengerComposition` and replaces `SCH-TripRequest` and `SCH-FlightSearchCriteria`. Earlier versions remain immutable. OD-020 and OD-021 stay OPEN, with registry notes separating search composition from Traveler semantics.

## Compatibility

The database column is nullable JSON. Existing rows remain null. Public and internal representations omit no existing fields; new composition is nullable/optional. Provider capability and resilience behavior remain unchanged.

## Validation

UI and domain validation reject negative/non-integer counts, zero total, and a total mismatch using Turkish text. Unsupported child/infant criteria produce zero provider calls and do not consume retry or circuit-breaker budget.
