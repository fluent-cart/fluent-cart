<?php

namespace FluentCart\App\Services\FileSystem\Drivers\S3;

use FluentCart\App\App;
use FluentCart\App\Modules\StorageDrivers\S3\S3;
use FluentCart\Framework\Support\Arr;

class S3FileList
{
    private string $date;
    private string $timeStamp;
    private string $accessKey;
    private string $bucket;
    private string $hashAlgorithm = 'sha256';
    private string $httpMethod;
    private string $region;
    private string $secretKey;
    private string $signature;
    private string $requestUrl;

    private int $maxKeys = 10;

    public static function get(string $secret, string $accessKey, string $bucket, string $region, $search = '')
    {
        $self = new static($secret, $accessKey, $bucket, $region);
        return $self->getList($search);
    }

    public function __construct(string $secret, string $accessKey, string $bucket = '', string $region = '')
    {
        $this->secretKey = $secret;
        $this->accessKey = $accessKey;
        $this->region = S3::getBucketRegion($bucket);
        $this->bucket = $bucket;

        $this->httpMethod = 'GET';
        $this->timeStamp = gmdate('Ymd\THis\Z');
        $this->date = substr($this->timeStamp, 0, 8);
        $this->maxKeys = (int)App::request()->get('per_page', 10);

        // ✅ Corrected region-based S3 endpoint
        $baseUrl = $this->bucket === ''
            ? "https://s3.{$this->region}.amazonaws.com"
            : "https://{$this->bucket}.s3.{$this->region}.amazonaws.com";

        $this->requestUrl = "{$baseUrl}/?encoding-type=url&list-type=2&max-keys={$this->maxKeys}";
        $this->signature = $this->generateSignature();

    }

    public function getList($search = '')
    {
        add_filter('http_request_timeout', fn() => 30);

        $url = $this->requestUrl;

        if (!empty($search)) {
            $url .= '&prefix=' . rawurlencode($search);
        }

        $this->signature = $this->generateSignature(['prefix' => $search]);

        $response = wp_remote_request($url, [
            'method'  => $this->httpMethod,
            'headers' => $this->getHeaders(),
        ]);

        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode === 200) {
            return $this->parseResponseXml($response);
        }

        return new \WP_Error(
            $responseCode,
            __('Invalid Credential', 'fluent-cart')
        );
    }

    public function verifyAuth()
    {
        add_filter('http_request_timeout', fn() => 30);

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $this->getHeaders(),
        ]);

        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode === 200) {
            return [
                'message' => __('Successfully tested S3!', 'fluent-cart'),
                'code'    => $responseCode,
            ];
        }

        $error_message = __('Invalid Credential', 'fluent-cart');
        $responseBody = wp_remote_retrieve_body($response);

        if (!empty($responseBody)) {
            $xml = @simplexml_load_string($responseBody);
            if ($xml) {
                $code = (string)$xml->Code;
                $message = (string)$xml->Message;
                // Uncomment if you want to include AWS error message
                // $error_message .= " ({$code}: {$message})";
            }
        }

        return new \WP_Error($responseCode, $error_message);
    }

    private function parseResponseXml($response): array
    {
        $xml = simplexml_load_string(wp_remote_retrieve_body($response));
        $array = json_decode(json_encode($xml), true);

        $files = [];
        $contents = Arr::get($array, 'Contents', []);

        if (!Arr::has($contents, 'Key')) {
            foreach ($contents as $file) {
                $files[] = [
                    'name'   => $file['Key'],
                    'size'   => $file['Size'],
                    'driver' => 's3',
                    'bucket' => $this->bucket,
                ];
            }
        } else {
            $files[] = [
                'name'   => $contents['Key'],
                'size'   => $contents['Size'],
                'driver' => 's3',
                'bucket' => $this->bucket,
            ];
        }

        return $files;
    }

    private function generateSignature(array $params = []): string
    {
        return hash_hmac(
            $this->hashAlgorithm,
            $this->createStringToSign($params),
            $this->getSigningKey()
        );
    }

    private function createStringToSign(array $params = []): string
    {
        $hash = hash($this->hashAlgorithm, $this->createCanonicalUrl($params));
        return "AWS4-HMAC-SHA256\n{$this->timeStamp}\n{$this->getScope()}\n{$hash}";
    }

    private function createCanonicalUrl(array $params = []): string
    {
        $prefix = Arr::get($params, 'prefix', '');
        $canonicalQuery = "encoding-type=url&list-type=2&max-keys={$this->maxKeys}";

        if ($prefix !== '') {
            $canonicalQuery .= '&prefix=' . rawurlencode($prefix);
        }

        $contentHash = $this->getContentHash();
        $host = $this->getHost();

        return "{$this->httpMethod}\n" .
            "/\n" .
            "{$canonicalQuery}\n" .
            "host:{$host}\n" .
            "x-amz-content-sha256:{$contentHash}\n" .
            "x-amz-date:{$this->timeStamp}\n\n" .
            "host;x-amz-content-sha256;x-amz-date\n" .
            "{$contentHash}";
    }

    private function getHost(): string
    {
        return $this->bucket === ''
            ? "s3.{$this->region}.amazonaws.com"
            : "{$this->bucket}.s3.{$this->region}.amazonaws.com";
    }

    private function getContentHash(): string
    {
        return hash($this->hashAlgorithm, '');
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
            'Authorization'        => sprintf(
                'AWS4-HMAC-SHA256 Credential=%s/%s/%s/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=%s',
                $this->accessKey,
                $this->date,
                $this->region,
                $this->signature
            ),
        ];
    }
}
