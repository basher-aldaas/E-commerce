// Idempotency-Key life-cycle of one checkout. Run: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';

import { createCheckout } from '../../public/assets/js/core/checkout.js';
import { fingerprint } from '../../public/assets/js/core/fingerprint.js';

function memoryStorage() {
  const data = new Map();
  return { getItem: (k) => data.get(k) ?? null, setItem: (k, v) => data.set(k, String(v)), removeItem: (k) => data.delete(k) };
}

let counter = 0;
const fresh = () => createCheckout({ storage: memoryStorage(), newKey: () => `key-${++counter}` });
const cart = (...pairs) => pairs.map(([id, qty]) => ({ id, qty }));

test('key is reused for the same cart', () => {
  const checkout = fresh();
  const first = checkout.keyFor(cart([1, 2], [5, 1]));
  assert.equal(checkout.keyFor(cart([5, 1], [1, 2])), first, 'line order does not matter');
});

test('key rotates when the cart changes', () => {
  const checkout = fresh();
  const first = checkout.keyFor(cart([1, 2]));
  assert.notEqual(checkout.keyFor(cart([1, 3])), first);
});

for (const outcome of ['WAREHOUSE_TIMEOUT', 'WAREHOUSE_UNAVAILABLE', 'ORDER_IN_PROGRESS', 'NETWORK', 'SERVER', 'RATE_LIMITED']) {
  test(`key is kept after ${outcome}, so the retry is the same attempt`, () => {
    const checkout = fresh();
    const first = checkout.keyFor(cart([1, 2]));
    checkout.settle(outcome);
    assert.equal(checkout.keyFor(cart([1, 2])), first);
  });
}

for (const outcome of ['PLACED', 'INSUFFICIENT_STOCK', 'IDEMPOTENCY_KEY_CONFLICT']) {
  test(`key is dropped after ${outcome}`, () => {
    const checkout = fresh();
    const first = checkout.keyFor(cart([1, 2]));
    checkout.settle(outcome);
    assert.notEqual(checkout.keyFor(cart([1, 2])), first);
  });
}

test('the key survives a reload through storage', () => {
  const storage = memoryStorage();
  const a = createCheckout({ storage, newKey: () => 'k-a' });
  const key = a.keyFor(cart([1, 2]));
  const b = createCheckout({ storage, newKey: () => 'k-b' });
  assert.equal(b.keyFor(cart([1, 2])), key);
});

test('a broken storage still works in memory', () => {
  const throwing = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); }, removeItem() { throw new Error('denied'); } };
  const checkout = createCheckout({ storage: throwing, newKey: () => 'k-x' });
  assert.equal(checkout.keyFor(cart([1, 1])), 'k-x');
});

test('fingerprint matches what the server shows (first 8 hex of SHA-256)', async () => {
  const expected = createHash('sha256').update('cart-1').digest('hex').slice(0, 8);
  assert.equal(await fingerprint('cart-1'), expected);
});

test('fingerprint falls back to a per-tab label without crypto.subtle', async () => {
  const label = await fingerprint('cart-1', null);
  assert.match(label, /^key #\d+ \(this tab\)$/);
});
