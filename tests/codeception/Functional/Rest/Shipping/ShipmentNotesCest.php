<?php

namespace Tests\Functional\Rest\Shipping;

use Codeception\Example;
use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Shipment;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsShipmentOrders;

/**
 * POST /orders/{id}/shipments stores the note as plain text: tags go, a lone
 * "<" stays, and every output escapes it.
 */
class ShipmentNotesCest
{
    use BuildsShipmentOrders;

    private function createWithNote(FunctionalTester $I, string $note): Shipment
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments', [
            'carrier_slug'    => Shipment::CARRIER_MANUAL,
            'carrier_name'    => 'Local Courier',
            'notify_customer' => false,
            'notes'           => $note,
            'items'           => [['order_item_id' => $item->id, 'qty' => 1]],
        ]);

        Assert::assertSame(200, $status, 'the note must be accepted. Body: ' . wp_json_encode($body));

        $shipment = Shipment::query()->where('order_id', $order->id)->first();
        Assert::assertNotNull($shipment, 'the shipment must be created');

        return $shipment;
    }

    protected function noteCases(): array
    {
        return [
            ['note' => 'qty<5 keep dry', 'stored' => 'qty<5 keep dry'],
            // Shaped like a tag, so it is removed: the accepted trade-off.
            ['note' => 'x<y and y>z', 'stored' => 'xz'],
            ['note' => 'qty < 5', 'stored' => 'qty < 5'],
            ['note' => '<b>fragile</b> note', 'stored' => 'fragile note'],
            ['note' => '<script>alert(1)</script>note', 'stored' => 'note'],
            ['note' => "line one\nqty<5\nline three", 'stored' => "line one\nqty<5\nline three"],
        ];
    }

    /**
     * @dataProvider noteCases
     */
    public function noteIsStoredAsPlainText(FunctionalTester $I, Example $example): void
    {
        $shipment = $this->createWithNote($I, $example['note']);

        Assert::assertSame($example['stored'], $shipment->notes, 'stored note for input: ' . $example['note']);
    }

    public function emailEscapesTheNoteOnce(FunctionalTester $I): void
    {
        $shipment = $this->createWithNote($I, 'qty < 5');
        $order    = Order::query()->with('customer')->find($shipment->order_id);

        $html = (string) App::make('view')->make('emails.order.shipment.customer', ['order' => $order, 'shipment' => $shipment]);

        Assert::assertStringContainsString('qty &lt; 5', $html, 'the email must escape "<" once');
        Assert::assertStringNotContainsString('&amp;lt;', $html, 'the email must not double-escape the note');
    }
}
