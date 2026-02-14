<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\Entrypoints\ExecutionGateway;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Moneo\UsageLimiter\Tests\TestCase;
use RuntimeException;

class ExecutionGatewayTest extends TestCase
{
    private ExecutionGateway $gateway;

    private UsageLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = app(ExecutionGateway::class);
        $this->limiter = app(UsageLimiter::class);
    }

    public function test_execute_reserves_and_commits_on_success(): void
    {
        $plan = $this->createPlanWithLimits('pro', [
            'api_calls' => ['included_amount' => 1000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        $result = $this->gateway->execute(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
            callback: fn () => 'executed',
        );

        $this->assertEquals('executed', $result);

        $usage = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(50, $usage['committed']);
        $this->assertEquals(0, $usage['reserved']);
    }

    public function test_execute_reserves_and_releases_on_failure(): void
    {
        $plan = $this->createPlanWithLimits('pro', [
            'api_calls' => ['included_amount' => 1000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        try {
            $this->gateway->execute(
                billingAccountId: $account->id,
                metricCode: 'api_calls',
                amount: 50,
                callback: function () {
                    throw new RuntimeException('Execution failed');
                },
            );
        } catch (RuntimeException) {
            // Expected
        }

        $usage = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(0, $usage['committed']);
        $this->assertEquals(0, $usage['reserved']);
    }

    public function test_execute_throws_when_limit_exceeded(): void
    {
        $plan = $this->createPlanWithLimits('basic', [
            'api_calls' => ['included_amount' => 10, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        $this->expectException(UsageLimitExceededException::class);

        $this->gateway->execute(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 11,
            callback: fn () => 'should not run',
        );
    }
}
