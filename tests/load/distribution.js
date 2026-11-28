
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://nginx';

const perInstance = {
    app1: new Counter('instance_app1'),
    app2: new Counter('instance_app2'),
    app3: new Counter('instance_app3'),
};

const failed = new Counter('requests_failed');

export const options = {
    scenarios: {
        distribution: {
            executor: 'shared-iterations',
            vus: Number(__ENV.VUS || 50),
            iterations: Number(__ENV.REQUESTS || 500),
            maxDuration: '2m',
        },
    },
};

export function setup() {
    const res = http.post(
        `${BASE}/api/v1/auth/login`,
        JSON.stringify({
            email: 'user@system.com',
            password: 'password123',
        }),
        {
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
        }
    );

    check(res, { 'logged in': (r) => r.status === 200 });

    return { token: res.json('data.access_token') };
}

export default function (data) {
    const res = http.get(
        `${BASE}/api/v2/products?per_page=12&page=${(__ITER % 5) + 1}`,
        {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${data.token}`,
            },
        }
    );

    const instance = res.headers['X-Instance'];

    if (perInstance[instance]) perInstance[instance].add(1);
    if (res.status !== 200) failed.add(1);

    check(res, { 'status 200': (r) => r.status === 200 });
}
