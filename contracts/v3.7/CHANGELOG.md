# v3.7.0

- DATE-only delta for OD-022. `SCH-FlightSearchCriteria` v3.7 replaces v3.5: `date_window` is no longer an opaque copy but an explicit `EXACT {date}` or `FLEXIBLE {start_date, end_date}` object carrying the TripRequest values unchanged (RULE-TRIP-001-02). No date is selected from a range and exact/flexible are never converted into each other.
- Not defined and not added: departure/return meaning, return date, one-way/round-trip, inclusive/exclusive bounds, date timezone. SCH-TripRequest and LOCATION semantics are unchanged.
- A TripRequest date that is not representable in this form, or a range whose end precedes its start, yields no criteria; the job fails before any provider call (GAP-FLT-CRITERIA-001).
- OD-022 remains PARTIALLY RESOLVED (LOCATION BLOCKED; DATE partial; PASSENGER open). v3.2-v3.6 artifacts are unchanged.
