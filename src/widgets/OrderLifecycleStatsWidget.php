<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\widgets;

use Craft;
use craft\base\Widget;
use johnhenry\orderlifecycle\assets\OrderLifecycleAsset;
use johnhenry\orderlifecycle\OrderLifecycle;
use Throwable;

/**
 * Order Lifecycle stats dashboard widget.
 *
 * Renders the lifecycle statistics summary on the CP dashboard for a
 * configurable look-back period.
 *
 * @property-read null|string $bodyHtml
 * @property-read null|string $settingsHtml
 * @property-read null|string $subtitle
 * @author John Henry Donovan <info@johnhenry.ie>
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
    // Private Properties
    // =========================================================================

    /**
     * @var array<string, mixed>|null The stats for the widget's look-back
     * period, or null if the query failed. Memoised because Craft renders the
     * subtitle and the body in separate calls and both need the same figures.
     */
    private ?array $_stats = null;

    /**
     * @var bool Whether the stats query has already been attempted.
     */
    private bool $_statsLoaded = false;

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The translated widget display name.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function maxColspan(): ?int
    {
        return 2;
    }

    /**
     * @inheritdoc
     *
     * Hidden from users who can't access the plugin or manage orders.
     *
     * @return bool Whether the current user can add the widget.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function isSelectable(): bool
    {
        return self::_userCanView() && parent::isSelectable();
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Shows the order count in scope (e.g. "Last 11 orders") in the widget's
     * native title chrome, so the stat grid's Total Events card doesn't have
     * to repeat it in its own caption.
     *
     * @return string|null The widget subtitle, or null if the stats are
     *                      unavailable.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSubtitle(): ?string
    {
        if (!self::_userCanView()) {
            return null;
        }

        $stats = $this->_getStats();

        if ($stats === null) {
            return null;
        }

        return Craft::t('order-lifecycle', 'Last {count} orders', ['count' => $stats['uniqueOrders']]);
    }

    /**
     * @inheritdoc
     *
     * @return string|null The rendered widget body HTML.
     * @throws Throwable If the widget template cannot be rendered.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBodyHtml(): ?string
    {
        $view = Craft::$app->getView();

        if (!self::_userCanView()) {
            return $view->renderTemplate('order-lifecycle/_widgets/_error', [
                'error' => Craft::t('order-lifecycle', 'You don’t have permission to view this widget.'),
            ]);
        }

        $view->registerAssetBundle(OrderLifecycleAsset::class);

        $stats = $this->_getStats();

        if ($stats === null) {
            return $view->renderTemplate('order-lifecycle/_widgets/_error', [
                'error' => Craft::t('order-lifecycle', 'Couldn\'t load the lifecycle stats. Check the logs for details.'),
            ]);
        }

        return $view->renderTemplate('order-lifecycle/_widgets/stats/body', [
            'widget' => $this,
            'stats' => $stats,
            'showTopEvents' => $this->showTopEvents,
        ]);
    }

    /**
     * @inheritdoc
     *
     * @return string|null The rendered settings HTML.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('order-lifecycle/_widgets/stats/settings', [
            'widget' => $this,
        ]);
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The validation rules.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['days'], 'integer', 'min' => 1];
        $rules[] = [['showTopEvents'], 'boolean'];

        return $rules;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Whether the current user may see what the widget shows: the plugin's
     * section and store-wide order figures.
     *
     * Checked again when rendering, since a widget stays on a dashboard after
     * the permission that let it be added is taken away.
     *
     * @return bool
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _userCanView(): bool
    {
        $user = Craft::$app->getUser();

        return $user->checkPermission('accessplugin-order-lifecycle')
            && $user->checkPermission('commerce-manageOrders');
    }

    /**
     * Returns the stats for the widget's look-back period.
     *
     * Craft's DashboardController renders every widget body in turn without a
     * try/catch of its own, so a query failure in one widget would take the
     * whole dashboard down with it. Contained here instead, and memoised so the
     * subtitle and the body share one query rather than running two.
     *
     * @return array<string, mixed>|null The stats, or null if the query failed.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _getStats(): ?array
    {
        if ($this->_statsLoaded) {
            return $this->_stats;
        }

        $this->_statsLoaded = true;

        try {
            $this->_stats = OrderLifecycle::$plugin->getStats()->getStats($this->days);
        } catch (Throwable $e) {
            Craft::error(
                'Order Lifecycle stats widget failed to load: ' . $e->getMessage(),
                'order-lifecycle',
            );
        }

        return $this->_stats;
    }
}
