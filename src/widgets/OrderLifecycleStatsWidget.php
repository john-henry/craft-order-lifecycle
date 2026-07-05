<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\widgets;

use Craft;
use craft\base\Widget;
use Exception;
use johnhenry\orderlifecycle\assets\OrderLifecycleAsset;
use johnhenry\orderlifecycle\OrderLifecycle;

/**
 * Order Lifecycle stats dashboard widget.
 *
 * Renders the lifecycle statistics summary on the CP dashboard for a
 * configurable look-back period.
 *
 * @property-read array $stats
 * @property-read null|string $bodyHtml
 * @property-read null|string $settingsHtml
 * @author John Henry Donovan
 * @since 1.0.0
 */
class OrderLifecycleStatsWidget extends Widget
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int Number of days to calculate stats for.
     */
    public int $days = 30;

    /**
     * @var bool Whether to show the "Top Events" list below the stat grid.
     */
    public bool $showTopEvents = true;

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
        return Craft::t('order-lifecycle', 'Order Lifecycle Stats');
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
        return 2;
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The validation rules.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function rules(): array
    {
        return [
            [['days'], 'integer', 'min' => 1],
            [['showTopEvents'], 'boolean'],
        ];
    }

    /**
     * @inheritdoc
     *
     * Shows the order count in scope (e.g. "Last 11 orders") in the widget's
     * native title chrome, so the stat grid's Total Events card doesn't have
     * to repeat it in its own caption.
     *
     * @return string|null The widget subtitle.
     * @throws Exception If the stats query fails.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getSubtitle(): ?string
    {
        $stats = OrderLifecycle::$plugin->getStats()->getStats($this->days);

        return Craft::t('order-lifecycle', 'Last {count} orders', ['count' => $stats['uniqueOrders']]);
    }

    /**
     * @inheritdoc
     *
     * @return string|null The rendered widget body HTML.
     * @throws Exception If the stats query fails.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getBodyHtml(): ?string
    {
        Craft::$app->getView()->registerAssetBundle(OrderLifecycleAsset::class);
        $stats = OrderLifecycle::$plugin->getStats()->getStats($this->days);

        return Craft::$app->getView()->renderTemplate('order-lifecycle/_widgets/stats/body', [
            'widget' => $this,
            'stats' => $stats,
            'showTopEvents' => $this->showTopEvents,
        ]);
    }

    /**
     * @inheritdoc
     *
     * @return string|null The rendered settings HTML.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('order-lifecycle/_widgets/stats/settings', [
            'widget' => $this,
        ]);
    }
}
