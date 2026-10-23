(() => {
    /*
     * "Gezilecek yer ekle" (POI-001, v3.15): discovery -> canonical results -> detail -> information refresh. Only
     * canonical SCH-Poi data is rendered; the browser sends {trip_request_id} only and never criteria (coordinates,
     * radius, categories, dates). Adding a place to the plan is not available yet (TRIP-BLD-001 / DAY-001).
     */
    const root = document.querySelector('[data-poi]');
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
    const formatDate = (value) => new Date(value).toLocaleString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
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

    // SCH-Sourced evidence: only FRESH evidence is presented without a caveat; nothing is called verified otherwise.
    const fresh = (fact) => fact?.verification_status === 'FRESH';
    const caveat = (fact) => (fresh(fact) ? '' : ' (doğrulanmamış bilgi)');
    const WEEKDAYS = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];

    const describeStatus = (fact) => ({
        OPERATIONAL: `Faaliyette${caveat(fact)}`,
        CLOSED_TEMPORARILY: `Geçici olarak kapalı${caveat(fact)}`,
        CLOSED_PERMANENTLY: `Kalıcı olarak kapalı${caveat(fact)}`,
    }[fact?.value] ?? 'Açık veya kapalı olduğu bilinmiyor');

    // Canonical weekly hours only; no "open now" and no conclusion about days that are not listed.
    const describeHours = (fact) => {
        const weekly = Array.isArray(fact?.value?.weekly) ? fact.value.weekly : [];
        if (weekly.length === 0) {
            return ['Çalışma saatleri bilinmiyor; ziyaret öncesinde yerin kendi kaynağından kontrol edin.'];
        }
        const label = [fact.certainty === 'ESTIMATED' ? 'tahmini' : 'kaynaklı', ...(fresh(fact) ? [] : ['doğrulanmamış bilgi'])].join(', ');

        return [
            `Çalışma saatleri (${label}):`,
            ...weekly.map((slot) => `${WEEKDAYS[slot.iso_weekday - 1] ?? '?'}: ${slot.open}–${slot.close}`),
            'Listede olmayan günler için bilgi yok.',
        ];
    };

    // Unknown admission is never free and never 0; "ücretsiz" only for an explicit fee_required=false.
    const describeAdmission = (fact) => {
        const value = fact?.value ?? {};
        const amount = value.amount ? formatMoney(value.amount) : null;
        if (value.fee_required === true) {
            return `Giriş ücretli${amount ? `: ${amount}` : ' (tutar bilinmiyor)'}${caveat(fact)}`;
        }
        if (value.fee_required === false) {
            return `Giriş ücretsiz${caveat(fact)}`;
        }
        if (amount) {
            return `Giriş ücreti: ${amount}${caveat(fact)}`;
        }

        return 'Giriş ücreti bilinmiyor';
    };

    // A reservation link exists only for FRESH evidence from an approved reservation source (SCH-Poi.booking); an
    // evidence reference such as an OpenStreetMap record or a website is never offered as a reservation link.
    const describeBooking = (fact, list) => {
        const text = {
            REQUIRED: `Rezervasyon gerekli${caveat(fact)}`,
            RECOMMENDED: `Rezervasyon önerilir${caveat(fact)}`,
            NOT_REQUIRED: `Rezervasyon gerekmez${caveat(fact)}`,
        }[fact?.value] ?? 'Rezervasyon gerekip gerekmediği bilinmiyor';
        const item = el('li', 'fact', text);
        if (fresh(fact) && ['REQUIRED', 'RECOMMENDED'].includes(fact.value) && /^https:\/\//.test(fact.source_reference ?? '')) {
            const link = el('a', '', 'Rezervasyon sayfası');
            link.href = fact.source_reference;
            link.rel = 'noopener noreferrer';
            link.target = '_blank';
            item.append(' ', link);
        }
        list.append(item);
    };

    const describeDuration = (duration) => (duration?.certainty === 'ESTIMATED' && duration.minutes
        ? `Tahmini ziyaret süresi: yaklaşık ${duration.minutes} dakika (tahmin)`
        : 'Ziyaret süresi bilinmiyor');

    const attribution = () => {
        const note = el('p', 'summary-meta', '© ');
        const link = el('a', '', 'OpenStreetMap katkıda bulunanlar');
        link.href = root.dataset.attributionUrl;
        link.rel = 'noopener noreferrer';
        link.target = '_blank';
        note.append(link);

        return note;
    };

    const renderPoi = (poi, full = false) => {
        const card = el('article', 'offer-card');
        const header = el('header', 'offer-card-header');
        const heading = el('div');
        const title = el('div', 'offer-heading');
        title.append(el('h3', 'hotel-name', poi.name));
        const evidence = poi.opening_hours ?? poi.admission;
        if (!fresh(evidence)) {
            title.append(el('span', 'badge badge-neutral', 'Doğrulanmamış bilgi'));
        }
        heading.append(title, el('p', 'offer-date', poi.location?.address ?? 'Adres bilgisi yok'));
        header.append(heading);

        const facts = el('ul', 'facts');
        facts.append(el('li', 'fact', describeAdmission(poi.admission)));
        describeBooking(poi.booking, facts);
        facts.append(el('li', 'fact', describeDuration(poi.visit_duration)));
        facts.append(el('li', 'fact', describeStatus(poi.business_status)));
        card.append(header, facts);

        const hours = describeHours(poi.opening_hours);
        if (full) {
            const block = el('ul', 'facts');
            hours.forEach((line) => block.append(el('li', 'fact', line)));
            card.append(block);
            if (evidence?.fetched_at) {
                card.append(el('p', 'summary-meta', `Bilgi kaynağı: OpenStreetMap topluluk verisi. Sağlayıcıdan son alınma: ${formatDate(evidence.fetched_at)}.`));
            }
        } else {
            card.append(el('p', 'summary-meta', hours.length > 1 ? 'Çalışma saati bilgisi var; ayrıntılarda görebilirsiniz.' : hours[0]));
        }

        return card;
    };

    const status = root.querySelector('[data-poi-status]');
    const results = root.querySelector('[data-poi-results]');
    const searchButton = root.querySelector('[data-poi-search]');
    const refreshButton = root.querySelector('[data-poi-refresh]');
    const storageKey = `poi-discovery:${root.dataset.tripRequestId}`;
    const pollInterval = Number(root.dataset.pollInterval) || 0;
    let pollTimer = null;

    const setStatus = (text, tone = '') => {
        status.className = `status-text${tone ? ` status-${tone}` : ''}`;
        status.textContent = text;
    };
    const message = (body, fallback) => body?.error?.message_tr ?? fallback;

    /*
     * OP-verifyPoi: verified=true only means the provider re-read succeeded. osm_overpass facts stay unverified, so the UI
     * speaks of refreshed information, never of verified facts. On failure the stored facts are shown unchanged.
     */
    const refreshInfo = async (poiId, container, button) => {
        button.disabled = true;
        container.replaceChildren(el('p', 'loading', 'Bilgiler sağlayıcıdan yeniden alınıyor…'));
        let response;
        try {
            response = await request(root.dataset.verifyUrl.replace('__POI__', encodeURIComponent(poiId)), { method: 'POST' });
        } catch {
            container.replaceChildren(el('p', 'alert alert-warning', 'Bilgiler şu anda yenilenemedi. Bağlantınızı kontrol edin.'));
            button.disabled = false;

            return;
        }
        if (!response.ok || !response.body?.poi) {
            container.replaceChildren(el('p', 'alert alert-warning', response.status === 404
                ? 'Bu yerin bilgileri artık bulunamıyor. Yeni bir arama yapabilirsiniz.'
                : message(response.body, 'Bilgiler şu anda yenilenemedi.')));
            button.disabled = false;

            return;
        }
        const result = response.body;
        const reasons = Array.isArray(result.reason_codes) ? result.reason_codes : [];
        let notice;
        if (result.verified) {
            notice = el('p', 'alert alert-info', 'Bilgiler sağlayıcıdan yeniden alındı. Bu bilgiler topluluk kaynaklıdır ve doğrulanmış değildir.');
        } else if (reasons.includes('RC-PROVIDER-TIMEOUT')) {
            notice = el('p', 'alert alert-warning', 'Sağlayıcı zamanında yanıt vermedi; güncel bilgiler alınamadı. Aşağıda daha önce alınan bilgiler gösteriliyor.');
        } else {
            notice = el('p', 'alert alert-warning', 'Güncel bilgiler alınamadı. Aşağıda daha önce alınan bilgiler gösteriliyor.');
        }
        container.replaceChildren(notice, renderPoi(result.poi, true), attribution());
        button.disabled = false;
    };

    // OP-getPoi: the stored canonical record; nothing is rebuilt from provider data.
    const showDetail = async (poiId, container) => {
        container.replaceChildren(el('p', 'loading', 'Ayrıntılar yükleniyor…'));
        let response;
        try {
            response = await request(root.dataset.poiUrl.replace('__POI__', encodeURIComponent(poiId)));
        } catch {
            container.replaceChildren(el('p', 'alert alert-warning', 'Ayrıntılar yüklenemedi. Bağlantınızı kontrol edin.'));

            return;
        }
        if (!response.ok || !response.body?.poi_id) {
            container.replaceChildren(el('p', 'alert alert-warning', response.status === 404
                ? 'Bu yerin bilgileri artık bulunamıyor. Yeni bir arama yapabilirsiniz.'
                : message(response.body, 'Ayrıntılar yüklenemedi.')));

            return;
        }
        const detail = el('div', 'stack');
        const info = el('div', 'stack');
        info.append(renderPoi(response.body, true), attribution());
        const refresh = el('button', 'btn btn-secondary', 'Bilgileri yeniden al');
        refresh.type = 'button';
        refresh.addEventListener('click', () => refreshInfo(response.body.poi_id, info, refresh));
        detail.append(info, refresh);
        container.replaceChildren(detail);
    };

    /*
     * v3.16 add-to-plan (unscheduled FLEXIBLE item) for the plan owner: a CSRF form carrying only the POI id, the expected
     * revision (empty before the first one) and a per-action idempotency key. The server loads the stored canonical POI.
     */
    const planAction = (poi) => {
        if (root.dataset.planAccess === 'OWNER' && root.dataset.planAddUrl) {
            const form = el('form');
            form.method = 'post';
            form.action = root.dataset.planAddUrl;
            const fields = {
                _token: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                poi_id: poi.poi_id,
                expected_version: root.dataset.planVersion ?? '',
                idempotency_key: newKey(),
            };
            Object.entries(fields).forEach(([name, value]) => {
                const input = el('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.append(input);
            });
            const button = el('button', 'btn btn-primary', 'Plana ekle');
            button.type = 'submit';
            form.addEventListener('submit', () => {
                button.disabled = true;
                button.textContent = 'Plana ekleniyor…';
            });
            form.append(button);

            return form;
        }
        if (root.dataset.planAccess === 'SIGNED_OUT') {
            const note = el('p', 'summary-meta', 'Plana eklemek için ');
            const link = el('a', '', 'giriş yapın');
            link.href = root.dataset.loginUrl;
            note.append(link, '.');

            return note;
        }

        return el('p', 'summary-meta', 'Bu planı yalnız sahibi düzenleyebilir.');
    };

    const renderResults = (pois) => {
        if (pois.length === 0) {
            results.replaceChildren(el('p', 'empty-note', 'Varış şehrinin çevresinde gösterilecek gezilecek yer bulunamadı.'));

            return;
        }
        const list = el('ol', 'offer-list');
        pois.forEach((poi) => {
            const item = el('li');
            const card = renderPoi(poi);
            const detail = el('div');
            const open = el('button', 'btn btn-secondary', 'Ayrıntıları gör');
            open.type = 'button';
            open.addEventListener('click', () => showDetail(poi.poi_id, detail));
            card.append(open, planAction(poi));
            item.append(card, detail);
            list.append(item);
        });
        results.replaceChildren(list, attribution());
    };

    const showJob = (job) => {
        const labels = { QUEUED: 'Arama sırada bekliyor…', RUNNING: 'Gezilecek yerler aranıyor…', PARTIAL: 'Gezilecek yerler aranıyor…' };
        if (job.status === 'COMPLETED') {
            const pois = Array.isArray(job.result?.pois) ? job.result.pois : [];
            setStatus(pois.length > 0 ? `${pois.length} gezilecek yer bulundu. Bilgiler topluluk kaynaklıdır; ziyaret öncesinde kontrol edin.` : 'Arama tamamlandı.', 'success');
            renderResults(pois);
        } else if (job.status === 'FAILED' || job.status === 'EXPIRED') {
            setStatus(job.error?.message_tr ?? 'Gezilecek yer araması tamamlanamadı.', 'danger');
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

    // OP-startPoiDiscovery: the body is only {trip_request_id}; criteria are derived on the server.
    const start = async () => {
        searchButton.disabled = true;
        results.replaceChildren();
        setStatus('Gezilecek yer araması başlatılıyor…');
        const key = newKey();
        try {
            const response = await request(root.dataset.startUrl, {
                method: 'POST',
                headers: { 'Idempotency-Key': key, 'Content-Type': 'application/json' },
                body: JSON.stringify({ trip_request_id: root.dataset.tripRequestId }),
            });
            if (response.status !== 202 || !response.body?.search_id) {
                setStatus(message(response.body, 'Gezilecek yer araması başlatılamadı.'), 'danger');
                searchButton.disabled = false;

                return;
            }
            storage.write(storageKey, { key, searchId: response.body.search_id });
            await poll(response.body.search_id);
        } catch {
            setStatus('Gezilecek yer araması başlatılamadı. Bağlantınızı kontrol edin.', 'danger');
            searchButton.disabled = false;
        }
    };

    searchButton.addEventListener('click', start);
    refreshButton.addEventListener('click', () => {
        const state = storage.read(storageKey);
        if (state?.searchId) {
            poll(state.searchId);
        }
    });

    const resume = storage.read(storageKey);
    if (resume?.searchId) {
        poll(resume.searchId);
    }
})();
