<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\variables;

use craft\commerce\elements\Order;
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\services\AiInsightsService;
use johnhenry\orderlifecycle\services\OrderLifecycleLogger;
use johnhenry\orderlifecycle\services\StatsService;
use yii\base\Exception as YiiException;
use yii\base\InvalidConfigException;
use yii\db\Exception as DbException;

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
     * @throws DbException If the log row cannot be inserted.
     * @throws YiiException If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function logCheckoutStarted(Order $order): bool
    {
        return OrderLifecycle::getInstance()->getLogger()->logCheckoutStarted($order);
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
        return OrderLifecycle::getInstance()->getLogger();
    }

    /**
     * Returns the AI insights service for advanced usage.
     *
     * Usage in Twig:
     * {% set structured = craft.orderLifecycle.aiInsights.decodeStructuredInsights(savedInsights.message) %}
     *
     * @return AiInsightsService The AI insights service instance.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getAiInsights(): AiInsightsService
    {
        return OrderLifecycle::getInstance()->getAiInsights();
    }

    /**
     * Returns the stats service for advanced usage.
     *
     * Usage in Twig:
     * {% set previousOrders = craft.orderLifecycle.stats.getPreviousOrderCount(order) %}
     *
     * @return StatsService The stats service instance.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getStats(): StatsService
    {
        return OrderLifecycle::getInstance()->getStats();
    }
}
