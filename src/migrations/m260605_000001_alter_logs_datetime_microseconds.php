<?php

namespace johnhenry\orderlifecycle\migrations;

use craft\db\Migration;

/**
 * Upgrade dateCreated and dateUpdated on orderlifecycle_logs to datetime(6)
 * so multiple events logged within the same second retain insertion order.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class m260605_000001_alter_logs_datetime_microseconds extends Migration
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function safeUp(): bool
    {
        $table = '{{%orderlifecycle_logs}}';

        $this->execute("ALTER TABLE $table
            MODIFY `dateCreated` datetime(6) NOT NULL,
            MODIFY `dateUpdated` datetime(6) NOT NULL");

        return true;
    }

    /**
     * @inheritdoc
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function safeDown(): bool
    {
        $table = '{{%orderlifecycle_logs}}';

        $this->execute("ALTER TABLE $table
            MODIFY `dateCreated` datetime NOT NULL,
            MODIFY `dateUpdated` datetime NOT NULL");

        return true;
    }
}
