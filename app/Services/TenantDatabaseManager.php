<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class TenantDatabaseManager
{
    /**
     * Normalize a domain/URL input into lowercase host[:port] without protocol or path.
     */
    public static function normalizeDomain(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtolower(trim($value));
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            $host = parse_url($trimmed, PHP_URL_HOST);
            if (!$host) {
                return null;
            }
            $port = parse_url($trimmed, PHP_URL_PORT);
            return $port ? "{$host}:{$port}" : $host;
        }

        // Strip accidental path/query fragments if passed without scheme
        $trimmed = explode('/', $trimmed)[0];
        $trimmed = explode('?', $trimmed)[0];

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Return lowercased [domain => databaseName] map from config/tenants.php.
     */
    public static function tenantDomainsMap(): array
    {
        $domains = config('tenants.domains', []);
        $map = [];

        foreach ($domains as $domain => $db) {
            $normalized = self::normalizeDomain((string) $domain);
            if ($normalized && is_string($db) && trim($db) !== '') {
                $map[$normalized] = trim($db);
            }
        }

        return $map;
    }

    /**
     * Return all whitelisted database names.
     */
    public static function allowedDatabases(): array
    {
        $originalDefault = Config::get('tenants.original_default_database');
        if ($originalDefault === null) {
            $originalDefault = Config::get('database.connections.mysql.database');
            Config::set('tenants.original_default_database', $originalDefault);
        }

        $dbs = array_values(self::tenantDomainsMap());
        $fallback = config('tenants.fallback');
        if (is_string($fallback) && trim($fallback) !== '') {
            $dbs[] = trim($fallback);
        }
        if (is_string($originalDefault) && trim($originalDefault) !== '') {
            $dbs[] = trim($originalDefault);
        }

        return array_values(array_unique(array_filter($dbs)));
    }

    /**
     * Resolve tenant domain and target database from an incoming HTTP request.
     *
     * @return array{domain: ?string, database: string}
     */
    public static function resolveFromRequest(Request $request): array
    {
        // Ensure original default database is captured before any switching occurs
        if (Config::get('tenants.original_default_database') === null) {
            Config::set('tenants.original_default_database', Config::get('database.connections.mysql.database'));
        }

        $candidates = [];

        // 1. Explicit query parameter (?tenant=domain.com) for webhook URLs
        if ($queryTenant = $request->query('tenant')) {
            $candidates[] = (string) $queryTenant;
        }

        // 2. Explicit custom header
        if ($header = $request->header('X-Tenant-Domain')) {
            $candidates[] = (string) $header;
        }

        // 3. X-Forwarded-Host header (sent by proxies or SSR Next.js requests)
        if ($forwardedHost = $request->header('X-Forwarded-Host')) {
            $candidates[] = (string) $forwardedHost;
        }

        // 4. HTTP Origin header
        if ($origin = $request->header('Origin')) {
            $originHost = parse_url($origin, PHP_URL_HOST);
            $originPort = parse_url($origin, PHP_URL_PORT);
            if ($originHost && $originPort) {
                $candidates[] = $originHost . ':' . $originPort;
            }
            if ($originHost) {
                $candidates[] = $originHost;
            }
        }

        // 5. HTTP Referer header
        if ($referer = $request->header('Referer')) {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            $refererPort = parse_url($referer, PHP_URL_PORT);
            if ($refererHost && $refererPort) {
                $candidates[] = $refererHost . ':' . $refererPort;
            }
            if ($refererHost) {
                $candidates[] = $refererHost;
            }
        }

        // 6. Request Host header (including port) & Host without 'api.' prefix
        $candidates[] = $request->getHttpHost();
        $host = $request->getHost();
        $candidates[] = $host;
        if (str_starts_with(strtolower($host), 'api.')) {
            $candidates[] = substr($host, 4);
        }

        $tenantDomains = self::tenantDomainsMap();

        foreach ($candidates as $rawCandidate) {
            $matched = self::matchDomainInMap($rawCandidate, $tenantDomains);
            if ($matched !== null) {
                return $matched;
            }
        }

        $fallbackDb = (string) config('tenants.fallback', Config::get('database.connections.mysql.database'));

        return [
            'domain' => null,
            'database' => $fallbackDb,
        ];
    }

    /**
     * Match a domain candidate against the tenant map (trying exact, without api., without :port).
     *
     * @return array{domain: string, database: string}|null
     */
    private static function matchDomainInMap(?string $candidate, array $tenantDomains): ?array
    {
        $normalized = self::normalizeDomain($candidate);
        if (!$normalized) {
            return null;
        }

        $variants = [$normalized];
        if (str_starts_with($normalized, 'api.')) {
            $variants[] = substr($normalized, 4);
        }
        if (str_contains($normalized, ':')) {
            $noPort = explode(':', $normalized)[0];
            $variants[] = $noPort;
            if (str_starts_with($noPort, 'api.')) {
                $variants[] = substr($noPort, 4);
            }
        }

        foreach (array_unique($variants) as $variant) {
            if (array_key_exists($variant, $tenantDomains)) {
                return [
                    'domain' => $variant,
                    'database' => $tenantDomains[$variant],
                ];
            }
        }

        return null;
    }

    /**
     * Switch the active tenant database connection.
     */
    public static function switchToDatabase(string $databaseName, ?string $domain = null): void
    {
        if (Config::get('tenants.original_default_database') === null) {
            Config::set('tenants.original_default_database', Config::get('database.connections.mysql.database'));
        }

        Config::set('tenants.current_database', $databaseName);
        Config::set('tenants.current_domain', $domain);

        $currentDb = Config::get('database.connections.mysql.database');
        if ($currentDb !== $databaseName) {
            Config::set('database.connections.mysql.database', $databaseName);
            if (Config::get('database.default') === 'mysql') {
                DB::purge('mysql');
                DB::reconnect('mysql');
            }
        }
    }

    /**
     * Switch to a whitelisted tenant database by domain or database name.
     */
    public static function switchByDomainOrDatabase(?string $tenantDomain = null, ?string $tenantDatabase = null): bool
    {
        $tenantDomains = self::tenantDomainsMap();

        if ($tenantDomain !== null && trim($tenantDomain) !== '') {
            $matched = self::matchDomainInMap($tenantDomain, $tenantDomains);
            if ($matched !== null) {
                self::switchToDatabase($matched['database'], $matched['domain']);
                return true;
            }
        }

        if ($tenantDatabase !== null && trim($tenantDatabase) !== '') {
            $cleanDb = trim($tenantDatabase);
            if (in_array($cleanDb, self::allowedDatabases(), true)) {
                $matchedDomain = array_search($cleanDb, $tenantDomains, true);
                self::switchToDatabase($cleanDb, $matchedDomain !== false ? $matchedDomain : null);
                return true;
            }
        }

        return false;
    }

    /**
     * Get the current resolved tenant domain.
     */
    public static function currentTenantDomain(?Request $request = null): ?string
    {
        $current = Config::get('tenants.current_domain');
        if (is_string($current) && $current !== '') {
            return $current;
        }

        if ($request) {
            $resolved = self::resolveFromRequest($request);
            if (!empty($resolved['domain'])) {
                return $resolved['domain'];
            }

            $headerDomain = self::normalizeDomain($request->header('X-Tenant-Domain'));
            if ($headerDomain) {
                return $headerDomain;
            }

            if ($origin = $request->header('Origin')) {
                $originDomain = self::normalizeDomain($origin);
                if ($originDomain) {
                    return $originDomain;
                }
            }
        }

        $currentDb = Config::get('database.connections.mysql.database');
        if ($currentDb) {
            $matchedDomain = array_search($currentDb, self::tenantDomainsMap(), true);
            if ($matchedDomain !== false) {
                return $matchedDomain;
            }
        }

        return null;
    }

    /**
     * Get the current tenant database name.
     */
    public static function currentDatabase(): string
    {
        return (string) (Config::get('tenants.current_database') ?: Config::get('database.connections.mysql.database'));
    }

    /**
     * Return tenant metadata keys for embedding into payment provider payloads.
     */
    public static function metadataForCurrentTenant(?Request $request = null): array
    {
        return array_filter([
            'tenant_domain' => self::currentTenantDomain($request),
            'tenant_db' => self::currentDatabase(),
        ], fn ($v) => is_string($v) && $v !== '');
    }

    /**
     * Extract tenant_domain and tenant_db from Stripe or ZSI Pay webhook payloads.
     *
     * @return array{tenant_domain: ?string, tenant_db: ?string}
     */
    public static function extractTenantMetadataFromPayload(array $payload): array
    {
        $metadataSources = [
            data_get($payload, 'data.object.metadata'),
            data_get($payload, 'data.object.subscription_details.metadata'),
            data_get($payload, 'data.object.parent.subscription_details.metadata'),
            data_get($payload, 'data.object.lines.data.0.metadata'),
            data_get($payload, 'data.metadata'),
            data_get($payload, 'metadata'),
            [
                'tenant_domain' => $payload['tenant_domain'] ?? null,
                'tenant_db' => $payload['tenant_db'] ?? ($payload['tenant_database'] ?? null),
            ],
        ];

        $tenantDomain = null;
        $tenantDb = null;

        foreach ($metadataSources as $source) {
            if (!is_array($source)) {
                continue;
            }

            if ($tenantDomain === null && !empty($source['tenant_domain']) && is_string($source['tenant_domain'])) {
                $tenantDomain = trim($source['tenant_domain']);
            }

            $dbCandidate = $source['tenant_db'] ?? ($source['tenant_database'] ?? null);
            if ($tenantDb === null && !empty($dbCandidate) && is_string($dbCandidate)) {
                $tenantDb = trim($dbCandidate);
            }

            if ($tenantDomain !== null && $tenantDb !== null) {
                break;
            }
        }

        return [
            'tenant_domain' => $tenantDomain,
            'tenant_db' => $tenantDb,
        ];
    }
}
