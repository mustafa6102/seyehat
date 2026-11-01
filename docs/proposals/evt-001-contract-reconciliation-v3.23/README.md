# EVT-001 sözleşme uzlaştırma önerisi — v3.23

> **NORMATİF DEĞİLDİR / ONAY BEKLER.** Aktif sözleşmeyi değiştirmez, yeni provider yetkisi vermez ve hiçbir kaydı APPROVED/ACTIVE yapmaz.

## Yetki tabanı

- registry/active_contract.json: v3.23.0, base v3.22.0.
- contracts/v3.22/registries/od_impact.json, OD-EVT-001-TM: RESOLVED; Ticketmaster Discovery API v2, start/poll discovery job, canonical event storage ve açık kullanıcı eylemiyle EVENT/FIXED plan ekleme yetkili.
- OD-P8-002: iptal/ertelenmiş/sold-out etkinlik aktif öneri olamaz; CTA yalnız fresh durum, exact allowlisted HTTPS host ve doğrulanmış zamanla açılır; otomatik plan mutasyonu yoktur.
- registry/registry_stories_b.js, EVT-001: RULE-01..03, FR-01..02, AC-01..03, TS-01..03 aktiftir.

## Çelişki ve eksik kayıt matrisi

| No | Dosya / kayıt | Mevcut değer | Çatışan yetki veya kanıt | Sınıf |
|---|---|---|---|---|
| C1 | od_impact.json decision_source; OD-EVT-001-TM | Karar od_impact içinde RESOLVED | İşaret edilen registry/v3.22/decisions.json içinde karar yok | Eksik karar registration |
| C2 | traceability.json, AC-EVT-001-02 | plan persistence not active; BACKEND_SLICE_ONLY_NOT_FULL_AC | OD-EVT-001-TM storage ve FIXED EVENT add-to-plan açıyor; uygulama mevcut | Stale reference |
| C3 | traceability.json, blocked_unchanged | EVT-001 plan persistence | Spesifik karar dar persistence dilimini açıyor | Stale blocker |
| C4 | traceability.json | Yalnız AC-01..03 ve INT-projectEventEvidence; RULE/FR/TS ile gerçek dosya/testler eksik | Start/poll/storage/plan-add kanıtı mevcut | Eksik traceability registration |
| C5 | v3.22 ve miras alan v3.23 OpenAPI | Event discovery/plan path'leri yok | Yetkili public route'lar mevcut | Eksik OpenAPI registration |
| C6 | v3.22/v3.23 contract index | Yalnız internal EventInput/EventResult/projection | Public operasyon, canonical Event ve EventDiscoveryJob yok | Eksik contract registration |
| C7 | permissions.json | Yalnız internal projection izni | Bearer ve OD-028 session+CSRF owner operasyonları kayıtlı değil | Eksik permission registration |
| C8 | lifecycle.json ve reason_error.json | EVT job/plan-event kayıtları yok | Uygulama lifecycle ve güvenli hata kodları kullanıyor | Eksik lifecycle/error registration |
| C9 | DecisionSupport EventInput/EventResult | Yalnız internal projection; canonical Event değil | events.event_json için canonical uygulama şekli var | Eksik canonical schema |
| C10 | story_overrides.json notu | UI/persistence AC'leri tamamlanmaz | Daha spesifik OD-EVT-001-TM dar activation yapıyor | Stale genel not; full feasibility için geçerli |
| C11 | FR-EVT-001-02 | Ulaşım/buffer günlük rota motoruna aktarılır | OD-032..035/full feasibility açık; uygulama sunmuyor | Gerçek unresolved normative decision |
| C12 | od_impact blocked/external-open | Provider license/mapping/capability evidence açık | Eski manual seed kanıtsız supported=1/FRESH yazabiliyordu | Deployment blocker; seed NOT READY yapıldı |

## Minimal önerilen normatif delta — onay olmadan uygulanmaz

1. Onaylı yeni sürüm karar kaydına, od_impact içeriğini değiştirmeden OD-EVT-001-TM ekleyin; karar sahibi ve approval basis doğrulansın.
2. Yalnız OP-startEventDiscovery, OP-pollEventDiscovery ve OP-addTripPlanEventItem operasyonlarını kaydedin. Booking/payment/ticket issuance eklemeyin.
3. EventDiscoveryStartRequest/Job, canonical Event ve plan-add şemalarını inceleme taslağı olarak oluşturun; raw provider payload ve secret alanlarını yasaklayın.
4. Contract index kayıtlarına decision_ref=OD-EVT-001-TM ekleyin.
5. Permissions kaydında bearer API, OD-028 session+CSRF, owner kontrolü ve değerlendirme sırasını onaylayın.
6. Lifecycle'a discovery durumlarını/terminal immutability'yi; reason/error'a güvenli hata kodlarını kaydedin.
7. Traceability'de RULE/FR/AC/TS kayıtlarını gerçek production/test dosyalarına bağlayın ve dar plan-persistence stale blocker'ını kaldırın.
8. FR-EVT-001-02, full feasibility, provider license/mapping ve production capability evidence engellerini açık bırakın; EVT-001 bütünü DONE yapılmasın.

## Mevcut uygulama sınırı

- Yetkili: Ticketmaster discovery start/poll, canonical snapshot, fresh/UNKNOWN semantiği, açık kullanıcı eylemiyle EVENT/FIXED ekleme, owner/idempotency.
- Etkin değil: otomatik plan mutasyonu, ulaşım/buffer feasibility, booking/payment/ticket issuance ve kanıtsız capability aktivasyonu.
- Canonical Event alan şekli şema onayı gelene kadar implementation contract adayıdır; yeni istemci/provider taahhüdü olarak genişletilmemelidir.

## Gerekli onay

Project owner/contract authority; security/API owner; data/provider owner; persistence owner. Onay versioned contract delta olarak kaydedilmeli; bu öneri doğrudan active contract'a dönüştürülmemelidir.
