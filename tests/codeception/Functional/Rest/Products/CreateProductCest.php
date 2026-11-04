<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductDownloadFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductMetaFactory;
use Tests\Support\Factory\ProductTermFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Creating a product" section, items 27-46.
 */
class CreateProductCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [27]
     *   "Admin can create a product with a title and a fulfilment type."
     */
    public function adminCanCreateProductWithTitleAndFulfilmentType(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $title = 'Create27 ' . wp_generate_password(6, false, false);

        [$status, $body] = $I->post('/fluent-cart/v2/products', [
            'post_title' => $title,
            'detail'     => ['fulfillment_type' => 'physical'],
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['data']['ID'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'response data.ID must be a real auto-generated post id');

        $post = get_post($newId);
        Assert::assertNotNull($post, 'created post must exist in wp_posts');
        Assert::assertSame($title, $post->post_title, 'wp_posts row must persist the requested title');
        Assert::assertSame(FluentProducts::CPT_NAME, $post->post_type, 'created post must use the fluent-products CPT');

        $detail = ProductDetail::query()->where('post_id', $newId)->first();
        Assert::assertNotNull($detail, 'product create must persist a fct_product_details row');
        Assert::assertSame('physical', $detail->fulfillment_type, 'product detail must persist the requested fulfilment type');
    }

    /**
     * Spec: Cases/Functional/products.md [28]
     *   "Admin sees a unique identifier automatically assigned to a newly created product."
     */
    public function adminSeesUniqueIdentifierAutomaticallyAssignedToNewProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $sharedTitle = 'IdProbe ' . wp_generate_password(6, false, false);

        [$firstStatus, $first] = $I->post('/fluent-cart/v2/products', [
            'post_title' => $sharedTitle,
            'detail'     => ['fulfillment_type' => 'digital'],
        ]);
        Assert::assertSame(200, $firstStatus, 'first create must return 200 to honour the spec scenario');
        [$secondStatus, $second] = $I->post('/fluent-cart/v2/products', [
            'post_title' => $sharedTitle,
            'detail'     => ['fulfillment_type' => 'digital'],
        ]);
        Assert::assertSame(200, $secondStatus, 'second create must return 200 to honour the spec scenario');

        $firstId = (int) ($first['data']['ID'] ?? 0);
        $secondId = (int) ($second['data']['ID'] ?? 0);

        Assert::assertGreaterThan(0, $firstId, 'first create must auto-assign a positive identifier');
        Assert::assertGreaterThan(0, $secondId, 'second create must auto-assign a positive identifier');
        Assert::assertNotSame($firstId, $secondId, 'two creates with the same title must receive distinct auto-assigned identifiers');

        $firstPost = get_post($firstId);
        Assert::assertNotNull($firstPost, 'first auto-assigned id must resolve to a persisted wp_posts row');
        Assert::assertSame(FluentProducts::CPT_NAME, $firstPost->post_type, 'first auto-assigned id must belong to the fluent-products CPT');
        $secondPost = get_post($secondId);
        Assert::assertNotNull($secondPost, 'second auto-assigned id must resolve to a persisted wp_posts row');
        Assert::assertSame(FluentProducts::CPT_NAME, $secondPost->post_type, 'second auto-assigned id must belong to the fluent-products CPT');
    }

    /**
     * Spec: Cases/Functional/products.md [29]
     *   "Admin sees an initial variation automatically created and marked in stock when creating a simple product."
     */
    public function adminSeesInitialVariationAutomaticallyCreatedAndInStockForSimpleProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $title = 'Variant29 ' . wp_generate_password(6, false, false);

        [$status, $body] = $I->post('/fluent-cart/v2/products', [
            'post_title' => $title,
            'detail'     => ['fulfillment_type' => 'digital', 'variation_type' => 'simple'],
        ]);

        $I->seeResponseCodeIs(200);
        $postId = (int) ($body['data']['ID'] ?? 0);
        Assert::assertGreaterThan(0, $postId, 'create response must surface the new product id');

        $detail = ProductDetail::query()->where('post_id', $postId)->first();
        Assert::assertNotNull($detail, 'create must persist a fct_product_details row');
        Assert::assertSame('simple', $detail->variation_type, 'product detail must persist the requested variation type');

        $variants = ProductVariation::query()->where('post_id', $postId)->get();
        Assert::assertCount(1, $variants, 'create must persist exactly one initial variant for the new product');
        $variant = $variants->first();
        Assert::assertSame('in-stock', $variant->stock_status, 'initial variant must be persisted with stock_status=in-stock');
        Assert::assertSame($title, $variant->variation_title, 'initial variant must inherit the product title');

        $responseVariant = $body['data']['variant'] ?? null;
        Assert::assertNotNull($responseVariant, 'create response must echo back the auto-created variant');
        Assert::assertSame((int) $variant->id, (int) ($responseVariant['id'] ?? 0), 'response variant id must match the persisted row id');
    }

    /**
     * Spec: Cases/Functional/products.md [29a]
     *   "Admin creating a Simple Variations product gets no starter variation, because the pricing table builds them on the edit page."
     */
    public function adminCreatingSimpleVariationsProductGetsNoStarterVariation(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/products', [
            'post_title' => 'Variant29a ' . wp_generate_password(6, false, false),
            'detail'     => ['fulfillment_type' => 'digital', 'variation_type' => 'simple_variations'],
        ]);

        $I->seeResponseCodeIs(200);
        $postId = (int) ($body['data']['ID'] ?? 0);
        Assert::assertGreaterThan(0, $postId, 'create response must surface the new product id');

        $detail = ProductDetail::query()->where('post_id', $postId)->first();
        Assert::assertNotNull($detail, 'create must persist a fct_product_details row');
        Assert::assertSame('simple_variations', $detail->variation_type, 'product detail must persist the requested variation type');

        $variants = ProductVariation::query()->where('post_id', $postId)->get();
        Assert::assertCount(0, $variants, 'a simple_variations product must be created with no starter variant');
        Assert::assertArrayHasKey('variant', (array) $body['data'], 'create response must still expose the variant key');
        Assert::assertNull($body['data']['variant'], 'create response variant must be null when no starter variant is created');
    }

    /**
     * Spec: Cases/Functional/products.md [29b]
     *   "Admin creating an Advanced Variations product gets no starter variation, because the attribute combinations build them on the edit page."
     */
    public function adminCreatingAdvancedVariationsProductGetsNoStarterVariation(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/products', [
            'post_title' => 'Variant29b ' . wp_generate_password(6, false, false),
            'detail'     => ['fulfillment_type' => 'physical', 'variation_type' => 'advanced_variations'],
        ]);

        $I->seeResponseCodeIs(200);
        $postId = (int) ($body['data']['ID'] ?? 0);
        Assert::assertGreaterThan(0, $postId, 'create response must surface the new product id');

        $detail = ProductDetail::query()->where('post_id', $postId)->first();
        Assert::assertNotNull($detail, 'create must persist a fct_product_details row');
        Assert::assertSame('advanced_variations', $detail->variation_type, 'product detail must persist the requested variation type');

        $variants = ProductVariation::query()->where('post_id', $postId)->get();
        Assert::assertCount(0, $variants, 'an advanced_variations product must be created with no starter variant');
        Assert::assertArrayHasKey('variant', (array) $body['data'], 'create response must still expose the variant key');
        Assert::assertNull($body['data']['variant'], 'create response variant must be null when no starter variant is created');
    }

    /**
     * Spec: Cases/Functional/products.md [30]
     *   "Admin sees the product's creation timestamp recorded in UTC regardless of the site's timezone."
     */
    public function adminSeesProductCreationTimestampRecordedInUtc(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $originalTimezone = get_option('timezone_string');
        update_option('timezone_string', 'America/Los_Angeles');

        try {
            $beforeUtc = gmdate('Y-m-d H:i:s');
            [$status, $body] = $I->post('/fluent-cart/v2/products', [
                'post_title'  => 'TimestampUtc ' . wp_generate_password(6, false, false),
                'post_status' => 'publish',
                'detail'      => ['fulfillment_type' => 'digital'],
            ]);
            $afterUtc = gmdate('Y-m-d H:i:s');

            $I->seeResponseCodeIs(200);
            $postId = (int) ($body['data']['ID'] ?? 0);
            Assert::assertGreaterThan(0, $postId, 'create response must surface the new product id');

            global $wpdb;
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT post_date_gmt FROM {$wpdb->posts} WHERE ID = %d", $postId),
                ARRAY_A
            );
            Assert::assertNotNull($row, 'wp_posts row must exist after create');
            $gmt = (string) ($row['post_date_gmt'] ?? '');
            Assert::assertNotEmpty($gmt, 'post_date_gmt must be persisted, not empty or 0000-00-00');
            Assert::assertNotSame('0000-00-00 00:00:00', $gmt, 'post_date_gmt must be a real timestamp, not the zero default');
            Assert::assertGreaterThanOrEqual(
                strtotime($beforeUtc . ' UTC'),
                strtotime($gmt . ' UTC'),
                'post_date_gmt must be >= UTC time captured before the request'
            );
            Assert::assertLessThanOrEqual(
                strtotime($afterUtc . ' UTC') + 5,
                strtotime($gmt . ' UTC'),
                'post_date_gmt must be <= UTC time captured after the request'
            );
        } finally {
            update_option('timezone_string', (string) $originalTimezone);
        }
    }

    /**
     * Spec: Cases/Functional/products.md [31]
     *   "Admin is rejected when creating a product without a title."
     */
    public function creatingProductWithoutTitleIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $beforePosts = $I->countFluentProductPosts();
        $beforeDetails = $I->countProductDetails();
        $beforeVariants = $I->countProductVariants();

        [$status, $body] = $I->post('/fluent-cart/v2/products', [
            'detail' => ['fulfillment_type' => 'digital'],
        ]);

        Assert::assertSame(422, $status, 'missing required title must return validation status 422');
        $errors = $body['errors'] ?? $body;
        Assert::assertArrayHasKey('post_title', $errors, 'validation errors must identify the missing post_title field');
        $messages = (array) ($errors['post_title'] ?? []);
        $joined = implode(' | ', array_map('strval', $messages));
        Assert::assertStringContainsStringIgnoringCase('title', $joined, 'post_title error message must mention the title');
        Assert::assertSame($beforePosts, $I->countFluentProductPosts(), 'rejected create must not insert a fluent-products wp_posts row');
        Assert::assertSame($beforeDetails, $I->countProductDetails(), 'rejected create must not insert a fct_product_details row');
        Assert::assertSame($beforeVariants, $I->countProductVariants(), 'rejected create must not insert a fct_product_variations row');
    }

    /**
     * Spec: Cases/Functional/products.md [32]
     *   "Admin is rejected when creating a product with a fulfilment type other than "physical" or "digital"."
     */
    public function creatingProductWithUnsupportedFulfilmentTypeIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $beforePosts = $I->countFluentProductPosts();
        $beforeDetails = $I->countProductDetails();
        $beforeVariants = $I->countProductVariants();

        [$status, $body] = $I->post('/fluent-cart/v2/products', [
            'post_title' => 'BadFulfillment ' . wp_generate_password(6, false, false),
            'detail'     => ['fulfillment_type' => 'pony-express'],
        ]);

        Assert::assertSame(422, $status, 'invalid fulfilment type must return validation status 422');
        $errors = $body['errors'] ?? $body;
        Assert::assertArrayHasKey('detail.fulfillment_type', $errors, 'validation errors must identify detail.fulfillment_type as the rejected field');
        $messages = (array) ($errors['detail.fulfillment_type'] ?? []);
        $joined = implode(' | ', array_map('strval', $messages));
        Assert::assertStringContainsStringIgnoringCase('fulfillment', $joined, 'detail.fulfillment_type error message must mention fulfillment');
        Assert::assertSame($beforePosts, $I->countFluentProductPosts(), 'rejected create must not insert a fluent-products wp_posts row');
        Assert::assertSame($beforeDetails, $I->countProductDetails(), 'rejected create must not insert a fct_product_details row');
        Assert::assertSame($beforeVariants, $I->countProductVariants(), 'rejected create must not insert a fct_product_variations row');
    }

    /**
     * Spec: Cases/Functional/products.md [33]
     *   "Admin can bulk-create up to ten products in a single request."
     */
    public function adminCanBulkCreateUpToTenProductsInOneRequest(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $payload = [];
        $titles = [];
        $runId = wp_generate_password(6, false, false);
        for ($i = 0; $i < 10; $i++) {
            $title = 'Bulk33-' . $i . '-' . $runId;
            $titles[] = $title;
            $payload[] = [
                'post_title'  => $title,
                'post_status' => 'draft',
                'detail'      => [
                    'fulfillment_type' => 'digital',
                    'variation_type'   => 'simple',
                ],
                'variants'    => [[
                    'variation_title' => $title,
                    'sku'             => 'BULK33-' . $runId . '-' . $i,
                    // CENTS. The Bulk Insert screen and the CSV importer convert the
                    // merchant's dollars at the input boundary, so the endpoint receives
                    // whole cents like every other product write path.
                    'item_price'      => 1234,
                    'other_info'      => ['payment_type' => 'onetime'],
                ]],
            ];
        }

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-insert', [
            'products' => $payload,
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('created', $body, 'bulk-insert response must contain a created list');
        $createdIds = array_map('intval', (array) $body['created']);
        Assert::assertCount(10, $createdIds, 'all 10 products must be reported as created');

        foreach ($titles as $title) {
            $matched = get_posts([
                'post_type'      => FluentProducts::CPT_NAME,
                'post_status'    => 'any',
                'title'          => $title,
                'fields'         => 'ids',
                'posts_per_page' => 1,
                'no_found_rows'  => true,
            ]);
            $postId = (int) ($matched[0] ?? 0);
            Assert::assertGreaterThan(0, $postId, "bulk-inserted product '{$title}' must persist as a fluent-products post");

            $variants = ProductVariation::query()->where('post_id', $postId)->get();
            Assert::assertCount(1, $variants, "bulk-inserted product '{$title}' must persist exactly one variant");
            Assert::assertSame(
                1234,
                (int) $variants->first()->item_price,
                "bulk-inserted variant for '{$title}' must persist the submitted 1234 cents unchanged"
            );
        }
    }

    /**
     * The CSV importer forwards a negative or malformed cell to this endpoint untouched
     * rather than normalizing it, precisely so these rules still fire. This test pins the
     * server half of that contract: if bulk-insert ever stopped rejecting these, the
     * importer's pass-through would become a silent mispricing instead of a row error.
     *
     * `numeric` catches a malformed cell; `min:0` catches a negative one. Both are on
     * BulkProductInsertService::validateProduct().
     */
    public function bulkInsertRejectsNegativeAndMalformedPricesInsteadOfCoercingThem(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $beforeVariants = $I->countProductVariants();

        $buildProduct = static function ($title, $price) {
            return [
                'post_title'  => $title,
                'post_status' => 'draft',
                'detail'      => ['fulfillment_type' => 'digital', 'variation_type' => 'simple'],
                'variants'    => [[
                    'variation_title' => $title,
                    'item_price'      => $price,
                    'other_info'      => ['payment_type' => 'onetime'],
                ]],
            ];
        };

        [$negativeStatus, $negativeBody] = $I->post('/fluent-cart/v2/products/bulk-insert', [
            'products' => [$buildProduct('NegativePrice', -1999)],
        ]);
        Assert::assertNotSame(
            200,
            $negativeStatus,
            'a negative price must be rejected, not stored as its absolute value. Body: ' . wp_json_encode($negativeBody)
        );

        [$malformedStatus, $malformedBody] = $I->post('/fluent-cart/v2/products/bulk-insert', [
            'products' => [$buildProduct('MalformedPrice', 'abc')],
        ]);
        Assert::assertNotSame(
            200,
            $malformedStatus,
            'a non-numeric price must be rejected, not silently stored as 0. Body: ' . wp_json_encode($malformedBody)
        );

        // The partially-numeric case is the one worth pinning: the importer used to
        // salvage "19.99abc" into 1999 cents, so the row imported as a real price.
        [$partialStatus, $partialBody] = $I->post('/fluent-cart/v2/products/bulk-insert', [
            'products' => [$buildProduct('PartiallyMalformedPrice', '19.99abc')],
        ]);
        Assert::assertNotSame(
            200,
            $partialStatus,
            'a partially numeric price must be rejected, not salvaged into 1999 cents. Body: ' . wp_json_encode($partialBody)
        );

        Assert::assertSame(
            $beforeVariants,
            $I->countProductVariants(),
            'neither rejected row may persist a variant'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [34]
     *   "Admin is rejected when bulk-creating more than ten products in a single request."
     */
    public function bulkCreatingMoreThanTenProductsIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $beforePosts = $I->countFluentProductPosts();
        $beforeDetails = $I->countProductDetails();
        $beforeVariants = $I->countProductVariants();

        $payload = [];
        for ($i = 0; $i < 11; $i++) {
            $payload[] = [
                'post_title' => 'BulkOverflow-' . $i . '-' . wp_generate_password(4, false, false),
                'detail'     => ['fulfillment_type' => 'digital'],
                'price'      => 9.99,
            ];
        }

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-insert', [
            'products' => $payload,
        ]);

        Assert::assertSame(422, $status, 'over-cap bulk insert must return 422 client error');
        $message = $body['message'] ?? ($body['data']['message'] ?? null) ?? ($body['errors']['message'] ?? null);
        Assert::assertNotEmpty($message, 'response must include an explanatory error message');
        Assert::assertStringContainsStringIgnoringCase('10', (string) $message, 'rejection message must reference the 10-product cap');
        Assert::assertArrayNotHasKey('created', $body, 'response must not include a created list when the chunk is rejected');
        Assert::assertSame($beforePosts, $I->countFluentProductPosts(), 'rejected over-cap bulk-insert must not insert any fluent-products wp_posts rows');
        Assert::assertSame($beforeDetails, $I->countProductDetails(), 'rejected over-cap bulk-insert must not insert any fct_product_details rows');
        Assert::assertSame($beforeVariants, $I->countProductVariants(), 'rejected over-cap bulk-insert must not insert any fct_product_variations rows');
    }

    /**
     * Spec: Cases/Functional/products.md [35]
     *   "Admin is rejected when bulk-creating with no products in the request."
     */
    public function bulkCreatingWithNoProductsIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $beforePosts = $I->countFluentProductPosts();
        $beforeDetails = $I->countProductDetails();
        $beforeVariants = $I->countProductVariants();

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-insert', [
            'products' => [],
        ]);

        Assert::assertSame(422, $status, 'empty bulk insert must return 422 client error');
        $message = $body['message'] ?? ($body['data']['message'] ?? null) ?? ($body['errors']['message'] ?? null);
        Assert::assertNotEmpty($message, 'response must include an explanatory error message');
        Assert::assertStringContainsStringIgnoringCase('no products', (string) $message, 'rejection message must indicate that no products were provided');
        Assert::assertSame($beforePosts, $I->countFluentProductPosts(), 'rejected empty bulk-insert must not insert any fluent-products wp_posts rows');
        Assert::assertSame($beforeDetails, $I->countProductDetails(), 'rejected empty bulk-insert must not insert any fct_product_details rows');
        Assert::assertSame($beforeVariants, $I->countProductVariants(), 'rejected empty bulk-insert must not insert any fct_product_variations rows');
    }

    /**
     * Spec: Cases/Functional/products.md [36]
     *   "Admin can create seeded dummy products in a chosen category for store testing."
     */
    public function adminCanCreateSeededDummyProductsInChosenCategory(FunctionalTester $I): void
    {
        global $wpdb;
        $I->loginAsSuperAdmin();
        $beforeCount = $I->countFluentProductPosts();

        [$status, $body] = $I->post('/fluent-cart/v2/products/create-dummy', [
            'category' => 'electronics',
            'index'    => 0,
        ]);

        Assert::assertSame(200, $status, 'create-dummy must return 200 when the category seed file exists');
        Assert::assertSame($beforeCount + 1, $I->countFluentProductPosts(), 'wp_posts must contain exactly one additional fluent-products row after seeding');
        Assert::assertGreaterThan(0, $I->countProductDetails(), 'seeded dummy product must persist a fct_product_details row');
        Assert::assertGreaterThan(0, $I->countProductVariants(), 'seeded dummy product must persist at least one fct_product_variation row');

        $newPostId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID DESC LIMIT 1",
            FluentProducts::CPT_NAME
        ));
        Assert::assertGreaterThan(0, $newPostId, 'must locate the newly seeded dummy product post id');

        $seedPath = constant('FLUENTCART_PLUGIN_PATH') . 'dummies/electronics.json';
        $seed = json_decode((string) file_get_contents($seedPath), true);
        $expectedTitle = (string) ($seed[0]['post_title'] ?? '');
        Assert::assertNotEmpty($expectedTitle, 'electronics.json index 0 must have a post_title to verify against');

        $newPost = get_post($newPostId);
        Assert::assertNotNull($newPost, 'newly seeded post must be retrievable');
        Assert::assertSame($expectedTitle, $newPost->post_title, 'seeded product title must come from the chosen-category seed file at the requested index');
        Assert::assertSame('publish', $newPost->post_status, 'seeded dummy product must be published so it appears in storefront listings for testing');
    }

    /**
     * Spec: Cases/Functional/products.md [37]
     *   "Admin can duplicate a product."
     */
    public function adminCanDuplicateProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $original = (new ProductFactory())->create(['post_title' => 'DuplicateMe ' . wp_generate_password(4, false, false)]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $original->ID . '/duplicate', []);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['product_id'] ?? $body['data']['product_id'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'duplicate response must surface the new product_id');
        Assert::assertNotSame((int) $original->ID, $newId, 'duplicate must produce a different product id from the original');

        $newPost = get_post($newId);
        Assert::assertNotNull($newPost, 'duplicate must persist a wp_posts row for the new product');
        Assert::assertSame(FluentProducts::CPT_NAME, $newPost->post_type, 'duplicate must use the fluent-products CPT');
        Assert::assertStringContainsString($original->post_title, $newPost->post_title, 'duplicate post_title must include the original title');

        $duplicateDetail = ProductDetail::query()->where('post_id', $newId)->first();
        Assert::assertNotNull($duplicateDetail, 'duplicate must persist its own fct_product_details row');
        Assert::assertSame($original->detail->fulfillment_type, $duplicateDetail->fulfillment_type, 'duplicate detail must inherit the original fulfilment type');
        $duplicateVariants = ProductVariation::query()->where('post_id', $newId)->get();
        Assert::assertCount($original->variants->count(), $duplicateVariants, 'duplicate must persist the same number of variant rows as the original');
        Assert::assertSame((int) $original->variants->first()->item_price, (int) $duplicateVariants->first()->item_price, 'duplicate variant must inherit the original item_price stored in cents');
    }

    /**
     * Spec: Cases/Functional/products.md [38]
     *   "Admin sees the duplicate of a product saved as a draft so it is not visible on the storefront until reviewed."
     */
    public function duplicateProductIsSavedAsDraft(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $original = (new ProductFactory())->create([
            'post_title'  => 'DraftDup ' . wp_generate_password(4, false, false),
            'post_status' => 'publish',
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $original->ID . '/duplicate', []);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['product_id'] ?? $body['data']['product_id'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'duplicate response must surface the new product_id');
        $newPost = get_post($newId);
        Assert::assertNotNull($newPost, 'duplicate must persist a wp_posts row');
        Assert::assertSame('draft', $newPost->post_status, 'duplicate post_status must be draft so it stays hidden from the storefront');
    }

    /**
     * Spec: Cases/Functional/products.md [39]
     *   "Admin can choose whether the duplicate inherits stock management, licence settings, or downloadable files from the original."
     */
    public function duplicateInheritanceFlagsControlStockLicenseAndDownloadables(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();
        $original = (new ProductFactory())
            ->withDetailAttributes([
                'manage_stock'        => 1,
                'manage_downloadable' => 1,
            ])
            ->withVariantAttributes([
                'manage_stock' => 1,
                'total_stock'  => 25,
                'available'    => 25,
            ])
            ->create(['post_title' => 'InheritFlags ' . wp_generate_password(4, false, false)]);
        (new ProductMetaFactory())->createLicenseSettings($original->ID, ['license_key_format' => 'uuid']);
        (new ProductDownloadFactory())->create((int) $original->ID, ['title' => 'OriginalDL']);

        [$offStatus, $offBody] = $I->post('/fluent-cart/v2/products/' . $original->ID . '/duplicate', [
            'import_stock_management'   => false,
            'import_license_settings'   => false,
            'import_downloadable_files' => false,
        ]);
        Assert::assertSame(200, $offStatus, 'first duplicate (all flags off) must return 200 before its body is inspected');
        $offId = (int) ($offBody['product_id'] ?? $offBody['data']['product_id'] ?? 0);
        Assert::assertGreaterThan(0, $offId, 'first duplicate must succeed');

        $offDetail = ProductDetail::query()->where('post_id', $offId)->first();
        Assert::assertSame(0, (int) $offDetail->manage_stock, 'duplicate with import_stock_management=false must disable manage_stock');
        Assert::assertSame(0, (int) $offDetail->manage_downloadable, 'duplicate with import_downloadable_files=false must disable manage_downloadable');
        $offLicense = ProductMeta::query()
            ->where('object_id', $offId)
            ->whereNull('object_type')
            ->where('meta_key', 'license_settings')
            ->first();
        Assert::assertNull($offLicense, 'duplicate with import_license_settings=false must not copy the license_settings meta row');
        $offDownloads = ProductDownload::query()->where('post_id', $offId)->count();
        Assert::assertSame(0, (int) $offDownloads, 'duplicate with import_downloadable_files=false must not copy downloadable files');

        [$onStatus, $onBody] = $I->post('/fluent-cart/v2/products/' . $original->ID . '/duplicate', [
            'import_stock_management'   => true,
            'import_license_settings'   => true,
            'import_downloadable_files' => true,
        ]);
        Assert::assertSame(200, $onStatus, 'second duplicate (all flags on) must return 200 before its body is inspected');
        $onId = (int) ($onBody['product_id'] ?? $onBody['data']['product_id'] ?? 0);
        Assert::assertGreaterThan(0, $onId, 'second duplicate must succeed');

        $onDetail = ProductDetail::query()->where('post_id', $onId)->first();
        Assert::assertSame(1, (int) $onDetail->manage_stock, 'duplicate with import_stock_management=true must keep manage_stock enabled');
        Assert::assertSame(1, (int) $onDetail->manage_downloadable, 'duplicate with import_downloadable_files=true must keep manage_downloadable enabled');
        $onLicense = ProductMeta::query()
            ->where('object_id', $onId)
            ->whereNull('object_type')
            ->where('meta_key', 'license_settings')
            ->first();
        Assert::assertNotNull($onLicense, 'duplicate with import_license_settings=true must copy the license_settings meta row');
        $onDownloads = ProductDownload::query()->where('post_id', $onId)->count();
        Assert::assertSame(1, (int) $onDownloads, 'duplicate with import_downloadable_files=true must copy downloadable file rows');
    }

    /**
     * Spec: Cases/Functional/products.md [40]
     *   "Admin receives a clear "not found" error when duplicating a product that does not exist."
     */
    public function duplicatingMissingProductReturnsNotFound(FunctionalTester $I): void
    {
        global $wpdb;
        $I->loginAsSuperAdmin();
        $beforePosts = $I->countFluentProductPosts();
        $beforeDetails = $I->countProductDetails();
        $beforeVariants = $I->countProductVariants();
        $missingId = ((int) $wpdb->get_var("SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}")) + 10000;

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $missingId . '/duplicate', []);

        Assert::assertSame(404, $status, 'duplicating a missing product must return 404 with a clear not-found error');
        $message = $body['message'] ?? ($body['data']['message'] ?? null) ?? ($body['errors']['message'] ?? null);
        Assert::assertNotEmpty($message, 'response must include an explanatory not-found message');
        Assert::assertStringContainsStringIgnoringCase('not found', (string) $message, 'rejection message must explicitly mention "not found"');
        Assert::assertSame($beforePosts, $I->countFluentProductPosts(), 'rejected duplicate must not insert any fluent-products wp_posts rows');
        Assert::assertSame($beforeDetails, $I->countProductDetails(), 'rejected duplicate must not insert any fct_product_details rows');
        Assert::assertSame($beforeVariants, $I->countProductVariants(), 'rejected duplicate must not insert any fct_product_variations rows');
    }

    /**
     * Spec: Cases/Functional/products.md [41]
     *   "A worker without the "create products" permission cannot create a product."
     */
    public function workerWithoutCreatePermissionCannotCreateProduct(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        $beforePosts = $I->countFluentProductPosts();
        $beforeDetails = $I->countProductDetails();
        $beforeVariants = $I->countProductVariants();

        [$status, $body] = $I->post('/fluent-cart/v2/products', [
            'post_title' => 'WorkerCreate ' . wp_generate_password(4, false, false),
            'detail'     => ['fulfillment_type' => 'digital'],
        ]);

        Assert::assertSame(403, $status, 'worker without products/create permission must be rejected with 403');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'WP REST permission_callback denial must surface the rest_forbidden code on the body');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejected request must include a permission-denied message');
        Assert::assertSame($beforePosts, $I->countFluentProductPosts(), 'rejected create must not insert a fluent-products wp_posts row');
        Assert::assertSame($beforeDetails, $I->countProductDetails(), 'rejected create must not insert a fct_product_details row');
        Assert::assertSame($beforeVariants, $I->countProductVariants(), 'rejected create must not insert a fct_product_variations row');
    }

    /**
     * Spec: Cases/Functional/products.md [42]
     *   "A store manager with the "create products" permission can create a product."
     */
    public function storeManagerWithCreatePermissionCanCreateProduct(FunctionalTester $I): void
    {
        $I->loginAsManager();
        $title = 'ManagerCreate ' . wp_generate_password(4, false, false);

        [$status, $body] = $I->post('/fluent-cart/v2/products', [
            'post_title' => $title,
            'detail'     => ['fulfillment_type' => 'digital'],
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['data']['ID'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'manager-created product must receive a real id');
        $detail = ProductDetail::query()->where('post_id', $newId)->first();
        Assert::assertNotNull($detail, 'manager create must persist fct_product_details');
    }

    /**
     * Spec: Cases/Functional/products.md [43]
     *   "An accountant cannot create a product."
     */
    public function accountantCannotCreateProduct(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        $beforePosts = $I->countFluentProductPosts();
        $beforeDetails = $I->countProductDetails();
        $beforeVariants = $I->countProductVariants();

        [$status, $body] = $I->post('/fluent-cart/v2/products', [
            'post_title' => 'AccountantCreate ' . wp_generate_password(4, false, false),
            'detail'     => ['fulfillment_type' => 'digital'],
        ]);

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 when attempting to create a product');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'WP REST permission_callback denial must surface the rest_forbidden code on the body');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejected request must include a permission-denied message');
        Assert::assertSame($beforePosts, $I->countFluentProductPosts(), 'rejected create must not insert a fluent-products wp_posts row');
        Assert::assertSame($beforeDetails, $I->countProductDetails(), 'rejected create must not insert a fct_product_details row');
        Assert::assertSame($beforeVariants, $I->countProductVariants(), 'rejected create must not insert a fct_product_variations row');
    }

    /**
     * Spec: Cases/Functional/products.md [44]
     *   "A worker without the "create products" permission cannot duplicate a product."
     */
    public function workerWithoutCreatePermissionCannotDuplicateProduct(FunctionalTester $I): void
    {
        $original = (new ProductFactory())->create(['post_title' => 'WorkerDup ' . wp_generate_password(4, false, false)]);
        $beforePosts = $I->countFluentProductPosts();
        $beforeDetails = $I->countProductDetails();
        $beforeVariants = $I->countProductVariants();
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $original->ID . '/duplicate', []);

        Assert::assertSame(403, $status, 'worker without products/create must be rejected with 403 from duplicate');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'WP REST permission_callback denial must surface the rest_forbidden code on the body');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejected duplicate must include a permission-denied message');
        Assert::assertSame($beforePosts, $I->countFluentProductPosts(), 'rejected duplicate must not insert a new fluent-products wp_posts row');
        Assert::assertSame($beforeDetails, $I->countProductDetails(), 'rejected duplicate must not insert a new fct_product_details row');
        Assert::assertSame($beforeVariants, $I->countProductVariants(), 'rejected duplicate must not insert any new fct_product_variations rows');
    }

    /**
     * Spec: Cases/Functional/products.md [45]
     *   "A worker without the "create products" permission cannot bulk-create products."
     */
    public function workerWithoutCreatePermissionCannotBulkCreateProducts(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        $beforePosts = $I->countFluentProductPosts();
        $beforeDetails = $I->countProductDetails();
        $beforeVariants = $I->countProductVariants();

        $runId = wp_generate_password(6, false, false);
        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-insert', [
            'products' => [
                [
                    'post_title'  => 'WorkerBulk-1-' . $runId,
                    'post_status' => 'draft',
                    'detail'      => ['fulfillment_type' => 'digital', 'variation_type' => 'simple'],
                    'variants'    => [[
                        'variation_title' => 'WorkerBulk-1',
                        'sku'             => 'WB1-' . $runId,
                        'item_price'      => 1,
                        'other_info'      => ['payment_type' => 'onetime'],
                    ]],
                ],
                [
                    'post_title'  => 'WorkerBulk-2-' . $runId,
                    'post_status' => 'draft',
                    'detail'      => ['fulfillment_type' => 'digital', 'variation_type' => 'simple'],
                    'variants'    => [[
                        'variation_title' => 'WorkerBulk-2',
                        'sku'             => 'WB2-' . $runId,
                        'item_price'      => 1,
                        'other_info'      => ['payment_type' => 'onetime'],
                    ]],
                ],
            ],
        ]);

        Assert::assertSame(403, $status, 'worker without products/create must be rejected with 403 from bulk-insert');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'WP REST permission_callback denial must surface the rest_forbidden code on the body');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejected bulk-insert must include a permission-denied message');
        Assert::assertSame($beforePosts, $I->countFluentProductPosts(), 'rejected bulk-insert must not insert any fluent-products wp_posts rows');
        Assert::assertSame($beforeDetails, $I->countProductDetails(), 'rejected bulk-insert must not insert any fct_product_details rows');
        Assert::assertSame($beforeVariants, $I->countProductVariants(), 'rejected bulk-insert must not insert any fct_product_variations rows');
    }

    /**
     * Spec: Cases/Functional/products.md [46]
     *   "A worker without the "create products" permission cannot create dummy products."
     */
    public function workerWithoutCreatePermissionCannotCreateDummyProducts(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        $beforePosts = $I->countFluentProductPosts();
        $beforeDetails = $I->countProductDetails();
        $beforeVariants = $I->countProductVariants();

        [$status, $body] = $I->post('/fluent-cart/v2/products/create-dummy', [
            'category' => 'electronics',
            'index'    => 0,
        ]);

        Assert::assertSame(403, $status, 'worker without products/create must be rejected with 403 from create-dummy');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'WP REST permission_callback denial must surface the rest_forbidden code on the body');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejected create-dummy must include a permission-denied message');
        Assert::assertSame($beforePosts, $I->countFluentProductPosts(), 'rejected create-dummy must not insert any fluent-products wp_posts rows (any status)');
        Assert::assertSame($beforeDetails, $I->countProductDetails(), 'rejected create-dummy must not insert any fct_product_details rows');
        Assert::assertSame($beforeVariants, $I->countProductVariants(), 'rejected create-dummy must not insert any fct_product_variations rows');
    }
}
