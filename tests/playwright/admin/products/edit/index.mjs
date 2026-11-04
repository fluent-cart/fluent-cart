/**
 * Product Edit — Suite Index
 *
 * Registers all product-edit page tests.
 * Run the full suite:  node tests/playwright/runner.mjs admin/products/edit
 * Run one test:        node tests/playwright/runner.mjs admin/products/edit/pricing-simple
 */
export { default as ProductEditSmokeTest }                from './smoke.test.mjs';
export { default as ProductEditContentTest }              from './content.test.mjs';
export { default as ProductEditPricingSimpleTest }        from './pricing-simple.test.mjs';
export { default as ProductEditPricingSimpleTaxTest }     from './pricing-simple-tax.test.mjs';
export { default as ProductEditPricingVariationsTest }    from './pricing-variations.test.mjs';
export { default as ProductEditFluentPlayerVideoTest }    from './fluent-player-video.test.mjs';
export { default as ProductEditGalleryVideoOrderTest }    from './gallery-video-order.test.mjs';
export { default as ProductEditGalleryTabLoadingTest }    from './gallery-tab-loading.test.mjs';
