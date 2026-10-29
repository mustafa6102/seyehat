# Önerilen operasyon, domain ve registry kayıtları

Tüm yeni semantik PROPOSED'dır. `schemas.json` biçim sınırını, bu dosya çapraz alan ve işlem sınırlarını tanımlar. Şema validasyonu tek başına kanıt doğrulaması değildir.

## Ortak HTTP, permission ve idempotency

- Değerlendirme sırası: AUTHN 401 → kök kaynak 404 → kök owner/Traveler grant 403 → alt kaynağın köke bağlılığını doğrula (uyuşmazlık 404) → şema/ön koşul → idempotency → domain değişikliği. Başka owner'ın job/candidate/place/event kaydı taşınamaz.
- `PERM-P8-OWNER`: RCG/STF/TRF/PRC/FOOD/EVT/watch/notification owner-only; collaborator, traveler_data_viewer, support_agent DENY. Worker yalnız doğrulanmış job bağlamında POLICY-SYSTEM-001; kamu bearer token'ıyla worker rolü kazanılmaz.
- `PERM-P8-TRAVELER`: basic metadata listelemede Trip owner veya accepted self binding; tam profil ve belgelerde self ya da açık, süreli, iptal edilmemiş action grant. Trip owner olmak başka yetişkinin belge erişimini otomatik vermez. Listeleme yalnız erişilebilen tam profilleri döndürür; yetkisiz profil sayısı veya belge alanı sızdırmaz. Minör işlemleri P8-TRV onayında temsil kanıtı tanımlanana kadar aktive edilmez; yetişkin dilimi minör desteği olarak raporlanmaz.
- Grant veren yalnız doğrulanmış adult self binding; ilk dilimde grant alıcısı yalnız `TRIP_OWNER`, sunucu TripRequest owner principal'ına çözer. Arbitrary principal veya binding_id istemciden kabul edilmez. Revocation bir sonraki okumada ve replay'de etkili. Invite kabulü ve sahiplik kanıtı için `decisions.md` kapısı zorunlu.
- Her mutasyonda Idempotency-Key 1..255. Kapsam authenticated principal + operation + kök kaynak; fingerprint method/path/kanonik gövde/ön koşul. Aynı anahtar/aynı istek ilk HTTP sonucunu yeniden döndürür; farklı istek 409. AuthZ replay öncesinde yeniden kontrol edilir. Eşzamanlı aynı anahtar bekleme veya mevcut v3.19 IN_PROGRESS 409 sözleşmesiyle çözülür; ikinci side effect yok.
- Kaynak değiştiren PUT/DELETE güçlü `If-Match: "version"` ister. Profile/grant/watch sürümü PlanRevision değildir. Eksik/biçimsiz precondition 400; stale sürüm 409 `RC-CONFLICT-REVISION-STALE` kapsam genişletmesi PROPOSED. Creation 200 + Location/ETag, GET versioned kaynakta ETag; asenkron start 202 + Location=poll_url. Plan işlemleri v3.16 ilk/sonraki mutasyon ön koşullarını aynen kullanır.
- Job kabulü ve ledger atomik kaydedilir, queue dispatch after-commit. Queue tekrar teslimi job lock/state ile tekrar provider araması başlatmaz. İdempotency yalnız cache'e emanet edilmez.
- GET hiçbir provider çağrısı, veri yenilemesi veya outbox yazımı yapmaz. Yeniden arama yeni start isteğidir; aynı anahtar eski job'ı döndürür. POST/read-ack dışında kabul edilmeyen gövde/query alanları 400.

Aktif hata tekrar kullanımı: RC-VALIDATION-SCHEMA/400, RC-AUTHN-REQUIRED/401, RC-AUTHZ-DENIED/403, RC-RESOURCE-NOT-FOUND/404, RC-CONFLICT-IDEMPOTENCY-KEY/409, RC-CONFLICT-REVISION-STALE/409, RC-PROVIDER-TIMEOUT/503, RC-PLATFORM-RATE-LIMITED/429. ErrorEnvelope message_tr Türkçe, correlation_id mevcut altyapıdan, details PII/ham provider içermeden. Listedeki tüm kodlar tüm operasyonlar için zorunlu hata değildir.

