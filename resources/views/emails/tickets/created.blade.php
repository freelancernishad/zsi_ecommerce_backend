@extends('emails.layout')

@section('title', 'Ticket Received - ' . config('app.name'))

@section('content')
    <h1>We Received Your Support Ticket</h1>
    <p>Hi <strong>{{ $user_name }}</strong>,</p>
    <p>This email confirms that we have received your support request. A support team member will review your request and get back to you as soon as possible.</p>

    <div class="info-box">
        <h3 style="font-size: 16px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Ticket Overview</h3>
        <table>
            <tr>
                <td class="label">Ticket ID</td>
                <td class="value" style="color: #2b59ff;">#{{ $ticket_id }}</td>
            </tr>
            <tr>
                <td class="label">Subject</td>
                <td class="value">{{ $subject }}</td>
            </tr>
            <tr>
                <td class="label">Priority</td>
                <td class="value" style="text-transform: capitalize;">{{ $priority }}</td>
            </tr>
            <tr>
                <td class="label">Status</td>
                <td class="value" style="text-transform: capitalize;">{{ $status }}</td>
            </tr>
        </table>
    </div>

    <p>You can check the live status of your ticket, add comments, or view updates directly from your support center.</p>

    <div class="btn-container">
        <a href="{{ url('/user/support') }}" class="btn-primary">View Support Ticket</a>
    </div>

    <p>Best regards,<br>The <strong>{{ config('app.name') }}</strong> Support Team</p>
@endsection
