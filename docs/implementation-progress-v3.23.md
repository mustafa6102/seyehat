# v3.23 uygulama ilerleme kaydı

Bu belge normatif değildir. Sonraki çalışma oturumlarının tamamlanan işleri yeniden yapmadan devam edebilmesi için gerçek kod ve test durumunu özetler. Yetki kaynağı `registry/active_contract.json` ve onun işaret ettiği v3.23 sözleşme zinciridir.

## Tamamlanan ve korunacak işler

- FlightSearch `ONE_WAY` ve `ROUND_TRIP`, OD-022 D1-D4, eski tekliflerin okuma anında normalizasyonu, Duffel slice doğrulaması, reprice güzergâh koruması ve seçim yaşam döngüsü.
- Gidiş/dönüş Türkçe uçuş sunumu ve ilgili v3.23 şema/OpenAPI doğrulamaları.
- Kullanıcı ekranlarında TripRequest, konum, sağlayıcı, teklif, tesis ve POI teknik kimliklerinin kaldırılması.
- TripRequest durumu ve plan değişiklik gerekçeleri için güvenli Türkçe sunum; bilinmeyen iç kodlar ham gösterilmez.
- Kara ulaşımı ekranındaki `GND-001`, `UNKNOWN` ve `PARTIAL` kullanıcıya gösterilmez; doğrulanmamış kapsam Türkçe ve fail-closed açıklanır.
- Uçuş, konaklama ve POI plan yüzeylerinde provider/offer/property/POI kimlikleri; uçuş ayrıntısında provider anahtarları kullanıcıya gösterilmez.
- v3.13/v3.14/v3.15/v3.16/v3.19 aktif persistence dilimi migration-backed hale getirildi: uçuş seçimi, konaklama arama/seçim, POI arama/kayıt, Trip/PlanRevision ve HOW idempotency ledger. `room_count`, owner çifti, onaylı indeks/FK ve MySQL CHECK invariant'ları production şemaya taşındı.
- ACC-001 yerel production zinciri arama → kanonik sonuç/ayrıntı → yeniden doğrulama → kalıcı seçim/yeniden onay → Seyahat Planım sunumu olarak tamamlandı. Kriter yalnız kalıcı TripRequest'ten türetilir; ONE_WAY için çıkış tarihi üretilmez, PARTIAL/UNKNOWN fiyat toplam sayılmaz ve görünür UI sağlayıcı/teklif/tesis kimliği göstermez.

## Bu oturumun çalışma sırası

- [x] Mevcut değişiklikleri ve sekiz PHPUnit hatasını yeniden sınıflandır.
- [x] Kalan Blade/JavaScript kullanıcı metinlerini, ham enum/kod/kimlikleri ve İngilizce mesajları denetle.
- [x] Yetkili yerelleştirme ve erişilebilirlik düzeltmelerini uygula ve test et.
- [x] Aktif sözleşme ile gerçek kodu karşılaştırarak workstream envanterini güncelle.
- [x] Aktif uçuş/konaklama/POI/TripPlan depolamasını production migration ile kur ve testlerin elle oluşturduğu şemaları migration tabanına taşı.
- [x] ACC-001 bağımsız ve sözleşmece yetkili dikey dilimini odak PHP/API/persistence/UI regresyonlarıyla tamamla.

## Güncel workstream envanteri

Durumlar yalnız gerçek application + UI/API + persistence + test zincirine dayanır. Bir sınıfın veya boş dizinin varlığı tamamlanmış özellik sayılmaz.

