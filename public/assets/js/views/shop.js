// The catalog (API v2): search, category, price range and "in stock only", all
// kept in the address (#/shop?category_id=1&in_stock=1) so back/forward and
// reloads keep the place. Names and prices may come from the Redis cache; the
// stock is always read live from the Warehouse.

import { h, mount } from '../core/dom.js';
import { api } from '../core/api.js';
import { href, navigate } from '../core/router.js';
import { cart, categories } from '../core/store.js';
import { money, stockKnown } from '../core/format.js';
import { banner, toast } from '../ui/feedback.js';
import { icon } from '../ui/icons.js';
import { openSheet } from '../ui/overlay.js';
import { emptyState, pager, productImage, skeletonCards, stockBadge } from '../ui/parts.js';
import { pageTitle } from '../ui/chrome.js';

const FILTER_KEYS = ['search', 'category_id', 'min_price', 'max_price', 'in_stock'];

export function canAddToCart(product) {
  // Unknown availability is allowed: the Warehouse decides when the order is placed.
  return product.stock_status !== 'out_of_stock' && product.stock_status !== 'not_stocked';
}

export function addToCart(product, qty = 1) {
  cart.add(product, qty);
  toast(`Added ${product.name} to your cart.`, { link: ['View cart', '#/cart'] });
}

export function productCard(product) {
  const allowed = canAddToCart(product);
  return h('article', { class: 'product-card' },
    h('div', { class: 'product-card__media' }, productImage(product, { alt: '' })),
    h('div', { class: 'product-card__body' },
      product.category ? h('span', { class: 'product-card__cat', text: product.category.name }) : null,
      h('a', { class: 'product-card__name', href: `#/product/${product.id}`, text: product.name }),
      stockBadge(product),
      h('div', { class: 'product-card__row' },
        h('span', { class: 'price', text: money(product.price) }),
        allowed
          ? h('button', { type: 'button', class: 'btn btn--outline btn--small', 'aria-label': `Add ${product.name} to cart`, onClick: () => addToCart(product) }, 'Add')
          : h('button', { type: 'button', class: 'btn btn--secondary btn--small', disabled: true, text: product.stock_status === 'not_stocked' ? 'Unavailable' : 'Sold out' }))));
}

function filterForm(query, list, onApply) {
  const value = (key) => query.get(key) || '';
  const selected = value('category_id');
  const form = h('form', { class: 'filters', onSubmit: (event) => { event.preventDefault(); onApply(); } },
    h('div', { class: 'filters__group' },
      h('h2', { text: 'Category' }),
      h('label', { class: 'check' }, h('input', { type: 'radio', name: 'category_id', value: '', checked: !selected }), 'All categories'),
      list.map((c) => h('label', { class: 'check' }, h('input', { type: 'radio', name: 'category_id', value: c.id, checked: String(c.id) === selected }), c.name))),
    h('div', { class: 'filters__group' },
      h('h2', { text: 'Price' }),
      h('div', { class: 'filters__range' },
        h('input', { class: 'input', type: 'number', name: 'min_price', min: 0, step: '0.01', inputMode: 'decimal', placeholder: '$ min', 'aria-label': 'Minimum price', value: value('min_price') }),
        h('input', { class: 'input', type: 'number', name: 'max_price', min: 0, step: '0.01', inputMode: 'decimal', placeholder: '$ max', 'aria-label': 'Maximum price', value: value('max_price') }))),
    h('div', { class: 'filters__group' },
      h('h2', { text: 'Availability' }),
      h('label', { class: 'switch' }, h('input', { type: 'checkbox', name: 'in_stock', value: '1', checked: value('in_stock') === '1', role: 'switch' }), 'In stock only')),
    h('div', { class: 'filters__group' },
      h('button', { type: 'submit', class: 'btn btn--primary', text: 'Apply filters' }),
      h('a', { class: 'btn btn--ghost', href: href('/shop', { search: value('search') }), text: 'Clear filters' })));
  return form;
}

function formToParams(form, query) {
  const data = new FormData(form);
  return {
    search: query.get('search') || '',
    category_id: data.get('category_id') || '',
    min_price: data.get('min_price') || '',
    max_price: data.get('max_price') || '',
    in_stock: data.get('in_stock') ? 1 : '',
  };
}

