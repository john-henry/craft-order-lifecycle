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
 * Renders the store-wide AI insights panel on the CP dashboard and manages the
 * on-disk storage of the most recently generated insights.
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
     * @var int The number of days to analyse.
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
     * @return string|null The widget icon path.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function icon(): ?string
    {
        return Craft::getAlias('@johnhenry/orderlifecycle/icon.svg');
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

    /**
     * Returns the path to the file storing the most recent store insights.
     *
     * @return string The absolute path to the storage file.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function storageFile(): string
    {
        $dir = Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . 'order-lifecycle';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'store-insights.json';
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string|null The rendered settings HTML.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('order-lifecycle/_widgets/ai-insights/settings', [
            'widget' => $this,
        ]);
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

        $saved = $this->loadSavedInsights();

        return Craft::$app->getView()->renderTemplate('order-lifecycle/_widgets/ai-insights/body', [
            'widget' => $this,
            'saved' => $saved,
        ]);
    }

    /**
     * Loads the most recently saved store insights from disk.
     *
     * @return array|null The decoded insights, or null if none are stored.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function loadSavedInsights(): ?array
    {
        $file = self::storageFile();
        if (!file_exists($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }
}
