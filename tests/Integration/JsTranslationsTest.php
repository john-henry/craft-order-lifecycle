<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\orderlifecycle\OrderLifecycle;

/**
 * Guards OrderLifecycle::JS_TRANSLATIONS against drift.
 *
 * Craft.t() in JavaScript reads Craft.translations, which is only populated by
 * View::registerTranslations(). A Craft.t() call whose message is missing from
 * JS_TRANSLATIONS renders in English on every translated install, and does so
 * silently: Craft.t() falls back to the source message rather than erroring, and
 * an English install looks correct either way. Nothing but this test notices.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */

/**
 * Every message this plugin passes to Craft.t() in JS, found by reading the
 * source rather than trusting a hand-kept list.
 *
 * @return string[]
 */
function olJsTranslatedMessages(): array
{
    $root = dirname(__DIR__, 2) . '/src';
    $files = array_merge(
        glob("$root/resources/js/*.js") ?: [],
        // Inline {% js %} blocks call Craft.t() too.
        iterator_to_array(new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/templates")),
            '/\.twig$/',
            RegexIterator::MATCH,
        )),
    );

    $messages = [];

    foreach ($files as $file) {
        $contents = file_get_contents((string)$file);
        preg_match_all(
            '/Craft\.t\(\s*[\'"]order-lifecycle[\'"]\s*,\s*([\'"])(.*?)\1/s',
            $contents,
            $matches,
        );
        foreach ($matches[2] as $message) {
            $messages[$message] = true;
        }
    }

    return array_keys($messages);
}

it('registers every Craft.t() message used in JavaScript', function() {
    $used = olJsTranslatedMessages();

    expect($used)->not->toBeEmpty('No Craft.t() calls found - has the regex gone stale?');

    $missing = array_values(array_diff($used, OrderLifecycle::JS_TRANSLATIONS));

    expect($missing)->toBe([], sprintf(
        "These messages are passed to Craft.t() but are absent from JS_TRANSLATIONS, so they " .
        "will render in English on translated installs:\n  - %s",
        implode("\n  - ", $missing),
    ));
});

it('has no unused entries in JS_TRANSLATIONS', function() {
    $orphaned = array_values(array_diff(OrderLifecycle::JS_TRANSLATIONS, olJsTranslatedMessages()));

    expect($orphaned)->toBe([], sprintf(
        "These entries are in JS_TRANSLATIONS but no longer used by any Craft.t() call:\n  - %s",
        implode("\n  - ", $orphaned),
    ));
});

it('has a source-language entry for every JS message', function() {
    $translations = require dirname(__DIR__, 2) . '/src/translations/en/order-lifecycle.php';

    $missing = array_values(array_diff(OrderLifecycle::JS_TRANSLATIONS, array_keys($translations)));

    expect($missing)->toBe([], sprintf(
        "These JS messages have no entry in translations/en, so translators never see them:\n  - %s",
        implode("\n  - ", $missing),
    ));
});
