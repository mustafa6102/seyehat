# Minor/guardian doğrulama ve yetki yaşam döngüsü

Durum: **PROPOSED — ONAY BEKLER**

## Önerilen durum makineleri

`GuardianBinding`: `PENDING_VERIFICATION → VERIFIED → REVOKED | EXPIRED | REVERIFICATION_REQUIRED`

`GuardianGrant`: `PENDING → ACTIVE → REVOKED | EXPIRED`; kapsam genişletme yeni grant ve yeni onaydır.

## Karar matrisi

| Konu | Mevcut durum | Öneri | Alternatif | Risk | Bağımlılık | Onay sahibi |
|---|---|---|---|---|---|---|
| Guardian doğrulama | Tanımsız | Kanıt türü, doğrulayan otorite/işlem ve proof_ref sürümlü kaydedilir; ham kanıt minimizasyonu ayrıca kararlaştırılır | İlk sürümde minor PII tamamen kapalı | Sahte guardian veya aşırı kanıt toplama | Hukuki geçerlilik ve identity ceremony | Legal + Security + Product |
| Grant kapsamı | Tanımsız | `READ_BASIC`, `WRITE_BASIC`, `READ_DOCUMENTS`, `WRITE_DOCUMENTS` ayrı; least privilege | Tek geniş guardian rolü | Gereğinden geniş erişim | POLICY-MINOR-001 | Security + Product |
| İptal | Tanımsız | Guardian/subject/authorized operator revoke; erişim anında ACTIVE kontrolü; cache invalidation | Sadece süre sonu | İptal sonrası erişim | Revocation propagation | Security + Operations |
| Yaş/statü değişimi | Tanımsız | Konfigüre edilen hukuki eşik uydurulmaz; onaylı kural olayı `REVERIFICATION_REQUIRED` üretir | Manuel inceleme | Yetkinin uygunsuz devamı | Jurisdiction policy | Legal + Product |
| Audit | Kısmi genel audit | Verification, grant, scope, revoke, deny ve privileged view olayları; belge değeri/token yok | Yalnız erişim audit'i | Yetki oluşum zinciri kanıtlanamaz | OD-013 retention | Legal + Security |
| Başka guardian | Tanımsız | Çoklu guardian ve çatışma/itiraz politikası ayrıca onaylanır | Tek guardian | Meşru erişim engeli veya çatışmalı talimat | Dispute workflow | Legal + Product |

## Fail-closed kuralı

Guardian proof tipi, yetkili doğrulayıcı, grant kapsamı, expiry/revocation ve audit retention onaylanıncaya kadar minor hassas alanları create/read/update operasyonları yayımlanmaz. Trip owner olmak guardian kanıtı sayılmaz.

