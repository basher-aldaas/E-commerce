<?php

use Illuminate\Support\Facades\Broadcast;
use Modules\Users\Models\User;

/*
| Private WebSocket channels (Pusher). The browser asks POST /broadcasting/auth
| (JWT, auth:api, see bootstrap/app.php) before it may subscribe; these
| callbacks decide the answer.
*/

// Status changes of one customer's orders: only that customer may listen.
// مثال: المستخدم صاحب المعرف 15 فقط يستطيع الاشتراك بالقناة orders.15
Broadcast::channel('orders.{userId}', function (User $user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Every new order and every status change, for the admin screens.
Broadcast::channel('admin.orders', function (User $user) {
    return $user->hasRole('admin');
});
