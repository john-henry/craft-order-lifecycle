<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\controllers;

use Craft;
use craft\web\Controller;
use Exception;
use johnhenry\orderlifecycle\assets\OrderLifecycleAsset;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Dashboard controller.
 *
 * Renders the plugin's full-page CP screens: the stats overview, AI insights
 * and the CSV export form.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class DashboardController extends Controller
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
     * Renders the overview / stats dashboard page.
     *
     * @return Response The rendered template response.
     * @throws Exception If the stats query fails.
     * @throws ForbiddenHttpException If the user lacks the required permission.
     * @throws InvalidConfigException If the stats service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('accessplugin-order-lifecycle');

        $request = Craft::$app->getRequest();
        $days = (int)$request->getParam('days', 30);

        $allowed = [0, 7, 14, 30, 60, 90];
        if (!in_array($days, $allowed, true)) {
            $days = 30;
        }

        $stats = OrderLifecycle::$plugin->getStats()->getStats($days);

        Craft::$app->getView()->registerAssetBundle(OrderLifecycleAsset::class);

        return $this->renderTemplate('order-lifecycle/dashboard/overview', [
            'stats' => $stats,
            'days' => $days,
        ]);
    }

    /**
     * Renders the AI insights full-page dashboard.
     *
     * @return Response The rendered template response.
     * @throws ForbiddenHttpException If the user lacks the required permission.
     * @throws InvalidConfigException If saved insights cannot be loaded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionInsights(): Response
    {
        $this->requirePermission('order-lifecycle:generateInsights');

        $saved = OrderLifecycle::getInstance()->getAiInsights()->getSavedStoreInsights();
        $hasApiKey = (bool)OrderLifecycle::$plugin->settings->getAnthropicApiKey();

        Craft::$app->getView()->registerAssetBundle(OrderLifecycleAsset::class);

        return $this->renderTemplate('order-lifecycle/dashboard/insights', [
            'saved' => $saved,
            'hasApiKey' => $hasApiKey,
        ]);
    }

    /**
     * Renders the CSV export form page.
     *
     * @return Response The rendered template response.
     * @throws ForbiddenHttpException If the user lacks the required permission.
     * @throws InvalidConfigException If the asset bundle cannot be registered.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionExport(): Response
    {
        $this->requirePermission('order-lifecycle:exportEvents');

        $eventTypes = EventType::cases();

        Craft::$app->getView()->registerAssetBundle(OrderLifecycleAsset::class);

        return $this->renderTemplate('order-lifecycle/dashboard/export', [
            'eventTypes' => $eventTypes,
        ]);
    }
}
