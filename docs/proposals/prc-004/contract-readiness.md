# PRC-004 en küçük contract-readiness sınırı

Durum: **BLOCKED / PROPOSAL ONLY**

## Güvenle doğrulanan mevcut parçalar

- Güncel uçuş teklifleri `Money`, offer snapshot ve source/freshness verisi taşır.
- TripRequest owner authorization altyapısı ve Türkçe web kanalı deseni vardır.
- PRC-004 için tarihsel `PriceSnapshot` repository'si, tablo sözleşmesi, API veya UI yoktur.

Bu parçalar tek başına price-history erişimi oluşturmaz. Güncel offer cache'ini tarihsel veri gibi kullanmak `FR-PRC-004-01` ve `NFR-AUD-001` ile uyumsuz olur.

## Backend ve frontend başlamadan önce gerekli en küçük aktif sözleşme deltası

1. **Kaynak ve kimlik:** Hangi mevcut owner-scoped resource üzerinden değerlendirme okunacağı ve opaque resource kimliği.
2. **Kohort şeması:** Yalnız registry'de adı geçen route/fare/baggage/currency boyutlarının mevcut kanonik şemalardaki kesin alan referansları; eksik alanın `NON_COMPARABLE` davranışı.
3. **Snapshot şeması:** Money, source, `fetched_at`, immutable snapshot kimliği ve `NFR-AUD-001` audit/config referansları. Yeni alan semantiği onaysız eklenemez.
4. **Değerlendirme şeması:** Config çözülmemiş fail-closed sonucu ile gelecekteki yeterli veri sonucunun ayrımı; Türkçe sunum için snapshot count/date range/source alanlarının nullability kuralları.
5. **HTTP operation:** Owner-only read, auth/resource/authz değerlendirme sırası, response ve kayıtlı reason/error kodları. Path ve operationId aktif contract kararı olmadan seçilemez.
6. **Permission/lifecycle:** Owner read ALLOW; collaborator/anonymous DENY. Snapshot retention/deletion ve saklama yaşam döngüsü ayrıca tanımlanmalı.
7. **Config kayıtları:** Yeterlilik ve outlier policy kayıtlarının owner/version alanları. Numeric değer veya yöntem yokken fail-closed; `AC-PRC-004-02/03` tamamlanmış sayılmaz.

## Engeller

- Aktif `registry/v3.19/contract_index.yaml` içinde PRC-004 contract artifact'i yok.
- Aktif uygulamada okunabilecek tarihsel fiyat deposu yok; veri uydurulamaz.
- `ADR-002` ayrı ve zaman bazlı bölümlenmiş OLTP tablo ister; `ADR-009` v3.3 ile MySQL 8 olarak ACCEPTED'dır. Snapshot schema'sı ve `OD-013` retention/privacy kararı onaylı olmadığı için DDL üretmek yine mevcut talimata aykırıdır.
- `OD-006` mevcut impact kaydında M2 development'ı bloke etmeye devam eder; notification kapsam dışı tutulsa da aktif kayıt sessizce değiştirilemez.
- Yeterlilik sayıları ve outlier yöntemi çözülmediği için `AC-PRC-004-02/03` uygulanamaz.

## Uygulama kararı

Bu aşamada backend endpoint, repository veya frontend bileşeni eklenmedi. Sadece sürekli `INSUFFICIENT_DATA` gösteren bir API/UI işlevsel price-history izlenimi verecek, aktif sözleşme olmadan payload uyduracak ve `AC-PRC-004-02/03` kapsamını yanlış temsil edecekti.

Production MySQL 2002 bağlantı problemi deployment-only doğrulama engelidir; yukarıdaki ürün/sözleşme engellerinin nedeni değildir.
