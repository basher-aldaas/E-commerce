// Small building blocks shared by the screens.

import { h } from '../core/dom.js';
import { href } from '../core/router.js';
import { stockLabel } from '../core/format.js';
import { icon } from './icons.js';

/** The live Warehouse stock as a badge with a word (never color alone). */
export function stockBadge(product) {
  const { tone, text } = stockLabel(product.stock_status, product.stock);
  return h('span', { class: `badge badge--${tone}`, text });
}

/** A product photo, or a neutral placeholder when there is none or it fails to load. */
export function productImage(product, { alt = product.name } = {}) {
  const placeholder = () => h('span', { class: 'placeholder-img', 'aria-hidden': 'true' }, icon('image'));
  if (!product.image_url && !product.image) return placeholder();
  const img = h('img', { src: product.image_url || product.image, alt, loading: 'lazy', decoding: 'async' });
  img.addEventListener('error', () => img.replaceWith(placeholder()), { once: true });
  return img;
}

export function emptyState(iconName, title, text, action = null, { level = 'h2' } = {}) {
  return h('div', { class: 'empty' }, icon(iconName), h(level, { text: title }), text ? h('p', { text }) : null, action);
}

export function skeletonCards(count = 8) {
  return Array.from({ length: count }, () => h('div', { class: 'product-card', 'aria-hidden': 'true' },
    h('div', { class: 'skeleton skeleton--media' }),
    h('div', { class: 'product-card__body' },
      h('div', { class: 'skeleton skeleton--line' }),
      h('div', { class: 'skeleton skeleton--title' }),
      h('div', { class: 'skeleton skeleton--line' }))));
}

export function skeletonBlock(lines = 4) {
  return h('div', { class: 'panel panel--pad', 'aria-hidden': 'true' },
    Array.from({ length: lines }, () => h('div', { class: 'skeleton skeleton--line' })));
}

/** Page links that keep the other query parameters. */
export function pager(path, params, { current_page: page, last_page: last }) {
  if (!last || last <= 1) return null;
  const link = (p, label, aria) => (p < 1 || p > last
    ? h('span', { class: 'is-disabled', 'aria-hidden': 'true', text: label })
    : h('a', { href: href(path, { ...params, page: p === 1 ? null : p }), 'aria-label': aria, 'aria-current': p === page && label === String(p) ? 'page' : null, text: label }));
  const pages = [];
  for (let p = Math.max(1, page - 2); p <= Math.min(last, page + 2); p++) pages.push(link(p, String(p), `Page ${p}`));
  return h('nav', { class: 'pager', 'aria-label': 'Pages' }, link(page - 1, '‹', 'Previous page'), pages, link(page + 1, '›', 'Next page'));
}

/** Quantity stepper. onChange(newQty) fires on buttons and on a typed value. */
export function stepper({ value, min = 1, max = 2147483647, onChange, label }) {
  const input = h('input', { type: 'number', inputMode: 'numeric', min, max, value, 'aria-label': label });
  const set = (next) => {
    const qty = Math.min(max, Math.max(min, Number.parseInt(next, 10) || min));
    input.value = qty;
    minus.disabled = qty <= min;
    onChange(qty);
  };
  const minus = h('button', { type: 'button', 'aria-label': `Decrease ${label}`, onClick: () => set(Number(input.value) - 1) }, icon('minus'));
  const plus = h('button', { type: 'button', 'aria-label': `Increase ${label}`, onClick: () => set(Number(input.value) + 1) }, icon('plus'));
  input.addEventListener('change', () => set(input.value));
  minus.disabled = value <= min;
  return h('div', { class: 'stepper' }, minus, input, plus);
}

/** A labeled form field with an optional error, linked for screen readers. */
export function field({ id, label, control, error = null, hint = null }) {
  const errorId = `${id}-error`;
  const hintId = `${id}-hint`;
  control.id = id;
  if (error) {
    control.setAttribute('aria-invalid', 'true');
    control.setAttribute('aria-describedby', errorId);
  } else if (hint) {
    control.setAttribute('aria-describedby', hintId);
  }
  return h('div', { class: 'field' },
    h('label', { for: id, text: label }),
    control,
    hint && !error ? h('p', { class: 'field__hint', id: hintId, text: hint }) : null,
    error ? h('p', { class: 'field__error', id: errorId, text: error }) : null);
}
