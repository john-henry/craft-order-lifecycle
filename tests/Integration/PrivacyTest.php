<?php

use craft\helpers\Json;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\jobs\PruneLogs;
use johnhenry\orderlifecycle\models\SettingsModel;
use johnhenry\orderlifecycle\OrderLifecycle;

// ---------------------------------------------------------------------------
// What leaves the site in an AI prompt
// ---------------------------------------------------------------------------
// The order context goes to Anthropic, so it carries what happened to the
// order and never who the customer is.

describe('AiInsightsService::buildOrderContext()', function() {
    it('leaves the customer email and address out', function() {
        $order = makeOrder('private.person@example.com');
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED);
        $logger->log($order, EventType::CUSTOMER_SET, ['customerType' => 'guest'], 'Customer set to private.person@example.com');
        $logger->log($order, EventType::SHIPPING_ADDRESS_SET, [
            'before' => null,
            'after' => ['addressLine1' => '1 Hidden Lane', 'locality' => 'Cork', 'countryCode' => 'IE'],
        ]);
        $logger->log($order, EventType::CART_UPDATED, [], 'Note from private.person@example.com');

        $ai = OrderLifecycle::$plugin->getAiInsights();
        $context = $ai->buildOrderContext($logger->getLogsForOrder($order->id));
        $json = Json::encode($context);

        expect($json)->not->toContain('private.person@example.com')
            ->and($json)->not->toContain('1 Hidden Lane')
            ->and($context['events'])->toHaveCount(4)
            ->and(array_keys($context['customer']))->toBe(['isGuest']);
    });

    it('leaves earlier AI output out of the timeline', function() {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED);
        $logger->log($order, EventType::AI_INSIGHTS, [], 'Earlier analysis');

        $context = OrderLifecycle::$plugin->getAiInsights()->buildOrderContext($logger->getLogsForOrder($order->id));

        expect(array_column($context['events'], 'type'))->toBe([EventType::CART_CREATED->value]);
    });
});

// ---------------------------------------------------------------------------
// Erasing one customer's history
// ---------------------------------------------------------------------------

describe('OrderLifecycleLogger::eraseLogs()', function() {
    it('erases every log for a customer email and nobody else’s', function() {
        $mine = makeOrder('erase.me@example.com');
        $other = makeOrder('keep.me@example.com');
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($mine, EventType::CART_CREATED);
        $logger->log($mine, EventType::CHECKOUT_STARTED);
        $logger->log($other, EventType::CART_CREATED);

        $erased = $logger->eraseLogs('erase.me@example.com', null);

        expect($erased)->toBe(2)
            ->and(logCount($mine->id, EventType::CART_CREATED))->toBe(0)
            ->and(logCount($other->id, EventType::CART_CREATED))->toBe(1);
    });

    it('erases one order’s logs by ID', function() {
        $order = makeOrder();
        $other = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED);
        $logger->log($other, EventType::CART_CREATED);

        expect($logger->eraseLogs(null, (int)$order->id))->toBe(1)
            ->and(logCount($other->id, EventType::CART_CREATED))->toBe(1);
    });

    it('erases nothing when given nothing to match', function() {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_CREATED);

        expect(OrderLifecycle::$plugin->getLogger()->eraseLogs(null, null))->toBe(0)
            ->and(logCount($order->id, EventType::CART_CREATED))->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// Retention
// ---------------------------------------------------------------------------

describe('pruning old logs', function() {
    it('deletes entries older than the cut-off and keeps the rest', function() {
        $order = makeOrder();
        insertBackdatedLog($order->id, EventType::CART_CREATED, '-40 days');
        insertBackdatedLog($order->id, EventType::CART_UPDATED, 'now');

        (new PruneLogs(['days' => 30]))->execute(null);

        expect(logCount($order->id, EventType::CART_CREATED))->toBe(0)
            ->and(logCount($order->id, EventType::CART_UPDATED))->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// The API key setting
// ---------------------------------------------------------------------------
// Settings are written to project config, which is committed to git, so the
// key itself must never be what is stored.

describe('SettingsModel anthropicApiKey', function() {
    it('accepts an environment variable reference or nothing', function(string $value) {
        $settings = new SettingsModel(['anthropicApiKey' => $value]);

        expect($settings->validate(['anthropicApiKey']))->toBeTrue();
    })->with(['$ANTHROPIC_API_KEY', '']);

    it('refuses a key pasted in directly', function() {
        $settings = new SettingsModel(['anthropicApiKey' => 'sk-ant-api03-abcdef']);

        expect($settings->validate(['anthropicApiKey']))->toBeFalse();
    });
});
