<?php

namespace FluentCart\App\Services\FileSystem\Drivers\S3;

use FluentCart\Framework\Support\Arr;

class S3BucketCreator
{
    private const DEFAULT_REGION = 'us-east-1';

    private string $accessKey;
    private string $secretKey;
    private string $region;
    private string $bucket;
    private string $hashAlgorithm = 'sha256';
    private string $httpMethod = 'PUT';
    private string $signature;
    private $timeStamp;
    private $date;
    private string $requestUrl;
    private ?string $sessionToken = null;

    public static function create(string $secret, string $accessKey, string $bucket, string $region, ?string $sessionToken = null)
    {
        $validation = S3InputValidator::validateBucketAndRegion($bucket, $region);
        if (is_wp_error($validation)) {
            return $validation;
        }

        return (new static($secret, $accessKey, $bucket, $region, $sessionToken))->createBucket();
    }

    public function __construct(string $secret, string $accessKey, string $bucket, string $region, ?string $sessionToken = null)
    {
        $this->secretKey = $secret;
        $this->accessKey = $accessKey;
        $this->bucket = $bucket;
        $this->region = $region;
        $this->sessionToken = $sessionToken;

        $this->timeStamp = gmdate('Ymd\THis\Z');
        $this->date = substr($this->timeStamp, 0, 8);
        
        $this->requestUrl = $this->getBucketBaseUrl();
        
        $this->generateSignature();
    }

    public function createBucket()
    {
        add_filter('http_request_timeout', function () {
            return 30;
        });
        
        $body = '';
        if ($this->region !== 'us-east-1') {
            $body = '<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">' .
                    '<LocationConstraint>' . $this->region . '</LocationConstraint>' .
                    '</CreateBucketConfiguration>';
        }

        $headers = $this->getHeaders($body);

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $headers,
            'body'    => $body
        ]);

        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode == '200') {
            return [
                'message' => __('Bucket created successfully', 'fluent-cart'),
                'bucket'  => $this->bucket,
                'region'  => $this->region
            ];
        } else {
            $errorBody = wp_remote_retrieve_body($response);
            $xml = simplexml_load_string($errorBody);
            $message = $xml ? (string)$xml->Message : __('Unknown error occurred', 'fluent-cart');
            $code = $xml ? (string)$xml->Code : $responseCode;
            
            // Append raw body if XML parsing failed for better debugging
            if (!$xml) {
                $message .= ' (' . substr($errorBody, 0, 200) . ')';
            }
            
            return new \WP_Error(
                'bucket_creation_failed',
                sprintf(__('Failed to create bucket: %s (%s)', 'fluent-cart'), $message, $code)
            );
        }
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function generateSignature()
    {
        // For CreateBucket with XML body, we likely need to sign payload hash if not us-east-1
        // But let's check basic signing first.
        
        // Actually, payload signing is required for v4 auth if we are sending body
        $this->signature = $this->generateSignatureKey();
    }

    private function createScope(): string
    {
        return "{$this->date}/{$this->region}/s3/aws4_request";
    }

    private function getContentHash($payload = ''): string
    {
        return hash($this->hashAlgorithm, $payload);
    }

    private function createCanonicalUrl($payloadHash): string
    {
        $payload = "$this->httpMethod\n" .
            "/\n\n" .
            "host:{$this->getHost()}\n" .
            "x-amz-content-sha256:{$payloadHash}\n" .
            "x-amz-date:{$this->timeStamp}\n";

        if ($this->sessionToken) {
            $payload .= "x-amz-security-token:{$this->sessionToken}\n";
        }

        $payload .= "\n";

        $signedHeaders = "host;x-amz-content-sha256;x-amz-date";
        if ($this->sessionToken) {
            $signedHeaders .= ";x-amz-security-token";
        }

        $payload .= $signedHeaders . "\n" .
            "{$payloadHash}";

        return $payload;
    }

    private function getHost(): string
    {
        return parse_url($this->getBucketBaseUrl(), PHP_URL_HOST) ?: "{$this->bucket}.s3.amazonaws.com";
    }

    private function getBucketBaseUrl(): string
    {
        $host = $this->region === self::DEFAULT_REGION
            ? "{$this->bucket}.s3.amazonaws.com"
            : "{$this->bucket}.s3.{$this->region}.amazonaws.com";

        return 'https://' . $host;
    }

    private function createStringToSign($payloadHash): string
    {
        $hash = hash($this->hashAlgorithm, $this->createCanonicalUrl($payloadHash));
        return "AWS4-HMAC-SHA256\n{$this->timeStamp}\n{$this->createScope()}\n{$hash}";
    }

    private function getSigningKey()
    {
        $dateKey = hash_hmac($this->hashAlgorithm, $this->date, "AWS4{$this->secretKey}", true);
        $regionKey = hash_hmac($this->hashAlgorithm, $this->region, $dateKey, true);
        $serviceKey = hash_hmac($this->hashAlgorithm, 's3', $regionKey, true);
        return hash_hmac($this->hashAlgorithm, 'aws4_request', $serviceKey, true);
    }
    
    // We need to re-generate signature dynamically based on body if needed, 
    // but constructor runs once. So usage of this class instance:
    
    // Actually, let's just do it inside getHeaders since body is there
    
    private function generateSignatureKey($body = '')
    {
        // wait, this method name in S3BucketList was 'generateSignatureKey' but it returned the Final signature?
        // Let's re-verify S3BucketList implementation. 
        // Yes, S3BucketList::generateSignatureKey returned hash_hmac(..., stringToSign, signingKey)
        // Which IS the signature.
        
        $payloadHash = $this->getContentHash($body);
        return hash_hmac($this->hashAlgorithm, $this->createStringToSign($payloadHash), $this->getSigningKey());
    }


    public function getHeaders($body = ''): array
    {
        $payloadHash = $this->getContentHash($body);
        
        // Re-calculate signature with body
        $signature = hash_hmac($this->hashAlgorithm, $this->createStringToSign($payloadHash), $this->getSigningKey());

        $headers = [
            "x-amz-content-sha256" => $payloadHash,
            'x-amz-date'           => $this->timeStamp,
        ];
        
        if ($this->region !== 'us-east-1') {
            $headers['Content-Type'] = 'application/xml';
            // AWS S3 CreateBucket doesn't strictly require Content-Type but good practice
        }

        if ($this->sessionToken) {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }

        $signedHeaders = "host;x-amz-content-sha256;x-amz-date";
        if ($this->sessionToken) {
            $signedHeaders .= ";x-amz-security-token";
        }

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$this->date}/{$this->region}/s3/aws4_request, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $headers;
    }
}
