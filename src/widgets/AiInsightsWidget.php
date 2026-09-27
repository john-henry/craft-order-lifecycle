<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\widgets;

use Craft;
use craft\base\Widget;
use johnhenry\orderlifecycle\assets\OrderLifecycleAsset;
use johnhenry\orderlifecycle\controllers\AiController;
use johnhenry\orderlifecycle\OrderLifecycle;
use Throwable;

/**
 * AI Insights dashboard widget.
 *
 * Renders the store-wide AI insights panel on the CP dashboard. The time
 * period is chosen inline in the widget body rather than via widget settings,
 * so this widget has none - the panel's own Period selector is the only place
 * that value is configured.
 *
 * @property-read null|string $bodyHtml
 * @property-read null|string $subtitle
 * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
        return 3;
    }

    /**
     * @inheritdoc
     *
     * Hidden from users who can't generate AI insights or manage orders.
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
     * @return string|null The widget subtitle.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @throws Throwable If the widget template cannot be rendered.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBodyHtml(): ?string
    {
        $view = Craft::$app->getView();
        $settings = OrderLifecycle::$plugin->getSettings();

        if (!self::_userCanView()) {
            return $view->renderTemplate('order-lifecycle/_widgets/_error', [
                'error' => Craft::t('order-lifecycle', 'You don’t have permission to view this widget.'),
            ]);
        }

        if (!$settings->getAnthropicApiKey()) {
            return $view->renderTemplate('order-lifecycle/_widgets/ai-insights/no-key');
        }

        $view->registerAssetBundle(OrderLifecycleAsset::class);

        // Craft's DashboardController renders every widget body in turn with no
        // try/catch of its own, so an unavailable cache or a malformed stored
        // payload here would take the whole dashboard down, not just this panel.
        try {
            $saved = OrderLifecycle::getInstance()->getAiInsights()->getSavedStoreInsights();
        } catch (Throwable $e) {
            Craft::error(
                'AI Insights widget failed to load: ' . $e->getMessage(),
                'order-lifecycle',
            );

            return $view->renderTemplate('order-lifecycle/_widgets/_error', [
                'error' => Craft::t('order-lifecycle', 'Couldn\'t load the saved insights. Check the logs for details.'),
            ]);
        }

        return $view->renderTemplate('order-lifecycle/_widgets/ai-insights/body', [
            'widget' => $this,
            'saved' => $saved,
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Whether the current user may see what the widget shows: store-wide
     * order figures, and the right to spend on AI calls.
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

        return $user->checkPermission(AiController::PERMISSION_GENERATE_INSIGHTS)
            && $user->checkPermission('commerce-manageOrders');
    }
}
