<?php

namespace Tests\Feature;

use App\Jobs\SendBulkEmail;
use App\Models\AllowedOrigin;
use App\Models\EmailLog;
use App\Models\SystemSetting;
use App\Services\TenantDatabaseManager;
use App\Services\TenantSettingsLoader;
use CjDropshipping\Jobs\ProcessCjBulkImportJob;
use CjDropshipping\Services\CjImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Config::set('tenants.domains', [
            'goldenmarkstore.com' => 'mustgyyu_zsi_ecommerce_db',
            'bismillahfashionusa.com' => 'mustgyyu_bismillahfashionusa_db',
            'ebsdeals.com' => 'mustgyyu_ebsdeals',
            'everythingpet.org' => 'mustgyyu_everythingpet',
            'localhost' => 'goldenmarkstore_db',
        ]);
        Config::set('tenants.fallback', 'goldenmarkstore_db');
        Config::set('tenants.original_default_database', 'goldenmarkstore_db');
        Config::set('tenants.base_cors_allowed_origins', ['https://base.example']);
        Config::set('cors.allowed_origins', ['https://base.example']);
        Config::set('database.connections.mysql.database', 'goldenmarkstore_db');
        Config::set('tenants.current_database', 'goldenmarkstore_db');
        Config::set('tenants.current_domain', null);

        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('allowed_origins');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('users');

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('allowed_origins', function (Blueprint $table) {
            $table->id();
            $table->string('origin_url')->unique();
            $table->timestamps();
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('recipient');
            $table->string('subject');
            $table->string('status')->default('sent');
            $table->unsignedBigInteger('template_id')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('stripe_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_tenant_settings_loader_isolates_cache_cors_and_clears_on_model_events(): void
    {
        // Tenant A
        TenantDatabaseManager::switchByDomainOrDatabase('ebsdeals.com');
        SystemSetting::setValue('MAIL_FROM_ADDRESS', 'support@ebsdeals.com');
        SystemSetting::setValue('STRIPE_SECRET', 'sk_test_ebsdeals');
        $originA = AllowedOrigin::create(['origin_url' => 'https://ebsdeals.com']);

        TenantSettingsLoader::applyForCurrentTenant();

        $this->assertSame('support@ebsdeals.com', Config::get('mail.from.address'));
        $this->assertSame('sk_test_ebsdeals', Config::get('services.stripe.secret'));
        $this->assertContains('https://ebsdeals.com', Config::get('cors.allowed_origins'));
        $this->assertTrue(Cache::has('system_settings:mustgyyu_ebsdeals'));
        $this->assertTrue(Cache::has('allowed_origins:mustgyyu_ebsdeals'));

        // Switch to Tenant B and replace table contents to simulate separate tenant DB
        TenantDatabaseManager::switchByDomainOrDatabase('everythingpet.org');
        SystemSetting::query()->delete();
        AllowedOrigin::query()->delete();
        SystemSetting::setValue('MAIL_FROM_ADDRESS', 'hello@everythingpet.org');
        SystemSetting::setValue('STRIPE_SECRET', 'sk_test_everythingpet');
        AllowedOrigin::create(['origin_url' => 'https://everythingpet.org']);

        TenantSettingsLoader::applyForCurrentTenant();

        $this->assertSame('hello@everythingpet.org', Config::get('mail.from.address'));
        $this->assertSame('sk_test_everythingpet', Config::get('services.stripe.secret'));
        $this->assertContains('https://everythingpet.org', Config::get('cors.allowed_origins'));
        $this->assertNotContains('https://ebsdeals.com', Config::get('cors.allowed_origins'));

        // Model events must clear active tenant caches
        SystemSetting::setValue('MAIL_FROM_ADDRESS', 'updated@everythingpet.org');
        $this->assertFalse(Cache::has('system_settings:mustgyyu_everythingpet'));

        $originA->delete();
        $this->assertFalse(Cache::has('allowed_origins:mustgyyu_everythingpet'));
    }

    public function test_send_bulk_email_job_restores_dispatched_tenant_database(): void
    {
        Mail::fake();

        TenantDatabaseManager::switchByDomainOrDatabase('bismillahfashionusa.com');
        $job = new SendBulkEmail(
            [['email' => 'buyer@example.com', 'name' => 'Buyer']],
            'Order Update',
            '<p>Hello {{name}}</p>',
            null
        );

        $this->assertSame('mustgyyu_bismillahfashionusa_db', $job->tenantDatabase);

        // Simulate queue worker starting on a different/default database
        TenantDatabaseManager::switchToDatabase('goldenmarkstore_db');
        $this->assertSame('goldenmarkstore_db', Config::get('database.connections.mysql.database'));

        $job->handle();

        $this->assertSame('mustgyyu_bismillahfashionusa_db', Config::get('database.connections.mysql.database'));
        Mail::assertSent(\App\Mail\DynamicEmail::class);
        $this->assertSame(1, EmailLog::where('recipient', 'buyer@example.com')->count());
    }

    public function test_process_cj_bulk_import_job_restores_tenant_before_resolving_service(): void
    {
        TenantDatabaseManager::switchByDomainOrDatabase('ebsdeals.com');
        $job = new ProcessCjBulkImportJob(['cj_pid_1', 'cj_pid_2'], 25.0, 5);
        $this->assertSame('mustgyyu_ebsdeals', $job->tenantDatabase);

        // Switch away to default DB to simulate worker state
        TenantDatabaseManager::switchToDatabase('goldenmarkstore_db');

        $mockService = Mockery::mock(CjImportService::class);
        $mockService->shouldReceive('bulkImportCjProducts')
            ->once()
            ->with(['cj_pid_1', 'cj_pid_2'], 25.0, 5)
            ->andReturnUsing(function () {
                $this->assertSame('mustgyyu_ebsdeals', Config::get('database.connections.mysql.database'));
                return ['total' => 2, 'successful' => 2, 'failed' => 0];
            });

        $this->app->instance(CjImportService::class, $mockService);

        $job->handle();
        $this->assertSame('mustgyyu_ebsdeals', Config::get('database.connections.mysql.database'));
    }

    public function test_sync_stripe_cards_command_supports_tenant_option_and_rejects_unlisted(): void
    {
        $this->artisan('stripe:sync-cards', ['--tenant' => 'evil_unknown'])
            ->assertExitCode(1);

        $this->artisan('stripe:sync-cards', ['--tenant' => 'ebsdeals.com'])
            ->assertExitCode(0);

        $this->assertSame('mustgyyu_ebsdeals', Config::get('database.connections.mysql.database'));
    }
}
