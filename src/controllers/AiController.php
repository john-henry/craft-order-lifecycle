<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\web\Controller;
use DateTime;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\widgets\AiInsightsWidget;
use JsonException;
use RuntimeException;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * AI controller.
 *
 * Handles the on-demand Claude AI insight requests for individual orders and
 * for store-wide statistics, building the prompts, calling the Anthropic API
 * and persisting the generated insights.
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
    // Static Methods
    // =========================================================================

    /**
     * Returns the default per-order insights prompt template.
     *
     * @return string The default per-order prompt, including {placeholder} tokens.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function defaultOrderPrompt(): string
    {
        return 'You are an e-commerce order analyst. Analyze the following order lifecycle data and provide concise, actionable insights for the store manager.' . "\n\n"
            . 'Order total: {total}' . "\n"
            . 'Status: {status}' . "\n"
            . 'Completed: {completed}' . "\n"
            . 'Customer type: {customerType}' . "\n\n"
            . 'Metrics:' . "\n"
            . '- Total events: {totalEvents}' . "\n"
            . '- Time from first to last event: {duration}' . "\n"
            . '- Payment attempts before success (0 means paid first try): {paymentAttempts}' . "\n"
            . '- Payment failures: {paymentFailed}' . "\n"
            . '- Email send failures: {emailFailures}' . "\n"
            . '- Order status changes: {statusChanges}' . "\n"
            . '- Line item changes: {lineItemChanges}' . "\n"
            . '- Address events (includes initial address entry, not just edits): {addressChanges}' . "\n"
            . '- Shipping method changes: {shippingChanges}' . "\n\n"
            . 'Event timeline (chronological):' . "\n"
            . '{eventTimeline}' . "\n\n"
            . 'Provide 2–4 concise insights. Use **bold** for key figures and bullet points for specific action items. Focus on friction points, payment behavior, notable patterns, and anything the store manager should investigate. Keep the response under 200 words.';
    }

    /**
     * Returns the default store-wide insights prompt template.
     *
     * @return string The default store prompt, including {placeholder} tokens.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function defaultStorePrompt(): string
    {
        return 'You are an e-commerce analyst. Analyze the following store metrics for {days} and provide a concise health report for the store manager.' . "\n\n"
            . 'Store metrics ({days}):' . "\n"
            . '- Total lifecycle events logged: {totalLogs}' . "\n"
            . '- Orders with lifecycle data: {uniqueOrders}' . "\n"
            . '- Carts created: {cartsCreated}' . "\n"
            . '- Orders completed: {ordersCompleted}' . "\n"
            . '- Conversion rate: {conversionRate}%' . "\n"
            . '- Cart abandonment rate: {abandonmentRate}%' . "\n"
            . '- Average cart value (completed orders): {avgCartValue}' . "\n"
            . '- Total payment retries (attempts after first try): {paymentAttempts}' . "\n"
            . '- Average payment retries per completed order: {avgPaymentAttempts}' . "\n"
            . '- Refunds issued: {refunds}' . "\n"
            . '- Coupon codes applied: {couponApplied}' . "\n"
            . '- Emails sent: {emailsSent}' . "\n"
            . '- Email failures: {emailsFailed}' . "\n"
            . '- Email success rate: {emailSuccessRate}%' . "\n\n"
            . 'Top event types by volume:' . "\n"
            . '{topTypes}' . "\n\n"
            . 'Write a concise store health report. Use markdown formatting - ## headers for sections, **bold** for key figures, and bullet lists where appropriate. Cover: overall order activity and conversion, payment health, email reliability, and one or two specific things the store manager should look into or act on. Be direct and practical. Under 300 words.';
    }

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
     * @throws JsonException If the order context cannot be decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionInsights(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('order-lifecycle:generateInsights');

        $orderId = (int) Craft::$app->getRequest()->getRequiredBodyParam('orderId');

        $settings = OrderLifecycle::$plugin->settings;
        $apiKey = $settings->getAnthropicApiKey();

        if (!$apiKey) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'No Anthropic API key configured. Add one in Settings → Order Lifecycle → Integrations.'),
            ]);
        }

        $logger = OrderLifecycle::getInstance()->logger;
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

        $context = $this->buildOrderContext($logs);
        $prompt = $this->buildPrompt($context);

        try {
            $insights = $this->callClaude($apiKey, $prompt);
        } catch (\Throwable $e) {
            Craft::error('Order Lifecycle AI insights error: ' . $e->getMessage(), 'order-lifecycle');
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'Failed to get AI insights: {error}', ['error' => $e->getMessage()]),
            ]);
        }

        $logger->log($order, EventType::AI_INSIGHTS, [], $insights);

        return $this->asJson([
            'success' => true,
            'insights' => $insights,
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Builds the structured order context used to render the per-order prompt.
     *
     * @param array $logs The lifecycle logs for the order, newest first.
     * @return array The structured order context (order, customer, events, metrics).
     * @throws JsonException If a log snapshot cannot be decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function buildOrderContext(array $logs): array
    {
        $lastLog = $logs[0];
        $firstLog = end($logs);

        $lastSnapshot = json_decode($lastLog['snapshot'] ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
        $firstSnapshot = json_decode($firstLog['snapshot'] ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];

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
        $totalDurationMinutes = ($firstTime && $lastTime) ? (int) round(($lastTime - $firstTime) / 60) : null;

        $paymentAttempts = count(array_filter($logs, fn($l) => $l['type'] === 'paymentAttempt'));
        $paymentFailed = count(array_filter($logs, fn($l) => $l['type'] === 'paymentProcessed' &&
            (json_decode($l['snapshot'] ?? '{}', true, 512, JSON_THROW_ON_ERROR)['payload']['success'] ?? true) === false));
        $emailFailures = count(array_filter($logs, fn($l) => $l['type'] === 'emailFailed'));
        $statusChanges = count(array_filter($logs, fn($l) => $l['type'] === 'statusChanged'));
        $lineItemChanges = count(array_filter($logs, fn($l) => in_array($l['type'], ['lineItemAdded', 'lineItemRemoved', 'lineItemUpdated'])));
        $addressChanges = count(array_filter($logs, fn($l) => in_array($l['type'], ['shippingAddressSet', 'billingAddressSet', 'shippingAddressRemoved', 'billingAddressRemoved'])));
        $shippingChanges = count(array_filter($logs, fn($l) => $l['type'] === 'shippingMethodSet'));

        return [
            'order' => $lastSnapshot['order'] ?? [],
            'customer' => $lastSnapshot['customer'] ?? [],
            'events' => $events,
            'metrics' => [
                'totalEvents' => count($logs),
                'totalDurationMinutes' => $totalDurationMinutes,
                'paymentAttempts' => $paymentAttempts,
                'paymentFailed' => $paymentFailed,
                'emailFailures' => $emailFailures,
                'statusChanges' => $statusChanges,
                'lineItemChanges' => $lineItemChanges,
                'addressChanges' => $addressChanges,
                'shippingChanges' => $shippingChanges,
            ],
        ];
    }

    /**
     * Builds the per-order prompt from the supplied order context.
     *
     * @param array $context The structured order context.
     * @return string The interpolated prompt ready to send to Claude.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function buildPrompt(array $context): string
    {
        $order = $context['order'];
        $metrics = $context['metrics'];
        $events = $context['events'];

        $eventTimeline = implode("\n", array_map(
            static fn($e) => '- [' . $e['date'] . '] ' . $e['type'] . ($e['message'] ? ': ' . $e['message'] : ''),
            $events
        ));

        $settings = OrderLifecycle::$plugin->settings;
        $template = trim($settings->orderInsightsPrompt) ?: self::defaultOrderPrompt();

        return str_replace(
            ['{total}', '{status}', '{completed}', '{customerType}', '{totalEvents}', '{duration}',
             '{paymentAttempts}', '{paymentFailed}', '{emailFailures}', '{statusChanges}',
             '{lineItemChanges}', '{addressChanges}', '{shippingChanges}', '{eventTimeline}', ],
            [
                ($order['totalPrice'] ?? 'unknown') . ' ' . ($order['currency'] ?? ''),
                $order['statusHandle'] ?? 'unknown',
                ($order['isCompleted'] ?? false) ? 'yes' : 'no',
                ($context['customer']['isGuest'] ?? false) ? 'guest' : 'registered',
                $metrics['totalEvents'],
                $metrics['totalDurationMinutes'] !== null ? $metrics['totalDurationMinutes'] . ' minutes' : 'unknown',
                $metrics['paymentAttempts'],
                $metrics['paymentFailed'],
                $metrics['emailFailures'],
                $metrics['statusChanges'],
                $metrics['lineItemChanges'],
                $metrics['addressChanges'],
                $metrics['shippingChanges'],
                $eventTimeline,
            ],
            $template
        );
    }

    /**
     * Generates AI insights for store-wide statistics.
     *
     * @return Response The JSON response containing the insights or an error.
     * @throws MethodNotAllowedHttpException If the request is not a POST request.
     * @throws JsonException If the saved insights cannot be encoded.
     * @throws ForbiddenHttpException If the user lacks the required permission.
     * @throws \Exception If the store statistics cannot be built.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionStoreInsights(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('order-lifecycle:generateInsights');

        $days = (int) Craft::$app->getRequest()->getBodyParam('days', 30);
        $days = max(0, min(365, $days)); // 0 = all time

        $context = trim((string) Craft::$app->getRequest()->getBodyParam('context', ''));

        $settings = OrderLifecycle::$plugin->settings;
        $apiKey = $settings->getAnthropicApiKey();

        if (!$apiKey) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'No Anthropic API key configured.'),
            ]);
        }

        $stats = $this->getStoreStats($days);
        $prompt = $this->buildStorePrompt($stats, $days, $context);

        try {
            $insights = $this->callClaude($apiKey, $prompt);
        } catch (\Throwable $e) {
            Craft::error('Order Lifecycle store AI insights error: ' . $e->getMessage(), 'order-lifecycle');
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('order-lifecycle', 'Failed to get AI insights: {error}', ['error' => $e->getMessage()]),
            ]);
        }

        $saved = [
            'insights' => $insights,
            'days' => $days,
            'generatedAt' => (new DateTime())->format('c'),
        ];

        file_put_contents(AiInsightsWidget::storageFile(), json_encode($saved, JSON_THROW_ON_ERROR));

        return $this->asJson([
            'success' => true,
            'insights' => $insights,
            'generatedAt' => $saved['generatedAt'],
            'days' => $days,
        ]);
    }

    /**
     * Aggregates store-wide lifecycle statistics for the given period.
     *
     * @param int $days The look-back window in days; 0 means all time.
     * @return array The aggregated store statistics.
     * @throws \Exception If a date cannot be prepared or a snapshot decoded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function getStoreStats(int $days): array
    {
        $since = $days > 0
            ? Db::prepareDateForDb(DateTimeHelper::toDateTime('-' . $days . ' days'))
            : null;

        $totalLogs = (int) (new Query())->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $since])->count();

        $uniqueOrders = (int) (new Query())->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $since])->count('DISTINCT [[orderId]]');

        $cartsCreated = (int) (new Query())->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'cartCreated'])->andFilterWhere(['>=', 'dateCreated', $since])
            ->count('DISTINCT [[orderId]]');

        $ordersCompleted = (int) (new Query())->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])->andFilterWhere(['>=', 'dateCreated', $since])
            ->count('DISTINCT [[orderId]]');

        $paymentAttempts = (int) (new Query())->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'paymentAttempt'])->andFilterWhere(['>=', 'dateCreated', $since])->count();

        $emailsSent = (int) (new Query())->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'emailSent'])->andFilterWhere(['>=', 'dateCreated', $since])->count();

        $emailsFailed = (int) (new Query())->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'emailFailed'])->andFilterWhere(['>=', 'dateCreated', $since])->count();

        $refunds = (int) (new Query())->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'paymentRefunded'])->andFilterWhere(['>=', 'dateCreated', $since])->count();

        $couponApplied = (int) (new Query())->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'couponApplied'])->andFilterWhere(['>=', 'dateCreated', $since])->count();

        $topTypes = (new Query())->select(['type', 'COUNT(*) as count'])
            ->from('{{%orderlifecycle_logs}}')
            ->andFilterWhere(['>=', 'dateCreated', $since])
            ->groupBy(['type'])->orderBy(['count' => SORT_DESC])->limit(8)->all();

        // Average cart value from orderCompleted snapshots
        $completedLogs = (new Query())->select(['snapshot'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['type' => 'orderCompleted'])->andFilterWhere(['>=', 'dateCreated', $since])->all();

        $totalValue = 0;
        $valueCount = 0;
        $currency = '';
        foreach ($completedLogs as $log) {
            $snap = json_decode($log['snapshot'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $price = $snap['order']['totalPrice'] ?? null;
            if ($price !== null) {
                $totalValue += $price;
                $valueCount++;
                if (!$currency) {
                    $currency = $snap['order']['currency'] ?? '';
                }
            }
        }
        $avgCartValue = $valueCount > 0 ? round($totalValue / $valueCount, 2) : null;

        $conversionRate = $cartsCreated > 0 ? round(($ordersCompleted / $cartsCreated) * 100, 1) : 0;
        $abandonmentRate = $cartsCreated > 0 ? round((($cartsCreated - $ordersCompleted) / $cartsCreated) * 100, 1) : 0;
        $emailSuccessRate = ($emailsSent + $emailsFailed) > 0
            ? round(($emailsSent / ($emailsSent + $emailsFailed)) * 100, 1) : null;
        $avgPaymentAttempts = $ordersCompleted > 0 ? round($paymentAttempts / $ordersCompleted, 2) : null;

        return compact(
            'totalLogs', 'uniqueOrders', 'cartsCreated', 'ordersCompleted',
            'conversionRate', 'abandonmentRate', 'paymentAttempts', 'avgPaymentAttempts',
            'emailsSent', 'emailsFailed', 'emailSuccessRate',
            'refunds', 'couponApplied', 'avgCartValue', 'currency', 'topTypes'
        );
    }

    /**
     * Builds the store-wide prompt from the aggregated statistics.
     *
     * @param array $s The aggregated store statistics.
     * @param int $days The look-back window in days; 0 means all time.
     * @param string $context Optional additional context from the store manager.
     * @return string The interpolated prompt ready to send to Claude.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function buildStorePrompt(array $s, int $days, string $context = ''): string
    {
        $topTypes = implode("\n", array_map(
            static fn($t) => '  ' . $t['type'] . ': ' . $t['count'],
            array_filter($s['topTypes'], fn($t) => $t['type'] !== 'aiInsights')
        ));

        $daysLabel = $days === 0 ? 'all time' : 'the last ' . $days . ' days';

        $settings = OrderLifecycle::$plugin->settings;
        $template = trim($settings->storeInsightsPrompt) ?: self::defaultStorePrompt();

        $prompt = str_replace(
            ['{days}', '{totalLogs}', '{uniqueOrders}', '{cartsCreated}', '{ordersCompleted}',
             '{conversionRate}', '{abandonmentRate}', '{avgCartValue}', '{paymentAttempts}',
             '{avgPaymentAttempts}', '{refunds}', '{couponApplied}', '{emailsSent}',
             '{emailsFailed}', '{emailSuccessRate}', '{topTypes}', ],
            [
                $daysLabel,
                $s['totalLogs'],
                $s['uniqueOrders'],
                $s['cartsCreated'],
                $s['ordersCompleted'],
                $s['conversionRate'],
                $s['abandonmentRate'],
                $s['avgCartValue'] !== null ? $s['avgCartValue'] . ' ' . $s['currency'] : 'unknown',
                $s['paymentAttempts'],
                $s['avgPaymentAttempts'] ?? 'unknown',
                $s['refunds'],
                $s['couponApplied'],
                $s['emailsSent'],
                $s['emailsFailed'],
                $s['emailSuccessRate'] ?? 'unknown',
                $topTypes,
            ],
            $template
        );

        if ($context !== '') {
            $prompt .= "\n\nAdditional context from the store manager:\n" . $context;
        }

        return $prompt;
    }

    /**
     * Calls the Anthropic Claude API and returns the generated text.
     *
     * @param string $apiKey The resolved Anthropic API key.
     * @param string $prompt The prompt to send to Claude.
     * @return string The generated insight text.
     * @throws JsonException If the request payload cannot be encoded or the response decoded.
     * @throws RuntimeException If the network request fails or the API returns an error.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function callClaude(string $apiKey, string $prompt): string
    {
        $payload = json_encode([
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 512,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException('Network error: ' . $curlError);
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if ($httpCode !== 200) {
            $errorMsg = $data['error']['message'] ?? "API returned HTTP {$httpCode}";
            throw new RuntimeException($errorMsg);
        }

        return $data['content'][0]['text'] ?? '';
    }
}
