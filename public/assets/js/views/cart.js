// Cart and checkout.
//
// - The cart keeps exactly what the customer chose; it never caps a quantity at
//   the stock shown. The Warehouse checks stock when the order is placed.
// - One Idempotency-Key per attempt (core/checkout.js): kept after 503, 504,
//   in-progress, network and server errors, so "Try again" is the same attempt;
//   replaced when the cart changes or after a final answer.
// - A 409 out-of-stock answer flags each affected line with what is available.

import { h, mount } from '../core/dom.js';
import { api } from '../core/api.js';
import { navigate } from '../core/router.js';
import { cart, onChange } from '../core/store.js';
import { checkout } from '../core/checkout.js';
import { fingerprint } from '../core/fingerprint.js';
import { money } from '../core/format.js';
import { banner } from '../ui/feedback.js';
import { emptyState, productImage, stepper } from '../ui/parts.js';
import { pageTitle } from '../ui/chrome.js';

/** Per outcome: banner tone, title, text. Copy as approved in the Phase 3 concept, §5. */
const OUTCOMES = {
  INSUFFICIENT_STOCK: ['warn', 'Stock changed while you were shopping.', 'Our warehouse couldn’t reserve every item, so we didn’t place your order and nothing was reserved. Review the highlighted items, then place your order again.'],
  WAREHOUSE_UNAVAILABLE: ['bad', 'We couldn’t reach our warehouse,', 'so your order wasn’t placed and nothing was reserved. You can try again.'],
  WAREHOUSE_TIMEOUT: ['warn', 'Our warehouse didn’t answer in time,', 'so we didn’t place your order. Any stock held for it is being returned. You can try again. It won’t create a second order.'],
  ORDER_IN_PROGRESS: ['info', 'We’re still finishing your last attempt.', 'Try again in a moment.'],
  IDEMPOTENCY_KEY_CONFLICT: ['warn', 'Your cart changed since your last attempt.', 'Please place the order again.'],
  RATE_LIMITED: ['warn', 'Too many requests.', null],
  NETWORK: ['bad', 'You appear to be offline.', 'Your order wasn’t confirmed. Check your connection and try again. It won’t create a second order.'],
  SERVER: ['bad', 'Something went wrong on our side,', 'and your order wasn’t confirmed. Trying again is safe. It won’t create a second order.'],
};

/** A flagged line is fixed once its quantity fits what the Warehouse said is available. */
const isFixed = (line, flag) => flag.stocked && flag.available > 0 && line.qty <= flag.available;

