(() => {
    /*
     * TRV-001 screens and the separate consent screen. Everything here is page-local: no request, no browser storage and
     * no field names, so nothing typed can leave the page. The check only lists empty sections and never reports a save.
     */
    const filters = document.querySelector('[data-traveler-filters]');
    if (filters) {
        const buttons = Array.from(filters.querySelectorAll('[data-traveler-filter]'));
        const rows = Array.from(document.querySelectorAll('[data-traveler-type]'));
        const status = document.querySelector('[data-traveler-filter-status]');
        buttons.forEach((button) => button.addEventListener('click', () => {
            const type = button.dataset.travelerFilter;
            buttons.forEach((other) => other.setAttribute('aria-pressed', String(other === button)));
            rows.forEach((row) => {
                row.hidden = type !== '' && row.dataset.travelerType !== type;
            });
            if (status) {
                status.textContent = `${rows.filter((row) => !row.hidden).length} yolcu gösteriliyor.`;
            }
        }));
    }

    document.querySelectorAll('[data-traveler-form]').forEach((form) => {
        const preview = form.querySelector('[data-traveler-preview]');
        const fields = form.querySelector('[data-traveler-fields]');
        const validate = form.querySelector('[data-traveler-validate]');
        const check = form.querySelector('[data-traveler-check]');
        const repeats = Array.from(form.querySelectorAll('[data-repeat]'));
        const refreshEmpty = (repeat) => {
            repeat.querySelector('[data-repeat-empty]').hidden = repeat.querySelector('[data-repeat-list]').children.length > 0;
        };
        const clear = () => {
            repeats.forEach((repeat) => {
                repeat.querySelector('[data-repeat-list]').replaceChildren();
                refreshEmpty(repeat);
            });
            check.textContent = '';
        };
        const setPreview = (enabled) => {
            fields.disabled = !enabled;
            validate.disabled = !enabled;
            if (!enabled) {
                clear();
            }
        };

        form.addEventListener('submit', (event) => event.preventDefault());
        preview.addEventListener('change', () => setPreview(preview.checked));

        repeats.forEach((repeat) => {
            const list = repeat.querySelector('[data-repeat-list]');
            const template = repeat.querySelector('[data-repeat-template]');
            repeat.querySelector('[data-repeat-add]').addEventListener('click', () => {
                list.append(template.content.cloneNode(true));
                refreshEmpty(repeat);
                list.lastElementChild.querySelector('input')?.focus();
            });
            list.addEventListener('click', (event) => {
                const remove = event.target.closest('[data-repeat-remove]');
                if (remove) {
                    remove.closest('li').remove();
                    refreshEmpty(repeat);
                    repeat.querySelector('[data-repeat-add]').focus();
                }
            });
        });

        validate.addEventListener('click', () => {
            const complete = (repeat) => {
                const rows = Array.from(repeat.querySelectorAll('[data-repeat-list] > li'));

                return rows.length > 0 && rows.every((row) => Array.from(row.querySelectorAll('[data-repeat-required]')).every((input) => input.value.trim() !== ''));
            };
            const missing = [
                ...(form.dataset.alwaysMissing ? [form.dataset.alwaysMissing] : []),
                ...repeats.filter((repeat) => !complete(repeat)).map((repeat) => repeat.dataset.repeatLabel),
            ];
            check.textContent = missing.length > 0
                ? `Eksik bilgi: ${missing.join(', ')}. Bu kontrol bilgileri kaydetmez.`
                : 'Bu bölümlerde boş alan yok. Bilgiler doğrulanmadı ve kaydedilmedi.';
        });

        // Add screen: the chosen traveler decides which draft access rule applies and whether the fields can open.
        const slot = form.querySelector('[data-traveler-slot]');
        slot?.addEventListener('change', () => {
            const minor = slot.selectedOptions[0]?.dataset.minor === '1';
            document.querySelectorAll('[data-access-rule]').forEach((rule) => {
                rule.hidden = rule.dataset.accessRule !== (minor ? 'minor' : 'adult');
            });
            form.querySelector('[data-preview-locked]').hidden = !minor;
            preview.disabled = minor;
            if (minor) {
                preview.checked = false;
                setPreview(false);
            }
        });
    });

    const consent = document.querySelector('[data-consent]');
    if (consent) {
        const box = consent.querySelector('[data-consent-check]');
        const status = consent.querySelector('[data-consent-status]');
        box.addEventListener('change', () => {
            status.textContent = box.checked
                ? 'İşaretlendi, ancak rıza kaydedilmedi; bu işaret rıza sayılmaz.'
                : 'Rıza alınmadı.';
        });
    }
})();
