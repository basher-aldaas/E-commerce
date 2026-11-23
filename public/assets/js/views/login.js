// Sign in. Customers land on the shop, admins on the dashboard, and an expired
// session returns to the page the person was on.

import { h } from '../core/dom.js';
import { api, fieldErrors } from '../core/api.js';
import { homeFor, navigate } from '../core/router.js';
import { session } from '../core/store.js';
import { banner } from '../ui/feedback.js';
import { field } from '../ui/parts.js';
import { pageTitle } from '../ui/chrome.js';

export function authCard(title, subtitle, form, alt) {
  return h('div', { class: 'auth' }, h('div', { class: 'auth__card' },
    h('div', { class: 'page-head' }, h('h1', { text: title }), subtitle ? h('p', { text: subtitle }) : null),
    form, alt));
}

export function loginView(main, ctx) {
  pageTitle('Sign in');
  const state = { email: '', password: '', errors: {}, message: ctx.query.get('expired') ? 'Your session ended. Please sign in again.' : null, busy: false };

  const render = () => {
    const email = h('input', { class: 'input', type: 'email', name: 'email', autocomplete: 'username', required: true, value: state.email, onInput: (e) => { state.email = e.target.value; } });
    const password = h('input', { class: 'input', type: 'password', name: 'password', autocomplete: 'current-password', required: true, value: state.password, onInput: (e) => { state.password = e.target.value; } });
    const submit = h('button', { class: 'btn btn--primary btn--block', type: 'submit', disabled: state.busy, text: state.busy ? 'Signing in…' : 'Sign in' });

    const fill = (address) => () => { state.email = address; state.password = 'password123'; render(); };
    const form = h('form', { novalidate: true, onSubmit: async (event) => {
      event.preventDefault();
      state.busy = true; state.errors = {}; state.message = null;
      render();
      try {
        const { data } = await api('POST', '/api/v1/auth/login', { body: { email: state.email, password: state.password } });
        session.set(data);
        navigate(session.takeReturnTo() || homeFor());
      } catch (error) {
        state.busy = false;
        state.errors = fieldErrors(error);
        state.message = Object.keys(state.errors).length ? null : error.message;
        render();
        main.querySelector('[aria-invalid="true"], .banner')?.focus();
      }
    } },
    state.message ? banner(ctx.query.get('expired') && !state.errors.email ? 'info' : 'bad', state.message, null, [], { alert: true }) : null,
    field({ id: 'login-email', label: 'Email', control: email, error: state.errors.email?.[0] }),
    field({ id: 'login-password', label: 'Password', control: password, error: state.errors.password?.[0] }),
    submit);

    main.replaceChildren(authCard('Sign in to Dukkan', 'Welcome back.', form, h('div', { class: 'auth__alt' },
      h('p', {}, 'New here? ', h('a', { href: '#/register', text: 'Create an account' })),
      h('div', { class: 'demo-accounts' },
        h('span', { text: 'Demo accounts (password123):' }),
        h('div', {},
          h('button', { type: 'button', class: 'chip', onClick: fill('user@system.com'), text: 'Customer' }),
          h('button', { type: 'button', class: 'chip', onClick: fill('admin@system.com'), text: 'Admin' }))))));
  };
  render();
}
