<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\enums;

use Craft;

/**
 * Order lifecycle event types.
 *
 * Backed string enum identifying every kind of lifecycle event that the plugin
 * can record, with helpers for human-readable labels and category grouping.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
enum EventType : string
{
    // =========================================================================
    // Cases
    // =========================================================================

    // Cart events
    case CART_CREATED = 'cartCreated';
    case CART_UPDATED = 'cartUpdated';

    // Line item events
    case LINE_ITEM_ADDED = 'lineItemAdded';
    case LINE_ITEM_REMOVED = 'lineItemRemoved';
    case LINE_ITEM_UPDATED = 'lineItemUpdated';

    // Coupon events
    case COUPON_APPLIED = 'couponApplied';
    case COUPON_REMOVED = 'couponRemoved';

    // Address events
    case SHIPPING_ADDRESS_SET = 'shippingAddressSet';
    case SHIPPING_ADDRESS_REMOVED = 'shippingAddressRemoved';
    case BILLING_ADDRESS_SET = 'billingAddressSet';
    case BILLING_ADDRESS_REMOVED = 'billingAddressRemoved';

    // Customer events
    case CUSTOMER_SET = 'customerSet';
    case CUSTOMER_REMOVED = 'customerRemoved';

    // Shipping method events
    case SHIPPING_METHOD_SET = 'shippingMethodSet';

    // Order status events
    case STATUS_CHANGED = 'statusChanged';
    case ORDER_COMPLETED = 'orderCompleted';
    case ORDER_PAID = 'orderPaid';

    // Payment events
    case PAYMENT_ATTEMPT = 'paymentAttempt';
    case PAYMENT_PROCESSED = 'paymentProcessed';
    case PAYMENT_AUTHORIZED = 'paymentAuthorized';
    case PAYMENT_CAPTURED = 'paymentCaptured';
    case PAYMENT_REFUNDED = 'paymentRefunded';
    case PAYMENT_TRANSACTION = 'paymentTransaction';

    // Email events
    case EMAIL_SENT = 'emailSent';
    case EMAIL_FAILED = 'emailFailed';

    // Checkout events
    case CHECKOUT_STARTED = 'checkoutStarted';

    // AI events
    case AI_INSIGHTS = 'aiInsights';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns the human-readable, translated label for this event type.
     *
     * @return string The translated label.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::CART_CREATED => Craft::t('order-lifecycle', 'Cart Created'),
            self::CART_UPDATED => Craft::t('order-lifecycle', 'Cart Updated'),
            self::LINE_ITEM_ADDED => Craft::t('order-lifecycle', 'Line Item Added'),
            self::LINE_ITEM_REMOVED => Craft::t('order-lifecycle', 'Line Item Removed'),
            self::LINE_ITEM_UPDATED => Craft::t('order-lifecycle', 'Line Item Updated'),
            self::COUPON_APPLIED => Craft::t('order-lifecycle', 'Coupon Applied'),
            self::COUPON_REMOVED => Craft::t('order-lifecycle', 'Coupon Removed'),
            self::SHIPPING_ADDRESS_SET => Craft::t('order-lifecycle', 'Shipping Address Set'),
            self::SHIPPING_ADDRESS_REMOVED => Craft::t('order-lifecycle', 'Shipping Address Removed'),
            self::BILLING_ADDRESS_SET => Craft::t('order-lifecycle', 'Billing Address Set'),
            self::BILLING_ADDRESS_REMOVED => Craft::t('order-lifecycle', 'Billing Address Removed'),
            self::CUSTOMER_SET => Craft::t('order-lifecycle', 'Customer Set'),
            self::CUSTOMER_REMOVED => Craft::t('order-lifecycle', 'Customer Removed'),
            self::SHIPPING_METHOD_SET => Craft::t('order-lifecycle', 'Shipping Method Set'),
            self::STATUS_CHANGED => Craft::t('order-lifecycle', 'Status Changed'),
            self::ORDER_COMPLETED => Craft::t('order-lifecycle', 'Order Completed'),
            self::ORDER_PAID => Craft::t('order-lifecycle', 'Order Paid'),
            self::PAYMENT_ATTEMPT => Craft::t('order-lifecycle', 'Payment Attempt'),
            self::PAYMENT_PROCESSED => Craft::t('order-lifecycle', 'Payment Processed'),
            self::PAYMENT_AUTHORIZED => Craft::t('order-lifecycle', 'Payment Authorized'),
            self::PAYMENT_CAPTURED => Craft::t('order-lifecycle', 'Payment Captured'),
            self::PAYMENT_REFUNDED => Craft::t('order-lifecycle', 'Payment Refunded'),
            self::PAYMENT_TRANSACTION => Craft::t('order-lifecycle', 'Payment Transaction'),
            self::EMAIL_SENT => Craft::t('order-lifecycle', 'Email Sent'),
            self::EMAIL_FAILED => Craft::t('order-lifecycle', 'Email Failed'),
            self::CHECKOUT_STARTED => Craft::t('order-lifecycle', 'Checkout Started'),
            self::AI_INSIGHTS => Craft::t('order-lifecycle', 'AI Insights'),
        };
    }

    /**
     * Returns the category grouping for this event type.
     *
     * @return string The category handle (e.g. cart, payment, email).
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getCategory(): string
    {
        return match ($this) {
            self::CART_CREATED,
            self::CART_UPDATED => 'cart',
            self::LINE_ITEM_ADDED,
            self::LINE_ITEM_REMOVED,
            self::LINE_ITEM_UPDATED => 'lineItems',
            self::COUPON_APPLIED,
            self::COUPON_REMOVED => 'coupons',
            self::SHIPPING_ADDRESS_SET,
            self::SHIPPING_ADDRESS_REMOVED,
            self::BILLING_ADDRESS_SET,
            self::BILLING_ADDRESS_REMOVED => 'addresses',
            self::CUSTOMER_SET,
            self::CUSTOMER_REMOVED => 'customer',
            self::SHIPPING_METHOD_SET => 'shipping',
            self::STATUS_CHANGED,
            self::ORDER_COMPLETED,
            self::ORDER_PAID => 'order',
            self::PAYMENT_ATTEMPT,
            self::PAYMENT_PROCESSED,
            self::PAYMENT_AUTHORIZED,
            self::PAYMENT_CAPTURED,
            self::PAYMENT_REFUNDED,
            self::PAYMENT_TRANSACTION => 'payment',
            self::EMAIL_SENT,
            self::EMAIL_FAILED => 'email',
            self::CHECKOUT_STARTED => 'checkout',
            self::AI_INSIGHTS => 'ai',
        };
    }

    /**
     * Returns the timeline filter pill this event type is shown under.
     *
     * Mirrors {@see getCategory()}'s taxonomy one-to-one, with a single fold:
     * checkout events show under the Cart pill rather than getting their own,
     * since a dedicated "Checkout" pill would only ever contain one event type.
     *
     * @return string The filter pill handle (cart, lineItems, coupons, addresses,
     *     customer, shipping, order, payment, email, or ai).
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getFilterPill(): string
    {
        $category = $this->getCategory();

        return $category === 'checkout' ? 'cart' : $category;
    }

    /**
     * Returns whether this is a cart-related event.
     *
     * @return bool True if this event belongs to the cart category.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isCartEvent(): bool
    {
        return $this->getCategory() === 'cart';
    }

    /**
     * Returns whether this is a payment-related event.
     *
     * @return bool True if this event belongs to the payment category.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isPaymentEvent(): bool
    {
        return $this->getCategory() === 'payment';
    }

    /**
     * Returns whether this is an email-related event.
     *
     * @return bool True if this event belongs to the email category.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isEmailEvent(): bool
    {
        return $this->getCategory() === 'email';
    }
}
