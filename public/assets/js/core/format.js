// Pure helpers shared by the screens: money, dates, stock labels, and the
// meaning of an order endpoint's error response. No DOM access here, so the
// rules can be unit-tested with `node --test tests/js/`.

const usd = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export const money = (value) => usd.format(Number(value));

const dateTime = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

export const when = (iso) => (iso ? dateTime.format(new Date(iso)) : '');

/** "Only N left" from this many units and below. */
export const LOW_STOCK = 3;

/**
 * The badge for a product's live Warehouse stock. Never guesses: anything the
 * API didn't state as known stock is "Availability unknown".
 */
export function stockLabel(status, stock) {
  switch (status) {
    case 'in_stock':
      return stock !== null && stock !== undefined && stock <= LOW_STOCK
        ? { tone: 'low', text: `Only ${stock} left` }
        : { tone: 'ok', text: 'In stock' };
    case 'out_of_stock':
      return { tone: 'out', text: 'Sold out' };
    case 'not_stocked':
      return { tone: 'soon', text: 'Coming soon' };
    default:
      return { tone: 'unknown', text: 'Availability unknown' };
  }
}

/** True when the Warehouse answered for this product (stock is known). */
export const stockKnown = (status) => status === 'in_stock' || status === 'out_of_stock' || status === 'not_stocked';

/**
 * The API's error envelope has no machine-readable type, so the order endpoint's
 * outcomes are told apart by status and data shape. These shapes come from
 * Modules/Orders (InsufficientStockException carries data.items; the in-progress
 * 409 and the idempotency 422 carry no field errors).
 */
export function classifyOrderError(status, data) {
  const hasItems = data && !Array.isArray(data) && Array.isArray(data.items) && typeof data.items[0] === 'object';
  const hasFields = data && !Array.isArray(data) && Object.keys(data).length > 0;
  switch (status) {
    case 0: return 'NETWORK';
    case 401: return 'UNAUTHENTICATED';
    case 409: return hasItems ? 'INSUFFICIENT_STOCK' : 'ORDER_IN_PROGRESS';
    case 422: return hasFields ? 'VALIDATION' : 'IDEMPOTENCY_KEY_CONFLICT';
    case 429: return 'RATE_LIMITED';
    case 503: return 'WAREHOUSE_UNAVAILABLE';
    case 504: return 'WAREHOUSE_TIMEOUT';
    default: return 'SERVER';
  }
}
