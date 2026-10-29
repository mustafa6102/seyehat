# HOW-001 — kullanıcı onay listesi

Durum: **DRAFT / NON-NORMATIVE / APPROVAL REQUIRED**  
Taban: `3.18.0`  
Hedef olası sürüm: `3.19.0`  
Tarih: `2026-10-23`

Bu belge karar almak içindir. İşaretlenmemiş seçenekler için varsayım yapılmaz. `PROVISIONAL` öneriler onay değildir; bu belge hiçbir OD/ADR'yi `RESOLVED` yapmaz ve v3.19'u aktive etmez.

## A. Zaten onaylı gereksinimler — yeniden seçim konusu değildir

- `HOW-001`, `FEAT-HOW-TO`, `EPIC-18`, faz `M3`; `BUS-001`, `BUS-006`, `BUS-009`.
- `RULE-HOW-001-01..03`: doğrulanmamış operasyonel gerçek kesinleştirilmez; genel açıklama ile doğrulanmış talimat ayrılır; kullanıcı rehberi Türkçedir.
- `FR-HOW-001-01..02`: itinerary item için source-backed bloklar; stale kritik talimat için yeniden doğrulama veya `STALE` gösterimi.
- `AC-HOW-001-01..03`, `TS-HOW-001-01..03`: bilinmeyen platform uydurulmaz; doğrulanmış bilet URL kaynağı korunur; stale kritik talimatta refresh denenir ve başarısızlıkta `STALE` gösterilir.
- `NFR-LOC-001`, `NFR-AI-GRD-001`; ayrıca `RULE-M3-001`, `RULE-M3-002`, `RULE-M3-006` geçerlidir.
- Mevcut plan/auth temeli: immutable `PlanRevision`, `OD-027`, `OD-028`, `OD-029`, `PLT-AUTH-001` owner sınırı.

## B. Onay bekleyen kararlar

### OD-HOW-001 — İlk sürüm hangi itinerary bağlamlarını destekleyecek?

Etkilenen: `HOW-001`, `FR-HOW-001-01`, `AC-HOW-001-01..03`, `SCH-ContextualGuide`, `OP-getTripPlanItemGuide`, `RC-HOW-CONTEXT-UNSUPPORTED`.

- **A — Yalnız CURRENT PlanRevision içindeki POI öğesi.** En küçük uygulanabilir sözleşme; mevcut `SCH-ItineraryItem` ile uyumlu. Ulaşım/check-in/rezervasyon story kapsamı açık kalır ve HOW-001 DONE sayılmaz. **PROVISIONAL öneri.**
- **B — POI + TripRequest uçuş/konaklama seçimleri için yeni context union.** Daha geniş fayda; seçimleri itinerary item'a bağlayan yeni şema ve mapping gerekir.
- **C — Ulaşım, giriş, check-in, rezervasyon ve event dahil tam Story kapsamı.** Story'ye en yakın; event/ground canonical modelleri ve bağlantı sözleşmeleri hazır değildir, uygulama öncesi ek OD'ler gerekir.

Kullanıcı seçimi: [ ] A  [ ] B  [ ] C

Ön bağımlılıklar: B için uçuş/konaklama → itinerary bağlantı kararı; C için ayrıca `OD-003` ve event/ground contract'ları. Saat/ulaşım/feasibility iddiası üretilecekse `OD-030..035` önce çözülmelidir. A, bunları kapsam dışı tutarak ilerleyebilir.

### OD-HOW-002 — Rehber metni nasıl üretilecek?

Etkilenen: `RULE-HOW-001-01..03`, `FR-HOW-001-01`, `AC-HOW-001-01`, `NFR-AI-GRD-001`, `SCH-InstructionBlock`.

