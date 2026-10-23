# v3.6.0

- Registry-only delta for the OD-022 location portion; no schema or OpenAPI change.
- Verified: SCH-LocationRef has no aviation identity field, FLT requirements define none, and the GeoNames catalog provides no authoritative aviation code to the current adapter. SCH-LocationRef is therefore not changed and no aviation identity shape is invented.
- Recorded: `station` LocationRef is not an M1A flight-search input (multimodal search is GND-001, M1C); TripRequest station support is unchanged.
- FlightSearchJob: a job whose criteria location cannot be represented for a flight provider fails via the existing FAILED transition without a provider call (GAP-FLT-CRITERIA-001).
- OD-022 remains PARTIALLY RESOLVED. v3.2-v3.5 artifacts are unchanged.
