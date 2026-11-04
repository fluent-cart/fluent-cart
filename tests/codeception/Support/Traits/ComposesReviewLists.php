<?php

namespace Tests\Support\Traits;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use Tests\Support\Factory\ProductFactory;

/**
 * A product with three reviews, and the machinery to render a composed Review
 * List against it.
 *
 * Shared because the same list is now exercised from two suites. How the rows
 * lay out is one contract with two halves that cannot share a WordPress: grid,
 * slider and masonry belong to Pro and are proven in IntegrationPro, while the
 * Integration suite runs Pro-absent by design and proves the other half — that
 * without Pro every one of them comes back as a single column.
 */
trait ComposesReviewLists
{
    /** @var int */
    protected $productId;

    /** @var int the store's Reviews per page before a test changed it */
    protected $originalPerPage;

    protected function bootComposedReviewList(): void
    {
        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['active'] = 'yes';
        $modules['reviews']['reviews_enabled'] = 'yes';
        ModuleSettings::saveSettings($modules);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        $this->originalPerPage = (int) ProductReviewService::getReviewSettings()['reviews_per_page'];

        $product = (new ProductFactory())->create(['post_title' => 'Composed Widget']);
        $this->productId = (int) $product->ID;

        foreach (['First opinion.', 'Second opinion.', 'Third opinion.'] as $text) {
            $review = new ProductReview([
                'post_id'       => $this->productId,
                'reviewer_name' => 'Dana Buyer',
                'review'        => $text,
                'rating'        => 5,
            ]);
            $review->status = Status::REVIEW_APPROVED;
            $review->save();
        }
    }

    protected function restoreComposedReviewList(): void
    {
        $this->setStorePerPage($this->originalPerPage);
        ProductReviewService::resetRuntimeCache();
    }

    /**
     * The review ids in the order a rendered list put them.
     *
     * @param string $html
     * @return array<int, string>
     */
    protected function reviewIdsIn(string $html): array
    {
        preg_match_all('#class="fct-review-item" data-review-id="(\\d+)"#', $html, $matches);

        return $matches[1];
    }

    /**
     * Set the store's Reviews per page for the duration of one test.
     *
     * Asserted, not assumed: a helper that quietly failed to change the
     * setting would leave every test below it passing for the wrong reason.
     *
     * @param int $perPage
     */
    protected function setStorePerPage(int $perPage): void
    {
        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['reviews_per_page'] = $perPage;
        ModuleSettings::saveSettings($modules);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        $this->assertSame(
            $perPage,
            (int) ProductReviewService::getReviewSettings()['reviews_per_page'],
            'the store setting actually changed'
        );
    }

    /**
     * @param string $innerBlocks serialized children of the list block
     * @param array $attributes extra list-block attributes
     */
    protected function renderList(string $innerBlocks, array $attributes = []): string
    {
        $attributes = array_merge([
            'query_type' => 'custom',
            'product_id' => (string) $this->productId,
        ], $attributes);

        $markup = '<!-- wp:fluent-cart/product-review-list ' . wp_json_encode($attributes) . ' -->'
            . $innerBlocks
            . '<!-- /wp:fluent-cart/product-review-list -->';

        return (string) preg_replace('#<style.*?</style>#s', '', do_blocks($markup));
    }

    /**
     * The composition token a rendered list advertises.
     *
     * @param string $html
     * @return string
     */
    protected function compositionTokenIn(string $html): string
    {
        preg_match('#data-client-id="([^"]*)"#', $html, $matches);

        return isset($matches[1]) ? $matches[1] : '';
    }

    /**
     * The rows the public reviews endpoint returns.
     *
     * Every list interaction after the first paint is this request, not the
     * block's render — so it is where a composed row is lost if the endpoint
     * has no way back to the composition.
     *
     * @param array $params
     * @return string
     */
    protected function rowsFromEndpoint(array $params): string
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/reviews/' . $this->productId);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $data = rest_do_request($request)->get_data();

        return (string) ($data['reviews_html'] ?? '');
    }

    /**
     * A Review Pagination block carrying $attributes.
     *
     * @param array $attributes
     * @return string
     */
    protected function pagerBlock(array $attributes = []): string
    {
        return '<!-- wp:fluent-cart/review-list-pagination '
            . ($attributes ? wp_json_encode($attributes) . ' ' : '')
            . '/-->';
    }

    /**
     * @param array|null $settings
     * @return array the settings as the page received them
     */
    protected function sliderSettingsFor($settings): array
    {
        $attributes = ['viewMode' => 'slider', 'gridColumns' => 2];

        if ($settings !== null) {
            $attributes['sliderSettings'] = $settings;
        }

        $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', $attributes);

        preg_match('#data-slider-settings="([^"]*)"#', $html, $matches);

        return (array) json_decode(html_entity_decode($matches[1] ?? '{}'), true);
    }
}
