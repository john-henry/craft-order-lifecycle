<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\helpers;

use Craft;
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
            return "Coupon code '$to' applied";
        }

        if ($from) {
            return "Coupon code '$from' removed";
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

        return "Order status changed to '$to'";
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
            return "Shipping method changed from <strong>$from</strong> to <strong>$to</strong>";
        }

        if ($to) {
            return "Shipping method set to <strong>$to</strong>";
        }

        if ($from) {
            return "Shipping method <strong>$from</strong> was removed";
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
        return "$typeLabel country changed to '$to'";
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
        // Normalize empty strings to null
        $fromEmail = $fromEmail ?: null;
        $toEmail = $toEmail ?: null;

        if ($fromEmail === $toEmail) {
            return null;
        }

        if ($toEmail) {
            $typeLabel = $customerType ? " ($customerType)" : '';
            return "Email set to '$toEmail'$typeLabel";
        }

        if ($fromEmail) {
            return "Email removed (was '$fromEmail')";
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

        // Create lookup arrays by SKU
        $prevBySku = [];
        foreach ($previous as $item) {
            $prevBySku[$item['sku']] = $item;
        }

        $currBySku = [];
        foreach ($current as $item) {
            $currBySku[$item['sku']] = $item;
        }

        // Check for quantity changes and new items
        foreach ($currBySku as $sku => $currItem) {
            if (isset($prevBySku[$sku])) {
                // Item exists in both - check for changes
                $prevItem = $prevBySku[$sku];
                if ($prevItem['qty'] !== $currItem['qty']) {
                    $diff = $currItem['qty'] - $prevItem['qty'];
                    $action = $diff > 0 ? 'increased' : 'decreased';
                    $changes[] = "'$sku' quantity $action from {$prevItem['qty']} to {$currItem['qty']}";
                }
            } else {
                // New item
                $changes[] = "'$sku' added (qty: {$currItem['qty']})";
            }
        }

        // Check for removed items
        foreach ($prevBySku as $sku => $prevItem) {
            if (!isset($currBySku[$sku])) {
                $changes[] = "'$sku' removed (was qty: {$prevItem['qty']})";
            }
        }

        return $changes;
    }

    /**
     * Sanitizes text for safe HTML output.
     *
     * @param string $text The text to sanitize.
     * @return string The escaped text.
     * @author John Henry Donovan
     * @since 1.0.0
     */
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
            return $label . ' set' . ($parts ? ': ' . implode(', ', $parts) : '');
        }

        if ($from !== null && $to === null) {
            return $label . ' removed';
        }

        if ($from === null || $to === null) {
            return null;
        }

        $labels = [
            'firstName' => 'First name',
            'lastName' => 'Last name',
            'addressLine1' => 'Address',
            'addressLine2' => 'Address line 2',
            'locality' => 'City',
            'administrativeArea' => 'State/Province',
            'postalCode' => 'Postcode',
            'countryCode' => 'Country',
        ];

        $changes = [];
        foreach ($labels as $field => $fieldLabel) {
            $prev = $from[$field] ?? null;
            $curr = $to[$field] ?? null;
            if ($prev === $curr) {
                continue;
            }
            if ($prev === null || $prev === '') {
                $changes[] = "$fieldLabel set to '$curr'";
            } elseif ($curr === null || $curr === '') {
                $changes[] = "$fieldLabel removed";
            } else {
                $changes[] = "$fieldLabel changed from '$prev' to '$curr'";
            }
        }

        if (empty($changes)) {
            return null;
        }

        return $label . ' updated: ' . implode(', ', $changes);
    }

    public static function sanitize(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
