<?php

/**
 * Order Lifecycle plugin for Craft CMS 5.
 *
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle;

use Craft;
use craft\base\Plugin as BasePlugin;
use johnhenry\orderlifecycle\base\PluginTrait;
use johnhenry\orderlifecycle\models\SettingsModel;
use johnhenry\orderlifecycle\services\ServicesTrait;
use yii\base\InvalidConfigException;

/**
 * Order Lifecycle plugin.
 *
 * Records granular Commerce order lifecycle events (cart creation, line item
 * changes, payments, emails, status changes) and surfaces them through a CP
 * dashboard, an order field, dashboard widgets, CSV export and on-demand AI
 * insights.
 *
 * @property-read SettingsModel $settings
 * @author John Henry Donovan
 * @since 1.0.0
 */
class OrderLifecycle extends BasePlugin
{
    // =========================================================================
    // Traits
    // =========================================================================

    use ServicesTrait;
    use PluginTrait;

    // =========================================================================
    // Static Properties
    // =========================================================================

    /**
     * @var OrderLifecycle The plugin instance.
     */
    public static OrderLifecycle $plugin;

    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.1';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        Craft::setAlias('@johnhenry/orderlifecycle', __DIR__);

        $this->_registerFieldTypes();
        $this->_registerTwigVariable();
        $this->_registerListeners();
        $this->_registerGarbageCollection();
        $this->_setupAutoPrune();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpUrlRules();
            $this->_registerWidgets();
            $this->_registerPermissions();
        }
    }
}
