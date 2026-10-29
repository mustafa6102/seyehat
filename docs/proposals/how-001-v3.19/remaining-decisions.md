# HOW-001 — kalan açık kararlar

Durum: **APPROVED IN PROPOSAL / ARCHIVED DECISION DETAIL**

Üst seviye `OD-HOW-001..010` ve aşağıdaki ayrıntılar kullanıcı tarafından açıkça onaylandı. Seçimler: `OD-HOW-004-D1=A`, `OD-HOW-009-D1=A`, `OD-HOW-009-D2=A`, `OD-HOW-011=A`.

## OD-HOW-004-D1 — Senkron reverify başarısızlığı nasıl kapanacak?

Onaylanmış çerçeve: Her evidence slot kendi kanonik kaynağının `verification_status`, `verified_at` ve `expires_at` değerini aynen devralır. HOW ayrı TTL hesaplamaz ve `UNVERIFIED` kanıtı `FRESH` yapmaz. Yan etkisiz GET stale kritik kanıtı gösterir; frontend, `OD-HOW-005=B` gereği ayrı refresh POST'unu açılış akışında tetikler.

- **A — Kaynak operasyon profilini devral + 503 fail-closed.** Refresh yalnız desteklenen ve onaylı source reverify operasyonuna delege edilir. Timeout/retry/circuit/rate-limit değerleri source operasyonunun mevcut versiyonlu `ADR-006` profilinden gelir; HOW için ikinci timeout veya sayısal varsayılan yoktur. Capability/profil/konfigürasyon yoksa, circuit açıksa veya provider başarısızsa çağrı yapılmaz ya da mevcut profilce kesilir; POST `RC-HOW-REVERIFY-UNAVAILABLE` ile 503 döner. UI önceki GET'teki değeri `STALE` etiketiyle tutar, aktif CTA açmaz. **PROVISIONAL öneri — en küçük sözleşme yüzeyi.**
- **B — Kaynak operasyon profilini devral + 200 sonuç zarfı.** Aynı fail-closed davranışı uygulanır; POST 200 içinde `refresh_status=UNAVAILABLE` ve stale rehberi döndürür. Kullanıcı akışı daha kolaydır fakat `SCH-ContextualGuide` için yeni refresh-result alanları gerekir.
- **C — HOW'a özel resilience profili.** Ayrı timeout/retry/circuit config'i gerekir. Sayısal değerler onaylanmadan uygulanamaz; kaynak operasyonuyla çifte bütçe doğurur.

Kullanıcı seçimi: [x] A  [ ] B  [ ] C

Etkilenen: `FR-HOW-001-02`, `AC/TS-HOW-001-03`, `OP-refreshTripPlanItemGuide`, `TRN-HOW-EVIDENCE-01`, `RC-HOW-REVERIFY-UNAVAILABLE`, `CFG-HOW-EVIDENCE-FRESHNESS`, `ADR-006`.

Bağımlılık sonucu: A/B seçilirse genel `ADR-006` tamamen çözülmek zorunda değildir; yalnız çağrılan source operation için onaylı profil/capability bulunmalıdır. Yoksa fail-closed çalışır. C, yeni değerler onaylanana kadar blokludur.

## OD-HOW-009-D1 — Refresh idempotency hangi kapsam ve persistence ile replay edilecek?

Onaylanmış çerçeve: GET idempotency anahtarı istemez. Refresh POST `Idempotency-Key` ister; HOW yeni `PlanRevision` veya outbox olayı üretmez.

- **A — Kalıcı, HOW'a özel olmayan operation-idempotency kaydı.** Bağlayıcı kapsam `(principal, operation, trip_request_id, item_id, base_revision_version, Idempotency-Key)` ve request fingerprint'tir. Aynı anahtar + aynı fingerprint tamamlanmış ilk HTTP status/body sonucunu birebir replay eder ve source reverify'i tekrar çağırmaz; farklı fingerprint `RC-CONFLICT-IDEMPOTENCY-KEY` olur. `IN_PROGRESS` eşzamanlı tekrar conflict/retryable sonuç verir; kesin kod ayrıca reason registry'de tanımlanır. PlanRevision/outbox yoktur. Saklama süresi uydurulmaz ve ayrı retention onayı olmadan uygulama başlayamaz. **PROVISIONAL öneri — gerçek replay garantisi sağlar, fakat gelecekte persistence sözleşmesi gerektirir.**
- **B — Mevcut uygulama cache'i üzerinde idempotency kaydı.** Aynı scope/fingerprint/replay kuralı süreçler arasında yalnız cache paylaşımı ve eviction izin verdiği ölçüde çalışır. Restart/eviction sonrası replay garantisi yoktur; güçlü idempotency vaadi verilemez.
- **C — Yalnız eşzamanlı request coalescing.** Aynı anda gelen çağrıları birleştirir; tamamlanmış çağrı daha sonra replay edilmez. `Idempotency-Key` semantiğini karşılamadığı için ancak sözleşme “deduplication” olarak değiştirilirse uygundur.

Kullanıcı seçimi: [x] A  [ ] B  [ ] C

