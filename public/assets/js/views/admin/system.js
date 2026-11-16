// Admin System page. Everything shown comes from real responses:
// - Warehouse health: GET /api/v1/system/warehouse, on open, every 15 s while the
//   tab is visible, and on "Check now". Older than 30 s → Unknown.
// - Catalog cache and ETag: read from this browser's own recent requests.
// - Reservations: GET /api/v1/system/reservations (Laravel's workflow record).

import { h, mount } from '../../core/dom.js';
import { api } from '../../core/api.js';
import { recorder } from '../../core/recorder.js';
import { fetchHealth, fetchReservations, whileVisible } from '../../core/diagnostics.js';
import { healthView, REASON_TEXT } from '../../core/health.js';
import { when } from '../../core/format.js';
import { banner } from '../../ui/feedback.js';
import { pageTitle } from '../../ui/chrome.js';

const HEALTH_BADGE = {
  healthy: ['ok', 'Healthy'],
  unreachable: ['out', 'Unreachable'],
  error: ['low', 'Responding with errors'],
  unknown: ['unknown', 'Unknown'],
};

const EXPECTATION_TEXT = {
  held: 'Yes (Reserve confirmed)',
  not_held: 'No',
  unsettled: 'Not settled yet',
  unknown: 'Unknown',
};

const RESERVATION_TONE = { placed: 'ok', released: 'soon', cancelled: 'soon', rejected: 'soon', pending: 'info', release_pending: 'low', cancel_pending: 'low', attention: 'out' };
const STATES = ['pending', 'rejected', 'placed', 'release_pending', 'released', 'cancel_pending', 'cancelled', 'attention'];

export function healthCard(probe, now = Date.now()) {
  const view = healthView(probe, now);
  const [tone, label] = HEALTH_BADGE[view.state];
  const age = view.ageS === null ? 'not checked yet' : `checked ${view.ageS} s ago`;
  return [
    h('span', { class: `badge badge--${tone}`, text: label }),
    h('span', { class: 'kpi__value', text: view.latencyMs !== null && view.state !== 'unknown' ? `${view.latencyMs} ms` : '—' }),
    h('span', { class: 'kpi__note', text: view.state === 'healthy'
      ? `The warehouse answered GetStock from its database${view.slow ? ' (slow)' : ''} · ${age}.`
      : `${REASON_TEXT[view.reason] || ''} ${view.ageS === null ? '' : `(${age})`}`.trim() }),
  ];
}

const latestWith = (predicate) => recorder.entries().find(predicate) ?? null;

