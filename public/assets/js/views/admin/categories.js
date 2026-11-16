// Admin categories: list and add.

import { h, mount } from '../../core/dom.js';
import { api, fieldErrors } from '../../core/api.js';
import { navigate } from '../../core/router.js';
import { categories } from '../../core/store.js';
import { banner, toast } from '../../ui/feedback.js';
import { field, skeletonBlock } from '../../ui/parts.js';
import { pageTitle } from '../../ui/chrome.js';

export async function adminCategoriesView(main, ctx) {
  pageTitle('Categories');
  const listBox = h('div', {}, skeletonBlock(4));
  const name = h('input', { class: 'input', name: 'name', required: true });
  const description = h('input', { class: 'input', name: 'description' });
  const submit = h('button', { type: 'submit', class: 'btn btn--primary', text: 'Add category' });
  const formBox = h('div');

  const drawForm = (errors = {}, message = null) => mount(formBox, h('form', { class: 'panel panel--pad', novalidate: true, onSubmit: async (event) => {
    event.preventDefault();
    submit.disabled = true;
    try {
      await api('POST', '/api/v1/categories', { body: { name: name.value, description: description.value || null } });
      categories.forget();
      toast(`Added ${name.value}.`);
      name.value = '';
      description.value = '';
      navigate(location.hash);
    } catch (error) {
      submit.disabled = false;
      const fields = fieldErrors(error);
      drawForm(fields, Object.keys(fields).length ? null : error.message);
      formBox.querySelector('[aria-invalid="true"], .banner')?.focus();
    }
  } },
  h('h2', { class: 'panel__title', text: 'New category' }),
  message ? banner('bad', 'We couldn’t add the category.', message, [], { alert: true }) : null,
  h('div', { class: 'form-grid' },
    field({ id: 'c-name', label: 'Name', control: name, error: errors.name?.[0] }),
    field({ id: 'c-description', label: 'Description (optional)', control: description, error: errors.description?.[0] })),
  h('div', {}, submit)));

  drawForm();
  mount(main, h('div', { class: 'wrap page' }, h('div', { class: 'page-head' }, h('h1', { text: 'Categories' })), formBox, listBox));

  categories.forget();
  let list;
  try {
    list = await categories.load(api);
  } catch (error) {
    if (ctx.isCurrent()) mount(listBox, banner('bad', 'We couldn’t load the categories.', error.message, [], { alert: true }));
    return;
  }
  if (!ctx.isCurrent()) return;
  mount(listBox, h('div', { class: 'panel table-wrap' }, h('table', { class: 'table table--stack' },
    h('thead', {}, h('tr', {}, h('th', { scope: 'col', text: 'Name' }), h('th', { scope: 'col', text: 'Description' }))),
    h('tbody', {}, list.map((c) => h('tr', {},
      h('td', { 'data-label': 'Name' }, h('a', { href: `#/shop?category_id=${c.id}`, text: c.name })),
      h('td', { 'data-label': 'Description', text: c.description || '—' })))))));
}