export async function shopView(main, ctx) {
  const { query } = ctx;
  const params = Object.fromEntries(FILTER_KEYS.map((k) => [k, query.get(k) || '']));
  const page = Number(query.get('page') || 1);

  const list = await categories.load(api).catch(() => []);
  if (!ctx.isCurrent()) return;
  const category = list.find((c) => String(c.id) === params.category_id);
  const heading = params.search ? `Results for “${params.search}”` : category ? category.name : 'All products';
  pageTitle(heading);

  const sideForm = filterForm(query, list, () => navigate(href('/shop', formToParams(sideForm, query))));
  const activeCount = ['category_id', 'min_price', 'max_price', 'in_stock'].filter((k) => params[k]).length;

  const openFilters = () => {
    const form = filterForm(query, list, () => { sheet.close(); navigate(href('/shop', formToParams(form, query))); });
    const sheet = openSheet({ title: 'Filters', side: window.innerWidth < 768 ? 'bottom' : 'right', body: form });
  };

  const chips = [];
  if (params.search) chips.push(['search', `“${params.search}”`]);
  if (category) chips.push(['category_id', category.name]);
  if (params.min_price) chips.push(['min_price', `From ${money(params.min_price)}`]);
  if (params.max_price) chips.push(['max_price', `Up to ${money(params.max_price)}`]);
  if (params.in_stock) chips.push(['in_stock', 'In stock only']);

  const count = h('p', { class: 'muted', 'aria-live': 'polite' });
  const notices = h('div', { class: 'shop__notices' });
  const grid = h('div', { class: 'product-grid', 'aria-busy': 'true' }, skeletonCards(8));
  const paging = h('div');

  mount(main, h('div', { class: 'wrap page' },
    h('div', { class: 'shop' },
      h('aside', { class: 'shop__side', 'aria-label': 'Filters' }, h('div', { class: 'filters--side' }, sideForm)),
      h('section', { class: 'shop__results', 'aria-labelledby': 'shop-title' },
        h('div', { class: 'page-head' }, h('h1', { id: 'shop-title', text: heading }), count),
        h('div', { class: 'shop__toolbar' },
          h('button', { type: 'button', class: 'btn btn--secondary btn--small shop__filter-button', onClick: openFilters }, icon('filter'), activeCount ? `Filters (${activeCount})` : 'Filters'),
          chips.map(([key, label]) => h('a', { class: 'chip', href: href('/shop', { ...params, [key]: '' }), 'aria-label': `Remove filter ${label}` }, label, icon('close')))),
        notices, grid, paging))));

  if (query.get('focus') === 'search') requestAnimationFrame(() => document.getElementById('search-mobile')?.focus());

  const apiQuery = new URLSearchParams(Object.entries({ ...params, page: page > 1 ? page : '', per_page: 12 }).filter(([, v]) => v !== ''));
  let result;
  try {
    result = await api('GET', `/api/v2/products?${apiQuery}`);
  } catch (error) {
    if (!ctx.isCurrent()) return;
    grid.removeAttribute('aria-busy');
    grid.replaceChildren();
    if (params.in_stock && (error.status === 503 || error.status === 504)) {
      mount(notices, banner('bad', '“In stock only” needs live stock.', 'We can’t filter by availability while the warehouse can’t be reached.', [
        h('a', { class: 'btn btn--secondary btn--small', href: href('/shop', { ...params, in_stock: '' }), text: 'Show all products' }),
      ], { alert: true }));
    } else {
      mount(notices, banner('bad', 'We couldn’t load the products.', error.message, [
        h('button', { type: 'button', class: 'btn btn--secondary btn--small', onClick: () => navigate(location.hash), text: 'Try again' }),
      ], { alert: true }));
    }
    return;
  }
  if (!ctx.isCurrent()) return;

  const products = result.data;
  const total = result.pagination?.total ?? products.length;
  count.textContent = `${total} ${total === 1 ? 'product' : 'products'}`;
  grid.removeAttribute('aria-busy');

  if (products.some((p) => !stockKnown(p.stock_status))) {
    mount(notices, banner('warn', 'Live stock isn’t available right now.', 'You can keep browsing and adding to your cart. We check availability with our warehouse when you place the order.'));
  }

  if (!products.length) {
    mount(grid, emptyState('search', 'No products match', 'Try another search or clear some filters.', h('a', { class: 'btn btn--secondary', href: '#/shop', text: 'Show all products' })));
    grid.classList.remove('product-grid');
    return;
  }
  mount(grid, products.map(productCard));
  mount(paging, result.pagination ? pager('/shop', params, result.pagination) : null);
}
