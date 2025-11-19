<?php

namespace FluentCart\App\Services\FileSystem\Drivers\S3;

use Exception;
use FluentCart\App\Modules\StorageDrivers\S3\S3;
use FluentCart\Framework\Support\Str;
use WP_Error;

class S3FileUploader
{
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $region;
    private string $hashAlgorithm = 'sha256';
    private string $httpMethod;
    private string $localFilePath;
    private string $s3FilePath;
    private string $signature;
    private string $requestUrl;
    private string $timeStamp;
    private string $date;

    /**
     * @throws Exception
     */
    public function __construct(string $secret, string $accessKey, string $bucket, string $region, string $localFilePath, string $s3FilePath)
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secret;
        $this->bucket = $bucket;
        $this->region = S3::getBucketRegion($bucket);
        $this->localFilePath = $localFilePath;
        $this->s3FilePath = $s3FilePath;
        $this->httpMethod = "PUT";

        $this->timeStamp = gmdate('Ymd\THis\Z');
        $this->date = substr($this->timeStamp, 0, 8);

        // ✅ Correct Regional Endpoint
        $this->requestUrl = "https://{$this->bucket}.s3.{$this->region}.amazonaws.com/{$this->s3FilePath}";

        $this->signature = $this->generateSignature();
    }

    /**
     * @throws Exception
     */
    public static function upload(string $secret, string $accessKey, string $bucket, string $region, string $localFilePath, string $s3FilePath)
    {
        return (new static($secret, $accessKey, $bucket, $region, $localFilePath, $s3FilePath))->uploadFile();
    }

    /**
     * @throws Exception
     */
    public function uploadFile()
    {
        add_filter('http_request_timeout', fn() => 30);

        $args = [
            'method'  => 'PUT',
            'headers' => $this->getHeaders(),
            'body'    => file_get_contents($this->localFilePath),
        ];

        $response = wp_remote_request($this->requestUrl, $args);
        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode === 200) {
            return [
                'message' => __('File Uploaded Successfully', 'fluent-cart'),
                'driver'  => 's3',
                'path'    => $this->s3FilePath
            ];
        }

        return new WP_Error($responseCode, __('Failed To Upload File', 'fluent-cart'));
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    /**
     * @throws Exception
     */
    public function generateSignature()
    {
        return hash_hmac(
            $this->hashAlgorithm,
            $this->createStringToSign(),
            $this->getSigningKey()
        );
    }

    private function createScope(): string
    {
        return "{$this->date}/{$this->region}/s3/aws4_request";
    }

    /**
     * @throws Exception
     */
    private function getContentHash(): string
    {
        if (!file_exists($this->localFilePath)) {
            throw new \Exception(esc_html__('File not found', 'fluent-cart'));
        }
        return hash($this->hashAlgorithm, file_get_contents($this->localFilePath));
    }

    /**
     * @throws Exception
     */
    private function createCanonicalUrl(): string
    {
        // Ensure file path begins with /
        $s3FilePath = Str::startsWith($this->s3FilePath, '/')
            ? $this->s3FilePath
            : "/{$this->s3FilePath}";

        $contentHash = $this->getContentHash();

        return "{$this->httpMethod}\n"
            . "{$s3FilePath}\n\n"
            . "host:{$this->getUploadHost()}\n"
            . "x-amz-content-sha256:{$contentHash}\n"
            . "x-amz-date:{$this->timeStamp}\n\n"
            . "host;x-amz-content-sha256;x-amz-date\n"
            . "{$contentHash}";
    }

    private function getUploadHost(): string
    {
        // ✅ Regional host
        return "{$this->bucket}.s3.{$this->region}.amazonaws.com";
    }

    /**
     * @throws Exception
     */
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

    /**
     * @throws Exception
     */
    public function getHeaders(): array
    {
        return [
            'x-amz-content-sha256' => $this->getContentHash(),
            'x-amz-date'           => $this->timeStamp,
            'Authorization'        => "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$this->date}/{$this->region}/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$this->getSignature()}"
        ];
    }
}
