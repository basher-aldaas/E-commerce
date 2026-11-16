// Safe DOM building. Every piece of data becomes a text node or a property, never
// HTML, so product names or descriptions cannot inject markup (no HTML strings here,
// which also lets the page run under a CSP without 'unsafe-inline').

/**
 * h('a', { href: '#/shop', class: 'x', onClick: fn }, 'text', child, [more])
 * - on<Event> functions become addEventListener calls (no inline handlers)
 * - "class", "text", "dataset" and "aria*"/"data-*" are handled explicitly
 */
export function h(tag, props, ...children) {
  const el = document.createElement(tag);
  for (const [key, value] of Object.entries(props || {})) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') el.className = value;
    else if (key === 'text') el.textContent = String(value);
    else if (key === 'dataset') Object.assign(el.dataset, value);
    else if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2).toLowerCase(), value);
    else if (key.startsWith('aria') || key.startsWith('data-') || key === 'role' || key === 'for') el.setAttribute(key, value === true ? 'true' : String(value));
    else if (key in el) el[key] = value;
    else el.setAttribute(key, value === true ? '' : String(value));
  }
  append(el, children);
  return el;
}

export function append(parent, children) {
  for (const child of [children].flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    parent.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return parent;
}

/** Replaces all children of an element. */
export function mount(parent, ...children) {
  parent.replaceChildren();
  return append(parent, children);
}

export const $ = (selector, root = document) => root.querySelector(selector);
