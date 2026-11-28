<?php

namespace App\Mail;

use App\Providers\AppServiceProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DynamicEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $subject;
    public $htmlContent;
    public $data;

    /**
     * Create a new message instance.
     */
    public function __construct($subject, $htmlContent, $data = [])
    {
        $this->subject = $subject;
        $this->htmlContent = $htmlContent;
        $this->data = $data;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $content = $this->htmlContent;

        $branding = AppServiceProvider::resolveEmailBranding();
        $companyName = $branding['storeName'] !== ''
            ? $branding['storeName']
            : config('app.name', 'Online Store');

        // Default data for expansion
        $allData = array_merge([
            'date' => date('M d, Y'),
            'company' => $companyName,
            'store_name' => $companyName,
            'store_url' => $branding['storeUrl'],
            'site_url' => $branding['storeUrl'],
            'website' => $branding['storeUrl'],
            'admin_url' => $branding['adminUrl'],
            'support_email' => $branding['supportEmail'],
        ], $this->data);

        foreach ($allData as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
            $content = str_replace('{{ ' . $key . ' }}', $value, $content);
        }

        return $this->subject($this->subject)
                    ->html($content);
    }
}
