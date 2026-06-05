<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

/**
 * Order Lifecycle config.php
 *
 * This file exists only as a template for the Order Lifecycle settings.
 * It does nothing on its own.
 *
 * Don't edit this file, instead copy it to 'craft/config' as 'order-lifecycle.php'
 * and make your changes there to override default settings.
 *
 * Once copied to 'craft/config', this file will be multi-environment aware as
 * well, so you can have different settings groups for each environment, just as
 * you do for 'general.php'
 */

return [
    '*' => [

        // --- Event logging ---

        // Log line item additions, removals and quantity changes
        'logLineItems' => true,

        // Log order status transitions
        'logStatusChanges' => true,

        // Log the orderCompleted event
        'logOrderComplete' => true,

        // Log the orderPaid event
        'logOrderPaid' => true,

        // Log order-related emails sent and failed
        'logEmailSent' => true,

        // Log coupon codes applied or removed
        'logCouponChanges' => true,

        // Log shipping and billing address changes
        'logAddressChanges' => true,

        // Log customer email set or removed
        'logCustomerChanges' => true,

        // Log shipping method selected or changed
        'logShippingMethodChanges' => true,

        // Log each payment attempt
        'logPaymentAttempts' => true,

        // Log payment authorization events
        'logPaymentAuthorized' => true,

        // Log payment capture events
        'logPaymentCaptured' => true,

        // Log refund events
        'logPaymentRefunded' => true,

        // Log every individual transaction state change (high volume - off by default)
        'logPaymentTransactions' => false,

        // --- Data collection ---

        // Store the IP address of the user who triggered each event
        'collectUserIp' => true,

        // Store the logged-in user ID alongside each event
        'collectUserId' => true,

        // --- Display ---

        // Show the Order Lifecycle Stats dashboard widget
        'showLifecycleStats' => true,

        // --- Log retention ---

        // Automatically delete logs older than this many days. 0 = disabled. Max 365.
        'autoPruneLogs' => 0,

        // --- API ---

        // Anthropic API key for AI Insights (set to '$ENV_VAR_NAME' to use an env var)
        // 'anthropicApiKey' => '$ANTHROPIC_API_KEY',

        // Custom prompt for per-order AI insights. Leave empty to use the built-in default.
        // Use {placeholder} tokens - see docs for the full list.
        // 'orderInsightsPrompt' => '',

        // Custom prompt for the store-level AI insights dashboard widget.
        // Use {placeholder} tokens - see docs for the full list.
        // 'storeInsightsPrompt' => '',
    ],

    'dev' => [
        'autoPruneLogs' => 0,
        'logPaymentTransactions' => true, // useful for debugging payment flows
    ],

    'production' => [
        'autoPruneLogs' => 90,
        'collectUserIp' => true,
        'collectUserId' => true,
    ],
];
