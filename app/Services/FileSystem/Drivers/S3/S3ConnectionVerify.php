<?php

namespace FluentCart\App\Services\FileSystem\Drivers\S3;

class S3ConnectionVerify
{
    private const DEFAULT_REGION = 'us-east-1';
    private const MAX_REGION_RETRIES = 2;

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
    private ?string $sessionToken = null;
    private string $payloadBody = '';
    private ?string $contentMD5 = null;
    private int $regionRetryCount = 0;

    public static function verify(string $secret, string $accessKey, ?string $sessionToken = null, string $bucket = '', string $region = 'us-east-1')
    {
        $validation = self::validateRequestInputs($region, $bucket);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $self = new static($secret, $accessKey, $sessionToken, $region);
        if ($bucket) {
            $self->bucket = $bucket;
            $self->requestUrl = $self->getBucketRequestUrl('?max-keys=1');
            $self->httpMethod = 'GET';
        }

        return $self->testConnection();
    }

    public static function checkBucketExistence(string $secret, string $accessKey, ?string $sessionToken = null, string $bucket = '', string $region = 'us-east-1')
    {
        $validation = self::validateRequestInputs($region, $bucket);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $self = new static($secret, $accessKey, $sessionToken, $region);
        $self->bucket = $bucket;
        $self->requestUrl = $self->getBucketRequestUrl('?max-keys=1');
        $self->httpMethod = 'GET';

        // Use a simplified test connection that doesn't check public access settings
        return $self->testBucketConnection();
    }

    public static function updatePublicAccessBlock(string $secret, string $accessKey, ?string $sessionToken = null, string $bucket = '', bool $enable = true, string $region = 'us-east-1')
    {
        $validation = self::validateRequestInputs($region, $bucket);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $self = new static($secret, $accessKey, $sessionToken, $region);
        $self->bucket = $bucket;
        return $self->setPublicAccessBlock($enable);
    }

    public static function updateObjectOwnership(string $secret, string $accessKey, ?string $sessionToken = null, string $bucket = '', bool $enforce = true, string $region = 'us-east-1')
    {
        $validation = self::validateRequestInputs($region, $bucket);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $self = new static($secret, $accessKey, $sessionToken, $region);
        $self->bucket = $bucket;
        return $self->setObjectOwnership($enforce);
    }

    public static function checkSecuritySettings(string $secret, string $accessKey, ?string $sessionToken = null, string $bucket = '', string $region = 'us-east-1')
    {
        $validation = self::validateRequestInputs($region, $bucket);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $self = new static($secret, $accessKey, $sessionToken, $region);
        $self->bucket = $bucket;

        $publicAccess = $self->checkPublicAccessBlock();
        $objectOwnership = $self->checkObjectOwnership();

        return [
            'block_public_access' => !is_wp_error($publicAccess) ? $publicAccess : false,
            'object_ownership'    => !is_wp_error($objectOwnership) ? $objectOwnership : false
        ];
    }

    public function __construct(string $secret, string $accessKey, ?string $sessionToken = null, string $region = 'us-east-1')
    {
        $this->secretKey = $secret;
        $this->accessKey = $accessKey;
        $this->sessionToken = $sessionToken;
        $this->region = $region;
        $this->bucket = '';

        $this->httpMethod = "GET";
        $this->timeStamp = gmdate('Ymd\THis\Z');
        $this->date = substr($this->timeStamp, 0, 8);
        $this->requestUrl = $this->getServiceListUrl();

        // Signature generation happens in testConnection now or we regenerate it there if we change params
    }

