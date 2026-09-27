<?php

use craft\web\View;
use johnhenry\orderlifecycle\controllers\SettingsController;
use johnhenry\orderlifecycle\models\SettingsModel;

// ---------------------------------------------------------------------------
// Settings pinned in config/order-lifecycle.php
// ---------------------------------------------------------------------------
// The settings model already carries the config file's values when the
// settings page saves, and Craft writes whatever it is handed into project
// config, which is committed to git. A value resolved from the environment
// would end up in the YAML. These write a real config file for the length of
// each test, since Craft reads it straight off disk.

/**
 * Writes config/order-lifecycle.php for one test, refusing to overwrite a real one.
 */
function olWriteConfigFile(array $config): string
{
    $path = Craft::$app->getConfig()->getConfigFilePath('order-lifecycle');

    if (file_exists($path)) {
        throw new RuntimeException("$path already exists; not overwriting it for a test.");
    }

    file_put_contents($path, '<?php return ' . var_export($config, true) . ';');

    return $path;
}

/**
 * Runs the settings controller's project-config filter on a set of attributes.
 */
function olPersistable(array $attributes): array
{
    $method = new ReflectionMethod(SettingsController::class, '_persistable');

    return $method->invoke(new SettingsController('settings', Craft::$app), $attributes);
}

describe('settings set in config/order-lifecycle.php', function() {
    afterEach(function() {
        $path = Craft::$app->getConfig()->getConfigFilePath('order-lifecycle');
        if (($this->olConfigPath ?? null) === $path && file_exists($path)) {
            unlink($path);
        }
    });

    it('keeps config file values out of what the settings page saves', function() {
        $this->olConfigPath = olWriteConfigFile([
            'anthropicApiKey' => 'sk-ant-from-the-environment',
            'asyncLogging' => true,
        ]);

        $stored = Craft::$app->getProjectConfig()->get('plugins.order-lifecycle.settings') ?? [];
        $saved = olPersistable([
            'anthropicApiKey' => 'sk-ant-from-the-environment',
            'asyncLogging' => true,
            'logLineItems' => false,
        ]);

        $expectKept = static function(string $key) use ($stored, $saved) {
            if (array_key_exists($key, $stored)) {
                expect($saved[$key])->toBe($stored[$key]);
            } else {
                expect($saved)->not->toHaveKey($key);
            }
        };

        $expectKept('anthropicApiKey');
        $expectKept('asyncLogging');
        expect($saved['logLineItems'])->toBeFalse();
    });

    it('saves everything when there is no config file', function() {
        $attributes = ['asyncLogging' => true, 'logLineItems' => false];

        expect(olPersistable($attributes))->toBe($attributes);
    });

    // A key the config file supplies comes from wherever the developer chose,
    // and never reaches project config, so it isn't held to the $ENV_VAR rule.
    it('accepts a literal API key only when the config file sets it', function() {
        $literal = new SettingsModel(['anthropicApiKey' => 'sk-ant-literal']);
        expect($literal->validate(['anthropicApiKey']))->toBeFalse();

        $this->olConfigPath = olWriteConfigFile(['anthropicApiKey' => 'sk-ant-literal']);

        $fromFile = new SettingsModel(['anthropicApiKey' => 'sk-ant-literal']);
        expect($fromFile->validate(['anthropicApiKey']))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Structured insight rendering
// ---------------------------------------------------------------------------
// The model marks figures in bold even inside the JSON strings it is asked
// for, so **text** is drawn as bold rather than shown with its asterisks. The
// text is escaped first, so bold is the only markup that gets through.

describe('structured insights', function() {
    it('draws **bold** as bold and escapes everything else', function() {
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = Craft::$app->getView()->renderTemplate('order-lifecycle/_includes/ai-insight-structured', [
            'structured' => [
                'priorityAction' => 'Check **3 failed** payments',
                'items' => [[
                    'type' => 'action',
                    'title' => 'Friction',
                    'description' => '**14 line item changes** <script>alert(1)</script>',
                    'bullets' => ['Took **54 minutes**'],
                ]],
            ],
        ]);

        expect($html)->toContain('<strong>3 failed</strong>')
            ->and($html)->toContain('<strong>14 line item changes</strong>')
            ->and($html)->toContain('<strong>54 minutes</strong>')
            ->and($html)->not->toContain('**')
            ->and($html)->not->toContain('<script>')
            ->and($html)->toContain('&lt;script&gt;');
    });
});
