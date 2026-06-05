<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\variables;

use craft\commerce\elements\Order;
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\services\OrderLifecycleLogger;

/**
 * Order Lifecycle Twig variable.
 *
 * Exposes plugin functionality to front-end and CP templates under
 * `craft.orderLifecycle`.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class OrderLifecycleVariable
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Logs that checkout has started for the given order.
     *
     * Usage in Twig:
     * {% do craft.orderLifecycle.logCheckoutStarted(cart) %}
     *
     * @param Order $order The order (cart) to log the checkout event for.
     * @return bool True if logged successfully, false if already logged.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function logCheckoutStarted(Order $order): bool
    {
        return OrderLifecycle::getInstance()->logger->logCheckoutStarted($order);
    }

    /**
     * Returns the logger service for advanced usage.
     *
     * @return OrderLifecycleLogger The logger service instance.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getLogger(): OrderLifecycleLogger
    {
        return OrderLifecycle::getInstance()->logger;
    }
}
