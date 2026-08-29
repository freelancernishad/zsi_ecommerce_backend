<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SwitchTenantDatabase
{
    /**
     * Handle an incoming request and switch MySQL connection dynamically
     * based on domain hostname or X-Tenant-Domain header.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $headerDomain = $request->header('X-Tenant-Domain');
        $hostDomain   = $request->getHost();

        $domainKey = $headerDomain ?: $hostDomain;
        $tenantDomains = config('tenants.domains', []);
        $fallbackDb    = config('tenants.fallback', config('database.connections.mysql.database'));

        if ($domainKey && array_key_exists($domainKey, $tenantDomains)) {
            $databaseName = $tenantDomains[$domainKey];
        } else {
            $databaseName = $fallbackDb;
        }

        // Dynamically reconfigure database connection
        $currentDb = Config::get('database.connections.mysql.database');

        if ($currentDb !== $databaseName) {
            Config::set('database.connections.mysql.database', $databaseName);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        return $next($request);
    }
}
