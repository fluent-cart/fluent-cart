<?php

namespace FluentCart\App\Services\FileSystem\Drivers\S3;

class S3ConnectionVerify
{
    private $date;
    private $timeStamp;
    private string $accessKey;
    private string $bucket;
    private string $hashAlgorithm = 'sha256';
    private string $httpMethod;
    private string $region;
    private string $secretKey;
    private string $signature;
    private string $requestUrl;

    public static function verify(string $secret, string $accessKey)
    {
        $self = new static($secret, $accessKey);
        return $self->testConnection();
    }

    public function __construct(string $secret, string $accessKey)
    {
        $this->secretKey = $secret;
        $this->accessKey = $accessKey;
        $this->region = 'us-east-1';
        $this->bucket = '';

        $this->httpMethod = "GET";
        $this->timeStamp = gmdate('Ymd\THis\Z');
        $this->date = substr($this->timeStamp, 0, 8);
        $this->requestUrl = "https://s3.amazonaws.com/?list-type=2&encoding-type=url&max-keys=1";

        $this->signature = $this->generateSignature();
    }

    public function testConnection()
    {
        add_filter('http_request_timeout', function () {
            return 30;
        });

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $this->getHeaders()
        ]);

        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode == '200') {
            return [
                'message' => __('Successfully verified S3 connection!', 'fluent-cart'),
                'code'    => $responseCode
            ];
        } else {
            $error_message = __('Invalid S3 credentials', 'fluent-cart');

            $responseBody = wp_remote_retrieve_body($response);
            if (!empty($responseBody)) {
                $xml = simplexml_load_string($responseBody);
                if ($xml && isset($xml->Code)) {
                    $code = (string)$xml->Code;
                    $message = (string)$xml->Message;
                    $error_message = $message ?: $error_message;

                    if(str_contains($error_message,'User:')) {
                        $error_message = __('Your IAM user does not have permission to use S3 buckets', 'fluent-cart');
                    }else{
                        $error_message = __('Invalid S3 credentials', 'fluent-cart');
                    }
                }
            }


            
            return new \WP_Error($responseCode, $error_message);
        }
    }

    private function generateSignature()
    {
        return hash_hmac(
            $this->hashAlgorithm,
            $this->createStringToSign(),
            $this->getSigningKey()
        );
    }

    private function createStringToSign(): string
    {
        $hash = hash($this->hashAlgorithm, $this->createCanonicalUrl());
        return "AWS4-HMAC-SHA256\n{$this->timeStamp}\n{$this->getScope()}\n{$hash}";
    }

    private function createCanonicalUrl(): string
    {
        $canonicalQuery = "encoding-type=url&list-type=2&max-keys=1";

        return "$this->httpMethod\n" .
            "/\n" .
            "{$canonicalQuery}\n" .
            "host:{$this->getHost()}\n" .
            "x-amz-content-sha256:{$this->getContentHash()}\n" .
            "x-amz-date:{$this->timeStamp}\n\n" .
            "host;x-amz-content-sha256;x-amz-date\n" .
            "{$this->getContentHash()}";
    }

    private function getHost(): string
    {
        return "s3.amazonaws.com";
    }

    private function getContentHash(): string
    {
        return hash($this->hashAlgorithm, "");
    }

    private function getScope(): string
    {
        return "{$this->date}/{$this->region}/s3/aws4_request";
    }

    private function getSigningKey()
    {
        $dateKey = hash_hmac($this->hashAlgorithm, $this->date, "AWS4{$this->secretKey}", true);
        $regionKey = hash_hmac($this->hashAlgorithm, $this->region, $dateKey, true);
        $serviceKey = hash_hmac($this->hashAlgorithm, 's3', $regionKey, true);
        return hash_hmac($this->hashAlgorithm, 'aws4_request', $serviceKey, true);
    }

    private function getHeaders(): array
    {
        return [
            "x-amz-content-sha256" => $this->getContentHash(),
            'x-amz-date'           => $this->timeStamp,
            'Authorization'        => "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$this->date}/{$this->region}/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$this->signature}"
        ];
    }
}