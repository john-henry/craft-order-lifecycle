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
        return 'clock';
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
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $value The field value.
     * @param ElementInterface|null $element The element the field is attached to.
     * @return string The rendered input HTML.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        if (!class_exists(Order::class)) {
            return '<div class="readable">
                <blockquote class="note">
                    <p>' . Craft::t('order-lifecycle', 'Craft Commerce must be installed to use this field.') . '</p>
                </blockquote>
            </div>';
        }

        if ($element === null) {
            return '<div class="readable">
                <blockquote class="note">
                    <p>' . Craft::t('order-lifecycle', 'This field can only be used with saved orders.') . '</p>
                </blockquote>
            </div>';
        }

        if (!$element instanceof Order) {
            return '<div class="readable">
                <blockquote class="note warning">
                    <p>' . Craft::t('order-lifecycle', 'This field can only be added to Commerce Order elements.') . '</p>
                </blockquote>
            </div>';
        }

        if (!$element->id) {
            return '<div class="readable">
                <blockquote class="note">
                    <p>' . Craft::t('order-lifecycle', 'Order lifecycle events will appear after the order is saved.') . '</p>
                </blockquote>
            </div>';
        }

        // Register the asset bundle
        Craft::$app->getView()->registerAssetBundle(OrderLifecycleAsset::class);

        $logs = OrderLifecycle::getInstance()->logger->getLogsForOrder(
            $element->id
        );

        // Get all order statuses
        $statuses = Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses();


        return Craft::$app->getView()->renderTemplate(
            'order-lifecycle/_field-input',
            [
                'order' => $element,
                'logs' => $logs,
                'field' => $this,
                'statuses' => $statuses,
                'transactions' => $element->getTransactions(),
            ]
        );
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        return $this->getInputHtml($value, $element);
    }
}
