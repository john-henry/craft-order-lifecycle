<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\services;

use Carbon\Carbon;
use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Query;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTimeImmutable;
use DateTimeZone;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\helpers\Formatter;
use johnhenry\orderlifecycle\jobs\LogOrderEventDeferredJob;
use johnhenry\orderlifecycle\jobs\LogOrderEventJob;
use johnhenry\orderlifecycle\OrderLifecycle;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\db\Exception;

/**
 * Order Lifecycle logger service.
 *
 * Records lifecycle events for Commerce orders, building order snapshots,
 * generating change descriptions and persisting log entries either inline or
 * via the queue.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class OrderLifecycleLogger extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int The most events recorded for one cart before it is completed.
     * Every cart save can add several rows, each with a full snapshot, and a
     * cart can be saved by anyone visiting the shop.
     */
    public const MAX_CART_EVENTS = 500;

    /**
     * @var int How many entries one pruning pass deletes.
     */
    public const PRUNE_BATCH_SIZE = 5000;

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * Snapshot each order had when a save of it began, keyed by order ID.
     * Changes detected after the save are compared against this rather than
     * the latest snapshot, which events fired part way through the save have
     * already moved on.
     *
     * @var array<int, array|null>
     */
    private array $_preSave = [];

    /**
     * Per-request snapshot cache keyed by order ID.
     *
     * Updated on every log() call so that subsequent EVENT_AFTER_SAVE handlers
     * within the same request always diff against the most recently queued
     * snapshot, not the (potentially stale) last row in the database.
     *
     * @var array<int, array>
     */
    private array $_snapshotCache = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Clear the in-request snapshot cache.
     *
     * Pass an order ID to clear that order from both the in-request memo and
     * the persistent cache. Passing null clears the memo only: a cache backend
     * cannot be enumerated by key prefix, so there is nothing to sweep the
     * persistent entries with. Used in tests to simulate a fresh logger without
     * re-registering event handlers.
     *
     * @param int|null $orderId The order ID to clear, or null for the in-request memo alone.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function clearSnapshotCache(?int $orderId = null): void
    {
        if ($orderId === null) {
            $this->_snapshotCache = [];
            $this->_preSave = [];
        } else {
            unset($this->_snapshotCache[$orderId], $this->_preSave[$orderId]);
            Craft::$app->getCache()->delete("ol_snapshot_{$orderId}");
        }
    }

    /**
     * Queues an order lifecycle event to be logged later, snapshot build included.
     *
     * Used by listeners that fire synchronously inside another queue job's own
     * execution (namely Emails::EVENT_BEFORE_SEND_MAIL / EVENT_AFTER_SEND_MAIL,
     * which run inside Commerce's SendEmailJob::execute()), unconditionally,
     * regardless of the asyncLogging setting: building a full order snapshot
     * inline there (line items, addresses, discounts, a shipping method lookup)
     * ties this plugin's logging cost to that job's time-to-reserve, and if it
     * runs long enough to exceed the TTR, the queue considers the job stuck and
     * re-runs it from scratch, which for SendEmailJob means Commerce genuinely
     * resends the email. Pushing this job instead keeps the caller down to a
     * single lightweight queue push, so it can never be the reason a mail job
     * times out. Not used by log() itself: those events depend on an accurate
     * diff against the actual previous snapshot, so their message is generated
     * synchronously instead (see log()'s docblock), and email events never use
     * a diffed message in the first place.
     *
     * @param int $orderId The ID of the order to log the event for.
     * @param EventType $type The type of lifecycle event.
     * @param array $payload Additional data to include in the snapshot.
     * @param string|null $message Optional custom message (auto-generated if null).
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function logDeferred(int $orderId, EventType $type, array $payload = [], ?string $message = null): void
    {
        Craft::$app->getQueue()->push(new LogOrderEventDeferredJob([
            'orderId' => $orderId,
            'type' => $type->value,
            'payload' => $payload,
            'message' => $message,
        ]));
    }

    /**
     * Logs an order lifecycle event.
     *
     * The snapshot is built and the change-description message generated
     * synchronously either way: those depend on diffing against the actual
     * previous snapshot at the moment the event happens, and the timeline
     * message is the plugin's main point, so it can't be left to run later
     * in a queue job that might execute after some other event for this
     * order has already changed what "the previous snapshot" means. When
     * asyncLogging is on, only the DB write itself, the part with no
     * bearing on message accuracy, is deferred via LogOrderEventJob.
     *
     * @param Order $order The order to log the event for.
     * @param EventType $type The type of lifecycle event.
     * @param array $payload Additional data to include in the snapshot.
     * @param string|null $message Optional custom message (auto-generated if null).
     * @return void
     * @throws Exception If the log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function log(Order $order, EventType $type, array $payload = [], ?string $message = null): void
    {
        $this->_log($order, $type, $payload, $message);
    }

    /**
     * Notes the snapshot an order has as a save of it begins.
     *
     * Commerce fires the line-item and status events from inside the save, and
     * logging those moves the order's latest snapshot on to the saved state.
     * The changes detected once the save ends are compared against the state
     * noted here instead, so a coupon or address changed in the same save is
     * still seen. A nested save keeps the note taken by the save around it.
     *
     * @param Order $order The order about to be saved.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function beginOrderSave(Order $order): void
    {
        if (!$order->id) {
            return;
        }

        $orderId = (int)$order->id;

        if (!array_key_exists($orderId, $this->_preSave)) {
            $this->_preSave[$orderId] = $this->getLastSnapshot($orderId);
        }
    }

    /**
     * Logs an order lifecycle event, comparing against a given snapshot.
     *
     * @param Order $order The order to log the event for.
     * @param EventType $type The type of lifecycle event.
     * @param array $payload Additional data to include in the snapshot.
     * @param string|null $message Optional custom message (auto-generated if null).
     * @param array|null $previous The snapshot to describe the change against, or null for the latest.
     * @return void
     * @throws Exception If the log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _log(Order $order, EventType $type, array $payload = [], ?string $message = null, ?array $previous = null): void
    {
        $logData = $this->_prepareLogData($order, $type, $payload, $message, $previous);

        if (OrderLifecycle::$plugin->getSettings()->asyncLogging) {
            Craft::$app->getQueue()->push(new LogOrderEventJob(['logData' => $logData]));
            return;
        }

        $this->insertLogRow($logData);
    }

    /**
     * Builds an order snapshot and persists a log row immediately, ignoring
     * the asyncLogging setting entirely.
     *
     * Used by LogOrderEventDeferredJob, whose whole purpose is to build the
     * snapshot itself once it runs, on the queue, rather than at dispatch
     * time (see logDeferred()'s docblock for why).
     *
     * @param Order $order The order to log the event for.
     * @param EventType $type The type of lifecycle event.
     * @param array $payload Additional data to include in the snapshot.
     * @param string|null $message Optional custom message (auto-generated if null).
     * @return void
     * @throws Exception If the log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function writeLogNow(Order $order, EventType $type, array $payload = [], ?string $message = null): void
    {
        $this->insertLogRow($this->_prepareLogData($order, $type, $payload, $message));
    }

    /**
     * Inserts an already-prepared log row as-is.
     *
     * Shared by the synchronous path and LogOrderEventJob, the queue job
     * log() pushes when asyncLogging is on, so there's a single source of
     * truth for the actual insert.
     *
     * @param array $logData The prepared log row (see _prepareLogData()).
     * @return void
     * @throws Exception If the log row cannot be inserted.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function insertLogRow(array $logData): void
    {
        $db = Craft::$app->getDb();

        if ($db->getTransaction() === null) {
            $db->createCommand()->insert('{{%orderlifecycle_logs}}', $logData)->execute();

            return;
        }

        // Inside the order's own save, so the insert runs in a savepoint: a
        // failed insert rolls back only itself, where on PostgreSQL it would
        // otherwise abort the whole transaction and fail the order save that
        // follows. A deadlock is different: the database has already rolled
        // the whole transaction back, so it is thrown on for the save to fail
        // as it truly has.
        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()->insert('{{%orderlifecycle_logs}}', $logData)->execute();
            $transaction->commit();
        } catch (Throwable $e) {
            if (!self::isDeadlock($e)) {
                $transaction->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Whether a database error is a deadlock or serialisation failure, after
     * which the database has already rolled the whole transaction back.
     *
     * @param Throwable $e The error.
     * @return bool
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function isDeadlock(Throwable $e): bool
    {
        $info = $e instanceof Exception ? $e->errorInfo : [];

        return in_array($info[0] ?? null, ['40001', '40P01'], true) || ($info[1] ?? null) === 1213;
    }

    /**
     * Get all lifecycle logs for a specific order
     *
     * Returns an array of log entries ordered by creation date (most recent first).
     *
     * @param int $orderId The order ID to fetch logs for.
     * @return array Array of log records.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getLogsForOrder(int $orderId): array
    {
        $rows = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $orderId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->all();

        return array_map(static function(array $row) {
            $row['date'] = self::rowDate($row['dateCreated']);

            return $row;
        }, $rows);
    }

    /**
     * Turns a stored log time into a date in Craft's timezone.
     *
     * Log times are stored as UTC with no zone attached, like every other
     * date Craft writes, so they are read as UTC here. Handing Twig the
     * stored string instead would read it in the viewer's own timezone.
     *
     * @param string $value A dateCreated or dateUpdated value as stored.
     * @return Carbon The same moment in Craft's timezone.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function rowDate(string $value): Carbon
    {
        return Carbon::parse($value, 'UTC')->setTimezone(Craft::$app->getTimeZone());
    }

    /**
     * The current time in the form log rows are stored in: UTC, with
     * microseconds so events in the same second keep their order.
     *
     * @return string
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    /**
     * Whether an order belongs to a guest rather than a registered customer.
     *
     * Commerce gives every order with an email a user, creating an inactive
     * one for a guest, so having a customer says nothing. A registered
     * customer is one whose account can be signed into, the same test
     * Commerce applies before saving addresses to an account.
     *
     * @param Order $order The order to check.
     * @return bool
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.1
     */
    public static function isGuest(Order $order): bool
    {
        $customer = $order->getCustomer();

        return $customer === null || !$customer->getIsCredentialed();
    }

    /**
     * Returns the timeline entries for an order: lifecycle logs (excluding
     * AI insights) enriched with their filter pill, the number of seconds
     * elapsed since the previous chronological event, which top-level order
     * snapshot fields changed since that previous event, the decoded snapshot
     * itself and the id of the transaction its payload names, if any.
     *
     * Entries are newest-first, matching {@see getLogsForOrder()}. The oldest
     * entry has no previous event to compare against, so its `deltaSeconds`
     * is null and its `orderChanges` diffs against an empty baseline, listing
     * the order's initial values.
     *
     * Each row gains:
     *
     * - `pill` - the filter pill the event shows under
     * - `label` - the event type's readable label
     * - `deltaSeconds` - seconds since the previous event, null for the oldest
     * - `orderChanges` - the field-by-field diff against the previous event
     * - `snapshotData` - the decoded snapshot, null where it is absent or malformed
     * - `transactionId` - the transaction the payload names, null where it names none
     *
     * @param int $orderId The order ID to build the timeline for.
     * @param array|null $logs The order's log rows, newest-first, where the
     *                         caller already has them. Every row carries its
     *                         snapshot, so a caller that shows both the raw
     *                         logs and the timeline would otherwise read the
     *                         whole lot twice for the one page.
     * @return array The enriched, newest-first timeline entries.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getTimelineForOrder(int $orderId, ?array $logs = null): array
    {
        $logs = array_values(array_filter(
            $logs ?? $this->getLogsForOrder($orderId),
            static fn(array $log) => $log['type'] !== EventType::AI_INSIGHTS->value
        ));

        // Decoded once per row and carried on the row itself. Everything but
        // the oldest entry gets diffed against its neighbour, so decoding
        // lazily would do it twice here; and the order edit screen reaches into
        // the same snapshot half a dozen times per event to draw one card,
        // which was six more parses of the same JSON apiece.
        $snapshots = array_map(
            fn(array $log) => $this->_decodeSnapshot($log['snapshot'] ?? null),
            $logs
        );

        $addressEventTypes = [
            EventType::SHIPPING_ADDRESS_SET,
            EventType::SHIPPING_ADDRESS_REMOVED,
            EventType::BILLING_ADDRESS_SET,
            EventType::BILLING_ADDRESS_REMOVED,
        ];

        $timeline = [];

        foreach ($logs as $index => $log) {
            $previous = $logs[$index + 1] ?? null;

            $snapshot = $snapshots[$index];
            $payload = is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : null;

            $log['snapshotData'] = $snapshot;
            $log['transactionId'] = isset($payload['transactionId']) ? (int)$payload['transactionId'] : null;
            $log['pill'] = EventType::tryFrom($log['type'])?->getFilterPill() ?? 'other';
            $log['label'] = EventType::tryFrom($log['type'])?->getLabel() ?? $log['type'];
            $log['deltaSeconds'] = $previous !== null
                ? max(0, self::rowDate($log['dateCreated'])->getTimestamp() - self::rowDate($previous['dateCreated'])->getTimestamp())
                : null;

            if (in_array(EventType::tryFrom($log['type']), $addressEventTypes, true)) {
                // address events carry their own before/after in the payload -
                // more reliable than the order-snapshot diff, which goes blank
                // when billing and shipping are both saved in the same request
                $after = $payload['after'] ?? null;
                $before = $payload['before'] ?? null;

                if ($after !== null) {
                    $log['orderChanges'] = $this->_diffFields($after, $before, [Formatter::class, 'addressFieldLabel']);
                } elseif ($before !== null) {
                    // diff against an empty baseline then flip from/to, so a
                    // removed address reads as "value -> null" not "null -> value"
                    $log['orderChanges'] = array_map(
                        static fn(array $change) => [
                            'field' => $change['field'],
                            'label' => $change['label'],
                            'from' => $change['to'],
                            'to' => $change['from'],
                        ],
                        $this->_diffFields($before, null, [Formatter::class, 'addressFieldLabel'])
                    );
                } else {
                    $log['orderChanges'] = [];
                }
            } else {
                // oldest entry has nothing to diff against, so every field
                // shows as "null -> value" - the order's initial state
                $log['orderChanges'] = $this->_diffOrderSnapshot(
                    self::_orderSection($snapshot),
                    self::_orderSection($snapshots[$index + 1] ?? null),
                );
            }

            $timeline[] = $log;
        }

        return $timeline;
    }

    /**
     * Get the most recent snapshot for an order
     *
     * Retrieves the snapshot data from the last log entry for the specified order.
     * Returns null if no logs exist or if the snapshot is invalid.
     *
     * @param int $orderId The order ID to fetch the snapshot for.
     * @return array|null The decoded snapshot array, or null if not found.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getLastSnapshot(int $orderId): ?array
    {
        // in-request cache first, for repeat saves within the same request
        if (isset($this->_snapshotCache[$orderId])) {
            return $this->_snapshotCache[$orderId];
        }

        // then the persistent cache, since async queue jobs may not have
        // written the DB row yet
        $cached = Craft::$app->getCache()->get("ol_snapshot_{$orderId}");
        if (is_array($cached)) {
            $this->_snapshotCache[$orderId] = $cached;
            return $cached;
        }

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $orderId])
            ->andWhere(['not', ['type' => EventType::AI_INSIGHTS->value]])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(1)
            ->one();
        if (!$row || empty($row['snapshot'])) {
            return null;
        }
        try {
            return Json::decodeIfJson($row['snapshot']);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Generate a human-readable description of changes between snapshots
     *
     * Compares two order snapshots and generates a readable description of what changed,
     * including line items, pricing, customer info, shipping, and status changes.
     *
     * @param array $previous The previous order snapshot.
     * @param array $current The current order snapshot.
     * @param EventType $type The event type to determine which changes to check.
     * @return string|null A description of changes, or null if no relevant changes.
     * @throws InvalidConfigException If a formatter dependency cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function generateChangeDescription(array $previous, array $current, EventType $type): ?string
    {
        $changes = [];

        // Order completed: always the same fixed message, not a snapshot diff
        if ($type === EventType::ORDER_COMPLETED) {
            return Craft::t('order-lifecycle', 'Cart converted to order');
        }

        // Coupon applied/removed - their type value doesn't match the
        // cart/order prefix check below, so they need their own branch
        if ($type === EventType::COUPON_APPLIED || $type === EventType::COUPON_REMOVED) {
            return Formatter::couponChange(
                $previous['order']['couponCode'] ?? null,
                $current['order']['couponCode'] ?? null
            );
        }

        // Order paid: show amount and gateway
        if ($type === EventType::ORDER_PAID) {
            $amount = $current['payload']['totalPaid'] ?? null;
            $currency = $current['payload']['currency'] ?? ($current['order']['currency'] ?? null);
            $gateway = $current['payload']['gatewayName'] ?? null;

            if ($amount !== null && $currency !== null) {
                $formatted = Craft::$app->getFormatter()->asCurrency((float)$amount, strtoupper($currency));
                $message = $gateway
                    ? Craft::t('order-lifecycle', '{amount} paid via {gateway}', ['amount' => $formatted, 'gateway' => Formatter::sanitize($gateway)])
                    : Craft::t('order-lifecycle', '{amount} paid', ['amount' => $formatted]);
                return $message;
            }
        }

        // Payment processed: show amount, gateway and outcome
        if ($type === EventType::PAYMENT_PROCESSED) {
            $amount = $current['payload']['amount'] ?? null;
            $currency = $current['payload']['currency'] ?? ($current['order']['currency'] ?? null);
            $gateway = $current['payload']['gatewayName'] ?? null;
            $success = $current['payload']['success'] ?? null;

            if ($amount !== null && $currency !== null) {
                $formatted = Craft::$app->getFormatter()->asCurrency((float)$amount, strtoupper($currency));
                $outcome = $success === false
                    ? Craft::t('order-lifecycle', 'failed')
                    : Craft::t('order-lifecycle', 'succeeded');

                return $gateway
                    ? Craft::t('order-lifecycle', '{amount} via {gateway} - {outcome}', [
                        'amount' => $formatted,
                        'gateway' => Formatter::sanitize($gateway),
                        'outcome' => $outcome,
                    ])
                    : Craft::t('order-lifecycle', '{amount} - {outcome}', ['amount' => $formatted, 'outcome' => $outcome]);
            }
        }

        // Payment captured: show the amount, and say so when it failed
        if ($type === EventType::PAYMENT_CAPTURED) {
            $amount = $current['payload']['amount'] ?? null;
            $currency = $current['payload']['currency'] ?? ($current['order']['currency'] ?? null);

            if ($amount !== null && $currency !== null) {
                $formatted = Craft::$app->getFormatter()->asCurrency((float)$amount, strtoupper($currency));

                return ($current['payload']['success'] ?? true) === false
                    ? Craft::t('order-lifecycle', 'Capture of {amount} failed', ['amount' => $formatted])
                    : Craft::t('order-lifecycle', '{amount} captured', ['amount' => $formatted]);
            }
        }

        // Payment refunded: show the amount refunded and via which gateway
        if ($type === EventType::PAYMENT_REFUNDED) {
            $amount = $current['payload']['amount'] ?? null;
            $currency = $current['payload']['currency'] ?? ($current['order']['currency'] ?? null);
            $gateway = $current['payload']['gatewayName'] ?? null;

            if ($amount !== null && $currency !== null) {
                $formatted = Craft::$app->getFormatter()->asCurrency((float)$amount, strtoupper($currency));

                if (($current['payload']['success'] ?? true) === false) {
                    return $gateway
                        ? Craft::t('order-lifecycle', 'Refund of {amount} via {gateway} failed', [
                            'amount' => $formatted,
                            'gateway' => Formatter::sanitize($gateway),
                        ])
                        : Craft::t('order-lifecycle', 'Refund of {amount} failed', ['amount' => $formatted]);
                }

                return $gateway
                    ? Craft::t('order-lifecycle', '{amount} refunded via {gateway}', [
                        'amount' => $formatted,
                        'gateway' => Formatter::sanitize($gateway),
                    ])
                    : Craft::t('order-lifecycle', '{amount} refunded', ['amount' => $formatted]);
            }
        }

        // same phrasing for sent/failed - the title already covers success
        if ($type === EventType::EMAIL_SENT || $type === EventType::EMAIL_FAILED) {
            $name = $current['payload']['name'] ?? null;

            if ($name === null) {
                return null;
            }

            $to = $current['payload']['to'] ?? null;
            $recipient = is_array($to) ? implode(', ', $to) : $to;

            if ($recipient !== null && $recipient !== '') {
                return Craft::t('order-lifecycle', '{name} → {recipient}', [
                    'name' => Formatter::sanitize($name),
                    'recipient' => Formatter::sanitize((string)$recipient),
                ]);
            }

            return Formatter::sanitize($name);
        }

        if ($type === EventType::LINE_ITEM_ADDED || $type === EventType::LINE_ITEM_REMOVED || $type === EventType::LINE_ITEM_UPDATED) {
            $changes = array_merge($changes, Formatter::compareLineItems(
                $previous['lineItems'] ?? [],
                $current['lineItems'] ?? []
            ));
        }

        if (str_starts_with($type->value, 'cart') || str_starts_with($type->value, 'order')) {
            $prevQty = $previous['order']['totalQty'] ?? 0;
            $currQty = $current['order']['totalQty'] ?? 0;
            if ($prevQty !== $currQty) {
                $changes[] = Formatter::quantityChange($prevQty, $currQty);
            }

            $prevPrice = $previous['order']['totalPrice'] ?? 0;
            $currPrice = $current['order']['totalPrice'] ?? 0;
            if (round((float)$prevPrice, 2) !== round((float)$currPrice, 2)) {
                $changes[] = Formatter::priceChange($prevPrice, $currPrice, $current['order']['currency']);
            }

            $prevCoupon = $previous['order']['couponCode'] ?? null;
            $currCoupon = $current['order']['couponCode'] ?? null;
            $couponMessage = Formatter::couponChange($prevCoupon, $currCoupon);
            if ($couponMessage) {
                $changes[] = $couponMessage;
            }

            $prevStatus = $previous['order']['statusHandle'] ?? null;
            $currStatus = $current['order']['statusHandle'] ?? null;
            $statusMessage = Formatter::statusChange($prevStatus, $currStatus);
            if ($statusMessage) {
                $changes[] = $statusMessage;
            }
        }

        if ($type === EventType::CUSTOMER_SET || $type === EventType::CUSTOMER_REMOVED) {
            $prevEmail = $previous['customer']['email'] ?? null;
            $currEmail = $current['customer']['email'] ?? null;
            $customerType = ($current['customer']['isGuest'] ?? true) ? 'guest' : 'customer';

            $customerMessage = Formatter::customerChange($prevEmail, $currEmail, $customerType);
            if ($customerMessage) {
                $changes[] = $customerMessage;
            }
        }

        if ($type === EventType::SHIPPING_METHOD_SET) {
            $prevMethod = $previous['order']['shippingMethodName'] ?? null;
            $currMethod = $current['order']['shippingMethodName'] ?? null;
            $shippingMessage = Formatter::shippingMethodChange($prevMethod, $currMethod);
            if ($shippingMessage) {
                $changes[] = $shippingMessage;
            }

            $prevCountry = $previous['addresses']['shipping']['countryCode'] ?? null;
            $currCountry = $current['addresses']['shipping']['countryCode'] ?? null;
            $countryMessage = Formatter::countryChange($prevCountry, $currCountry);
            if ($countryMessage) {
                $changes[] = $countryMessage;
            }
        }

        if ($type === EventType::BILLING_ADDRESS_SET || $type === EventType::BILLING_ADDRESS_REMOVED) {
            $prevCountry = $previous['addresses']['billing']['countryCode'] ?? null;
            $currCountry = $current['addresses']['billing']['countryCode'] ?? null;
            $countryMessage = Formatter::countryChange($prevCountry, $currCountry, 'billing');
            if ($countryMessage) {
                $changes[] = $countryMessage;
            }
        }

        return !empty($changes) ? implode('; ', $changes) : null;
    }


    /**
     * Get the most recent log entry for a specific order and event type
     *
     * Useful for checking if an event has already been logged or for updating
     * existing log entries (e.g., retry attempts).
     *
     * @param int $orderId The order ID.
     * @param EventType $type The event type to search for.
     * @return array|null The log record, or null if not found.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getLastLogForOrderAndType(int $orderId, EventType $type): ?array
    {
        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $orderId, 'type' => $type->value])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(1)
            ->one();

        return $row ?: null;
    }

    /**
     * Delete a log entry by ID.
     *
     * @param int $logId The log entry ID to delete.
     * @return void
     * @throws Exception If the delete command fails.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function deleteLog(int $logId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%orderlifecycle_logs}}', ['id' => $logId])
            ->execute();
    }

    /**
     * Deletes all log entries for a given order.
     *
     * @param int $orderId The order ID whose logs should be deleted.
     * @return void
     * @throws Exception If the delete command fails.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function deleteLogsForOrder(int $orderId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%orderlifecycle_logs}}', ['orderId' => $orderId])
            ->execute();
    }

    /**
     * Logs a Commerce transaction state change against its order.
     *
     * @param \craft\commerce\models\Transaction $transaction The transaction that was saved.
     * @return void
     * @throws Exception If the log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function logTransaction(\craft\commerce\models\Transaction $transaction): void
    {
        $order = $transaction->getOrder();

        $logData = [
            'transactionId' => $transaction->id,
            'type' => $transaction->type,
            'status' => $transaction->status,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            // getGateway() returns null for offline/manual transactions.
            'gateway' => $transaction->getGateway()?->name,
            'reference' => $transaction->reference,
        ];

        $message = null;
        if ($transaction->status === TransactionRecord::STATUS_FAILED) {
            $logData['code'] = $transaction->code;
            $logData['message'] = $transaction->message;
            $message = Craft::t('order-lifecycle', 'Payment {type} failed: {reason}', [
                'type' => Formatter::sanitize((string)$transaction->type),
                'reason' => Formatter::sanitize((string)$transaction->message),
            ]);
        } elseif ($transaction->status === TransactionRecord::STATUS_SUCCESS) {
            $message = Craft::t('order-lifecycle', 'Payment {type} succeeded', [
                'type' => Formatter::sanitize((string)$transaction->type),
            ]);
        }

        $this->log($order, EventType::PAYMENT_TRANSACTION, $logData, $message);
    }

    /**
     * Update an existing log entry
     *
     * Used for consolidating retry attempts or updating log information
     * without creating duplicate entries.
     *
     * @param int $logId The log entry ID to update.
     * @param array $updates The fields to update (column => value pairs).
     * @return void
     * @throws Exception If the update command fails.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function updateLog(int $logId, array $updates): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%orderlifecycle_logs}}', array_merge($updates, [
                'dateUpdated' => self::now(),
            ]), ['id' => $logId])
            ->execute();
    }

    /**
     * Log checkout started event
     *
     * Records when a customer visits the checkout page. Only logs once per order
     * to avoid duplicate entries. Call this from your checkout template using:
     * {% do craft.orderLifecycle.logCheckoutStarted(cart) %}
     *
     * @param Order $order The order (cart) to log the checkout event for.
     * @return bool True if logged successfully, false if already logged.
     * @throws Exception If the log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function logCheckoutStarted(Order $order): bool
    {
        // A cart Commerce hasn't saved yet has nothing to log against.
        if (!$order->id) {
            return false;
        }

        $existingCheckout = $this->getLastLogForOrderAndType($order->id, EventType::CHECKOUT_STARTED);

        if ($existingCheckout) {
            return false;
        }

        $message = self::isGuest($order)
            ? Craft::t('order-lifecycle', 'Guest customer visited checkout page')
            : Craft::t('order-lifecycle', 'Registered customer visited checkout page');

        $this->log($order, EventType::CHECKOUT_STARTED, [
            'email' => $order->email,
            'totalPrice' => $order->getTotalPrice(),
            'totalQty' => $order->getTotalQty(),
            'currency' => $order->currency,
        ], $message);

        return true;
    }

    /**
     * Logs the granular changes detected on an order save.
     *
     * On the first save this records CART_CREATED. On subsequent saves it diffs
     * the current order against the last snapshot and records coupon, address,
     * customer, shipping-method and line-item changes according to the plugin
     * settings. When no previous snapshot exists it falls back to CART_UPDATED.
     *
     * @param Order $order The order that was saved.
     * @param bool $isNew Whether this was the order's first save.
     * @return void
     * @throws Exception If a log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function logOrderSaveChanges(Order $order, bool $isNew): void
    {
        $settings = OrderLifecycle::$plugin->getSettings();
        $orderId = (int)$order->id;

        // The snapshot noted as the save began, if one was: events logged
        // during the save have moved the latest snapshot on already.
        $preSave = $this->_preSave[$orderId] ?? null;
        unset($this->_preSave[$orderId]);

        if (!$order->isCompleted && $this->_cartEventCount($orderId) >= self::MAX_CART_EVENTS) {
            return;
        }

        if ($isNew) {
            // no previous snapshot yet, so generateChangeDescription() won't
            // run for this event - pass the message explicitly instead
            $message = self::isGuest($order)
                ? Craft::t('order-lifecycle', 'Cart initialized for guest customer')
                : Craft::t('order-lifecycle', 'Cart initialized for registered customer');
            $this->log($order, EventType::CART_CREATED, [], $message);
        }

        $previous = $preSave ?? $this->getLastSnapshot($orderId);

        if ($previous === null) {
            if (!$isNew) {
                $this->log($order, EventType::CART_UPDATED);
            }

            return;
        }

        if ($settings->logCouponChanges) {
            $this->_logCouponChange($order, $previous);
        }

        if ($settings->logAddressChanges) {
            $this->_logAddressChanges($order, $previous);
        }

        if ($settings->logCustomerChanges) {
            $this->_logCustomerChange($order, $previous);
        }

        if ($settings->logShippingMethodChanges) {
            $this->_logShippingMethodChange($order, $previous);
        }

        if ($settings->logLineItems && isset($previous['lineItems'])) {
            $this->_logLineItemQuantityChanges($order, $previous);
        }
    }

    /**
     * Logs a failed Commerce SendEmail queue job against its order.
     *
     * Consolidates retries of the same email within a five-minute window into a
     * single log entry rather than creating a new EMAIL_FAILED row per attempt.
     *
     * @param int|null $orderId The order ID carried by the failed job.
     * @param int|null $emailId The Commerce email ID carried by the failed job.
     * @param int $attempt The attempt number reported by the queue.
     * @param string|null $jobId The queue job ID, if known.
     * @param Throwable|null $error The error that caused the job to fail.
     * @return void
     * @throws Exception If a log row cannot be inserted or updated.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function logFailedEmailJob(
        ?int $orderId,
        ?int $emailId,
        int $attempt,
        ?string $jobId,
        ?Throwable $error,
    ): void {
        $order = $orderId !== null ? Order::find()->id($orderId)->status(null)->one() : null;

        if (!$order instanceof Order) {
            return;
        }

        $emailName = null;
        if ($emailId !== null) {
            // Commerce 5 emails belong to a store
            $email = Commerce::getInstance()->getEmails()->getEmailById($emailId, $order->storeId);
            $emailName = $email->name ?? null;
        }

        $errorMsg = Formatter::sanitize($error?->getMessage() ?? 'Unknown error');
        $errorClass = $error !== null ? get_class($error) : null;
        $existingLog = $this->getLastLogForOrderAndType($order->id, EventType::EMAIL_FAILED);

        if ($this->_isSameFailedEmail($existingLog, $emailId)) {
            $snapshot = Json::decodeIfJson($existingLog['snapshot']);
            $snapshot['payload']['attempt'] = $attempt;
            $snapshot['payload']['lastError'] = $errorMsg;
            $snapshot['payload']['lastErrorClass'] = $errorClass;

            $this->updateLog((int)$existingLog['id'], [
                'message' => Craft::t('order-lifecycle', 'Email queue job failed after {attempt} attempt(s): {error}', ['attempt' => $attempt, 'error' => $errorMsg]),
                'snapshot' => Json::encode($snapshot),
            ]);

            return;
        }

        $message = $attempt > 1
            ? Craft::t('order-lifecycle', 'Email queue job failed after {attempt} attempt(s): {error}', ['attempt' => $attempt, 'error' => $errorMsg])
            : Craft::t('order-lifecycle', 'Email queue job failed: {error}', ['error' => $errorMsg]);

        $this->log($order, EventType::EMAIL_FAILED, [
            'emailId' => $emailId,
            'emailName' => $emailName,
            'orderNumber' => $order->number,
            'orderReference' => $order->reference,
            'error' => $errorMsg,
            'errorClass' => $errorClass,
            'attempt' => $attempt,
            'jobId' => $jobId,
        ], $message);
    }

    /**
     * The entries of orders that no longer exist.
     *
     * A trashed order is not an orphan: it is restorable for as long as the
     * soft-delete duration allows, and it has to come back with its timeline
     * intact. Craft's own garbage collection removes the elements row once that
     * window closes, and the next pass takes the logs with it.
     *
     * Shared by the delete below and the console command's dry run, so the
     * count it shows is what would go.
     *
     * @return Query The matching log rows.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function orphanedLogsQuery(): Query
    {
        return (new Query())
            ->from(['l' => '{{%orderlifecycle_logs}}'])
            ->leftJoin(['e' => '{{%elements}}'], '[[e.id]] = [[l.orderId]]')
            ->where(['e.id' => null]);
    }

    /**
     * Deletes logs for orders that no longer exist.
     *
     * @return int The number of log rows deleted.
     * @throws Exception If the delete command fails.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function deleteOrphanedLogs(): int
    {
        $deleted = 0;

        do {
            $ids = $this->orphanedLogsQuery()
                ->select(['l.id'])
                ->limit(self::PRUNE_BATCH_SIZE)
                ->column();

            if ($ids === []) {
                break;
            }

            $deleted += Craft::$app->getDb()->createCommand()
                ->delete('{{%orderlifecycle_logs}}', ['id' => $ids])
                ->execute();
        } while (count($ids) === self::PRUNE_BATCH_SIZE);

        return $deleted;
    }

    /**
     * The condition matching entries older than a number of days.
     *
     * @param int $days The age in days.
     * @return array A query condition.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function olderThanCondition(int $days): array
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("-$days days");

        return ['<', 'dateCreated', $cutoff->format('Y-m-d H:i:s')];
    }

    /**
     * Deletes one batch of entries older than a number of days.
     *
     * @param int $days The age in days; entries older than this go.
     * @return int How many were deleted: fewer than a full batch means none are left.
     * @throws Exception If the delete fails.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function deleteLogsOlderThan(int $days): int
    {
        $ids = (new Query())
            ->select(['id'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(self::olderThanCondition($days))
            ->orderBy(['id' => SORT_ASC])
            ->limit(self::PRUNE_BATCH_SIZE)
            ->column();

        if ($ids === []) {
            return 0;
        }

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%orderlifecycle_logs}}', ['id' => $ids])
            ->execute();
    }

    /**
     * The log rows holding one customer's or one order's details.
     *
     * By email, it takes every order placed with that address, plus any log
     * that recorded the address itself (an order whose email was changed
     * away from it still holds the old one in its history).
     *
     * @param string|null $email A customer email address.
     * @param int|null $orderId An order ID.
     * @return Query The matching log rows.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function customerLogsQuery(?string $email, ?int $orderId): Query
    {
        $query = (new Query())->from('{{%orderlifecycle_logs}}');
        $conditions = ['or'];

        if ($orderId !== null) {
            $conditions[] = ['orderId' => $orderId];
        }

        if ($email !== null && $email !== '') {
            $orderIds = (new Query())
                ->select(['id'])
                ->from('{{%commerce_orders}}')
                ->where(['email' => $email]);
            $conditions[] = ['orderId' => $orderIds];
            $conditions[] = ['like', 'snapshot', Json::encode($email)];
        }

        return $query->where(count($conditions) > 1 ? $conditions : '0=1');
    }

    /**
     * Deletes the log rows holding one customer's or one order's details,
     * for a request to erase them.
     *
     * The rows are deleted rather than edited: a snapshot keeps the customer's
     * details in several places, and a history with one detail blanked out
     * still identifies them by the rest.
     *
     * @param string|null $email A customer email address.
     * @param int|null $orderId An order ID.
     * @return int How many rows were deleted.
     * @throws Exception If the delete fails.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function eraseLogs(?string $email, ?int $orderId): int
    {
        $ids = $this->customerLogsQuery($email, $orderId)->select(['id'])->column();

        if ($ids === []) {
            return 0;
        }

        foreach (array_unique(array_map('intval', (new Query())
            ->select(['orderId'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['id' => $ids])
            ->column())) as $affectedOrderId) {
            $this->clearSnapshotCache($affectedOrderId);
        }

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%orderlifecycle_logs}}', ['id' => $ids])
            ->execute();
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Builds an order snapshot, generates a change-description message and
     * assembles the log row ready for insertion, updating the snapshot
     * caches along the way.
     *
     * Always synchronous: message generation needs to diff against the
     * actual previous snapshot at the moment of the event, not whatever
     * happens to be the "last" snapshot whenever a deferred job eventually
     * runs.
     *
     * @param Order $order The order to log the event for.
     * @param EventType $type The type of lifecycle event.
     * @param array $payload Additional data to include in the snapshot.
     * @param string|null $message Optional custom message (auto-generated if null).
     * @param array|null $previous The snapshot to describe the change against, or null for the latest.
     * @return array The prepared log row, ready to pass to insertLogRow().
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _prepareLogData(Order $order, EventType $type, array $payload, ?string $message, ?array $previous = null): array
    {
        $settings = OrderLifecycle::$plugin->getSettings();
        $orderId = (int)$order->id;

        // grab this before we touch the cache below, or we'd diff against ourselves
        $previousSnapshot = $previous ?? $this->getLastSnapshot($orderId);

        $currentSnapshot = $this->_buildSnapshot($order, $payload);

        if ($message === null && $previousSnapshot !== null) {
            $message = $this->generateChangeDescription($previousSnapshot, $currentSnapshot, $type);
        }

        $now = self::now();

        // getUserIP() only exists on the web request; when this runs on the
        // queue (LogOrderEventDeferredJob, or any listener firing inside another
        // queue job) the request is a console request with no client IP to
        // capture, so record null rather than calling a method that isn't there.
        $request = Craft::$app->getRequest();

        $logData = [
            'orderId' => $orderId,
            'storeId' => $order->storeId,
            'type' => $type->value,
            'message' => $message,
            'snapshot' => Json::encode($currentSnapshot),
            'userId' => $settings->collectUserId ? Craft::$app->getUser()->getId() : null,
            'ip' => $settings->collectUserIp && !$request->getIsConsoleRequest() ? $request->getUserIP() : null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ];

        $this->_snapshotCache[$orderId] = $currentSnapshot;
        Craft::$app->getCache()->set("ol_snapshot_{$orderId}", $currentSnapshot, 7200);

        return $logData;
    }

    /**
     * Decodes a log's JSON snapshot.
     *
     * @param string|null $snapshot The log's JSON snapshot.
     * @return array|null The decoded snapshot, or null if absent or malformed.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _decodeSnapshot(?string $snapshot): ?array
    {
        if ($snapshot === null || $snapshot === '') {
            return null;
        }

        try {
            $decoded = Json::decode($snapshot);
        } catch (InvalidArgumentException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Picks the top-level `order` section out of a decoded snapshot.
     *
     * @param array|null $snapshot The decoded snapshot.
     * @return array|null The `order` section, or null where it is absent or not an array.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private static function _orderSection(?array $snapshot): ?array
    {
        $order = $snapshot['order'] ?? null;

        return is_array($order) ? $order : null;
    }

    /**
     * Diffs two decoded `order` snapshot sections.
     *
     * @param array|null $current The current log's decoded `order` section.
     * @param array|null $previous The previous chronological log's decoded `order` section, or null if there isn't one.
     * @return array A list of ['field' => ..., 'label' => ..., 'from' => ..., 'to' => ...] entries for changed fields.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _diffOrderSnapshot(?array $current, ?array $previous): array
    {
        $changes = $this->_diffFields($current, $previous, [Formatter::class, 'orderFieldLabel']);

        // Cart-wide totals read as a summary rather than the specific thing
        // that changed, so they always sort after whatever else is in the
        // diff, regardless of where they fall in the snapshot's own field order.
        $trailingFields = ['totalQty', 'totalPrice'];
        $primary = array_values(array_filter(
            $changes,
            static fn(array $change) => !in_array($change['field'], $trailingFields, true)
        ));
        $trailing = array_values(array_filter(
            $changes,
            static fn(array $change) => in_array($change['field'], $trailingFields, true)
        ));

        return array_merge($primary, $trailing);
    }

    /**
     * Diffs two decoded field arrays (an order snapshot's `order` section, or
     * an address event's before/after payload), producing a uniform list of
     * changed fields ready for the timeline's diff table.
     *
     * A null `$previous` is treated as an empty baseline, so every populated
     * `$current` field shows as `null -> value` rather than being skipped -
     * this is what lets the oldest event in a timeline (nothing to diff
     * against) show its initial field values instead of an empty diff.
     *
     * `null` and `''` are both treated as "no value" - a field going from
     * unset to an empty string isn't a meaningful change, so that transition
     * is skipped rather than shown as `null -> ""`.
     *
     * @param array|null $current The current field values.
     * @param array|null $previous The previous field values to diff against, or null if there aren't any.
     * @param callable $labelResolver Resolves a field name to its human-readable label.
     * @return array A list of ['field' => ..., 'label' => ..., 'from' => ..., 'to' => ...] entries for changed fields.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _diffFields(?array $current, ?array $previous, callable $labelResolver): array
    {
        if ($current === null) {
            return [];
        }

        $previous ??= [];

        $changes = [];

        foreach ($current as $field => $value) {
            $previousValue = $previous[$field] ?? null;

            if ($previousValue === $value) {
                continue;
            }

            // null and '' both mean "no value" - skip that transition so it
            // doesn't show up as "Shipping Method: null -> ''"
            $previousIsBlank = $previousValue === null || $previousValue === '';
            $valueIsBlank = $value === null || $value === '';

            if ($previousIsBlank && $valueIsBlank) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $labelResolver($field),
                'from' => $previousValue,
                'to' => $value,
            ];
        }

        return $changes;
    }

    /**
     * Logs a coupon code change detected against the previous snapshot.
     *
     * @param Order $order The saved order.
     * @param array $previous The previous order snapshot.
     * @return void
     * @throws Exception If a log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _logCouponChange(Order $order, array $previous): void
    {
        $before = $previous['order']['couponCode'] ?? null;
        $after = $order->couponCode ?: null;

        if ($before === $after) {
            return;
        }

        $this->_log(
            $order,
            $after ? EventType::COUPON_APPLIED : EventType::COUPON_REMOVED,
            compact('before', 'after'),
            null,
            $previous,
        );
    }

    /**
     * Logs shipping and billing address changes detected against the snapshot.
     *
     * @param Order $order The saved order.
     * @param array $previous The previous order snapshot.
     * @return void
     * @throws Exception If a log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _logAddressChanges(Order $order, array $previous): void
    {
        $pairs = [
            ['shipping', 'getShippingAddress', EventType::SHIPPING_ADDRESS_SET, EventType::SHIPPING_ADDRESS_REMOVED],
            ['billing', 'getBillingAddress', EventType::BILLING_ADDRESS_SET, EventType::BILLING_ADDRESS_REMOVED],
        ];

        foreach ($pairs as [$key, $getter, $eventTypeSet, $eventTypeRemoved]) {
            $prevData = $previous['addresses'][$key] ?? null;
            $currAddress = $order->$getter();
            $currData = self::_serializeAddress($currAddress);

            if ($prevData === $currData) {
                continue;
            }

            $eventType = $currData ? $eventTypeSet : $eventTypeRemoved;
            $message = Formatter::addressChange($prevData, $currData, $key);
            $this->_log($order, $eventType, ['before' => $prevData, 'after' => $currData], $message, $previous);
        }
    }

    /**
     * Logs a customer email change detected against the previous snapshot.
     *
     * @param Order $order The saved order.
     * @param array $previous The previous order snapshot.
     * @return void
     * @throws Exception If a log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _logCustomerChange(Order $order, array $previous): void
    {
        $before = ($previous['customer']['email'] ?? null) ?: null;
        $after = ($order->email ?: null) ?: null;

        if ($before === $after) {
            return;
        }

        $customerType = self::isGuest($order) ? 'guest' : 'customer';
        $customerId = $order->getCustomer()?->id;

        $this->_log(
            $order,
            $after ? EventType::CUSTOMER_SET : EventType::CUSTOMER_REMOVED,
            compact('before', 'after', 'customerType', 'customerId'),
            null,
            $previous,
        );
    }

    /**
     * Logs a shipping-method change detected against the previous snapshot.
     *
     * @param Order $order The saved order.
     * @param array $previous The previous order snapshot.
     * @return void
     * @throws Exception If a log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _logShippingMethodChange(Order $order, array $previous): void
    {
        $before = $previous['order']['shippingMethodHandle'] ?? null;
        $after = $order->shippingMethodHandle;

        // Commerce flips shippingMethodHandle between null and '' during
        // checkout recalculation without a method ever being chosen (e.g. on
        // the shipping step with a single free method not yet submitted). Both
        // mean "no method", so treat them as equal, or the churn logs a phantom
        // SHIPPING_METHOD_SET with a null handle and no real change to describe.
        if (($before ?? '') === ($after ?? '')) {
            return;
        }

        $this->_log($order, EventType::SHIPPING_METHOD_SET, compact('before', 'after'), null, $previous);
    }

    /**
     * Logs per-SKU line-item quantity changes detected against the snapshot.
     *
     * @param Order $order The saved order.
     * @param array $previous The snapshot the order had before this save.
     * @return void
     * @throws Exception If a log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _logLineItemQuantityChanges(Order $order, array $previous): void
    {
        $previousLineItems = $previous['lineItems'] ?? [];

        $currentLineItems = [];
        foreach ($order->getLineItems() as $lineItem) {
            $currentLineItems[$lineItem->sku] = $lineItem;
        }

        foreach ($previousLineItems as $prevItem) {
            $sku = $prevItem['sku'] ?? null;
            $prevQty = $prevItem['qty'] ?? 0;

            if (!$sku || !isset($currentLineItems[$sku])) {
                continue;
            }

            $li = $currentLineItems[$sku];
            if ($prevQty === $li->qty) {
                continue;
            }

            $this->_log($order, EventType::LINE_ITEM_UPDATED, [
                'sku' => $li->sku,
                'qtyBefore' => $prevQty,
                'qtyAfter' => $li->qty,
                'subtotal' => $li->getSubtotal(),
            ], null, $previous);
        }
    }

    /**
     * Determines whether an existing failure log is the same email within the window.
     *
     * @param array|null $existingLog The most recent EMAIL_FAILED log, if any.
     * @param int|null $emailId The Commerce email ID of the current failure.
     * @return bool Whether the failure should be consolidated into the existing log.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _isSameFailedEmail(?array $existingLog, ?int $emailId): bool
    {
        if ($existingLog === null) {
            return false;
        }

        $snapshot = Json::decodeIfJson($existingLog['snapshot']);
        $existingEmailId = $snapshot['payload']['emailId'] ?? null;
        $timeDiff = time() - self::rowDate($existingLog['dateCreated'])->getTimestamp();

        return $existingEmailId === $emailId && $timeDiff < 300;
    }

    /**
     * How many events an order has recorded.
     *
     * @param int $orderId The order ID.
     * @return int
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _cartEventCount(int $orderId): int
    {
        return (int)(new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $orderId])
            ->count();
    }

    /**
     * Build a snapshot of the current order state.
     *
     * Captures comprehensive order data including line items, customer info,
     * addresses, adjustments, and any additional payload data.
     *
     * @param Order $order The order to create a snapshot of.
     * @param array $payload Additional data to include in the snapshot.
     * @return array The complete order snapshot.
     * @throws InvalidConfigException If the shipping method cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _buildSnapshot(Order $order, array $payload): array
    {
        $lineItems = [];
        foreach ($order->getLineItems() as $li) {
            $lineItems[] = [
                'sku' => $li->sku,
                'description' => $li->getDescription(),
                'qty' => $li->qty,
                'subtotal' => $li->getSubtotal(),
            ];
        }

        $customer = $order->getCustomer();
        $customerInfo = [
            'email' => $order->email,
            'isGuest' => self::isGuest($order),
            'customerId' => $customer?->id,
            'userId' => $customer?->id,
        ];

        $shippingMethodName = null;
        if ($order->shippingMethodHandle) {
            $shippingMethodName = $this->_getShippingMethodName($order->shippingMethodHandle);
        }

        return [
            'order' => [
                'id' => (int)$order->id,
                'number' => $order->number,
                'isCompleted' => $order->isCompleted,
                'dateOrdered' => $order->dateOrdered?->format('Y-m-d H:i:s'),
                'couponCode' => $order->couponCode ?: null,
                'totalQty' => $order->getTotalQty(),
                'totalPrice' => $order->getTotalPrice(),
                'totalShippingCost' => $order->getTotalShippingCost(),
                'currency' => $order->currency,
                'statusId' => $order->orderStatusId,
                'statusHandle' => $order->getOrderStatus()?->handle,
                'shippingMethodHandle' => $order->shippingMethodHandle,
                'shippingMethodName' => $shippingMethodName,
                'shippingAddressId' => $order->shippingAddressId,
                'billingAddressId' => $order->billingAddressId,
            ],
            'customer' => $customerInfo,
            'lineItems' => $lineItems,
            'discounts' => array_map(static fn($a) => $a->toArray(), $order->getAdjustmentsByType('discount')),
            'addresses' => [
                'billing' => self::_serializeAddress($order->getBillingAddress()),
                'shipping' => self::_serializeAddress($order->getShippingAddress()),
            ],
            'payload' => $payload,
        ];
    }

    /**
     * Gets the shipping method name by handle.
     *
     * Looks up the human-readable name for a shipping method from its handle.
     *
     * @param string $handle The shipping method handle.
     * @return string|null The shipping method name, or null if not found.
     * @throws InvalidConfigException If the Commerce shipping methods service is unavailable.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _getShippingMethodName(string $handle): ?string
    {
        $allShippingMethods = Commerce::getInstance()
            ->getShippingMethods()
            ->getAllShippingMethods();

        foreach ($allShippingMethods as $method) {
            // go through the interface getters - not every shipping method
            // exposes handle/name as plain properties
            if ($method->getHandle() === $handle) {
                return $method->getName();
            }
        }

        return null;
    }

    /**
     * Serializes the comparable fields of an Address element for snapshot storage.
     *
     * @param \craft\elements\Address|null $address The address to serialize, or null.
     * @return array|null The serialized address fields, or null when no address is given.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private static function _serializeAddress(?\craft\elements\Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $fields = [
            'firstName' => $address->firstName,
            'lastName' => $address->lastName,
            'addressLine1' => $address->addressLine1,
            'addressLine2' => $address->addressLine2,
            'locality' => $address->locality,
            'administrativeArea' => $address->administrativeArea,
            'postalCode' => $address->postalCode,
            'countryCode' => $address->countryCode,
        ];

        // Normalise '' to null per field: an optional address field left
        // untouched can be stored as either, and _logAddressChanges() compares
        // whole serialized arrays, so a null<->'' flip in any one field would
        // otherwise read as a real address change and log a phantom
        // SHIPPING_ADDRESS_SET / BILLING_ADDRESS_SET with nothing to describe.
        return array_map(static fn($v) => $v === '' ? null : $v, $fields);
    }
}
