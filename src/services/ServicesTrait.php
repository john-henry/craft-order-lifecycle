<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\services;

use yii\base\InvalidConfigException;

/**
 * Registers and exposes the plugin's service components.
 *
 * @property-read AiInsightsService $aiInsights
 * @property-read ExportService $export
 * @property-read OrderLifecycleLogger $logger
 * @property-read StatsService $stats
 * @author John Henry Donovan
 * @since 1.0.0
 */
trait ServicesTrait
{
    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The plugin configuration, including registered service components.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'aiInsights' => AiInsightsService::class,
                'export' => ExportService::class,
                'logger' => OrderLifecycleLogger::class,
                'stats' => StatsService::class,
            ],
        ];
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns the AI insights service.
     *
     * @return AiInsightsService The AI insights service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getAiInsights(): AiInsightsService
    {
        $component = $this->get('aiInsights');
        assert($component instanceof AiInsightsService);

        return $component;
    }

    /**
     * Returns the export service.
     *
     * @return ExportService The export service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getExport(): ExportService
    {
        $component = $this->get('export');
        assert($component instanceof ExportService);

        return $component;
    }

    /**
     * Returns the logger service.
     *
     * @return OrderLifecycleLogger The logger service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getLogger(): OrderLifecycleLogger
    {
        $component = $this->get('logger');
        assert($component instanceof OrderLifecycleLogger);

        return $component;
    }

    /**
     * Returns the stats service.
     *
     * @return StatsService The stats service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getStats(): StatsService
    {
        $component = $this->get('stats');
        assert($component instanceof StatsService);

        return $component;
    }
}
