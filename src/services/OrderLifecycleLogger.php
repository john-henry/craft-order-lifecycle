<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Json;
use DateTimeImmutable;
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
use yii\db\Expression;

/**
 * Order Lifecycle logger service.
 *
 * Records lifecycle events for Commerce orders, building order snapshots,
 * generating change descriptions and persisting log entries either inline or
 * via the queue.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class OrderLifecycleLogger extends Component
{
    // =========================================================================
    // Private Properties
    // =========================================================================

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
     * Pass an order ID to clear a single entry, or null to clear all entries.
     * Used in tests to simulate a fresh logger without re-registering event handlers.
     *
     * @param int|null $orderId The order ID to clear, or null to clear all entries.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function clearSnapshotCache(?int $orderId = null): void
    {
        if ($orderId === null) {
            $this->_snapshotCache = [];
        } else {
            unset($this->_snapshotCache[$orderId]);
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function log(Order $order, EventType $type, array $payload = [], ?string $message = null): void
    {
        $logData = $this->_prepareLogData($order, $type, $payload, $message);

        if (OrderLifecycle::$plugin->settings->asyncLogging) {
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function insertLogRow(array $logData): void
    {
        Craft::$app->getDb()->createCommand()
            ->insert('{{%orderlifecycle_logs}}', $logData)
            ->execute();
    }

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
     * @return array The prepared log row, ready to pass to insertLogRow().
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _prepareLogData(Order $order, EventType $type, array $payload, ?string $message): array
    {
        $settings = OrderLifecycle::$plugin->settings;
        $orderId = (int)$order->id;

        // grab this before we touch the cache below, or we'd diff against ourselves
        $previousSnapshot = $this->getLastSnapshot($orderId);

        $currentSnapshot = $this->_buildSnapshot($order, $payload);

        if ($message === null && $previousSnapshot !== null) {
            $message = $this->generateChangeDescription($previousSnapshot, $currentSnapshot, $type);
        }

        // microsecond precision so same-second events keep their insert order
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');

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
            'uid' => Craft::$app->getSecurity()->generateRandomString(),
        ];

        $this->_snapshotCache[$orderId] = $currentSnapshot;
        Craft::$app->getCache()->set("ol_snapshot_{$orderId}", $currentSnapshot, 7200);

        return $logData;
    }

    /**
     * Get all lifecycle logs for a specific order
     *
     * Returns an array of log entries ordered by creation date (most recent first).
     *
     * @param int $orderId The order ID to fetch logs for.
     * @return array Array of log records.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getLogsForOrder(int $orderId): array
    {
        $q = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $orderId])
            ->orderBy(['dateCreated' => SORT_DESC]);


        return $q->all();
    }

    /**
     * Returns the timeline entries for an order: lifecycle logs (excluding
     * AI insights) enriched with their filter pill, the number of seconds
     * elapsed since the previous chronological event, and which top-level
     * order snapshot fields changed since that previous event.
     *
     * Entries are newest-first, matching {@see getLogsForOrder()}. The oldest
     * entry has no previous event to compare against, so its `deltaSeconds`
     * and `orderChanges` are null/empty.
     *
     * @param int $orderId The order ID to build the timeline for.
     * @return array The enriched, newest-first timeline entries.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getTimelineForOrder(int $orderId): array
    {
        $logs = array_values(array_filter(
            $this->getLogsForOrder($orderId),
            static fn(array $log) => $log['type'] !== EventType::AI_INSIGHTS->value
        ));

        // decode once up front - everything but the oldest entry gets diffed
        // against its neighbour, so decoding lazily would do it twice
        $orderSnapshots = array_map(
            fn(array $log) => $this->_decodeOrderSnapshot($log['snapshot'] ?? null),
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

            $log['pill'] = EventType::tryFrom($log['type'])?->getFilterPill() ?? 'other';
            $log['deltaSeconds'] = $previous !== null
                ? max(0, strtotime($log['dateCreated']) - strtotime($previous['dateCreated']))
                : null;

            if (in_array(EventType::tryFrom($log['type']), $addressEventTypes, true)) {
                // address events carry their own before/after in the payload -
                // more reliable than the order-snapshot diff, which goes blank
                // when billing and shipping are both saved in the same request
                $payload = $this->_decodeSnapshotPayload($log['snapshot'] ?? null);
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
                $log['orderChanges'] = $this->_diffOrderSnapshot($orderSnapshots[$index], $orderSnapshots[$index + 1] ?? null);
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
     * @author John Henry Donovan
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
            ->orderBy(['dateCreated' => SORT_DESC])
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
     * @author John Henry Donovan
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

        // Payment refunded: show the amount refunded and via which gateway
        if ($type === EventType::PAYMENT_REFUNDED) {
            $amount = $current['payload']['amount'] ?? null;
            $currency = $current['payload']['currency'] ?? ($current['order']['currency'] ?? null);
            $gateway = $current['payload']['gatewayName'] ?? null;

            if ($amount !== null && $currency !== null) {
                $formatted = Craft::$app->getFormatter()->asCurrency((float)$amount, strtoupper($currency));

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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getLastLogForOrderAndType(int $orderId, EventType $type): ?array
    {
        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $orderId, 'type' => $type->value])
            ->orderBy(['dateCreated' => SORT_DESC])
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
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
        if ($transaction->status === 'failed') {
            $logData['code'] = $transaction->code;
            $logData['message'] = $transaction->message;
            $message = 'Payment ' . Formatter::sanitize((string)$transaction->type) . ' failed: ' . Formatter::sanitize((string)$transaction->message);
        } elseif ($transaction->status === 'success') {
            $message = 'Payment ' . Formatter::sanitize((string)$transaction->type) . ' succeeded';
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function updateLog(int $logId, array $updates): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%orderlifecycle_logs}}', array_merge($updates, [
                'dateUpdated' => new Expression('NOW()'),
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function logCheckoutStarted(Order $order): bool
    {
        $existingCheckout = $this->getLastLogForOrderAndType($order->id, EventType::CHECKOUT_STARTED);

        if ($existingCheckout) {
            return false;
        }

        $message = $order->getCustomer()
            ? Craft::t('order-lifecycle', 'Registered customer visited checkout page')
            : Craft::t('order-lifecycle', 'Guest customer visited checkout page');

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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function logOrderSaveChanges(Order $order, bool $isNew): void
    {
        $settings = OrderLifecycle::$plugin->settings;

        if ($isNew) {
            // no previous snapshot yet, so generateChangeDescription() won't
            // run for this event - pass the message explicitly instead
            $message = $order->getCustomer()
                ? Craft::t('order-lifecycle', 'Cart initialized for registered customer')
                : Craft::t('order-lifecycle', 'Cart initialized for guest customer');
            $this->log($order, EventType::CART_CREATED, [], $message);
        }

        $previous = $this->getLastSnapshot((int)$order->id);

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
            $this->_logLineItemQuantityChanges($order, $previous['lineItems']);
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
     * @param string|null $orderNumber The order number carried by the failed job.
     * @param string|null $orderReference The order reference carried by the failed job.
     * @param int $attempt The attempt number reported by the queue.
     * @param string|null $jobId The queue job ID, if known.
     * @param Throwable|null $error The error that caused the job to fail.
     * @return void
     * @throws Exception If a log row cannot be inserted or updated.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function logFailedEmailJob(
        ?int $orderId,
        ?int $emailId,
        ?string $orderNumber,
        ?string $orderReference,
        int $attempt,
        ?string $jobId,
        ?Throwable $error,
    ): void {
        $order = $this->_resolveOrderForFailedEmail($orderId, $orderNumber);

        if ($order === null) {
            return;
        }

        $emailName = null;
        if ($emailId !== null) {
            $email = Commerce::getInstance()->getEmails()->getEmailById($emailId);
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
                'message' => "Email queue job failed after $attempt attempt(s): $errorMsg",
                'snapshot' => Json::encode($snapshot),
            ]);

            return;
        }

        $message = $attempt > 1
            ? "Email queue job failed after $attempt attempt(s): $errorMsg"
            : "Email queue job failed: $errorMsg";

        $this->log($order, EventType::EMAIL_FAILED, [
            'emailId' => $emailId,
            'emailName' => $emailName,
            'orderNumber' => $orderNumber,
            'orderReference' => $orderReference,
            'error' => $errorMsg,
            'errorClass' => $errorClass,
            'attempt' => $attempt,
            'jobId' => $jobId,
        ], $message);
    }

    /**
     * Deletes logs for orders that no longer exist (deleted or trashed).
     *
     * Shared by the garbage-collection listener and the console purge command so
     * both use identical orphan-detection logic.
     *
     * @return int The number of log rows deleted.
     * @throws Exception If the delete command fails.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function deleteOrphanedLogs(): int
    {
        $orphanedIds = (new Query())
            ->select('orderId')
            ->from([
                'tmp' => (new Query())
                    ->select('[[l.orderId]]')
                    ->from('{{%orderlifecycle_logs}} l')
                    ->leftJoin('{{%elements}} e', '[[e.id]] = [[l.orderId]]')
                    ->where(['OR',
                        ['e.id' => null],
                        ['NOT', ['e.dateDeleted' => null]],
                    ]),
            ]);

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%orderlifecycle_logs}}', ['in', 'orderId', $orphanedIds])
            ->execute();
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Decodes a log's JSON snapshot and returns just its top-level `order` section.
     *
     * @param string|null $snapshot The log's JSON snapshot.
     * @return array|null The decoded `order` section, or null if absent/malformed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _decodeOrderSnapshot(?string $snapshot): ?array
    {
        if ($snapshot === null || $snapshot === '') {
            return null;
        }

        try {
            $decoded = Json::decode($snapshot);
        } catch (InvalidArgumentException) {
            return null;
        }

        $order = is_array($decoded) ? ($decoded['order'] ?? null) : null;

        return is_array($order) ? $order : null;
    }

    /**
     * Decodes a log's JSON snapshot and returns just its top-level `payload` section.
     *
     * @param string|null $snapshot The log's JSON snapshot.
     * @return array|null The decoded `payload` section, or null if absent/malformed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _decodeSnapshotPayload(?string $snapshot): ?array
    {
        if ($snapshot === null || $snapshot === '') {
            return null;
        }

        try {
            $decoded = Json::decode($snapshot);
        } catch (InvalidArgumentException) {
            return null;
        }

        $payload = is_array($decoded) ? ($decoded['payload'] ?? null) : null;

        return is_array($payload) ? $payload : null;
    }

    /**
     * Diffs two decoded `order` snapshot sections.
     *
     * @param array|null $current The current log's decoded `order` section.
     * @param array|null $previous The previous chronological log's decoded `order` section, or null if there isn't one.
     * @return array A list of ['field' => ..., 'label' => ..., 'from' => ..., 'to' => ...] entries for changed fields.
     * @author John Henry Donovan
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _logCouponChange(Order $order, array $previous): void
    {
        $before = $previous['order']['couponCode'] ?? null;
        $after = $order->couponCode ?: null;

        if ($before === $after) {
            return;
        }

        $this->log(
            $order,
            $after ? EventType::COUPON_APPLIED : EventType::COUPON_REMOVED,
            compact('before', 'after')
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
     * @author John Henry Donovan
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
            $this->log($order, $eventType, ['before' => $prevData, 'after' => $currData], $message);
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _logCustomerChange(Order $order, array $previous): void
    {
        $before = ($previous['customer']['email'] ?? null) ?: null;
        $after = ($order->email ?: null) ?: null;

        if ($before === $after) {
            return;
        }

        $customer = $order->getCustomer();
        $customerType = $customer ? 'customer' : 'guest';
        $customerId = $customer?->id;

        $this->log(
            $order,
            $after ? EventType::CUSTOMER_SET : EventType::CUSTOMER_REMOVED,
            compact('before', 'after', 'customerType', 'customerId')
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _logShippingMethodChange(Order $order, array $previous): void
    {
        $before = $previous['order']['shippingMethodHandle'] ?? null;
        $after = $order->shippingMethodHandle;

        if ($before === $after) {
            return;
        }

        $this->log($order, EventType::SHIPPING_METHOD_SET, compact('before', 'after'));
    }

    /**
     * Logs per-SKU line-item quantity changes detected against the snapshot.
     *
     * @param Order $order The saved order.
     * @param array $previousLineItems The previous snapshot's line items.
     * @return void
     * @throws Exception If a log row cannot be inserted.
     * @throws \yii\base\Exception If a random UID cannot be generated.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _logLineItemQuantityChanges(Order $order, array $previousLineItems): void
    {
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

            $this->log($order, EventType::LINE_ITEM_UPDATED, [
                'sku' => $li->sku,
                'qtyBefore' => $prevQty,
                'qtyAfter' => $li->qty,
                'subtotal' => $li->getSubtotal(),
            ]);
        }
    }

    /**
     * Resolves the order for a failed email job by ID, falling back to number.
     *
     * @param int|null $orderId The order ID carried by the failed job.
     * @param string|null $orderNumber The order number carried by the failed job.
     * @return Order|null The resolved order, or null if it cannot be found.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _resolveOrderForFailedEmail(?int $orderId, ?string $orderNumber): ?Order
    {
        if ($orderId !== null) {
            $order = Order::find()->id($orderId)->one();
            if ($order instanceof Order) {
                return $order;
            }
        }

        if ($orderNumber !== null) {
            $order = Order::find()->number($orderNumber)->one();
            if ($order instanceof Order) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Determines whether an existing failure log is the same email within the window.
     *
     * @param array|null $existingLog The most recent EMAIL_FAILED log, if any.
     * @param int|null $emailId The Commerce email ID of the current failure.
     * @return bool Whether the failure should be consolidated into the existing log.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _isSameFailedEmail(?array $existingLog, ?int $emailId): bool
    {
        if ($existingLog === null) {
            return false;
        }

        $snapshot = Json::decodeIfJson($existingLog['snapshot']);
        $existingEmailId = $snapshot['payload']['emailId'] ?? null;
        $timeDiff = time() - strtotime($existingLog['dateCreated']);

        return $existingEmailId === $emailId && $timeDiff < 300;
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
     * @author John Henry Donovan
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
            'isGuest' => !$customer,
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _serializeAddress(?\craft\elements\Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'firstName' => $address->firstName,
            'lastName' => $address->lastName,
            'addressLine1' => $address->addressLine1,
            'addressLine2' => $address->addressLine2,
            'locality' => $address->locality,
            'administrativeArea' => $address->administrativeArea,
            'postalCode' => $address->postalCode,
            'countryCode' => $address->countryCode,
        ];
    }
}
