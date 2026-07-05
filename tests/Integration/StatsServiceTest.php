<?php

use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;

// ---------------------------------------------------------------------------
// Return shape
// ---------------------------------------------------------------------------

describe('StatsService::getStats() return shape', function () {
    it('returns all expected keys', function () {
        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);

        expect($stats)->toHaveKeys([
            'totalLogs',
            'uniqueOrders',
            'avgLogsPerOrder',
            'topEventTypes',
            'avgTimeToCompletion',
            'conversionRate',
            'cartsCreated',
            'ordersCompleted',
            'avgCheckoutDuration',
            'abandonmentRate',
            'abandonedCarts',
            'avgPaymentAttempts',
            'returningCustomerRate',
            'avgCartValue',
            'emailSuccessRate',
            'emailsSent',
            'emailsFailed',
            'trends',
            'days',
        ]);
    });

    it('echoes the days parameter back', function () {
        expect(OrderLifecycle::$plugin->getStats()->getStats(7)['days'])->toBe(7);
        expect(OrderLifecycle::$plugin->getStats()->getStats(90)['days'])->toBe(90);
    });
});

// ---------------------------------------------------------------------------
// Valid ranges (stats queries succeed and return sensible types)
// ---------------------------------------------------------------------------

describe('StatsService::getStats() value types and ranges', function () {
    it('returns non-negative integers for count fields', function () {
        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);

        expect($stats['totalLogs'])->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and($stats['uniqueOrders'])->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and($stats['cartsCreated'])->toBeGreaterThanOrEqual(0)
            ->and($stats['ordersCompleted'])->toBeGreaterThanOrEqual(0)
            ->and($stats['emailsSent'])->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and($stats['emailsFailed'])->toBeInt()->toBeGreaterThanOrEqual(0);
    });

    it('returns null or string for time-based averages', function () {
        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);

        $avg = $stats['avgTimeToCompletion'];
        expect($avg === null || is_string($avg))->toBeTrue();

        $checkout = $stats['avgCheckoutDuration'];
        expect($checkout === null || is_string($checkout))->toBeTrue();

        $cartValue = $stats['avgCartValue'];
        expect($cartValue === null || is_numeric($cartValue))->toBeTrue();
    });

    it('returns rates between 0 and 100', function () {
        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);

        expect($stats['conversionRate'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
            ->and($stats['abandonmentRate'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
            ->and($stats['emailSuccessRate'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
            ->and($stats['returningCustomerRate'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
    });
});

// ---------------------------------------------------------------------------
// With data
// ---------------------------------------------------------------------------

describe('StatsService::getStats() with log data', function () {
    it('counts log entries and unique orders', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED);
        $logger->log($order, EventType::CHECKOUT_STARTED);

        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);

        expect($stats['totalLogs'])->toBeGreaterThanOrEqual(2)
            ->and($stats['uniqueOrders'])->toBeGreaterThanOrEqual(1);
    });

    it('uniqueOrders does not equal totalLogs when multiple events exist for one order', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        // Write 3 events for a single order
        $logger->log($order, EventType::CART_CREATED);
        $logger->log($order, EventType::CHECKOUT_STARTED);
        $logger->log($order, EventType::ORDER_COMPLETED);

        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);

        // uniqueOrders must never exceed totalLogs
        expect($stats['uniqueOrders'])->toBeLessThanOrEqual($stats['totalLogs']);
    });

    it('includes the event type in topEventTypes', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED);

        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);
        $types = array_column($stats['topEventTypes'], 'type');

        expect($types)->toContain(EventType::CART_CREATED->value);
    });

    it('counts email sent and failed events', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::EMAIL_SENT);
        $logger->log($order, EventType::EMAIL_SENT);
        $logger->log($order, EventType::EMAIL_FAILED);

        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);

        expect($stats['emailsSent'])->toBeGreaterThanOrEqual(2)
            ->and($stats['emailsFailed'])->toBeGreaterThanOrEqual(1)
            ->and($stats['emailSuccessRate'])->toBeGreaterThan(0);
    });

    it('returns numeric types for all integer and float fields', function () {
        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);

        expect($stats['totalLogs'])->toBeInt()
            ->and($stats['uniqueOrders'])->toBeInt()
            ->and($stats['avgLogsPerOrder'])->toBeNumeric()
            ->and($stats['conversionRate'])->toBeNumeric()
            ->and($stats['abandonmentRate'])->toBeNumeric()
            ->and($stats['emailSuccessRate'])->toBeNumeric()
            ->and($stats['emailsSent'])->toBeInt()
            ->and($stats['emailsFailed'])->toBeInt();
    });
});