- **A — Deterministik Türkçe şablon + typed evidence slot.** Kritik gerçekler yalnız domain verisinden renderer'a girer; düşük halüsinasyon riski, daha az dil esnekliği. **PROVISIONAL öneri.**
- **B — LLM taslağı + zorunlu evidence-slot doğrulama ve deterministik kritik alan enjeksiyonu.** Daha doğal anlatım; prompt/model yönetişimi, redaction ve publish gate sözleşmeleri gerekir.
- **C — Serbest LLM metni.** En esnek; `NFR-AI-GRD-001` ve `RULE-HOW-001-01` ile uyumsuz olduğundan mevcut gereksinimler değiştirilmeden uygulanamaz.

Kullanıcı seçimi: [ ] A  [ ] B  [ ] C

Ön bağımlılıklar: B için M4 AI publish/evidence gate contract'ları; C için normatif gereksinim değişikliği gerekir. A için önceki açık OD zorunlu değildir.

### OD-HOW-003 — Operasyonel gerçekler hangi kaynaklardan alınacak?

Etkilenen: `FR-HOW-001-01..02`, `AC-HOW-001-01..03`, `SCH-InstructionBlock.evidence_slots`, `TRN-HOW-EVIDENCE-01`, `RC-HOW-EVIDENCE-UNAVAILABLE`.

- **A — Yalnız mevcut kanonik snapshot ve onaylı source reverify operasyonları.** Kaynak zinciri korunur; mevcut provider'ların taşımadığı gerçekler UNKNOWN kalır. **PROVISIONAL öneri.**
- **B — HOW'a özel onaylı guide provider.** Daha fazla veri; provider seçimi, mapping, capability, DPA, retention ve resilience sözleşmesi gerekir.
- **C — Genel web araması.** Geniş kapsama erişebilir; otorite, lisans, provenance, freshness ve güvenlik sınırı tanımlı değildir.

Kullanıcı seçimi: [ ] A  [ ] B  [ ] C

Ön bağımlılıklar: A'da POI kullanımı `OD-025` koşulları ve provider capability ile sınırlıdır; üretim reverify profili için `ADR-006` açık kalabilir ve fail-closed uygulanır. B/C için `OD-013`, `OD-014`, `ADR-006` ve yeni provider/provenance kararı önce gerekir.

### OD-HOW-004 — Freshness ve stale kritik talimat nasıl yönetilecek?

Etkilenen: `FR-HOW-001-02`, `AC-HOW-001-03`, `TS-HOW-001-03`, `SCH-InstructionBlock.verification_status/verified_at/expires_at`, `CFG-HOW-EVIDENCE-FRESHNESS`, `CFG-HOW-REVERIFY-TIMEOUT`, `TRN-HOW-EVIDENCE-01`.

- **A — Kaynak `VerificationStatus` ve `expires_at` aynen devralınır; stale kritik slot açılış akışında desteklenen source reverify ile senkron denenir.** Yeni TTL uydurmaz; latency/timeout bütçesi onaylanmalıdır. **PROVISIONAL öneri.**
- **B — HOW için ayrı TTL/freshness penceresi.** Bağımsız kontrol sağlar; kaynakla çelişen iki tazelik gerçeği ve sayısal config kararı doğurur.
- **C — Arka plan refresh; kullanıcıya mevcut stale rehber hemen döner.** Hızlı okuma; job lifecycle/polling ve AC-03'te “rehber açılırken deneme” zamanlamasının açıklanması gerekir.

Kullanıcı seçimi: [ ] A  [ ] B  [ ] C

Ön bağımlılıklar: A için provider/operation bazlı timeout-retry-circuit değerleri (`ADR-006`) veya açık fail-closed bütçesi; B için yeni sayısal ürün kararı; C için kabul edilmiş `ADR-011` kalıbının HOW job contract'ına uyarlanması gerekir.

### OD-HOW-005 — Okuma ve yeniden doğrulama API'si nasıl ayrılacak?

Etkilenen: `FR-HOW-001-01..02`, `AC-HOW-001-03`, `OP-getTripPlanItemGuide`, `OP-refreshTripPlanItemGuide`, HTTP method/response/error yüzeyi.