Yeni öneri reason kayıtları (aktivasyonda ayrı registry kaydı yapılır):

| Kimlik / machine_code | Tür/HTTP | Koşul ve Türkçe mesaj |
|---|---|---|
| RC-P8-SOURCE-UNAVAILABLE / SOURCE_UNAVAILABLE | ERROR/503 | Onaylı kaynak kullanılamıyor. “Veri kaynağı şu anda kullanılamıyor.” |
| RC-P8-EVIDENCE-INSUFFICIENT / EVIDENCE_INSUFFICIENT | WARNING/null | Girdi eksik/eski. “Bu bilgi doğrulanamadı.” |
| RC-P8-UNPROTECTED / CONNECTION_UNPROTECTED | DECISION_REASON/null | Ayrı bilet kanıtı. “Bağlantı ayrı biletlerden oluşuyor.” |
| RC-P8-PROTECTION-UNKNOWN / PROTECTION_UNKNOWN | DECISION_REASON/null | Koruma kanıtı yok. “Bağlantı koruması doğrulanamadı.” |
| RC-P8-RECLAIM / BAGGAGE_RECLAIM_REQUIRED | DECISION_REASON/null | Kaynaklı reclaim gereksinimi. “Bagajı teslim alıp yeniden vermeniz gerekiyor.” |
| RC-P8-TERMINAL / TERMINAL_TRANSFER_REQUIRED | DECISION_REASON/null | “Terminal değişimi gerekiyor.” |
| RC-P8-AIRPORT / AIRPORT_TRANSFER_REQUIRED | DECISION_REASON/null | “Havalimanı değişimi gerekiyor.” |
| RC-P8-TRANSFER-SHORT / TRANSFER_WINDOW_INSUFFICIENT | DECISION_REASON/null | Mevcut süre gereken süreden kısa. “Bağlantı için yeterli süre yok.” |
| RC-P8-CUTOFF / CHECK_IN_CUTOFF_MISSED | DECISION_REASON/null | Kaynaklı cutoff kaçırılıyor. “Son check-in saatine yetişilemiyor.” |
| RC-P8-FALLBACK / TRANSFER_DURATION_ESTIMATED | WARNING/null | “Bağlantı süresi tahminidir.” |
| RC-P8-CLOSED / PLACE_CLOSED_IN_WINDOW | DECISION_REASON/null | “Mekân seçilen zaman aralığında kapalı.” |
| RC-P8-EVENT-INACTIVE / EVENT_NOT_ACTIVE | DECISION_REASON/null | “Etkinlik iptal edilmiş veya satışa uygun değil.” |
| RC-P8-SELECTION-STALE / SELECTION_EVIDENCE_STALE | ERROR/409 | Seçim gözlem zamanı değişti. “Güncel bilgileri inceleyip yeniden onaylayın.” |
| RC-P8-INPUT-UNSUPPORTED / INPUT_UNSUPPORTED | ERROR/422 | Desteklenmeyen istek/bağlam. “Bu koşullarla işlem yapılamıyor.” |

PRC reason kayıtları mevcut taslak isimleriyle ayrı kalır: RC-PRC-COHORT-MAPPING-UNRESOLVED, RC-PRC-SUFFICIENCY-CONFIG-UNRESOLVED, RC-PRC-OUTLIER-CONFIG-UNRESOLVED, RC-PRC-HISTORY-INSUFFICIENT. Bunlar WARNING/null, Türkçe açıklama ve exact config_version ile döner; `CONFIGURATION_UNRESOLVED` gerçek boş geçmiş yerine kullanılmaz. RC-SEARCH-BUDGET-EXHAUSTED mevcut kayıt aynen korunur.

## TRV-001

