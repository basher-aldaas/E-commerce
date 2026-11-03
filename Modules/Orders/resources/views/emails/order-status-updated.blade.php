<!DOCTYPE html>
<html lang="en">
<body style="font-family: Arial, Helvetica, sans-serif; color: #111827; line-height: 1.6;">
    <h2 style="margin-bottom: 8px;">Order #{{ $order->id }} update</h2>

    <p>The status of your order has been updated to
        <strong>{{ ucfirst($order->status) }}</strong>.</p>

    <p style="color: #6b7280;">Order total: {{ number_format($order->total_amount, 2) }}</p>

    <p style="margin-top: 24px; color: #6b7280; font-size: 13px;">
        Thank you for shopping with {{ config('app.name') }}.
    </p>
</body>
</html>