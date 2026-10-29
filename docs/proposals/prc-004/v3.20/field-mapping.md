# Supported pricing field map

## Flight

| PRC need | Existing canonical field/source | Readiness |
|---|---|---|
| Price and currency | `FlightOffer.price` → `DerivedMoney`; `Money.minor_units`, `Money.currency` | Supported |
| Price completeness/certainty | `price.completeness`, `price.certainty`, `known_lower_bound`, `unknown_components` | Supported |
| Provenance/time | `FlightOffer.sources[].provider_id`, `provider_offer_id`, `fetched_at`, `verified_at`, `expires_at`, `verification_status`; `OfferSnapshot.captured_at` | Supported |
| Baggage | `FlightOffer.baggage` / `BaggageAllowance` | Supported as observed evidence |
| Fare conditions | `OfferSnapshot.fare_conditions` | Supported as observed evidence |
| Route identity | `FlightOffer.segments[]` has carrier, flight number and times | **Blocked:** active schema explicitly lacks segment origin/destination LocationRef; `itineraryFingerprint()` is an internal method, not a contract identifier |
| Canonical fare identity | Fare terms and baggage facts exist | **Blocked:** no approved canonical fare-product/fare-family identifier |

`provider_offer_id`, price amount and internal `deduplicationKey()` cannot be cohort identities: they identify an offer/version and the dedupe key includes the price itself.

## Accommodation

| PRC need | Existing canonical field/source | Readiness |
|---|---|---|
| Price and currency | `AccommodationOffer.total_price` → `DerivedMoney` | Supported |
| Property/stay dates | `property_id`, `check_in_date`, `check_out_date` | Supported |
| Provenance/time | `source.provider_id`, `provider_offer_id`, `fetched_at`, `verified_at`; revalidation snapshot `captured_at` | Supported |
| Room/fare identity | `room_description`, `cancellation` | **Blocked:** description is provider text, not an approved canonical room/rate-plan identifier |
| Occupancy identity | Not present in `AccommodationOffer` | **Blocked:** exact guest/occupancy cohort cannot be reconstructed from the offer schema |

## Consequence

Both products can produce a provenance-preserving observed snapshot shape. Neither currently has all approved canonical identifiers required for an exact comparable cohort. Until those mappings are added by a separate contract decision, evaluation must return `CONFIGURATION_UNRESOLVED`; observed data must not be grouped heuristically.
