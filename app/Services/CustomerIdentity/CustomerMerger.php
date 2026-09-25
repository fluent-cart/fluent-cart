<?php

namespace FluentCart\App\Services\CustomerIdentity;

use FluentCart\App\App;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\CustomerMeta;
use FluentCart\App\Models\LabelRelationship;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderDownloadPermission;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Database\Schema;
use FluentCart\Framework\Database\Orm\Builder;

/**
 * Moves an unlinked customer's resources after proof of inbox ownership.
 *
 * Keep the source as a valid parent for in-flight checkout and extension writes.
 * Without a writer protocol or foreign keys, an empty check cannot make deletion
 * safe. Late resources stay unlinked and can be recovered by another email claim.
 */
class CustomerMerger
{
    const BATCH_SIZE = 100;

    /** Fail closed: recovery relies on InnoDB rollback and row locks. */
    public static function supportsTransactions(): bool
    {
        global $wpdb;

        static $coreTables = null;
        if ($coreTables === null) {
            $coreTables = [];
            foreach ([Customer::class, Order::class, Subscription::class, OrderDownloadPermission::class, Cart::class, CustomerAddresses::class, CustomerMeta::class, LabelRelationship::class, Activity::class] as $model) {
                $coreTables[] = (new $model())->getTable();
            }
        }
        $required = [$wpdb->users, $wpdb->usermeta, $wpdb->options];
        foreach ($coreTables as $table) {
            $required[] = $wpdb->prefix . $table;
        }
        // Extensions must register the full table names written by their handoff.
        // This is additive: listeners cannot remove the core transaction requirements.
        $additional = apply_filters('fluent_cart/customer/recovery_transaction_tables', []);
        if (!is_array($additional)) {
            return false;
        }
        foreach ($additional as $table) {
            if (!is_string($table) || $table === '') {
                return false;
            }
            $required[] = $table;
        }
        $required = array_values(array_unique($required));
        $optional = $wpdb->prefix . 'fct_licenses';
        $tables = array_values(array_unique(array_merge($required, [$optional])));
        $placeholders = implode(', ', array_fill(0, count($tables), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)",
            $tables
        ));
        $engines = [];
        foreach ($rows ?: [] as $row) {
            $engines[$row->TABLE_NAME] = strtolower((string) $row->ENGINE);
        }
        foreach ($required as $table) {
            if (($engines[$table] ?? '') !== 'innodb') {
                return false;
            }
        }
        return !isset($engines[$optional]) || $engines[$optional] === 'innodb';
    }

    /**
     * @param Customer $source Unlinked record being absorbed.
     * @param Customer $target Account-linked record it is folded into.
     * @return bool True when all checked resources have moved; the source is retained.
     */
    public static function absorb(Customer $source, Customer $target, ?bool &$needsAnotherBatch = null): bool
    {
        $needsAnotherBatch = false;
        if ((int) $source->id === (int) $target->id || $source->user_id || !$target->user_id || !static::supportsTransactions()) {
            return false;
        }

        return Customer::query()->getConnection()->transaction(function () use ($source, $target, &$needsAnotherBatch) {
            $sourceId = (int) $source->id;
            $targetId = (int) $target->id;

            static::moveRows(Order::query()->where('customer_id', $sourceId), 'customer_id', $targetId);
            static::moveRows(Subscription::query()->where('customer_id', $sourceId), 'customer_id', $targetId);
            static::moveRows(OrderDownloadPermission::query()->where('customer_id', $sourceId), 'customer_id', $targetId);
            static::moveRows(Cart::query()->where('customer_id', $sourceId), 'customer_id', $targetId);

            static::moveAddresses($sourceId, $targetId);
            static::moveMeta($sourceId, $targetId);

            static::moveRows(LabelRelationship::query()->where('labelable_type', Customer::class)->where('labelable_id', $sourceId), 'labelable_id', $targetId);
            static::moveRows(Activity::query()->where('module_type', Customer::class)->where('module_id', $sourceId), 'module_id', $targetId);

            if (static::hasCoreResources($sourceId)) {
                $needsAnotherBatch = true;
                return false;
            }

            // Existing contract: Pro licensing moves its rows here.
            do_action('fluent_cart/customer_resources_moved', [
                'from_customer_id' => $sourceId,
                'to_customer_id'   => $targetId
            ]);

            // This is a completion check, not permission to delete the source.
            return static::isEmpty($sourceId);
        });
    }

    protected static function moveRows(Builder $query, string $column, int $targetId): void
    {
        $key = $query->getModel()->getKeyName();
        $ids = (clone $query)->orderBy($key)->limit(static::BATCH_SIZE)->toBase()->pluck($key)->toArray();
        if ($ids) {
            $query->whereIn($key, $ids)->update([$column => $targetId]);
        }
    }

    /** Target primaries win; otherwise keep the earliest source primary. */
    protected static function moveAddresses(int $sourceId, int $targetId): void
    {
        $rows = CustomerAddresses::query()->where('customer_id', $sourceId)->orderBy('id')
            ->limit(static::BATCH_SIZE)->toBase()->get(['id', 'type', 'is_primary']);
        $types = $rows->pluck('type')->unique()->toArray();
        $primaryTypes = CustomerAddresses::query()->where('customer_id', $targetId)
            ->whereIn('type', $types)->where('is_primary', 1)->groupBy('type')->toBase()->pluck('type')->toArray();
        $demote = [];
        foreach ($rows as $row) {
            if ($row->is_primary) {
                if (in_array($row->type, $primaryTypes, true)) {
                    $demote[] = $row->id;
                } else {
                    $primaryTypes[] = $row->type;
                }
            }
        }
        if ($demote) {
            CustomerAddresses::query()->where('customer_id', $sourceId)->whereIn('id', $demote)->update(['is_primary' => 0]);
        }
        if ($rows->isNotEmpty()) {
            CustomerAddresses::query()->where('customer_id', $sourceId)->whereIn('id', $rows->pluck('id')->toArray())
                ->update(['customer_id' => $targetId]);
        }
    }

    /** Target values win; batch duplicate detection and writes without loading values. */
    protected static function moveMeta(int $sourceId, int $targetId): void
    {
        $rows = CustomerMeta::query()->where('customer_id', $sourceId)->orderBy('id')
            ->limit(static::BATCH_SIZE)->toBase()->get(['id', 'meta_key']);
        $keys = CustomerMeta::query()->where('customer_id', $targetId)
            ->whereIn('meta_key', $rows->pluck('meta_key')->toArray())->groupBy('meta_key')->toBase()->pluck('meta_key')->toArray();
        $move = [];
        $discard = [];
        foreach ($rows as $row) {
            if (in_array($row->meta_key, $keys, true)) {
                $discard[] = $row->id;
            } else {
                $move[] = $row->id;
                $keys[] = $row->meta_key;
            }
        }
        if ($discard) {
            CustomerMeta::query()->where('customer_id', $sourceId)->whereIn('id', $discard)->delete();
        }
        if ($move) {
            CustomerMeta::query()->where('customer_id', $sourceId)->whereIn('id', $move)->update(['customer_id' => $targetId]);
        }
    }

    public static function hasCoreResources(int $customerId): bool
    {
        foreach ([Order::class, Subscription::class, OrderDownloadPermission::class, Cart::class, CustomerAddresses::class, CustomerMeta::class] as $model) {
            if ($model::query()->where('customer_id', $customerId)->exists()) {
                return true;
            }
        }
        return LabelRelationship::query()->where('labelable_type', Customer::class)->where('labelable_id', $customerId)->exists()
            || Activity::query()->where('module_type', Customer::class)->where('module_id', $customerId)->exists();
    }

    /** Empty retained source rows are not a reason to request inbox proof again. */
    public static function hasRecoverableCustomers(Builder $customers): bool
    {
        $resources = [];
        foreach ([Order::class, Subscription::class, OrderDownloadPermission::class, Cart::class, CustomerAddresses::class, CustomerMeta::class] as $model) {
            $resources[] = [(new $model())->getTable(), 'customer_id', null];
        }
        $resources[] = [(new LabelRelationship())->getTable(), 'labelable_id', 'labelable_type'];
        $resources[] = [(new Activity())->getTable(), 'module_id', 'module_type'];
        if (Schema::hasTable('fct_licenses')) {
            $resources[] = ['fct_licenses', 'customer_id', null];
        }
        $customerTable = $customers->getModel()->getTable();
        return (clone $customers)->where(function ($query) use ($resources, $customerTable) {
            foreach ($resources as [$table, $column, $type]) {
                $query->orWhereExists(function ($resource) use ($table, $column, $type, $customerTable) {
                    $resource->selectRaw('1')->from($table)->whereColumn($table . '.' . $column, $customerTable . '.id');
                    if ($type) {
                        $resource->where($table . '.' . $type, Customer::class);
                    }
                });
            }
        })->exists();
    }

    /** A small foreground recovery has a fixed source and resource budget. */
    public static function fitsForeground(array $sourceIds): bool
    {
        $remaining = static::BATCH_SIZE;
        foreach ([Order::class, Subscription::class, OrderDownloadPermission::class, Cart::class, CustomerAddresses::class, CustomerMeta::class, LabelRelationship::class, Activity::class] as $model) {
            $query = $model::query();
            if ($model === LabelRelationship::class) {
                $query->where('labelable_type', Customer::class)->whereIn('labelable_id', $sourceIds);
            } elseif ($model === Activity::class) {
                $query->where('module_type', Customer::class)->whereIn('module_id', $sourceIds);
            } else {
                $query->whereIn('customer_id', $sourceIds);
            }
            $remaining -= $query->limit($remaining + 1)->toBase()->pluck($query->getModel()->getKeyName())->count();
            if ($remaining < 0) {
                return false;
            }
        }
        return true;
    }

    /** Re-read to detect resources left behind by an extension. */
    protected static function isEmpty(int $customerId): bool
    {
        if (static::hasCoreResources($customerId)) {
            return false;
        }

        // Pro licenses when Pro is inactive: its listener could not move them.
        if (Schema::hasTable('fct_licenses') && App::db()->table('fct_licenses')->where('customer_id', $customerId)->exists()) {
            return false;
        }

        return true;
    }
}
