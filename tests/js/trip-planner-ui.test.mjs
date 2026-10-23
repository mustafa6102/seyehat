// TRIP-001 planner form behaviour of public/assets/trip-planner.js (journey/date disclosure, passenger steppers,
// country-first location selection). Markup mirrors resources/views/trip-planner.blade.php and its partials.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { FakeElement, loadScript, settle } from './fake-dom.mjs';

const node = (tag, props = {}, children = []) => {
    const { dataset = {}, attrs = {}, ...rest } = props;
    const element = Object.assign(new FakeElement(tag), rest);
    element.dataset = { ...dataset };
    Object.entries(attrs).forEach(([name, value]) => element.setAttribute(name, value));
    element.append(...children);

    return element;
};
const radio = (name, value, checked = false) => node('input', { type: 'radio', name, value, checked });
const dateInput = (name) => node('input', { type: 'date', name });
const travelDate = (prefix, disabled = false) => node('fieldset', { className: 'travel-date', dataset: { travelDate: prefix }, disabled }, [
    radio(`${prefix}_date_mode`, 'exact', true),
    radio(`${prefix}_date_mode`, 'flexible'),
    node('div', { dataset: { dateFields: 'exact' } }, [dateInput(`${prefix}_exact_date`)]),
    node('div', { dataset: { dateFields: 'flexible' }, hidden: true }, [dateInput(`${prefix}_start_date`), dateInput(`${prefix}_end_date`)]),
]);
const stepper = (name, label, value) => node('div', { dataset: { stepper: '' } }, [
    node('button', { type: 'button', hidden: true, dataset: { step: '-1' }, attrs: { 'aria-label': `${label} sayısını azalt` } }),
    node('input', { type: 'number', name, value: String(value) }),
    node('button', { type: 'button', hidden: true, dataset: { step: '1' }, attrs: { 'aria-label': `${label} sayısını artır` } }),
]);
const picker = (prefix) => node('fieldset', { dataset: { locationPicker: prefix } }, [
    node('div', { dataset: { combobox: 'country' } }, [
        node('input', { id: `${prefix}-country`, disabled: true }),
        node('ul', { id: `${prefix}-country-listbox`, hidden: true, attrs: { role: 'listbox' } }),
    ]),
    node('div', { dataset: { combobox: 'location' } }, [
        node('input', { id: `${prefix}-location`, disabled: true }),
        node('ul', { id: `${prefix}-location-listbox`, hidden: true, attrs: { role: 'listbox' } }),
    ]),
    node('p', { className: 'selection-summary' }),
    node('p', { dataset: { comboboxStatus: '' } }),
    ...['kind', 'value', 'iana_timezone', 'selection'].map((field) => node('input', { type: 'hidden', name: `${prefix}_${field}`, dataset: { locationField: field } })),
]);

const COUNTRIES = { countries: [{ code: 'TR', label: 'Türkiye' }, { code: 'NL', label: 'Hollanda' }] };
const IST = {
    label: 'İstanbul Havalimanı', kind: 'airport', iana_timezone: 'Europe/Istanbul', country_code: 'TR',
    aviation_identity: { type: 'IATA_AIRPORT', code: 'IST' }, selection: 'opaque-token-1',
};

const planner = ({ adults = 1, children = 0, infants = 0 } = {}) => {
    const root = node('form', {}, [
        node('div', { dataset: {
            locationKinds: JSON.stringify({ city: 'Şehir', airport: 'Havaalanı' }),
            locationCountriesUrl: '/konumlar/ulkeler',
            locationResolveUrl: '/konumlar/cozumle',
            flightLocationSearchUrl: '/konumlar/ara?baglam=ucus',
        } }, [picker('origin'), picker('destination')]),
        node('fieldset', { dataset: { journey: '' } }, [
            radio('journey_type', 'ONE_WAY'),
            radio('journey_type', 'ROUND_TRIP'),
            travelDate('outbound'),
            node('div', { dataset: { returnDate: '' }, hidden: true }, [travelDate('return', true)]),
        ]),
        node('fieldset', { dataset: { passengers: '' } }, [
            stepper('adults', 'Yetişkin', adults),
            stepper('children', 'Çocuk', children),
            stepper('infants', 'Bebek', infants),
            node('strong', { dataset: { passengerTotal: '' } }),
            node('input', { type: 'hidden', name: 'traveler_count', value: '1' }),
        ]),
    ]);
    const { requests } = loadScript('public/assets/trip-planner.js', {}, (url) => {
        if (url.startsWith('/konumlar/ulkeler')) {
            return { status: 200, body: COUNTRIES };
        }
        if (url.startsWith('/konumlar/ara')) {
            // The second entry lacks a server-issued token and must never be selectable.
            return { status: 200, body: { locations: [IST, { ...IST, label: 'Tokensiz kayıt', selection: '' }] } };
        }

        return { status: 404, body: {} };
    }, {}, root);
    const $ = (selector) => root.querySelector(selector);

    return { root, $, requests };
};

