// Turns a health probe result (GET /api/v1/system/warehouse) into what the UI
// shows. The rules keep the three ideas apart: a fresh probe answer is shown as
// is; anything older than 30 s, missing, or a failed probe request is "unknown"
// with its reason, never "unreachable" or "down".

export const STALE_MS = 30000;

/**
 * @param probe {ok: true, data, receivedAt} | {ok: false, error: {status}, receivedAt} | null
 */
export function healthView(probe, now = Date.now()) {
  if (!probe) return { state: 'unknown', reason: 'not_checked', ageS: null, latencyMs: null, slow: false };

  const ageMs = Math.max(0, now - probe.receivedAt);
  const ageS = Math.round(ageMs / 1000);

  if (!probe.ok) {
    const reason = probe.error?.status === 403 ? 'not_admin' : 'probe_failed';
    return { state: 'unknown', reason, ageS, latencyMs: null, slow: false };
  }

  const { status, reason = null, latency_ms: latencyMs = null, slow = false } = probe.data ?? {};

  if (ageMs > STALE_MS) return { state: 'unknown', reason: 'stale', ageS, latencyMs, slow };

  const known = ['healthy', 'unreachable', 'error', 'unknown'];
  return { state: known.includes(status) ? status : 'unknown', reason, ageS, latencyMs, slow: Boolean(slow) };
}

/** Plain-language text for each reason, shown under the state. */
export const REASON_TEXT = {
  connect_failed: 'Laravel could not open a connection, so nothing was sent.',
  deadline_exceeded: 'No answer within the health budget (up to 0.5 s to connect, then 1.0 s for the call; 1.5 s at most). That is not proof the warehouse is down.',
  grpc_internal: 'The warehouse answered with an internal error (for example, its database failed).',
  invalid_request: 'The warehouse refused the probe request.',
  connection_lost: 'The connection dropped after the request was sent.',
  unexpected_reply: 'The warehouse replied without the product that was asked for.',
  integration_disabled: 'The warehouse integration is switched off in Laravel.',
  stale: 'The last check is more than 30 s old.',
  probe_failed: 'The health check itself failed, so nothing is known.',
  not_admin: 'Only admins can run the health check.',
  not_checked: 'Not checked yet.',
};
