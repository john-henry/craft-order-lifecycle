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

/**
 * Defers an entire log() call, snapshot build included, to the queue.
 *
 * This is the job {@see OrderLifecycleLogger::log()} pushes whenever the
 * plugin's asyncLogging setting is on, and what its email-listener callers
 * (Emails::EVENT_BEFORE_SEND_MAIL / EVENT_AFTER_SEND_MAIL, which run inside
 * Commerce's own SendEmailJob::execute()) always use regardless of that
 * setting. It carries only scalar properties (order ID, event type, payload,
 * message) and re-fetches the order and builds the snapshot itself once it
 * runs, rather than persisting a snapshot already built at dispatch time:
 * building a full order snapshot inline (line items, addresses, discounts,
 * a shipping method lookup) is the expensive part of logging, not the final
 * insert, so anywhere that inline cost would matter (an order save on a
 * busy checkout, or worse, inside another queue job's own execution, where a
 * slow-enough snapshot build can push that job past its time-to-reserve and
 * cause it to be treated as stuck and re-run from scratch, which for
 * Commerce's SendEmailJob means genuinely resending the email) needs the
 * whole operation deferred, not just the write.
 *
 * @author John Henry Donovan
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function execute($queue): void
    {
        $order = Craft::$app->getElements()->getElementById($this->orderId, Order::class);
        if (!$order instanceof Order) {
            return;
        }

        // writeLogNow(), not log() - log() would re-check asyncLogging and
        // just push another deferred job, looping forever
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('order-lifecycle', 'Log order lifecycle event');
    }
}
