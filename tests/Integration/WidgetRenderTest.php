<?php

use craft\web\View;
use markhuot\craftpest\factories\User as UserFactory;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\widgets\AiInsightsWidget;
use johnhenry\orderlifecycle\widgets\OrderLifecycleStatsWidget;

// ---------------------------------------------------------------------------
// Dashboard widget render
// ---------------------------------------------------------------------------
// Craft's DashboardController draws every widget body in turn without a
// try/catch of its own, so a widget that throws takes the whole dashboard with
// it. These render the bodies the way the dashboard would.

describe('Dashboard widgets', function() {
    beforeEach(function() {
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        $this->actingAs(UserFactory::factory()->admin(true)->create());
    });

    it('renders the stats widget body and its settings', function() {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_CREATED);

        $widget = new OrderLifecycleStatsWidget(['days' => 30, 'showTopEvents' => true]);

        expect($widget->getBodyHtml())->toContain('ol-stat-grid')
            ->and($widget->getSettingsHtml())->toBeString()
            ->and($widget->getSubtitle())->toBeString();
    });

    it('renders the stats widget with the top events list switched off', function() {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_CREATED);

        $widget = new OrderLifecycleStatsWidget(['days' => 30, 'showTopEvents' => false]);

        expect($widget->getBodyHtml())->toBeString()
            ->and($widget->getBodyHtml())->not->toContain('Top Events');
    });

    it('tells you to add a key when the AI widget has none', function() {
        $settings = OrderLifecycle::$plugin->settings;
        $was = $settings->anthropicApiKey;
        $settings->anthropicApiKey = '';

        try {
            expect((new AiInsightsWidget())->getBodyHtml())->toContain('Anthropic API key');
        } finally {
            $settings->anthropicApiKey = $was;
        }
    });
});
