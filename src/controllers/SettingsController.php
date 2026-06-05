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

        // Get site options
        $siteOptions = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteOptions[] = [
                'value' => $site->id,
                'label' => $site->name,
            ];
        }

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
        $this->requireAdmin();

        $request = Craft::$app->getRequest();

        $postedSettings = $request->getBodyParam('settings', []);

        $settings = OrderLifecycle::$plugin->settings;
        $settings->setAttributes($postedSettings, false);
        $settings->anthropicApiKey = $this->request->getBodyParam('anthropicApiKey', $settings->anthropicApiKey);

        // Validate the settings
        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('order-lifecycle', 'Couldn\'t save plugin settings.'));

            // Send the settings back to the template
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
