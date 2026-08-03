@extends('emails.layout')

@section('title', 'Order Confirmed - ' . config('app.name'))

@section('content')
    <div style="text-align: center; margin-bottom: 24px;">
        <div style="display: inline-block; background-color: #dbeafe; color: #2b59ff; border-radius: 9999px; width: 56px; height: 56px; line-height: 56px; font-size: 28px; font-weight: bold; margin-bottom: 12px;">✓</div>
        <h1>Order Confirmed!</h1>
    </div>

    <p>Hi <strong>{{ $user_name }}</strong>,</p>
    <p>Thank you for your purchase. We are thrilled to have you on board! Your subscription plan has been activated successfully.</p>

    <div class="info-box">
        <h3 style="font-size: 16px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Order Summary</h3>
        <table>
            <tr>
                <td class="label">Plan Name</td>
                <td class="value">{{ $plan_name }}</td>
            </tr>
            <tr>
                <td class="label">Billing Cycle</td>
                <td class="value" style="text-transform: capitalize;">{{ $interval ?? 'one-time' }}</td>
            </tr>
            <tr>
                <td class="label">Start Date</td>
                <td class="value">{{ $start_date }}</td>
            </tr>
            <tr>
                <td class="label">Next Payment</td>
                <td class="value">{{ $next_payment_date ?? 'N/A' }}</td>
            </tr>
            <tr style="border-top: 1px solid #e2e8f0;">
                <td class="label" style="padding-top: 12px; font-size: 15px; color: #0f172a;">Amount Paid</td>
                <td class="value" style="padding-top: 12px; font-size: 18px; color: #2b59ff;">${{ number_format($amount, 2) }}</td>
            </tr>
        </table>
    </div>

    <p>You can manage your subscription details, view invoices, or adjust billing settings directly from your dashboard.</p>

    <div class="btn-container">
        <a href="{{ url('/user/dashboard') }}" class="btn-primary">Go to Dashboard</a>
    </div>

    <p>Best regards,<br>The <strong>{{ config('app.name') }}</strong> Team</p>
@endsection