Mevcut Traveler alanları korunur; id/trip_id istemci create gövdesinden çıkarılıp sunucuda üretilir/path'ten bağlanır. Önerilen ilk dilim **mevcut Trip üzerinde** çalışır; profil oluşturmak için sessiz Trip veya boş PlanRevision yaratılmaz. Plan öncesi yolcu girişi ayrıca OD-021 ürün kapsamı kararı gerektirir. Eski `POST /travelers` aktivasyonda aynı servise açık bir v-next request delta ile taşınmalı veya kaldırılması onaylanmalıdır; sessiz geriye uyumsuzluk yok.

Create insert-only; var olan id asla updateOrInsert ile ele geçirilmez. Update immutable trip bağlantısını değiştirmez. Koleksiyonlar replace-all: eksik alan 400, boş liste bilinmiyor anlamında kalır. Hukuki belge doğrulaması istemci verification_status ile yükseltilemez; kullanıcı girdisi UNVERIFIED, yetkili evidence adapter dışında FRESH yazılamaz. Age kategorileri ve document field/code dictionary P8-TRV-READINESS konfigürasyonuyla sürümlenir; ADULT/CHILD sınırları burada uydurulmaz.

İlk create yetkisi: Trip owner yalnız `age_category` beyanı ve dört boş koleksiyonla davet edilecek bir taslak profil oluşturabilir; başka kişinin nationality veya belge verisini binding/grant öncesi yükleyemez. Yanıt id/trip/version ve boş koleksiyonlardır, tamamlanmış profil değildir. Bu minimal veri toplama da P8-TRV Legal onayına tabidir. Adult self bağlandıktan sonra kendi profilini tamamlar veya Trip owner'a action grant verir. Tam profil yanıtı bütün belge koleksiyonlarını içerdiği için READ_PROFILE + READ_DOCUMENTS beraber gerekir; yalnız basic yetkiyle belge döndürülmez. Update için karşılık gelen iki write action da gerekir. `createTravelerInvitation` yanıtı binding + expires_at içerir; token yalnız onaylanan güvenli teslim kanalındadır.

Readiness yalnız input completeness: gereken path eksikse NEEDS_VERIFICATION + JSON Pointer listesi; tamam ise INPUTS_COMPLETE. ALLOWED/ülkeye giriş onayı üretmez. Gerekli alan profilinin kaynağı onaylanmamışsa bu operasyon aktive edilmez; bütün yolculara sahte eksiklik listesi üretilmez. Aktif Visa/ResidencePermit şemaları yalnız verification_status içerdiğinden gerçek uyum için ek alan kataloğu onayı gereklidir.

Binding lifecycle PENDING → ACCEPTED veya REVOKED; ACCEPTED → REVOKED. Invite token tek kullanım, expiry zorunlu, hash at rest; token replay sonuç ledger'ında plaintext tutulmaz. Kabulde grant kendiliğinden oluşmaz. Grant ACTIVE → REVOKED/EXPIRED; scope genişletmesi yeni grant/onaydır. Profil yetki kontrolü decrypt'ten önce; audit alan değerlerini/token/principal'ı yazmaz. Invite token kimin hangi gerçek yolcusu olduğunu tek başına kanıtlamaz: güvenli teslim ve adult self attestation/kimlik eşlemesi P8-TRV kapısıdır.

Invitation accept response'unda secret yoktur; idempotent replay mevcut binding'i döndürür. Expired/revoked token ve başka self principal tarafından tüketilmiş token 403; aynı idempotency key ile başarılı kabul replay'i auth/grant revocation kontrolünden sonra döner. Profil silme API'si bu ilk dilimde önerilmedi: zorunlu erasure işlemi onaylı PLT-PII-001 worker/subject-rights süreciyle yürütülmeden TRV production aktive edilmez. Yalnız create/read/update privacy lifecycle'ı tamamlamaz.

## RCG-001

Server TripRequest'ten onaylı FlightSearchCriteria üretir; istemci hub, fiyat veya provider payload gönderemez. Candidate kimliği job içinde opak ve kararlı. Outbound/return yönleri ayrı; ROUND_TRIP için iki direction eksikse tam sonuç iddiası yok. Tek-hub leg sorguları one-way criteria kullanır; tarih/bağlantı penceresi türetimi P8-RCG policy onayı olmadan başlatılmaz. Alternatif varış kolu ground evidence yoksa UNRESOLVED; ground route uydurulmaz.

