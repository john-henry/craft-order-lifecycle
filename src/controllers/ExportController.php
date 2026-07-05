<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\web\Controller;
use Exception;
use johnhenry\orderlifecycle\OrderLifecycle;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
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
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @throws MethodNotAllowedHttpException If the request is not a POST request.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionCsv(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('order-lifecycle:exportEvents');

        $request = Craft::$app->getRequest();

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

        $orderedColumnKeys = array_column($processedColumns, 'key');

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
            $query->andWhere(['orderId' => (int)$orderId]);
        }

        $logs = $query->all();

        $csv = OrderLifecycle::getInstance()->getExport()->generateCsv($logs, $orderedColumnKeys);

        $filename = 'lifecycle-events-' . (new \DateTime())->format('Y-m-d-His') . '.csv';

        return $this->response->sendContentAsFile($csv, $filename, [
            'mimeType' => 'text/csv',
        ]);
    }
}
