<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\services;

use Carbon\Carbon;
use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\errors\SiteNotFoundException;
use craft\helpers\Cp;
use DateTimeInterface;
use johnhenry\orderlifecycle\enums\EventType;
use yii\base\InvalidConfigException;

/**
 * Stats service.
 *
 * Computes aggregate order lifecycle statistics (conversion, abandonment,
 * checkout duration, payment attempts, email success, cart value and
 * period-over-period trends) with short-lived caching.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class StatsService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int The seconds of inactivity after which an incomplete cart counts as abandoned.
     */
    public const ABANDONMENT_THRESHOLD_SECONDS = 3600;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns the aggregated statistics for the given number of days.
     *
     * @param int $days The look-back window in days; 0 means all time.
     * @param int|null $storeId The store to report on, or null for the one the
     *        control panel is showing (see {@see resolveStoreId()}).
     * @return array The aggregated statistics.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getStats(int $days, ?int $storeId = null): array
    {
        $storeId ??= $this->resolveStoreId();

        // key the cache by store as well as period, so each store gets its own snapshot
        $cacheKey = 'orderlifecycle_stats_' . $days . '_' . ($storeId ?? 'all');
        $cached = Craft::$app->getCache()->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        // 0 = all time, no date filter
        $since = $days > 0 ? self::_utcDaysAgo($days) : null;

        $counts = $this->_eventCounts($since, null, $storeId);

        $topEventTypes = array_map(
            static function(array $row): array {
                $type = EventType::tryFrom((string)$row['type']);
                $row['label'] = $type?->getLabel() ?? (string)$row['type'];

                return $row;
            },
            array_slice($counts['byType'], 0, 5)
        );

        $conversionStats = $this->_getConversionStats($since, null, $storeId);
        $abandonmentStats = $this->_getAbandonmentStats($since, null, $storeId, $conversionStats);
        $emailStats = $this->_getEmailStats($since, null, $storeId);

        // no prior period to compare against for all-time
        $trends = [];
        if ($since !== null) {
            $trends = $this->_computeTrends(self::_utcDaysAgo($days * 2), $since, $storeId, [
                'totalLogs' => $counts['total'],
                'uniqueOrders' => $counts['orders'],
                'conversionRate' => $conversionStats['rate'],
                'abandonmentRate' => $abandonmentStats['rate'],
                'emailSuccessRate' => $emailStats['successRate'],
                'emailsSent' => $emailStats['sent'],
                'emailsFailed' => $emailStats['failed'],
            ]);
        }

        [$avgCartValue, $currency] = $this->_averageCompletedOrderValue($since, $storeId);

        $stats = [
            'totalLogs' => $counts['total'],
            'uniqueOrders' => $counts['orders'],
            'avgLogsPerOrder' => $counts['orders'] > 0 ? round($counts['total'] / $counts['orders'], 1) : 0,
            'topEventTypes' => $topEventTypes,
            'avgTimeToCompletion' => $this->_getAverageTimeToCompletion($since, $storeId),
            'conversionRate' => $conversionStats['rate'],
            'cartsCreated' => $conversionStats['cartsCreated'],
            'ordersCompleted' => $conversionStats['ordersCompleted'],
            'avgCheckoutDuration' => $this->_getAverageCheckoutDuration($since, $storeId),
            'abandonmentRate' => $abandonmentStats['rate'],
            'abandonedCarts' => $abandonmentStats['abandoned'],
            'avgPaymentAttempts' => $this->_getAveragePaymentAttempts($since, $storeId),
            'returningCustomerRate' => $this->_getReturningCustomerRate($since, $storeId),
            'avgCartValue' => $avgCartValue,
            'currency' => $currency,
            'emailSuccessRate' => $emailStats['successRate'],
            'emailsSent' => $emailStats['sent'],
            'emailsFailed' => $emailStats['failed'],
            'trends' => $trends,
            'days' => $days,
        ];

        Craft::$app->getCache()->set($cacheKey, $stats, 300);

        return $stats;
    }

    /**
     * Aggregates store-wide lifecycle statistics for AI insight generation.
     *
     * Cached for five minutes per store and period, like {@see getStats()}.
     *
     * @param int $days The look-back window in days; 0 means all time.
     * @param int|null $storeId The store to scope stats to, or null for all stores.
     * @return array The aggregated store statistics for the insight prompt.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getStoreInsightStats(int $days, ?int $storeId = null): array
    {
        $cacheKey = 'orderlifecycle_store_insight_stats_' . $days . '_' . ($storeId ?? 'all');
        $cached = Craft::$app->getCache()->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $since = $days > 0 ? self::_utcDaysAgo($days) : null;

        $counts = $this->_eventCounts($since, null, $storeId);
        $byType = array_column($counts['byType'], 'count', 'type');
        $countOf = static fn(string $type): int => (int)($byType[$type] ?? 0);

        // conversion and abandonment come from the same helpers the dashboard
        // widget uses, so both surfaces always show the same numbers
        $conversionStats = $this->_getConversionStats($since, null, $storeId);
        $abandonmentStats = $this->_getAbandonmentStats($since, null, $storeId, $conversionStats);

        // a refund the gateway declined is logged with success false
        $refundsQuery = $this->_logs($since, null, $storeId)
            ->andWhere(['type' => EventType::PAYMENT_REFUNDED->value])
            ->andWhere(['not like', 'snapshot', '"success":false']);

        [$avgCartValue, $currency] = $this->_averageCompletedOrderValue($since, $storeId);

        $cartsCreated = $conversionStats['cartsCreated'];
        $ordersCompleted = $conversionStats['ordersCompleted'];
        $paymentAttempts = $countOf(EventType::PAYMENT_ATTEMPT->value);
        $emailsSent = $countOf(EventType::EMAIL_SENT->value);
        $emailsFailed = $countOf(EventType::EMAIL_FAILED->value);

        $stats = [
            'totalLogs' => $counts['total'],
            'uniqueOrders' => $counts['orders'],
            'cartsCreated' => $cartsCreated,
            'ordersCompleted' => $ordersCompleted,
            'conversionRate' => $conversionStats['rate'],
            'abandonmentRate' => $abandonmentStats['rate'],
            'paymentAttempts' => $paymentAttempts,
            'avgPaymentAttempts' => $ordersCompleted > 0 ? round($paymentAttempts / $ordersCompleted, 2) : null,
            'emailsSent' => $emailsSent,
            'emailsFailed' => $emailsFailed,
            'emailSuccessRate' => ($emailsSent + $emailsFailed) > 0
                ? round(($emailsSent / ($emailsSent + $emailsFailed)) * 100, 1) : null,
            'refunds' => (int)$refundsQuery->count(),
            'couponApplied' => $countOf(EventType::COUPON_APPLIED->value),
            'avgCartValue' => $avgCartValue,
            'currency' => $currency,
            'topTypes' => array_slice($counts['byType'], 0, 8),
        ];

        Craft::$app->getCache()->set($cacheKey, $stats, 300);

        return $stats;
    }

    /**
     * Returns how many earlier orders the same customer completed in the same
     * store, not counting the given order or any cart.
     *
     * @param Order $order The order to find previous orders for.
     * @return int The number of previous completed orders by the same customer.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getPreviousOrderCount(Order $order): int
    {
        $customer = $order->getCustomer();

        if ($customer === null) {
            return 0;
        }

        $query = Order::find()
            ->customer($customer)
            ->isCompleted(true)
            ->storeId($order->storeId);

        if ($order->id) {
            $query->id('not ' . $order->id);
        }

        if ($order->dateOrdered !== null) {
            $query->dateOrdered('< ' . $order->dateOrdered->format(DATE_ATOM));
        }

        return (int)$query->count();
    }

    /**
     * The store the plugin's statistics and insights report on.
     *
     * In the control panel that's the store of the site the site switcher is
     * on, the way Commerce's own widgets pick theirs; the current store would
     * always be the primary one there. Elsewhere it's the current store.
     *
     * @return int|null The store ID, or null if Commerce can't resolve one.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function resolveStoreId(): ?int
    {
        $commerce = Commerce::getInstance();

        if ($commerce === null) {
            return null;
        }

        try {
            if (Craft::$app->getRequest()->getIsCpRequest()) {
                $site = Cp::requestedSite();

                if ($site !== null) {
                    return $commerce->getStores()->getStoreBySiteId($site->id)?->id;
                }
            }

            return $commerce->getStores()->getCurrentStore()->id;
        } catch (InvalidConfigException|SiteNotFoundException) {
            return null;
        }
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * A log query limited to a window and a store, leaving out AI insight
     * entries, which record the plugin's own work rather than the order's.
     *
     * @param string|null $since UTC lower bound, inclusive, or null for none.
     * @param string|null $until UTC upper bound, exclusive, or null for none.
     * @param int|null $storeId The store, or null for every store.
     * @param string $alias A table alias, or '' for none.
     * @return Query
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _logs(?string $since, ?string $until, ?int $storeId, string $alias = ''): Query
    {
        $col = static fn(string $name) => $alias !== '' ? "$alias.$name" : $name;

        return (new Query())
            ->from($alias !== '' ? [$alias => '{{%orderlifecycle_logs}}'] : '{{%orderlifecycle_logs}}')
            ->where(['not', [$col('type') => EventType::AI_INSIGHTS->value]])
            ->andFilterWhere(['>=', $col('dateCreated'), $since])
            ->andFilterWhere(['<', $col('dateCreated'), $until])
            ->andFilterWhere([$col('storeId') => $storeId]);
    }

    /**
     * Totals for a window: entries, orders, and entries by type.
     *
     * @param string|null $since UTC lower bound, or null for none.
     * @param string|null $until UTC upper bound, or null for none.
     * @param int|null $storeId The store, or null for every store.
     * @return array{total: int, orders: int, byType: array<int, array{type: string, count: int}>}
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _eventCounts(?string $since, ?string $until, ?int $storeId): array
    {
        $byType = array_map(
            static fn(array $row) => ['type' => (string)$row['type'], 'count' => (int)$row['count']],
            $this->_logs($since, $until, $storeId)
                ->select(['type', 'count' => 'COUNT(*)'])
                ->groupBy(['type'])
                ->orderBy(['count' => SORT_DESC])
                ->all()
        );

        return [
            'total' => array_sum(array_column($byType, 'count')),
            'orders' => (int)$this->_logs($since, $until, $storeId)->count('DISTINCT [[orderId]]'),
            'byType' => $byType,
        ];
    }

    /**
     * The IDs of orders completed within a window, as a subquery.
     *
     * @param string|null $since UTC lower bound, or null for none.
     * @param int|null $storeId The store, or null for every store.
     * @return Query
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _completedOrderIds(?string $since, ?int $storeId): Query
    {
        return $this->_logs($since, null, $storeId)
            ->select(['orderId'])
            ->andWhere(['type' => EventType::ORDER_COMPLETED->value])
            ->distinct();
    }

    /**
     * The average completed-order value and its currency, from Commerce's own
     * order totals. Where a store has taken orders in more than one currency,
     * the most used one is averaged.
     *
     * @param string|null $since UTC lower bound, or null for none.
     * @param int|null $storeId The store, or null for every store.
     * @return array{0: float|null, 1: string} The average value (or null) and currency code.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _averageCompletedOrderValue(?string $since, ?int $storeId = null): array
    {
        $row = (new Query())
            ->select(['currency', 'average' => 'AVG([[totalPrice]])', 'orders' => 'COUNT(*)'])
            ->from('{{%commerce_orders}}')
            ->where(['id' => $this->_completedOrderIds($since, $storeId)])
            ->groupBy(['currency'])
            ->orderBy(['orders' => SORT_DESC])
            ->limit(1)
            ->one();

        if (!$row) {
            return [null, ''];
        }

        return [round((float)$row['average'], 2), (string)$row['currency']];
    }

    /**
     * Calculates the average time from cart creation to order completion, in
     * one query however many orders there are.
     *
     * @param string|null $since UTC lower bound, or null for none.
     * @param int|null $storeId The store, or null for every store.
     * @return string|null The formatted average duration, or null if none.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _getAverageTimeToCompletion(?string $since, ?int $storeId = null): ?string
    {
        $rows = $this->_logs($since, null, $storeId, 'c')
            ->select(['c.orderId', 'completed' => 'MAX([[c.dateCreated]])', 'created' => 'MIN([[s.dateCreated]])'])
            ->innerJoin(['s' => '{{%orderlifecycle_logs}}'], ['and', '[[s.orderId]] = [[c.orderId]]', ['s.type' => EventType::CART_CREATED->value]])
            ->andWhere(['c.type' => EventType::ORDER_COMPLETED->value])
            ->groupBy(['c.orderId'])
            ->all();

        $durations = array_map(
            static fn(array $row) => self::_seconds($row['created'], $row['completed']),
            $rows
        );

        return $durations !== [] ? $this->_formatDuration(array_sum($durations) / count($durations)) : null;
    }

    /**
     * Calculates the conversion rate from carts to completed orders.
     *
     * Only carts that had an item added (or that completed) count toward
     * "carts created", so empty per-visitor session carts are left out of the
     * denominator, matching how abandonment counts them. Orders completed
     * before the plugin was installed have no logs, so they're counted from
     * Commerce's own table, as both a cart and a completed order. Nothing
     * placed after installing is counted that way, so pruning old logs can't
     * make pruned carts reappear as untracked ones.
     *
     * @param string|null $since UTC lower bound, or null for none.
     * @param string|null $until UTC upper bound, or null for none.
     * @param int|null $storeId The store, or null for every store.
     * @return array The carts created, orders completed and conversion rate.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _getConversionStats(?string $since, ?string $until, ?int $storeId = null): array
    {
        $realCartIds = (new Query())
            ->select('orderId')
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => [EventType::LINE_ITEM_ADDED->value, EventType::ORDER_COMPLETED->value]])
            ->andFilterWhere(['storeId' => $storeId]);

        $cartsCreated = (int)$this->_logs($since, $until, $storeId)
            ->andWhere(['type' => EventType::CART_CREATED->value])
            ->andWhere(['orderId' => $realCartIds])
            ->count('DISTINCT [[orderId]]');

        $ordersCompleted = (int)$this->_logs($since, $until, $storeId)
            ->andWhere(['type' => EventType::ORDER_COMPLETED->value])
            ->count('DISTINCT [[orderId]]');

        $prePlugin = 0;
        $installedAt = self::_installedAt();

        if ($installedAt !== null && ($since === null || $since < $installedAt)) {
            $prePlugin = (int)(new Query())
                ->from('{{%commerce_orders}}')
                ->where(['isCompleted' => true])
                ->andWhere(['<', 'dateOrdered', $until !== null && $until < $installedAt ? $until : $installedAt])
                ->andFilterWhere(['>=', 'dateOrdered', $since])
                ->andFilterWhere(['storeId' => $storeId])
                ->andWhere(['not in', 'id', (new Query())
                    ->select(['orderId'])
                    ->from('{{%orderlifecycle_logs}}')
                    ->where(['type' => EventType::ORDER_COMPLETED->value]),
                ])
                ->count();
        }

        $totalCartsCreated = $cartsCreated + $prePlugin;
        $totalOrdersCompleted = $ordersCompleted + $prePlugin;

        return [
            'cartsCreated' => $totalCartsCreated,
            'ordersCompleted' => $totalOrdersCompleted,
            'rate' => $totalCartsCreated > 0 ? round(($totalOrdersCompleted / $totalCartsCreated) * 100, 1) : 0,
        ];
    }

    /**
     * Formats a duration in seconds to a human-readable string.
     *
     * @param float $seconds The duration in seconds.
     * @return string The formatted duration (e.g. 45s, 12m, 3.5h, 2.1d).
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds) . 's';
        }

        $minutes = $seconds / 60;
        if ($minutes < 60) {
            return round($minutes) . 'm';
        }

        $hours = $minutes / 60;
        if ($hours < 24) {
            return round($hours, 1) . 'h';
        }

        $days = $hours / 24;
        return round($days, 1) . 'd';
    }

    /**
     * Calculates the average checkout duration, from the last checkout visit
     * to the first payment. Two queries however many orders there are.
     *
     * @param string|null $since UTC lower bound, or null for none.
     * @param int|null $storeId The store, or null for every store.
     * @return string|null The formatted average duration, or null if none.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _getAverageCheckoutDuration(?string $since, ?int $storeId = null): ?string
    {
        // the first payment: paying again after a refund isn't the checkout
        $paidAt = $this->_logs($since, null, $storeId)
            ->select(['paid' => 'MIN([[dateCreated]])', 'orderId'])
            ->andWhere(['type' => EventType::ORDER_PAID->value])
            ->groupBy(['orderId'])
            ->all();

        if ($paidAt === []) {
            return null;
        }

        $paidByOrder = array_column($paidAt, 'paid', 'orderId');

        $checkoutRows = (new Query())
            ->select(['orderId', 'dateCreated'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => EventType::CHECKOUT_STARTED->value, 'orderId' => array_keys($paidByOrder)])
            ->all();

        // the last checkout visit before that payment, per order
        $startedByOrder = [];
        foreach ($checkoutRows as $row) {
            $paid = $paidByOrder[$row['orderId']] ?? null;

            if ($paid !== null && $row['dateCreated'] <= $paid && ($startedByOrder[$row['orderId']] ?? '') < $row['dateCreated']) {
                $startedByOrder[$row['orderId']] = $row['dateCreated'];
            }
        }

        $durations = [];
        foreach ($startedByOrder as $orderId => $started) {
            $seconds = self::_seconds($started, $paidByOrder[$orderId]);

            // cap at an hour to keep outliers (abandoned-then-resumed
            // checkouts) from skewing the average
            if ($seconds > 0 && $seconds < 3600) {
                $durations[] = $seconds;
            }
        }

        return $durations !== [] ? $this->_formatDuration(array_sum($durations) / count($durations)) : null;
    }

    /**
     * Calculates the cart abandonment rate.
     *
     * A cart counts as abandoned once it's gone
     * {@see ABANDONMENT_THRESHOLD_SECONDS} (1 hour) since its last activity
     * (`dateUpdated`, which Commerce bumps on every recalculation) without
     * completing. Carts still within that window are "in progress" and don't
     * count.
     *
     * The count is limited to carts with both a `cartCreated` and a
     * `lineItemAdded` log: `cartCreated` keeps it within the carts the
     * conversion rate divides by, and `lineItemAdded` leaves out empty carts.
     * With "Log Line Item Changes" off there are no `lineItemAdded` logs and
     * the rate reads as zero.
     *
     * @param string|null $since UTC lower bound on the cart's creation, or null for none.
     * @param string|null $until UTC upper bound on the cart's creation, or null for none.
     * @param int|null $storeId The store, or null for every store.
     * @param array $conversionStats The result of {@see _getConversionStats()} for the same window.
     * @return array The number of abandoned carts and the abandonment rate.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _getAbandonmentStats(?string $since, ?string $until, ?int $storeId, array $conversionStats): array
    {
        $cutoff = Carbon::now('UTC')->subSeconds(self::ABANDONMENT_THRESHOLD_SECONDS)->format('Y-m-d H:i:s');

        $trackedCartIds = (new Query())
            ->select('orderId')
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => EventType::CART_CREATED->value])
            ->andFilterWhere(['storeId' => $storeId]);

        $cartsWithAnItem = (new Query())
            ->select('orderId')
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => EventType::LINE_ITEM_ADDED->value])
            ->andFilterWhere(['storeId' => $storeId]);

        $abandoned = (int)(new Query())
            ->from('{{%commerce_orders}}')
            ->where(['isCompleted' => false])
            ->andFilterWhere(['storeId' => $storeId])
            ->andFilterWhere(['>=', 'dateCreated', $since])
            ->andFilterWhere(['<', 'dateCreated', $until])
            ->andWhere(['<=', 'dateUpdated', $cutoff])
            ->andWhere(['id' => $trackedCartIds])
            ->andWhere(['id' => $cartsWithAnItem])
            ->count();

        $cartsCreated = $conversionStats['cartsCreated'];

        return [
            'abandoned' => $abandoned,
            'rate' => $cartsCreated > 0 ? round(($abandoned / $cartsCreated) * 100, 1) : 0,
        ];
    }

    /**
     * Calculates the average number of payment retries among completed orders
     * that needed them, in one query.
     *
     * First-time successful payments have no paymentAttempt log, so this only
     * reflects orders where the customer had to retry. Returns 0 when no
     * retries occurred.
     *
     * @param string|null $since UTC lower bound, or null for none.
     * @param int|null $storeId The store, or null for every store.
     * @return float The average payment retries per order that had retries.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _getAveragePaymentAttempts(?string $since, ?int $storeId = null): float
    {
        $row = (new Query())
            ->select(['attempts' => 'COUNT(*)', 'orders' => 'COUNT(DISTINCT [[orderId]])'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => EventType::PAYMENT_ATTEMPT->value])
            ->andWhere(['orderId' => $this->_completedOrderIds($since, $storeId)])
            ->one();

        $orders = (int)($row['orders'] ?? 0);

        return $orders > 0 ? round((int)$row['attempts'] / $orders, 1) : 0;
    }

    /**
     * Calculates the percentage of orders from returning customers.
     *
     * @param string|null $since UTC lower bound, or null for none.
     * @param int|null $storeId The store, or null for every store.
     * @return float The returning customer rate.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _getReturningCustomerRate(?string $since, ?int $storeId = null): float
    {
        $completedOrders = (new Query())
            ->select(['o.id', 'o.email'])
            ->from(['o' => '{{%commerce_orders}}'])
            ->where(['o.isCompleted' => true])
            ->andWhere(['not', ['o.email' => null]])
            ->andWhere(['not', ['o.email' => '']])
            ->andFilterWhere(['>=', 'o.dateOrdered', $since])
            ->andFilterWhere(['o.storeId' => $storeId])
            ->all();

        if (empty($completedOrders)) {
            return 0;
        }

        // one query for the earliest order ID per email store-wide, then
        // compare in memory - avoids a query per completed order
        $emails = array_values(array_unique(array_column($completedOrders, 'email')));

        $firstIdRows = (new Query())
            ->select(['firstId' => 'MIN([[id]])', 'email'])
            ->from('{{%commerce_orders}}')
            ->where(['isCompleted' => true, 'email' => $emails])
            ->andFilterWhere(['storeId' => $storeId])
            ->groupBy(['email'])
            ->all();

        $firstOrderIdByEmail = array_column($firstIdRows, 'firstId', 'email');

        $returningCount = 0;
        foreach ($completedOrders as $order) {
            $firstId = $firstOrderIdByEmail[$order['email']] ?? null;

            // returning = not their first completed order for that email
            if ($firstId !== null && (int)$order['id'] > (int)$firstId) {
                $returningCount++;
            }
        }

        return round(($returningCount / count($completedOrders)) * 100, 1);
    }

    /**
     * Computes period-over-period percentage changes for key metrics, working
     * out the previous period with the same calculations as the current one.
     *
     * @param string $prevSince UTC start of the previous period.
     * @param string $since UTC start of the current period, and end of the previous.
     * @param int|null $storeId The store, or null for every store.
     * @param array $current The current period's metric values.
     * @return array The percentage deltas for each tracked metric.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _computeTrends(string $prevSince, string $since, ?int $storeId, array $current): array
    {
        $prevCounts = $this->_eventCounts($prevSince, $since, $storeId);
        $prevConversion = $this->_getConversionStats($prevSince, $since, $storeId);
        $prevAbandonment = $this->_getAbandonmentStats($prevSince, $since, $storeId, $prevConversion);
        $prevEmail = $this->_getEmailStats($prevSince, $since, $storeId);

        $delta = static function(float|int $cur, float|int $p): ?float {
            return $p > 0 ? round((($cur - $p) / $p) * 100, 1) : null;
        };

        $emailDelta = null;
        if (($prevEmail['sent'] + $prevEmail['failed']) > 0 && ($current['emailsSent'] + $current['emailsFailed']) > 0) {
            $emailDelta = $delta($current['emailSuccessRate'], $prevEmail['successRate']);
        }

        return [
            'totalLogs' => $delta($current['totalLogs'], $prevCounts['total']),
            'uniqueOrders' => $delta($current['uniqueOrders'], $prevCounts['orders']),
            'conversionRate' => $delta($current['conversionRate'], $prevConversion['rate']),
            'abandonmentRate' => $delta($current['abandonmentRate'], $prevAbandonment['rate']),
            'emailSuccessRate' => $emailDelta,
        ];
    }

    /**
     * Calculates the email success rate.
     *
     * @param string|null $since UTC lower bound, or null for none.
     * @param string|null $until UTC upper bound, or null for none.
     * @param int|null $storeId The store, or null for every store.
     * @return array The emails sent, failed and the success rate.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _getEmailStats(?string $since, ?string $until, ?int $storeId = null): array
    {
        $counts = array_column(
            $this->_logs($since, $until, $storeId)
                ->select(['type', 'count' => 'COUNT(*)'])
                ->andWhere(['type' => [EventType::EMAIL_SENT->value, EventType::EMAIL_FAILED->value]])
                ->groupBy(['type'])
                ->all(),
            'count',
            'type'
        );

        $sent = (int)($counts[EventType::EMAIL_SENT->value] ?? 0);
        $failed = (int)($counts[EventType::EMAIL_FAILED->value] ?? 0);
        $total = $sent + $failed;

        return [
            'sent' => $sent,
            'failed' => $failed,
            'successRate' => $total > 0 ? round(($sent / $total) * 100, 1) : 0,
        ];
    }

    /**
     * The UTC time a number of days ago, as stored dates are compared.
     *
     * @param int $days The number of days.
     * @return string
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _utcDaysAgo(int $days): string
    {
        return Carbon::now('UTC')->subDays($days)->format('Y-m-d H:i:s');
    }

    /**
     * Seconds between two stored UTC times.
     *
     * @param string $from The earlier time.
     * @param string $to The later time.
     * @return int
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _seconds(string $from, string $to): int
    {
        return Carbon::parse($to, 'UTC')->getTimestamp() - Carbon::parse($from, 'UTC')->getTimestamp();
    }

    /**
     * When the plugin was installed, as a stored UTC time.
     *
     * @return string|null
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _installedAt(): ?string
    {
        $installDate = Craft::$app->getPlugins()->getStoredPluginInfo('order-lifecycle')['installDate'] ?? null;

        return $installDate instanceof DateTimeInterface
            ? Carbon::instance($installDate)->setTimezone('UTC')->format('Y-m-d H:i:s')
            : null;
    }
}
