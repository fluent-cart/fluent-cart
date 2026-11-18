<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\MCP\Tools\TransactionTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * list-transactions — the cross-record payment ledger.
 *
 * Seeds a mix of charges/refunds across currencies, statuses and modes, then
 * asserts the filters, the per-currency money split, mode scoping, customer
 * scoping via the parent order, and summary_only.
 */
class ListTransactionsTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    private int $orderId;
    private int $customerId;

    private const IN_WINDOW  = '2025-06-15 12:00:00';
    private const OUT_WINDOW = '2025-05-01 12:00:00';

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
    }

    private function seed(): void
    {
        $this->customerId = $this->customerFactory->create()->id;
        // No auto-transaction: this test owns the exact transaction set.
        $order = $this->orderFactory->create([
            'customer_id'    => $this->customerId,
            'type'           => Status::ORDER_TYPE_PAYMENT,
            'payment_status' => Status::PAYMENT_PAID,
            'currency'       => 'USD',
            'total_amount'   => Helper::toCent(100),
            'created_at'     => self::IN_WINDOW,
        ]);
        $this->orderId = $order->id;

        // type, status, currency, $, mode, created_at
        $this->txn('charge', Status::TRANSACTION_SUCCEEDED, 'USD', 100, Status::ORDER_MODE_LIVE, self::IN_WINDOW);
        $this->txn('refund', Status::TRANSACTION_SUCCEEDED, 'USD', 30, Status::ORDER_MODE_LIVE, self::IN_WINDOW);
        $this->txn('charge', Status::TRANSACTION_FAILED, 'USD', 50, Status::ORDER_MODE_LIVE, self::IN_WINDOW);
        $this->txn('charge', Status::TRANSACTION_SUCCEEDED, 'EUR', 80, Status::ORDER_MODE_LIVE, self::IN_WINDOW);
        $this->txn('charge', Status::TRANSACTION_SUCCEEDED, 'USD', 999, Status::ORDER_MODE_LIVE, self::OUT_WINDOW);
        $this->txn('refund', Status::TRANSACTION_SUCCEEDED, 'USD', 20, Status::ORDER_MODE_TEST, self::IN_WINDOW);
    }

    private function txn(string $type, string $status, string $currency, $dollars, string $mode, string $createdAt): void
    {
        OrderTransaction::query()->create([
            'order_id'         => $this->orderId,
            'order_type'       => Status::ORDER_TYPE_PAYMENT,
            'transaction_type' => $type,
            'status'           => $status,
            'currency'         => $currency,
            'total'            => Helper::toCent($dollars),
            'payment_method'   => 'stripe',
            'payment_mode'     => $mode,
            'created_at'       => $createdAt,
        ]);
    }

    private function params(array $extra): array
    {
        return array_merge(['date_from' => '2025-06-01', 'date_to' => '2025-06-30'], $extra);
    }

    private function currencyTotalCents(array $summary, string $code): int
    {
        foreach ($summary['amount_by_currency'] as $c) {
            if ($c['currency'] === $code) {
                return (int) $c['total']['amount_cents'];
            }
        }
        return -1;
    }

    private function currencyCount(array $summary, string $code): int
    {
        foreach ($summary['amount_by_currency'] as $c) {
            if ($c['currency'] === $code) {
                return (int) $c['count'];
            }
        }
        return -1;
    }

    public function test_window_excludes_out_of_window_and_default_mode_counts_all(): void
    {
        $this->seed();

        $res = TransactionTools::listTransactions($this->params([]));
        $summary = $res['data']['summary'];

        $this->assertSame('all', $res['meta']['mode']);
        // 5 in-window (the $999 charge is out of window); the test-mode refund is
        // included because mode defaults to all.
        $this->assertSame(5, $summary['matching_count']);
        $this->assertCount(5, $res['data']['transactions']);

        // Money split per currency, never summed across.
        $this->assertSame(Helper::toCent(100 + 30 + 50 + 20), $this->currencyTotalCents($summary, 'USD'));
        $this->assertSame(4, $this->currencyCount($summary, 'USD'));
        $this->assertSame(Helper::toCent(80), $this->currencyTotalCents($summary, 'EUR'));

        // Charges and refunds are split per type; the summary line never adds them
        $usd = $summary['amount_by_currency'][array_search('USD', array_column($summary['amount_by_currency'], 'currency'), true)];
        $this->assertEquals(['charge' => 150.0, 'refund' => 50.0], $usd['by_type']);
        $this->assertStringContainsString('refund', $res['summary']);
        $this->assertStringNotContainsString('totaling', $res['summary']);

        // Count breakdowns. by_type/by_status are stdClass on purpose: the MCP
        // schema declares them JSON objects and an empty array would encode as
        // [] — pin the type before reading the counts.
        $this->assertInstanceOf(\stdClass::class, $summary['by_type'], 'by_type must stay an object so empty maps encode as {}');
        $this->assertInstanceOf(\stdClass::class, $summary['by_status'], 'by_status must stay an object so empty maps encode as {}');
        $byType   = (array) $summary['by_type'];
        $byStatus = (array) $summary['by_status'];
        $this->assertSame(3, $byType['charge'], 'in-window charge count');
        $this->assertSame(2, $byType['refund'], 'in-window refund count');
        $this->assertSame(4, $byStatus['succeeded'], 'in-window succeeded count');
        $this->assertSame(1, $byStatus['failed'], 'in-window failed count');
    }

    public function test_type_refund_filter(): void
    {
        $this->seed();

        $res = TransactionTools::listTransactions($this->params(['type' => 'refund']));
        $summary = $res['data']['summary'];

        $this->assertSame(2, $summary['matching_count']);
        $this->assertSame(Helper::toCent(30 + 20), $this->currencyTotalCents($summary, 'USD'));
        foreach ($res['data']['transactions'] as $row) {
            $this->assertSame('refund', $row['type']);
        }
    }

    public function test_status_failed_for_dunning(): void
    {
        $this->seed();

        $res = TransactionTools::listTransactions($this->params(['status' => 'failed']));

        $this->assertSame(1, $res['data']['summary']['matching_count']);
        $this->assertSame('failed', $res['data']['transactions'][0]['status']);
    }

    public function test_mode_live_excludes_test_transactions(): void
    {
        $this->seed();

        $res = TransactionTools::listTransactions($this->params(['mode' => 'live']));

        $this->assertSame('live', $res['meta']['mode']);
        // The test-mode refund ($20) drops out.
        $this->assertSame(4, $res['data']['summary']['matching_count']);
        $this->assertSame(Helper::toCent(100 + 30 + 50), $this->currencyTotalCents($res['data']['summary'], 'USD'));
    }

    public function test_currency_filter(): void
    {
        $this->seed();

        $res = TransactionTools::listTransactions($this->params(['currency' => 'EUR']));

        $this->assertSame(1, $res['data']['summary']['matching_count']);
        $this->assertSame('EUR', $res['data']['transactions'][0]['currency']);
    }

    public function test_customer_id_scopes_via_parent_order(): void
    {
        $this->seed();

        $mine = TransactionTools::listTransactions($this->params(['customer_id' => $this->customerId]));
        $this->assertSame(5, $mine['data']['summary']['matching_count']);
        // Row carries the resolved customer.
        $this->assertSame($this->customerId, $mine['data']['transactions'][0]['customer']['id']);

        $other = TransactionTools::listTransactions($this->params(['customer_id' => $this->customerId + 99999]));
        $this->assertSame(0, $other['data']['summary']['matching_count']);
    }

    public function test_empty_result_keeps_object_shaped_breakdowns(): void
    {
        $this->seed();

        // No GBP transactions exist — the empty maps are exactly where the
        // object contract matters: a raw empty array would serialize as []
        // and break the MCP schema, which declares by_type/by_status objects.
        $res = TransactionTools::listTransactions($this->params(['currency' => 'GBP']));
        $summary = $res['data']['summary'];

        $this->assertSame(0, $summary['matching_count'], 'no GBP transactions were seeded');
        $this->assertInstanceOf(\stdClass::class, $summary['by_type'], 'empty by_type must stay an object');
        $this->assertInstanceOf(\stdClass::class, $summary['by_status'], 'empty by_status must stay an object');
        $this->assertSame('{}', json_encode($summary['by_type']), 'empty by_type must encode as {}, not []');
        $this->assertSame('{}', json_encode($summary['by_status']), 'empty by_status must encode as {}, not []');
    }

    public function test_summary_only_omits_rows(): void
    {
        $this->seed();

        $res = TransactionTools::listTransactions($this->params(['summary_only' => true]));

        $this->assertArrayHasKey('summary', $res['data']);
        $this->assertArrayNotHasKey('transactions', $res['data']);
        $this->assertSame(5, $res['data']['summary']['matching_count']);
    }
}
