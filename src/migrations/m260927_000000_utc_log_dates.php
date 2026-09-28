<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Converts stored log times to UTC.
 *
 * Log times were written in the timezone of the request that wrote them, while
 * everything that reads them treats them as UTC, like every other date Craft
 * stores. Most were written in the system timezone, so they are converted from
 * that. The offset is applied per daylight-saving period, so a summer entry
 * and a winter entry each move by the offset in force when they were written.
 * Entries written from the control panel by someone with their own timezone
 * preference can't be told apart and are converted like the rest.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class m260927_000000_utc_log_dates extends Migration
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int How many rows each UPDATE statement covers, by ID.
     */
    private const _BATCH = 20000;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     * @throws \yii\db\Exception If an update fails.
     * @throws \Exception If the system timezone is not a valid one.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function safeUp(): bool
    {
        $table = '{{%orderlifecycle_logs}}';

        if (!$this->db->tableExists($table)) {
            return true;
        }

        $zone = new DateTimeZone(self::systemTimeZone());

        $range = (new Query())
            ->select(['min' => 'MIN([[id]])', 'max' => 'MAX([[id]])', 'first' => 'MIN([[dateCreated]])', 'last' => 'MAX([[dateUpdated]])'])
            ->from($table)
            ->one($this->db);

        if (empty($range['min'])) {
            return true;
        }

        $periods = self::periods($zone, (string)$range['first'], (string)$range['last']);

        // A zone that sat on UTC throughout needs nothing moved
        if (array_filter($periods, static fn(array $period) => $period['offset'] !== 0) === []) {
            return true;
        }

        $set = [
            'dateCreated' => $this->_shiftExpression('dateCreated', $periods),
            'dateUpdated' => $this->_shiftExpression('dateUpdated', $periods),
        ];

        for ($from = (int)$range['min']; $from <= (int)$range['max']; $from += self::_BATCH) {
            $this->db->createCommand()
                ->update($table, $set, ['between', 'id', $from, $from + self::_BATCH - 1], [], false)
                ->execute();
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always false: there is no telling converted times apart.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function safeDown(): bool
    {
        echo "m260927_000000_utc_log_dates cannot be reverted.\n";

        return false;
    }

    /**
     * The system timezone: the general config's, or the one in project config.
     *
     * @return string A timezone identifier.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function systemTimeZone(): string
    {
        return Craft::$app->getConfig()->getGeneral()->timezone
            ?: (Craft::$app->getProjectConfig()->get('system.timeZone') ?? 'UTC');
    }

    /**
     * The spans of local time between the zone's offset changes that cover a
     * range, each with the offset in force.
     *
     * @param DateTimeZone $zone The zone the times were written in.
     * @param string $first The earliest stored time.
     * @param string $last The latest stored time.
     * @return array<int, array{from: string|null, to: string|null, offset: int}> Local-time bounds, open at either end.
     * @throws \Exception If a stored time can't be read.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function periods(DateTimeZone $zone, string $first, string $last): array
    {
        $start = (new DateTimeImmutable($first, $zone))->modify('-2 days')->getTimestamp();
        $end = (new DateTimeImmutable($last, $zone))->modify('+2 days')->getTimestamp();

        $transitions = $zone->getTransitions($start, $end) ?: [];
        $periods = [];

        foreach ($transitions as $i => $transition) {
            $next = $transitions[$i + 1] ?? null;
            $offset = (int)$transition['offset'];

            $periods[] = [
                // the first entry is the state at $start, not a change
                'from' => $i === 0 ? null : gmdate('Y-m-d H:i:s', $transition['ts'] + $offset),
                'to' => $next !== null ? gmdate('Y-m-d H:i:s', $next['ts'] + $offset) : null,
                'offset' => $offset,
            ];
        }

        return $periods;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * An expression moving a column from local time to UTC by the offset of
     * the period each value falls in.
     *
     * @param string $column The column.
     * @param array<int, array{from: string|null, to: string|null, offset: int}> $periods The periods.
     * @return \yii\db\Expression
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _shiftExpression(string $column, array $periods): \yii\db\Expression
    {
        $col = $this->db->quoteColumnName($column);
        $cases = [];

        foreach ($periods as $period) {
            $conditions = [];
            if ($period['from'] !== null) {
                $conditions[] = "$col >= " . $this->db->quoteValue($period['from']);
            }
            if ($period['to'] !== null) {
                $conditions[] = "$col < " . $this->db->quoteValue($period['to']);
            }

            $when = $conditions !== [] ? implode(' AND ', $conditions) : '1=1';
            $cases[] = "WHEN $when THEN " . $this->_minusSeconds($col, $period['offset']);
        }

        return new \yii\db\Expression("CASE " . implode(' ', $cases) . " ELSE $col END");
    }

    /**
     * An expression taking a number of seconds off a datetime column.
     *
     * @param string $col The quoted column.
     * @param int $seconds The seconds to take off; negative adds them.
     * @return string
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _minusSeconds(string $col, int $seconds): string
    {
        return $this->db->getIsPgsql()
            ? "($col - INTERVAL '$seconds seconds')"
            : "DATE_SUB($col, INTERVAL $seconds SECOND)";
    }
}
