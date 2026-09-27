<?php

use craft\db\Query;
use craft\helpers\StringHelper;
use johnhenry\orderlifecycle\enums\EventType;
use craft\helpers\Json;
use johnhenry\orderlifecycle\OrderLifecycle;

// ---------------------------------------------------------------------------
// generateCsv(): CSV formula injection protection
// ---------------------------------------------------------------------------

describe('ExportService::generateCsv() CSV injection protection', function () {
    it('prefixes a formula-injection payload in the message column with a quote', function () {
        $order = makeOrder();
        $export = OrderLifecycle::$plugin->getExport();

        $logs = [[
            'id' => 1,
            'orderId' => $order->id,
            'type' => 'cartCreated',
            'message' => '=cmd|\'/c calc\'!A1',
            'snapshot' => Json::encode(['payload' => []]),
            'dateCreated' => '2026-01-01 00:00:00',
        ]];

        $csv = $export->generateCsv($logs, ['message']);
        $rows = array_map('str_getcsv', explode("\n", trim($csv)));

        expect($rows[1][0])->toBe("'=cmd|'/c calc'!A1");
    });

    it('prefixes values starting with +, -, @, or a tab', function () {
        $order = makeOrder();
        $export = OrderLifecycle::$plugin->getExport();

        foreach (['+1+1', '-2+3', '@SUM(A1:A9)', "\tmalicious"] as $i => $trigger) {
            $logs[] = [
                'id' => $i,
                'orderId' => $order->id,
                'type' => 'cartCreated',
                'message' => $trigger,
                'snapshot' => Json::encode(['payload' => []]),
                'dateCreated' => '2026-01-01 00:00:00',
            ];
        }

        $csv = $export->generateCsv($logs, ['message']);
        $rows = array_map('str_getcsv', explode("\n", trim($csv)));

        foreach (array_slice($rows, 1) as $row) {
            expect($row[0])->toStartWith("'");
        }
    });

    it('leaves ordinary messages untouched', function () {
        $order = makeOrder();
        $export = OrderLifecycle::$plugin->getExport();

        $logs = [[
            'id' => 1,
            'orderId' => $order->id,
            'type' => 'cartCreated',
            'message' => 'Coupon code \'WELCOME10\' applied',
            'snapshot' => Json::encode(['payload' => []]),
            'dateCreated' => '2026-01-01 00:00:00',
        ]];

        $csv = $export->generateCsv($logs, ['message']);
        $rows = array_map('str_getcsv', explode("\n", trim($csv)));

        expect($rows[1][0])->toBe('Coupon code \'WELCOME10\' applied');
    });
});


// ---------------------------------------------------------------------------
// generateCsvFromQuery(): batched reads
// ---------------------------------------------------------------------------

describe('ExportService::generateCsvFromQuery()', function () {
    it('produces the same CSV as an all-at-once read, across several batches', function () {
        $order = makeOrder();
        $db = Craft::$app->getDb();

        // More rows than one batch holds, so a paging mistake shows up as
        // missing or repeated lines rather than passing by luck.
        $rowCount = 1100;
        $rows = [];
        for ($i = 1; $i <= $rowCount; $i++) {
            $rows[] = [
                $order->id,
                'cartCreated',
                "row $i",
                Json::encode(['payload' => ['n' => $i]]),
                sprintf('2026-01-01 00:00:%02d', $i % 60),
            ];
        }

        $db->createCommand()->batchInsert(
            '{{%orderlifecycle_logs}}',
            ['orderId', 'type', 'message', 'snapshot', 'dateCreated'],
            $rows,
        )->execute();

        $export = OrderLifecycle::$plugin->getExport();
        $query = static fn() => (new Query())
            ->select(['id', 'orderId', 'type', 'message', 'snapshot', 'dateCreated'])
            ->from('{{%orderlifecycle_logs}}')
            ->where(['orderId' => $order->id])
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC]);

        $batched = $export->generateCsvFromQuery($query(), ['id', 'message']);
        $atOnce = $export->generateCsv($query()->all(), ['id', 'message']);

        expect(substr_count(trim($batched), "\n"))->toBe($rowCount);
        expect($batched)->toBe($atOnce);
    });
});