const choose = (root, name, value) => {
    root.querySelectorAll(`input[name="${name}"]`).forEach((input) => {
        input.checked = input.value === value;
    });
    root.querySelector(`input[name="${name}"][value="${value}"]`).dispatch('change');
};
const fields = (group, direction) => ({
    exact: group.querySelector('[data-date-fields="exact"]'),
    flexible: group.querySelector('[data-date-fields="flexible"]'),
    exactInput: group.querySelector(`input[name="${direction}_exact_date"]`),
    startInput: group.querySelector(`input[name="${direction}_start_date"]`),
    endInput: group.querySelector(`input[name="${direction}_end_date"]`),
});

test('one-way exact shows only the outbound exact date; return controls stay hidden and disabled', () => {
    const { root, $ } = planner();
    choose(root, 'journey_type', 'ONE_WAY');

    const outbound = fields($('[data-travel-date="outbound"]'), 'outbound');
    assert.equal(outbound.exact.hidden, false);
    assert.equal(outbound.exactInput.disabled, false);
    assert.equal(outbound.flexible.hidden, true);
    assert.equal(outbound.startInput.disabled, true);
    assert.equal(outbound.endInput.disabled, true);
    assert.equal($('[data-return-date]').hidden, true);
    assert.equal($('[data-travel-date="return"]').disabled, true);
});

test('round trip reveals the return date next to the outbound date and one-way hides it again', () => {
    const { root, $ } = planner();
    // Nothing is pre-selected by the script.
    assert.deepEqual(root.querySelectorAll('input[name="journey_type"]:checked'), []);

    choose(root, 'journey_type', 'ROUND_TRIP');
    assert.equal($('[data-return-date]').hidden, false);
    assert.equal($('[data-travel-date="return"]').disabled, false);
    const back = fields($('[data-travel-date="return"]'), 'return');
    assert.equal(back.exact.hidden, false);
    assert.equal(back.exactInput.disabled, false);
    assert.equal(back.flexible.hidden, true);

    choose(root, 'journey_type', 'ONE_WAY');
    assert.equal($('[data-return-date]').hidden, true);
    assert.equal($('[data-travel-date="return"]').disabled, true);
    // The script only toggles visibility; submitted values are never assigned.
    assert.equal($('input[name="journey_type"][value="ONE_WAY"]').checked, true);
    assert.equal(back.exactInput.value, '');
});

test('flexible dates reveal only the range fields and exact dates only the single date field', () => {
    const { root, $ } = planner();
    const outbound = fields($('[data-travel-date="outbound"]'), 'outbound');

    choose(root, 'outbound_date_mode', 'flexible');
    assert.equal(outbound.exact.hidden, true);
    assert.equal(outbound.exactInput.disabled, true);
    assert.equal(outbound.flexible.hidden, false);
    assert.equal(outbound.startInput.disabled, false);
    assert.equal(outbound.endInput.disabled, false);
    // Directions are independent: the return date keeps its own mode.
    assert.equal(fields($('[data-travel-date="return"]'), 'return').flexible.hidden, true);

    choose(root, 'outbound_date_mode', 'exact');
    assert.equal(outbound.exact.hidden, false);
    assert.equal(outbound.flexible.hidden, true);
    assert.equal(outbound.startInput.disabled, true);
});

