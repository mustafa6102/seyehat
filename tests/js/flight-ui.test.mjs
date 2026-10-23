// FLT-002 / FLT-005 / FLT-REPRICE-001 / ADR-011 rendering of public/assets/flight.js (contract-shaped fixtures).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { element, loadScript, settle } from './fake-dom.mjs';

const money = (minorUnits, currency = 'TRY') => ({ minor_units: minorUnits, currency });
const allowance = (value, certainty = 'EXACT', verification = 'FRESH') => ({
    value, certainty, source_reference: 'p:1', fetched_at: '2026-09-29T10:00:00+00:00', verified_at: null, expires_at: null, verification_status: verification,
});
const fee = (component, mandatory, value, certainty = 'EXACT', verification = 'FRESH') => ({
    component, mandatory, value, certainty, source_reference: 'p:1', fetched_at: '2026-09-29T10:00:00+00:00', verified_at: null, expires_at: null, verification_status: verification,
});
const price = (completeness, certainty, value, lowerBound = null, unknownComponents = []) => ({
    value, certainty, completeness, known_lower_bound: lowerBound, unknown_components: unknownComponents,
    fx_snapshot_id: null, input_refs: [], calculation_version: 'v1', calculated_at: '2026-09-29T10:00:00+00:00',
});
const time = (local, zone, utc) => ({ local_datetime: local, iana_timezone: zone, utc_instant: utc });
const segment = (carrier, number, departure, arrival, minutes, model = 'UNKNOWN') => ({
    operating_carrier: carrier, operating_flight_number: number, marketing_flight_references: [], carrier_model: model, departure, arrival, duration_minutes: minutes,
});
// IST 06:11 (UTC+3) -> AMS 08:40 (UTC+2): 3 h 29 min.
const DIRECT = [segment('ZZ', '1234', time('2026-10-09T06:11:00', 'Europe/Istanbul', '2026-10-09T03:11:00Z'), time('2026-10-09T08:40:00', 'Europe/Amsterdam', '2026-10-09T06:40:00Z'), 209)];
// Two segments with a layover, arriving the next local day: 22:30 -> 01:15 (+1), total 3 h 45 min including the layover.
const CONNECTING = [
    segment('ZZ', '10', time('2026-10-09T22:30:00', 'Europe/Istanbul', '2026-10-09T19:30:00Z'), time('2026-10-09T23:30:00', 'Europe/Berlin', '2026-10-09T21:30:00Z'), 120, 'FULL_SERVICE'),
    segment('ZZ', '20', time('2026-10-10T00:15:00', 'Europe/Berlin', '2026-10-09T22:15:00Z'), time('2026-10-10T01:15:00', 'Europe/Amsterdam', '2026-10-09T23:15:00Z'), 60),
];
const knownBaggage = (fees = []) => ({ personal_item: allowance(true), cabin_baggage: allowance(true), checked_baggage: allowance(true), fees });
const offer = (id, offerPrice, fees = [], segments = DIRECT, baggage = undefined) => ({
    provider_offer_id: id,
    state: 'CURRENT',
    price: offerPrice,
    expires_at: '2026-10-01T10:00:00+00:00',
    baggage: baggage === undefined
        ? { personal_item: allowance(true), cabin_baggage: allowance(null, 'UNKNOWN'), checked_baggage: allowance(false, 'ESTIMATED', 'STALE'), fees }
        : baggage,
    bookable: false,
    sources: [{ provider_id: 'duffel_flights', provider_role: 'AGGREGATOR', distribution_channel: 'NDC' }],
    segments,
});

const COMPLETE = offer('A-1', price('COMPLETE', 'EXACT', money(123450)));
const PARTIAL = offer('B-1', price('PARTIAL', 'ESTIMATED', null, money(99900), ['CHECKED_BAGGAGE_FEE']), [
    fee('CHECKED_BAGGAGE_FEE', true, null, 'UNKNOWN'),
    fee('SEAT_SELECTION', false, money(15000), 'ESTIMATED', 'STALE'),
]);
const UNKNOWN = offer('C-1', price('UNKNOWN', 'UNKNOWN', null));
const DUFFEL = offer('off_0000AzV5v0bT1xG9pQ', price('COMPLETE', 'EXACT', money(452000, 'EUR')), [], DIRECT, knownBaggage());
const text = (node) => node.textContent.replace(/\s/g, ' ');
// The always-visible part of a card: everything outside the "Ayrıntıları gör" details element.
const summaryText = (card) => card.children.filter((child) => child.tagName !== 'details').map(text).join(' ');
const detailsOf = (card) => card.byTag('details')[0];

