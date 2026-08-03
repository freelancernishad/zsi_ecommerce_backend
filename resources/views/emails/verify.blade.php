@extends('emails.layout')

@section('title', 'Verify Email Address - ' . config('app.name'))

@section('content')
    <h1>Verify Your Email Address</h1>
    <p>Hello <strong>{{ $user->name ?? 'User' }}</strong>,</p>
    <p>Thank you for joining <strong>{{ config('app.name') }}</strong>! Please verify your email address by clicking the button below to complete setting up your account:</p>
    
    <div class="btn-container">
        <a href="{{ $verify_url . '?token=' . ($user->email_verification_hash ?? '') }}" class="btn-primary">Verify Email Address</a>
    </div>

    <p>If you did not create an account on <strong>{{ config('app.name') }}</strong>, no further action is required.</p>

    <p>Best regards,<br>The <strong>{{ config('app.name') }}</strong> Team</p>
@endsection
