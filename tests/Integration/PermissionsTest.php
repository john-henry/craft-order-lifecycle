<?php

use craft\elements\User;
use johnhenry\orderlifecycle\controllers\AiController;
use johnhenry\orderlifecycle\controllers\ExportController;
use johnhenry\orderlifecycle\widgets\AiInsightsWidget;
use johnhenry\orderlifecycle\widgets\OrderLifecycleStatsWidget;
use markhuot\craftpest\factories\User as UserFactory;
use yii\web\ForbiddenHttpException;

// ---------------------------------------------------------------------------
// Who can see and do what
// ---------------------------------------------------------------------------
// The plugin's screens show every customer's orders, so each one asks for the
// access Commerce itself asks for before showing orders, on top of the
// plugin's own permission.

/**
 * Creates a non-admin user holding exactly the given permissions and logs in as them.
 */
function olActAs(object $test, array $permissions): User
{
    $user = UserFactory::factory()->admin(false)->create();
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
    $test->actingAs($user);

    return $user;
}

describe('permission handles', function() {
    it('registers kebab-case handles', function() {
        $handles = [];
        foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
            $handles = array_merge($handles, array_keys($group['permissions']));
        }

        expect($handles)->toContain(ExportController::PERMISSION_EXPORT_EVENTS)
            ->and($handles)->toContain(AiController::PERMISSION_GENERATE_INSIGHTS)
            ->and(ExportController::PERMISSION_EXPORT_EVENTS)->toBe('order-lifecycle:export-events')
            ->and(AiController::PERMISSION_GENERATE_INSIGHTS)->toBe('order-lifecycle:generate-insights');
    });
});

describe('CSV export', function() {
    it('refuses someone who can export but cannot manage orders', function() {
        olActAs($this, ['accesscp', ExportController::PERMISSION_EXPORT_EVENTS]);

        expect(fn() => $this->post('actions/order-lifecycle/export/csv', ['exportColumns' => ['orderId']]))
            ->toThrow(ForbiddenHttpException::class);
    });

    it('lets someone with both permissions download the file', function() {
        olActAs($this, ['accesscp', ExportController::PERMISSION_EXPORT_EVENTS, 'commerce-manageorders']);

        $this->post('actions/order-lifecycle/export/csv', ['exportColumns' => ['orderId']])
            ->assertOk();
    });
});

describe('per-order AI insights', function() {
    // Checked before anything else about the order is read, so someone who
    // can't open the order learns nothing about it, not even that it exists.
    it('treats an order the user cannot view as not found', function() {
        olActAs($this, ['accesscp', AiController::PERMISSION_GENERATE_INSIGHTS]);
        $order = makeOrder();

        $json = $this->postJson('actions/order-lifecycle/ai/insights', ['orderId' => $order->id])
            ->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['error'])->toBe('Order not found.');
    });
});

describe('dashboard widgets', function() {
    it('offers the stats widget only to users who can see orders', function() {
        olActAs($this, ['accesscp', 'accessplugin-order-lifecycle']);
        expect(OrderLifecycleStatsWidget::isSelectable())->toBeFalse();

        olActAs($this, ['accesscp', 'accessplugin-order-lifecycle', 'commerce-manageorders']);
        expect(OrderLifecycleStatsWidget::isSelectable())->toBeTrue();
    });

    it('offers the AI widget only to users who can generate insights and see orders', function() {
        olActAs($this, ['accesscp', 'commerce-manageorders']);
        expect(AiInsightsWidget::isSelectable())->toBeFalse();

        olActAs($this, ['accesscp', 'commerce-manageorders', AiController::PERMISSION_GENERATE_INSIGHTS]);
        expect(AiInsightsWidget::isSelectable())->toBeTrue();
    });
});