const searchPage = (job, startStatus = 202, pollStatus = 200) => {
    const nodes = {
        '[data-flight-search]': element({
            startUrl: '/web-api/v1/flight-search-jobs', pollUrl: '/web-api/v1/flight-search-jobs/__SEARCH__', offerUrl: '/teklifler/__OFFER__',
            storageKey: 'flight-search:trip-1', tripRequestId: 'trip-1',
        }),
        '[data-search-status]': element(),
        '[data-search-start]': element(),
        '[data-search-refresh]': element(),
        '[data-search-restart]': element(),
        '[data-search-results]': element(),
        '[data-offer-list]': element(),
    };
    nodes['[data-search-refresh]'].hidden = true;
    nodes['[data-search-restart]'].hidden = true;
    nodes['[data-search-results]'].hidden = true;
    const polls = [];
    const { requests } = loadScript('public/assets/flight.js', nodes, (url, options) => {
        if (options.method === 'POST') {
            return startStatus === 202
                ? { status: 202, body: { search_id: 'search-1', status: 'QUEUED' } }
                : { status: startStatus, body: { error: { code: 'TRIP_REQUEST_INCOMPLETE', message_tr: 'Seyahat talebinin zorunlu alanları eksik.', correlation_id: null, details: null } } };
        }
        polls.push(url);

        return { status: pollStatus, body: typeof job === 'function' ? job(polls.length) : job };
    });

    return { nodes, requests, polls };
};

const job = (status, offers = null, error = null) => ({
    search_id: 'search-1', trip_request_id: 'trip-1', status, result: offers === null ? null : { offers }, error, expires_at: null,
});

test('start sends only the trip request id with an idempotency key and renders COMPLETE, PARTIAL and UNKNOWN prices in backend order', async () => {
    const { nodes, requests } = searchPage(job('COMPLETED', [UNKNOWN, PARTIAL, COMPLETE]));

    await nodes['[data-search-start]'].click();
    await settle();

    const start = requests.find((request) => request.method === 'POST');
    assert.equal(start.body, JSON.stringify({ trip_request_id: 'trip-1' }));
    assert.equal(start.headers['Idempotency-Key'], '00000000-0000-4000-8000-000000000001');
    assert.ok('X-CSRF-TOKEN' in start.headers, 'OD-028 web channel requests carry the CSRF header');
    assert.equal(nodes['[data-search-status]'].textContent, 'Arama tamamlandı: 3 uçuş teklifi bulundu.');
    assert.equal(nodes['[data-search-results]'].hidden, false);

    // Backend order is preserved (no ranking); the offer id only addresses the detail page.
    const cards = nodes['[data-offer-list]'].byClass('offer-card');
    assert.deepEqual(cards.map((card) => card.byTag('a')[0].href), ['/teklifler/C-1', '/teklifler/B-1', '/teklifler/A-1']);
    const [unknownCard, partialCard, completeCard] = cards.map(text);

    assert.match(completeCard, /₺1\.234,50/);
    assert.match(completeCard, /Toplam fiyat/);

    assert.match(partialCard, /En az ₺999,00/);
    assert.match(partialCard, /bu tutar kesin toplam fiyat değildir/);
    assert.match(partialCard, /Bilinmeyen bileşenler: CHECKED_BAGGAGE_FEE/);
    assert.match(partialCard, /Zorunlu ek ücretler.*CHECKED_BAGGAGE_FEE: Tutar bilinmiyor/);
    assert.match(partialCard, /İsteğe bağlı ek ücretler.*SEAT_SELECTION: ₺150,00 \(tahmini\) \(bilgi güncel olmayabilir\)/);

    assert.match(unknownCard, /Toplam fiyat doğrulanamadı/);
    assert.doesNotMatch(unknownCard, /₺\s?0|0,00/);

    // Baggage: known, unknown and estimated/stale allowances are distinguished.
    assert.match(completeCard, /Kişisel eşyaDahil/);
    assert.match(completeCard, /Kabin bagajıDoğrulanamadı/);
    assert.match(completeCard, /Kayıtlı bagajDahil değil \(tahmini, bilgi güncel olmayabilir\)/);
    assert.match(completeCard, /Yalnız arama sonucu; satın alınabilir olarak işaretlenmez\./);
});

