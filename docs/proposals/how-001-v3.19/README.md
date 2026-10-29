# HOW-001 Implementation Contract önerisi (karar taslağı)

Durum: **DRAFT / NON-NORMATIVE**  
Hedeflenen olası sürüm: `3.19.0`  
Taban: `3.18.0`  
Tarih: `2026-10-23`

Bu klasör aktif `registry/` veya `contracts/` zincirinin parçası değildir. Buradaki hiçbir öneri bir OD/ADR'yi çözmez, uygulamayı yetkilendirmez veya normatif sözleşmeyi değiştirmez.

## 1. Onaylı gereksinimler

- Story `HOW-001` / `FEAT-HOW-TO` / `EPIC-18`, faz `M3`, iş hedefleri `BUS-001`, `BUS-006`, `BUS-009`.
- Story: Kullanıcı, planındaki ulaşım, giriş, check-in veya rezervasyon adımlarını doğrulanmış veriye dayalı adım adım görmek ister.
- Veri bildirimi: Contextual instruction, linked itinerary item, source refs, verified_at, locale.
- `RULE-HOW-001-01`: Saat, ücret, terminal, platform, link veya giriş kuralı doğrulanmadan kesinleştirilemez.
- `RULE-HOW-001-02`: Genel açıklama ile doğrulanmış operasyonel talimat ayrıştırılır.
- `RULE-HOW-001-03`: Son kullanıcı rehberi Türkçedir.
- `FR-HOW-001-01`: Itinerary item için source-backed instruction blokları oluşturulur.
- `FR-HOW-001-02`: Freshness süresi geçen kritik talimat yeniden doğrulanır veya `STALE` işaretlenir.
- `AC-HOW-001-01`: Bilinmeyen platform numarası uydurulmaz.
- `AC-HOW-001-02`: Doğrulanmış bilet URL'sinin kaynak bağlantısı korunur.
- `AC-HOW-001-03`: Kritik talimat stale olduğunda yeniden doğrulama denenir; başarısızsa `STALE` etiketi gösterilir.
- `TS-HOW-001-01..03`: Unknown platform; verified booking link; stale instruction.
- `NFR-LOC-001`: Kullanıcı metni Türkçe, teknik identifier/kod İngilizce.
- `NFR-AI-GRD-001`: Kritik gerçekler serbest LLM metninden yayınlanmaz; evidence slot üzerinden domain verisinden renderer tarafından enjekte edilir.
- İlgili yatay kurallar: `RULE-M3-001`, `RULE-M3-002`, `RULE-M3-006`, `PLT-AUTH-001`, `PLT-PII-001`; plan bağlamı için `TRIP-BLD-001`, `DAY-001`, `OD-027`, `OD-028`, `OD-029`.

## 2. Eksik sözleşme yüzeyi

Henüz onaylı değildir: HOW operasyonları, istek/yanıt şemaları, instruction tür/taksonomisi, desteklenen itinerary-item türleri, veri kaynakları ve yeniden doğrulama adaptörleri, kritik alan listesi, freshness penceresi, owner/collaborator izinleri, rehber yaşam döngüsü, hata kodları, cache/persistence modeli, idempotency/outbox davranışı ve web kanalı.

Mevcut `SCH-ItineraryItem` yalnız POI alt kümesini taşır. Ulaşım, uçuş/konaklama check-in'i, bilet ve rezervasyon için tek bir onaylı itinerary-item bağlantı modeli bulunmadığından Story'nin tüm alanları bugün eksiksiz üretilemez.

## 3. Karar paketi

