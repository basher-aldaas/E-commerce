// Pure formatting helpers of the web interface. Run: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { money, stockLabel, classifyOrderError } from '../../public/assets/js/core/format.js';

test('money formats US dollars with two decimals', () => {
  assert.equal(money('89.99'), '$89.99');
  assert.equal(money(149), '$149.00');
  assert.equal(money(1234.5), '$1,234.50');
});

test('stockLabel maps every stock_status the API returns', () => {
  assert.deepEqual(stockLabel('in_stock', 12), { tone: 'ok', text: 'In stock' });
  assert.deepEqual(stockLabel('in_stock', 3), { tone: 'low', text: 'Only 3 left' });
  assert.deepEqual(stockLabel('in_stock', 1), { tone: 'low', text: 'Only 1 left' });
  assert.deepEqual(stockLabel('out_of_stock', 0), { tone: 'out', text: 'Sold out' });
  assert.deepEqual(stockLabel('not_stocked', 0), { tone: 'soon', text: 'Coming soon' });
  assert.deepEqual(stockLabel('unavailable', null), { tone: 'unknown', text: 'Availability unknown' });
});

test('stockLabel never guesses for an unknown status', () => {
  assert.deepEqual(stockLabel(undefined, undefined), { tone: 'unknown', text: 'Availability unknown' });
});

test('classifyOrderError reads the order endpoint responses', () => {
  const shortage = { items: [{ product_id: 1, requested_quantity: 3, available_quantity: 1, stocked: true }] };
  assert.equal(classifyOrderError(409, shortage), 'INSUFFICIENT_STOCK');
  assert.equal(classifyOrderError(409, []), 'ORDER_IN_PROGRESS');
  assert.equal(classifyOrderError(409, null), 'ORDER_IN_PROGRESS');
  assert.equal(classifyOrderError(422, { items: ['The items field is required.'] }), 'VALIDATION');
  assert.equal(classifyOrderError(422, []), 'IDEMPOTENCY_KEY_CONFLICT');
  assert.equal(classifyOrderError(503, []), 'WAREHOUSE_UNAVAILABLE');
  assert.equal(classifyOrderError(504, []), 'WAREHOUSE_TIMEOUT');
  assert.equal(classifyOrderError(429, []), 'RATE_LIMITED');
  assert.equal(classifyOrderError(0, null), 'NETWORK');
  assert.equal(classifyOrderError(500, []), 'SERVER');
});

test('an expired login at checkout is UNAUTHENTICATED, not a server error', () => {
  assert.equal(classifyOrderError(401, []), 'UNAUTHENTICATED');
});