test('a signed-out or non-owner poll shows the Turkish server message once and stops polling', async () => {
    for (const [status, message] of [[401, 'Bu işlem için oturum açmanız gerekiyor.'], [403, 'Bu işlem için yetkiniz bulunmuyor.']]) {
        const denied = { error: { code: status === 401 ? 'AUTHENTICATION_REQUIRED' : 'AUTHORIZATION_DENIED', message_tr: message, correlation_id: null, details: null } };
        const { nodes, polls } = searchPage(denied, 202, status);
        await nodes['[data-search-start]'].click();
        await settle();

        assert.equal(nodes['[data-search-status]'].textContent, message);
        assert.equal(nodes['[data-search-refresh]'].hidden, true);
        assert.equal(nodes['[data-search-results]'].hidden, true);
        assert.equal(polls.length, 1);
    }
});

test('queued, running and partial states keep refresh available and never fetch more than asked', async () => {
    const states = [job('QUEUED'), job('RUNNING'), job('PARTIAL', [COMPLETE])];
    const { nodes, polls } = searchPage((n) => states[Math.min(n, states.length) - 1]);

    await nodes['[data-search-start]'].click();
    await settle();
    assert.equal(nodes['[data-search-status]'].textContent, 'Uçuş aramanız sıraya alındı.');
    assert.equal(nodes['[data-search-results]'].hidden, true);
    assert.equal(nodes['[data-search-refresh]'].hidden, false);

    await nodes['[data-search-refresh]'].click();
    assert.equal(nodes['[data-search-status]'].textContent, 'Uçuşlar aranıyor.');
    assert.equal(nodes['[data-search-refresh]'].hidden, false);

    await nodes['[data-search-refresh]'].click();
    assert.equal(nodes['[data-search-status]'].textContent, 'Uçuşlar aranıyor; ilk teklifler aşağıda.');
    assert.equal(nodes['[data-search-results]'].hidden, false);
    assert.equal(nodes['[data-offer-list]'].byClass('offer-card').length, 1);
    // Without a configured interval there is no automatic polling: exactly one poll per user action.
    await settle();
    assert.equal(polls.length, 3);
});

test('completed search with zero offers shows an empty state instead of results', async () => {
    const { nodes } = searchPage(job('COMPLETED', []));
    await nodes['[data-search-start]'].click();
    await settle();

    assert.equal(nodes['[data-search-status]'].textContent, 'Aramanıza uygun uçuş bulunamadı.');
    assert.equal(nodes['[data-search-results]'].hidden, true);
    assert.equal(nodes['[data-offer-list]'].byClass('offer-card').length, 0);
    assert.equal(nodes['[data-search-refresh]'].hidden, true);
});

test('failed and expired searches show the Turkish server message and offer a restart without results', async () => {
    for (const [status, message] of [['FAILED', 'Uçuş araması tamamlanamadı. Lütfen yeniden deneyin.'], ['EXPIRED', 'Arama süresi doldu. Yeniden arama başlatabilirsiniz.']]) {
        const { nodes } = searchPage(job(status, null, { code: null, message_tr: message }));
        await nodes['[data-search-start]'].click();
        await settle();

        assert.equal(nodes['[data-search-status]'].textContent, message);
        assert.equal(nodes['[data-search-results]'].hidden, true);
        assert.equal(nodes['[data-search-restart]'].hidden, false);
        assert.equal(nodes['[data-search-refresh]'].hidden, true);
    }
});

test('a rejected start shows the contract error message and keeps the start button usable', async () => {
    const { nodes, polls } = searchPage(job('QUEUED'), 422);
    await nodes['[data-search-start]'].click();
    await settle();

    assert.equal(nodes['[data-search-status]'].textContent, 'Seyahat talebinin zorunlu alanları eksik.');
    assert.equal(nodes['[data-search-start]'].disabled, false);
    assert.equal(polls.length, 0);
});

const offerPage = (reprice) => {
    const nodes = {
        '[data-offer-detail]': element({ offerUrl: '/api/v1/offers/B-1', repriceUrl: '/api/v1/offers/B-1/reprice' }),
        '[data-offer-body]': element(),
        '[data-reprice-section]': element(),
        '[data-reprice]': element(),
        '[data-reprice-result]': element(),
    };
    nodes['[data-reprice-section]'].hidden = true;
    const { requests } = loadScript('public/assets/flight.js', nodes, (url, options) => (options.method === 'POST' ? reprice : { status: 200, body: PARTIAL }));

    return { nodes, requests };
};

