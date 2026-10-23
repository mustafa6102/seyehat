(() => {
    /*
     * "Konaklama ekle" (ACC-001, v3.14): search -> results -> revalidation -> selection, plus the persisted selection's
     * reconfirmation. Only canonical API data is rendered; the browser never sends criteria, prices or offer data -
     * search sends {trip_request_id}, selection sends {offer_id, expected_verified_at, accept_changed_offer} only.
     */
    const root = document.querySelector('[data-accommodation]');
    if (!root) {
        return;
    }

    const el = (tag, className = '', text = null) => {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== null) {
            node.textContent = text;
        }

        return node;
    };
    const request = async (url, options = {}) => {
        // OD-028: TripRequest-owned calls go to the session web channel (/web-api/v1), whose POST/PUT need the page's CSRF token.
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers ?? {}) } });
        let body = null;
        try {
            body = await response.json();
        } catch {
            body = null;
        }

        return { status: response.status, ok: response.ok, body };
    };
    const newKey = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`);
    const formatMoney = (money) => {
        try {
            const formatter = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: money.currency });

            return formatter.format(money.minor_units / 10 ** formatter.resolvedOptions().maximumFractionDigits);
        } catch {
            return `${money.minor_units} ${money.currency} (alt birim)`;
        }
    };
    const formatDate = (value, withTime = false) => new Date(value).toLocaleString('tr-TR', withTime
        ? { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { day: 'numeric', month: 'long', year: 'numeric' });
    const storage = {
        read: (key) => {
            try {
                return JSON.parse(window.sessionStorage.getItem(key) ?? 'null');
            } catch {
                return null;
            }
        },
        write: (key, value) => {
            try {
                window.sessionStorage.setItem(key, JSON.stringify(value));
            } catch {
                // Unavailable storage only loses resumption after a reload.
            }
        },
    };

    // RULE-ACC-001-08: only a COMPLETE price is a total; PARTIAL shows the known lower bound and that fees are unknown.
    const describePrice = (price) => {
        if (price?.completeness === 'COMPLETE' && price.value) {
            return { headline: formatMoney(price.value), note: 'Toplam fiyat (vergi ve zorunlu ücretler dahil)' };
        }
        if (price?.known_lower_bound) {
            return { headline: `En az ${formatMoney(price.known_lower_bound)}`, note: 'Tesiste ödenecek zorunlu ücretler bilinmiyor; kesin toplam değildir.' };
        }

        return { headline: 'Fiyat bilinmiyor', note: 'Toplam fiyat doğrulanamadı.' };
    };
    const describeCancellation = (cancellation) => {
        const text = {
            REFUNDABLE: cancellation?.free_cancellation_until
                ? `Ücretsiz iptal: ${formatDate(cancellation.free_cancellation_until, true)} öncesine kadar`
                : 'İade edilebilir',
            PARTIALLY_REFUNDABLE: 'Kısmen iade edilebilir',
            NON_REFUNDABLE: 'İade edilemez',
        }[cancellation?.policy] ?? 'İptal koşulu bilinmiyor';

        return cancellation?.verification_status === 'VERIFIED' || cancellation?.policy === 'UNKNOWN' || !cancellation ? text : `${text} (doğrulanmadı)`;
    };
    const STATES = {
        UNVERIFIED: ['badge-neutral', 'Doğrulanmadı'],
        CURRENT: ['badge-success', 'Doğrulandı'],
        CHANGED: ['badge-warning', 'Koşullar değişti'],
        EXPIRED: ['badge-error', 'Süresi doldu'],
    };

    const renderOffer = (offer) => {
        const card = el('article', 'offer-card');
        const header = el('header', 'offer-card-header');
        const heading = el('div');
        const title = el('div', 'offer-heading');
        title.append(el('h3', 'hotel-name', offer.property_name));
        const [tone, label] = STATES[offer.state] ?? STATES.UNVERIFIED;
        title.append(el('span', `badge ${tone}`, label));
        heading.append(title, el('p', 'offer-date', `${formatDate(offer.check_in_date)} – ${formatDate(offer.check_out_date)}`));
        const price = describePrice(offer.total_price);
        const priceBox = el('div', `price price-plain${offer.total_price?.completeness === 'COMPLETE' ? '' : ' price-partial'}`);
        priceBox.append(el('p', 'price-headline', price.headline), el('p', 'price-note', price.note));
        header.append(heading, priceBox);

        const facts = el('ul', 'facts');
        if (offer.room_description) {
            facts.append(el('li', 'fact', offer.room_description));
        }
        if (offer.location?.address) {
            facts.append(el('li', 'fact', offer.location.address));
        }
        facts.append(el('li', 'fact', describeCancellation(offer.cancellation)));
        if (offer.check_in?.from_time) {
            // RULE-ACC-001-02: check-in content is never presented as confirmed.
            facts.append(el('li', 'fact', `Giriş ${offer.check_in.from_time} sonrası (doğrulanmadı)`));
        }
        card.append(header, facts);

        return card;
    };

    const status = root.querySelector('[data-accommodation-status]');
    const selectionBox = root.querySelector('[data-accommodation-selection]');
    const results = root.querySelector('[data-accommodation-results]');
    const searchButton = root.querySelector('[data-accommodation-search]');
    const refreshButton = root.querySelector('[data-accommodation-refresh]');
    const storageKey = `accommodation-search:${root.dataset.tripRequestId}`;
    const pollInterval = Number(root.dataset.pollInterval) || 0;
    let pollTimer = null;

    const setStatus = (text, tone = '') => {
        status.className = `status-text${tone ? ` status-${tone}` : ''}`;
        status.textContent = text;
    };
    const planLink = (text = 'Seyahat Planım’ı güncelle') => {
        const link = el('a', 'btn btn-secondary', text);
        link.href = root.dataset.planUrl;

        return link;
    };
    const message = (body, fallback) => body?.error?.message_tr ?? fallback;

    // OP-selectTripRequestAccommodation / OP-reconfirmTripRequestAccommodation: success only on 200 + CURRENT.
    const commit = (container, label, url, method, payload) => {
        const button = el('button', 'btn btn-primary', label);
        button.type = 'button';
        const key = newKey();
        const feedback = el('p', 'status-text');
        feedback.setAttribute('role', 'status');
        button.addEventListener('click', async () => {
            button.disabled = true;
            feedback.textContent = 'Seçiminiz kaydediliyor…';
            try {
                const response = await request(url, {
                    method,
                    headers: { 'Idempotency-Key': key, 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                if (response.ok && response.body?.status === 'CURRENT') {
                    container.replaceChildren(el('p', 'alert alert-success', 'Konaklama seyahat planınıza kaydedildi. Rezervasyon yapılmadı.'), planLink());

                    return;
                }
                feedback.textContent = message(response.body, 'Seçim kaydedilemedi. Lütfen yeniden deneyin.');
                button.disabled = response.status === 409;
            } catch {
                feedback.textContent = 'Seçim kaydedilemedi. Bağlantınızı kontrol edip yeniden deneyin.';
                button.disabled = false;
            }
        });
        container.append(button, feedback);
    };

    /*
     * OP-revalidateAccommodationOffer before any selection. CURRENT -> select; CHANGED -> explicit acceptance;
     * UNVERIFIED/EXPIRED -> not selectable. $reconfirm switches the action to the selection's reconfirmation.
     */
    const revalidate = async (offerId, container, reconfirm = false) => {
        container.replaceChildren(el('p', 'loading', 'Teklif sağlayıcıdan yeniden doğrulanıyor…'));
        let response;
        try {
            response = await request(root.dataset.revalidateUrl.replace('__OFFER__', encodeURIComponent(offerId)), { method: 'POST' });
        } catch {
            container.replaceChildren(el('p', 'alert alert-warning', 'Yeniden doğrulama şu anda yapılamadı. Bağlantınızı kontrol edin.'));

            return;
        }
        if (!response.ok || !response.body?.current?.offer) {
            container.replaceChildren(el('p', 'alert alert-warning', message(response.body, 'Yeniden doğrulama şu anda yapılamadı.')));

            return;
        }
        const offer = response.body.current.offer;
        container.replaceChildren(renderOffer(offer));
        const actions = el('div', 'stack');
        if (offer.state === 'CURRENT' || offer.state === 'CHANGED') {
            if (offer.state === 'CHANGED') {
                actions.append(el('p', 'alert alert-warning', 'Teklifin fiyatı, zorunlu ücretleri, uygunluğu veya iptal koşulu değişti. Yukarıdaki güncel bilgileri inceleyin.'));
            }
            const expected = offer.source?.verified_at;
            if (reconfirm) {
                commit(actions, 'Yeni koşulları onayla', root.dataset.reconfirmUrl, 'POST', { expected_verified_at: expected });
            } else {
                commit(actions, offer.state === 'CHANGED' ? 'Yeni koşulları kabul et ve bu konaklamayı seç' : 'Bu konaklamayı seç', root.dataset.selectionUrl, 'PUT', {
                    offer_id: offer.offer_id,
                    expected_verified_at: expected,
                    accept_changed_offer: offer.state === 'CHANGED',
                });
            }
        } else if (offer.state === 'EXPIRED') {
            actions.append(el('p', 'alert alert-danger', 'Bu konaklama teklifinin süresi doldu; seçilemez. Yeni bir arama yapın.'));
        } else {
            actions.append(el('p', 'alert alert-warning', 'Teklif sağlayıcıdan doğrulanamadı; doğrulanmamış teklif seçilemez. Daha sonra yeniden deneyin.'));
        }
        container.append(actions);
    };

    const renderResults = (offers) => {
        if (offers.length === 0) {
            results.replaceChildren(el('p', 'empty-note', 'Bu kriterlere uygun konaklama bulunamadı.'));

            return;
        }
        const list = el('ol', 'offer-list');
        offers.forEach((offer) => {
            const item = el('li');
            const card = renderOffer(offer);
            const detail = el('div');
            if (offer.state !== 'EXPIRED') {
                const verify = el('button', 'btn btn-secondary', 'Ayrıntıları gör ve doğrula');
                verify.type = 'button';
                verify.addEventListener('click', () => revalidate(offer.offer_id, detail));
                card.append(verify);
            }
            item.append(card, detail);
            list.append(item);
        });
        results.replaceChildren(list);
    };

    const showJob = (job) => {
        const labels = { QUEUED: 'Arama sırada bekliyor…', RUNNING: 'Konaklamalar aranıyor…', PARTIAL: 'Konaklamalar aranıyor…' };
        if (job.status === 'COMPLETED') {
            const offers = Array.isArray(job.result?.offers) ? job.result.offers : [];
            setStatus(offers.length > 0 ? `${offers.length} konaklama teklifi bulundu. Seçmeden önce teklifi doğrulayın.` : 'Arama tamamlandı.', 'success');
            renderResults(offers);
        } else if (job.status === 'FAILED' || job.status === 'EXPIRED') {
            setStatus(job.error?.message_tr ?? 'Konaklama araması tamamlanamadı.', 'danger');
            results.replaceChildren();
        } else {
            setStatus(labels[job.status] ?? 'Arama sürüyor…');
        }
        const settled = ['COMPLETED', 'FAILED', 'EXPIRED'].includes(job.status);
        refreshButton.hidden = settled || pollInterval > 0;
        searchButton.disabled = !settled;

        return settled;
    };

    const poll = async (searchId) => {
        clearTimeout(pollTimer);
        try {
            const response = await request(root.dataset.pollUrl.replace('__SEARCH__', encodeURIComponent(searchId)));
            if (!response.ok || !response.body) {
                setStatus(message(response.body, 'Arama durumu alınamadı.'), 'danger');
                searchButton.disabled = false;

                return;
            }
            if (!showJob(response.body) && pollInterval > 0) {
                pollTimer = setTimeout(() => poll(searchId), pollInterval);
            }
        } catch {
            setStatus('Arama durumu alınamadı. Bağlantınızı kontrol edin.', 'danger');
            searchButton.disabled = false;
        }
    };

    // OP-startAccommodationSearch: the body is only {trip_request_id}; criteria are derived on the server.
    const start = async () => {
        searchButton.disabled = true;
        results.replaceChildren();
        setStatus('Konaklama araması başlatılıyor…');
        const key = newKey();
        try {
            const response = await request(root.dataset.startUrl, {
                method: 'POST',
                headers: { 'Idempotency-Key': key, 'Content-Type': 'application/json' },
                body: JSON.stringify({ trip_request_id: root.dataset.tripRequestId }),
            });
            if (response.status !== 202 || !response.body?.search_id) {
                setStatus(message(response.body, 'Konaklama araması başlatılamadı.'), 'danger');
                searchButton.disabled = false;

                return;
            }
            storage.write(storageKey, { key, searchId: response.body.search_id });
            await poll(response.body.search_id);
        } catch {
            setStatus('Konaklama araması başlatılamadı. Bağlantınızı kontrol edin.', 'danger');
            searchButton.disabled = false;
        }
    };

    // OP-getTripRequestAccommodationSelection: the persisted selection is authoritative, not browser state.
    const showSelection = async () => {
        let response;
        try {
            response = await request(root.dataset.selectionUrl);
        } catch {
            return;
        }
        if (!response.ok || !response.body?.offer) {
            selectionBox.hidden = true;

            return;
        }
        const selection = response.body;
        const content = el('div', 'stack');
        if (selection.status === 'CURRENT') {
            content.append(el('p', 'alert alert-success', 'Seçili konaklamanız. Başka bir konaklama seçerseniz bu seçimin yerine geçer.'), renderOffer(selection.offer));
        } else if (selection.status === 'RECONFIRMATION_REQUIRED') {
            // The stored price is not shown as verified; the latest server-side evidence is fetched by revalidation.
            content.append(el('p', 'alert alert-warning', `${selection.offer.property_name}: fiyat veya koşullar değişti. Seçiminizin geçerli kalması için güncel koşulları inceleyip onaylayın.`));
            const detail = el('div');
            const review = el('button', 'btn btn-primary', 'Güncel koşulları gör');
            review.type = 'button';
            review.addEventListener('click', () => revalidate(selection.offer.offer_id, detail, true));
            content.append(review, detail);
        } else {
            content.append(el('p', 'alert alert-danger', `${selection.offer.property_name}: seçilen konaklama teklifinin süresi doldu. Yeni bir arama yaparak başka bir konaklama seçebilirsiniz.`));
        }
        selectionBox.replaceChildren(content);
        selectionBox.hidden = false;
    };

    searchButton.addEventListener('click', start);
    refreshButton.addEventListener('click', () => {
        const state = storage.read(storageKey);
        if (state?.searchId) {
            poll(state.searchId);
        }
    });

    showSelection();
    const resume = storage.read(storageKey);
    if (resume?.searchId) {
        poll(resume.searchId);
    }
})();
