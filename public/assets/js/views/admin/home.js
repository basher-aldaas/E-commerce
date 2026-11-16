// Admin dashboard: totals, the newest orders, and a link to the System page.

import { h, mount } from '../../core/dom.js';
import { api } from '../../core/api.js';
import { money, when } from '../../core/format.js';
import { icon } from '../../ui/icons.js';
import { skeletonBlock } from '../../ui/parts.js';
import { pageTitle } from '../../ui/chrome.js';
import { statusChip } from '../orders.js';

const kpi = (label, value, note, link) => h('div', { class: 'kpi' },
  h('span', { class: 'panel__title', text: label }),
  h('span', { class: 'kpi__value', text: value }),
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
  const recent = orders.status === 'fulfilled' ? orders.value.data.data.slice(0, 6) : [];

  mount(body,
    h('div', { class: 'kpis' },
      kpi('Products', total(products, (v) => v.pagination?.total ?? v.data.length), 'In the catalog', ['Manage products', '#/admin/products']),
      kpi('Categories', total(categories, (v) => v.data.total), null, ['Manage categories', '#/admin/categories']),
      kpi('Orders', total(orders, (v) => v.data.total), 'All time', ['View orders', '#/admin/orders']),
      kpi('System', 'Health & cache', 'Warehouse health, cache and reservations', ['Open System', '#/admin/system'])),
    h('section', { class: 'panel', 'aria-labelledby': 'recent-title' },
      h('div', { class: 'panel--pad' }, h('div', { class: 'panel__head' }, h('h2', { id: 'recent-title', text: 'Newest orders' }), h('a', { href: '#/admin/orders' }, 'All orders ', icon('right')))),
      recent.length
        ? h('div', { class: 'table-wrap' }, h('table', { class: 'table table--stack' },
          h('thead', {}, h('tr', {}, ['Order', 'Placed', 'Status'].map((t) => h('th', { scope: 'col', text: t })), h('th', { scope: 'col', class: 'num', text: 'Total' }))),
          h('tbody', {}, recent.map((o) => h('tr', {},
            h('td', { 'data-label': 'Order' }, h('a', { href: `#/orders/${o.id}`, text: `#${o.id}` })),
            h('td', { 'data-label': 'Placed', text: when(o.created_at) }),
            h('td', { 'data-label': 'Status' }, statusChip(o.status)),
            h('td', { 'data-label': 'Total', class: 'num', text: money(o.total_amount) }))))))
        : h('p', { class: 'panel--pad muted', text: 'No orders yet.' })));
}