| Workstream | Durum | Kanıt / kalan iş |
|---|---|---|
| Traveler / privacy | BLOCKED | DTO/repository mevcut; API bilinçli olarak 401 ile kapalı. Trip sahipliği, alan şifreleme, anahtar yönetimi, retention ve silme kararları açık. |
| FlightSearch / reprice / selection | DONE (v3.23 kapsamı) | ONE_WAY/ROUND_TRIP, D1-D4, altı operation ve seçim koruması testli. Gerçek provider smoke yapılmadı. |
| Accommodation | DONE (yerel sözleşme dilimi) / release-blocked | ACC-001 arama, sonuç, ayrıntı/yeniden doğrulama, seçim, reconfirmation, sahiplik, persistence ve Seyahat Planım UNKNOWN sunumu testli. Gerçek Duffel Stays capability/provider smoke ve MySQL 8 deployment kanıtı yok. |
| Trip building / daily itinerary | PARTIAL | Immutable PlanRevision, optimistic concurrency, POI ekleme/çıkarma, gün atama, sıra UI/API akışı ve production migration var; deterministik zaman/ulaşım fizibilite kapısı eksik. |
| POI / HOW | PARTIAL | Overpass sınırı, canonical POI, plan entegrasyonu, kaynak temelli HOW rehberi ve production migration var; gerçek provider smoke yok. |
| FOOD | BLOCKED | Aktif production modülü/operation yok; onaylı backend/provider sözleşmesi bulunmuyor. |
| EVT | PARTIAL / deployment-blocked | Event service, Ticketmaster adapter, queue, controller ve plan UI bağlantısı var; migration ve bağımsız feature/contract regresyon kanıtı eksik. Provider kapsamı ayrıca onay gerektiriyor. |
| Ground / door-to-door | BLOCKED presentation | Sahiplik korumalı fail-closed durum ekranları var; OD-003 nedeniyle rota/provider/fiyat/süre üretimi yok. |
| Transfer risk / visa / total cost | BLOCKED/PARTIAL | TransferFeasibility ve ConnectionProtection saf domain sınıfları var; otoritatif MCT/vize/FX/risk politikaları açık. Uçuş ve konaklama bilinen maliyetleri ayrı gösteriliyor, çapraz para birimi toplamı üretilmiyor. |
| AI planning / audits | NOT_STARTED | `AiReasoning` ve `Audit` dizinlerinde production sınıfı yok. Provider, schema, evidence ve privacy kararları çözülmeden başlanmayacak. |
| Dynamic replanning | NOT_STARTED | `Replan` production uygulaması yok; LIVE-001..004 kapsam dışı ve M5 kararları açık. |
| Price monitoring | BLOCKED | PRC-004 proposal/approval kaydı normatif active contract değil; snapshot/provider/schedule zinciri yok. |
| Preparation / offline | BLOCKED/NOT_STARTED | Onaylı PREP backend sözleşmesi ve offline istemci/sync kararı yok. |
| Reliability / production readiness | PARTIAL | Auth0, redaction, retry/rate-limit/circuit, concurrency ve aktif feature-storage migration ileri/geri akışı testli. Event storage, outbox relay/DLQ, gerçek MySQL deployment ve canlı servis kanıtı kalıyor. |

## Bilinen engeller

- Traveler API'yi doğrudan kullanan yedi test 401 alıyor: altı `TravelerTest`, bir `SensitiveDataProtectionTest`. API kısıtlaması OD-P8-TRV-SCOPE kapsamında bilinçlidir; sahiplik, Trip-Traveler yaşam döngüsü, şifreleme/anahtar yönetimi ve retention kararları çözülmeden koruma kaldırılmayacak.
- `GeoNamesLocationCatalogTest` provider profil listesini kapalı liste varsayıyor ve mevcut `ticketmaster` profilini beklenmeyen sayıyor. Provider kapsamı için yeni onay olmadan test allowlist’i genişletilmeyecek.
- FOOD-001, EVT-001 ve PREP-001 için onaylı backend/API/veri sözleşmesi yoktur; OD-030..035 açık kaldığı sürece işlev uydurulmayacak.
- LIVE-001..004 görev kapsamı dışındadır.

## Migration çakışması bulguları

