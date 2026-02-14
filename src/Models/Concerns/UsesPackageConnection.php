<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Models\Concerns;

trait UsesPackageConnection
{
    public function getConnectionName(): ?string
    {
        return config('usage-limiter.database_connection') ?? parent::getConnectionName();
    }

    protected function prefixedTable(string $name): string
    {
        return config('usage-limiter.table_prefix', 'ul_').$name;
    }
}
