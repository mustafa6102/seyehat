/**
 * EVT-001 v3.22: event discovery, detail and add-to-plan for the trip-plan page.
 * Mirrors poi.js structure. API key is server-side only and never appears here.
 * Uses the web-api (session + CSRF) channel per OD-028.
 */
(function () {
  'use strict';

  const POLL_DEFAULT_MS = 3000;
  const SESSION_KEY = 'evt_search_state';

  function init() {
    const root = document.querySelector('[data-event]');
    if (!root) return;

    const btn = root.querySelector('[data-event-search]');
    const refreshBtn = root.querySelector('[data-event-refresh]');
    const statusEl = root.querySelector('[data-event-status]');
    const resultsEl = root.querySelector('[data-event-results]');

    if (btn) btn.addEventListener('click', () => start(root, statusEl, resultsEl, btn, refreshBtn));
    if (refreshBtn) refreshBtn.addEventListener('click', () => resume(root, statusEl, resultsEl, btn, refreshBtn));

    tryResume(root, statusEl, resultsEl, btn, refreshBtn);
  }

  function tryResume(root, statusEl, resultsEl, btn, refreshBtn) {
    try {
      const saved = sessionStorage.getItem(SESSION_KEY + ':' + root.dataset.tripRequestId);
      if (!saved) return;
      const state = JSON.parse(saved);
      if (state.tripRequestId !== root.dataset.tripRequestId) return;
      if (state.status === 'COMPLETED' && Array.isArray(state.events)) {
        renderResults(root, resultsEl, state.events);
        if (refreshBtn) refreshBtn.hidden = false;
        return;
      }
      if (state.searchId && (state.status === 'QUEUED' || state.status === 'RUNNING')) {
        setStatus(statusEl, 'Önceki arama devam ediyor…');
        if (btn) btn.disabled = true;
        poll(root, state.searchId, statusEl, resultsEl, btn, refreshBtn);
      }
    } catch (_) {}
  }

  function resume(root, statusEl, resultsEl, btn, refreshBtn) {
    clearSession(root);
    start(root, statusEl, resultsEl, btn, refreshBtn);
  }

  function start(root, statusEl, resultsEl, btn, refreshBtn) {
    const tripRequestId = root.dataset.tripRequestId;
    const startUrl = root.dataset.startUrl;
    if (!tripRequestId || !startUrl) return;

    const idempotencyKey = randomKey();
    setStatus(statusEl, 'Etkinlikler aranıyor…');
    if (btn) btn.disabled = true;
    if (refreshBtn) refreshBtn.hidden = true;
    clearResults(resultsEl);

    request('POST', startUrl, { trip_request_id: tripRequestId }, idempotencyKey)
      .then(data => {
        saveSession(root, { tripRequestId, searchId: data.search_id, status: data.status });
        poll(root, data.search_id, statusEl, resultsEl, btn, refreshBtn);
      })
      .catch(err => {
        setStatus(statusEl, (err && err.message_tr) || 'Etkinlik araması başlatılamadı. Lütfen yeniden deneyin.');
        if (btn) btn.disabled = false;
        if (refreshBtn) refreshBtn.hidden = false;
      });
  }

  function poll(root, searchId, statusEl, resultsEl, btn, refreshBtn) {
    const pollUrl = root.dataset.pollUrl.replace('__SEARCH__', encodeURIComponent(searchId));
    const interval = parseInt(root.dataset.pollInterval || POLL_DEFAULT_MS, 10);

    fetch(pollUrl, { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : r.json().then(b => Promise.reject(b)))
      .then(job => {
        const evts = job.result && job.result.events;
        saveSession(root, { tripRequestId: root.dataset.tripRequestId, searchId, status: job.status, events: evts });
        if (job.status === 'COMPLETED') {
          const events = evts || [];
          if (btn) btn.disabled = false;
          if (refreshBtn) refreshBtn.hidden = false;
          if (events.length === 0) {
            setStatus(statusEl, 'Bu tarihler için etkinlik bulunamadı.');
          } else {
            setStatus(statusEl, events.length + ' etkinlik bulundu.');
          }
          renderResults(root, resultsEl, events);
        } else if (job.status === 'FAILED') {
          setStatus(statusEl, (job.error && job.error.message_tr) || 'Etkinlik araması başarısız oldu.');
          if (btn) btn.disabled = false;
          if (refreshBtn) refreshBtn.hidden = false;
        } else {
          setTimeout(() => poll(root, searchId, statusEl, resultsEl, btn, refreshBtn), interval);
        }
      })
      .catch(() => {
        setStatus(statusEl, 'Etkinlik arama durumu alınamadı. Yenile düğmesine basın.');
        if (btn) btn.disabled = false;
        if (refreshBtn) refreshBtn.hidden = false;
      });
  }

  function renderResults(root, resultsEl, events) {
    clearResults(resultsEl);
    if (!events || events.length === 0) return;

    const planAccess = root.dataset.planAccess;
    const planAddUrl = root.dataset.planAddUrl;
    const planVersion = root.dataset.planVersion;
    const loginUrl = root.dataset.loginUrl;

    events.forEach(function (event) {
      const card = document.createElement('article');
      card.className = 'result-card';

      const name = document.createElement('h3');
      name.className = 'result-card-title';
      name.textContent = event.name || 'İsimsiz etkinlik';
      card.appendChild(name);

      if (event.venue && (event.venue.name || event.venue.city)) {
        const venue = document.createElement('p');
        venue.className = 'result-card-meta';
        venue.textContent = [event.venue.name, event.venue.city, event.venue.country].filter(Boolean).join(', ');
        card.appendChild(venue);
      }

      var startFact = event.starts_at;
      if (startFact && startFact.value) {
        const dt = document.createElement('p');
        dt.className = 'result-card-meta';
        try {
          const d = new Date(startFact.value);
          const tz = event.iana_timezone || undefined;
          dt.textContent = d.toLocaleString('tr-TR', { timeZone: tz, dateStyle: 'medium', timeStyle: 'short' });
        } catch (_) {
          dt.textContent = startFact.value;
        }
        card.appendChild(dt);
      }

      if (event.categories && event.categories.length > 0) {
        const cats = document.createElement('p');
        cats.className = 'result-card-tags';
        cats.textContent = event.categories.join(' · ');
        card.appendChild(cats);
      }

      var priceFact = event.price_ranges;
      if (priceFact && Array.isArray(priceFact.value) && priceFact.value.length > 0) {
        const r = priceFact.value[0];
        const price = document.createElement('p');
        price.className = 'result-card-meta';
        const minStr = r.min != null ? r.min : '?';
        const maxStr = r.max != null ? '–' + r.max : '';
        price.textContent = 'Tahmini fiyat: ' + minStr + maxStr + ' ' + r.currency;
        card.appendChild(price);
      }

      var linkFact = event.ticket_link;
      if (linkFact && linkFact.value) {
        const a = document.createElement('a');
        a.href = linkFact.value;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.className = 'result-card-link';
        a.textContent = 'Bilet bilgisi (Ticketmaster)';
        card.appendChild(a);
      }

      if (planAccess === 'OWNER' && planAddUrl && event.event_id) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = planAddUrl;
        form.className = 'plan-action-form';
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        addHidden(form, '_token', csrfMeta ? csrfMeta.content : '');
        addHidden(form, 'event_id', event.event_id);
        addHidden(form, 'expected_version', planVersion || '');
        addHidden(form, 'idempotency_key', randomKey());
        const submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.className = 'btn btn-secondary btn-sm';
        submitBtn.textContent = 'Plana ekle';
        form.appendChild(submitBtn);
        card.appendChild(form);
      } else if (planAccess === 'SIGNED_OUT' && loginUrl) {
        const loginA = document.createElement('a');
        loginA.href = loginUrl;
        loginA.className = 'btn btn-secondary btn-sm';
        loginA.textContent = 'Plana eklemek için giriş yap';
        card.appendChild(loginA);
      }

      resultsEl.appendChild(card);
    });
  }

  function addHidden(form, name, value) {
    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = name;
    inp.value = value;
    form.appendChild(inp);
  }

  function request(method, url, body, idempotencyKey) {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    return fetch(url, {
      method: method,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfMeta ? csrfMeta.content : '',
        'Idempotency-Key': idempotencyKey,
      },
      body: JSON.stringify(body),
    }).then(function (r) {
      return r.ok ? r.json() : r.json().then(function (b) { return Promise.reject(b.error || b); });
    });
  }

  function setStatus(el, msg) { if (el) el.textContent = msg; }
  function clearResults(el) { if (el) el.innerHTML = ''; }

  function saveSession(root, state) {
    try { sessionStorage.setItem(SESSION_KEY + ':' + root.dataset.tripRequestId, JSON.stringify(state)); } catch (_) {}
  }

  function clearSession(root) {
    try { sessionStorage.removeItem(SESSION_KEY + ':' + root.dataset.tripRequestId); } catch (_) {}
  }

  function randomKey() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
      return crypto.randomUUID().replace(/-/g, '');
    }
    return Math.random().toString(36).slice(2) + Date.now().toString(36);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