- `2026_10_29_000000_create_active_feature_storage_tables.php` fresh SQLite şemasında tek başına temiz uygulanır; 61 error migration'ın aynı işlemi iki kez yapmasından değil, yedi feature testinin `migrate` sonrasında aynı owner kolonlarını ve aynı feature tablolarını tekrar `Schema::table` / `Schema::create` ile kurmasından kaynaklandı.
- Redundant test şemaları kaldırıldı: `HowGuideTest`, `OidcBearerAuthenticationTest`, `RoundTripFlightOfferTest`, `TripPlanApiTest`, `TripPlanDayAssignmentTest`, `TripPlanWebTest` ve `TripRequestOwnedOperationsAuthorizationTest` artık production migration zincirini kullanır.
- Migration; mevcut manuel production şeması ve repository erişimleriyle karşılaştırıldı. `room_count`, owner çifti, uçuş/konaklama seçimleri, accommodation/POI job tabloları, POI, Trip/PlanRevision ve HOW ledger kolonları doğrulandı; onaylı indeks adları, PlanRevision→Trip FK'si, mikrosaniye hassasiyeti ve MySQL CHECK invariant'ları eşlendi.
- Statik MySQL karşılaştırmasında Laravel `timestamps(6)` üretiminin onaylı `DATETIME(6) NOT NULL` tanımından ayrıldığı görüldü. Aktif feature-storage migration'ı açık `dateTime(..., 6)` kolonlarına geçirildi; `pois_provider_place_unique` ve `plan_revisions_trip_fk` adları production SQL/doğrulama betiğiyle eşlendi.
- Uygulanmış migration silinmedi veya yeniden adlandırılmadı; conflict gizleyen `hasTable`/`hasColumn` atlaması ya da geniş exception handling eklenmedi.

## MySQL 8 doğrulama durumu

- Bu makinede MySQL istemcisi/sunucusu ve Docker yoktur; yalnız `C:\Program Files\MariaDB 12.3\bin\mysql.exe` bulundu. MariaDB, istenen MySQL 8 kanıtı yerine kullanılmadı.
- Var olan veya dolu bir veritabanında migration komutu çalıştırılmadı. İzole in-memory SQLite üzerinde tam migration up/down başarılıdır.
- Migration zinciri, `database/manual/production_schema_update.sql` ve salt-okunur `verify_production_schema.sql` statik olarak karşılaştırıldı; kolon tip/nullability, mikrosaniye hassasiyeti, adlandırılmış indeks/FK ve CHECK DDL uyumsuzlukları migration içinde düzeltildi.
- MySQL 8 üzerinde hâlâ doğrulanmamış kontroller: gerçek up/down yürütmesi, `information_schema` sonuçlarının tamamının `MATCH/PRESENT` olması, InnoDB/collation, CHECK enforcement/definition, FK davranışı ve MySQL'in ürettiği kesin kolon/index metadatası. Bu nedenle MySQL 8 doğrulaması **geçti** sayılmaz.

## Production veritabanı denetim paketi

