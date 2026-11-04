<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\ProductVariation;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Batch::update() builds a raw UPDATE by wrapping each array KEY in backticks as a
 * SQL column identifier. The keys originate from user input on the
 * products/bulk-update endpoint, so a crafted key such as
 *   item_price` = (SELECT SLEEP(3)) AND `item_price
 * breaks out of the identifier context and injects a subquery (CWE-89).
 *
 * This proves the sink rejects a non-column identifier instead of executing it,
 * while leaving a legitimate batch update working.
 */
class BatchUpdateSqlInjectionTest extends WPTestCase
{
    public function test_crafted_variant_key_cannot_inject_sql(): void
    {
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $injectedKey = 'item_price` = (SELECT SLEEP(3)) AND `item_price';

        $start = microtime(true);
        $threw = false;
        try {
            ProductVariation::query()->batchUpdate([
                ['id' => $variant->id, $injectedKey => 'x'],
            ]);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        $elapsed = microtime(true) - $start;

        $this->assertTrue(
            $threw,
            'batchUpdate must reject a key that is not a plain column identifier'
        );
        $this->assertLessThan(
            2.0,
            $elapsed,
            'The injected SLEEP(3) must not execute — identifier was passed to SQL unescaped'
        );
    }

    public function test_crafted_id_value_cannot_inject_sql(): void
    {
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        // The WHERE/WHEN id values are also concatenated into the SQL. A crafted id
        // must not break out of its quoted context and run a subquery.
        $start = microtime(true);
        try {
            ProductVariation::query()->batchUpdate([
                ['id' => "' OR (SELECT SLEEP(3)) OR '", 'item_price' => 100],
            ]);
        } catch (\Exception $e) {
            // An exception is an acceptable fail-closed outcome.
        }
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            2.0,
            $elapsed,
            'The injected SLEEP(3) in the id value must not execute'
        );
    }

    public function test_json_array_value_cannot_inject_sql(): void
    {
        // The field name (other_info) is a legitimate column, so the identifier
        // guard allows it. Its VALUE is an array, serialized to JSON and dropped
        // into the SQL. json_encode() does not escape single quotes, so a crafted
        // value must not break out of the string literal and execute a subquery.
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $payload = ['a' => "' OR (SELECT SLEEP(3)) OR '"];

        $start = microtime(true);
        ProductVariation::query()->batchUpdate([
            ['id' => $variant->id, 'other_info' => $payload],
        ]);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            2.0,
            $elapsed,
            'The injected SLEEP(3) in a JSON array value must not execute'
        );

