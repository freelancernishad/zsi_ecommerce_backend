@extends('emails.layout')

@section('title', 'Test Email - ' . config('app.name'))

@section('content')
    <h1>Mail System Test</h1>
    <p>Hello,</p>
    <p>This is a test email sent from <strong>{{ config('app.name') }}</strong>.</p>
    
    <div class="info-box" style="background-color: #f0fdf4; border-color: #bbf7d0;">
        <p style="margin: 0; font-size: 14px; color: #166534; font-weight: 600;">
            ✓ Your SMTP and Email Configuration is working properly!
        </p>
    </div>

    <p>Best regards,<br>The <strong>{{ config('app.name') }}</strong> Technical Team</p>
@endsection
