# Contract Version 3.12.0

- Inherits immutable v3.11.0 and all earlier artifacts unless replaced in `registry/v3.12/contract_index.yaml`.
- Adds SCH-PassengerComposition and replaces SCH-TripRequest and SCH-FlightSearchCriteria.
- The new field is optional; existing count-only records retain their meaning and require no backfill.
- OD-020 and OD-021 remain OPEN.
