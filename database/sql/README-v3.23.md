# v3.23 phpMyAdmin veritabanı prosedürü

1. Uygulamayı bakım penceresine alın; queue worker/cron tetiklerini durdurun. phpMyAdmin'da doğru production veritabanını seçtiğinizi iki kez doğrulayın.
2. Hem mantıksal SQL dışa aktarımı hem hosting snapshot'ı alın; geri yüklenebilirliğini doğrulamadan DDL çalıştırmayın.
3. `production-schema-diagnostics-v3.23.sql` dosyasını çalıştırın ve bütün sonuç sekmelerini dışa aktarın. Yorumlarda belirtilen `migrations`, `provider_capabilities` ve veri-integrity sorgularını yalnız ilgili tablo/kolon `PRESENT` ise ayrıca çalıştırın.
4. Çıktıyı migration'lar ve aktif sözleşmeyle karşılaştırın. Mevcut `production-missing-changes-v3.23.sql` **NOT READY** durumundadır; production çıktısı incelenmeden çalıştırılacak DDL içermez.
5. İnceleme sonunda yalnız kesin `MISSING` farklar için yeni, additive ve sıralı bir corrective SQL hazırlanmalıdır. `TYPE_MISMATCH`, `NULLABILITY_MISMATCH`, kısmen mevcut tablo veya geçersiz veri otomatik dönüştürülmez; ayrıca karar ve bakım planı gerekir.
6. Onaylı corrective SQL düşük trafikte tek kez uygulanır. MySQL DDL implicit commit yapar; dosyanın tamamı transaction ile geri alınamaz. Hata olursa sonraki ifadeye geçmeyin ve yeni ileri-düzeltme planı hazırlayın.
7. `production-schema-verify-v3.23.sql` çalıştırılır. Tablo sonuçları `PRESENT`, kolon/indeks/FK/CHECK fark sonuçları boş olmalıdır. Capability satırları insan tarafından kanıt ve freshness açısından incelenir.
8. Laravel `migrations` tablosuna körlemesine kayıt eklemeyin. Önce gerçek migration kayıtlarını teşhis çıktısıyla karşılaştırın:
   - Migration kaydı varsa fakat şema eksikse, kayıt değiştirilmez; ayrı forward corrective migration/SQL gerekir.
   - Şema manuel kurulmuş fakat migration kaydı yoksa, sonraki `artisan migrate` aynı tabloyu oluşturmaya çalışabilir. Uygulama açılmadan önce exact migration dosyası, şema doğrulaması ve mevcut batch sırası birlikte gözden geçirilmelidir.
   - cPanel'de güvenli Laravel migration çalıştırma kanalı yoksa yalnız DDL uygulayıp uygulamayı açmayın; migration tracking uzlaştırması için kontrollü deployment adımı oluşturun. Bu paket otomatik `INSERT INTO migrations` içermez.
9. Queue worker/cron ancak doğrulama temiz ve uygulama smoke kontrolleri başarılıysa yeniden başlatılır.

Rollback sınırlaması: Bu paket DROP/TRUNCATE içermez. MySQL DDL transaction-safe olmadığı için geri dönüş, test edilmiş yedekten restore veya ayrıca onaylanmış ileri düzeltmedir; phpMyAdmin'da doğaçlama `DROP COLUMN/TABLE` uygulanmaz.
