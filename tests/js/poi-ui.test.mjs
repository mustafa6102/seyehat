import test from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, loadScript, settle } from './fake-dom.mjs';

const poi = (businessStatus) => ({
    poi_id: `poi-${businessStatus}`,
    name: 'Sözleşme Test Yeri',
    location: { address: 'Test adresi' },
    opening_hours: { value: null, verification_status: 'UNVERIFIED' },
    admission: { value: {}, verification_status: 'UNVERIFIED' },
    booking: { value: 'UNKNOWN', verification_status: 'UNVERIFIED' },
    visit_duration: { minutes: null, certainty: 'UNKNOWN' },
    business_status: { value: businessStatus, verification_status: 'FRESH' },
});

const page = () => {
    const document = new FakeElement('main');
    const csrf = new FakeElement('meta');
    csrf.setAttribute('name', 'csrf-token');
    csrf.content = 'csrf-token';
    const root = new FakeElement('section');
    Object.assign(root.dataset, {
        poi: '',
        startUrl: '/poi-searches',
        pollUrl: '/poi-searches/__SEARCH__',
        poiUrl: '/pois/__POI__',
        verifyUrl: '/pois/__POI__/verify',
        tripRequestId: 'trip-1',
        planAccess: 'OWNER',
        planAddUrl: '/trip-plan/items/poi',
        planVersion: '3',
        attributionUrl: 'https://www.openstreetmap.org/copyright',
        pollInterval: '0',
    });
    const status = new FakeElement('p'); status.dataset.poiStatus = '';
    const results = new FakeElement('div'); results.dataset.poiResults = '';
    const search = new FakeElement('button'); search.dataset.poiSearch = '';
    const refresh = new FakeElement('button'); refresh.dataset.poiRefresh = '';
    root.append(status, results, search, refresh);
    document.append(csrf, root);

    return { document, results, search };
};

const render = async (businessStatus) => {
    const ui = page();
    loadScript('public/assets/poi.js', {}, (url, options) => {
        if (url === '/poi-searches' && options.method === 'POST') {
            return { status: 202, body: { search_id: 'search-1' } };
        }

        return { status: 200, body: { status: 'COMPLETED', result: { pois: [poi(businessStatus)] } } };
    }, {}, ui.document);
    await ui.search.click();
    await settle();

    return ui.results;
};

test('TS-POI-001-08: sağlayıcının kapalı bildirdiği yer plan öğesi olarak önerilmez', async () => {
    for (const status of ['CLOSED_TEMPORARILY', 'CLOSED_PERMANENTLY']) {
        const results = await render(status);
        assert.match(results.textContent, status === 'CLOSED_TEMPORARILY' ? /Geçici olarak kapalı/ : /Kalıcı olarak kapalı/);
        assert.match(results.textContent, /plan öğesi olarak önerilmiyor/);
        assert.equal(results.byTag('form').length, 0);
        assert.equal(results.byTag('button').some((button) => button.textContent === 'Plana ekle'), false);
    }
});

test('faaliyetteki yer için mevcut sahip plan eylemi korunur', async () => {
    const results = await render('OPERATIONAL');
    const forms = results.byTag('form');
    assert.equal(forms.length, 1);
    assert.equal(forms[0].action, '/trip-plan/items/poi');
    assert.equal(forms[0].byTag('button')[0].textContent, 'Plana ekle');
});
