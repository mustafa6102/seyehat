<?php

$registry = json_decode((string) file_get_contents(base_path('registry/registry_snapshot.json')), true, 512, JSON_THROW_ON_ERROR);

// Presentation-only screen archetypes: each EPIC gets the task-oriented layout its analysis stories describe.
$archetypes = [
    'profile' => ['label' => 'Profil ve talep', 'icon' => 'users', 'layout' => 'Adım adım form ve özet paneli'],
    'search' => ['label' => 'Arama ve karşılaştırma', 'icon' => 'search', 'layout' => 'Filtre çubuğu ve karşılaştırılabilir sonuç kartları'],
    'route' => ['label' => 'Rota ve aktarma', 'icon' => 'route', 'layout' => 'Bacak bacak rota çizelgesi ve risk notları'],
    'cost' => ['label' => 'Fiyat ve maliyet', 'icon' => 'wallet', 'layout' => 'Kalem kalem maliyet dökümü ve veri yeterliliği'],
    'itinerary' => ['label' => 'Plan ve gün düzeni', 'icon' => 'calendar', 'layout' => 'Gün sütunları ve zaman çizelgesi'],
    'ai' => ['label' => 'AI planlama', 'icon' => 'sparkles', 'layout' => 'Plan önerisi, gerekçe ve kaynak paneli'],
    'audit' => ['label' => 'Denetim ve uyum', 'icon' => 'list-check', 'layout' => 'Kontrol listesi ve bulgu kartları'],
    'live' => ['label' => 'Canlı yolculuk', 'icon' => 'clock', 'layout' => 'Şimdi, sıradaki ve hızlı erişim kartları'],
    'platform' => ['label' => 'Güvenlik ve güvenilirlik', 'icon' => 'shield', 'layout' => 'Durum özeti ve ayar listesi'],
];
$epicArchetypes = [
    'EPIC-01' => 'profile', 'EPIC-02' => 'profile', 'EPIC-03' => 'search', 'EPIC-04' => 'search', 'EPIC-05' => 'route',
    'EPIC-06' => 'route', 'EPIC-07' => 'search', 'EPIC-08' => 'cost', 'EPIC-09' => 'search', 'EPIC-10' => 'cost',
    'EPIC-11' => 'cost', 'EPIC-12' => 'itinerary', 'EPIC-13' => 'search', 'EPIC-14' => 'search', 'EPIC-15' => 'search',
    'EPIC-16' => 'search', 'EPIC-17' => 'itinerary', 'EPIC-18' => 'itinerary', 'EPIC-19' => 'itinerary', 'EPIC-20' => 'ai',
    'EPIC-21' => 'audit', 'EPIC-22' => 'live', 'EPIC-23' => 'live', 'EPIC-24' => 'platform', 'EPIC-25' => 'audit',
    'EPIC-26' => 'platform',
];

$epics = [];
foreach ($registry['master']['EPIC'] as [$id, $title, $milestone]) {
    $archetype = $epicArchetypes[$id] ?? 'platform';
    $epics[$id] = compact('id', 'title', 'milestone', 'archetype');
}

$connectedRoutes = [
    'PLT-AUTH-001' => 'auth.login',
    'TRIP-001' => 'trip-planner.index',
    'FLT-002' => 'trip-requests.mine',
    'FLT-005' => 'trip-requests.mine',
    'FLT-REPRICE-001' => 'trip-requests.mine',
    'TRIP-BLD-001' => 'trip-requests.mine',
    'ACC-001' => 'trip-requests.mine',
    'POI-001' => 'trip-requests.mine',
    'DAY-001' => 'trip-requests.mine',
    // HOW-001 completes AC/TS-HOW-001-01..03 through the active v3.19 owner-only guide contract:
    // unknown facts are not invented, verified links are preserved, and STALE evidence refreshes fail closed.
    'HOW-001' => 'trip-requests.mine',
    'PLT-CONC-001' => 'trip-requests.mine',
    'PLT-REL-001' => 'trip-requests.mine',
    // RANK-001 currently exposes only the approved PARTIAL-vs-COMPLETE safety slice.
    // The canonical/personalized comparator remains blocked by OD-007.
    'RANK-001' => 'trip-requests.mine',
    // CST-001 exposes only the approved known-lower-bound / unknown-component slice.
    // Cross-currency normalization remains blocked by OD-005.
    'CST-001' => 'trip-requests.mine',
    // GND-001 exposes only AC-GND-001-02: without verified ground data the leg remains UNKNOWN
    // and the total cost coverage remains PARTIAL. Provider-backed offers remain blocked by OD-003.
    'GND-001' => 'trip-requests.mine',
    // D2D-001 exposes only an owner-scoped fail-closed screen. No comparison AC is complete
    // until OD-003/OD-005/OD-007 provide verified first/last-mile, time and cost evidence.
    'D2D-001' => 'trip-requests.mine',
];