- Önceki workstream'lerin DB yüzeyi migration, repository ve manuel SQL üzerinden yeniden envanterlendi: Laravel queue tabloları; outbox; provider capability; TripRequest + journey/passenger/owner/room alanları; flight search/selection; ACC-001 search/selection; Traveler; POI; Trip/PlanRevision ve HOW idempotency ledger. OIDC için ayrı kullanıcı tablosu yoktur; sahiplik TripRequest'tedir. FlightOffer/reprice cache-backed, ground sunumu ve yerelleştirme kalıcı tablo gerektirmez. Shipped production örneğinde cache/session file-backed'tir.
- `database/manual/verify_production_schema.sql` yalnız v3.13-v3.16 aktif feature-storage alt kümesini doğruluyordu; base TripRequest/FlightSearch, queue, provider capability, Traveler, outbox, HOW ve Laravel migration tracking kapsamı eksikti. Yeni v3.23 teşhis/doğrulama dosyaları bu yüzeyleri tek pakette toplar.
- Migration zinciri accommodation/POI job tablolarında `(trip_request_id, status)` indeksleri üretir; eski manuel production SQL ve eski verify dosyası bu iki indeksi kapsamıyordu. Yeni verify paketi bunları açıkça bekler. Bunun production'da gerçek eksik olup olmadığı teşhis çıktısı gelene kadar `UNKNOWN`'dır.
- Traveler→Trip, contract-facing `trip_request_id`→`trip_requests.id` ve outbox→PlanRevision FK'leri tip/kimlik ve açık ADR/OD kararları nedeniyle bilinçli olarak eklenmez. Owner backfill, capability `supported=1` verisi, retention/encryption dönüşümü ve event tabloları bu pakette üretilmez.
- Production erişimi olmadığı için `database/sql/production-missing-changes-v3.23.sql` bilinçli olarak `NOT READY` ve DDL/DML içermeyen bir güvenlik kapısıdır. `production-schema-diagnostics-v3.23.sql` çıktısı incelenmeden corrective SQL güvenli kabul edilmez.
- phpMyAdmin sırası ve migration tracking sınırlamaları `database/sql/README-v3.23.md` içinde belgelendi. Laravel `migrations` tablosuna otomatik/kör kayıt eklenmez; manuel şema ile migration geçmişi ayrı kontrollü deployment adımında uzlaştırılmalıdır.

## Kullanıcı yüzeyi denetim özeti

- `resources/views` ve `public/assets` içindeki görünür metinler; ham durum/reason kodları, provider anahtarları ve teknik kimlikler için tarandı.
- Sunucu tarafından üretilen havacılık kodları kullanıcı için yararlı rota kısaltması olarak korunur. Provider/catalog kimliklerinden ad türetilmez. GeoNames adı yalnız mevcut katalog çözümlemesi başarılıysa gösterilir; aksi durumda doğrulanmamış bir ad uydurulmaz.
- `TripPresentation` TripRequest durumları, plan değişiklik gerekçeleri ve konum türleri için merkezi fail-closed sunum sınırıdır. Yeni iç değerler ham metin olarak gösterilmez.
- Blade yorumları ve `data-*` içindeki sözleşme/operation kimlikleri kullanıcı metni değildir; HTTP/persistence akışının çalışması için gereken opak kimlikler görünür içerikten ayrı tutulur.

## Son doğrulama

- Tam PHPUnit: 341 geçti, 8 başarısız (349 test / 5463 assertion). ACC regresyonlarına ek olarak iki production SQL güvenlik/envanter testi geçti; sekiz başarısızlık aynı yedi Traveler-kapısı testi ile bir provider-profile testidir, yeni regression yoktur.
- OIDC/Trip sahipliği odaklı paket: 11/11 başarılı (66 assertion).
- Persistence/OIDC/TripPlan/HOW odaklı paket: 72/72 başarılı (1602 assertion). Uçuş/konaklama/POI/TripPlan testleri artık aynı tabloları elle oluşturmadan production migration'ı kullanır.
- ACC odaklı PHP/API/persistence/UI: 5/5 başarılı (36 assertion). AC/TS-ACC-001-04/05/06/08/10 kapsamındaki kriter türetme, ONE_WAY fail-closed, idempotent arama, sahiplik, kalıcı UNKNOWN fiyat ve görünür kimlik sızıntısı regresyonları doğrulandı; mevcut AC/TS-ACC-001-07 yeniden onay davranışı korundu.
- Migration yapı testi: 2/2 başarılı (23 assertion); SQLite üzerinde ileri kurulum, gerekli tablo/kolon/indeks/FK ve aynı süreçte rollback doğrulandı. MySQL 8 gerçek sunucu doğrulaması yapılamadı.
- Yerelleştirme ve sunum odaklı PHPUnit: 49/49 başarılı (662 assertion).
- JavaScript: 47/47 başarılı. İki yeni ACC UI testi kanonik tesis adı, PARTIAL/UNKNOWN ücret sunumu, görünür teknik kimliklerin gizlenmesi ve CHANGED alanlarının açık onaydan önce listelenmesini doğruladı.
- Production SQL paketi: 2/2 başarılı (46 assertion). Teşhis/doğrulama dosyalarının read-only kaldığı, corrective dosyanın `NOT READY` kapısı olduğu ve pre-EVT persistence envanterini kapsadığı doğrulandı. MySQL 8 execution/parser doğrulaması yapılmadı.
- v3.23 contract doğrulaması önceki geçerli tabanda 80/80 başarılıdır; normatif sözleşme değiştirilmedi.
- `git diff --check` içerik hatası bildirmedi; yalnız mevcut CRLF/LF normalizasyon uyarıları vardır. Otomatik toplu format uygulanmadı.

