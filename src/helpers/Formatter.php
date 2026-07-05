<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\helpers;

use Craft;
use craft\helpers\Inflector;
use yii\base\InvalidConfigException;

/**
 * Formatting utilities for order lifecycle data.
 *
 * All methods are static and testable without full application context.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class Formatter
{
    // =========================================================================
    // Constants
    // =========================================================================

    /**
     * @var string[] The address snapshot fields, in display order.
     */
    public const ADDRESS_FIELDS = [
        'firstName',
        'lastName',
        'addressLine1',
        'addressLine2',
        'locality',
        'administrativeArea',
        'postalCode',
        'countryCode',
    ];

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * Formats a currency value.
     *
     * @param float $amount The amount to format.
     * @param string $currency The ISO currency code.
     * @return string The formatted currency string.
     * @throws InvalidConfigException If the formatter component is unavailable.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function currency(float $amount, string $currency): string
    {
        return Craft::$app->getFormatter()->asCurrency($amount, $currency);
    }

    /**
     * Formats a date/time value.
     *
     * @param mixed $value The date/time value to format.
     * @param string $format The format width (short, medium, long, full).
     * @return string The formatted date/time string.
     * @throws InvalidConfigException If the formatter component is unavailable.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function dateTime(mixed $value, string $format = 'medium'): string
    {
        return Craft::$app->getFormatter()->asDatetime($value, $format);
    }

    /**
     * Formats a quantity change message.
     *
     * @param int $from The previous quantity.
     * @param int $to The new quantity.
     * @return string The change description.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function quantityChange(int $from, int $to): string
    {
        return "Total quantity changed from $from to $to";
    }

    /**
     * Formats a price change message.
     *
     * @param float $from The previous price.
     * @param float $to The new price.
     * @param string $currency The ISO currency code.
     * @return string The change description.
     * @throws InvalidConfigException If the formatter component is unavailable.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function priceChange(float $from, float $to, string $currency): string
    {
        $fromFormatted = self::currency($from, $currency);
        $toFormatted = self::currency($to, $currency);
        return "Total price changed from $fromFormatted to $toFormatted";
    }

    /**
     * Formats a coupon change message.
     *
     * @param string|null $from The previous coupon code.
     * @param string|null $to The new coupon code.
     * @return string|null The change description, or null if unchanged.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function couponChange(?string $from, ?string $to): ?string
    {
        if ($from === $to) {
            return null;
        }

        if ($to) {
            return "Coupon code '" . self::sanitize($to) . "' applied";
        }

        if ($from) {
            return "Coupon code '" . self::sanitize($from) . "' removed";
        }

        return null;
    }

    /**
     * Formats an order status change message.
     *
     * @param string|null $from The previous status.
     * @param string|null $to The new status.
     * @return string|null The change description, or null if unchanged or cleared.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function statusChange(?string $from, ?string $to): ?string
    {
        if ($from === $to || !$to) {
            return null;
        }

        return "Order status changed to '" . self::sanitize($to) . "'";
    }

    /**
     * Formats a shipping method change message.
     *
     * @param string|null $from The previous shipping method.
     * @param string|null $to The new shipping method.
     * @return string|null The change description, or null if unchanged.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function shippingMethodChange(?string $from, ?string $to): ?string
    {
        if ($from === $to) {
            return null;
        }

        if ($to && $from) {
            return 'Shipping method changed from <strong>' . self::sanitize($from) . '</strong> to <strong>' . self::sanitize($to) . '</strong>';
        }

        if ($to) {
            return 'Shipping method set to <strong>' . self::sanitize($to) . '</strong>';
        }

        if ($from) {
            return 'Shipping method <strong>' . self::sanitize($from) . '</strong> was removed';
        }

        return null;
    }

    /**
     * Formats a country change message.
     *
     * @param string|null $from The previous country.
     * @param string|null $to The new country.
     * @param string $type The address type (shipping or billing).
     * @return string|null The change description, or null if unchanged or cleared.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function countryChange(?string $from, ?string $to, string $type = 'shipping'): ?string
    {
        if ($from === $to || !$to) {
            return null;
        }

        $typeLabel = ucfirst($type);
        return "$typeLabel country changed to '" . self::sanitize($to) . "'";
    }

    /**
     * Formats a customer change message.
     *
     * @param string|null $fromEmail The previous customer email.
     * @param string|null $toEmail The new customer email.
     * @param string|null $customerType The customer type label (e.g. guest, registered).
     * @return string|null The change description, or null if unchanged.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function customerChange(?string $fromEmail, ?string $toEmail, ?string $customerType = null): ?string
    {
        $fromEmail = $fromEmail ?: null;
        $toEmail = $toEmail ?: null;

        if ($fromEmail === $toEmail) {
            return null;
        }

        if ($toEmail) {
            $typeLabel = $customerType ? ' (' . self::sanitize($customerType) . ')' : '';
            return "Email set to '" . self::sanitize($toEmail) . "'$typeLabel";
        }

        if ($fromEmail) {
            return "Email removed (was '" . self::sanitize($fromEmail) . "')";
        }

        return null;
    }

    /**
     * Compares line items and generates change descriptions.
     *
     * Identifies added, removed, and quantity-changed line items between two snapshots.
     *
     * @param array $previous Previous line items array.
     * @param array $current Current line items array.
     * @return array Array of change description strings.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function compareLineItems(array $previous, array $current): array
    {
        $changes = [];

        $prevBySku = [];
        foreach ($previous as $item) {
            $prevBySku[$item['sku']] = $item;
        }

        $currBySku = [];
        foreach ($current as $item) {
            $currBySku[$item['sku']] = $item;
        }

        foreach ($currBySku as $sku => $currItem) {
            // description (e.g. "Ballpoint Pen - Grey") reads better than the
            // SKU, which we still key the lookup arrays by
            $label = self::sanitize((string)(($currItem['description'] ?? '') ?: $sku));

            if (isset($prevBySku[$sku])) {
                $prevItem = $prevBySku[$sku];
                if ($prevItem['qty'] !== $currItem['qty']) {
                    $diff = $currItem['qty'] - $prevItem['qty'];
                    $action = $diff > 0 ? 'increased' : 'decreased';
                    $changes[] = "'$label' quantity $action from {$prevItem['qty']} to {$currItem['qty']}";
                }
            } else {
                $changes[] = "'$label' added (qty: {$currItem['qty']})";
            }
        }

        foreach ($prevBySku as $sku => $prevItem) {
            if (!isset($currBySku[$sku])) {
                $label = self::sanitize((string)(($prevItem['description'] ?? '') ?: $sku));
                $changes[] = "'$label' removed (was qty: {$prevItem['qty']})";
            }
        }

        return $changes;
    }

    /**
     * Formats a human-readable diff between two serialized address arrays.
     *
     * Returns a summary of which fields changed, were added, or were cleared.
     * Returns null when both addresses are null (no-op).
     *
     * @param array|null $from Previous address fields (null = no address).
     * @param array|null $to   Current address fields (null = address removed).
     * @param string     $type 'shipping' or 'billing'.
     * @return string|null
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function addressChange(?array $from, ?array $to, string $type = 'shipping'): ?string
    {
        $label = ucfirst($type) . ' address';

        if ($from === null && $to !== null) {
            $name = trim(($to['firstName'] ?? '') . ' ' . ($to['lastName'] ?? ''));
            $parts = array_filter([$name ?: null, $to['addressLine1'] ?? null, $to['locality'] ?? null, $to['countryCode'] ?? null]);
            $parts = array_map(static fn(string $part): string => self::sanitize($part), $parts);

            // no "{type} address set:" prefix - the event title covers that
            return $parts ? implode(', ', $parts) : null;
        }

        if ($from !== null && $to === null) {
            // event title already says "{Type} Address Removed"
            return null;
        }

        if ($from === null || $to === null) {
            return null;
        }

        $changes = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $fieldLabel = self::addressFieldLabel($field);
            $prev = $from[$field] ?? null;
            $curr = $to[$field] ?? null;
            if ($prev === $curr) {
                continue;
            }
            if ($prev === null || $prev === '') {
                $changes[] = "$fieldLabel set to '" . self::sanitize((string)$curr) . "'";
            } elseif ($curr === null || $curr === '') {
                $changes[] = "$fieldLabel removed";
            } else {
                $changes[] = "$fieldLabel changed from '" . self::sanitize((string)$prev) . "' to '" . self::sanitize((string)$curr) . "'";
            }
        }

        if (empty($changes)) {
            return null;
        }

        return $label . ' updated: ' . implode(', ', $changes);
    }

    /**
     * Sanitizes text for safe HTML output.
     *
     * @param string $text The text to sanitize.
     * @return string The escaped text.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function sanitize(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Returns a human-readable label for a top-level order snapshot field.
     *
     * Used to label rows in the timeline's "changes since previous event"
     * diff, which otherwise shows raw camelCase snapshot keys (e.g.
     * `shippingMethodHandle`) that aren't meaningful to a store manager.
     *
     * @param string $field The raw snapshot field name (e.g. `statusHandle`).
     * @return string The human-readable label, or the field name humanised if unrecognised.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function orderFieldLabel(string $field): string
    {
        return match ($field) {
            'id' => Craft::t('order-lifecycle', 'Order ID'),
            'number' => Craft::t('order-lifecycle', 'Order Number'),
            'isCompleted' => Craft::t('order-lifecycle', 'Completed'),
            'dateOrdered' => Craft::t('order-lifecycle', 'Date Ordered'),
            'couponCode' => Craft::t('order-lifecycle', 'Coupon Code'),
            'totalQty' => Craft::t('order-lifecycle', 'Cart Total Quantity'),
            'totalPrice' => Craft::t('order-lifecycle', 'Cart Total Price'),
            'totalShippingCost' => Craft::t('order-lifecycle', 'Shipping Cost'),
            'currency' => Craft::t('order-lifecycle', 'Currency'),
            'statusId' => Craft::t('order-lifecycle', 'Status ID'),
            'statusHandle' => Craft::t('order-lifecycle', 'Order Status'),
            'shippingMethodHandle' => Craft::t('order-lifecycle', 'Shipping Method'),
            'shippingMethodName' => Craft::t('order-lifecycle', 'Shipping Method Name'),
            'shippingAddressId' => Craft::t('order-lifecycle', 'Shipping Address'),
            'billingAddressId' => Craft::t('order-lifecycle', 'Billing Address'),
            default => Inflector::camel2words($field, true),
        };
    }

    /**
     * Returns a human-readable label for an address snapshot field.
     *
     * Used both by {@see addressChange()}'s field-by-field diff message and
     * by the timeline's per-event address diff (built from a
     * shippingAddressSet/billingAddressSet event's own before/after payload).
     *
     * @param string $field The raw address field name (e.g. `addressLine1`).
     * @return string The human-readable label, or the field name humanised if unrecognised.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function addressFieldLabel(string $field): string
    {
        return match ($field) {
            'firstName' => Craft::t('order-lifecycle', 'First Name'),
            'lastName' => Craft::t('order-lifecycle', 'Last Name'),
            'addressLine1' => Craft::t('order-lifecycle', 'Address'),
            'addressLine2' => Craft::t('order-lifecycle', 'Address Line 2'),
            'locality' => Craft::t('order-lifecycle', 'City'),
            'administrativeArea' => Craft::t('order-lifecycle', 'State/Province'),
            'postalCode' => Craft::t('order-lifecycle', 'Postcode'),
            'countryCode' => Craft::t('order-lifecycle', 'Country'),
            default => Inflector::camel2words($field, true),
        };
    }
}
