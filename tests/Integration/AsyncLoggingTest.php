<?php

use craft\db\Query;
use craft\helpers\Json;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\jobs\LogOrderEventDeferredJob;
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
// log(): async path
// ---------------------------------------------------------------------------

describe('OrderLifecycleLogger::log() async', function () {
    it('does not write to DB immediately when asyncLogging is enabled', function () {
        $order = makeOrder(); // auto-CART_CREATED job queued, not yet written
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CHECKOUT_STARTED, [], 'Checkout');

        // Nothing written yet: both the auto-log and this call are in the queue
        $count = (int)(new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id])
            ->count();

        expect($count)->toBe(0);
    });

    it('builds the snapshot and message synchronously even when asyncLogging is enabled', function () {
        // Regression test for the opposite failure mode: log() briefly
        // deferred the ENTIRE operation, snapshot build and message
        // generation included, whenever asyncLogging was on. That meant a
        // deferred job's change-description message was only as accurate
        // as whatever "the previous snapshot" happened to be by the time
        // the queue got around to running it, which could race against
        // other events queued for the same order in between. Message
        // accuracy is the plugin's main point, so only the DB write itself
        // is deferred: the snapshot is built, and the cache updated,
        // synchronously inside log().
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();
        $logger->clearSnapshotCache((int)$order->id);

        $logger->log($order, EventType::CHECKOUT_STARTED, [], 'Checkout');

        expect(Craft::$app->getCache()->get('ol_snapshot_' . $order->id))->toBeArray();
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
// LogOrderEventJob: direct execution
// ---------------------------------------------------------------------------

describe('LogOrderEventJob', function () {
    it('inserts the pre-built log row as-is when executed', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();
        $logger->clearSnapshotCache((int)$order->id);

        $logger->log($order, EventType::CHECKOUT_STARTED, [], 'Pre-built message');

        Craft::$app->queue->run();

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CHECKOUT_STARTED->value])
            ->one();

        expect($row)->not->toBeNull()
            ->and($row['message'])->toBe('Pre-built message');
    });

    it('inserts the exact snapshot that was cached at dispatch time, not one rebuilt at execution time', function () {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_UPDATED, [], 'Snapshot check');

        // The prepared snapshot is already cached before the queue runs.
        $snapshotAtDispatch = Craft::$app->getCache()->get('ol_snapshot_' . $order->id);
        expect($snapshotAtDispatch)->toBeArray();

        Craft::$app->queue->run();

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CART_UPDATED->value])
            ->one();

        $storedSnapshot = Json::decodeIfJson($row['snapshot']);

        // Loose comparison: JSON round-tripping normalises 0.0 to 0, which
        // doesn't affect what matters here — that no rebuild happened.
        expect($storedSnapshot)->toEqual($snapshotAtDispatch);
    });
});

// ---------------------------------------------------------------------------
// LogOrderEventDeferredJob: direct execution
// ---------------------------------------------------------------------------

describe('LogOrderEventDeferredJob', function () {
    it('re-fetches the order, builds a snapshot and inserts a log row when executed', function () {
        $order = makeOrder();

        $job = new LogOrderEventDeferredJob([
            'orderId' => $order->id,
            'type'    => EventType::CART_CREATED->value,
            'payload' => [],
            'message' => 'Direct job test',
        ]);

        $job->execute(null);

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CART_CREATED->value])
            ->one();

        expect($row)->not->toBeNull()
            ->and($row['message'])->toBe('Direct job test');

        $snapshot = Json::decodeIfJson($row['snapshot']);
        expect($snapshot)->toBeArray()
            ->and($snapshot['order']['id'])->toBe($order->id);
    });

    it('does nothing when the order no longer exists', function () {
        $job = new LogOrderEventDeferredJob([
            'orderId' => 999999999,
            'type'    => EventType::CART_CREATED->value,
            'payload' => [],
            'message' => 'Should never be written',
        ]);

        $job->execute(null);

        $count = (int)(new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['message' => 'Should never be written'])
            ->count();

        expect($count)->toBe(0);
    });
});