const snapshot = (snapshotOffer, terms) => ({ offer: snapshotOffer, fare_conditions: { terms, baggage: snapshotOffer.baggage }, captured_at: '2026-09-29T10:00:00+00:00' });
const term = (key, value, certainty = 'EXACT') => ({ key, value, certainty, source_reference: 'p:1', fetched_at: '2026-09-29T10:00:00+00:00', verified_at: null, expires_at: null, verification_status: 'FRESH' });
// The canonical offer as OP-repriceOffer returns it: its OfferState and, when the provider confirmed it, verified_at.
const repriced = (base, state, verifiedAt = null) => ({
    ...base,
    state,
    sources: [{ provider_id: 'duffel_flights', provider_role: 'AGGREGATOR', distribution_channel: 'UNKNOWN', fetched_at: '2026-09-29T10:00:00+00:00', verified_at: verifiedAt, expires_at: base.expires_at, verification_status: verifiedAt ? 'FRESH' : 'UNVERIFIED' }],
});
const repriceResult = (current, overrides = {}) => ({ status: 200, body: {
    previous: snapshot(COMPLETE, []),
    current: snapshot(current, []),
    changes: { price_changed: false, fare_conditions_changed: false, baggage_changed: false, critical_change: false },
    reason_codes: [],
    previous_approval_valid: false,
    continuation_allowed: false,
    reprice_capability: { operation: 'reprice', supported: true, status: 'FRESH', evidence: 'e', verified_at: null, verification_status: 'FRESH' },
    ...overrides,
} });
const flat = (node) => node.textContent.replace(/\s/g, ' ');

test('TS-FLT-REPRICE-001-01: opening a selected offer reprices it once automatically and shows the verification time and price', async () => {
    const current = repriced(offer('B-1', price('COMPLETE', 'EXACT', money(123450))), 'CURRENT', '2026-09-29T10:00:00+00:00');
    const { nodes, requests } = offerPage(repriceResult(current, { previous_approval_valid: true, continuation_allowed: true }));
    await settle();

    // Exactly one OP-repriceOffer call, made without any user action; the stored search result is replaced.
    assert.deepEqual(requests.map((request) => `${request.method} ${request.url}`), ['GET /api/v1/offers/B-1', 'POST /api/v1/offers/B-1/reprice']);
    assert.equal(nodes['[data-reprice-section]'].hidden, false);
    const result = flat(nodes['[data-reprice-result]']);
    assert.match(result, /Teklif yeniden doğrulandı; fiyat ve koşullar değişmedi\. Önceki onayınız geçerli\./);
    assert.match(result, /Son doğrulama: 29 Eyl 2026 \d{2}:00 · Doğrulanan fiyat: ₺1\.234,50/);
    assert.match(result, /rezervasyon\/yönlendirme adımı henüz kullanıma açılmadı\./);
    assert.doesNotMatch(result, /kabul ediyorum|Satın al|Rezervasyon yap/);
    const body = flat(nodes['[data-offer-body]']);
    assert.match(body, /₺1\.234,50/);
    assert.doesNotMatch(body, /En az ₺999,00|yeniden doğrulanıyor/);
});

