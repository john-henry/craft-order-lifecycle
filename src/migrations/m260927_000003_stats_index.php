<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\migrations;

use craft\db\Migration;
use craft\helpers\Db;

/**
 * Adds an index on store, type and date for the statistics queries.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class m260927_000003_stats_index extends Migration
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     * @throws \yii\base\NotSupportedException If the database driver can't list indexes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function safeUp(): bool
    {
        $table = '{{%orderlifecycle_logs}}';
        $columns = ['storeId', 'type', 'dateCreated'];

        if (!$this->db->tableExists($table) || Db::findIndex($table, $columns, false, $this->db) !== null) {
            return true;
        }

        $this->createIndex(null, $table, $columns);

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function safeDown(): bool
    {
        $this->dropIndexIfExists('{{%orderlifecycle_logs}}', ['storeId', 'type', 'dateCreated']);

        return true;
    }
}
