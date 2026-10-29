# OD-022 gidiş-dönüş uçuş sözleşmesi önerisi

Durum: **APPROVED D1–D4 (2026-10-29) → v3.23.0 ACTIVE** — bkz. `approval-record.md`  
Taban: `3.22.0` · Sürüm: `3.23.0` · Tarih: `2026-10-29`

Bu klasör kayıt amaçlıdır; normatif kaynak `contracts/v3.23/` ve `registry/v3.23/`'tür. OD-022 `PARTIALLY_RESOLVED` kalır. Uygulama kodu henüz v3.23'e uyarlanmadı (`contracts/v3.23/registries/traceability.json` → `implementation_gap`).

| Dosya | İçerik |
|---|---|
| `approval-document.md` | **Onay belgesi.** Tek önerilen karar seti: onay gerektiren D1–D4, mevcut sözleşmenin zaten belirlediği A1–A8, etkilenen sözleşmeler, uyumluluk, kapsam dışı OPEN konular ve riskler. |
| `proposed_contract.yaml` | Makine okunur delta: şema/OpenAPI değişiklikleri, Duffel eşlemesi, hata semantiği, önerilen RULE/FR/AC/TS kayıtları, regresyon test planı. |
| `schemas/FlightOffer.schema.json` | Önerilen `SCH-FlightOffer` 3.23 (v3.4 + `journey_type` + segment `direction`). |
| `examples/fixtures.json` | Önerilen ve v3.4 şemalarına karşı doğrulama örnekleri (D2 normalizasyonu dahil). |

Önceki taslaktaki seçenekli `approval-checklist.md` kaldırıldı. Bu dosyanın yerini `approval-document.md` aldı; RT-01..12 ayrı ayrı seçilmez, tek paket olarak onaya sunulur.
