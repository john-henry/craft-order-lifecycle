<?php

use craft\db\Query;
use craft\helpers\Json;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;

// ---------------------------------------------------------------------------
// log()
// ---------------------------------------------------------------------------

describe('OrderLifecycleLogger::log()', function () {
    it('writes a row to the logs table', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED, [], 'Cart created');

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CART_CREATED->value])
            ->one();

        expect($row)->not->toBeNull()
            ->and($row['message'])->toBe('Cart created');
    });

    it('stores a valid snapshot with order and customer data', function () {
        $order = makeOrder('snapshot@example.com');
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED);

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id])
            ->one();

        $snapshot = Json::decodeIfJson($row['snapshot']);

        expect($snapshot)->toBeArray()
            ->and($snapshot['order']['id'])->toBe($order->id)
            ->and($snapshot['customer']['email'])->toBe('snapshot@example.com')
            ->and($snapshot['lineItems'])->toBeArray();
    });

    it('logs multiple distinct events for the same order', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED, [], 'Created');
        $logger->log($order, EventType::CART_UPDATED, [], 'Updated');

        expect(logExists($order->id, EventType::CART_CREATED))->toBeTrue();
        expect(logExists($order->id, EventType::CART_UPDATED))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// getLogsForOrder()
// ---------------------------------------------------------------------------

describe('OrderLifecycleLogger::getLogsForOrder()', function () {
    it('returns an empty array when no logs exist', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        expect($logger->getLogsForOrder($order->id))->toBeEmpty();
    });

    it('returns only logs belonging to the requested order', function () {
        $orderA = makeOrder('a@example.com');
        $orderB = makeOrder('b@example.com');
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($orderA, EventType::CART_CREATED, [], 'A created');
        $logger->log($orderB, EventType::CART_CREATED, [], 'B created');

        $logsA = $logger->getLogsForOrder($orderA->id);

        expect($logsA)->toHaveCount(1)
            ->and($logsA[0]['orderId'])->toBe($orderA->id);
    });

    it('returns logs newest first', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED, [], 'First');
        $logger->log($order, EventType::CART_UPDATED, [], 'Second');

        $logs = $logger->getLogsForOrder($order->id);

        expect($logs)->toHaveCount(2)
            // Most recently inserted row (highest id) comes first
            ->and((int)$logs[0]['id'])->toBeGreaterThan((int)$logs[1]['id']);
    });
});

// ---------------------------------------------------------------------------
// logCheckoutStarted()
// ---------------------------------------------------------------------------

describe('OrderLifecycleLogger::logCheckoutStarted()', function () {
    it('creates a checkout_started log and returns true', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $result = $logger->logCheckoutStarted($order);

        expect($result)->toBeTrue();
        expect(logExists($order->id, EventType::CHECKOUT_STARTED))->toBeTrue();
    });

    it('does not log a second visit and returns false', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->logCheckoutStarted($order);
        $result = $logger->logCheckoutStarted($order);

        expect($result)->toBeFalse();
        expect($logger->getLogsForOrder($order->id))->toHaveCount(1);
    });

    it('allows checkout logging independently per order', function () {
        $orderA = makeOrder('a@example.com');
        $orderB = makeOrder('b@example.com');
        $logger = OrderLifecycle::$plugin->getLogger();

        expect($logger->logCheckoutStarted($orderA))->toBeTrue();
        expect($logger->logCheckoutStarted($orderB))->toBeTrue();

        expect(logExists($orderA->id, EventType::CHECKOUT_STARTED))->toBeTrue();
        expect(logExists($orderB->id, EventType::CHECKOUT_STARTED))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// getLastLogForOrderAndType()
// ---------------------------------------------------------------------------

describe('OrderLifecycleLogger::getLastLogForOrderAndType()', function () {
    it('returns null when no log of that type exists', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        expect($logger->getLastLogForOrderAndType($order->id, EventType::CART_UPDATED))->toBeNull();
    });

    it('returns the row when exactly one log of that type exists', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED, [], 'Created');

        $row = $logger->getLastLogForOrderAndType($order->id, EventType::CART_CREATED);

        expect($row)->not->toBeNull()
            ->and($row['orderId'])->toBe($order->id)
            ->and($row['type'])->toBe(EventType::CART_CREATED->value);
    });

    it('returns null for a type that was never logged even when other types exist', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED, [], 'Created');

        expect($logger->getLastLogForOrderAndType($order->id, EventType::ORDER_PAID))->toBeNull();
    });

    it('is scoped to the requested order', function () {
        $orderA = makeOrder('a@example.com');
        $orderB = makeOrder('b@example.com');
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($orderA, EventType::CART_CREATED, [], 'A');

        expect($logger->getLastLogForOrderAndType($orderB->id, EventType::CART_CREATED))->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// updateLog()
// ---------------------------------------------------------------------------

describe('OrderLifecycleLogger::updateLog()', function () {
    it('updates the specified fields on a log entry', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED, [], 'Original message');

        $row = (new \craft\db\Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id])
            ->one();

        $logger->updateLog((int)$row['id'], ['message' => 'Updated message']);

        $updated = (new \craft\db\Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['id' => $row['id']])
            ->one();

        expect($updated['message'])->toBe('Updated message');
    });

    it('does not affect other log entries', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED, [], 'First');
        $logger->log($order, EventType::CART_UPDATED, [], 'Second');

        $rows = (new \craft\db\Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $logger->updateLog((int)$rows[0]['id'], ['message' => 'Changed']);

        $unchanged = (new \craft\db\Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['id' => $rows[1]['id']])
            ->one();

        expect($unchanged['message'])->toBe('Second');
    });
});
