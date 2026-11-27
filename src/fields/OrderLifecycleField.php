<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Html;
use craft\models\GqlSchema;
use johnhenry\orderlifecycle\assets\OrderLifecycleAsset;
use johnhenry\orderlifecycle\controllers\AiController;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\services\OrderLifecycleLogger;
use Throwable;

/**
 * Order Lifecycle field type.
 *
 * Read-only Commerce order field that renders the recorded lifecycle events and
 * transactions for the order it is attached to.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class OrderLifecycleField extends Field
{
    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The translated field display name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function displayName(): string
    {
        return Craft::t('order-lifecycle', 'Order Lifecycle Events');
    }

    /**
     * @inheritdoc
     *
     * The timeline is read from the plugin's own log table, so nothing is kept
     * in the order's content.
     *
     * @return null No content to store.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function dbType(): array|string|null
    {
        return null;
    }

    /**
     * @inheritdoc
     *
     * @return string The field icon handle.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function icon(): string
    {
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    /**
     * @inheritdoc
     *
     * The field never holds a value, so making it required would fail every
     * save of the order it's on.
     *
     * @return bool Always false.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function isRequirable(): bool
    {
        return false;
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param GqlSchema $schema The schema being built.
     * @return bool Always false: the field has no value to expose.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function includeInGqlSchema(GqlSchema $schema): bool
    {
        return false;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $value The field value.
     * @param ElementInterface|null $element The element the field is attached to.
     * @param bool $inline Whether the field is being rendered inline.
     * @return string The rendered input HTML.
     * @throws Throwable If the field template cannot be rendered.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        if (!class_exists(Order::class)) {
            return $this->_note(Craft::t('order-lifecycle', 'Craft Commerce must be installed to use this field.'));
        }

        if ($element === null) {
            return $this->_note(Craft::t('order-lifecycle', 'This field can only be used with saved orders.'));
        }

        if (!$element instanceof Order) {
            return $this->_note(
                Craft::t('order-lifecycle', 'This field can only be added to Commerce Order elements.'),
                'warning'
            );
        }

        if (!$element->id) {
            return $this->_note(Craft::t('order-lifecycle', 'Order lifecycle events will appear after the order is saved.'));
        }

        Craft::$app->getView()->registerAssetBundle(OrderLifecycleAsset::class);

        $plugin = OrderLifecycle::getInstance();
        $logger = $plugin->getLogger();
        $logs = $logger->getLogsForOrder($element->id);
        $timeline = $logger->getTimelineForOrder($element->id, $logs);
        // Status IDs belong to a store, so this order's store's statuses
        $statuses = Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses($element->storeId);

        // Keyed by id so a timeline row finds its transaction by lookup. The
        // list itself is still passed: a refund event needs the children of the
        // charge it matched, which is a scan either way.
        $transactions = $element->getTransactions();
        $transactionsById = [];
        foreach ($transactions as $transaction) {
            $transactionsById[(int)$transaction->id] = $transaction;
        }

        // Resolved here rather than in the template: a control panel screen has
        // no business reaching through craft.orderLifecycle, which exists for
        // front-end templates, or through craft.app.plugins to read settings.
        $savedInsight = null;
        foreach ($logs as $log) {
            if ($log['type'] === EventType::AI_INSIGHTS->value) {
                $savedInsight = $log;
                break;
            }
        }

        return Craft::$app->getView()->renderTemplate('order-lifecycle/_field-input', [
            'order' => $element,
            'isGuest' => OrderLifecycleLogger::isGuest($element),
            'logs' => $logs,
            'timeline' => $timeline,
            'field' => $this,
            'statuses' => $statuses,
            'transactions' => $transactions,
            'transactionsById' => $transactionsById,
            'settings' => $plugin->getSettings(),
            'hasApiKey' => $plugin->getAiInsights()->getApiKey() !== '',
            'canGenerateInsights' => Craft::$app->getUser()->checkPermission(AiController::PERMISSION_GENERATE_INSIGHTS),
            'previousOrders' => $plugin->getStats()->getPreviousOrderCount($element),
            'savedInsights' => $savedInsight,
            'structuredInsights' => $savedInsight !== null
                ? $plugin->getAiInsights()->decodeStructuredInsights($savedInsight['message'] ?? null)
                : null,
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Renders a read-only CP note blockquote.
     *
     * @param string $message The note message.
     * @param string|null $modifier An optional blockquote modifier class (e.g. warning).
     * @return string The note HTML.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _note(string $message, ?string $modifier = null): string
    {
        $class = 'note' . ($modifier !== null ? ' ' . $modifier : '');

        return '<div class="readable"><blockquote class="' . $class . '"><p>'
            . Html::encode($message) . '</p></blockquote></div>';
    }
}
