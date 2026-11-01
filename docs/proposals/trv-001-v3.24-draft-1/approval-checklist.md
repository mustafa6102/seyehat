# TRV-001 aktivasyon onay listesi

Durum: **PROPOSED**

- [ ] Contract owner: eklemeli OpenAPI/schema delta, compatibility ve eski consumer envanteri.
- [ ] Product owner: mevcut Trip ön koşulu, adult/minor ilk dilim kapsamı ve action listesi.
- [ ] Security owner: issuer-subject binding, grant resolver, KMS/HSM, envelope formatı, AAD, rotation, break-glass ve crypto-shredding.
- [ ] Legal/KVKK owner: OD-013 matrisindeki her veri kategorisinin amaç, hukuki sebep, retention, deletion ve legal hold hücreleri.
- [ ] Legal/KVKK + Procurement + Security: OD-014 içindeki her gerçek alıcı/provider, ülke, veri listesi, mekanizma ve alt işleyen kanıtı.
- [ ] Legal + Security + Product: guardian proof, grant kapsamı, expiry/revoke, dispute ve yaş/statü değişimi.
- [ ] Data/Product owner: authoritative required-document mapping ve readiness profil sürümü; `NEEDS_VERIFICATION` yalnız completeness sonucu.
- [ ] Operations: key rotation/deletion runbook, backup davranışı, başarısız worker alarmı ve audit erişimi.
- [ ] Test owner: contract validation, 401/403 sırası, cross-Trip/cross-Traveler denial, duplicate overwrite reddi, rotation ve deletion kanıt senaryoları.

Tüm kutular somut karar kaydı, accountable owner, approver, tarih, kapsam ve artifact hash ile tamamlanmadan ACTIVE registry güncellenmez ve PII endpoint yayımlanmaz.
