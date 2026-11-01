# MySQL 8 izole şema doğrulaması

Production veya mevcut/dolu veritabanında çalıştırılmaz. MySQL 8.0.x üzerinde yeni, boş ve yalnız bu doğrulamaya ayrılmış veritabanı gerekir. MariaDB kanıt sayılmaz.

## 1. Sürüm ve boş veritabanı kapısı

PowerShell:

    mysql --version
    php -v
    php artisan --version

Yetkili test sunucusunda:

    CREATE DATABASE ai_travel_schema_v323_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER 'ai_travel_schema_v323'@'localhost' IDENTIFIED BY '<TEK_KULLANIMLIK_GUCLU_PAROLA>';
    GRANT ALL PRIVILEGES ON ai_travel_schema_v323_test.* TO 'ai_travel_schema_v323'@'localhost';
    SELECT COUNT(*) AS table_count FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'ai_travel_schema_v323_test';

Beklenen table_count: 0. Farklıysa durun.

## 2. Migration up

Mevcut .env dosyasını değiştirmeden yalnız komut süreci için:

    $env:APP_ENV='testing'
    $env:DB_CONNECTION='mysql'
    $env:DB_HOST='127.0.0.1'
    $env:DB_PORT='3306'
    $env:DB_DATABASE='ai_travel_schema_v323_test'
    $env:DB_USERNAME='ai_travel_schema_v323'
    $env:DB_PASSWORD='<TEK_KULLANIMLIK_GUCLU_PAROLA>'
    php artisan config:clear
    php artisan migrate:fresh --force
    php artisan migrate:status

migrate:fresh yalnız adı ve boşluğu doğrulanmış bu izole DB'de kullanılabilir.

## 3. Salt-okunur doğrulama

    mysql --host=127.0.0.1 --port=3306 --user=ai_travel_schema_v323 --password ai_travel_schema_v323_test < database/sql/production-schema-verify-v3.23.sql
    mysql --host=127.0.0.1 --port=3306 --user=ai_travel_schema_v323 --password ai_travel_schema_v323_test < database/manual/verify_production_schema.sql

Kabul: tablolar PRESENT; kolon mismatch yok; gerekli index/FK/check eksik değil; events ve event_discovery_jobs ile uq_events_provider, uq_event_discovery_jobs_key, idx_event_discovery_jobs_trip mevcut; zaman kolonları DATETIME(6).

## 4. Down ve yeniden up

    php artisan migrate:rollback --force
    php artisan migrate:status
    php artisan migrate --force
    php artisan migrate:status

Rollback sonrası son batch tablolarının kalktığını ve yeniden up sonrası iki verification dosyasının tekrar geçtiğini kaydedin. Bu production rollback prosedürü değildir.

## 5. İzole ortamı kaldırma

Yalnız doğrulanmış test adlarıyla:

    DROP DATABASE ai_travel_schema_v323_test;
    DROP USER 'ai_travel_schema_v323'@'localhost';

## Kanıt

MySQL sürümü, migrate/status çıktıları, iki verification SQL çıktısı ve up/down/up zamanlarını saklayın. Secret/parolayı kaydetmeyin. Bu kanıt production şemasını doğrulamaz; production için production-schema-diagnostics-v3.23.sql çıktısı ayrıca incelenmelidir.
