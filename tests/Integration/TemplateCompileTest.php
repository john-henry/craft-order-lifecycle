<?php

use Twig\Source;

// ---------------------------------------------------------------------------
// CP template compile smoke test
// ---------------------------------------------------------------------------
// What the dashboard and the order field do in a browser is browser territory,
// but a template that will not compile takes its whole page down and nothing
// else in this suite would notice. Tokenising and parsing every template under
// Craft's own Twig environment catches a syntax slip without any page context.

describe('Plugin CP templates', function() {
    it('compiles every template without a syntax error', function() {
        $templatesPath = Craft::getAlias('@root') . '/plugins/craft-order-lifecycle/src/templates';

        // CP-only Twig functions (actionInput and the rest) only exist in the
        // control panel environment, which is what these templates render under.
        $view = Craft::$app->getView();
        $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);
        $twig = $view->getTwig();

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesPath));
        $checked = [];

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $name = substr($file->getPathname(), strlen($templatesPath) + 1);
            $twig->parse($twig->tokenize(new Source(file_get_contents($file->getPathname()), $name)));
            $checked[] = str_replace('\\', '/', $name);
        }

        expect($checked)->toContain('_settings.twig')
            ->and($checked)->toContain('_field-input.twig')
            ->and($checked)->toContain('_dashboard/overview.twig')
            ->and($checked)->toContain('_dashboard/insights.twig')
            ->and($checked)->toContain('_dashboard/export.twig')
            ->and($checked)->toContain('_utilities/export.twig')
            ->and($checked)->toContain('_widgets/_error.twig')
            ->and($checked)->toContain('_widgets/stats/body.twig')
            ->and($checked)->toContain('_widgets/ai-insights/body.twig');
    });

    // Compiling cannot catch a missing {% import %}: Twig only raises "Variable
    // does not exist" at render time, so a template using forms.* without the
    // import ships broken and looks fine here. Require the import statically in
    // any template that reaches for an alias.
    it('imports the macros and forms wherever they are used', function() {
        $templatesPath = Craft::getAlias('@root') . '/plugins/craft-order-lifecycle/src/templates';

        $aliases = [
            'forms' => '/import\s+[\'"]_includes\/forms(\.twig)?[\'"]\s+as\s+forms/',
            'statusBadges' => '/import\s+[\'"][^\'"]+[\'"]\s+as\s+statusBadges/',
            'eventIcons' => '/import\s+[\'"][^\'"]+[\'"]\s+as\s+eventIcons/',
        ];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesPath));
        $missing = [];

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $source = (string)file_get_contents($file->getPathname());
            $name = substr($file->getPathname(), strlen($templatesPath) + 1);

            foreach ($aliases as $alias => $importPattern) {
                if (preg_match('/\b' . $alias . '\./', $source) && !preg_match($importPattern, $source)) {
                    $missing[] = "{$name} uses {$alias}. without importing it";
                }
            }
        }

        expect($missing)->toBe([]);
    });

    // House rule: commentary belongs in PHP, JS or the docs, never in a plugin
    // template.
    it('carries no Twig comments', function() {
        $templatesPath = Craft::getAlias('@root') . '/plugins/craft-order-lifecycle/src/templates';

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesPath));
        $offenders = [];

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            // The whole comment, opener and closer both. A bare `{#` also turns
            // up inside an ICU plural (`other{# hops}`), which is a message
            // format and not commentary.
            if (preg_match('/\{#.*?#\}/s', (string)file_get_contents($file->getPathname())) === 1) {
                $offenders[] = substr($file->getPathname(), strlen($templatesPath) + 1);
            }
        }

        expect($offenders)->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// Control panel JavaScript
// ---------------------------------------------------------------------------

describe('Plugin CP JavaScript', function() {
    // House rule: no jQuery in control panel assets. Garnish is jQuery-based
    // itself and normalises whatever it is handed, so the one place that talks
    // to it passes a selector rather than a collection.
    it('uses no jQuery', function() {
        $jsPath = Craft::getAlias('@root') . '/plugins/craft-order-lifecycle/src/resources/js';
        $offenders = [];

        foreach (glob("$jsPath/*.js") ?: [] as $file) {
            // Comments stripped first, or the note explaining that Garnish is
            // the jQuery here counts as a use of it.
            $source = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string)file_get_contents($file));

            if (preg_match('/(?<![\w.])\$\(|\bjQuery\b/', (string)$source) === 1) {
                $offenders[] = basename($file);
            }
        }

        expect($offenders)->toBe([]);
    });

    // Garnish hands its argument to jQuery's makeArray, which wraps a string
    // as a single entry rather than resolving it as a selector. Passing
    // '#exportColumnsList tr' therefore gives the dragger one string and no
    // rows, and the column sort silently stops working with nothing in the
    // console to say so. It has to be given elements.
    it('hands Garnish elements rather than a selector', function() {
        $source = (string)file_get_contents(
            Craft::getAlias('@root') . '/plugins/craft-order-lifecycle/src/resources/js/export.js'
        );

        preg_match_all('/new\s+Garnish\.\w+\(\s*([^,]+),/', $source, $matches);

        expect($matches[1])->not->toBeEmpty();

        foreach ($matches[1] as $firstArgument) {
            expect(trim($firstArgument))->not->toStartWith("'")
                ->and(trim($firstArgument))->not->toStartWith('"');
        }
    });

    // The preset buttons are rendered from the template and read back by name
    // in the script's switch, so the two lists have to agree or a button
    // silently does nothing.
    it('handles every date preset the export template renders', function() {
        $root = Craft::getAlias('@root') . '/plugins/craft-order-lifecycle/src';

        preg_match_all(
            '/data-preset="([^"]+)"/',
            (string)file_get_contents("$root/templates/_utilities/export.twig"),
            $rendered,
        );
        preg_match_all(
            "/case '([^']+)':/",
            (string)file_get_contents("$root/resources/js/export.js"),
            $handled,
        );

        expect($rendered[1])->not->toBeEmpty()
            ->and(array_diff($rendered[1], $handled[1]))->toBe([])
            ->and(array_diff($handled[1], $rendered[1]))->toBe([]);
    });
});
