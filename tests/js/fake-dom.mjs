// Minimal DOM + fetch doubles for running public/assets/*.js under node:test without dependencies.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

export class FakeElement {
    constructor(tagName) {
        this.tagName = tagName;
        this.children = [];
        this.attributes = {};
        this.dataset = {};
        this.listeners = {};
        this.hidden = false;
        this.disabled = false;
        this.checked = false;
        this.value = '';
        this.className = '';
        this.ownText = '';
        this.parent = null;
    }

    get textContent() {
        return this.ownText + this.children.map((child) => child.textContent).join('');
    }

    set textContent(value) {
        this.ownText = String(value);
        this.children = [];
    }

    append(...nodes) {
        nodes.forEach((node) => {
            if (typeof node === 'string') {
                this.children.push(Object.assign(new FakeElement('#text'), { ownText: node, parent: this }));
            } else if (node.tagName === '#fragment') {
                node.children.forEach((child) => {
                    child.parent = this;
                });
                this.children.push(...node.children);
                node.children = [];
            } else {
                node.parent = this;
                this.children.push(node);
            }
        });
    }

    replaceChildren(...nodes) {
        this.children = [];
        this.ownText = '';
        this.append(...nodes);
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    getAttribute(name) {
        return this.attributes[name] ?? null;
    }

    removeAttribute(name) {
        delete this.attributes[name];
    }

    addEventListener(type, listener) {
        (this.listeners[type] ??= []).push(listener);
    }

    async click() {
        for (const listener of this.listeners.click ?? []) {
            await listener({ preventDefault() {} });
        }
    }

    /** Calls the listeners of `type` synchronously; returns the event (e.g. to check defaultPrevented). */
    dispatch(type, init = {}) {
        const event = { type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...init };
        (this.listeners[type] ?? []).forEach((listener) => listener(event));

        return event;
    }

    focus() {
        this.dispatch('focus');
    }

    scrollIntoView() {}

    /** Attribute lookup used by selectors: data-* from dataset, then set attributes, then element properties. */
    attr(name) {
        if (name.startsWith('data-')) {
            return this.dataset[name.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())];
        }
        if (name in this.attributes) {
            return this.attributes[name];
        }

        return this[name] === undefined || this[name] === null ? undefined : String(this[name]);
    }

    /** One compound selector: optional tag, [attr], [attr="value"], .class and :checked. */
    matchesCompound(compound) {
        const tag = /^[a-z]+/.exec(compound)?.[0];
        if (tag && this.tagName !== tag) {
            return false;
        }
        for (const [, name, value] of compound.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)) {
            const actual = this.attr(name);
            if (actual === undefined || (value !== undefined && actual !== value)) {
                return false;
            }
        }
        for (const [, className] of compound.replace(/\[[^\]]*\]/g, '').matchAll(/\.([\w-]+)/g)) {
            if (!this.className.split(' ').includes(className)) {
                return false;
            }
        }

        return !compound.includes(':checked') || this.checked === true;
    }

    /** Every descendant element (depth first), including this one. */
    all() {
        return [this, ...this.children.flatMap((child) => child.all())];
    }

    byClass(className) {
        return this.all().filter((node) => node.className.split(' ').includes(className));
    }

    byTag(tagName) {
        return this.all().filter((node) => node.tagName === tagName);
    }

    /** Descendant selectors made of compounds separated by whitespace (see matchesCompound). */
    querySelectorAll(selector) {
        const parts = selector.trim().split(/\s+/);
        const last = parts.pop();

        return this.all().slice(1).filter((node) => {
            if (node.tagName === '#text' || !node.matchesCompound(last)) {
                return false;
            }
            let ancestor = node.parent;
            for (const part of [...parts].reverse()) {
                while (ancestor && !ancestor.matchesCompound(part)) {
                    ancestor = ancestor.parent;
                }
                if (!ancestor) {
                    return false;
                }
                ancestor = ancestor.parent;
            }

            return true;
        });
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }
}

/**
 * Loads a browser script with the given `[data-*]` elements registered for querySelector and a routed fetch.
 *
 * @param {string} path script path relative to the project root
 * @param {Record<string, FakeElement>} elements selector -> element
 * @param {(url: string, options: object) => {status: number, body?: unknown}} route
 * @param {Record<string, string>} initialStorage sessionStorage entries present before the script runs
 * @param {FakeElement|null} page optional element tree queried by document.querySelector(All)
 * @param {object} windowOverrides extra or replaced window members (e.g. location, crypto)
 */
export const loadScript = (path, elements, route, initialStorage = {}, page = null, windowOverrides = {}) => {
    const requests = [];
    const storage = new Map(Object.entries(initialStorage));
    const lookup = (selector) => elements[selector] ?? null;
    Object.values(elements).forEach((node) => {
        node.querySelector = lookup;
    });
    const tree = new FakeElement('#document');
    if (page) {
        tree.append(page);
    }
    const document = {
        createElement: (tag) => new FakeElement(tag),
        createDocumentFragment: () => new FakeElement('#fragment'),
        querySelector: (selector) => lookup(selector) ?? tree.querySelector(selector),
        querySelectorAll: (selector) => tree.querySelectorAll(selector),
    };
    const window = {
        sessionStorage: {
            getItem: (key) => (storage.has(key) ? storage.get(key) : null),
            setItem: (key, value) => storage.set(key, String(value)),
            removeItem: (key) => storage.delete(key),
        },
        crypto: { randomUUID: () => '00000000-0000-4000-8000-000000000001' },
        history: { back() {} },
        ...windowOverrides,
    };
    const fetch = async (url, options = {}) => {
        requests.push({ url, method: options.method ?? 'GET', headers: options.headers ?? {}, body: options.body ?? null });
        const { status, body } = route(url, options);

        return {
            status,
            ok: status >= 200 && status < 300,
            json: async () => {
                if (body === undefined) {
                    throw new SyntaxError('no body');
                }

                return body;
            },
        };
    };

    vm.runInNewContext(readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8'), { document, window, fetch, setTimeout, clearTimeout, console, URL, URLSearchParams });

    return { requests, storage };
};

export const settle = async () => {
    for (let i = 0; i < 10; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};

export const element = (dataset = {}) => Object.assign(new FakeElement('div'), { dataset });
