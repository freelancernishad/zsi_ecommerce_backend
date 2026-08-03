@extends('emails.layout')

@section('title', 'Admin Notification: New Ticket #' . $ticket_id . ' - ' . config('app.name'))

@section('content')
    <div style="display: inline-block; background-color: #fee2e2; color: #dc2626; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 12px;">Admin Notification</div>
    <h1>New Support Ticket Submitted</h1>
    <p>Hello Admin,</p>
    <p>A new support ticket has been created by <strong>{{ $user_name }}</strong>.</p>

    <div class="info-box">
        <h3 style="font-size: 16px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Ticket Metadata</h3>
        <table>
            <tr>
                <td class="label">Ticket ID</td>
                <td class="value" style="color: #2b59ff;">#{{ $ticket_id }}</td>
            </tr>
            <tr>
                <td class="label">Submitted By</td>
                <td class="value">{{ $user_name }}</td>
            </tr>
            <tr>
                <td class="label">Subject</td>
                <td class="value">{{ $subject }}</td>
            </tr>
            <tr>
                <td class="label">Priority</td>
                <td class="value" style="text-transform: capitalize;">{{ $priority }}</td>
            </tr>
        </table>
    </div>

    <p>Please review and assign a support agent to handle this ticket.</p>

    <div class="btn-container">
        <a href="{{ url('/admin/support/tickets/' . $ticket_id) }}" class="btn-primary" style="background-color: #0f172a; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.3);">Manage Ticket in Admin Panel</a>
    </div>

    <p>Best regards,<br><strong>{{ config('app.name') }} System</strong></p>
@endsection
