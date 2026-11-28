// k6 (Requirement 5): 500 real orders, each one becomes an order.created
// message that worker1, worker2 or worker3 processes.
//
// API (3 instances) -> outbox -> outbox-relay -> RabbitMQ -> worker1/2/3
//
// Before running (see docs/messaging/README.md):
//   - workers in db mode (no Mailtrap): ORDER_WORKER_ACTION=db
//   - API rate limit raised: API_RATE_LIMIT_PER_MINUTE=100000
//   - enough stock: docker compose exec warehouse python -m app.cli set-stock 7 1000
//
//   docker run --rm -i --network e-commerce_default grafana/k6:0.54.0 run - < tests/load/workers.js
//
// Options: ORDERS (default 500), VUS = concurrent users (default 20),
// PRODUCT_ID (default 7).

import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE_URL || 'http://nginx';
const PRODUCT_ID = Number(__ENV.PRODUCT_ID || 7);
const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };

export const options = {
    scenarios: {
        orders: {
            executor: 'shared-iterations',
            vus: Number(__ENV.VUS || 20),
            iterations: Number(__ENV.ORDERS || 500),
            maxDuration: '5m',
        },
    },
};

export function setup() {
    const res = http.post(
        `${BASE}/api/v1/auth/login`,
        JSON.stringify({ email: 'user@system.com', password: 'password123' }),
        { headers: JSON_HEADERS }
    );

    check(res, { 'logged in': (r) => r.status === 200 });

    return { token: res.json('data.access_token'), run: Date.now() };
}

export default function (data) {
    const res = http.post(
        `${BASE}/api/v1/orders`,
        JSON.stringify({ items: [{ product_id: PRODUCT_ID, quantity: 1 }] }),
        {
            headers: Object.assign({}, JSON_HEADERS, {
                Authorization: `Bearer ${data.token}`,
                // A new key per order, so none is treated as a repeat.
                'Idempotency-Key': `workers-${data.run}-${__VU}-${__ITER}`,
            }),
        }
    );

    check(res, { 'order 201': (r) => r.status === 201 });
}
