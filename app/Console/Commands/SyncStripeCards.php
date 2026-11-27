<?php

namespace App\Console\Commands;

use App\Services\Gateways\StripeService;
use App\Services\TenantDatabaseManager;
use App\Services\TenantSettingsLoader;
use Illuminate\Console\Command;

class SyncStripeCards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stripe:sync-cards {user_id?} {--tenant= : Specific tenant domain or database name} {--all-tenants : Run across all configured tenant databases}';

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

        if ($this->option('all-tenants')) {
            $totalCount = 0;
            foreach (TenantDatabaseManager::allowedDatabases() as $db) {
                $this->info("Switching to tenant DB: {$db}");
                TenantDatabaseManager::switchToDatabase($db);
                TenantSettingsLoader::applyForCurrentTenant();
                $totalCount += $this->syncForActiveTenant($userId);
            }
            $this->info("Successfully synced {$totalCount} cards across all tenants.");
            return self::SUCCESS;
        }

        if ($tenant = $this->option('tenant')) {
            if (!TenantDatabaseManager::switchByDomainOrDatabase($tenant, $tenant)) {
                $this->error("No matching whitelisted tenant found for: {$tenant}");
                return self::FAILURE;
            }
            TenantSettingsLoader::applyForCurrentTenant();
        }

        $count = $this->syncForActiveTenant($userId);
        $this->info("Successfully synced {$count} cards.");
        return self::SUCCESS;
    }

    /**
     * Sync cards for users in the currently active tenant database.
     */
    protected function syncForActiveTenant(?string $userId): int
    {
        $users = $userId
            ? [\App\Models\User::find($userId)]
            : \App\Models\User::whereNotNull('stripe_id')->get();

        $stripeService = new StripeService();
        $count = 0;

        foreach ($users as $user) {
            if (!$user || !$user->stripe_id) {
                continue;
            }

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

        return $count;
    }
}
