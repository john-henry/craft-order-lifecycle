<?php

/**
 * Unit tests run without a Craft application instance: any test calling
 * Craft::$app or Craft::t() will fail here. For integration tests that need
 * a real Craft + Commerce context (e.g. OrderLifecycleLogger), install
 * markhuot/craft-pest-core in the parent Craft project and run:
 *
 *   vendor/bin/pest plugins/craft-order-lifecycle/tests \
 *     --test-directory=plugins/craft-order-lifecycle/tests
 */

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\StringHelper;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;
use yii\caching\ArrayCache;

// Apply TestCase + RefreshesDatabase to every Integration test.
// RefreshesDatabase wraps each test in a DB transaction that rolls back on
// teardown, preventing test orders and logs from persisting to the database.
uses(
    TestCase::class,
    RefreshesDatabase::class,
)->in('Integration');

// Swap the Craft cache component to a fresh in-memory ArrayCache before each
// integration test. This avoids FileCache filesystem warnings and ensures the
// cache is empty at test start without needing an explicit flush().
uses()->beforeEach(function () {
    Craft::$app->set('cache', new ArrayCache());
    // Force synchronous logging regardless of the saved project config value,
    // so $logger->log() inserts directly within the test transaction.
    OrderLifecycle::$plugin->settings->asyncLogging = false;
    // Clear the logger's in-request snapshot cache so entries from one test
    // don't bleed into the next. We clear rather than replace the component
    // because EVENT_AFTER_SAVE handlers capture the logger instance at
    // registration time and would otherwise still use the old instance.
    OrderLifecycle::$plugin->getLogger()->clearSnapshotCache();
})->in('Integration');

// ---------------------------------------------------------------------------
// Shared integration test helpers
// Defined here so they are available regardless of which test file runs.
// ---------------------------------------------------------------------------

/**
 * Creates a minimal saved Commerce Order and wipes its auto-logged CART_CREATED
 * entry, giving each test a clean log slate.
 *
 * @throws \yii\db\Exception
 */
function makeOrder(string $email = 'test@example.com'): Order
{
    $order = new Order();
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->email = $email;
    $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException(
            'Could not save test order: ' . implode(', ', $order->getErrorSummary(true))
        );
    }

    // The plugin's EVENT_AFTER_SAVE listener auto-logs CART_CREATED on first save.
    // Clear those entries so each test starts with a clean log state.
    Craft::$app->getDb()->createCommand()
        ->delete('{{%orderlifecycle_logs}}', ['orderId' => $order->id])
        ->execute();

    return $order;
}

/**
 * Creates a saved order and keeps the auto-logged CART_CREATED entry.
 * The CART_CREATED log stores the initial snapshot which the EVENT_AFTER_SAVE
 * handler diffs against on every subsequent save to detect changes.
 */
function orderWithSnapshot(string $email = 'test@example.com'): Order
{
    $order = new Order();
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->email = $email;
    $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException(
            'Could not save order: ' . implode(', ', $order->getErrorSummary(true))
        );
    }

    return $order;
}

/**
 * Re-saves an existing order, keeping RECALCULATION_MODE_NONE so Commerce
 * doesn't try to recalculate prices (which would fail without real products).
 */
function reSave(Order $order): void
{
    $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException(
            'Could not re-save order: ' . implode(', ', $order->getErrorSummary(true))
        );
    }
}

function logExists(int $orderId, EventType $type): bool
{
    return (new Query())
        ->from('{{%orderlifecycle_logs}}')
        ->where(['orderId' => $orderId, 'type' => $type->value])
        ->exists();
}

function logCount(int $orderId, EventType $type): int
{
    return (int)(new Query())
        ->from('{{%orderlifecycle_logs}}')
        ->where(['orderId' => $orderId, 'type' => $type->value])
        ->count();
}

/**
 * Insert a lifecycle log row directly, bypassing the logger service.
 * Useful for backdating logs into a previous stats period to test trend calculation.
 *
 * @param string $at Any strtotime-compatible string, e.g. '-40 days', 'now'
 * @throws \yii\db\Exception
 * @throws Exception
 */
function insertBackdatedLog(int $orderId, EventType $type, string $at = 'now'): void
{
    $date = date('Y-m-d H:i:s', strtotime($at));
    // stamp the current store so these rows survive the store-scoped stats
    // queries, matching what the logger writes for a real order save
    $storeId = Commerce::getInstance()->getStores()->getCurrentStore()->id;
    Craft::$app->getDb()->createCommand()->insert('{{%orderlifecycle_logs}}', [
        'orderId'     => $orderId,
        'storeId'     => $storeId,
        'type'        => $type->value,
        'message'     => '',
        'snapshot'    => '{}',
        'dateCreated' => $date,
        'dateUpdated' => $date,
        'uid'         => StringHelper::UUID(),
    ])->execute();
}