export function cartView(main, ctx) {
  pageTitle('Your cart');
  const state = { busy: false, outcome: null, message: null, retryAt: 0, flags: new Map() };
  let timer = null;

  const placeOrder = async () => {
    if (state.busy || !cart.lines.length) return;
    state.busy = true;
    state.outcome = null;
    render();

    const lines = cart.lines.map((l) => ({ id: l.id, qty: l.qty }));
    const key = checkout.keyFor(lines);
    const keyFp = await fingerprint(key);

    try {
      const { data: order } = await api('POST', '/api/v1/orders', {
        body: { items: lines.map((l) => ({ product_id: l.id, quantity: l.qty })) },
        headers: { 'Idempotency-Key': key },
        keyFp,
      });
      checkout.settle('PLACED');
      cart.clear();
      navigate(`#/orders/${order.id}?placed=1`);
    } catch (error) {
      if (error.type === 'UNAUTHENTICATED') return;
      checkout.settle(error.type);
      state.busy = false;
      state.outcome = OUTCOMES[error.type] ? error.type : 'VALIDATION';
      state.message = error.message;
      state.flags = new Map();
      if (error.type === 'INSUFFICIENT_STOCK') {
        for (const item of error.errors.items) {
          state.flags.set(item.product_id, { requested: item.requested_quantity, available: item.available_quantity, stocked: item.stocked });
        }
      }
      if (error.type === 'RATE_LIMITED') state.retryAt = Date.now() + (error.retryAfter || 30) * 1000;
      if (error.type === 'ORDER_IN_PROGRESS') state.retryAt = Date.now() + 4000;
      if (!ctx.isCurrent()) return;
      render();
      main.querySelector('.banner[role="alert"]')?.focus();
    }
  };

  const waitLeft = () => Math.max(0, Math.ceil((state.retryAt - Date.now()) / 1000));

  const outcomeBanner = () => {
    if (!state.outcome) return null;
    if (state.outcome === 'VALIDATION') return banner('bad', 'We couldn’t place your order.', state.message, [], { alert: true });
    const [tone, title, text] = OUTCOMES[state.outcome];
    const wait = waitLeft();
    const retryable = !['INSUFFICIENT_STOCK', 'IDEMPOTENCY_KEY_CONFLICT'].includes(state.outcome);
    const body = state.outcome === 'RATE_LIMITED' ? (wait ? `Try again in ${wait} seconds.` : 'You can try again now.') : text;
    const actions = [];
    if (retryable) {
      actions.push(h('button', { type: 'button', class: 'btn btn--secondary btn--small', disabled: wait > 0 || state.busy, onClick: placeOrder, text: wait ? `Try again (${wait})` : 'Try again' }));
    }
    if (['WAREHOUSE_TIMEOUT', 'ORDER_IN_PROGRESS', 'SERVER', 'NETWORK'].includes(state.outcome)) {
      actions.push(h('a', { class: 'btn btn--ghost btn--small', href: '#/orders', text: 'View my orders' }));
    }
    return banner(tone, title, body, actions, { alert: true });
  };

  const lineRow = (line) => {
    const flag = state.flags.get(line.id);
    const fixed = flag ? isFixed(line, flag) : true;
    let fix = null;
    if (flag && !fixed) {
      if (!flag.stocked) {
        fix = h('div', { class: 'line__fix' }, h('span', {}, h('b', { text: 'No longer sold here.' })),
          h('button', { type: 'button', class: 'btn btn--secondary btn--small', onClick: () => cart.remove(line.id), text: 'Remove from cart' }));
      } else if (flag.available === 0) {
        fix = h('div', { class: 'line__fix' }, h('span', {}, h('b', { text: 'Sold out' }), ' since you added it.'),
          h('button', { type: 'button', class: 'btn btn--secondary btn--small', onClick: () => cart.remove(line.id), text: 'Remove from cart' }));
      } else {
        fix = h('div', { class: 'line__fix' },
          h('span', {}, 'You asked for ', h('b', { text: flag.requested }), ', only ', h('b', { text: flag.available }), ' available.'),
          h('button', { type: 'button', class: 'btn btn--secondary btn--small', onClick: () => cart.setQty(line.id, flag.available), text: `Change to ${flag.available}` }),
          h('button', { type: 'button', class: 'link-btn', onClick: () => cart.remove(line.id), text: 'Remove' }));
      }
    }
    return h('li', { class: `line${flag && !fixed ? (flag.stocked && flag.available > 0 ? ' line--warn' : ' line--bad') : ''}` },
      h('div', { class: 'line__media' }, productImage(line, { alt: '' })),
      h('div', {}, h('a', { class: 'line__name', href: `#/product/${line.id}`, text: line.name }), h('p', { class: 'small muted', text: `${money(line.price)} each` })),
      stepper({ value: line.qty, label: `quantity of ${line.name}`, onChange: (qty) => cart.setQty(line.id, qty) }),
      h('span', { class: 'line__total', text: money(Number(line.price) * line.qty) }),
      fix || h('div', { class: 'line__fix' }, h('button', { type: 'button', class: 'link-btn small', onClick: () => cart.remove(line.id), text: 'Remove', 'aria-label': `Remove ${line.name}` })));
  };

  const render = () => {
    if (!ctx.isCurrent()) return;
    if (!cart.lines.length) {
      mount(main, h('div', { class: 'wrap page' }, h('h1', { class: 'sr-only', text: 'Your cart' }),
        emptyState('cart', 'Your cart is empty', 'Find something you like in the shop.', h('a', { class: 'btn btn--primary', href: '#/shop', text: 'Start shopping' }))));
      return;
    }
    const unresolved = [...state.flags].filter(([id, flag]) => {
      const line = cart.lines.find((l) => l.id === id);
      return line && !isFixed(line, flag);
    }).length;
    const blocked = state.busy || unresolved > 0 || waitLeft() > 0;
    const placeButton = () => h('button', { type: 'button', class: 'btn btn--primary btn--block', disabled: blocked, onClick: placeOrder, text: state.busy ? 'Placing order…' : 'Place order' });

    mount(main, h('div', { class: 'wrap page' },
      h('div', { class: 'page-head' }, h('h1', { text: 'Your cart' }), h('p', { text: `${cart.count} ${cart.count === 1 ? 'item' : 'items'}` })),
      outcomeBanner(),
      h('div', { class: 'cart' },
        h('ul', { class: 'lines', role: 'list' }, cart.lines.map(lineRow)),
        h('aside', { class: 'cart__summary', 'aria-label': 'Order summary' },
          h('div', { class: 'summary' },
            h('h2', { text: 'Order summary' }),
            h('div', { class: 'summary__row' }, h('span', { text: `Items (${cart.count})` }), h('span', { text: money(cart.total) })),
            h('div', { class: 'summary__row summary__row--total' }, h('span', { text: 'Total' }), h('span', { text: money(cart.total) })),
            h('div', { class: 'summary__checkout' }, placeButton()),
            h('p', { class: 'small', text: unresolved
              ? `Fix the ${unresolved} highlighted ${unresolved === 1 ? 'item' : 'items'} to continue.`
              : 'Your stock is reserved the moment the order is placed. The final total is confirmed by our server.' })))),
      h('div', { class: 'sticky-checkout' },
        h('div', {}, h('span', { class: 'small muted', text: 'Total' }), h('div', { class: 'price', text: money(cart.total) })),
        placeButton())));

    clearTimeout(timer);
    if (waitLeft() > 0) timer = setTimeout(render, 1000);
  };

  // Re-render on cart changes made here (quantity, remove) while this screen is
  // open, keeping keyboard focus on the control that was being used.
  const off = onChange(() => {
    if (!ctx.isCurrent()) { off(); return; }
    if (state.busy) return;
    const label = document.activeElement?.getAttribute('aria-label');
    render();
    if (label) [...main.querySelectorAll('[aria-label]')].find((el) => el.getAttribute('aria-label') === label)?.focus();
  });
  render();
}
