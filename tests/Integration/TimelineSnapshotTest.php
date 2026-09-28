<?php

use craft\commerce\Plugin as Commerce;
use craft\web\View;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\fields\OrderLifecycleField;
use johnhenry\orderlifecycle\OrderLifecycle;

// ---------------------------------------------------------------------------
// Timeline rows carry their decoded snapshot and matched transaction
// ---------------------------------------------------------------------------
// The order edit screen used to parse each row's snapshot JSON six times over
// and scan the transaction list four times to draw one card. Both are resolved
// once in the service now, so the row shape is what the screen depends on.

/** Saves a real transaction against an order and returns it. */
function olSaveTransaction(craft\commerce\elements\Order $order, string $status = 'success'): craft\commerce\models\Transaction
{
    $gateway = Commerce::getInstance()->getGateways()->getAllGateways()->first();

    // createTransaction() reads the gateway off the order, so set it there
    // rather than only on the transaction, or it warns its way to the same result.
    $order->gatewayId = (int)$gateway->id;
    Craft::$app->getElements()->saveElement($order, false);

    $transactions = Commerce::getInstance()->getTransactions();
    $transaction = $transactions->createTransaction($order, null, 'purchase');
    $transaction->status = $status;
    $transaction->amount = 10;

    $transactions->saveTransaction($transaction);

    return $transaction;
}

describe('OrderLifecycleLogger::getTimelineForOrder() row shape', function() {
    it('carries the decoded snapshot on every row', function() {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_CREATED);

        $row = OrderLifecycle::$plugin->getLogger()->getTimelineForOrder((int)$order->id)[0];

        expect($row)->toHaveKey('snapshotData')
            ->and($row['snapshotData'])->toBeArray()
            ->and($row['snapshotData'])->toHaveKey('order')
            ->and($row['snapshotData']['order']['id'])->toBe((int)$order->id);
    });

    it('carries the transaction id the payload names', function() {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log(
            $order,
            EventType::PAYMENT_PROCESSED,
            ['transactionId' => 4242, 'success' => true],
        );

        $row = OrderLifecycle::$plugin->getLogger()->getTimelineForOrder((int)$order->id)[0];

        expect($row['transactionId'])->toBe(4242);
    });

    it('leaves the transaction id null on an event that names none', function() {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_CREATED);

        expect(OrderLifecycle::$plugin->getLogger()->getTimelineForOrder((int)$order->id)[0]['transactionId'])
            ->toBeNull();
    });

    it('leaves snapshotData null when the snapshot will not decode', function() {
        $order = makeOrder();
        $orderId = (int)$order->id;

        Craft::$app->getDb()->createCommand()->insert('{{%orderlifecycle_logs}}', [
            'orderId' => $orderId,
            'type' => EventType::CART_CREATED->value,
            'message' => 'broken',
            'snapshot' => 'not json at all',
            'dateCreated' => '2026-01-01 00:00:00',
            'dateUpdated' => '2026-01-01 00:00:00',
            'uid' => craft\helpers\StringHelper::UUID(),
        ])->execute();

        $row = OrderLifecycle::$plugin->getLogger()->getTimelineForOrder($orderId)[0];

        expect($row['snapshotData'])->toBeNull()
            ->and($row['transactionId'])->toBeNull();
    });
});

describe('The order field and its transactions', function() {
    it('shows the gateway and status of the transaction a payment event names', function() {
        $order = makeOrder();
        $transaction = olSaveTransaction($order, 'failed');

        OrderLifecycle::$plugin->getLogger()->log(
            $order,
            EventType::PAYMENT_PROCESSED,
            ['transactionId' => (int)$transaction->id, 'success' => false],
        );

        $fresh = Commerce::getInstance()->getOrders()->getOrderById((int)$order->id);
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        $html = (new OrderLifecycleField(['handle' => 'ol', 'name' => 'OL']))->getInputHtml(null, $fresh);

        expect($html)->toContain('Transaction Status')
            ->and($html)->toContain('Gateway')
            ->and($html)->not->toContain('not found.');
    });

    it('says so when the named transaction is not there', function() {
        $order = makeOrder();

        OrderLifecycle::$plugin->getLogger()->log(
            $order,
            EventType::PAYMENT_PROCESSED,
            ['transactionId' => 999999, 'success' => true],
        );

        $fresh = Commerce::getInstance()->getOrders()->getOrderById((int)$order->id);
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        $html = (new OrderLifecycleField(['handle' => 'ol', 'name' => 'OL']))->getInputHtml(null, $fresh);

        expect($html)->toContain('999999')
            ->and($html)->toContain('not found.');
    });
});