test('passenger steppers keep counts non-negative, never leave zero passengers and mirror the total', async () => {
    const { $ } = planner();
    const input = (name) => $(`input[name="${name}"]`);
    const button = (name, step) => input(name).parent.querySelector(`[data-step="${step}"]`);
    const snapshot = () => ({
        adults: input('adults').value, children: input('children').value, infants: input('infants').value,
        total: $('[data-passenger-total]').textContent, travelerCount: input('traveler_count').value,
    });

    // Native buttons with Turkish labels, revealed by the script (keyboard activation = click).
    for (const name of ['adults', 'children', 'infants']) {
        for (const step of ['-1', '1']) {
            assert.equal(button(name, step).hidden, false);
            assert.equal(button(name, step).tagName, 'button');
            assert.equal(button(name, step).type, 'button');
        }
    }
    assert.equal(button('adults', '1').attributes['aria-label'], 'Yetişkin sayısını artır');
    assert.deepEqual(snapshot(), { adults: '1', children: '0', infants: '0', total: '1', travelerCount: '1' });
    assert.equal(button('adults', '-1').attributes['aria-disabled'], 'true');
    assert.equal(button('children', '-1').attributes['aria-disabled'], 'true');
    assert.equal(button('children', '1').attributes['aria-disabled'], 'false');

    await button('adults', '-1').click();
    assert.equal(input('adults').value, '1', 'the last passenger cannot be removed');

    await button('adults', '1').click();
    await button('children', '1').click();
    await button('infants', '1').click();
    assert.deepEqual(snapshot(), { adults: '2', children: '1', infants: '1', total: '4', travelerCount: '4' });
    assert.equal(button('adults', '-1').attributes['aria-disabled'], 'false');

    await button('children', '-1').click();
    await button('children', '-1').click();
    assert.equal(input('children').value, '0', 'a count never goes below zero');
    assert.deepEqual(snapshot(), { adults: '2', children: '0', infants: '1', total: '3', travelerCount: '3' });
});

test('typed counts update the total; invalid input is left for the server instead of being guessed', () => {
    const { $ } = planner({ adults: 0, children: 0, infants: 0 });
    const input = (name) => $(`input[name="${name}"]`);
    assert.equal($('[data-passenger-total]').textContent, '0');
    assert.equal(input('traveler_count').value, '0');

    input('children').value = '2';
    input('children').dispatch('input');
    assert.equal($('[data-passenger-total]').textContent, '2');
    assert.equal(input('traveler_count').value, '2');

    input('infants').value = 'iki';
    input('infants').dispatch('input');
    assert.equal($('[data-passenger-total]').textContent, '2');
    assert.equal(input('traveler_count').value, '2', 'traveler_count is not rewritten from an invalid composition');
    assert.equal(input('infants').value, 'iki');
});

test('location is chosen country first with the keyboard and only a server-issued entry fills the hidden fields', async () => {
    const { $, requests } = planner();
    const group = $('[data-location-picker="origin"]');
    const country = group.querySelector('[data-combobox="country"] input');
    const location = group.querySelector('[data-combobox="location"] input');
    const hidden = (field) => group.querySelector(`[data-location-field="${field}"]`).value;

    assert.equal(country.disabled, false);
    assert.equal(location.disabled, true, 'location search waits for a country');

    country.focus();
    await settle();
    assert.equal(group.querySelector('[data-combobox="country"] [role="listbox"]').querySelectorAll('[role="option"]').length, 2);
    assert.equal(country.dispatch('keydown', { key: 'ArrowDown' }).defaultPrevented, true);
    country.dispatch('keydown', { key: 'Enter' });
    assert.equal(country.value, 'Türkiye');
    assert.equal(location.disabled, false);

    location.value = 'ist';
    location.dispatch('input');
    await new Promise((resolve) => setTimeout(resolve, 260));
    await settle();
    const options = group.querySelector('[data-combobox="location"] [role="listbox"]').querySelectorAll('[role="option"]');
    assert.equal(options.length, 1, 'an entry without a selection token is not offered');
    location.dispatch('keydown', { key: 'ArrowDown' });
    assert.equal(location.attributes['aria-activedescendant'], options[0].id);
    location.dispatch('keydown', { key: 'Enter' });

    assert.equal(location.value, 'İstanbul Havalimanı (IST)');
    assert.equal(hidden('selection'), 'opaque-token-1');
    assert.equal(hidden('kind'), 'airport');
    assert.equal(hidden('iana_timezone'), 'Europe/Istanbul');
    assert.equal(hidden('value'), '');
    assert.equal(group.querySelector('.selection-summary').textContent, 'Seçildi: İstanbul Havalimanı (IST) (Havaalanı)');

    // Typing free text afterwards clears the canonical selection instead of submitting the text.
    location.value = 'Serbest metin';
    location.dispatch('input');
    assert.equal(hidden('selection'), '');
    assert.equal(hidden('kind'), '');
    assert.equal(group.querySelector('.selection-summary').textContent, '');

    // Only same-origin application endpoints are called.
    assert.ok(requests.every((request) => request.url.startsWith('/konumlar/')));
});
