<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\jobs;

use Craft;
use craft\queue\BaseJob;
use johnhenry\orderlifecycle\OrderLifecycle;
use Throwable;

/**
 * Generates store-wide AI insights off the web request.
 *
 * Aggregates the store statistics, builds the prompt, calls Anthropic Claude
 * and persists the result via the cache so the dashboard/widget can render it
 * once the job completes.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class GenerateStoreInsights extends BaseJob
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int The look-back window in days; 0 means all time.
     */
    public int $days = 30;

    /**
     * @var string Optional free-text context from the store manager.
     */
    public string $context = '';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $queue The queue the job belongs to.
     * @return void
     * @throws Throwable If the insight generation or persistence fails.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function execute($queue): void
    {
        $ai = OrderLifecycle::getInstance()->getAiInsights();
        $apiKey = $ai->getApiKey();

        if ($apiKey === '') {
            return;
        }

        try {
            $stats = OrderLifecycle::getInstance()->getStats()->getStoreInsightStats($this->days);
            $prompt = $ai->buildStorePrompt($stats, $this->days, $this->context);

            $insights = $ai->generateInsights($apiKey, $prompt);
            $ai->saveStoreInsights($insights, $this->days);
        } catch (Throwable $e) {
            // re-throw so the job shows as failed/retryable in the Queue
            // Manager instead of silently succeeding with no insights saved
            Craft::error('Order Lifecycle store insights job failed: ' . $e->getMessage(), 'order-lifecycle');
            throw $e;
        }
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
        return Craft::t('order-lifecycle', 'Generate store-wide AI insights');
    }
}
