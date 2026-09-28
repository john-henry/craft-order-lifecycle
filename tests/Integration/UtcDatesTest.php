<?php

use craft\db\Query;
use craft\helpers\StringHelper;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\migrations\m260927_000000_utc_log_dates;
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\services\OrderLifecycleLogger;

// ---------------------------------------------------------------------------
// Log times are stored in UTC
// ---------------------------------------------------------------------------
// Everything that reads a log time treats it as UTC, like every other date
// Craft stores. A time written in the request's own timezone would be off by
// the site's offset everywhere it is read back.

describe('log times', function() {
    it('writes UTC whatever the request timezone', function() {
        $was = Craft::$app->getTimeZone();
        Craft::$app->setTimeZone('America/New_York');

        try {
            $order = makeOrder();
            OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_UPDATED);
        } finally {
            Craft::$app->setTimeZone($was);
        }

        $stored = (string)(new Query())
            ->select(['dateCreated'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id])
            ->scalar();

        $storedAt = (new DateTimeImmutable($stored, new DateTimeZone('UTC')))->getTimestamp();

        expect(abs($storedAt - time()))->toBeLessThan(60);
    });

    it('reads a stored time back in the system timezone', function() {
        $was = Craft::$app->getTimeZone();
        Craft::$app->setTimeZone('Europe/Dublin');

        try {
            $date = OrderLifecycleLogger::rowDate('2026-07-10 11:00:00');
        } finally {
            Craft::$app->setTimeZone($was);
        }

        expect($date->format('H:i'))->toBe('12:00')
            ->and($date->getTimezone()->getName())->toBe('Europe/Dublin');
    });
});

// ---------------------------------------------------------------------------
// Converting existing rows
// ---------------------------------------------------------------------------
// Rows written before 2.0.0 hold local time. Each moves by the offset in force
// when it was written, so a summer row and a winter row move by different
// amounts.

describe('m260927_000000_utc_log_dates', function() {
    it('splits a range at each daylight-saving change', function() {
        $periods = m260927_000000_utc_log_dates::periods(
            new DateTimeZone('Europe/Dublin'),
            '2026-01-10 10:00:00',
            '2026-07-10 10:00:00',
        );

        $offsets = array_unique(array_column($periods, 'offset'));
        sort($offsets);

        expect($offsets)->toBe([0, 3600])
            ->and($periods[0]['from'])->toBeNull()
            ->and(end($periods)['to'])->toBeNull();
    });

    it('finds no offset for a zone on UTC', function() {
        $periods = m260927_000000_utc_log_dates::periods(
            new DateTimeZone('UTC'),
            '2026-01-10 10:00:00',
            '2026-07-10 10:00:00',
        );

        expect(array_unique(array_column($periods, 'offset')))->toBe([0]);
    });

    it('moves summer and winter rows by their own offset', function() {
        $order = makeOrder();
        $db = Craft::$app->getDb();

        $insert = static function(string $at) use ($db, $order): int {
            $db->createCommand()->insert('{{%orderlifecycle_logs}}', [
                'orderId' => $order->id,
                'type' => EventType::CART_UPDATED->value,
                'message' => '',
                'snapshot' => '{}',
                'dateCreated' => $at,
                'dateUpdated' => $at,
                'uid' => StringHelper::UUID(),
            ])->execute();

            return (int)$db->getLastInsertID();
        };

        $winter = $insert('2026-01-10 12:00:00');
        $summer = $insert('2026-07-10 12:00:00');

        $general = Craft::$app->getConfig()->getGeneral();
        $was = $general->timezone;
        $general->timezone = 'Europe/Dublin';

        try {
            (new m260927_000000_utc_log_dates())->safeUp();
        } finally {
            $general->timezone = $was;
        }

        $byId = array_map(
            static fn(string $date) => substr($date, 0, 19),
            (new Query())
                ->select(['dateCreated'])
                ->from('{{%orderlifecycle_logs}}')
                ->where(['id' => [$winter, $summer]])
                ->indexBy('id')
                ->column(),
        );

        expect($byId[$winter])->toBe('2026-01-10 12:00:00')
            ->and($byId[$summer])->toBe('2026-07-10 11:00:00');
    });
});
