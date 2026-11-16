// Toasts (polite announcements) and banners (in-page messages).

import { h } from '../core/dom.js';
import { icon } from './icons.js';

/** toast('Added Smart Watch to your cart', { link: ['View cart', '#/cart'] }) */
export function toast(message, { tone = 'info', link = null, timeout = 4500 } = {}) {
  const region = document.getElementById('toasts');
  if (!region) return;
  const el = h('div', { class: `toast${tone === 'error' ? ' toast--error' : ''}`, role: tone === 'error' ? 'alert' : 'status' },
    h('span', { text: message }),
    link ? h('a', { href: link[1], text: link[0] }) : null);
  region.append(el);
  setTimeout(() => el.remove(), timeout);
}

const BANNER_ICON = { warn: 'alert', bad: 'alert', info: 'info', ok: 'check' };

/**
 * banner('warn', 'Live stock isn\'t available right now.', 'You can keep browsing…', [buttons])
 * role="alert" makes screen readers announce it when it appears (checkout errors).
 */
export function banner(tone, title, text = null, actions = [], { alert = false } = {}) {
  return h('div', { class: `banner banner--${tone}`, role: alert ? 'alert' : null, tabIndex: alert ? -1 : null },
    icon(BANNER_ICON[tone] || 'info'),
    h('div', { class: 'banner__body' },
      h('p', {}, h('span', { class: 'banner__title', text: title }), text ? ` ${text}` : null),
      actions.length ? h('div', { class: 'banner__actions' }, actions) : null));
}
