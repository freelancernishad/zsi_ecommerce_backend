<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncStripeCards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stripe:sync-cards {user_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Stripe payment methods to local database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->argument('user_id');
        $users = $userId ? [\App\Models\User::find($userId)] : \App\Models\User::whereNotNull('stripe_id')->get();

        $stripeService = app(\App\Services\Gateways\StripeService::class);
        $count = 0;

        foreach ($users as $user) {
            if (!$user || !$user->stripe_id) continue;

            $this->info("Syncing cards for user: {$user->email}");
            
            try {
                $cards = $stripeService->listCards($user);
                foreach ($cards->data as $card) {
                    $stripeService->syncPaymentMethod($user, $card);
                    $count++;
                }
            } catch (\Exception $e) {
                $this->error("Failed to sync for user {$user->id}: " . $e->getMessage());
            }
        }

        $this->info("Successfully synced {$count} cards.");
    }
}