| ID | Karar | Seçenekler ve trade-off | PROVISIONAL öneri |
|---|---|---|---|
| OD-HOW-001 | İlk sürüm kapsamı | A: yalnız mevcut POI item (dar ama uygulanabilir); B: POI + seçili uçuş/konaklama için ayrı context union (daha geniş, yeni bağlantı sözleşmesi); C: tüm story türleri (tam ama event/ground modelleri yok) | **PROVISIONAL A**; diğer türlerde `CONTEXT_UNSUPPORTED`, Story DONE sayılmaz |
| OD-HOW-002 | Üretim biçimi | A: yalnız deterministik şablon/evidence slot; B: LLM taslak + slot doğrulama; C: serbest LLM | **PROVISIONAL A**; NFR-AI-GRD-001 ile en düşük risk |
| OD-HOW-003 | Veri kaynağı | A: yalnız mevcut kanonik snapshot ve onaylı reverify operasyonu; B: yeni guide provider; C: genel web araması | **PROVISIONAL A**; kaynak uydurmaz, kapsam daha dardır |
| OD-HOW-004 | Freshness | A: kaynak olgunun `VerificationStatus`/`expires_at` değerini devral; kritik stale olguda desteklenen reverify'i senkron dene; B: HOW için ayrı TTL; C: arka plan refresh | **PROVISIONAL A**; yeni sayısal TTL uydurmaz, ancak timeout bütçesi ayrıca onay ister |
| OD-HOW-005 | Okuma ve yeniden doğrulama işlemi | A: tek `POST .../guide:resolve`; B: saf `GET` + ayrı `POST .../refresh`; C: yalnız GET ve async job | **PROVISIONAL B**; GET yan etkisiz kalır, AC-03 için açık kullanıcı eylemi ve gözlenebilir refresh sağlanır |
| OD-HOW-006 | Persistence | A: hesaplanan read model, yalnız kaynak kimlikleri; B: mutable cache; C: immutable guide snapshot | **PROVISIONAL A**; yeni iş tablosu yok, source-of-truth çoğaltılmaz. Audit/log saklama kararı ayrı kalır |
| OD-HOW-007 | Yetki | A: TripRequest owner read/refresh; B: owner read, yalnız owner refresh; C: collaborator rolleri | **PROVISIONAL A**; mevcut `PLT-AUTH-001` owner sınırını yeniden kullanır, üyelik icat etmez |
| OD-HOW-008 | Plan revision bağı | A: CURRENT revision item; B: herhangi revision; C: item kimliğiyle revisionsız | **PROVISIONAL A**; stale bağlamı önler. İstemci `If-Match` ile CURRENT sürümü bildirir |
| OD-HOW-009 | Idempotency/outbox | A: read yok; refresh `Idempotency-Key`, yeni PlanRevision/outbox yok; B: refresh PlanRevision üretir; C: rehber olayı yayımlar | **PROVISIONAL A**; rehber kaynak olguyu değiştirmez. Provider reverify'nin kendi yan etkisi/audit'i korunur |
| OD-HOW-010 | Web kanalı | A: OD-028 kuralıyla `/web-api/v1`; B: yalnız bearer API | **PROVISIONAL A**; token tarayıcıya verilmez, refresh CSRF korumalıdır |
| OD-HOW-011 | Kritik alanlar ve gösterim | A: saat/ücret/terminal/platform/link/giriş kuralı kritik; UNVERIFIED değer gizlenir veya “doğrulanamadı”, STALE etiketlenir ve aktif CTA kapatılır; B: tüm blok kritik | **PROVISIONAL A**; doğrudan RULE-HOW-001-01 ve RULE-M3-006 ile uyumlu |

Bu kimlikler taslak yer tutuculardır; aktif OD registry'sine eklenmemiştir ve `RESOLVED` değildir.

## 4. Önerilen ilk onay

Önce `OD-HOW-001`, `OD-HOW-003`, `OD-HOW-004`, `OD-HOW-005` ve `OD-HOW-006` birlikte onaylanmalıdır. Bunlar kapsamı, kanıt kaynağını, AC-03 refresh davranışını ve veri saklama sınırını belirler. Ardından izin/revision/web/idempotency kararları (`OD-HOW-007..010`) onaylanabilir.

Önerilen ilk teslimat yalnız mevcut CURRENT PlanRevision içindeki POI öğesi için Türkçe, deterministik ve source-backed rehberdir. Bu alt küme `HOW-001`in tamamlandığı anlamına gelmez; ulaşım/check-in/rezervasyon kapsamı desteklenen canonical item modelleri oluşana kadar açık kalır.

## 5. Taslak artefaktlar

- `proposed_contract.yaml`: sürüm, registry, izlenebilirlik, izin, lifecycle, reason/error ve config taslağı.
- `openapi/m1a.openapi.yaml`: iki önerilen operasyonun non-normative OpenAPI deltası.
- `schemas/ContextualGuide.schema.json`: önerilen yanıt şeması.
- `schemas/InstructionBlock.schema.json`: evidence-slot instruction bloğu.

Uygulama, test, migration veya aktif contract değişikliği yapılmamıştır. Bu analiz görevi için `DB CHANGE REQUIRED: NO`; ileride `OD-HOW-006` için A seçilirse HOW'a özel iş tablosu gerekmez.
