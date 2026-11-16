// k6 load test: the product catalog (API v2), cached vs uncached.
//
// Same requests, same data, two modes:
//   MODE=cached  normal requests (Redis catalog cache; stock is always live)
//   MODE=bypass  every request sends X-Cache-Bypass: 1 (honored outside production)
//
// Run from the project root (needs API_RATE_LIMIT_PER_MINUTE raised, see
// docs/performance/DEMO.md):
//   docker run --rm -i --network e-commerce_default -e MODE=cached grafana/k6:0.54.0 run - < tests/load/catalog.js
//
// Reads only: it never creates orders or changes stock.

import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE_URL || 'http://app:8000';
const MODE = __ENV.MODE || 'cached';

export const options = {
  vus: Number(__ENV.VUS || 10),
  duration: __ENV.DURATION || '30s',
  summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  const res = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ email: 'user@system.com', password: 'password123' }), {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  });
  check(res, { 'logged in': (r) => r.status === 200 });
  return { token: res.json('data.access_token') };
}

// A fixed rotation of real catalog pages, so both modes request the same URLs.
const PAGES = [];
for (let category = 1; category <= 8; category++) {
  for (let page = 1; page <= 5; page++) PAGES.push(`/api/v2/products?category_id=${category}&per_page=12&page=${page}`);
}

export default function (data) {
  const headers = { Accept: 'application/json', Authorization: `Bearer ${data.token}` };
  if (MODE === 'bypass') headers['X-Cache-Bypass'] = '1';
  const url = PAGES[(__VU * 7 + __ITER) % PAGES.length];
  const res = http.get(`${BASE}${url}`, { headers, tags: { mode: MODE } });
  check(res, {
    'status 200': (r) => r.status === 200,
    'cache header as expected': (r) => (MODE === 'bypass' ? r.headers['X-Cache'] === 'BYPASS' : ['HIT', 'MISS'].includes(r.headers['X-Cache'])),
  });
}