## Sonraki kesin iş

Önce izole bir MySQL 8 ortamında tam migration up/down ve `verify_production_schema.sql` çalıştırarak bu oturumdaki statik düzeltmeleri gerçek motor üzerinde doğrulamak. Ardından EVT-001 active-contract şema/operation kayıtları ve Ticketmaster provider kapsam onayı doğrulanmalı; her ikisi de onaylıysa event production migration + bağımsız regresyon dilimine geçilmeli. Traveler 401 kapısı ve provider allowlist testi onay olmadan değiştirilmemeli.

## 2026-10-29 EVT-001 uygulama güncellemesi

- `OD-EVT-001-TM` aktif v3.23 zincirinde RESOLVED olarak doğrulandı: Ticketmaster Discovery API v2 yalnız `event_discovery` için yetkilidir. Capability kaydı/evidence olmadan provider çağrısı yapılmaz; allowlist genişletilmedi. Bilet host listesi için kod içi varsayılan kaldırıldı ve yalnız açık ortam yapılandırması kabul edildi.
- RULE-EVT-001-01/02/03, FR-EVT-001-01 ve AC/TS-EVT-001-01/02/03 yerel dilimde tamamlandı: TripRequest kaynaklı şehir/tarih kriteri, varış IANA saat diliminden UTC dönüşümü, canonical kaynak/evidence, iptal/offsale/UNKNOWN fail-closed davranışı, doğrulanmış etkinliğin `EVENT/FIXED` PlanRevision snapshot'ı, sahiplik/idempotency ve Türkçe sonuç/plan sunumu.
- ONE_WAY talepte olmayan bir bitiş tarihi üretilmez. Legacy/esnek başlangıç tarihi, bilinmeyen IANA saat dilimi veya yapılandırılmamış arama yarıçapı fail-closed Türkçe hata verir.
- `events` ve `event_discovery_jobs` Laravel migration zincirine eklendi; canonical JSON, mikrosaniyeli `DATETIME(6)`, sağlayıcı/ref unique indeksi, idempotency unique indeksi ve TripRequest arama indeksi manuel EVT şemasıyla eşlendi. v3.23 diagnostic/verify SQL ve `database/manual/verify_production_schema.sql` salt-okunur EVT kontrolleriyle genişletildi.
- GeoNames testi, yeni bir profil yetkilendirmek için değil, zaten RESOLVED `OD-EVT-001-TM` ile mevcut Ticketmaster profilini tanımayan eski kapalı-list test kusurunu düzeltmek için güncellendi.
- Aktif `od_impact.json` kararı ile OpenAPI/contract index/traceability paketi arasında tutarsızlık sürüyor: EVT discovery/detail/plan operasyonları OpenAPI ve contract index'te yayınlanmamış, traceability kaydı eski blocker metnini taşıyor. Normatif dosyalar değiştirilmedi; provider kapsamı event discovery dışına çıkarılmadı.
- Odak doğrulama: EVT PHP/domain 3/3, EVT API/sahiplik/persistence 2/2, migration up/down 2/2, production SQL güvenlik/envanter 2/2 ve EVT JavaScript UI 3/3 geçti. Tüm JavaScript paketi 50/50 geçti.
- Tam PHPUnit: 348 geçti, 7 başarısız (355 test / 5499 assertion). Önceki sekiz blocker içindeki eski GeoNames/Ticketmaster profil uyuşmazlığı sözleşme destekli test düzeltmesiyle kapandı; kalan yedi hata değişmeyen Traveler/PII 401 kapısıdır. EVT kaynaklı yeni regresyon yoktur. BrowserScript EVT wrapper eklendikten sonraki odak doğrulaması ayrıca geçmiştir.
- MySQL 8 runtime hâlâ yoktur. EVT migration up/down, kesin MySQL metadata/indeks davranışı ve `verify_production_schema.sql` gerçek sonuçları **PENDING**; SQLite sonucu MySQL kanıtı sayılmaz. Production schema diagnostic çıktısı gelmeden `production-missing-changes-v3.23.sql` **NOT READY** kalır.

