# OD-013 veri yaşam döngüsü karar matrisi

Durum: **PROPOSED — HUKUKİ SONUÇ İÇERMEZ**

Her `OPEN` hücre Legal/Data Owner tarafından somut değer ve kanıt referansıyla doldurulmadan PII workflow aktive edilmez.

| Veri kategorisi | İşleme amacı | Hukuki sebep | Retention başlangıcı/bitişi | Deletion tetikleyicisi | Legal hold | Risk | Bağımlılık | Onay sahibi |
|---|---|---|---|---|---|---|---|---|
| age_category | Seyahat profili ve gereken belge sınıfını belirleme | OPEN | OPEN | Trip/profile silme talebi ve amaç sona ermesi — kesin kural OPEN | OPEN | Yaş/minor statüsü hassasiyet doğurur | Minor kararı | Legal + Product |
| nationalities[] | Kullanıcının talep ettiği seyahat hazırlığı | OPEN | OPEN | OPEN | OPEN | Özel nitelikli/hassas profil çıkarımı | OD-014, required-document source | Legal + Product |
| travel_documents[] | Belge readiness ve kullanıcı talebi | OPEN | OPEN | OPEN | OPEN | Kimlik hırsızlığı, aşırı veri toplama | ADR-010, schema field minimization | Legal + Security |
| held_visas[] | Readiness girdisi | OPEN | OPEN | OPEN | OPEN | Güncellik ve gereksiz saklama | Authoritative mapping | Legal + Product |
| residence_permits[] | Readiness girdisi | OPEN | OPEN | OPEN | OPEN | Ülke/ikamet profili ifşası | OD-014 | Legal + Product |
| subject binding | Traveler'ı doğru principal'a bağlama | OPEN | OPEN | Binding revoke/subject request — kesin kural OPEN | OPEN | Yanlış kişiye erişim | Identity ceremony | Legal + Security |
| grants/guardian proof | Yetki kanıtı ve iptal | OPEN | OPEN | Revoke/expiry ve subject-rights sonucu — kesin kural OPEN | OPEN | Yetkinin gereğinden uzun sürmesi | Minor lifecycle | Legal + Security |
| PII access audit | Güvenlik, uyuşmazlık ve hesap verebilirlik | OPEN | OPEN | Retention sonu; güvenlik olayı istisnası OPEN | OPEN | Audit'in kendisi kişisel veri olabilir | Audit minimization | Legal + Security |
| encryption metadata/key refs | Şifre çözme ve rotation | OPEN | Veri yaşam döngüsüne bağlı; kesin kural OPEN | Crypto-shredding olayı | OPEN | Anahtar silme geri döndürülemez | KMS/backup runbook | Security + Operations + Legal |

## Onayda zorunlu alanlar

- Amaç bazında açık hukuki sebep ve kapsam.
- Takvimsel süre veya doğrulanabilir lifecycle olayı; “gerektiği kadar” kabul edilmez.
- Primary DB, cache, queue, log, replica ve backup için deletion kapsamı.
- Legal hold başlatma/bitirme yetkisi, gerekçe kaydı ve hold altındaki alanlar.
- Subject request doğrulaması, SLA sahibi ve başarısız silme alarmı.
- Crypto-shredding ile fiziksel/logical silmenin hangi sırada ve hangi kanıtla tamamlandığı.

