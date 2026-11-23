// Create a customer account, then sign in straight away.

import { h } from '../core/dom.js';
import { api, fieldErrors } from '../core/api.js';
import { homeFor, navigate } from '../core/router.js';
import { session } from '../core/store.js';
import { banner, toast } from '../ui/feedback.js';
import { field } from '../ui/parts.js';
import { pageTitle } from '../ui/chrome.js';
import { authCard } from './login.js';

export function registerView(main) {
  pageTitle('Create account');
  const state = { name: '', email: '', password: '', password_confirmation: '', errors: {}, message: null, busy: false };

  const input = (key, type, autocomplete) => h('input', { class: 'input', type, name: key, autocomplete, required: true, value: state[key], onInput: (e) => { state[key] = e.target.value; } });

  const render = () => {
    const form = h('form', { novalidate: true, onSubmit: async (event) => {
      event.preventDefault();
      state.busy = true; state.errors = {}; state.message = null;
      render();
      try {
        const { data } = await api('POST', '/api/v1/auth/register', { body: {
          name: state.name, email: state.email, password: state.password, password_confirmation: state.password_confirmation,
        } });
        session.set(data);
        toast(`Welcome to Dukkan, ${data.user.name.split(' ')[0]}.`);
        navigate(homeFor());
      } catch (error) {
        state.busy = false;
        state.errors = fieldErrors(error);
        state.message = Object.keys(state.errors).length ? null : error.message;
        render();
        main.querySelector('[aria-invalid="true"], .banner')?.focus();
      }
    } },
    state.message ? banner('bad', state.message, null, [], { alert: true }) : null,
    field({ id: 'reg-name', label: 'Full name', control: input('name', 'text', 'name'), error: state.errors.name?.[0] }),
    field({ id: 'reg-email', label: 'Email', control: input('email', 'email', 'email'), error: state.errors.email?.[0] }),
    field({ id: 'reg-password', label: 'Password', control: input('password', 'password', 'new-password'), error: state.errors.password?.[0], hint: 'At least 8 characters.' }),
    field({ id: 'reg-confirm', label: 'Confirm password', control: input('password_confirmation', 'password', 'new-password') }),
    h('button', { class: 'btn btn--primary btn--block', type: 'submit', disabled: state.busy, text: state.busy ? 'Creating account…' : 'Create account' }));

    main.replaceChildren(authCard('Create your account', 'It takes less than a minute.', form,
      h('p', { class: 'auth__alt' }, 'Already have an account? ', h('a', { href: '#/login', text: 'Sign in' }))));
  };
  render();
}