Etkilenen: `OP-refreshTripPlanItemGuide`, `Idempotency-Key`, request fingerprint, `RC-CONFLICT-IDEMPOTENCY-KEY`, yeni operation-idempotency contract/persistence yüzeyi, `OD-HOW-006`.

Bağımlılık sonucu: A seçimi HOW rehber verisini kalıcılaştırmaz, dolayısıyla `OD-HOW-006=A` ile çelişmez; yalnız komut replay kaydıdır. Ancak retention hâlâ açık bir karar ve ileride manuel DB değişikliği gerekip gerekmediği uygulama tasarımından önce belirlenmelidir. B/C yeni DB istemez fakat tam replay garantisi sağlamaz.

## OD-HOW-009-D2 — Kalıcı idempotency kaydı ne zaman silinecek?

Bu karar yalnız `OD-HOW-009-D1=A` seçilirse geçerlidir. Sayısal saklama süresi önerilmez.

- **A — TripRequest yaşam döngüsüne bağlı silme.** Idempotency kaydı TripRequest var olduğu sürece tutulur; TripRequest onaylı retention/deletion sürecinde silindiğinde ilişkili kayıt da silinir. Ayrı sayısal süre uydurmaz ve replay garantisini TripRequest ömrü boyunca korur. `OD-013` tamamlanana kadar production retention kesinleşmez. **PROVISIONAL öneri.**
- **B — Ayrı versiyonlu süre config'i.** Daha kısa veri saklama sağlayabilir; süre, başlangıç anı ve eviction sonrası replay davranışı ayrıca onaylanmadan sözleşme tamamlanamaz.
- **C — Süresiz bağlama.** En güçlü replay; veri minimizasyonu ve silme yükümlülükleri açısından ayrıca hukuki/güvenlik onayı gerektirir.

Kullanıcı seçimi: [x] A  [ ] B  [ ] C

Etkilenen: operation-idempotency persistence contract'ı, `RC-CONFLICT-IDEMPOTENCY-KEY`, `OD-013`, TripRequest deletion/retention lifecycle.

## OD-HOW-011 — Kritik evidence slot ve kullanıcı sunumu

Soru: Hangi slotlar kritik sayılacak; `UNKNOWN`, `UNVERIFIED`, `STALE`, `EXPIRED` nasıl gösterilecek ve hangi bağlantı aktif CTA olabilecek?

- **A — RULE kapsamlı kritik slot matrisi.** Kritik slotlar yalnız `TIME`, `FEE`, `TERMINAL`, `PLATFORM`, `LINK`, `ENTRY_RULE` olur. `FRESH`: doğrulanmış değer gösterilir; `LINK` yalnız geçerli source-backed hedefse aktif CTA olabilir. `UNKNOWN`: değer yoktur, “Bilgi mevcut değil” gösterilir, CTA yoktur. `UNVERIFIED`: operasyonel değer kesin bilgi olarak gösterilmez, “Doğrulanamadı” gösterilir, CTA yoktur. `STALE`: son bilinen değer “Güncelliğini yitirmiş” etiketiyle gösterilebilir, refresh sunulur, CTA yoktur. `EXPIRED`: operasyonel değer kullanılmaz, “Süresi doldu” gösterilir, CTA yoktur. Kanıtı açıklayan `source_reference` bağlantısı satın alma/rezervasyon CTA'sından ayrıdır; güvenli URL ise provenance amacıyla gösterilebilir. Genel bloklar kritik gerçek slotu içermez. **PROVISIONAL öneri — `RULE-HOW-001-01`, `RULE-M3-002` ve `RULE-M3-006` ile en dar uyumlu seçenek.**
- **B — Her evidence slot kritiktir.** En güvenli sunum; kritik olmayan açıklayıcı kaynakları da gereksiz yere bloklar ve genel/operasyonel ayrımını zayıflatır.
- **C — Kritiklik provider/item türüne göre config edilir.** Daha esnek; taksonomi, config version, audit ve her provider/item kombinasyonu için ayrı ürün kararı gerektirir.

Kullanıcı seçimi: [x] A  [ ] B  [ ] C

Etkilenen: `RULE-HOW-001-01..02`, `FR-HOW-001-01..02`, `AC-HOW-001-01..03`, `NFR-AI-GRD-001`, `RULE-M3-002`, `RULE-M3-006`, `SCH-InstructionBlock`, `SCH-ContextualGuide`, CTA renderer davranışı.

Bloker sonucu: `OD-HOW-011` onaylanmadan `SCH-InstructionBlock` ve frontend sunum sözleşmesi kesinleşemez; bu nedenle ilk POI kapsamının contract paketi implementation-ready değildir.

## İstenen son kullanıcı onayı

- `OD-HOW-004-D1`: [x] A  [ ] B  [ ] C
- `OD-HOW-009-D1`: [x] A  [ ] B  [ ] C
- `OD-HOW-009-D2`: [x] A  [ ] B  [ ] C
- `OD-HOW-011`: [x] A  [ ] B  [ ] C

`OD-HOW-009-D2` sayısal retention değeri uydurmadan silme sınırını açık seçime bağlar.

DB CHANGE REQUIRED: NO (bu karar hazırlama görevi için).
