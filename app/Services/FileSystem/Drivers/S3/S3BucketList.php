<?php

namespace FluentCart\App\Services\FileSystem\Drivers\S3;

use FluentCart\Framework\Support\Arr;

class S3BucketList
{
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private string $hashAlgorithm = 'sha256';
    private string $httpMethod;
    private string $signature;
    private $timeStamp;
    private $date;
    private string $requestUrl;


    public static function get(string $secret, string $accessKey, string $region)
    {
        return (new static($secret, $accessKey, $region))->getList();
    }

    public function __construct(string $secret, string $accessKey, string $region)
    {
        $this->secretKey = $secret;
        $this->accessKey = $accessKey;
        $this->region = $region;

        $this->httpMethod = "GET";
        $this->timeStamp = gmdate('Ymd\THis\Z');
        $this->date = substr($this->timeStamp, 0, 8);
        $this->requestUrl = "https://s3.amazonaws.com";
        $this->generateSignature();
    }

    public function getList()
    {
        add_filter('http_request_timeout', function () {
            return 30; // Set the timeout to 30 seconds (or adjust as needed)
        });

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $this->getHeaders()
        ]);


        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode == '200') {
            return $this->parseResponseXml($response);
        } else {
            return new \WP_Error(
                $responseCode,
                __('Invalid Credential', 'fluent-cart')
            );
        }
    }

    private function parseResponseXml($response): array
    {
        $xml = simplexml_load_string(wp_remote_retrieve_body($response));
        $array = json_decode(json_encode($xml), TRUE);

        $responseBucket = Arr::get($array, 'Buckets.Bucket', []);

        if(Arr::has($responseBucket,'Name')){
            return [
                Arr::get($responseBucket, 'Name')
            ];
        }

        $buckets = [];
        foreach ($responseBucket as $bucket) {
            $buckets[] = Arr::get($bucket, 'Name');
        }
        return $buckets;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function generateSignature()
    {
        $this->signature = $this->generateSignatureKey();
    }

    private function createScope(): string
    {
        return "{$this->date}/{$this->region}/s3/aws4_request";
    }

    private function getContentHash(): string
    {
        return hash($this->hashAlgorithm, "");
    }

    private function createCanonicalUrl(): string
    {
        return "$this->httpMethod\n" .
            "/\n\n" .
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

    private function createStringToSign(): string
    {
        $hash = hash($this->hashAlgorithm, $this->createCanonicalUrl());
        return "AWS4-HMAC-SHA256\n{$this->timeStamp}\n{$this->createScope()}\n{$hash}";
    }

    private function getSigningKey()
    {
        $dateKey = hash_hmac($this->hashAlgorithm, $this->date, "AWS4{$this->secretKey}", true);
        $regionKey = hash_hmac($this->hashAlgorithm, $this->region, $dateKey, true);
        $serviceKey = hash_hmac($this->hashAlgorithm, 's3', $regionKey, true);
        return hash_hmac($this->hashAlgorithm, 'aws4_request', $serviceKey, true);
    }

    private function generateSignatureKey()
    {
        return hash_hmac($this->hashAlgorithm, $this->createStringToSign(), $this->getSigningKey());
    }

    public function getHeaders(): array
    {
        return [
            "x-amz-content-sha256" => $this->getContentHash(),
            'x-amz-date'           => $this->timeStamp,
            'Authorization'        => "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$this->date}/{$this->region}/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$this->getSignature()}"
        ];
    }
}
