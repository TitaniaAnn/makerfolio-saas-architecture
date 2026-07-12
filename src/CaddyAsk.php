<?php

declare(strict_types=1);

namespace MakerfolioArch;

/**
 * The /caddy-ask cert-issuance gate as a pure policy function
 * (ARCHITECTURE.md §7).
 *
 * Caddy's on-demand TLS asks this question before issuing (or renewing)
 * a certificate for any SNI hostname. Two conditions, both required:
 *
 *  - the DOMAIN must have completed DNS verification
 *    (DNS_VERIFIED / CERT_PROVISIONING / ACTIVE) — the only path into
 *    those states is the TXT ownership challenge, which is what stops
 *    an attacker from burning Let's Encrypt rate limits by pointing
 *    hostile domains at the platform's IP;
 *
 *  - the owning TENANT must be alive — ACTIVE, GRACE, or within the
 *    first 30 days of SUSPENDED. After that (and for
 *    PENDING_DELETION / DELETED) the gate answers no, Caddy stops
 *    renewing, and lapsed accounts stop consuming rate-limit headroom.
 */
final class CaddyAsk
{
    private const ISSUABLE_DOMAIN_STATUSES = [
        TenantDomain::DNS_VERIFIED,
        TenantDomain::CERT_PROVISIONING,
        TenantDomain::ACTIVE,
    ];

    public const SUSPENDED_GRACE_DAYS = 30;

    /**
     * @param ?array $domain tenant_domains row (null = unknown hostname)
     * @param ?array $tenant owning tenants row
     */
    public static function allow(?array $domain, ?array $tenant, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($domain === null || !in_array($domain['status'], self::ISSUABLE_DOMAIN_STATUSES, true)) {
            return false;
        }
        if ($tenant === null) {
            return false;
        }

        return match ($tenant['status']) {
            Tenant::ACTIVE, Tenant::GRACE => true,
            Tenant::SUSPENDED             => self::withinSuspendedGrace($tenant, $now),
            default                       => false,
        };
    }

    private static function withinSuspendedGrace(array $tenant, \DateTimeImmutable $now): bool
    {
        if (empty($tenant['suspended_at'])) {
            return false;
        }
        $cutoff = (new \DateTimeImmutable($tenant['suspended_at']))
            ->modify('+' . self::SUSPENDED_GRACE_DAYS . ' days');

        return $now < $cutoff;
    }
}
