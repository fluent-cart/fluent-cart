<?php

namespace FluentCart\Database\Migrations;

use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Services\DateTime\DateTime;

class OrderMetaMigrator extends Migrator
{
    protected static int $chunkSize = 500;

    public static string $tableName = 'fct_order_meta';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_om_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `order_id` BIGINT(20) NULL,
                `meta_key` VARCHAR(192) NOT NULL,
                `meta_value` LONGTEXT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_ord_id_idx` (`order_id` ASC),
                INDEX `{$indexPrefix}_ord_meta_key_idx` (`order_id` ASC, `meta_key` ASC)";
    }

    public static function migrated()
    {
        static::renameKeyToMetaKey();
        static::renameValueToMetaValue();
        static::addMetaKeyIndexes();
        static::migrateVatTaxIdToBusinessInfo();
        static::migrateVatReverseToBusinessInfo();
    }

    public static function renameKeyToMetaKey()
    {
        // "ALTER TABLE %i CHANGE `key` `meta_key` VARCHAR(192)"
        static::renameColumnIfExists('key', 'meta_key', 'VARCHAR(192)');
    }

    public static function renameValueToMetaValue()
    {
        // "ALTER TABLE %i CHANGE `value` `meta_value` LONGTEXT"
        static::renameColumnIfExists('value', 'meta_value', 'LONGTEXT');
    }

    public static function addMetaKeyIndexes()
    {
        $indexPrefix = static::getDbPrefix() . 'fct_om_';

        static::addIndexIfNotExists("{$indexPrefix}_ord_meta_key_idx", ['order_id', 'meta_key']);
    }

    /**
     * Convert legacy `vat_tax_id` rows to the unified `business_info` structure.
     *
     * Old path: TaxModule::storeVatNumberOnOrder() wrote one string row per order:
     *   meta_key = 'vat_tax_id', meta_value = '<vat-number-string>'
     *
     * New path: TaxModule::storeBusinessInfoOnOrder() writes:
     *   meta_key = 'business_info', meta_value = JSON { company_name, legal_registration_id, tax_number, ... }
     *
     * This migration is idempotent: if a `business_info` row already exists for an order
     * it merges the old VAT number in only when `tax_number` is absent.
     */
    public static function migrateVatTaxIdToBusinessInfo()
    {
        global $wpdb;
        $table = $wpdb->prefix . static::$tableName;
        $lastId = 0;

        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT `id`, `order_id`, `meta_value`
                FROM `{$table}`
                WHERE `meta_key` = 'vat_tax_id' AND `id` > %d
                ORDER BY `id` ASC
                LIMIT %d",
                $lastId,
                static::$chunkSize
            ));

            if (empty($rows)) {
                break;
            }

            $payloadByOrderId = [];
            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $vatNumber = $row->meta_value;

                // meta_value may be JSON-encoded by the model setter
                if (is_string($vatNumber)) {
                    $decoded = json_decode($vatNumber, true);
                    if (is_string($decoded)) {
                        $vatNumber = $decoded;
                    }
                }

                $vatNumber = sanitize_text_field((string) $vatNumber);

                if (empty($vatNumber)) {
                    continue;
                }

                if (!isset($payloadByOrderId[$row->order_id])) {
                    $payloadByOrderId[$row->order_id] = [
                        'tax_number'           => $vatNumber,
                        'tax_number_validated' => false,
                        'tax_number_country'   => '',
                    ];
                }
            }

            if (!empty($payloadByOrderId)) {
                static::syncBusinessInfoChunk(array_keys($payloadByOrderId), $payloadByOrderId);
            }
        } while (count($rows) === static::$chunkSize);
    }

    /**
     * Migrate EU-scoped VAT data from fct_order_tax_rate.meta.vat_reverse
     * into the unified fct_order_meta.business_info row.
     *
     * Old path: TaxModule::prepareOtherData() wrote vat_reverse into the tax rate
     * meta only when tax_data.valid === true (EU VIES-validated VAT). This meant
     * validated EU VAT numbers were only readable from the tax rate table, not from
     * a common order-level location.
     *
     * New path: business_info in fct_order_meta holds all tax identity fields for
     * every country, with tax_number_validated=true marking a VIES-verified number.
     *
     * Idempotent: skips an order if business_info.tax_number is already populated.
     */
    public static function migrateVatReverseToBusinessInfo()
    {
        global $wpdb;
        $taxRateTable = $wpdb->prefix . 'fct_order_tax_rate';
        $processed = [];
        $lastId = 0;

        do {
            // LIKE filter is safe here — vat_reverse only ever appears as a JSON key written
            // by TaxModule::prepareOtherData(), never as a value, so no false positives.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT `id`, `order_id`, `meta`
                FROM `{$taxRateTable}`
                WHERE `meta` LIKE '%%\"vat_reverse\"%%' AND `id` > %d
                ORDER BY `id` ASC
                LIMIT %d",
                $lastId,
                static::$chunkSize
            ));

            if (empty($rows)) {
                break;
            }

            $payloadByOrderId = [];
            foreach ($rows as $row) {
                $lastId = (int) $row->id;

                // One business_info row per order — process only the first tax rate row that carries vat_reverse
                if (isset($processed[$row->order_id])) {
                    continue;
                }
                $processed[$row->order_id] = true;

                $meta = json_decode($row->meta, true);
                if (empty($meta['vat_reverse']) || !is_array($meta['vat_reverse'])) {
                    continue;
                }

                $vatReverse = $meta['vat_reverse'];
                $vatNumber  = sanitize_text_field((string) (isset($vatReverse['vat_number']) ? $vatReverse['vat_number'] : ''));

                if (empty($vatNumber)) {
                    continue;
                }

                $vatCountry = sanitize_text_field((string) (isset($vatReverse['country']) ? $vatReverse['country'] : ''));
                $vatName    = sanitize_text_field((string) (isset($vatReverse['name']) ? $vatReverse['name'] : ''));

                $payloadByOrderId[$row->order_id] = [
                    'tax_number'           => $vatNumber,
                    'tax_number_validated' => true,
                    'tax_number_country'   => $vatCountry,
                    'tax_number_name'      => $vatName,
                ];
            }

            if (!empty($payloadByOrderId)) {
                static::syncBusinessInfoChunk(array_keys($payloadByOrderId), $payloadByOrderId);
            }
        } while (count($rows) === static::$chunkSize);
    }

    protected static function syncBusinessInfoChunk(array $orderIds, array $payloadByOrderId)
    {
        if (empty($orderIds) || empty($payloadByOrderId)) {
            return;
        }

        $timestamp = DateTime::gmtNow()->format('Y-m-d H:i:s');
        $existingRows = OrderMeta::query()
            ->select(['id', 'order_id', 'meta_value'])
            ->where('meta_key', 'business_info')
            ->whereIn('order_id', $orderIds)
            ->get();

        $updates = [];
        $existingByOrderId = [];

        foreach ($existingRows as $existingRow) {
            $existingByOrderId[$existingRow->order_id] = $existingRow;
        }

        $inserts = [];
        foreach ($payloadByOrderId as $orderId => $payload) {
            if (isset($existingByOrderId[$orderId])) {
                $businessInfo = $existingByOrderId[$orderId]->meta_value;
                if (!is_array($businessInfo)) {
                    $businessInfo = [];
                }

                if (!empty($businessInfo['tax_number'])) {
                    continue;
                }

                $updates[] = [
                    'id'         => $existingByOrderId[$orderId]->id,
                    'meta_value' => wp_json_encode(array_merge($businessInfo, $payload)),
                    'updated_at' => $timestamp,
                ];
                continue;
            }

            $inserts[] = [
                'order_id'    => $orderId,
                'meta_key'    => 'business_info',
                'meta_value'  => wp_json_encode($payload),
                'created_at'  => $timestamp,
                'updated_at'  => $timestamp,
            ];
        }

        if (!empty($inserts)) {
            OrderMeta::query()->insert($inserts);
        }

        if (!empty($updates)) {
            OrderMeta::query()->batchUpdate($updates);
        }
    }
}
