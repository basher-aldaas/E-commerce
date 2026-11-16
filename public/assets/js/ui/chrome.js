// The frame around every screen: the store header (with search and categories),
// the phone tab bar, the admin sidebar, and the footer. Screens render into the
// <main> element this module creates.

import { h, mount } from '../core/dom.js';
import { currentPath, href, homeFor, navigate } from '../core/router.js';
import { cart, categories, session } from '../core/store.js';
import { api } from '../core/api.js';
import { icon } from './icons.js';
import { openSheet } from './overlay.js';
import { toast } from './feedback.js';

export const main = h('main', { id: 'main', tabIndex: -1 });

/** Sets the tab title: "Cart · Dukkan". */
export function pageTitle(text) {
  document.title = text ? `${text} · Dukkan` : 'Dukkan';
}

const brand = (to) => h('a', { class: 'brand', href: to }, h('span', { class: 'brand__mark', 'aria-hidden': 'true' }), h('span', { text: 'Dukkan' }));

async function logout() {
  try {
    await api('POST', '/api/v1/auth/logout');
  } catch {
    /* the token is dropped locally either way */
  }
  session.clear();
  toast('You are signed out.');
  navigate('#/login');
}

function isActive(target) {
  const { path } = currentPath();
  const base = target.slice(1).split('?')[0];
  if (base === '/admin') return path === '/admin';
  if (base === '/shop') return path === '/shop' || path.startsWith('/product/');
  return path === base || path.startsWith(`${base}/`);
}

const current = (target) => (isActive(target) ? 'page' : null);

function cartCount() {
  return cart.count > 0 ? h('span', { class: 'count-pill', text: cart.count > 99 ? '99+' : cart.count }) : null;
}

function searchForm(id) {
  const { path, query } = currentPath();
  const input = h('input', { type: 'search', id, name: 'search', placeholder: 'Search products', autocomplete: 'off', value: path === '/shop' ? query.get('search') || '' : '', 'aria-label': 'Search products' });
  return h('form', { class: 'search', role: 'search', onSubmit: (event) => {
    event.preventDefault();
    const { query: q } = currentPath();
    navigate(href('/shop', { search: input.value.trim(), category_id: path === '/shop' ? q.get('category_id') : null }));
  } }, icon('search'), input);
}

function accountMenu() {
  const user = session.user;
  return h('details', { class: 'account-menu' },
    h('summary', { class: 'header-link', 'aria-label': `Account: ${user.name}` }, icon('user'), h('span', { text: user.name.split(' ')[0] })),
    h('div', { class: 'account-menu__pop' },
      h('div', { class: 'who' }, h('strong', { text: user.name }), h('span', { class: 'muted small', text: session.isAdmin ? 'Admin' : 'Customer' })),
      session.isAdmin ? h('a', { href: '#/admin' }, icon('dashboard'), 'Admin dashboard') : null,
      h('a', { href: '#/orders' }, icon('orders'), session.isAdmin ? 'All orders' : 'My orders'),
      h('button', { type: 'button', onClick: logout }, icon('logout'), 'Sign out')));
}

function mobileMenu() {
  const links = session.isAdmin
    ? [['#/admin', 'Admin dashboard'], ['#/shop', 'Shop'], ['#/orders', 'Orders']]
    : [['#/shop', 'Shop'], ['#/cart', 'Cart'], ['#/orders', 'My orders']];
  const body = h('nav', { class: 'filters', 'aria-label': 'Menu' },
    h('div', { class: 'filters__group' }, links.map(([to, label]) => h('a', { class: 'header-link', href: to, text: label }))),
    h('div', { class: 'filters__group' },
      h('h2', { text: 'Categories' }),
      h('div', { class: 'filters__group', id: 'menu-categories' }, h('span', { class: 'muted small', text: 'Loading…' }))),
    h('div', { class: 'filters__group' },
      h('p', { class: 'small muted', text: `Signed in as ${session.user.name}` }),
      h('button', { type: 'button', class: 'btn btn--secondary', onClick: logout }, icon('logout'), 'Sign out')));
  const sheet = openSheet({ title: 'Menu', side: 'left', body });
  body.addEventListener('click', (event) => { if (event.target.closest('a')) sheet.close(); });
  categories.load(api).then((list) => {
    const target = body.querySelector('#menu-categories');
    if (target) mount(target, list.map((c) => h('a', { class: 'header-link', href: href('/shop', { category_id: c.id }), text: c.name })));
  }).catch(() => {});
}

function categoryStrip() {
  const nav = h('nav', { class: 'wrap', 'aria-label': 'Categories' });
  const strip = h('div', { class: 'category-strip' }, nav);
  const { path, query } = currentPath();
  const active = path === '/shop' ? query.get('category_id') : null;
  categories.load(api).then((list) => {
    mount(nav,
      h('a', { href: '#/shop', 'aria-current': path === '/shop' && !active ? 'true' : null, text: 'All products' }),
      list.map((c) => h('a', { href: href('/shop', { category_id: c.id }), 'aria-current': String(c.id) === active ? 'true' : null, text: c.name })));
  }).catch(() => strip.remove());
  return strip;
}

