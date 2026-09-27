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
 * @author John Henry Donovan <info@johnhenry.ie>
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
    // Const Properties
    // =========================================================================

    /**
     * @var string[] Every message the plugin passes through Craft.t() in
     * JavaScript, whether from a .js file or an inline js block.
     *
     * Craft.t() reads Craft.translations, which is only populated by
     * View::registerTranslations(). Without this list a translated install
     * silently renders these strings in English, because Craft.t() falls back
     * to the source message. Nothing is emitted on an English install:
     * registerTranslations() skips any message whose translation matches the
     * source, so this costs those installs nothing.
     *
     * JsTranslationsTest keeps this in sync with the code: add a Craft.t()
     * call without adding it here and that test fails.
     */
    public const JS_TRANSLATIONS = [
        'All time',
        'Analyzing order…',
        'Column moved to position {position} of {total}.',
        'Copied!',
        'Copy',
        'Deselect All',
        'Failed to get insights.',
        'Generate insights',
        'Generated {date}',
        'Generating insights…',
        'Hide full snapshot',
        'Insights generated.',
        'Last {days} days',
        'Newest first',
        'Oldest first',
        'Priority action',
        'Refresh Insights',
        'Refresh',
        'Request failed. Please try again.',
        'Select All',
        'Showing all {count} events',
        'Showing {visible} of {total} events',
        'Sorted newest first',
        'Sorted oldest first',
        'View full snapshot',
    ];

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
    public string $schemaVersion = '1.1.0';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        Craft::setAlias('@johnhenry/orderlifecycle', __DIR__);

        $this->_registerFieldTypes();
        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerListeners();
        $this->_registerGarbageCollection();
        $this->_setupAutoPrune();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpUrlRules();
            $this->_registerWidgets();
        }
    }
}
