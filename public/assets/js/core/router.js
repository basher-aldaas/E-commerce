// Hash router: every screen has its own address (#/product/15, #/orders/3,
// #/shop?category=7&in_stock=1), so links can be shared, reloads keep the place
// and the browser's back button works.
//
// Role checks here only decide what to show. The API enforces every permission
// itself; a customer who forces an admin address still gets 403 from the server.

import { session } from './store.js';

const routes = [];

export function route(pattern, view, { auth = true, role = null, layout = 'store' } = {}) {
  const keys = [];
  const regex = new RegExp(`^${pattern.replace(/:(\w+)/g, (_, key) => { keys.push(key); return '([^/]+)'; })}$`);
  routes.push({ regex, keys, view, auth, role, layout });
}

export function currentPath() {
  const hash = location.hash.slice(1) || '/';
  const [path, query = ''] = hash.split('?');
  return { path, query: new URLSearchParams(query) };
}

export const homeFor = () => (!session.user ? '#/login' : session.isAdmin ? '#/admin' : '#/shop');

export function navigate(href) {
  if (location.hash === href) window.dispatchEvent(new HashChangeEvent('hashchange'));
  else location.hash = href;
}

/** Builds "#/path?key=value" from an object, leaving out empty values. */
export function href(path, params = {}) {
  const query = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined && v !== false));
  const text = query.toString();
  return `#${path}${text ? `?${text}` : ''}`;
}

let renderCount = 0;

export function resolve() {
  const { path, query } = currentPath();
  for (const r of routes) {
    const match = path.match(r.regex);
    if (!match) continue;
    if (r.auth && !session.user) return { redirect: '#/login' };
    if (!r.auth && session.user) return { redirect: homeFor() };
    if (r.role === 'admin' && !session.isAdmin) return { redirect: homeFor() };
    if (r.role === 'customer' && session.isAdmin) return { redirect: homeFor() };
    const params = Object.fromEntries(r.keys.map((k, i) => [k, decodeURIComponent(match[i + 1])]));
    const token = ++renderCount;
    // A slow response for a page the user already left must not draw over the new one.
    return { view: r.view, layout: r.layout, ctx: { params, query, path, isCurrent: () => token === renderCount } };
  }
  return { redirect: homeFor() };
}
