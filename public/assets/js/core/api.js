// The only place that talks to the Laravel API. It reads the standard envelope
// ({status, data, message, status_code, timestamp}), turns every failure into an
// ApiError, and, after the caller's result is ready, hands a redacted summary of
// the exchange to the demo panel's recorder. Recording never changes a request:
// it adds no header, never retries, and can't throw into the caller.

import { session } from './store.js';
import { recorder, entryFromResponse, stockSeen } from './recorder.js';
import { classifyOrderError } from './format.js';

export class ApiError extends Error {
  constructor({ status, type, message, errors = null, retryAfter = null }) {
    super(message);
    this.status = status;
    this.type = type;
    this.errors = errors;
    this.retryAfter = retryAfter;
  }
}

function errorType(method, path, status, data) {
  if (method === 'POST' && /^\/api\/v1\/orders$/.test(path)) return classifyOrderError(status, data);
  switch (status) {
    case 0: return 'NETWORK';
    case 401: return 'UNAUTHENTICATED';
    case 403: return 'FORBIDDEN';
    case 404: return 'NOT_FOUND';
    case 409: return 'CONFLICT';
    case 422: return 'VALIDATION';
    case 429: return 'RATE_LIMITED';
    case 503: return 'WAREHOUSE_UNAVAILABLE';
    case 504: return 'WAREHOUSE_TIMEOUT';
    default: return 'SERVER';
  }
}

try {
  performance.setResourceTimingBufferSize?.(400);
  performance.addEventListener?.('resourcetimingbufferfull', () => performance.clearResourceTimings());
} catch {
  /* Resource Timing unavailable: 304s are reported as unknown */
}

/**
 * fetch() reports a browser revalidation (304) as 200 with the cached body. The
 * browser's Resource Timing entry tells them apart: only headers came over the
 * network while the body was reused. null when the browser doesn't say.
 */
function revalidated(path) {
  try {
    const entries = performance.getEntriesByName(new URL(path, location.origin).href);
    const last = entries[entries.length - 1];
    if (!last || !last.encodedBodySize) return null;
    if (last.transferSize === 0) return null; // served from cache without asking: not a revalidation we can prove
    return last.transferSize < last.encodedBodySize;
  } catch {
    return null;
  }
}

function record(details) {
  try {
    recorder.record(entryFromResponse({ ...details, diagnostic: details.path.startsWith('/api/v1/system/') }));
  } catch {
    /* the demo log must never affect the store */
  }
}

export async function api(method, path, { body, headers = {}, keyFp = null } = {}) {
  const isForm = body instanceof FormData;
  const requestHeaders = { Accept: 'application/json', ...headers };
  if (body && !isForm) requestHeaders['Content-Type'] = 'application/json';
  if (session.token) requestHeaders.Authorization = `Bearer ${session.token}`;

  const started = performance.now();
  let response;
  try {
    response = await fetch(path, { method, headers: requestHeaders, body: body ? (isForm ? body : JSON.stringify(body)) : undefined });
  } catch {
    const error = new ApiError({ status: 0, type: 'NETWORK', message: 'You appear to be offline, or the server could not be reached. Try again.' });
    record({ method, path, response: null, ms: performance.now() - started, errorType: error.type, keyFp });
    throw error;
  }

  const json = await response.json().catch(() => ({}));
  const ms = performance.now() - started;
  const retryAfter = response.headers.get('Retry-After');

  if (!response.ok) {
    const error = new ApiError({
      status: response.status,
      type: errorType(method, path, response.status, json.data),
      message: json.message || `Request failed (${response.status}).`,
      errors: json.data ?? null,
      retryAfter: retryAfter === null ? null : Number(retryAfter),
    });
    queueMicrotask(() => record({ method, path, response, ms, errorType: error.type, keyFp }));

    if (response.status === 401 && session.token && !path.endsWith('/auth/logout')) {
      session.expire();
    }
    throw error;
  }

  queueMicrotask(() => record({
    method, path, response, ms, keyFp,
    revalidated: method === 'GET' ? revalidated(path) : null,
    stock: /\/products/.test(path) ? stockSeen(json.data) : null,
  }));
  return { data: json.data, message: json.message, pagination: json.pagination ?? null, response };
}

/** Field errors of a 422 validation response, keyed by field name. */
export const fieldErrors = (error) => (error instanceof ApiError && error.type === 'VALIDATION' && error.errors && !Array.isArray(error.errors) ? error.errors : {});
