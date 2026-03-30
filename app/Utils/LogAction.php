<?php

namespace App\Utils;

use App\Models\UserLog;
use Illuminate\Support\Facades\Request;
use Jenssegers\Agent\Agent;

class LogAction
{
    /**
     * Record a system or user action log.
     *
     * @param int|null $userId The ID of the affected user (customer)
     * @param string $action Slug-like action name
     * @param string $description Human-readable description
     * @param mixed $metadata Extra context or state changes
     * @param string|null $category Classification (e.g., Auth, Profile)
     * @param bool $isSuccess Status of the action
     * @return UserLog
     */
    public static function record($userId, $action, $description, $metadata = null, $category = null, $isSuccess = true)
    {
        $causer = auth()->user();
        $agent = new Agent();
        
        return UserLog::create([
            'user_id' => $userId,
            'causer_id' => $causer ? $causer->id : null,
            'causer_name' => $causer ? $causer->name : 'System',
            'category' => $category,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata ? (is_array($metadata) ? $metadata : ['data' => $metadata]) : null,
            'device' => $agent->device(),
            'platform' => $agent->platform(),
            'browser' => $agent->browser(),
            'is_success' => $isSuccess,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
