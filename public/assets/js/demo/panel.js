// "Under the hood": the demo panel. Loaded with import() only while demo mode is
// on. It reads only the recorder (this browser's own requests, already redacted)
// and, for admins, the System endpoints. It never adds headers to, retries or
// otherwise changes the store's requests.
//
// Three things are kept apart on purpose:
//   Health (probe)  the admin health endpoint's answer, with its age
//   Last observed   what this browser's last stock-related request saw
//   Unknown         no probe, a stale probe, a failed probe, or not an admin

import { h, mount } from '../core/dom.js';
import { recorder, storeEntries } from '../core/recorder.js';
import { onChange, session } from '../core/store.js';
import { fetchHealth, fetchReservations, whileVisible } from '../core/diagnostics.js';
import { healthView, REASON_TEXT } from '../core/health.js';
import { icon } from '../ui/icons.js';

const HEALTH_DOT = { healthy: ['ok', 'Healthy'], unreachable: ['bad', 'Unreachable'], error: ['warn', 'Errors'], unknown: ['unknown', 'Unknown'] };

const ago = (at) => `${Math.max(0, Math.round((Date.now() - at) / 1000))} s ago`;
const isOrderPost = (e) => e.method === 'POST' && e.path === '/api/v1/orders';

/** What the last stock-related request in this browser saw. */
export function lastObserved(entries) {
  const entry = entries.find((e) => isOrderPost(e) || e.stock !== null);
  if (!entry) return null;
  const ORDER_OUTCOME = {
    INSUFFICIENT_STOCK: ['ok', 'Answered: not enough stock'],
    WAREHOUSE_UNAVAILABLE: ['bad', 'Could not connect'],
    WAREHOUSE_TIMEOUT: ['warn', 'No answer in time'],
    ORDER_IN_PROGRESS: ['warn', 'Last attempt still settling'],
  };
  let [tone, label] = ['unknown', 'Unknown'];
  if (isOrderPost(entry)) {
    if (entry.status === 201) [tone, label] = ['ok', 'Answered: reserved'];
    else if (ORDER_OUTCOME[entry.errorType]) [tone, label] = ORDER_OUTCOME[entry.errorType];
  } else {
    [tone, label] = entry.stock === 'live' ? ['ok', 'Answered: live stock'] : ['warn', 'Stock could not be read'];
  }
  return { tone, label, detail: `${entry.method} ${entry.path} → ${entry.status || 'no response'} · ${ago(entry.at)}` };
}

/** The steps of one order attempt, worded as what its HTTP result proves. */
export function flowSteps(entry) {
  const sent = { tone: 'ok', text: 'Cart sent with an Idempotency-Key', note: entry.keyFp ? `key fp ${entry.keyFp}, generated in this browser` : 'no key fingerprint recorded' };
  const outcome = {
    201: [{ tone: 'ok', text: 'Warehouse Reserve accepted', note: 'shown because the response was 201: the order exists, so the reservation succeeded' },
      { tone: 'ok', text: `Order placed · ${entry.ms} ms`, note: 'measured in the browser, request start to response' }],
  }[entry.status] || ({
    INSUFFICIENT_STOCK: [{ tone: 'warn', text: 'Warehouse refused: not enough stock', note: '409. Nothing was reserved and no order was created.' }],
    WAREHOUSE_UNAVAILABLE: [{ tone: 'bad', text: 'Laravel could not connect to the warehouse', note: '503. Reserve was never sent, so there is nothing to release. A retry with the same key is safe.' }],
    WAREHOUSE_TIMEOUT: [{ tone: 'warn', text: 'Reserve sent, no answer in time', note: '504. Laravel sent Release for the key, which also blocks a late Reserve. No order was created.' }],
    ORDER_IN_PROGRESS: [{ tone: 'wait', text: 'Previous attempt with this key is still settling', note: '409. Nothing was sent to the warehouse.' }],
  }[entry.errorType] || [{ tone: 'bad', text: `Request failed (${entry.status || 'no response'})`, note: entry.errorType || '' }]);
  return [sent, ...outcome];
}

