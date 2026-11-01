# ADR-010 kapsam tamamlama taslağı

Durum: **PROPOSED — ONAY BEKLER**

| Başlık | Mevcut durum | Önerilen karar | Alternatif | Risk | Bağımlılık | Onay sahibi |
|---|---|---|---|---|---|---|
| Principal binding | OIDC principal `(issuer, subject)` aktiftir; Traveler subject binding yoktur | Immutable `TravelerSubjectBinding(traveler_id, issuer, subject, status, verified_at, proof_ref)`; istemci `subject` yazamaz | Kurum içi ayrı identity registry | Yanlış binding başka kişinin PII erişimine yol açar | Identity ceremony ve proof türü | Security + Product |
| Trip + Traveler authorization | Policy matrisi vardır; gerçek binding/grant resolver yoktur | Her işlemde Trip rolü ve Traveler subject/grant birlikte aranır; owner olmak başka yetişkin belgesine otomatik erişim vermez | Yalnız self-access ilk dilimi | Grant karmaşıklığı ve yanlış scope | POLICY-TRAVELER-001, POLICY-PII-VIEW-001 | Security + Product |
| Şifreleme | Hassas JSON plaintext; APP_KEY yeterli kabul edilmemiştir | Traveler başına DEK; KMS/HSM KEK ile envelope encryption; ciphertext/encrypted_dek/key_version/aad_version | Uygulama anahtarıyla encrypted cast | Tek anahtar blast radius; crypto-shredding kanıtı zayıf | KMS seçimi ve threat model | Security + Operations |
| AAD | Tanımlı değil | `traveler_id + field_path + schema_version` canonical AAD; Trip değiştirilemez | Yalnız traveler_id | Field swap/replay riski | Envelope formatı | Security |
| Rotation | Tanımlı değil | KEK rotation ile DEK re-wrap; algoritma/AAD değişiminde kontrollü re-encrypt; başarısız kayıtlar fail-closed kuyruğa | Okumada lazy rotation | Uzun süre eski key kullanımı veya kısmi dönüşüm | Rotation runbook, metrics, rollback | Security + Operations |
| Crypto-shredding | Tanımlı değil | Onaylı deletion event sonrası DEK erişilemez kılınır; backup/legal-hold istisnası ayrı kaydedilir | Sadece ciphertext silme | Backup kopyaları ve legal hold çelişkisi | OD-013 | Legal + Security + Operations |
| Audit | Redaksiyonlu audit altyapısı kısmi | Allow/deny, action, principal ref hash/pseudonym, trip_id, traveler_id, field scope, decision reason, key version; alan değeri/token yok | Yalnız başarılı erişim audit'i | Denial ve privileged access görünmez kalır | Audit retention kararı | Security + Legal |
| Privileged access | ADR-010 içinde OPEN | Break-glass: named role, gerekçe, süreli onay, çift kontrol, tam audit | Privileged erişimi tamamen kapalı tutma | Yetki kötüye kullanımı | Operasyon runbook'u | Security owner |

Önerilen ilk güvenli dilim: self-bound yetişkin Traveler; başka yetişkin ve minor erişimleri ayrı grant/guardian kararına kadar kapalı.

