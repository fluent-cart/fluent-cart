<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\User;
use FluentCart\App\Services\ShortCodeParser\Parsers\OrderParser;
use FluentCart\App\Services\ShortCodeParser\Parsers\TransactionParser;
use FluentCart\App\Services\ShortCodeParser\Parsers\WPParser;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class SmartcodeDataAccessTest extends WPTestCase
{
    public function test_unknown_order_path_does_not_load_relations(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id])->fresh();
        $order->unsetRelation('customer');
        $parser = new OrderParser(['order' => $order]);

        $this->assertNull($parser->parse('customer.user_id', 'order.customer.user_id'), 'Unlisted customer fields must not resolve.');
        $this->assertFalse($order->relationLoaded('customer'), 'Rejected paths must not lazy-load relations.');
    }

    public function test_unknown_paths_cannot_read_preloaded_credentials_or_arbitrary_arrays(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id])->fresh();
        $order->setRelation('customer', $customer);
        $customer->setRelation('wpUser', User::query()->find($customer->user_id));
        $parser = new WPParser(['order' => $order, 'private' => ['token' => 'secret-token']]);

        $this->assertNull($parser->parse('order.customer.wpUser.user_pass', 'wp.order.customer.wpUser.user_pass'), 'Preloading must not bypass the allowlist.');
        $this->assertNull($parser->parse('private.token', 'wp.private.token'), 'Unknown array paths must not resolve.');
        $this->assertNull($parser->parse('unknown', 'private.token'), 'The template fallback must not bypass the allowlist.');
    }

    public function test_supported_order_fields_and_customer_email_still_render(): void
    {
        $customer = (new CustomerFactory())->create(['first_name' => 'Alice', 'last_name' => 'Buyer']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'note' => 'Delivery note'])->fresh();
        $data = ['order' => $order];

        $this->assertSame($customer->email, ShortcodeTemplateBuilder::make('{{order.customer.email}}', $data), 'Customer notification recipients must still resolve.');
        $this->assertSame('Alice Buyer', ShortcodeTemplateBuilder::make('{{order.customer.full_name}}', $data), 'Customer names must still render.');
        $this->assertSame((string) $order->currency, ShortcodeTemplateBuilder::make('{{order.currency}}', $data), 'Order currency must still render.');
        $this->assertSame('Delivery note', ShortcodeTemplateBuilder::make('{{order.note}}', $data), 'Order notes must still render.');
        $this->assertSame('', ShortcodeTemplateBuilder::make('{{order.refunded_at}}', $data), 'Nullable order fields must render empty.');
        $this->assertSame('Delivery note', ShortcodeTemplateBuilder::make('{{order.note}}', ['order' => $order->toArray()]), 'Array-backed contexts must still work.');
    }

    public function test_transaction_fields_resolve_but_nested_private_data_does_not(): void
    {
        $parser = new TransactionParser(['transaction' => [
            'status' => 'succeeded', 'currency' => 'USD', 'card_last_4' => '4242',
            'meta' => ['secret' => 'private-value'],
        ]]);

        $this->assertSame('succeeded', $parser->parse('status', 'transaction.status'), 'Transaction status must still resolve.');
        $this->assertSame('4242', $parser->parse('card_last_4', 'transaction.card_last_4'), 'Public card details must still resolve.');
        $this->assertNull($parser->parse('meta.secret', 'transaction.meta.secret'), 'Transaction metadata must not be exposed by fallback.');
    }

    public function test_wp_user_relation_does_not_load_credentials(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $user = get_user_by('ID', $customer->user_id);
        $this->assertNotEmpty($user->user_pass, 'The fixture must have a password hash.');

        foreach ([$customer->fresh(), Customer::query()->with('wpUser')->find($customer->id)] as $loaded) {
            $attributes = $loaded->wpUser->getAttributes();
            $this->assertArrayNotHasKey('user_pass', $attributes, 'Lazy and eager loading must exclude password hashes from memory.');
            $this->assertArrayNotHasKey('user_activation_key', $attributes, 'Lazy and eager loading must exclude activation tokens from memory.');
            $this->assertSame((int) $customer->user_id, (int) $loaded->wpUser->ID, 'The relation must still resolve the linked user.');
            $this->assertSame($user->user_email, $loaded->wpUser->user_email, 'Public user fields must remain available.');
        }
    }
}
