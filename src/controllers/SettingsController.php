<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\controllers;

use Craft;
use craft\errors\MissingComponentException;
use craft\web\Controller;
use johnhenry\orderlifecycle\OrderLifecycle;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * Settings controller.
 *
 * Renders and persists the plugin's settings screen.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class SettingsController extends Controller
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
     * Renders the plugin settings edit screen.
     *
     * @return Response|null The rendered template response.
     * @throws ForbiddenHttpException If the user is not an admin.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionEdit(): ?Response
    {
        $this->requireAdmin();

        $settings = OrderLifecycle::$plugin->settings;

        return $this->renderTemplate('order-lifecycle/_settings', [
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('order-lifecycle'),
        ]);
    }

    /**
     * Saves the plugin settings.
     *
     * @return Response|null A redirect response on success, or a model failure response.
     * @throws MissingComponentException If the session component is unavailable.
     * @throws BadRequestHttpException If the request is malformed.
     * @throws MethodNotAllowedHttpException If the request is not a POST request.
     * @throws ForbiddenHttpException If the user is not an admin.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(requireAdminChanges: true);

        $request = Craft::$app->getRequest();

        $settings = OrderLifecycle::$plugin->settings;

        $settings->logLineItems = (bool)$request->getBodyParam('settings[logLineItems]', $settings->logLineItems);
        $settings->logStatusChanges = (bool)$request->getBodyParam('settings[logStatusChanges]', $settings->logStatusChanges);
        $settings->logOrderComplete = (bool)$request->getBodyParam('settings[logOrderComplete]', $settings->logOrderComplete);
        $settings->logOrderPaid = (bool)$request->getBodyParam('settings[logOrderPaid]', $settings->logOrderPaid);
        $settings->logEmailSent = (bool)$request->getBodyParam('settings[logEmailSent]', $settings->logEmailSent);
        $settings->showLifecycleStats = (bool)$request->getBodyParam('settings[showLifecycleStats]', $settings->showLifecycleStats);
        $settings->logCouponChanges = (bool)$request->getBodyParam('settings[logCouponChanges]', $settings->logCouponChanges);
        $settings->logAddressChanges = (bool)$request->getBodyParam('settings[logAddressChanges]', $settings->logAddressChanges);
        $settings->logCustomerChanges = (bool)$request->getBodyParam('settings[logCustomerChanges]', $settings->logCustomerChanges);
        $settings->logShippingMethodChanges = (bool)$request->getBodyParam('settings[logShippingMethodChanges]', $settings->logShippingMethodChanges);
        $settings->logPaymentAttempts = (bool)$request->getBodyParam('settings[logPaymentAttempts]', $settings->logPaymentAttempts);
        $settings->logPaymentAuthorized = (bool)$request->getBodyParam('settings[logPaymentAuthorized]', $settings->logPaymentAuthorized);
        $settings->logPaymentCaptured = (bool)$request->getBodyParam('settings[logPaymentCaptured]', $settings->logPaymentCaptured);
        $settings->logPaymentRefunded = (bool)$request->getBodyParam('settings[logPaymentRefunded]', $settings->logPaymentRefunded);
        $settings->logPaymentTransactions = (bool)$request->getBodyParam('settings[logPaymentTransactions]', $settings->logPaymentTransactions);
        $settings->collectUserIp = (bool)$request->getBodyParam('settings[collectUserIp]', $settings->collectUserIp);
        $settings->collectUserId = (bool)$request->getBodyParam('settings[collectUserId]', $settings->collectUserId);
        $settings->asyncLogging = (bool)$request->getBodyParam('settings[asyncLogging]', $settings->asyncLogging);
        $settings->orderInsightsStyle = (string)$request->getBodyParam('settings[orderInsightsStyle]', $settings->orderInsightsStyle);
        $settings->orderInsightsPrompt = (string)$request->getBodyParam('settings[orderInsightsPrompt]', $settings->orderInsightsPrompt);
        $settings->storeInsightsPrompt = (string)$request->getBodyParam('settings[storeInsightsPrompt]', $settings->storeInsightsPrompt);

        // these two are posted as top-level fields, not nested under settings[]
        $settings->autoPruneLogs = (int)$request->getBodyParam('autoPruneLogs', $settings->autoPruneLogs);
        $settings->anthropicApiKey = (string)$request->getBodyParam('anthropicApiKey', $settings->anthropicApiKey);

        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('order-lifecycle', 'Couldn\'t save plugin settings.'));

            return $this->asModelFailure(
                $settings,
                Craft::t('order-lifecycle', 'Couldn\'t save plugin settings.'),
                'settings'
            );
        }

        if (!Craft::$app->getPlugins()->savePluginSettings(OrderLifecycle::$plugin, $settings->getAttributes())) {
            Craft::$app->getSession()->setError(Craft::t('order-lifecycle', 'Couldn\'t save plugin settings.'));

            return $this->asModelFailure(
                $settings,
                Craft::t('order-lifecycle', 'Couldn\'t save plugin settings.'),
                'settings'
            );
        }

        $notice = Craft::t('order-lifecycle', 'Plugin settings saved.');

        Craft::$app->getSession()->setSuccess($notice);

        return $this->redirectToPostedUrl();
    }
}
