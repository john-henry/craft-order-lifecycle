<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\widgets;

use Craft;
use craft\base\Widget;
use johnhenry\orderlifecycle\assets\OrderLifecycleAsset;
use johnhenry\orderlifecycle\OrderLifecycle;

/**
 * AI Insights dashboard widget.
 *
 * Renders the store-wide AI insights panel on the CP dashboard. The time
 * period is chosen inline in the widget body rather than via widget settings,
 * so this widget has none - the panel's own Period selector is the only place
 * that value is configured.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class AiInsightsWidget extends Widget
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int The default number of days to analyse before the Period selector is changed.
     */
    public int $days = 30;

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The translated widget display name.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function displayName(): string
    {
        return Craft::t('order-lifecycle', 'Order Lifecycle AI Insights');
    }

    /**
     * @inheritdoc
     *
     * @return string The widget icon path.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function icon(): string
    {
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    /**
     * @inheritdoc
     *
     * @return int|null The maximum column span for the widget.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function maxColspan(): ?int
    {
        return 3;
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string|null The widget subtitle.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getSubtitle(): ?string
    {
        return Craft::t('order-lifecycle', "Summarize your store's conversion, payments and anomalies with AI.");
    }

    /**
     * @inheritdoc
     *
     * @return string|null The rendered widget body HTML.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getBodyHtml(): ?string
    {
        $settings = OrderLifecycle::$plugin->settings;

        if (!$settings->getAnthropicApiKey()) {
            return Craft::$app->getView()->renderTemplate('order-lifecycle/_widgets/ai-insights/no-key');
        }

        Craft::$app->getView()->registerAssetBundle(OrderLifecycleAsset::class);

        $saved = OrderLifecycle::getInstance()->getAiInsights()->getSavedStoreInsights();

        return Craft::$app->getView()->renderTemplate('order-lifecycle/_widgets/ai-insights/body', [
            'widget' => $this,
            'saved' => $saved,
        ]);
    }
}
