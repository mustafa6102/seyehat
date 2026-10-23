# Contract Versioning

- Patch (`v3.2.x`): geriye uyumlu alan/operasyon/hata kodu ekleme, validator düzeltmesi, metadata/traceability düzeltmesi, mevcut davranışı netleştiren geçiş ekleme.
- Breaking: alan silme, alan tipini/semantiğini değiştirme, mevcut durum geçişini kaldırma, mevcut operasyonu uyumsuz değiştirme. Breaking değişiklik yeni sözleşme major/minor baseline ve ADR gerektirir.
- Pre-release istisnası: Henüz dış/iç tüketicisi olmayan ve production kullanımına açılmamış contractlarda breaking değişiklik, CHANGELOG içinde açıkça PRE-RELEASE BREAKING olarak işaretlenerek v3.2.x patch içinde yapılabilir. İlk tüketici/production adoption sonrasında bu istisna sona erer.
- Generated Word kapağı kaynak contract sürümünü göstermelidir.
