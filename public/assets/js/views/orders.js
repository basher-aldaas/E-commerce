// Order list: a customer's own orders, or every order for an admin. Both update
// live over the WebSocket: a status change repaints its row, and an admin sees
// new orders appear at the top of page 1.

import { h, mount } from '../core/dom.js';
import { api } from '../core/api.js';
import { session } from '../core/store.js';
import { navigate } from '../core/router.js';
import { money, when } from '../core/format.js';
import { banner } from '../ui/feedback.js';
import { emptyState, pager, skeletonBlock } from '../ui/parts.js';
import { pageTitle } from '../ui/chrome.js';
import { ORDER_PLACED, STATUS_UPDATED, onOrderEvent } from '../core/realtime.js';
import { flash, liveBadge } from '../ui/live.js';

export const STATUS_TONE = { pending: 'info', confirmed: 'info', processing: 'low', completed: 'ok', cancelled: 'soon' };

export const statusChip = (status) => h('span', { class: `badge badge--${STATUS_TONE[status] || 'unknown'} status-chip`, text: status });

/** One table row; data-order-id lets a live update find it. */
export const orderRow = (order, { customer = false } = {}) => h('tr', { 'data-order-id': order.id },
  h('td', { 'data-label': 'Order' }, h('a', { href: `#/orders/${order.id}`, text: `#${order.id}` })),
  h('td', { 'data-label': 'Placed', text: when(order.created_at) }),
  customer ? h('td', { 'data-label': 'Customer', text: `Customer #${order.user_id}` }) : null,
  h('td', { 'data-label': 'Status', class: 'order-status' }, statusChip(order.status)),
  h('td', { 'data-label': 'Total', class: 'num', text: money(order.total_amount) }));

/** Repaints the status of an order's row in this table, if it is shown. */
export function applyStatus(table, { order_id: id, status }) {
  const row = table.querySelector(`tr[data-order-id="${Number(id)}"]`);
  const cell = row?.querySelector('.order-status');
  if (!cell || cell.textContent === status) return;
  cell.replaceChildren(statusChip(status));
  flash(row);
}

/** Shared by #/orders (store layout) and #/admin/orders (admin layout). */
export async function renderOrderList(main, ctx, { basePath }) {
  const page = Number(ctx.query.get('page') || 1);
  const title = session.isAdmin ? 'All orders' : 'My orders';
  pageTitle(title);
  const body = h('div', {}, skeletonBlock(5));
  mount(main, h('div', { class: 'wrap page' }, h('div', { class: 'page-head' }, h('h1', { text: title }), liveBadge(ctx)), body));

  let result;
  try {
    result = await api('GET', `/api/v1/orders${page > 1 ? `?page=${page}` : ''}`);
  } catch (error) {
    if (ctx.isCurrent()) mount(body, banner('bad', 'We couldn’t load the orders.', error.message, [], { alert: true }));
    return;
  }
  if (!ctx.isCurrent()) return;

  const { data: orders, current_page: currentPage, last_page: lastPage, per_page: perPage } = result.data;

  let tbody = null;
  // A new order while the list is empty: draw the list again (it now has one row).
  if (session.isAdmin && currentPage === 1) {
    onOrderEvent(ORDER_PLACED, () => { if (!tbody) navigate(location.hash); }, ctx);
  }

  if (!orders.length) {
    mount(body, emptyState('orders', 'No orders yet', session.isAdmin ? 'Orders appear here as customers place them.' : 'When you place an order, it shows up here.',
      session.isAdmin ? null : h('a', { class: 'btn btn--primary', href: '#/shop', text: 'Start shopping' })));
    return;
  }

  const customer = session.isAdmin;
  tbody = h('tbody', {}, orders.map((order) => orderRow(order, { customer })));
  const table = h('table', { class: 'table table--stack' },
    h('thead', {}, h('tr', {},
      h('th', { scope: 'col', text: 'Order' }),
      h('th', { scope: 'col', text: 'Placed' }),
      customer ? h('th', { scope: 'col', text: 'Customer' }) : null,
      h('th', { scope: 'col', text: 'Status' }),
      h('th', { scope: 'col', class: 'num', text: 'Total' }))),
    tbody);

  mount(body, h('div', { class: 'panel table-wrap' }, table), pager(basePath, {}, { current_page: currentPage, last_page: lastPage }));

  onOrderEvent(STATUS_UPDATED, (payload) => applyStatus(table, payload), ctx);

  // Newest first: a new order goes on top of page 1; the page keeps its size.
  if (session.isAdmin && currentPage === 1) {
    onOrderEvent(ORDER_PLACED, ({ order }) => {
      if (tbody.querySelector(`tr[data-order-id="${Number(order.id)}"]`)) return;
      const row = orderRow(order, { customer });
      tbody.prepend(row);
      flash(row);
      while (tbody.rows.length > (perPage || 15)) tbody.lastElementChild.remove();
    }, ctx);
  }
}

export const ordersView = (main, ctx) => renderOrderList(main, ctx, { basePath: '/orders' });