// Traveler-facing information architecture. Internal capabilities remain reachable only from the technical catalog.
$categories = [
    'planlama' => ['title' => 'Planını oluştur', 'description' => 'Seyahat talebini oluştur, planını sürdür ve kişiselleştirme seçeneklerini keşfet.', 'icon' => 'route'],
    'ulasim' => ['title' => 'Ulaşım', 'description' => 'Uçuşları, aktarmaları, kara bağlantılarını ve toplam yolculuk etkisini birlikte değerlendir.', 'icon' => 'plane'],
    'konaklama-kesif' => ['title' => 'Konaklama ve keşif', 'description' => 'Konaklama, gezilecek yer, yemek ve etkinlik seçeneklerini planına bağla.', 'icon' => 'map-pin'],
    'gunluk-plan' => ['title' => 'Günlük plan', 'description' => 'Günlerini düzenle, zaman çakışmalarını ve uygulanabilirlik uyarılarını gör.', 'icon' => 'calendar'],
    'hazirlik-yolculuk' => ['title' => 'Hazırlık ve yolculuk', 'description' => 'Rezervasyon hazırlıklarından yolculuk sırasındaki değişikliklere kadar sonraki adımı takip et.', 'icon' => 'check'],
    'hesap-gizlilik' => ['title' => 'Hesap ve gizlilik', 'description' => 'Oturum, erişim ve kişisel veri sınırlarını anlaşılır biçimde gör.', 'icon' => 'shield'],
];

$categoryStories = [
    'planlama' => ['TRIP-001', 'TRV-001', 'TRIP-BLD-001', 'AI-PLN-001', 'AI-PER-001', 'AI-ALT-001', 'AI-EXP-001'],
    'ulasim' => ['FLT-002', 'FLT-005', 'FLT-REPRICE-001', 'RANK-001', 'RCG-001', 'STF-003', 'TRF-001', 'VIS-002', 'CST-001', 'GND-001', 'D2D-001', 'PRC-004'],
    'konaklama-kesif' => ['ACC-001', 'POI-001', 'FOOD-001', 'EVT-001'],
    'gunluk-plan' => ['DAY-001', 'HOW-001', 'AUD-001', 'AUD-002', 'AUD-003', 'AUD-004', 'AUD-005'],
    'hazirlik-yolculuk' => ['PREP-001', 'LIVE-001', 'LIVE-002', 'LIVE-003', 'LIVE-004', 'RPL-001', 'RPL-002', 'RPL-003', 'RPL-004', 'RPL-005', 'PLT-OFF-001'],
    'hesap-gizlilik' => ['PLT-AUTH-001', 'PLT-PII-001'],
];

$internalStories = ['PRV-001', 'AI-STR-001', 'AI-PRV-001', 'AI-OPS-001', 'AI-PRIV-001', 'AUD-006', 'AUD-007', 'AUD-008', 'PLT-CONC-001', 'PLT-AISEC-001', 'PLT-REL-001'];
$mainDestinations = ['TRIP-001', 'TRIP-BLD-001'];
$contextualStories = ['FLT-005', 'FLT-REPRICE-001', 'HOW-001', 'AI-EXP-001', 'LIVE-002', 'LIVE-003', 'LIVE-004', 'RPL-004', 'RPL-005'];
$tripContextStories = ['TRV-001', 'RANK-001', 'RCG-001', 'STF-003', 'TRF-001', 'VIS-002', 'CST-001', 'GND-001', 'D2D-001', 'PRC-004', 'DAY-001', 'AUD-001', 'AUD-002', 'AUD-003', 'AUD-004', 'AUD-005', 'PREP-001', 'LIVE-001', 'RPL-001', 'RPL-002', 'RPL-003'];

