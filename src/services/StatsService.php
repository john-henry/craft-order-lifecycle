<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use Exception;
use johnhenry\orderlifecycle\enums\EventType;
use JsonException;
use yii\base\InvalidConfigException;

/**
 * Stats service.
 *
 * Computes aggregate order lifecycle statistics (conversion, abandonment,
 * checkout duration, payment attempts, email success, cart value and
 * period-over-period trends) with short-lived caching.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class StatsService extends Component
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns the aggregated statistics for the given number of days.
     *
     * @param int $days The look-back window in days; 0 means all time.
     * @return array The aggregated statistics.
     * @throws Exception If a query or date preparation fails.
     * @throws JsonException If a snapshot cannot be decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getStats(int $days): array
    {
        // scope to the current store so stats don't bleed across stores
        $storeId = $this->_resolveStoreId();

        // storeId in the key too, or one store's cached snapshot would get
        // served to another store for up to 5 minutes on a multi-store install
        $cacheKey = 'orderlifecycle_stats_' . $days . '_' . ($storeId ?? 'all');
        $cached = Craft::$app->getCache()->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        // 0 = all time, no date filter
        $sinceDate = $days > 0 ? DateTimeHelper::toDateTime('-' . $days . ' days') : null;
        $sinceDateDb = $sinceDate ? Db::prepareDateForDb($sinceDate) : null;

        $totalLogs = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->count();

        $logsByType = (new Query())
            ->select(['type', 'COUNT(*) as count'])
            ->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->groupBy(['type'])
            ->orderBy(['count' => SORT_DESC])
            ->all();

        $uniqueOrders = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->count('DISTINCT [[orderId]]');

        $avgLogsPerOrder = $uniqueOrders > 0 ? round($totalLogs / $uniqueOrders, 1) : 0;

        $topEventTypes = array_map(
            static function(array $row): array {
                $type = EventType::tryFrom((string)$row['type']);
                $row['label'] = $type?->getLabel() ?? (string)$row['type'];

                return $row;
            },
            array_slice($logsByType, 0, 5)
        );

        $avgTimeToCompletion = $this->getAverageTimeToCompletion($sinceDateDb, $storeId);
        $conversionStats = $this->getConversionStats($sinceDateDb, $storeId);
        $avgCheckoutDuration = $this->getAverageCheckoutDuration($sinceDateDb, $storeId);
        $abandonmentStats = $this->getAbandonmentStats($sinceDateDb, $storeId, $conversionStats);
        $avgPaymentAttempts = $this->getAveragePaymentAttempts($sinceDateDb, $storeId);
        $returningCustomerRate = $this->getReturningCustomerRate($sinceDateDb, $storeId);
        $avgCartValue = $this->getAverageCartValue($sinceDateDb, $storeId);
        $emailStats = $this->getEmailStats($sinceDateDb, $storeId);

        // no prior period to compare against for all-time
        $trends = [];
        if ($days > 0 && $sinceDateDb !== null) {
            $prevSinceDateDb = Db::prepareDateForDb(
                DateTimeHelper::toDateTime('-' . ($days * 2) . ' days')
            );
            $trends = $this->computeTrends($sinceDateDb, $prevSinceDateDb, $storeId, [
                'totalLogs' => (int)$totalLogs,
                'uniqueOrders' => (int)$uniqueOrders,
                'conversionRate' => $conversionStats['rate'],
                'abandonmentRate' => $abandonmentStats['rate'],
                'emailSuccessRate' => $emailStats['successRate'],
                'emailsSent' => (int)$emailStats['sent'],
                'emailsFailed' => (int)$emailStats['failed'],
            ]);
        }

        $stats = [
            'totalLogs' => (int)$totalLogs,
            'uniqueOrders' => (int)$uniqueOrders,
            'avgLogsPerOrder' => $avgLogsPerOrder,
            'topEventTypes' => $topEventTypes,
            'avgTimeToCompletion' => $avgTimeToCompletion,
            'conversionRate' => $conversionStats['rate'],
            'cartsCreated' => $conversionStats['cartsCreated'],
            'ordersCompleted' => $conversionStats['ordersCompleted'],
            'avgCheckoutDuration' => $avgCheckoutDuration,
            'abandonmentRate' => $abandonmentStats['rate'],
            'abandonedCarts' => $abandonmentStats['abandoned'],
            'avgPaymentAttempts' => $avgPaymentAttempts,
            'returningCustomerRate' => $returningCustomerRate,
            'avgCartValue' => $avgCartValue,
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
     * Reads the completed-order total from the snapshot's `order.totalPrice`,
     * which `buildSnapshot()` always populates.
     *
     * @param int $days The look-back window in days; 0 means all time.
     * @param int|null $storeId The store to scope stats to, or null for all stores.
     * @return array The aggregated store statistics for the insight prompt.
     * @throws Exception If a query or date preparation fails.
     * @throws JsonException If a snapshot cannot be decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getStoreInsightStats(int $days, ?int $storeId = null): array
    {
        $since = $days > 0
            ? Db::prepareDateForDb(DateTimeHelper::toDateTime('-' . $days . ' days'))
            : null;

        // every count here is scoped to the store via the logs' own storeId column
        $countByType = function(string $type) use ($since, $storeId): int {
            $query = (new Query())
                ->from('{{%orderlifecycle_logs}}')
                ->where(['type' => $type])
                ->andFilterWhere(['>=', 'dateCreated', $since]);
            $this->_scopeLogsToStore($query, $storeId);

            return (int)$query->count();
        };

        $totalLogsQuery = (new Query())->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $since]);
        $this->_scopeLogsToStore($totalLogsQuery, $storeId);
        $totalLogs = (int)$totalLogsQuery->count();

        $uniqueOrdersQuery = (new Query())->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $since]);
        $this->_scopeLogsToStore($uniqueOrdersQuery, $storeId);
        $uniqueOrders = (int)$uniqueOrdersQuery->count('DISTINCT [[orderId]]');

        // reuse the corrected totals (pre-plugin orders included) and the same
        // 1-hour-inactivity abandonment definition used by the stats widget, instead
        // of re-deriving them from raw log counts and drifting out of sync again
        $conversionStats = $this->getConversionStats($since, $storeId);
        $cartsCreated = $conversionStats['cartsCreated'];
        $ordersCompleted = $conversionStats['ordersCompleted'];
        $abandonmentStats = $this->getAbandonmentStats($since, $storeId, $conversionStats);

        $paymentAttempts = $countByType('paymentAttempt');
        $emailsSent = $countByType('emailSent');
        $emailsFailed = $countByType('emailFailed');
        $refunds = $countByType('paymentRefunded');
        $couponApplied = $countByType('couponApplied');

        $topTypesQuery = (new Query())->select(['type', 'COUNT(*) as count'])
            ->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $since]);
        $this->_scopeLogsToStore($topTypesQuery, $storeId);
        $topTypes = $topTypesQuery->groupBy(['type'])->orderBy(['count' => SORT_DESC])->limit(8)->all();

        [$avgCartValue, $currency] = $this->_averageCompletedOrderValue($since, $storeId);

        $conversionRate = $conversionStats['rate'];
        $abandonmentRate = $abandonmentStats['rate'];
        $emailSuccessRate = ($emailsSent + $emailsFailed) > 0
            ? round(($emailsSent / ($emailsSent + $emailsFailed)) * 100, 1) : null;
        $avgPaymentAttempts = $ordersCompleted > 0 ? round($paymentAttempts / $ordersCompleted, 2) : null;

        return compact(
            'totalLogs',
            'uniqueOrders',
            'cartsCreated',
            'ordersCompleted',
            'conversionRate',
            'abandonmentRate',
            'paymentAttempts',
            'avgPaymentAttempts',
            'emailsSent',
            'emailsFailed',
            'emailSuccessRate',
            'refunds',
            'couponApplied',
            'avgCartValue',
            'currency',
            'topTypes'
        );
    }

    /**
     * Returns how many other orders the same customer has completed, not
     * counting the given order itself or any carts still in a temporary
     * (incomplete) state.
     *
     * @param Order $order The order to find previous orders for.
     * @return int The number of previous completed orders by the same customer.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getPreviousOrderCount(Order $order): int
    {
        $customer = $order->getCustomer();

        if ($customer === null) {
            return 0;
        }

        return Order::find()
            ->customer($customer)
            ->id('not ' . $order->id)
            ->status('not temp')
            ->count();
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Computes the average completed-order value and its currency from snapshots.
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @param int|null $storeId The store to scope the average to, or null for all stores.
     * @return array{0: float|null, 1: string} The average value (or null) and currency code.
     * @throws JsonException If a snapshot cannot be decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _averageCompletedOrderValue(?string $sinceDateDb, ?int $storeId = null): array
    {
        $query = (new Query())->select(['snapshot'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb]);
        $this->_scopeLogsToStore($query, $storeId);

        $completedLogs = $query->all();

        $totalValue = 0;
        $count = 0;
        $currency = '';

        foreach ($completedLogs as $log) {
            $snapshot = json_decode($log['snapshot'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $price = $snapshot['order']['totalPrice'] ?? null;

            if ($price === null) {
                continue;
            }

            $totalValue += $price;
            $count++;
            if ($currency === '') {
                $currency = $snapshot['order']['currency'] ?? '';
            }
        }

        return [$count > 0 ? round($totalValue / $count, 2) : null, $currency];
    }

    /**
     * Constrains a `{{%orderlifecycle_logs}}` query to a single store.
     *
     * A no-op when `$storeId` is null. Rows logged before the `storeId` column
     * existed and whose order has since been deleted couldn't be backfilled and
     * stay null, so they're naturally excluded from any store-scoped query.
     *
     * @param Query $query The logs query to constrain, mutated in place.
     * @param int|null $storeId The store to scope to, or null for all stores.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.1
     */
    private function _scopeLogsToStore(Query $query, ?int $storeId): void
    {
        if ($storeId !== null) {
            $query->andWhere(['storeId' => $storeId]);
        }
    }

    /**
     * Resolves the Commerce store ID to scope order-table stats queries to.
     *
     * @return int|null The current store ID, or null if Commerce cannot resolve one.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _resolveStoreId(): ?int
    {
        try {
            return Commerce::getInstance()?->getStores()->getCurrentStore()->id;
        } catch (InvalidConfigException) {
            return null;
        }
    }

    /**
     * Calculates the average time from cart creation to order completion.
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @param int|null $storeId The store to scope counts to, or null for all stores.
     * @return string|null The formatted average duration, or null if none.
     * @throws Exception If a date cannot be parsed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getAverageTimeToCompletion(?string $sinceDateDb, ?int $storeId = null): ?string
    {
        $completedOrders = (new Query())
            ->select(['orderId'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->column();

        if (empty($completedOrders)) {
            return null;
        }

        $totalSeconds = 0;
        $count = 0;

        foreach ($completedOrders as $orderId) {
            $cartCreated = (new Query())
                ->select(['dateCreated'])
                ->from('{{%orderlifecycle_logs}}')
                ->where(['orderId' => $orderId, 'type' => 'cartCreated'])
                ->orderBy(['dateCreated' => SORT_ASC])
                ->limit(1)
                ->one();

            $orderCompleted = (new Query())
                ->select(['dateCreated'])
                ->from('{{%orderlifecycle_logs}}')
                ->where(['orderId' => $orderId, 'type' => 'orderCompleted'])
                ->orderBy(['dateCreated' => SORT_DESC])
                ->limit(1)
                ->one();

            if ($cartCreated && $orderCompleted) {
                $start = DateTimeHelper::toDateTime($cartCreated['dateCreated']);
                $end = DateTimeHelper::toDateTime($orderCompleted['dateCreated']);
                $totalSeconds += $end->getTimestamp() - $start->getTimestamp();
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        $avgSeconds = $totalSeconds / $count;
        return $this->formatDuration($avgSeconds);
    }

    /**
     * Calculates the conversion rate from carts to completed orders.
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @param int|null $storeId The Commerce store ID to scope order queries to, or null for all stores.
     * @return array The carts created, orders completed and conversion rate.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getConversionStats(?string $sinceDateDb, ?int $storeId = null): array
    {
        $cartsCreated = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'cartCreated'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->count('DISTINCT [[orderId]]');

        $ordersCompleted = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->count('DISTINCT [[orderId]]');

        // orders from before the plugin was installed have no lifecycle logs
        // at all, so count them separately straight from Commerce's own table
        $prePluginCarts = (new Query())
            ->from('{{%commerce_orders}}')
            ->andFilterWhere(['>=', 'dateOrdered', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->andWhere(['not in', 'id', (new Query())
                ->select(['orderId'])
                ->from('{{%orderlifecycle_logs}}')
                ->where(['type' => 'cartCreated'])
                ->distinct(),
            ])
            ->count();

        $prePluginCompleted = (new Query())
            ->from('{{%commerce_orders}}')
            ->andFilterWhere(['>=', 'dateOrdered', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->andWhere(['isCompleted' => true])
            ->andWhere(['not in', 'id', (new Query())
                ->select(['orderId'])
                ->from('{{%orderlifecycle_logs}}')
                ->where(['type' => 'orderCompleted'])
                ->distinct(),
            ])
            ->count();

        $totalCartsCreated = $cartsCreated + $prePluginCarts;
        $totalOrdersCompleted = $ordersCompleted + $prePluginCompleted;

        $rate = $totalCartsCreated > 0 ? round(($totalOrdersCompleted / $totalCartsCreated) * 100, 1) : 0;

        return [
            'cartsCreated' => (int)$totalCartsCreated,
            'ordersCompleted' => (int)$totalOrdersCompleted,
            'rate' => $rate,
        ];
    }

    /**
     * Formats a duration in seconds to a human-readable string.
     *
     * @param float $seconds The duration in seconds.
     * @return string The formatted duration (e.g. 45s, 12m, 3.5h, 2.1d).
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function formatDuration(float $seconds): string
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
     * Calculates the average checkout duration (from checkout start to payment).
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @param int|null $storeId The store to scope counts to, or null for all stores.
     * @return string|null The formatted average duration, or null if none.
     * @throws Exception If a date cannot be parsed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getAverageCheckoutDuration(?string $sinceDateDb, ?int $storeId = null): ?string
    {
        $paidOrders = (new Query())
            ->select(['orderId'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderPaid'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->column();

        if (empty($paidOrders)) {
            return null;
        }

        $totalSeconds = 0;
        $count = 0;

        foreach ($paidOrders as $orderId) {
            $orderPaid = (new Query())
                ->select(['dateCreated'])
                ->from('{{%orderlifecycle_logs}}')
                ->where(['orderId' => $orderId, 'type' => 'orderPaid'])
                ->orderBy(['dateCreated' => SORT_DESC])
                ->limit(1)
                ->one();

            if (!$orderPaid) {
                continue;
            }

            $checkoutStart = (new Query())
                ->select(['dateCreated'])
                ->from('{{%orderlifecycle_logs}}')
                ->where(['orderId' => $orderId, 'type' => 'checkoutStarted'])
                ->andWhere(['<=', 'dateCreated', $orderPaid['dateCreated']])
                ->orderBy(['dateCreated' => SORT_DESC])
                ->limit(1)
                ->one();

            if ($checkoutStart) {
                $start = DateTimeHelper::toDateTime($checkoutStart['dateCreated']);
                $end = DateTimeHelper::toDateTime($orderPaid['dateCreated']);
                $diffSeconds = $end->getTimestamp() - $start->getTimestamp();

                // cap at an hour to keep outliers (abandoned-then-resumed
                // checkouts) from skewing the average
                if ($diffSeconds > 0 && $diffSeconds < 3600) {
                    $totalSeconds += $diffSeconds;
                    $count++;
                }
            }
        }

        if ($count === 0) {
            return null;
        }

        $avgSeconds = $totalSeconds / $count;
        return $this->formatDuration($avgSeconds);
    }

    /**
     * The number of seconds of inactivity after which an incomplete cart counts as abandoned.
     *
     * @author John Henry Donovan
     * @since 1.0.1
     */
    private const ABANDONMENT_THRESHOLD_SECONDS = 3600;

    /**
     * Calculates the cart abandonment rate.
     *
     * A cart counts as abandoned once it's gone {@see ABANDONMENT_THRESHOLD_SECONDS} (1 hour)
     * since its last activity (`dateUpdated`, which Commerce bumps on every recalculation) without
     * completing. Carts still within that window are "in progress" - not completed, but not yet
     * abandoned either - so they're excluded from both the numerator and, implicitly, from being
     * miscounted as abandoned.
     *
     * Queries `{{%commerce_orders}}` directly (scoped to the same store and window as
     * {@see getConversionStats()}) rather than the logs table, since the logs table has no record
     * of "still active" state - only discrete events. `cartsCreated` for the rate's denominator is
     * taken from `$conversionStats`, which already corrects for pre-plugin orders.
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @param int|null $storeId The store to scope counts to, or null for all stores.
     * @param array $conversionStats The result of {@see getConversionStats()} for the same window.
     * @return array The number of abandoned carts and the abandonment rate.
     * @throws Exception If the inactivity cutoff date fails to prepare.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getAbandonmentStats(?string $sinceDateDb, ?int $storeId, array $conversionStats): array
    {
        $cutoff = Db::prepareDateForDb(
            DateTimeHelper::toDateTime('-' . self::ABANDONMENT_THRESHOLD_SECONDS . ' seconds')
        );

        $abandoned = (new Query())
            ->from('{{%commerce_orders}}')
            ->where(['isCompleted' => false])
            ->andFilterWhere(['storeId' => $storeId])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andWhere(['<=', 'dateUpdated', $cutoff])
            ->count();

        $cartsCreated = $conversionStats['cartsCreated'];
        $rate = $cartsCreated > 0 ? round(($abandoned / $cartsCreated) * 100, 1) : 0;

        return [
            'abandoned' => (int)$abandoned,
            'rate' => $rate,
        ];
    }

    /**
     * Calculates the average number of payment retries among orders that required them.
     *
     * First-time successful payments have no paymentAttempt log, so this only
     * reflects orders where the customer had to retry. Returns 0 when no retries occurred.
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @param int|null $storeId The store to scope counts to, or null for all stores.
     * @return float The average payment retries per order that had retries.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getAveragePaymentAttempts(?string $sinceDateDb, ?int $storeId = null): float
    {
        $completedOrders = (new Query())
            ->select(['orderId'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->column();

        if (empty($completedOrders)) {
            return 0;
        }

        $totalAttempts = 0;
        $count = 0;

        foreach ($completedOrders as $orderId) {
            $attempts = (new Query())
                ->from('{{%orderlifecycle_logs}}')
                ->where(['orderId' => $orderId, 'type' => 'paymentAttempt'])
                ->count();

            if ($attempts > 0) {
                $totalAttempts += $attempts;
                $count++;
            }
        }

        return $count > 0 ? round($totalAttempts / $count, 1) : 0;
    }

    /**
     * Calculates the percentage of orders from returning customers.
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @param int|null $storeId The Commerce store ID to scope order queries to, or null for all stores.
     * @return float The returning customer rate.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getReturningCustomerRate(?string $sinceDateDb, ?int $storeId = null): float
    {
        $completedOrders = (new Query())
            ->select(['o.id', 'o.email'])
            ->from(['o' => '{{%commerce_orders}}'])
            ->where(['o.isCompleted' => true])
            ->andWhere(['not', ['o.email' => null]])
            ->andWhere(['not', ['o.email' => '']])
            ->andFilterWhere(['>=', 'o.dateOrdered', $sinceDateDb])
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
     * Calculates the average cart value for completed orders.
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @param int|null $storeId The store to scope counts to, or null for all stores.
     * @return float|null The average cart value, or null if none.
     * @throws JsonException If a snapshot cannot be decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getAverageCartValue(?string $sinceDateDb, ?int $storeId = null): ?float
    {
        $completedOrders = (new Query())
            ->select(['orderId', 'snapshot'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->all();

        if (empty($completedOrders)) {
            return null;
        }

        $totalValue = 0;
        $count = 0;

        foreach ($completedOrders as $log) {
            $snapshot = json_decode($log['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            // older rows only have the payload copy, not order.totalPrice
            $totalPrice = $snapshot['order']['totalPrice'] ?? $snapshot['payload']['totalPrice'] ?? null;

            if ($totalPrice !== null) {
                $totalValue += $totalPrice;
                $count++;
            }
        }

        return $count > 0 ? round($totalValue / $count, 2) : null;
    }

    /**
     * Fetches raw counts for the previous equivalent period for trend comparison.
     *
     * @param string $sinceDateDb The DB-formatted start of the current period.
     * @param string $prevSinceDateDb The DB-formatted start of the previous period.
     * @param int|null $storeId The store to scope the baseline to, or null for all stores.
     * @return array The baseline counts for the previous period.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getPreviousPeriodBaseline(string $sinceDateDb, string $prevSinceDateDb, ?int $storeId = null): array
    {
        $start = ['>=', 'dateCreated', $prevSinceDateDb];
        $end = ['<',  'dateCreated', $sinceDateDb];
        $store = ['storeId' => $storeId];

        $totalLogs = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->andWhere($start)->andWhere($end)
            ->andFilterWhere($store)
            ->count();

        $uniqueOrders = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->andWhere($start)->andWhere($end)
            ->andFilterWhere($store)
            ->count('DISTINCT [[orderId]]');

        $cartsCreated = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'cartCreated'])
            ->andWhere($start)->andWhere($end)
            ->andFilterWhere($store)
            ->count('DISTINCT [[orderId]]');

        $ordersCompleted = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andWhere($start)->andWhere($end)
            ->andFilterWhere($store)
            ->count('DISTINCT [[orderId]]');

        $emailsSent = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'emailSent'])
            ->andWhere($start)->andWhere($end)
            ->andFilterWhere($store)
            ->count();

        $emailsFailed = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'emailFailed'])
            ->andWhere($start)->andWhere($end)
            ->andFilterWhere($store)
            ->count();

        return [
            'totalLogs' => (int)$totalLogs,
            'uniqueOrders' => (int)$uniqueOrders,
            'cartsCreated' => (int)$cartsCreated,
            'ordersCompleted' => (int)$ordersCompleted,
            'emailsSent' => (int)$emailsSent,
            'emailsFailed' => (int)$emailsFailed,
        ];
    }

    /**
     * Computes period-over-period percentage changes for key metrics.
     *
     * @param string $sinceDateDb The DB-formatted start of the current period.
     * @param string $prevSinceDateDb The DB-formatted start of the previous period.
     * @param int|null $storeId The store to scope the baseline to, or null for all stores.
     * @param array $current The current period's metric values.
     * @return array The percentage deltas for each tracked metric.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function computeTrends(string $sinceDateDb, string $prevSinceDateDb, ?int $storeId, array $current): array
    {
        $prev = $this->getPreviousPeriodBaseline($sinceDateDb, $prevSinceDateDb, $storeId);

        $prevConversionRate = $prev['cartsCreated'] > 0
            ? round(($prev['ordersCompleted'] / $prev['cartsCreated']) * 100, 1)
            : 0;

        $prevAbandoned = max(0, $prev['cartsCreated'] - $prev['ordersCompleted']);
        $prevAbandonmentRate = $prev['cartsCreated'] > 0
            ? round(($prevAbandoned / $prev['cartsCreated']) * 100, 1)
            : 0;

        $prevEmailTotal = (int)$prev['emailsSent'] + (int)$prev['emailsFailed'];
        $prevEmailSuccessRate = $prevEmailTotal > 0
            ? round(((int)$prev['emailsSent'] / $prevEmailTotal) * 100, 1)
            : null;

        $delta = static function(float|int $cur, float|int $p): ?float {
            return $p > 0 ? round((($cur - $p) / $p) * 100, 1) : null;
        };

        $emailDelta = null;
        if ($prevEmailSuccessRate !== null && ((int)$current['emailsSent'] + (int)$current['emailsFailed']) > 0) {
            $emailDelta = $delta($current['emailSuccessRate'], $prevEmailSuccessRate);
        }

        return [
            'totalLogs' => $delta($current['totalLogs'],      $prev['totalLogs']),
            'uniqueOrders' => $delta($current['uniqueOrders'],   $prev['uniqueOrders']),
            'conversionRate' => $delta($current['conversionRate'],  $prevConversionRate),
            'abandonmentRate' => $delta($current['abandonmentRate'], $prevAbandonmentRate),
            'emailSuccessRate' => $emailDelta,
        ];
    }

    /**
     * Calculates the email success rate.
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @param int|null $storeId The store to scope counts to, or null for all stores.
     * @return array The emails sent, failed and the success rate.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getEmailStats(?string $sinceDateDb, ?int $storeId = null): array
    {
        $emailsSent = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'emailSent'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->count();

        $emailsFailed = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'emailFailed'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->andFilterWhere(['storeId' => $storeId])
            ->count();

        $total = $emailsSent + $emailsFailed;
        $successRate = $total > 0 ? round(($emailsSent / $total) * 100, 1) : 0;

        return [
            'sent' => (int)$emailsSent,
            'failed' => (int)$emailsFailed,
            'successRate' => $successRate,
        ];
    }
}
