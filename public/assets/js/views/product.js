// One product, with its live Warehouse stock. The quantity is never capped at the
// stock shown: the Warehouse decides when the order is placed.

import { h, mount } from '../core/dom.js';
import { api } from '../core/api.js';
import { session } from '../core/store.js';
import { money, stockKnown } from '../core/format.js';
import { banner } from '../ui/feedback.js';
import { emptyState, productImage, skeletonBlock, stepper, stockBadge } from '../ui/parts.js';
import { pageTitle } from '../ui/chrome.js';
import { addToCart, canAddToCart } from './shop.js';

const clock = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

export async function productView(main, ctx) {
  mount(main, h('div', { class: 'wrap page' }, skeletonBlock(6)));

  let product;
  try {
    ({ data: product } = await api('GET', `/api/v2/products/${encodeURIComponent(ctx.params.id)}`));
  } catch (error) {
    if (!ctx.isCurrent()) return;
    pageTitle('Product not found');
    mount(main, h('div', { class: 'wrap page' }, error.status === 404
      ? emptyState('box', 'We couldn’t find that product', 'It may have been removed.', h('a', { class: 'btn btn--secondary', href: '#/shop', text: 'Back to the shop' }), { level: 'h1' })
      : banner('bad', 'We couldn’t load this product.', error.message, [], { alert: true })));
    return;
  }
  if (!ctx.isCurrent()) return;
  pageTitle(product.name);

  let qty = 1;
  const allowed = canAddToCart(product) && !session.isAdmin;
  const known = stockKnown(product.stock_status);
  const checkedAt = clock.format(new Date());

  const addButton = () => h('button', { type: 'button', class: 'btn btn--primary', disabled: !allowed, onClick: () => addToCart(product, qty) },
    allowed ? 'Add to cart' : session.isAdmin ? 'Admins can’t place orders' : product.stock_status === 'not_stocked' ? 'Not available yet' : 'Sold out');

  mount(main, h('div', { class: 'wrap page' },
    h('nav', { class: 'crumbs', 'aria-label': 'Breadcrumb' },
      h('a', { href: '#/shop', text: 'Shop' }), h('span', { 'aria-hidden': 'true', text: '/' }),
      product.category ? [h('a', { href: `#/shop?category_id=${product.category.id}`, text: product.category.name }), h('span', { 'aria-hidden': 'true', text: '/' })] : null,
      h('span', { 'aria-current': 'page', text: product.name })),
    h('div', { class: 'pdp' },
      h('div', { class: 'pdp__media' }, productImage(product)),
      h('div', { class: 'pdp__info' },
        product.category ? h('span', { class: 'product-card__cat', text: product.category.name }) : null,
        h('h1', { text: product.name }),
        h('span', { class: 'pdp__price', text: money(product.price) }),
        product.description ? h('p', { class: 'pdp__desc', text: product.description }) : null,
        h('div', { class: 'stock-box' },
          stockBadge(product),
          h('p', { text: known
            ? `Live from our warehouse at ${checkedAt}. Your items are reserved when you place the order.`
            : 'We can’t check live stock right now. Availability is confirmed when you place the order.' })),
        h('div', { class: 'buy-row' },
          allowed ? stepper({ value: 1, label: 'quantity', onChange: (value) => { qty = value; } }) : null,
          addButton()),
        h('div', { class: 'facts' },
          h('div', {}, 'Stock', h('b', { text: known ? 'Checked live' : 'Unknown right now' })),
          h('div', {}, 'Reserved', h('b', { text: 'When you order' })),
          h('div', {}, 'Cancelled orders', h('b', { text: 'Return the stock' }))))),
    h('div', { class: 'sticky-buy' }, h('span', { class: 'price', text: money(product.price) }), addButton())));
}
