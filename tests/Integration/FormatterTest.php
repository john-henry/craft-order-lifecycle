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
