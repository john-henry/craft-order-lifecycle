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
use johnhenry\orderlifecycle\assets\OrderLifecycleAsset;
use johnhenry\orderlifecycle\OrderLifecycle;
use Throwable;

/**
 * Order Lifecycle field type.
 *
 * Read-only Commerce order field that renders the recorded lifecycle events and
 * transactions for the order it is attached to.
 *
 * @author John Henry Donovan
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function displayName(): string
    {
        return Craft::t('order-lifecycle', 'Order Lifecycle Events');
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether this field stores its value in a content column.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function hasContentColumn(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     *
     * @return string The field icon handle.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function icon(): string
    {
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    /**
     * @inheritdoc
     *
     * @return array The supported translation methods.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function supportedTranslationMethods(): array
    {
        return [
            self::TRANSLATION_METHOD_NONE,
        ];
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
     * @author John Henry Donovan
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

        $logger = OrderLifecycle::getInstance()->getLogger();
        $logs = $logger->getLogsForOrder($element->id);
        $timeline = $logger->getTimelineForOrder($element->id);
        $statuses = Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses();

        return Craft::$app->getView()->renderTemplate('order-lifecycle/_field-input', [
            'order' => $element,
            'logs' => $logs,
            'timeline' => $timeline,
            'field' => $this,
            'statuses' => $statuses,
            'transactions' => $element->getTransactions(),
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _note(string $message, ?string $modifier = null): string
    {
        $class = 'note' . ($modifier !== null ? ' ' . $modifier : '');

        return '<div class="readable"><blockquote class="' . $class . '"><p>' . $message . '</p></blockquote></div>';
    }
}
