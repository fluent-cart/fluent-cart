<?php

namespace Tests\Support\Traits;

/**
 * Builds the inbound side of a PayPal webhook delivery for handler tests:
 * the PAYPAL-* signature headers (installed into $_SERVER, which is where the
 * handler's header polyfill reads them in CLI) and a canned event body.
 */
trait BuildsPayPalWebhookRequests
{
    /** @var array<string, mixed> $_SERVER values replaced by installPayPalSignatureHeaders() */
    private array $payPalServerBackup = [];

    /**
     * @return array<string, string> $_SERVER keys → header values
     */
    protected function payPalSignatureHeaders(): array
    {
        return [
            'HTTP_PAYPAL_AUTH_ALGO'         => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL'          => 'https://api-m.sandbox.paypal.com/v1/notifications/certs/test',
            'HTTP_PAYPAL_TRANSMISSION_ID'   => 'test-transmission-id',
            'HTTP_PAYPAL_TRANSMISSION_SIG'  => 'test-transmission-signature',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-01-01T00:00:00Z',
        ];
    }

    protected function installPayPalSignatureHeaders(): void
    {
        $this->payPalServerBackup = [];
        foreach ($this->payPalSignatureHeaders() as $key => $value) {
            $this->payPalServerBackup[$key] = array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null;
            $_SERVER[$key] = $value;
        }
    }

    protected function clearPayPalSignatureHeaders(): void
    {
        foreach (array_keys($this->payPalSignatureHeaders()) as $key) {
            unset($_SERVER[$key]);
        }
    }

    protected function restorePayPalSignatureHeaders(): void
    {
        foreach ($this->payPalServerBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        $this->payPalServerBackup = [];
    }

    /**
     * A PAYMENT.CAPTURE.COMPLETED event that references no real order, so the
     * handler's own processing is a no-op and only the verification gate matters.
     */
    protected function payPalCaptureCompletedWebhookBody(): string
    {
        return wp_json_encode([
            'id'         => 'WH-TEST-CAPTURE-COMPLETED',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource'   => [
                'id'        => 'CAPTURE-TEST',
                'custom_id' => 'unknown-order-reference',
                'amount'    => [
                    'value'         => '10.00',
                    'currency_code' => 'USD',
                ],
            ],
        ]);
    }
}