Seed manifest immutable version + sıralı hub/arrival kimlikleri + evidence_refs içerir. Aynı nokta, origin/destination ve duplicate elenir. Her pruning check kendi referansıyla PASS/FAIL/UNKNOWN; herhangi FAIL provider çağrısından önce PRUNED, UNKNOWN UNRESOLVED. Savings/coverage/compliance heuristiği veya seed seçimi kodda icat edilmez.

Budget provider/operation/job kapsamındadır. Retry dahil her outbound HTTP attempt bir request; cache hit tüketmez, queue replay yeni bütçe açmaz. Bir adayın zorunlu leg ilk-attempt rezervasyonu atomik; kota yetmezse hiçbiri çağrılmaz. Retry kalan kotadan rezerve edilir; yetersizse yeni attempt yok, sonuç PARTIAL. Attempt gönderilmeden önce durable counter artar; ağ sonucunun bilinmemesi bütçeyi geri vermez. Crash recovery at-least-once execution bütçe sınırını aşamaz. Bu, mevcut in-memory ledger'ın production genişletmesidir.

Job QUEUED → RUNNING → SUCCEEDED/PARTIAL/FAILED. SUCCEEDED yalnız bütün kapsam çözülmüşse; no-result araması COMPLETE olabilir. Missing budget/provider/pruning kapsamı PARTIAL; hiçbir yetkili provider çağrısı yapılamıyorsa accepted job FAILED + kayıtlı neden. Kuyruğa almadan bilinen deployment/config hatası 503. Oluşmamış job için fake job_id yok. Sıralama candidate seed sırası; fiyat/risk comparator OD-007 bu paketin kapsamında değil.

## STF-003 ve TRF-001

Read model job/candidate'a bağlı sunucu snapshot'ından hesaplanır. Client protection, risk, MCT veya süre gönderemez. Risk/transfer sonucu RouteOption'ın mevcut cost şemasına uygunsuz alan eklemez; ayrı response. `NO_ADDITIONAL_RESTRICTION` RECOMMENDED değildir; final karar diğer kapıların en kısıtlayıcısıdır.

STF: ayrı bilet/PNR kanıtı UNPROTECTED; aynı booking ref koruma kanıtı değildir. PROTECTED yalnız Bölüm 7.3'teki doğrulanmış through-ticket/interline/guarantee kanıtı. Bağlantılar karışık korumalıysa route düzeyinde PARTIALLY_PROTECTED; connection düzeyinde her bağlantı ayrı görünür. Kanıtsız protection UNKNOWN; cap SHOWN_WITH_WARNING. Risk scoring config yoksa risk UNKNOWN ve aynı cap; LOW varsayılanı yok. Reclaim/check-in/terminal/airport timeline yalnız gerekli olduğu kanıtlanmış adımları bildirir; bilinmeyen gereklilik null fact'tir, yapılması gerekmiyor demek değildir. Süre ve saat yoksa null, sıralı adım gerçek zamanmış gibi sunulmaz.

TRF: mevcut pencere = sonraki kalkış instant − önceki varış instant; timezone offset ve UTC arithmetic, floor saniye farkı/60; negatif pencere INFEASIBLE. Pozitif dakika duration girdileri saniyeden gelirse ceil ile dönüştürülmesi P8-TRF teknik önerisidir, onay kaydı gerekir. PROTECTED: doğrulanmış MCT + yalnız MCT dışında olduğu kanıtlanmış gerekli bileşenler. Self-transfer: deplane + gerekli immigration/reclaim/ground/security + bag-drop/check-in cutoff lead time + konfigüre buffer. Cutoff bir mutlak deadline ise lead time olarak aynı toplama bir kez normalize edilir; arrival-to-check-in readiness deadline kontrolü de ayrı yapılır, security'yi deadline öncesine yanlış taşıma.

