<?php

namespace Tests\Integration\Contracts;

use FluentCart\App\Http\Requests\OrderRequest;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Request-boundary cents invariant for OrderRequest.
 *
 * The REST suite cannot isolate this contract: every REST observation of
 * shipping_total happens AFTER both OrderRequest::sanitize() and the resource
 * guard in OrderResource::updatedPlaceOrder() have run, so reverting the
 * sanitize entry to bare floatval leaves every Functional test green — the
 * resource guard repairs the value downstream on the create path, and on the
 * update path ($order->update(), no resource guard) MySQL itself rounds the
 * float into the BIGINT column to the same integer. The ONLY observable
 * difference is the sanitized value each in-process consumer receives, so this
 * test drives the real pipeline the controllers use —
 * Request::getSafe((new OrderRequest)->sanitize()), i.e. Sanitizer::sanitize()
 * plus pickKeys() — and asserts on that value directly.
 *
 * assertSame() with an int expectation is load-bearing: floatval(1999) is
 * float(1999.0), so even the clean-integer row fails on TYPE if the roundCent
 * closure is removed. Revert the closure to 'floatval' and every row here goes
 * red while CreateOrderCest and the resource contract test stay green.
 */
class OrderRequestCentsContractTest extends WPTestCase
{
    /**
     * Spec: Cases/Integration/orders.md [263]
     *   "Passing an order payload through OrderRequest's sanitize map via
     *    Request::getSafe() yields shipping_total as a whole-cent INTEGER at the
     *    boundary — JS float artifacts round to the nearest cent before any
     *    resource or model code runs, and even a clean integer input emerges as
     *    an int, not a float."
     */
    public function test_request_boundary_normalizes_shipping_total_to_whole_cent_int(): void
    {
        // Same discrimination matrix as the resource contract test, plus the
        // clean-integer row whose TYPE alone kills a floatval regression.
        //
        //   input                  roundCent  floatval
        //   1998.9999999999998     int 1999   float 1998.99…  (19.99 * 100 in JS)
        //   7.000000000000001      int 7      float 7.00…1    (0.07 * 100 in JS)
        //   1999.4                 int 1999   float 1999.4
        //   1999.5                 int 2000   float 1999.5
        //   1999                   int 1999   float 1999.0
        $cases = [
            [1998.9999999999998, 1999, 'a below-integer JS artifact must leave the request boundary as int 1999'],
            [7.000000000000001, 7, 'an above-integer JS artifact must leave the request boundary as int 7'],
            [1999.4, 1999, 'below half must round down at the boundary'],
            [1999.5, 2000, 'half must round up at the boundary'],
            [1999, 1999, 'a clean integer must stay int — floatval would degrade it to float(1999.0)'],
            [2000.5, 2001, "an even-base half must round UP at the boundary — banker's rounding would keep 2000"],
        ];

        $map = (new OrderRequest())->sanitize();

        foreach ($cases as $case) {
            list($supplied, $expected, $why) = $case;

            // The real consumer pipeline: OrderController::store()/updateOrder()
            // both do $request->getSafe($request->sanitize()) on the framework
            // request carrying the client payload.
            $request = new Request(App::make(), [], ['shipping_total' => $supplied]);
            $safe    = $request->getSafe($map);

            $this->assertSame(
                $expected,
                Arr::get($safe, 'shipping_total'),
                $why . ' (supplied ' . var_export($supplied, true) . ', got '
                    . var_export(Arr::get($safe, 'shipping_total'), true)
                    . '). This is the only surface where the request-layer guard is observable.'
            );
        }
    }

    /**
     * Spec: Cases/Integration/orders.md [264]
     *   "Both direct cents boundaries REJECT magnitudes beyond float's
     *    exact-integer range by throwing InvalidArgumentException instead of
     *    letting the int cast wrap 1e19 into a negative-but-valid BIGINT."
     */
    public function test_request_boundary_rejects_out_of_range_shipping_total(): void
    {
        $map = (new OrderRequest())->sanitize();

        foreach ([1e19, '1e19', 1e100, -1e19] as $supplied) {
            $request = new Request(App::make(), [], ['shipping_total' => $supplied]);

            try {
                $safe = $request->getSafe($map);
                $this->fail(
                    'an out-of-range shipping_total must throw at the sanitize boundary, not coerce — got '
                        . var_export(\FluentCart\Framework\Support\Arr::get($safe, 'shipping_total'), true)
                        . ' for supplied ' . var_export($supplied, true)
                );
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(
                    'cents',
                    $e->getMessage(),
                    'the boundary rejection must identify itself as a cents-range violation (supplied '
                        . var_export($supplied, true) . ')'
                );
            }
        }
    }
}
