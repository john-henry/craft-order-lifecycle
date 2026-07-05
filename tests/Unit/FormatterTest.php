<?php

use johnhenry\orderlifecycle\helpers\Formatter;

describe('Formatter::quantityChange()', function () {
    it('describes an increase', function () {
        expect(Formatter::quantityChange(2, 5))
            ->toBe('Total quantity changed from 2 to 5');
    });

    it('describes a decrease', function () {
        expect(Formatter::quantityChange(5, 1))
            ->toBe('Total quantity changed from 5 to 1');
    });
});

describe('Formatter::couponChange()', function () {
    it('returns null when code is unchanged', function () {
        expect(Formatter::couponChange('SAVE10', 'SAVE10'))->toBeNull();
        expect(Formatter::couponChange(null, null))->toBeNull();
    });

    it('describes an applied coupon', function () {
        expect(Formatter::couponChange(null, 'SAVE10'))
            ->toBe("Coupon code 'SAVE10' applied");
    });

    it('describes a removed coupon', function () {
        expect(Formatter::couponChange('SAVE10', null))
            ->toBe("Coupon code 'SAVE10' removed");
    });
});

describe('Formatter::statusChange()', function () {
    it('returns null when status is unchanged', function () {
        expect(Formatter::statusChange('processing', 'processing'))->toBeNull();
    });

    it('returns null when new status is null', function () {
        expect(Formatter::statusChange('processing', null))->toBeNull();
    });

    it('describes a status change', function () {
        expect(Formatter::statusChange('pending', 'complete'))
            ->toBe("Order status changed to 'complete'");
    });
});

describe('Formatter::shippingMethodChange()', function () {
    it('returns null when method is unchanged', function () {
        expect(Formatter::shippingMethodChange('Standard', 'Standard'))->toBeNull();
    });

    it('describes switching from one method to another', function () {
        expect(Formatter::shippingMethodChange('Standard', 'Express'))
            ->toBe('Shipping method changed from <strong>Standard</strong> to <strong>Express</strong>');
    });

    it('describes a newly set method', function () {
        expect(Formatter::shippingMethodChange(null, 'Express'))
            ->toBe('Shipping method set to <strong>Express</strong>');
    });

    it('describes a removed method', function () {
        expect(Formatter::shippingMethodChange('Standard', null))
            ->toBe('Shipping method <strong>Standard</strong> was removed');
    });
});

describe('Formatter::countryChange()', function () {
    it('returns null when country is unchanged', function () {
        expect(Formatter::countryChange('IE', 'IE'))->toBeNull();
    });

    it('returns null when new country is null', function () {
        expect(Formatter::countryChange('IE', null))->toBeNull();
    });

    it('describes a shipping country change by default', function () {
        expect(Formatter::countryChange('IE', 'GB'))
            ->toBe("Shipping country changed to 'GB'");
    });

    it('describes a billing country change', function () {
        expect(Formatter::countryChange('IE', 'US', 'billing'))
            ->toBe("Billing country changed to 'US'");
    });
});

describe('Formatter::customerChange()', function () {
    it('returns null when email is unchanged', function () {
        expect(Formatter::customerChange('a@b.com', 'a@b.com'))->toBeNull();
    });

    it('normalizes empty strings to null', function () {
        expect(Formatter::customerChange('', ''))->toBeNull();
    });

    it('describes a new guest email', function () {
        expect(Formatter::customerChange(null, 'user@example.com', 'guest'))
            ->toBe("Email set to 'user@example.com' (guest)");
    });

    it('describes a new registered customer email', function () {
        expect(Formatter::customerChange(null, 'user@example.com', 'customer'))
            ->toBe("Email set to 'user@example.com' (customer)");
    });

    it('describes a removed email', function () {
        expect(Formatter::customerChange('user@example.com', null))
            ->toBe("Email removed (was 'user@example.com')");
    });
});

