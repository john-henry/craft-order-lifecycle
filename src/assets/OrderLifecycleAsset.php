<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Order Lifecycle CP asset bundle.
 *
 * Registers the Control Panel CSS and JavaScript for the plugin's dashboard,
 * order field and CSV export screens.
 *
 * @author John Henry Donovan
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
     * @author John Henry Donovan
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
}
