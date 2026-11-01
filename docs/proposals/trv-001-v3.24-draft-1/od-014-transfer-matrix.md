# OD-014 yurt dışı aktarım ve alıcı matrisi

Durum: **PROPOSED ŞABLON — GERÇEK ALICI/PROVIDER BEYANI YOKTUR**

Repository ve mevcut kararlar gerçek Traveler PII alıcısı, ülke veya aktarım mekanizmasını kanıtlamaz. Bu nedenle aşağıdaki kayıtlar `UNKNOWN` bırakılmıştır; varsayılan provider adı eklenmemiştir.

| Alıcı/provider tüzel adı | Rolü | Ülke ve veri merkezi | Veri kategorileri | Amaç | Aktarım mekanizması | Alt işleyenler | Saklama/erişim kanıtı | Durum | Onay sahibi |
|---|---|---|---|---|---|---|---|---|---|
| UNKNOWN | KMS/HSM hizmeti adayı | UNKNOWN | encrypted DEK, key metadata; plaintext erişimi olup olmadığı UNKNOWN | Anahtar koruma | OPEN | UNKNOWN | DPA/teknik mimari kanıtı gerekli | BLOCKED | Legal + Security + Procurement |
| UNKNOWN | Hosting/database/backup alıcısı adayı | UNKNOWN | Traveler ciphertext ve metadata; plaintext erişim modeli UNKNOWN | Uygulama barındırma | OPEN | UNKNOWN | Bölge, support access ve backup konumu gerekli | BLOCKED | Legal + Security + Operations |
| UNKNOWN | Kimlik sağlayıcı | UNKNOWN | issuer, subject ve authentication metadata | Kimlik doğrulama/binding | OPEN | UNKNOWN | Mevcut OIDC deployment gerçekleri gerekli | BLOCKED | Legal + Security |
| UNKNOWN | Seyahat/provider entegrasyonu | UNKNOWN | Hiçbiri varsayılmaz; gönderilecek kesin alanlar OPEN | Kullanıcı talebiyle dış işlem | OPEN | UNKNOWN | API contract, lisans ve minimization kanıtı gerekli | BLOCKED | Product + Legal + Provider owner |
| UNKNOWN | Audit/observability alıcısı | UNKNOWN | Pseudonymous access metadata; PII içermemesi hedeflenir | Güvenlik gözlemi | OPEN | UNKNOWN | Log routing/redaction ve support access kanıtı gerekli | BLOCKED | Security + Legal + Operations |

## Aktivasyon kuralı

Her gerçek alıcı için tüzel ad, rol, ülke(ler), veri merkezi/uzaktan destek erişimi, kesin JSON Pointer veri listesi, amaç, mekanizma, alt işleyenler, retention ve sözleşme/kanıt referansı kaydedilir. Bir hücre `UNKNOWN` ise o alıcıya Traveler PII aktarımı fail-closed kalır.

