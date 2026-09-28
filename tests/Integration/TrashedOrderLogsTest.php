<?php

use craft\db\Query;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;

// ---------------------------------------------------------------------------
// Trashed orders keep their timeline
// ---------------------------------------------------------------------------
// Element::EVENT_AFTER_DELETE fires for a soft delete as well as a hard one,
// and dateDeleted is set on the model in both cases, so hardDelete is the only
// thing that separates them. A trashed order is restorable for the whole
// soft-delete duration and has to come back with its history.

describe('Order lifecycle logs and deletion', function() {
    $logCount = static fn(int $orderId): int => (int)(new Query())
        ->from('{{%orderlifecycle_logs}}')
        ->where(['orderId' => $orderId])
        ->count();

    it('keeps the logs when an order is trashed', function() use ($logCount) {
        $order = makeOrder();
        $orderId = (int)$order->id;
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::ORDER_COMPLETED, ['totalPrice' => 1]);

        expect($logCount($orderId))->toBe(1);

        Craft::$app->getElements()->deleteElement($order);

        expect($logCount($orderId))->toBe(1);
    });

    it('keeps the logs when garbage collection runs over a trashed order', function() use ($logCount) {
        $order = makeOrder();
        $orderId = (int)$order->id;
        $logger = OrderLifecycle::$plugin->getLogger();
        $logger->log($order, EventType::ORDER_COMPLETED, ['totalPrice' => 1]);

        Craft::$app->getElements()->deleteElement($order);
        $logger->deleteOrphanedLogs();

        expect($logCount($orderId))->toBe(1);
    });

    it('deletes the logs when an order is deleted for good', function() use ($logCount) {
        $order = makeOrder();
        $orderId = (int)$order->id;
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::ORDER_COMPLETED, ['totalPrice' => 1]);

        Craft::$app->getElements()->deleteElement($order, true);

        expect($logCount($orderId))->toBe(0);
    });

    // The orderId foreign key cascades, so a row removed outside the element
    // layer takes its logs with it and never reaches the orphan sweep. Pinned
    // here because it is what makes that sweep insurance rather than the
    // mechanism, and because a migration that drops the key would show up as
    // this test failing rather than as logs quietly outliving their orders.
    it('loses the logs with the order row itself, event or no event', function() use ($logCount) {
        $order = makeOrder();
        $orderId = (int)$order->id;
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::ORDER_COMPLETED, ['totalPrice' => 1]);

        Craft::$app->getDb()->createCommand()
            ->delete('{{%commerce_orders}}', ['id' => $orderId])
            ->execute();

        expect($logCount($orderId))->toBe(0);
    });
});
