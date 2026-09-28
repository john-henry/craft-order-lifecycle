<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\jobs;

use Craft;
use craft\queue\BaseJob;
use johnhenry\orderlifecycle\OrderLifecycle;

/**
 * Deletes old log entries, and entries for orders that no longer exist, in
 * batches on the queue.
 *
 * Runs on the queue rather than in the request that notices it is due, so a
 * large backlog never holds up a shopper's page, and in batches so a table
 * that has grown for years is cleared a slice at a time instead of in one
 * statement that can outlast the request or the connection.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class PruneLogs extends BaseJob
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int How many batches one run deletes before handing on to another.
     */
    public const BATCHES_PER_RUN = 50;

    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int Delete entries older than this many days; 0 leaves old entries alone.
     */
    public int $days = 0;

    /**
     * @var bool Whether to delete the entries of orders that no longer exist.
     */
    public bool $orphaned = false;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $queue The queue the job belongs to.
     * @return void
     * @throws \yii\db\Exception If a delete fails.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function execute($queue): void
    {
        $logger = OrderLifecycle::$plugin->getLogger();
        $deleted = 0;

        if ($this->orphaned) {
            $deleted += $logger->deleteOrphanedLogs();
        }

        if ($this->days > 0) {
            for ($batch = 0; $batch < self::BATCHES_PER_RUN; $batch++) {
                $count = $logger->deleteLogsOlderThan($this->days);
                $deleted += $count;

                if ($count < $logger::PRUNE_BATCH_SIZE) {
                    $this->_report($deleted);

                    return;
                }
            }

            // More left than one run clears: another job takes it from here.
            Craft::$app->getQueue()->push(new self(['days' => $this->days]));
        }

        $this->_report($deleted);
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string|null The default queue job description.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('order-lifecycle', 'Pruning order lifecycle logs');
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Logs how many entries the run deleted.
     *
     * @param int $deleted The number of entries deleted.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _report(int $deleted): void
    {
        if ($deleted > 0) {
            Craft::info("Pruned $deleted order lifecycle log(s)", 'order-lifecycle');
        }
    }
}
