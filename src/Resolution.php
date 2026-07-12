<?php

declare(strict_types=1);

namespace MakerfolioArch;

/**
 * What TenantResolver decided about a request's Host header.
 *
 *  MARKETING — platform apex/www: public schema only, no tenant.
 *  TENANT    — serve the tenant (ACTIVE or GRACE); caller setSchema()s.
 *  SUSPENDED — render the branded "temporarily unavailable" page.
 *  PENDING   — unverified signup: "finish setup" page, /admin/ allowed.
 *  REDIRECT  — renamed handle: 301 to the current handle's host.
 *  NOT_FOUND — no such tenant/domain: 404.
 */
final class Resolution
{
    public const MARKETING = 'MARKETING';
    public const TENANT    = 'TENANT';
    public const SUSPENDED = 'SUSPENDED';
    public const PENDING   = 'PENDING';
    public const REDIRECT  = 'REDIRECT';
    public const NOT_FOUND = 'NOT_FOUND';

    private function __construct(
        public readonly string $mode,
        public readonly ?array $tenant = null,
        public readonly ?string $redirectToHandle = null,
    ) {
    }

    public static function marketing(): self
    {
        return new self(self::MARKETING);
    }

    public static function tenant(array $tenant): self
    {
        return new self(self::TENANT, $tenant);
    }

    public static function suspended(array $tenant): self
    {
        return new self(self::SUSPENDED, $tenant);
    }

    public static function pending(array $tenant): self
    {
        return new self(self::PENDING, $tenant);
    }

    public static function redirect(string $toHandle): self
    {
        return new self(self::REDIRECT, null, $toHandle);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }
}
