# AI Travel Planner v3.2

Bu depo, ADR-001 uyarınca Laravel tabanlı modüler monolitin ilk temelini içerir. Normatif kaynak v3.2 Analysis + Implementation Contract'tır; `/registry` ve `/contracts` altındaki kimlikler ile durumlar değiştirilmeden korunur.

## Gereksinimler

- PHP 8.2 veya üzeri
- Composer 2
- MySQL 8
- Gerekli PHP uzantıları: PDO MySQL, mbstring, openssl, tokenizer, xml, ctype, json

## Yerel kurulum

```bash
cp .env.example .env
composer install --no-dev --optimize-autoloader
php artisan key:generate
```

`.env` içinde `DB_DATABASE`, `DB_USERNAME` ve `DB_PASSWORD` değerlerini ortamın MySQL 8 kimlik bilgileriyle doldurun. Sırlar depoya eklenmemelidir.

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

## Linux ve alan adı yayını

Alan adının document root değeri proje kökü değil, mutlak olarak projenin `/public` dizini olmalıdır. PHP-FPM/Nginx veya Apache yalnız `public/index.php` dosyasını web giriş noktası olarak kullanmalıdır. `.env`, `storage`, `vendor`, `registry` ve `contracts` doğrudan web üzerinden sunulmamalıdır.

Yayın kullanıcısının `storage` ile `bootstrap/cache` dizinlerine yazma yetkisi olmalıdır. Production ortamında `APP_ENV=production`, `APP_DEBUG=false` kullanılmalı; log hedefi stderr veya erişimi sınırlandırılmış bir dosya olmalıdır.

Queue ve live-ingestion bağımsız worker süreçleridir:

```bash
php artisan queue:work database --queue=outbox-relay --sleep=1 --tries=3
php artisan queue:work database --queue=live-ingestion --sleep=1 --tries=3
```

Bu aşamada job/relay davranışı uygulanmamıştır; komutlar yalnız process sınırlarını gösterir. Worker süreçleri systemd veya Supervisor ile ayrı ayrı yönetilmelidir.

## Sağlık kontrolü

`GET /api/v1/health` başarılı durumda gövdesiz `204 No Content` döndürür.

## Mimari sınırlar

Modüller `app/Modules` altındadır. Modüller arası çağrılar yalnız hedef modülün `Contracts` namespace'indeki public servis arayüzleri üzerinden yapılır. Provider SDK nesneleri ve ham payload tipleri yalnız `ProviderAcl/Infrastructure` sınırında tutulur; domain katmanına canonical DTO dışında veri geçmez.

ADR-003 için `outbox_events` tablosu ve `OutboxWriter` arayüzü hazırlanmıştır. PlanRevision ile outbox yazımı aynı MySQL transaction'ında yapılmalıdır. Relay, DLQ/replay ve consumer idempotency davranışları henüz uygulanmamıştır. ADR-008 kararı açık olduğundan `sequence` nullable ve event ordering PROVISIONAL olarak kalır.
