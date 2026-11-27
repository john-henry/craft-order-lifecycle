<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Settings model.
 *
 * Holds the plugin's configurable options: which lifecycle events to log,
 * privacy/data collection toggles, async logging, auto-prune retention and the
 * AI insights configuration.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class SettingsModel extends Model
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether to log line item add/remove/update events.
     */
    public bool $logLineItems = true;

    /**
     * @var bool Whether to log order status changes.
     */
    public bool $logStatusChanges = true;

    /**
     * @var bool Whether to log the order completion event.
     */
    public bool $logOrderComplete = true;

    /**
     * @var bool Whether to log the order paid event.
     */
    public bool $logOrderPaid = true;

    /**
     * @var bool Whether to log email sent/failed events.
     */
    public bool $logEmailSent = true;

    /**
     * @var bool Whether to show the lifecycle summary cards at the top of the order field.
     */
    public bool $showLifecycleStats = true;

    /**
     * @var bool Whether to log coupon apply/remove events.
     */
    public bool $logCouponChanges = true;

    /**
     * @var bool Whether to log billing/shipping address changes.
     */
    public bool $logAddressChanges = true;

    /**
     * @var bool Whether to log customer changes.
     */
    public bool $logCustomerChanges = true;

    /**
     * @var bool Whether to log shipping method changes.
     */
    public bool $logShippingMethodChanges = true;

    /**
     * @var bool Whether to log payment attempts (before/after process).
     */
    public bool $logPaymentAttempts = true;

    /**
     * @var bool Whether to log payment authorization events.
     */
    public bool $logPaymentAuthorized = true;

    /**
     * @var bool Whether to log payment capture events.
     */
    public bool $logPaymentCaptured = true;

    /**
     * @var bool Whether to log payment refund events.
     */
    public bool $logPaymentRefunded = true;

    /**
     * @var bool Whether to log every payment transaction (verbose).
     */
    public bool $logPaymentTransactions = false;

    /**
     * @var bool Whether to store the IP address of users making order changes.
     * Off unless asked for: an IP address is personal data.
     */
    public bool $collectUserIp = false;

    /**
     * @var bool Whether to store the user ID of users making order changes.
     */
    public bool $collectUserId = true;

    /**
     * @var bool Whether to write log entries via the queue instead of inline.
     */
    public bool $asyncLogging = false;

    /**
     * @var int The age in days after which logs are auto-pruned; 0 disables pruning.
     */
    public int $autoPruneLogs = 0;

    /**
     * @var string The Anthropic API key (env var reference) used for AI insights.
     */
    public string $anthropicApiKey = '';

    /**
     * @var string The presentation style for per-order AI insights ('structured' or 'narrative').
     */
    public string $orderInsightsStyle = 'structured';

    /**
     * @var string An optional custom prompt template for per-order AI insights.
     */
    public string $orderInsightsPrompt = '';

    /**
     * @var string An optional custom prompt template for store-wide AI insights.
     */
    public string $storeInsightsPrompt = '';

    // =========================================================================
    // Public Methods
    // =========================================================================


    /**
     * @inheritdoc
     *
     * @return array<string, string> The translated attribute labels.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function attributeLabels(): array
    {
        return [
            'logLineItems' => Craft::t('order-lifecycle', 'Log Line Item Changes'),
            'logStatusChanges' => Craft::t('order-lifecycle', 'Log Order Status Changes'),
            'logOrderComplete' => Craft::t('order-lifecycle', 'Log Order Complete Event'),
            'logOrderPaid' => Craft::t('order-lifecycle', 'Log Order Paid Event'),
            'logEmailSent' => Craft::t('order-lifecycle', 'Log Email Sent Events'),
            'showLifecycleStats' => Craft::t('order-lifecycle', 'Show Lifecycle Stats'),
            'logCouponChanges' => Craft::t('order-lifecycle', 'Log Coupon/Discount Changes'),
            'logAddressChanges' => Craft::t('order-lifecycle', 'Log Address Changes'),
            'logCustomerChanges' => Craft::t('order-lifecycle', 'Log Customer Changes'),
            'logShippingMethodChanges' => Craft::t('order-lifecycle', 'Log Shipping Method Changes'),
            'logPaymentAttempts' => Craft::t('order-lifecycle', 'Log Payment Attempts'),
            'logPaymentAuthorized' => Craft::t('order-lifecycle', 'Log Payment Authorized'),
            'logPaymentCaptured' => Craft::t('order-lifecycle', 'Log Payment Captured'),
            'logPaymentRefunded' => Craft::t('order-lifecycle', 'Log Payment Refunded'),
            'logPaymentTransactions' => Craft::t('order-lifecycle', 'Log All Payment Transactions'),
            'collectUserIp' => Craft::t('order-lifecycle', 'Collect User IP Address'),
            'collectUserId' => Craft::t('order-lifecycle', 'Collect User ID'),
            'asyncLogging' => Craft::t('order-lifecycle', 'Async Queue Logging'),
            'autoPruneLogs' => Craft::t('order-lifecycle', 'Auto-Prune Logs After (Days)'),
            'orderInsightsStyle' => Craft::t('order-lifecycle', 'Per-Order Insights Style'),
            'orderInsightsPrompt' => Craft::t('order-lifecycle', 'Per-Order Insights Prompt'),
            'storeInsightsPrompt' => Craft::t('order-lifecycle', 'Store Insights Prompt'),
            'anthropicApiKey' => Craft::t('order-lifecycle', 'Anthropic API Key'),
        ];
    }

    /**
     * Returns the resolved Anthropic API key.
     *
     * @return string The parsed API key, or an empty string if unset.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getAnthropicApiKey(): string
    {
        return trim((string)App::parseEnv($this->anthropicApiKey));
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array<int, mixed> The validation rules.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['logLineItems', 'logStatusChanges', 'logOrderComplete', 'logOrderPaid',
                'logEmailSent', 'showLifecycleStats', 'logCouponChanges', 'logAddressChanges',
                'logCustomerChanges', 'logShippingMethodChanges', 'logPaymentAttempts',
                'logPaymentAuthorized', 'logPaymentCaptured', 'logPaymentRefunded',
                'logPaymentTransactions', 'collectUserIp', 'collectUserId', 'asyncLogging', ], 'boolean'],
            ['autoPruneLogs', 'integer', 'min' => 0, 'max' => 365],
            ['autoPruneLogs', 'required'],
            ['anthropicApiKey', 'string', 'max' => 255],
            // an environment variable, never the key itself: settings are
            // saved to project config, which is committed
            // (a key set in config/order-lifecycle.php is never saved, so it's left alone)
            ['anthropicApiKey', 'match',
                'pattern' => '/^(\$[A-Za-z_][A-Za-z0-9_]*)?$/',
                'message' => Craft::t('order-lifecycle', 'Enter an environment variable holding the key, like $ANTHROPIC_API_KEY, not the key itself.'),
                'when' => static fn() => !array_key_exists('anthropicApiKey', Craft::$app->getConfig()->getConfigFromFile('order-lifecycle')),
            ],
            ['orderInsightsStyle', 'in', 'range' => ['structured', 'narrative']],
            [['orderInsightsPrompt', 'storeInsightsPrompt'], 'string', 'max' => 5000],
        ]);
    }
}
