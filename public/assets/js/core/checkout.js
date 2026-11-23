// One Idempotency-Key per checkout attempt.
//
// Retrying the SAME attempt (same key, same cart) is always safe: the server
// returns the order if it was made, answers 409 while the last try is still
// settling, and only reserves again once the old reservation is released. So the
// key is kept after 503, 504, in-progress, network and server errors.
//
// A NEW key is a new purchase. It is only taken when the cart changes, or after a
// final answer: the order was placed, the Warehouse refused (the customer then
// reviews the cart), or the server says the key belongs to a different cart.

const STORAGE_KEY = 'checkout-attempt';
const DROP_AFTER = new Set(['PLACED', 'INSUFFICIENT_STOCK', 'IDEMPOTENCY_KEY_CONFLICT']);

const cartHash = (lines) => JSON.stringify(
  lines.map((l) => [Number(l.id), Number(l.qty)]).sort((a, b) => a[0] - b[0]),
);

const randomKey = () => (globalThis.crypto?.randomUUID
  ? globalThis.crypto.randomUUID()
  : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`);

export function createCheckout({ storage = null, newKey = randomKey } = {}) {
  let attempt = null;
  try {
    attempt = JSON.parse(storage?.getItem(STORAGE_KEY) ?? 'null');
  } catch {
    attempt = null;
  }

  const save = () => {
    try {
      if (attempt) storage?.setItem(STORAGE_KEY, JSON.stringify(attempt));
      else storage?.removeItem(STORAGE_KEY);
    } catch {
      /* storage unavailable: the attempt lives in memory only */
    }
  };

  return {
    /** The key for this cart: the current attempt's, or a new one if the cart changed. */
    keyFor(lines) {
      const hash = cartHash(lines);
      if (!attempt || attempt.hash !== hash) {
        attempt = { hash, key: newKey() };
        save();
      }
      return attempt.key;
    },
    /** Records how the attempt ended (an outcome name from classifyOrderError, or PLACED). */
    settle(outcome) {
      if (DROP_AFTER.has(outcome)) {
        attempt = null;
        save();
      }
    },
  };
}

const browserStorage = (() => {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
})();

export const checkout = createCheckout({ storage: browserStorage });
