@extends('emails.layout')

@section('title', 'Admin Notification: User Reply on Ticket #' . $ticket_id . ' - ' . config('app.name'))

@section('extra_css')
<style>
    .reply-box {
        background-color: #f8fafc;
        border-left: 4px solid #0f172a;
        padding: 18px 20px;
        margin: 24px 0;
        border-radius: 0 12px 12px 0;
        color: #1e293b;
        font-size: 15px;
        line-height: 1.6;
    }
</style>
@endsection

@section('content')
    <div style="display: inline-block; background-color: #fee2e2; color: #dc2626; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 12px;">Admin Alert</div>
    <h1>New User Reply on Ticket #{{ $ticket_id }}</h1>
    <p>Hello Admin,</p>
    <p>User <strong>{{ $user_name }}</strong> has replied to support ticket #{{ $ticket_id }}.</p>

    <div class="reply-box">
        "{{ \Illuminate\Support\Str::limit($reply_content, 300) }}"
    </div>

    <div class="info-box">
        <h3 style="font-size: 16px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Ticket Overview</h3>
        <table>
            <tr>
                <td class="label">Ticket ID</td>
                <td class="value" style="color: #2b59ff;">#{{ $ticket_id }}</td>
            </tr>
            <tr>
                <td class="label">User</td>
                <td class="value">{{ $user_name }}</td>
            </tr>
            <tr>
                <td class="label">Subject</td>
                <td class="value">{{ $ticket_subject }}</td>
            </tr>
            <tr>
                <td class="label">Status</td>
                <td class="value" style="text-transform: capitalize;">{{ $ticket_status }}</td>
            </tr>
        </table>
    </div>

    <div class="btn-container">
        <a href="{{ url('/admin/support/tickets/' . $ticket_id) }}" class="btn-primary" style="background-color: #0f172a; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.3);">View & Respond in Admin Panel</a>
    </div>

    <p>Best regards,<br><strong>{{ config('app.name') }} System</strong></p>
@endsection