function requestRow(e) {
  const code = e.revalidated === true ? '304*' : String(e.status || '—');
  const codeClass = `req__code req__code--${e.revalidated === true ? 3 : String(e.status || 0)[0]}`;
  const meta = [];
  meta.push(h('span', { text: `${e.ms} ms` }));
  if (e.xCache) meta.push(h('span', {}, h('b', { text: `X-Cache ${e.xCache}` })));
  for (const t of e.serverTiming) meta.push(h('span', { text: `${t.name} ${t.dur?.toFixed(1)} ms${t.desc ? ` (${t.desc})` : ''}` }));
  if (e.etag) meta.push(h('span', { text: `ETag ${e.etag.replace(/"/g, '').slice(0, 8)}…` }));
  if (e.revalidated === true) meta.push(h('span', {}, h('b', { text: 'Revalidated' }), ' · body reused (* from Resource Timing)'));
  if (e.stock) meta.push(h('span', { text: e.stock === 'live' ? 'stock live' : 'stock unavailable' }));
  if (e.errorType) meta.push(h('span', {}, h('b', { text: e.errorType })));
  if (e.retryAfter) meta.push(h('span', { text: `Retry-After ${e.retryAfter} s` }));
  if (e.keyFp) meta.push(h('span', { text: `key fp ${e.keyFp}` }));
  if (isOrderPost(e)) meta.push(h('span', { text: 'never cached' }));
  return h('div', { class: 'req' },
    h('div', { class: 'req__top' }, h('span', { class: 'req__method', text: e.method }), h('span', { class: 'req__path', text: e.path, title: e.path }), h('span', { class: codeClass, text: code })),
    h('div', { class: 'req__meta' }, meta));
}