        // The payload must be stored verbatim as data, not interpreted as SQL.
        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(
            $payload,
            (array) $fresh->other_info,
            'The crafted value must round-trip as stored data'
        );
    }

    public function test_scalar_json_string_value_cannot_inject_sql(): void
    {
        // A scalar string value that happens to be valid JSON (e.g. the literal
        // text "' OR (SELECT SLEEP(3)) OR '") was decoded by the JSON-aware escape
        // helper and returned unescaped, breaking out of the SQL string literal.
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        // The PHP string whose content is: "' OR (SELECT SLEEP(3)) OR '"
        $payload = '"' . "' OR (SELECT SLEEP(3)) OR '" . '"';

        $start = microtime(true);
        ProductVariation::query()->batchUpdate([
            ['id' => $variant->id, 'variation_title' => $payload],
        ]);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            2.0,
            $elapsed,
            'A JSON-valid scalar string value must not execute injected SQL'
        );

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(
            $payload,
            $fresh->variation_title,
            'The crafted scalar value must round-trip verbatim as data'
        );
    }

    public function test_json_encoded_scalar_string_round_trips_and_isolates_rows(): void
    {
        // Reviewer-requested: json_encode("O'Reilly") as variation_title must be
        // stored exactly and must not affect any other row.
        $product = (new ProductFactory())->create();
        $target = $product->variants->first();

        $other = ProductVariation::query()->create([
            'post_id'    => $product->ID,
            'variation_title' => 'untouched',
            'item_price' => 555,
            'item_status' => 'active',
            'payment_type' => 'onetime',
        ]);

        $payload = json_encode("O'Reilly"); // the literal string: "O'Reilly"

        ProductVariation::query()->batchUpdate([
            ['id' => $target->id, 'variation_title' => $payload],
        ]);

        $freshTarget = ProductVariation::query()->find($target->id);
        $freshOther  = ProductVariation::query()->find($other->id);

        $this->assertSame($payload, $freshTarget->variation_title, 'Targeted title must store exactly');
        $this->assertSame('untouched', $freshOther->variation_title, 'Other row title must be unchanged');
        $this->assertSame(555, (int) $freshOther->item_price, 'Other row price must be unchanged');
    }

    public function test_json_special_characters_round_trip(): void
    {
        // Ordinary text with quotes and backslashes must survive a batch update
        // intact — the escaping must not corrupt legitimate data.
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $payload = ['note' => "O'Reilly \\ \"Media\" it's fine"];

        ProductVariation::query()->batchUpdate([
            ['id' => $variant->id, 'other_info' => $payload],
        ]);

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(
            $payload,
            (array) $fresh->other_info,
            'Quotes and backslashes must round-trip unchanged'
        );
    }

    public function test_only_the_targeted_row_changes(): void
    {
        // A batch update must touch only the row whose id matches — an injection
        // or a broken WHERE could update or leak other rows.
        $product = (new ProductFactory())->create();
        $target = $product->variants->first();

        $other = ProductVariation::query()->create([
            'post_id'    => $product->ID,
            'variation_title' => 'untouched',
            'item_price' => 777,
            'item_status' => 'active',
            'payment_type' => 'onetime',
        ]);

        ProductVariation::query()->batchUpdate([
            ['id' => $target->id, 'item_price' => 4242],
        ]);

        $freshTarget = ProductVariation::query()->find($target->id);
        $freshOther  = ProductVariation::query()->find($other->id);

        $this->assertSame(4242, (int) $freshTarget->item_price, 'Targeted row must update');
        $this->assertSame(777, (int) $freshOther->item_price, 'Non-targeted row must stay unchanged');
    }

    public function test_legitimate_batch_update_still_works(): void
    {
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        ProductVariation::query()->batchUpdate([
            ['id' => $variant->id, 'item_price' => 4242],
        ]);

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(4242, (int) $fresh->item_price, 'A valid column update must persist');
    }

    public function test_datetime_object_value_is_stored_not_blanked(): void
    {
        // Callers pass DateTime objects (e.g. updated_at, refunded_at). prepare()
        // would turn an object into '' — the value must be formatted first so the
        // timestamp is written, matching the previous behaviour.
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $dt = \FluentCart\Framework\Support\DateTime::now();
        $expected = $dt->format((new ProductVariation())->getDateFormat());

        ProductVariation::query()->batchUpdate([
            ['id' => $variant->id, 'updated_at' => $dt],
        ]);

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(
            $expected,
            (string) $fresh->updated_at,
            'A DateTime value must be stored as a formatted timestamp, not blanked'
        );
    }

    public function test_boolean_value_is_stored_as_integer(): void
    {
        // Boolean flags must persist as 0/1, not '' (prepare('%s', false) === '').
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        ProductVariation::query()->batchUpdate([
            ['id' => $variant->id, 'manage_stock' => true],
        ]);
        $this->assertSame(1, (int) ProductVariation::query()->find($variant->id)->manage_stock, 'true must store as 1');

        ProductVariation::query()->batchUpdate([
            ['id' => $variant->id, 'manage_stock' => false],
        ]);
        $this->assertSame(0, (int) ProductVariation::query()->find($variant->id)->manage_stock, 'false must store as 0');
    }

    public function test_increment_decrement_arithmetic_still_works(): void
    {
        // The [operator, amount] arithmetic form must keep working.
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();
        ProductVariation::query()->where('id', $variant->id)->update(['total_stock' => 10]);

        ProductVariation::query()->batchUpdate([
            ['id' => $variant->id, 'total_stock' => ['+', 5]],
        ]);
        $this->assertSame(15, (int) ProductVariation::query()->find($variant->id)->total_stock, 'increment must add');

        ProductVariation::query()->batchUpdate([
            ['id' => $variant->id, 'total_stock' => ['-', 3]],
        ]);
        $this->assertSame(12, (int) ProductVariation::query()->find($variant->id)->total_stock, 'decrement must subtract');
    }
}
