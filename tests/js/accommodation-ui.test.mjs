import test from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, loadScript, settle } from './fake-dom.mjs';

const offer = (state = 'UNVERIFIED') => ({
    offer_id: 'opaque-offer-1',
    property_id: 'raw-property-secret',
    property_name: 'Roma Merkez Oteli',
    check_in_date: '2026-12-01',
    check_out_date: '2026-12-05',
    room_description: 'Standart oda',
    location: { address: 'Roma, İtalya' },
    total_price: {
        value: null,
        known_lower_bound: { minor_units: 50000, currency: 'EUR' },
        completeness: 'PARTIAL',
        unknown_components: ['MANDATORY_FEES_AT_PROPERTY'],
    },
    cancellation: { policy: 'UNKNOWN', verification_status: 'UNVERIFIED' },
    state,
    expires_at: '2027-01-01T00:00:00Z',
    source: {
        provider_id: 'raw-provider-secret',
        provider_offer_id: 'raw-rate-secret',
        verified_at: state === 'UNVERIFIED' ? null : '2026-10-29T09:00:00Z',
    },
});

const page = () => {
    const document = new FakeElement('main');
    const csrf = new FakeElement('meta');
    csrf.setAttribute('name', 'csrf-token');
    csrf.content = 'csrf-token';
    const root = new FakeElement('section');
    Object.assign(root.dataset, {
        accommodation: '',
        tripRequestId: 'trip-1',
        startUrl: '/accommodation-searches',
        pollUrl: '/accommodation-searches/__SEARCH__',
        revalidateUrl: '/accommodation-offers/__OFFER__/revalidate',
        selectionUrl: '/trip-requests/trip-1/accommodation-selection',
        reconfirmUrl: '/trip-requests/trip-1/accommodation-selection/reconfirm',
        planUrl: '/planlar/trip-1/plan',
        pollInterval: '0',
    });
    const selection = new FakeElement('div'); selection.dataset.accommodationSelection = '';
    const status = new FakeElement('p'); status.dataset.accommodationStatus = '';
    const results = new FakeElement('div'); results.dataset.accommodationResults = '';
    const search = new FakeElement('button'); search.dataset.accommodationSearch = '';
    const refresh = new FakeElement('button'); refresh.dataset.accommodationRefresh = '';
    root.append(selection, status, results, search, refresh);
    document.append(csrf, root);

    return { document, status, results, search };
};

test('TS-ACC-001-04/10: canonical results show the property and UNKNOWN price without raw provider identifiers', async () => {
    const ui = page();
    loadScript('public/assets/accommodation.js', {}, (url, options) => {
        if (url === '/trip-requests/trip-1/accommodation-selection') {
            return { status: 404, body: { error: { message_tr: 'Henüz seçim yok.' } } };
        }
        if (url === '/accommodation-searches' && options.method === 'POST') {
            return { status: 202, body: { search_id: 'search-1', status: 'QUEUED' } };
        }

        return { status: 200, body: { status: 'COMPLETED', result: { offers: [offer()] } } };
    }, {}, ui.document);
    await ui.search.click();
    await settle();

    assert.match(ui.results.textContent, /Roma Merkez Oteli/);
    assert.match(ui.results.textContent, /En az/);
    assert.match(ui.results.textContent, /zorunlu ücretler bilinmiyor/);
    assert.doesNotMatch(ui.results.textContent, /raw-provider-secret|raw-rate-secret|raw-property-secret/);
});

test('TS-ACC-001-07: CHANGED revalidation lists canonical changed fields before explicit acceptance', async () => {
    const ui = page();
    loadScript('public/assets/accommodation.js', {}, (url, options) => {
        if (url === '/trip-requests/trip-1/accommodation-selection') {
            return { status: 404, body: { error: { message_tr: 'Henüz seçim yok.' } } };
        }
        if (url === '/accommodation-searches' && options.method === 'POST') {
            return { status: 202, body: { search_id: 'search-1', status: 'QUEUED' } };
        }
        if (url.includes('/revalidate')) {
            return {
                status: 200,
                body: {
                    current: { offer: offer('CHANGED') },
                    changes: { price_changed: true, mandatory_fees_changed: true, availability_changed: false, cancellation_changed: false },
                },
            };
        }

        return { status: 200, body: { status: 'COMPLETED', result: { offers: [offer()] } } };
    }, {}, ui.document);
    await ui.search.click();
    await settle();
    const detailButton = ui.results.byTag('button').find((button) => button.textContent === 'Ayrıntıları gör ve doğrula');
    assert.ok(detailButton);
    await detailButton.click();
    await settle();

    assert.match(ui.results.textContent, /kritik koşulları değişti/);
    assert.match(ui.results.textContent, /Toplam fiyat değişti/);
    assert.match(ui.results.textContent, /Zorunlu ücretler veya vergiler değişti/);
    assert.ok(ui.results.byTag('button').some((button) => button.textContent.includes('Yeni koşulları kabul et')));
});
