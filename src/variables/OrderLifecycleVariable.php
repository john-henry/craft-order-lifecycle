<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\variables;

use craft\commerce\elements\Order;
use johnhenry\orderlifecycle\OrderLifecycle;
use yii\base\Exception as YiiException;
use yii\base\InvalidConfigException;
use yii\db\Exception as DbException;

/**
 * Order Lifecycle Twig variable.
 *
 * Exposes the plugin to templates under `craft.orderLifecycle`.
 *
 * Deliberately narrow. Craft registers this variable for front-end requests as
 * well as control panel ones, and a template is not a place where permissions
 * get checked, so everything here either takes an element the template already
 * holds or works on a string it was handed. Nothing takes a bare ID, and
 * nothing writes to or deletes a log.
 *
 * The services themselves are still reachable from PHP, where the caller
 * controls who is asking:
 *
 * ```php
 * OrderLifecycle::getInstance()->getLogger();
 * ```
 *
 * @author John Henry Donovan <info@johnhenry.ie>
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
     * @throws DbException If the log row cannot be inserted.
     * @throws YiiException If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function logCheckoutStarted(Order $order): bool
    {
        return OrderLifecycle::getInstance()->getLogger()->logCheckoutStarted($order);
    }

    /**
     * Returns how many orders this order's customer completed before it.
     *
     * Usage in Twig:
     * {% set previousOrders = craft.orderLifecycle.getPreviousOrderCount(order) %}
     *
     * @param Order $order The order whose customer to count previous orders for.
     * @return int The number of earlier completed orders, or 0 where the order
     *             has no customer.
     * @throws InvalidConfigException If the stats service cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getPreviousOrderCount(Order $order): int
    {
        return OrderLifecycle::getInstance()->getStats()->getPreviousOrderCount($order);
    }

    /**
     * Decodes a saved AI insight where it was generated in the structured
     * style, so a template can tell one apart from a narrative one before
     * rendering it.
     *
     * Usage in Twig:
     * {% set structured = craft.orderLifecycle.decodeStructuredInsights(savedInsight.message) %}
     *
     * @param string|null $raw The saved insight message.
     * @return array|null The decoded structure, or null where the message is
     *                    empty or is narrative markdown rather than JSON.
     * @throws InvalidConfigException If the AI insights service cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function decodeStructuredInsights(?string $raw): ?array
    {
        return OrderLifecycle::getInstance()->getAiInsights()->decodeStructuredInsights($raw);
    }
}
