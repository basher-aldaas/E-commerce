// Requests for the admin System page and the demo panel. Both endpoints are
// admin-only, read-only and never cached (see docs/openapi.yaml, tag System).

import { api } from './api.js';

/** One health probe. Resolves to the shape healthView() reads; never rejects. */
export async function fetchHealth() {
  try {
    const { data } = await api('GET', '/api/v1/system/warehouse');
    return { ok: true, data, receivedAt: Date.now() };
  } catch (error) {
    return { ok: false, error: { status: error.status, message: error.message }, receivedAt: Date.now() };
  }
}

/** One page of Laravel's reservation records, with counts per status. */
export async function fetchReservations({ status = '', page = 1 } = {}) {
  const query = new URLSearchParams(Object.entries({ status, page: page > 1 ? page : '' }).filter(([, v]) => v !== ''));
  const { data } = await api('GET', `/api/v1/system/reservations${query.size ? `?${query}` : ''}`);
  return data;
}

/** Re-runs fn every intervalMs while the tab is visible; returns a stop function. */
export function whileVisible(fn, intervalMs) {
  let timer = null;
  const start = () => {
    if (timer === null && document.visibilityState === 'visible') timer = setInterval(fn, intervalMs);
  };
  const stop = () => {
    clearInterval(timer);
    timer = null;
  };
  const onVisibility = () => {
    if (document.visibilityState === 'visible') {
      fn();
      start();
    } else {
      stop();
    }
  };
  document.addEventListener('visibilitychange', onVisibility);
  start();
  return () => {
    stop();
    document.removeEventListener('visibilitychange', onVisibility);
  };
}