test('TS-FLT-REPRICE-001-03/07: a changed price, fare or baggage condition shows new values and needs explicit acknowledgement', async () => {
    const changed = repriced(offer('B-1', price('COMPLETE', 'EXACT', money(150000))), 'CHANGED', '2026-09-29T10:00:00+00:00');
    const { nodes } = offerPage(repriceResult(changed, {
        previous: snapshot(PARTIAL, [term('refundable', true)]),
        current: snapshot(changed, [term('refundable', null, 'UNKNOWN')]),
        changes: { price_changed: true, fare_conditions_changed: true, baggage_changed: true, critical_change: true },
        reason_codes: ['RC-EVIDENCE-OFFER-CHANGED'],
    }));
    await settle();
    const resultNode = nodes['[data-reprice-result]'];
    const result = flat(resultNode);

    assert.match(result, /Teklifin fiyatı veya koşulları değişti\. Önceki onay artık geçerli değil/);
    assert.match(result, /Teklifin fiyatı veya kritik koşulları değişti\./);
    assert.match(result, /Doğrulanan fiyat: ₺1\.500,00/);
    assert.match(result, /FiyatEn az ₺999,00 — Fiyatın bir kısmı henüz dahil değil; bu tutar kesin toplam fiyat değildir\.₺1\.500,00 — Toplam fiyatDeğişti/);
    // An unknown condition stays unknown; it is never shown as a value.
    assert.match(result, /Ücret koşullarırefundable: Evetrefundable: BilinmiyorDeğişti/);
    assert.match(result, /Devam etmek için güncel fiyatı ve koşulları onaylamanız gerekir\./);
    assert.doesNotMatch(result, /onayladınız|Satın al|Rezervasyon yap/);
    assert.match(flat(nodes['[data-offer-body]']), /₺1\.500,00.*Değişti|Değişti.*₺1\.500,00/);

    const [accept] = resultNode.byTag('button');
    assert.equal(accept.textContent, 'Güncel fiyatı ve koşulları kabul ediyorum');
    await accept.click();
    assert.equal(accept.disabled, true);
    assert.match(flat(resultNode), /Güncel fiyatı ve koşulları onayladınız\. Rezervasyon veya yönlendirme adımı henüz kullanıma açılmadığı için satın alma yapılmadı\./);
});

test('TS-FLT-REPRICE-001-04: an expired offer is not presented as current or purchasable', async () => {
    const expired = repriced(offer('B-1', price('COMPLETE', 'EXACT', money(123450))), 'EXPIRED', '2026-09-29T10:00:00+00:00');
    const { nodes } = offerPage(repriceResult(expired, { reason_codes: ['RC-EVIDENCE-OFFER-EXPIRED'] }));
    await settle();
    const result = flat(nodes['[data-reprice-result]']);

    assert.match(result, /Bu teklif artık geçerli değil\./);
    assert.match(result, /Teklifin geçerlilik süresi doldu\./);
    assert.match(result, /Bu teklifle şu anda devam edilemez\./);
    assert.doesNotMatch(result, /Son doğrulama|doğrulandı|kabul ediyorum/);
    assert.equal(nodes['[data-reprice-result]'].byTag('button').length, 0);
    assert.match(flat(nodes['[data-offer-body]']), /Süresi doldu/);
});

test('TS-FLT-REPRICE-001-05: a provider timeout leaves the offer UNVERIFIED and never labels it verified or current', async () => {
    const stale = repriced(COMPLETE, 'UNVERIFIED', '2026-09-28T08:00:00+00:00');
    const { nodes } = offerPage(repriceResult(stale, { reason_codes: ['RC-PROVIDER-TIMEOUT', 'RC-EVIDENCE-REPRICE-UNVERIFIED'] }));
    await settle();
    const result = flat(nodes['[data-reprice-result]']);

    assert.match(result, /Teklif sağlayıcıdan doğrulanamadı; fiyat ve koşullar değişmiş olabilir\. Bu teklif güncel olarak gösterilmez\./);
    assert.match(result, /Sağlayıcı yanıtı zamanında alınamadı\./);
    assert.match(result, /Bu teklifle şu anda devam edilemez\./);
    // The earlier search-time verification must not be shown as a successful re-verification.
    assert.doesNotMatch(result, /Son doğrulama|yeniden doğrulandı|Önceki onayınız geçerli|kabul ediyorum/);
    assert.match(flat(nodes['[data-offer-body]']), /Doğrulanmadı/);
});

test('TS-FLT-REPRICE-001-02: an unsupported reprice warns that the price may have changed', async () => {
    const stale = repriced(COMPLETE, 'UNVERIFIED', '2026-09-28T08:00:00+00:00');
    const { nodes } = offerPage(repriceResult(stale, {
        reason_codes: ['RC-EVIDENCE-REPRICE-UNVERIFIED'],
        reprice_capability: { operation: 'reprice', supported: false, status: 'UNKNOWN', evidence: null, verified_at: null, verification_status: 'UNVERIFIED' },
    }));
    await settle();
    const result = flat(nodes['[data-reprice-result]']);

    assert.match(result, /fiyat ve koşullar değişmiş olabilir/);
    assert.match(result, /Bu sağlayıcı için yeniden fiyatlama şu anda kullanılamıyor; gösterilen bilgiler arama anındaki bilgilerdir\./);
    assert.doesNotMatch(result, /Son doğrulama|yeniden doğrulandı/);
});

