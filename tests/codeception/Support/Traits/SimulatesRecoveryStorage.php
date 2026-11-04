<?php

namespace Tests\Support\Traits;

/** Changes only the engine metadata read; never alters a WordPress table. */
trait SimulatesRecoveryStorage
{
    protected $storageQueryFilter;

    protected function reportRecoveryEngine(string $table, string $engine = 'MyISAM'): void
    {
        global $wpdb;
        $replacement = $wpdb->prepare('SELECT TABLE_NAME, CASE WHEN TABLE_NAME = %s THEN %s ELSE ENGINE END AS ENGINE FROM information_schema.TABLES', $table, $engine);
        $this->storageQueryFilter = function ($query) use ($replacement) {
            return str_replace('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES', $replacement, $query);
        };
        add_filter('query', $this->storageQueryFilter);
    }

    protected function restoreRecoveryEngine(): void
    {
        if ($this->storageQueryFilter) {
            remove_filter('query', $this->storageQueryFilter);
            $this->storageQueryFilter = null;
        }
    }
}
