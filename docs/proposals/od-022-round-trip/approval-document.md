# OD-022 Gidiş-Dönüş Uçuş — Onay Belgesi

Durum: **PROPOSED — proje sahibi onayı bekliyor.** Bu belge onay kaydı değildir.  
Aktif sözleşme: `3.22.0` (`registry/active_contract.json`) · Hedef: `3.23.0` · Tarih: `2026-10-29`

Bu belge OD-022'yi çözmez, `RESOLVED` yapmaz, v3.23'ü aktive etmez ve uygulama kodunu yetkilendirmez. Onaya kadar gidiş-dönüş araması **BLOCKED** kalır. `FlightSearchCriteria::journeyBlockerTr` engeli korunur.

## 1. Onaylanması istenen tek karar

> **OD-022 / ROUND_TRIP alt konusu:** EXACT gidiş ve EXACT dönüş tarihli ROUND_TRIP talebi, sağlayıcıya tek bir çok slice'lı istekle gönderilir. Her sağlayıcı teklifi tek bir kanonik `FlightOffer` olur. Gidiş ve dönüş, `journey_type` ve segment `direction` alanlarıyla ayrılır. Aşağıdaki D1–D4 ile A1–A8 bu kararın bütünüdür. Bunların arasında ayrı seçim istenmez.

Onaylanırsa OD-022 kaydında yalnız şu madde `remaining_open` listesinden `resolved` listesine taşınır: "ROUND_TRIP - FlightOffer'da slice/yön yapısı yok". **OD-022 durumu `PARTIALLY_RESOLVED` kalır** (bkz. §5).

## 2. Onay gerektiren yeni kararlar (D1–D4)

| # | Önerilen karar | Gerekçe (tek cümle) | Sözleşme referansı |
|---|---|---|---|
| **D1** (RT-01) | `SCH-FlightOffer` 3.23: zorunlu `journey_type` (`ONE_WAY`/`ROUND_TRIP`, sağlayıcı slice sayısından) ve segment başına zorunlu `direction` (`OUTBOUND`/`INBOUND`, slice sırasından). `segments` tek ve kesintisiz sıralı dizi olarak kalır: önce gidiş, sonra dönüş. | En küçük ek yüzeydir ve FR-FLT-002-02/03'ün "sıralı segment listesi" tanımını değiştirmez. | `contracts/v3.4/schemas/FlightOffer.schema.json` → `schemas/FlightOffer.schema.json`; `FR-FLT-002-01/03`, `RULE-FLT-002-05`, `RULE-TRIP-001-04` |
| **D2** (RT-07) | v3.4 biçiminde saklanmış teklif görüntüleri (`flight_selections.offer`, teklif önbelleği) okunurken `journey_type=ONE_WAY` ve tüm segmentlerde `direction=OUTBOUND` olarak normalize edilir. Veri dönüşümü (SQL) yapılmaz. | Bu değer tahmin değildir: taban sürümden beri tek üretici olan Duffel adaptörü, hem arama hem reprice yolunda (`retrieveOffer`) `count($slices) !== 1` olan teklifi reddeder ve ROUND_TRIP ölçütünü HTTP'den önce engeller. | `DuffelFlightProviderAdapter::parseOffer` (git `aab07ad`, satır 277), `::expressibleSlices` (satır 128); `SCH-TripRequestFlightSelection` 3.13 `x-persistence` |
| **D3** (RT-06) | Reprice yanıtında segment listesi (yön ve sıra dahil) saklı görüntüden farklıysa sonuç `UNVERIFIED` + `RC-EVIDENCE-REPRICE-UNVERIFIED` olur. Seçim değişmez. Kural tek yön teklifleri de kapsar. | `SCH-OfferChangeSet` güzergâh değişimini ifade edemez; doğrulanamayan teklifin CURRENT gösterilmemesi zaten onaylı kuraldır. | `contracts/v3.3/schemas/OfferChangeSet.schema.json`, `AC-FLT-REPRICE-001-04/07`, `RULE-FLT-REPRICE-001-03`, `TRN-FSEL-02` (v3.13 lifecycle) |
| **D4** (RT-12 ve kayıtlar) | Yeni gereksinim kayıtları: `RULE-FLT-002-07/08`, `FR-FLT-002-04`, `AC-FLT-002-07..09`, `TS-FLT-002-08..11`, `AC/TS-FLT-005-04`, `RULE-FLT-REPRICE-001-08`, `AC/TS-FLT-REPRICE-001-14..16`. Slice sayısı, sırası veya uç noktası talep ile uyuşmayan teklif atılır (IATA_CITY talebinde havalimanının şehir kodu eşleşmelidir). | Mevcut "eksik teklif tamamlanmaz" ilkesini çok slice'a uygular ve onaylı gidiş-dönüş talep modelini uçuş tarafında izlenebilir kılar. | Metinler: `proposed_contract.yaml#proposed_requirements`; `SCH-AviationIdentity` (v3.8), `RULE-FLT-002-01` |

