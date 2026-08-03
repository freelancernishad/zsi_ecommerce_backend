<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name'))</title>
    <style>
        /* Global & Reset Styles */
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif;
            background-color: #f1f5f9;
            color: #374151;
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
            background-color: #f1f5f9;
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
            padding: 28px 32px 20px 32px;
            text-align: center;
            border-bottom: 1px solid #f1f5f9;
        }
        .brand-logo-text {
            font-size: 24px;
            font-weight: 800;
            color: #2b59ff;
            text-decoration: none;
            letter-spacing: -0.5px;
        }
        .brand-badge {
            display: inline-block;
            background: linear-gradient(135deg, #2b59ff 0%, #1d4ed8 100%);
            color: #ffffff;
            padding: 6px 16px;
            border-radius: 9999px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.5px;
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
            background-color: #2b59ff;
            color: #ffffff !important;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            padding: 13px 32px;
            border-radius: 10px;
            box-shadow: 0 4px 14px rgba(43, 89, 255, 0.3);
            transition: all 0.2s ease;
        }

        /* Footer */
        .email-footer {
            background-color: #f8fafc;
            border-top: 1px solid #f1f5f9;
            padding: 24px 32px;
            text-align: center;
        }
        .email-footer p {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        .email-footer a {
            color: #2b59ff;
            text-decoration: none;
        }
    </style>
    @yield('extra_css')
</head>
<body>
    <div class="email-wrapper">
        <div class="email-card">
            <!-- Header -->
            <div class="email-header">
                <a href="{{ config('app.url') }}" class="brand-logo-text">
                    {{ config('app.name', 'App') }}
                </a>
            </div>

            <!-- Main Body -->
            <div class="email-body">
                @yield('content')
            </div>

            <!-- Footer -->
            <div class="email-footer">
                <p>&copy; {{ date('Y') }} {{ config('app.name', 'App') }}. All rights reserved.</p>
                <p>If you have any questions, feel free to <a href="mailto:support@{{ parse_url(config('app.url'), PHP_URL_HOST) ?? 'example.com' }}">contact support</a>.</p>
            </div>
        </div>
    </div>
</body>
</html>
