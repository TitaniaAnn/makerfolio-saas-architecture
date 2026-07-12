<?php

declare(strict_types=1);

namespace MakerfolioArch;

/**
 * The eight-state custom-domain machine (ARCHITECTURE.md §6, §7).
 *
 * Every actor — the DNS-verification cron, the TLS probe that observes
 * cert issuance, the operator's retry button, the tenant's disable
 * toggle — funnels through transitionTo(). The edge map encodes the
 * rules that matter: nothing reaches ACTIVE except through
 * CERT_PROVISIONING, nothing re-enters the pipeline from FAILED_* or
 * DISABLED except by re-verification, and there is no DISABLED→ACTIVE
 * shortcut.
 */
final class TenantDomain
{
    public const PENDING_DNS       = 'PENDING_DNS';
    public const DNS_VERIFIED      = 'DNS_VERIFIED';
    public const CERT_PROVISIONING = 'CERT_PROVISIONING';
    public const ACTIVE            = 'ACTIVE';
    public const FAILED_DNS        = 'FAILED_DNS';
    public const FAILED_CHALLENGE  = 'FAILED_CHALLENGE';
    public const FAILED_RATE_LIMIT = 'FAILED_RATE_LIMIT';
    public const DISABLED          = 'DISABLED';

    private const TRANSITIONS = [
        self::PENDING_DNS       => [self::DNS_VERIFIED, self::FAILED_DNS, self::DISABLED],
        self::DNS_VERIFIED      => [self::CERT_PROVISIONING, self::FAILED_CHALLENGE, self::FAILED_RATE_LIMIT, self::DISABLED],
        self::CERT_PROVISIONING => [self::ACTIVE, self::FAILED_CHALLENGE, self::FAILED_RATE_LIMIT],
        self::ACTIVE            => [self::DISABLED],
        self::FAILED_DNS        => [self::PENDING_DNS, self::DISABLED],
        self::FAILED_CHALLENGE  => [self::PENDING_DNS, self::DISABLED],
        self::FAILED_RATE_LIMIT => [self::PENDING_DNS, self::DISABLED],
        self::DISABLED          => [self::DNS_VERIFIED, self::PENDING_DNS],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return array the updated tenant_domains row */
    public static function transitionTo(Database $db, int|string $domainId, string $to, string $context): array
    {
        return $db->transaction(function (Database $db) use ($domainId, $to, $context): array {
            $domain = $db->fetchOne('SELECT * FROM tenant_domains WHERE id = ?', [$domainId]);
            if ($domain === null) {
                throw new \RuntimeException("No tenant_domain {$domainId}");
            }
            if (!self::canTransition($domain['status'], $to)) {
                throw new \DomainException(
                    "Invalid domain transition {$domain['status']} -> {$to}"
                );
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $db->query(
                'UPDATE tenant_domains SET status = ?, updated_at = ? WHERE id = ?',
                [$to, $now, $domainId]
            );
            $db->query(
                'INSERT INTO platform_activity (id, action, target_type, target_id, details, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    bin2hex(random_bytes(16)),
                    'tenant_domains.transition',
                    'tenant_domain',
                    (string) $domainId,
                    json_encode(['from' => $domain['status'], 'to' => $to, 'context' => $context]),
                    $now,
                ]
            );

            return $db->fetchOne('SELECT * FROM tenant_domains WHERE id = ?', [$domainId]) ?? [];
        });
    }
}
