<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\Framework\Http\URL as BaseUrl;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductDownloadFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing downloadable files" section, items 115-116.
 */
class GetProductDownloadableUrlCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [115]
     *   "Admin can request a time-limited download URL for a downloadable file."
     */
    public function adminCanRequestTimeLimitedDownloadUrlForDownloadableFile(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'GetUrl115']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title'               => 'GetUrl Probe',
            'download_identifier' => '11111111-1111-4111-8111-111111111115',
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/products/getDownloadableUrl/' . $download->id);

        $I->seeResponseCodeIs(200);
        $url = (string) ($body['url'] ?? '');
        Assert::assertNotEmpty($url, 'response must surface a non-empty signed download URL');

        $params = (new BaseUrl())->validate($url);
        Assert::assertIsArray($params, 'signed URL must verify cleanly against the framework signer (signature must match and payload must decrypt)');
        Assert::assertArrayHasKey('valid_till', $params, 'signed payload must include valid_till to bound the URL lifetime');
        Assert::assertArrayHasKey('download_identifier', $params, 'signed payload must carry the file download_identifier so the receiver can resolve the file');
        Assert::assertSame((string) $download->download_identifier, (string) $params['download_identifier'], 'signed download_identifier must point at the requested file row');

        $validTill = (int) $params['valid_till'];
        Assert::assertGreaterThan(time(), $validTill, 'valid_till must be a future Unix timestamp so the URL is not already expired on issue');
    }

    /**
     * Spec: Cases/Functional/products.md [115a]
     *   "The copied download URL expires within seven days."
     */
    public function copiedDownloadUrlExpiresWithinSevenDays(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'GetUrl115a']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title'               => 'GetUrl Lifetime Probe',
            'download_identifier' => '11111111-1111-4111-8111-11111111115a',
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/products/getDownloadableUrl/' . $download->id);

        $I->seeResponseCodeIs(200);
        $params = (new BaseUrl())->validate((string) ($body['url'] ?? ''));
        Assert::assertIsArray($params, 'signed URL must verify cleanly against the framework signer');

        $validTill = (int) $params['valid_till'];
        $sevenDays = 7 * DAY_IN_SECONDS;
        Assert::assertLessThanOrEqual(time() + $sevenDays + 60, $validTill, 'the copied download URL must expire within seven days, not hundreds of days. valid_till is ' . round(($validTill - time()) / DAY_IN_SECONDS, 1) . ' days away');
        Assert::assertGreaterThan(time() + $sevenDays - 60, $validTill, 'the copied download URL must still last about seven days');
    }

    /**
     * Spec: Cases/Functional/products.md [115b]
     *   "Worker and Accountant, who may only view products, cannot request a download URL."
     */
    public function viewOnlyRolesCannotRequestDownloadUrl(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'GetUrl115b']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title'               => 'GetUrl ViewOnly Probe',
            'download_identifier' => '11111111-1111-4111-8111-11111111115b',
        ]);

        foreach (['worker', 'accountant'] as $role) {
            $I->loginAs($role);
            [$status, $body] = $I->get('/fluent-cart/v2/products/getDownloadableUrl/' . $download->id);

            Assert::assertSame(403, $status, $role . ' has only products/view and must not get a signed download URL. Body: ' . wp_json_encode($body));
            Assert::assertArrayNotHasKey('url', (array) $body, $role . ' denial must not include a download URL');
        }
    }

    /**
     * Spec: Cases/Functional/products.md [115c]
     *   "Store manager, who may edit products, can still request a download URL."
     */
    public function storeManagerCanRequestDownloadUrl(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'GetUrl115c']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title'               => 'GetUrl Manager Probe',
            'download_identifier' => '11111111-1111-4111-8111-11111111115c',
        ]);

        $I->loginAsManager();
        [$status, $body] = $I->get('/fluent-cart/v2/products/getDownloadableUrl/' . $download->id);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['url'] ?? '', 'store manager has products/edit and must receive a signed download URL');
    }

}