Bir gerekli bileşenin uygulanabilirliği/süresi bilinmiyorsa required_minutes.value=null, certainty UNKNOWN; UNKNOWN 0 değildir. Belgelenmiş kısa pencere veya kaçırılmış cutoff INFEASIBLE/REJECTED; yalnız eksiklik UNKNOWN/SHOWN_WITH_WARNING. Fallback kullanan yeterli pencere FEASIBLE fakat cap SHOWN_WITH_WARNING ve ESTIMATED. MCT içi bileşen çifte sayılmaz; kanıtlı airside/bagajsız akışta reclaim/landside eklenmez. Her sonucun lineage/config version'u korunur; P8-TRF approved input mapping olmadan provider ham alanları yorumlanmaz.

## PRC-004

Flight-only owner read önerisi mevcut draft-1 path'ini korur; productType başka değer 400. Aynı TripRequest'e ait offer gözlemi ve canonical cohort mapping zorunlu. Tarihsel capture yalnız başarılı canonical flight search normalization/reprice gözlemi sonrası; cache hit yeni gözlem değildir. Aynı source_observation_id tek snapshot. Capture zamanında ham provider yanıtı veya yolcu profili saklanmaz. Source timestamp fetched_at, captured_at ingestion UTC; ikisi eşit sayılmaz.

Fiyat karşılaştırması **aynı gözlenen currency**, FX yok. Route/fare/baggage/currency anahtarlarının EXACT equality sözleşmesi P8-PRC mapping dosyasında onaylanmalı; fare-product kanıtı yoksa NON_COMPARABLE. COMPLETE canonical fiyat dışındaki teklifler snapshot değerlendirmesine girmez. Minimum count/span, outlier kuralı ve exclusion sırası onaylı config'ten; default yok. AVAILABLE: sayı/tarih aralığı/kaynaklar + yalnız gözlenen min/max; hedef, garanti veya tahmin alanı yok. Diğer statülerde tüm monetary summary null. Çok az veri ve çözülememiş policy birbirinden ayrılır.

Draft-1'deki 20 snapshot, 14 gün, MAD/3.5 ve 13 ay **onay değildir**. Bu pakette normative eşik olarak kullanılmaz. Deterministik median, ölçek sabiti, strict sınır, MAD=0 davranışı ve exclusion sonrası yeterlilik P8-PRC onay maddeleridir. Retention/deletion worker ve provenance saklama aktivasyonun zorunlu parçasıdır; önce biriktirip sonra privacy kararı alınmaz.

## EPIC-11 — önerilen ilk Story

Aktif katalog: FEAT-NOTIFICATION DEFERRED, Story yok. Öneri kimliği **P8-NOT-001**; canonical Story ID ancak Product kayıt kararıyla atanır. İlk kapsam: kullanıcının kendi TripRequest'i için belirlediği Money eşiğini karşılayan doğrulanmış COMPLETE uçuş fiyatını **uygulama içi inbox** ile bildirme. Email/push/SMS ve otomatik provider polling ilk dilimde yok. Bu kanal/scope seçimi OD-006'yı sessizce çözmez, ayrı scoped approval ister.

Trigger yalnız mevcut başarılı flight search/reprice gözleminden; aynı currency ve aynı TripRequest. `price <= user threshold`; UNKNOWN/PARTIAL veya stale/expired teklif bildirim oluşturmaz. Dedupe watch_id + source_observation_id. ACTIVE watch → observation → notification + outbox aynı transaction; aynı gözlem için ikinci bildirim yok. Watch PAUSED/CANCELLED iken bildirim yok; CANCELLED terminal. Threshold değişikliği geçmiş gözlemleri tekrar işlemez. Güncel offer süresi dolunca inbox kaydı geçmiş kayıt olarak kalır, actionable=false; okuma provider çağırmaz. Inbox sayfalama `(observed_at, notification_id)` azalan, opaque cursor; page size onaylı config.

Notification UNREAD (read_at=null) → READ; ack aynı sonucu tekrar döndürür, owner kontrolü korunur. Outbox event P8-OpportunityObserved payload yalnız notification/watch/offer id'leri ve observation id; PII veya bearer yok. Outbox “teslim edildi” anlamına gelmez. İlk kanal kalıcı inbox olduğundan ayrı dış gönderim adapter'i veya sahte delivery status yok.