## 3. Mevcut onaylı sözleşmenin zaten belirlediği konular (A1–A8, ayrı onay gerekmez)

| # | Sonuç | Belirleyen onaylı kayıt |
|---|---|---|
| A1 (RT-02) | Tek sağlayıcı teklifi = tek `provider_offer_id`. Bağımsız tek yön teklifler gidiş-dönüş olarak birleştirilmez. | `SCH-FlightOffer.provider_offer_id` tekildir; `SCH-TripRequestFlightSelection.provider_offer_id` tekildir; `OP-repriceOffer` tek `offerId` alır; `RULE-FLT-002-01` |
| A2 (RT-03) | Tekilleştirme anahtarı değişmez. Bir arama işi tek yolculuk tipi ürettiği için yön eklemek gerekmez. | `FR-FLT-002-02/03`; `SCH-FlightSearchJob.trip_request_id` (v3.4) |
| A3 (RT-04) | Fiyat, tüm yönler ve yolcular için tek toplamdır; yöne bölünmez. | `SCH-FlightOffer.price` (tek `DerivedMoney`), `verified_price` (v3.13), `RULE-FLT-REPRICE-001-07`, `RULE-FLT-005-02` |
| A4 (RT-05) | Bagaj teklif düzeyindedir. Bir hak yalnız tüm segmentlerde aynıysa bilinen değer olur; aksi halde UNKNOWN. | `SCH-FlightOffer.baggage` (tek `BaggageAllowance` v3.3), `RULE-FLT-005-01/02`, `AC-FLT-005-01/02` |
| A5 (RT-08) | Seçim `TripRequestFlightSelection` üzerinde kalır; TripPlan şeması değişmez. | OD-021 RESOLVED (`registry/v3.16/decisions.yaml`), `SCH-ItineraryItem` v3.16 yalnız POI; uçuş çapaları OD-034 (OPEN) |
| A6 (RT-09) | Mevcut `duffel_flights` / `search` capability kanıtı yeterlidir; yolculuk tipi boyutu yoktur. | `RULE-PRV-001-01`, `FR-PRV-001-01`, `SCH-ProviderCapability.operation` (v3.3) |
| A7 (RT-10) | Teklif koruma iddiası taşımaz; search-only teklif `bookable=false` kalır; arayüzde "korumalı" denmez. | OD-017 PARTIALLY_RESOLVED (kanıt yoksa UNKNOWN, `registry/v3.22/decisions.json`), `RULE-FLT-002-03`, `AC-FLT-002-02` |
| A8 (RT-11) | Yalnız EXACT/EXACT aranır. FLEXIBLE tarih sağlayıcıya gitmeden engellenir. | OD-022 `remaining_open` (FLEXIBLE), `GAP-FLT-CRITERIA-001`, `RULE-TRIP-001-05` |

Ayrıca seçimde `offer.journey_type ≠ TripRequest.journey_type` ise mevcut 409 `RC-DECISION-OFFER-NOT-SELECTABLE` döner. Bunun gerekçesi `TRN-FSEL-01` guard'ı ve `RULE-TRIP-001-04`'tür. Talep, aramadan sonra `journey_type` değiştirilerek güncellenebildiği için bu guard gereklidir; kural metni D4 içindeki `RULE-FLT-REPRICE-001-08`'dir.

## 4. Etkilenen sözleşmeler ve uyumluluk

