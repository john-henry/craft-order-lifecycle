<?php

use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\StringHelper;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\services\OrderLifecycleLogger;

// ---------------------------------------------------------------------------
// What an order save logs, and when it logs nothing
// ---------------------------------------------------------------------------

describe('order save change detection', function() {
    // A line item event logged part way through a save writes a snapshot that
    // already carries the save's other changes. Diffing against that would
    // hide them, so the save compares against the snapshot from before it.
    it('still sees a coupon applied in the same save as a line item change', function() {
        $order = orderWithSnapshot();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->beginOrderSave($order);
        $order->couponCode = 'SAVE10';
        $logger->log($order, EventType::LINE_ITEM_ADDED);
        $logger->logOrderSaveChanges($order, false);

        expect(logCount($order->id, EventType::COUPON_APPLIED))->toBe(1);
    });

    it('logs nothing for a bulk resave', function() {
        $order = orderWithSnapshot();
        $order->couponCode = 'SAVE10';
        $order->resaving = true;

        reSave($order);

        expect(logCount($order->id, EventType::COUPON_APPLIED))->toBe(0);
    });

    it('stops logging changes to a cart that has hit the cap', function() {
        $order = orderWithSnapshot();
        $now = OrderLifecycleLogger::now();

        $rows = [];
        for ($i = 1; $i < OrderLifecycleLogger::MAX_CART_EVENTS; $i++) {
            $rows[] = [$order->id, EventType::CART_UPDATED->value, '', '{}', $now, $now, StringHelper::UUID()];
        }
        Craft::$app->getDb()->createCommand()->batchInsert(
            '{{%orderlifecycle_logs}}',
            ['orderId', 'type', 'message', 'snapshot', 'dateCreated', 'dateUpdated', 'uid'],
            $rows,
        )->execute();

        $order->couponCode = 'SAVE10';
        reSave($order);

        $total = (int)(new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id])
            ->count();

        expect(logCount($order->id, EventType::COUPON_APPLIED))->toBe(0)
            ->and($total)->toBe(OrderLifecycleLogger::MAX_CART_EVENTS);
    });
});

describe('logCheckoutStarted()', function() {
    it('skips a cart that has not been saved yet', function() {
        $order = new Order();

        expect(OrderLifecycle::$plugin->getLogger()->logCheckoutStarted($order))->toBeFalse();
    });
});
