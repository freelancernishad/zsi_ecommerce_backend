@extends('emails.layout')

@section('title', 'Admin Notification: New Contact Message - ' . config('app.name'))

@section('content')
    <div style="display: inline-block; background-color: #fee2e2; color: #dc2626; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 12px;">Admin Notification</div>
    <h1>New Contact Form Submission</h1>
    <p>Hello Admin,</p>
    <p>A user has submitted a new inquiry via the contact form. Below are the submission details:</p>

    <div class="info-box">
        <h3 style="font-size: 16px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Contact Information</h3>
        <table>
            <tr>
                <td class="label">Name</td>
                <td class="value">{{ $name }}</td>
            </tr>
            <tr>
                <td class="label">Email Address</td>
                <td class="value" style="color: #2b59ff;">{{ $email }}</td>
            </tr>
            <tr>
                <td class="label">Subject</td>
                <td class="value">{{ $subject }}</td>
            </tr>
            <tr>
                <td class="label" style="vertical-align: top; padding-top: 10px;">Message</td>
                <td class="value" style="font-weight: normal; text-align: left; padding-top: 10px; white-space: pre-line; color: #475569;">{{ $user_message }}</td>
            </tr>
        </table>
    </div>

    <p>Please reply directly to <strong>{{ $email }}</strong> or manage this through the admin console.</p>

    <p>Best regards,<br><strong>{{ config('app.name') }} System Bot</strong></p>
@endsection
