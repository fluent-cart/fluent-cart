import {describe, expect, it} from 'vitest';
import {
    blockToTemplate,
    hasReviewWritingBlocks,
    isBareReviewList,
    summarySectionTemplate,
    withRetainedAttributes,
    withoutReviewWritingBlocks,
    withWriteReviewButton,
} from '@/BlockEditor/ProductReviews/Components/reviewSectionShape';

/**
 * Several products keep the rating summary (it combines them) but lose Write a
 * Review and the form, which need one product. One product gets them back.
 */
const reviewList = ['fluent-cart/product-review-list', {viewMode: 'list'}, [
    ['fluent-cart/review-item', {}, [['fluent-cart/review-item-content', {}, []]]],
]];
const summaryOnly = ['fluent-cart/product-review-summary-group', {}, [['fluent-cart/product-review-summary', {}, []]]];

describe('review section shape', () => {
    it('keeps the summary and drops Write a Review from the starting layout', () => {
        const section = summarySectionTemplate(reviewList);

        expect(hasReviewWritingBlocks(section)).toBe(true);
        expect(withoutReviewWritingBlocks(section)).toEqual([
            ['core/columns', {style: {spacing: {blockGap: '24px'}}}, [
                ['core/column', {width: '320px'}, [summaryOnly]],
                ['core/column', {}, [reviewList]],
            ]],
        ]);
    });

    it('drops a column left empty and lets the list take the width', () => {
        const section = [['core/columns', {}, [
            ['core/column', {}, [['fluent-cart/product-review-summary-group', {}, [['fluent-cart/write-a-review-button', {}, []]]]]],
            ['core/column', {}, [reviewList]],
        ]]];

        expect(withoutReviewWritingBlocks(section)).toEqual([reviewList]);
    });

    it('keeps the rest of a custom arrangement', () => {
        const heading = ['core/heading', {content: 'What buyers say'}, []];
        const section = [heading, ['core/group', {}, [['fluent-cart/product-review-form', {}, []], reviewList]]];

        expect(withoutReviewWritingBlocks(section)).toEqual([heading, ['core/group', {}, [reviewList]]]);
        expect(hasReviewWritingBlocks(withoutReviewWritingBlocks(section))).toBe(false);
    });

    it('leaves columns that were already empty alone', () => {
        const section = [['core/columns', {}, [['core/column', {}, []], ['core/column', {}, [reviewList]]]]];

        expect(withoutReviewWritingBlocks(section)).toEqual(section);
    });

    it('puts Write a Review back under the summary for one product', () => {
        const stripped = withoutReviewWritingBlocks(summarySectionTemplate(reviewList));

        expect(withWriteReviewButton(stripped)).toEqual([
            ['core/columns', {style: {spacing: {blockGap: '24px'}}}, [
                ['core/column', {width: '320px'}, [['fluent-cart/product-review-summary-group', {}, [
                    ['fluent-cart/product-review-summary', {}, []],
                    ['fluent-cart/write-a-review-button', {}],
                ]]]],
                ['core/column', {}, [reviewList]],
            ]],
        ]);
        expect(withWriteReviewButton(summarySectionTemplate(reviewList))).toBeNull();
        expect(withWriteReviewButton([reviewList])).toBeNull();
    });

    it('puts a bare review list back beside a summary', () => {
        expect(isBareReviewList([reviewList])).toBe(true);
        expect(isBareReviewList([reviewList, ['core/paragraph', {}, []]])).toBe(false);
        expect(summarySectionTemplate(reviewList)[0][2][1][2]).toEqual([reviewList]);
    });

    it('reads editor blocks as templates', () => {
        const block = {
            name: 'fluent-cart/product-review-list',
            attributes: {viewMode: 'list'},
            innerBlocks: [{name: 'fluent-cart/review-item', attributes: {}, innerBlocks: []}],
        };

        expect(blockToTemplate(block)).toEqual([
            'fluent-cart/product-review-list', {viewMode: 'list'}, [['fluent-cart/review-item', {}, []]],
        ]);
    });

    it('keeps the settings of blocks that stay when a layout is rebuilt', () => {
        const preset = [['core/columns', {}, [
            ['core/column', {width: '320px'}, [['fluent-cart/product-review-summary-group', {}, [
                ['fluent-cart/product-review-summary', {}],
                ['fluent-cart/write-a-review-button', {}],
            ]]]],
            ['core/column', {}, [['fluent-cart/product-review-list', {viewMode: 'list'}]]],
        ]]];
        // Several products: the merchant's colours and anchor, without the button.
        const current = [['core/columns', {anchor: 'reviews'}, [
            ['core/column', {width: '320px'}, [['fluent-cart/product-review-summary-group', {className: 'my-card'}, [
                ['fluent-cart/product-review-summary', {starColor: '#123456'}, []],
            ]]]],
            ['core/column', {}, [['fluent-cart/product-review-list', {viewMode: 'list', perPage: 6}, []]]],
        ]]];

        expect(withRetainedAttributes(preset, current)).toEqual([['core/columns', {anchor: 'reviews'}, [
            ['core/column', {width: '320px'}, [['fluent-cart/product-review-summary-group', {className: 'my-card'}, [
                ['fluent-cart/product-review-summary', {starColor: '#123456'}, []],
                // Back from nothing, so the template's own settings.
                ['fluent-cart/write-a-review-button', {}, []],
            ]]]],
            ['core/column', {}, [['fluent-cart/product-review-list', {viewMode: 'list', perPage: 6}, []]]],
        ]]]);
    });
});

