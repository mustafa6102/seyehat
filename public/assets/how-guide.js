(() => {
    /* HOW-001 v3.19: side-effect-free GET followed by a separate refresh POST only when critical evidence is STALE. */
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const newKey = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`);

    const safeUrl = (value) => {
        try {
            const url = new URL(value);
            return url.protocol === 'https:' ? url.href : null;
        } catch {
            return null;
        }
    };

    const render = (root, guide) => {
        const content = root.querySelector('[data-how-guide-content]');
        content.replaceChildren();
        guide.blocks.forEach((block) => {
            const article = document.createElement('article');
            article.className = 'alert alert-info';
            const text = document.createElement('p');
            text.textContent = block.text_tr;
            article.append(text);
            (block.evidence_slots ?? []).forEach((slot) => {
                if (slot.verification_status !== 'FRESH') {
                    const badge = document.createElement('span');
                    badge.className = slot.verification_status === 'STALE' ? 'badge badge-warning' : 'badge badge-unverified';
                    badge.textContent = slot.knowledge_status === 'UNKNOWN' ? 'Bilgi mevcut değil' : ({
                        UNVERIFIED: 'Doğrulanamadı', STALE: 'Güncelliğini yitirmiş', EXPIRED: 'Süresi doldu',
                    }[slot.verification_status] ?? slot.verification_status);
                    article.append(badge);
                }
                const source = safeUrl(slot.source_reference);
                if (source) {
                    const sourceLink = document.createElement('a');
                    sourceLink.href = source;
                    sourceLink.target = '_blank';
                    sourceLink.rel = 'noopener noreferrer';
                    sourceLink.textContent = ' Kaynağı görüntüle';
                    article.append(sourceLink);
                }
                const cta = slot.active_cta ? safeUrl(slot.display_value) : null;
                if (cta) {
                    const action = document.createElement('a');
                    action.href = cta;
                    action.target = '_blank';
                    action.rel = 'noopener noreferrer';
                    action.className = 'btn btn-secondary';
                    action.textContent = 'Rezervasyon sayfasına git';
                    article.append(action);
                }
            });
            content.append(article);
        });
    };

    document.querySelectorAll('[data-how-guide]').forEach((root) => {
        const card = root.closest('.offer-card');
        const button = card?.querySelector('[data-how-guide-open]');
        const closeButton = root.querySelector('[data-how-guide-close]');
        const status = root.querySelector('[data-how-guide-status]');
        let key = null;

        const request = async (url, method, idempotencyKey = null) => {
            const headers = { Accept: 'application/json', 'If-Match': `"${root.dataset.guideVersion}"` };
            if (method === 'POST') {
                headers['X-CSRF-TOKEN'] = csrf();
                headers['Idempotency-Key'] = idempotencyKey;
            }
            const response = await fetch(url, { method, headers });
            let body = null;
            try { body = await response.json(); } catch { body = null; }
            return { response, body };
        };

        const open = async () => {
            root.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            button.disabled = true;
            status.textContent = 'Rehber yükleniyor…';
            try {
                const initial = await request(root.dataset.guideUrl, 'GET');
                if (!initial.response.ok) {
                    status.textContent = initial.body?.error?.message_tr ?? 'Rehber yüklenemedi.';
                    return;
                }
                render(root, initial.body);
                status.textContent = 'Rehber kaynak durumlarıyla birlikte gösteriliyor.';
                if (!initial.body.has_stale_critical_evidence) {
                    return;
                }
                status.textContent = 'Güncelliğini yitirmiş bilgi yeniden doğrulanıyor…';
                key ??= newKey();
                const refreshed = await request(root.dataset.guideRefreshUrl, 'POST', key);
                key = null;
                if (refreshed.response.ok) {
                    render(root, refreshed.body);
                    status.textContent = 'Kaynak bilgisi yeniden kontrol edildi.';
                    return;
                }
                status.textContent = refreshed.body?.error?.message_tr ?? 'Kaynak bilgisi yeniden doğrulanamadı; eski bilgi işaretli olarak korunuyor.';
            } catch {
                status.textContent = 'Bağlantı kurulamadı. Rehber yeniden yüklenebilir.';
            } finally {
                button.disabled = false;
            }
        };

        button?.addEventListener('click', open);
        closeButton?.addEventListener('click', () => {
            root.hidden = true;
            button?.setAttribute('aria-expanded', 'false');
            button?.focus();
        });
    });
})();
