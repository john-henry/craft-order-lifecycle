<?php

use johnhenry\orderlifecycle\models\SettingsModel;

// ---------------------------------------------------------------------------
// Default values
// ---------------------------------------------------------------------------

describe('SettingsModel defaults', function () {
    it('ships with every logging toggle enabled by default', function () {
        $settings = new SettingsModel();

        expect($settings->logLineItems)->toBeTrue()
            ->and($settings->logStatusChanges)->toBeTrue()
            ->and($settings->logOrderComplete)->toBeTrue()
            ->and($settings->logOrderPaid)->toBeTrue()
            ->and($settings->logEmailSent)->toBeTrue()
            ->and($settings->logCouponChanges)->toBeTrue()
            ->and($settings->logAddressChanges)->toBeTrue()
            ->and($settings->logCustomerChanges)->toBeTrue()
            ->and($settings->logShippingMethodChanges)->toBeTrue()
            ->and($settings->logPaymentAttempts)->toBeTrue()
            ->and($settings->logPaymentAuthorized)->toBeTrue()
            ->and($settings->logPaymentCaptured)->toBeTrue()
            ->and($settings->logPaymentRefunded)->toBeTrue();
    });

    it('ships with verbose / privacy-sensitive toggles disabled by default', function () {
        $settings = new SettingsModel();

        expect($settings->logPaymentTransactions)->toBeFalse()
            ->and($settings->asyncLogging)->toBeFalse();
    });

    it('ships with data-collection enabled by default', function () {
        $settings = new SettingsModel();

        expect($settings->collectUserIp)->toBeTrue()
            ->and($settings->collectUserId)->toBeTrue();
    });

    it('ships with pruning disabled (0) by default', function () {
        $settings = new SettingsModel();

        expect($settings->autoPruneLogs)->toBe(0);
    });

    it('ships with an empty Anthropic API key by default', function () {
        $settings = new SettingsModel();

        expect($settings->anthropicApiKey)->toBe('');
    });
});

// ---------------------------------------------------------------------------
// autoPruneLogs validation
// ---------------------------------------------------------------------------

describe('SettingsModel autoPruneLogs validation', function () {
    it('passes for 0 (pruning disabled)', function () {
        $settings = new SettingsModel(['autoPruneLogs' => 0]);

        expect($settings->validate(['autoPruneLogs']))->toBeTrue();
    });

    it('passes for a mid-range value', function () {
        $settings = new SettingsModel(['autoPruneLogs' => 90]);

        expect($settings->validate(['autoPruneLogs']))->toBeTrue();
    });

    it('passes for the maximum value of 365', function () {
        $settings = new SettingsModel(['autoPruneLogs' => 365]);

        expect($settings->validate(['autoPruneLogs']))->toBeTrue();
    });

    it('fails for a value above 365', function () {
        $settings = new SettingsModel(['autoPruneLogs' => 366]);

        expect($settings->validate(['autoPruneLogs']))->toBeFalse()
            ->and($settings->getErrors('autoPruneLogs'))->not->toBeEmpty();
    });

    it('fails for a negative value', function () {
        $settings = new SettingsModel(['autoPruneLogs' => -1]);

        expect($settings->validate(['autoPruneLogs']))->toBeFalse()
            ->and($settings->getErrors('autoPruneLogs'))->not->toBeEmpty();
    });

});

// ---------------------------------------------------------------------------
// Boolean field validation
// ---------------------------------------------------------------------------

describe('SettingsModel boolean field validation', function () {
    it('accepts true for asyncLogging', function () {
        $settings = new SettingsModel(['asyncLogging' => true]);

        expect($settings->validate(['asyncLogging']))->toBeTrue();
    });

    it('accepts false for asyncLogging', function () {
        $settings = new SettingsModel(['asyncLogging' => false]);

        expect($settings->validate(['asyncLogging']))->toBeTrue();
    });

    it('accepts false for logPaymentTransactions', function () {
        $settings = new SettingsModel(['logPaymentTransactions' => false]);

        expect($settings->validate(['logPaymentTransactions']))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// String field validation
// ---------------------------------------------------------------------------

describe('SettingsModel string field validation', function () {
    it('passes for a normal Anthropic API key reference', function () {
        $settings = new SettingsModel(['anthropicApiKey' => '$ANTHROPIC_API_KEY']);

        expect($settings->validate(['anthropicApiKey']))->toBeTrue();
    });

    it('fails when anthropicApiKey exceeds the max length', function () {
        $settings = new SettingsModel(['anthropicApiKey' => str_repeat('a', 256)]);

        expect($settings->validate(['anthropicApiKey']))->toBeFalse()
            ->and($settings->getErrors('anthropicApiKey'))->not->toBeEmpty();
    });

    it('passes for a normal-length order insights prompt', function () {
        $settings = new SettingsModel(['orderInsightsPrompt' => 'Summarize this order in two sentences.']);

        expect($settings->validate(['orderInsightsPrompt']))->toBeTrue();
    });

    it('fails when orderInsightsPrompt exceeds the max length', function () {
        $settings = new SettingsModel(['orderInsightsPrompt' => str_repeat('a', 5001)]);

        expect($settings->validate(['orderInsightsPrompt']))->toBeFalse()
            ->and($settings->getErrors('orderInsightsPrompt'))->not->toBeEmpty();
    });

    it('fails when storeInsightsPrompt exceeds the max length', function () {
        $settings = new SettingsModel(['storeInsightsPrompt' => str_repeat('a', 5001)]);

        expect($settings->validate(['storeInsightsPrompt']))->toBeFalse()
            ->and($settings->getErrors('storeInsightsPrompt'))->not->toBeEmpty();
    });
});
