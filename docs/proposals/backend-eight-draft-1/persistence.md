# Mantıksal MySQL 8 sözleşmesi — SQL değildir

Tüm yeni tablo/kolonlar PROPOSED. Bu dosya DDL üretmez/çalıştırmaz. JSON alanları ilgili `schemas.json` tanımının doğrulanmış canonical serileştirmesidir; raw provider payload değil. Opak ID'ler mevcut Str::uuid yaklaşımıyla string(36); mevcut Trip/Traveler legacy id alanları kendi mevcut tipini korur. UTC timestamps microsecond hassasiyetli; version unsigned integer >=1. FK/charset tipleri referans verilen mevcut kolonla aynı olmalı; SQL görevinde gerçek şema kontrol edilmeden tip değişimi yapılmaz.

## Ortak yeni kayıtlar

**backend_operation_idempotency**: scope_hash char(64), principal_hash char(64), operation varchar(128), resource_id varchar(255), idempotency_key varchar(255), request_fingerprint char(64), status {IN_PROGRESS,COMPLETED}, response_status nullable integer, response_json nullable JSON, created_at/finished_at timestamps. PK(scope_hash,idempotency_key); scope principal/operation/resource'ün length-delimited canonical serialization hash'i. v3.19 HOW ledger deseni tekrar kullanılır, **HOW tablosu veya operation=refreshTripPlanItemGuide const şeması genişletilmiş sayılmaz**. Yeni ledger şeması ve PII response encryption/retention OD-013 onayına bağlı. Invite token plaintext response ledger'a yazılmaz; replay aynı binding'i döndürür fakat yeni token üretmez, token-only response replay özel sözleşmesi aşağıdadır.

Invite create idempotency: token ayrı onaylı güvenli teslim mekanizmasına gönderilir, HTTP response token içermez; replay yalnız ilk binding+expiry sonucunu döndürür. Token delivery retry aynı invitation'a bağlı ve onaylı güvenli secret envelope üzerinden yapılır; ikinci invitation/token üretmez. Teslim kanalı, encrypted delivery secret lifecycle ve proof P8-TRV onayı tamamlanmadan davet workflow'u aktive edilmez.

