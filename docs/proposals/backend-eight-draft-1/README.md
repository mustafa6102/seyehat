# Sekiz backend grubu — backend-eight-draft-1

Durum: **PROPOSED_NON_NORMATIVE**, 2026-10-28. Taban: aktif Implementation Contract **3.21.0** ve devraldığı sözleşmeler. Hedef aktif sürüm henüz atanmadı. `day-001-v3.22` veya `prc-004-draft-1` bu paketle etkinleşmez. Hiçbir OD/ADR kapatılmadı.

Bu, onaylanacak sözleşme paketidir; tüm özelliklerin bugün kodlamaya hazır olduğu iddiası değildir. Şema ve operasyon taslakları somuttur. Karar sahiplerinin doldurması gereken değerler `decisions.md` içinde açıkça ayrılmıştır. İlgili karar satırları ve konfigürasyonlar tamamlanmadan o dilim aktive edilemez; genel bir “paketi onaylıyorum” ifadesi eksik sağlayıcı, eşik, hukuki dayanak veya kriptografi kararını yerine geçirmez.

## Dosyalar

- `manifest.json`: yerel öneri kimliği, kapsam, her operasyonun aktivasyon kapısı.
- `schemas.json`: kapalı nesneler kullanan JSON Schema 2020-12 kanonik veri kataloğu; aktif Money/Sourced/Derived/POI/PrincipalRef sözleşmelerine gerçek dosya referansları.
- `openapi.json`: OpenAPI 3.1 önerisi; kesin HTTP metotları, yollar, request/response şemaları ve ön koşullar.
- `records.md`: izinler, lifecycle, reason/error, hesaplama ve entegrasyon kuralları.
- `persistence.md`: SQL olmayan mantıksal kayıt, anahtar, transaction ve saklama sözleşmesi.
- `decisions.md`: en küçük onay listesi, eksik kayıtlar ve aktivasyon sırası.
- `traceability.json`: mevcut RULE/FR/AC → önerilen operasyon/şema/kapı; yeni bildirim Story ve AC'leri ayrı PROPOSED ad alanında.

Tüm `P8-*`, `SCH-*-P8` ve `OP-*-P8` kimlikleri yalnız bu pakete aittir. Aktif registry'ye eklenmemiştir. Mevcut OP-getTraveler gibi kimlikler aktifleştirmede yerinde sürümlenir; ikinci bir aktif kimlik yaratılmaz. Öneri dosyaları runtime tarafından yüklenmez.

## Mevcut kaynaklardan alınan teknik kararlar

OIDC `(issuer, subject)` kimliği ve değişmez TripRequest owner: v3.16 ADR-010. Trip owner'ı `Trip.trip_request_id` üzerinden türetilir; principal, rol veya owner istemci gövdesinden alınmaz. OD-028 web kanalı: uygun operasyonların aynı handler'ları oturum + CSRF ile `/web-api/v1` altında kullanılır. API yolları mevcut `/api/v1` öneki altında sunulur.

MySQL 8: ADR-009. Yeni kalıcı mutasyonlarda DB unique ile idempotency: ADR-002. Plan değişikliği + immutable revision + outbox tek transaction: ADR-003, OD-027 ve v3.16. İlk plan değişikliği `If-None-Match: *`, sonraki `If-Match: "version"`; geçmiş revision JSON'u yeniden yazılmaz. Money integer minor units; UNKNOWN sıfır değildir. Kanıt metadata'sı Sourced/Derived ve Bölüm 6.2'den alınır. FRESH olmayan veri güvenli/aktif satın alınabilir iddiası doğurmaz.

## Son üretim değişikliklerinin etkisi

TravelerController şu anda boş yetki kapsamıyla matrise gider ve authenticated kullanıcıya da 403 döner; routes/api.php bearer ister. Bu yalnız erişim kapatmasıdır, TRV teslimi değildir. P8-TRV aktive edildiğinde gerçek server-owned binding/grant çözümleyicisi bu boş kapsamı değiştirir; sürekli 403 bir başarı ölçütü değildir. Mevcut `DatabaseTravelerRepository::save` global id üzerinden `updateOrInsert` ve düz JSON yazar; bu davranış yeni izinli akışta kullanılmaz. Mevcut veriler kendiliğinden sahiplenilmez veya şifreli ilan edilmez.

RCG mevcut `BoundedCandidateGenerator`, `SearchBudgetLedger`, `ConfigSearchBudgetPolicy` üzerinden ilerler. Son değişiklikler yanlış/boş kanıt ve geçersiz bütçe rezervasyonunu reddeder; production seed/evidence/leg adapter sağlamaz. İç `seed:<id>` etiketi provider-backed provenance yerine kullanılamaz. Uygulamada yeni kanonik sonuç DTO'suna açık mapping gerekir.

Mevcut `trips`, `plan_revisions`, POI/konaklama ve HOW tablolarının bir kısmı yalnız manual DDL paketlerinde tanımlı; canlı DB varlığı doğrulanmadı. Bu paket mevcut şemaların kurulu olduğunu varsayarak deployment kanıtı üretmez.

## Aktivasyon sonrası gerçek işler

Onaylanan alt dilimler birbirinden bağımsız aktive edilebilir. RCG'nin direkt/tek-hub üretimi ground onayını beklemeden başlayabilir; ground kolu PARTIAL kalır. STF koruma/timeline dilimi risk eşiklerini uydurmadan UNKNOWN risk taşıyabilir. TRF yalnız doğrulanmış girdilerle hesaplanabilir; fallback ayrı onay ister. FOOD/EVT keşif ve kanıt sunumu tam DAY gate'i beklemez; yerleştirme NOT_EVALUATED kalır. Bunlar tam Story tamamlığı değildir.

TRV hukuki/güvenlik profili, PRC yönetişim ve retention, bildirim Story/kanal politikası eksikken ilgili endpoint yayımlanmaz. Placeholder endpoint, boş başarılı provider sonucu veya sürekli 403 ürün akışı oluşturulmaz. Kullanılabilir bir kayıtlı provider olmaması gerçek aramada 503; başarılı boş arama ise SUCCEEDED + boş listedir, ikisi birbirine dönüştürülmez.
