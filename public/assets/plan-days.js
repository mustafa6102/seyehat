(() => {
    /*
     * Day plan (DAY-001 v3.18, OD-029 A): manual day assignment and in-day order of plan items through
     * OP-assignTripPlanItemDay on the session web channel (/web-api/v1, OD-028). OD-030 A adds a separate positive-integer
     * duration request; source/status remain server-owned USER_PROVIDED/UNVERIFIED. No time or feasibility is sent. The
     * server-rendered page is the source of truth: after a change the page is reloaded, after a conflict the user reloads.
     */
    const root = document.querySelector('[data-plan-days]');
    if (!root) {
        return;
    }
    const status = root.querySelector('[data-plan-days-status]');
    const reloadButton = root.querySelector('[data-plan-days-reload]');
    const containers = Array.from(root.querySelectorAll('[data-day-assignment]'));
    const durationContainers = Array.from(root.querySelectorAll('[data-plan-duration]'));
    const revisionMutationControls = Array.from(root.querySelectorAll('[data-plan-mutation] button'));
    const controls = () => [
        ...[...containers, ...durationContainers].flatMap((container) => [
            ...Array.from(container.querySelectorAll('select')),
            ...Array.from(container.querySelectorAll('input')),
            ...Array.from(container.querySelectorAll('button')),
        ]),
        ...revisionMutationControls,
    ];
    const reload = () => window.location?.reload?.();
    const newKey = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`);

    // Lock every control that mutates this revision, including item removal, so parallel actions cannot race on one If-Match.
    // Disabled state as rendered (first/last item cannot move further) is restored after a recoverable failure.
    const rendered = new Map();
    const setBusy = (busy) => {
        controls().forEach((control) => {
            if (!rendered.has(control)) {
                rendered.set(control, control.disabled);
            }
            control.disabled = busy || rendered.get(control);
        });
    };
    const setStatus = (text, urgent = false) => {
        if (status) {
            status.textContent = text;
            status.setAttribute('role', urgent ? 'alert' : 'status');
            if (urgent) {
                status.focus();
            }
        }
    };

    /** [Turkish message, whether only a reload of the canonical plan can continue] */
    const failure = (code, httpStatus, body) => {
        if (code === 'REVISION_STALE' || code === 'IDEMPOTENCY_KEY_CONFLICT') {
            return ['Plan bu sırada değişti. Güncel planı görmek için sayfayı yenileyin.', true];
        }
        if (httpStatus === 401) {
            return ['Oturumunuz sona ermiş. Yeniden giriş yapıp sayfayı yenileyin.', true];
        }
        if (httpStatus === 403) {
            return ['Bu planı yalnız sahibi düzenleyebilir.', true];
        }
        if (httpStatus === 419) {
            return ['Sayfanın güvenlik süresi doldu. Sayfayı yenileyip yeniden deneyin.', true];
        }
        if (code === 'DAY_FRAME_UNAVAILABLE' || code === 'DAY_ASSIGNMENT_INVALID' || code === 'RESOURCE_NOT_FOUND') {
            return [body?.error?.message_tr ?? 'Plan güncel değil. Sayfayı yenileyin.', true];
        }

        return [body?.error?.message_tr ?? 'Plan güncellenemedi. Lütfen yeniden deneyin.', false];
    };

    // A retry of exactly the same change after a network failure reuses its key, so it can never apply twice.
    let lastAttempt = null;
    const send = async (url, body) => {
        const payload = JSON.stringify(body);
        const key = lastAttempt?.url === url && lastAttempt.payload === payload ? lastAttempt.key : newKey();
        lastAttempt = { url, payload, key };
        setBusy(true);
        setStatus('Kaydediliyor…');
        let response;
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            response = await fetch(url, {
                method: 'PUT',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'If-Match': `"${root.dataset.planVersion}"`,
                    'Idempotency-Key': key,
                },
                body: payload,
            });
        } catch {
            setStatus('Bağlantı kurulamadı. Bağlantınızı kontrol edip yeniden deneyin.');
            setBusy(false);

            return;
        }
        let responseBody = null;
        try {
            responseBody = await response.json();
        } catch {
            responseBody = null;
        }
        if (response.ok) {
            lastAttempt = null;
            setStatus('Plan güncellendi. Sayfa yenileniyor…');
            reload();

            return;
        }
        const [message, needsReload] = failure(responseBody?.error?.code, response.status, responseBody);
        setStatus(message, needsReload);
        if (needsReload) {
            // The request was definitively rejected; a reload is required before another mutation.
            lastAttempt = null;
            if (reloadButton) {
                reloadButton.hidden = false;
            }

            return;
        }
        setBusy(false);
    };

    containers.forEach((container) => {
        const { url, date } = container.dataset;
        const position = Number(container.dataset.position);
        const select = container.querySelector('[data-day-select]');
        container.querySelector('[data-day-apply]')?.addEventListener('click', () => {
            const target = select?.value ?? '';
            if (target === date) {
                setStatus(target === '' ? 'Bu yer zaten bir güne atanmamış.' : 'Bu yer zaten seçilen günde.');

                return undefined;
            }

            return send(url, { date: target === '' ? null : target });
        });
        container.querySelector('[data-day-move="up"]')?.addEventListener('click', () => send(url, { date, position: position - 1 }));
        container.querySelector('[data-day-move="down"]')?.addEventListener('click', () => send(url, { date, position: position + 1 }));
    });
    durationContainers.forEach((container) => {
        const input = container.querySelector('[data-duration-input]');
        container.querySelector('[data-duration-save]')?.addEventListener('click', () => {
            const raw = input?.value ?? '';
            const minutes = Number(raw);
            if (!/^[0-9]+$/.test(raw) || !Number.isSafeInteger(minutes) || minutes < 1) {
                setStatus('Ziyaret süresini dakika olarak 1 veya daha büyük bir tam sayı girin.');
                input?.focus();

                return;
            }
            if (String(minutes) === container.dataset.currentMinutes) {
                setStatus(`Ziyaret süresi zaten ${minutes} dakika; plan sürümü değiştirilmedi.`);

                return;
            }

            return send(container.dataset.url, { minutes });
        });
    });
    reloadButton?.addEventListener('click', reload);
})();
