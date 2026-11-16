// Every order, inside the admin layout.

import { renderOrderList } from '../orders.js';

export const adminOrdersView = (main, ctx) => renderOrderList(main, ctx, { basePath: '/admin/orders' });
