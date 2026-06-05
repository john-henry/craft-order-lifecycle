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
use johnhenry\orderlifecycle\jobs\LogOrderEventJob;
use johnhenry\orderlifecycle\OrderLifecycle;
use Throwable;
use yii\base\Component;
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
     * Logs an order lifecycle event.
     *
     * Creates a log entry for the specified order and event type, capturing a snapshot
     * of the current order state and optionally comparing it with the previous snapshot
     * to generate a change description.
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
    /**
     * Clear the in-request snapshot cache.
     *
     * Pass an order ID to clear a single entry, or null to clear all entries.
     * Used in tests to simulate a fresh logger without re-registering event handlers.
     *
     * @param int|null $orderId
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
     * @inheritdoc
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function log(Order $order, EventType $type, array $payload = [], ?string $message = null): void
    {
        $settings = OrderLifecycle::$plugin->settings;
        $orderId = (int)$order->id;

        // Read the previous snapshot BEFORE updating any caches, so change
        // descriptions diff against the actual prior state.
        $previousSnapshot = $this->getLastSnapshot($orderId);

        $currentSnapshot = $this->buildSnapshot($order, $payload);

        // Resolve the message now — the three-tier cache (memory → persistent →
        // DB) means getLastSnapshot() returns the correct prior state even for
        // async logging across multiple requests.
        if ($message === null && $previousSnapshot !== null) {
            $message = $this->generateChangeDescription($previousSnapshot, $currentSnapshot, $type);
        }

        // Use microsecond precision so events logged within the same second
        // retain their insertion order in the timeline.
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');

        $logData = [
            'orderId' => $orderId,
            'type' => $type->value,
            'message' => $message,
            'snapshot' => Json::encode($currentSnapshot),
            'userId' => $settings->collectUserId ? Craft::$app->getUser()->getId() : null,
            'ip' => $settings->collectUserIp ? Craft::$app->getRequest()->getUserIP() : null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => Craft::$app->getSecurity()->generateRandomString(),
        ];

        // Update caches to currentSnapshot AFTER computing the message so that
        // subsequent calls in the same request diff against this event's state.
        $this->_snapshotCache[$orderId] = $currentSnapshot;
        Craft::$app->getCache()->set("ol_snapshot_{$orderId}", $currentSnapshot, 7200);

        if ($settings->asyncLogging) {
            Craft::$app->getQueue()->push(new LogOrderEventJob($logData));
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->insert('{{%orderlifecycle_logs}}', $logData)
            ->execute();
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
        // 1. In-request memory — fastest, covers same-request duplicate saves.
        if (isset($this->_snapshotCache[$orderId])) {
            return $this->_snapshotCache[$orderId];
        }

        // 2. Persistent cache — covers cross-request saves when async queue
        //    jobs haven't executed yet and the DB row is still stale.
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
     * Build a snapshot of the current order state
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
    private function buildSnapshot(Order $order, array $payload): array
    {
        $lineItems = [];
        foreach ($order->getLineItems() as $li) {
            $lineItems[] = [
                'sku' => $li->sku,
                'qty' => $li->qty,
                'subtotal' => $li->getSubtotal(),
            ];
        }
        // Get customer info
        $customer = $order->getCustomer();
        $customerInfo = [
            'email' => $order->email,
            'isGuest' => !$customer,
            'customerId' => $customer?->id,
            'userId' => $customer?->id,
        ];

        // Get shipping method name
        $shippingMethodName = null;
        if ($order->shippingMethodHandle) {
            $shippingMethodName = $this->getShippingMethodName($order->shippingMethodHandle);
        }



        return [
            'order' => [
                'id' => (int)$order->id,
                'number' => $order->number,
                'isCompleted' => $order->isCompleted,
                'couponCode' => $order->couponCode ?: null,
                'totalQty' => $order->getTotalQty(),
                'totalPrice' => $order->getTotalPrice(),
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
            'shippingAdjustments' => array_map(static fn($a) => $a->toArray(), $order->getAdjustmentsByType('shipping')),
            'taxAdjustments' => array_map(static fn($a) => $a->toArray(), $order->getAdjustmentsByType('tax')),
            'addresses' => [
                'billing' => self::_serializeAddress($order->getBillingAddress()),
                'shipping' => self::_serializeAddress($order->getShippingAddress()),
            ],
            'payload' => $payload,
        ];
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

        // Order paid — show amount and gateway
        if ($type === EventType::ORDER_PAID) {
            $amount = $current['payload']['totalPaid'] ?? null;
            $currency = $current['payload']['currency'] ?? ($current['order']['currency'] ?? null);
            $gateway = $current['payload']['gatewayName'] ?? null;

            if ($amount !== null && $currency !== null) {
                $formatted = Craft::$app->getFormatter()->asCurrency((float)$amount, strtoupper($currency));
                $message = $gateway
                    ? Craft::t('order-lifecycle', '{amount} paid via {gateway}', ['amount' => $formatted, 'gateway' => $gateway])
                    : Craft::t('order-lifecycle', '{amount} paid', ['amount' => $formatted]);
                return $message;
            }
        }

        // Check line item changes
        if ($type === EventType::LINE_ITEM_ADDED || $type === EventType::LINE_ITEM_REMOVED || $type === EventType::LINE_ITEM_UPDATED) {
            $changes = array_merge($changes, Formatter::compareLineItems(
                $previous['lineItems'] ?? [],
                $current['lineItems'] ?? []
            ));
        }

        // Check cart/order changes
        if (str_starts_with($type->value, 'cart') || str_starts_with($type->value, 'order')) {
            // Total quantity change
            $prevQty = $previous['order']['totalQty'] ?? 0;
            $currQty = $current['order']['totalQty'] ?? 0;
            if ($prevQty !== $currQty) {
                $changes[] = Formatter::quantityChange($prevQty, $currQty);
            }

            // Total price change
            $prevPrice = $previous['order']['totalPrice'] ?? 0;
            $currPrice = $current['order']['totalPrice'] ?? 0;
            if (round((float)$prevPrice, 2) !== round((float)$currPrice, 2)) {
                $changes[] = Formatter::priceChange($prevPrice, $currPrice, $current['order']['currency']);
            }

            // Coupon code changes
            $prevCoupon = $previous['order']['couponCode'] ?? null;
            $currCoupon = $current['order']['couponCode'] ?? null;
            $couponMessage = Formatter::couponChange($prevCoupon, $currCoupon);
            if ($couponMessage) {
                $changes[] = $couponMessage;
            }

            // Status changes
            $prevStatus = $previous['order']['statusHandle'] ?? null;
            $currStatus = $current['order']['statusHandle'] ?? null;
            $statusMessage = Formatter::statusChange($prevStatus, $currStatus);
            if ($statusMessage) {
                $changes[] = $statusMessage;
            }
        }


        // Check customer changes
        if ($type === EventType::CUSTOMER_SET || $type === EventType::CUSTOMER_REMOVED) {
            $prevEmail = $previous['customer']['email'] ?? null;
            $currEmail = $current['customer']['email'] ?? null;
            $customerType = ($current['customer']['isGuest'] ?? true) ? 'guest' : 'customer';

            $customerMessage = Formatter::customerChange($prevEmail, $currEmail, $customerType);
            if ($customerMessage) {
                $changes[] = $customerMessage;
            }
        }

        // Check shipping changes
        if ($type === EventType::SHIPPING_METHOD_SET) {
            $prevMethod = $previous['order']['shippingMethodName'] ?? null;
            $currMethod = $current['order']['shippingMethodName'] ?? null;
            $shippingMessage = Formatter::shippingMethodChange($prevMethod, $currMethod);
            if ($shippingMessage) {
                $changes[] = $shippingMessage;
            }

            $prevCountry = $previous['addresses']['shippingCountry'] ?? null;
            $currCountry = $current['addresses']['shippingCountry'] ?? null;
            $countryMessage = Formatter::countryChange($prevCountry, $currCountry);
            if ($countryMessage) {
                $changes[] = $countryMessage;
            }
        }

        // Check billing changes
        if ($type === EventType::BILLING_ADDRESS_SET || $type === EventType::BILLING_ADDRESS_REMOVED) {
            $prevCountry = $previous['addresses']['billingCountry'] ?? null;
            $currCountry = $current['addresses']['billingCountry'] ?? null;
            $countryMessage = Formatter::countryChange($prevCountry, $currCountry, 'billing');
            if ($countryMessage) {
                $changes[] = $countryMessage;
            }
        }

        return !empty($changes) ? implode('; ', $changes) : null;
    }


    /**
     * Get the shipping method name by handle
     *
     * Looks up the human-readable name for a shipping method from its handle.
     *
     * @param string $handle The shipping method handle.
     * @return string|null The shipping method name, or null if not found.
     * @throws InvalidConfigException If the Commerce shipping methods service is unavailable.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    /**
     * Serialize the comparable fields of an Address element for snapshot storage.
     *
     * @param \craft\elements\Address|null $address
     * @return array|null
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

    private function getShippingMethodName(string $handle): ?string
    {
        $allShippingMethods = Commerce::getInstance()
            ->getShippingMethods()
            ->getAllShippingMethods();

        foreach ($allShippingMethods as $method) {
            if ($method->handle === $handle) {
                return $method->name;
            }
        }

        return null;
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
        // Check if checkout was already logged for this order
        $existingCheckout = $this->getLastLogForOrderAndType($order->id, EventType::CHECKOUT_STARTED);

        if ($existingCheckout) {
            return false; // Already logged, don't log again
        }

        // Log the checkout started event
        $this->log($order, EventType::CHECKOUT_STARTED, [
            'email' => $order->email,
            'totalPrice' => $order->getTotalPrice(),
            'totalQty' => $order->getTotalQty(),
            'currency' => $order->currency,
        ], 'Customer visited checkout page');

        return true;
    }
}
