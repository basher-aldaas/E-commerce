// Entry point: registers the screens, draws the frame for each route's layout,
// moves focus to the new screen's heading, and switches demo mode on or off.

import { h } from './core/dom.js';
import { navigate, resolve, route } from './core/router.js';
import { onChange, session } from './core/store.js';
import { ORDER_PLACED, STATUS_UPDATED, onOrderEvent, startRealtime } from './core/realtime.js';
import { main, renderChrome, refreshCartBadges } from './ui/chrome.js';
import { toast } from './ui/feedback.js';

import { loginView } from './views/login.js';
import { registerView } from './views/register.js';
import { shopView } from './views/shop.js';
import { productView } from './views/product.js';
import { cartView } from './views/cart.js';
import { ordersView } from './views/orders.js';
import { orderView } from './views/order.js';
import { adminHomeView } from './views/admin/home.js';
import { adminOrdersView } from './views/admin/orders.js';
import { adminProductsView } from './views/admin/products.js';
import { adminCategoriesView } from './views/admin/categories.js';
import { adminSystemView } from './views/admin/system.js';

route('/login', loginView, { auth: false, layout: 'auth' });
route('/register', registerView, { auth: false, layout: 'auth' });
route('/shop', shopView);
route('/product/:id', productView);
route('/cart', cartView, { role: 'customer' });
route('/orders', ordersView);
route('/orders/:id', orderView);
route('/admin', adminHomeView, { role: 'admin', layout: 'admin' });
route('/admin/orders', adminOrdersView, { role: 'admin', layout: 'admin' });
route('/admin/products', adminProductsView, { role: 'admin', layout: 'admin' });
route('/admin/categories', adminCategoriesView, { role: 'admin', layout: 'admin' });
route('/admin/system', adminSystemView, { role: 'admin', layout: 'admin' });

const content = h('div', { class: 'app__main' });
const demoSlot = h('aside', { class: 'app__demo', 'aria-label': 'Under the hood' });
const app = h('div', { class: 'app' }, content, demoSlot);

document.body.append(
  h('a', { class: 'skip-link', href: '#main', text: 'Skip to content', onClick: (event) => { event.preventDefault(); main.focus(); } }),
  app,
  h('div', { class: 'toasts', id: 'toasts', 'aria-live': 'polite' }),
);

let lastLayout = null;
// Clean-up of the screen being left (e.g. its live-update listeners).
let leaving = [];

async function render() {
  const result = resolve();
  if (result.redirect) {
    navigate(result.redirect);
    return;
  }
  leaving.splice(0).forEach((fn) => { try { fn(); } catch { /* ignore */ } });
  result.ctx.onLeave = (fn) => { leaving.push(fn); };
  renderChrome(content, result.layout);
  lastLayout = result.layout;
  main.replaceChildren();
  window.scrollTo(0, 0);
  try {
    await result.view(main, result.ctx);
  } catch (error) {
    if (result.ctx.isCurrent()) toast(error.message || 'Something went wrong.', { tone: 'error' });
  }
  // Move focus to the new screen's heading so screen readers announce it.
  if (result.ctx.isCurrent()) {
    const heading = main.querySelector('h1');
    if (heading) {
      heading.tabIndex = -1;
      heading.focus({ preventScroll: true });
    }
  }
}

onChange(() => {
  if (lastLayout) refreshCartBadges(content);
});
window.addEventListener('hashchange', render);
render();

// ---------- live order updates (WebSocket, see core/realtime.js) ----------
// Screens that show orders update themselves; these toasts tell the user
// wherever they are.
startRealtime();
onOrderEvent(STATUS_UPDATED, ({ order_id: id, status }) => {
  if (!session.isAdmin) toast(`Order #${id} is now ${status}.`, { link: ['View order', `#/orders/${id}`] });
});
onOrderEvent(ORDER_PLACED, ({ order }) => {
  toast(`New order #${order.id}.`, { link: ['View order', `#/orders/${order.id}`] });
});

// ---------- demo mode ----------
// Off by default. On with /?demo=1 or Ctrl+Alt+D, kept for this tab only. The
// panel's code is only downloaded while it is on, so customers never load it.

const DEMO_KEY = 'demo-mode';
const readDemo = () => {
  try {
    return sessionStorage.getItem(DEMO_KEY) === '1';
  } catch {
    return false;
  }
};
const writeDemo = (on) => {
  try {
    if (on) sessionStorage.setItem(DEMO_KEY, '1');
    else sessionStorage.removeItem(DEMO_KEY);
  } catch {
    /* no storage: demo mode lasts until reload */
  }
};

let demo = null;

async function setDemo(on) {
  writeDemo(on);
  if (on && !demo) {
    const { mountDemo } = await import('./demo/panel.js');
    demo = mountDemo({ app, slot: demoSlot, onTurnOff: () => setDemo(false) });
  } else if (!on && demo) {
    demo.destroy();
    demo = null;
    toast('Demo mode is off.');
  }
}

if (new URLSearchParams(location.search).get('demo') === '1') writeDemo(true);
if (readDemo()) setDemo(true);

window.addEventListener('keydown', (event) => {
  if (event.ctrlKey && event.altKey && event.key.toLowerCase() === 'd') {
    event.preventDefault();
    setDemo(!demo);
  }
});