test('reprice failure is reported in Turkish without raw provider details and the stored offer is marked unverified', async () => {
    const { nodes, requests } = offerPage({ status: 503, body: { error: { code: 'PROVIDER_TIMEOUT', message_tr: 'x', correlation_id: null, details: { trace: 'vendor stack' } } } });
    await settle();

    assert.equal(flat(nodes['[data-reprice-result]']), 'Yeniden doğrulama şu anda yapılamadı. Lütfen daha sonra tekrar deneyin.');
    assert.match(flat(nodes['[data-offer-body]']), /teklif yeniden doğrulanamadığı için güncel olmayabilir\./);
    assert.doesNotMatch(flat(nodes['[data-offer-body]']), /vendor stack|PROVIDER_TIMEOUT/);
    assert.equal(nodes['[data-reprice]'].disabled, false);

    // A manual retry calls the same operation again.
    await nodes['[data-reprice]'].click();
    assert.equal(requests.filter((request) => request.method === 'POST').length, 2);
});

test('a repeated offer in one snapshot is listed once and a newer snapshot replaces the list without duplicates', async () => {
    const snapshots = [job('PARTIAL', [COMPLETE, COMPLETE]), job('PARTIAL', [COMPLETE, PARTIAL]), job('COMPLETED', [COMPLETE, PARTIAL, UNKNOWN])];
    const { nodes } = searchPage((n) => snapshots[Math.min(n, snapshots.length) - 1]);

    await nodes['[data-search-start]'].click();
    await settle();
    assert.equal(nodes['[data-offer-list]'].byClass('offer-card').length, 1);

    await nodes['[data-search-refresh]'].click();
    assert.equal(nodes['[data-offer-list]'].byClass('offer-card').length, 2);

    await nodes['[data-search-refresh]'].click();
    assert.equal(nodes['[data-offer-list]'].byClass('offer-card').length, 3);
    assert.equal(nodes['[data-search-refresh]'].hidden, true);
});

test('a network interruption keeps the search, offers a retry and never starts a second search', async () => {
    let calls = 0;
    const nodes = {
        '[data-flight-search]': element({ startUrl: '/s', pollUrl: '/p/__SEARCH__', offerUrl: '/t/__OFFER__', storageKey: 'k', tripRequestId: 'trip-1' }),
        '[data-search-status]': element(),
        '[data-search-start]': element(),
        '[data-search-refresh]': element(),
        '[data-search-restart]': element(),
        '[data-search-results]': element(),
        '[data-offer-list]': element(),
    };
    nodes['[data-search-refresh]'].hidden = true;
    const { requests } = loadScript('public/assets/flight.js', nodes, (url, options) => {
        if (options.method === 'POST') {
            return { status: 202, body: { search_id: 'search-1', status: 'QUEUED' } };
        }
        calls += 1;
        if (calls === 1) {
            throw new TypeError('network down');
        }

        return { status: 200, body: job('COMPLETED', [COMPLETE]) };
    });

    await nodes['[data-search-start]'].click();
    await settle();
    assert.match(nodes['[data-search-status]'].textContent, /Bağlantı kesildi/);
    assert.equal(nodes['[data-search-refresh]'].hidden, false);
    assert.equal(nodes['[data-search-start]'].hidden, true);

    await nodes['[data-search-refresh]'].click();
    assert.match(nodes['[data-search-status]'].textContent, /Arama tamamlandı/);
    assert.equal(requests.filter((request) => request.method === 'POST').length, 1);
});

test('TS-FLT-REPRICE-001-07: a baggage-only change with the same price still requires acknowledgement and keeps unknown bags unknown', async () => {
    const lostBag = { ...COMPLETE.baggage, checked_baggage: allowance(null, 'UNKNOWN') };
    const changed = repriced(offer('B-1', price('COMPLETE', 'EXACT', money(123450)), [], DIRECT, lostBag), 'CHANGED', '2026-09-29T10:00:00+00:00');
    const { nodes } = offerPage(repriceResult(changed, {
        changes: { price_changed: false, fare_conditions_changed: false, baggage_changed: true, critical_change: true },
        reason_codes: ['RC-EVIDENCE-OFFER-CHANGED'],
    }));
    await settle();
    const result = flat(nodes['[data-reprice-result]']);

    assert.match(result, /Fiyat₺1\.234,50 — Toplam fiyat₺1\.234,50 — Toplam fiyatDeğişmedi/);
    assert.match(result, /BagajKişisel eşya: Dahil · Kabin bagajı: Doğrulanamadı · Kayıtlı bagaj: Dahil değil.*Kayıtlı bagaj: DoğrulanamadıDeğişti/);
    assert.match(result, /Güncel fiyatı ve koşulları kabul ediyorum/);
    assert.doesNotMatch(result, /Kayıtlı bagaj: Dahil(?! değil)|onayladınız/);
});

