# v3.5.0

- Added internal `SCH-FlightSearchCriteria`: the untransformed copy of a SEARCHABLE TripRequest's `origin_place`, `destination_place`, `date_window` and `traveler_count` plus `trip_request_id`. `preferences` are not flight criteria. Fields reference SCH-LocationRef and SCH-TripRequest; no field is redefined.
- The provider adapter boundary receives this criteria object; criteria are derived server-side from the stored TripRequest, never resent by clients.
- FlightSearchJob lifecycle is unchanged; a job whose TripRequest cannot yield criteria fails through the existing FAILED transition without calling a provider.
- OD-022 is PARTIALLY RESOLVED; aviation identifiers, location-kind eligibility, date direction/flexibility, passenger types, cabin and trip type remain OPEN. Missing reason/error code recorded as GAP-FLT-CRITERIA-001.
- No OpenAPI operation changes. v3.2, v3.3 and v3.4 artifacts are unchanged.
