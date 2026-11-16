// A safe, short label for an Idempotency-Key in the demo panel: the first 8 hex
// characters of its SHA-256, the same characters the admin reservations list
// shows from the hash Laravel stores. The key itself is never displayed.
//
// crypto.subtle only exists in secure contexts (https or localhost). On a plain
// http LAN address the label falls back to a per-tab counter.

const fallbackLabels = new Map();

export async function fingerprint(key, subtle = globalThis.crypto?.subtle) {
  if (!subtle) {
    if (!fallbackLabels.has(key)) fallbackLabels.set(key, `key #${fallbackLabels.size + 1} (this tab)`);
    return fallbackLabels.get(key);
  }
  const digest = await subtle.digest('SHA-256', new TextEncoder().encode(key));
  return [...new Uint8Array(digest).slice(0, 4)].map((b) => b.toString(16).padStart(2, '0')).join('');
}
