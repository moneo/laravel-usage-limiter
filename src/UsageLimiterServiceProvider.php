<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter;

use Illuminate\Support\ServiceProvider;
use Moneo\UsageLimiter\Commands\CleanupIdempotencyCommand;
use Moneo\UsageLimiter\Commands\ExpireReservationsCommand;
use Moneo\UsageLimiter\Commands\RecalculateOveragesCommand;
use Moneo\UsageLimiter\Commands\ReconcileUsageCommand;
use Moneo\UsageLimiter\Commands\WalletReconcileCommand;
use Moneo\UsageLimiter\Contracts\IdempotencyStore;
use Moneo\UsageLimiter\Contracts\PeriodResolver;
use Moneo\UsageLimiter\Contracts\PlanResolver;
use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\Contracts\WalletRepository;
use Moneo\UsageLimiter\Core\EnforcementEngine;
use Moneo\UsageLimiter\Core\PricingEngine;
use Moneo\UsageLimiter\Core\ReservationManager;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\Entrypoints\EventIngestor;
use Moneo\UsageLimiter\Entrypoints\ExecutionGateway;
use Moneo\UsageLimiter\Enums\EnforcementMode;
use Moneo\UsageLimiter\Enums\PricingMode;
use Moneo\UsageLimiter\Repositories\EloquentIdempotencyStore;
use Moneo\UsageLimiter\Repositories\EloquentUsageRepository;
use Moneo\UsageLimiter\Repositories\EloquentWalletRepository;

class UsageLimiterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/usage-limiter.php', 'usage-limiter');

        $this->registerContracts();
        $this->registerEngines();
        $this->registerCoreServices();
        $this->registerEntrypoints();
    }

    public function boot(): void
    {
        $this->publishConfig();
        $this->loadMigrations();
        $this->registerCommands();
    }

    private function registerContracts(): void
    {
        // Repository contracts
        $this->app->singleton(UsageRepository::class, EloquentUsageRepository::class);
        $this->app->singleton(WalletRepository::class, EloquentWalletRepository::class);
        $this->app->singleton(IdempotencyStore::class, EloquentIdempotencyStore::class);

        // Resolver contracts (configurable)
        $this->app->singleton(PeriodResolver::class, function () {
            $class = config('usage-limiter.period_resolver');

            return $this->app->make($class);
        });

        $this->app->singleton(PlanResolver::class, function () {
            $class = config('usage-limiter.plan_resolver');

            return $this->app->make($class);
        });
    }

    private function registerEngines(): void
    {
        // Enforcement engine with policy registry
        $this->app->singleton(EnforcementEngine::class, function () {
            $engine = new EnforcementEngine;

            $policies = config('usage-limiter.enforcement_policies', []);
            foreach ($policies as $mode => $class) {
                $enforcementMode = EnforcementMode::tryFrom($mode);
                if ($enforcementMode !== null) {
                    $engine->registerPolicy($enforcementMode, $this->app->make($class));
                }
            }

            return $engine;
        });

        // Pricing engine with policy registry
        $this->app->singleton(PricingEngine::class, function () {
            $engine = new PricingEngine;

            $policies = config('usage-limiter.pricing_policies', []);
            foreach ($policies as $mode => $class) {
                $pricingMode = PricingMode::tryFrom($mode);
                if ($pricingMode !== null) {
                    $engine->registerPolicy($pricingMode, $this->app->make($class));
                }
            }

            return $engine;
        });
    }

    private function registerCoreServices(): void
    {
        // ReservationManager
        $this->app->singleton(ReservationManager::class, function () {
            return new ReservationManager(
                $this->app->make(UsageRepository::class),
                $this->app->make(EnforcementEngine::class),
                $this->app->make(PricingEngine::class),
            );
        });

        // UsageLimiter (the main orchestrator)
        $this->app->singleton(UsageLimiter::class, function () {
            return new UsageLimiter(
                $this->app->make(ReservationManager::class),
                $this->app->make(PlanResolver::class),
                $this->app->make(PeriodResolver::class),
                $this->app->make(UsageRepository::class),
                $this->app->make(EnforcementEngine::class),
            );
        });
    }

    private function registerEntrypoints(): void
    {
        $this->app->singleton(ExecutionGateway::class, function () {
            return new ExecutionGateway($this->app->make(UsageLimiter::class));
        });

        $this->app->singleton(EventIngestor::class, function () {
            return new EventIngestor($this->app->make(UsageLimiter::class));
        });
    }

    private function publishConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/usage-limiter.php' => config_path('usage-limiter.php'),
            ], 'usage-limiter-config');
        }
    }

    private function loadMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireReservationsCommand::class,
                ReconcileUsageCommand::class,
                CleanupIdempotencyCommand::class,
                RecalculateOveragesCommand::class,
                WalletReconcileCommand::class,
            ]);
        }
    }
}
