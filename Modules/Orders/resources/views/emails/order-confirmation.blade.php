<!DOCTYPE html>
<html lang="en">
<body style="font-family: Arial, Helvetica, sans-serif; color: #111827; line-height: 1.6;">
    <h2 style="margin-bottom: 8px;">Thank you for your order!</h2>

    <p>Your order <strong>#{{ $order->id }}</strong> has been received and is now
        <strong>{{ ucfirst($order->status) }}</strong>.</p>

    <table cellpadding="8" cellspacing="0"
           style="border-collapse: collapse; width: 100%; max-width: 560px; margin-top: 12px;">
        <thead>
            <tr style="background: #f3f4f6; text-align: left;">
                <th style="border: 1px solid #e5e7eb;">Product</th>
                <th style="border: 1px solid #e5e7eb;">Qty</th>
                <th style="border: 1px solid #e5e7eb;">Unit price</th>
                <th style="border: 1px solid #e5e7eb;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td style="border: 1px solid #e5e7eb;">
                        {{ optional($item->product)->name ?? ('#'.$item->product_id) }}
                    </td>
                    <td style="border: 1px solid #e5e7eb;">{{ $item->quantity }}</td>
                    <td style="border: 1px solid #e5e7eb;">{{ number_format($item->unit_price, 2) }}</td>
                    <td style="border: 1px solid #e5e7eb;">
                        {{ number_format($item->unit_price * $item->quantity, 2) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="margin-top: 16px; font-size: 16px;">
        <strong>Total: {{ number_format($order->total_amount, 2) }}</strong>
    </p>
</body>
</html>