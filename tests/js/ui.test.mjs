// Shared design-system behaviour of public/assets/ui.js: mobile navigation, tabs, dropdown menu, dialog, tooltip.
// Markup mirrors resources/views/layouts/app.blade.php and resources/views/components/ui/*.blade.php.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { FakeElement, loadScript } from './fake-dom.mjs';

const node = (tag, props = {}, children = []) => {
    const { dataset = {}, attrs = {}, ...rest } = props;
    const element = Object.assign(new FakeElement(tag), rest);
    element.dataset = { ...dataset };
    Object.entries(attrs).forEach(([name, value]) => element.setAttribute(name, value));
    element.append(...children);

    return element;
};
const focused = (element) => {
    let count = 0;
    element.addEventListener('focus', () => {
        count += 1;
    });

    return () => count;
};
const load = (page) => loadScript('public/assets/ui.js', {}, () => ({ status: 204 }), {}, page);

test('mobile navigation starts collapsed, toggles with the button and closes on Escape with focus restored', () => {
    const toggle = node('button', { hidden: true, dataset: { navToggle: '' }, attrs: { 'aria-expanded': 'false' } });
    const header = node('header', { dataset: { appHeader: '' } }, [toggle, node('nav')]);
    const toggleFocus = focused(toggle);
    load(header);

    assert.equal(toggle.hidden, false);
    assert.ok('navReady' in header.dataset);
    assert.ok(!('navOpen' in header.dataset));
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');

    toggle.dispatch('click');
    assert.ok('navOpen' in header.dataset);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');

    header.dispatch('keydown', { key: 'Escape' });
    assert.ok(!('navOpen' in header.dataset));
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(toggleFocus(), 1);
});

test('tabs show only the selected panel and move with arrow keys, skipping disabled tabs', () => {
    const tab = (id, selected = false, disabled = false) => node('button', { disabled, attrs: { role: 'tab', id: `tab-${id}`, 'aria-controls': `panel-${id}`, 'aria-selected': String(selected) } });
    const panel = (id) => node('div', { attrs: { role: 'tabpanel', id: `panel-${id}` } });
    const tabs = [tab('a', true), tab('b'), tab('c', false, true)];
    const panels = [panel('a'), panel('b'), panel('c')];
    const lastFocus = focused(tabs[1]);
    load(node('div', { dataset: { tabs: '' } }, [...tabs, ...panels]));

    assert.deepEqual(panels.map((item) => item.hidden), [false, true, true]);
    assert.deepEqual(tabs.map((item) => item.tabIndex), [0, -1, -1]);

    const event = tabs[0].dispatch('keydown', { key: 'ArrowRight' });
    assert.equal(event.defaultPrevented, true);
    assert.deepEqual(tabs.map((item) => item.getAttribute('aria-selected')), ['false', 'true', 'false']);
    assert.deepEqual(panels.map((item) => item.hidden), [true, false, true]);
    assert.equal(lastFocus(), 1);

    // The disabled third tab is skipped: ArrowRight from the last enabled tab wraps to the first.
    tabs[1].dispatch('keydown', { key: 'ArrowRight' });
    assert.equal(tabs[0].getAttribute('aria-selected'), 'true');

    tabs[1].dispatch('click');
    assert.deepEqual(panels.map((item) => item.hidden), [true, false, true]);
});

test('dropdown opens onto its first enabled item, moves with arrows and closes on Escape with focus restored', () => {
    const item = (disabled = false) => node('button', { disabled, dataset: { menuItem: '' } });
    const items = [item(true), item(), item()];
    const menu = node('div', { hidden: true, dataset: { dropdownMenu: '' } }, items);
    const toggle = node('button', { dataset: { dropdownToggle: '' } });
    const dropdown = node('div', { dataset: { dropdown: '' } }, [toggle, menu]);
    const firstFocus = focused(items[1]);
    const secondFocus = focused(items[2]);
    const toggleFocus = focused(toggle);
    load(dropdown);

    assert.equal(menu.hidden, true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');

    toggle.dispatch('click');
    assert.equal(menu.hidden, false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(firstFocus(), 1);

    dropdown.dispatch('keydown', { key: 'ArrowDown', target: items[1] });
    assert.equal(secondFocus(), 1);

    dropdown.dispatch('keydown', { key: 'Escape', target: items[2] });
    assert.equal(menu.hidden, true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(toggleFocus(), 1);
});

test('dialog opens from its trigger and closes from a close control or a backdrop click only', () => {
    const calls = [];
    const closer = node('button', { dataset: { dialogClose: '' } });
    const body = node('div', {}, [closer]);
    const dialog = Object.assign(node('dialog', { attrs: { id: 'filters' } }, [body]), {
        showModal: () => calls.push('open'),
        close: () => calls.push('close'),
    });
    const opener = node('button', { dataset: { dialogOpen: 'filters' } });
    load(node('div', {}, [opener, dialog]));

    opener.dispatch('click');
    assert.deepEqual(calls, ['open']);

    dialog.dispatch('click', { target: body });
    assert.deepEqual(calls, ['open']);

    dialog.dispatch('click');
    closer.dispatch('click');
    assert.deepEqual(calls, ['open', 'close', 'close']);
});

test('tooltip is dismissed by Escape and restored once focus or the pointer leaves', () => {
    const host = node('span', { dataset: { tooltipHost: '' } });
    load(host);

    host.dispatch('keydown', { key: 'Enter' });
    assert.ok(!('tooltipDismissed' in host.dataset));

    host.dispatch('keydown', { key: 'Escape' });
    assert.ok('tooltipDismissed' in host.dataset);

    host.dispatch('focusout');
    assert.ok(!('tooltipDismissed' in host.dataset));
});
