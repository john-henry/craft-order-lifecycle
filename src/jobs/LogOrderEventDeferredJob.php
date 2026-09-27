<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\services\OrderLifecycleLogger;

/**
 * Defers an entire log() call, snapshot build included, to the queue.
 *
 * This is the job {@see OrderLifecycleLogger::logDeferred()} pushes, which the
 * email listeners use: they run inside Commerce's own SendEmailJob, where
 * building a full order snapshot inline could push that job past its
 * time-to-reserve and have Commerce send the email again. It carries only
 * scalar properties and builds the snapshot itself once it runs. (With
 * asyncLogging on, log() pushes {@see LogOrderEventJob} instead, which only
 * defers the write.)
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class LogOrderEventDeferredJob extends BaseJob
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int The ID of the order the event belongs to.
     */
    public int $orderId;

    /**
     * @var string The event type handle.
     */
    public string $type;

    /**
     * @var array The event payload, forwarded to OrderLifecycleLogger::log().
     */
    public array $payload = [];

    /**
     * @var string|null An optional human-readable message describing the event.
     */
    public ?string $message = null;

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
        $order = Craft::$app->getElements()->getElementById($this->orderId, Order::class);
        if (!$order instanceof Order) {
            return;
        }

        // writeLogNow(), not log(): log() would re-check asyncLogging and
        // push a second job just to do the write
        OrderLifecycle::$plugin->getLogger()->writeLogNow(
            $order,
            EventType::from($this->type),
            $this->payload,
            $this->message,
        );
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
        return Craft::t('order-lifecycle', 'Log order lifecycle event');
    }
}
