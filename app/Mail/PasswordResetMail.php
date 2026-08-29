<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

use App\Models\SiteSetting;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $resetUrl;
    public $otp;

    // Dynamic settings properties
    public $storeName;
    public $storeAddress;
    public $supportPhone;
    public $supportEmail;
    public $logoUrl;

    /**
     * Create a new message instance.
     *
     * @param $user
     * @param $resetUrl
     * @param $otp
     * @return void
     */
    public function __construct($user, $resetUrl, $otp)
    {
        $this->user = $user;
        $this->resetUrl = $resetUrl;
        $this->otp = $otp;

        // Fetch settings from SiteSetting
        $this->storeName = SiteSetting::getValue('store_name', 'Online Store');
        $this->storeAddress = SiteSetting::getValue('store_address', '');
        $this->supportPhone = SiteSetting::getValue('support_phone', '');
        $this->supportEmail = SiteSetting::getValue('support_email', '');

        // Extract frontend base URL from resetUrl
        $parsedUrl = parse_url($resetUrl);
        $frontendBase = ($parsedUrl['scheme'] ?? 'http') . '://' . ($parsedUrl['host'] ?? 'localhost') . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '');
        $this->logoUrl = $frontendBase . '/logo.png';
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->view('emails.password_reset')
                    ->subject($this->storeName . ' - Password Reset Request')
                    ->with([
                        'user' => $this->user,
                        'resetUrl' => $this->resetUrl,
                        'otp' => $this->otp,
                        'storeName' => $this->storeName,
                        'storeAddress' => $this->storeAddress,
                        'supportPhone' => $this->supportPhone,
                        'supportEmail' => $this->supportEmail,
                        'logoUrl' => $this->logoUrl,
                    ]);
    }
}
