<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\Webhook;

class WebhookVerifier
{
    private string $secretKey;
    private int $timestampTolerance;

    public function __construct(string $secretKey, int $timestampTolerance = 300)
    {
        if (empty($secretKey)) {
            new \WP_Error('paddle_webhook_secret_missing', __('Paddle webhook secret is missing', 'fluent-cart-pro'));
        }

        $this->secretKey = $secretKey;
        $this->timestampTolerance = $timestampTolerance;
    }

    /**
     * Verify a webhook signature
     *
     * @param string $signature The Paddle-Signature header value
     * @param string $rawBody The raw request body (should not be processed/modified)
     * @return bool True if signature is valid, false otherwise
     */
    public function verify(string $signature, string $rawBody): bool
    {
            $parsedSignature = $this->parseSignatureHeader($signature);

            if (is_wp_error($parsedSignature)) {
                return false;
            }

            // Check timestamp to prevent replay attacks
            if (!$this->isTimestampValid($parsedSignature['timestamp'])) {
                return false;
            }

            $signedPayload = $this->buildSignedPayload($parsedSignature['timestamp'], $rawBody);
            $expectedSignature = $this->generateSignature($signedPayload);
            return $this->compareSignatures($parsedSignature['signature'], $expectedSignature);

    }

    /**
     * Verify webhook from HTTP request data
     *
     * @param array $headers HTTP headers (should contain 'paddle-signature' or 'Paddle-Signature')
     * @param string $rawBody The raw request body
     * @return bool True if signature is valid, false otherwise
     */
    public function verifyFromRequest(array $headers, string $rawBody): bool
    {
        // Look for the signature header (case-insensitive)
        $signature = null;
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'paddle-signature') {
                $signature = is_array($value) ? $value[0] : $value;
                break;
            }
        }

        if (empty($signature)) {
            new \WP_Error('paddle_webhook_signature_invalid', __('Invalid webhook signature', 'fluent-cart-pro'));
        }

        return $this->verify($signature, $rawBody);
    }

    private function isTimestampValid(string $timestamp): bool
    {
        if (!is_numeric($timestamp)) {
            return false;
        }

        $timestampInt = (int) $timestamp;
        $currentTime = time();
        $timeDifference = abs($currentTime - $timestampInt);

        return $timeDifference <= $this->timestampTolerance;
    }

    private function parseSignatureHeader(string $signature): array
    {
        $parts = explode(';', $signature);
        $timestamp = null;
        $signatureHash = null;

        foreach ($parts as $part) {
            $keyValue = explode('=', $part, 2);
            if (count($keyValue) !== 2) {
                continue;
            }

            $key = trim($keyValue[0]);
            $value = trim($keyValue[1]);

            if ($key === 'ts') {
                $timestamp = $value;
            } elseif ($key === 'h1') {
                $signatureHash = $value;
            }
        }

        if ($timestamp === null || $signatureHash === null) {
           new \WP_Error('paddle_webhook_signature_invalid', __('Invalid webhook signature', 'fluent-cart-pro'));
        }

        if (!is_numeric($timestamp)) {
            new \WP_Error('paddle_webhook_signature_invalid', __('Invalid webhook signature', 'fluent-cart-pro'));
        }

        return [
            'timestamp' => (int)$timestamp,
            'signature' => $signatureHash
        ];
    }

    private function buildSignedPayload(int $timestamp, string $rawBody): string
    {
        return $timestamp . ':' . $rawBody;
    }

    private function generateSignature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secretKey);
    }

    private function compareSignatures(string $receivedSignature, string $expectedSignature): bool
    {
        return hash_equals($expectedSignature, $receivedSignature);
    }

    private function getPaddleSignatureFromHeaders(array $headers): ?string
    {
        // Check for various header formats (case-insensitive)
        $possibleKeys = [
            'Paddle-Signature',
            'paddle-signature',
            'PADDLE-SIGNATURE',
            'HTTP_PADDLE_SIGNATURE'
        ];

        foreach ($possibleKeys as $key) {
            if (isset($headers[$key])) {
                return $headers[$key];
            }
        }

        // Also check with case-insensitive array search
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'paddle-signature') {
                return $value;
            }
        }

        return null;
    }

    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    public function getTimestampTolerance(): int
    {
        return $this->timestampTolerance;
    }

    public function setTimestampTolerance(int $tolerance): void
    {
        $this->timestampTolerance = $tolerance;
    }
}
