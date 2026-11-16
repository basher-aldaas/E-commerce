// Client state: the session (JWT + user) and the cart, kept in localStorage so
// they survive a reload. Storage can be unavailable (private mode), so every
// access is guarded and the app still works in memory.

import { recorder } from './recorder.js';

const read = (key, fallback) => {
  try {
    const value = JSON.parse(localStorage.getItem(key));
    return value ?? fallback;
  } catch {
    return fallback;
  }
};
const write = (key, value) => {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch {
    /* storage unavailable: keep the in-memory value */
  }
};

const listeners = new Set();
const notify = () => listeners.forEach((fn) => fn());
export const onChange = (fn) => { listeners.add(fn); return () => listeners.delete(fn); };

export const session = {
  token: read('token', null),
  user: read('user', null),
  get isAdmin() { return Boolean(this.user && this.user.roles.includes('admin')); },
  set(auth) {
    this.token = auth.access_token;
    this.user = auth.user;
    write('token', this.token);
    write('user', this.user);
    notify();
  },
  clear() {
    this.token = null;
    this.user = null;
    write('token', null);
    write('user', null);
    cart.clear();
    recorder.clear();
    notify();
  },
  /** The server rejected the token: sign out and come back here after logging in. */
  expire() {
    const back = location.hash && !location.hash.startsWith('#/login') ? location.hash : '';
    this.clear();
    try {
      sessionStorage.setItem('return-to', back);
    } catch {
      /* no storage: land on the home screen after login */
    }
    location.hash = '#/login?expired=1';
  },
  /** Where to go after logging in (set by expire()), read once. */
  takeReturnTo() {
    try {
      const back = sessionStorage.getItem('return-to');
      sessionStorage.removeItem('return-to');
      return back || null;
    } catch {
      return null;
    }
  },
};

// The cart keeps exactly what the customer chose. It never limits a quantity
// to the stock shown on screen: the Warehouse checks stock when the order is
// placed, and its answer is shown on the line.
export const cart = {
  lines: read('cart', []).filter((l) => Number.isInteger(l.id) && Number.isInteger(l.qty) && l.qty > 0)
    .map(({ id, name, price, image, qty }) => ({ id, name, price, image, qty })),
  save() { write('cart', this.lines); notify(); },
  get count() { return this.lines.reduce((n, l) => n + l.qty, 0); },
  get total() { return this.lines.reduce((sum, l) => sum + Number(l.price) * l.qty, 0); },
  add(product, qty = 1) {
    const line = this.lines.find((l) => l.id === product.id);
    if (line) line.qty += qty;
    else this.lines.push({ id: product.id, name: product.name, price: product.price, image: product.image_url || null, qty });
    this.save();
  },
  setQty(id, qty) {
    const line = this.lines.find((l) => l.id === id);
    if (line) { line.qty = qty; this.save(); }
  },
  remove(id) { this.lines = this.lines.filter((l) => l.id !== id); this.save(); },
  clear() { this.lines = []; this.save(); },
};

// Loaded once per page load: categories change rarely and the list is small.
let categoriesPromise = null;
export const categories = {
  load(api) {
    categoriesPromise ??= (async () => {
      const all = [];
      for (let page = 1; ; page++) {
        const res = await api('GET', `/api/v1/categories?page=${page}`);
        all.push(...res.data.data);
        if (page >= res.data.last_page) break;
      }
      return all.sort((a, b) => a.name.localeCompare(b.name));
    })().catch((error) => { categoriesPromise = null; throw error; });
    return categoriesPromise;
  },
  forget() { categoriesPromise = null; },
};
