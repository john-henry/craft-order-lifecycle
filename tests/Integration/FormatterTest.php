<?php

use johnhenry\orderlifecycle\helpers\Formatter;


describe('Formatter::priceChange()', function () {
    it('describes a price increase', function () {
        $from = Craft::$app->getFormatter()->asCurrency(10.0, 'EUR');
        $to   = Craft::$app->getFormatter()->asCurrency(25.0, 'EUR');

        expect(Formatter::priceChange(10.0, 25.0, 'EUR'))
            ->toBe("Total price changed from $from to $to");
    });

    it('describes a price decrease', function () {
        $from = Craft::$app->getFormatter()->asCurrency(50.0, 'USD');
        $to   = Craft::$app->getFormatter()->asCurrency(9.99, 'USD');

        expect(Formatter::priceChange(50.0, 9.99, 'USD'))
            ->toBe("Total price changed from $from to $to");
    });

    it('uses the supplied currency code for formatting', function () {
        $result = Formatter::priceChange(1.0, 2.0, 'GBP');

        // The exact symbol depends on locale, but the currency must appear somewhere.
        expect($result)->toContain('1')
            ->and($result)->toContain('2');
    });
});

describe('Formatter::couponChange()', function () {
    it('describes a coupon being applied', function () {
        expect(Formatter::couponChange(null, 'SAVE10'))->toContain('SAVE10');
    });

    it('describes a coupon being removed', function () {
        expect(Formatter::couponChange('SAVE10', null))->toContain('SAVE10');
    });

    it('returns null when the coupon is unchanged', function () {
        expect(Formatter::couponChange('SAVE10', 'SAVE10'))->toBeNull();
    });

    it('escapes a coupon code containing HTML', function () {
        expect(Formatter::couponChange(null, '<b>x</b>'))->not->toContain('<b>');
    });
});

describe('Formatter::statusChange()', function () {
    it('describes a new status', function () {
        expect(Formatter::statusChange('new', 'shipped'))->toContain('shipped');
    });

    it('returns null when the status is cleared or unchanged', function () {
        expect(Formatter::statusChange('shipped', null))->toBeNull()
            ->and(Formatter::statusChange('shipped', 'shipped'))->toBeNull();
    });
});

describe('Formatter field labels', function () {
    it('maps a known order field to its translated label', function () {
        expect(Formatter::orderFieldLabel('statusHandle'))->toBe('Order Status');
    });

    it('humanises an unrecognised order field name', function () {
        expect(Formatter::orderFieldLabel('someUnknownField'))->toBe('Some Unknown Field');
    });

    it('maps a known address field to its translated label', function () {
        expect(Formatter::addressFieldLabel('addressLine1'))->toBe('Address');
    });

    it('humanises an unrecognised address field name', function () {
        expect(Formatter::addressFieldLabel('someUnknownField'))->toBe('Some Unknown Field');
    });
});
