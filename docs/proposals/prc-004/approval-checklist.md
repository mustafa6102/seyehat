# PRC-004 açık onay kontrol listesi

Durum: **PROPOSAL**. İşaretlenmemiş seçeneklerin varsayılanı yoktur; öneriler **PROVISIONAL**'dır.

> 2026-10-23 kullanıcı yönü `approval-record.md` içinde kaydedildi. 2026-10-25 seçimleri `draft-1/contract-delta.yaml` içinde PROPOSED/NON-NORMATIVE olarak kaydedildi. Aktif contract deltası, politika yönetişim alanları, `OD-006` ve `OD-013` çözülmüş değildir; aşağıdaki boş alanlar otomatik onay sayılmaz.

## Ürün ve sözleşme kararları

### OD-PRC-001 — Karşılaştırılabilir snapshot kümesi

- [ ] A — Tüm kanonik route/fare/baggage/currency anahtarlarında kesin eşleşme; eksik/UNKNOWN karşılaştırılamaz. **PROVISIONAL öneri**
- [ ] B — Onaylanacak eşdeğerlik grupları.
- [ ] C — Provider bazında ayrı kohort.

Seçim: `_____`

### OD-PRC-002 — Veri yeterliliği modeli

- [ ] A — Minimum snapshot adedi + minimum zaman kapsamı birlikte, versiyonlu config; config yoksa fail-closed. **PROVISIONAL öneri**
- [ ] B — Provider/kohort bazlı versiyonlu eşikler.
- [ ] C — Eşik onayına kadar yalnız `INSUFFICIENT_DATA`.

Seçim: `_____`

A veya B seçilirse ayrıca açıkça girilmesi zorunludur; boş değer onay değildir:

- Minimum snapshot adedi: `_____`
- Minimum zaman kapsamı ve birimi: `_____`
- Config sahibi: `_____`
- İlk config version kimliği: `_____`

### OD-PRC-003 — Outlier politikası

- [ ] A — Onaylı deterministik yöntem + parametreler versiyonlu config; config yoksa değerlendirme yok. **PROVISIONAL öneri**
- [ ] B — Yalnız provider'ın açık invalid/outlier işareti.
- [ ] C — Outlier ayrımı yok; `AC-PRC-004-03` uygulanmaz.

Seçim: `_____`

A seçilirse ayrıca açıkça girilmesi zorunludur; boş değer onay değildir:

- Yöntem adı/tanımı: `_____`
- Gerekli parametreler ve değerleri: `_____`
- Config sahibi: `_____`
- İlk config version kimliği: `_____`

### OD-PRC-004 — İlk contract/API kapsamı

- [ ] A — Owner-only salt-okunur değerlendirme + Türkçe UI; notification ve hedef fiyat kapsam dışı. **PROVISIONAL öneri**
- [ ] B — Değerlendirme + notification aboneliği.
- [ ] C — Yalnız internal servis; kullanıcı UI'ı yok.

Seçim: `_____`

### OD-PRC-005 — `OD-006` bağı

- [ ] A — Notification dışı PRC-004 ilk dilimi `OD-006`'dan ayrılır; aktif registry değişikliği ayrıca onaylanır. **PROVISIONAL öneri**
- [ ] B — Önce `OD-006` çözülür; PRC-004 bekler.

Seçim: `_____`

## Mimari önkoşul

`ADR-009` v3.3 ile **ACCEPTED / MySQL 8**'dir. Bu mimari önkoşul tamamlanmıştır; ancak snapshot schema'sı ile `OD-013` retention/privacy kararı aktif ve onaylı olmadan DDL hazırlanamaz.

## Onay kapsamı

- [ ] Seçimler yalnız proposal hazırlığını ilerletir; aktif contract/registry bu kayıtla değişmez.
- [ ] Numeric eşikler, outlier yöntemi ve parametreleri boş bırakılmışsa `AC-PRC-004-02/03` implementation-ready değildir.
- [ ] Production MySQL `2002` bağlantı sorunu deployment doğrulama engelidir; PRC-004 requirement veya schema kararı değildir.

Onaylayan / tarih: `_____`
