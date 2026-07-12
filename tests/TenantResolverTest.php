<?php

declare(strict_types=1);

use MakerfolioArch\Resolution;
use MakerfolioArch\Tenant;
use MakerfolioArch\TenantDomain;
use MakerfolioArch\TenantResolver;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §1 (and docs/03) — the Host-header classification
 * matrix: marketing apex, tenant subdomains with status gating,
 * reserved handles, rename redirects, and custom domains that only
 * resolve while ACTIVE.
 */
final class TenantResolverTest extends TestCase
{
    private TenantResolver $resolver;

    protected function setUp(): void
    {
        $tenants = [
            'annie' => ['id' => 1, 'handle' => 'annie', 'schema_name' => 'tenant_1', 'status' => Tenant::ACTIVE],
            'bob'   => ['id' => 2, 'handle' => 'bob', 'schema_name' => 'tenant_2', 'status' => Tenant::SUSPENDED],
            'carol' => ['id' => 3, 'handle' => 'carol', 'schema_name' => 'tenant_3', 'status' => Tenant::PENDING_VERIFICATION],
            'dave'  => ['id' => 4, 'handle' => 'dave', 'schema_name' => 'tenant_4', 'status' => Tenant::GRACE],
        ];
        $domains = [
            'anniespots.com' => ['status' => TenantDomain::ACTIVE, 'tenant' => $tenants['annie']],
            'bobsbowls.com'  => ['status' => TenantDomain::PENDING_DNS, 'tenant' => $tenants['bob']],
        ];
        $redirects = ['anne' => 'annie'];

        $this->resolver = new TenantResolver(
            'makerfolio.art',
            ['admin', 'api', 'www', 'mail', 'status'],
            fn (string $handle): ?array => $tenants[$handle] ?? null,
            fn (string $hostname): ?array => $domains[$hostname] ?? null,
            fn (string $handle): ?string => $redirects[$handle] ?? null,
        );
    }

    public function test_platform_apex_and_www_are_the_marketing_site(): void
    {
        self::assertSame(Resolution::MARKETING, $this->resolver->resolve('makerfolio.art')->mode);
        self::assertSame(Resolution::MARKETING, $this->resolver->resolve('www.makerfolio.art')->mode);
    }

    public function test_active_subdomain_resolves_to_its_tenant(): void
    {
        $r = $this->resolver->resolve('annie.makerfolio.art');

        self::assertSame(Resolution::TENANT, $r->mode);
        self::assertSame('tenant_1', $r->tenant['schema_name']);
    }

    public function test_host_is_normalized_before_lookup(): void
    {
        $r = $this->resolver->resolve('Annie.Makerfolio.Art:443');

        self::assertSame(Resolution::TENANT, $r->mode, 'port stripped, case folded');
    }

    public function test_status_gates_suspended_grace_and_pending(): void
    {
        self::assertSame(Resolution::SUSPENDED, $this->resolver->resolve('bob.makerfolio.art')->mode);
        self::assertSame(Resolution::PENDING, $this->resolver->resolve('carol.makerfolio.art')->mode);
        self::assertSame(
            Resolution::TENANT,
            $this->resolver->resolve('dave.makerfolio.art')->mode,
            'GRACE still serves — dunning is a banner, not an outage'
        );
    }

    public function test_reserved_and_short_handles_never_match_tenants(): void
    {
        self::assertSame(Resolution::NOT_FOUND, $this->resolver->resolve('admin.makerfolio.art')->mode);
        self::assertSame(
            Resolution::NOT_FOUND,
            $this->resolver->resolve('fr.makerfolio.art')->mode,
            'every 2-letter handle is reserved (country codes)'
        );
        self::assertSame(
            Resolution::NOT_FOUND,
            $this->resolver->resolve('a.b.makerfolio.art')->mode,
            'nested subdomains never match'
        );
    }

    public function test_renamed_handle_redirects_to_the_current_one(): void
    {
        $r = $this->resolver->resolve('anne.makerfolio.art');

        self::assertSame(Resolution::REDIRECT, $r->mode);
        self::assertSame('annie', $r->redirectToHandle);
    }

    public function test_unknown_subdomain_is_not_found(): void
    {
        self::assertSame(Resolution::NOT_FOUND, $this->resolver->resolve('zelda.makerfolio.art')->mode);
    }

    public function test_custom_domain_resolves_only_while_active(): void
    {
        $r = $this->resolver->resolve('anniespots.com');
        self::assertSame(Resolution::TENANT, $r->mode);
        self::assertSame('tenant_1', $r->tenant['schema_name']);

        self::assertSame(
            Resolution::NOT_FOUND,
            $this->resolver->resolve('bobsbowls.com')->mode,
            'a PENDING_DNS domain must not serve traffic'
        );
        self::assertSame(Resolution::NOT_FOUND, $this->resolver->resolve('unknown.example')->mode);
    }
}
