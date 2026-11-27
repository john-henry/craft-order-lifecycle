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
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\services\OrderLifecycleLogger;
use Throwable;
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
 * persistence to AiInsightsService. Both actions run inline, so neither needs a
 * queue worker to be running.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class AiController extends Controller
{
    // =========================================================================
    // Traits
    // =========================================================================

    use AiRateLimitTrait;

    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string The permission to generate AI insights.
     */
    public const PERMISSION_GENERATE_INSIGHTS = 'order-lifecycle:generate-insights';

    /**
     * @var int The most AI requests one user may make in a window.
     */
    public const AI_RATE_LIMIT = 20;

    /**
     * @var int The length of the rolling rate-limit window, in seconds.
     */
    public const AI_RATE_WINDOW = 60;

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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionInsights(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(self::PERMISSION_GENERATE_INSIGHTS);

        if ($this->aiRateLimitExceeded('order-insights', self::AI_RATE_LIMIT, self::AI_RATE_WINDOW)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'Too many insight requests, please wait a moment.'),
            ]);
        }

        $orderId = (int)$this->request->getRequiredBodyParam('orderId');

        $ai = OrderLifecycle::getInstance()->getAiInsights();
        $apiKey = $ai->getApiKey();

        if ($apiKey === '') {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'No Anthropic API key configured. Add one in Order Lifecycle → Settings → Integrations.'),
            ]);
        }

        // Being allowed to generate insights is not the same as being allowed
        // to read this order: the ID comes straight off the request. Checked
        // before anything is read, so the answer doesn't give away which
        // orders have a history either.
        $order = Commerce::getInstance()->getOrders()->getOrderById($orderId);

        if (!$order || !Craft::$app->getElements()->canView($order)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'Order not found.'),
            ]);
        }

        $logger = OrderLifecycle::getInstance()->getLogger();
        $context = $ai->buildOrderContext($logger->getLogsForOrder($orderId));

        if ($context['events'] === []) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'No lifecycle data available for this order.'),
            ]);
        }

        $prompt = $ai->buildOrderPrompt($context);

        try {
            $insights = $ai->generateInsights($apiKey, $prompt);
        } catch (Throwable $e) {
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
     * Generates store-wide AI insights and returns them.
     *
     * Runs inline like {@see actionInsights()} so it works without a queue
     * worker; the store manager waits on a spinner while Claude responds. The
     * result is also saved so the dashboard and widget can show it on reload.
     *
     * @return Response The JSON response containing the insights or an error.
     * @throws MethodNotAllowedHttpException If the request is not a POST request.
     * @throws BadRequestHttpException If the request does not accept a JSON response.
     * @throws ForbiddenHttpException If the user lacks the required permission.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionStoreInsights(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(self::PERMISSION_GENERATE_INSIGHTS);

        if ($this->aiRateLimitExceeded('store-insights', self::AI_RATE_LIMIT, self::AI_RATE_WINDOW)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'Too many insight requests, please wait a moment.'),
            ]);
        }

        $days = (int)$this->request->getBodyParam('days', 30);
        $days = max(0, min(365, $days)); // 0 = all time

        $ai = OrderLifecycle::getInstance()->getAiInsights();
        $apiKey = $ai->getApiKey();

        if ($apiKey === '') {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'No Anthropic API key configured.'),
            ]);
        }

        $context = $ai->truncateContext((string)$this->request->getBodyParam('context', ''));
        $storeId = OrderLifecycle::getInstance()->getStats()->resolveStoreId();

        try {
            $stats = OrderLifecycle::getInstance()->getStats()->getStoreInsightStats($days, $storeId);
            $prompt = $ai->buildStorePrompt($stats, $days, $context);
            $insights = $ai->generateInsights($apiKey, $prompt);
        } catch (Throwable $e) {
            Craft::error('Order Lifecycle store insights error: ' . $e->getMessage(), 'order-lifecycle');

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'Failed to get AI insights. Please try again.'),
            ]);
        }

        $saved = $ai->saveStoreInsights($insights, $days, $storeId);

        return $this->asJson([
            'success' => true,
            'insights' => $insights,
            'days' => $days,
            'generatedAt' => $saved['generatedAt'] ?? null,
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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _buildOrderSummary(Order $order): string
    {
        $customerLabel = OrderLifecycleLogger::isGuest($order)
            ? Craft::t('order-lifecycle', 'Guest')
            : Craft::t('order-lifecycle', 'Registered');

        $statusLabel = $order->isCompleted
            ? Craft::t('order-lifecycle', 'Completed')
            : ($order->getOrderStatus()->name ?? Craft::t('order-lifecycle', 'Pending'));

        $segments = [
            Craft::t('order-lifecycle', 'Order #{reference}', ['reference' => $order->reference ?? $order->id]),
            (string)$order->currency,
            Craft::t('order-lifecycle', '{customerLabel} Customer', ['customerLabel' => $customerLabel]),
            $statusLabel,
        ];

        return implode(' | ', $segments);
    }
}
