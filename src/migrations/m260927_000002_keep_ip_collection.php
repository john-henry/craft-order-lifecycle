<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\migrations;

use Craft;
use craft\db\Migration;
use yii\base\Exception;

/**
 * Keeps IP address collection on for installs that never saved the settings.
 *
 * The default moved from on to off. An install that never saved the settings
 * has no stored value, so without this it would stop recording IP addresses
 * on upgrade without anyone choosing to.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class m260927_000002_keep_ip_collection extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     * @throws Exception if project config can't be updated.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $schemaVersion = $projectConfig->get('plugins.order-lifecycle.schemaVersion', true);

        if ($schemaVersion !== null && version_compare($schemaVersion, '1.1.0', '>=')) {
            return true;
        }

        self::apply();

        return true;
    }

    /**
     * Keeps IP collection on where no choice is stored.
     *
     * @return void
     * @throws Exception if project config can't be updated.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function apply(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // Only an install that already had the plugin: a fresh one takes the new default
        if ($projectConfig->get('plugins.order-lifecycle', true) === null
            || $projectConfig->get('plugins.order-lifecycle.settings.collectUserIp') !== null) {
            return;
        }

        $projectConfig->set(
            'plugins.order-lifecycle.settings.collectUserIp',
            true,
            'Keep Order Lifecycle IP address collection on',
        );
    }

    /**
     * @inheritdoc
     *
     * @return bool Always false; the change isn't reverted.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function safeDown(): bool
    {
        echo "m260927_000002_keep_ip_collection cannot be reverted.\n";

        return false;
    }
}
