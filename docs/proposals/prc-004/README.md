# PRC-004 uygulama öncesi karar paketi

Durum: **PROPOSAL / PROVISIONAL**. Bu dizin aktif sözleşmeyi veya OD/ADR kayıtlarını değiştirmez.

## Onaylı gereksinimler

- Story: `PRC-004` — Fiyat geçmişi ve veri yetersizliği; `BUS-001`, `BUS-004`, `EPIC-10`, `FEAT-PRICE-HISTORY`, faz `M2`.
- Story amacı: geçmiş fiyat verisi yeterliyse bağlamlı fiyat değerlendirmesi, yetersizse açık veri-yetersizliği göstermek.
- `RULE-PRC-004-01`: Yeterli snapshot yoksa “veri yetersiz” denir.
- `RULE-PRC-004-02`: Geçmişte görülmemiş fiyat garanti/hedef olarak sunulmaz.
- `RULE-PRC-004-03`: Yeterlilik eşiği ve outlier politikası versiyonlu config ile yönetilir.
- `FR-PRC-004-01`: Snapshot `source` ve `fetched_at` ile saklanır.
- `AC-PRC-004-01`: Yetersiz geçmişte keyfi hedef fiyat üretilmez.
- `AC-PRC-004-02`: Yeterli karşılaştırılabilir snapshot varsa snapshot sayısı, tarih aralığı ve kaynak gösterilir.
- `AC-PRC-004-03`: Outlier politikası uygulanır; outlier değer hedef fiyat olarak sunulmaz.
- `TS-PRC-004-01` “Yeni rota” → `AC-PRC-004-01`.
- `TS-PRC-004-02` “Yeterli snapshot” → `AC-PRC-004-02`.
- `TS-PRC-004-03` “Outlier” → `AC-PRC-004-03`.
- `NFR-AUD-001`: Kritik kararlar rule/config version, input snapshot ve evidence version ile audit edilir.

Kanonik veri ipucu `PriceSnapshot`, route/fare/baggage/currency anahtarları ve `fetched_at` ile sınırlıdır. Sayısal eşik, karşılaştırılabilirlik ayrıntısı, outlier yöntemi veya sonuç API şeması onaylanmış değildir.

## Mevcut durum

`docs/implementation-audit.md` PRC-004'ü **NOT_STARTED** sayar: price-history storage, API ve UI yoktur. Mevcut uçuş fiyat kodu güncel `Money`, offer/snapshot ve source/freshness verisini işler; tarihsel `PriceSnapshot` deposu, yeterlilik motoru veya outlier politikası içermez.

Aktif v3.19 contract index içinde PRC-004 operasyonu, JSON Schema, reason/error kaydı, lifecycle veya permission kaydı yoktur. Bunlar kararlar onaylandıktan sonra yeni sürüm taslağında tanımlanmalıdır; mevcut v3.19 sessizce genişletilmemelidir.

## Mevcut kararlar ve bağımlılıklar

- `ADR-002` ACCEPTED: PRC-004 snapshot'ları OLTP'de ayrı, zaman bazlı bölümlenmiş tabloda tutulur.
- `ADR-009` ACCEPTED (v3.3): Laravel web/API OLTP motoru MySQL 8'dir. PRC-004 DDL'i yine de snapshot schema'sı ve `OD-013` retention/privacy kararı onaylanmadan hazırlanamaz.
- `OD-006` OPEN: notification kanalları; mevcut impact kaydı tüm M2 development'ı bloke eder, fakat PRC-004 gereksinimlerinde bildirim yoktur. PRC-004'ün bildirim dışı ilk kapsamının bu bloktan ayrılıp ayrılmayacağı açıkça onaylanmalıdır.
- Production MySQL `2002` bağlantı hatası yalnız deployment/runtime doğrulama engelidir; PRC-004 gereksinimi veya ürün kararı değildir.

## Taslak karar seçenekleri

Bu seçenekler mevcut registry'de onaylı değildir.

### OD-PRC-001 — Karşılaştırılabilir snapshot kümesi

Soru: Route/fare/baggage/currency alanları hangi eşitlik kuralıyla tek değerlendirme kohortu oluşturur?

- A: Yalnız kanonik alanların tamamında kesin eşleşme; eksik/UNKNOWN anahtar kohorta alınmaz. En dar kapsam ve çapraz ürün karşılaştırması riski en düşük.
- B: Bazı alanları eşdeğerlik gruplarıyla birleştirme. Daha çok veri sağlar; yeni taksonomi ve ürün kuralları gerektirir.
- C: Provider'a göre ayrı kohort. Kaynak tutarlılığı yüksek; sağlayıcılar arası değerlendirmeyi engeller.

