<?php

use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\StringHelper;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;

/**
 * Regression coverage for the 1.0.1 multi-store scoping work: the logger stamps
 * each row with its order's store, and store-wide stats only count the current
 * store's rows.
 */

// ---------------------------------------------------------------------------
// Write path
// ---------------------------------------------------------------------------

describe('OrderLifecycleLogger store stamping', function () {
    it('stamps each logged event with the order\'s store id', function () {
        $order = makeOrder();
        $storeId = Commerce::getInstance()->getStores()->getCurrentStore()->id;

        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_UPDATED);

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CART_UPDATED->value])
            ->one();

        expect((int)$row['storeId'])->toBe((int)$storeId);
    });
});

// ---------------------------------------------------------------------------
// Read path
// ---------------------------------------------------------------------------

describe('StatsService store scoping', function () {
    it('only counts logs stamped with the current store', function () {
        Craft::$app->getDb()->createCommand()->delete('{{%orderlifecycle_logs}}')->execute();

        $order = makeOrder();
        $storeId = Commerce::getInstance()->getStores()->getCurrentStore()->id;

        // one row belonging to the current store, one belonging to no store
        // (an orphan-style row that must not bleed into this store's totals)
        insertLogForStore($order->id, $storeId);
        insertLogForStore($order->id, null);

        $stats = OrderLifecycle::$plugin->getStats()->getStats(0);

        expect($stats['totalLogs'])->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// Abandonment threshold
// ---------------------------------------------------------------------------

describe('StatsService abandonment threshold', function () {
    it('counts a tracked cart with items and no activity for over an hour as abandoned', function () {
        $baseline = OrderLifecycle::$plugin->getStats()->getStats(0)['abandonedCarts'];

        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();
        $logger->log($order, EventType::CART_CREATED);
        $logger->log($order, EventType::LINE_ITEM_ADDED, ['sku' => 'TEST-1', 'qty' => 1]);

        // backdate the cart's last activity to two hours ago
        Craft::$app->getDb()->createCommand()->update(
            '{{%commerce_orders}}',
            ['dateUpdated' => date('Y-m-d H:i:s', strtotime('-2 hours'))],
            ['id' => $order->id]
        )->execute();

        Craft::$app->getCache()->flush();
        $after = OrderLifecycle::$plugin->getStats()->getStats(0)['abandonedCarts'];

        expect($after)->toBe($baseline + 1);
    });

    it('does not count a cart touched within the last hour as abandoned', function () {
        $baseline = OrderLifecycle::$plugin->getStats()->getStats(0)['abandonedCarts'];

        // a fresh cart with items (dateUpdated = now) is in progress, not abandoned
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();
        $logger->log($order, EventType::CART_CREATED);
        $logger->log($order, EventType::LINE_ITEM_ADDED, ['sku' => 'TEST-2', 'qty' => 1]);

        Craft::$app->getCache()->flush();
        $after = OrderLifecycle::$plugin->getStats()->getStats(0)['abandonedCarts'];

        expect($after)->toBe($baseline);
    });

    it('does not count an idle cart the plugin never tracked as created', function () {
        $baseline = OrderLifecycle::$plugin->getStats()->getStats(0)['abandonedCarts'];

        // an incomplete cart with no cartCreated log (an empty session cart, or
        // one created before the plugin was installed) - idle for two hours but
        // never a tracked "cart created", so it isn't counted as abandoned
        $order = makeOrder(); // makeOrder wipes the auto-logged cartCreated entry
        Craft::$app->getDb()->createCommand()->update(
            '{{%commerce_orders}}',
            ['dateUpdated' => date('Y-m-d H:i:s', strtotime('-2 hours'))],
            ['id' => $order->id]
        )->execute();

        Craft::$app->getCache()->flush();
        $after = OrderLifecycle::$plugin->getStats()->getStats(0)['abandonedCarts'];

        expect($after)->toBe($baseline);
    });

    it('does not count an idle tracked cart that never had a line item added', function () {
        $baseline = OrderLifecycle::$plugin->getStats()->getStats(0)['abandonedCarts'];

        // a tracked but empty cart: created and idle for two hours, but nothing
        // was ever added to it, so it isn't an abandoned purchase
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_CREATED);
        Craft::$app->getDb()->createCommand()->update(
            '{{%commerce_orders}}',
            ['dateUpdated' => date('Y-m-d H:i:s', strtotime('-2 hours'))],
            ['id' => $order->id]
        )->execute();

        Craft::$app->getCache()->flush();
        $after = OrderLifecycle::$plugin->getStats()->getStats(0)['abandonedCarts'];

        expect($after)->toBe($baseline);
    });
});

// ---------------------------------------------------------------------------
// Conversion denominator
// ---------------------------------------------------------------------------

describe('StatsService conversion', function () {
    it('excludes empty carts from the carts-created count', function () {
        $baseline = OrderLifecycle::$plugin->getStats()->getStats(0)['cartsCreated'];

        $logger = OrderLifecycle::$plugin->getLogger();

        // a real cart (item added) and an empty cart (created, nothing added)
        $realCart = makeOrder();
        $logger->log($realCart, EventType::CART_CREATED);
        $logger->log($realCart, EventType::LINE_ITEM_ADDED, ['sku' => 'REAL-1', 'qty' => 1]);

        $emptyCart = makeOrder();
        $logger->log($emptyCart, EventType::CART_CREATED);

        Craft::$app->getCache()->flush();
        $after = OrderLifecycle::$plugin->getStats()->getStats(0)['cartsCreated'];

        // only the real cart joins the count; the empty cart is ignored
        expect($after)->toBe($baseline + 1);
    });
});

/**
 * Inserts a single log row for a given order and store, bypassing the logger.
 *
 * @throws \yii\db\Exception
 */
function insertLogForStore(int $orderId, ?int $storeId): void
{
    Craft::$app->getDb()->createCommand()->insert('{{%orderlifecycle_logs}}', [
        'orderId'     => $orderId,
        'storeId'     => $storeId,
        'type'        => EventType::CART_UPDATED->value,
        'message'     => '',
        'snapshot'    => '{}',
        'dateCreated' => date('Y-m-d H:i:s'),
        'dateUpdated' => date('Y-m-d H:i:s'),
        'uid'         => StringHelper::UUID(),
    ])->execute();
}
