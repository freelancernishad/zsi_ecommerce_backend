<?php

namespace App\Utils;

use App\Models\UserLog;
use Illuminate\Support\Facades\Request;

class LogAction
{
    /**
     * Record a system or user action log.
     *
     * @param int|null $userId The ID of the affected user (customer)
     * @param string $action Slug-like action name
     * @param string $description Human-readable description
     * @param mixed $metadata Extra context or state changes
     * @return UserLog
     */
    public static function record($userId, $action, $description, $metadata = null)
    {
        $causer = auth()->user();
        
        return UserLog::create([
            'user_id' => $userId,
            'causer_id' => $causer ? $causer->id : null,
            'causer_name' => $causer ? $causer->name : 'System',
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata ? (is_array($metadata) ? $metadata : ['data' => $metadata]) : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
