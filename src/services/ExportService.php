<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\services;

use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use yii\base\InvalidConfigException;

/**
 * Export service.
 *
 * Builds CSV exports of lifecycle events, resolving the referenced orders one
 * batch at a time to avoid per-row lookups without holding an unbounded number
 * of hydrated orders in memory.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class ExportService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int How many log rows are read, and how many orders are hydrated,
     * per pass of the export.
     */
    private const _BATCH_SIZE = 500;

    /**
     * @var array<string, string> The available export columns and their headers.
     */
    private const AVAILABLE_COLUMNS = [
        'id' => 'Log ID',
        'dateCreated' => 'Date/Time',
        'orderId' => 'Order ID',
        'orderNumber' => 'Order Number',
        'type' => 'Event Type',
        'message' => 'Message',
        'email' => 'Customer Email',
        'orderStatus' => 'Order Status',
        'totalPrice' => 'Total Price',
        'currency' => 'Currency',
        'payload' => 'Payload (JSON)',
    ];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Builds the CSV for the lifecycle events matching the supplied filters.
     *
     * Both arguments come straight off the export form, untouched. Reading
     * them is this service's job: which filters narrow the query, which
     * columns were ticked and what order they were dragged into.
     *
     * @param array $criteria The posted filters: dateFrom, dateTo, eventTypes
     *                        and orderId, any of which may be absent or empty.
     * @param array $columnSelection The posted exportColumns structure, keyed by
     *                               column, each with `checked` and `order`. An
     *                               empty selection means every column.
     * @return string The generated CSV content.
     * @throws InvalidConfigException If an order's status or totals cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function exportCsv(array $criteria, array $columnSelection = []): string
    {
        $stream = $this->exportCsvStream($criteria, $columnSelection);
        $csv = (string)stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * Writes the CSV for the lifecycle events matching the supplied filters
     * to a temporary stream, ready to send as a download without the whole
     * file ever being held as one string.
     *
     * @param array $criteria The posted filters, plus `storeId` to limit it to one store.
     * @param array $columnSelection The posted exportColumns structure.
     * @return resource The stream, rewound to the start.
     * @throws InvalidConfigException If an order's status or totals cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function exportCsvStream(array $criteria, array $columnSelection = [])
    {
        $query = $this->_logQuery($criteria);

        return $this->_writeCsv(
            static fn(): iterable => self::_pages($query),
            self::_orderedColumnKeys($columnSelection),
        );
    }

    /**
     * Generates the CSV content for the supplied lifecycle logs.
     *
     * @param array $logs The lifecycle log rows to export.
     * @param string[] $columns The ordered keys of the columns to include; empty means all.
     * @return string The generated CSV content.
     * @throws InvalidConfigException If an order's status or totals cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function generateCsv(array $logs, array $columns = []): string
    {
        return self::_readAll($this->_writeCsv(
            static function() use ($logs): iterable {
                yield $logs;
            },
            $columns,
        ));
    }

    /**
     * Generates the CSV content for every log row the query matches.
     *
     * An export is filtered by date and event type at most, so the row count is
     * whatever the store has logged: on a busy shop that is the whole table.
     * It is read a page at a time, which keeps both the rows and the orders
     * they point at bounded.
     *
     * @param Query $query The log query to export. It must select `id` and
     *        `dateCreated`; it is read in `dateCreated`, `id` order.
     * @param string[] $columns The ordered keys of the columns to include; empty means all.
     * @return string The generated CSV content.
     * @throws InvalidConfigException If an order's status or totals cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function generateCsvFromQuery(Query $query, array $columns = []): string
    {
        return self::_readAll($this->_writeCsv(
            static fn(): iterable => self::_pages($query),
            $columns,
        ));
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Turns the posted column selection into an ordered list of column keys.
     *
     * A row carries `checked` and the position it was dragged to. Anything not
     * ticked is dropped, and a column with no position sorts to the end rather
     * than jumping to the front.
     *
     * @param array $selection The posted exportColumns structure.
     * @return string[] The ordered column keys, empty where nothing was ticked.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private static function _orderedColumnKeys(array $selection): array
    {
        $chosen = [];

        foreach ($selection as $key => $column) {
            if (!is_array($column) || empty($column['checked'])) {
                continue;
            }

            $chosen[] = [
                'key' => (string)$key,
                'order' => isset($column['order']) ? (int)$column['order'] : PHP_INT_MAX,
            ];
        }

        usort($chosen, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_column($chosen, 'key');
    }

    /**
     * Builds the log query for the supplied filters.
     *
     * Ordered by date with the id breaking ties, because the caller reads it in
     * batches and an unstable order pages over the same row twice.
     *
     * @param array $criteria The posted filters.
     * @return Query The log query.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _logQuery(array $criteria): Query
    {
        $query = (new Query())
            ->select([
                'id',
                'orderId',
                'type',
                'message',
                'snapshot',
                'dateCreated',
            ])
            ->from('{{%orderlifecycle_logs}}')
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC]);

        $dateFrom = DateTimeHelper::toDateTime($criteria['dateFrom'] ?? null);

        if ($dateFrom !== false) {
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($dateFrom)]);
        }

        $dateTo = DateTimeHelper::toDateTime($criteria['dateTo'] ?? null);

        if ($dateTo !== false) {
            $query->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($dateTo)]);
        }

        // An empty selection means every type; anything else narrows to what
        // was chosen. A value nobody records matches no rows, which is the
        // right answer for somebody who asked for one slice of the log.
        $eventTypes = $criteria['eventTypes'] ?? null;

        if (is_array($eventTypes) && $eventTypes !== []) {
            $query->andWhere(['type' => array_map('strval', array_filter($eventTypes, 'is_scalar'))]);
        }

        $storeId = $criteria['storeId'] ?? null;

        if ($storeId !== null) {
            $query->andWhere(['storeId' => (int)$storeId]);
        }

        $orderId = (int)($criteria['orderId'] ?? 0);

        if ($orderId > 0) {
            $query->andWhere(['orderId' => $orderId]);
        }

        return $query;
    }

    /**
     * Writes the CSV for every batch the supplied generator yields.
     *
     * @param callable(): iterable<array> $batches Yields the log rows one batch at a time.
     * @param string[] $columns The ordered keys of the columns to include; empty means all.
     * @return resource The CSV, in a temporary stream rewound to the start.
     * @throws InvalidConfigException If an order's status or totals cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _writeCsv(callable $batches, array $columns)
    {
        if (empty($columns)) {
            $columns = array_keys(self::AVAILABLE_COLUMNS);
        }

        $output = fopen('php://temp', 'r+b');

        $headers = [];
        foreach ($columns as $column) {
            if (isset(self::AVAILABLE_COLUMNS[$column])) {
                $headers[] = self::AVAILABLE_COLUMNS[$column];
            }
        }
        fputcsv($output, $headers);

        foreach ($batches() as $logs) {
            $orders = $this->_loadOrders($logs);

            foreach ($logs as $log) {
                $order = $orders[$log['orderId']] ?? null;
                $dataMap = $this->_buildRow($log, $order);

                $row = [];
                foreach ($columns as $column) {
                    if (isset($dataMap[$column])) {
                        $row[] = self::_sanitizeCsvValue((string)$dataMap[$column]);
                    }
                }

                fputcsv($output, $row);
            }
        }

        rewind($output);

        return $output;
    }

    /**
     * Reads a log query a page at a time, each page starting after the last
     * row of the one before.
     *
     * Keyed on (dateCreated, id) rather than an offset, so each page costs the
     * same however deep into the table it is, and on the main connection, so
     * it sees the same data the rest of the request does (Craft's unbuffered
     * batching opens a second connection, which can't see inside an open
     * transaction).
     *
     * @param Query $query The log query; it must select `id` and `dateCreated`.
     * @return iterable<array> The rows, one page at a time.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _pages(Query $query): iterable
    {
        $last = null;

        do {
            $page = (clone $query)
                ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])
                ->limit(self::_BATCH_SIZE);

            if ($last !== null) {
                $page->andWhere(['or',
                    ['>', 'dateCreated', $last['dateCreated']],
                    ['and', ['dateCreated' => $last['dateCreated']], ['>', 'id', $last['id']]],
                ]);
            }

            $rows = $page->all();

            if ($rows === []) {
                return;
            }

            yield $rows;

            $last = end($rows);
        } while (count($rows) === self::_BATCH_SIZE);
    }

    /**
     * Reads a CSV stream into a string and closes it.
     *
     * @param resource $stream The stream.
     * @return string
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _readAll($stream): string
    {
        $csv = (string)stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * Neutralizes CSV formula injection in a cell value.
     *
     * Several of the exported columns (message, email, the JSON payload) can
     * contain customer-supplied data from checkout. A value starting with
     * =, +, -, @, a tab or a carriage return is interpreted as a formula by
     * Excel/Google Sheets when the file is opened, so a malicious coupon
     * code or address line could execute arbitrary formulas for whoever
     * opens the export. Prefixing it with a single quote forces spreadsheet
     * software to treat it as plain text.
     *
     * @param string $value The raw cell value.
     * @return string The value, prefixed with a quote if it starts with a formula trigger character.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private static function _sanitizeCsvValue(string $value): string
    {
        // a number, a negative refund included, is a value, not a formula
        if ($value === '' || is_numeric($value)) {
            return $value;
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Loads every order referenced by the logs in a single batched query.
     *
     * @param array $logs The lifecycle log rows to export.
     * @return array<int, Order> The orders indexed by ID.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _loadOrders(array $logs): array
    {
        $orderIds = array_values(array_unique(array_column($logs, 'orderId')));

        if (empty($orderIds)) {
            return [];
        }

        return Order::find()
            ->id($orderIds)
            ->status(null)
            ->indexBy('id')
            ->all();
    }

    /**
     * Builds the column value map for a single log row.
     *
     * @param array $log The lifecycle log row.
     * @param Order|null $order The resolved order, or null if it no longer exists.
     * @return array<string, string> The column key to value map.
     * @throws InvalidConfigException If the order's status or totals cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _buildRow(array $log, ?Order $order): array
    {
        $snapshot = Json::decodeIfJson($log['snapshot']);
        $payload = $snapshot['payload'] ?? [];

        return [
            'id' => $log['id'],
            'dateCreated' => OrderLifecycleLogger::rowDate($log['dateCreated'])->format('Y-m-d H:i:s'),
            'orderId' => $log['orderId'],
            'orderNumber' => $order->number ?? 'N/A',
            'type' => $log['type'],
            // stored with the timeline's markup, which means nothing in a spreadsheet
            'message' => html_entity_decode(strip_tags((string)($log['message'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'email' => $order->email ?? '',
            'orderStatus' => $order?->getOrderStatus()->name ?? '',
            'totalPrice' => (string)$order?->getTotalPrice(),
            'currency' => $order->currency ?? '',
            'payload' => Json::encode($payload),
        ];
    }
}
