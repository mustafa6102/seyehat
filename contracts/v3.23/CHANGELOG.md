# v3.23.0

- OD-022 ROUND_TRIP sub-decision approved by the project owner (2026-10-29, D1–D4). OD-022 stays `PARTIALLY_RESOLVED`; FLEXIBLE dates, cabin class, segment location and passenger types (OD-020) remain open.
- D1: `SCH-FlightOffer` 3.23 adds required `journey_type` (`ONE_WAY`/`ROUND_TRIP`, from provider slice count) and required segment `direction` (`OUTBOUND`/`INBOUND`, from slice order). `segments` stays one contiguous ordered array; all other fields are unchanged.
- D2: v3.4-shaped persisted offers are normalized on read to `ONE_WAY`/`OUTBOUND`; no SQL migration.
- D3: a reprice whose segment/direction list differs from the stored snapshot is `UNVERIFIED` (`RC-EVIDENCE-REPRICE-UNVERIFIED`); the existing selection is preserved. Applies to one-way offers as well.
- D4: 19 requirement records added (`registry/v3.23/story_overrides.json`); provider offers with mismatching slice count/order/endpoints are dropped.
- `SCH-FlightSearchResult`, `SCH-FlightSearchJob`, `SCH-OfferSnapshot`, `SCH-RepriceResult` and `SCH-TripRequestFlightSelection` are re-versioned only to reference v3.23 FlightOffer; their semantics are unchanged.
- OpenAPI: `OP-pollFlightSearch`, `OP-getOffer`, `OP-repriceOffer`, `OP-getTripRequestFlightSelection`, `OP-selectTripRequestFlight`, `OP-reconfirmTripRequestFlight` reference the v3.23 schemas. No new operation, parameter, status code or reason code. `OP-createPartnerRedirect` (PROVISIONAL, OD-015) is unchanged.
- Registry corrections: OD-022 is now listed explicitly (v3.22 `decisions.json` omitted it); `blocked_features.RCG-001` OD-022 wording corrected.
- No booking, ticketing or payment surface is added. Application code is not changed by this version (`traceability.json` → `implementation_gap`).
