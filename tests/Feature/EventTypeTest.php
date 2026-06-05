<?php

use johnhenry\orderlifecycle\enums\EventType;

describe('EventType string values', function () {
    it('has the correct backing value for key cases', function () {
        expect(EventType::CART_CREATED->value)->toBe('cartCreated');
        expect(EventType::ORDER_COMPLETED->value)->toBe('orderCompleted');
        expect(EventType::CHECKOUT_STARTED->value)->toBe('checkoutStarted');
        expect(EventType::PAYMENT_REFUNDED->value)->toBe('paymentRefunded');
        expect(EventType::EMAIL_SENT->value)->toBe('emailSent');
    });
});

describe('EventType::getCategory()', function () {
    it('categorises cart events', function () {
        expect(EventType::CART_CREATED->getCategory())->toBe('cart');
        expect(EventType::CART_UPDATED->getCategory())->toBe('cart');
    });

    it('categorises line item events', function () {
        expect(EventType::LINE_ITEM_ADDED->getCategory())->toBe('lineItems');
        expect(EventType::LINE_ITEM_REMOVED->getCategory())->toBe('lineItems');
        expect(EventType::LINE_ITEM_UPDATED->getCategory())->toBe('lineItems');
    });

    it('categorises coupon events', function () {
        expect(EventType::COUPON_APPLIED->getCategory())->toBe('coupons');
        expect(EventType::COUPON_REMOVED->getCategory())->toBe('coupons');
    });

    it('categorises address events', function () {
        expect(EventType::SHIPPING_ADDRESS_SET->getCategory())->toBe('addresses');
        expect(EventType::BILLING_ADDRESS_REMOVED->getCategory())->toBe('addresses');
    });

    it('categorises customer events', function () {
        expect(EventType::CUSTOMER_SET->getCategory())->toBe('customer');
        expect(EventType::CUSTOMER_REMOVED->getCategory())->toBe('customer');
    });

    it('categorises shipping events', function () {
        expect(EventType::SHIPPING_METHOD_SET->getCategory())->toBe('shipping');
    });

    it('categorises order status events', function () {
        expect(EventType::STATUS_CHANGED->getCategory())->toBe('order');
        expect(EventType::ORDER_COMPLETED->getCategory())->toBe('order');
        expect(EventType::ORDER_PAID->getCategory())->toBe('order');
    });

    it('categorises payment events', function () {
        expect(EventType::PAYMENT_ATTEMPT->getCategory())->toBe('payment');
        expect(EventType::PAYMENT_PROCESSED->getCategory())->toBe('payment');
        expect(EventType::PAYMENT_AUTHORIZED->getCategory())->toBe('payment');
        expect(EventType::PAYMENT_CAPTURED->getCategory())->toBe('payment');
        expect(EventType::PAYMENT_REFUNDED->getCategory())->toBe('payment');
        expect(EventType::PAYMENT_TRANSACTION->getCategory())->toBe('payment');
    });

    it('categorises email events', function () {
        expect(EventType::EMAIL_SENT->getCategory())->toBe('email');
        expect(EventType::EMAIL_FAILED->getCategory())->toBe('email');
    });

    it('categorises checkout events', function () {
        expect(EventType::CHECKOUT_STARTED->getCategory())->toBe('checkout');
    });
});

describe('EventType boolean helpers', function () {
    it('isCartEvent() returns true only for cart events', function () {
        expect(EventType::CART_CREATED->isCartEvent())->toBeTrue();
        expect(EventType::CART_UPDATED->isCartEvent())->toBeTrue();
        expect(EventType::ORDER_COMPLETED->isCartEvent())->toBeFalse();
        expect(EventType::PAYMENT_PROCESSED->isCartEvent())->toBeFalse();
    });

    it('isPaymentEvent() returns true only for payment events', function () {
        expect(EventType::PAYMENT_PROCESSED->isPaymentEvent())->toBeTrue();
        expect(EventType::PAYMENT_REFUNDED->isPaymentEvent())->toBeTrue();
        expect(EventType::CART_CREATED->isPaymentEvent())->toBeFalse();
        expect(EventType::EMAIL_SENT->isPaymentEvent())->toBeFalse();
    });

    it('isEmailEvent() returns true only for email events', function () {
        expect(EventType::EMAIL_SENT->isEmailEvent())->toBeTrue();
        expect(EventType::EMAIL_FAILED->isEmailEvent())->toBeTrue();
        expect(EventType::PAYMENT_PROCESSED->isEmailEvent())->toBeFalse();
        expect(EventType::ORDER_COMPLETED->isEmailEvent())->toBeFalse();
    });
});
