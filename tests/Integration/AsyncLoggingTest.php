<?php

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Json;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\jobs\LogOrderEventJob;
use johnhenry\orderlifecycle\OrderLifecycle;


beforeEach(function () {
    OrderLifecycle::$plugin->settings->asyncLogging = true;
});

afterEach(function () {
    OrderLifecycle::$plugin->settings->asyncLogging = false;
    // Drain any jobs queued by tests so they don't bleed across
    Craft::$app->queue->run();
});

// ---------------------------------------------------------------------------
// log() — async path
// ---------------------------------------------------------------------------

describe('OrderLifecycleLogger::log() async', function () {
    it('does not write to DB immediately when asyncLogging is enabled', function () {
        $order = makeOrder(); // auto-CART_CREATED job queued, not yet written
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CHECKOUT_STARTED, [], 'Checkout');

        // Nothing written yet — both the auto-log and this call are in the queue
        $count = (int)(new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id])
            ->count();

        expect($count)->toBe(0);
    });

    it('writes to DB after the queue is run', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CHECKOUT_STARTED, [], 'Checkout');

        Craft::$app->queue->run();

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CHECKOUT_STARTED->value])
            ->one();

        expect($row)->not->toBeNull()
            ->and($row['message'])->toBe('Checkout');
    });

    it('preserves the snapshot in the job and writes it correctly', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_UPDATED, [], 'Updated');

        Craft::$app->queue->run();

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CART_UPDATED->value])
            ->one();

        $snapshot = Json::decodeIfJson($row['snapshot']);

        expect($snapshot)->toBeArray()
            ->and($snapshot['order']['id'])->toBe($order->id);
    });

    it('preserves uid and timestamps through the job', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_UPDATED, [], 'Message');

        Craft::$app->queue->run();

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CART_UPDATED->value])
            ->one();

        expect($row['uid'])->not->toBeEmpty()
            ->and($row['dateCreated'])->not->toBeNull()
            ->and($row['dateUpdated'])->not->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// LogOrderEventJob — direct execution
// ---------------------------------------------------------------------------

describe('LogOrderEventJob', function () {
    it('inserts a log row when executed', function () {
        $order = makeOrder();

        $job = new LogOrderEventJob([
            'orderId'     => $order->id,
            'type'        => EventType::CART_CREATED->value,
            'message'     => 'Direct job test',
            'snapshot'    => Json::encode(['order' => ['id' => $order->id]]),
            'userId'      => null,
            'ip'          => null,
            'dateCreated' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'dateUpdated' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'uid'         => Craft::$app->getSecurity()->generateRandomString(),
        ]);

        $job->execute(null);

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CART_CREATED->value])
            ->one();

        expect($row)->not->toBeNull()
            ->and($row['message'])->toBe('Direct job test');
    });
});

