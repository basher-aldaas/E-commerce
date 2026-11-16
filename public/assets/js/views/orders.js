// Order list: a customer's own orders, or every order for an admin.

import { h, mount } from '../core/dom.js';
import { api } from '../core/api.js';
import { session } from '../core/store.js';
import { money, when } from '../core/format.js';
import { banner } from '../ui/feedback.js';
import { emptyState, pager, skeletonBlock } from '../ui/parts.js';
import { pageTitle } from '../ui/chrome.js';

export const STATUS_TONE = { pending: 'info', confirmed: 'info', processing: 'low', completed: 'ok', cancelled: 'soon' };

export const statusChip = (status) => h('span', { class: `badge badge--${STATUS_TONE[status] || 'unknown'} status-chip`, text: status });

/** Shared by #/orders (store layout) and #/admin/orders (admin layout). */
export async function renderOrderList(main, ctx, { basePath }) {
  const page = Number(ctx.query.get('page') || 1);
  const title = session.isAdmin ? 'All orders' : 'My orders';
  pageTitle(title);
  const body = h('div', {}, skeletonBlock(5));
  mount(main, h('div', { class: 'wrap page' }, h('div', { class: 'page-head' }, h('h1', { text: title })), body));

  let result;
  try {
    result = await api('GET', `/api/v1/orders${page > 1 ? `?page=${page}` : ''}`);
  } catch (error) {
    if (ctx.isCurrent()) mount(body, banner('bad', 'We couldn’t load the orders.', error.message, [], { alert: true }));
    return;
  }
  if (!ctx.isCurrent()) return;

  const { data: orders, current_page: currentPage, last_page: lastPage } = result.data;
  if (!orders.length) {
    mount(body, emptyState('orders', 'No orders yet', session.isAdmin ? 'Orders appear here as customers place them.' : 'When you place an order, it shows up here.',
      session.isAdmin ? null : h('a', { class: 'btn btn--primary', href: '#/shop', text: 'Start shopping' })));
    return;
  }

  mount(body,
    h('div', { class: 'panel table-wrap' }, h('table', { class: 'table table--stack' },
      h('thead', {}, h('tr', {},
        h('th', { scope: 'col', text: 'Order' }),
        h('th', { scope: 'col', text: 'Placed' }),
        session.isAdmin ? h('th', { scope: 'col', text: 'Customer' }) : null,
        h('th', { scope: 'col', text: 'Status' }),
        h('th', { scope: 'col', class: 'num', text: 'Total' }))),
      h('tbody', {}, orders.map((order) => h('tr', {},
        h('td', { 'data-label': 'Order' }, h('a', { href: `#/orders/${order.id}`, text: `#${order.id}` })),
        h('td', { 'data-label': 'Placed', text: when(order.created_at) }),
        session.isAdmin ? h('td', { 'data-label': 'Customer', text: `Customer #${order.user_id}` }) : null,
        h('td', { 'data-label': 'Status' }, statusChip(order.status)),
        h('td', { 'data-label': 'Total', class: 'num', text: money(order.total_amount) })))))),
    pager(basePath, {}, { current_page: currentPage, last_page: lastPage }));
}

export const ordersView = (main, ctx) => renderOrderList(main, ctx, { basePath: '/orders' });
