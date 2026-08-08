<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', $storeName ?? config('app.name'))</title>
    <style>
        /* Global & Reset Styles */
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #334155;
            margin: 0;
            padding: 0;
            -webkit-text-size-adjust: none;
            width: 100% !important;
        }
        table {
            border-collapse: collapse;
        }
        img {
            max-width: 100%;
            height: auto;
            border: 0;
        }
        
        /* Container & Cards */
        .email-wrapper {
            width: 100%;
            background-color: #f8fafc;
            padding: 40px 15px;
        }
        .email-card {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        
        /* Header & Masthead */
        .email-header {
            background-color: #ffffff;
            padding: 28px 32px;
            text-align: center;
            border-bottom: 2px solid #eab308;
        }
        
        /* Body Content */
        .email-body {
            padding: 32px;
            line-height: 1.625;
        }
        h1, h2, h3 {
            color: #0f172a;
            font-weight: 700;
            margin-top: 0;
            margin-bottom: 16px;
            line-height: 1.3;
        }
        h1 { font-size: 22px; }
        h2 { font-size: 18px; }
        p {
            font-size: 15px;
            color: #475569;
            margin-top: 0;
            margin-bottom: 20px;
        }
 
        /* Highlight & Info Box */
        .info-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
        }
        .info-box table {
            width: 100%;
        }
        .info-box td {
            padding: 6px 0;
            font-size: 14px;
            color: #475569;
        }
        .info-box .label {
            font-weight: 600;
            color: #1e293b;
        }
        .info-box .value {
            text-align: right;
            font-weight: 700;
            color: #0f172a;
        }
 
        /* Buttons */
        .btn-container {
            text-align: center;
            margin: 32px 0 24px 0;
        }
        .btn-primary {
            display: inline-block;
            background-color: #eab308;
            color: #ffffff !important;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            padding: 13px 32px;
            border-radius: 10px;
            box-shadow: 0 4px 14px rgba(234, 179, 8, 0.3);
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            background-color: #ca8a04;
        }
 
        /* Footer */
        .email-footer {
            background-color: #f8fafc;
            border-top: 1px solid #f1f5f9;
            padding: 28px 32px;
            text-align: center;
        }
        .email-footer p {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 6px;
        }
        .email-footer a {
            color: #d97706;
            text-decoration: none;
            font-weight: 600;
        }
        .email-footer a:hover {
            text-decoration: underline;
        }
    </style>
    @yield('extra_css')
</head>
<body>
    <div class="email-wrapper">
        <div class="email-card">
            <!-- Header -->
            <div class="email-header">
                @if(!empty($logoUrl))
                    <a href="{{ $resetUrl ?? '#' }}" style="display: inline-block; text-decoration: none;">
                        <img src="{{ $logoUrl }}" alt="{{ $storeName ?? config('app.name') }}" style="height: 45px; width: auto; display: block; margin: 0 auto;" />
                    </a>
                @else
                    <a href="{{ $resetUrl ?? '#' }}" style="font-size: 24px; font-weight: 800; color: #eab308; text-decoration: none; letter-spacing: -0.5px;">
                        {{ $storeName ?? config('app.name') }}
                    </a>
                @endif
            </div>

            <!-- Main Body -->
            <div class="email-body">
                @yield('content')
            </div>

            <!-- Footer -->
            <div class="email-footer">
                @if(!empty($storeAddress))
                    <p style="margin-bottom: 5px;">{{ $storeAddress }}</p>
                @endif
                <p style="margin-bottom: 5px;">
                    @if(!empty($supportPhone))
                        Phone: {{ $supportPhone }}
                    @endif
                    @if(!empty($supportPhone) && !empty($supportEmail))
                        &bull;
                    @endif
                    @if(!empty($supportEmail))
                        Email: <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                    @endif
                </p>
                <p style="margin-top: 15px; font-size: 12px; color: #94a3b8;">&copy; {{ date('Y') }} {{ $storeName ?? config('app.name') }}. All rights reserved.</p>
            </div>
        </div>
    </div>
</body>
</html>