describe('Formatter::compareLineItems()', function () {
    it('detects an added item', function () {
        $changes = Formatter::compareLineItems(
            [],
            [['sku' => 'WIDGET-A', 'qty' => 2, 'subtotal' => 20.00]]
        );
        expect($changes)->toContain("'WIDGET-A' added (qty: 2)");
    });

    it('detects a removed item', function () {
        $changes = Formatter::compareLineItems(
            [['sku' => 'WIDGET-A', 'qty' => 2, 'subtotal' => 20.00]],
            []
        );
        expect($changes)->toContain("'WIDGET-A' removed (was qty: 2)");
    });

    it('detects a quantity increase', function () {
        $changes = Formatter::compareLineItems(
            [['sku' => 'WIDGET-A', 'qty' => 1, 'subtotal' => 10.00]],
            [['sku' => 'WIDGET-A', 'qty' => 3, 'subtotal' => 30.00]]
        );
        expect($changes)->toContain("'WIDGET-A' quantity increased from 1 to 3");
    });

    it('detects a quantity decrease', function () {
        $changes = Formatter::compareLineItems(
            [['sku' => 'WIDGET-A', 'qty' => 3, 'subtotal' => 30.00]],
            [['sku' => 'WIDGET-A', 'qty' => 1, 'subtotal' => 10.00]]
        );
        expect($changes)->toContain("'WIDGET-A' quantity decreased from 3 to 1");
    });

    it('returns an empty array when nothing changed', function () {
        $items = [['sku' => 'WIDGET-A', 'qty' => 2, 'subtotal' => 20.00]];
        expect(Formatter::compareLineItems($items, $items))->toBeEmpty();
    });

    it('handles multiple simultaneous changes', function () {
        $changes = Formatter::compareLineItems(
            [
                ['sku' => 'WIDGET-A', 'qty' => 2, 'subtotal' => 20.00],
                ['sku' => 'WIDGET-B', 'qty' => 1, 'subtotal' => 10.00],
            ],
            [
                ['sku' => 'WIDGET-A', 'qty' => 3, 'subtotal' => 30.00],
                ['sku' => 'WIDGET-C', 'qty' => 1, 'subtotal' => 15.00],
            ]
        );
        expect($changes)
            ->toContain("'WIDGET-A' quantity increased from 2 to 3")
            ->toContain("'WIDGET-C' added (qty: 1)")
            ->toContain("'WIDGET-B' removed (was qty: 1)");
    });
});

describe('Formatter XSS escaping', function () {
    it('escapes coupon codes', function () {
        expect(Formatter::couponChange(null, '<img src=x onerror=alert(1)>'))
            ->toBe("Coupon code '&lt;img src=x onerror=alert(1)&gt;' applied");
    });

    it('escapes SKUs in line item changes', function () {
        $changes = Formatter::compareLineItems(
            [],
            [['sku' => '<script>alert(1)</script>', 'qty' => 1, 'subtotal' => 10.00]]
        );
        expect($changes)->toContain("'&lt;script&gt;alert(1)&lt;/script&gt;' added (qty: 1)");
    });

    it('escapes customer email addresses', function () {
        expect(Formatter::customerChange(null, '"><script>alert(1)</script>@example.com'))
            ->toBe('Email set to \'&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;@example.com\'');
    });

    it('escapes address fields on initial set', function () {
        $result = Formatter::addressChange(null, [
            'firstName' => '<img src=x onerror=alert(1)>',
            'lastName' => 'Doe',
            'addressLine1' => '123 Main St',
            'locality' => 'City',
            'countryCode' => 'IE',
        ]);
        expect($result)->toContain('&lt;img src=x onerror=alert(1)&gt;')
            ->not->toContain('<img src=x');
    });

    it('escapes address fields on update', function () {
        $result = Formatter::addressChange(
            ['addressLine1' => '123 Main St'],
            ['addressLine1' => '<script>alert(1)</script>'],
        );
        expect($result)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->not->toContain('<script>');
    });

    it('escapes shipping method names', function () {
        expect(Formatter::shippingMethodChange(null, '<script>alert(1)</script>'))
            ->toBe('Shipping method set to <strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong>');
    });
});

describe('Formatter::sanitize()', function () {
    it('escapes HTML special characters', function () {
        expect(Formatter::sanitize('<script>alert("xss")</script>'))
            ->toBe('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;');
    });

    it('passes safe text through unchanged', function () {
        expect(Formatter::sanitize('Hello World'))->toBe('Hello World');
    });

    it('escapes single quotes', function () {
        expect(Formatter::sanitize("it's a test"))->toBe('it&apos;s a test');
    });
});
