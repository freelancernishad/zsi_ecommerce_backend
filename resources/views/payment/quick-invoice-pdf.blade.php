<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice - {{ $payment->transaction_id ?? ('PAY-' . $payment->id) }}</title>
    <style>
        @page {
            margin: 25px 30px;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1e293b;
            font-size: 13px;
            line-height: 1.4;
        }
        
        /* Header Table */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            background-color: #092a37;
            padding: 25px;
            border-radius: 8px;
        }
        .brand-name {
            font-size: 24px;
            font-weight: bold;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .brand-name span {
            color: #38bdf8;
        }
        .brand-sub {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 3px;
        }
        .invoice-title {
            font-size: 20px;
            font-weight: bold;
            color: #38bdf8;
            text-align: right;
            text-transform: uppercase;
        }
        .invoice-meta {
            font-size: 11px;
            color: #e2e8f0;
            text-align: right;
            margin-top: 5px;
        }
        .status-badge {
            display: inline-block;
            background-color: #10b981;
            color: #ffffff;
            font-size: 10px;
            font-weight: bold;
            padding: 4px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            margin-top: 6px;
        }

        /* Information Grid Table */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        .info-cell {
            width: 50%;
            vertical-align: top;
            padding: 12px 15px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
        .info-cell h4 {
            font-size: 10px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
        }
        .info-cell p {
            margin: 2px 0;
            color: #334155;
        }
        .highlight {
            font-weight: bold;
            color: #0f172a;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        .items-table th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: bold;
            padding: 10px 12px;
            border-bottom: 2px solid #cbd5e1;
            text-align: left;
        }
        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
        }
        .text-right {
            text-align: right;
        }

        /* Summary Table */
        .summary-wrapper {
            width: 100%;
            margin-bottom: 35px;
        }
        .summary-table {
            width: 280px;
            float: right;
            border-collapse: collapse;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px;
        }
        .summary-row td {
            padding: 6px 12px;
            font-size: 12px;
            color: #64748b;
        }
        .summary-row.total td {
            font-size: 15px;
            font-weight: bold;
            color: #0f172a;
            border-top: 2px solid #cbd5e1;
            padding-top: 10px;
        }
        .amount-green {
            color: #059669;
            font-weight: bold;
        }

        .clear {
            clear: both;
        }

        /* Footer */
        .footer-table {
            width: 100%;
            border-top: 1px dashed #cbd5e1;
            padding-top: 15px;
            margin-top: 40px;
        }
        .security-badge {
            font-size: 11px;
            color: #059669;
            font-weight: bold;
        }
        .thanks-note {
            font-size: 11px;
            color: #64748b;
            text-align: right;
        }
    </style>
</head>
<body>

    <!-- Header Banner -->
    <table class="header-table">
        <tr>
            <td style="vertical-align: middle;">
                <div class="brand-name">Golden<span>Mark</span></div>
                <div class="brand-sub">Management & E-Commerce Store</div>
            </td>
            <td style="vertical-align: middle; text-align: right;">
                <div class="invoice-title">Official Receipt</div>
                <div class="invoice-meta">
                    <div>Receipt #: <strong>INV-{{ str_pad($payment->id, 6, '0', STR_PAD_LEFT) }}</strong></div>
                    <div>Date: <strong>{{ $payment->created_at ? $payment->created_at->format('M d, Y') : date('M d, Y') }}</strong></div>
                </div>
                <span class="status-badge">{{ strtoupper($payment->status) }}</span>
            </td>
        </tr>
    </table>

    <!-- Info Grid -->
    <table class="info-table">
        <tr>
            <td class="info-cell" style="padding-right: 10px;">
                <h4>Payer Information</h4>
                <p class="highlight">{{ $payment->name }}</p>
                <p>{{ $payment->email }}</p>
            </td>
            <td style="width: 20px;"><!-- Spacer --></td>
            <td class="info-cell">
                <h4>Payment Details</h4>
                <p>Method: <span class="highlight">{{ strtoupper($payment->payment_method ?? 'Online') }}</span></p>
                <p>Transaction ID: <span class="highlight" style="font-family: monospace; font-size: 11px;">{{ $payment->transaction_id ?? ('PAY-' . $payment->id) }}</span></p>
                <p>Currency: <span class="highlight">{{ strtoupper($payment->currency ?? 'USD') }}</span></p>
            </td>
        </tr>
    </table>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 55%;">Description / Reason for Payment</th>
                <th class="text-right" style="width: 15%;">Qty</th>
                <th class="text-right" style="width: 15%;">Unit Price</th>
                <th class="text-right" style="width: 15%;">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong style="color: #0f172a; font-size: 13px;">{{ $payment->reason }}</strong>
                    <div style="font-size: 11px; color: #64748b; margin-top: 3px;">Direct Quick Payment Settlement</div>
                </td>
                <td class="text-right">1</td>
                <td class="text-right">${{ number_format($payment->amount, 2) }}</td>
                <td class="text-right amount-green">${{ number_format($payment->amount, 2) }} USD</td>
            </tr>
        </tbody>
    </table>

    <!-- Summary Section -->
    <div class="summary-wrapper">
        <table class="summary-table">
            <tr class="summary-row">
                <td>Subtotal</td>
                <td class="text-right">${{ number_format($payment->amount, 2) }}</td>
            </tr>
            <tr class="summary-row">
                <td>Tax & Fees (0%)</td>
                <td class="text-right">$0.00</td>
            </tr>
            <tr class="summary-row total">
                <td>Total Paid</td>
                <td class="text-right amount-green">${{ number_format($payment->amount, 2) }} USD</td>
            </tr>
        </table>
        <div class="clear"></div>
    </div>

    <!-- Footer -->
    <table class="footer-table">
        <tr>
            <td class="security-badge">
                ✓ 100% Encrypted &amp; Verified Payment
            </td>
            <td class="thanks-note">
                Thank you for your business!<br>
                GoldenMark Store Official Receipt
            </td>
        </tr>
    </table>

</body>
</html>
