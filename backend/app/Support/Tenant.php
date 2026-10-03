<?php

namespace App\Support;

/**
 * Holds the organization (tenant) for the current request or job.
 * Set by the ResolveTenant middleware / API key auth, read by the
 * BelongsToOrganization global scope.
 */
class Tenant
{
    private static ?int $organizationId = null;

    public static function set(?int $organizationId): void
    {
        self::$organizationId = $organizationId;
    }

    public static function id(): ?int
    {
        return self::$organizationId;
    }

    public static function check(): bool
    {
        return self::$organizationId !== null;
    }

    /**
     * Run a callback in the context of the given organization.
     */
    public static function run(int $organizationId, callable $callback): mixed
    {
        $previous = self::$organizationId;
        self::$organizationId = $organizationId;

        try {
            return $callback();
        } finally {
            self::$organizationId = $previous;
        }
    }
}
