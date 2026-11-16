// The demo panel's request log: allow-listed fields only. Run: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createRecorder, parseServerTiming, entryFromResponse, stockSeen, storeEntries } from '../../public/assets/js/core/recorder.js';

const ALLOWED = ['at', 'method', 'path', 'status', 'ms', 'errorType', 'xCache', 'etag', 'serverTiming', 'retryAfter', 'revalidated', 'keyFp', 'stock', 'diagnostic'];

test('recorder keeps only allow-listed fields, whatever it is given', () => {
  const recorder = createRecorder();
  recorder.record({
    method: 'POST', path: '/api/v1/auth/login', status: 200, ms: 40,
    headers: { Authorization: 'Bearer secret' }, body: { password: 'hunter2' }, token: 'jwt',
  });
  const [entry] = recorder.entries();
  assert.deepEqual(Object.keys(entry).sort(), [...ALLOWED].sort());
  assert.ok(!JSON.stringify(entry).includes('hunter2'));
  assert.ok(!JSON.stringify(entry).includes('secret'));
  assert.ok(!JSON.stringify(entry).includes('jwt'));
});

test('auth requests keep no response details', () => {
  const recorder = createRecorder();
  recorder.record({ method: 'POST', path: '/api/v1/auth/login', status: 200, ms: 40, etag: '"abc"', xCache: 'MISS' });
  const [entry] = recorder.entries();
  assert.equal(entry.etag, null);
  assert.equal(entry.xCache, null);
});

test('recorder keeps the newest 100 entries, newest first', () => {
  const recorder = createRecorder();
  for (let i = 0; i < 105; i++) recorder.record({ method: 'GET', path: `/api/v2/products?page=${i}`, status: 200, ms: 1 });
  const entries = recorder.entries();
  assert.equal(entries.length, 100);
  assert.equal(entries[0].path, '/api/v2/products?page=104');
});

test('subscribers hear about each entry and can unsubscribe', () => {
  const recorder = createRecorder();
  let heard = 0;
  const stop = recorder.subscribe(() => heard++);
  recorder.record({ method: 'GET', path: '/x', status: 200, ms: 1 });
  stop();
  recorder.record({ method: 'GET', path: '/y', status: 200, ms: 1 });
  assert.equal(heard, 1);
});

test('a throwing subscriber never breaks recording', () => {
  const recorder = createRecorder();
  recorder.subscribe(() => { throw new Error('panel bug'); });
  assert.doesNotThrow(() => recorder.record({ method: 'GET', path: '/x', status: 200, ms: 1 }));
  assert.equal(recorder.entries().length, 1);
});

test('clear empties the log', () => {
  const recorder = createRecorder();
  recorder.record({ method: 'GET', path: '/x', status: 200, ms: 1 });
  recorder.clear();
  assert.equal(recorder.entries().length, 0);
});

test('parseServerTiming reads name, duration and description', () => {
  assert.deepEqual(parseServerTiming('catalog;dur=1.8;desc="HIT", stock;dur=6.1'), [
    { name: 'catalog', dur: 1.8, desc: 'HIT' },
    { name: 'stock', dur: 6.1, desc: null },
  ]);
  assert.deepEqual(parseServerTiming(null), []);
});

test('entryFromResponse reads only the allowed response headers', () => {
  const headers = new Map([['x-cache', 'HIT'], ['etag', '"a81f"'], ['server-timing', 'catalog;dur=2'], ['retry-after', '23'], ['set-cookie', 'x']]);
  const response = { status: 200, headers: { get: (k) => headers.get(k.toLowerCase()) ?? null } };
  const entry = entryFromResponse({ method: 'GET', path: '/api/v2/products', response, ms: 12.4 });
  assert.equal(entry.xCache, 'HIT');
  assert.equal(entry.etag, '"a81f"');
  assert.equal(entry.retryAfter, 23);
  assert.deepEqual(entry.serverTiming, [{ name: 'catalog', dur: 2, desc: null }]);
  assert.equal(entry.ms, 12);
});

test('stockSeen says whether a product response had live stock', () => {
  assert.equal(stockSeen([{ stock_status: 'in_stock' }, { stock_status: 'not_stocked' }]), 'live');
  assert.equal(stockSeen([{ stock_status: 'in_stock' }, { stock_status: 'unavailable' }]), 'unavailable');
  assert.equal(stockSeen({ stock_status: 'unavailable' }), 'unavailable');
  assert.equal(stockSeen({ id: 1, status: 'pending' }), null);
  assert.equal(stockSeen(null), null);
});

test('the stock field is recorded only as live, unavailable or null', () => {
  const recorder = createRecorder();
  recorder.record({ method: 'GET', path: '/api/v2/products', status: 200, ms: 1, stock: 'unavailable' });
  recorder.record({ method: 'GET', path: '/api/v2/products', status: 200, ms: 1, stock: { secret: 1 } });
  const [bogus, real] = recorder.entries();
  assert.equal(real.stock, 'unavailable');
  assert.equal(bogus.stock, null);
});

test('diagnostic requests are marked and left out of the store request list', () => {
  const recorder = createRecorder();
  recorder.record({ method: 'GET', path: '/api/v2/products', status: 200, ms: 1 });
  recorder.record({ method: 'GET', path: '/api/v1/system/warehouse', status: 200, ms: 1, diagnostic: true });
  assert.equal(recorder.entries()[0].diagnostic, true);
  assert.deepEqual(storeEntries(recorder.entries()).map((e) => e.path), ['/api/v2/products']);
});
