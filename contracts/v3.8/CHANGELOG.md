# v3.8.0

- OD-022 LOCATION delta. New `SCH-AviationIdentity` v3.8: provider-independent `{type: IATA_AIRPORT | IATA_CITY, code: [A-Z]{3}}`. Airport and city codes are distinct identities.
- `SCH-LocationRef` v3.8 replaces v3.2: optional, server-assigned (`readOnly`) `aviation_identity`; the catalog identity (`value`) stays separate. `kind airport` may carry only `IATA_AIRPORT`, `kind city` only `IATA_CITY`; other kinds carry none.
- `SCH-FlightSearchCriteria` v3.8 replaces v3.7: places reference `SCH-LocationRef` v3.8. Flight provider calls require airport + IATA_AIRPORT or city + IATA_CITY; station, missing, invalid or mismatched identities are blocked before any adapter. No city-to-airport expansion in the system. Date semantics unchanged from v3.7.
- Source boundary: GeoNames = general location discovery (no aviation identity, no GeoNames -> IATA mapping); Duffel Places = flight location discovery. Provider resource ids are never aviation identities.
- Public `/api/v1` `SCH-TripRequest` (v3.2) and the OpenAPI document are unchanged: the create request does not accept `aviation_identity` and responses do not include it.
- OD-022 remains PARTIALLY RESOLVED (DATE and PASSENGER open). ADR-006 values for `duffel_places` are not defined. v3.2-v3.7 artifacts are unchanged.
