# v3.19.0

- HOW-001 için yalnız CURRENT PlanRevision POI öğesi kapsamında deterministik Türkçe bağlamsal rehber.
- Yeni şemalar: `SCH-ContextualGuide`, `SCH-InstructionBlock`, INTERNAL `SCH-OperationIdempotencyRecord`.
- Yeni operasyonlar: yan etkisiz `OP-getTripPlanItemGuide` ve idempotent `OP-refreshTripPlanItemGuide`; OD-028 web kanalı eşleri.
- Freshness kaynak olgudan devralınır; HOW ayrı TTL veya provider capability üretmez. Source reverify profili/capability yoksa 503 fail-closed.
- Refresh PlanRevision veya outbox olayı oluşturmaz. Kalıcı replay ledger sonucu birebir döndürür ve TripRequest retention yaşam döngüsüne bağlıdır; OD-013 açık kalır.
- Kritik slotlar: TIME, FEE, TERMINAL, PLATFORM, LINK, ENTRY_RULE. STALE/EXPIRED/UNVERIFIED bağlantı aktif CTA değildir.
