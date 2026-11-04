<?php

namespace Tests\Integration\Services\FileSystem;

use FluentCart\App\Services\FileSystem\Drivers\S3\S3FileUploader;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * SigV4 request shape for S3FileUploader, around the opt-in
 * `fluent_cart/storage/s3_prevent_overwrite` filter.
 *
 * The canonical request and the Authorization header must always agree on the
 * signed-header list; if they drift apart S3 rejects every upload with 403.
 * These tests recompute the signature independently from an explicitly written
 * canonical request, so the canonical format is pinned byte-for-byte without
 * reaching into private methods.
 */
class S3FileUploaderTest extends WPTestCase
{
    private const BUCKET = 'ninjaeddplugins';
    private const KEY = 'fluentformpro-6.0.2.zip';
    private const REGION = 'us-east-1';
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    private const ACCESS = 'AKIAIOSFODNN7EXAMPLE';

    private string $fixturePath;

    public function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = wp_tempnam('fct-s3-uploader');
        file_put_contents($this->fixturePath, 'plugin-zip-contents');

        // S3::getBucketRegion() resolves the region over HTTP. Keep the test
        // off the network and pin the region it caches.
        add_filter('pre_http_request', function () {
            return [
                'headers'  => ['x-amz-bucket-region' => self::REGION],
                'body'     => '',
                'response' => ['code' => 200, 'message' => 'OK'],
            ];
        });
    }

    public function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        remove_all_filters('fluent_cart/storage/s3_prevent_overwrite');

        if (file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    public function test_no_conditional_header_is_sent_by_default(): void
    {
        $uploader = new S3FileUploader(
            self::SECRET, self::ACCESS, self::BUCKET, self::REGION, $this->fixturePath, self::KEY
        );

        $headers = $uploader->getHeaders();

        $this->assertArrayNotHasKey(
            'If-None-Match',
            $headers,
            'existing installs must keep overwriting objects, so the header must be absent unless opted in'
        );
        $this->assertStringContainsString(
            'SignedHeaders=host;x-amz-content-sha256;x-amz-date,',
            $headers['Authorization'],
            'the default signed-header list must be unchanged from before the filter existed'
        );
    }

    public function test_conditional_header_is_sent_when_the_filter_opts_in(): void
    {
        add_filter('fluent_cart/storage/s3_prevent_overwrite', '__return_true');

        $uploader = new S3FileUploader(
            self::SECRET, self::ACCESS, self::BUCKET, self::REGION, $this->fixturePath, self::KEY
        );

        $headers = $uploader->getHeaders();

        $this->assertSame(
            '*',
            $headers['If-None-Match'],
            'If-None-Match: * is what makes S3 answer 412 instead of replacing the object'
        );
        $this->assertStringContainsString(
            'SignedHeaders=host;if-none-match;x-amz-content-sha256;x-amz-date,',
            $headers['Authorization'],
            'if-none-match must be signed, and SigV4 requires the list sorted by lowercase header name'
        );
    }

    public function test_the_filter_receives_the_bucket_and_key_as_context(): void
    {
        $seen = [];

        add_filter('fluent_cart/storage/s3_prevent_overwrite', function ($enabled, $context) use (&$seen) {
            $seen = $context;
            return $enabled;
        }, 10, 2);

        new S3FileUploader(
            self::SECRET, self::ACCESS, self::BUCKET, self::REGION, $this->fixturePath, self::KEY
        );

        $this->assertSame(
            self::BUCKET,
            $seen['bucket'],
            'listeners need the bucket so protection can be enabled per bucket'
        );
        $this->assertSame(
            self::KEY,
            $seen['s3_file_path'],
            'listeners need the object key so protection can be scoped to a path'
        );
    }

    public function test_upload_of_an_existing_key_returns_a_wp_error_when_protection_is_on(): void
    {
        add_filter('fluent_cart/storage/s3_prevent_overwrite', '__return_true');

        // S3 answers 412 Precondition Failed when If-None-Match: * hits an
        // existing key. Only the upload PUT is overridden; the region lookup
        // in setUp() stays on its 200 stub. The PUT's headers are captured so
        // the test proves what is transmitted, not just what getHeaders()
        // returns.
        $sentHeaders = null;

        add_filter('pre_http_request', function ($response, $args) use (&$sentHeaders) {
            if (($args['method'] ?? '') === 'PUT') {
                $sentHeaders = $args['headers'];

                return [
                    'headers'  => [],
                    'body'     => '',
                    'response' => ['code' => 412, 'message' => 'Precondition Failed'],
                ];
            }

            return $response;
        }, 11, 2);

        $uploader = new S3FileUploader(
            self::SECRET, self::ACCESS, self::BUCKET, self::REGION, $this->fixturePath, self::KEY
        );

        $result = $uploader->uploadFile();

        $this->assertSame(
            '*',
            $sentHeaders['If-None-Match'] ?? null,
            'the conditional header must reach the actual PUT request, not only getHeaders()'
        );
        $this->assertStringContainsString(
            'SignedHeaders=host;if-none-match;x-amz-content-sha256;x-amz-date,',
            $sentHeaders['Authorization'] ?? '',
            'the transmitted Authorization header must sign the conditional header'
        );
        $this->assertInstanceOf(
            \WP_Error::class,
            $result,
            'a refused overwrite must surface as WP_Error, not as a success array'
        );
        $this->assertSame(
            412,
            $result->get_error_code(),
            'the WP_Error code must carry the S3 response code'
        );
        $this->assertStringContainsString(
            'A file named "' . self::KEY . '" already exists',
            $result->get_error_message(),
            'the error must name the conflicting file so the admin knows which upload to rename'
        );
    }

    public function test_a_412_without_opt_in_is_not_reported_as_an_overwrite_conflict(): void
    {
        add_filter('pre_http_request', function ($response, $args) {
            if (($args['method'] ?? '') === 'PUT') {
                return [
                    'headers'  => [],
                    'body'     => '',
                    'response' => ['code' => 412, 'message' => 'Precondition Failed'],
                ];
            }

            return $response;
        }, 11, 2);

        $uploader = new S3FileUploader(
            self::SECRET, self::ACCESS, self::BUCKET, self::REGION, $this->fixturePath, self::KEY
        );

        $result = $uploader->uploadFile();

        $this->assertInstanceOf(
            \WP_Error::class,
            $result,
            'a failed upload must still surface as WP_Error'
        );
        $this->assertSame(
            'Failed To Upload File',
            $result->get_error_message(),
            'without the opt-in no conditional header was sent, so a 412 must fall through to the generic error instead of claiming an overwrite conflict'
        );
    }

    public function test_signature_matches_the_canonical_request_when_protection_is_off(): void
    {
        // Capture the headers of the real PUT so the signature contract is
        // pinned on the transmitted request, not on getHeaders() output.
        $sentHeaders = null;

        add_filter('pre_http_request', function ($response, $args) use (&$sentHeaders) {
            if (($args['method'] ?? '') === 'PUT') {
                $sentHeaders = $args['headers'];

                return [
                    'headers'  => [],
                    'body'     => '',
                    'response' => ['code' => 200, 'message' => 'OK'],
                ];
            }

            return $response;
        }, 11, 2);

        $uploader = new S3FileUploader(
            self::SECRET, self::ACCESS, self::BUCKET, self::REGION, $this->fixturePath, self::KEY
        );
        $uploader->uploadFile();

        $this->assertIsArray($sentHeaders, 'uploadFile() must have performed the PUT whose headers we pin');

        $timeStamp = $sentHeaders['x-amz-date'];
        $date = substr($timeStamp, 0, 8);
        $contentHash = hash('sha256', file_get_contents($this->fixturePath));
        $host = self::BUCKET . '.s3.' . self::REGION . '.amazonaws.com';

        $canonical = "PUT\n"
            . '/' . self::KEY . "\n\n"
            . "host:{$host}\n"
            . "x-amz-content-sha256:{$contentHash}\n"
            . "x-amz-date:{$timeStamp}\n\n"
            . "host;x-amz-content-sha256;x-amz-date\n"
            . $contentHash;

        $stringToSign = "AWS4-HMAC-SHA256\n{$timeStamp}\n{$date}/" . self::REGION . "/s3/aws4_request\n"
            . hash('sha256', $canonical);

        $dateKey = hash_hmac('sha256', $date, 'AWS4' . self::SECRET, true);
        $regionKey = hash_hmac('sha256', self::REGION, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $this->assertMatchesRegularExpression(
            '/^\d{8}T\d{6}Z$/',
            $timeStamp,
            'the transmitted x-amz-date must be an ISO-8601 basic UTC timestamp or AWS rejects the request regardless of the signature'
        );
        $this->assertArrayNotHasKey(
            'If-None-Match',
            $sentHeaders,
            'without the opt-in the transmitted PUT must not carry the conditional header'
        );
        $this->assertSame(
            $signature,
            $uploader->getSignature(),
            'the default canonical request must be byte-identical to the format S3 signed before the filter existed'
        );
        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=' . self::ACCESS . "/{$date}/" . self::REGION . "/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$signature}",
            $sentHeaders['Authorization'],
            'the transmitted Authorization header must serialize the credential scope, signed-header list, and signature exactly'
        );
        $this->assertSame(
            $contentHash,
            $sentHeaders['x-amz-content-sha256'],
            'the transmitted payload hash must match the hash that was signed'
        );
    }

    public function test_signature_matches_the_canonical_request_when_protection_is_on(): void
    {
        add_filter('fluent_cart/storage/s3_prevent_overwrite', '__return_true');

        // Same on-path capture as the protection-off case: the contract is
        // pinned on what wp_remote_request() transmits.
        $sentHeaders = null;

        add_filter('pre_http_request', function ($response, $args) use (&$sentHeaders) {
            if (($args['method'] ?? '') === 'PUT') {
                $sentHeaders = $args['headers'];

                return [
                    'headers'  => [],
                    'body'     => '',
                    'response' => ['code' => 200, 'message' => 'OK'],
                ];
            }

            return $response;
        }, 11, 2);

        $uploader = new S3FileUploader(
            self::SECRET, self::ACCESS, self::BUCKET, self::REGION, $this->fixturePath, self::KEY
        );
        $uploader->uploadFile();

        $this->assertIsArray($sentHeaders, 'uploadFile() must have performed the PUT whose headers we pin');

        $timeStamp = $sentHeaders['x-amz-date'];
        $date = substr($timeStamp, 0, 8);
        $contentHash = hash('sha256', file_get_contents($this->fixturePath));
        $host = self::BUCKET . '.s3.' . self::REGION . '.amazonaws.com';

        // if-none-match sorts between host and x-amz-content-sha256.
        $canonical = "PUT\n"
            . '/' . self::KEY . "\n\n"
            . "host:{$host}\n"
            . "if-none-match:*\n"
            . "x-amz-content-sha256:{$contentHash}\n"
            . "x-amz-date:{$timeStamp}\n\n"
            . "host;if-none-match;x-amz-content-sha256;x-amz-date\n"
            . $contentHash;

        $stringToSign = "AWS4-HMAC-SHA256\n{$timeStamp}\n{$date}/" . self::REGION . "/s3/aws4_request\n"
            . hash('sha256', $canonical);

        $dateKey = hash_hmac('sha256', $date, 'AWS4' . self::SECRET, true);
        $regionKey = hash_hmac('sha256', self::REGION, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $this->assertMatchesRegularExpression(
            '/^\d{8}T\d{6}Z$/',
            $timeStamp,
            'the transmitted x-amz-date must be an ISO-8601 basic UTC timestamp or AWS rejects the request regardless of the signature'
        );
        $this->assertSame(
            $signature,
            $uploader->getSignature(),
            'the canonical header block must carry if-none-match in sorted position and match the SignedHeaders list'
        );
        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=' . self::ACCESS . "/{$date}/" . self::REGION . "/s3/aws4_request, SignedHeaders=host;if-none-match;x-amz-content-sha256;x-amz-date, Signature={$signature}",
            $sentHeaders['Authorization'],
            'the transmitted Authorization header must serialize the credential scope, the extended signed-header list, and the signature exactly'
        );
        $this->assertSame(
            $contentHash,
            $sentHeaders['x-amz-content-sha256'],
            'the transmitted payload hash must match the hash that was signed'
        );
    }
}
