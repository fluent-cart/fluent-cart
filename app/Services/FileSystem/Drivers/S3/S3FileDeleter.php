<?php

namespace FluentCart\App\Services\FileSystem\Drivers\S3;

use Exception;
use FluentCart\App\Modules\StorageDrivers\S3\S3;

class S3FileDeleter
{
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $region;
    private string $hashAlgorithm = 'sha256';
    private string $httpMethod;
    private string $s3FilePath;
    private string $signature;
    private string $requestUrl;
    private $timeStamp;
    private $date;

    public function __construct(string $secret, string $accessKey, string $bucket, string $region, string $s3FilePath)
    {
        $this->secretKey = $secret;
        $this->accessKey = $accessKey;
        $this->bucket = $bucket;
        $this->region = S3::getBucketRegion($bucket);
        $this->s3FilePath = $s3FilePath;
        $this->httpMethod = "DELETE";
        $this->timeStamp = gmdate('Ymd\THis\Z');
        $this->date = substr($this->timeStamp, 0, 8);
        $this->requestUrl = "https://{$this->bucket}.s3.amazonaws.com/{$this->s3FilePath}";
        $this->signature = $this->generateSignature();
    }

    public static function delete(string $secret, string $accessKey, string $bucket, string $region, string $s3FilePath)
    {
        return (new static($secret, $accessKey, $bucket, $region, $s3FilePath))->deleteFile();
    }

    public function deleteFile()
    {
        add_filter('http_request_timeout', function () {
            return 30;
        });

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $this->getHeaders()
        ]);

        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode == 204) {
            return [
                'message' => __('File Deleted Successfully', 'fluent-cart'),
                'driver'  => 's3',
                'path'    => $this->s3FilePath
            ];
        } else {
            return new \WP_Error($responseCode, __('Failed To Delete File', 'fluent-cart'));
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
        $s3FilePath = '/' . ltrim($this->s3FilePath, '/');
        return "{$this->httpMethod}\n{$s3FilePath}\n\nhost:{$this->getHost()}\nx-amz-content-sha256:{$this->getContentHash()}\nx-amz-date:{$this->timeStamp}\n\nhost;x-amz-content-sha256;x-amz-date\n{$this->getContentHash()}";
    }

    private function getHost(): string
    {
        return "{$this->bucket}.s3.amazonaws.com";
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
            'x-amz-content-sha256' => $this->getContentHash(),
            'x-amz-date'           => $this->timeStamp,
            'Authorization'        => "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$this->date}/{$this->region}/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$this->signature}"
        ];
    }
}