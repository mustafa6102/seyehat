# PRC-004 kullanıcı yönü kayıt özeti

Kayıt tarihi: 2026-10-23  
Durum: **APPROVED DIRECTION / CONTRACT NOT ACTIVE**

Kullanıcı aşağıdaki uygulama yönünü onayladı:

- Karşılaştırılabilir kohortta yalnız kesin kanonik eşleşme kullanılacak.
- Yeterlilik veya outlier config'i çözülmemişse değerlendirme fail-closed olacak.
- İlk yüzey owner-only, salt-okunur API ve Türkçe UI olacak.
- Hedef fiyat, notification ve varsayımsal provider davranışı üretilmeyecek.
- `OD-006` açık kalacak; bu kayıt onu RESOLVED yapmaz. Bu belgedeki tarihsel `ADR-009` varsayımı daha sonra v3.3 ile aşılmıştır: `ADR-009` ACCEPTED / MySQL 8'dir.

Bu yön şu boşlukları onaylamaz veya doldurmaz:

- route/fare/baggage/currency kohort anahtarlarının kanonik alan kimlikleri;
- yeterlilik için numeric snapshot adedi veya zaman kapsamı;
- outlier yöntemi veya parametreleri;
- tarihsel snapshot veri kaynağı, saklama tablosu veya bölümleme DDL'i;
- HTTP resource kimliği/path'i, response JSON Schema'sı, reason/error kodları ve lifecycle.

Sonuç: `RULE-PRC-004-01` için fail-closed davranış yönü onaylıdır; `AC-PRC-004-02/03` implementation-ready değildir. Aktif v3.19 sözleşmesi değişmemiştir.
