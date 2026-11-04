<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductDownload;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing downloadable files" section, items 109-110, 117, 229-231.
 */
class AttachProductDownloadablesCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [109]
     *   "Admin can attach downloadable files to a product."
     */
    public function adminCanAttachDownloadableFilesToProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Attach109']);

        $payload = [
            'downloadable_files' => [
                [
                    'title'                => 'Manual PDF',
                    'type'                 => 'local',
                    'driver'               => 'local',
                    'file_name'            => 'manual-109.pdf',
                    'file_path'            => '/uploads/manual-109.pdf',
                    'file_url'             => 'http://example.com/manual-109.pdf',
                    'file_size'            => '2048',
                    'serial'               => 1,
                    'product_variation_id' => [],
                    'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
                ],
            ],
        ];

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files', $payload);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('downloadable_files', $body, 'attach response must surface the persisted downloadable_files list');

        $rows = ProductDownload::query()->where('post_id', $product->ID)->get();
        Assert::assertCount(1, $rows, 'sync must persist exactly one fct_product_downloads row for the submitted file');
        $row = $rows->first();
        Assert::assertSame('Manual PDF', (string) $row->title, 'persisted row must echo the submitted title');
        Assert::assertSame('local', (string) $row->driver, 'persisted row must echo the submitted driver');
        Assert::assertSame('manual-109.pdf', (string) $row->file_name, 'persisted row must echo the submitted file_name');
        Assert::assertSame((int) $product->ID, (int) $row->post_id, 'persisted row must be scoped to the requested product');

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(1, (int) $detail->manage_downloadable, 'attaching downloadable files must flip ProductDetail.manage_downloadable to 1');
    }

    /**
     * Spec: Cases/Functional/products.md [110]
     *   "Admin sees a unique download identifier automatically assigned to each downloadable file."
     */
    public function adminSeesUniqueDownloadIdentifierAutomaticallyAssignedToEachDownloadableFile(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'AutoUuid110']);

        $files = [];
        for ($i = 1; $i <= 2; $i++) {
            $files[] = [
                'title'                => 'File' . $i,
                'type'                 => 'local',
                'driver'               => 'local',
                'file_name'            => 'auto-' . $i . '.zip',
                'file_path'            => '/uploads/auto-' . $i . '.zip',
                'file_url'             => 'http://example.com/auto-' . $i . '.zip',
                'serial'               => $i,
                'product_variation_id' => [],
                'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
            ];
        }

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files', [
            'downloadable_files' => $files,
        ]);

        $I->seeResponseCodeIs(200);
        $rows = ProductDownload::query()->where('post_id', $product->ID)->orderBy('id')->get();
        Assert::assertCount(2, $rows, 'sync must persist exactly two rows for two submitted files');

        $persistedIdentifiers = [];
        foreach ($rows as $row) {
            $id = (string) $row->download_identifier;
            Assert::assertNotEmpty($id, 'every persisted row must carry a non-empty auto-assigned download_identifier');
            Assert::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $id, 'download_identifier must be a UUID-shaped string (36 chars, hex+dashes) automatically assigned by the controller');
            $persistedIdentifiers[] = $id;
        }
        Assert::assertCount(2, array_unique($persistedIdentifiers), 'each downloadable file must receive a distinct auto-generated identifier');

        $responseFiles = $body['downloadable_files'] ?? [];
        Assert::assertCount(2, $responseFiles, 'attach response must surface both persisted downloadable files back to the admin');
        $responseIdentifiers = [];
        foreach ($responseFiles as $file) {
            $rid = (string) ($file['download_identifier'] ?? '');
            Assert::assertNotEmpty($rid, 'attach response must echo back a non-empty auto-assigned download_identifier per file so the admin can see it');
            $responseIdentifiers[] = $rid;
        }
        sort($persistedIdentifiers);
        sort($responseIdentifiers);
        Assert::assertSame($persistedIdentifiers, $responseIdentifiers, 'response download_identifiers must match the persisted ones');
    }

    /**
     * Spec: Cases/Functional/products.md [117]
     *   "Admin is rejected when attaching a downloadable file with missing required fields."
     */
    public function adminIsRejectedWhenAttachingDownloadableFileWithMissingRequiredFields(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Missing117']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files', [
            'downloadable_files' => [
                [
                    'title'     => '',
                    'type'      => '',
                    'driver'    => '',
                    'file_name' => '',
                    'file_path' => '',
                    'file_url'  => '',
                    'serial'    => 1,
                ],
            ],
        ]);

        Assert::assertSame(422, $status, 'missing-required-fields request must be rejected with a 422 validation error');
        Assert::assertArrayHasKey('downloadable_files.0.title', $body, 'rejection body must surface the missing title field');
        Assert::assertArrayHasKey('downloadable_files.0.type', $body, 'rejection body must surface the missing type field');
        Assert::assertArrayHasKey('downloadable_files.0.driver', $body, 'rejection body must surface the missing driver field');
        Assert::assertArrayHasKey('downloadable_files.0.file_name', $body, 'rejection body must surface the missing file_name field');
        Assert::assertArrayHasKey('downloadable_files.0.file_path', $body, 'rejection body must surface the missing file_path field');
        Assert::assertSame(
            'File Path is required.',
            (string) ($body['downloadable_files.0.file_path']['required'] ?? ''),
            'controller must pass ProductDownloadableBulkFileRequest::messages() into Validator::make() so the friendly custom message is returned, not the framework\'s generic wording'
        );
        Assert::assertArrayNotHasKey('downloadable_files.0.file_url', $body, 'file_url is nullable (the local storage driver never supplies it) and must not be reported as missing');

        Assert::assertSame(0, ProductDownload::query()->where('post_id', $product->ID)->count(), 'rejected sync must not persist any downloadable rows');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertNotSame(1, (int) $detail->manage_downloadable, 'rejected sync must not flip ProductDetail.manage_downloadable to 1');
    }

    /**
     * Spec: Cases/Functional/products.md [229]
     *   "Admin can attach a local downloadable file without a file_url and it succeeds."
     */
    public function adminCanAttachLocalDownloadableFileWithoutFileUrl(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'LocalNoUrl229']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files', [
            'downloadable_files' => [
                [
                    'title'      => 'Local eBook',
                    'type'       => 'pdf',
                    'driver'     => 'local',
                    'file_name'  => 'local-ebook-229.pdf',
                    'file_path'  => 'local-ebook-229.pdf',
                    'serial'     => 1,
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'a local file with no file_url must be accepted. Body: ' . wp_json_encode($body));
        $row = ProductDownload::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($row, 'sync must persist the local file even without a submitted file_url');
        Assert::assertSame('local-ebook-229.pdf', (string) $row->file_path, 'persisted row must echo the submitted file_path');
        Assert::assertSame('local-ebook-229.pdf', (string) $row->file_url, 'persisted row must fall back file_url to file_name when none is submitted');
    }

    /**
     * Spec: Cases/Functional/products.md [230]
     *   "Admin is rejected when a provided file_url exceeds 200 characters."
     */
    public function adminIsRejectedWhenFileUrlExceeds200Characters(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'OverlongUrl230']);
        $overlengthUrl = 'http://example.com/' . str_repeat('a', 200) . '.pdf';

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files', [
            'downloadable_files' => [
                [
                    'title'      => 'Overlong URL File',
                    'type'       => 'pdf',
                    'driver'     => 's3',
                    'file_name'  => 'overlong-230.pdf',
                    'file_path'  => 'overlong-230.pdf',
                    'file_url'   => $overlengthUrl,
                    'serial'     => 1,
                ],
            ],
        ]);

        Assert::assertSame(422, $status, 'a file_url over 200 characters must still be rejected when one is provided. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('downloadable_files.0.file_url', $body, 'rejection body must surface the overlength file_url field');
        Assert::assertSame(0, ProductDownload::query()->where('post_id', $product->ID)->count(), 'rejected sync must not persist any downloadable rows');
    }

    /**
     * Spec: Cases/Functional/products.md [231]
     *   "Admin can attach multiple local downloadable files, including one scoped to a
     *    variation, none supplying a file_url."
     */
    public function adminCanAttachMultipleLocalFilesIncludingVariationScopedWithoutFileUrl(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => 'Variant231'])
            ->create(['post_title' => 'MultiLocalNoUrl231']);
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files', [
            'downloadable_files' => [
                [
                    'title'      => 'General File',
                    'type'       => 'pdf',
                    'driver'     => 'local',
                    'file_name'  => 'general-231.pdf',
                    'file_path'  => 'general-231.pdf',
                    'serial'     => 1,
                ],
                [
                    'title'                 => 'Variant File',
                    'type'                  => 'zip',
                    'driver'                => 'local',
                    'file_name'             => 'variant-231.zip',
                    'file_path'             => 'variant-231.zip',
                    'product_variation_id'  => [(int) $variant->id],
                    'serial'                => 2,
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'multiple local files without file_url, one variation-scoped, must be accepted. Body: ' . wp_json_encode($body));
        $rows = ProductDownload::query()->where('post_id', $product->ID)->orderBy('id')->get();
        Assert::assertCount(2, $rows, 'sync must persist both submitted local files');

        $variantScoped = $rows->first(function ($row) {
            return (string) $row->file_name === 'variant-231.zip';
        });
        Assert::assertNotNull($variantScoped, 'variant-scoped file must be persisted');
        $variantIds = array_map('intval', (array) $variantScoped->product_variation_id);
        Assert::assertContains((int) $variant->id, $variantIds, 'variant-scoped file must retain its product_variation_id assignment');
    }
}
