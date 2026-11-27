<?php

namespace App\Http\Middleware;

use App\Services\TenantDatabaseManager;
use App\Services\TenantSettingsLoader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SwitchTenantDatabase
{
    /**
     * Handle an incoming request and switch MySQL connection dynamically
     * based on domain hostname, query parameter, or X-Tenant-Domain header.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resolved = TenantDatabaseManager::resolveFromRequest($request);
        TenantDatabaseManager::switchToDatabase($resolved['database'], $resolved['domain']);
        TenantSettingsLoader::applyForCurrentTenant();

        return $next($request);
    }
}
