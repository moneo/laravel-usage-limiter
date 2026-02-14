<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Table Name Prefix
    |--------------------------------------------------------------------------
    |
    | All tables created by this package will be prefixed with this string.
    | Change this if you have naming conflicts with existing tables.
    |
    */

    'table_prefix' => 'ul_',

    /*
    |--------------------------------------------------------------------------
    | Period Resolution Strategy
    |--------------------------------------------------------------------------
    |
    | The class responsible for determining billing period boundaries.
    |
    | Built-in options:
    |  - CalendarMonthResolver (default): monthly periods starting on the 1st
    |  - WeeklyPeriodResolver: ISO week boundaries (Monday–Sunday)
    |  - Rolling30DayResolver: 30-day periods anchored to account creation date
    |
    */

    'period_resolver' => \Moneo\UsageLimiter\Policies\Period\CalendarMonthResolver::class,

    /*
    |--------------------------------------------------------------------------
    | Plan Resolution Strategy
    |--------------------------------------------------------------------------
    |
    | The class responsible for resolving a billing account's effective plan
    | with metric limits and overrides merged.
    |
    */

    'plan_resolver' => \Moneo\UsageLimiter\Repositories\EloquentPlanResolver::class,

    /*
    |--------------------------------------------------------------------------
    | Reservation TTL
    |--------------------------------------------------------------------------
    |
    | How long a pending reservation stays valid before auto-expiring (minutes).
    | If a job or process crashes without committing or releasing, the
    | usage:expire-reservations command will clean up after this period.
    |
    */

    'reservation_ttl_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Idempotency Record TTL
    |--------------------------------------------------------------------------
    |
    | How long idempotency records are retained before cleanup (hours).
    | The usage:cleanup-idempotency command purges records older than this.
    |
    */

    'idempotency_ttl_hours' => 48,

    /*
    |--------------------------------------------------------------------------
    | Default Enforcement Mode
    |--------------------------------------------------------------------------
    |
    | The enforcement mode used when not explicitly set on a plan metric limit.
    | Options: 'hard' (deny at limit), 'soft' (allow with warning)
    |
    */

    'default_enforcement_mode' => 'hard',

    /*
    |--------------------------------------------------------------------------
    | Default Pricing Mode
    |--------------------------------------------------------------------------
    |
    | The pricing mode used when not explicitly set on a plan metric limit.
    | Options: 'prepaid', 'postpaid', 'hybrid'
    |
    */

    'default_pricing_mode' => 'postpaid',

    /*
    |--------------------------------------------------------------------------
    | Pricing Policy Registry
    |--------------------------------------------------------------------------
    |
    | Maps pricing mode strings to their implementation classes.
    | Override to provide custom pricing behavior.
    |
    */

    'pricing_policies' => [
        'prepaid' => \Moneo\UsageLimiter\Policies\Pricing\PrepaidPricingPolicy::class,
        'postpaid' => \Moneo\UsageLimiter\Policies\Pricing\PostpaidPricingPolicy::class,
        'hybrid' => \Moneo\UsageLimiter\Policies\Pricing\HybridPricingPolicy::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Enforcement Policy Registry
    |--------------------------------------------------------------------------
    |
    | Maps enforcement mode strings to their implementation classes.
    | Override to provide custom enforcement behavior.
    |
    */

    'enforcement_policies' => [
        'hard' => \Moneo\UsageLimiter\Policies\Enforcement\HardEnforcementPolicy::class,
        'soft' => \Moneo\UsageLimiter\Policies\Enforcement\SoftEnforcementPolicy::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan Cache Settings
    |--------------------------------------------------------------------------
    |
    | Resolved plans are cached to avoid repeated DB queries.
    | Set 'store' to null to use the default cache driver.
    |
    */

    'cache' => [
        'store' => null,
        'ttl_seconds' => 60,
        'prefix' => 'ul_plan:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Limit Warning Threshold
    |--------------------------------------------------------------------------
    |
    | When usage reaches this percentage of the effective limit,
    | a LimitApproaching event is fired.
    |
    */

    'limit_warning_threshold_percent' => 80,

    /*
    |--------------------------------------------------------------------------
    | Redis Fast-Path (Optional)
    |--------------------------------------------------------------------------
    |
    | Reserved for a future release.
    | These keys are currently no-op and kept for forward compatibility.
    |
    */

    'redis' => [
        'enabled' => false,
        'connection' => 'default',
        'flush_interval_seconds' => 5,
        'metrics' => [], // Only these metric_codes use Redis fast-path
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation Settings
    |--------------------------------------------------------------------------
    |
    | Controls the behavior of the usage:reconcile command.
    |
    */

    'reconciliation' => [
        'divergence_threshold_percent' => 1,
        'auto_correct' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection to use for all package tables.
    | Set to null to use the application's default connection.
    |
    */

    'database_connection' => null,

];
