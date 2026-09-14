<?php

namespace FluentCart\App\Services\FileSystem\Drivers\S3;

use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Modules\StorageDrivers\S3\S3;
use FluentCart\App\Modules\StorageDrivers\S3\S3 as S3StorageDriver;
use FluentCart\App\Modules\StorageDrivers\S3\S3Settings;
use FluentCart\App\Services\FileSystem\Drivers\BaseDriver;
use FluentCart\Framework\Support\Arr;

class S3Driver extends BaseDriver
{
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $region;


    public function __construct(?string $dirPath = null, ?string $dirName = null)
    {
        parent::__construct($dirPath, $dirName);

        $getSettings = (new S3Settings())->get();

        $this->secretKey = Arr::get($getSettings, 'secret_key', '');
        $this->accessKey = Arr::get($getSettings, 'access_key', '');
        $this->bucket = S3Settings::resolveEffectiveBucket($getSettings);
        $this->region = Arr::get($getSettings, 'region', '');
        $this->storageDriver = new S3StorageDriver();
    }

    public function buckets()
    {
        return S3BucketList::get(
            $this->secretKey,
            $this->accessKey,
            $this->region
        );
    }

    public function uploadFile($localFilePath, $uploadToFilePath, $file, $params = [])
    {
        $fileSize = $file->toArray()['size_in_bytes'];

        $response = S3FileUploader::upload(
            $this->secretKey,
            $this->accessKey,
            $this->bucket,
            $this->region,
            $localFilePath,
            $uploadToFilePath
        );

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'message' => __('File Uploaded Successfully', 'fluent-cart'),
            'path'    => $response['path'],
            'file'    => [
                'driver' => 's3',
                'size'   => $fileSize,
                'bucket' => $this->bucket,
                'name'   => $response['path'],
            ],
        ];
    }


    public function listFiles(array $params = [])
    {
        return S3FileList::get(
            $this->secretKey,
            $this->accessKey,
            $this->bucket,
            $this->region,
            Arr::get($params, 'search', ''),
        );
    }

    protected function generatePresignedDownloadUrlOld(string $filePath, $expirationMinutes = 15, $bucket = null, $fileName = null): string
    {
        $this->bucket = $bucket ?? $this->bucket;
        $this->region = S3::getBucketRegion($this->bucket);


        if (empty($fileName)) {
            $fileName = basename($filePath);
        }

        $fileName = explode('_____fluent-cart_____', $fileName)[0];
        $fileName = explode('__fluent-cart__', $fileName)[0];

        $expirationMinutes = is_numeric($expirationMinutes) ? (int)$expirationMinutes : 15;
        $expires = time() + ($expirationMinutes * 60);

        $stringToSign = "GET\n\n\n{$expires}\n/{$this->bucket}/{$filePath}";

        $queryParams = [
            'AWSAccessKeyId' => $this->accessKey,
            'Expires' => $expires,
        ];

        if (!empty($fileName)) {
            $contentDisposition = "attachment; filename=\"{$fileName}\"";
            $queryParams['response-content-disposition'] = $contentDisposition;

            // For Signature V1, the parameter value is NOT URL-encoded in string-to-sign
            $stringToSign .= '?response-content-disposition=' . $contentDisposition;
        }

        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        $queryParams['Signature'] = $signature;

        $url = "https://{$this->bucket}.s3.{$this->region}.amazonaws.com/{$filePath}";
        $queryString = http_build_query($queryParams);

        return $url . '?' . $queryString;
    }

    protected function generatePresignedDownloadUrl(string $filePath, $expirationMinutes = 15, $bucket = null, $fileName = null): string
    {
        $this->bucket = $bucket ?? $this->bucket;
        $this->region = S3::getBucketRegion($this->bucket);

        if (empty($fileName)) {
            $fileName = basename($filePath);
        }

        $fileName = explode('_____fluent-cart_____', $fileName)[0];
        $fileName = explode('__fluent-cart__', $fileName)[0];

        $expirationMinutes = is_numeric($expirationMinutes) ? (int)$expirationMinutes : 15;
        $expiresInSeconds = $expirationMinutes * 60;

        // AWS Signature V4 requires ISO8601 format
        $dateTime = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        // Credential scope
        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";

        $hasDot = strpos($this->bucket, '.') !== false;

        // Canonical request components. Deliberately does not ltrim() leading
        // slashes: "foo", "/foo", and "//foo" are distinct S3 keys, and
        // stripping the slash would redirect the customer to the wrong object.
        $encodedFilePath = '/' . implode('/', array_map(
            'rawurlencode',
            explode('/', $filePath)
        ));

        // For dotted buckets, use path-style: include bucket in canonical URI
        if ($hasDot) {
            $canonicalUri = '/' . $this->bucket . $encodedFilePath;
            $host = "s3.{$this->region}.amazonaws.com";
        } else {
            $canonicalUri = $encodedFilePath;
            $host = "{$this->bucket}.s3.{$this->region}.amazonaws.com";
        }

        $canonicalQueryString = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $credentialScope,
            'X-Amz-Date' => $dateTime,
            'X-Amz-Expires' => $expiresInSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];

        if (!empty($fileName)) {
            $canonicalQueryString['response-content-disposition'] = $this->buildContentDispositionValue($fileName);
        }

        // Sort query parameters
        ksort($canonicalQueryString);

        // Build canonical query string
        $canonicalQueryStringEncoded = [];
        foreach ($canonicalQueryString as $key => $value) {
            $canonicalQueryStringEncoded[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        $canonicalQueryStringStr = implode('&', $canonicalQueryStringEncoded);

        // Canonical headers
        $canonicalHeaders = "host:{$host}\n";

        // Create canonical request
        $canonicalRequest = "GET\n{$canonicalUri}\n{$canonicalQueryStringStr}\n{$canonicalHeaders}\nhost\nUNSIGNED-PAYLOAD";

        // String to sign
        $stringToSign = "AWS4-HMAC-SHA256\n{$dateTime}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        // Calculate signature
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        // Build final URL
        $url = "https://{$host}{$canonicalUri}?{$canonicalQueryStringStr}&X-Amz-Signature={$signature}";

        return $url;
    }

    /**
     * Builds an RFC 6266/RFC 5987-compliant Content-Disposition value.
     * HTTP header values must be ISO-8859-1, so a raw Unicode filename (e.g.
     * Bengali) in a plain filename="..." parameter makes S3 reject the
     * request with "Header value cannot be represented using ISO-8859-1."
     * This supplies both an ASCII-safe filename="..." fallback for clients
     * that don't understand filename*, and a filename*=UTF-8''... parameter
     * carrying the exact intended Unicode name that modern browsers use.
     *
     * The returned raw value is assigned into $canonicalQueryString as-is;
     * the existing per-parameter rawurlencode() loop further down encodes
     * it exactly once for the query string, so this method must not
     * pre-encode the value itself (only the filename* attr-value, which is
     * a distinct RFC 5987 encoding layer, not query-string encoding).
     */
    private function buildContentDispositionValue(string $fileName): string
    {
        // Strip control/CR-LF characters so neither the raw header value nor
        // the filename* payload can inject extra header content.
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/', '', $fileName);
        if ($sanitized === null) {
            $sanitized = $fileName;
        }

        $asciiFileName = $this->buildAsciiFallbackFileName($sanitized);
        // RFC 6266 quoted-string: backslash-escape backslashes and quotes.
        $asciiFileName = addcslashes($asciiFileName, '\\"');

        $encodedFileName = rawurlencode($sanitized);

        return 'attachment; filename="' . $asciiFileName . '"; filename*=UTF-8\'\'' . $encodedFileName;
    }

    private function buildAsciiFallbackFileName(string $fileName): string
    {
        $extension = preg_replace('/[^\x20-\x7E]/', '', pathinfo($fileName, PATHINFO_EXTENSION));

        // Empty or dot-only names ("", ".", "..", "...") carry no meaningful
        // identity to preserve; keep the generic fallback for them.
        if (preg_match('/^\.*$/', $fileName)) {
            return $extension !== '' ? "download.{$extension}" : 'download';
        }

        // Already fully ASCII: preserve it exactly, including leading dots,
        // underscores, hyphens, and extension punctuation (e.g. ".env",
        // "_report_.txt", "report.a-b") - nothing here needs to change for
        // a client that ignores filename*.
        if (preg_replace('/[^\x20-\x7E]/', '', $fileName) === $fileName) {
            return $fileName;
        }

        // Otherwise the basename has non-ASCII content to strip. Anything
        // dropped here (Bengali, Japanese, Arabic, emoji…) is fully
        // preserved via filename*; legacy clients ignoring filename* never
        // see this fallback. Trim the separator characters (dots, dashes,
        // underscores, spaces) that content leaves dangling at the edges
        // once it's gone.
        $basename = pathinfo($fileName, PATHINFO_FILENAME);
        $basename = preg_replace('/[^\x20-\x7E]/', '', $basename);
        $basename = trim($basename, " \t\n\r\0\x0B-_.");

        if ($basename === '') {
            return $extension !== '' ? "download.{$extension}" : 'download';
        }

        return $extension !== '' ? "{$basename}.{$extension}" : $basename;
    }

    protected function retrieveFileForDownload(string $downloadableFilePath, $bucket = null)
    {
        $this->bucket = $bucket;
        $this->region = S3::getBucketRegion($this->bucket);
        $hasDot = strpos($this->bucket, '.') !== false;

        if ($hasDot) {
            $url = "https://s3.{$this->region}.amazonaws.com/{$this->bucket}/{$downloadableFilePath}";
        } else {
            $url = "https://{$this->bucket}.s3.{$this->region}.amazonaws.com/{$downloadableFilePath}";
        }

        $date = gmdate('D, d M Y H:i:s T');
        $signature = base64_encode(hash_hmac('sha1', "GET\n\n\n{$date}\n/{$this->bucket}/{$downloadableFilePath}", $this->secretKey, true));
        $response = wp_remote_get($url, [
            'headers' => [
                "Date"          => $date,
                "Authorization" => "AWS {$this->accessKey}:{$signature}",
            ],
        ]);

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        return $response['body'];
    }

    public function getSignedDownloadUrl(string $filePath, $bucket = null, $productDownload = null): string
    {
        $expirationMinutes = 7 * 24 * 60;
        apply_filters('fluent_cart/download_expiration_minutes', $expirationMinutes, [
            'file_path' => $filePath,
            'bucket'    => $bucket,
            'driver'    => 's3',
        ]);
        $fileName = null;
        if($productDownload instanceof ProductDownload){
            $fileName = $productDownload->file_name;
        }
        return $this->generatePresignedDownloadUrl($filePath, $expirationMinutes, $bucket, $fileName);
    }

    public function downloadFile(string $filePath, $fileName = null, $bucket = null)
    {
        $expirationMinutes = 7 * 24 * 60;
        apply_filters('fluent_cart/download_expiration_minutes', $expirationMinutes, [
            'file_path' => $filePath,
            'bucket'    => $bucket,
            'driver'    => 's3',
        ]);
        wp_redirect($this->generatePresignedDownloadUrl($filePath, $expirationMinutes, $bucket, $fileName));
        exit;
    }

    public function getFilePath(string $filePath, $fileName = null, $bucket = null)
    {
        return $this->retrieveFileForDownload($filePath, $bucket);
    }

    protected function getDefaultDirPath()
    {
        return $this->bucket;
    }

    public function deleteFile($filePath, $bucket = null)
    {
        $bucket = $bucket ?: $this->bucket;
        return S3FileDeleter::delete(
            $this->secretKey,
            $this->accessKey,
            $bucket,
            $this->region,
            $filePath
        );
    }
}
