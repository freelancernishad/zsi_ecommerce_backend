<?php

namespace App\Mail;

use App\Models\Order;
use App\Providers\AppServiceProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Order $order;
    public string $customerName;
    public string $trackOrderUrl;
    public string $myOrdersUrl;
    public string $storeName;
    public string $storeAddress;
    public string $supportPhone;
    public string $supportEmail;
    public ?string $logoUrl;
    public string $storeUrl;
    public string $adminUrl;

    public function __construct(Order $order)
    {
        $order->loadMissing(['items.product', 'items.variant', 'user']);
        $this->order = $order;

        $branding = AppServiceProvider::resolveEmailBranding();
        $this->storeName = $branding['storeName'];
        $this->storeAddress = $branding['storeAddress'];
        $this->supportPhone = $branding['supportPhone'];
        $this->supportEmail = $branding['supportEmail'];
        $this->logoUrl = $branding['logoUrl'];
        $this->storeUrl = $branding['storeUrl'];
        $this->adminUrl = $branding['adminUrl'];

        $fullName = trim(($order->shipping_first_name ?? '') . ' ' . ($order->shipping_last_name ?? ''));
        $this->customerName = $fullName !== ''
            ? $fullName
            : ($order->user?->name ?: 'Valued Customer');

        $baseStoreUrl = rtrim($this->storeUrl !== '' ? $this->storeUrl : (string) config('app.url'), '/');
        $this->trackOrderUrl = $baseStoreUrl . '/track-order?order_number=' . urlencode((string) $order->order_number);
        $this->myOrdersUrl = $baseStoreUrl . '/my-account/orders';
    }

    public function build()
    {
        return $this->view('emails.orders.confirmation')
            ->subject('Order Confirmed #' . $this->order->order_number . ' - ' . $this->storeName)
            ->with([
                'order' => $this->order,
                'customerName' => $this->customerName,
                'trackOrderUrl' => $this->trackOrderUrl,
                'myOrdersUrl' => $this->myOrdersUrl,
                'storeName' => $this->storeName,
                'storeAddress' => $this->storeAddress,
                'supportPhone' => $this->supportPhone,
                'supportEmail' => $this->supportEmail,
                'logoUrl' => $this->logoUrl,
                'storeUrl' => $this->storeUrl,
                'adminUrl' => $this->adminUrl,
            ]);
    }
}
