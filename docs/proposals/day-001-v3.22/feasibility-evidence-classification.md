# DAY-001 Feasibility Evidence Classification — v3.22 NON-NORMATIVE

Durum: **PROPOSED / NON-NORMATIVE**. Bu belge aktif sözleşme veya uygulama yetkisi değildir.

## Onaylı politika tabanı

- Eksik, `UNVERIFIED`, `STALE` veya `EXPIRED` opening-hours/availability kanıtı `FEASIBLE` üretemez.
- Eksik IANA timezone veya gerekli özel-gün kanıtı tahmin edilmez.
- Yetersiz kanıt `UNKNOWN / NOT_VERIFIED` olarak ayrılır; yalnız kanıt yetersizliği `IMPOSSIBLE` değildir.
- Mevcut `SCH-Sourced`, `Certainty` ve `VerificationStatus` provenance/freshness semantiği aynen kullanılır.

## Uygulamadan önce eksik sözleşme kararları

1. `UNKNOWN` ile `NOT_VERIFIED` tek enum sonucu mu, yoksa ayrı karar ve kanıt durumu alanları mı olacak?
2. Sınıflandırma yalnız bir plan öğesi için mi, gün/revision bütünü için mi üretilecek?
3. Tetikleyici salt-okuma operasyonu mu, revision mutasyonu mu olacak?
4. Sonuç saklanacak mı, her okumada mı hesaplanacak; hangi lifecycle ve reason kodları kullanılacak?
5. `FEASIBLE` ve `IMPOSSIBLE` için yeterli pozitif/negatif kanıt eşiği nedir?

`OD-032..035` çözülmeden transit, tampon/tempo, uçuş/konaklama sınırı veya tam gün feasibility sonucu tanımlanamaz.
