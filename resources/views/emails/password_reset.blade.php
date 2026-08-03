@extends('emails.layout')

@section('title', 'Reset Your Password - ' . config('app.name'))

@section('content')
    <h1>Password Reset Request</h1>
    <p>Hello <strong>{{ $user->name ?? 'User' }}</strong>,</p>
    <p>We received a request to reset your password for your <strong>{{ config('app.name') }}</strong> account. Click the button below to choose a new password:</p>
    
    <div class="btn-container">
        <a href="{{ $resetUrl }}" class="btn-primary">Reset Password</a>
    </div>

    <div class="info-box" style="background-color: #fefce8; border-color: #fef08a;">
        <p style="margin: 0; font-size: 13px; color: #854d0e;">
            <strong>Note:</strong> This password reset link will expire in 60 minutes. If you did not request a password reset, no further action is required and your account remains safe.
        </p>
    </div>

    <p>Best regards,<br>The <strong>{{ config('app.name') }}</strong> Team</p>
@endsection
