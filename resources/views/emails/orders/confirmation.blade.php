@extends('emails.layout')

@section('title', 'Order Confirmed #' . $order->order_number . ' - ' . ($storeName ?? config('app.name')))

@section('content')
    <div style="text-align: center; margin-bottom: 24px;">
        <div style="display: inline-block; background-color: #dcfce7; color: #16a34a; border-radius: 9999px; width: 56px; height: 56px; line-height: 56px; font-size: 28px; font-weight: bold; margin-bottom: 12px;">&#10003;</div>
        <h1>Order Confirmed!</h1>
        <p style="margin-bottom: 0; color: #64748b; font-size: 14px;">Order #<strong>{{ $order->order_number }}</strong></p>
    </div>

    <p>Hi <strong>{{ $customerName }}</strong>,</p>
    <p>Thank you for shopping with <strong>{{ $storeName ?? config('app.name') }}</strong>! We have received your order and it is now being processed.</p>

    <div class="info-box">
        <h3 style="font-size: 16px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Order Details</h3>
        <table>
            <tr>
                <td class="label">Order Number</td>
                <td class="value" style="color: #2b59ff;">#{{ $order->order_number }}</td>
            </tr>
            <tr>
                <td class="label">Order Date</td>
                <td class="value">{{ optional($order->created_at)->format('M j, Y g:i A') ?? date('M j, Y') }}</td>
            </tr>
            <tr>
                <td class="label">Payment Method</td>
                <td class="value" style="text-transform: capitalize;">{{ $order->payment_method_name ?: str_replace('_', ' ', (string) ($order->payment_method ?? 'Card')) }}</td>
            </tr>
            <tr>
                <td class="label">Payment Status</td>
                <td class="value" style="text-transform: capitalize;">{{ $order->payment_status ?? 'pending' }}</td>
            </tr>
        </table>
    </div>

    @if($order->items && $order->items->count() > 0)
    <div class="info-box">
        <h3 style="font-size: 16px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Items Ordered</h3>
        <table>
            @foreach($order->items as $item)
                @php
                    $variantLabel = '';
                    if ($item->variant) {
                        $variantParts = array_filter([
                            $item->variant->color ?? null,
                            $item->variant->size ?? null,
                        ]);
                        $variantLabel = $item->variant->combination_label ?: implode(' / ', $variantParts);
                    }
                    $lineTotal = ((float) $item->price) * ((int) $item->quantity);
                @endphp
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px 8px 10px 0;">
                        <div style="font-weight: 600; color: #0f172a;">{{ $item->product->name ?? 'Product' }}</div>
                        @if($variantLabel !== '')
                            <div style="font-size: 12px; color: #64748b;">{{ $variantLabel }}</div>
                        @endif
                        <div style="font-size: 12px; color: #64748b;">Qty: {{ (int) $item->quantity }} &times; ${{ number_format((float) $item->price, 2) }}</div>
                    </td>
                    <td class="value" style="padding: 10px 0; vertical-align: top;">${{ number_format($lineTotal, 2) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
    @endif

    <div class="info-box">
        <h3 style="font-size: 16px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Price Summary</h3>
        <table>
            <tr>
                <td class="label">Subtotal</td>
                <td class="value">${{ number_format((float) $order->subtotal, 2) }}</td>
            </tr>
            @if((float) $order->discount > 0)
            <tr>
                <td class="label">Discount{{ $order->coupon_code ? ' (' . $order->coupon_code . ')' : '' }}</td>
                <td class="value" style="color: #16a34a;">-${{ number_format((float) $order->discount, 2) }}</td>
            </tr>
            @endif
            <tr>
                <td class="label">Shipping{{ $order->shipping_method ? ' (' . $order->shipping_method . ')' : '' }}</td>
                <td class="value">{{ (float) $order->shipping_cost > 0 ? '$' . number_format((float) $order->shipping_cost, 2) : 'Free' }}</td>
            </tr>
            @if((float) $order->tax > 0)
            <tr>
                <td class="label">Tax</td>
                <td class="value">${{ number_format((float) $order->tax, 2) }}</td>
            </tr>
            @endif
            <tr style="border-top: 1px solid #e2e8f0;">
                <td class="label" style="padding-top: 12px; font-size: 15px; color: #0f172a;">Total</td>
                <td class="value" style="padding-top: 12px; font-size: 18px; color: #2b59ff;">${{ number_format((float) $order->total, 2) }}</td>
            </tr>
        </table>
    </div>

    @if(!empty($order->shipping_street_address) || !empty($order->shipping_city) || !empty($order->shipping_country))
    <div class="info-box">
        <h3 style="font-size: 16px; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Shipping Address</h3>
        <p style="margin: 0; font-size: 14px; color: #334155; line-height: 1.6;">
            <strong>{{ $customerName }}</strong><br>
            @if(!empty($order->shipping_street_address)){{ $order->shipping_street_address }}<br>@endif
            {{ collect([$order->shipping_city, $order->shipping_state, $order->shipping_zip_code])->filter()->implode(', ') }}@if(!empty($order->shipping_city) || !empty($order->shipping_state) || !empty($order->shipping_zip_code))<br>@endif
            @if(!empty($order->shipping_country)){{ $order->shipping_country }}<br>@endif
            @if(!empty($order->shipping_phone))Phone: {{ $order->shipping_phone }}@endif
        </p>
    </div>
    @endif

    <div class="btn-container">
        <a href="{{ $trackOrderUrl }}" class="btn-primary">Track Your Order</a>
    </div>

    @if(!empty($order->user_id))
    <p style="text-align: center; font-size: 13px; margin-top: -10px;">
        Or <a href="{{ $myOrdersUrl }}" style="color: #2563eb; text-decoration: none; font-weight: 600;">view your order history</a> in your account.
    </p>
    @endif

    <p>Best regards,<br>The <strong>{{ $storeName ?? config('app.name') }}</strong> Team</p>
@endsection
