<?php

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