const cardsFor = async (offers) => {
    const { nodes } = searchPage(job('COMPLETED', offers));
    await nodes['[data-search-start]'].click();
    await settle();

    return nodes['[data-offer-list]'].byClass('offer-card');
};

test('the default offer card shows the decision summary without offer ids or timezone identifiers', async () => {
    const [card] = await cardsFor([DUFFEL]);
    const summary = summaryText(card);

    assert.match(summary, /ZZ 1234/);
    assert.match(summary, /€4\.520,00|4\.520,00 €/);
    assert.match(summary, /Toplam fiyat/);
    assert.match(summary, /06:11 ?Kalkış.*3 sa 29 dk.*Direkt.*08:40 ?Varış/);
    assert.match(summary, /9 Eki 2026/);
    assert.match(summary, /Kabin bagajı dahil/);
    assert.match(summary, /Kayıtlı bagaj dahil/);
    // Secondary information is only inside the details area.
    assert.doesNotMatch(summary, /duffel_flights|Kaynak:|Geçerlilik|Zorunlu ek ücret|satın alınabilir/);
    // No raw provider offer id and no IANA timezone identifier anywhere in the card, details included.
    assert.doesNotMatch(text(card), /off_0000|Europe\/|Istanbul|Amsterdam/);
    assert.doesNotMatch(text(card), /Ayrıntı ve yeniden doğrulama|Teklifi seç/);
});

test('details are a collapsed, keyboard-accessible disclosure that keeps the canonical secondary information', async () => {
    const [card] = await cardsFor([DUFFEL]);
    const details = detailsOf(card);

    assert.equal(details.open, false);
    assert.equal(details.byTag('summary')[0].textContent, 'Ayrıntıları gör');
    const body = text(details);
    assert.match(body, /Uçuş bölümleri/);
    assert.match(body, /Kalkış: 9 Eki 2026 06:11 \(yerel saat\)/);
    assert.match(body, /Varış: 9 Eki 2026 08:40 \(yerel saat\)/);
    assert.match(body, /Teklif geçerliliği:/);
    assert.match(body, /Zorunlu ek ücret bildirilmedi\./);
    assert.match(body, /Kaynak: duffel_flights/);
    assert.match(body, /Yalnız arama sonucu; satın alınabilir olarak işaretlenmez\./);
    // Unknown carrier model is not shown at all.
    assert.doesNotMatch(body, /Taşıyıcı modeli bilinmiyor/);
    const link = details.byTag('a')[0];
    assert.equal(link.textContent, 'Güncel fiyatı ve koşulları kontrol et');
    assert.equal(link.href, '/teklifler/off_0000AzV5v0bT1xG9pQ');
});

test('stops and duration are derived only from the canonical segments', async () => {
    const [direct, connecting] = await cardsFor([DUFFEL, offer('D-1', price('COMPLETE', 'EXACT', money(100000)), [], CONNECTING)]);

    assert.match(summaryText(direct), /Direkt/);
    assert.doesNotMatch(summaryText(direct), /aktarma/);
    const summary = summaryText(connecting);
    assert.match(summary, /ZZ 10 · ZZ 20/);
    assert.match(summary, /22:30 ?Kalkış.*3 sa 45 dk.*1 aktarma.*01:15 \+1 gün ?Varış/);
    assert.doesNotMatch(summary, /Direkt/);
    assert.match(text(detailsOf(connecting)), /Tam hizmet/);
});

