<?php

declare(strict_types=1);

namespace MakerfolioArch;

/**
 * Host-header → tenant classification (ARCHITECTURE.md §1; the request
 * pipeline in docs/03-routing-and-tls.md).
 *
 * Pure decision logic: the three lookups (tenant by handle, custom
 * domain by hostname, rename redirect by handle) are injected as
 * closures, so the classification matrix is unit-testable with fixture
 * data. In the product the resolver additionally caches lookups
 * per-worker and calls Database::setSchema() on a TENANT resolution;
 * here the Resolution value is the output and setSchema is the
 * caller's move — the decision is what's on display.
 */
final class TenantResolver
{
    /**
     * @param callable(string): ?array $findTenantByHandle    handle → tenants row
     * @param callable(string): ?array $findDomainByHostname  hostname →
     *        ['status' => ..., 'tenant' => tenants row] or null
     * @param callable(string): ?string $findRedirect         old handle → current handle
     * @param list<string> $reservedHandles
     */
    public function __construct(
        private readonly string $platformDomain,
        private readonly array $reservedHandles,
        private readonly \Closure $findTenantByHandle,
        private readonly \Closure $findDomainByHostname,
        private readonly \Closure $findRedirect,
    ) {
    }

    public function resolve(string $host): Resolution
    {
        $host = strtolower(explode(':', trim($host))[0]);

        // Class 1: the marketing site (apex + www).
        if ($host === $this->platformDomain || $host === 'www.' . $this->platformDomain) {
            return Resolution::marketing();
        }

        // Class 2: tenant on a platform subdomain.
        $suffix = '.' . $this->platformDomain;
        if (str_ends_with($host, $suffix)) {
            return $this->resolveSubdomain(substr($host, 0, -strlen($suffix)));
        }

        // Class 3: tenant on a custom domain — exact hostname, ACTIVE only.
        $domain = ($this->findDomainByHostname)($host);
        if ($domain === null || $domain['status'] !== TenantDomain::ACTIVE) {
            return Resolution::notFound();
        }

        return $this->gateByStatus($domain['tenant']);
    }

    private function resolveSubdomain(string $handle): Resolution
    {
        // Nested subdomains never match tenants; reserved handles
        // (hard-coded list + every 2-letter string) never reach lookup.
        if (
            $handle === ''
            || str_contains($handle, '.')
            || strlen($handle) <= 2
            || in_array($handle, $this->reservedHandles, true)
        ) {
            return Resolution::notFound();
        }

        $tenant = ($this->findTenantByHandle)($handle);
        if ($tenant !== null) {
            return $this->gateByStatus($tenant);
        }

        // Rename support: a 301 to the current handle for a year.
        $target = ($this->findRedirect)($handle);

        return $target !== null ? Resolution::redirect($target) : Resolution::notFound();
    }

    private function gateByStatus(array $tenant): Resolution
    {
        return match ($tenant['status']) {
            Tenant::ACTIVE, Tenant::GRACE => Resolution::tenant($tenant),
            Tenant::SUSPENDED             => Resolution::suspended($tenant),
            Tenant::PENDING_VERIFICATION  => Resolution::pending($tenant),
            default                       => Resolution::notFound(),
        };
    }
}
