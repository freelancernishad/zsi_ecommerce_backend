@extends('emails.layout')

@section('title', 'Welcome to ' . config('app.name'))

@section('content')
    <div style="text-align: center; margin-bottom: 20px;">
        <span style="font-size: 40px; display: inline-block; margin-bottom: 8px;">🎉</span>
        <h1>Welcome, {{ $name }}!</h1>
    </div>

    <p>We're excited to have you on board! Your account registration on <strong>{{ config('app.name') }}</strong> was completed successfully.</p>

    <p>You can now log in to explore all the features, services, and products available on our platform.</p>

    <div class="btn-container">
        <a href="{{ config('app.url') }}" class="btn-primary">Explore Platform</a>
    </div>

    <p>If you have any questions or need assistance, our support team is always here to help.</p>

    <p>Best regards,<br>The <strong>{{ config('app.name') }}</strong> Team</p>
@endsection
