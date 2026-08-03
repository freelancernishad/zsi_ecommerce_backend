@extends('emails.layout')

@section('title', 'Your Verification Code - ' . config('app.name'))

@section('extra_css')
<style>
    .otp-card {
        background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
        border: 2px dashed #2b59ff;
        border-radius: 14px;
        padding: 24px;
        text-align: center;
        margin: 28px 0;
    }
    .otp-code {
        font-size: 38px;
        font-weight: 800;
        letter-spacing: 10px;
        color: #2b59ff;
        margin: 8px 0;
        font-family: 'Courier New', Courier, monospace;
    }
    .otp-subtitle {
        font-size: 13px;
        color: #64748b;
        margin-bottom: 0;
    }
</style>
@endsection

@section('content')
    <h1>Verify Your Account</h1>
    <p>Thank you for registering with <strong>{{ config('app.name') }}</strong>! To complete your verification process, please use the following One-Time Password (OTP):</p>
    
    <div class="otp-card">
        <div class="otp-code">{{ $otp }}</div>
        <p class="otp-subtitle">Valid for a short period. Do not share this code with anyone.</p>
    </div>

    <p>If you did not request this OTP, please ignore this message or contact support if you suspect unauthorized activity.</p>
    <p>Best regards,<br>The <strong>{{ config('app.name') }}</strong> Team</p>
@endsection
