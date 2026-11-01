# TRV-001 karar ve sözleşme delta taslağı

Durum: **PROPOSED_NON_NORMATIVE**  
Tarih: 2026-10-31  
Taban: ACTIVE v3.23.0; v3.22 `OD-P8-TRV-SCOPE`; ACTIVE `SCH-Traveler` v3.3

Bu paket hiçbir kararı kapatmaz, ACTIVE registry/contracts dosyalarını değiştirmez ve PII iş akışını aktive etmez. Gerçek hukuk sonucu, provider/alıcı, ülke, retention süresi, KMS ürünü veya guardian kanıtı bilinmiyorsa `OPEN`/`UNKNOWN` bırakılmıştır.

## Paket

- `adr-010-options.md`: issuer-subject binding, Traveler authorization ve envelope encryption seçenekleri.
- `od-013-decision-matrix.md`: amaç, hukuki sebep, retention, deletion ve legal hold karar matrisi.
- `od-014-transfer-matrix.md`: gerçek alıcı/provider ve aktarım kayıt şablonu.
- `minor-guardian-lifecycle.md`: guardian doğrulama, grant, iptal ve audit yaşam döngüsü.
- `openapi.proposed.yaml`: eklemeli, sürümlü Traveler create operasyonu.
- `schemas/TravelerCreateRequest.schema.json`: istemci `id`/`trip_id` kabul etmeyen request şeması.
- `approval-checklist.md`: aktivasyon öncesi imza ve kanıt kapıları.

## Mevcut durum ve önerilen sınır

| Konu | Mevcut durum | Öneri | Aktivasyon kapısı |
|---|---|---|---|
| Traveler create | ACTIVE eski operasyon `SCH-Traveler` request alır; v3.22 server-generated ID yönüyle uyumsuzluk vardır | Yeni `/trips/{tripId}/travelers`; `id` sunucuda, `trip_id` path ve yetkili Trip kaydından | Contract owner + Product + Security |
| Persistence | v3.22 insert-only ister | Duplicate ID overwrite değil conflict; idempotency sonucu aynı Traveler'a bağlanır | Tech + Security |
| PII erişimi | Bearer sonrası subject/grant çözümü yok; fail-closed | AuthN → Trip existence/authorization → Traveler grant → decrypt sırası | ADR-010 onayı |
| Verification | Canonical enum `UNVERIFIED` içerir | Kullanıcı girdisi `UNVERIFIED`; `NEEDS_VERIFICATION` ayrı readiness sonucudur | Required-document mapping onayı |
| Minor | Guardian kanıtı ve grant lifecycle açık | Onaya kadar minor hassas PII create/read/update kapalı | Legal + Product + Security |

## Geriye uyumluluk

Yeni operasyon ve request şeması eklemelidir. Mevcut `OP-createTraveler` ve `SCH-Traveler` yerinde değiştirilmez. Eski operasyon için migration/deprecation süresi, consumer envanteri ve kapatma tarihi ayrıca onaylanır. Bu taslakta eski endpoint açılmaz veya güvenliği gevşetilmez.

