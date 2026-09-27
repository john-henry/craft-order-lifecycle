<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View as CraftView;
use johnhenry\orderlifecycle\OrderLifecycle;
use yii\web\View as YiiView;

/**
 * Order Lifecycle CP asset bundle.
 *
 * Registers the Control Panel CSS and JavaScript for the plugin's dashboard,
 * order field and CSV export screens.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class OrderLifecycleAsset extends AssetBundle
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function init(): void
    {
        $this->sourcePath = '@johnhenry/orderlifecycle/resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/cp.css',
            'css/export.css',
        ];

        $this->js = [
            'js/cp.js',
            'js/export.js',
        ];

        parent::init();
    }

    /**
     * @inheritdoc
     *
     * Registers the plugin's JavaScript messages alongside the files, so that
     * Craft.t() has something to look them up in. Doing it here rather than at
     * each call site means every screen that registers this bundle, the order
     * field, the dashboard, the widgets and the settings, gets them.
     *
     * @param YiiView $view The view registering the bundle.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof CraftView) {
            $view->registerTranslations('order-lifecycle', OrderLifecycle::JS_TRANSLATIONS);
        }
    }
}
