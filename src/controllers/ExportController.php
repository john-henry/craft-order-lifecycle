<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\web\Controller;
use Exception;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Export controller.
 *
 * Handles CSV export of lifecycle events for analysis in BI tools, spreadsheets
 * and data warehouses.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ExportController extends Controller
{
    // =========================================================================
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Exports the filtered lifecycle events as a downloadable CSV file.
     *
     * @return Response The CSV file download response.
     * @throws ForbiddenHttpException If the user lacks the required permission.
     * @throws Exception If the query or CSV generation fails.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionCsv(): Response
    {
        $this->requirePermission('order-lifecycle:exportEvents');

        $request = Craft::$app->getRequest();

        // Get filter parameters
        $dateFrom = $request->getParam('dateFrom');
        $dateTo = $request->getParam('dateTo');
        $eventTypes = $request->getParam('eventTypes');
        $orderId = $request->getParam('orderId');
        $exportColumns = $request->getParam('exportColumns', []);

        $processedColumns = [];
        foreach ($exportColumns as $columnKey => $columnData) {
            if (isset($columnData['checked']) && $columnData['checked']) {
                $order = isset($columnData['order']) ? (int)$columnData['order'] : 999;
                $processedColumns[] = [
                    'key' => $columnKey,
                    'order' => $order,
                ];
            }
        }

        usort($processedColumns, static function($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        // Extract just the keys
        $orderedColumnKeys = array_column($processedColumns, 'key');

        // Build query
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
            ->orderBy(['dateCreated' => SORT_ASC]);

        // Apply filters
        if ($dateFrom) {
            $dateFromObj = DateTimeHelper::toDateTime($dateFrom);
            if ($dateFromObj) {
                $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($dateFromObj)]);
            }
        }

        if ($dateTo) {
            $dateToObj = DateTimeHelper::toDateTime($dateTo);
            if ($dateToObj) {
                $query->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($dateToObj)]);
            }
        }

        if (is_array($eventTypes) && !empty($eventTypes)) {
            $query->andWhere(['in', 'type', $eventTypes]);
        }

        if ($orderId) {
            $query->andWhere(['orderId' => $orderId]);
        }

        $logs = $query->all();

        // Generate CSV
        $csv = $this->generateCsv($logs, $orderedColumnKeys);

        // Set response headers
        $filename = 'lifecycle-events-' . (new \DateTime())->format('Y-m-d-His') . '.csv';

        return $this->response->sendContentAsFile(
            $csv,
            $filename,
            [
                'mimeType' => 'text/csv',
            ]
        );
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Generates the CSV content from the supplied logs.
     *
     * @param array $logs The lifecycle log rows to export.
     * @param array $exportColumns The ordered keys of the columns to include; empty means all.
     * @return string The generated CSV content.
     * @throws InvalidConfigException If an order's status or totals cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function generateCsv(array $logs, array $exportColumns = []): string
    {
        $output = fopen('php://temp', 'rb+');

        $availableColumns = [
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

        // If no columns selected, export all
        if (empty($exportColumns)) {
            $exportColumns = array_keys($availableColumns);
        }

        // Write CSV header with only selected columns
        $headers = [];
        foreach ($exportColumns as $column) {
            if (isset($availableColumns[$column])) {
                $headers[] = $availableColumns[$column];
            }
        }
        fputcsv($output, $headers);

        // Cache orders to avoid repeated queries
        $ordersCache = [];

        foreach ($logs as $log) {
            $orderId = $log['orderId'];

            // Get order details (cached)
            if (!isset($ordersCache[$orderId])) {
                $order = Order::find()->id($orderId)->one();
                $ordersCache[$orderId] = $order;
            } else {
                $order = $ordersCache[$orderId];
            }

            // Parse snapshot
            $snapshot = Json::decodeIfJson($log['snapshot']);
            $payload = $snapshot['payload'] ?? [];

            $dataMap = [
                'id' => $log['id'],
                'dateCreated' => $log['dateCreated'],
                'orderId' => $orderId,
                'orderNumber' => $order?->number ?? 'N/A',
                'type' => $log['type'],
                'message' => $log['message'] ?? '',
                'email' => $order?->email ?? '',
                'orderStatus' => $order?->getOrderStatus()?->name ?? '',
                'totalPrice' => (string)$order?->getTotalPrice(),
                'currency' => $order?->currency ?? '',
                'payload' => Json::encode($payload),
            ];

            // Build row with only selected columns
            $row = [];
            foreach ($exportColumns as $column) {
                if (isset($dataMap[$column])) {
                    $row[] = $dataMap[$column];
                }
            }

            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
