# v3.12.0

- Added optional search/pricing-only `SCH-PassengerComposition` with `adults`, `children`, and `infants`.
- Replaced SCH-TripRequest and SCH-FlightSearchCriteria to carry the optional composition. When present, its sum equals `traveler_count`; legacy count-only records remain unchanged.
- OD-020 and OD-021 remain OPEN. The composition is not Traveler identity/profile/category data.
- Duffel maps explicit all-adult compositions. Child/infant compositions without exact ages fail before provider HTTP.
- v3.2-v3.11 artifacts are unchanged.
