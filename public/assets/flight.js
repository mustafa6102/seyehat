(() => {
    const STATE_LABELS = { CURRENT: 'Güncel', CHANGED: 'Değişti', EXPIRED: 'Süresi doldu', UNVERIFIED: 'Doğrulanmadı' };
    const VERIFICATION_NOTES = { STALE: 'bilgi güncel olmayabilir', EXPIRED: 'doğrulama süresi dolmuş', UNVERIFIED: 'doğrulanmadı' };
    const REASON_MESSAGES = {
        'RC-EVIDENCE-OFFER-CHANGED': 'Teklifin fiyatı veya kritik koşulları değişti.',
        'RC-EVIDENCE-OFFER-EXPIRED': 'Teklifin geçerlilik süresi doldu.',
        'RC-PROVIDER-TIMEOUT': 'Sağlayıcı yanıtı zamanında alınamadı.',
        'RC-EVIDENCE-REPRICE-UNVERIFIED': 'Teklif yeniden doğrulanamadı; doğrulanmamış olarak gösteriliyor.',
    };
    const ALLOWANCES = [
        ['personal_item', 'Kişisel eşya'],
        ['cabin_baggage', 'Kabin bagajı'],
        ['checked_baggage', 'Kayıtlı bagaj'],
    ];
    const TERMINAL = ['COMPLETED', 'FAILED', 'EXPIRED'];
    const CARRIER_MODELS = { FULL_SERVICE: 'Tam hizmet', LCC: 'Düşük maliyetli', HYBRID: 'Hibrit', UNKNOWN: 'Taşıyıcı modeli bilinmiyor' };
    const PROVIDER_ROLES = { AGGREGATOR: 'Toplayıcı', GDS: 'GDS', DIRECT_SUPPLIER: 'Doğrudan tedarikçi', AFFILIATE: 'Satış ortağı', UNKNOWN: 'bilinmiyor' };
    const CHANNELS = { NDC: 'NDC', GDS_EDIFACT: 'GDS (EDIFACT)', DIRECT_API: 'Doğrudan API', OTHER: 'Diğer', UNKNOWN: 'bilinmiyor' };

    const el = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    };

    const formatMoney = (money) => {
        try {
            const formatter = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: money.currency });
            const digits = formatter.resolvedOptions().maximumFractionDigits;

            return formatter.format(money.minor_units / 10 ** digits);
        } catch {
            return `${money.minor_units} ${money.currency} (alt birim)`;
        }
    };

    const formatDate = (iso) => {
        const date = new Date(iso);

        return Number.isNaN(date.getTime())
            ? iso
            : new Intl.DateTimeFormat('tr-TR', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    };

    // Canonical local time (Section 6.4): the local wall-clock time as provided, never converted in the browser.
    // The IANA zone identifier is technical and is not displayed.
    const splitLocal = (time) => {
        const [date = '', clock = ''] = String(time?.local_datetime ?? '').split('T');

        return { date, clock: clock.slice(0, 5) };
    };

    const formatDay = (date) => {
        const day = new Date(`${date}T00:00:00Z`);

        return Number.isNaN(day.getTime())
            ? date
            : new Intl.DateTimeFormat('tr-TR', { dateStyle: 'medium', timeZone: 'UTC' }).format(day);
    };

    const formatLocal = (time) => {
        const { date, clock } = splitLocal(time);

        return `${formatDay(date)} ${clock} (yerel saat)`;
    };

    const formatDuration = (minutes) => {
        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;
        if (hours === 0) {
            return `${rest} dk`;
        }

        return rest === 0 ? `${hours} sa` : `${hours} sa ${rest} dk`;
    };

    // Card summary derived only from the canonical segment list (one ordered one-way itinerary per offer).
    // Segments carry no airport codes, so no route endpoints are shown or guessed.
    const describeItinerary = (segments) => {
        const list = Array.isArray(segments) ? segments : [];
        if (list.length === 0) {
            return null;
        }
        const first = list[0];
        const last = list[list.length - 1];
        const departure = splitLocal(first.departure);
        const arrival = splitLocal(last.arrival);
        const minutes = list.length === 1
            ? first.duration_minutes
            : Math.round((Date.parse(last.arrival?.utc_instant) - Date.parse(first.departure?.utc_instant)) / 60000);
        const dayShift = Math.round((Date.parse(`${arrival.date}T00:00:00Z`) - Date.parse(`${departure.date}T00:00:00Z`)) / 86400000);

        return {
            flights: list.map((segment) => `${segment.operating_carrier} ${segment.operating_flight_number}`),
            departure: departure.clock,
            arrival: arrival.clock,
            date: formatDay(departure.date),
            dayShift: Number.isFinite(dayShift) && dayShift > 0 ? dayShift : 0,
            duration: Number.isFinite(minutes) && minutes > 0 ? formatDuration(minutes) : 'Süre bilinmiyor',
            stops: list.length === 1 ? 'Direkt' : `${list.length - 1} aktarma`,
        };
    };

    // DerivedMoney: COMPLETE shows its value, PARTIAL only a lower bound, anything else is unknown (never 0).
    const describePrice = (price) => {
        if (price?.completeness === 'COMPLETE' && price.value && price.certainty !== 'UNKNOWN') {
            return {
                tone: 'complete',
                headline: formatMoney(price.value),
                note: price.certainty === 'ESTIMATED' ? 'Tahmini toplam fiyat' : 'Toplam fiyat',
                components: [],
            };
        }
        if (price?.completeness === 'PARTIAL' && price.known_lower_bound) {
            return {
                tone: 'partial',
                headline: `En az ${formatMoney(price.known_lower_bound)}`,
                note: 'Fiyatın bir kısmı henüz dahil değil; bu tutar kesin toplam fiyat değildir.',
                components: price.unknown_components ?? [],
            };
        }

        return { tone: 'unknown', headline: 'Toplam fiyat doğrulanamadı', note: 'Kesin bir toplam fiyat gösterilemiyor.', components: [] };
    };

    const describeAllowance = (item) => {
        const known = item && item.certainty !== 'UNKNOWN' && typeof item.value === 'boolean';
        const notes = [];
        if (known && item.certainty === 'ESTIMATED') {
            notes.push('tahmini');
        }
        if (item && VERIFICATION_NOTES[item.verification_status]) {
            notes.push(VERIFICATION_NOTES[item.verification_status]);
        }

        return { text: known ? (item.value ? 'Dahil' : 'Dahil değil') : 'Doğrulanamadı', known, included: known && item.value, notes };
    };

    // Compact card lines: an unknown allowance stays explicitly unknown, never "included", free or zero.
    const describeBaggageSummary = (baggage) => {
        if (!baggage || ALLOWANCES.every(([key]) => !describeAllowance(baggage[key]).known)) {
            return ['Bagaj bilgisi bilinmiyor'];
        }

        return [['cabin_baggage', 'Kabin bagajı'], ['checked_baggage', 'Kayıtlı bagaj']].map(([key, label]) => {
            const allowance = describeAllowance(baggage[key]);
            const text = allowance.known
                ? `${label} ${allowance.included ? 'dahil' : 'dahil değil'}`
                : `${label} bilgisi bilinmiyor`;

            return allowance.notes.length > 0 ? `${text} (${allowance.notes.join(', ')})` : text;
        });
    };

    const renderPrice = (price) => {
        const description = describePrice(price);
        const box = el('div', `price price-${description.tone}`);
        box.append(el('p', 'price-headline', description.headline), el('p', 'price-note', description.note));

        return box;
    };

    const renderBaggage = (baggage) => {
        const section = el('div', 'baggage');
        section.append(el('h4', 'baggage-title', 'Bagaj'));
        if (!baggage) {
            section.append(el('p', 'summary-meta', 'Bagaj bilgisi doğrulanamadı.'));

            return section;
        }

        const list = el('dl', 'baggage-list');
        ALLOWANCES.forEach(([key, label]) => {
            const allowance = describeAllowance(baggage[key]);
            const row = el('div', allowance.known ? '' : 'is-unknown');
            const value = el('dd', '', allowance.text);
            if (allowance.notes.length > 0) {
                value.append(el('span', 'summary-meta', ` (${allowance.notes.join(', ')})`));
            }
            row.append(el('dt', '', label), value);
            list.append(row);
        });
        section.append(list);

        // AncillaryFee (FLT-005): the component name is provider data; an unknown amount is never shown as 0 and
        // fees are never summed in the browser.
        const feeList = (fees) => {
            const list = el('ul', 'fee-list');
            fees.forEach((fee) => {
                const amount = fee.certainty === 'UNKNOWN' || !fee.value
                    ? 'Tutar bilinmiyor'
                    : `${formatMoney(fee.value)}${fee.certainty === 'ESTIMATED' ? ' (tahmini)' : ''}`;
                const note = VERIFICATION_NOTES[fee.verification_status];
                list.append(el('li', '', `${fee.component}: ${amount}${note ? ` (${note})` : ''}`));
            });

            return list;
        };
        const allFees = baggage.fees ?? [];
        const mandatory = allFees.filter((fee) => fee.mandatory === true);
        const optional = allFees.filter((fee) => fee.mandatory === false);
        section.append(el('h4', 'baggage-title', 'Zorunlu ek ücretler'));
        section.append(mandatory.length === 0 ? el('p', 'summary-meta', 'Zorunlu ek ücret bildirilmedi.') : feeList(mandatory));
        if (optional.length > 0) {
            section.append(el('h4', 'baggage-title', 'İsteğe bağlı ek ücretler'), feeList(optional));
        }

        return section;
    };

    const renderSegments = (segments) => {
        const section = el('div', 'segments');
        section.append(el('h4', 'baggage-title', 'Uçuş bölümleri'));
        if (!Array.isArray(segments) || segments.length === 0) {
            section.append(el('p', 'summary-meta', 'Uçuş bölümü bilgisi yok.'));

            return section;
        }
        const list = el('ol', 'segment-list');
        segments.forEach((segment) => {
            const item = el('li', 'segment');
            const model = segment.carrier_model === 'UNKNOWN' ? null : CARRIER_MODELS[segment.carrier_model];
            item.append(
                el('p', 'segment-flight', `${segment.operating_carrier} ${segment.operating_flight_number}`),
                el('p', '', `Kalkış: ${formatLocal(segment.departure)}`),
                el('p', '', `Varış: ${formatLocal(segment.arrival)}`),
                el('p', 'summary-meta', `Süre: ${formatDuration(segment.duration_minutes)}${model ? ` · ${model}` : ''}`),
            );
            if ((segment.marketing_flight_references ?? []).length > 0) {
                item.append(el('p', 'summary-meta', `Ortak uçuş (codeshare) numaraları: ${segment.marketing_flight_references.join(', ')}`));
            }
            list.append(item);
        });
        section.append(list);

        return section;
    };

    const renderSources = (offer) => {
        const section = el('div', 'sources');
        section.append(el('p', 'summary-meta', offer.bookable
            ? 'Satın alınabilir kaynak mevcut; yönlendirme adımı henüz kullanıma açılmadı.'
            : 'Yalnız arama sonucu; satın alınabilir olarak işaretlenmez.'));
        (offer.sources ?? []).forEach((source) => {
            section.append(el('p', 'summary-meta', `Kaynak: ${source.provider_id} · Rol: ${PROVIDER_ROLES[source.provider_role] ?? source.provider_role} · Kanal: ${CHANNELS[source.distribution_channel] ?? source.distribution_channel}`));
        });

        return section;
    };

    // Decision summary first (flights, price, times, duration, stops, date, baggage); everything else, including
    // canonical metadata that is not shown elsewhere, stays in the keyboard-accessible "Ayrıntıları gör" area.
    // The provider offer id is never displayed; it only addresses the offer detail page.
    const renderOffer = (offer, detailUrl, { expanded = false } = {}) => {
        const card = el('article', 'offer-card');
        const itinerary = describeItinerary(offer.segments);
        const header = el('header', 'offer-card-header');
        const title = el('div', 'offer-heading');
        title.append(el('h3', 'offer-title', itinerary ? itinerary.flights.join(' · ') : 'Uçuş teklifi'));
        if (offer.state !== 'CURRENT') {
            title.append(el('span', `badge badge-state-${String(offer.state).toLowerCase()}`, STATE_LABELS[offer.state] ?? offer.state));
        }
        header.append(title, renderPrice(offer.price));
        card.append(header);

        if (itinerary) {
            const route = el('div', 'offer-route');
            const departure = el('p', 'offer-endpoint');
            departure.append(el('span', 'offer-clock', itinerary.departure), el('span', 'offer-endpoint-label', 'Kalkış'));
            const path = el('p', 'offer-path');
            const line = el('span', 'offer-line');
            line.setAttribute('aria-hidden', 'true');
            path.append(el('span', 'offer-duration', itinerary.duration), line, el('span', 'offer-stops', itinerary.stops));
            const arrival = el('p', 'offer-endpoint offer-endpoint-arrival');
            const arrivalClock = el('span', 'offer-clock', itinerary.arrival);
            if (itinerary.dayShift > 0) {
                arrivalClock.append(el('span', 'offer-day-shift', ` +${itinerary.dayShift} gün`));
            }
            arrival.append(arrivalClock, el('span', 'offer-endpoint-label', 'Varış'));
            route.append(departure, path, arrival);
            card.append(route, el('p', 'offer-date', itinerary.date));
        } else {
            card.append(el('p', 'summary-meta', 'Uçuş bölümü bilgisi yok.'));
        }

        const baggage = el('div', 'offer-baggage');
        const baggageList = el('ul', 'offer-baggage-list');
        describeBaggageSummary(offer.baggage).forEach((line) => baggageList.append(el('li', '', line)));
        baggage.append(el('h4', 'offer-baggage-title', 'Bagaj'), baggageList);
        card.append(baggage);

        const details = el('details', 'offer-details');
        details.open = expanded;
        details.append(el('summary', 'offer-details-toggle', 'Ayrıntıları gör'));
        const body = el('div', 'offer-details-body');
        const components = describePrice(offer.price).components;
        if (components.length > 0) {
            body.append(el('p', 'summary-meta', `Bilinmeyen bileşenler: ${components.join(', ')}`));
        }
        body.append(renderSegments(offer.segments), renderBaggage(offer.baggage));
        body.append(el('p', 'summary-meta', offer.expires_at ? `Teklif geçerliliği: ${formatDate(offer.expires_at)}` : 'Geçerlilik süresi bildirilmedi.'));
        body.append(renderSources(offer));
        if (detailUrl) {
            const link = el('a', 'btn btn-secondary', 'Güncel fiyatı ve koşulları kontrol et');
            link.href = detailUrl.replace('__OFFER__', encodeURIComponent(offer.provider_offer_id));
            body.append(link);
        }
        details.append(body);
        card.append(details);

        return card;
    };

    // Canonical offer identity keeps a repeated or newer snapshot from listing an offer twice.
    const uniqueOffers = (job) => {
        const seen = new Set();

        return (Array.isArray(job.result?.offers) ? job.result.offers : []).filter((offer) => {
            const identity = offer?.provider_offer_id;
            if (typeof identity !== 'string' || seen.has(identity)) {
                return false;
            }
            seen.add(identity);

            return true;
        });
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
                // Storage may be unavailable; the search still works for this page view.
            }
        },
        clear: (key) => {
            try {
                window.sessionStorage.removeItem(key);
            } catch {
                // Ignore unavailable storage.
            }
        },
    };

    const newIdempotencyKey = () => {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);

        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    };

    const initSearch = (root) => {
        const status = root.querySelector('[data-search-status]');
        const startButton = root.querySelector('[data-search-start]');
        const refreshButton = root.querySelector('[data-search-refresh]');
        const restartButton = root.querySelector('[data-search-restart]');
        const results = document.querySelector('[data-search-results]');
        const list = document.querySelector('[data-offer-list]');
        const startLabel = startButton.textContent;
        const storageKey = root.dataset.storageKey;
        const interval = Number(root.dataset.pollInterval ?? 0);
        let state = storage.read(storageKey) ?? {};
        let busy = false;
        let timer;

        const showStatus = (message, tone = 'info') => {
            status.replaceChildren(el('p', `status-text status-${tone}`, message));
        };

        const renderResults = (job, offers) => {
            // A completed search without offers is reported in the status line only.
            const visible = job.status === 'PARTIAL' || (job.status === 'COMPLETED' && offers.length > 0);
            results.hidden = !visible;
            if (!visible) {
                return;
            }
            list.replaceChildren(...offers.map((offer) => {
                const item = el('li');
                item.append(renderOffer(offer, root.dataset.offerUrl));

                return item;
            }));
            if (offers.length === 0) {
                list.append(el('li', 'empty-note', 'Henüz teklif yok.'));
            }
        };

        const render = (job) => {
            const settled = TERMINAL.includes(job.status) || (job.status === 'PARTIAL' && job.error);
            const offers = uniqueOffers(job);
            const messages = {
                QUEUED: ['Uçuş aramanız sıraya alındı.', 'info'],
                RUNNING: ['Uçuşlar aranıyor.', 'info'],
                PARTIAL: job.error
                    ? [`Arama kısmi sonuçla tamamlandı. ${job.error.message_tr}`, 'warning']
                    : ['Uçuşlar aranıyor; ilk teklifler aşağıda.', 'info'],
                COMPLETED: offers.length > 0
                    ? [`Arama tamamlandı: ${offers.length} uçuş teklifi bulundu.`, 'success']
                    : ['Aramanıza uygun uçuş bulunamadı.', 'info'],
                FAILED: [job.error?.message_tr ?? 'Arama tamamlanamadı.', 'danger'],
                EXPIRED: ['Arama süresi doldu. Yeniden arama başlatabilirsiniz.', 'danger'],
            };
            const [message, tone] = messages[job.status] ?? ['Arama durumu bilinmiyor.', 'warning'];
            showStatus(message, tone);
            renderResults(job, offers);
            startButton.hidden = true;
            refreshButton.hidden = settled;
            restartButton.hidden = !(job.status === 'FAILED' || job.status === 'EXPIRED');

            return settled;
        };

        let polling = false;

        // A failed status request never starts a new search; it only retries the same search's status.
        const retryLater = (message) => {
            showStatus(message, 'warning');
            startButton.hidden = true;
            refreshButton.hidden = false;
            if (interval > 0) {
                timer = setTimeout(poll, interval);
            }
        };

        const poll = async () => {
            clearTimeout(timer);
            if (!state.searchId || polling) {
                return;
            }
            polling = true;
            refreshButton.disabled = true;
            try {
                const response = await request(root.dataset.pollUrl.replace('__SEARCH__', encodeURIComponent(state.searchId)));
                if (response.status === 404) {
                    storage.clear(storageKey);
                    state = {};
                    showStatus('Arama kaydı bulunamadı. Yeni bir arama başlatabilirsiniz.', 'warning');
                    startButton.hidden = false;
                    refreshButton.hidden = true;

                    return;
                }
                // OD-028: signed out (401) or not the TripRequest owner (403) does not change by retrying.
                if (response.status === 401 || response.status === 403) {
                    showStatus(response.body?.error?.message_tr ?? 'Bu aramayı görüntüleme yetkiniz bulunmuyor.', 'warning');
                    refreshButton.hidden = true;

                    return;
                }
                if (!response.ok || !response.body) {
                    retryLater('Arama durumu şu anda alınamadı. Yeniden denenecek; isterseniz "Durumu yenile" düğmesini kullanın.');

                    return;
                }
                const settled = render(response.body);
                if (!settled && interval > 0) {
                    timer = setTimeout(poll, interval);
                }
            } catch {
                retryLater('Bağlantı kesildi; arama durumu alınamadı. Bağlantınızı kontrol edin, aramanız korunuyor.');
            } finally {
                polling = false;
                refreshButton.disabled = false;
            }
        };

        const start = async () => {
            if (busy || state.searchId) {
                return;
            }
            busy = true;
            startButton.disabled = true;
            startButton.textContent = 'Arama başlatılıyor…';
            // The key is stored before the request so a retry or reload reuses the same logical search.
            state = { key: state.key ?? newIdempotencyKey() };
            storage.write(storageKey, state);
            showStatus('Arama başlatılıyor…');
            try {
                const response = await request(root.dataset.startUrl, {
                    method: 'POST',
                    headers: { 'Idempotency-Key': state.key, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ trip_request_id: root.dataset.tripRequestId }),
                });
                if (response.status !== 202 || !response.body?.search_id) {
                    showStatus(response.body?.error?.message_tr ?? 'Arama başlatılamadı. Lütfen yeniden deneyin.', 'danger');
                    startButton.disabled = false;
                    startButton.textContent = startLabel;

                    return;
                }
                state = { ...state, searchId: response.body.search_id };
                storage.write(storageKey, state);
                await poll();
            } catch {
                showStatus('Arama başlatılamadı. Bağlantınızı kontrol edip yeniden deneyin.', 'danger');
                startButton.disabled = false;
                startButton.textContent = startLabel;
            } finally {
                busy = false;
            }
        };

        startButton.addEventListener('click', start);
        refreshButton.addEventListener('click', poll);
        restartButton.addEventListener('click', () => {
            clearTimeout(timer);
            storage.clear(storageKey);
            state = {};
            results.hidden = true;
            restartButton.hidden = true;
            refreshButton.hidden = true;
            startButton.hidden = false;
            startButton.disabled = false;
            startButton.textContent = startLabel;
            showStatus('Arama henüz başlatılmadı.');
        });

        if (state.searchId) {
            poll();
        }
    };

    const describeTerms = (snapshot) => {
        const terms = snapshot?.fare_conditions?.terms ?? [];
        if (terms.length === 0) {
            return 'Koşul bilgisi yok';
        }

        return terms.map((term) => {
            const value = term.certainty === 'UNKNOWN' || term.value === null
                ? 'Bilinmiyor'
                : (term.value === true ? 'Evet' : (term.value === false ? 'Hayır' : String(term.value)));

            return `${term.key}: ${value}`;
        }).join(' · ');
    };

    const describeBaggageLine = (baggage) => (baggage
        ? ALLOWANCES.map(([key, label]) => `${label}: ${describeAllowance(baggage[key]).text}`).join(' · ')
        : 'Bagaj bilgisi doğrulanamadı');

    /*
     * OP-repriceOffer outcome (FLT-REPRICE-001), derived only from the contract fields: the current OfferState,
     * the unverified reason code and the change set. Anything not positively verified is treated as unverified.
     */
    const repriceOutcome = (result) => {
        const state = result.current?.offer?.state;
        if (state === 'EXPIRED') {
            return 'expired';
        }
        if (state === 'UNVERIFIED' || (result.reason_codes ?? []).includes('RC-EVIDENCE-REPRICE-UNVERIFIED')) {
            return 'unverified';
        }
        if (state === 'CHANGED' || result.changes?.critical_change === true) {
            return 'changed';
        }

        return state === 'CURRENT' && result.previous_approval_valid === true ? 'current' : 'unverified';
    };

    const OUTCOME_BANNERS = {
        current: ['alert-success', 'status', 'Teklif yeniden doğrulandı; fiyat ve koşullar değişmedi. Önceki onayınız geçerli.'],
        changed: ['alert-warning', 'alert', 'Teklifin fiyatı veya koşulları değişti. Önceki onay artık geçerli değil; devam etmeden önce yeni değerleri inceleyip onaylayın.'],
        expired: ['alert-danger', 'alert', 'Bu teklif artık geçerli değil. Uçuş aramasına dönüp yeni bir teklif seçebilirsiniz.'],
        unverified: ['alert-warning', 'alert', 'Teklif sağlayıcıdan doğrulanamadı; fiyat ve koşullar değişmiş olabilir. Bu teklif güncel olarak gösterilmez.'],
    };

    const renderReprice = (result) => {
        const fragment = document.createDocumentFragment();
        const outcome = repriceOutcome(result);
        const [tone, role, message] = OUTCOME_BANNERS[outcome];
        const banner = el('div', `alert ${tone}`);
        banner.setAttribute('role', role);
        banner.append(el('p', '', message));
        fragment.append(banner);

        // AC-FLT-REPRICE-001-01: last verification time and verified price, only for a provider-confirmed offer.
        const verifiedAt = result.current?.offer?.sources?.[0]?.verified_at;
        if ((outcome === 'current' || outcome === 'changed') && verifiedAt) {
            fragment.append(el('p', 'verification-line', `Son doğrulama: ${formatDate(verifiedAt)} · Doğrulanan fiyat: ${describePrice(result.current.offer.price).headline}`));
        }
        if (outcome === 'unverified' && result.reprice_capability?.supported === false) {
            fragment.append(el('p', 'summary-meta', 'Bu sağlayıcı için yeniden fiyatlama şu anda kullanılamıyor; gösterilen bilgiler arama anındaki bilgilerdir.'));
        }

        const reasons = (result.reason_codes ?? []).map((code) => REASON_MESSAGES[code]).filter(Boolean);
        if (reasons.length > 0) {
            const list = el('ul', 'reason-list');
            reasons.forEach((reason) => list.append(el('li', '', reason)));
            fragment.append(list);
        }

        if (outcome === 'expired' || outcome === 'unverified') {
            fragment.append(el('p', 'alert alert-warning', 'Bu teklifle şu anda devam edilemez.'));

            return fragment;
        }

        const table = el('table', 'compare-table');
        const head = el('tr');
        ['', 'Önceki', 'Güncel', 'Durum'].forEach((label) => head.append(el('th', '', label)));
        const thead = el('thead');
        thead.append(head);
        const tbody = el('tbody');
        const priceText = (snapshot) => {
            const description = describePrice(snapshot?.offer?.price);

            return `${description.headline} — ${description.note}`;
        };
        [
            ['Fiyat', priceText(result.previous), priceText(result.current), result.changes?.price_changed],
            ['Ücret koşulları', describeTerms(result.previous), describeTerms(result.current), result.changes?.fare_conditions_changed],
            ['Bagaj', describeBaggageLine(result.previous?.offer?.baggage), describeBaggageLine(result.current?.offer?.baggage), result.changes?.baggage_changed],
        ].forEach(([label, before, after, changed]) => {
            const row = el('tr', changed ? 'is-changed' : '');
            row.append(el('th', '', label), el('td', '', before), el('td', '', after), el('td', '', changed ? 'Değişti' : 'Değişmedi'));
            tbody.append(row);
        });
        table.append(thead, tbody);
        const scroller = el('div', 'table-scroll');
        scroller.append(table);
        fragment.append(scroller);

        if (outcome === 'current') {
            fragment.append(el('p', 'alert alert-info', 'Teklif bu koşullarla geçerli; ancak rezervasyon/yönlendirme adımı henüz kullanıma açılmadı.'));

            return fragment;
        }

        // RULE-FLT-REPRICE-001-03: a changed price, fare or baggage condition is never accepted silently; the user
        // must acknowledge the updated values explicitly. No booking, payment or redirect follows (Model A, OD-011).
        const acknowledgement = el('div', 'reprice-acknowledgement');
        const note = el('p', 'alert alert-warning', 'Devam etmek için güncel fiyatı ve koşulları onaylamanız gerekir.');
        const accept = el('button', 'btn btn-primary', 'Güncel fiyatı ve koşulları kabul ediyorum');
        accept.type = 'button';
        accept.addEventListener('click', () => {
            accept.disabled = true;
            note.className = 'alert alert-success';
            note.textContent = 'Güncel fiyatı ve koşulları onayladınız. Rezervasyon veya yönlendirme adımı henüz kullanıma açılmadığı için satın alma yapılmadı.';
        });
        acknowledgement.append(note, accept);
        fragment.append(acknowledgement);

        return fragment;
    };

    /*
     * v3.13 TripRequest flight selection, offered only after a successful revalidation (CURRENT, or CHANGED with
     * explicit acceptance). The request carries the offer id and the shown verification time only; success is
     * displayed only after the server confirms it.
     */
    const initSelection = (root, repriceResult) => {
        const box = root.querySelector('[data-selection]');
        if (!root.dataset.selectionUrl || !box) {
            return async () => {};
        }
        const content = box.querySelector('[data-selection-body]');
        const status = box.querySelector('[data-selection-status]');
        const planLink = () => {
            const link = el('a', 'btn btn-secondary', 'Seyahat Planım’a git');
            link.href = root.dataset.planUrl;

            return link;
        };

        return async (result) => {
            const outcome = repriceOutcome(result);
            const offerId = result.current?.offer?.provider_offer_id;
            const verifiedAt = result.current?.captured_at;
            box.hidden = true;
            status.textContent = '';
            if ((outcome !== 'current' && outcome !== 'changed') || !offerId || !verifiedAt) {
                return;
            }
            let existing = null;
            try {
                const response = await request(root.dataset.selectionUrl);
                existing = response.ok ? response.body : null;
            } catch {
                existing = null;
            }
            const sameOffer = existing?.offer?.provider_offer_id === offerId;
            // The client-only acknowledgement is replaced by the server-side selection when a TripRequest is known.
            repriceResult.querySelector('.reprice-acknowledgement')?.remove();

            if (sameOffer && existing.status === 'CURRENT' && outcome === 'current') {
                content.replaceChildren(el('p', 'alert alert-success', 'Bu uçuş seyahat planınızda seçili.'), planLink());
                box.hidden = false;

                return;
            }
            const reconfirm = sameOffer && existing.status === 'RECONFIRMATION_REQUIRED';
            const label = reconfirm
                ? 'Yeni fiyatı onayla'
                : (outcome === 'changed' ? 'Yeni fiyatı kabul et ve bu uçuşu seç' : 'Bu uçuşu seç');
            const intro = reconfirm
                ? 'Seçtiğiniz uçuşun fiyatı veya koşulları değişti. Seçiminizin geçerli kalması için yeni değerleri onaylayın.'
                : 'Bu uçuşu seyahat planınıza ekleyebilirsiniz. Rezervasyon veya ödeme yapılmaz.';
            const button = el('button', 'btn btn-primary', label);
            button.type = 'button';
            const key = newIdempotencyKey();
            const payload = reconfirm
                ? { expected_verified_at: verifiedAt }
                : { offer_id: offerId, expected_verified_at: verifiedAt, accept_changed_offer: outcome === 'changed' };
            button.addEventListener('click', async () => {
                button.disabled = true;
                status.textContent = 'Seçiminiz kaydediliyor…';
                try {
                    const response = await request(reconfirm ? root.dataset.reconfirmUrl : root.dataset.selectionUrl, {
                        method: reconfirm ? 'POST' : 'PUT',
                        headers: { 'Idempotency-Key': key, 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload),
                    });
                    if (response.ok && response.body?.status === 'CURRENT') {
                        status.textContent = '';
                        content.replaceChildren(el('p', 'alert alert-success', 'Uçuş seçildi ve seyahat planınıza kaydedildi.'), planLink());

                        return;
                    }
                    status.textContent = response.body?.error?.message_tr ?? 'Seçim kaydedilemedi. Lütfen yeniden deneyin.';
                    button.disabled = response.status === 409;
                } catch {
                    status.textContent = 'Seçim kaydedilemedi. Bağlantınızı kontrol edip yeniden deneyin.';
                    button.disabled = false;
                }
            });
            content.replaceChildren(el('p', '', intro), button);
            box.hidden = false;
        };
    };

    const initOffer = (root) => {
        const body = root.querySelector('[data-offer-body]');
        const repriceSection = root.querySelector('[data-reprice-section]');
        const repriceButton = root.querySelector('[data-reprice]');
        const repriceResult = root.querySelector('[data-reprice-result]');
        const offerSelection = initSelection(root, repriceResult);

        // The stored search result is shown only as "not yet re-verified" until OP-repriceOffer answers.
        const showOffer = (offer, note) => {
            const card = renderOffer(offer, null, { expanded: true });
            body.replaceChildren(...(note ? [el('p', 'offer-verification-note', note), card] : [card]));
        };
        let stored = null;
        let busy = false;

        const reprice = async () => {
            if (busy) {
                return;
            }
            busy = true;
            repriceButton.disabled = true;
            repriceResult.replaceChildren(el('p', 'loading', 'Teklif sağlayıcıdan yeniden doğrulanıyor…'));
            const unverified = (message) => {
                repriceResult.replaceChildren(el('p', 'alert alert-warning', message));
                if (stored) {
                    showOffer(stored, 'Arama sonucundaki bilgiler gösteriliyor; teklif yeniden doğrulanamadığı için güncel olmayabilir.');
                }
            };
            try {
                const response = await request(root.dataset.repriceUrl, { method: 'POST' });
                if (response.ok && response.body) {
                    repriceResult.replaceChildren(renderReprice(response.body));
                    if (response.body.current?.offer) {
                        showOffer(response.body.current.offer);
                    }
                    await offerSelection(response.body);
                } else {
                    unverified(response.status === 404
                        ? 'Yeniden fiyatlanacak teklif bulunamadı.'
                        : 'Yeniden doğrulama şu anda yapılamadı. Lütfen daha sonra tekrar deneyin.');
                }
            } catch {
                unverified('Yeniden doğrulama şu anda yapılamadı. Bağlantınızı kontrol edin.');
            } finally {
                busy = false;
                repriceButton.disabled = false;
            }
        };

        request(root.dataset.offerUrl).then((response) => {
            if (response.ok && response.body) {
                stored = response.body;
                showOffer(stored, 'Arama sonucundaki bilgiler; teklif sağlayıcıdan yeniden doğrulanıyor.');
                repriceSection.hidden = false;
                // FLT-REPRICE-001: a selected offer is revalidated before it is presented as current.
                return reprice();
            }
            body.replaceChildren(el('p', 'empty-note', response.status === 404
                ? 'Teklif bulunamadı veya geçerlilik süresi doldu.'
                : 'Teklif şu anda yüklenemedi. Daha sonra yeniden deneyin.'));

            return undefined;
        }).catch(() => body.replaceChildren(el('p', 'empty-note', 'Teklif şu anda yüklenemedi. Bağlantınızı kontrol edin.')));

        repriceButton.addEventListener('click', reprice);
    };

    // "Taleplerim": a card shows a search state only when this tab already knows the request's search
    // (the flight page's own session state); nothing is started or polled repeatedly from the list.
    const describeRequestSearch = (job) => {
        if (job.status === 'COMPLETED') {
            return uniqueOffers(job).length > 0 ? ['Teklifler hazır', 'success'] : ['Uygun uçuş bulunamadı', 'info'];
        }

        return {
            QUEUED: ['Arama bekliyor', 'info'],
            RUNNING: ['Aranıyor', 'info'],
            PARTIAL: job.error ? ['Teklifler hazır (kısmi sonuç)', 'warning'] : ['Aranıyor', 'info'],
            FAILED: ['Arama tamamlanamadı', 'danger'],
            EXPIRED: ['Arama süresi doldu', 'danger'],
        }[job.status] ?? null;
    };

    const initRequests = (root) => {
        root.querySelectorAll('[data-request-card]').forEach(async (card) => {
            const state = storage.read(card.dataset.storageKey);
            if (!state?.searchId) {
                return;
            }
            try {
                const response = await request(root.dataset.pollUrl.replace('__SEARCH__', encodeURIComponent(state.searchId)));
                const described = response.ok && response.body ? describeRequestSearch(response.body) : null;
                if (!described) {
                    return;
                }
                const [label, tone] = described;
                const status = card.querySelector('[data-request-status]');
                status.replaceChildren(el('span', `request-status-label status-${tone}`, `Uçuş araması: ${label}`));
                status.hidden = false;
                const action = card.querySelector('[data-request-action]');
                if (action) {
                    action.textContent = 'Teklifleri görüntüle';
                }
            } catch {
                // The card still links to the flight page, which shows the search's own state.
            }
        });
    };

    const search = document.querySelector('[data-flight-search]');
    if (search) {
        initSearch(search);
    }
    const requestList = document.querySelector('[data-trip-requests]');
    if (requestList) {
        initRequests(requestList);
    }
    const detail = document.querySelector('[data-offer-detail]');
    if (detail) {
        initOffer(detail);
    }
    document.querySelector('[data-history-back]')?.addEventListener('click', () => window.history.back());
})();
