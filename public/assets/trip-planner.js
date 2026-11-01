(() => {
    /*
     * Journey and directional dates (TRIP-001 v3.9). Visibility and enablement only: this script never sets a
     * journey type, a date mode value or a date. Hidden/disabled controls are not submitted; the server validates.
     */
    document.querySelectorAll('[data-travel-date]').forEach((travelDate) => {
        const updateDateMode = () => {
            const selected = travelDate.querySelector('input[type="radio"]:checked')?.value ?? 'exact';

            travelDate.querySelectorAll('[data-date-fields]').forEach((group) => {
                const active = group.dataset.dateFields === selected;
                group.hidden = !active;
                group.querySelectorAll('input').forEach((input) => {
                    input.disabled = !active;
                });
            });
        };

        travelDate.querySelectorAll('input[type="radio"]').forEach((radio) => radio.addEventListener('change', updateDateMode));
        updateDateMode();
    });

    const journey = document.querySelector('[data-journey]');

    if (journey) {
        const returnDate = journey.querySelector('[data-return-date]');
        const returnFields = returnDate?.querySelector('[data-travel-date]');
        const updateJourney = () => {
            const roundTrip = journey.querySelector('input[name="journey_type"]:checked')?.value === 'ROUND_TRIP';

            if (returnDate && returnFields) {
                returnDate.hidden = !roundTrip;
                returnFields.disabled = !roundTrip;
            }
        };

        journey.querySelectorAll('input[name="journey_type"]').forEach((radio) => radio.addEventListener('change', updateJourney));
        updateJourney();
    }

    /*
     * Passenger steppers (passenger_composition v3.12): +/- buttons adjust the existing number inputs, which stay
     * editable and are submitted as before. Each count stays >= 0 and the buttons never take the total below one;
     * the server remains authoritative (it derives traveler_count from the composition and validates it).
     */
    const passengers = document.querySelector('[data-passengers]');

    if (passengers) {
        const steppers = [...passengers.querySelectorAll('[data-stepper]')].map((stepper) => ({
            input: stepper.querySelector('input'),
            buttons: [...stepper.querySelectorAll('[data-step]')],
        }));
        const totalText = passengers.querySelector('[data-passenger-total]');
        const travelerCount = passengers.querySelector('input[name="traveler_count"]');
        const count = (input) => (/^\d+$/.test(input.value.trim()) ? Number(input.value.trim()) : null);
        const total = () => steppers.reduce((sum, { input }) => sum + (count(input) ?? 0), 0);
        // A decrement is blocked at zero and when it would leave no passenger at all.
        const blocked = (input, step) => step < 0 && ((count(input) ?? 0) === 0 || total() <= 1);

        const refresh = () => {
            const sum = total();
            totalText.textContent = String(sum);
            // Mirrors the visible total only while every count is a valid whole number; otherwise the server reports it.
            if (steppers.every(({ input }) => count(input) !== null)) {
                travelerCount.value = String(sum);
            }
            // aria-disabled (not disabled) keeps keyboard focus on the button when a limit is reached.
            steppers.forEach(({ input, buttons }) => buttons.forEach((button) => {
                button.setAttribute('aria-disabled', String(blocked(input, Number(button.dataset.step))));
            }));
        };

        steppers.forEach(({ input, buttons }) => {
            buttons.forEach((button) => {
                button.hidden = false;
                button.addEventListener('click', () => {
                    const step = Number(button.dataset.step);
                    if (blocked(input, step)) {
                        return;
                    }
                    input.value = String((count(input) ?? 0) + step);
                    refresh();
                });
            });
            input.addEventListener('input', refresh);
        });
        refresh();
    }

    /*
     * Location picker (country -> city/airport/station) following the ARIA 1.2 combobox pattern.
     *
     * The country list is static server data; location search goes through the application's own /konumlar/*
     * endpoints only. The user never types a timezone; kind, id/token and iana_timezone come from the selected
     * canonical entry. Flight entries submit only the opaque server-issued selection token.
     */
    const routeFields = document.querySelector('[data-location-kinds]');
    const catalogStatus = document.querySelector('[data-location-catalog-status]');
    const MIN_QUERY_LENGTH = 2;
    let kindLabels = {};

    try {
        kindLabels = JSON.parse(routeFields?.dataset.locationKinds ?? '{}');
    } catch {
        kindLabels = {};
    }

    const createCombobox = ({ input, listbox, announce, search, describe, onSelect, onClear, findTyped, emptyMessage = 'Sonuç bulunamadı.', minLength = 0, openOnFocus = false }) => {
        let items = [];
        let activeIndex = -1;
        let requestId = 0;
        let debounce;
        let committed = false;

        const close = () => {
            listbox.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            activeIndex = -1;
        };

        const setActive = (index) => {
            const options = listbox.querySelectorAll('[role="option"]');
            options.forEach((option, i) => option.setAttribute('aria-selected', String(i === index)));
            activeIndex = index;

            if (index < 0) {
                input.removeAttribute('aria-activedescendant');
                return;
            }

            input.setAttribute('aria-activedescendant', options[index].id);
            options[index].scrollIntoView({ block: 'nearest' });
        };

        const select = (index) => {
            const item = items[index];

            if (!item) {
                return;
            }

            input.value = item.display ?? item.label;
            committed = true;
            close();
            announce('', true);
            onSelect(item);
        };

        // Typed text that identifies exactly one entry counts as choosing it; anything else stays unselected.
        const commitTyped = async () => {
            const query = input.value.trim();

            if (!findTyped || committed || query === '') {
                return;
            }

            const item = await findTyped(query).catch(() => null);

            if (item && !committed && input.value.trim() === query) {
                clearTimeout(debounce);
                requestId++;
                input.value = item.label;
                committed = true;
                close();
                announce('', true);
                onSelect(item, { typed: true });
            }
        };

        const render = (results) => {
            items = results;
            listbox.replaceChildren(...results.map((item, i) => {
                const option = document.createElement('li');
                option.id = `${listbox.id}-option-${i}`;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                option.className = 'combobox-option';
                const label = document.createElement('span');
                label.textContent = item.display ?? item.label;
                option.append(label);
                const detail = describe?.(item);

                if (detail) {
                    const badge = document.createElement('span');
                    badge.className = 'combobox-option-kind';
                    badge.textContent = detail;
                    option.append(badge);
                }

                option.addEventListener('mousedown', (event) => event.preventDefault());
                option.addEventListener('click', () => select(i));

                return option;
            }));

            listbox.hidden = results.length === 0;
            input.setAttribute('aria-expanded', String(results.length > 0));
            activeIndex = -1;
            input.removeAttribute('aria-activedescendant');

            if (results.length === 0) {
                announce(emptyMessage, true);
            } else {
                announce(`${results.length} sonuç bulundu. Seçmek için ok tuşlarını kullanın.`, false);
            }
        };

        const runSearch = async () => {
            const current = ++requestId;
            const query = input.value.trim();

            if (query.length < minLength) {
                render([]);
                announce(`Aramak için en az ${minLength} karakter yazın.`, true);
                return;
            }

            announce('Aranıyor…', true);

            try {
                const results = await search(query);

                if (current === requestId) {
                    render(results);
                }
            } catch {
                if (current === requestId) {
                    render([]);
                    announce('Konum araması şu anda yapılamıyor. Lütfen biraz sonra yeniden deneyin.', true);
                }
            }
        };

        input.addEventListener('input', () => {
            committed = false;
            onClear();
            clearTimeout(debounce);
            debounce = setTimeout(runSearch, 200);
        });

        if (openOnFocus) {
            input.addEventListener('focus', () => {
                if (listbox.hidden && !input.disabled) {
                    runSearch();
                }
            });
        }

        input.addEventListener('keydown', (event) => {
            const open = !listbox.hidden;

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                open ? setActive(Math.min(activeIndex + 1, items.length - 1)) : runSearch();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                open && setActive(Math.max(activeIndex - 1, 0));
            } else if (event.key === 'Enter' && (open || (!committed && input.value.trim() !== ''))) {
                // Enter while choosing never submits the half-filled form; a lone suggestion is taken as the choice.
                event.preventDefault();
                if (open && (activeIndex >= 0 || items.length === 1)) {
                    select(Math.max(activeIndex, 0));
                } else {
                    commitTyped();
                }
            } else if (event.key === 'Tab') {
                commitTyped();
            } else if (event.key === 'Escape') {
                if (open) {
                    event.preventDefault();
                    close();
                } else if (input.value !== '') {
                    input.value = '';
                    onClear();
                }
            }
        });

        input.addEventListener('blur', () => {
            close();
            commitTyped();
        });

        return {
            clear: () => {
                clearTimeout(debounce);
                requestId++;
                committed = false;
                input.value = '';
                close();
                announce('', true);
            },
            // A selection restored from the catalog is already chosen; leaving the field must not choose it again.
            restore: (label) => {
                input.value = label;
                committed = true;
            },
        };
    };

    /*
     * Flight entries (OD-022 v3.8) carry an opaque server-issued selection token; the IATA code shown is the one
     * in the server result. The form submits only that token, from which the server derives kind, value, timezone
     * and aviation identity. No code is typed or built here.
     */
    const isFlightEntry = (item) => typeof item?.label === 'string' && item.label !== ''
        && Object.hasOwn(kindLabels, item.kind) && typeof item.iana_timezone === 'string'
        && /^[A-Z]{3}$/.test(item.aviation_identity?.code ?? '') && typeof item.selection === 'string' && item.selection !== '';
    const flightLabel = (item) => `${item.label} (${item.aviation_identity.code})`;

    const mountLocationPicker = (group, catalog) => {
        const countryInput = group.querySelector('[data-combobox="country"] input');
        const locationInput = group.querySelector('[data-combobox="location"] input');
        const summary = group.querySelector('.selection-summary');
        const status = group.querySelector('[data-combobox-status]');
        const fields = Object.fromEntries(
            [...group.querySelectorAll('[data-location-field]')].map((field) => [field.dataset.locationField, field]),
        );
        const hints = Object.fromEntries([...group.querySelectorAll('[data-combobox]')].map((box) => {
            const hint = document.createElement('p');
            hint.className = 'combobox-hint';
            hint.hidden = true;
            box.append(hint);

            return [box.dataset.combobox, hint];
        }));
        const announcer = (kind) => (message, visible) => {
            status.textContent = message;
            hints[kind].textContent = visible ? message : '';
            hints[kind].hidden = !visible || message === '';
        };
        let countryCode = null;

        const clearLocation = () => {
            Object.values(fields).forEach((field) => {
                field.value = '';
            });
            summary.textContent = '';
        };

        if (!catalog) {
            countryInput.disabled = true;
            locationInput.disabled = true;
            return;
        }

        const setLocation = (location) => {
            clearLocation();
            fields.kind.value = location.kind;
            fields.iana_timezone.value = location.iana_timezone;

            if (catalog.flight) {
                fields.selection.value = location.selection;
                summary.textContent = `Seçildi: ${flightLabel(location)} (${kindLabels[location.kind]})`;
            } else {
                fields.value.value = location.id;
                summary.textContent = `Seçildi: ${location.label} (${kindLabels[location.kind]})`;
            }
        };

        const setCountry = (country) => {
            countryCode = country?.code ?? null;
            locationInput.disabled = countryCode === null || !catalog.searchable;
            locationInput.placeholder = !catalog.searchable
                ? 'Konum araması şu anda kullanılamıyor'
                : (countryCode === null ? 'Önce ülke seçin' : (catalog.flight ? 'Havalimanı veya şehir arayın' : 'Şehir, havaalanı veya istasyon arayın'));
        };

        const location = createCombobox({
            input: locationInput,
            listbox: group.querySelector('[data-combobox="location"] [role="listbox"]'),
            announce: announcer('location'),
            minLength: MIN_QUERY_LENGTH,
            // The flight catalog is English: Turkish exonyms such as "Londra" have no entry there.
            emptyMessage: catalog.flight ? 'Sonuç bulunamadı. Şehrin İngilizce adını veya havalimanı kodunu deneyin.' : undefined,
            search: async (query) => {
                const results = countryCode === null ? [] : await catalog.searchLocations(countryCode, query);

                // Only canonical entries of an allowed kind that carry a timezone (and, for flights, a token) are selectable.
                return catalog.flight
                    ? results.filter(isFlightEntry).map((item) => ({ ...item, display: flightLabel(item) }))
                    : results.filter((item) => item?.id && item?.label && item?.iana_timezone && Object.hasOwn(kindLabels, item.kind));
            },
            describe: (item) => kindLabels[item.kind],
            onSelect: setLocation,
            onClear: clearLocation,
        });

        const resetLocation = (country) => {
            location.clear();
            clearLocation();
            setCountry(country);
        };

        const country = createCombobox({
            input: countryInput,
            listbox: group.querySelector('[data-combobox="country"] [role="listbox"]'),
            announce: announcer('country'),
            openOnFocus: true,
            search: async (query) => (await catalog.searchCountries(query)).filter((item) => item?.code && item?.label),
            findTyped: catalog.findCountry,
            onSelect: (country, { typed = false } = {}) => {
                resetLocation(country);
                // Leaving the field with a typed country keeps focus wherever the user moved it.
                if (!typed && !locationInput.disabled) {
                    locationInput.focus();
                }
            },
            onClear: () => resetLocation(null),
        });

        countryInput.disabled = false;
        setCountry(null);

        const stored = catalog.flight ? fields.selection.value : (fields.kind.value && fields.value.value);

        if (!stored) {
            clearLocation();
            return;
        }

        // Restore a previous selection (e.g. after a validation error) from the canonical catalog only.
        Promise.resolve(catalog.resolve(catalog.flight ? fields.selection.value : fields.kind.value, fields.value.value))
            .then((resolved) => {
                if (!resolved || (catalog.flight && !isFlightEntry(resolved.location))) {
                    clearLocation();
                    return;
                }

                country.restore(resolved.country.label);
                setCountry(resolved.country);
                location.restore(catalog.flight ? flightLabel(resolved.location) : resolved.location.label);
                setLocation(resolved.location);
            })
            .catch(clearLocation);
    };

    // Same-origin application endpoints only; the browser never calls a location or aviation provider directly.
    const createCatalog = ({ flightLocationSearchUrl, locationCountriesUrl, locationSearchUrl, locationResolveUrl }) => {
        let countries;
        const flight = Boolean(flightLocationSearchUrl);
        const getJson = async (url) => {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error('catalog');
            }

            return response.json();
        };
        const withQuery = (url, params) => `${url}${url.includes('?') ? '&' : '?'}${new URLSearchParams(params)}`;
        const loadCountries = () => {
            countries ??= getJson(locationCountriesUrl).then((body) => body.countries).catch((error) => {
                countries = undefined;
                throw error;
            });

            return countries;
        };
        // Turkish-aware and accent-insensitive, so "turkiye", "irak" and "Turkiye" all find their country.
        const normalize = (text) => text.toLocaleLowerCase('tr').normalize('NFD').replace(/\p{M}/gu, '').replace(/ı/g, 'i');
        // Everyday Turkish names that the official labels ("Birleşik Krallık", "Amerika Birleşik Devletleri") do not contain.
        const countryAliases = { GB: 'ingiltere', US: 'abd' };
        const countryNames = (country) => [country.label, countryAliases[country.code] ?? ''].map(normalize);
        const searchCountries = async (query) => (await loadCountries())
            .filter((country) => countryNames(country).some((name) => name.includes(normalize(query))));
        const countryByCode = async (code) => (await loadCountries()).find((item) => item.code === code);

        return {
            flight,
            searchable: flight || Boolean(locationSearchUrl),
            searchCountries,
            // An exact name wins ("Nijer" over "Nijerya"); otherwise the text must match a single country.
            findCountry: async (query) => {
                const matches = await searchCountries(query);

                return matches.find((country) => countryNames(country).includes(normalize(query))) ?? (matches.length === 1 ? matches[0] : null);
            },
            // Minimum query length and result limit are enforced server-side from config.
            searchLocations: async (countryCode, query) => (await getJson(
                withQuery(flight ? flightLocationSearchUrl : locationSearchUrl, { ulke: countryCode, q: query }),
            )).locations ?? [],
            resolve: async (kindOrToken, id) => {
                if (flight) {
                    const location = (await getJson(withQuery(locationResolveUrl, { secim: kindOrToken }))).location;
                    const country = await countryByCode(location?.country_code);

                    return country ? { country, location } : null;
                }
                const location = (await getJson(withQuery(locationResolveUrl, { kimlik: id }))).location;
                const country = await countryByCode(location?.country_code);

                return location?.kind === kindOrToken && country ? { country, location } : null;
            },
        };
    };

    const mountLocationPickers = (catalog) => {
        document.querySelectorAll('[data-location-picker]').forEach((group) => mountLocationPicker(group, catalog));

        if (catalogStatus) {
            catalogStatus.hidden = Boolean(catalog?.searchable);
        }
    };

    const catalogUrls = routeFields?.dataset ?? {};
    window.TripPlannerLocations = { mount: mountLocationPickers };
    mountLocationPickers(catalogUrls.locationCountriesUrl ? createCatalog(catalogUrls) : null);

    document.querySelector('[data-error-summary]')?.focus();
})();
