// One order: status timeline, lines and total. Admins can change the status;
// cancelling asks first, because it returns the reserved stock to the Warehouse.

import { h, mount } from '../core/dom.js';
import { api } from '../core/api.js';
import { navigate } from '../core/router.js';
import { session } from '../core/store.js';
import { money, when } from '../core/format.js';
import { banner, toast } from '../ui/feedback.js';
import { confirmDialog } from '../ui/overlay.js';
import { emptyState, productImage, skeletonBlock } from '../ui/parts.js';
import { pageTitle } from '../ui/chrome.js';
import { statusChip } from './orders.js';

const STEPS = ['pending', 'confirmed', 'processing', 'completed'];
const STEP_LABEL = { pending: 'Placed', confirmed: 'Confirmed', processing: 'Processing', completed: 'Completed' };

function timeline(status) {
  if (status === 'cancelled') return banner('info', 'This order was cancelled.', 'Its reserved stock went back to our warehouse.');
  const at = STEPS.indexOf(status);
  return h('ol', { class: 'timeline', 'aria-label': 'Order progress' }, STEPS.map((step, i) => h('li', {
    class: i < at ? 'is-done' : i === at ? 'is-done is-current' : '',
    'aria-current': i === at ? 'step' : null,
    text: STEP_LABEL[step],
  })));
}

/** Messages for a failed status change (concept §5 and the cancel flow). */
function statusError(error) {
  if (error.status === 409) {
    return banner('warn', 'This order changed since you opened it.', error.message, [
      h('button', { type: 'button', class: 'btn btn--secondary btn--small', onClick: () => navigate(location.hash), text: 'Reload order' })], { alert: true });
  }
  if (error.status === 503 || error.status === 504) {
    return banner('warn', 'The warehouse hasn’t confirmed the stock return yet.', 'The order isn’t marked cancelled until it does. The cancellation finishes automatically, or you can try again.', [], { alert: true });
  }
  return banner('bad', 'We couldn’t update the order.', error.message, [], { alert: true });
}

export async function orderView(main, ctx) {
  mount(main, h('div', { class: 'wrap page' }, skeletonBlock(6)));
  let order;
  try {
    ({ data: order } = await api('GET', `/api/v1/orders/${encodeURIComponent(ctx.params.id)}`));
  } catch (error) {
    if (!ctx.isCurrent()) return;
    pageTitle('Order not found');
    mount(main, h('div', { class: 'wrap page' }, error.status === 404 || error.status === 403
      ? emptyState('orders', 'We couldn’t find that order', null, h('a', { class: 'btn btn--secondary', href: '#/orders', text: 'Go to your orders' }), { level: 'h1' })
      : banner('bad', 'We couldn’t load this order.', error.message, [], { alert: true })));
    return;
  }
  if (!ctx.isCurrent()) return;
  pageTitle(`Order #${order.id}`);

  const notice = h('div');
  const placed = ctx.query.get('placed') === '1';
  const count = order.items.reduce((n, item) => n + item.quantity, 0);

  let adminBox = null;
  if (session.isAdmin) {
    const select = h('select', { class: 'select', id: 'order-status' },
      [...STEPS, 'cancelled'].map((s) => h('option', { value: s, selected: s === order.status, text: s[0].toUpperCase() + s.slice(1) })));
    const save = h('button', { type: 'submit', class: 'btn btn--primary', text: 'Update status' });
    adminBox = h('form', { class: 'panel panel--pad', onSubmit: async (event) => {
      event.preventDefault();
      const status = select.value;
      if (status === order.status) return;
      if (status === 'cancelled' && !(await confirmDialog({ title: `Cancel order #${order.id}?`, body: 'Its reserved stock goes back to the warehouse. This can’t be undone.', confirmLabel: 'Cancel order', cancelLabel: 'Keep order', danger: true }))) return;
      save.disabled = true;
      save.textContent = 'Updating…';
      try {
        await api('POST', `/api/v1/orders/${order.id}/status`, { body: { status } });
        toast(`Order #${order.id} is now ${status}.`);
        navigate(location.hash.split('?')[0]);
      } catch (error) {
        save.disabled = false;
        save.textContent = 'Update status';
        mount(notice, statusError(error));
        notice.querySelector('.banner')?.focus();
      }
    } },
    h('h2', { class: 'panel__title', text: 'Manage order' }),
    h('div', { class: 'field' }, h('label', { for: 'order-status', text: 'Status' }), select),
    save);
  }

  mount(main, h('div', { class: 'wrap page' },
    placed ? banner('ok', `Order #${order.id} is placed.`, `Your ${count === 1 ? 'item is' : `${count} items are`} reserved in our warehouse.`) : null,
    notice,
    h('div', { class: 'order-head' },
      h('div', {}, h('h1', { text: `Order #${order.id}` }), h('p', { class: 'muted', text: `Placed ${when(order.created_at)}${session.isAdmin ? ` · Customer #${order.user_id}` : ''}` })),
      statusChip(order.status)),
    timeline(order.status),
    h('div', { class: 'order-grid' },
      h('ul', { class: 'lines', role: 'list' }, order.items.map((item) => {
        const product = item.product ?? { id: item.product_id, name: `Product #${item.product_id}` };
        const image = product.image ? { ...product, image_url: `/storage/${product.image}` } : product;
        return h('li', { class: 'line' },
          h('div', { class: 'line__media' }, productImage(image, { alt: '' })),
          h('div', {}, h('a', { class: 'line__name', href: `#/product/${product.id}`, text: product.name }), h('p', { class: 'small muted', text: `${item.quantity} × ${money(item.unit_price)}` })),
          h('span'),
          h('span', { class: 'line__total', text: money(Number(item.unit_price) * item.quantity) }));
      })),
      h('aside', { class: 'cart__summary' },
        h('div', { class: 'summary' },
          h('h2', { text: 'Summary' }),
          h('div', { class: 'summary__row' }, h('span', { text: `Items (${count})` }), h('span', { text: money(order.total_amount) })),
          h('div', { class: 'summary__row summary__row--total' }, h('span', { text: 'Total' }), h('span', { text: money(order.total_amount) }))),
        adminBox)),
    h('a', { href: session.isAdmin ? '#/admin/orders' : '#/orders', text: session.isAdmin ? '← All orders' : '← My orders' })));
}
