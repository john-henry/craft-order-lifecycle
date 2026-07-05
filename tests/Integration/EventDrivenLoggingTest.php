<?php

use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;

// ---------------------------------------------------------------------------
// Coupon events
// ---------------------------------------------------------------------------

describe('EVENT_AFTER_SAVE: coupon changes', function () {
    it('logs COUPON_APPLIED when a coupon code is first set', function () {
        $order = orderWithSnapshot(); // snapshot: couponCode = null

        $order->couponCode = 'SAVE10';
        reSave($order);

        expect(logCount($order->id, EventType::COUPON_APPLIED))->toBe(1);
    });

    it('logs COUPON_REMOVED when a coupon code is cleared', function () {
        $order = orderWithSnapshot();

        $order->couponCode = 'SAVE10';
        reSave($order); // snapshot now: couponCode = 'SAVE10'

        $order->couponCode = null;
        reSave($order);

        expect(logCount($order->id, EventType::COUPON_REMOVED))->toBe(1);
    });

    it('does not log a coupon event when code is unchanged', function () {
        $order = orderWithSnapshot();

        $order->couponCode = 'SAVE10';
        reSave($order); // COUPON_APPLIED (count = 1)

        reSave($order); // same code, no new event
        reSave($order); // same code, no new event

        expect(logCount($order->id, EventType::COUPON_APPLIED))->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// Customer events
// ---------------------------------------------------------------------------

describe('EVENT_AFTER_SAVE: customer changes', function () {
    it('logs CUSTOMER_SET when the email address changes', function () {
        $order = orderWithSnapshot('before@example.com'); // snapshot: email = before

        $order->email = 'after@example.com';
        reSave($order);

        expect(logCount($order->id, EventType::CUSTOMER_SET))->toBe(1);
    });

    it('logs CUSTOMER_REMOVED when email is cleared', function () {
        $order = orderWithSnapshot('someone@example.com');

        $order->email = '';
        reSave($order);

        expect(logCount($order->id, EventType::CUSTOMER_REMOVED))->toBe(1);
    });

    it('does not log a customer event when email is unchanged', function () {
        $order = orderWithSnapshot('same@example.com');

        reSave($order); // no email change
        reSave($order); // no email change

        expect(logCount($order->id, EventType::CUSTOMER_SET))->toBe(0);
        expect(logCount($order->id, EventType::CUSTOMER_REMOVED))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Shipping method events
// ---------------------------------------------------------------------------

describe('EVENT_AFTER_SAVE: shipping method changes', function () {
    it('logs SHIPPING_METHOD_SET when a handle is assigned', function () {
        $order = orderWithSnapshot(); // snapshot: shippingMethodHandle = null

        $order->shippingMethodHandle = 'freeShipping';
        reSave($order);

        expect(logCount($order->id, EventType::SHIPPING_METHOD_SET))->toBe(1);
    });

    it('logs SHIPPING_METHOD_SET again when handle changes to a different value', function () {
        $order = orderWithSnapshot();

        $order->shippingMethodHandle = 'standard';
        reSave($order); // SHIPPING_METHOD_SET #1

        $order->shippingMethodHandle = 'express';
        reSave($order); // SHIPPING_METHOD_SET #2

        expect(logCount($order->id, EventType::SHIPPING_METHOD_SET))->toBe(2);
    });

    it('does not log SHIPPING_METHOD_SET when handle is unchanged', function () {
        $order = orderWithSnapshot();

        $order->shippingMethodHandle = 'standard';
        reSave($order); // SHIPPING_METHOD_SET (count = 1)

        reSave($order); // unchanged, no new event

        expect(logCount($order->id, EventType::SHIPPING_METHOD_SET))->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// Country changes (generateChangeDescription reads addresses.*.countryCode)
// ---------------------------------------------------------------------------

describe('generateChangeDescription: country changes', function () {
    it('describes a shipping country change from the serialized address snapshot', function () {
        $logger = OrderLifecycle::$plugin->getLogger();

        $previous = ['addresses' => ['shipping' => ['countryCode' => 'IE']]];
        $current = ['addresses' => ['shipping' => ['countryCode' => 'GB']]];

        $message = $logger->generateChangeDescription($previous, $current, EventType::SHIPPING_METHOD_SET);

        expect($message)->toContain('GB');
    });

    it('describes a billing country change from the serialized address snapshot', function () {
        $logger = OrderLifecycle::$plugin->getLogger();

        $previous = ['addresses' => ['billing' => ['countryCode' => 'IE']]];
        $current = ['addresses' => ['billing' => ['countryCode' => 'FR']]];

        $message = $logger->generateChangeDescription($previous, $current, EventType::BILLING_ADDRESS_SET);

        expect($message)->toContain('FR');
    });

    it('returns null when the shipping country is unchanged', function () {
        $logger = OrderLifecycle::$plugin->getLogger();

        $snapshot = ['addresses' => ['shipping' => ['countryCode' => 'IE']]];

        $message = $logger->generateChangeDescription($snapshot, $snapshot, EventType::SHIPPING_METHOD_SET);

        expect($message)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Fallback: CART_UPDATED when no snapshot exists
// ---------------------------------------------------------------------------

describe('EVENT_AFTER_SAVE: no-snapshot fallback', function () {
    it('logs CART_UPDATED when an order has no snapshot on re-save', function () {
        $order = orderWithSnapshot();

        // Remove all logs, destroying the snapshot; also clear the in-memory
        // cache so the logger sees the same "no snapshot" state as the DB.
        Craft::$app->getDb()->createCommand()
            ->delete('{{%orderlifecycle_logs}}', ['orderId' => $order->id])
            ->execute();
        OrderLifecycle::$plugin->getLogger()->clearSnapshotCache($order->id);

        reSave($order);

        expect(logCount($order->id, EventType::CART_UPDATED))->toBe(1);
    });
});