| Artefakt | Değişiklik | Uyumluluk |
|---|---|---|
| `SCH-FlightOffer` 3.4 → 3.23 | 2 zorunlu yanıt alanı eklenir | Ek alan. Precedent: v3.4 CHANGELOG'u FlightOffer'a `bookable/sources/segments` eklemeyi kırıcı saymamıştır; v3.13 VERSIONING "patch-level addition" ile aynı sınıf. PRE-RELEASE istisnası veya ADR gerekmez. |
| `SCH-OfferSnapshot`, `SCH-RepriceResult`, `SCH-FlightSearchResult`, `SCH-TripRequestFlightSelection` | Yalnız FlightOffer referansı 3.23'e yeniden sürümlenir | Semantik değişmez (v3.4 yeniden sürümleme deseni) |
| `OP-pollFlightSearch`, `OP-getOffer`, `OP-repriceOffer` (`contracts/openapi/m1a.openapi.yaml`); `OP-getTripRequestFlightSelection`, `OP-selectTripRequestFlight`, `OP-reconfirmTripRequestFlight` (`contracts/v3.13/openapi/m1a.openapi.yaml`); web kanalı OD-028 | Yanıt şeması referansı | Yeni operasyon, parametre veya hata kodu yok |
| `SCH-FlightSearchCriteria` 3.12, `SCH-TripRequest` 3.9, `SCH-OfferChangeSet` 3.3, `SCH-TripPlan`/`ItineraryItem` 3.16, `OP-startFlightSearch`, `OP-getSelectedFlightOpportunity` 3.22 | Yok | — |
| Saklı veriler | D2 ile okuma anında normalize edilir | Veri kaybı veya SQL yok |
| Tek yön davranışı | Arama, tekilleştirme, sıralama, seçim, UNKNOWN anlamı ve `bookable` aynı; yanıta iki alan eklenir; D3 yalnız aynı kimlikle farklı güzergâh dönerse etkilenir | Mevcut tek yön testleri yeşil kalmalıdır (`proposed_contract.yaml#regression_tests`) |
| Taslak öneriler | `docs/proposals/prc-004/v3.20` v3.4 `flight_segment`'e başvurur; v3.4 değişmediği için geçerli kalır. Gidiş-dönüş rota kimliği için PRC-004 daha sonra `direction`'ı dikkate almalıdır. | Aktif değil |

**Sürüm:** `3.23.0`. Depo her delta için yeni `3.x.0` baseline açar; değişiklik "patch-level addition" sınıfındadır.

## 5. Kapsam dışında OPEN kalanlar (bu onayla çözülmez)

- **OD-022 kalan maddeleri:** FLEXIBLE tarihli sağlayıcı araması (`GAP-FLT-CRITERIA-001` ile birlikte), kabin sınıfı, segment havalimanı/LocationRef.
- **OD-020:** Yolcu tipi/yaş dağılımı. Adaptör ALL_ADULT politikasıyla çalışmaya devam eder.
- **OD-017:** Lisanslı bilet koruması kanıtı ve risk eşikleri.
- **OD-034:** Uçuş/konaklama plan çapaları.
- **OD-001, OD-011, OD-015:** Production sağlayıcı ve yönlendirme/booking. Booking, bilet ve ödeme kapsam dışıdır.
- **OD-013:** Saklı teklif görüntülerinin saklama süresi.

## 6. Kalan riskler

1. **Tek bilet kanıtı yok:** Duffel'in çok slice'lı teklifi tek bilet/PNR olarak düzenleyeceği depoda kanıtlanmamıştır. Arayüz bu yüzden yalnız "tek teklif, tek toplam fiyat" der; koruma iddia etmez (A7).
2. **Bagaj daha sık UNKNOWN görünebilir:** Yönler farklıysa hak UNKNOWN olur (A4). Bu bilgi kaybıdır ama yanıltıcı değildir; yön başına bagaj ayrı bir genişletme gerektirir.
3. **IATA_CITY eşleşmesi:** Sağlayıcı yanıtında havalimanının şehir kodu yoksa teklif atılır (D4). Bu durumda sonuç sayısı düşebilir.
4. **Aktif v3.22 kayıt tutarsızlığı:** `contracts/v3.22/registries/od_impact.json` → `blocked_features.RCG-001` OD-022'yi "cabin/flexible/passenger remaining_open" olarak özetler ve ROUND_TRIP maddesini atlar. Ayrıca `registry/v3.22/decisions.json` OD-022'yi listelemez; durum `registry/v3.21/decisions.yaml` `open_decisions` listesinden miras alınır. Bu belge aktif baseline'ı değiştirmez. Aktivasyon deltasında OD-022 açıkça kaydedilmeli ve bu özet düzeltilmelidir.
5. **Test ortamı:** `tests/Feature/FlightOfferEndpointTest.php` sabit tarihli (2026-10-27) fixture nedeniyle bugün başarısızdır. Konu dışıdır ama regresyon ölçümünü bulandırır.

## 7. Onay sonrası (ayrı görev, ayrı yetki)

1. Onay kaydı oluşturulur: proje sahibinin adı ve tarihiyle; `external_approval_claimed` değeri gerçeğe göre girilir.
2. Aktif delta hazırlanır: `contracts/v3.23/{schemas,registries,openapi,CHANGELOG.md,VERSIONING.md}`, `registry/v3.23/{decisions,contract_index,story_overrides}`, `registry/active_contract.json`.
3. Uygulama ve testler yazılır; `journeyBlockerTr` yalnız FLEXIBLE için kalır.

Ekler: `proposed_contract.yaml` (makine okunur delta), `schemas/FlightOffer.schema.json` (önerilen şema), `examples/fixtures.json` (doğrulama örnekleri).
