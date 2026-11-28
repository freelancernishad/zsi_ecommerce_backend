<?php

namespace App\Services;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderEmailService
{
    /**
     * Send an order confirmation email at most once per order (concurrency-safe via lockForUpdate).
     */
    public function sendOrderConfirmation(Order $order): bool
    {
        $recipientEmail = null;
        $claimed = false;

        try {
            $claimed = DB::transaction(function () use ($order, &$recipientEmail) {
                $locked = Order::with('user')->where('id', $order->id)->lockForUpdate()->first();
                if (!$locked) {
                    return false;
                }

                $details = is_array($locked->payment_details) ? $locked->payment_details : [];
                if (!empty($details['confirmation_email_sent_at'])) {
                    return false;
                }

                $candidateEmail = trim((string) ($locked->shipping_email ?: $locked->user?->email ?: ''));
                if ($candidateEmail === '' || !filter_var($candidateEmail, FILTER_VALIDATE_EMAIL)) {
                    Log::warning("Order #{$locked->id} ({$locked->order_number}) confirmation email skipped: no valid recipient email.");
                    return false;
                }

                $recipientEmail = $candidateEmail;
                $details['confirmation_email_sent_at'] = now()->toIso8601String();
                $locked->payment_details = $details;
                $locked->save();

                return true;
            });
        } catch (\Throwable $e) {
            Log::error("Order #{$order->id} confirmation email lock failed: " . $e->getMessage());
            return false;
        }

        if (!$claimed || !$recipientEmail) {
            return false;
        }

        try {
            $freshOrder = Order::with(['items.product', 'items.variant', 'user'])->find($order->id);
            if (!$freshOrder) {
                return false;
            }

            Mail::to($recipientEmail)->send(new OrderConfirmationMail($freshOrder));
            Log::info("Order confirmation email sent to {$recipientEmail} for Order #{$freshOrder->id} ({$freshOrder->order_number}).");

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send order confirmation email for Order #{$order->id}: " . $e->getMessage());

            // Clear the sent flag so a subsequent webhook or retry can still deliver the email
            try {
                DB::transaction(function () use ($order) {
                    $locked = Order::where('id', $order->id)->lockForUpdate()->first();
                    if ($locked) {
                        $details = is_array($locked->payment_details) ? $locked->payment_details : [];
                        unset($details['confirmation_email_sent_at']);
                        $locked->payment_details = $details;
                        $locked->save();
                    }
                });
            } catch (\Throwable $revertErr) {
                Log::warning("Failed to revert confirmation_email_sent_at for Order #{$order->id}: " . $revertErr->getMessage());
            }

            return false;
        }
    }
}
