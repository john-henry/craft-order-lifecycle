<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\jobs;

use Craft;
use craft\db\Query;
use craft\queue\BaseJob;
use johnhenry\orderlifecycle\OrderLifecycle;

/**
 * Persists an already-prepared lifecycle log row to the database.
 *
 * This is the job {@see OrderLifecycleLogger::log()} pushes whenever the
 * plugin's asyncLogging setting is on. Unlike {@see LogOrderEventDeferredJob},
 * it does not rebuild anything: the snapshot and the change-description
 * message are both built synchronously by log() before this job is queued,
 * so the timeline stays accurate (no other event for the same order can run
 * in between and change what "the previous snapshot" means). Only the DB
 * write itself, the part with no bearing on message accuracy, is deferred.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class LogOrderEventJob extends BaseJob
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var array The fully-prepared log row, ready to insert as-is.
     */
    public array $logData = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $queue The queue the job belongs to.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function execute($queue): void
    {
        // An order deleted or purged before the job ran takes its timeline
        // with it; writing the entry now would only fail on the order key.
        $orderId = (int)($this->logData['orderId'] ?? 0);
        $exists = (new Query())->from('{{%commerce_orders}}')->where(['id' => $orderId])->exists();

        if (!$exists) {
            return;
        }

        OrderLifecycle::$plugin->getLogger()->insertLogRow($this->logData);
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string|null The default queue job description.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('order-lifecycle', 'Write order lifecycle log entry');
    }
}
