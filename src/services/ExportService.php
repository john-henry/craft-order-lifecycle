<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\services;

use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\Json;
use yii\base\InvalidConfigException;

/**
 * Export service.
 *
 * Builds CSV exports of lifecycle events, resolving all referenced orders in a
 * single batched query to avoid per-row lookups.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ExportService extends Component
{
    // =========================================================================
    // Constants
    // =========================================================================

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
     * Generates the CSV content for the supplied lifecycle logs.
     *
     * @param array $logs The lifecycle log rows to export.
     * @param string[] $columns The ordered keys of the columns to include; empty means all.
     * @return string The generated CSV content.
     * @throws InvalidConfigException If an order's status or totals cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function generateCsv(array $logs, array $columns = []): string
    {
        if (empty($columns)) {
            $columns = array_keys(self::AVAILABLE_COLUMNS);
        }

        $orders = $this->_loadOrders($logs);

        $output = fopen('php://temp', 'rb+');

        $headers = [];
        foreach ($columns as $column) {
            if (isset(self::AVAILABLE_COLUMNS[$column])) {
                $headers[] = self::AVAILABLE_COLUMNS[$column];
            }
        }
        fputcsv($output, $headers);

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

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _sanitizeCsvValue(string $value): string
    {
        if ($value === '') {
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _buildRow(array $log, ?Order $order): array
    {
        $snapshot = Json::decodeIfJson($log['snapshot']);
        $payload = $snapshot['payload'] ?? [];

        return [
            'id' => $log['id'],
            'dateCreated' => $log['dateCreated'],
            'orderId' => $log['orderId'],
            'orderNumber' => $order?->number ?? 'N/A',
            'type' => $log['type'],
            'message' => $log['message'] ?? '',
            'email' => $order?->email ?? '',
            'orderStatus' => $order?->getOrderStatus()?->name ?? '',
            'totalPrice' => (string)$order?->getTotalPrice(),
            'currency' => $order?->currency ?? '',
            'payload' => Json::encode($payload),
        ];
    }
}
