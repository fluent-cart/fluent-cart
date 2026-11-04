<?php

namespace Tests\Functional\Rest\Shipping;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\Shipment;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * GET /orders/{id}/shipments/{sid}/label asks the carrier for a current label
 * link and streams the PDF; nothing is stored. The list only says has_label.
 */
class LabelDownloadCest
{
    private const PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    // An IP literal: wp_safe_remote_get() rejects host names that do not resolve.
    private const LABEL_URL = 'https://93.184.216.34/label.pdf';

    /** @var array|\WP_Error what the carrier's label host answers */
    private $download;

    private $mock;

    public function _before(FunctionalTester $I): void
    {
        StubShippingCarrier::install();
        StubShippingCarrier::$labelUrl = self::LABEL_URL;
        $this->download = ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => self::PDF];
        $this->mock = function ($pre, $args, $url) {
            return $url === self::LABEL_URL ? $this->download : $pre;
        };
        add_filter('pre_http_request', $this->mock, 10, 3);
    }

    public function _after(FunctionalTester $I): void
    {
        remove_filter('pre_http_request', $this->mock, 10);
        StubShippingCarrier::uninstall();
    }

    private function order(): Order
    {
        $customer = (new CustomerFactory())->create();

        return (new OrderFactory())->create(['customer_id' => $customer->id]);
    }

    private function seedShipment(int $orderId, ?array $meta, string $carrier = StubShippingCarrier::SLUG): Shipment
    {
        $shipment = new Shipment([
            'carrier_slug'    => $carrier,
            'carrier_name'    => 'Label Carrier',
            'tracking_number' => 'TRK-LBL',
            'items'           => [],
        ]);
        $shipment->order_id = $orderId;
        $shipment->status   = Shipment::STATUS_DISPATCHED;
        $shipment->meta     = $meta;
        $shipment->save();

        return $shipment;
    }

    private function route(int $orderId, int $shipmentId): string
    {
        return '/fluent-cart/v2/orders/' . $orderId . '/shipments/' . $shipmentId . '/label';
    }

    public function carrierLabelIsFetchedAndServedAsAPdfAttachment(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order    = $this->order();
        $shipment = $this->seedShipment($order->id, ['transaction_id' => 'txn_1']);

        [$status, , $response] = $I->get($this->route($order->id, $shipment->id));

        Assert::assertSame(200, $status, 'the carrier label must download');
        $headers = $response->get_headers();
        Assert::assertSame('application/pdf', $headers['Content-Type'] ?? '', 'served as a PDF');
        Assert::assertSame('attachment; filename="label-' . $shipment->id . '.pdf"', $headers['Content-Disposition'] ?? '', 'served as a download named after the shipment');
        Assert::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? '', 'the browser must not sniff the type');
        Assert::assertSame(self::PDF, $response->get_data(), 'the body is the carrier PDF');
        Assert::assertSame([(int) $shipment->id], StubShippingCarrier::$labelUrlCalls, 'the carrier is asked for a link once');
        Assert::assertArrayNotHasKey('label_pdf_base64', (array) Shipment::find($shipment->id)->meta, 'the PDF is not stored');
    }

    public function theRestServerWritesTheRawBytes(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order    = $this->order();
        $shipment = $this->seedShipment($order->id, ['transaction_id' => 'txn_1']);

        [, , $response] = $I->get($this->route($order->id, $shipment->id));

        ob_start();
        $served = apply_filters('rest_pre_serve_request', false, $response, new \WP_REST_Request('GET', $this->route($order->id, $shipment->id)), rest_get_server());
        $output = ob_get_clean();

        Assert::assertTrue($served, 'the label response must bypass JSON encoding');
        Assert::assertSame(self::PDF, $output, 'the raw PDF bytes are written');

        ob_start();
        $other = apply_filters('rest_pre_serve_request', false, new \WP_REST_Response(['ok' => true]), new \WP_REST_Request('GET', '/x'), rest_get_server());
        $otherOutput = ob_get_clean();

        Assert::assertFalse($other, 'other responses stay JSON');
        Assert::assertSame('', $otherOutput, 'nothing is written for other responses');
    }

    public function listingSaysWhichShipmentsHaveACarrierLabel(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order   = $this->order();
        $bought  = $this->seedShipment($order->id, ['transaction_id' => 'txn_1']);
        $without = $this->seedShipment($order->id, ['label_url' => 'https://carrier.test/label.pdf'], 'manual');

        [$status, $body] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments');
        Assert::assertSame(200, $status);

        $rows = [];
        foreach ($body['shipments'] as $row) {
            $rows[(int) $row['id']] = $row;
        }

        Assert::assertTrue($rows[(int) $bought->id]['has_label'], 'a carrier label is flagged');
        Assert::assertFalse($rows[(int) $without->id]['has_label'], 'a carrier without labels has nothing to stream');
        Assert::assertSame('https://carrier.test/label.pdf', $rows[(int) $without->id]['meta']['label_url'] ?? '', 'the carrier URL is still listed');
        Assert::assertSame([], StubShippingCarrier::$labelUrlCalls, 'listing makes no carrier call');
        Assert::assertStringNotContainsString('%PDF', (string) wp_json_encode($body), 'no label bytes in the list');
    }

    public function aLabelTheCarrierCannotProvideIs404(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order  = $this->order();
        $manual = $this->seedShipment($order->id, ['label_url' => 'https://carrier.test/label.pdf'], 'manual');
        $noMeta = $this->seedShipment($order->id, null);

        [$status] = $I->get($this->route($order->id, $manual->id));
        Assert::assertSame(404, $status, 'a carrier without labels is a 404');

        StubShippingCarrier::$labelUrl = '';
        [$status] = $I->get($this->route($order->id, $noMeta->id));
        Assert::assertSame(404, $status, 'a carrier with no label for the shipment is a 404');
    }

    public function aCarrierOrDownloadFailureIs502(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order    = $this->order();
        $shipment = $this->seedShipment($order->id, ['transaction_id' => 'txn_1']);

        StubShippingCarrier::$labelUrl = new \WP_Error('carrier_down', 'The carrier did not answer.');
        [$status, $body] = $I->get($this->route($order->id, $shipment->id));
        Assert::assertSame(502, $status, 'a carrier error is a bad gateway');
        Assert::assertSame('The carrier did not answer.', $body['message'] ?? '', 'the carrier message is shown');

        StubShippingCarrier::$labelUrl = self::LABEL_URL;
        $this->download = ['response' => ['code' => 500, 'message' => 'Error'], 'headers' => [], 'body' => self::PDF];
        [$status] = $I->get($this->route($order->id, $shipment->id));
        Assert::assertSame(502, $status, 'a failed download is a bad gateway');

        $this->download = ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => '<html>expired</html>'];
        [$status, $body] = $I->get($this->route($order->id, $shipment->id));
        Assert::assertSame(502, $status, 'a page that is not a PDF is not served');
        Assert::assertStringNotContainsString('expired', (string) wp_json_encode($body), 'the carrier page is not passed on');
    }

    public function shipmentFromAnotherOrderIs404(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order   = $this->order();
        $foreign = $this->order();
        $shipment = $this->seedShipment($foreign->id, ['transaction_id' => 'txn_1']);

        [$status, $body] = $I->get($this->route($order->id, $shipment->id));

        Assert::assertSame(404, $status, 'a shipment must be addressed through its own order');
        Assert::assertStringNotContainsString('%PDF', (string) wp_json_encode($body), 'no label bytes leak');
        Assert::assertSame([], StubShippingCarrier::$labelUrlCalls, 'the carrier is not asked');

        [$missing] = $I->get($this->route(999999, $shipment->id));
        Assert::assertSame(404, $missing, 'an unknown order is a 404');
    }

    public function labelNeedsOrdersView(FunctionalTester $I): void
    {
        $order    = $this->order();
        $shipment = $this->seedShipment($order->id, ['transaction_id' => 'txn_1']);

        $I->logout();
        [$guest] = $I->get($this->route($order->id, $shipment->id));
        Assert::assertSame(401, $guest, 'a guest is unauthenticated');

        $subscriber = wp_insert_user([
            'user_login' => 'label_subscriber_' . wp_generate_password(6, false),
            'user_pass'  => wp_generate_password(),
            'user_email' => 'label-sub-' . wp_generate_password(6, false) . '@example.test',
            'role'       => 'subscriber',
        ]);
        wp_set_current_user($subscriber);
        [$plain] = $I->get($this->route($order->id, $shipment->id));
        Assert::assertSame(403, $plain, 'a user without orders/view is forbidden');

        $I->loginAsAccountant();
        [$viewer] = $I->get($this->route($order->id, $shipment->id));
        Assert::assertSame(200, $viewer, 'orders/view is enough to download a label');
    }
}
