<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;

/**
 * Adds a `storeId` column to `{{%orderlifecycle_logs}}`.
 *
 * The table previously had no store association of its own - only `orderId` -
 * so scoping any stats query to a single store meant an `IN (SELECT id FROM
 * commerce_orders WHERE storeId = ...)` subquery against every log row. On a
 * multi-store install with a large log table that subquery gets expensive, and
 * it silently loses the association if the underlying order is ever hard-deleted.
 * A direct, indexed column fixes both.
 *
 * @author John Henry Donovan
 * @since 1.0.1
 */
class m260706_141921_AddStoreIdToLogs extends Migration
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration applied successfully.
     * @author John Henry Donovan
     * @since 1.0.1
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%orderlifecycle_logs}}', 'storeId')) {
            $this->addColumn(
                '{{%orderlifecycle_logs}}',
                'storeId',
                $this->integer()->after('orderId')
            );

            $this->_backfillStoreIds();

            $this->createIndex(null, '{{%orderlifecycle_logs}}', ['storeId']);
            $this->addForeignKey(
                null,
                '{{%orderlifecycle_logs}}', 'storeId',
                '{{%commerce_stores}}', 'id',
                'SET NULL', 'CASCADE'
            );

            Craft::$app->getDb()->getSchema()->refresh();
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration reverted successfully.
     * @author John Henry Donovan
     * @since 1.0.1
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%orderlifecycle_logs}}', 'storeId')) {
            $this->dropColumn('{{%orderlifecycle_logs}}', 'storeId');
        }

        return true;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Backfills `storeId` on existing log rows from their order's current store.
     *
     * Batched per store rather than a single cross-table JOIN update, to stay
     * portable across this plugin's supported MySQL and PostgreSQL targets.
     * Rows whose order no longer exists are left null - {@see
     * \johnhenry\orderlifecycle\services\OrderLifecycleLogger::deleteOrphanedLogs()}
     * clears those out regardless on its next run.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.1
     */
    private function _backfillStoreIds(): void
    {
        $storeIds = (new Query())
            ->select(['storeId'])
            ->from('{{%commerce_orders}}')
            ->distinct()
            ->column($this->db);

        foreach ($storeIds as $storeId) {
            $orderIdsForStore = (new Query())
                ->select(['id'])
                ->from('{{%commerce_orders}}')
                ->where(['storeId' => $storeId]);

            $this->update(
                '{{%orderlifecycle_logs}}',
                ['storeId' => $storeId],
                ['in', 'orderId', $orderIdsForStore],
                updateTimestamp: false
            );
        }
    }
}
