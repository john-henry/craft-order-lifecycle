<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\controllers;

use Craft;
use craft\web\Controller;
use DateTimeImmutable;
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
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class ExportController extends Controller
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string The permission to export lifecycle events.
     */
    public const PERMISSION_EXPORT_EVENTS = 'order-lifecycle:export-events';

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
     * The filters and the column selection arrive exactly as the form posted
     * them; ExportService is what knows how to read them.
     *
     * @return Response The CSV file download response.
     * @throws ForbiddenHttpException If the user lacks the required permission.
     * @throws Exception If the query or CSV generation fails.
     * @throws InvalidConfigException If a required component cannot be resolved.
     * @throws MethodNotAllowedHttpException If the request is not a POST request.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionCsv(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_EXPORT_EVENTS);
        // The export carries every customer's email and address, so it needs
        // the same access Commerce asks for to see those orders.
        $this->requirePermission('commerce-manageOrders');

        $request = $this->request;
        $plugin = OrderLifecycle::getInstance();

        $criteria = [
            'dateFrom' => $request->getBodyParam('dateFrom'),
            'dateTo' => $request->getBodyParam('dateTo'),
            'eventTypes' => $request->getBodyParam('eventTypes'),
            'orderId' => $request->getBodyParam('orderId'),
            'storeId' => $plugin->getStats()->resolveStoreId(),
        ];
        $columns = $request->getBodyParam('exportColumns', []);

        Craft::info(sprintf(
            'Lifecycle events exported by user %s (store %s, order %s, types %s)',
            Craft::$app->getUser()->getId() ?? '?',
            $criteria['storeId'] ?? 'all',
            $criteria['orderId'] ?: 'any',
            is_array($criteria['eventTypes']) ? implode(',', array_filter($criteria['eventTypes'], 'is_scalar')) : 'all',
        ), 'order-lifecycle');

        $stream = $plugin->getExport()->exportCsvStream($criteria, is_array($columns) ? $columns : []);

        $filename = 'lifecycle-events-' . (new DateTimeImmutable('now'))->format('Y-m-d-His') . '.csv';

        // Exported cells carry customer-supplied text, so stop the browser
        // second-guessing the declared type and rendering the file as HTML.
        $this->response->getHeaders()->set('X-Content-Type-Options', 'nosniff');

        return $this->response->sendStreamAsFile($stream, $filename, [
            'mimeType' => 'text/csv',
        ]);
    }
}
