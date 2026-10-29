// Day plan behaviour of public/assets/plan-days.js (DAY-001 v3.18). Markup mirrors resources/views/trip-plan.blade.php and
// resources/views/partials/plan-item.blade.php; requests go to the /web-api/v1 session channel (OD-028).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { FakeElement, loadScript, settle } from './fake-dom.mjs';

const URL_A = '/web-api/v1/trip-requests/7/plan/items/item-a/day-assignment';
const URL_B = '/web-api/v1/trip-requests/7/plan/items/item-b/day-assignment';

const node = (tag, props = {}, children = []) => {
    const { dataset = {}, attrs = {}, ...rest } = props;
    const element = Object.assign(new FakeElement(tag), rest);
    element.dataset = { ...dataset };
    Object.entries(attrs).forEach(([name, value]) => element.setAttribute(name, value));
    element.append(...children);

    return element;
};

/** Item A: day 2026-12-01 position 1 of 2 ("up" disabled); item B: unassigned. */
const page = () => {
    const status = node('p', { dataset: { planDaysStatus: '' } });
    const reload = node('button', { hidden: true, dataset: { planDaysReload: '' } });
    const selectA = node('select', { value: '2026-12-01', dataset: { daySelect: '' } });
    const applyA = node('button', { dataset: { dayApply: '' } });
    const up = node('button', { disabled: true, dataset: { dayMove: 'up' } });
    const down = node('button', { dataset: { dayMove: 'down' } });
    const selectB = node('select', { value: '', dataset: { daySelect: '' } });
    const applyB = node('button', { dataset: { dayApply: '' } });
    const root = node('div', { dataset: { planDays: '', planVersion: '4' } }, [
        status,
        reload,
        node('div', { dataset: { dayAssignment: '', url: URL_A, date: '2026-12-01', position: '1' } }, [selectA, applyA, up, down]),
        node('div', { dataset: { dayAssignment: '', url: URL_B, date: '', position: '' } }, [selectB, applyB]),
    ]);
    const tree = node('div', {}, [node('meta', { content: 'csrf-123', attrs: { name: 'csrf-token' } }), root]);

    return { tree, status, reload, selectA, applyA, up, down, selectB, applyB };
};

const load = (route) => {
    const dom = page();
    let reloads = 0;
    let keys = 0;
    const { requests } = loadScript('public/assets/plan-days.js', {}, route, {}, dom.tree, {
        location: { reload: () => { reloads += 1; } },
        crypto: { randomUUID: () => `key-${++keys}` },
    });

    return { ...dom, requests, reloads: () => reloads };
};

test('assigning an unassigned place PUTs only {date} with If-Match, Idempotency-Key and CSRF, then reloads the plan', async () => {
    const ui = load(() => ({ status: 200, body: { revision: { version: 5 } } }));
    ui.selectB.value = '2026-12-02';

    await ui.applyB.click();
    await settle();

    assert.equal(ui.requests.length, 1);
    const [request] = ui.requests;
    assert.equal(request.url, URL_B);
    assert.equal(request.method, 'PUT');
    assert.equal(request.headers['If-Match'], '"4"');
    assert.equal(request.headers['Idempotency-Key'], 'key-1');
    assert.equal(request.headers['X-CSRF-TOKEN'], 'csrf-123');
    assert.deepEqual(JSON.parse(request.body), { date: '2026-12-02' });
    assert.equal(ui.status.textContent, 'Plan güncellendi. Sayfa yenileniyor…');
    assert.equal(ui.reloads(), 1);
});

test('choosing "Güne atanmamış" sends date null and moving sends the same day with position ±1; no time, duration or feasibility', async () => {
    const ui = load(() => ({ status: 200, body: {} }));
    ui.selectA.value = '';
    await ui.applyA.click();
    await ui.down.click();
    await settle();

    assert.deepEqual(ui.requests.map((request) => JSON.parse(request.body)), [{ date: null }, { date: '2026-12-01', position: 2 }]);
    ui.requests.forEach((request) => {
        assert.deepEqual(Object.keys(JSON.parse(request.body)).filter((key) => !['date', 'position'].includes(key)), []);
    });
});

test('an unchanged day sends nothing', async () => {
    const ui = load(() => ({ status: 200, body: {} }));
    await ui.applyA.click();
    await ui.applyB.click();
    await settle();

    assert.equal(ui.requests.length, 0);
    assert.equal(ui.status.textContent, 'Bu yer zaten bir güne atanmamış.');
});

test('a stale revision keeps every control disabled and offers a reload', async () => {
    const ui = load(() => ({ status: 409, body: { error: { code: 'REVISION_STALE', message_tr: 'Plan sürümü güncel değil.' } } }));
    ui.selectB.value = '2026-12-01';
    await ui.applyB.click();
    await settle();

    assert.equal(ui.status.textContent, 'Plan bu sırada değişti. Güncel planı görmek için sayfayı yenileyin.');
    assert.equal(ui.reload.hidden, false);
    assert.ok([ui.selectA, ui.applyA, ui.up, ui.down, ui.selectB, ui.applyB].every((control) => control.disabled));
    assert.equal(ui.reloads(), 0);
    await ui.reload.click();
    assert.equal(ui.reloads(), 1);
});

test('authorization, CSRF and day validation failures show Turkish messages', async () => {
    const cases = [
        [401, { error: { code: 'AUTHENTICATION_REQUIRED' } }, 'Oturumunuz sona ermiş. Yeniden giriş yapıp sayfayı yenileyin.'],
        [403, { error: { code: 'AUTHORIZATION_DENIED' } }, 'Bu planı yalnız sahibi düzenleyebilir.'],
        [419, undefined, 'Sayfanın güvenlik süresi doldu. Sayfayı yenileyip yeniden deneyin.'],
        [422, { error: { code: 'DAY_ASSIGNMENT_INVALID', message_tr: 'Seçilen gün seyahat tarihleri içinde değil veya sıra geçersiz.' } }, 'Seçilen gün seyahat tarihleri içinde değil veya sıra geçersiz.'],
        [409, { error: { code: 'IDEMPOTENCY_KEY_CONFLICT' } }, 'Plan bu sırada değişti. Güncel planı görmek için sayfayı yenileyin.'],
    ];
    for (const [status, body, message] of cases) {
        const ui = load(() => ({ status, body }));
        await ui.down.click();
        await settle();
        assert.equal(ui.status.textContent, message, String(status));
        assert.equal(ui.reload.hidden, false, String(status));
    }
});

test('a network failure re-enables the rendered controls and a retry of the same change reuses its Idempotency-Key', async () => {
    let attempts = 0;
    const ui = load(() => {
        attempts += 1;
        if (attempts === 1) {
            throw new TypeError('offline');
        }

        return { status: 200, body: {} };
    });

    await ui.down.click();
    await settle();
    assert.equal(ui.status.textContent, 'Bağlantı kurulamadı. Bağlantınızı kontrol edip yeniden deneyin.');
    assert.equal(ui.down.disabled, false);
    assert.equal(ui.up.disabled, true, 'the first item still cannot move up');

    await ui.down.click();
    await settle();
    assert.deepEqual(ui.requests.map((request) => request.headers['Idempotency-Key']), ['key-1', 'key-1']);
    assert.equal(ui.reloads(), 1);
});