export function mountDemo({ app, slot, onTurnOff }) {
  const css = h('link', { rel: 'stylesheet', href: '/assets/css/demo.css' });
  document.head.append(css);

  let tab = 'overview';
  let probe = null;
  let reservations = null;
  let open = true;
  let sheet = null;

  const body = h('div', { class: 'demo__body', role: 'tabpanel', id: 'demo-tabpanel', tabIndex: 0 });
  const tabs = h('div', { class: 'demo__tabs', role: 'tablist', 'aria-label': 'Demo panel' });
  const panel = h('section', { class: 'demo', 'aria-label': 'Under the hood' },
    h('div', { class: 'demo__head' },
      h('div', {}, h('div', { class: 'demo__title', text: 'Under the hood' }), h('div', { class: 'demo__tag', text: 'Demo mode · real requests' })),
      h('button', { type: 'button', class: 'demo__close', 'aria-label': 'Close the demo panel', onClick: () => setOpen(false) }, icon('close'))),
    tabs, body);
  const launcher = h('button', { type: 'button', class: 'demo-launch', onClick: () => setOpen(true), text: 'Demo' });

  const section = (label, ...children) => h('div', { class: 'demo__sec' }, h('span', { class: 'demo__label', text: label }), children);

  // WAI-ARIA tabs: each tab names the panel it controls; only the selected tab is
  // in the Tab order; Left/Right/Home/End move between tabs and select them.
  const TABS = [['overview', 'Overview'], ['requests', 'Requests'], ['flow', 'Order flow']];
  const selectTab = (id) => {
    tab = id;
    draw();
    tabs.querySelector(`#demo-tab-${id}`)?.focus();
  };
  function drawTabs() {
    mount(tabs, TABS.map(([id, label], index) => h('button', {
      type: 'button', role: 'tab', id: `demo-tab-${id}`, 'aria-controls': 'demo-tabpanel',
      'aria-selected': String(tab === id), tabIndex: tab === id ? 0 : -1, text: label,
      onClick: () => selectTab(id),
      onKeydown: (event) => {
        const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: TABS.length - 1 }[event.key];
        if (next === undefined) return;
        event.preventDefault();
        selectTab(TABS[(next + TABS.length) % TABS.length][0]);
      },
    })));
    body.setAttribute('aria-labelledby', `demo-tab-${tab}`);
  }

  function overview() {
    const view = session.isAdmin ? healthView(probe) : healthView({ ok: false, error: { status: 403 }, receivedAt: Date.now() });
    const [tone, label] = HEALTH_DOT[view.state];
    const observed = lastObserved(recorder.entries());
    const latestOrder = recorder.entries().find(isOrderPost);
    return [
      section('Warehouse',
        h('div', { class: 'demo__row' }, h('span', { text: 'Health (probe)' }), h('span', { class: `dot dot--${tone}`, text: label }),
          h('small', { text: view.state === 'healthy'
            ? `GetStock answered from the warehouse database in ${view.latencyMs} ms${view.slow ? ' (slow)' : ''} · checked ${view.ageS} s ago`
            : `${REASON_TEXT[view.reason] || ''}${view.ageS !== null && session.isAdmin ? ` Last check ${view.ageS} s ago.` : ''}` })),
        h('div', { class: 'demo__row' }, h('span', { text: 'Last observed' }),
          observed ? h('span', { class: `dot dot--${observed.tone}`, text: observed.label }) : h('span', { class: 'dot dot--unknown', text: 'Unknown' }),
          h('small', { text: observed ? `${observed.detail}. Describes that request, not the warehouse now.` : 'No stock-related request yet in this tab.' })),
        session.isAdmin ? h('button', { type: 'button', class: 'demo__action', text: 'Check health now', onClick: checkHealth }) : null),
      section('Last order attempt', latestOrder
        ? h('ol', { class: 'flow' }, flowSteps(latestOrder).map((s) => h('li', { class: s.tone === 'ok' ? '' : `is-${s.tone}` }, h('span', {}, s.text, h('small', { text: s.note })))))
        : h('p', { class: 'demo__empty', text: 'No order placed in this tab yet.' })),
      section('Recent store requests', storeEntries(recorder.entries()).slice(0, 4).map(requestRow), storeEntries(recorder.entries()).length ? null : h('p', { class: 'demo__empty', text: 'No requests yet.' })),
      h('button', { type: 'button', class: 'demo__action', text: 'Turn off demo mode', onClick: onTurnOff }),
    ];
  }

  function requests() {
    const entries = recorder.entries();
    return [section(`Requests in this tab (${entries.length}, newest first)`,
      entries.length ? entries.slice(0, 40).map(requestRow) : h('p', { class: 'demo__empty', text: 'No requests yet.' }),
      h('small', { class: 'demo__empty', text: 'Only method, path, status, timing and a few response headers are kept. No request headers, bodies, tokens or keys.' }))];
  }

  function flow() {
    const attempts = recorder.entries().filter(isOrderPost).slice(0, 6);
    if (!attempts.length) return [section('Order flow', h('p', { class: 'demo__empty', text: 'Place an order to see its steps here.' }))];
    return attempts.map((entry, i) => {
      const retry = attempts.slice(i + 1).some((older) => older.keyFp && older.keyFp === entry.keyFp);
      const row = reservations?.items.find((r) => r.idempotency_fp && r.idempotency_fp === entry.keyFp);
      return section(`${ago(entry.at)}${retry ? ' · retry of the same attempt' : ''}`,
        h('ol', { class: 'flow' }, flowSteps(entry).map((s) => h('li', { class: s.tone === 'ok' ? '' : `is-${s.tone}` }, h('span', {}, s.text, h('small', { text: s.note }))))),
        session.isAdmin
          ? h('small', { class: 'demo__empty', text: row ? `Laravel record #${row.id}: ${row.status} (warehouse expected to hold: ${row.warehouse_expectation}).` : 'No matching Laravel record on the first page of reservations.' })
          : null);
    });
  }

  function draw() {
    drawTabs();
    mount(body, tab === 'overview' ? overview() : tab === 'requests' ? requests() : flow());
  }

  async function checkHealth() {
    if (!session.isAdmin) return;
    probe = await fetchHealth();
    draw();
  }

  async function loadReservations() {
    if (!session.isAdmin) return;
    try {
      reservations = await fetchReservations();
    } catch {
      reservations = null;
    }
    if (tab === 'flow') draw();
  }

  // Place the panel: docked beside the store on wide screens, a sheet below 1024 px.
  const wide = matchMedia('(min-width: 1024px)');
  function place() {
    sheet?.close();
    sheet = null;
    panel.remove();
    app.classList.remove('has-demo');
    launcher.hidden = open && wide.matches;
    if (!open) return;
    if (wide.matches) {
      slot.append(panel);
      app.classList.add('has-demo');
    } else {
      const dialog = h('dialog', { class: 'demo-sheet', 'aria-label': 'Under the hood' }, panel);
      dialog.addEventListener('close', () => {
        if (sheet === dialog) { sheet = null; open = false; launcher.hidden = false; }
        dialog.remove();
      });
      document.body.append(dialog);
      sheet = dialog;
      dialog.showModal();
      launcher.hidden = true;
    }
  }
  function setOpen(next) {
    open = next;
    place();
    if (open) { draw(); checkHealth(); loadReservations(); }
  }

  document.body.append(launcher);
  wide.addEventListener('change', place);

  const unsubscribe = recorder.subscribe((entry) => {
    if (!open || entry.diagnostic) return;
    draw();
    if (isOrderPost(entry)) loadReservations();
  });
  const offSession = onChange(() => { if (open) draw(); });
  const stopProbe = whileVisible(() => { if (open) checkHealth(); }, 15000);
  // Keeps the "N s ago" ages and the 30 s → Unknown rule current. A redraw puts
  // keyboard focus back on the same control (the panel is a modal sheet below
  // 1024 px, so focus is always inside it there).
  const ticker = setInterval(() => {
    if (!open || tab !== 'overview') return;
    const active = panel.contains(document.activeElement) ? document.activeElement : null;
    const key = active ? `${active.tagName}|${active.getAttribute('aria-label') ?? ''}|${active.textContent}` : null;
    draw();
    if (key) [...panel.querySelectorAll('button')].find((el) => `${el.tagName}|${el.getAttribute('aria-label') ?? ''}|${el.textContent}` === key)?.focus();
  }, 1000);

  setOpen(wide.matches);
  if (!wide.matches) launcher.hidden = false;

  return {
    destroy() {
      clearInterval(ticker);
      stopProbe();
      unsubscribe();
      offSession();
      wide.removeEventListener('change', place);
      sheet?.close();
      panel.remove();
      launcher.remove();
      css.remove();
      app.classList.remove('has-demo');
    },
  };
}
