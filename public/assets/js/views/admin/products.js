// Admin products: the catalog with live stock (read-only: stock is changed only
// in the Warehouse, with its CLI), and a form to add a product.

import { h, mount } from '../../core/dom.js';
import { api, fieldErrors } from '../../core/api.js';
import { href, navigate } from '../../core/router.js';
import { categories } from '../../core/store.js';
import { money } from '../../core/format.js';
import { banner, toast } from '../../ui/feedback.js';
import { openSheet } from '../../ui/overlay.js';
import { icon } from '../../ui/icons.js';
import { field, pager, productImage, skeletonBlock, stockBadge } from '../../ui/parts.js';
import { pageTitle } from '../../ui/chrome.js';

function productForm(list, onDone) {
  const state = { errors: {}, message: null, busy: false };
  const form = h('form', { class: 'form-grid', novalidate: true });
  const preview = h('img', { class: 'image-preview', alt: 'Selected image preview', hidden: true });
  const image = h('input', { class: 'input', type: 'file', name: 'image', accept: 'image/jpeg,image/png,image/webp', onChange: () => {
    const file = image.files[0];
    preview.hidden = !file;
    if (file) preview.src = URL.createObjectURL(file); // blob: is allowed by img-src
  } });
  const controls = {
    name: h('input', { class: 'input', name: 'name', required: true }),
    price: h('input', { class: 'input', name: 'price', type: 'number', min: 0, step: '0.01', inputMode: 'decimal', required: true }),
    category_id: h('select', { class: 'select', name: 'category_id', required: true },
      h('option', { value: '', text: 'Choose a category' }), list.map((c) => h('option', { value: c.id, text: c.name }))),
    description: h('textarea', { class: 'textarea', name: 'description' }),
  };
  const submit = h('button', { type: 'submit', class: 'btn btn--primary', text: 'Add product' });
  const status = h('div', { class: 'span-all' });

  const draw = () => mount(form,
    status,
    field({ id: 'p-name', label: 'Name', control: controls.name, error: state.errors.name?.[0] }),
    field({ id: 'p-price', label: 'Price (USD)', control: controls.price, error: state.errors.price?.[0] }),
    field({ id: 'p-category', label: 'Category', control: controls.category_id, error: state.errors.category_id?.[0] }),
    h('div', { class: 'span-all' }, field({ id: 'p-description', label: 'Description', control: controls.description, error: state.errors.description?.[0] })),
    h('div', { class: 'span-all' }, field({ id: 'p-image', label: 'Image (optional, up to 2 MB)', control: image, error: state.errors.image?.[0] }), preview),
    h('p', { class: 'span-all small muted', text: 'A new product starts as “Coming soon”: it has no stock until an operator adds it in the warehouse.' }),
    h('div', { class: 'span-all' }, submit));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    submit.disabled = true;
    submit.textContent = 'Adding…';
    try {
      const { data } = await api('POST', '/api/v1/products', { body: new FormData(form) });
      toast(`Added ${data.name}.`);
      onDone();
    } catch (error) {
      state.errors = fieldErrors(error);
      draw();
      if (!Object.keys(state.errors).length) mount(status, banner('bad', 'We couldn’t add the product.', error.message, [], { alert: true }));
      form.querySelector('[aria-invalid="true"], .banner')?.focus();
      submit.disabled = false;
      submit.textContent = 'Add product';
    }
  });
  draw();
  return form;
}

export async function adminProductsView(main, ctx) {
  pageTitle('Products');
  const page = Number(ctx.query.get('page') || 1);
  const body = h('div', {}, skeletonBlock(6));
  const list = await categories.load(api).catch(() => []);

  const openForm = () => {
    const sheet = openSheet({ title: 'Add a product', body: productForm(list, () => { sheet.close(); categories.forget(); navigate(href('/admin/products', {})); }) });
  };

  mount(main, h('div', { class: 'wrap page' },
    h('div', { class: 'toolbar' },
      h('div', { class: 'page-head' }, h('h1', { text: 'Products' }), h('p', { text: 'Stock is live from the warehouse and managed there.' })),
      h('button', { type: 'button', class: 'btn btn--primary', onClick: openForm }, icon('plus'), 'Add product')),
    body));

  let result;
  try {
    result = await api('GET', `/api/v2/products?per_page=15${page > 1 ? `&page=${page}` : ''}`);
  } catch (error) {
    if (ctx.isCurrent()) mount(body, banner('bad', 'We couldn’t load the products.', error.message, [], { alert: true }));
    return;
  }
  if (!ctx.isCurrent()) return;

  mount(body,
    h('div', { class: 'panel table-wrap' }, h('table', { class: 'table table--stack' },
      h('thead', {}, h('tr', {}, ['', 'Product', 'Category', 'Live stock'].map((t) => h('th', { scope: 'col', text: t })), h('th', { scope: 'col', class: 'num', text: 'Price' }))),
      h('tbody', {}, result.data.map((p) => h('tr', {},
        h('td', { 'data-label': 'Image' }, h('div', { class: 'line__media' }, productImage(p, { alt: '' }))),
        h('td', { 'data-label': 'Product' }, h('a', { href: `#/product/${p.id}`, text: p.name }), h('div', { class: 'small muted', text: `#${p.id}` })),
        h('td', { 'data-label': 'Category', text: p.category?.name ?? '—' }),
        h('td', { 'data-label': 'Live stock' }, stockBadge(p), p.stock !== null && p.stock !== undefined ? h('span', { class: 'small muted num', text: ` ${p.stock} units` }) : null),
        h('td', { 'data-label': 'Price', class: 'num', text: money(p.price) })))))),
    result.pagination ? pager('/admin/products', {}, result.pagination) : null);
}