**PROVISIONAL öneri: A.** Etkilenenler: `RULE-PRC-004-01/03`, `AC-PRC-004-01/02`, `TS-PRC-004-01/02`, gelecekteki `SCH-PriceSnapshot`.

### OD-PRC-002 — Veri yeterliliği politikası

Soru: Bir kohort hangi onaylı koşullarda “yeterli” sayılır?

- A: Product/Data tarafından ayrıca sağlanacak minimum snapshot adedi ve minimum zaman kapsamının ikisi de versiyonlu config'te zorunlu olur; değer yoksa sonuç `INSUFFICIENT_DATA` olur.
- B: Provider/kohort bazlı farklı eşikler. Esnektir; kalibrasyon, override ve audit yüzeyi büyür.
- C: Eşik onaylanana kadar yalnız `INSUFFICIENT_DATA`. Güvenlidir fakat `AC-PRC-004-02` tamamlanamaz.

**PROVISIONAL öneri: A.** Bu öneri hiçbir sayı belirlemez; uygulamadan önce iki değer ve sahip/config version ayrıca açıkça onaylanmalıdır. Etkilenenler: `RULE-PRC-004-01/03`, `AC-PRC-004-01/02`, `TS-PRC-004-01/02`, `NFR-AUD-001`, gelecekteki `CFG-PRC-SUFFICIENCY`.

### OD-PRC-003 — Outlier politikası

Soru: Outlier hangi onaylı deterministik kuralla işaretlenir ve değerlendirmeye nasıl etki eder?

- A: Product/Data tarafından ayrıca onaylanan yöntem ve parametreler versiyonlu config olur; config yoksa hedef/değerlendirme üretilmez.
- B: Yalnız provider'ın açık outlier/invalid işareti kullanılır. Yerel istatistik uydurmaz; provider işareti yoksa `AC-PRC-004-03` kapsamı daralır.
- C: Otomatik outlier ayrımı yapılmaz. Veri kaybı riski düşer; `AC-PRC-004-03` karşılanmaz.

**PROVISIONAL öneri: A.** Yöntem, parametre veya numeric sınır önerilmemiştir ve ayrıca onaylanmalıdır. Outlier snapshot silinmez; değerlendirmeye dahil edilme durumu ile policy/config version audit edilir. Etkilenenler: `RULE-PRC-004-02/03`, `AC-PRC-004-03`, `TS-PRC-004-03`, `NFR-AUD-001`, gelecekteki `CFG-PRC-OUTLIER`.

### OD-PRC-004 — İlk sözleşme yüzeyi

Soru: İlk sürüm hangi kullanıcı işini ve sonucu sözleşmeye bağlar?

- A: Owner-only, salt-okunur fiyat geçmişi değerlendirmesi; sonuç `INSUFFICIENT_DATA` veya yeterli veri özeti, snapshot count/date range/source ve policy/config version taşır. Bildirim ve hedef fiyat üretimi kapsam dışıdır.
- B: Değerlendirme ile notification aboneliğini birlikte açma. `OD-006` çözümü ve daha geniş lifecycle/outbox kapsamı gerekir.
- C: Yalnız internal servis; Türkçe frontend yok. Story'nin kullanıcı görünürlüğünü tamamlamaz.

**PROVISIONAL öneri: A.** Yeni contract taslağı için operation, permission, lifecycle, reason/error ve response schema gerekir. Etkilenenler: `PRC-004`, `FR-PRC-004-01`, `AC-PRC-004-01/02/03`, `NFR-AUD-001`.

### OD-PRC-005 — M2/OD-006 kapsam ilişkisi

Soru: Notification içermeyen PRC-004 ilk dilimi, `OD-006` açıkken ilerleyebilir mi?

- A: Evet; `OD-006` yalnız `FEAT-NOTIFICATION` ve notification içeren sonraki PRC kapsamını bloke eder.
- B: Hayır; mevcut “M2 development” bloklaması korunur ve önce `OD-006` çözülür.

**PROVISIONAL öneri: A.** Bu seçim aktif impact kaydı değiştirilmeden uygulama yetkisi vermez; sonraki sözleşme sürümünde açık traceability kaydı gerekir.

## Hâlâ zorunlu onaylar

2026-10-25 kullanıcı seçimleri `draft-1/contract-delta.yaml` içinde PROPOSED/NON-NORMATIVE olarak kaydedilmiştir. Aktif contract kaydı, politika owner/version/effective_at alanları, kanonik alan eşlemeleri ve `OD-013` onayı tamamlanmadan `AC-PRC-004-01/02/03` uygulanamaz; snapshot schema'sı ve retention sözleşmesi aktif olmadan DDL hazırlanamaz.
