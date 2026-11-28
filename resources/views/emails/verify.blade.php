@extends('emails.layout')

@section('title', 'Verify Email Address - ' . ($storeName ?? config('app.name')))

@section('content')
    <h1>Verify Your Email Address</h1>
    <p>Hello <strong>{{ $user->name ?? 'User' }}</strong>,</p>
    <p>Thank you for joining <strong>{{ $storeName ?? config('app.name') }}</strong>! Please verify your email address by clicking the button below to complete setting up your account:</p>
    
    @php
        $baseVerifyUrl = !empty($verify_url) ? $verify_url : (rtrim($storeUrl ?? config('app.url'), '/') . '/verify-otp');
        $separator = str_contains($baseVerifyUrl, '?') ? '&' : '?';
        $fullVerifyUrl = $baseVerifyUrl . $separator . 'token=' . ($user->email_verification_hash ?? '');
    @endphp
    <div class="btn-container">
        <a href="{{ $fullVerifyUrl }}" class="btn-primary">Verify Email Address</a>
    </div>

    <p>If you did not create an account on <strong>{{ $storeName ?? config('app.name') }}</strong>, no further action is required.</p>

    <p>Best regards,<br>The <strong>{{ $storeName ?? config('app.name') }}</strong> Team</p>
@endsection
