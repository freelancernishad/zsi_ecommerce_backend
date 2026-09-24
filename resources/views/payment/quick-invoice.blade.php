<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Payment Receipt - {{ $payment->transaction_id ?? ('PAY-' . $payment->id) }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            line-height: 1.5;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .no-print-bar {
            background-color: #0f172a;
            color: #ffffff;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .no-print-bar h2 {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-primary {
            background-color: #2563eb;
            color: #ffffff;
        }

        .btn-primary:hover {
            background-color: #1d4ed8;
        }

        .btn-secondary {
            background-color: rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }

        .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.25);
        }

        .invoice-wrapper {
            max-width: 800px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .invoice-header {
            background: linear-gradient(135deg, #092a37 0%, #0f172a 100%);
            color: #ffffff;
            padding: 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .brand-logo {
            font-size: 26px;
            font-weight: 900;
            letter-spacing: -0.5px;
            color: #ffffff;
            text-transform: uppercase;
        }

        .brand-logo span {
            color: #38bdf8;
        }

        .brand-sub {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
            font-weight: 500;
        }

        .invoice-title-block {
            text-align: right;
        }

        .invoice-title {
            font-size: 22px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #38bdf8;
        }

        .invoice-meta {
            margin-top: 8px;
            font-size: 12px;
            color: #cbd5e1;
        }

        .status-badge {
            display: inline-block;
            margin-top: 10px;
            padding: 4px 12px;
            border-radius: 9999px;
            background-color: #10b981;
            color: #ffffff;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .invoice-body {
            padding: 40px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            margin-bottom: 40px;
            padding-bottom: 24px;
            border-bottom: 1px solid #f1f5f9;
        }

        .info-card h4 {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 10px;
        }

        .info-card p {
            font-size: 14px;
            color: #334155;
            font-weight: 500;
            margin-bottom: 4px;
        }

        .info-card .highlight {
            font-weight: 700;
            color: #0f172a;
        }

        .table-wrapper {
            margin-bottom: 32px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            background-color: #f8fafc;
            color: #475569;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 14px 16px;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 16px;
            font-size: 14px;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }

        .text-right {
            text-align: right;
        }

        .summary-section {
            display: flex;
            justify-content: flex-end;
            margin-top: 24px;
        }

        .summary-box {
            width: 320px;
            background-color: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e2e8f0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #64748b;
            margin-bottom: 10px;
            font-weight: 500;
        }

        .summary-row.total {
            font-size: 16px;
            font-weight: 900;
            color: #0f172a;
            border-top: 2px solid #e2e8f0;
            padding-top: 12px;
            margin-bottom: 0;
        }

        .summary-row.total .amount {
            color: #059669;
            font-size: 20px;
        }

        .invoice-footer {
            margin-top: 48px;
            padding-top: 24px;
            border-top: 1px dashed #cbd5e1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .security-note {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
            color: #64748b;
        }

        .security-icon {
            width: 32px;
            height: 32px;
            background-color: #d1fae5;
            color: #059669;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 16px;
        }

        .thanks-msg {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            text-align: right;
        }

        @media print {
            .no-print-bar {
                display: none !important;
            }
            body {
                background: #ffffff;
            }
            .invoice-wrapper {
                margin: 0;
                box-shadow: none;
                border: none;
                border-radius: 0;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>

    <div className="no-print-bar" id="noPrintBar">
        <h2>Official Payment Receipt</h2>
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-primary" onclick="window.print()">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                Print / Download PDF
            </button>
            <button class="btn btn-secondary" onclick="window.close()">Close</button>
        </div>
    </div>

    <div class="invoice-wrapper">
        <!-- Header -->
        <div class="invoice-header">
            <div>
                <div class="brand-logo">Golden<span>Mark</span></div>
                <div class="brand-sub">Management & E-Commerce Store</div>
            </div>
            <div class="invoice-title-block">
                <div class="invoice-title">Official Receipt</div>
                <div class="invoice-meta">
                    <div>Receipt #: <strong>INV-{{ str_pad($payment->id, 6, '0', STR_PAD_LEFT) }}</strong></div>
                    <div>Date: <strong>{{ $payment->created_at ? $payment->created_at->format('M d, Y - h:i A') : date('M d, Y') }}</strong></div>
                </div>
                <span class="status-badge">{{ strtoupper($payment->status) }}</span>
            </div>
        </div>

        <!-- Body -->
        <div class="invoice-body">
            
            <div class="info-grid">
                <div class="info-card">
                    <h4>Payer Information</h4>
                    <p class="highlight">{{ $payment->name }}</p>
                    <p>{{ $payment->email }}</p>
                </div>

                <div class="info-card">
                    <h4>Payment Gateway Details</h4>
                    <p>Method: <span class="highlight">{{ strtoupper($payment->payment_method ?? 'Online') }}</span></p>
                    <p>Transaction ID: <span class="highlight" style="font-family: monospace; font-size: 12px;">{{ $payment->transaction_id ?? ('PAY-' . $payment->id) }}</span></p>
                    <p>Currency: <span class="highlight">{{ strtoupper($payment->currency ?? 'USD') }}</span></p>
                </div>
            </div>

            <!-- Table -->
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Description / Reason for Payment</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Unit Amount</th>
                            <th class="text-right">Total Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong style="color: #0f172a; font-size: 14px;">{{ $payment->reason }}</strong>
                                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Direct Quick Payment Settlement</div>
                            </td>
                            <td class="text-right">1</td>
                            <td class="text-right">${{ number_format($payment->amount, 2) }}</td>
                            <td class="text-right" style="font-weight: 700; color: #059669;">${{ number_format($payment->amount, 2) }} USD</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Summary -->
            <div class="summary-section">
                <div class="summary-box">
                    <div class="summary-row">
                        <span>Subtotal</span>
                        <span>${{ number_format($payment->amount, 2) }}</span>
                    </div>
                    <div class="summary-row">
                        <span>Tax & Fees (0%)</span>
                        <span>$0.00</span>
                    </div>
                    <div class="summary-row total">
                        <span>Total Paid Amount</span>
                        <span class="amount">${{ number_format($payment->amount, 2) }} USD</span>
                    </div>
                </div>
            </div>

            <!-- Footer Note -->
            <div class="invoice-footer">
                <div class="security-note">
                    <div class="security-icon">✓</div>
                    <div>
                        <strong style="color: #0f172a;">100% Encrypted &amp; Verified Payment</strong>
                        <div style="font-size: 11px;">Processed securely via Store Payment Gateway</div>
                    </div>
                </div>

                <div class="thanks-msg">
                    Thank you for your business!<br>
                    <span style="font-size: 11px; font-weight: 500; color: #64748b;">GoldenMark Store Official Documentation</span>
                </div>
            </div>

        </div>
    </div>

    <script>
        // Auto trigger print window if requested
        if (window.location.search.indexOf('auto_print=1') !== -1) {
            window.onload = function() {
                window.print();
            };
        }
    </script>
</body>
</html>
