// The demo panel's request log. It is fed by api.js after each response and only
// ever holds the allow-listed fields below: no request headers (so no JWT), no
// bodies (so no passwords), no raw Idempotency-Key (only its fingerprint). It lives
// in memory, keeps the newest 100 entries, and is cleared on logout.

const MAX_ENTRIES = 100;

export function parseServerTiming(header) {
  if (!header) return [];
  return header.split(',').map((part) => {
    const [name, ...params] = part.trim().split(';');
    const entry = { name: name.trim(), dur: null, desc: null };
    for (const param of params) {
      const [key, raw = ''] = param.trim().split('=');
      const value = raw.replace(/^"|"$/g, '');
      if (key === 'dur') entry.dur = Number(value);
      if (key === 'desc') entry.desc = value;
    }
    return entry;
  }).filter((entry) => entry.name);
}

const isAuthPath = (path) => /\/auth\//.test(path);

/**
 * Whether a product response carried live Warehouse stock: 'unavailable' when any
 * product's stock couldn't be read, 'live' when all could, null when the body has
 * no products. Only this word is recorded, never the body.
 */
export function stockSeen(data) {
  const products = (Array.isArray(data) ? data : [data]).filter((p) => p && typeof p === 'object' && 'stock_status' in p);
  if (!products.length) return null;
  return products.some((p) => p.stock_status === 'unavailable') ? 'unavailable' : 'live';
}

function clean(input) {
  const auth = isAuthPath(String(input.path ?? ''));
  return {
    at: input.at ?? Date.now(),
    method: String(input.method ?? 'GET'),
    path: String(input.path ?? ''),
    status: Number(input.status ?? 0),
    ms: Math.round(Number(input.ms ?? 0)),
    errorType: input.errorType ?? null,
    xCache: auth ? null : input.xCache ?? null,
    etag: auth ? null : input.etag ?? null,
    serverTiming: auth ? [] : Array.isArray(input.serverTiming) ? input.serverTiming : [],
    retryAfter: input.retryAfter ?? null,
    revalidated: auth ? null : input.revalidated ?? null,
    keyFp: input.keyFp ?? null,
    stock: input.stock === 'live' || input.stock === 'unavailable' ? input.stock : null,
    diagnostic: input.diagnostic === true,
  };
}

/** The store's own requests, without the System/demo diagnostics (health probe, reservations). */
export const storeEntries = (entries) => entries.filter((e) => !e.diagnostic);

/** Builds an entry from a fetch Response, reading only the allowed headers. */
export function entryFromResponse({ method, path, response, ms, errorType = null, revalidated = null, keyFp = null, stock = null, diagnostic = false }) {
  const header = (name) => response?.headers?.get(name) ?? null;
  const retryAfter = header('Retry-After');
  return clean({
    method,
    path,
    status: response?.status ?? 0,
    ms,
    errorType,
    xCache: header('X-Cache'),
    etag: header('ETag'),
    serverTiming: parseServerTiming(header('Server-Timing')),
    retryAfter: retryAfter === null ? null : Number(retryAfter),
    revalidated,
    keyFp,
    stock,
    diagnostic,
  });
}

export function createRecorder() {
  let entries = [];
  const listeners = new Set();

  return {
    record(input) {
      entries = [clean(input), ...entries].slice(0, MAX_ENTRIES);
      for (const fn of listeners) {
        try {
          fn(entries[0]);
        } catch {
          /* a panel bug must never affect the store */
        }
      }
    },
    entries: () => entries.slice(),
    clear() {
      entries = [];
    },
    subscribe(fn) {
      listeners.add(fn);
      return () => listeners.delete(fn);
    },
  };
}

export const recorder = createRecorder();
