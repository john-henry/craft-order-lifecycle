<?php

use craft\web\View;
use markhuot\craftpest\factories\User as UserFactory;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\fields\OrderLifecycleField;
use johnhenry\orderlifecycle\OrderLifecycle;

// ---------------------------------------------------------------------------
// Order field render
// ---------------------------------------------------------------------------
// The field draws on every order edit screen, and the context it hands the
// template is built in PHP rather than pulled out of craft.orderLifecycle, so
// a key dropped from that array takes the whole screen down with it.

describe('OrderLifecycleField::getInputHtml()', function() {
    it('renders an order with a timeline and a saved insight', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->log($order, EventType::CART_CREATED);
        $logger->log($order, EventType::CHECKOUT_STARTED);
        $logger->log($order, EventType::ORDER_COMPLETED);
        $logger->log($order, EventType::AI_INSIGHTS, [], '{"priorityAction":null,"items":[]}');

        $field = new OrderLifecycleField(['handle' => 'orderLifecycle', 'name' => 'Order Lifecycle']);
        $settings = OrderLifecycle::$plugin->settings;
        $wasKey = $settings->anthropicApiKey;

        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        try {
            $settings->anthropicApiKey = 'test-key';
            $withAi = $field->getInputHtml(null, $order);

            $settings->anthropicApiKey = '';
            $withoutAi = $field->getInputHtml(null, $order);
        } finally {
            $settings->anthropicApiKey = $wasKey;
        }

        expect($withAi)->toContain('order-lifecycle-field')
            ->and($withAi)->toContain('lifecycle-analysis-card')
            ->and($withoutAi)->toContain('order-lifecycle-field')
            ->and($withoutAi)->not->toContain('lifecycle-analysis-card');
    });

    it('renders an order with no events at all', function() {
        $order = makeOrder();
        $field = new OrderLifecycleField(['handle' => 'orderLifecycle', 'name' => 'Order Lifecycle']);

        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        expect($field->getInputHtml(null, $order))->toBeString();
    });

    // Twig's json_encode escapes non-ASCII characters and slashes unless told
    // not to, so a euro amount would read as \u20ac in the change table.
    it('shows currency symbols and URLs as written', function() {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::STATUS_CHANGED, [
            'note' => 'Refund of €10.00 issued',
            'link' => 'https://example.com/refunds',
        ]);

        $field = new OrderLifecycleField(['handle' => 'orderLifecycle', 'name' => 'Order Lifecycle']);
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        $html = $field->getInputHtml(null, $order);

        expect($html)->toContain('€10.00')
            ->and($html)->toContain('https://example.com/refunds')
            ->and($html)->not->toContain('\\u20ac')
            ->and($html)->not->toContain('https:\\/\\/');
    });

    it('renders the stats block when the setting is on', function() {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_CREATED);

        $settings = OrderLifecycle::$plugin->settings;
        $was = $settings->showLifecycleStats;
        $settings->showLifecycleStats = true;

        $field = new OrderLifecycleField(['handle' => 'orderLifecycle', 'name' => 'Order Lifecycle']);
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        $html = $field->getInputHtml(null, $order);

        $settings->showLifecycleStats = $was;

        expect($html)->toContain('lifecycle-metric-card');
    });
});