## EVT-001 sonrası kesin yetkili iş

İzole MySQL 8 üzerinde tüm migration zincirini ileri/geri çalıştırmak ve `database/manual/verify_production_schema.sql` ile v3.23 verification SQL sonuçlarını doğrulamak. Uygulama workstream'i olarak EVT kapsamını genişletmeden önce OpenAPI/contract-index/traceability tutarsızlığının onaylı sözleşme sürümünde giderilmesi gerekir; FOOD/PREP ve LIVE-001..004 bu kayıtla yetkilendirilmiş sonraki iş değildir.

## 2026-10-29 EVT-001 sözleşme uzlaştırma ve DB doğrulama denetimi

- Normatif dosyalar değiştirilmedi. Non-normative öneri `docs/proposals/evt-001-contract-reconciliation-v3.23/README.md` altında hazırlandı.
- OD-EVT-001-TM od_impact içinde RESOLVED olmasına rağmen decision source olan `registry/v3.22/decisions.json` içinde kayıtlı değil. OpenAPI public EVT path'lerini, contract index public operasyon/canonical şemaları, permissions owner kanallarını ve lifecycle/reason-error EVT durumlarını içermiyor. Traceability AC-EVT-001-02 için “plan persistence not active” diyor ve blocked_unchanged içinde EVT-001 plan persistence tutuyor.
- FR-EVT-001-02 ulaşım/buffer entegrasyonu gerçek açık karar kapsamıdır ve uygulanmış sayılmadı. Provider lisans/mapping ve production capability evidence açık kaldı; runtime capability gate korunuyor.
- Kanıtsız supported=1/FRESH yazabilen `database/manual/ticketmaster_capability_seed.sql` DML'si kaldırıldı; dosya NOT READY ve salt-okunur sorgu haline getirildi. Provider allowlist veya runtime capability statüsü değiştirilmedi.
- MySQL 8, mysqld, Docker ve Podman bulunmuyor. Yerel bağlantıda production uyarısı görüldü ve EVT migration Pending durumdaydı; hiçbir migration çalıştırılmadı. MySQL 8 doğrulaması PENDING kaldı.
- İzole MySQL 8 up/down/up ve verification prosedürü `database/sql/MYSQL8-ISOLATED-VALIDATION-v3.23.md` içinde hazırlandı. Corrective SQL hâlen NOT READY ve DDL/DML içermez.
- Doğrulama: 25 aktif/miras sözleşme JSON dosyası parse edildi; Composer manifest geçerli; EVT/TripPlan/migration/SQL güvenlik/browser odak paketi 36/36 geçti; JavaScript 50/50 geçti. Tam PHPUnit 348 geçti, değişmeyen 7 Traveler/PII 401 testi başarısız oldu (355 test, 5501 assertion); yeni regresyon yok.

## Sonraki yetkili görev

Contract authority'nin uzlaştırma önerisini inceleyip versioned normative delta için açık onay vermesi; bağımsız olarak izole MySQL 8 ortamında belgelenen up/down/up ve read-only schema verification prosedürünün yürütülmesi. Production için ilk adım yalnız `production-schema-diagnostics-v3.23.sql` çıktısının alınması ve incelenmesidir.
