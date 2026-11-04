<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Traits\MocksPayPalHttp;

class PayPalApiErrorHandlingTest extends WPTestCase
{
    use MocksPayPalHttp;

    public function setUp(): void
    {
        parent::setUp();
        $this->installPayPalHttpMock();
    }

    public function tearDown(): void
    {
        $this->removePayPalHttpMock();
        parent::tearDown();
    }

    /**
     * @dataProvider paypalErrorResponses
     */
    public function test_get_request_preserves_paypal_error_messages(array $body, string $expectedMessage): void
    {
        $this->scriptPayPal('paypal-error', 401, $body);

        $warning = null;
        set_error_handler(function ($severity, $message) use (&$warning) {
            $warning = $message;
            return true;
        });

        try {
            $result = API::getRequest(
                'https://api-m.paypal.com/v1/paypal-error',
                'test-access-token',
                'live'
            );
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(\WP_Error::class, $result, 'PayPal errors must be returned as WP_Error.');
        $this->assertSame($expectedMessage, $result->get_error_message(), 'The useful PayPal error message must be preserved.');
        $this->assertNull($warning, 'Parsing a PayPal error must not emit a PHP warning.');
    }

    public static function paypalErrorResponses(): array
    {
        return [
            'error description without message' => [
                [
                    'error'             => 'invalid_client',
                    'error_description' => 'Invalid client credentials',
                ],
                'Invalid client credentials',
            ],
            'REST detail issue without description' => [
                [
                    'name'    => 'VALIDATION_ERROR',
                    'message' => 'Invalid request - see details',
                    'details' => [['issue' => 'cannot be negative']],
                ],
                'cannot be negative',
            ],
            'REST detail description' => [
                [
                    'name'    => 'VALIDATION_ERROR',
                    'message' => 'Invalid request - see details',
                    'details' => [[
                        'issue'       => 'INVALID_AMOUNT',
                        'description' => 'The amount is invalid.',
                    ]],
                ],
                'The amount is invalid.',
            ],
            'error without message' => [
                ['error' => 'unknown_error'],
                'PayPal General Error',
            ],
        ];
    }
}