- **A — Tek `POST .../guide:resolve`; okuma ve refresh aynı komutta.** Basit istemci akışı; salt okuma yan etkili olur, cache ve tekrar davranışı zorlaşır.
- **B — Yan etkisiz `GET .../guide` + ayrı `POST .../guide/refresh`; UI stale GET sonucunda POST'u açıkça tetikler.** HTTP semantiği ve provider çağrısı gözlenebilir kalır; iki istek gerekir. **PROVISIONAL öneri.**
- **C — Yalnız GET + async refresh job.** Okuma hızlıdır; job start/poll operasyonları ve geçici stale UI gerekir.

Kullanıcı seçimi: [ ] A  [ ] B  [ ] C

Ön bağımlılıklar: C için `ADR-011` kalıbına uygun yeni lifecycle ve job şeması; A/B için `OD-HOW-004` freshness davranışı onaylanmalıdır.

### OD-HOW-006 — HOW verisi nasıl saklanacak?

Etkilenen: `SCH-ContextualGuide`, `SCH-InstructionBlock`, persistence metadata, retention, audit ve gelecekteki DB/SQL gereksinimi.

- **A — Her okumada kanonik snapshot'lardan hesaplanan read model; HOW'a özel kayıt yok.** Source-of-truth çoğalmaz ve HOW tablosu gerekmez; tarihsel rehber görüntüsü tutulmaz. **PROVISIONAL öneri.**
- **B — Süreli mutable cache.** Performans artar; invalidation, TTL, encryption/retention ve stale cache kuralları gerekir.
- **C — Plan revision'a bağlı immutable guide snapshot.** Güçlü tarihsel kanıt; depolama, PII/retention, snapshot yenileme ve olası outbox sözleşmesi gerekir.

Kullanıcı seçimi: [ ] A  [ ] B  [ ] C

Ön bağımlılıklar: B/C için `OD-013` retention ve ADR-010 secret/security başlıkları; C için `ADR-003`/`ADR-008` olay gereksinimi kararı. A için HOW'a özel persistence kararı gerekmez.

### OD-HOW-007 — Rehberi kim okuyabilir ve yeniden doğrulayabilir?

Etkilenen: `PLT-AUTH-001`, permissions registry, iki HOW operasyonunun `401/403/404` sırası, `RULE-HOW-001-01`, olası provider maliyeti.

- **A — Yalnız TripRequest owner hem okur hem refresh eder.** Mevcut owner sınırını kullanır; paylaşım yoktur. **PROVISIONAL öneri.**
- **B — Tanımlı Trip üyesi okuyabilir, yalnız owner refresh eder.** Daha iyi paylaşım; üyelik/rol modeli bugün onaylı değildir.
- **C — Rol bazlı read/refresh matrisi.** En esnek; collaborator rolleri, audit ve ayrıcalıklı erişim politikası gerekir.

Kullanıcı seçimi: [ ] A  [ ] B  [ ] C

Ön bağımlılıklar: A, v3.16 owner kuralıyla ilerleyebilir; ADR-010'un kalan başlıkları release riski olarak açık kalır. B/C için Trip membership/collaborator ve ADR-010 yetki/audit kararları önce gerekir.

### OD-HOW-008 — Rehber hangi PlanRevision'a bağlanacak?

Etkilenen: `FR-HOW-001-01`, `SCH-ContextualGuide.plan_revision_version/item_id`, iki HOW operasyonunda `If-Match`, `RC-CONFLICT-REVISION-STALE`, immutable `PlanRevision`.

- **A — Yalnız CURRENT revision item; istemci `If-Match: "<version>"` gönderir.** Stale bağlamı reddeder ve immutable revision modelini korur; geçmiş rehber doğrudan okunmaz. **PROVISIONAL öneri.**
- **B — Kimliği verilen herhangi immutable revision item.** Tarihsel okuma sağlar; geçmiş revision read API/authorization ve refresh yasağı tanımlanmalıdır.
- **C — Revision bağı olmadan yalnız item ID.** Basit URI; item'ın kaldırılması/taşınması halinde belirsiz ve stale sonuç riski taşır.