function storeHeader() {
  const actions = session.isAdmin
    ? [h('a', { class: 'header-link', href: '#/orders', 'aria-current': current('#/orders') }, icon('orders'), h('span', { text: 'Orders' })),
      accountMenu(),
      h('a', { class: 'cart-button', href: '#/admin' }, icon('dashboard'), h('span', { text: 'Admin' }))]
    : [h('a', { class: 'header-link', href: '#/orders', 'aria-current': current('#/orders') }, icon('orders'), h('span', { text: 'Orders' })),
      accountMenu(),
      h('a', { class: 'cart-button', href: '#/cart', 'aria-label': `Cart, ${cart.count} items` }, icon('cart'), h('span', { text: 'Cart' }), cartCount())];

  return h('header', { class: 'site-header' },
    h('div', { class: 'wrap' },
      h('div', { class: 'site-header__bar' },
        h('button', { type: 'button', class: 'btn btn--ghost btn--icon menu-button', 'aria-label': 'Open menu', onClick: mobileMenu }, icon('menu')),
        brand(homeFor()),
        searchForm('search-desktop'),
        h('div', { class: 'header-actions' }, actions)),
      h('div', { class: 'search-row' }, searchForm('search-mobile'))),
    categoryStrip());
}

function tabbar() {
  const items = session.isAdmin
    ? [['#/admin', 'Dashboard', 'dashboard'], ['#/admin/orders', 'Orders', 'orders'], ['#/admin/products', 'Products', 'box'], ['#/admin/system', 'System', 'activity']]
    : [['#/shop', 'Shop', 'store'], ['#/shop?focus=search', 'Search', 'search'], ['#/cart', 'Cart', 'cart'], ['#/orders', 'Orders', 'orders']];
  return h('nav', { class: 'tabbar', 'aria-label': 'Main' }, items.map(([to, label, name]) => {
    const isSearch = to.includes('focus=search');
    return h('a', { href: to, 'aria-current': !isSearch && current(to) ? 'page' : null, onClick: isSearch ? (event) => {
      event.preventDefault();
      if (currentPath().path !== '/shop') navigate('#/shop');
      requestAnimationFrame(() => document.getElementById('search-mobile')?.focus());
    } : null }, icon(name), h('span', { text: label }), to === '#/cart' ? cartCount() : null);
  }));
}

function footer() {
  return h('footer', { class: 'site-footer' }, h('div', { class: 'wrap' },
    h('span', { text: 'Dukkan · Stock is checked live with our warehouse and reserved when you order.' }),
    h('span', { text: 'Prices in US dollars.' })));
}

const ADMIN_LINKS = [
  ['#/admin', 'Dashboard', 'dashboard'],
  ['#/admin/orders', 'Orders', 'orders'],
  ['#/admin/products', 'Products', 'box'],
  ['#/admin/categories', 'Categories', 'folder'],
  ['#/admin/system', 'System', 'activity'],
];

function adminSide() {
  return h('nav', { class: 'admin__side', 'aria-label': 'Admin' },
    brand('#/admin'),
    ADMIN_LINKS.map(([to, label, name]) => h('a', { href: to, 'aria-current': current(to), title: label }, icon(name), h('span', { text: label }))),
    h('a', { href: '#/shop', title: 'View store' }, icon('store'), h('span', { text: 'View store' })),
    h('span', { class: 'sep' }),
    h('div', { class: 'account' },
      h('span', { class: 'who', text: session.user.name }),
      h('button', { type: 'button', onClick: logout, 'aria-label': 'Sign out' }, h('span', { text: 'Sign out' }))));
}

function adminTop() {
  const openMenu = () => {
    const body = h('nav', { class: 'filters', 'aria-label': 'Admin' },
      h('div', { class: 'filters__group' },
        [...ADMIN_LINKS, ['#/shop', 'View store', 'store']].map(([to, label, name]) => h('a', { class: 'header-link', href: to, 'aria-current': current(to) }, icon(name), label))),
      h('button', { type: 'button', class: 'btn btn--secondary', onClick: logout }, icon('logout'), 'Sign out'));
    const sheet = openSheet({ title: 'Admin', side: 'left', body });
    body.addEventListener('click', (event) => { if (event.target.closest('a')) sheet.close(); });
  };
  return h('div', { class: 'admin-top' },
    h('button', { type: 'button', class: 'btn btn--ghost btn--icon', 'aria-label': 'Open menu', onClick: openMenu }, icon('menu')),
    brand('#/admin'));
}

/** Rebuilds the frame for a layout and returns the <main> element. */
export function renderChrome(root, layout) {
  if (layout === 'admin') {
    mount(root, h('div', { class: 'admin' }, adminSide(), h('div', { class: 'admin__main' }, adminTop(), main)), tabbar());
  } else if (layout === 'auth') {
    mount(root, h('header', { class: 'site-header' }, h('div', { class: 'wrap' }, h('div', { class: 'site-header__bar' }, brand('#/login')))), main);
  } else {
    mount(root, storeHeader(), main, footer(), tabbar());
  }
  return main;
}

/** Updates only the cart counters, so typing in a quantity doesn't rebuild the page. */
export function refreshCartBadges(root) {
  for (const link of root.querySelectorAll('a[href="#/cart"]')) {
    link.querySelector('.count-pill')?.remove();
    const pill = cartCount();
    if (pill) link.append(pill);
    if (link.classList.contains('cart-button')) link.setAttribute('aria-label', `Cart, ${cart.count} items`);
  }
}
