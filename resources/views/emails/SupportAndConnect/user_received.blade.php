@extends('emails.layout')

@section('title', 'Message Received - ' . config('app.name'))

@section('content')
    <div style="text-align: center; margin-bottom: 20px;">
        <span style="font-size: 36px;">✉️</span>
        <h1>Contact Message Received</h1>
    </div>

    <p>Hello <strong>{{ $name }}</strong>,</p>
    <p>Thank you for reaching out to <strong>{{ config('app.name') }}</strong>! We have received your inquiry and our team will get back to you shortly.</p>

    <div class="info-box">
        <h3 style="font-size: 16px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Message Details</h3>
        <table>
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

    <p>We appreciate your patience and look forward to assisting you.</p>

    <p>Best regards,<br>The <strong>{{ config('app.name') }}</strong> Support Team</p>
@endsection
