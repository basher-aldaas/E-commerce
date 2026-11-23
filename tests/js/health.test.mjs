// How the UI reads a health probe result. Run: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { healthView, STALE_MS, REASON_TEXT } from '../../public/assets/js/core/health.js';

const now = Date.parse('2026-11-14T10:42:30Z');
const probe = (status, ageMs, extra = {}) => ({
  ok: true,
  receivedAt: now - ageMs,
  data: { status, reason: null, latency_ms: 38, slow: false, ...extra },
});

test('the stale limit is 30 seconds', () => {
  assert.equal(STALE_MS, 30000);
});

test('a fresh healthy probe is healthy with its age and latency', () => {
  assert.deepEqual(healthView(probe('healthy', 4000), now), { state: 'healthy', reason: null, ageS: 4, latencyMs: 38, slow: false });
});

test('unreachable and error are passed through while fresh', () => {
  assert.equal(healthView(probe('unreachable', 1000, { reason: 'connect_failed', latency_ms: null }), now).state, 'unreachable');
  assert.equal(healthView(probe('error', 1000, { reason: 'grpc_internal' }), now).state, 'error');
});

test('health older than 30 s is unknown, keeping the age', () => {
  const view = healthView(probe('healthy', 41000), now);
  assert.equal(view.state, 'unknown');
  assert.equal(view.reason, 'stale');
  assert.equal(view.ageS, 41);
});

test('health from a failed request is unknown, not unreachable', () => {
  const view = healthView({ ok: false, receivedAt: now - 1000, error: { status: 500 } }, now);
  assert.equal(view.state, 'unknown');
  assert.equal(view.reason, 'probe_failed');
});

test('a 403 (not an admin) is unknown with its own reason', () => {
  assert.equal(healthView({ ok: false, receivedAt: now, error: { status: 403 } }, now).reason, 'not_admin');
});

test('no probe yet is unknown', () => {
  assert.deepEqual(healthView(null, now), { state: 'unknown', reason: 'not_checked', ageS: null, latencyMs: null, slow: false });
});

test('the timeout text states the real limits, not a flat 1.5 s', () => {
  assert.match(REASON_TEXT.deadline_exceeded, /0\.5 s to connect/);
  assert.match(REASON_TEXT.deadline_exceeded, /1\.0 s for the call/);
});
