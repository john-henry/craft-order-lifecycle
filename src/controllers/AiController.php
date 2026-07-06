<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\jobs\GenerateStoreInsights;
use johnhenry\orderlifecycle\OrderLifecycle;
use JsonException;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * AI controller.
 *
 * Thin controller for the on-demand Claude AI insight requests. Delegates stats
 * aggregation to StatsService and prompt building, the Anthropic call and result
 * persistence to AiInsightsService; store-wide generation runs on the queue.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class AiController extends Controller
{
    // =========================================================================
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Generates AI insights for a single order.
     *
     * @return Response The JSON response containing the insights or an error.
     * @throws Exception If logging the insight fails.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @throws MethodNotAllowedHttpException If the request is not a POST request.
     * @throws \yii\base\Exception If the order context cannot be built.
     * @throws BadRequestHttpException If the required order ID is missing.
     * @throws ForbiddenHttpException If the user lacks the required permission.
     * @throws JsonException If the order context cannot be decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionInsights(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('order-lifecycle:generateInsights');

        $orderId = (int)$this->request->getRequiredBodyParam('orderId');

        $ai = OrderLifecycle::getInstance()->getAiInsights();
        $apiKey = $ai->getApiKey();

        if ($apiKey === '') {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'No Anthropic API key configured. Add one in Settings → Order Lifecycle → Integrations.'),
            ]);
        }

        $logger = OrderLifecycle::getInstance()->getLogger();
        $logs = $logger->getLogsForOrder($orderId);

        if (empty($logs)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'No lifecycle data available for this order.'),
            ]);
        }

        $order = Commerce::getInstance()->getOrders()->getOrderById($orderId);

        if (!$order) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'Order not found.'),
            ]);
        }

        $context = $this->_buildOrderContext($logs);
        $prompt = $ai->buildOrderPrompt($context);

        try {
            $insights = $ai->generateInsights($apiKey, $prompt);
        } catch (\Throwable $e) {
            Craft::error('Order Lifecycle AI insights error: ' . $e->getMessage(), 'order-lifecycle');

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'Failed to get AI insights. Please try again.'),
            ]);
        }

        $logger->log($order, EventType::AI_INSIGHTS, [], $insights);

        return $this->asJson([
            'success' => true,
            'insights' => $insights,
            'summary' => $this->_buildOrderSummary($order),
        ]);
    }

    /**
     * Queues store-wide AI insight generation and returns immediately.
     *
     * The Anthropic call runs on the queue rather than blocking the request; the
     * CP polls actionStoreInsightsStatus for the result.
     *
     * @return Response The JSON response indicating the job was queued.
     * @throws MethodNotAllowedHttpException If the request is not a POST request.
     * @throws BadRequestHttpException If the request does not accept a JSON response.
     * @throws ForbiddenHttpException If the user lacks the required permission.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionStoreInsights(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('order-lifecycle:generateInsights');

        $days = (int)$this->request->getBodyParam('days', 30);
        $days = max(0, min(365, $days)); // 0 = all time

        $ai = OrderLifecycle::getInstance()->getAiInsights();

        if ($ai->getApiKey() === '') {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'No Anthropic API key configured.'),
            ]);
        }

        $context = $ai->truncateContext((string)$this->request->getBodyParam('context', ''));
        $storeId = Commerce::getInstance()?->getStores()->getCurrentStore()->id;

        // the stats query + 60s Anthropic call can get close to the queue's
        // default 300s TTR under load, so give it more room than that
        Craft::$app->getQueue()->ttr(600)->push(new GenerateStoreInsights([
            'days' => $days,
            'context' => $context,
            'storeId' => $storeId,
        ]));

        return $this->asJson([
            'success' => true,
            'queued' => true,
            'days' => $days,
        ]);
    }

    /**
     * Returns the most recently generated store-wide insights, if any.
     *
     * @return Response The JSON response with the stored insights or a pending flag.
     * @throws BadRequestHttpException If the request does not accept a JSON response.
     * @throws ForbiddenHttpException If the user lacks the required permission.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionStoreInsightsStatus(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('order-lifecycle:generateInsights');

        $storeId = Commerce::getInstance()?->getStores()->getCurrentStore()->id;
        $saved = OrderLifecycle::getInstance()->getAiInsights()->getSavedStoreInsights($storeId);

        if ($saved === null) {
            return $this->asJson([
                'success' => true,
                'ready' => false,
            ]);
        }

        return $this->asJson([
            'success' => true,
            'ready' => true,
            'insights' => $saved['insights'],
            'generatedAt' => $saved['generatedAt'],
            'days' => $saved['days'],
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Builds the "Order #{reference} | {currency} | {customer type} Customer | {status}"
     * summary line shown above the AI insights panel.
     *
     * Built from live order data rather than the AI response, so it always
     * reflects the order's current state even if it's changed since the
     * insights were last generated. Each segment is translated on its own
     * and joined with a fixed separator, so translators can phrase each
     * fact naturally instead of matching one fixed English sentence.
     *
     * @param Order $order The order to summarise.
     * @return string The rendered summary line.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _buildOrderSummary(Order $order): string
    {
        $customerLabel = $order->getCustomer()
            ? Craft::t('order-lifecycle', 'Registered')
            : Craft::t('order-lifecycle', 'Guest');

        $statusLabel = $order->isCompleted
            ? Craft::t('order-lifecycle', 'Completed')
            : ($order->getOrderStatus()?->name ?? Craft::t('order-lifecycle', 'Pending'));

        $segments = [
            Craft::t('order-lifecycle', 'Order #{reference}', ['reference' => $order->reference ?? $order->id]),
            (string)$order->currency,
            Craft::t('order-lifecycle', '{customerLabel} Customer', ['customerLabel' => $customerLabel]),
            $statusLabel,
        ];

        return implode(' | ', $segments);
    }

    /**
     * Builds the structured order context used to render the per-order prompt.
     *
     * @param array $logs The lifecycle logs for the order, newest first.
     * @return array The structured order context (order, customer, events, metrics).
     * @throws JsonException If a log snapshot cannot be decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _buildOrderContext(array $logs): array
    {
        $lastLog = $logs[0];
        $firstLog = end($logs);

        $lastSnapshot = json_decode($lastLog['snapshot'] ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];

        $events = [];
        foreach (array_reverse($logs) as $log) {
            $events[] = [
                'type' => $log['type'],
                'message' => $log['message'] ?? '',
                'date' => $log['dateCreated'],
            ];
        }

        $firstTime = strtotime($firstLog['dateCreated']);
        $lastTime = strtotime($lastLog['dateCreated']);
        $totalDurationMinutes = ($firstTime && $lastTime) ? (int)round(($lastTime - $firstTime) / 60) : null;

        return [
            'order' => $lastSnapshot['order'] ?? [],
            'customer' => $lastSnapshot['customer'] ?? [],
            'events' => $events,
            'metrics' => [
                'totalEvents' => count($logs),
                'totalDurationMinutes' => $totalDurationMinutes,
                'paymentAttempts' => $this->_countType($logs, ['paymentAttempt']),
                'paymentFailed' => $this->_countPaymentFailures($logs),
                'emailFailures' => $this->_countType($logs, ['emailFailed']),
                'statusChanges' => $this->_countType($logs, ['statusChanged']),
                'lineItemChanges' => $this->_countType($logs, ['lineItemAdded', 'lineItemRemoved', 'lineItemUpdated']),
                'addressChanges' => $this->_countType($logs, ['shippingAddressSet', 'billingAddressSet', 'shippingAddressRemoved', 'billingAddressRemoved']),
                'shippingChanges' => $this->_countType($logs, ['shippingMethodSet']),
            ],
        ];
    }

    /**
     * Counts logs whose type is one of the supplied handles.
     *
     * @param array $logs The lifecycle logs.
     * @param string[] $types The event type handles to count.
     * @return int The number of matching logs.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _countType(array $logs, array $types): int
    {
        return count(array_filter($logs, static fn($l) => in_array($l['type'], $types, true)));
    }

    /**
     * Counts failed payment-processed logs.
     *
     * @param array $logs The lifecycle logs.
     * @return int The number of failed payment attempts.
     * @throws JsonException If a snapshot cannot be decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _countPaymentFailures(array $logs): int
    {
        return count(array_filter($logs, static function($l) {
            if ($l['type'] !== 'paymentProcessed') {
                return false;
            }

            $snapshot = json_decode($l['snapshot'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);

            return ($snapshot['payload']['success'] ?? true) === false;
        }));
    }
}
