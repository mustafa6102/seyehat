# v3.13.0

- Minimum "seçili uçuş" kararı (Seyahat Planım ekranı için). Tam TRIP-BLD-001 itinerary mimarisi (Trip, ItineraryDay, ItineraryItem, PlanRevision) tanımlanmadı.
- New `SCH-TripRequestFlightSelection`: TripRequest başına en fazla bir kayıt. Kanonik teklif görüntüsü (`SCH-FlightOffer` v3.4), `provider_id`, `provider_offer_id`, `verified_price` (`SCH-Money`, bilinmiyorsa null), `verified_at`, `expires_at`, `status`, `created_at`, `updated_at`. Ham sağlayıcı yanıtı ve gizli bilgiler saklanmaz.
- New lifecycle `TripRequestFlightSelectionStatus`: `CURRENT`, `RECONFIRMATION_REQUIRED`, `EXPIRED` (TRN-FSEL-01..04). OfferState makinesine yeni durum eklenmedi; TRN-OFFER-01 (CURRENT -> CHANGED) seçili teklif için TRN-FSEL-02 yan etkisini tetikler.
- OpenAPI delta: `OP-getTripRequestFlightSelection`, `OP-selectTripRequestFlight`, `OP-reconfirmTripRequestFlight`. `OP-repriceOffer` yanıtı değişmedi; yalnız seçim yan etkisi belgelendi.
- New reason/error: `RC-DECISION-OFFER-NOT-SELECTABLE` (`OFFER_NOT_SELECTABLE`, HTTP 409). Reused: `RC-EVIDENCE-OFFER-CHANGED`, `RC-EVIDENCE-OFFER-EXPIRED`, `RC-RESOURCE-NOT-FOUND`, `RC-VALIDATION-SCHEMA`, `RC-CONFLICT-IDEMPOTENCY-KEY`.
- FLT-REPRICE-001 additions: RULE-FLT-REPRICE-001-04..07, FR-FLT-REPRICE-001-02..03, AC-FLT-REPRICE-001-08..13, TS-FLT-REPRICE-001-08..13 (`registry/v3.13/story_overrides.yaml`).
- Booking, ödeme, partner yönlendirme, konaklama/POI/restoran/etkinlik seçimi, itinerary kalıcılığı, PlanRevision ve kimlik doğrulama/sahiplik kapsam dışıdır.
- OD-011, OD-013, OD-015, OD-016, OD-021 ve ADR-010 OPEN kalır; bağımlılıklar `registries/od_impact.yaml` içinde kayıtlıdır. v3.2-v3.12 artifacts are unchanged.
