// Admin dashboard: totals, the newest orders, and a link to the System page.
// New orders and status changes arrive live over the WebSocket (Pusher).

import { h, mount } from '../../core/dom.js';
import { api } from '../../core/api.js';
import { icon } from '../../ui/icons.js';
import { skeletonBlock } from '../../ui/parts.js';
import { pageTitle } from '../../ui/chrome.js';
import { applyStatus, orderRow } from '../orders.js';
import { ORDER_PLACED, STATUS_UPDATED, onOrderEvent } from '../../core/realtime.js';
import { flash, liveBadge } from '../../ui/live.js';

const kpi = (label, value, note, link) => h('div', { class: 'kpi' },
  h('span', { class: 'panel__title', text: label }),
  value instanceof Node ? value : h('span', { class: 'kpi__value', text: value }),
  note ? h('span', { class: 'kpi__note', text: note }) : null,
  link ? h('div', { class: 'kpi__actions' }, h('a', { class: 'btn btn--secondary btn--small', href: link[1], text: link[0] })) : null);

export async function adminHomeView(main, ctx) {
  pageTitle('Dashboard');
  const body = h('div', { class: 'page' }, skeletonBlock(4));
  mount(main, h('div', { class: 'wrap page' }, h('div', { class: 'page-head' }, h('h1', { text: 'Dashboard' }), h('p', { text: 'Today at Dukkan.' })), body));

  const [products, categories, orders] = await Promise.allSettled([
    api('GET', '/api/v2/products?per_page=1'),
    api('GET', '/api/v1/categories'),
    api('GET', '/api/v1/orders'),
  ]);
  if (!ctx.isCurrent()) return;

  const total = (r, read) => (r.status === 'fulfilled' ? String(read(r.value)) : '—');
  const RECENT = 6;
  const recent = orders.status === 'fulfilled' ? orders.value.data.data.slice(0, RECENT) : [];
  const orderCount = h('span', { class: 'kpi__value', text: total(orders, (v) => v.data.total) });
  const tbody = h('tbody', {}, recent.map((o) => orderRow(o)));
  const table = h('table', { class: 'table table--stack' },
    h('thead', {}, h('tr', {}, ['Order', 'Placed', 'Status'].map((t) => h('th', { scope: 'col', text: t })), h('th', { scope: 'col', class: 'num', text: 'Total' }))),
    tbody);
  const recentSlot = h('div', {}, recent.length ? h('div', { class: 'table-wrap' }, table) : h('p', { class: 'panel--pad muted', text: 'No orders yet.' }));

  mount(body,
    h('div', { class: 'kpis' },
      kpi('Products', total(products, (v) => v.pagination?.total ?? v.data.length), 'In the catalog', ['Manage products', '#/admin/products']),
      kpi('Categories', total(categories, (v) => v.data.total), null, ['Manage categories', '#/admin/categories']),
      kpi('Orders', orderCount, 'All time', ['View orders', '#/admin/orders']),
      kpi('System', 'Health & cache', 'Warehouse health, cache and reservations', ['Open System', '#/admin/system'])),
    h('section', { class: 'panel', 'aria-labelledby': 'recent-title' },
      h('div', { class: 'panel--pad' }, h('div', { class: 'panel__head' },
        h('div', { class: 'live-title' }, h('h2', { id: 'recent-title', text: 'Newest orders' }), liveBadge(ctx)),
        h('a', { href: '#/admin/orders' }, 'All orders ', icon('right')))),
      recentSlot));

  onOrderEvent(STATUS_UPDATED, (payload) => applyStatus(table, payload), ctx);
  onOrderEvent(ORDER_PLACED, ({ order }) => {
    if (tbody.querySelector(`tr[data-order-id="${Number(order.id)}"]`)) return;
    const row = orderRow(order);
    tbody.prepend(row);
    flash(row);
    while (tbody.rows.length > RECENT) tbody.lastElementChild.remove();
    if (!recentSlot.contains(table)) mount(recentSlot, h('div', { class: 'table-wrap' }, table));
    const count = Number(orderCount.textContent);
    if (Number.isFinite(count) && orderCount.textContent !== '—') orderCount.textContent = String(count + 1);
  }, ctx);
}