$deliveredCoverage = [
    'ACC-001' => [
        'requirements' => ['FR-ACC-001-03', 'RULE-ACC-001-06'],
        'acceptance_criteria' => ['AC-ACC-001-07'],
        'test_scenarios' => ['TS-ACC-001-07'],
    ],
    'POI-001' => [
        'requirements' => ['RULE-POI-001-07'],
        'acceptance_criteria' => ['AC-POI-001-08'],
        'test_scenarios' => ['TS-POI-001-08'],
    ],
    'HOW-001' => [
        'requirements' => ['FR-HOW-001-01', 'FR-HOW-001-02'],
        'acceptance_criteria' => ['AC-HOW-001-01', 'AC-HOW-001-02', 'AC-HOW-001-03'],
        'test_scenarios' => ['TS-HOW-001-01', 'TS-HOW-001-02', 'TS-HOW-001-03'],
    ],
];

$categoryByStory = [];
foreach ($categoryStories as $categoryId => $storyIds) {
    foreach ($storyIds as $storyId) {
        $categoryByStory[$storyId] = $categoryId;
    }
}

$stories = [];
foreach ($registry['stories'] as $story) {
    $stories[$story['id']] = [
        'id' => $story['id'],
        'title' => $story['title'],
        'epics' => $story['epic'],
        'phase' => $story['phase'],
        'story' => $story['story'],
        'value' => $story['value'] ?? $story['story'],
        'scope' => $story['scope'] ?? $story['story'],
        'out' => $story['out'] ?? 'Aktif sözleşmenin desteklemediği işlemler bu kapsamda çalıştırılmaz.',
        'requirements' => array_values(array_filter([
            $story['id'],
            $story['feat'] ?? null,
            ...($story['bus'] ?? []),
            ...($story['nfr'] ?? []),
            ...array_map(fn (string|int $key): string => 'RULE-'.$story['id'].'-'.str_pad((string) $key, 2, '0', STR_PAD_LEFT), array_keys($story['rules'] ?? [])),
            ...array_map(fn (string|int $key): string => 'FR-'.$story['id'].'-'.str_pad((string) $key, 2, '0', STR_PAD_LEFT), array_keys($story['fr'] ?? [])),
            ...array_map(fn (string|int $key): string => 'AC-'.$story['id'].'-'.str_pad((string) $key, 2, '0', STR_PAD_LEFT), array_keys($story['ac'] ?? [])),
            ...array_map(fn (string|int $key): string => 'TS-'.$story['id'].'-'.str_pad((string) $key, 2, '0', STR_PAD_LEFT), array_keys($story['ts'] ?? [])),
        ])),
        'route' => $connectedRoutes[$story['id']] ?? null,
        'delivered_coverage' => $deliveredCoverage[$story['id']] ?? null,
        'category' => $categoryByStory[$story['id']] ?? null,
        'user_facing' => ! in_array($story['id'], $internalStories, true),
        'classification' => match (true) {
            in_array($story['id'], $internalStories, true) => 'internal',
            in_array($story['id'], $mainDestinations, true) => 'main_navigation',
            in_array($story['id'], $contextualStories, true) => 'contextual',
            in_array($story['id'], $tripContextStories, true) => 'trip_context',
            default => 'category_subpage',
        },
        'archetype' => $epics[$story['epic'][0]]['archetype'] ?? 'platform',
        // Analysis text shown as-is: functional requirements as tasks, business rules as trust rules and the
        // Given/When/Then acceptance criteria split into situation, action and expected result.
        'tasks' => array_values($story['fr'] ?? []),
        'rules' => array_values($story['rules'] ?? []),
        'checks' => array_values(array_map(function (string $criterion): array {
            preg_match('/^Given (.+?) When (.+?) Then (.+)$/u', $criterion, $parts);

            return $parts === []
                ? ['given' => null, 'when' => null, 'then' => $criterion]
                : ['given' => $parts[1], 'when' => $parts[2], 'then' => $parts[3]];
        }, $story['ac'] ?? [])),
        'data' => $story['data'] ?? null,
    ];
}

return compact('epics', 'stories', 'archetypes', 'categories', 'categoryStories');