## FOOD-001 / EVT-001

Discovery kriterleri TripRequest varış konum/tarihlerinden; food window offsetli instants ve aynı IANA zone, end > start. Cuisines yalnız onaylı taksonomi; allergy/diet ilk dilimde saklanmaz, tıbbi uygunluk üretilmez. Provider whitelist/field mapping ve source freshness profili ayrı onaylanır. POI provider'ı restoran veya etkinlik kaynağı olarak otomatik yetkilendirilmez.

FoodPrice ayrık kuralları: EXACT yalnız amount; RANGE yalnız minimum/maximum, aynı currency, minimum <= maximum; PRICE_LEVEL yalnız sourced price_level; UNKNOWN hepsi null. PRICE_LEVEL'dan Money türetilmez. Event status.value yalnız SCHEDULED/CANCELLED/POSTPONED/UNKNOWN; ticket_status.value AVAILABLE/SOLD_OUT/UNKNOWN. Çeviri vendor mapping'inde kanıtla yapılır. Price da Sourced metadata ile tutulur; yalın Money kanıtsız kabul edilmez.

Food `reservation_actionable` yalnız FRESH, non-UNKNOWN booking ve FRESH HTTPS reservation_link, onaylı URL authority varsa true. Event `ticket_actionable` yalnız FRESH SCHEDULED + FRESH AVAILABLE + FRESH HTTPS ticket_link + onaylı URL authority varsa true. STALE/EXPIRED/UNVERIFIED/null girdide false; URL yalnız kaynak referansı olarak kalabilir. Bu boolean'lar provider alanı değil backend evidence projection'ıdır. Kanıtsız ad/venue için uydurma resource oluşturulmaz; kısmi provider cevabı PARTIAL kapsam sebebidir.

Food opening-hours assessment yalnız date-specific kanıt + timezone + gerekli special-day coverage ile OPEN/CLOSED; aksi UNKNOWN. CLOSED o pencereye yerleştirilmez; UNKNOWN fizibilite değildir. Detour önce/sonra plan öğeleri ve approved routing evidence ile derived extra_minutes/CostBreakdown; kaynak yoksa null, sıfır değil. Assessment aynı job'ın window'unda ve start anındaki CURRENT plan snapshot'ında değerlendirilir; plan sürümü değişirse yeniden discovery gerekir, sessiz farklı plan kullanılamaz.

Selection explicit user POST; expected_verified_at mevcut canonical snapshot ile eşleşmeli. Değişmiş/eski kanıt 409; taze snapshot görmek ve yeni onay gerekir. Food FLEXIBLE, Event FIXED. Event add için FRESH start + IANA zone, CANCELLED/POSTPONED/SOLD_OUT değil; aksi 422/409. Bitiş bilinmiyorsa fixed_end=null, süre uydurulmaz. Event start immutable item constraint; optimizer/day move/duration endpoint'i değiştiremez. Kullanıcı kaldırması mevcut remove işleminde açık onay ve yeni revision ile mümkündür.

Mevcut ItineraryItem kapalı şemasına allOf ile alan eklenmez: yeni oneOf(aktif POI, FoodItem, EventItem) ve bunu kullanan PlanRevision/TripPlan öneri kopyaları gerekir. Eski POI revision'ları aynı okunur. Food snapshot fiyat/rezervasyon evidence'ı plan revision içine kopyalanır; Event fixed alanları doğrulanmış event snapshot'tan. Provider refresh geçmiş revision'ı değiştirmez; iptal/yeniden saat değişimi yeni kullanıcı kararı gerektirir. HOW yalnız POI; FOOD/EVT rehberine genişletilmez. Event'i mevcut POI day/duration operasyonuna zorla sokma yok.

STF/TRF read modelleri, FOOD detour ve event transit/buffer aktarımı tam DAY gate'i etkinleştirmez. OD-031 policy-only; OD-032..035 çözülmeden plan feasibility_status NOT_EVALUATED kalır. Food/Event add backend'i UI yazımı olmadan aktive edilebilir; AC'lerin kullanıcıya gösterim kısmı ayrıca teslim edilmelidir.
