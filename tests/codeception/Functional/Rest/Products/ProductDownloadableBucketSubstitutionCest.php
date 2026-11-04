<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\Meta;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Modules\StorageDrivers\S3\S3Settings;
use FluentCart\App\Services\FileSystem\DownloadService;
use FluentCart\Framework\Support\Arr;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing downloadable files", items 117a-117d.
 *
 * Security finding FC-SEC-04 (2026-08-25 assessment): a product editor could put any
 * bucket name in a downloadable file's `settings.bucket`. The controller stored it
 * verbatim and DownloadService fed it straight into cloud download signing, so the
 * store's credentials would sign a link for a private object in a sibling bucket that
 * the store never configured.
 *
 * The store configures exactly one bucket per driver (S3Settings::resolveEffectiveBucket,
 * R2Settings likewise); the admin file browser only ever echoes that same bucket back.
 * So the bucket is server state, never request state — both the write path (this
 * controller) and the read path (DownloadService) now resolve it from driver settings
 * and ignore whatever the row or the request carries.
 */
class ProductDownloadableBucketSubstitutionCest
{
    private const STORE_BUCKET = 'fct-store-bucket';

    private const SIBLING_BUCKET = 'fct-private-sibling-bucket';

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
        $this->configureS3Driver();
    }

    public function _after(FunctionalTester $I): void
    {
        S3Settings::$cachedSettings = null;
    }

    /**
     * Enable the S3 driver against a known bucket. S3::isEnabled() requires
     * is_active=yes plus a resolvable bucket, and the driver registry only
     * exposes enabled drivers.
     */
    private function configureS3Driver(): void
    {
        S3Settings::$cachedSettings = null;

        fluent_cart_update_option('fluent_cart_storage_settings_s3', [
            'is_active'   => 'yes',
            'auth_method' => 'db',
            'access_key'  => 'AKIAFCTTESTACCESSKEY',
            'secret_key'  => 'fct-test-secret-key',
            'bucket'      => self::STORE_BUCKET,
            'region'      => 'us-east-1',
        ]);

        S3Settings::$cachedSettings = null;

        // S3::getBucketRegion() reads this cache before reaching for the network, so
        // seeding both buckets keeps the signing oracle deterministic and offline.
        Meta::query()->create([
            'meta_key'   => 'fct_s3_region',
            'meta_value' => [
                self::STORE_BUCKET   => 'us-east-1',
                self::SIBLING_BUCKET => 'us-east-1',
            ],
        ]);
    }

    private function attachPayload(array $overrides = []): array
    {
        return [
            'downloadable_files' => [
                array_merge([
                    'title'                => 'Bucket Check',
                    'type'                 => 'file',
                    'driver'               => 's3',
                    'file_name'            => 'sentinel.txt',
                    'file_path'            => 'sentinel.txt',
                    'file_url'             => 'sentinel.txt',
                    'file_size'            => '20',
                    'serial'               => 1,
                    'product_variation_id' => [],
                    'settings'             => [
                        'download_limit'  => -1,
                        'download_expiry' => -1,
                        'bucket'          => self::SIBLING_BUCKET,
                    ],
                ], $overrides),
            ],
        ];
    }

    /**
     * Spec: Cases/Functional/products.md [117a]
     *   "A product editor cannot attach a downloadable file pointing at a bucket the store
     *    has not configured — the stored bucket is the store's own."
     */
    public function attachingDownloadableFileCannotSubstituteAnUnconfiguredBucket(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BucketSub117a']);

        [$status, $body] = $I->post(
            '/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files',
            $this->attachPayload()
        );

        Assert::assertSame(200, $status, 'attaching a legitimate S3 file must still succeed. Body: ' . wp_json_encode($body));

        $row = ProductDownload::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($row, 'the downloadable file must be persisted');

        $storedBucket = (string) Arr::get((array) $row->settings, 'bucket', '');
        Assert::assertNotSame(
            self::SIBLING_BUCKET,
            $storedBucket,
            'the caller-supplied sibling bucket must never reach fct_product_downloads.settings'
        );
        Assert::assertSame(
            self::STORE_BUCKET,
            $storedBucket,
            'settings.bucket must be replaced with the bucket resolved from the S3 driver settings'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [117b]
     *   "A product editor cannot substitute an unconfigured bucket by editing an existing
     *    downloadable file either."
     */
    public function updatingDownloadableFileCannotSubstituteAnUnconfiguredBucket(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BucketSub117b']);

        $I->post(
            '/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files',
            $this->attachPayload(['settings' => ['download_limit' => -1, 'download_expiry' => -1]])
        );

        $row = ProductDownload::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($row, 'the downloadable file must be persisted before the update leg');

        [$status, $body] = $I->put('/fluent-cart/v2/products/' . $row->id . '/update', [
            'title'                => 'Bucket Check Updated',
            'type'                 => 'file',
            'driver'               => 's3',
            'file_name'            => 'sentinel.txt',
            'file_path'            => 'sentinel.txt',
            'file_url'             => 'sentinel.txt',
            'serial'               => 1,
            'product_variation_id' => [],
            'settings'             => [
                'download_limit'  => -1,
                'download_expiry' => -1,
                'bucket'          => self::SIBLING_BUCKET,
            ],
        ]);

        Assert::assertSame(200, $status, 'editing a downloadable file must still succeed. Body: ' . wp_json_encode($body));

        $updated = ProductDownload::query()->find($row->id);
        $storedBucket = (string) Arr::get((array) $updated->settings, 'bucket', '');
        Assert::assertSame(
            self::STORE_BUCKET,
            $storedBucket,
            'the update path must resolve settings.bucket server-side, not echo the request value'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [117c]
     *   "A downloadable file already carrying a foreign bucket is still signed against the
     *    store's own bucket."
     *
     * Rows written before the write-path fix keep the attacker's bucket, so the read path
     * must resolve it too — otherwise an existing poisoned row stays exploitable forever.
     */
    public function signedDownloadUrlIgnoresAForeignBucketStoredOnTheRow(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BucketSub117c']);

        // Write the poisoned row directly: the controller now refuses to create one.
        $downloadId = (int) ProductDownload::query()->insertGetId([
            'post_id'              => (int) $product->ID,
            'product_variation_id' => '[]',
            'download_identifier'  => 'fct-bucket-sub-117c',
            'title'                => 'Legacy Poisoned Row',
            'type'                 => 'file',
            'driver'               => 's3',
            'file_name'            => 'sentinel.txt',
            'file_path'            => 'sentinel.txt',
            'file_url'             => 'sentinel.txt',
            'file_size'            => '20',
            'serial'               => 1,
            'settings'             => wp_json_encode(['bucket' => self::SIBLING_BUCKET]),
        ]);

        $row = ProductDownload::query()->find($downloadId);
        Assert::assertSame(
            self::SIBLING_BUCKET,
            (string) Arr::get((array) $row->settings, 'bucket', ''),
            'fixture guard: the row under test must really carry the foreign bucket'
        );

        $url = DownloadService::getDownloadableUrlFromDownload($row->toArray());

        Assert::assertStringNotContainsString(
            self::SIBLING_BUCKET,
            $url,
            'the presigned URL must not address the foreign bucket stored on the row. URL: ' . $url
        );
        Assert::assertStringContainsString(
            self::STORE_BUCKET,
            $url,
            'the presigned URL must address the bucket configured in the S3 driver settings. URL: ' . $url
        );
    }

    /**
     * Spec: Cases/Functional/products.md [117d]
     *   "A product editor cannot attach a downloadable file to a storage driver the store
     *    has not enabled."
     *
     * Without this the driver name is a second way to reach an unintended backend: an
     * unresolvable driver would leave settings.bucket unconstrained.
     */
    public function attachingDownloadableFileForADisabledDriverIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BucketSub117d']);

        [$status, $body] = $I->post(
            '/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files',
            $this->attachPayload(['driver' => 'definitely-not-a-driver'])
        );

        Assert::assertSame(
            422,
            $status,
            'an unknown storage driver must be rejected with 422. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            0,
            ProductDownload::query()->where('post_id', $product->ID)->count(),
            'a rejected attach must persist no fct_product_downloads row'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [117e]
     *   "Attaching a downloadable file to local storage keeps working and stores no bucket."
     *
     * The control leg: the bucket resolution must not break the driver that has none.
     */
    public function attachingLocalDownloadableFileStillWorksAndStoresNoBucket(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BucketSub117e']);

        [$status, $body] = $I->post(
            '/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files',
            $this->attachPayload(['driver' => 'local'])
        );

        Assert::assertSame(200, $status, 'attaching a local file must still succeed. Body: ' . wp_json_encode($body));

        $row = ProductDownload::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($row, 'the local downloadable file must be persisted');
        Assert::assertSame(
            '',
            (string) Arr::get((array) $row->settings, 'bucket', ''),
            'the local driver has no bucket, so settings.bucket must be emptied rather than echoed'
        );
    }
}
