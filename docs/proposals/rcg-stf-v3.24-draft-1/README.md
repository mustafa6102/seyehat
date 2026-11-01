# RCG/STF v3.24 draft 1

Status: **PROPOSED / INACTIVE**. This package is not part of `registry/active_contract.json` and does not authorize provider calls or persistence.

Authoritative proposal artifact: `openapi.json`, with payload definitions in `schemas.json`. The consumer is the Turkish flight-results UI; providers are the FlightSearch and TripBuilder modules. Activation requires Contract Authority approval plus the decision owners listed in `registries.json`.

Compatibility: existing single-offer search, offer, reprice and `TripRequestFlightSelection` operations are unchanged. New route operations use distinct paths and identifiers. A route selection stores immutable references to independently repriced CURRENT offers; it is not a booking or ticket.

Implementation boundary before approval: `App\Modules\SelfTransfer` may match, calculate, deduplicate, rank and render caller-supplied real offers in memory. Candidate provider calls, ground estimates, public operations and TripPlan persistence remain disabled.
