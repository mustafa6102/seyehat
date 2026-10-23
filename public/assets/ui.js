/*
 * Ortak arayüz davranışları (tasarım sistemi): mobil gezinme, sekmeler, açılır menü, pencere/çekmece, ipucu.
 * Yalnız sunum durumu yönetir; veri çekmez, form değeri üretmez. Betik yüklenmezse içerik erişilebilir kalır.
 */
(() => {
    const on = (node, type, listener) => node && node.addEventListener && node.addEventListener(type, listener);
    const all = (root, selector) => [...root.querySelectorAll(selector)];

    /* Mobil gezinme: düğme yalnız betik hazırken görünür; Escape kapatır ve odağı düğmeye döndürür. */
    const header = document.querySelector('[data-app-header]');
    const navToggle = header?.querySelector('[data-nav-toggle]');

    if (header && navToggle) {
        const setOpen = (open) => {
            if (open) {
                header.dataset.navOpen = '';
            } else {
                delete header.dataset.navOpen;
            }
            navToggle.setAttribute('aria-expanded', String(open));
        };

        header.dataset.navReady = '';
        navToggle.hidden = false;
        setOpen(false);
        on(navToggle, 'click', () => setOpen(!('navOpen' in header.dataset)));
        on(header, 'keydown', (event) => {
            if (event.key === 'Escape' && 'navOpen' in header.dataset) {
                setOpen(false);
                navToggle.focus();
            }
        });
    }

    /* Sekmeler: tek sekme odak sırasındadır; ok tuşları, Home ve End sekmeler arasında dolaşır. */
    all(document, '[data-tabs]').forEach((tabs) => {
        const tabList = all(tabs, '[role="tab"]');
        const enabled = () => tabList.filter((tab) => !tab.disabled);
        const select = (selected, focus = false) => {
            tabList.forEach((tab) => {
                const active = tab === selected;
                const panel = tabs.querySelector(`[id="${tab.getAttribute('aria-controls')}"]`);

                tab.setAttribute('aria-selected', String(active));
                tab.tabIndex = active ? 0 : -1;
                if (panel) {
                    panel.hidden = !active;
                }
            });
            if (focus) {
                selected.focus();
            }
        };

        tabList.forEach((tab) => {
            on(tab, 'click', () => select(tab));
            on(tab, 'keydown', (event) => {
                const list = enabled();
                const index = list.indexOf(tab);
                const target = {
                    ArrowRight: list[(index + 1) % list.length],
                    ArrowLeft: list[(index - 1 + list.length) % list.length],
                    Home: list[0],
                    End: list[list.length - 1],
                }[event.key];

                if (target) {
                    event.preventDefault();
                    select(target, true);
                }
            });
        });

        const initial = tabList.find((tab) => tab.getAttribute('aria-selected') === 'true') ?? enabled()[0];
        if (initial) {
            select(initial);
        }
    });

    /* Açılır menü: düğme açar/kapatır; ok tuşları öğeler arasında dolaşır; Escape kapatıp odağı düğmeye döndürür. */
    const dropdowns = all(document, '[data-dropdown]').map((dropdown) => {
        const toggle = dropdown.querySelector('[data-dropdown-toggle]');
        const menu = dropdown.querySelector('[data-dropdown-menu]');
        const items = () => all(menu, '[data-menu-item]').filter((item) => !item.disabled);
        const setOpen = (open) => {
            menu.hidden = !open;
            toggle.setAttribute('aria-expanded', String(open));
        };

        setOpen(false);
        on(toggle, 'click', () => {
            const open = menu.hidden;

            setOpen(open);
            if (open) {
                items()[0]?.focus();
            }
        });
        on(dropdown, 'keydown', (event) => {
            if (menu.hidden) {
                return;
            }
            if (event.key === 'Escape') {
                setOpen(false);
                toggle.focus();

                return;
            }
            const list = items();
            const index = list.indexOf(event.target);
            const target = {
                ArrowDown: list[(index + 1) % list.length],
                ArrowUp: list[(index - 1 + list.length) % list.length],
                Home: list[0],
                End: list[list.length - 1],
            }[event.key];

            if (target) {
                event.preventDefault();
                target.focus();
            }
        });
        on(menu, 'click', (event) => {
            if (event.target !== menu) {
                setOpen(false);
                toggle.focus();
            }
        });

        return { dropdown, setOpen };
    });

    on(document, 'click', (event) => {
        dropdowns.forEach(({ dropdown, setOpen }) => {
            if (!dropdown.contains(event.target)) {
                setOpen(false);
            }
        });
    });

    /* Pencere ve çekmece: yerel <dialog>; odak tuzağı ve Escape tarayıcıdan gelir. Arka plana tıklamak kapatır. */
    all(document, '[data-dialog-open]').forEach((opener) => {
        on(opener, 'click', () => {
            const dialog = document.querySelector(`[id="${opener.dataset.dialogOpen}"]`);

            if (dialog?.showModal) {
                dialog.showModal();
            }
        });
    });
    all(document, 'dialog').forEach((dialog) => {
        all(dialog, '[data-dialog-close]').forEach((closer) => on(closer, 'click', () => dialog.close()));
        on(dialog, 'click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    /* İpucu: fareyle veya odakla görünür; Escape, odak başka yere gidene kadar gizler (WCAG 1.4.13). */
    all(document, '[data-tooltip-host]').forEach((host) => {
        const restore = () => {
            delete host.dataset.tooltipDismissed;
        };

        on(host, 'keydown', (event) => {
            if (event.key === 'Escape') {
                host.dataset.tooltipDismissed = '';
            }
        });
        on(host, 'focusout', restore);
        on(host, 'mouseleave', restore);
    });
})();
