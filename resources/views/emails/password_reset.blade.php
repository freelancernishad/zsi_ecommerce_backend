@extends('emails.layout')

@section('title', 'Reset Your Password - ' . config('app.name'))

@section('content')
    <h1>Password Reset Request</h1>
    <p>Hello <strong>{{ $user->name ?? 'User' }}</strong>,</p>
    <p>We received a request to reset your password for your <strong>{{ config('app.name') }}</strong> account. You can reset your password using either of the following two options:</p>
    
    <div style="margin: 20px 0; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; background-color: #f9fafb;">
        <h3 style="margin-top: 0; color: #111827; font-size: 16px;">Option 1: Reset using OTP Code</h3>
        <p style="margin-bottom: 12px; font-size: 14px; color: #4b5563;">Enter this 6-digit OTP code directly on the reset page:</p>
        <div style="font-size: 26px; font-weight: bold; background-color: #e5e7eb; padding: 12px; text-align: center; border-radius: 8px; letter-spacing: 5px; color: #1e3a8a; max-width: 200px; margin: 0 auto 10px auto;">
            {{ $otp }}
        </div>
    </div>

    <div style="margin: 20px 0; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; background-color: #f9fafb;">
        <h3 style="margin-top: 0; color: #111827; font-size: 16px;">Option 2: Reset using Direct Link</h3>
        <p style="margin-bottom: 15px; font-size: 14px; color: #4b5563;">Click the button below to go to the password reset page directly:</p>
        <div class="btn-container" style="text-align: center; margin: 0;">
            <a href="{{ $resetUrl }}" class="btn-primary" style="display: inline-block;">Reset Password</a>
        </div>
    </div>

    <div class="info-box" style="background-color: #fefce8; border-color: #fef08a;">
        <p style="margin: 0; font-size: 13px; color: #854d0e;">
            <strong>Note:</strong> The OTP code and reset link will expire in 10 minutes. If you did not request a password reset, no further action is required and your account remains safe.
        </p>
    </div>

    <p>Best regards,<br>The <strong>{{ config('app.name') }}</strong> Team</p>
@endsection
