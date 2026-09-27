<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use Exception;
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\services\OrderLifecycleLogger;
use yii\console\ExitCode;

/**
 * Maintains the order lifecycle log table: purges old or orphaned entries, erases a customer's entries, and reports on it.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class LogsController extends Controller
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int Number of days to keep logs (default: 90).
     */
    public int $days = 90;

    /**
     * @var bool Dry run mode - show what would be deleted without actually deleting.
     */
    public bool $dryRun = false;

    /**
     * @var string|null The customer email address whose entries to erase.
     */
    public ?string $email = null;

    /**
     * @var int|null The order ID whose entries to erase.
     */
    public ?int $order = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param string $actionID The action ID.
     * @return array The available options for the action.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'purge' || $actionID === 'purge-orphaned' || $actionID === 'erase') {
            $options[] = 'dryRun';
        }

        if ($actionID === 'erase') {
            $options[] = 'email';
            $options[] = 'order';
        }

        if ($actionID === 'purge') {
            $options[] = 'days';
        }

        return $options;
    }

    /**
     * @inheritdoc
     *
     * @return array The option aliases.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'd' => 'days',
            'n' => 'dryRun',
        ]);
    }

    /**
     * Purges log entries older than the configured number of days.
     *
     * @return int The exit code.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPurge(): int
    {
        if ($this->days < 1) {
            $this->stderr(Craft::t('order-lifecycle', '--days must be 1 or more.') . PHP_EOL, Console::FG_RED);
            return ExitCode::USAGE;
        }

        $this->stdout(Craft::t('order-lifecycle', 'Purging Order Lifecycle logs older than {days} days...', ['days' => $this->days]) . PHP_EOL, Console::FG_YELLOW);

        try {
            $logger = OrderLifecycle::getInstance()->getLogger();
            $condition = OrderLifecycleLogger::olderThanCondition($this->days);

            $logsToDelete = (int)(new Query())
                ->from('{{%orderlifecycle_logs}}')
                ->where($condition)
                ->count();

            if ($logsToDelete === 0) {
                $this->stdout(Craft::t('order-lifecycle', 'No logs found older than {days} days.', ['days' => $this->days]) . PHP_EOL, Console::FG_GREEN);
                return ExitCode::OK;
            }

            $this->stdout(Craft::t('order-lifecycle', 'Found {count} log(s) to delete.', ['count' => $logsToDelete]) . PHP_EOL . PHP_EOL);

            if ($this->dryRun) {
                $this->_printSample((new Query())
                    ->from('{{%orderlifecycle_logs}}')
                    ->where($condition)
                    ->orderBy(['dateCreated' => SORT_ASC]), $logsToDelete);

                return ExitCode::OK;
            }

            $deleted = 0;
            do {
                $batch = $logger->deleteLogsOlderThan($this->days);
                $deleted += $batch;
            } while ($batch === OrderLifecycleLogger::PRUNE_BATCH_SIZE);

            $this->stdout(Craft::t('order-lifecycle', 'Successfully deleted {count} log(s).', ['count' => $deleted]) . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        } catch (Exception $e) {
            $this->stderr(Craft::t('order-lifecycle', 'Error: {message}', ['message' => $e->getMessage()]) . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Deletes logs for orders that no longer exist. A trashed order keeps its logs until it is deleted for good.
     *
     * Run this after purging inactive carts in Commerce to keep stats accurate.
     *
     * @return int The exit code.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPurgeOrphaned(): int
    {
        $this->stdout(Craft::t('order-lifecycle', 'Scanning for orphaned lifecycle logs...') . PHP_EOL, Console::FG_YELLOW);

        try {
            $logger = OrderLifecycle::getInstance()->getLogger();
            $count = (int)$logger->orphanedLogsQuery()->count();

            if ($count === 0) {
                $this->stdout(Craft::t('order-lifecycle', 'No orphaned logs found.') . PHP_EOL, Console::FG_GREEN);
                return ExitCode::OK;
            }

            $this->stdout(Craft::t('order-lifecycle', 'Found {count} log(s) for deleted orders.', ['count' => $count]) . PHP_EOL . PHP_EOL);

            if ($this->dryRun) {
                $this->_printSample($logger->orphanedLogsQuery()->select(['l.id', 'l.orderId', 'l.type', 'l.dateCreated']), $count);

                return ExitCode::OK;
            }

            $deleted = $logger->deleteOrphanedLogs();

            $this->stdout(Craft::t('order-lifecycle', 'Successfully deleted {count} log(s).', ['count' => $deleted]) . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        } catch (Exception $e) {
            $this->stderr(Craft::t('order-lifecycle', 'Error: {message}', ['message' => $e->getMessage()]) . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Erases one customer's or one order's lifecycle logs, for a request to erase their personal data.
     *
     * Pass --email to take every order placed with that address, and any log
     * that recorded it, or --order to take one order's logs.
     *
     * @return int The exit code.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionErase(): int
    {
        $email = $this->email !== null ? trim($this->email) : null;

        if (($email === null || $email === '') && $this->order === null) {
            $this->stderr(Craft::t('order-lifecycle', 'Pass --email or --order.') . PHP_EOL, Console::FG_RED);
            return ExitCode::USAGE;
        }

        try {
            $logger = OrderLifecycle::getInstance()->getLogger();
            $count = (int)$logger->customerLogsQuery($email, $this->order)->count();

            if ($count === 0) {
                $this->stdout(Craft::t('order-lifecycle', 'No logs found.') . PHP_EOL, Console::FG_GREEN);
                return ExitCode::OK;
            }

            $this->stdout(Craft::t('order-lifecycle', 'Found {count} log(s) to delete.', ['count' => $count]) . PHP_EOL . PHP_EOL);

            if ($this->dryRun) {
                $this->_printSample($logger->customerLogsQuery($email, $this->order)->orderBy(['dateCreated' => SORT_ASC]), $count);

                return ExitCode::OK;
            }

            if ($this->interactive && !$this->confirm(Craft::t('order-lifecycle', 'Delete them?'))) {
                return ExitCode::OK;
            }

            $deleted = $logger->eraseLogs($email, $this->order);

            $this->stdout(Craft::t('order-lifecycle', 'Successfully deleted {count} log(s).', ['count' => $deleted]) . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        } catch (Exception $e) {
            $this->stderr(Craft::t('order-lifecycle', 'Error: {message}', ['message' => $e->getMessage()]) . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Shows statistics about the stored logs.
     *
     * @return int The exit code.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionStats(): int
    {
        $this->stdout(Craft::t('order-lifecycle', 'Order Lifecycle Log Statistics') . PHP_EOL . PHP_EOL, Console::FG_YELLOW);

        try {
            $totalLogs = (int)(new Query())
                ->from('{{%orderlifecycle_logs}}')
                ->count();

            $this->stdout(Craft::t('order-lifecycle', 'Total logs: {count}', ['count' => $totalLogs]) . PHP_EOL, Console::FG_CYAN);

            $logsByType = (new Query())
                ->select(['type', 'COUNT(*) as count'])
                ->from('{{%orderlifecycle_logs}}')
                ->groupBy(['type'])
                ->orderBy(['count' => SORT_DESC])
                ->all();

            $this->stdout(PHP_EOL . Craft::t('order-lifecycle', 'Logs by type:') . PHP_EOL);
            foreach ($logsByType as $row) {
                $this->stdout(sprintf("  %-30s %d" . PHP_EOL, $row['type'], $row['count']));
            }

            $oldestLog = (new Query())
                ->select(['dateCreated'])
                ->from('{{%orderlifecycle_logs}}')
                ->orderBy(['dateCreated' => SORT_ASC])
                ->limit(1)
                ->scalar();

            $newestLog = (new Query())
                ->select(['dateCreated'])
                ->from('{{%orderlifecycle_logs}}')
                ->orderBy(['dateCreated' => SORT_DESC])
                ->limit(1)
                ->scalar();

            if ($oldestLog && $newestLog) {
                $this->stdout(PHP_EOL . Craft::t('order-lifecycle', 'Date range:') . PHP_EOL);
                $this->stdout('  ' . Craft::t('order-lifecycle', 'Oldest: {date}', ['date' => $oldestLog . ' UTC']) . PHP_EOL);
                $this->stdout('  ' . Craft::t('order-lifecycle', 'Newest: {date}', ['date' => $newestLog . ' UTC']) . PHP_EOL);
            }

            // rough estimate - ~2KB per log entry
            $estimatedSizeKB = $totalLogs * 2;
            $estimatedSizeMB = round($estimatedSizeKB / 1024, 2);

            $this->stdout(PHP_EOL . Craft::t('order-lifecycle', 'Estimated storage: {size} MB', ['size' => $estimatedSizeMB]) . PHP_EOL, Console::FG_CYAN);

            return ExitCode::OK;
        } catch (Exception $e) {
            $this->stderr(Craft::t('order-lifecycle', 'Error: {message}', ['message' => $e->getMessage()]) . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Vacuums/optimizes the logs table.
     *
     * @return int The exit code.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionOptimize(): int
    {
        $this->stdout(Craft::t('order-lifecycle', 'Optimizing orderlifecycle_logs table...') . PHP_EOL, Console::FG_YELLOW);

        try {
            $db = Craft::$app->getDb();

            if ($db->getIsMysql()) {
                $db->createCommand("OPTIMIZE TABLE {{%orderlifecycle_logs}}")->execute();
                $this->stdout(Craft::t('order-lifecycle', 'Table optimized successfully.') . PHP_EOL, Console::FG_GREEN);
            } elseif ($db->getIsPgsql()) {
                // VACUUM can't run inside a transaction - fine here since this
                // console action never opens one
                $db->createCommand("VACUUM ANALYZE {{%orderlifecycle_logs}}")->execute();
                $this->stdout(Craft::t('order-lifecycle', 'Table vacuumed successfully.') . PHP_EOL, Console::FG_GREEN);
            } else {
                $this->stdout(Craft::t('order-lifecycle', 'Optimization not supported for this database type.') . PHP_EOL, Console::FG_YELLOW);
            }

            return ExitCode::OK;
        } catch (Exception $e) {
            $this->stderr(Craft::t('order-lifecycle', 'Error: {message}', ['message' => $e->getMessage()]) . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Prints the first ten rows a dry run would delete, and how many more.
     *
     * @param Query $query The rows that would be deleted.
     * @param int $count How many rows match in all.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _printSample(Query $query, int $count): void
    {
        $this->stdout(Craft::t('order-lifecycle', 'Sample of logs that would be deleted:') . PHP_EOL, Console::FG_GREY);

        foreach ((clone $query)->limit(10)->all() as $log) {
            $this->stdout(sprintf(
                "  ID: %d | Order: %d | Type: %s | Date: %s UTC" . PHP_EOL,
                $log['id'],
                $log['orderId'],
                $log['type'],
                $log['dateCreated']
            ), Console::FG_GREY);
        }

        if ($count > 10) {
            $this->stdout('  ' . Craft::t('order-lifecycle', '... and {count} more', ['count' => $count - 10]) . PHP_EOL, Console::FG_GREY);
        }

        $this->stdout(PHP_EOL . Craft::t('order-lifecycle', 'Dry run complete. No logs were actually deleted.') . PHP_EOL, Console::FG_YELLOW);
        $this->stdout(Craft::t('order-lifecycle', 'Run without --dry-run to delete {count} log(s).', ['count' => $count]) . PHP_EOL, Console::FG_YELLOW);
    }
}
