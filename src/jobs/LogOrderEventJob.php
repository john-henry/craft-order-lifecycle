<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\jobs;

use Craft;
use craft\queue\BaseJob;
use yii\db\Exception;

/**
 * Writes a single order lifecycle log entry from pre-built data.
 *
 * All snapshot/message/userId/IP data is captured at dispatch time so the
 * job reflects the order state at the moment the event occurred, not when
 * the queue worker processes it.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class LogOrderEventJob extends BaseJob
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
     * @var string|null An optional human-readable message describing the event.
     */
    public ?string $message;

    /**
     * @var string|null The JSON-encoded order snapshot captured at dispatch time.
     */
    public ?string $snapshot;

    /**
     * @var int|null The ID of the user who triggered the event, if known.
     */
    public ?int $userId;

    /**
     * @var string|null The IP address of the user who triggered the event, if collected.
     */
    public ?string $ip;

    /**
     * @var string The creation timestamp for the log row.
     */
    public string $dateCreated;

    /**
     * @var string The update timestamp for the log row.
     */
    public string $dateUpdated;

    /**
     * @var string The UID for the log row.
     */
    public string $uid;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $queue The queue the job belongs to.
     * @return void
     * @throws Exception If the log row cannot be inserted.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function execute($queue): void
    {
        Craft::$app->getDb()->createCommand()
            ->insert('{{%orderlifecycle_logs}}', [
                'orderId' => $this->orderId,
                'type' => $this->type,
                'message' => $this->message,
                'snapshot' => $this->snapshot,
                'userId' => $this->userId,
                'ip' => $this->ip,
                'dateCreated' => $this->dateCreated,
                'dateUpdated' => $this->dateUpdated,
                'uid' => $this->uid,
            ])
            ->execute();
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
