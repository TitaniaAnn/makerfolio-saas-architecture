<?php

declare(strict_types=1);

use MakerfolioArch\CaddyAsk;
use MakerfolioArch\Tenant;
use MakerfolioArch\TenantDomain;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §7 — the /caddy-ask cert-issuance gate: certs only
 * for DNS-verified domains of live-enough tenants, including the
 * 30-day suspended cutoff that stops renewing lapsed accounts.
 */
final class CaddyAskTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-07-01T12:00:00Z');
    }

    private static function domain(string $status): array
    {
        return ['hostname' => 'anniespots.com', 'status' => $status];
    }

    private static function tenant(string $status, ?string $suspendedAt = null): array
    {
        return ['id' => 1, 'status' => $status, 'suspended_at' => $suspendedAt];
    }

    public function test_unknown_hostname_is_refused(): void
    {
        self::assertFalse(CaddyAsk::allow(null, self::tenant(Tenant::ACTIVE), $this->now));
    }

    public function test_only_dns_verified_pipeline_states_are_issuable(): void
    {
        $tenant = self::tenant(Tenant::ACTIVE);

        foreach ([TenantDomain::DNS_VERIFIED, TenantDomain::CERT_PROVISIONING, TenantDomain::ACTIVE] as $ok) {
            self::assertTrue(CaddyAsk::allow(self::domain($ok), $tenant, $this->now), $ok);
        }
        foreach (
            [TenantDomain::PENDING_DNS, TenantDomain::FAILED_DNS, TenantDomain::FAILED_CHALLENGE,
             TenantDomain::FAILED_RATE_LIMIT, TenantDomain::DISABLED] as $refused
        ) {
            self::assertFalse(
                CaddyAsk::allow(self::domain($refused), $tenant, $this->now),
                "{$refused}: no cert without a completed TXT ownership challenge"
            );
        }
    }

    public function test_active_and_grace_tenants_keep_their_certs(): void
    {
        self::assertTrue(CaddyAsk::allow(self::domain(TenantDomain::ACTIVE), self::tenant(Tenant::ACTIVE), $this->now));
        self::assertTrue(CaddyAsk::allow(self::domain(TenantDomain::ACTIVE), self::tenant(Tenant::GRACE), $this->now));
    }

    public function test_suspended_tenants_keep_certs_for_30_days_then_lose_them(): void
    {
        $domain = self::domain(TenantDomain::ACTIVE);

        $suspendedTenDaysAgo = self::tenant(Tenant::SUSPENDED, '2026-06-21T12:00:00Z');
        self::assertTrue(CaddyAsk::allow($domain, $suspendedTenDaysAgo, $this->now), '10 days: still serving the suspended page');

        $suspendedThirtyOneDaysAgo = self::tenant(Tenant::SUSPENDED, '2026-05-31T12:00:00Z');
        self::assertFalse(CaddyAsk::allow($domain, $suspendedThirtyOneDaysAgo, $this->now), '31 days: stop renewing');
    }

    public function test_deletion_pipeline_and_dead_tenants_are_refused(): void
    {
        $domain = self::domain(TenantDomain::ACTIVE);

        self::assertFalse(CaddyAsk::allow($domain, self::tenant(Tenant::PENDING_DELETION), $this->now));
        self::assertFalse(CaddyAsk::allow($domain, self::tenant(Tenant::DELETED), $this->now));
        self::assertFalse(CaddyAsk::allow($domain, null, $this->now));
    }
}
