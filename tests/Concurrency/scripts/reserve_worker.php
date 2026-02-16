<?php

declare(strict_types=1);

/**
 * Concurrency worker script for reserve operations.
 *
 * Bootstraps the Laravel testbench app, performs a reserve() call,
 * and outputs JSON result to stdout.
 *
 * Environment variables:
 *   ACCOUNT_ID    - billing account ID
 *   METRIC_CODE   - metric code (default: api_calls)
 *   AMOUNT        - reservation amount (default: 1)
 *   DB_CONNECTION - database connection name
 *   DB_HOST       - database host
 *   DB_PORT       - database port
 *   DB_DATABASE   - database name
 *   DB_USERNAME   - database username
 *   DB_PASSWORD   - database password
 */

require_once __DIR__.'/../../../vendor/autoload.php';

use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\UsageLimiterServiceProvider;

// Bootstrap a minimal Laravel app
$app = (new class extends \Orchestra\Testbench\Foundation\Application
{
    protected function getPackageProviders($app): array
    {
        return [UsageLimiterServiceProvider::class];
    }
})->createApplication();

// Configure DB from env
$dbConnection = getenv('DB_CONNECTION') ?: 'mysql';
$app['config']->set('database.default', $dbConnection);
$app['config']->set("database.connections.{$dbConnection}", [
    'driver' => $dbConnection,
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: ($dbConnection === 'pgsql' ? '5432' : '3306'),
    'database' => getenv('DB_DATABASE') ?: 'testing',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: 'password',
    'charset' => $dbConnection === 'pgsql' ? 'utf8' : 'utf8mb4',
    'collation' => $dbConnection === 'pgsql' ? null : 'utf8mb4_unicode_ci',
    'prefix' => '',
]);

$app['config']->set('usage-limiter.database_connection', $dbConnection);

$accountId = (int) getenv('ACCOUNT_ID');
$metricCode = getenv('METRIC_CODE') ?: 'api_calls';
$amount = (int) (getenv('AMOUNT') ?: 1);
$workerId = getenv('WORKER_ID') ?: '0';

try {
    $limiter = $app->make(UsageLimiter::class);

    $result = $limiter->reserve(new UsageAttempt(
        billingAccountId: $accountId,
        metricCode: $metricCode,
        amount: $amount,
    ));

    echo json_encode([
        'success' => true,
        'ulid' => $result->ulid,
        'worker_id' => $workerId,
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'error_class' => get_class($e),
        'worker_id' => $workerId,
    ]);
}
