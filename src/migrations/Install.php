<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\migrations;

use Craft;
use craft\db\Migration;

/**
 * Install migration.
 *
 * Creates the order lifecycle logs table, its indexes and foreign keys.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class Install extends Migration
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration applied successfully.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function safeUp(): bool
    {
        if ($this->createTables()) {
            $this->createIndexes();
            $this->addForeignKeys();

            Craft::$app->getDb()->getSchema()->refresh();
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration reverted successfully.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%orderlifecycle_logs}}');
        return true;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * Creates the plugin's database tables.
     *
     * @return bool Always true once the tables exist.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function createTables(): bool
    {
        if (!$this->db->tableExists('{{%orderlifecycle_logs}}')) {
            // microsecond precision so same-second events keep their insert order
            $microsecondDateTime = $this->db->getIsPgsql() ? 'timestamp(6)' : 'datetime(6)';

            $this->createTable('{{%orderlifecycle_logs}}', [
                'id' => $this->primaryKey(),
                'orderId' => $this->integer()->notNull(),
                'type' => $this->string(50)->notNull(),
                'message' => $this->text()->null(),
                'snapshot' => $this->mediumText()->null(), // JSON
                'userId' => $this->integer()->null(),
                'ip' => $this->string(45)->null(),   // IPv4/IPv6
                'dateCreated' => "$microsecondDateTime NOT NULL",
                'dateUpdated' => "$microsecondDateTime NOT NULL",
                'uid' => $this->uid(),
            ]);
        }
        return true;
    }

    /**
     * Creates the indexes for the plugin's tables.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function createIndexes(): void
    {
        $this->createIndex(null, '{{%orderlifecycle_logs}}', ['orderId', 'dateCreated']);
        $this->createIndex(null, '{{%orderlifecycle_logs}}', ['dateCreated']);
        $this->createIndex(null, '{{%orderlifecycle_logs}}', ['userId']);
        $this->createIndex(null, '{{%orderlifecycle_logs}}', ['type']);
    }

    /**
     * Adds the foreign keys for the plugin's tables.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function addForeignKeys(): void
    {
        $this->addForeignKey(
            null,
            '{{%orderlifecycle_logs}}', 'orderId',
            '{{%commerce_orders}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%orderlifecycle_logs}}', 'userId',
            '{{%users}}', 'id',
            'SET NULL', 'CASCADE'
        );
    }
}
