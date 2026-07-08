<?php

use craft\commerce\elements\Order;
use craft\commerce\events\ProcessPaymentEvent;
use craft\commerce\models\Transaction;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\services\Payments;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;
use yii\base\Event;

// ---------------------------------------------------------------------------
// EVENT_AFTER_PROCESS_PAYMENT: payment attempt / processed logging
//
// Regression coverage for offsite and PaymentIntent gateways (Stripe). The
// listener used to treat any non-"success" transaction status as a failure,
// so the intermediate states these gateways return when a payment is created
// but not yet resolved (pending, redirect, processing) were logged as a
// failed paymentAttempt and a paymentProcessed with success=false, which the
// AI insights then counted as a failed payment on a perfectly good order.
// Only a terminal success or failure is a real outcome now.
// ---------------------------------------------------------------------------

/**
 * Fires EVENT_AFTER_PROCESS_PAYMENT for an order with a transaction in the
 * given status, exactly as Commerce's Payments service does after processing.
 */
function fireProcessPayment(int|string $orderId, string $status): void
{
    /** @var \craft\commerce\elements\Order $order */
    $order = Craft::$app->getElements()->getElementById($orderId, \craft\commerce\elements\Order::class);

    $transaction = new Transaction([
        'status' => $status,
        'type' => 'purchase',
        'amount' => 10.00,
        'currency' => 'EUR',
    ]);

    Event::trigger(Payments::class, Payments::EVENT_AFTER_PROCESS_PAYMENT, new ProcessPaymentEvent([
        'order' => $order,
        'transaction' => $transaction,
    ]));
}

describe('EVENT_AFTER_PROCESS_PAYMENT: intermediate statuses', function () {
    it('does not log a payment attempt or processed event for a redirect status', function () {
        $order = orderWithSnapshot();

        fireProcessPayment($order->id, TransactionRecord::STATUS_REDIRECT);

        expect(logCount($order->id, EventType::PAYMENT_ATTEMPT))->toBe(0)
            ->and(logCount($order->id, EventType::PAYMENT_PROCESSED))->toBe(0);
    });

    it('does not log anything for a processing status (Stripe requires_payment_method)', function () {
        $order = orderWithSnapshot();

        fireProcessPayment($order->id, TransactionRecord::STATUS_PROCESSING);

        expect(logCount($order->id, EventType::PAYMENT_ATTEMPT))->toBe(0)
            ->and(logCount($order->id, EventType::PAYMENT_PROCESSED))->toBe(0);
    });

    it('does not log anything for a pending status', function () {
        $order = orderWithSnapshot();

        fireProcessPayment($order->id, TransactionRecord::STATUS_PENDING);

        expect(logCount($order->id, EventType::PAYMENT_ATTEMPT))->toBe(0)
            ->and(logCount($order->id, EventType::PAYMENT_PROCESSED))->toBe(0);
    });
});

describe('payment logging isolation', function () {
    it('never lets a logging failure escape into the payment flow', function () {
        // Commerce fires these listeners inside its payment try/catch, which
        // turns any escaping exception into a reported payment failure after
        // the charge has already gone through. A logging error must therefore
        // be swallowed, not thrown, or a successful payment looks failed and
        // the shopper is invited to pay a second time.
        $method = new ReflectionMethod(OrderLifecycle::class, '_logSafely');

        $escaped = false;
        try {
            $method->invoke(null, static function () {
                throw new RuntimeException('the log database is down');
            });
        } catch (Throwable) {
            $escaped = true;
        }

        expect($escaped)->toBeFalse();
    });

    it('still runs the logging work when it does not fail', function () {
        $method = new ReflectionMethod(OrderLifecycle::class, '_logSafely');

        $ran = false;
        $method->invoke(null, function () use (&$ran) {
            $ran = true;
        });

        expect($ran)->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Order completion listeners
//
// These fire from Order::updateOrderPaidInformation(), which Commerce calls
// inside the same payment try/catch as EVENT_AFTER_PROCESS_PAYMENT, so they
// are routed through the same _logSafely() guard. Firing each one and
// asserting the row is written confirms the guard wraps them without a
// closure-scope (use) mistake: a bad capture would be swallowed by _logSafely
// and surface here as a missing log row rather than an error.
// ---------------------------------------------------------------------------

describe('order completion listeners route through the safe logger', function () {
    it('logs ORDER_PAID when the paid event fires', function () {
        $order = orderWithSnapshot();

        $order->trigger(Order::EVENT_AFTER_ORDER_PAID);

        expect(logCount($order->id, EventType::ORDER_PAID))->toBe(1);
    });

    it('logs ORDER_COMPLETED when the complete event fires', function () {
        $order = orderWithSnapshot();

        $order->trigger(Order::EVENT_AFTER_COMPLETE_ORDER);

        expect(logCount($order->id, EventType::ORDER_COMPLETED))->toBe(1);
    });

    it('logs PAYMENT_AUTHORIZED when the authorized event fires', function () {
        $order = orderWithSnapshot();

        $order->trigger(Order::EVENT_AFTER_ORDER_AUTHORIZED);

        expect(logCount($order->id, EventType::PAYMENT_AUTHORIZED))->toBe(1);
    });
});

describe('EVENT_AFTER_PROCESS_PAYMENT: terminal statuses', function () {
    it('logs a processed event but no attempt for a successful payment', function () {
        $order = orderWithSnapshot();

        fireProcessPayment($order->id, TransactionRecord::STATUS_SUCCESS);

        expect(logCount($order->id, EventType::PAYMENT_ATTEMPT))->toBe(0)
            ->and(logCount($order->id, EventType::PAYMENT_PROCESSED))->toBe(1);
    });

    it('logs both an attempt and a failed processed event for a failed payment', function () {
        $order = orderWithSnapshot();

        fireProcessPayment($order->id, TransactionRecord::STATUS_FAILED);

        expect(logCount($order->id, EventType::PAYMENT_ATTEMPT))->toBe(1)
            ->and(logCount($order->id, EventType::PAYMENT_PROCESSED))->toBe(1);
    });
});