export async function adminSystemView(main, ctx) {
  pageTitle('System');
  let probe = null;
  let checking = false;
  const loadedAt = Date.now();

  const healthBody = h('div', { class: 'kpi' });
  const cacheBody = h('div', { class: 'kpi' });
  const etagBody = h('div', { class: 'kpi' });
  const compareBox = h('div');
  const reservationsBox = h('section', { class: 'panel', 'aria-labelledby': 'res-title' });
  const checkButton = h('button', { type: 'button', class: 'btn btn--secondary btn--small', text: 'Check now', onClick: () => check() });

  const drawHealth = () => mount(healthBody, h('span', { class: 'panel__title', text: 'Warehouse health' }), healthCard(probe),
    h('div', { class: 'kpi__actions' }, checkButton),
    h('span', { class: 'kpi__note', text: 'Rechecks every 15 s while this page is open and visible.' }));

  const drawCache = () => {
    const last = latestWith((e) => e.xCache);
    const catalog = last?.serverTiming.find((t) => t.name === 'catalog');
    const stock = last?.serverTiming.find((t) => t.name === 'stock');
    mount(cacheBody,
      h('span', { class: 'panel__title', text: 'Catalog cache (Redis)' }),
      last ? h('span', { class: `badge badge--${last.xCache === 'HIT' ? 'ok' : 'info'}`, text: `Last: X-Cache ${last.xCache}` }) : h('span', { class: 'badge badge--unknown', text: 'No catalog request yet' }),
      h('span', { class: 'kpi__value', text: catalog?.dur !== null && catalog?.dur !== undefined ? `${catalog.dur.toFixed(1)} ms` : '—' }),
      h('span', { class: 'kpi__note', text: last
        ? `Catalog part of ${last.method} ${last.path}${stock ? `; live stock read took ${stock.dur.toFixed(1)} ms (never cached)` : ''}.`
        : 'Browse the shop or press Compare to see a cache result.' }),
      h('div', { class: 'kpi__actions' }, h('button', { type: 'button', class: 'btn btn--secondary btn--small', text: 'Compare HIT vs bypass', onClick: compare })));
  };

  const drawEtag = () => {
    const since = recorder.entries().filter((e) => e.at >= loadedAt && e.method === 'GET');
    const revalidated = since.filter((e) => e.revalidated === true).length;
    const last = latestWith((e) => e.etag);
    mount(etagBody,
      h('span', { class: 'panel__title', text: 'Conditional requests (ETag)' }),
      h('span', { class: `badge badge--${last ? 'ok' : 'unknown'}`, text: last ? 'ETag sent by the server' : 'No ETag seen yet' }),
      h('span', { class: 'kpi__value', text: `${revalidated} × 304` }),
      h('span', { class: 'kpi__note', text: 'Revalidations since this page loaded, from the browser’s Resource Timing. The ETag covers live stock too, so a stock change gives a new ETag.' }));
  };

  async function check() {
    if (checking) return;
    checking = true;
    checkButton.disabled = true;
    checkButton.textContent = 'Checking…';
    probe = await fetchHealth();
    checking = false;
    if (!ctx.isCurrent()) return;
    checkButton.disabled = false;
    checkButton.textContent = 'Check now';
    drawHealth();
  }

  async function compare() {
    const path = '/api/v2/products?per_page=12';
    const run = async (label, headers) => {
      const started = performance.now();
      try {
        const { response } = await api('GET', path, { headers });
        const timing = response.headers.get('Server-Timing') || '';
        const catalog = /catalog;dur=([\d.]+)/.exec(timing);
        return { label, xCache: response.headers.get('X-Cache') || 'none', catalogMs: catalog ? Number(catalog[1]) : null, totalMs: performance.now() - started };
      } catch (error) {
        return { label, error: error.message };
      }
    };
    const results = [await run('Normal request', {}), await run('With X-Cache-Bypass: 1', { 'X-Cache-Bypass': '1' })];
    if (!ctx.isCurrent()) return;
    mount(compareBox, h('div', { class: 'panel table-wrap' }, h('table', { class: 'table' },
      h('caption', { class: 'sr-only', text: 'Cache comparison' }),
      h('thead', {}, h('tr', {}, ['Diagnostic request', 'X-Cache', 'Catalog time', 'Total in browser'].map((t) => h('th', { scope: 'col', text: t })))),
      h('tbody', {}, results.map((r) => h('tr', {},
        h('td', { text: r.label }),
        h('td', { text: r.error ? 'failed' : r.xCache }),
        h('td', { class: 'num', text: r.catalogMs === null || r.catalogMs === undefined ? '—' : `${r.catalogMs.toFixed(1)} ms` }),
        h('td', { class: 'num', text: r.error ? r.error : `${Math.round(r.totalMs)} ms` })))))),
      h('p', { class: 'small muted', text: `${path} · The bypass header is only honored outside production; X-Cache shows what the server actually did.` }));
    drawCache();
  }

  async function drawReservations(status = '', page = 1) {
    // The box is rebuilt below; remember which control had focus (the filter or a
    // pager button) and put focus back on it, so keyboard use isn't interrupted.
    const active = reservationsBox.contains(document.activeElement) ? document.activeElement : null;
    const focusKey = active ? (active.id || active.textContent) : null;
    let data;
    try {
      data = await fetchReservations({ status, page });
    } catch (error) {
      if (ctx.isCurrent()) mount(reservationsBox, h('div', { class: 'panel--pad' }, banner('bad', 'We couldn’t load reservations.', error.message, [], { alert: true })));
      return;
    }
    queueMicrotask(() => {
      if (!focusKey) return;
      const target = reservationsBox.querySelector(`#${CSS.escape(focusKey)}`)
        ?? [...reservationsBox.querySelectorAll('button')].find((b) => b.textContent === focusKey && !b.disabled)
        ?? reservationsBox.querySelector('#res-status');
      target?.focus();
    });
    if (!ctx.isCurrent()) return;
    const unsettled = (data.counts.release_pending || 0) + (data.counts.cancel_pending || 0);
    const filter = h('select', { class: 'select', id: 'res-status', onChange: () => drawReservations(filter.value) },
      h('option', { value: '', text: 'All states' }),
      STATES.map((s) => h('option', { value: s, selected: s === status, text: `${s} (${data.counts[s] || 0})` })));
    const { current_page: current, last_page: last } = data.pagination;
    mount(reservationsBox,
      h('div', { class: 'panel--pad' },
        h('div', { class: 'panel__head' },
          h('div', {}, h('h2', { id: 'res-title', text: 'Order reservations' }),
            h('p', { class: 'small muted', text: `Laravel’s workflow record of each order attempt${unsettled ? ` · ${unsettled} not settled yet` : ''}. “Expected to hold” is derived from Laravel’s state, not read from the warehouse.` })),
          h('div', { class: 'field' }, h('label', { for: 'res-status', text: 'Filter' }), filter))),
      data.items.length
        ? h('div', { class: 'table-wrap' }, h('table', { class: 'table table--stack' },
          h('thead', {}, h('tr', {}, ['Res.', 'Order', 'Customer', 'Laravel state', 'Warehouse expected to hold stock', 'Idem. key fp', 'Updated'].map((t) => h('th', { scope: 'col', text: t })))),
          h('tbody', {}, data.items.map((r) => h('tr', {},
            h('td', { 'data-label': 'Res.', class: 'num', text: `#${r.id}` }),
            h('td', { 'data-label': 'Order' }, r.order_id ? h('a', { href: `#/orders/${r.order_id}`, text: `#${r.order_id}` }) : h('span', { class: 'muted', text: 'none' })),
            h('td', { 'data-label': 'Customer', text: r.user.name }),
            h('td', { 'data-label': 'Laravel state' }, h('span', { class: `badge badge--${RESERVATION_TONE[r.status] || 'unknown'}`, text: r.status })),
            h('td', { 'data-label': 'Expected to hold', text: EXPECTATION_TEXT[r.warehouse_expectation] }),
            h('td', { 'data-label': 'Key fp', class: 'mono', text: r.idempotency_fp ?? '—' }),
            h('td', { 'data-label': 'Updated', text: when(r.updated_at) }))))))
        : h('p', { class: 'panel--pad muted', text: 'No reservations in this state.' }),
      last > 1 ? h('div', { class: 'panel--pad pager' },
        h('button', { type: 'button', class: 'btn btn--secondary btn--small', disabled: current <= 1, onClick: () => drawReservations(status, current - 1), text: 'Newer' }),
        h('span', { text: `Page ${current} of ${last}` }),
        h('button', { type: 'button', class: 'btn btn--secondary btn--small', disabled: current >= last, onClick: () => drawReservations(status, current + 1), text: 'Older' })) : null,
      h('p', { class: 'panel--pad small muted', text: '“Not settled yet” means a Release is waiting for the warehouse’s reply; warehouse:reconcile finishes it. “attention” means the warehouse refused a step that should succeed, and a person should check the logs.' }));
  }

  mount(main, h('div', { class: 'wrap page' },
    h('div', { class: 'page-head' }, h('h1', { text: 'System' }), h('p', { text: 'Live checks and your own recent requests. Nothing here changes data.' })),
    h('div', { class: 'kpis' }, healthBody, cacheBody, etagBody),
    compareBox,
    reservationsBox));

  drawHealth();
  drawCache();
  drawEtag();
  check();
  drawReservations();

  // Probe every 15 s while visible; refresh the age text every second.
  const stopProbe = whileVisible(check, 15000);
  const ticker = setInterval(() => {
    if (!ctx.isCurrent()) {
      clearInterval(ticker);
      stopProbe();
      unsubscribe();
      return;
    }
    if (!checking) drawHealth();
  }, 1000);
  const unsubscribe = recorder.subscribe(() => { if (ctx.isCurrent()) { drawCache(); drawEtag(); } });
}