    public function testConnection()
    {
        add_filter('http_request_timeout', function () {
            return 30;
        });

        // Regenerate signature because request params might have changed (if bucket was set)
        $this->signature = $this->generateSignature();

        // Ensure headers are generated with new info
        $headers = $this->getHeaders();

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $headers
        ]);

        $responseCode = wp_remote_retrieve_response_code($response);

        // Success check
        if ($responseCode >= 200 && $responseCode < 300) {
            $data = [
                'message' => __('Successfully verified S3 connection!', 'fluent-cart'),
                'code'    => $responseCode,
                'region'  => $this->region
            ];

            if ($this->bucket) {
                // Check Public Access Block
                $publicAccess = $this->checkPublicAccessBlock();
                if (!is_wp_error($publicAccess)) {
                    $data['block_public_access'] = $publicAccess;
                }

                // Check Object Ownership
                $objectOwnership = $this->checkObjectOwnership();
                if (!is_wp_error($objectOwnership)) {
                    $data['object_ownership'] = $objectOwnership;
                }
            }

            return $data;
        }

        // Check for Region Mismatch (301 PermanentRedirect or 400 with specific headers)
        $detectedRegion = wp_remote_retrieve_header($response, 'x-amz-bucket-region');
        if (($responseCode == 301 || $responseCode == 400) && $detectedRegion && $detectedRegion !== $this->region) {
            $retryResult = $this->retryWithRegion($detectedRegion, '?max-keys=1');
            if (is_wp_error($retryResult)) {
                return $retryResult;
            }

            return $this->testConnection();
        }

        // Error Handling
        $error_message = __('Invalid S3 credentials', 'fluent-cart');
        $errorCode = 'invalid_credentials';

        $responseBody = wp_remote_retrieve_body($response);

        if (!empty($responseBody)) {
             $xml = simplexml_load_string($responseBody);
             if ($xml) {
                 $awsCode = isset($xml->Code) ? (string)$xml->Code : '';
                 $awsMessage = isset($xml->Message) ? (string) $xml->Message : '';

                 // Check specific AWS error codes
                 if ($awsCode === 'NoSuchBucket') {
                     $msg = $awsMessage ?: sprintf(__('Bucket (%s) does not exist.', 'fluent-cart'), $this->bucket);
                     return new \WP_Error('bucket_not_found', $msg);
                 }

                 if ($awsCode === 'PermanentRedirect') {
                     $error_message = $awsMessage ?: __('The bucket you are attempting to access must be addressed using the specified endpoint.', 'fluent-cart');
                     if ($detectedRegion) {
                         $error_message .= ' ' . sprintf(__('Correct Region: %s', 'fluent-cart'), $detectedRegion);
                     }
                     return new \WP_Error('region_mismatch', $error_message);
                 }

                 if ($awsCode === 'AccessDenied' || $responseCode == 403) {
                      // If we are checking credentials only (no bucket), generic invalid strings
                      if (!$this->bucket) {
                          $error_message = $awsMessage ?: __('Invalid S3 credentials', 'fluent-cart');
                      } else {
                          $error_message = $awsMessage ?: sprintf(__('Access forbidden to the configured bucket (%s). Check permissions.', 'fluent-cart'), $this->bucket);
                          $errorCode = 'bucket_forbidden';
                      }
                 }

                 // Use AWS message if available and we haven't set a custom one
                 if ($awsMessage && $error_message === __('Invalid S3 credentials', 'fluent-cart') && $errorCode === 'invalid_credentials') {
                      $error_message = $awsMessage;
                 }

                 if (strpos($error_message, 'User:') !== false) {
                     $error_message = __('Your IAM user does not have permission to use S3 buckets', 'fluent-cart');
                 }
             }
        }

        // If we didn't get XML or couldn't parse it, fallback to status codes
        if ($this->bucket) {
            if ($responseCode == 404) {
                return new \WP_Error('bucket_not_found', sprintf(__('Media cannot be offloaded because a bucket with the configured name (%s) does not exist.', 'fluent-cart'), $this->bucket));
            }
            if ($responseCode == 403 && $errorCode === 'invalid_credentials') {
                 // Use more specific error if we haven't already
                  return new \WP_Error('bucket_forbidden', sprintf(__('Access forbidden to the configured bucket (%s). Check permissions.', 'fluent-cart'), $this->bucket));
            }
        }

        return new \WP_Error($responseCode, $error_message);
    }

    public function testBucketConnection()
    {
        add_filter('http_request_timeout', function () {
            return 30;
        });

        // Regenerate signature because request params might have changed
        $this->signature = $this->generateSignature();
        $headers = $this->getHeaders();

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $headers
        ]);

        $responseCode = wp_remote_retrieve_response_code($response);

        // Success check
        if ($responseCode >= 200 && $responseCode < 300) {
            return [
                'message' => __('Successfully verified bucket!', 'fluent-cart'),
                'code'    => $responseCode,
                'region'  => $this->region
            ];
        }

        // Check for Region Mismatch (301 PermanentRedirect or 400 with specific headers)
        $detectedRegion = wp_remote_retrieve_header($response, 'x-amz-bucket-region');

        // If header is missing or we are in a redirect loop, try GetBucketLocation API
        if (($responseCode == 301 || $responseCode == 400) && (!$detectedRegion || $detectedRegion !== $this->region)) {
            // Try explicit GetBucketLocation call
            $locationRegion = $this->getBucketLocation();
            if ($locationRegion && $locationRegion !== $this->region) {
                $detectedRegion = $locationRegion;
            }

            // Fallback: Try HEAD request (unsigned) which often returns region header even on 400/403
            if (!$detectedRegion) {
                $headUrl = $this->getBucketBaseUrlForRegion(self::DEFAULT_REGION);
                $headResponse = wp_remote_head($headUrl);
                if (!is_wp_error($headResponse)) {
                    $headRegion = wp_remote_retrieve_header($headResponse, 'x-amz-bucket-region');
                    if ($headRegion) {
                        $detectedRegion = $headRegion;
                    }
                }
            }
        }


        if (($responseCode == 301 || $responseCode == 400) && $detectedRegion && $detectedRegion !== $this->region) {
            $retryResult = $this->retryWithRegion($detectedRegion, '?max-keys=1');
            if (is_wp_error($retryResult)) {
                return $retryResult;
            }

            return $this->testBucketConnection();
        }

        // Error Handling reuse
        $error_message = __('Invalid S3 credentials', 'fluent-cart');
        $errorCode = 'invalid_credentials';

        $responseBody = wp_remote_retrieve_body($response);

        if (!empty($responseBody)) {
             $xml = simplexml_load_string($responseBody);
             if ($xml) {
                 $awsCode = isset($xml->Code) ? (string)$xml->Code : '';
                 $awsMessage = isset($xml->Message) ? (string) $xml->Message : '';

                 if ($awsCode === 'NoSuchBucket') {
                     $msg = $awsMessage ?: sprintf(__('Bucket (%s) does not exist.', 'fluent-cart'), $this->bucket);
                     return new \WP_Error('bucket_not_found', $msg);
                 }

                 if ($awsCode === 'PermanentRedirect') {
                     // Ensure we display the endpoint message if we couldn't auto-redirect
                     $error_message = $awsMessage ?: __('The bucket you are attempting to access must be addressed using the specified endpoint.', 'fluent-cart');
                     // We should ideally tell the user the correct region if we know it
                     if ($detectedRegion) {
                         $error_message .= ' ' . sprintf(__('Correct Region: %s', 'fluent-cart'), $detectedRegion);
                     }
                     return new \WP_Error('region_mismatch', $error_message);
                 }

                 if ($awsCode === 'AccessDenied' || $responseCode == 403) {
                      $error_message = $awsMessage ?: sprintf(__('Access forbidden to the configured bucket (%s). Check permissions.', 'fluent-cart'), $this->bucket);
                 }

                 if ($awsMessage && $error_message === __('Invalid S3 credentials', 'fluent-cart')) {
                      $error_message = $awsMessage;
                 }
             }
        }

        if ($responseCode == 404) {
            return new \WP_Error('bucket_not_found', sprintf(__('Bucket (%s) does not exist.', 'fluent-cart'), $this->bucket));
        }

        return new \WP_Error($responseCode, $error_message);
    }

    public function checkPublicAccessBlock()
    {
        // Save current state
        $originalRequestUrl = $this->requestUrl;
        $originalHttpMethod = $this->httpMethod;
        $originalContentMD5 = $this->contentMD5;

        // Set up for GET request (read public access block)
        $this->requestUrl = $this->getBucketRequestUrl('/?publicAccessBlock');
        $this->httpMethod = 'GET';
        $this->contentMD5 = null; // No body for GET request

        // Regenerate signature because request params changed
        $this->signature = $this->generateSignature();

        // Generate headers with new signature
        $headers = $this->getHeaders();

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $headers
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Restore original values
        $this->requestUrl = $originalRequestUrl;
        $this->httpMethod = $originalHttpMethod;
        $this->contentMD5 = $originalContentMD5;

        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode >= 200 && $responseCode < 300) {
            $body = wp_remote_retrieve_body($response);
            $xml = simplexml_load_string($body);

            // AWS returns root element <PublicAccessBlockConfiguration>
            // $xml IS the configuration object, not a wrapper containing it.
            if ($xml) {
                // Check if all block settings are enabled
                $blockPublicAcls = (string)$xml->BlockPublicAcls === 'true';
                $ignorePublicAcls = (string)$xml->IgnorePublicAcls === 'true';
                $blockPublicPolicy = (string)$xml->BlockPublicPolicy === 'true';
                $restrictPublicBuckets = (string)$xml->RestrictPublicBuckets === 'true';

                // If any is true, we can consider it as having some blocking, but usually "Block All" means all are true.
                return $blockPublicAcls && $ignorePublicAcls && $blockPublicPolicy && $restrictPublicBuckets;
            }
        } else if ($responseCode == 404) {
             // 404 on ?publicAccessBlock means no configuration exists, so it's disabled.
             return false;
        }

        return new \WP_Error('public_access_check_failed', 'Could not check public access settings');
    }

    public function setPublicAccessBlock(bool $enable)
    {
        // Save current requestUrl and method
        $originalRequestUrl = $this->requestUrl;
        $originalHttpMethod = $this->httpMethod;

        $this->requestUrl = $this->getBucketRequestUrl('/?publicAccessBlock');
        $this->httpMethod = 'PUT';

        $setting = $enable ? 'true' : 'false';
        $this->payloadBody = <<<XML
<PublicAccessBlockConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
   <BlockPublicAcls>{$setting}</BlockPublicAcls>
   <IgnorePublicAcls>{$setting}</IgnorePublicAcls>
   <BlockPublicPolicy>{$setting}</BlockPublicPolicy>
   <RestrictPublicBuckets>{$setting}</RestrictPublicBuckets>
</PublicAccessBlockConfiguration>
XML;

        $this->signature = $this->generateSignature();
        $headers = $this->getHeaders();
        $headers['Content-Type'] = 'application/xml';

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $headers,
            'body'    => $this->payloadBody
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Restore
        $this->requestUrl = $originalRequestUrl;
        $this->httpMethod = $originalHttpMethod;
        $this->payloadBody = '';

        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode >= 200 && $responseCode < 300) {
            return true;
        }

        $body = wp_remote_retrieve_body($response);
        $xml = simplexml_load_string($body);
        $errorMsg = $xml && isset($xml->Message) ? (string)$xml->Message : $body;
        return new \WP_Error('s3_update_failed', 'Failed to update S3 Public Access Block: ' . $errorMsg);
    }

    public function checkObjectOwnership()
    {
        $originalRequestUrl = $this->requestUrl;
        $originalHttpMethod = $this->httpMethod;
        $originalContentMD5 = $this->contentMD5;

        // Set up for GET request (read ownership controls)
        $this->requestUrl = $this->getBucketRequestUrl('/?ownershipControls');
        $this->httpMethod = 'GET';
        $this->contentMD5 = null; // No body for GET request

        $this->signature = $this->generateSignature();
        $headers = $this->getHeaders();

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $headers
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Restore original values
        $this->requestUrl = $originalRequestUrl;
        $this->httpMethod = $originalHttpMethod;
        $this->contentMD5 = $originalContentMD5;

        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode >= 200 && $responseCode < 300) {
            $body = wp_remote_retrieve_body($response);
            $xml = simplexml_load_string($body);

            // AWS response format: <OwnershipControls><Rule><ObjectOwnership>VALUE</ObjectOwnership></Rule></OwnershipControls>
            if ($xml && isset($xml->Rule->ObjectOwnership)) {
                $ownership = (string)$xml->Rule->ObjectOwnership;
                // BucketOwnerEnforced = Enforced (ACLs disabled)
                // BucketOwnerPreferred = ACLs enabled (usually)
                return $ownership === 'BucketOwnerEnforced';
            }
        } else if ($responseCode == 404) {
             // If ownership controls are not found, it implies legacy behavior (ObjectWriter), which means ACLs are enabled (Not Enforced).
             return false;
        }

        return new \WP_Error('ownership_check_failed', 'Could not check object ownership settings');
    }

    public function getBucketLocation()
    {
        $originalRequestUrl = $this->requestUrl;
        $originalHttpMethod = $this->httpMethod;
        $originalContentMD5 = $this->contentMD5;

        // S3 API: GET /?location
        // This request must be signed, but standard auth works.
        // It returns LocationConstraint XML.

        $this->requestUrl = $this->getBucketRequestUrl('/?location');

        $this->httpMethod = 'GET';
        $this->contentMD5 = null;

        $this->signature = $this->generateSignature();
        $headers = $this->getHeaders();

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $headers
        ]);

        // Restore
        $this->requestUrl = $originalRequestUrl;
        $this->httpMethod = $originalHttpMethod;
        $this->contentMD5 = $originalContentMD5;

        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode >= 200 && $responseCode < 300) {
            $body = wp_remote_retrieve_body($response);
            $xml = simplexml_load_string($body);
            if ($xml) {
                $location = (string)$xml ?: self::DEFAULT_REGION;

                return S3InputValidator::isValidRegion($location) ? $location : null;
            }
        }

        return null; // Could not determine
    }

    public function setObjectOwnership(bool $enforce)
    {
        $originalRequestUrl = $this->requestUrl;
        $originalHttpMethod = $this->httpMethod;

        $this->requestUrl = $this->getBucketRequestUrl('/?ownershipControls');
        $this->httpMethod = 'PUT';

        // BucketOwnerEnforced = Enforced (ACLs disabled)
        // BucketOwnerPreferred = ACLs enabled
        $setting = $enforce ? 'BucketOwnerEnforced' : 'BucketOwnerPreferred';

        $this->payloadBody = '<OwnershipControls xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Rule><ObjectOwnership>' . $setting . '</ObjectOwnership></Rule></OwnershipControls>';

        // Set Content-MD5 property for signature generation
        $this->contentMD5 = base64_encode(md5($this->payloadBody, true));

        $this->signature = $this->generateSignature();
        $headers = $this->getHeaders();

        // Add Content-Type header
        $headers['Content-Type'] = 'application/xml';

        $response = wp_remote_request($this->requestUrl, [
            'method'  => $this->httpMethod,
            'headers' => $headers,
            'body'    => $this->payloadBody
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $this->requestUrl = $originalRequestUrl;
        $this->httpMethod = $originalHttpMethod;
        $this->payloadBody = '';
        $this->contentMD5 = null; // Reset MD5 after PUT operation

        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode >= 200 && $responseCode < 300) {
            return true;
        }

        $body = wp_remote_retrieve_body($response);
        $xml = simplexml_load_string($body);
        $errorMsg = $xml && isset($xml->Message) ? (string)$xml->Message : $body;
        return new \WP_Error('s3_update_failed_ownership', 'Failed to update S3 Object Ownership: ' . $errorMsg);
    }

    private static function validateRequestInputs(string $region, string $bucket = '')
    {
        $regionValidation = S3InputValidator::validateRegion($region);
        if (is_wp_error($regionValidation)) {
            return $regionValidation;
        }

        if ($bucket !== '') {
            $bucketValidation = S3InputValidator::validateBucket($bucket);
            if (is_wp_error($bucketValidation)) {
                return $bucketValidation;
            }
        }

        return true;
    }

    private function getServiceListUrl(): string
    {
        $host = $this->region === self::DEFAULT_REGION
            ? 's3.amazonaws.com'
            : "s3.{$this->region}.amazonaws.com";

        return 'https://' . $host . '/?list-type=2&encoding-type=url&max-keys=1';
    }

    private function getBucketBaseUrlForRegion(string $region): string
    {
        if ($this->bucket === '') {
            return $this->getServiceListUrl();
        }

        if (strpos($this->bucket, '.') !== false) {
            $host = $region === self::DEFAULT_REGION ? 's3.amazonaws.com' : "s3.{$region}.amazonaws.com";
            return 'https://' . $host . '/' . $this->bucket . '/';
        }

        $host = $region === self::DEFAULT_REGION
            ? "{$this->bucket}.s3.amazonaws.com"
            : "{$this->bucket}.s3.{$region}.amazonaws.com";

        return 'https://' . $host;
    }

    private function getBucketRequestUrl(string $querySuffix = '?max-keys=1'): string
    {
        return $this->getBucketBaseUrlForRegion($this->region) . $querySuffix;
    }

    private function retryWithRegion(string $detectedRegion, string $querySuffix)
    {
        $regionValidation = S3InputValidator::validateRegion($detectedRegion);
        if (is_wp_error($regionValidation)) {
            return $regionValidation;
        }

        if ($this->regionRetryCount >= self::MAX_REGION_RETRIES) {
            return new \WP_Error(
                'region_retry_limit',
                __('Unable to determine the correct S3 region after multiple attempts. Please verify the bucket region and try again.', 'fluent-cart')
            );
        }

        $this->regionRetryCount++;
        $this->region = $detectedRegion;
        $this->requestUrl = $this->bucket ? $this->getBucketRequestUrl($querySuffix) : $this->getServiceListUrl();

        return true;
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

        // Logic to construct query params correctly for different requests
        // If verify() was called with a bucket, we set requestUrl with ?max-keys=1 on the root of the bucket path
        // But if we are checking public access block, the query is different.
        // We need to handle this based on the current requestUrl or context.
        // Simplified approach: rely on the fact that for the main check, it IS max-keys=1.

        if ($this->bucket) {
             // For bucket check (GET /?max-keys=1)
             if (strpos($this->requestUrl, 'max-keys=1') !== false) {
                 $canonicalQuery = "max-keys=1";
             } else if (strpos($this->requestUrl, 'publicAccessBlock') !== false) {
                 $canonicalQuery = "publicAccessBlock=";
             } else if (strpos($this->requestUrl, 'ownershipControls') !== false) {
                 $canonicalQuery = "ownershipControls=";
             } else if (strpos($this->requestUrl, 'location') !== false) {
                 $canonicalQuery = "location=";
             }
        }

        // Build canonical headers - MUST be sorted alphabetically by header name
        $canonicalHeaders = "";

        if ($this->contentMD5) {
            $canonicalHeaders .= "content-md5:{$this->contentMD5}\n";
        }

        $canonicalHeaders .= "host:{$this->getHost()}\n";
        $canonicalHeaders .= "x-amz-content-sha256:{$this->getContentHash()}\n";
        $canonicalHeaders .= "x-amz-date:{$this->timeStamp}\n";

        if ($this->sessionToken) {
            $canonicalHeaders .= "x-amz-security-token:{$this->sessionToken}\n";
        }

        // Signed headers - MUST match the order of canonical headers (alphabetically sorted)
        $signedHeaders = "";
        if ($this->contentMD5) {
            $signedHeaders .= "content-md5;";
        }
        $signedHeaders .= "host;x-amz-content-sha256;x-amz-date";

        if ($this->sessionToken) {
            $signedHeaders .= ";x-amz-security-token";
        }

        $path = "/"; // Default path
        if ($this->isPathStyle()) {
             $path = "/{$this->bucket}/";
        }

        // Canonical request format per AWS Signature v4:
        // HTTPMethod\n
        // CanonicalURI\n
        // CanonicalQueryString\n
        // CanonicalHeaders\n
        // SignedHeaders\n
        // HashedPayload
        $payload = "$this->httpMethod\n" .
            "{$path}\n" .
            "{$canonicalQuery}\n" .
            "{$canonicalHeaders}\n" .
            "{$signedHeaders}\n" .
            $this->getContentHash();

        return $payload;
    }

    private function getHost(): string
    {
        // Parse host from the request URL to support virtual hosted style buckets
        return parse_url($this->requestUrl, PHP_URL_HOST) ?: "s3.amazonaws.com";
    }

    private function getContentHash(): string
    {
        return hash($this->hashAlgorithm, $this->payloadBody);
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
        $headers = [
            "x-amz-content-sha256" => $this->getContentHash(),
            'x-amz-date'           => $this->timeStamp,
        ];

        if ($this->contentMD5) {
            $headers['content-md5'] = $this->contentMD5;
        }

        if ($this->sessionToken) {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }

        $signedHeaders = "";

        if ($this->contentMD5) {
            $signedHeaders .= "content-md5;";
        }

        $signedHeaders .= "host;x-amz-content-sha256;x-amz-date";

        if ($this->sessionToken) {
            $signedHeaders .= ";x-amz-security-token";
        }

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$this->date}/{$this->region}/s3/aws4_request, SignedHeaders={$signedHeaders}, Signature={$this->signature}";

        return $headers;
    }

    private function isPathStyle()
    {
        return $this->bucket && strpos($this->bucket, '.') !== false;
    }
}
