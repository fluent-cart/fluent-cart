<?php

namespace Tests\Support\Traits;

/**
 * Intercepts every PayPal call so tests never reach the real API.
 *
 * The OAuth token request is always satisfied, and only the endpoint under test
 * is scripted. That distinction matters: a mock that swallows the token request
 * makes API::makeRequest bail before it ever reaches the endpoint, and the test
 * then passes or fails for a reason that has nothing to do with the code it
 * claims to cover.
 */
trait MocksPayPalHttp
{
    /** @var callable|null */
    private $payPalHttpFilter;

    /** @var array<string, array> endpoint fragment => wp_remote response */
    private $payPalRoutes = [];

    /** @var array<int, string> every PayPal URL requested, in order */
    private $payPalRequests = [];

    protected function installPayPalHttpMock(): void
    {
        $this->payPalRoutes = [];
        $this->payPalRequests = [];

        $this->payPalHttpFilter = function ($preempt, $args, $url) {
            if (strpos($url, 'paypal.com') === false) {
                return $preempt; // never intercept anything else
            }

            $this->payPalRequests[] = $url;

            // Always hand back a usable access token; scripting this per-test
            // would only ever produce false results.
            if (strpos($url, 'oauth2/token') !== false) {
                return $this->payPalResponse(200, [
                    'access_token' => 'test-access-token',
                    'expires_in'   => 32400,
                    'app_id'       => 'APP-TEST',
                ]);
            }

            foreach ($this->payPalRoutes as $fragment => $response) {
                if (strpos($url, $fragment) !== false) {
                    return $response;
                }
            }

            return $this->payPalResponse(500, ['name' => 'UNSCRIPTED_ENDPOINT', 'message' => $url]);
        };

        add_filter('pre_http_request', $this->payPalHttpFilter, 10, 3);
    }

    protected function removePayPalHttpMock(): void
    {
        if ($this->payPalHttpFilter) {
            remove_filter('pre_http_request', $this->payPalHttpFilter, 10);
            $this->payPalHttpFilter = null;
        }
    }

    /**
     * @param string $endpointFragment e.g. 'vault/payment-tokens'
     */
    protected function scriptPayPal(string $endpointFragment, int $status, array $body): void
    {
        $this->payPalRoutes[$endpointFragment] = $this->payPalResponse($status, $body);
    }

    protected function payPalRequestCount(string $endpointFragment): int
    {
        $count = 0;
        foreach ($this->payPalRequests as $url) {
            if (strpos($url, $endpointFragment) !== false) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * A captured Orders-v2 order. Field paths mirror the ones core reads in
     * PayPalGateway\Processor::maybePersistVaultToken, so the fixture and
     * production cannot drift on where PayPal puts things.
     */
    protected function capturedPayPalOrder(string $tokenId = 'tok_vault_1', string $email = 'buyer@example.com', string $payerId = 'PAYER1'): array
    {
        return [
            'id'             => 'PPORDER1',
            'status'         => 'COMPLETED',
            'payment_source' => [
                'paypal' => [
                    'email_address' => $email,
                    'attributes'    => [
                        'vault' => [
                            'id'       => $tokenId,
                            'status'   => 'VAULTED',
                            'customer' => ['id' => 'ppcust_1'],
                        ],
                    ],
                ],
            ],
            'payer'          => [
                'payer_id' => $payerId,
                'name'     => ['given_name' => 'Ada', 'surname' => 'Lovelace'],
            ],
        ];
    }

    /**
     * The buyer paid but declined the save agreement: PayPal still describes the
     * account, but the vault container carries no token.
     */
    protected function capturedPayPalOrderWithDeclinedSave(): array
    {
        $order = $this->capturedPayPalOrder();
        $order['payment_source']['paypal']['attributes']['vault'] = ['status' => 'CREATED'];

        return $order;
    }

    /**
     * The account cannot vault at all: no vault container comes back.
     */
    protected function capturedPayPalOrderWithoutVaulting(): array
    {
        $order = $this->capturedPayPalOrder();
        unset($order['payment_source']['paypal']['attributes']);

        return $order;
    }

    private function payPalResponse(int $status, array $body): array
    {
        return [
            'response' => ['code' => $status, 'message' => 'stubbed'],
            'body'     => json_encode($body),
            'headers'  => [],
            'cookies'  => [],
        ];
    }
}
