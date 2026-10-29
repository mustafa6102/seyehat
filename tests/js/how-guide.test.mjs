import test from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, loadScript, settle } from './fake-dom.mjs';

const guide = (stale = false) => ({
    blocks: [{ text_tr: stale ? 'Saat: 09:00–17:00. Güncelliğini yitirmiş; yeniden doğrulayın.' : 'Platform: Bilgi mevcut değil.', evidence_slots: [{
        knowledge_status: stale ? 'KNOWN' : 'UNKNOWN', display_value: stale ? '09:00–17:00' : null,
        source_reference: 'https://source.example/fact', verification_status: stale ? 'STALE' : 'UNVERIFIED', active_cta: false,
    }] }],
    has_stale_critical_evidence: stale,
});

const page = () => {
    const document = new FakeElement('main');
    const card = new FakeElement('article'); card.className = 'offer-card';
    const open = new FakeElement('button'); open.dataset.howGuideOpen = '';
    const root = new FakeElement('section'); Object.assign(root.dataset, { howGuide: '', guideUrl: '/guide', guideRefreshUrl: '/guide/refresh', guideVersion: '4' });
    const close = new FakeElement('button'); close.dataset.howGuideClose = '';
    const status = new FakeElement('p'); status.dataset.howGuideStatus = '';
    const content = new FakeElement('div'); content.dataset.howGuideContent = '';
    root.append(close, status, content); card.append(open, root); document.append(card); root.closest = () => card;

    return { document, open, close, root, status, content };
};

test('TS-HOW-001-01: bilinmeyen kritik bilgi Türkçe gösterilir ve provider yenilemesi yapılmaz', async () => {
    const ui = page();
    const { requests } = loadScript('public/assets/how-guide.js', {}, () => ({ status: 200, body: guide(false) }), {}, ui.document);
    await ui.open.click(); await settle();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].method, 'GET');
    assert.match(ui.content.textContent, /Bilgi mevcut değil/);
    assert.match(ui.status.textContent, /kaynak durumlarıyla birlikte/);
});

test('TS-HOW-001-03: STALE bilgi işaretli korunur ve idempotent refresh çağrılır', async () => {
    const ui = page(); let calls = 0;
    const { requests } = loadScript('public/assets/how-guide.js', {}, () => ++calls === 1
        ? { status: 200, body: guide(true) }
        : { status: 503, body: { error: { message_tr: 'Kaynak yeniden doğrulanamadı; eski bilgi korunuyor.' } } }, {}, ui.document);
    await ui.open.click(); await settle();
    assert.equal(requests.length, 2);
    assert.deepEqual([requests[1].method, requests[1].headers['If-Match'], requests[1].headers['Idempotency-Key']], ['POST', '"4"', '00000000-0000-4000-8000-000000000001']);
    assert.match(ui.content.textContent, /Güncelliğini yitirmiş/);
    assert.equal(ui.status.textContent, 'Kaynak yeniden doğrulanamadı; eski bilgi korunuyor.');
});

test('TS-HOW-001-02: yalnız doğrulanmış HTTPS rezervasyon bağlantısı aktif eyleme dönüşür', async () => {
    const ui = page();
    const verified = {
        blocks: [{ text_tr: 'Rezervasyon bağlantısı doğrulandı.', evidence_slots: [{
            knowledge_status: 'KNOWN', display_value: 'https://tickets.example/rome',
            source_reference: 'https://tickets.example/rome', verification_status: 'FRESH', active_cta: true,
        }] }],
        has_stale_critical_evidence: false,
    };
    loadScript('public/assets/how-guide.js', {}, () => ({ status: 200, body: verified }), {}, ui.document);
    await ui.open.click(); await settle();

    const links = ui.content.byTag('a');
    assert.equal(links.length, 2);
    assert.equal(links[1].textContent, 'Rezervasyon sayfasına git');
    assert.equal(links[1].href, 'https://tickets.example/rome');
});

test('rehber kapatıldığında tetikleyicinin erişilebilir durumu geri verilir', async () => {
    const ui = page();
    loadScript('public/assets/how-guide.js', {}, () => ({ status: 200, body: guide(false) }), {}, ui.document);
    await ui.open.click(); await settle(); await ui.close.click();
    assert.equal(ui.root.hidden, true);
    assert.equal(ui.open.getAttribute('aria-expanded'), 'false');
});
