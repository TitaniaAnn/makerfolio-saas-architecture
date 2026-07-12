<?php

declare(strict_types=1);

use MakerfolioArch\Database;
use MakerfolioArch\Tenant;
use MakerfolioArch\TenantDomain;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §6 — state changes go through transitionTo(): edges
 * are validated against the machine, every transition writes an audit
 * row, and invalid jumps throw instead of silently updating.
 */
final class StateMachineTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = toy_platform_db();
        $this->db->query(
            'INSERT INTO tenants (id, handle, schema_name, status) VALUES (?, ?, ?, ?)',
            [1, 'annie', 'tenant_1', Tenant::PENDING_VERIFICATION]
        );
        $this->db->query(
            'INSERT INTO tenant_domains (id, tenant_id, hostname, status) VALUES (?, ?, ?, ?)',
            [10, 1, 'anniespots.com', TenantDomain::PENDING_DNS]
        );
    }

    public function test_tenant_lifecycle_happy_path_and_audit_trail(): void
    {
        $t = Tenant::transitionTo($this->db, 1, Tenant::ACTIVE, 'email verified');
        self::assertSame(Tenant::ACTIVE, $t['status']);

        $t = Tenant::transitionTo($this->db, 1, Tenant::GRACE, 'invoice.payment_failed');
        $t = Tenant::transitionTo($this->db, 1, Tenant::ACTIVE, 'charge succeeded');
        self::assertSame(Tenant::ACTIVE, $t['status']);
        self::assertNull($t['suspended_at']);

        $audit = array_map(
            static fn (array $row): array => json_decode($row['details'], true),
            $this->db->fetchAll(
                "SELECT details FROM platform_activity WHERE action = 'tenants.transition'"
            )
        );
        self::assertCount(3, $audit, 'every transition writes exactly one audit row');
        self::assertContains(
            ['from' => Tenant::GRACE, 'to' => Tenant::ACTIVE, 'reason' => 'charge succeeded'],
            $audit
        );
    }

    public function test_suspension_stamps_suspended_at_and_restore_clears_it(): void
    {
        Tenant::transitionTo($this->db, 1, Tenant::ACTIVE, 'email verified');
        $t = Tenant::transitionTo($this->db, 1, Tenant::SUSPENDED, 'TOS_VIOLATION');
        self::assertNotNull($t['suspended_at'], '/caddy-ask reads this for the 30-day cert cutoff');

        $t = Tenant::transitionTo($this->db, 1, Tenant::ACTIVE, 'operator restore');
        self::assertNull($t['suspended_at']);
    }

    public function test_invalid_tenant_edges_throw_and_do_not_update(): void
    {
        // ACTIVE cannot jump straight to DELETED — deletion goes through
        // PENDING_DELETION so the 30-day recovery window always exists.
        Tenant::transitionTo($this->db, 1, Tenant::ACTIVE, 'email verified');

        try {
            Tenant::transitionTo($this->db, 1, Tenant::DELETED, 'nope');
            self::fail('expected DomainException');
        } catch (DomainException) {
        }

        $row = $this->db->fetchOne('SELECT status FROM tenants WHERE id = ?', [1]);
        self::assertSame(Tenant::ACTIVE, $row['status'], 'a rejected transition must not write');
    }

    public function test_deleted_is_terminal(): void
    {
        foreach (
            [Tenant::ACTIVE, Tenant::PENDING_DELETION, Tenant::DELETED] as $next
        ) {
            Tenant::transitionTo($this->db, 1, $next, 'step');
        }

        $this->expectException(DomainException::class);
        Tenant::transitionTo($this->db, 1, Tenant::ACTIVE, 'resurrect');
    }

    public function test_domain_machine_walks_the_provisioning_pipeline(): void
    {
        foreach (
            [
                TenantDomain::DNS_VERIFIED      => 'CNAME + TXT verified',
                TenantDomain::CERT_PROVISIONING => 'caddy asked',
                TenantDomain::ACTIVE            => 'TLS probe saw the cert',
            ] as $to => $context
        ) {
            $d = TenantDomain::transitionTo($this->db, 10, $to, $context);
        }
        self::assertSame(TenantDomain::ACTIVE, $d['status']);
        self::assertNotNull($d['updated_at']);
    }

    public function test_domain_machine_has_no_disabled_to_active_shortcut(): void
    {
        TenantDomain::transitionTo($this->db, 10, TenantDomain::DNS_VERIFIED, 'verified');
        TenantDomain::transitionTo($this->db, 10, TenantDomain::DISABLED, 'tenant disabled');

        $this->expectException(DomainException::class);
        // Re-enable must go back through DNS_VERIFIED / PENDING_DNS.
        TenantDomain::transitionTo($this->db, 10, TenantDomain::ACTIVE, 'shortcut');
    }

    public function test_failed_domain_reenters_via_pending_dns(): void
    {
        TenantDomain::transitionTo($this->db, 10, TenantDomain::FAILED_DNS, '7 days of failed checks');
        $d = TenantDomain::transitionTo($this->db, 10, TenantDomain::PENDING_DNS, 're-verify with fresh token');

        self::assertSame(TenantDomain::PENDING_DNS, $d['status']);
    }
}
