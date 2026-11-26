// Live order updates over a WebSocket: Pusher, through Laravel Echo.
//
// After login the browser asks the API for the public Pusher key
// (GET /api/v1/broadcasting/config), opens one WebSocket and subscribes to one
// private channel. Laravel checks the subscription at POST /broadcasting/auth
// with the same JWT as every API call (routes/channels.php):
//   customer -> private-orders.{userId}  (status changes of their own orders)
//   admin    -> private-admin.orders     (new orders + every status change)
//
// Messages only refresh what is already on screen. The API stays the source of
// truth: if Pusher is unreachable the interface works exactly as before, and a
// reload always shows the right status.

import { api } from './api.js';
import { onChange, session } from './store.js';

export const STATUS_UPDATED = 'order.status.updated';
export const ORDER_PLACED = 'order.placed';

const listeners = { [STATUS_UPDATED]: new Set(), [ORDER_PLACED]: new Set() };
const stateListeners = new Set();

// off: no session or broadcasting disabled · connecting · live · unavailable
let state = 'off';
let echo = null;
let runningFor = null; // the token the current connection belongs to

function setState(next) {
  if (state === next) return;
  state = next;
  stateListeners.forEach((fn) => fn(state));
}

export const liveState = () => state;

export function onLiveState(fn) {
  stateListeners.add(fn);
  return () => stateListeners.delete(fn);
}

/**
 * Calls fn(payload) for each message of that type. With a view's ctx the
 * listener is removed when the user leaves the screen.
 */
export function onOrderEvent(name, fn, ctx = null) {
  listeners[name].add(fn);
  const off = () => listeners[name].delete(fn);
  ctx?.onLeave?.(off);
  return off;
}

function emit(name, payload) {
  listeners[name].forEach((fn) => {
    try {
      fn(payload);
    } catch (error) {
      console.error(error);
    }
  });
}

async function loadEcho() {
  // pusher.min.js is a UMD bundle: imported as a module it sets self.Pusher.
  await import('../../vendor/pusher-8.6.0.min.js');
  const { default: Echo } = await import('../../vendor/laravel-echo-2.6.3.js');
  return Echo;
}

async function start(token) {
  setState('connecting');
  const stillMine = () => runningFor === token;

  let config;
  try {
    ({ data: config } = await api('GET', '/api/v1/broadcasting/config'));
  } catch {
    if (stillMine()) setState('unavailable');
    return;
  }
  if (!stillMine()) return;
  if (!config.enabled) {
    setState('off');
    return;
  }

  let Echo;
  try {
    Echo = await loadEcho();
  } catch {
    if (stillMine()) setState('unavailable');
    return;
  }
  if (!stillMine()) return;

  echo = new Echo({
    broadcaster: 'pusher',
    Pusher: self.Pusher,
    key: config.key,
    cluster: config.cluster,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'], // WebSocket only, no HTTP fallbacks
    channelAuthorization: {
      endpoint: config.auth_endpoint,
      transport: 'ajax',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    },
  });

  echo.connector.pusher.connection.bind('state_change', ({ current }) => {
    if (current === 'connecting' || current === 'initialized') setState('connecting');
    else if (current !== 'connected') setState('unavailable');
    // "connected" becomes "live" once the private channel is subscribed.
  });

  const channel = session.isAdmin ? echo.private('admin.orders') : echo.private(`orders.${session.user.id}`);
  channel
    .subscribed(() => setState('live'))
    .error(() => setState('unavailable')) // /broadcasting/auth refused or failed
    .listen(`.${STATUS_UPDATED}`, (payload) => emit(STATUS_UPDATED, payload));
  if (session.isAdmin) channel.listen(`.${ORDER_PLACED}`, (payload) => emit(ORDER_PLACED, payload));
}

function stop() {
  try {
    echo?.disconnect();
  } catch {
    /* already closed */
  }
  echo = null;
  setState('off');
}

/** Connects after login, disconnects on logout, reconnects for another account. */
function sync() {
  const token = session.user ? session.token : null;
  if (token === runningFor) return;
  stop();
  runningFor = token;
  if (token) start(token);
}

export function startRealtime() {
  sync();
  onChange(sync);
}
