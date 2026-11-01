-- STATUS: NOT READY - PRODUCTION'DA CALISTIRMAYIN.
--
-- OD-EVT-001-TM Ticketmaster Discovery API v2'yi EVT-001 icin secer; ancak
-- provider license/mapping ve deployment capability evidence kaydi halen ortam-ozeldir.
-- API anahtari veya bu dosyanin varligi supported=1 / FRESH kaniti degildir.
-- Onayli, tarihli ve ortama ozgu capability kaniti incelenmeden bu dosya DML uretmez.
-- Laravel migrations tablosuna veya provider_capabilities tablosuna kayit eklemez.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Salt-okunur mevcut durum kontrolu. Eksik satir destek var anlamina gelmez.
SELECT provider_id, operation, environment, supported, status, evidence, verified_at
FROM provider_capabilities
WHERE provider_id = 'ticketmaster'
  AND operation = 'event_discovery'
  AND environment = 'production';

-- Gerekli onaylar:
-- 1. Ticketmaster kullanim/lisans ve operasyon esleme kaniti.
-- 2. Production credential/config smoke sonucu (secret kaydetmeden).
-- 3. Yetkili karar sahibinin production capability aktivasyon onayi.
-- 4. Kanit metni, verified_at ve durumun deployment kaydinda incelenmesi.
