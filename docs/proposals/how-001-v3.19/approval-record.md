# HOW-001 taslak onay kaydı

Durum: **RECORDED / DRAFT PACKAGE / NOT RESOLVED**  
Kayıt tarihi: `2026-10-23`

Kullanıcı aşağıdaki üst seviye seçenekleri açıkça onayladı:

| Karar | Kullanıcı seçimi | Kısa anlam |
|---|---|---|
| `OD-HOW-001` | **A** | İlk kapsam yalnız CURRENT PlanRevision içindeki POI öğesi; HOW-001 DONE sayılmaz. |
| `OD-HOW-002` | **A** | Deterministik Türkçe şablon ve typed evidence slot. |
| `OD-HOW-003` | **A** | Yalnız kanonik snapshot ve onaylı source reverify operasyonları. |
| `OD-HOW-004` | **A** | Kaynak freshness durumunu devral; stale kritik kanıt için senkron reverify. |
| `OD-HOW-005` | **B** | Yan etkisiz GET ve ayrı POST refresh. |
| `OD-HOW-006` | **A** | HOW'a özel kalıcı kayıt olmadan hesaplanan read model. |
| `OD-HOW-007` | **A** | Yalnız TripRequest owner read/refresh. |
| `OD-HOW-008` | **A** | CURRENT revision item ve `If-Match`. |
| `OD-HOW-009` | **A** | GET anahtarsız; refresh idempotent; yeni PlanRevision/outbox yok. |
| `OD-HOW-010` | **A** | OD-028 web kanalı: Bearer API + session/CSRF web API. |

Bu kayıt yalnız proposal klasöründedir. Aktif `registry/` ve `contracts/` zincirine işlenmemiştir; hiçbir karar `RESOLVED` değildir ve v3.19 aktif değildir.

Ek kullanıcı onayları: `OD-HOW-004-D1=A`, `OD-HOW-009-D1=A`, `OD-HOW-009-D2=A`, `OD-HOW-011=A`.

Proposal karar paketi kullanıcı açısından tamamlanmıştır. Aktif sözleşmeye taşıma ayrı v3.19 delta dosyalarında yapılmıştır; bu geçmiş proposal kaydı `OPEN` durumunu korur.
