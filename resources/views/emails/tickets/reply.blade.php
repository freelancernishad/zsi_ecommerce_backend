@extends('emails.layout')

@section('title', 'New Reply on Ticket #' . $ticket_id . ' - ' . config('app.name'))

@section('extra_css')
<style>
    .reply-box {
        background-color: #eff6ff;
        border-left: 4px solid #2b59ff;
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
    <h1>New Reply on Ticket #{{ $ticket_id }}</h1>
    <p>Hi <strong>{{ $user_name }}</strong>,</p>
    <p>A new response has been added to your support ticket:</p>

    <div class="reply-box">
        "{{ \Illuminate\Support\Str::limit($reply_content, 300) }}"
    </div>

    <div class="info-box">
        <h3 style="font-size: 16px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Ticket Details</h3>
        <table>
            <tr>
                <td class="label">Ticket ID</td>
                <td class="value" style="color: #2b59ff;">#{{ $ticket_id }}</td>
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

    <p>Click the button below to view the full response and continue the conversation.</p>

    <div class="btn-container">
        <a href="{{ url('/user/support') }}" class="btn-primary">View & Reply to Ticket</a>
    </div>

    <p>Best regards,<br>The <strong>{{ config('app.name') }}</strong> Support Team</p>
@endsection
