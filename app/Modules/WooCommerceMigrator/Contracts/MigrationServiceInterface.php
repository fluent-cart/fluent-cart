<?php

namespace FluentCart\App\Modules\WooCommerceMigrator\Contracts;

interface MigrationServiceInterface
{
    /**
     * Check if the migration dependencies are met
     *
     * @return bool
     */
    public function checkDependencies(): bool;

    /**
     * Run the migration
     *
     * @param array $options Migration options
     * @return array Migration results with counts and status
     */
    public function migrate(array $options = []): array;

    /**
     * Get migration statistics/summary
     *
     * @return array
     */
    public function getStats(): array;

    /**
     * Clean up migration data (for fresh migrations)
     *
     * @return bool
     */
    public function cleanup(): bool;
} 