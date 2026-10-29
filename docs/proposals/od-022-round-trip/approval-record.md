# OD-022 ROUND_TRIP — onay kaydı

Durum: **APPROVED (D1–D4) / v3.23.0 ACTIVE**  
Kayıt tarihi: `2026-10-29` · Onaylayan: proje sahibi · Dış onay iddiası: yok

Proje sahibinin talimatı: "Project-owner approval applies to D1–D4 for ROUND_TRIP only".

| Karar | Onaylanan içerik (`approval-document.md` §2) |
|---|---|
| D1 | `FlightOffer.journey_type` ve segment `direction` zorunlu; tek, sıralı `segments` dizisi korunur. |
| D2 | Saklı v3.4 tek yön teklifler okuma anında normalize edilir; SQL dönüşümü yok. |
| D3 | Reprice'ta güzergâh/yön farkı `UNVERIFIED`; mevcut seçim korunur. |
| D4 | 19 gereksinim kaydı; slice sayısı/sırası/uç noktası zorunlu eşleşmesi. |

A1–A8 yeni onay değildir; mevcut sözleşmenin sonuçlarıdır. OD-022 `PARTIALLY_RESOLVED` kalır. Kapsam dışı OPEN konular `approval-document.md` §5'tedir.

Normatif karşılık: `contracts/v3.23/`, `registry/v3.23/`, `registry/active_contract.json` (3.23.0). Bu klasör kayıt amaçlıdır, normatif değildir.