// ---------------------------------------------------------------------------
// exportCsv(): reading the posted form
// ---------------------------------------------------------------------------
// The controller hands the filters and the column selection over exactly as the
// form posted them, so reading them is this service's job and this is where it
// gets pinned.

describe('ExportService::exportCsv()', function() {
    $rows = static function(string $csv): array {
        return array_map('str_getcsv', explode("\n", trim($csv)));
    };

    it('keeps only the ticked columns, in the order they were dragged into', function() use ($rows) {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_CREATED);

        $csv = OrderLifecycle::$plugin->getExport()->exportCsv(
            ['orderId' => $order->id],
            [
                'message' => ['checked' => '1', 'order' => '2'],
                'id' => ['checked' => '1', 'order' => '0'],
                'currency' => ['checked' => '0', 'order' => '1'],
                'type' => ['checked' => '1', 'order' => '1'],
            ],
        );

        expect($rows($csv)[0])->toBe(['Log ID', 'Event Type', 'Message']);
    });

    it('sorts a column with no position to the end rather than the front', function() use ($rows) {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_CREATED);

        $csv = OrderLifecycle::$plugin->getExport()->exportCsv(
            ['orderId' => $order->id],
            [
                'message' => ['checked' => '1'],
                'id' => ['checked' => '1', 'order' => '0'],
            ],
        );

        expect($rows($csv)[0])->toBe(['Log ID', 'Message']);
    });

    it('includes every column when nothing was ticked', function() use ($rows) {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_CREATED);

        $csv = OrderLifecycle::$plugin->getExport()->exportCsv(['orderId' => $order->id], []);

        expect($rows($csv)[0])->toContain('Log ID')
            ->and($rows($csv)[0])->toContain('Payload (JSON)');
    });

    it('narrows to the chosen event types', function() use ($rows) {
        $order = makeOrder();
        $logger = OrderLifecycle::$plugin->getLogger();
        $logger->log($order, EventType::CART_CREATED);
        $logger->log($order, EventType::ORDER_COMPLETED);
        $logger->log($order, EventType::ORDER_PAID);

        $csv = OrderLifecycle::$plugin->getExport()->exportCsv(
            ['orderId' => $order->id, 'eventTypes' => ['orderCompleted', 'orderPaid']],
            ['type' => ['checked' => '1', 'order' => '0']],
        );

        // The column carries the raw event type value, not its display label.
        expect(array_column(array_slice($rows($csv), 1), 0))
            ->toEqualCanonicalizing(['orderCompleted', 'orderPaid']);
    });

    // Pins the fallback, not any validation: an event type nobody records has
    // to narrow the export to nothing. Treating an unrecognised selection as
    // "no selection" would hand the whole table to somebody who asked for one
    // slice of it.
    it('returns no rows when none of the chosen event types are real', function() use ($rows) {
        $order = makeOrder();
        OrderLifecycle::$plugin->getLogger()->log($order, EventType::CART_CREATED);

        $csv = OrderLifecycle::$plugin->getExport()->exportCsv(
            ['orderId' => $order->id, 'eventTypes' => ['nonsense', 'alsoNonsense']],
            ['type' => ['checked' => '1', 'order' => '0']],
        );

        expect($rows($csv))->toHaveCount(1); // the header, nothing else
    });

    it('narrows to a date range and ignores a blank one', function() use ($rows) {
        $order = makeOrder();
        $db = Craft::$app->getDb();

        foreach (['2026-01-01 00:00:00', '2026-06-01 00:00:00'] as $when) {
            $db->createCommand()->insert('{{%orderlifecycle_logs}}', [
                'orderId' => $order->id,
                'type' => EventType::CART_CREATED->value,
                'message' => $when,
                'snapshot' => Json::encode(['payload' => []]),
                'dateCreated' => $when,
                'dateUpdated' => $when,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        $export = OrderLifecycle::$plugin->getExport();
        $columns = ['message' => ['checked' => '1', 'order' => '0']];

        $all = $export->exportCsv(['orderId' => $order->id, 'dateFrom' => '', 'dateTo' => null], $columns);
        $narrowed = $export->exportCsv(['orderId' => $order->id, 'dateFrom' => '2026-03-01'], $columns);

        expect($rows($all))->toHaveCount(3)
            ->and($rows($narrowed))->toHaveCount(2)
            ->and($rows($narrowed)[1][0])->toBe('2026-06-01 00:00:00');
    });
});
