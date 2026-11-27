<?php

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Json;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\services\OrderLifecycleLogger;

// ---------------------------------------------------------------------------
// Guest versus registered customers
// ---------------------------------------------------------------------------
// Commerce gives every order with an email a user, an inactive one for a
// guest, so an order having a customer can't be what marks it registered.
// Only a customer whose account can be signed into counts.

/**
 * Saves an active user and an order that belongs to them.
 *
 * @throws \Throwable
 */
function registeredOrder(string $email): Order
{
    $user = new User();
    $user->email = $email;
    $user->username = $email;
    $user->active = true;

    if (!Craft::$app->getElements()->saveElement($user, false)) {
        throw new RuntimeException('Could not save test user');
    }

    $order = new Order();
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setCustomer($user);
    $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save test order');
    }

    return $order;
}

/**
 * Returns the decoded snapshot of an order's newest log of the given type.
 */
function latestSnapshot(int $orderId, EventType $type): array
{
    $row = (new Query())
        ->from('{{%orderlifecycle_logs}}')
        ->where(['orderId' => $orderId, 'type' => $type->value])
        ->orderBy(['id' => SORT_DESC])
        ->one();

    return Json::decodeIfJson($row['snapshot'] ?? '{}') ?: [];
}

describe('OrderLifecycleLogger::isGuest()', function() {
    it('treats an order with only an email as a guest order', function() {
        $order = makeOrder('guest.shopper@example.com');

        expect($order->getCustomer())->not->toBeNull()
            ->and($order->getCustomer()->getIsCredentialed())->toBeFalse()
            ->and(OrderLifecycleLogger::isGuest($order))->toBeTrue();
    });

    it('treats an order owned by an active account as registered', function() {
        $order = registeredOrder('member.shopper@example.com');

        expect(OrderLifecycleLogger::isGuest($order))->toBeFalse();
    });

    it('treats an order with no customer at all as a guest order', function() {
        expect(OrderLifecycleLogger::isGuest(new Order()))->toBeTrue();
    });
});

describe('Guest and registered in what gets logged', function() {
    it('logs a guest checkout and snapshot as a guest', function() {
        $order = makeOrder('guest.checkout@example.com');
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->logCheckoutStarted($order);

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CHECKOUT_STARTED->value])
            ->one();

        expect($row['message'])->toBe('Guest customer visited checkout page')
            ->and(latestSnapshot($order->id, EventType::CHECKOUT_STARTED)['customer']['isGuest'])->toBeTrue();
    });

    it('logs a registered checkout and snapshot as registered', function() {
        $order = registeredOrder('member.checkout@example.com');
        $logger = OrderLifecycle::$plugin->getLogger();

        $logger->logCheckoutStarted($order);

        $row = (new Query())
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CHECKOUT_STARTED->value])
            ->one();

        expect($row['message'])->toBe('Registered customer visited checkout page')
            ->and(latestSnapshot($order->id, EventType::CHECKOUT_STARTED)['customer']['isGuest'])->toBeFalse();
    });

    it('labels a new cart by whether its customer can sign in', function() {
        $guest = orderWithSnapshot('guest.cart@example.com');
        $member = registeredOrder('member.cart@example.com');

        $message = static fn(Order $order) => (new Query())
            ->select('message')
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id, 'type' => EventType::CART_CREATED->value])
            ->scalar();

        expect($message($guest))->toBe('Cart initialized for guest customer')
            ->and($message($member))->toBe('Cart initialized for registered customer');
    });

    it('fills the {customerType} prompt token from the same test', function() {
        $ai = OrderLifecycle::$plugin->getAiInsights();
        $logger = OrderLifecycle::$plugin->getLogger();

        $guest = makeOrder('guest.prompt@example.com');
        $logger->log($guest, EventType::CART_UPDATED);
        $member = registeredOrder('member.prompt@example.com');
        $logger->log($member, EventType::CART_UPDATED);

        $guestContext = $ai->buildOrderContext($logger->getLogsForOrder($guest->id));
        $memberContext = $ai->buildOrderContext($logger->getLogsForOrder($member->id));

        // An empty custom prompt falls back to the default, which carries the token.
        $settings = OrderLifecycle::$plugin->getSettings();
        $wasPrompt = $settings->orderInsightsPrompt;

        try {
            $settings->orderInsightsPrompt = '';
            $guestPrompt = $ai->buildOrderPrompt($guestContext);
            $memberPrompt = $ai->buildOrderPrompt($memberContext);
        } finally {
            $settings->orderInsightsPrompt = $wasPrompt;
        }

        expect($guestContext['customer']['isGuest'])->toBeTrue()
            ->and($memberContext['customer']['isGuest'])->toBeFalse()
            ->and($guestPrompt)->toContain('Customer type: guest')
            ->and($memberPrompt)->toContain('Customer type: registered');
    });
});
