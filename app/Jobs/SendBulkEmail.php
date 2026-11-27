<?php

namespace App\Jobs;

use App\Services\TenantDatabaseManager;
use App\Services\TenantSettingsLoader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendBulkEmail implements ShouldQueue
{
    use Queueable;

    public $recipients;
    public $subject;
    public $content;
    public $templateId;
    public ?string $tenantDatabase = null;

    /**
     * Create a new job instance.
     */
    public function __construct($recipients, $subject, $content, $templateId = null, ?string $tenantDatabase = null)
    {
        $this->recipients = $recipients;
        $this->subject = $subject;
        $this->content = $content;
        $this->templateId = $templateId;
        $this->tenantDatabase = $tenantDatabase ?: TenantDatabaseManager::currentDatabase();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->tenantDatabase) {
            TenantDatabaseManager::switchByDomainOrDatabase(null, $this->tenantDatabase);
            TenantSettingsLoader::applyForCurrentTenant();
        }

        foreach ($this->recipients as $recipient) {
            \Mail::to($recipient['email'])->send(new \App\Mail\DynamicEmail($this->subject, $this->content, ['name' => $recipient['name']]));
            
            // Log the email
            \App\Models\EmailLog::create([
                'recipient' => $recipient['email'],
                'subject' => $this->subject,
                'status' => 'sent',
                'template_id' => $this->templateId,
            ]);
        }
    }
}
