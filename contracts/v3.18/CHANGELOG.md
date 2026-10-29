# v3.18.0

- OD-029 RESOLVED (user, 2026-10-23) - Seçenek A: plan günleri TripRequest'in EXACT gidiş ve dönüş tarihlerinden (ROUND_TRIP, iki uç dahil) varış noktasının IANA saat diliminde türetilir; kullanıcı FLEXIBLE POI öğesini elle güne atar, gün içinde sıralar veya atamayı kaldırır. Atama uygulanabilirlik iddiası taşımaz.
- Schemas (replace v3.16, same IDs): `SCH-ItineraryItem` (+ `day_assignment` = null | {date, position, feasibility_status=NOT_EVALUATED}), `SCH-PlanRevision` (+ `change.moved_item_ids`), `SCH-TripPlan` (+ türetilmiş `day_frame`). v3.16 revision'ları DB değişikliği olmadan okunur (day_assignment=null, moved_item_ids=[]).
- New operation (ACTIVE): `OP-assignTripPlanItemDay` - `PUT /trip-requests/{tripRequestId}/plan/items/{itemId}/day-assignment` (If-Match + Idempotency-Key, TRN-PLANREVISION-01). Web kanalı OD-028 A kuralıyla `/web-api/v1` altında.
- OP-removeTripPlanItem açıklaması: atanmış öğe çıkarılınca aynı gündeki sıra kesintisiz yeniden numaralanır.
- New reason codes: `RC-VALIDATION-DAY-FRAME-UNAVAILABLE` (422), `RC-VALIDATION-DAY-ASSIGNMENT-INVALID` (422).
- DAY-001 additions: RULE-04/05, FR-03, AC-05..08, TS-05..08; RULE-02/03 açıklandı. TRIP-BLD-001 RULE-05 ve FR-02 açıklandı.
- New OPEN decisions: OD-030 (saat/süre), OD-031 (çalışma saati kanıtı), OD-032 (ulaşım süresi), OD-033 (tampon/tempo/gün penceresi), OD-034 (uçuş/konaklama sınırları), OD-035 (gate sonucu ve reason kodları).
- Kapsam dışı: saat, süre, ulaşım, tampon, tempo, çalışma saati değerlendirmesi, otomatik yerleştirme ve uygulanabilirlik.
