<?php

use johnhenry\orderlifecycle\controllers\AiController;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// AI endpoint rate limit
// ---------------------------------------------------------------------------
// Both insight actions spend Anthropic credit on request, so a per-user rolling
// counter caps how many one user can trigger in a window. The cap is generous
// by design, so these drive the counter directly rather than making 20 real
// calls. Helper is uniquely named: Pest loads every test file into one process.

/**
 * Spends a user's whole allowance for a bucket.
 *
 * The counter is keyed by a fixed window, so a test that seeds only the window
 * it starts in fails whenever the run crosses the boundary between seeding and
 * asking. Both windows are filled, which costs nothing and cannot race.
 */
function olSpendAiAllowance(string $bucket, int $userId): void
{
    $slot = (int)floor(time() / AiController::AI_RATE_WINDOW);

    foreach ([$slot, $slot + 1] as $window) {
        Craft::$app->getCache()->set(
            "order-lifecycle:$bucket-rate:$userId:$window",
            AiController::AI_RATE_LIMIT,
            AiController::AI_RATE_WINDOW * 2,
        );
    }
}

describe('AiController rate limit', function() {
    it('refuses a per-order request once the window is spent', function() {
        $user = UserFactory::factory()->admin(true)->create();
        $this->actingAs($user);

        olSpendAiAllowance('order-insights', (int)$user->id);

        $json = $this->postJson('actions/order-lifecycle/ai/insights', [
            'orderId' => 999999,
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['error'])->toContain('Too many');
    });

    it('refuses a store-wide request once the window is spent', function() {
        $user = UserFactory::factory()->admin(true)->create();
        $this->actingAs($user);

        olSpendAiAllowance('store-insights', (int)$user->id);

        $json = $this->postJson('actions/order-lifecycle/ai/store-insights', [
            'days' => 30,
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['error'])->toContain('Too many');
    });

    // Driven through the trait rather than the endpoint: a call that clears the
    // gate goes on to make a real, paid Anthropic request on any install that
    // has a key configured, and a test suite has no business doing that.
    it('keeps the two actions on separate allowances', function() {
        $user = UserFactory::factory()->admin(true)->create();
        $this->actingAs($user);

        $gate = new class {
            use johnhenry\orderlifecycle\controllers\AiRateLimitTrait;

            public function exceeded(string $bucket): bool
            {
                return $this->aiRateLimitExceeded(
                    $bucket,
                    AiController::AI_RATE_LIMIT,
                    AiController::AI_RATE_WINDOW,
                );
            }
        };

        for ($i = 0; $i < AiController::AI_RATE_LIMIT; $i++) {
            expect($gate->exceeded('order-insights'))->toBeFalse();
        }

        expect($gate->exceeded('order-insights'))->toBeTrue()
            ->and($gate->exceeded('store-insights'))->toBeFalse();
    });
});
