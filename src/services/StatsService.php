<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use Exception;
use JsonException;

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
        $cacheKey = 'orderlifecycle_stats_' . $days;
        $cached = Craft::$app->getCache()->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        // $days === 0 means all time - no date filter applied
        $sinceDate = $days > 0 ? DateTimeHelper::toDateTime('-' . $days . ' days') : null;
        $sinceDateDb = $sinceDate ? Db::prepareDateForDb($sinceDate) : null;

        // Get total logs count
        $totalLogs = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->count();

        // Get logs by type
        $logsByType = (new Query())
            ->select(['type', 'COUNT(*) as count'])
            ->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->groupBy(['type'])
            ->orderBy(['count' => SORT_DESC])
            ->all();

        // Get unique orders tracked
        $uniqueOrders = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->count('DISTINCT [[orderId]]');

        // Calculate average logs per order
        $avgLogsPerOrder = $uniqueOrders > 0 ? round($totalLogs / $uniqueOrders, 1) : 0;

        // Get most active event types (top 5)
        $topEventTypes = array_slice($logsByType, 0, 5);

        // Get average time to completion (from cartCreated to orderCompleted)
        $avgTimeToCompletion = $this->getAverageTimeToCompletion($sinceDateDb);

        // Get conversion rate (carts that became completed orders)
        $conversionStats = $this->getConversionStats($sinceDateDb);

        // Get average checkout duration
        $avgCheckoutDuration = $this->getAverageCheckoutDuration($sinceDateDb);

        // Get cart abandonment stats
        $abandonmentStats = $this->getAbandonmentStats($sinceDateDb);

        // Get average payment attempts
        $avgPaymentAttempts = $this->getAveragePaymentAttempts($sinceDateDb);

        // Get returning customer rate
        $returningCustomerRate = $this->getReturningCustomerRate($sinceDateDb);

        // Get average cart value
        $avgCartValue = $this->getAverageCartValue($sinceDateDb);

        // Get email success rate
        $emailStats = $this->getEmailStats($sinceDateDb);

        // Compute period-over-period trends (skipped for all-time view)
        $trends = [];
        if ($days > 0 && $sinceDateDb !== null) {
            $prevSinceDateDb = Db::prepareDateForDb(
                DateTimeHelper::toDateTime('-' . ($days * 2) . ' days')
            );
            $trends = $this->computeTrends($sinceDateDb, $prevSinceDateDb, [
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

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Calculates the average time from cart creation to order completion.
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @return string|null The formatted average duration, or null if none.
     * @throws Exception If a date cannot be parsed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getAverageTimeToCompletion(?string $sinceDateDb): ?string
    {
        $completedOrders = (new Query())
            ->select(['orderId'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->column();

        if (empty($completedOrders)) {
            return null;
        }

        $totalSeconds = 0;
        $count = 0;

        foreach ($completedOrders as $orderId) {
            // Get first cart created log
            $cartCreated = (new Query())
                ->select(['dateCreated'])
                ->from('{{%orderlifecycle_logs}}')
                ->where(['orderId' => $orderId, 'type' => 'cartCreated'])
                ->orderBy(['dateCreated' => SORT_ASC])
                ->limit(1)
                ->one();

            // Get order completed log
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
     * @return array The carts created, orders completed and conversion rate.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getConversionStats(?string $sinceDateDb): array
    {
        // Get orders that have lifecycle logs
        $cartsCreated = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'cartCreated'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->count('DISTINCT [[orderId]]');

        $ordersCompleted = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->count('DISTINCT [[orderId]]');

        // Get orders from Commerce that don't have lifecycle logs (pre-plugin installation)
        $prePluginCarts = (new Query())
            ->from('{{%commerce_orders}}')
            ->andFilterWhere(['>=', 'dateOrdered', $sinceDateDb])
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
     * @return string|null The formatted average duration, or null if none.
     * @throws Exception If a date cannot be parsed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getAverageCheckoutDuration(?string $sinceDateDb): ?string
    {
        $paidOrders = (new Query())
            ->select(['orderId'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderPaid'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->column();

        if (empty($paidOrders)) {
            return null;
        }

        $totalSeconds = 0;
        $count = 0;

        foreach ($paidOrders as $orderId) {
            // Get order paid log first so we can use its timestamp to scope checkout start
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

            // Get the most recent checkoutStarted before the payment
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

                // Only count reasonable durations (less than 1 hour to filter outliers)
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
     * Calculates the cart abandonment rate.
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @return array The number of abandoned carts and the abandonment rate.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getAbandonmentStats(?string $sinceDateDb): array
    {
        $cartsCreated = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'cartCreated'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->count('DISTINCT [[orderId]]');

        $ordersCompleted = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->count('DISTINCT [[orderId]]');

        $abandoned = $cartsCreated - $ordersCompleted;
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
     * @return float The average payment retries per order that had retries.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getAveragePaymentAttempts(?string $sinceDateDb): float
    {
        $completedOrders = (new Query())
            ->select(['orderId'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
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
     * @return float The returning customer rate.
     * @throws JsonException If a snapshot cannot be decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getReturningCustomerRate(?string $sinceDateDb): float
    {
        // Get completed orders in the period with their customer email
        $completedOrders = (new Query())
            ->select(['o.id', 'o.email'])
            ->from(['o' => '{{%commerce_orders}}'])
            ->where(['o.isCompleted' => true])
            ->andWhere(['not', ['o.email' => null]])
            ->andWhere(['not', ['o.email' => '']])
            ->andFilterWhere(['>=', 'o.dateOrdered', $sinceDateDb])
            ->all();

        if (empty($completedOrders)) {
            return 0;
        }

        $returningCount = 0;

        foreach ($completedOrders as $order) {
            // A returning customer has at least one other completed order before this one
            $priorOrders = (new Query())
                ->from('{{%commerce_orders}}')
                ->where(['email' => $order['email'], 'isCompleted' => true])
                ->andWhere(['<', 'id', $order['id']])
                ->count();

            if ($priorOrders > 0) {
                $returningCount++;
            }
        }

        return round(($returningCount / count($completedOrders)) * 100, 1);
    }

    /**
     * Calculates the average cart value for completed orders.
     *
     * @param string|null $sinceDateDb The DB-formatted lower date bound, or null for all time.
     * @return float|null The average cart value, or null if none.
     * @throws JsonException If a snapshot cannot be decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getAverageCartValue(?string $sinceDateDb): ?float
    {
        $completedOrders = (new Query())
            ->select(['orderId', 'snapshot'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->all();

        if (empty($completedOrders)) {
            return null;
        }

        $totalValue = 0;
        $count = 0;

        foreach ($completedOrders as $log) {
            $snapshot = json_decode($log['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $totalPrice = $snapshot['payload']['totalPrice'] ?? null;

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
     * @return array The baseline counts for the previous period.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getPreviousPeriodBaseline(string $sinceDateDb, string $prevSinceDateDb): array
    {
        $start = ['>=', 'dateCreated', $prevSinceDateDb];
        $end = ['<',  'dateCreated', $sinceDateDb];

        $totalLogs = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->andWhere($start)->andWhere($end)
            ->count();

        $uniqueOrders = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->andWhere($start)->andWhere($end)
            ->count('DISTINCT [[orderId]]');

        $cartsCreated = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'cartCreated'])
            ->andWhere($start)->andWhere($end)
            ->count('DISTINCT [[orderId]]');

        $ordersCompleted = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])
            ->andWhere($start)->andWhere($end)
            ->count('DISTINCT [[orderId]]');

        $emailsSent = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'emailSent'])
            ->andWhere($start)->andWhere($end)
            ->count();

        $emailsFailed = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'emailFailed'])
            ->andWhere($start)->andWhere($end)
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
     * @param array $current The current period's metric values.
     * @return array The percentage deltas for each tracked metric.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function computeTrends(string $sinceDateDb, string $prevSinceDateDb, array $current): array
    {
        $prev = $this->getPreviousPeriodBaseline($sinceDateDb, $prevSinceDateDb);

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
     * @return array The emails sent, failed and the success rate.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getEmailStats(?string $sinceDateDb): array
    {
        $emailsSent = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'emailSent'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
            ->count();

        $emailsFailed = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'emailFailed'])
            ->andFilterWhere(['>=', 'dateCreated', $sinceDateDb])
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
