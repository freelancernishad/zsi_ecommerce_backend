<?php

namespace App\Mail;

use App\Providers\AppServiceProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

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
    public function __construct($user, $resetUrl, $otp = null)
    {
        $this->user = $user;
        $this->resetUrl = $resetUrl;
        $this->otp = $otp;

        $branding = AppServiceProvider::resolveEmailBranding();
        $this->storeName = $branding['storeName'];
        $this->storeAddress = $branding['storeAddress'];
        $this->supportPhone = $branding['supportPhone'];
        $this->supportEmail = $branding['supportEmail'];
        $this->logoUrl = $branding['logoUrl'];
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
