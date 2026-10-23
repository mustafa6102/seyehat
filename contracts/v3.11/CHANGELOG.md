# v3.11.0

- Registry-only delta. No schema or OpenAPI change.
- Records Duffel Flights (`duffel_flights`) as the first concrete FlightProviderAdapter, added on the product owner's instruction (2026-09-29). OD-001 is NOT resolved: it stays OPEN.
- PLT-REL-001 / NFR-RL-001: provider/operation-scoped, versioned deploy-time profile `duffel-flights-ops-2026-09-29` (CFG-RETRY-TIMEOUT-CIRCUIT scope provider/operation). ADR-006 stays OPEN.
- The adapter is opt-in: it needs a credential and DUFFEL_FLIGHTS_PASSENGER_POLICY=ALL_ADULT (interim deploy-time decision; OD-020 stays OPEN). ONE_WAY + EXACT only; ROUND_TRIP, FLEXIBLE dates and cabin class are not sent (OD-022 remaining_open).
- Contract gap: canonical FlightOffer has no slice/direction structure, so ROUND_TRIP results cannot be represented.
- Capability evidence: only SEARCH for `duffel_flights`; OD-008 stays OPEN. v3.2-v3.10 artifacts are unchanged.