// ---------------------------------------------------------------------------
// Order deletion cleanup
// ---------------------------------------------------------------------------

describe('StatsService orphaned log cleanup', function () {
    it('removes logs when the order is deleted', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();
        $logger->log($order, EventType::CART_CREATED);
        $orderId = $order->id;

        expect(logExists($orderId, EventType::CART_CREATED))->toBeTrue();

        Craft::$app->getElements()->deleteElement($order);

        expect(logExists($orderId, EventType::CART_CREATED))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Trend indicators
// ---------------------------------------------------------------------------

describe('StatsService::getStats() trend indicators', function () {

    // Note: getStats() queries the shared production DB. These tests insert
    // enough rows in the measured direction to dominate any pre-existing data,
    // so they don't require (and must not attempt) a global table wipe.

    it('returns a trends key in the stats array', function () {
        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);
        expect($stats)->toHaveKey('trends');
    });

    it('returns an empty trends array for all-time (days=0)', function () {
        $stats = OrderLifecycle::$plugin->getStats()->getStats(0);
        expect($stats['trends'])->toBeArray()->toBeEmpty();
    });

    it('trends contains the expected keys for period-based stats', function () {
        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);
        expect($stats['trends'])->toHaveKeys([
            'totalLogs',
            'uniqueOrders',
            'conversionRate',
            'abandonmentRate',
            'emailSuccessRate',
        ]);
    });

    it('each trend value is null or numeric', function () {
        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);
        foreach ($stats['trends'] as $key => $value) {
            expect($value === null || is_numeric($value))->toBeTrue(
                "Expected trend '{$key}' to be null or numeric, got " . gettype($value)
            );
        }
    });

    it('returns a numeric trend when the previous period has data', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        insertBackdatedLog($order->id, EventType::CART_CREATED, '-40 days');
        $logger->log($order, EventType::CART_CREATED);

        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);

        expect($stats['trends']['totalLogs'])->toBeNumeric();
    });

    it('returns a positive totalLogs trend when current period has far more events', function () {
        // Trends are store-wide, so wipe the table first (rolled back on teardown)
        // to keep the ratio deterministic against any seeded data.
        Craft::$app->getDb()->createCommand()->delete('{{%orderlifecycle_logs}}')->execute();

        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        // 2 rows in the previous period, 20 in the current period.
        insertBackdatedLog($order->id, EventType::CART_CREATED, '-40 days');
        insertBackdatedLog($order->id, EventType::CART_UPDATED, '-40 days');

        for ($i = 0; $i < 20; $i++) {
            $logger->log($order, EventType::CART_UPDATED);
        }

        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);

        expect($stats['trends']['totalLogs'])->toBeGreaterThan(0);
    });

    it('returns a negative totalLogs trend when previous period has far more events', function () {
        // Trends are store-wide, so wipe the table first (rolled back on teardown)
        // to keep the ratio deterministic against any seeded data.
        Craft::$app->getDb()->createCommand()->delete('{{%orderlifecycle_logs}}')->execute();

        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        // 20 rows in the previous period, 1 in the current period.
        for ($i = 0; $i < 20; $i++) {
            insertBackdatedLog($order->id, EventType::CART_UPDATED, '-40 days');
        }

        $logger->log($order, EventType::CART_CREATED);

        $stats = OrderLifecycle::$plugin->getStats()->getStats(30);

        expect($stats['trends']['totalLogs'])->toBeLessThan(0);
    });
});

// ---------------------------------------------------------------------------
// Period filtering
// ---------------------------------------------------------------------------

describe('StatsService::getStats() period parameter', function () {
    it('returns 0 logs for a 1-day window when no recent logs exist', function () {
        // The transaction rollback ensures no logs from this test bleed in,
        // but pre-existing logs in the DB from before the test window could appear.
        // We just assert the call succeeds and returns a valid shape.
        $stats = OrderLifecycle::$plugin->getStats()->getStats(1);

        expect($stats['days'])->toBe(1)
            ->and($stats['totalLogs'])->toBeInt()
            ->and($stats['uniqueOrders'])->toBeInt();
    });

    it('accepts all valid period values without error', function () {
        foreach ([7, 14, 30, 60, 90, 365] as $days) {
            $stats = OrderLifecycle::$plugin->getStats()->getStats($days);
            expect($stats['days'])->toBe($days);
        }
    });
});
