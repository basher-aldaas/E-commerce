// k6 race test (Requirement 2): 20 customers order the same product at the same
// instant over real HTTP, through the whole app and the real Warehouse.
//
// With 1 unit in stock exactly one order must succeed (201) and the other 19
// must be refused (409); the stock must never go below zero. Afterwards the test
// cancels the order(s) it created, as admin, so the Warehouse gets the stock back.
//
//   docker run --rm -i --network e-commerce_default grafana/k6:0.54.0 run - < tests/load/race.js
//
// PRODUCT_ID  product to race for (default 13: the seed gives it 1 unit)
// RACERS      number of customers (default 20: perf-customer-1..20, from perf:seed)
// QUANTITY    units per order (default 1)
//
// What it changes, on purpose: the winning order(s) stay in the database as
// cancelled orders, and every attempt leaves its reservation record (placed then
// cancelled, or rejected). Nothing is deleted or reset. The default rate limit
// (60 per minute) is enough: each customer sends one order.

import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://app:8000';
const PRODUCT_ID = Number(__ENV.PRODUCT_ID || 13);
const RACERS = Number(__ENV.RACERS || 20);
const QUANTITY = Number(__ENV.QUANTITY || 1);
const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };

const placed = new Counter('race_orders_201');
const refused = new Counter('race_orders_409');
const other = new Counter('race_orders_other');

export const options = {
  scenarios: {
    race: { executor: 'per-vu-iterations', vus: RACERS, iterations: 1, maxDuration: '60s' },
  },
  // The run fails (exit code 99) on any unexpected status or any failed check,
  // including the teardown checks on the number of orders and the stock.
  thresholds: {
    race_orders_other: ['count==0'],
    checks: ['rate==1.0'],
  },
  summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
};

function login(email) {
  const res = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ email, password: 'password123' }), { headers: JSON_HEADERS });
  if (res.status !== 200) fail(`login ${email}: ${res.status} ${res.body}`);
  return res.json('data.access_token');
}

function auth(token, extra = {}) {
  return { headers: Object.assign({ Accept: 'application/json', Authorization: `Bearer ${token}` }, extra) };
}

function liveStock(token) {
  const res = http.get(`${BASE}/api/v2/products/${PRODUCT_ID}`, auth(token));
  if (res.status !== 200) fail(`read product ${PRODUCT_ID}: ${res.status}`);
  return res.json('data.stock');
}

function newestOrderId(token) {
  const res = http.get(`${BASE}/api/v1/orders?page=1`, auth(token));
  const orders = res.json('data') || [];
  return orders.length ? orders[0].id : 0;
}

export function setup() {
  const admin = login('admin@system.com');
  const customers = [];
  for (let i = 1; i <= RACERS; i++) {
    const token = login(`perf-customer-${i}@example.test`);
    customers.push({ token, lastOrderId: newestOrderId(token) });
  }

  const before = liveStock(admin);
  if (before === null) fail(`product ${PRODUCT_ID} has no Warehouse stock (not stocked or Warehouse down)`);
  const expectedWinners = Math.min(RACERS, Math.floor(before / QUANTITY));
  console.log(`product ${PRODUCT_ID}: stock before = ${before}; ${RACERS} orders of ${QUANTITY}; expected 201 = ${expectedWinners}, 409 = ${RACERS - expectedWinners}`);

  // Everyone sends at the same instant, ~2 s from now.
  return { admin, customers, before, expectedWinners, startAt: Date.now() + 2000 };
}

export default function (data) {
  const me = data.customers[__VU - 1];
  const wait = (data.startAt - Date.now()) / 1000;
  if (wait > 0) sleep(wait);

  const res = http.post(
    `${BASE}/api/v1/orders`,
    JSON.stringify({ items: [{ product_id: PRODUCT_ID, quantity: QUANTITY }] }),
    { headers: Object.assign({}, JSON_HEADERS, { Authorization: `Bearer ${me.token}`, 'Idempotency-Key': `race-${data.startAt}-${__VU}` }) },
  );

  if (res.status === 201) placed.add(1);
  else if (res.status === 409) refused.add(1);
  else {
    other.add(1);
    console.error(`VU ${__VU}: unexpected ${res.status} ${res.body}`);
  }
  check(res, { '201 or 409': (r) => r.status === 201 || r.status === 409 });
}

export function teardown(data) {
  const after = liveStock(data.admin);

  // The orders this run created: newer than each customer's newest order before.
  const created = [];
  for (const c of data.customers) {
    const res = http.get(`${BASE}/api/v1/orders?page=1`, auth(c.token));
    for (const o of res.json('data') || []) if (o.id > c.lastOrderId) created.push(o.id);
  }

  console.log(`stock after the race = ${after} (before ${data.before}); orders created = ${created.length} [${created.join(', ')}]`);
  check(null, {
    'exactly the expected number of orders was created': () => created.length === data.expectedWinners,
    'stock went down by exactly the units sold': () => after === data.before - created.length * QUANTITY,
    'stock never went below zero': () => after >= 0,
  });

  // Give the stock back: cancelling releases the reservation in the Warehouse.
  for (const id of created) {
    const res = http.post(`${BASE}/api/v1/orders/${id}/status`, JSON.stringify({ status: 'cancelled' }), {
      headers: Object.assign({}, JSON_HEADERS, { Authorization: `Bearer ${data.admin}` }),
    });
    check(res, { 'cancelled (stock returned)': (r) => r.status === 200 && r.json('data.status') === 'cancelled' });
  }

  const restored = liveStock(data.admin);
  console.log(`stock after cancelling the race orders = ${restored}`);
  check(null, { 'stock is back to where it started': () => restored === data.before });
}

//Get-Content tests/load/race.js | docker run --rm -i --network e-commerce_default grafana/k6:0.54.0 run -
