// k6 load test: the order lists (GET /api/v1/orders), the queries the Phase 4
// indexes are for. Run once before and once after the index migration with the
// same settings:
//
//   docker run --rm -i --network e-commerce_default grafana/k6:0.54.0 run - < tests/load/orders.js
//
// ROLE=admin    every order, newest first (full table sort without an index)
// ROLE=customer one seeded customer's orders (perf-customer-1@example.test)
// ENDPOINT      another admin read instead, e.g. ENDPOINT=/api/v1/perf/orders?per_page=50
//               for the N+1 demonstration (same request every time, no paging)
//
// Reads only. Needs the perf:seed dataset and API_RATE_LIMIT_PER_MINUTE raised
// (see docs/performance/DEMO.md).

import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE_URL || 'http://app:8000';
const ROLE = __ENV.ROLE || 'admin';
const ENDPOINT = __ENV.ENDPOINT || '';
const ACCOUNTS = {
  admin: 'admin@system.com',
  customer: 'perf-customer-1@example.test',
};

export const options = {
  vus: Number(__ENV.VUS || 10),
  duration: __ENV.DURATION || '30s',
  summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  const res = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ email: ACCOUNTS[ROLE], password: 'password123' }), {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  });
  check(res, { 'logged in': (r) => r.status === 200 });
  return { token: res.json('data.access_token') };
}

export default function (data) {
  // Pages 1-5: what people actually open; the same rotation in every run.
  const page = (__ITER % 5) + 1;
  const url = ENDPOINT ? `${BASE}${ENDPOINT}` : `${BASE}/api/v1/orders?page=${page}`;
  const res = http.get(url, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${data.token}` },
    tags: { role: ROLE },
  });
  check(res, { 'status 200': (r) => r.status === 200 });
}