test('unknown baggage stays explicitly unknown and is never shown as included', async () => {
    const allUnknown = { personal_item: allowance(null, 'UNKNOWN'), cabin_baggage: allowance(null, 'UNKNOWN'), checked_baggage: allowance(null, 'UNKNOWN'), fees: [] };
    const cards = await cardsFor([
        offer('E-1', price('COMPLETE', 'EXACT', money(100000)), [], DIRECT, null),
        offer('E-2', price('COMPLETE', 'EXACT', money(100000)), [], DIRECT, allUnknown),
        COMPLETE,
    ]);

    for (const card of cards.slice(0, 2)) {
        const baggage = text(card.byClass('offer-baggage')[0]);
        assert.match(baggage, /Bagaj bilgisi bilinmiyor/);
        assert.doesNotMatch(baggage, /dahil|ücretsiz|0/i);
    }
    // Mixed: cabin unknown stays unknown, checked "not included" keeps its estimate/staleness notes.
    const mixed = text(cards[2].byClass('offer-baggage')[0]);
    assert.match(mixed, /Kabin bagajı bilgisi bilinmiyor/);
    assert.match(mixed, /Kayıtlı bagaj dahil değil \(tahmini, bilgi güncel olmayabilir\)/);
});

test('multiple offers render as separate cards in backend order and the detail page shows details expanded', async () => {
    const cards = await cardsFor([COMPLETE, DUFFEL, UNKNOWN]);
    assert.equal(cards.length, 3);
    assert.deepEqual(cards.map((card) => card.byTag('a')[0].href), ['/teklifler/A-1', '/teklifler/off_0000AzV5v0bT1xG9pQ', '/teklifler/C-1']);

    const { nodes } = offerPage({ status: 503 });
    await settle();
    const [detailCard] = nodes['[data-offer-body]'].byClass('offer-card');
    assert.equal(detailsOf(detailCard).open, true);
    assert.equal(detailCard.byTag('a').length, 0);
    assert.doesNotMatch(text(detailCard), /B-1/);
});

const requestList = (jobs, stored) => {
    const cards = Object.keys(jobs).concat(['trip-none']).map((id) => {
        const card = element({ requestCard: '', storageKey: `flight-search:${id}` });
        const status = element({ requestStatus: '' });
        status.hidden = true;
        const action = element({ requestAction: '' });
        action.textContent = 'Uçuş ara';
        card.append(status, action);

        return card;
    });
    const root = element({ pollUrl: '/api/v1/flight-search-jobs/__SEARCH__' });
    root.append(...cards);
    const initial = Object.fromEntries(Object.keys(jobs).filter((id) => stored.includes(id))
        .map((id) => [`flight-search:${id}`, JSON.stringify({ key: 'k', searchId: `search-${id}` })]));
    const { requests } = loadScript('public/assets/flight.js', { '[data-trip-requests]': root }, (url) => {
        const id = decodeURIComponent(url.split('/').pop()).replace('search-', '');

        return jobs[id] === 404 ? { status: 404, body: { error: {} } } : { status: 200, body: jobs[id] };
    }, initial);

    return { cards, requests };
};

test('Taleplerim cards show a Turkish search label only for a search this tab already knows', async () => {
    const jobs = {
        queued: job('QUEUED'),
        running: job('RUNNING'),
        ready: job('COMPLETED', [COMPLETE]),
        empty: job('COMPLETED', []),
        failed: job('FAILED', null, { code: null, message_tr: 'x' }),
        unknown: job('QUEUED'),
        gone: 404,
    };
    const { cards, requests } = requestList(jobs, ['queued', 'running', 'ready', 'empty', 'failed', 'gone']);
    await settle();

    const status = (card) => card.children[0];
    const action = (card) => card.children[1];
    const expected = ['Arama bekliyor', 'Aranıyor', 'Teklifler hazır', 'Uygun uçuş bulunamadı', 'Arama tamamlanamadı'];
    expected.forEach((label, index) => {
        assert.equal(status(cards[index]).hidden, false);
        assert.equal(status(cards[index]).textContent, `Uçuş araması: ${label}`);
        assert.equal(action(cards[index]).textContent, 'Teklifleri görüntüle');
    });
    // Not stored in this tab, a missing search and a card without a search keep "Uçuş ara" and no status.
    for (const card of cards.slice(5)) {
        assert.equal(status(card).hidden, true);
        assert.equal(action(card).textContent, 'Uçuş ara');
    }
    // Read-only: one status request per stored search, nothing started.
    assert.equal(requests.filter((request) => request.method === 'POST').length, 0);
    assert.equal(requests.length, 6);
});