Kullanıcı seçimi: [ ] A  [ ] B  [ ] C

Ön bağımlılıklar: A için `OD-027` ve `OD-029` zaten çözülmüştür. B için geçmiş PlanRevision read sözleşmesi; C için item kimliği yaşam döngüsü kararı gerekir.

### OD-HOW-009 — Idempotency, PlanRevision ve outbox davranışı ne olacak?

Etkilenen: `OP-refreshTripPlanItemGuide`, `Idempotency-Key`, `RC-CONFLICT-IDEMPOTENCY-KEY`, `TRN-HOW-EVIDENCE-01`, `PlanRevision`, olası outbox event'i.

- **A — GET için idempotency yok; refresh `Idempotency-Key` ister; HOW yeni PlanRevision veya outbox üretmez.** Aynı provider refresh'i tekrarını sınırlar, planı değiştirmez; idempotency sonucu için mevcut kayıt mekanizmasının kapsamı açıkça genişletilmelidir. **PROVISIONAL öneri.**
- **B — Refresh yeni immutable PlanRevision üretir ve mevcut revision outbox garantisini kullanır.** Güçlü audit; rehber kanıt yenilemesini plan içeriği değişikliği sayar ve gereksiz revision büyümesi yaratır.
- **C — PlanRevision değişmez; ayrı HOW refresh event'i outbox'a yazılır.** En iyi gözlenebilirlik; yeni event şeması, transaction sınırı, retention ve ordering gerekir.

Kullanıcı seçimi: [ ] A  [ ] B  [ ] C

Ön bağımlılıklar: A için HOW refresh idempotency persistence/scope kararı; B için `OD-027` anlam genişletmesi; C için `ADR-003` transaction ve açık `ADR-008` ordering kararı gerekir.

### OD-HOW-010 — Tarayıcı kanalı nasıl sunulacak?

Etkilenen: iki HOW operasyonunun public/web yolları, authentication, CSRF, permissions ve Türkçe frontend entegrasyonu.

- **A — OD-028 modeli: `/api/v1` Bearer; `/web-api/v1` session principal; refresh POST CSRF korumalı.** Mevcut web mimarisiyle uyumlu, token tarayıcıya verilmez. **PROVISIONAL öneri.**
- **B — Yalnız `/api/v1` Bearer.** Daha küçük API yüzeyi; mevcut Blade/browser istemcisine bearer token verme veya ayrı BFF gerektirir.

Kullanıcı seçimi: [ ] A  [ ] B

Ön bağımlılıklar: A için `OD-028` zaten çözülmüştür. B seçilirse browser authentication mimarisi ayrıca kararlaştırılmalıdır.

## C. Paket onayı

Her karar için yukarıda tam olarak bir seçenek işaretlenmeden paket onaylanmış sayılmaz.

- [ ] OD-HOW-001 seçildi
- [ ] OD-HOW-002 seçildi
- [ ] OD-HOW-003 seçildi
- [ ] OD-HOW-004 seçildi
- [ ] OD-HOW-005 seçildi
- [ ] OD-HOW-006 seçildi
- [ ] OD-HOW-007 seçildi
- [ ] OD-HOW-008 seçildi
- [ ] OD-HOW-009 seçildi
- [ ] OD-HOW-010 seçildi
- [ ] Seçilen kombinasyonun yukarıdaki ön bağımlılıkları kabul edildi
- [ ] Bu onay yalnız contract paketinin normatif delta olarak hazırlanmasına izin verir; uygulama kodu, test, migration, SQL, commit veya push yetkisi vermez

Not: Ön taslaktaki `OD-HOW-011` (kritik alanlar ve gösterim) bu on maddelik onay paketine dahil edilmemiştir. `SCH-InstructionBlock` kesinleştirilmeden önce ayrı onaylanmalı veya kullanıcı talimatıyla bu pakete eklenmelidir; aksi halde kritik slot taksonomisi PROVISIONAL kalır.

DB CHANGE REQUIRED: NO (bu onay-listesi görevi için).