**backend_decision_snapshots**: decision_id PK, trip_request_id, operation, resource_id, input_snapshot JSON (PII içermez; PII reference yalnız encrypted domain storage'a), input_fingerprint char(64), rule_version, configuration_versions JSON, evidence_refs JSON, result_json JSON, created_at, expires_at nullable. Unique(operation,resource_id,input_fingerprint,rule_version); owner TripRequest'ten. RCG tamamlanırken STF/TRF results burada materialize edilebilir; GET immutable snapshot okur. Yeni evidence refresh yeni decision kaydıdır. Input observation id/hash audit için korunur. Numeric retention tanımlanmadı.

Plan mutasyonları yeni ortak ledger kullanmak yerine mevcut PlanRevision idempotency benzersizliğini korur. Ledger/replay key'i bir projection değişikliği için revision üzerinde tutulur; domain değişikliği ve outbox tek transaction.

## TRV-001 — mevcut tablo değişikliği + yeni tablolar: YES

- `travelers`: mevcut id PK ve trip_id bağlantısı korunur; version, status {ACTIVE,ERASURE_PENDING,ERASED}, encrypted_profile envelope + key_ref eklenmesi önerilir. Nationalities/documents/visas/permits plaintext kolonlarının aktif okuma/yazımı kaldırılır; dönüştürme/backfill/backup temizliği Security onaylı ayrı işlem. Legacy kayıtlar owner'sız otomatik bağlanmaz. Global updateOrInsert yasak; insert duplicate conflict, update WHERE id+trip_id+version. Erasure semantics/retention kapalıyken bu şema aktive edilmez.
- `traveler_principal_bindings`: binding_id PK; trip_id, traveler_id nullable (owner binding için nullable), principal_issuer/subject (255, birebir case-sensitive), role {OWNER,SELF}, status, version, accepted_at/revoked_at. Unique(trip_id,principal_issuer,principal_subject,role,traveler_id) yerine nullable uniqueness tuzağını önleyen ayrı non-null binding_scope_key UNIQUE kullanılır. OWNER server TripRequest owner'dan oluşturulur; SELF approved invitation proof'tan; client rol yazamaz. Bir traveler için en fazla bir ACTIVE SELF binding: generated active_self_key UNIQUE veya transactional equivalent.
- `traveler_invitations`: invitation_id PK, traveler_id, trip_id, token_hash UNIQUE, target proof reference encrypted, expires_at, consumed_at, binding_id nullable; PK token değil. Atomic consume yalnız not-consumed/not-expired, scoped proof doğrulaması sonrası. Delivery secret encrypted service channel'da, HTTP/log/outbox payload'da yok.
- `traveler_access_grants`: grant_id PK, traveler_id, grantor_binding_id, grantee_binding_id, actions JSON (enum), status, version, expires_at, created_at/revoked_at. Aynı Trip bindingleri transaction içinde doğrulanır. Grantor ACTIVE SELF değilse mutation yok. Action kapsamı genişlemesi yeni onay; revoked grant replay yetkiyi geri getirmez.
- Audit encryption/access events mevcut audit altyapısını tekrar kullanır, değerler/log principal redaksiyonu değişmez; kalıcı audit retention P8-TRV security profili gerektirir. Crypto material uygulama DB'sinde plaintext tutulmaz; algoritma/KMS burada seçilmedi.

## RCG-001 — yeni tablolar: YES

- `route_candidate_jobs`: job_id PK, trip_request_id FK mantığı, criteria_json canonical FlightSearchCriteria, seed_version, policy_versions JSON, status, result_json nullable CandidateResult, created_at/started_at/finished_at/expires_at, failure_code nullable. Job immutable criteria; out-of-scope provider'dan raw payload yok. Parent owner taşınmaz; TripRequest'ten türetilir.
- `route_candidate_budgets`: PK(job_id,provider_id,operation); configured_limit, reserved_requests, attempted_requests, config_version. `0 <= attempted <= reserved <= configured_limit`; reserve row-lock altında. Attempt için reservation re-use edilemez. Retry de aynı job counter'ını tüketir.
- `route_candidate_attempts`: attempt_id PK, job_id/provider_id/operation, candidate_id/leg_id, reservation_id, attempt_number, state {RESERVED,DISPATCHED,ANSWERED,FAILED,OUTCOME_UNKNOWN}, result_ref nullable, dispatched_at nullable. UNIQUE(reservation_id,attempt_number). Dispatch öncesi durable token consumption; network belirsizliği refund edilmez. Yeni retry yeni attempt reservation ister.
- Canonical candidates job result JSON içinde, ayrı candidate tablosu zorunlu değil. Her candidate_id job içinde unique. Ground evidence referansı yoksa ground leg offer_ref=null ve PARTIAL; fake ground offer satırı oluşturulmaz.

## STF-003 / TRF-001 — feature-specific tablo: NO; audit ortak tablo: YES

Risk/transfer DTO hesaplaması saf domain olarak DB'siz uygulanabilir. Owner API RCG job snapshot'ını kullanır. Audit gereksinimi için `backend_decision_snapshots` ortak tablosu gerekir; RouteOption veya FlightOffer'a risk sütunları eklenmez. Bu nedenle **tüm üretim API + audit dilimi mevcut approved persistence ile sıfır DB değişikliği değildir**. Yalnız mevcut canonical girdilerden hesaplayan bağımsız fonksiyon dilimi DB gerektirmez.

## PRC-004 — yeni tablo: YES, approval olmadan DDL bile üretilmez

- `price_snapshots`: snapshot_id PK, trip_request_id, offer_id, source_operation_id, source_observation_id, cohort_key nullable, cohort_mapping_version, currency char(3), amount_minor bigint, source_reference, fetched_at/captured_at/expires_at. Unique(source_operation_id,source_observation_id,trip_request_id); index(trip_request_id,cohort_key,fetched_at). Snapshot immutable; cleanup approved retention worker tarafından.
- Canonical cohort dimensions snapshot içinde JSON olarak ayrıca saklanır (route/fare/baggage identity, currency); cohort_key bu JSON'un canonical hash'i. Mapping değişirse eski satır yeniden etiketlenmez; version ayrımıyla yeni cohort.
- ADR-002 time partition isteği MySQL unique/partition-key kısıtlarıyla tasarlanmalı: fiziksel partition kullanılırsa global dedupe ayrı non-partitioned `price_snapshot_observations` unique(source_operation_id,source_observation_id,trip_request_id) ledger'ında, snapshot partition key captured_date PK/unique'lara dahil. Ledger+snapshot insert atomik. Partition granularity onaylı retention üzerinden seçilir, burada sayısal periyot atanmaz.
- PriceEvaluation GET salt-okunur hesaplanan model; ayrıca evaluation tablosu zorunlu değil. Kullanılan immutable snapshot/config versionlar audit response'ta; persistent evaluation audit gerekiyorsa ortak decision snapshot worker tarafından yazılır, GET yazmaz.
- Silme callback'i mevcut değil: TripRequest deletion contract + worker transaction/verification P8-PRC kapısı. Global/cross-owner history yok; aggregation scope değişikliği ayrı privacy approval ister.

## EPIC-11 — yeni tablolar: YES

- `opportunity_watches`: watch_id PK, trip_request_id, version, status, currency, threshold_minor, created_at/updated_at; threshold currency eşitliği zorunlu. Owner derive edilir.
- `opportunity_notifications`: notification_id PK, watch_id FK, source_observation_id, offer_id, observed_price JSON Money, observed_at/expires_at, read_at nullable. UNIQUE(watch_id,source_observation_id). Index(watch_id,observed_at,notification_id); cursor keyset pagination. Actionable read-time current evidence/freshness projection'dır, kalıcı true bayrağı değil.
- Aynı transaction içinde notification + mevcut outbox_events event; worker retry unique ile no-op replay. Delivery receipt tablosu ilk inbox diliminde gerekmez. Watch mutation idempotency yeni ortak ledger; ack idempotent read_at ilk timestamp'i korur. Dış notification provider yok.

## FOOD-001 / EVT-001 — discovery yeni tablolar: YES; plan depolaması yeni tablo: NO

- `food_discovery_jobs` / `event_discovery_jobs`: job_id PK, trip_request_id, criteria JSON, base_revision_version nullable, source policy versions, status, result refs JSON, failure_code nullable, created_at/expires_at. Job->result owner scope her okumada doğrulanır.
- `food_places` / `events`: opaque id PK, provider_id/provider_resource_id, observation_id, snapshot JSON, verified_at nullable, expires_at nullable, source policy version. UNIQUE(provider_id,provider_resource_id,observation_id); her gözlem immutable. Job result refs aynı snapshot'ları işaret eder. Vendor kaynak kimliği public ownership değildir.
- Food assessment canonical window ve frozen neighbor item ids/base revision ile job snapshot'ında tutulur; provider ground calls discovery worker içinde, GET'te değil. Aynı tarihin farklı saat diliminden yeniden yorumlanması yok.
- Yeni FOOD/EVENT item JSON'ları mevcut `plan_revisions.items` içinde; `trips`, version, idempotency ve outbox aynı transaction. Yeni itinerary tabloları gerekmez; tip union sözleşmesi değişir. PlanRevision remove/move operation'larının type validation'ı FOOD/FIXED kurallarını korumalıdır. Eski immutable snapshot'a refresh yazılmaz.

## DB sınıflandırması

| Dilim | Mevcut persistence yeterli mi? | DB etkisi |
|---|---|---|
| TRV gerçek çoklu profil/yetki/şifreleme | Hayır | Traveler alter + binding/invitation/grant + ledger |
| RCG job/provider budget | Hayır | Jobs/budgets/attempts + ledger |
| STF/TRF saf hesaplama | Evet, state yok | Yok |
| STF/TRF owner read + kalıcı audit | Hayır | Ortak decision snapshots, RCG job bağımlılığı |
| PRC history | Hayır | Snapshot/observation ledger; retention/deletion |
| EPIC-11 inbox | Hayır | Watch/notification; mevcut outbox tekrar kullanılır |
| FOOD/EVT discovery | Hayır | Jobs + immutable canonical observations |
| FOOD/EVT plan item projection | Evet, onaylı tablolar kuruluysa | Yeni tablo yok; JSON union ve servis desteği değişir |

Toplam paket DB CHANGE REQUIRED: **YES**. Bu tur SQL dosyası hazırlanmadı; mevcut SQL/production dosyaları değiştirilmedi.
