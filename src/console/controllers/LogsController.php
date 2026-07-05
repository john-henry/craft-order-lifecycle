<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\console\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use Exception;
use johnhenry\orderlifecycle\OrderLifecycle;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\BaseConsole;

/**
 * Logs console controller.
 *
 * Provides CLI commands for maintaining the order lifecycle log table: purging
 * by age, purging orphaned logs, reporting statistics and optimizing the table.
 *
 * @author John Henry Donovan
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

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param string $actionID The action ID.
     * @return array The available options for the action.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'purge' || $actionID === 'purge-orphaned') {
            $options[] = 'dryRun';
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function optionAliases(): array
    {
        return [
            'd' => 'days',
            'n' => 'dryRun',
        ];
    }

    /**
     * Purges log entries older than the configured number of days.
     *
     * @return int The exit code.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionPurge(): int
    {
        $this->stdout(Craft::t('order-lifecycle', 'Purging Order Lifecycle logs older than {days} days...', ['days' => $this->days]) . PHP_EOL, BaseConsole::FG_YELLOW);

        try {
            $cutoffDate = DateTimeHelper::toDateTime('-' . $this->days . ' days');

            $logsToDelete = (new Query())
                ->from('{{%orderlifecycle_logs}}')
                ->where(['<', 'dateCreated', Db::prepareDateForDb($cutoffDate)])
                ->count();

            if ($logsToDelete === 0) {
                $this->stdout(Craft::t('order-lifecycle', 'No logs found older than {days} days.', ['days' => $this->days]) . PHP_EOL, BaseConsole::FG_GREEN);
                return ExitCode::OK;
            }

            $this->stdout(Craft::t('order-lifecycle', 'Found {count} log(s) to delete.', ['count' => $logsToDelete]) . PHP_EOL . PHP_EOL);

            if ($this->dryRun) {
                $sampleLogs = (new Query())
                    ->select(['id', 'orderId', 'type', 'dateCreated'])
                    ->from('{{%orderlifecycle_logs}}')
                    ->where(['<', 'dateCreated', Db::prepareDateForDb($cutoffDate)])
                    ->orderBy(['dateCreated' => SORT_ASC])
                    ->limit(10)
                    ->all();

                $this->stdout(Craft::t('order-lifecycle', 'Sample of logs that would be deleted:') . PHP_EOL, BaseConsole::FG_GREY);
                foreach ($sampleLogs as $log) {
                    $this->stdout(sprintf(
                        "  ID: %d | Order: %d | Type: %s | Date: %s" . PHP_EOL,
                        $log['id'],
                        $log['orderId'],
                        $log['type'],
                        $log['dateCreated']
                    ), BaseConsole::FG_GREY);
                }

                if ($logsToDelete > 10) {
                    $this->stdout('  ' . Craft::t('order-lifecycle', '... and {count} more', ['count' => $logsToDelete - 10]) . PHP_EOL, BaseConsole::FG_GREY);
                }

                $this->stdout(PHP_EOL . Craft::t('order-lifecycle', 'Dry run complete. No logs were actually deleted.') . PHP_EOL, BaseConsole::FG_YELLOW);
                $this->stdout(Craft::t('order-lifecycle', 'Run without --dry-run to delete {count} log(s).', ['count' => $logsToDelete]) . PHP_EOL, BaseConsole::FG_YELLOW);
            } else {
                $deleted = Craft::$app->getDb()->createCommand()
                    ->delete('{{%orderlifecycle_logs}}', ['<', 'dateCreated', Db::prepareDateForDb($cutoffDate)])
                    ->execute();

                $this->stdout(Craft::t('order-lifecycle', 'Successfully deleted {count} log(s).', ['count' => $deleted]) . PHP_EOL, BaseConsole::FG_GREEN);
            }

            return ExitCode::OK;
        } catch (Exception $e) {
            $this->stderr(Craft::t('order-lifecycle', 'Error: {message}', ['message' => $e->getMessage()]) . PHP_EOL, BaseConsole::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Deletes logs for orders that no longer exist (deleted or trashed orders).
     *
     * Run this after purging inactive carts in Commerce to keep stats accurate.
     *
     * @return int The exit code.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionPurgeOrphaned(): int
    {
        $this->stdout(Craft::t('order-lifecycle', 'Scanning for orphaned lifecycle logs...') . PHP_EOL, BaseConsole::FG_YELLOW);

        try {
            $orphanedQuery = (new Query())
                ->select(['l.id', 'l.orderId', 'l.type', 'l.dateCreated'])
                ->from('{{%orderlifecycle_logs}} l')
                ->leftJoin('{{%elements}} e', 'e.id = l.orderId')
                ->where(['OR',
                    ['e.id' => null],
                    ['NOT', ['e.dateDeleted' => null]],
                ]);

            $count = (clone $orphanedQuery)->count();

            if ($count === 0) {
                $this->stdout(Craft::t('order-lifecycle', 'No orphaned logs found.') . PHP_EOL, BaseConsole::FG_GREEN);
                return ExitCode::OK;
            }

            $this->stdout(Craft::t('order-lifecycle', 'Found {count} log(s) for deleted or trashed orders.', ['count' => $count]) . PHP_EOL . PHP_EOL);

            if ($this->dryRun) {
                $sample = (clone $orphanedQuery)->limit(10)->all();
                $this->stdout(Craft::t('order-lifecycle', 'Sample of logs that would be deleted:') . PHP_EOL, BaseConsole::FG_GREY);
                foreach ($sample as $log) {
                    $this->stdout(sprintf(
                        "  ID: %d | Order: %d | Type: %s | Date: %s" . PHP_EOL,
                        $log['id'],
                        $log['orderId'],
                        $log['type'],
                        $log['dateCreated']
                    ), BaseConsole::FG_GREY);
                }
                if ($count > 10) {
                    $this->stdout('  ' . Craft::t('order-lifecycle', '... and {count} more', ['count' => $count - 10]) . PHP_EOL, BaseConsole::FG_GREY);
                }
                $this->stdout(PHP_EOL . Craft::t('order-lifecycle', 'Dry run complete. No logs were actually deleted.') . PHP_EOL, BaseConsole::FG_YELLOW);
                $this->stdout(Craft::t('order-lifecycle', 'Run without --dry-run to delete {count} log(s).', ['count' => $count]) . PHP_EOL, BaseConsole::FG_YELLOW);
            } else {
                $deleted = OrderLifecycle::getInstance()->getLogger()->deleteOrphanedLogs();

                $this->stdout(Craft::t('order-lifecycle', 'Successfully deleted {count} log(s).', ['count' => $deleted]) . PHP_EOL, BaseConsole::FG_GREEN);
            }

            return ExitCode::OK;
        } catch (Exception $e) {
            $this->stderr(Craft::t('order-lifecycle', 'Error: {message}', ['message' => $e->getMessage()]) . PHP_EOL, BaseConsole::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Shows statistics about the stored logs.
     *
     * @return int The exit code.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionStats(): int
    {
        $this->stdout(Craft::t('order-lifecycle', 'Order Lifecycle Log Statistics') . PHP_EOL . PHP_EOL, BaseConsole::FG_YELLOW);

        try {
            $totalLogs = (new Query())
                ->from('{{%orderlifecycle_logs}}')
                ->count();

            $this->stdout(Craft::t('order-lifecycle', 'Total logs: {count}', ['count' => $totalLogs]) . PHP_EOL, BaseConsole::FG_CYAN);

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
                $this->stdout('  ' . Craft::t('order-lifecycle', 'Oldest: {date}', ['date' => $oldestLog]) . PHP_EOL);
                $this->stdout('  ' . Craft::t('order-lifecycle', 'Newest: {date}', ['date' => $newestLog]) . PHP_EOL);
            }

            // rough estimate - ~2KB per log entry
            $estimatedSizeKB = $totalLogs * 2;
            $estimatedSizeMB = round($estimatedSizeKB / 1024, 2);

            $this->stdout(PHP_EOL . Craft::t('order-lifecycle', 'Estimated storage: {size} MB', ['size' => $estimatedSizeMB]) . PHP_EOL, BaseConsole::FG_CYAN);

            return ExitCode::OK;
        } catch (Exception $e) {
            $this->stderr(Craft::t('order-lifecycle', 'Error: {message}', ['message' => $e->getMessage()]) . PHP_EOL, BaseConsole::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Vacuums/optimizes the logs table.
     *
     * @return int The exit code.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionOptimize(): int
    {
        $this->stdout(Craft::t('order-lifecycle', 'Optimizing orderlifecycle_logs table...') . PHP_EOL, BaseConsole::FG_YELLOW);

        try {
            $db = Craft::$app->getDb();

            if ($db->getIsMysql()) {
                $db->createCommand("OPTIMIZE TABLE {{%orderlifecycle_logs}}")->execute();
                $this->stdout(Craft::t('order-lifecycle', 'Table optimized successfully.') . PHP_EOL, BaseConsole::FG_GREEN);
            } elseif ($db->getIsPgsql()) {
                // VACUUM can't run inside a transaction - fine here since this
                // console action never opens one
                $db->createCommand("VACUUM ANALYZE {{%orderlifecycle_logs}}")->execute();
                $this->stdout(Craft::t('order-lifecycle', 'Table vacuumed successfully.') . PHP_EOL, BaseConsole::FG_GREEN);
            } else {
                $this->stdout(Craft::t('order-lifecycle', 'Optimization not supported for this database type.') . PHP_EOL, BaseConsole::FG_YELLOW);
            }

            return ExitCode::OK;
        } catch (Exception $e) {
            $this->stderr(Craft::t('order-lifecycle', 'Error: {message}', ['message' => $e->getMessage()]) . PHP_EOL, BaseConsole::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
