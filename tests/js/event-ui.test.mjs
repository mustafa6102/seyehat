import test from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, loadScript, settle } from './fake-dom.mjs';

const event = (status, ticketStatus) => ({
    event_id: 'evt-internal-not-visible',
    name: 'Roma Caz Gecesi',
    venue: { name: 'Auditorium', city: 'Roma', country: 'İtalya' },
    status: fact(status),
    ticket_status: ticketStatus ? fact(ticketStatus) : null,
    starts_at: fact('2099-12-15T19:00:00Z'),
    iana_timezone: 'Europe/Rome',
    ticket_link: status === 'SCHEDULED' && ticketStatus === 'AVAILABLE' ? fact('https://www.ticketmaster.com/event/1') : null,
    price_ranges: null,
});

function fact(value) {
    return { value, verification_status: 'FRESH', expires_at: '2099-12-16T00:00:00Z' };
}

async function render(item) {
    const document = new FakeElement('main');
    const root = new FakeElement('section');
    Object.assign(root.dataset, { event: '', tripRequestId: 'trip-1', startUrl: '/events', pollUrl: '/events/__SEARCH__', pollInterval: '0', planAccess: 'OWNER', planAddUrl: '/plan/events', planVersion: '2' });
    const status = new FakeElement('p'); status.dataset.eventStatus = '';
    const results = new FakeElement('div'); results.dataset.eventResults = '';
    const search = new FakeElement('button'); search.dataset.eventSearch = '';
    const refresh = new FakeElement('button'); refresh.dataset.eventRefresh = '';
    root.append(status, results, search, refresh);
    document.append(root);
    const runtime = loadScript('public/assets/event.js', {}, (url, options) => options && options.method === 'POST'
        ? { status: 202, body: { search_id: 'search-1', status: 'QUEUED' } }
        : { status: 200, body: { status: 'COMPLETED', result: { events: [item] } } }, {}, document);
    await search.click();
    await settle();
    assert.ok(runtime.requests.length >= 2, 'Etkinlik araması ve durum sorgusu gönderilmelidir.');
    return results;
}

test('TS-EVT-001-01: iptal edilmiş etkinlik bilet veya plan eylemi göstermez', async () => {
    const results = await render(event('CANCELLED', null));
    assert.match(results.textContent, /Etkinlik iptal edildi/);
    assert.match(results.textContent, /Fiyat bilinmiyor/);
    assert.equal(results.byTag('form').length, 0);
    assert.doesNotMatch(results.textContent, /evt-internal-not-visible/);
});

test('TS-EVT-001-02: doğrulanmış sabit etkinlik plana eklenebilir', async () => {
    const results = await render(event('SCHEDULED', 'AVAILABLE'));
    assert.match(results.textContent, /Roma Caz Gecesi/);
    assert.match(results.textContent, /Bilet bilgisi/);
    assert.equal(results.byTag('form').length, 1);
    assert.equal(results.byTag('button').some(button => button.textContent === 'Plana ekle'), true);
});

test('TS-EVT-001-03: uygunluğu bilinmeyen etkinlik aktif öneri olmaz', async () => {
    const results = await render(event('SCHEDULED', null));
    assert.match(results.textContent, /Bilet uygunluğu ya da etkinlik zamanı doğrulanamadı/);
    assert.equal(results.byTag('form').length, 0);
});
