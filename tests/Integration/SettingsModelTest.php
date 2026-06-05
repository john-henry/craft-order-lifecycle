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
